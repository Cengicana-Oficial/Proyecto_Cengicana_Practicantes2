// Listas de solicitudes (Enviadas / Recibidas): busqueda + estado + tipo + prioridad
// combinados, orden por columna, conteo y filtros recordados en la URL.
function initRequestLists() {
  const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  document.querySelectorAll('[data-request-list]').forEach((list) => {
    const search = list.querySelector('[data-list-search]');
    const tipo = list.querySelector('[data-list-tipo]');
    const prioridad = list.querySelector('[data-list-prioridad]');
    const chips = Array.from(list.querySelectorAll('[data-list-estado]'));
    const tbody = list.querySelector('tbody');
    const rows = Array.from(tbody.rows);
    const count = list.querySelector('[data-list-count]');
    const empty = list.querySelector('[data-list-empty]');
    const fallback = list.dataset.default || 'todas';
    let estado = fallback;
    let sort = { key: '', dir: 1 };

    // Restaurar filtros desde la URL (?estado=&q=&tipo=&prioridad=&orden=).
    const params = new URLSearchParams(location.search);
    if (params.has('estado') && chips.some((chip) => chip.dataset.listEstado === params.get('estado'))) estado = params.get('estado');
    search.value = params.get('q') || '';
    tipo.value = params.get('tipo') || '';
    prioridad.value = params.get('prioridad') || '';
    const orden = (params.get('orden') || '').split(':');
    if (orden[0]) sort = { key: orden[0], dir: orden[1] === 'asc' ? 1 : -1 };

    const matchesEstado = (row) => estado === 'todas'
      || (estado === 'abiertas' && row.dataset.abierta === '1')
      || (estado === 'vencidas' && row.dataset.vencida === '1')
      || row.dataset.estado === estado;

    const saveUrl = () => {
      const url = new URL(location.href);
      const set = (key, value, empty) => (value && value !== empty ? url.searchParams.set(key, value) : url.searchParams.delete(key));
      set('estado', estado, fallback);
      set('q', search.value.trim(), '');
      set('tipo', tipo.value, '');
      set('prioridad', prioridad.value, '');
      set('orden', sort.key ? sort.key + ':' + (sort.dir === 1 ? 'asc' : 'desc') : '', '');
      history.replaceState(null, '', url);
    };

    const apply = () => {
      const terms = normalize(search.value).split(' ').filter(Boolean);
      let visible = 0;
      rows.forEach((row) => {
        const show = matchesEstado(row)
          && (!tipo.value || row.dataset.tipo === tipo.value)
          && (!prioridad.value || row.dataset.prioridad === prioridad.value)
          && terms.every((term) => row.dataset.buscar.includes(term));
        row.hidden = !show;
        if (show) visible++;
      });
      chips.forEach((chip) => {
        const active = chip.dataset.listEstado === estado;
        chip.classList.toggle('active', active);
        chip.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      count.textContent = rows.length ? visible + ' de ' + rows.length : '';
      if (rows.length) empty.classList.toggle('hidden', visible > 0);
      saveUrl();
    };

    const applySort = () => {
      list.querySelectorAll('[data-sort]').forEach((button) => {
        const th = button.closest('th');
        if (button.dataset.sort === sort.key) th.setAttribute('aria-sort', sort.dir === 1 ? 'ascending' : 'descending');
        else th.removeAttribute('aria-sort');
      });
      if (!sort.key) return;
      const numeric = ['prioridad', 'estado'].includes(sort.key);
      const value = (row) => row.dataset['k' + sort.key.charAt(0).toUpperCase() + sort.key.slice(1)] || '';
      rows.slice().sort((a, b) => {
        const diff = numeric ? Number(value(a)) - Number(value(b)) : value(a).localeCompare(value(b), 'es');
        return diff * sort.dir;
      }).forEach((row) => tbody.appendChild(row));
    };

    let timer;
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(apply, 120); });
    tipo.addEventListener('change', apply);
    prioridad.addEventListener('change', apply);
    chips.forEach((chip) => chip.addEventListener('click', () => { estado = chip.dataset.listEstado; apply(); }));
    list.querySelectorAll('[data-sort]').forEach((button) => button.addEventListener('click', () => {
      const key = button.dataset.sort;
      // Primer clic: lo mas relevante primero (prioridad alta, actividad reciente); fechas requeridas, la mas proxima.
      const firstDir = ['prioridad', 'actividad'].includes(key) ? -1 : 1;
      sort = sort.key === key ? { key, dir: -sort.dir } : { key, dir: firstDir };
      applySort();
      saveUrl();
    }));
    const reset = list.querySelector('[data-list-reset]');
    if (reset) reset.addEventListener('click', () => {
      estado = 'todas'; search.value = ''; tipo.value = ''; prioridad.value = '';
      apply();
      search.focus();
    });
    // Clic en cualquier parte de la fila abre el detalle (sin romper seleccion de texto ni enlaces).
    tbody.addEventListener('click', (event) => {
      if (event.target.closest('a, button') || String(window.getSelection())) return;
      const row = event.target.closest('tr');
      const link = row && row.querySelector('a.request-code');
      if (!link) return;
      if (event.ctrlKey || event.metaKey) window.open(link.href, '_blank');
      else location.href = link.href;
    });
    // "/" enfoca la busqueda.
    document.addEventListener('keydown', (event) => {
      if (event.key !== '/' || event.target.closest('input, textarea, select')) return;
      event.preventDefault();
      search.focus();
    });

    applySort();
    apply();
  });
}
document.addEventListener('DOMContentLoaded', initRequestLists);

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

