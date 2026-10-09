(() => {
  const root = document.querySelector('.catalogo');
  if (!root) return;
  // Menu desplegable de acciones: posicion fija para no quedar recortado por el scroll de la lista.
  const closeMenus = except => root.querySelectorAll('details.catalogo-menu[open]').forEach(d => { if (d !== except) d.open = false; });
  root.addEventListener('toggle', event => {
    const menu = event.target; if (!menu.matches || !menu.matches('details.catalogo-menu') || !menu.open) return;
    closeMenus(menu);
    const box = menu.querySelector('summary').getBoundingClientRect(), list = menu.querySelector('.catalogo-menu-list');
    list.style.left = Math.max(8, box.right - list.offsetWidth) + 'px';
    const below = box.bottom + 4, height = list.offsetHeight;
    list.style.top = (below + height > window.innerHeight - 8 ? Math.max(8, box.top - height - 4) : below) + 'px';
  }, true);
  document.addEventListener('click', event => { if (!event.target.closest('details.catalogo-menu')) closeMenus(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenus(); });
  window.addEventListener('scroll', () => closeMenus(), true);
  window.addEventListener('resize', () => closeMenus());
  root.querySelectorAll('[data-catalogo-search]').forEach(input => {
    input.addEventListener('input', () => {
      const query = input.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
      document.getElementById(input.dataset.catalogoSearch).querySelectorAll('[data-catalogo-row]').forEach(row => {
        row.hidden = !row.textContent.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().includes(query);
      });
    });
  });
  root.querySelectorAll('[data-catalogo-tab]').forEach(button => button.addEventListener('click', () => {
    root.querySelectorAll('[data-catalogo-tab]').forEach(tab => tab.classList.toggle('active', tab === button));
    root.querySelectorAll('[data-catalogo-panel]').forEach(panel => { panel.hidden = panel.dataset.catalogoPanel !== button.dataset.catalogoTab; });
  }));
  const leader = document.getElementById('catalogo-lider');
  if (leader) {
    const boxes = [...root.querySelectorAll('input[name="integrantes[]"]')];
    const update = () => {
      boxes.forEach(box => {
        const isLeader = box.value === leader.value;
        if (isLeader) box.checked = true;
        box.disabled = isLeader;
        box.closest('label').querySelector('[data-lider-tag]').hidden = !isLeader;
      });
      document.getElementById('catalogo-total').textContent = boxes.filter(box => box.checked).length;
    };
    leader.addEventListener('change', update);
    boxes.forEach(box => box.addEventListener('change', update));
    update();
  }
  const logo = document.getElementById('catalogo-logo-input');
  let previewUrl;
  logo?.addEventListener('change', () => {
    const preview = document.getElementById('catalogo-logo-preview');
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    if (logo.files[0]) { previewUrl = URL.createObjectURL(logo.files[0]); preview.src = previewUrl; preview.hidden = false; }
  });
  const dialog = root.querySelector('[role="dialog"]');
  if (dialog) {
    const focusable = () => [...dialog.querySelectorAll('a,button,input,select,textarea')].filter(el => !el.disabled && el.getClientRects().length);
    focusable()[0]?.focus();
    dialog.addEventListener('keydown', event => {
      if (event.key === 'Escape') window.location.href = 'index.php?view=comites';
      if (event.key !== 'Tab') return;
      const elements = focusable();
      if (event.shiftKey && document.activeElement === elements[0]) { event.preventDefault(); elements.at(-1)?.focus(); }
      else if (!event.shiftKey && document.activeElement === elements.at(-1)) { event.preventDefault(); elements[0]?.focus(); }
    });
  }
})();
