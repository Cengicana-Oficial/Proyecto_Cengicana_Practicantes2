'use strict';
let csrfToken = '';
let editingId = null;
let saving = false;
const $ = id => document.getElementById(id);
function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function notice(message, error = false) {
  const box = $('appNotice'); box.hidden = false; box.className = 'notice' + (error ? ' error' : ''); box.textContent = message;
  const modal = document.querySelector('.modal-overlay.show .modal-box');
  if (modal && error) { let feedback=modal.querySelector('.modal-notice'); if(!feedback){feedback=document.createElement('p');feedback.className='notice error modal-notice';feedback.setAttribute('role','alert');modal.prepend(feedback);} feedback.textContent=message; feedback.scrollIntoView({block:'nearest'}); }
  if (error) box.scrollIntoView({behavior:'smooth',block:'nearest'});
}
async function api(action, payload) {
  const options = payload === undefined ? {} : {method:'POST',headers:{'X-CSRF-Token':csrfToken},body:payload instanceof FormData ? payload : JSON.stringify(payload)};
  if (payload !== undefined && !(payload instanceof FormData)) options.headers['Content-Type'] = 'application/json';
  const response = await fetch('api.php?action=' + encodeURIComponent(action), options);
  const result = await response.json().catch(() => { throw new Error('El servidor no respondió correctamente. Intenta nuevamente.'); });
  if (response.status === 401) { location.href = '../login/login.php'; throw new Error('Inicia sesión en Módulos para continuar.'); }
  if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo completar la operación.');
  return result;
}
async function loadData() {
  const result = await api('bootstrap');
  csrfToken = result.csrf;
  data.splice(0,data.length,...result.data);
  data.forEach(row => {
    const month = Number(row.fechaISO?.slice(5,7));
    row.mesLabel = month ? monthNames[month-1] + ' ' + row.fechaISO.slice(0,4) : 'Sin fecha';
    if (row.emailProfesional) asesorEmails[row.asesor] = row.emailProfesional;
  });
  usersData = result.users;
  AREAS.splice(0,AREAS.length,...result.areas);
  const previousArea = $('nsArea').value;
  $('nsArea').replaceChildren(new Option('Seleccionar área',''), ...AREAS.map(area=>new Option(area,area)));
  $('nsArea').value = result.user.rol === 'profesional' ? result.user.area : previousArea;
  $('nsArea').disabled = result.user.rol === 'profesional';
  permissionsMatrix.splice(0,permissionsMatrix.length,...result.permissions);
  currentUserId = Number(result.user.id); currentRole = result.user.rol; currentArea = result.user.area; currentUserName = result.user.nombre;
  $('currentUserSelect').replaceChildren(new Option(currentUserName + ' · ' + roleLabels[currentRole], currentUserId));
  $('newServiceBtn').hidden = !canManageServices();
  for (const [id,index] of [['tabContacts',6],['tabDashboard',7],['tabUsers',9]]) $(id).hidden = !permissionsMatrix[index]?.[currentRole];
  $('areaNoteList').style.display = isAreaRestricted() ? 'block':'none';
  $('areaNoteList').textContent = 'Servicios del área: ' + currentArea;
  $('jefeNoteList').style.display = 'none';
  updateExportButtonsVisibility();
  $('bulkSyncBtn').hidden = !permissionsMatrix[8]?.[currentRole];
  // Refresh filter options without discarding the selected filter after a save.
  for (const [id,key,label] of [['dashIngenio','institucion','Todos los ingenios'],['dashMes','mesLabel','Todos los meses']]) {
    const old = $(id).value;
    $(id).replaceChildren(new Option(label,''),...[...new Set(data.map(r=>r[key]))].sort().map(v=>new Option(v,v)));
    if ([...$(id).options].some(o=>o.value===old)) $(id).value = old;
  }
  applyFilters();
}
async function mutate(action, payload, after) {
  if (saving) return;
  saving = true; document.body.classList.add('saving');
  try { const result = await api(action,payload); await loadData(); if (after) after(result); notice('Cambios guardados correctamente.'); return result; }
  catch (error) { notice(error.message,true); }
  finally { saving = false; document.body.classList.remove('saving'); }
}
async function logout() { try { await api('logout',{}); location.href = '../login/login.php'; } catch(e) { notice(e.message,true); } }
function surveyURL(row) {
  if (!row?.token) return '';
  const url = new URL('encuesta.php',location.href); url.searchParams.set('id',row.id); url.searchParams.set('token',row.token); return url.href;
}
function downloadBlob(blob, name) {
  const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = name; document.body.append(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),30000);
}
function csvCell(value) {
  let str = String(value ?? ''); if (/^[\s]*[=+@\-\t\r]/.test(str)) str = "'" + str;
  return '"' + str.replace(/"/g,'""') + '"';
}
function exportCSV(rows, headers, filename) {
  if (!permissionsMatrix[8]?.[currentRole]) return notice('No tienes permiso para exportar.',true);
  downloadBlob(new Blob(['\uFEFF' + [headers,...rows].map(r=>r.map(csvCell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}),filename);
}
function exportFullReportCSV() {
  const rows = data.filter(r=>(!$('dashIngenio').value || r.institucion===$('dashIngenio').value) && (!$('dashMes').value || r.mesLabel===$('dashMes').value));
  exportCSV(rows.map(r=>[r.id,r.cliente,r.institucion,r.area,r.asesor,r.fecha,r.estado,r.encuesta,r.puntuacion,r.comentario,r.personaRespondio,r.cargoRespondente,r.transferencia,r.lugarTransferencia,r.fechaTransferencia,segLabels[r.seguimiento.estado]]),['ID','Cliente','Institución','Área','Asesor','Fecha','Estado','Encuesta','Puntuación','Comentario','Respondió','Cargo','Transferencia','Destino','Fecha transferencia','Seguimiento'],'reporte_completo.csv');
}
const originalShowView = showView;
showView = function(name) {
  const index = {contacts:6,dashboard:7,users:9,newservice:2}[name];
  if (index !== undefined && !permissionsMatrix[index]?.[currentRole]) return notice('No tienes acceso a esta sección.',true);
  originalShowView(name); $('appNotice').hidden = true;
  document.querySelectorAll('.tech-sidebar .nav-tab').forEach(tab => {
    if (tab.classList.contains('active')) tab.setAttribute('aria-current','page');
    else tab.removeAttribute('aria-current');
  });
  setSidebarOpen(false);
};
const originalOpenDetail = openDetail;
openDetail = function(id) {
  if (!permissionsMatrix[1]?.[currentRole]) return notice('No tienes permiso para ver el detalle.',true);
  const row = data.find(r=>r.id===id); if (!row) return notice('Servicio no encontrado.',true);
  originalOpenDetail(id);
  $('editServiceBtn').hidden = !permissionsMatrix[10]?.[currentRole];
  const ev = $('dEvidencia'); ev.replaceChildren();
  if (row.evidencia && row.evidencia !== '-') { const a = document.createElement('a'); a.href = 'evidencia.php?id=' + encodeURIComponent(id); a.textContent = 'Descargar evidencia'; ev.append(a); } else ev.textContent = 'Sin evidencia';
  $('dCargoInput').disabled = !canFillSurvey();
  $('dCargoInput').parentElement.querySelector('button').hidden = !canFillSurvey();
  document.querySelector('#detailStateSinGenerar button').hidden = !permissionsMatrix[3]?.[currentRole];
  if (row.encuesta === 'pendiente' && !row.token && permissionsMatrix[3]?.[currentRole]) {
    $('linkInput').placeholder = 'Genera un enlace para compartir esta encuesta';
    $('mailBtn').removeAttribute('href');
  }
  const pendingNote=$('detailStatePendiente').querySelector('.pending-note');
  if(pendingNote) pendingNote.textContent=row.token ? 'Enlace disponible · Aún sin respuesta del cliente' : 'Encuesta pendiente · Genera el enlace para compartirla';
  const gen = $('ensureLinkBtn'); if (gen) gen.remove();
  if (row.encuesta === 'pendiente' && !row.token && permissionsMatrix[3]?.[currentRole]) {
    const button = document.createElement('button'); button.id='ensureLinkBtn'; button.className='btn btn-secondary btn-sm'; button.textContent='Generar enlace'; button.onclick=generarLink; $('detailStatePendiente').prepend(button);
  }
  // Evidence remains available even when no transfer is associated with the service.
  let extra = $('serviceExtra'); if (!extra) { extra = document.createElement('div'); extra.id='serviceExtra'; extra.className='service-extra'; $('dDesc').parentElement.append(extra); }
  extra.textContent = 'Participantes: ' + row.participantes + ' · Medio: ' + (row.medio || 'Sin especificar') + (row.fechaCierre ? ' · Cierre: ' + toDDMMYYYY(row.fechaCierre):'');
  if (row.evidencia && row.evidencia !== '-') { const a = document.createElement('a'); a.href='evidencia.php?id='+encodeURIComponent(id); a.textContent='Descargar evidencia'; extra.append(document.createElement('br'),a); }
};
async function generarLink() { await mutate('link',{id:currentId},()=>openDetail(currentId)); }
async function enviarEncuestaRapido(id) { await mutate('link',{id},()=>{openDetail(id); notice('Enlace listo. Usa «Abrir correo» para compartirlo.');}); }
function notificarAsesor() {
  const row = data.find(r=>r.id===currentId);
  if (!permissionsMatrix[5]?.[currentRole]) return notice('No tienes permiso para esta acción.',true);
  if (!row.emailProfesional) return notice('Agrega el correo del asesor en Editar servicio.',true);
  location.href = 'mailto:' + encodeURIComponent(row.emailProfesional) + '?subject=' + encodeURIComponent('Seguimiento · '+row.id) + '&body=' + encodeURIComponent('Calificación: '+row.puntuacion+'/5\nComentario: '+row.comentario+'\nCliente: '+row.cliente+'\nServicio: '+row.desc);
  notice('Se abrió un borrador en tu aplicación de correo. El envío se realiza desde allí.');
}
async function registrarSeguimiento() { await mutate('followup',{id:currentId,estado:segEstadoSeleccionado,nota:$('segNotaInput').value},()=>{ $('segNotaInput').value='';openDetail(currentId); }); }
async function guardarCargoDetalle() { await mutate('cargo',{id:currentId,cargo:$('dCargoInput').value},()=>openDetail(currentId)); }
async function submitResponderModal() {
  const nombre = $('mNombre').value.trim(); $('mNombreErr').classList.toggle('show',!nombre); $('mStarsErr').classList.toggle('show',!modalRating);
  if (!nombre || !modalRating) return;
  await mutate('response',{id:modalTargetId,nombre,cargo:$('mCargo').value,puntuacion:modalRating,comentario:$('mComentario').value},()=>{ closeResponderModal(); openDetail(modalTargetId); });
}
const originalOpenNewService = openNewService;
openNewService = function() {
  editingId = null; originalOpenNewService();
  $('newServiceForm').querySelector('h2').textContent='Registro de Nuevo Servicio';
  for (const id of ['nsEvidenciaFile','nsFechaTransfer','nsLugarTransfer','nsDescTransfer']) $(id).value='';
  const now = new Date(); $('nsFechaSolicitud').value = [now.getFullYear(),String(now.getMonth()+1).padStart(2,'0'),String(now.getDate()).padStart(2,'0')].join('-');
};
const fieldMap = {institucion:'nsInstitucion',cliente:'nsCliente',email:'nsEmail',celular:'nsCelular',fechaISO:'nsFechaSolicitud',area:'nsArea',asesor:'nsAsesor',emailProfesional:'nsEmailProfesional',tipo:'nsTipo',estado:'nsEstado',fechaCierre:'nsFechaCierre',desc:'nsDescripcion',lugar:'nsLugar',medio:'nsMedio',participantes:'nsParticipantes',lugarTransferencia:'nsLugarTransfer',descripcionTransferencia:'nsDescTransfer'};
function editService(id) {
  if (!permissionsMatrix[10]?.[currentRole]) return notice('Solo el Admin puede editar servicios existentes.',true);
  const row = data.find(r=>r.id===id); if (!row) return notice('Servicio no encontrado.',true);
  openNewService(); editingId=id; $('newServiceForm').querySelector('h2').textContent='Editar servicio · '+id;
  for (const [key,element] of Object.entries(fieldMap)) {
    if ($(element).tagName==='SELECT' && row[key] && ![...$(element).options].some(o=>o.value===row[key])) $(element).add(new Option(row[key],row[key]));
    $(element).value = row[key] ?? '';
  }
  if (row.fechaTransferencia !== '-') $('nsFechaTransfer').value=row.fechaTransferencia.split('/').reverse().join('-');
  setToggle('nsEnvio',row.envioEncuesta==='Si'?'si':'no'); setToggle('nsTransfer',row.transferencia==='Si'?'si':'no');
  $('nsEvidenciaLabel').textContent = row.evidencia && row.evidencia!=='-' ? 'Adjunto actual: '+row.evidencia : 'Seleccionar archivo · sin adjunto';
}
async function guardarNuevoServicio() {
  for (const input of document.querySelectorAll('#newServiceForm input')) if (!input.checkValidity()) { input.reportValidity(); return; }
  const form = new FormData();
  for (const [key,id] of Object.entries(fieldMap)) form.set(key,$(id).value);
  if (form.get('institucion')==='__otra') form.set('institucion',$('nsInstitucionOtra').value);
  form.set('envioEncuesta',$('nsEnvioSi').dataset.value==='si'?'Si':'No'); form.set('transferencia',$('nsTransferSi').dataset.value==='si'?'Si':'No');
  form.set('fechaTransferISO',$('nsFechaTransfer').value);
  if ($('nsEvidenciaFile').files[0]) form.set('evidencia',$('nsEvidenciaFile').files[0]);
  if (editingId) form.set('id',editingId);
  await mutate(editingId?'update':'create',form,result=>{editingId=null;openDetail(result.id);});
}
function optionList(items,value) { return items.map(([v,label])=>'<option value="'+escapeHtml(v)+'"'+(v===value?' selected':'')+'>'+escapeHtml(label)+'</option>').join(''); }
function renderUsersTable() {
  $('usersBody').innerHTML = usersData.map(u=>{
    const locked = u.superadmin || Number(u.id)===currentUserId;
    return `<tr><td>${escapeHtml(u.nombre)}${u.superadmin ? '<small class="access-note">Superadmin de Módulos</small>' : ''}</td><td>${escapeHtml(u.email)}</td><td><select id="accessRole${u.id}" aria-label="Perfil de ${escapeHtml(u.nombre)}" ${locked?'disabled':''} onchange="changeAccessRole(${u.id})">${optionList([['','Sin acceso'],['profesional','Profesional'],['admin','Admin']],u.rol)}</select></td><td><select id="accessArea${u.id}" aria-label="área de ${escapeHtml(u.nombre)}" ${locked||u.rol!=='profesional'?'disabled':''}>${optionList(u.rol==='admin'?[['Todas','Todas las áreas']]:[['','Seleccionar área'],...AREAS.map(a=>[a,a])],u.area)}</select></td><td><button type="button" class="btn btn-primary btn-sm" ${locked?'disabled':''} onclick="saveUserAccess(${u.id})">Guardar</button></td></tr>`;
  }).join('');
}
function changeAccessRole(id) {
  const role=$('accessRole'+id).value, select=$('accessArea'+id), previous=select.value;
  select.innerHTML=optionList(role==='admin'?[['Todas','Todas las áreas']]:[['','Seleccionar área'],...AREAS.map(a=>[a,a])],role==='admin'?'Todas':previous);
  select.disabled=role!=='profesional';
}
async function saveUserAccess(id) {
  const rol=$('accessRole'+id).value, area=$('accessArea'+id).value;
  if(rol==='profesional'&&!area) return notice('Selecciona el área del profesional.',true);
  await mutate('user_access_save',{id,rol,area},renderUsersView);
}
function renderContacts() {
  syncRegistry={}; syncIdCounter=0;
  const advisors=new Map(),clients=new Map(),transfers=new Map();
  data.forEach(r=>{
    let a=advisors.get(r.asesor); if(!a) {a={nombre:r.asesor,empresa:'Cengicaña',correo:r.emailProfesional,areas:new Set(),count:0};advisors.set(r.asesor,a);} a.count++;a.areas.add(r.area);
    const key=r.cliente+'|'+r.institucion;let c=clients.get(key); if(!c) {c={nombre:r.cliente,empresa:r.institucion,correo:r.email,telefono:r.celular,cargo:r.cargoRespondente,count:0,encuesta:r.encuesta,areas:new Set()};clients.set(key,c);} c.count++;c.areas.add(r.area);
    if(r.transferencia==='Si' && r.lugarTransferencia && r.lugarTransferencia!=='-' && !transfers.has(r.lugarTransferencia)) transfers.set(r.lugarTransferencia,{nombre:r.lugarTransferencia,empresa:r.lugarTransferencia,id:r.id,fecha:r.fechaTransferencia,descripcion:r.descripcionTransferencia});
  });
  const exportButton=c=>{const id=registerSyncCandidate(c.nombre,c.empresa,c.telefono,c.correo,c.cargo,c.areas?[...c.areas].join(', '):'Transferencia');return permissionsMatrix[8]?.[currentRole]?`<button class="btn btn-outline btn-sm" onclick="openSyncModal('${id}')">Exportar</button>`:'—';};
  $('asesoresBody').innerHTML=[...advisors.values()].map(a=>`<tr><td>${escapeHtml(a.nombre)}</td><td>${escapeHtml([...a.areas].join(', '))}</td><td>${a.count}</td><td>${escapeHtml(a.correo||'—')}</td><td>${exportButton(a)}</td></tr>`).join('')||'<tr><td colspan="5" class="empty-state">No hay asesores registrados.</td></tr>';
  $('clientesBody').innerHTML=[...clients.values()].map(c=>`<tr><td>${escapeHtml(c.nombre)}</td><td>${escapeHtml(c.empresa)}</td><td>${escapeHtml(c.correo)}</td><td>${escapeHtml(c.telefono)}</td><td>${c.count}</td><td>${{respondida:'Respondida',pendiente:'Pendiente','sin-generar':'Sin generar'}[c.encuesta]}</td><td>${exportButton(c)}</td></tr>`).join('')||'<tr><td colspan="7" class="empty-state">No hay clientes registrados.</td></tr>';
  $('transferContactsBody').innerHTML=[...transfers.values()].map(c=>`<tr><td>${escapeHtml(c.nombre)}</td><td>${escapeHtml(c.id)}</td><td>${escapeHtml(c.fecha)}</td><td>${escapeHtml(c.descripcion)}</td><td>${exportButton(c)}</td></tr>`).join('')||'<tr><td colspan="5" class="empty-state">No hay destinos de transferencia registrados.</td></tr>';
}
function exportContacts(contacts) { exportCSV(contacts.map(c=>[c.nombre,c.empresa,c.telefono,c.correo,c.cargo,c.servicios]),['Nombre','Institución','Teléfono','Correo','Cargo','Servicios'],'contactos.csv'); }
function confirmSync() { const c=syncRegistry[currentSyncId]; if(c) {exportContacts([c]);closeSyncModal();} }
function bulkSyncAll() { renderContacts();exportContacts(Object.values(syncRegistry)); }
async function copyInput(id,feedback) {
  const input=$(id); if(!input.value) return notice('Genera el enlace desde el detalle del servicio.',true);
  try { await navigator.clipboard.writeText(input.value); $(feedback).classList.add('show');setTimeout(()=>$(feedback).classList.remove('show'),1800); }
  catch { input.focus();input.select();notice('Selecciona y copia el enlace con Ctrl+C.'); }
}
copiarLink=()=>copyInput('linkInput','copyFeedback'); copiarLinkPreview=()=>copyInput('pvLinkInput','pvCopyFeedback');
// Accessible dialogs: contain keyboard focus, Escape closes and restores focus.
let previousFocus=null;
document.querySelectorAll('.modal-overlay').forEach(overlay=>{
  const box=overlay.firstElementChild;box.setAttribute('role','dialog');box.setAttribute('aria-modal','true');box.setAttribute('aria-label',box.querySelector('h3,h2')?.textContent || 'Formulario');box.tabIndex=-1;
  new MutationObserver(()=>{if(overlay.classList.contains('show')) {box.querySelector('.modal-notice')?.remove();previousFocus=document.activeElement;(box.querySelector('input,button')||box).focus();} else previousFocus?.focus();}).observe(overlay,{attributes:true,attributeFilter:['class']});
  overlay.addEventListener('click',e=>{if(e.target===overlay)overlay.classList.remove('show');});
  overlay.addEventListener('keydown',e=>{if(e.key==='Escape')overlay.classList.remove('show');if(e.key==='Tab'){const items=[...box.querySelectorAll('button,input,textarea,select,a[href]')].filter(el=>!el.disabled&&el.getClientRects().length);const first=items[0],last=items.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}}});
});
document.querySelectorAll('label:not([for])').forEach(label=>{const field=label.parentElement.querySelector('input[id],select[id],textarea[id]');if(field)label.htmlFor=field.id;});
async function initApp() {
  try {
    await loadData();
    AREAS.splice(0,AREAS.length,...new Set([...$('nsArea').options].map(o=>o.value).filter(Boolean).concat(data.map(r=>r.area).filter(Boolean))));
    const params=new URLSearchParams(location.search),view=params.get('view'),id=params.get('id');
    if(view==='newservice')openNewService();else if(view==='edit')editService(id);else if(id){openDetail(id);if(view==='respond')openResponderModal(id);}
  } catch(error) {notice(error.message,true);$('tableBody').innerHTML='<tr><td colspan="11" class="empty-state">No fue posible cargar los servicios. <button class="btn btn-outline" onclick="initApp()">Reintentar</button></td></tr>';}
}
const sidebarMobile = window.matchMedia('(max-width: 1024px)');
function setSidebarOpen(open, restoreFocus = true) {
  const sidebar = $('techSidebar'), toggle = $('techMenuToggle');
  const wasOpen = sidebar.classList.contains('is-open');
  open = Boolean(open && sidebarMobile.matches);
  sidebar.classList.toggle('is-open', open);
  sidebar.inert = sidebarMobile.matches && !open;
  document.querySelector('.main-content').inert = open;
  document.body.classList.toggle('tech-menu-open', open);
  $('techSidebarBackdrop').hidden = !open;
  toggle.setAttribute('aria-expanded', String(open));
  if (open) requestAnimationFrame(() => requestAnimationFrame(() => {
    if (sidebar.classList.contains('is-open')) sidebar.querySelector('.tech-menu-close').focus();
  }));
  else if (wasOpen && restoreFocus) toggle.focus();
}
$('techSidebarBackdrop').addEventListener('click', () => setSidebarOpen(false));
document.addEventListener('keydown', event => {
  if (!sidebarMobile.matches || !$('techSidebar').classList.contains('is-open')) return;
  if (event.key === 'Escape') { event.preventDefault(); setSidebarOpen(false); }
  if (event.key === 'Tab') {
    const items = [...$('techSidebar').querySelectorAll('button:not(:disabled),a[href]')].filter(el => el.getClientRects().length && !el.hidden);
    const first = items[0], last = items.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }
});
sidebarMobile.addEventListener('change', () => setSidebarOpen(false, false));
setSidebarOpen(false, false);
initApp();