// Seguimiento de idea: filtra la lista de posibles responsables.
function filterIdeaResponsables(input) {
  const query = input.value.trim().toLowerCase();
  const list = input.form.querySelector('.idea-responsables-list');
  const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const needle = normalize(query);
  let visible = 0;
  list.querySelectorAll('.permission-option').forEach((option) => {
    const show = normalize(option.textContent).includes(needle);
    option.classList.toggle('hidden', !show);
    if (show) visible++;
  });
  const empty = list.querySelector('[data-responsables-vacio]');
  if (empty) empty.classList.toggle('hidden', visible > 0);
}


// Seguimiento de idea: al ir a un paso (barra superior o #paso-x), abrirlo.
function openIdeaStepFromHash() {
  if (!location.hash.startsWith('#paso-')) return;
  const target = document.querySelector(location.hash);
  const step = target && target.closest('details.idea-step');
  if (step) step.open = true;
}
window.addEventListener('hashchange', openIdeaStepFromHash);
document.addEventListener('DOMContentLoaded', openIdeaStepFromHash);

// Ponderacion de idea: puntaje en vivo (misma formula que idea_puntaje() en PHP).
document.addEventListener('change', (event) => {
  const form = event.target.closest('#form-ponderacion');
  if (!form || event.target.type !== 'radio') return;
  const live = document.getElementById('puntaje-vivo');
  let total = 0;
  let completo = true;
  form.querySelectorAll('.idea-criterio').forEach((criterio) => {
    const marcado = criterio.querySelector('input:checked');
    if (!marcado) { completo = false; return; }
    total += (Number(marcado.value) / 5) * Number(criterio.dataset.peso);
  });
  const puntaje = Math.round(total * 10) / 10;
  const alta = Number(live.dataset.alta);
  const media = Number(live.dataset.media);
  const [clave, texto] = !completo ? ['pendiente', 'Califica todos los criterios']
    : puntaje >= alta ? ['alta', 'Recomendada para implementacion']
    : puntaje >= media ? ['media', 'Con reservas'] : ['baja', 'No recomendada'];
  live.className = 'idea-score-live is-' + clave;
  live.querySelector('.idea-score-num strong').textContent = completo ? String(puntaje) : '—';
  live.querySelector('.idea-score-meter span').style.width = (completo ? puntaje : total) + '%';
  live.querySelector('.idea-score-label').textContent = texto;
});

// Buzon de ideas: ordenar por fecha (id) o por puntaje de ponderacion.
function sortIdeas(criterio) {
  const list = document.getElementById('lista-ideas');
  if (!list) return;
  const cards = Array.from(list.querySelectorAll('.idea-card'));
  cards.sort((a, b) => criterio === 'puntaje'
    ? Number(b.dataset.puntaje) - Number(a.dataset.puntaje) || Number(b.dataset.orden) - Number(a.dataset.orden)
    : Number(b.dataset.orden) - Number(a.dataset.orden));
  cards.forEach((card) => list.appendChild(card));
}

