(function () {
    'use strict';

    const page = document.querySelector('[data-history-page]');
    if (!page) return;

    const buttons = Array.from(page.querySelectorAll('[data-history-select]'));
    const panels = Array.from(page.querySelectorAll('[data-history-panel]'));
    const title = page.querySelector('[data-history-current-title]');

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
        if (selectedButton && title) title.textContent = selectedButton.dataset.historyTitle || 'Historial de versiones';
        if (focusSelected && selectedButton) selectedButton.focus();
    }

    buttons.forEach(function (button, index) {
        button.addEventListener('click', function () {
            selectHistory(button.dataset.historySelect, false);
        });
        button.addEventListener('keydown', function (event) {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            let nextIndex = index;
            if (event.key === 'ArrowDown') nextIndex = (index + 1) % buttons.length;
            if (event.key === 'ArrowUp') nextIndex = (index - 1 + buttons.length) % buttons.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = buttons.length - 1;
            selectHistory(buttons[nextIndex].dataset.historySelect, true);
        });
    });

    if (buttons.length) {
        const initial = page.dataset.initialSelection || buttons[0].dataset.historySelect;
        selectHistory(initial, false);
    }
}());
