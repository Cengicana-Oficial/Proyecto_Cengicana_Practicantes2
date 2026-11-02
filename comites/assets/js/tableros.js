(() => {
  'use strict';
  const grid = document.getElementById('tableros-grid'); if (!grid) return;
  const cards = [...grid.querySelectorAll('[data-board]')];
  const key = card => 'comites.tablero.vista.' + card.dataset.board;
  const remember = card => { try { sessionStorage.setItem(key(card), JSON.stringify({ collapsed: card.classList.contains('is-collapsed'), expanded: card.classList.contains('is-expanded'), height: card.querySelector('[data-board-height]')?.value || '620' })); } catch {} };
  const status = (card, text) => { const el = card.querySelector('.tablero-status'); if (el) el.textContent = text; };
  function load(card, reload = false) {
    const frame = card.querySelector('iframe'); if (!frame || frame.getAttribute('src') && !reload) return;
    status(card, 'Cargando…'); frame.src = frame.dataset.src;
  }
  function collapsed(card, value) {
    card.classList.toggle('is-collapsed', value); card.querySelector('.tablero-content').hidden = value;
    const b = card.querySelector('[data-board-action="toggle"]'); b.textContent = value ? 'Mostrar' : 'Contraer'; b.setAttribute('aria-expanded', String(!value)); if (!value) load(card);
  }
  function expanded(card, value) {
    card.classList.toggle('is-expanded', value); const b = card.querySelector('[data-board-action="expand"]'); b.textContent = value ? 'Restaurar tamaño' : 'Ampliar'; b.setAttribute('aria-pressed', String(value)); if (value) collapsed(card, false);
  }
  cards.forEach(card => {
    const frame = card.querySelector('iframe'); if (!frame) return;
    // Los proveedores externos necesitan su origen para autenticación; el contenido del mismo sitio queda aislado.
    if (new URL(frame.dataset.src, location.href).origin !== location.origin) frame.sandbox.add('allow-same-origin');
    frame.addEventListener('load', () => status(card, ''));
    let saved = {}; try { saved = JSON.parse(sessionStorage.getItem(key(card)) || '{}') || {}; } catch {}
    const height = ['420', '620', '850'].includes(saved.height) ? saved.height : '620'; card.querySelector('[data-board-height]').value = height; card.style.setProperty('--board-height', height + 'px');
    expanded(card, !!saved.expanded); collapsed(card, !!saved.collapsed);
  });
  grid.addEventListener('click', async event => {
    const b = event.target.closest('[data-board-action]'); if (!b) return; const card = b.closest('[data-board]'), action = b.dataset.boardAction;
    if (action === 'toggle') { if (document.fullscreenElement === card) await document.exitFullscreen(); collapsed(card, !card.classList.contains('is-collapsed')); }
    if (action === 'expand') expanded(card, !card.classList.contains('is-expanded'));
    if (action === 'reload') load(card, true);
    if (action === 'fullscreen') {
      collapsed(card, false);
      try { if (document.fullscreenElement === card) await document.exitFullscreen(); else if (card.requestFullscreen) await card.requestFullscreen(); else throw new Error(); }
      catch { expanded(card, true); status(card, 'Vista ampliada. Este navegador no habilitó pantalla completa.'); }
    }
    remember(card);
  });
  grid.addEventListener('change', event => { if (!event.target.matches('[data-board-height]')) return; const card = event.target.closest('[data-board]'); if (!['420', '620', '850'].includes(event.target.value)) return; card.style.setProperty('--board-height', event.target.value + 'px'); remember(card); });
  document.addEventListener('fullscreenchange', () => { cards.forEach(card => { const b = card.querySelector('[data-board-action="fullscreen"]'); if (b) b.textContent = document.fullscreenElement === card ? 'Salir de pantalla completa' : 'Pantalla completa'; }); });
})();
