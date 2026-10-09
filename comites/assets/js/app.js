function filterRows(inputId, tableId) {
  const query = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll(`#${tableId} tbody tr`).forEach((row) => {
    row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
  });
}

function setStatusFilter(button, tableId, estado) {
  button.closest('.filters').querySelectorAll('.filter-chip').forEach((chip) => chip.classList.remove('active'));
  button.classList.add('active');
  document.querySelectorAll(`#${tableId} tbody tr`).forEach((row) => {
    row.style.display = estado === 'todas' || row.dataset.estado === estado ? '' : 'none';
  });
}

function initSidebarToggle() {
  const sidebar = document.getElementById('appSidebar');
  const toggle = document.getElementById('sidebarToggle');
  const nav = document.getElementById('sidebarNav');
  if (!sidebar || !toggle) return;

  function closeMenu() {
    sidebar.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
  }

  toggle.addEventListener('click', () => {
    const willOpen = !sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', willOpen);
    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  });

  document.addEventListener('click', (event) => {
    if (sidebar.classList.contains('is-open')
      && !sidebar.contains(event.target)
      && event.target !== toggle
      && !toggle.contains(event.target)) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenu();
  });

  if (nav) {
    nav.addEventListener('click', (event) => {
      if (event.target.closest('a')) closeMenu();
    });
  }
}

