(function () {
    'use strict';

    const page = document.querySelector('[data-plan-page]');
    if (!page) return;

    const toast = document.querySelector('[data-plan-toast]');
    let toastTimer = 0;

    function showToast(message, error) {
        if (!toast) return;
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.toggle('is-error', Boolean(error));
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2600);
    }

    const tabs = Array.from(page.querySelectorAll('[data-plan-tab]'));
    const panels = Array.from(page.querySelectorAll('[data-plan-panel]'));

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const target = tab.dataset.planTab;
            tabs.forEach(function (item) {
                const active = item === tab;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                const active = panel.dataset.planPanel === target;
                panel.hidden = !active;
                panel.classList.toggle('is-active', active);
            });
        });
    });

    const queueFilters = {};
    page.querySelectorAll('[data-queue-filter]').forEach(function (input) {
        queueFilters[input.dataset.queueFilter] = input;
        input.addEventListener(input.type === 'search' ? 'input' : 'change', filterQueue);
    });

    function filterQueue() {
        const values = {};
        Object.keys(queueFilters).forEach(function (key) {
            values[key] = queueFilters[key].value.trim().toLocaleLowerCase('es');
        });

        let visibleTotal = 0;
        page.querySelectorAll('[data-queue-cube]').forEach(function (cube) {
            let visibleInCube = 0;
            cube.querySelectorAll('[data-queue-card]').forEach(function (card) {
                const visible = (!values.type || card.dataset.type === values.type)
                    && (!values.state || card.dataset.state === values.state)
                    && (!values.priority || card.dataset.priority === values.priority)
                    && (!values.analyst || (card.dataset.analyst || '').includes(values.analyst))
                    && (!values.client || (card.dataset.client || '').includes(values.client))
                    && (!values.search || (card.dataset.analysis || '').includes(values.search));
                card.hidden = !visible;
                if (visible) visibleInCube += 1;
            });
            cube.hidden = visibleInCube === 0;
            visibleTotal += visibleInCube;
        });

        const empty = page.querySelector('[data-queue-empty]');
        if (empty) empty.hidden = visibleTotal !== 0;
    }

    const queueModal = document.querySelector('[data-queue-modal]');
    const queueModalDialog = queueModal ? queueModal.querySelector('.plan-modal-dialog') : null;
    const queueModalTitle = queueModal ? queueModal.querySelector('[data-queue-modal-title]') : null;
    const queueModalSubtitle = queueModal ? queueModal.querySelector('[data-queue-modal-subtitle]') : null;
    const queueModalBody = queueModal ? queueModal.querySelector('[data-queue-modal-body]') : null;
    let queueModalTrigger = null;

    function openQueueModal(button) {
        if (!queueModal || !queueModalBody) return;
        const card = button.closest('[data-queue-card]');
        const detail = card ? card.querySelector('[data-queue-detail]') : null;
        const table = detail ? detail.querySelector('table') : null;
        if (!card || !table) return;

        const analysis = card.querySelector('h3');
        const summary = card.querySelector(':scope > p');
        queueModalTrigger = button;
        queueModalTitle.textContent = analysis ? analysis.textContent.trim() : 'Detalle de muestras';
        queueModalSubtitle.textContent = summary ? summary.textContent.trim() + ' · Con lote de origen' : 'Muestras con lote de origen';
        queueModalBody.replaceChildren(table.cloneNode(true));
        queueModal.hidden = false;
        document.body.classList.add('plan-modal-open');
        window.requestAnimationFrame(function () {
            queueModal.classList.add('is-open');
            const closeButton = queueModal.querySelector('[data-queue-modal-close]');
            if (closeButton) closeButton.focus();
        });
    }

    function closeQueueModal() {
        if (!queueModal || queueModal.hidden) return;
        queueModal.classList.remove('is-open');
        document.body.classList.remove('plan-modal-open');
        window.setTimeout(function () {
            queueModal.hidden = true;
            queueModalBody.replaceChildren();
            if (queueModalTrigger) queueModalTrigger.focus();
            queueModalTrigger = null;
        }, 160);
    }

    page.querySelectorAll('[data-queue-modal-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            openQueueModal(button);
        });
    });

    if (queueModal) {
        queueModal.querySelectorAll('[data-queue-modal-close]').forEach(function (button) {
            button.addEventListener('click', closeQueueModal);
        });
        queueModal.addEventListener('click', function (event) {
            if (event.target === queueModal) closeQueueModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !queueModal.hidden) closeQueueModal();
            if (event.key === 'Tab' && !queueModal.hidden && queueModalDialog) {
                const focusable = Array.from(queueModalDialog.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'));
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

    const matrixFilters = {};
    page.querySelectorAll('[data-matrix-filter]').forEach(function (input) {
        matrixFilters[input.dataset.matrixFilter] = input;
        input.addEventListener('change', filterMatrix);
    });

    function filterMatrix() {
        const state = matrixFilters.state ? matrixFilters.state.value : '';
        const client = matrixFilters.client ? matrixFilters.client.value : '';
        let visibleTotal = 0;

        page.querySelectorAll('[data-matrix-cube]').forEach(function (cube) {
            let visibleInCube = 0;
            cube.querySelectorAll('[data-matrix-row]').forEach(function (row) {
                const states = (row.dataset.states || '').split(' ');
                const visible = (!state || states.includes(state))
                    && (!client || row.dataset.client === client);
                row.hidden = !visible;
                if (visible) visibleInCube += 1;
            });
            cube.hidden = visibleInCube === 0;
            visibleTotal += visibleInCube;
        });

        const empty = page.querySelector('[data-matrix-empty]');
        if (empty) empty.hidden = visibleTotal !== 0;
    }

    const board = page.querySelector('[data-kanban-board]');
    if (!board || page.dataset.canManage !== '1') return;

    let draggedCard = null;
    let originalColumn = null;

    board.querySelectorAll('.plan-kanban-card[draggable="true"]').forEach(function (card) {
        card.addEventListener('dragstart', function (event) {
            draggedCard = card;
            originalColumn = card.closest('[data-kanban-col]');
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.formIds || '[]');
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('is-dragging');
            board.querySelectorAll('[data-kanban-col]').forEach(function (column) {
                column.classList.remove('is-over');
            });
            draggedCard = null;
            originalColumn = null;
        });
    });

    board.querySelectorAll('[data-kanban-col]').forEach(function (column) {
        column.addEventListener('dragover', function (event) {
            if (!draggedCard) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            column.classList.add('is-over');
        });

        column.addEventListener('dragleave', function (event) {
            if (!column.contains(event.relatedTarget)) column.classList.remove('is-over');
        });

        column.addEventListener('drop', async function (event) {
            event.preventDefault();
            column.classList.remove('is-over');
            if (!draggedCard || column === originalColumn) return;

            let formIds = [];
            try {
                formIds = JSON.parse(draggedCard.dataset.formIds || '[]');
            } catch (ignore) {
                formIds = [];
            }
            if (!formIds.length) return;

            const targetState = column.dataset.estado;
            const card = draggedCard;
            card.setAttribute('draggable', 'false');

            try {
                const responses = await Promise.all(formIds.map(function (id) {
                    return fetch(page.dataset.endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ id_formulario: id, estado: targetState })
                    });
                }));
                if (responses.some(function (response) { return !response.ok; })) {
                    throw new Error('No se pudo actualizar una de las tareas.');
                }

                column.querySelector('.plan-kanban-body').appendChild(card);
                updateKanbanCounts();
                showToast('La cola se movió correctamente.');
            } catch (error) {
                showToast(error.message || 'No fue posible mover la cola.', true);
            } finally {
                card.setAttribute('draggable', 'true');
            }
        });
    });

    function updateKanbanCounts() {
        board.querySelectorAll('[data-kanban-col]').forEach(function (column) {
            const count = column.querySelectorAll('.plan-kanban-card').length;
            const badge = column.querySelector('[data-kanban-count]');
            const empty = column.querySelector('.plan-kanban-empty');
            if (badge) badge.textContent = String(count);
            if (empty) empty.hidden = count !== 0;
        });
    }
}());
