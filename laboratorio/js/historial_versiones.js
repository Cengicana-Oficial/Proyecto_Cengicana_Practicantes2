/* Historial de versiones: selección de muestra + análisis, búsqueda, filtro por estado actual y
   resultado de cada versión calculado con las mismas fórmulas de Validación técnica
   (hojas_definiciones.js + validacion_tecnica.js). */
(function () {
    'use strict';

    const page = document.querySelector('[data-history-page]');
    if (!page) return;

    const buttons = Array.from(page.querySelectorAll('[data-history-select]'));
    const panels = Array.from(page.querySelectorAll('[data-history-panel]'));
    const title = page.querySelector('[data-history-current-title]');
    const search = page.querySelector('[data-history-search]');
    const chips = Array.from(page.querySelectorAll('[data-history-filter]'));
    const noMatch = page.querySelector('[data-history-nomatch]');
    let filtro = '';

    const norm = v => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const visibles = () => buttons.filter(b => !b.hidden);

    function selectHistory(key, focusSelected) {
        let selectedButton = null;
        buttons.forEach(function (button) {
            const active = button.dataset.historySelect === key;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            if (active) selectedButton = button;
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.historyPanel !== key;
        });
        if (title) title.textContent = selectedButton ? (selectedButton.dataset.historyTitle || 'Historial de versiones') : 'Selecciona una muestra';
        if (focusSelected && selectedButton) selectedButton.focus();
    }

    function aplicarFiltro() {
        const q = norm(search ? search.value.trim() : '');
        buttons.forEach(function (b) {
            b.hidden = !((!filtro || b.dataset.historyEstado === filtro) && (!q || norm(b.dataset.historyText).includes(q)));
        });
        const lista = visibles();
        if (noMatch) noMatch.hidden = lista.length > 0;
        const activo = buttons.find(b => b.classList.contains('is-active'));
        if (!activo || activo.hidden) selectHistory(lista.length ? lista[0].dataset.historySelect : '', false);
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            selectHistory(button.dataset.historySelect, false);
        });
        button.addEventListener('keydown', function (event) {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const lista = visibles();
            const index = lista.indexOf(button);
            let nextIndex = index;
            if (event.key === 'ArrowDown') nextIndex = (index + 1) % lista.length;
            if (event.key === 'ArrowUp') nextIndex = (index - 1 + lista.length) % lista.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = lista.length - 1;
            selectHistory(lista[nextIndex].dataset.historySelect, true);
        });
    });
    if (search) search.addEventListener('input', aplicarFiltro);
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            filtro = chip.dataset.historyFilter || '';
            chips.forEach(c => c.classList.toggle('is-on', c === chip));
            aplicarFiltro();
        });
    });

    // Resultado de cada versión con las fórmulas de la hoja de trabajo.
    const defs = window.LabHojas;
    const V = window.LabVal;
    if (defs && V) {
        page.querySelectorAll('[data-history-calc]').forEach(function (nodo) {
            try {
                const c = JSON.parse(nodo.dataset.historyCalc);
                const h = defs.hojas[c.hoja];
                if (!h) return;
                const cap = { hoja: c.hoja, analisis: c.analisis || (h.analisis || [])[0], encabezado: c.encabezado || {}, controles: c.controles || {}, curva: c.curva || [] };
                const m = V.filaCalc(cap, c.fila || {}, String(c.id), V.contexto(cap));
                const p = (V.vistaDe(cap).principal || {})[cap.analisis] || {};
                let texto = '';
                if (p.k) {
                    const valor = m[p.k];
                    if (typeof valor === 'number' && isFinite(valor)) {
                        const cat = (defs.catalogo || []).find(x => x.analisis === cap.analisis && c.hoja.indexOf(x.tipoMuestra + '|') === 0);
                        texto = defs.fx(valor, p.d) + (cat && cat.unidad ? ' ' + cat.unidad : '');
                    } else if (valor != null && valor !== '') {
                        texto = String(valor);
                    }
                } else if (typeof p.texto === 'function') {
                    texto = p.texto(m);
                }
                if (texto) {
                    nodo.innerHTML = 'Resultado: <strong></strong>';
                    nodo.querySelector('strong').textContent = texto;
                    nodo.hidden = false;
                }
            } catch (e) {
                /* Si la hoja cambió de formato, se muestran solo los datos capturados. */
            }
        });
    }

    if (buttons.length) {
        const initial = page.dataset.initialSelection || buttons[0].dataset.historySelect;
        selectHistory(initial, false);
    }
}());
