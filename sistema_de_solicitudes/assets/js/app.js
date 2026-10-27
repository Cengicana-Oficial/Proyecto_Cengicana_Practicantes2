function filterRows(inputId, tableId) {
  const query = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll(`#${tableId} tbody tr`).forEach((row) => {
    row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
  });
}

function filterPrograms(inputId, gridId) {
  const query = document.getElementById(inputId).value.trim().toLowerCase();
  let visible = 0;
  document.querySelectorAll(`#${gridId} .program-admin-card`).forEach((card) => {
    const match = (card.dataset.name || '').includes(query);
    card.classList.toggle('hidden', !match);
    if (match) visible += 1;
  });
  const empty = document.getElementById(`${gridId}-vacio`);
  if (empty) empty.classList.toggle('hidden', visible > 0);
}

function initProgramCards() {
  document.querySelectorAll('.program-admin-name').forEach((input) => {
    const card = input.closest('.program-admin-card');
    input.addEventListener('input', () => {
      card.classList.toggle('is-dirty', input.value.trim() !== input.dataset.original);
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        input.value = input.dataset.original;
        card.classList.remove('is-dirty');
        input.blur();
      }
    });
  });
}

function setStatusFilter(button, tableId, estado) {
  button.closest('.filters').querySelectorAll('.filter-chip').forEach((chip) => chip.classList.remove('active'));
  button.classList.add('active');
  document.querySelectorAll(`#${tableId} tbody tr`).forEach((row) => {
    row.style.display = estado === 'todas' || row.dataset.estado === estado ? '' : 'none';
  });
}

function updateTipoForm() {
  const tipo = document.getElementById('tipo').value;
  const campoProgramaDestino = document.getElementById('campo-programa-destino');
  const programaOrigen = document.getElementById('programa_origen_id');
  const programaOrigenLabel = document.getElementById('programa_origen_label');
  const programaOrigenHint = document.getElementById('programa_origen_hint');
  const programaDestino = document.getElementById('programa_destino_id');

  campoProgramaDestino.classList.toggle('hidden', tipo !== 'apoyo');
  document.getElementById('campos-compra').classList.toggle('hidden', tipo !== 'compra');

  if (!programaOrigen) return;

  const administracionOption = Array.from(programaOrigen.options).find((option) => {
    return option.dataset.programName === 'administracion';
  });

  if (tipo === 'compra' || tipo === 'ti') {
    if (administracionOption) programaOrigen.value = administracionOption.value;
    programaOrigen.disabled = true;
    programaOrigen.classList.add('is-locked');
    if (programaOrigenLabel) {
      programaOrigenLabel.innerHTML = 'Area responsable <span class="req">*</span>';
    }
    if (programaOrigenHint) {
      programaOrigenHint.textContent = 'Compras y Soporte TI se asignan automaticamente a Administracion.';
    }
  } else {
    if (tipo === 'apoyo' && programaOrigen.dataset.userProgramId) {
      programaOrigen.value = programaOrigen.dataset.userProgramId;
    }
    programaOrigen.disabled = false;
    programaOrigen.classList.remove('is-locked');
    if (programaOrigenLabel) {
      programaOrigenLabel.innerHTML = 'Area / programa origen <span class="req">*</span>';
    }
    if (programaOrigenHint) {
      programaOrigenHint.textContent = tipo === 'apoyo'
        ? 'Para apoyo, la solicitud sale desde tu area y se envia al area seleccionada abajo.'
        : '';
    }
  }

  if (programaDestino) {
    programaDestino.required = tipo === 'apoyo';
    if (tipo !== 'apoyo') programaDestino.value = '';
  }
}

function updatePermissionPanels() {
  const app = document.querySelector('.app');
  const rolesWithManualPermissions = app?.dataset.manualPermissionRoles
    ? JSON.parse(app.dataset.manualPermissionRoles)
    : [];

  document.querySelectorAll('[data-permission-role]').forEach((select) => {
    const targetId = select.dataset.targetPanel;
    const panel = targetId
      ? document.getElementById(targetId)
      : select.closest('.form-grid')?.querySelector('[data-permission-panel]');

    if (!panel) return;
    const isManual = rolesWithManualPermissions.includes(select.value);
    panel.classList.toggle('permissions-readonly', !isManual);
    panel.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
      checkbox.disabled = !isManual;
    });

    const hint = panel.querySelector('[data-permission-hint]');
    if (hint) {
      hint.textContent = isManual
        ? 'Puedes ajustar permisos para este rol.'
        : 'Permisos automaticos por jerarquia del rol.';
    }
  });
}