// Presupuesto de implementacion: filas y total calculado.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-costos]').forEach((container) => {
    const add = container.querySelector('[data-agregar-costo]');
    if (!add) return;
    const body = container.querySelector('tbody');
    const field = container.closest('.idea-field') || container.parentElement;
    const totalInput = field.querySelector('[name="costo_estimado"]');
    const hint = field.querySelector('[data-costo-hint]');
    const totalCell = container.querySelector('[data-costos-total]');
    const summary = container.querySelector('[data-costos-resumen]');
    const emptyRow = container.querySelector('[data-costos-vacio]');
    const money = new Intl.NumberFormat('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const rows = () => Array.from(body.querySelectorAll('[data-costo-fila]'));
    let index = rows().length;
    const update = () => {
      let cents = 0;
      const byCategory = new Map();
      rows().forEach((row) => {
        const quantity = Number(row.querySelector('[name$="[cantidad]"]').value) || 0;
        const price = Number(row.querySelector('[name$="[precio]"]').value) || 0;
        const subtotal = Math.round(quantity * price * 100);
        const category = row.querySelector('[name$="[categoria]"]').value;
        cents += subtotal;
        byCategory.set(category, (byCategory.get(category) || 0) + subtotal);
        row.querySelector('[data-subtotal]').textContent = money.format(subtotal / 100);
      });
      const count = rows().length;
      if (emptyRow) emptyRow.hidden = count > 0;
      totalCell.textContent = money.format(cents / 100);
      summary.replaceChildren(...Array.from(byCategory).sort((a, b) => b[1] - a[1]).map(([category, amount]) => {
        const chip = document.createElement('span');
        const strong = document.createElement('strong');
        strong.textContent = 'Q ' + money.format(amount / 100);
        chip.append(category + ' ', strong);
        return chip;
      }));
      if (!totalInput) return;
      totalInput.readOnly = count > 0;
      if (count) totalInput.value = (cents / 100).toFixed(2);
      if (hint) hint.textContent = count ? 'Calculado del desglose (' + count + ' item' + (count === 1 ? '' : 's') + ').' : 'Estimacion general (sin desglose).';
    };
    const addRow = (source) => {
      if (rows().length >= 100) {
        alert('El desglose admite hasta 100 items.');
        return;
      }
      const html = container.querySelector('template').innerHTML.replaceAll('__INDEX__', String(index++));
      const holder = document.createElement('tbody');
      holder.innerHTML = html.trim();
      const row = holder.firstElementChild;
      if (source) {
        source.querySelectorAll('[name]').forEach((input) => {
          const key = input.name.slice(input.name.lastIndexOf('['));
          row.querySelector('[name$="' + key + '"]').value = input.value;
        });
        source.after(row);
      } else {
        body.insertBefore(row, emptyRow);
      }
      update();
      row.querySelector('[name$="[descripcion]"]').focus();
      container.dispatchEvent(new Event('input', { bubbles: true }));
    };
    add.addEventListener('click', () => addRow(null));
    container.addEventListener('input', update);
    container.addEventListener('change', update);
    container.addEventListener('click', (event) => {
      const duplicate = event.target.closest('[data-duplicar-costo]');
      if (duplicate) {
        addRow(duplicate.closest('tr'));
        return;
      }
      const remove = event.target.closest('[data-quitar-costo]');
      if (!remove) return;
      const row = remove.closest('tr');
      const description = row.querySelector('[name$="[descripcion]"]').value.trim();
      if (description && !confirm('¿Quitar el item "' + description + '"?')) return;
      row.remove();
      if (!rows().length && totalInput) totalInput.value = '';
      update();
      container.dispatchEvent(new Event('input', { bubbles: true }));
    });
    // Enter en una fila agrega la siguiente en vez de enviar el formulario.
    container.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.target.tagName !== 'INPUT') return;
      event.preventDefault();
      const row = event.target.closest('[data-costo-fila]');
      if (row && row === rows().at(-1)) addRow(null);
    });
    update();
  });
});

