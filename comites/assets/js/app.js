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

document.addEventListener('DOMContentLoaded', () => {
  initSidebarToggle();
  initModals();
  initPillToggles();
  initAcuerdosEditor();
  initAttendeeFilter();
  initAgregarAsistenteRapido();
});