function initUserSearch() {
  const input = document.getElementById('agregar_usuario_buscar');
  const hidden = document.getElementById('agregar_usuario_id');
  const results = document.getElementById('agregar_usuario_resultados');
  const hint = document.getElementById('agregar_usuario_hint');
  if (!input || !hidden || !results) return;

  let users = [];
  try {
    users = JSON.parse(input.dataset.users || '[]');
  } catch (e) {
    users = [];
  }

  const MAX_MATCHES = 5;
  const defaultHint = hint ? hint.textContent : '';
  let matches = [];
  let activeIndex = -1;

  function setHint(text, isError) {
    if (!hint) return;
    hint.textContent = text;
    hint.classList.toggle('form-hint-error', Boolean(isError));
  }

  function closeResults() {
    results.hidden = true;
    results.innerHTML = '';
    matches = [];
    activeIndex = -1;
  }

  function selectUser(user) {
    hidden.value = String(user.id);
    input.value = user.label;
    input.classList.add('is-selected');
    // Limpiar la validacion personalizada: si quedaba marcada de un intento
    // anterior, el navegador bloqueaba el envio sin disparar el evento submit.
    input.setCustomValidity('');
    setHint('Usuario seleccionado. Ya puedes agregarlo al modulo.', false);
    closeResults();
  }

  function clearSelection() {
    hidden.value = '';
    input.classList.remove('is-selected');
    input.setCustomValidity('');
    setHint(defaultHint, false);
  }

  function findMatches(query) {
    const normalized = query.trim().toLowerCase();
    if (normalized === '') return [];
    return users.filter((u) => u.label.toLowerCase().includes(normalized));
  }

  function highlight(index) {
    activeIndex = index;
    results.querySelectorAll('.user-search-item').forEach((item, i) => {
      item.classList.toggle('is-active', i === index);
    });
  }

  function renderMatches(query) {
    if (query.trim() === '') {
      closeResults();
      return;
    }

    matches = findMatches(query).slice(0, MAX_MATCHES);
    activeIndex = -1;
    results.innerHTML = '';

    if (matches.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'user-search-empty';
      empty.textContent = 'Sin coincidencias.';
      results.appendChild(empty);
    } else {
      matches.forEach((u, index) => {
        const item = document.createElement('div');
        item.className = 'user-search-item';
        item.textContent = u.label;
        // mousedown en lugar de click: evita perder la seleccion por el blur del input.
        item.addEventListener('mousedown', (event) => {
          event.preventDefault();
          selectUser(u);
        });
        item.addEventListener('mouseenter', () => highlight(index));
        results.appendChild(item);
      });
      highlight(0);
    }

    results.hidden = false;
  }

  function showError(message) {
    input.setCustomValidity(message);
    input.reportValidity();
    setHint(message, true);
    input.focus();
  }

  input.addEventListener('input', () => {
    clearSelection();
    renderMatches(input.value);
  });

  input.addEventListener('focus', () => {
    if (input.value.trim() !== '' && hidden.value === '') {
      renderMatches(input.value);
    }
  });

  input.addEventListener('keydown', (event) => {
    if (results.hidden || matches.length === 0) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      highlight((activeIndex + 1) % matches.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlight((activeIndex - 1 + matches.length) % matches.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      selectUser(matches[activeIndex >= 0 ? activeIndex : 0]);
    } else if (event.key === 'Escape') {
      closeResults();
    }
  });

  document.addEventListener('click', (event) => {
    if (event.target !== input && !results.contains(event.target)) {
      closeResults();
    }
  });

  const form = input.closest('form');
  if (form) {
    form.addEventListener('submit', (event) => {
      if (hidden.value) return;

      // Si lo escrito identifica a un solo usuario (o coincide exacto), seleccionarlo.
      const typed = input.value.trim().toLowerCase();
      const candidates = findMatches(input.value);
      const exact = candidates.find((u) => u.label.toLowerCase() === typed);
      const pick = exact || (candidates.length === 1 ? candidates[0] : null);
      if (pick) {
        selectUser(pick);
        return;
      }

      event.preventDefault();
      if (typed === '') {
        showError('Escribe el nombre o correo del usuario que quieres agregar.');
      } else if (candidates.length === 0) {
        showError('No hay usuarios disponibles que coincidan con lo escrito.');
      } else {
        showError('Hay varias coincidencias: selecciona un usuario de la lista.');
        renderMatches(input.value);
      }
    });
  }
}

function initRowActions() {
  const toggles = document.querySelectorAll('.row-actions-toggle');
  if (!toggles.length) return;

  let openMenu = null;

  function closeMenu() {
    if (!openMenu) return;
    openMenu.menu.hidden = true;
    openMenu.toggle.setAttribute('aria-expanded', 'false');
    openMenu.toggle.classList.remove('is-open');
    openMenu = null;
  }

  // El menu usa position: fixed para que no lo recorte el scroll horizontal de la tabla.
  function positionMenu(toggle, menu) {
    const rect = toggle.getBoundingClientRect();
    menu.style.minWidth = `${Math.max(rect.width, 190)}px`;
    const menuHeight = menu.offsetHeight;
    const menuWidth = menu.offsetWidth;
    const openUp = rect.bottom + menuHeight + 8 > window.innerHeight && rect.top > menuHeight + 8;
    menu.style.top = `${openUp ? rect.top - menuHeight - 4 : rect.bottom + 4}px`;
    menu.style.left = `${Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8))}px`;
  }

  toggles.forEach((toggle) => {
    const menu = toggle.parentElement.querySelector('.row-actions-menu');
    if (!menu) return;

    toggle.addEventListener('click', (event) => {
      event.stopPropagation();
      const wasOpen = openMenu && openMenu.menu === menu;
      closeMenu();
      if (wasOpen) return;
      menu.hidden = false;
      positionMenu(toggle, menu);
      toggle.setAttribute('aria-expanded', 'true');
      toggle.classList.add('is-open');
      openMenu = { toggle, menu };
      const first = menu.querySelector('.row-actions-item');
      if (first) first.focus({ preventScroll: true });
    });

    menu.addEventListener('keydown', (event) => {
      const items = Array.from(menu.querySelectorAll('.row-actions-item'));
      const index = items.indexOf(document.activeElement);
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        items[(index + 1) % items.length].focus();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        items[(index - 1 + items.length) % items.length].focus();
      } else if (event.key === 'Escape') {
        closeMenu();
        toggle.focus();
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (openMenu && !openMenu.menu.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenu();
  });
  window.addEventListener('resize', closeMenu);
  window.addEventListener('scroll', closeMenu, true);

  // Al cambiar el programa o el rol de un usuario, avisar que hay un cambio pendiente de guardar.
  document.querySelectorAll('.user-program-select').forEach((select) => {
    const row = select.closest('tr');
    select.addEventListener('change', () => {
      const changed = Array.from(row.querySelectorAll('.user-program-select'))
        .some((field) => field.value !== (field.dataset.original || ''));
      row.classList.toggle('is-dirty', changed);
    });
  });
}

function initNotifications() {
  const toggle = document.getElementById('notifToggle');
  const panel = document.getElementById('notifPanel');
  if (!toggle || !panel) return;

  function setOpen(open) {
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.classList.toggle('is-open', open);
  }

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

document.addEventListener('DOMContentLoaded', () => {
  initSidebarToggle();
  initUserSearch();
  initProgramCards();
  initRowActions();
  initNotifications();

  const tipo = document.getElementById('tipo');
  if (tipo) {
    tipo.addEventListener('change', updateTipoForm);
    updateTipoForm();
  }

  document.querySelectorAll('[data-permission-role]').forEach((select) => {
    select.addEventListener('change', updatePermissionPanels);
  });
  updatePermissionPanels();

  const seguimientoAdjuntos = document.getElementById('seguimiento_adjuntos');
  if (seguimientoAdjuntos) {
    seguimientoAdjuntos.addEventListener('change', () => {
      const list = document.getElementById('seguimiento_adjuntos_list');
      list.innerHTML = '';
      Array.from(seguimientoAdjuntos.files).forEach((file) => {
        const item = document.createElement('div');
        item.className = 'file-selected-pill';
        item.innerHTML = `<i class="ti ti-file"></i>${file.name}`;
        list.appendChild(item);
      });
    });
  }
});

// Buzon de ideas: filtro por estado + busqueda de texto.
function filterIdeas() {
  const list = document.getElementById('lista-ideas');
  if (!list) return;
  const query = (document.getElementById('buscar-ideas').value || '').trim().toLowerCase();
  const chip = document.querySelector('.filters .filter-chip.active');
  const estado = chip ? chip.dataset.estado : 'todas';
  let visible = 0;
  list.querySelectorAll('.idea-card').forEach((card) => {
    const match = (estado === 'todas' || card.dataset.estado === estado) && card.textContent.toLowerCase().includes(query);
    card.classList.toggle('hidden', !match);
    if (match) visible += 1;
  });
  const empty = document.getElementById('lista-ideas-vacio');
  if (empty) empty.classList.toggle('hidden', visible > 0 || list.children.length === 0);
}

function setIdeaFilter(button) {
  button.closest('.filters').querySelectorAll('.filter-chip').forEach((chip) => chip.classList.remove('active'));
  button.classList.add('active');
  filterIdeas();
}

function copyBuzonLink(button) {
  const input = document.getElementById('buzon-url');
  const done = () => {
    const original = button.innerHTML;
    button.innerHTML = '<i class="ti ti-check"></i>Copiado';
    setTimeout(() => { button.innerHTML = original; }, 2000);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(input.value).then(done, () => { input.select(); document.execCommand('copy'); done(); });
  } else {
    input.select();
    document.execCommand('copy');
    done();
  }
}