function initModals() {
  document.querySelectorAll('.modal-backdrop[data-close-href]').forEach((backdrop) => {
    backdrop.addEventListener('click', () => {
      window.location.href = backdrop.dataset.closeHref;
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const open = document.querySelector('.modal-backdrop.is-open[data-close-href]');
    if (open) window.location.href = open.dataset.closeHref;
  });
}

function initPillToggles() {
  // Refuerzo de :has() para navegadores sin soporte: sincroniza la clase
  // is-checked del <label> con el estado real del checkbox interno.
  document.querySelectorAll('.pill-toggle').forEach((label) => {
    const input = label.querySelector('input');
    if (!input) return;
    const sync = () => label.classList.toggle('is-checked', input.checked);
    sync();
    input.addEventListener('change', sync);
  });
}

let comitesAcuerdoContador = 0;

function initAcuerdosEditor() {
  const contenedor = document.getElementById('acuerdosRows');
  const template = document.getElementById('acuerdoRowTemplate');
  const btnAgregar = document.getElementById('btnAgregarAcuerdo');
  if (!contenedor || !template || !btnAgregar) return;

  comitesAcuerdoContador = contenedor.querySelectorAll('[data-acuerdo-row]').length;

  function quitarEmptyState() {
    const vacio = document.getElementById('acuerdosEmptyState');
    if (vacio) vacio.remove();
  }

  function ligarRemove(fila) {
    const btn = fila.querySelector('[data-remove-acuerdo]');
    if (btn) {
      btn.addEventListener('click', () => {
        fila.remove();
      });
    }
  }

  contenedor.querySelectorAll('[data-acuerdo-row]').forEach(ligarRemove);

  btnAgregar.addEventListener('click', () => {
    const idx = comitesAcuerdoContador++;
    const fragmento = template.content.cloneNode(true);
    const fila = fragmento.querySelector('[data-acuerdo-row]');
    fila.querySelectorAll('[name*="__IDX__"]').forEach((campo) => {
      campo.name = campo.name.replace('__IDX__', String(idx));
    });
    quitarEmptyState();
    contenedor.appendChild(fragmento);
    ligarRemove(contenedor.lastElementChild);
  });
}

function initAttendeeFilter() {
  const select = document.getElementById('reunionComiteSelect');
  const lista = document.getElementById('attendeeList');
  if (!select || !lista) return;

  function aplicarFiltro() {
    const comiteId = select.value;
    lista.querySelectorAll('.attendee-item').forEach((item) => {
      const comites = (item.dataset.comites || '').split(',').filter(Boolean);
      const coincide = comites.length === 0 || comites.includes(comiteId);
      item.classList.toggle('is-hidden', !coincide);
    });
  }

  select.addEventListener('change', aplicarFiltro);
  aplicarFiltro();
}

function initAgregarAsistenteRapido() {
  const boton = document.getElementById('btnAgregarAsistente');
  const lista = document.getElementById('attendeeList');
  const select = document.getElementById('reunionComiteSelect');
  const responsableInput = document.getElementById('reunionResponsableInput');
  const msg = document.getElementById('extraAsistenteMsg');
  if (!boton || !lista || !select) return;

  boton.addEventListener('click', async () => {
    const nombre = document.getElementById('extraNombre').value.trim();
    const cargo = document.getElementById('extraCargo').value.trim();
    const empresa = document.getElementById('extraEmpresa').value.trim();
    const email = document.getElementById('extraEmail').value.trim();

    if (!nombre) {
      if (msg) { msg.textContent = 'Escribe el nombre del asistente.'; msg.classList.add('is-error'); }
      return;
    }

    boton.disabled = true;
    try {
      const body = new URLSearchParams({ nombre, cargo, empresa, email, comite_id: select.value });
      const resp = await fetch('ajax/agregar_asistente.php', { method: 'POST', body });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        throw new Error(data.error || 'No fue posible guardar el asistente.');
      }

      const item = document.createElement('label');
      item.className = 'attendee-item';
      item.dataset.comites = (data.contacto.comites || []).join(',');
      item.innerHTML = '<input type="checkbox" name="asistentes[]" checked>' +
        '<span>' + escapeHtmlComites(data.contacto.nombre) + '<small>' + escapeHtmlComites(data.contacto.cargo || 'Contacto adicional') + '</small></span>';
      item.querySelector('input').value = String(data.contacto.id);
      lista.appendChild(item);

      if (responsableInput && !responsableInput.value) {
        responsableInput.value = data.contacto.nombre;
      }

      document.getElementById('extraNombre').value = '';
      document.getElementById('extraCargo').value = '';
      document.getElementById('extraEmpresa').value = '';
      document.getElementById('extraEmail').value = '';
      if (msg) { msg.textContent = 'Asistente agregado a la base de datos.'; msg.classList.remove('is-error'); }
    } catch (err) {
      if (msg) { msg.textContent = err.message; msg.classList.add('is-error'); }
    } finally {
      boton.disabled = false;
    }
  });
}

function escapeHtmlComites(value) {
  const div = document.createElement('div');
  div.textContent = value || '';
  return div.innerHTML;
}

/** Menu desplegable de acciones por fila: posicion fija para no quedar recortado por el scroll de la tabla. */
function initRowMenus() {
  const closeMenus = (except) => document.querySelectorAll('details.row-menu[open]').forEach((d) => { if (d !== except) d.open = false; });
  document.addEventListener('toggle', (event) => {
    const menu = event.target;
    if (!menu.matches || !menu.matches('details.row-menu') || !menu.open) return;
    closeMenus(menu);
    const box = menu.querySelector('summary').getBoundingClientRect();
    const list = menu.querySelector('.row-menu-list');
    list.style.left = `${Math.max(8, box.right - list.offsetWidth)}px`;
    const below = box.bottom + 4;
    const height = list.offsetHeight;
    list.style.top = `${below + height > window.innerHeight - 8 ? Math.max(8, box.top - height - 4) : below}px`;
  }, true);
  document.addEventListener('click', (event) => { if (!event.target.closest('details.row-menu')) closeMenus(); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenus(); });
  window.addEventListener('scroll', () => closeMenus(), true);
  window.addEventListener('resize', () => closeMenus());
}

/** Formulario de redes: muestra las metricas (y los destinos de WhatsApp) que corresponden a la plataforma. */
function initRedesForm() {
  const select = document.getElementById('red-plataforma');
  const config = document.getElementById('red-metricas-config');
  if (!select || !config) return;
  const metricas = JSON.parse(config.textContent);
  const update = () => {
    const actual = metricas[select.value] || metricas.Otro;
    document.querySelectorAll('#red-form [data-metrica]').forEach((label) => {
      const campo = label.dataset.metrica;
      label.hidden = !actual[campo];
      if (actual[campo]) label.querySelector('.form-label').textContent = actual[campo];
    });
    document.querySelectorAll('#red-form [data-solo-whatsapp]').forEach((el) => { el.hidden = select.value !== 'WhatsApp'; });
  };
  select.addEventListener('change', update);
}

/**
 * Redes por API: si alguna conexion tiene el intervalo vencido, sincroniza en
 * segundo plano y ofrece recargar cuando hay datos nuevos (no recarga sola
 * para no interrumpir lo que el usuario este haciendo).
 */
function initRedesSync() {
  document.querySelectorAll('[data-sync-btn]').forEach((btn) => btn.closest('form').addEventListener('submit', (event) => {
    if (event.submitter !== btn) return;
    btn.classList.add('is-loading');
    btn.lastChild.textContent = ' Sincronizando…';
  }));
  const bar = document.getElementById('red-sync');
  if (!bar || bar.dataset.auto !== '1') return;
  const estado = document.getElementById('red-sync-estado');
  estado.hidden = false;
  const body = new FormData();
  body.set('redes_auto', '1');
  body.set('csrf', bar.dataset.csrf);
  fetch('index.php?view=publicaciones', { method: 'POST', body })
    .then((r) => r.json())
    .then((data) => {
      if (data.ok && data.actualizado) {
        estado.innerHTML = '<i class="ti ti-circle-check"></i> Métricas actualizadas <button type="button">Recargar</button>';
        estado.querySelector('button').addEventListener('click', () => window.location.reload());
      } else {
        estado.hidden = true;
      }
    })
    .catch(() => { estado.hidden = true; });
}

/** Campanita: abre y cierra el panel de notificaciones (clic fuera o Esc lo cierra). */
function initNotifications() {
  const toggle = document.getElementById('notifToggle');
  const panel = document.getElementById('notifPanel');
  if (!toggle || !panel) return;
  const setOpen = (open) => {
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.classList.toggle('is-open', open);
  };
  toggle.addEventListener('click', (event) => {
    event.stopPropagation();
    setOpen(panel.hidden);
  });
  document.addEventListener('click', (event) => {
    if (!panel.hidden && !panel.contains(event.target)) setOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) {
      setOpen(false);
      toggle.focus();
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initNotifications();
  initRowMenus();
  initRedesForm();
  initRedesSync();
  initSidebarToggle();
  initModals();
  initPillToggles();
  initAcuerdosEditor();
  initAttendeeFilter();
  initAgregarAsistenteRapido();
});