// Analisis de idea: progreso en vivo, cambios sin guardar, Ctrl+S y textos que crecen.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-analisis-form]');
  if (!form) return;
  const progress = document.querySelector('[data-analisis-progreso]');
  const status = form.querySelector('[data-dirty-status]');
  const detail = form.querySelector('[name="analisis_detalle"]');
  const responsablesCount = form.querySelector('[data-responsables-count]');
  const snapshot = () => new URLSearchParams(new FormData(form)).toString();
  let initial = snapshot();
  let submitting = false;
  if (status && status.textContent.trim()) initial = '';

  const grow = (area) => {
    area.style.height = 'auto';
    area.style.height = Math.min(area.scrollHeight + 2, 480) + 'px';
  };
  form.querySelectorAll('textarea[data-autogrow]').forEach(grow);

  const filled = (point) => {
    const kind = point.dataset.punto;
    if (kind === 'responsables') {
      // Sin casillas (no gestiona el buzon): se conserva lo que marco el servidor.
      return point.querySelector('input[type=checkbox]')
        ? point.querySelector('input[type=checkbox]:checked') !== null
        : point.classList.contains('is-filled');
    }
    if (kind === 'costo') {
      return point.querySelector('[data-costo-fila]') !== null
        || Array.from(point.querySelectorAll('[name="costo_estimado"], [name="costo_detalle"]')).some((el) => el.value.trim() !== '');
    }
    const area = point.querySelector('textarea');
    return area ? area.value.trim() !== '' : point.classList.contains('is-filled');
  };

  const refresh = () => {
    let count = 0;
    document.querySelectorAll('#paso-analisis [data-punto]').forEach((point) => {
      const ok = filled(point);
      point.classList.toggle('is-filled', ok);
      if (ok) count++;
    });
    if (progress) {
      const total = Number(progress.dataset.total) || 1;
      progress.querySelector('[data-analisis-llenos]').textContent = String(count);
      progress.querySelector('.idea-progress-bar span').style.width = Math.round(count * 100 / total) + '%';
    }
    if (responsablesCount) {
      responsablesCount.textContent = form.querySelectorAll('[name="responsables[]"]:checked').length + ' seleccionado(s)';
    }
    if (detail) {
      const requires = form.querySelector('[name="requiere_analisis"]:checked');
      detail.required = Boolean(requires && requires.value === '1');
    }
    const dirty = snapshot() !== initial;
    if (status) status.textContent = dirty ? 'Cambios sin guardar' : '';
    form.classList.toggle('is-dirty', dirty);
    return dirty;
  };

  form.addEventListener('input', (event) => {
    if (event.target.matches('textarea[data-autogrow]')) grow(event.target);
    refresh();
  });
  form.addEventListener('change', refresh);
  form.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('beforeunload', (event) => {
    if (submitting || !refresh()) return;
    event.preventDefault();
    event.returnValue = '';
  });
  document.addEventListener('keydown', (event) => {
    if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 's') return;
    if (!form.closest('details')?.open) return;
    event.preventDefault();
    form.requestSubmit(form.querySelector('.btn-primary[type=submit]'));
  });
  // Los textareas dentro de un <details> cerrado no tienen altura: ajustarlos al abrir.
  form.closest('details')?.addEventListener('toggle', () => form.querySelectorAll('textarea[data-autogrow]').forEach(grow));
  refresh();
});

