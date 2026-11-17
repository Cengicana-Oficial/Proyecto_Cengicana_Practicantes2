// Prueba local de carga del panel con una cookie temporal del portal.
// No imprime credenciales ni tokens. Requiere un Edge/Chrome con CDP en localhost.
const port = process.env.CENGI_CDP_PORT || '9223';
const cookie = process.env.CENGI_TEST_COOKIE;
if (!cookie) throw new Error('Falta CENGI_TEST_COOKIE.');
const tabs = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
const page = tabs.find(tab => tab.type === 'page');
if (!page) throw new Error('No hay pestaña de prueba.');
const socket = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, {once: true});
  socket.addEventListener('error', reject, {once: true});
});
let nextId = 0;
const pending = new Map();
const errors = [];
socket.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (message.method === 'Runtime.exceptionThrown') {
    errors.push(message.params?.exceptionDetails?.text || 'Error de JavaScript');
  }
  const waiter = pending.get(message.id);
  if (!waiter) return;
  pending.delete(message.id);
  if (message.error) waiter.reject(new Error(message.error.message));
  else waiter.resolve(message.result);
});
function send(method, params = {}) {
  const id = ++nextId;
  return new Promise((resolve, reject) => {
    pending.set(id, {resolve, reject});
    socket.send(JSON.stringify({id, method, params}));
  });
}
await send('Network.enable');
await send('Runtime.enable');
await send('Page.enable');
await send('Network.setCookie', {
  name: 'PHPSESSID', value: cookie, url: 'http://127.0.0.1:8085/',
  httpOnly: true, sameSite: 'Lax', path: '/'
});
await send('Page.navigate', {url: 'http://127.0.0.1:8085/cengisalas/index.html?panel=1'});
const deadline = Date.now() + 25000;
let state;
while (Date.now() < deadline) {
  await new Promise(resolve => setTimeout(resolve, 500));
  const result = await send('Runtime.evaluate', {
    expression: `JSON.stringify({ready:document.readyState,app:!!document.querySelector('#app:not(.hidden)'),loading:document.querySelector('#appLoading span')?.textContent||'',name:document.querySelector('.user-name')?.textContent||''})`,
    returnByValue: true
  });
  try { state = JSON.parse(result.result?.value || '{}'); } catch { state = {}; }
  if (state.app || (state.ready === 'complete' && state.loading !== 'Preparando tu espacio de trabajo…')) break;
}
console.log(JSON.stringify({app: Boolean(state?.app), loading: state?.loading || '', name: state?.name || '', errors}));
if (state?.app) {
  const menuResult = await send('Runtime.evaluate', {
    expression: `JSON.stringify({role:document.querySelector('.user-role')?.textContent||'',items:[...document.querySelectorAll('aside nav > button, aside nav > a')].filter(el=>getComputedStyle(el).display!=='none'&&!el.classList.contains('hidden')).map(el=>el.textContent.trim().replace(/\\s+/g,' '))})`,
    returnByValue: true
  });
  console.log(JSON.stringify({menu: JSON.parse(menuResult.result?.value || '{}')}));
  if (process.env.CENGI_TEST_ROLE_MODAL === '1') {
    await send('Runtime.evaluate', {expression: `document.querySelector('#navManagePermissions')?.click()`});
    await new Promise(resolve => setTimeout(resolve, 1200));
    const modalResult = await send('Runtime.evaluate', {
      expression: `JSON.stringify({open:!document.querySelector('#modal')?.classList.contains('hidden'),roles:document.querySelectorAll('input[name="salasRole"]').length,users:document.querySelector('#permissionUserSelect')?.options.length||0,error:document.querySelector('#permissionFormError')?.textContent||''})`,
      returnByValue: true
    });
    const modal = JSON.parse(modalResult.result?.value || '{}');
    console.log(JSON.stringify({roleModal: modal}));
    if (!modal.open || modal.roles !== 3 || modal.users < 1) process.exitCode = 1;
  }
  if (process.env.CENGI_TEST_USERS_VIEW === '1') {
    await send('Runtime.evaluate', {expression: `document.querySelector('#closeModal')?.click(); document.querySelector('button[data-view="users"]')?.click()`});
    await new Promise(resolve => setTimeout(resolve, 1200));
    const usersResult = await send('Runtime.evaluate', {
      expression: `JSON.stringify({active:document.querySelector('#usersView')?.classList.contains('active'),rows:document.querySelectorAll('#userGroups tbody tr').length,text:document.querySelector('#userGroups')?.textContent?.slice(0,120)||''})`,
      returnByValue: true
    });
    const usersView = JSON.parse(usersResult.result?.value || '{}');
    console.log(JSON.stringify({usersView}));
    if (!usersView.active || usersView.rows < 1) process.exitCode = 1;
  }
  if (process.env.CENGI_TEST_CREATE_MODAL === '1') {
    await send('Runtime.evaluate', {expression: `document.querySelector('#closeModal')?.click();document.querySelector('button[data-view="users"]')?.click();document.querySelector('#createPortalUser')?.click()`});
    await new Promise(resolve => setTimeout(resolve, 900));
    const createResult = await send('Runtime.evaluate', {
      expression: `JSON.stringify({open:!document.querySelector('#modal')?.classList.contains('hidden'),name:!!document.querySelector('#portalNewName'),email:!!document.querySelector('#portalNewEmail'),password:!!document.querySelector('#portalNewPassword'),roles:[...document.querySelectorAll('#portalNewRole option')].map(o=>o.value),organizations:document.querySelector('#portalNewOrganization')?.options.length||0})`,
      returnByValue: true
    });
    const createModal = JSON.parse(createResult.result?.value || '{}');
    console.log(JSON.stringify({createModal}));
    if (!createModal.open || !createModal.name || !createModal.email || !createModal.password || createModal.roles?.length !== 3 || createModal.organizations < 1) process.exitCode = 1;
  }
  if (process.env.CENGI_TEST_STANDARD_PRESET === '1') {
    const standardResult = await send('Runtime.evaluate', {
      expression: `(() => {currentUser=applyPortalSalasRole(currentUser,{role:'user',superadmin:false});syncCurrentUserInterface();return JSON.stringify({items:[...document.querySelectorAll('aside nav > button, aside nav > a')].filter(el=>getComputedStyle(el).display!=='none'&&!el.classList.contains('hidden')).map(el=>el.textContent.trim().replace(/\\s+/g,' '))})})()`,
      returnByValue: true
    });
    const standardPreset = JSON.parse(standardResult.result?.value || '{}');
    console.log(JSON.stringify({standardPreset}));
    if (standardPreset.items?.some(item => /Solicitudes pago|Reporter|Mantenimiento|Salones$|Roles y permisos/.test(item))) process.exitCode = 1;
  }
}
const waitMs = Number(process.env.CENGI_TEST_WAIT_MS || 0);
if (state?.app && waitMs > 0) {
  await new Promise(resolve => setTimeout(resolve, waitMs));
  const result = await send('Runtime.evaluate', {
    expression: `JSON.stringify({url:location.pathname+location.search,app:!!document.querySelector('#app:not(.hidden)'),name:document.querySelector('.user-name')?.textContent||''})`,
    returnByValue: true
  });
  const after = JSON.parse(result.result?.value || '{}');
  console.log(JSON.stringify({afterWaitMs: waitMs, ...after, errors}));
  if (!after.app || !after.url?.includes('/cengisalas/index.html')) process.exitCode = 1;
}
socket.close();
if (!state?.app) process.exitCode = 1;