// Detalle de solicitud: ayuda segun el estado elegido, motivo obligatorio al rechazar,
// confirmacion al completar (ya no se podra modificar) y tamano maximo de adjuntos.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-request-update]');
  if (!form) return;
  const current = form.dataset.estadoActual;
  const observation = form.querySelector('[name="observacion"]');
  const hint = form.querySelector('[data-estado-hint]');
  const requiredMark = form.querySelector('[data-obs-required]');
  const files = form.querySelector('[name="seguimiento_adjuntos[]"]');
  const hints = {
    recibido: 'La solicitud vuelve a quedar como recibida.',
    proceso: 'Indica en que se esta trabajando; el solicitante lo vera en su historial.',
    completado: 'Al completarla ya no se podra modificar. Puedes adjuntar el documento de cierre.',
    rechazado: 'Explica el motivo del rechazo: es obligatorio y el solicitante lo vera.',
  };
  const selected = () => (form.querySelector('[name="estado"]:checked') || {}).value;
  const refresh = () => {
    const status = selected();
    const text = status === current ? 'Sin cambio de estado: se registrara solo la observacion o los documentos.' : (hints[status] || '');
    hint.textContent = text;
    observation.required = status === 'rechazado';
    requiredMark.classList.toggle('hidden', status !== 'rechazado');
  };
  // El tamano y tipo de los adjuntos los valida la zona de archivos (initDropzones).
  form.addEventListener('change', (event) => {
    if (event.target.name === 'estado') refresh();
  });
  form.addEventListener('submit', (event) => {
    const status = selected();
    if (status === current && !observation.value.trim() && !files.files.length) {
      event.preventDefault();
      observation.setCustomValidity('Escribe una observacion, adjunta un documento o cambia el estado.');
      observation.reportValidity();
      return;
    }
    if (status === 'completado' && !confirm('¿Marcar la solicitud como completada? Despues ya no se podra modificar.')) {
      event.preventDefault();
      return;
    }
    const submit = form.querySelector('[type=submit]');
    submit.disabled = true;
    if (files.files.length) submit.innerHTML = '<i class="ti ti-loader-2 spin"></i>Subiendo ' + files.files.length + ' archivo' + (files.files.length === 1 ? '' : 's') + '...';
  });
  observation.addEventListener('input', () => observation.setCustomValidity(''));
  refresh();
});

// "Volver" desde el detalle: si se llego desde una lista, regresar con sus filtros.
document.addEventListener('DOMContentLoaded', () => {
  const back = document.querySelector('[data-back-to-list]');
  if (!back || !document.referrer) return;
  try {
    const from = new URL(document.referrer);
    if (from.origin === location.origin && from.pathname === location.pathname && ['mis', 'recibidas'].includes(from.searchParams.get('view'))) {
      back.href = from.href;
    }
  } catch (error) { /* referrer invalido: se deja el enlace por defecto */ }
});

// Zona de adjuntos (render_dropzone en PHP): arrastrar y soltar, lista de archivos con
// vista previa de imagenes, quitar uno a uno y validacion de tipo, tamano y total
// (post_max_size) antes de enviar. Los archivos validos viven en el <input type="file">.
function initDropzones() {
  const icons = { pdf: 'ti-file-type-pdf', doc: 'ti-file-type-doc', docx: 'ti-file-type-doc', xls: 'ti-file-spreadsheet', xlsx: 'ti-file-spreadsheet', jpg: 'ti-photo', jpeg: 'ti-photo', png: 'ti-photo' };
  const formatSize = (bytes) => (bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB');
  const extension = (name) => (name.includes('.') ? name.split('.').pop().toLowerCase() : '');

  document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type=file]');
    const list = zone.querySelector('[data-dropzone-files]');
    const errorBox = zone.querySelector('[data-dropzone-error]');
    const maxBytes = Number(zone.dataset.maxBytes);
    const maxTotal = Number(zone.dataset.maxTotal);
    const maxFiles = Number(zone.dataset.maxFiles) || 20;
    const allowed = (zone.dataset.ext || '').split(',');
    const files = [];
    const previews = new Map();

    const sync = () => {
      const transfer = new DataTransfer();
      files.forEach((file) => transfer.items.add(file));
      input.files = transfer.files;
    };

    const render = () => {
      list.replaceChildren();
      const total = files.reduce((sum, file) => sum + file.size, 0);
      files.forEach((file, index) => {
        const ext = extension(file.name);
        const item = document.createElement('li');
        item.className = 'dropzone-file';
        const thumb = document.createElement('span');
        thumb.className = 'dropzone-thumb';
        if (['jpg', 'jpeg', 'png'].includes(ext)) {
          if (!previews.has(file)) previews.set(file, URL.createObjectURL(file));
          const img = document.createElement('img');
          img.src = previews.get(file);
          img.alt = '';
          thumb.appendChild(img);
        } else {
          const icon = document.createElement('i');
          icon.className = 'ti ' + (icons[ext] || 'ti-file');
          thumb.appendChild(icon);
          thumb.dataset.ext = ext;
        }
        const info = document.createElement('span');
        info.className = 'dropzone-file-info';
        const name = document.createElement('strong');
        name.textContent = file.name;
        name.title = file.name;
        const size = document.createElement('small');
        size.textContent = formatSize(file.size) + ' · ' + (ext ? ext.toUpperCase() : 'archivo');
        info.append(name, size);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'dropzone-remove';
        remove.setAttribute('aria-label', 'Quitar ' + file.name);
        remove.title = 'Quitar';
        remove.innerHTML = '<i class="ti ti-x"></i>';
        remove.addEventListener('click', () => {
          if (previews.has(file)) URL.revokeObjectURL(previews.get(file));
          previews.delete(file);
          files.splice(index, 1);
          sync();
          render();
          showErrors([]);
          input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        item.append(thumb, info, remove);
        list.appendChild(item);
      });
      if (files.length) {
        const summary = document.createElement('li');
        summary.className = 'dropzone-summary';
        const text = document.createElement('span');
        text.textContent = files.length + ' archivo' + (files.length === 1 ? '' : 's') + ' · ' + formatSize(total) + ' de ' + formatSize(maxTotal);
        const meter = document.createElement('span');
        meter.className = 'dropzone-meter';
        const bar = document.createElement('span');
        bar.style.width = Math.min(100, Math.round(total * 100 / maxTotal)) + '%';
        meter.appendChild(bar);
        summary.append(text, meter);
        list.appendChild(summary);
      }
      zone.classList.toggle('has-files', files.length > 0);
    };

    const showErrors = (messages) => {
      errorBox.replaceChildren();
      errorBox.classList.toggle('hidden', messages.length === 0);
      if (!messages.length) return;
      const ul = document.createElement('ul');
      messages.forEach((message) => {
        const li = document.createElement('li');
        li.textContent = message;
        ul.appendChild(li);
      });
      errorBox.appendChild(ul);
    };

    const add = (incoming) => {
      const errors = [];
      let total = files.reduce((sum, file) => sum + file.size, 0);
      Array.from(incoming).forEach((file) => {
        const ext = extension(file.name);
        if (files.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified)) return;
        if (!allowed.includes(ext)) { errors.push(file.name + ': tipo no permitido (PDF, Word, Excel, JPG o PNG).'); return; }
        if (file.size === 0) { errors.push(file.name + ': el archivo esta vacio.'); return; }
        if (file.size > maxBytes) { errors.push(file.name + ': pesa ' + formatSize(file.size) + ' y el maximo por archivo es ' + formatSize(maxBytes) + '.'); return; }
        if (total + file.size > maxTotal) { errors.push(file.name + ': supera el total permitido por envio (' + formatSize(maxTotal) + '). Envialo por separado.'); return; }
        if (files.length >= maxFiles) { errors.push(file.name + ': maximo ' + maxFiles + ' archivos por envio.'); return; }
        files.push(file);
        total += file.size;
      });
      sync();
      render();
      showErrors(errors);
    };

    // Elegir en el explorador agrega a los ya seleccionados (no los reemplaza).
    input.addEventListener('change', () => {
      const picked = Array.from(input.files);
      sync();
      add(picked);
    });
    ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (event) => {
      if (!event.dataTransfer || !Array.from(event.dataTransfer.types).includes('Files')) return;
      event.preventDefault();
      zone.classList.add('is-dragging');
    }));
    ['dragleave', 'dragend'].forEach((type) => zone.addEventListener(type, (event) => {
      if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-dragging');
    }));
    zone.addEventListener('drop', (event) => {
      event.preventDefault();
      zone.classList.remove('is-dragging');
      if (event.dataTransfer && event.dataTransfer.files.length) add(event.dataTransfer.files);
    });
    // Pegar una captura de pantalla (Ctrl+V) mientras se escribe en el formulario.
    const form = zone.closest('form');
    if (form) form.addEventListener('paste', (event) => {
      const pasted = Array.from((event.clipboardData && event.clipboardData.files) || []);
      if (!pasted.length) return;
      event.preventDefault();
      const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
      add(pasted.map((file, i) => new File([file], 'captura-' + stamp + (i ? '-' + i : '') + '.' + (extension(file.name) || 'png'), { type: file.type, lastModified: file.lastModified })));
    });
  });
}
document.addEventListener('DOMContentLoaded', initDropzones);
