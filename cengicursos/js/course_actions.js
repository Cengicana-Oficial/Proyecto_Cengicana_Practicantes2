/* Menú "Acciones" para las filas de tabla de todo el módulo (se carga desde menu_render()).
 *
 * 1. Bloques .cengi-row-actions dentro de una fila: se convierten en menú desplegable.
 * 2. Detección automática: la celda de acciones de una fila (la última celda, o una con
 *    "accion"/"action" en su clase) que tenga 2 o más botones y ningún otro contenido se
 *    envuelve en un .cengi-row-actions y se convierte igual. Los elementos no se mueven de
 *    lugar en el DOM (los formularios siguen funcionando); el menú se muestra como popover.
 *
 * 3. Filas que no son tabla (tarjetas, <article>): el contenedor de botones se marca con
 *    data-acciones-menu (opcional: data-actions-title y data-acciones-nombre para el
 *    encabezado del menú). El contenedor se queda en su lugar y adentro se crea el menú.
 *
 * Para excluir una tabla o una celda: data-sin-menu en la tabla, fila o celda.
 */
(function () {
    'use strict';
    if (window.cengiAccionesMenu) return;

    var opened = null;
    var contador = 0;
    var CONTROLES = 'a.btn, button.btn, .cengi-action-btn, .pg-icono, .dir-icono, .bi-btn, .pp-link, input[type="submit"].btn, button[type="submit"]';
    var CONTENEDOR = '[data-acciones-menu]';

    function close(restoreFocus) {
        if (!opened) return;
        var item = opened;
        opened = null;
        if (item.panel.hidePopover && item.panel.matches(':popover-open')) item.panel.hidePopover();
        item.panel.hidden = true;
        item.button.setAttribute('aria-expanded', 'false');
        if (restoreFocus) item.button.focus();
    }

    function textoVisible(nodo) {
        return (nodo.textContent || '').replace(/\s+/g, ' ').trim();
    }

    // Una celda califica si tiene 2+ botones, ningún campo editable y ningún texto fuera de los botones.
    function celdaDeAcciones(td) {
        if (td.closest('[data-sin-menu], .cengi-row-actions, .dropdown, .btn-group')) return false;
        if (td.querySelector('.cengi-row-actions')) return false;
        var fila = td.parentElement;
        var esUltima = fila && fila.lastElementChild === td;
        if (!esUltima && !/accion|action/i.test(td.className)) return false;
        if (td.querySelector('input:not([type="hidden"]):not([type="submit"]), select, textarea, [data-toggle="dropdown"], table')) return false;
        var controles = Array.prototype.filter.call(td.querySelectorAll(CONTROLES), function (c) {
            return !c.parentElement.closest(CONTROLES);
        });
        if (controles.length < 2) return false;
        var copia = td.cloneNode(true);
        copia.querySelectorAll(CONTROLES + ', input, script, template, .modal, .sr-only').forEach(function (n) { n.remove(); });
        return textoVisible(copia) === '';
    }

    function envolver(td) {
        var panel = document.createElement('div');
        panel.className = 'cengi-row-actions';
        panel.setAttribute('data-auto', '');
        // Las ventanas modales y los scripts se quedan en la celda, fuera del menú oculto.
        Array.prototype.slice.call(td.childNodes).forEach(function (nodo) {
            if (nodo.nodeType === 1 && nodo.matches('.modal, script, template')) return;
            panel.appendChild(nodo);
        });
        td.appendChild(panel);
        td.classList.add('cengi-acciones-col');
        return panel;
    }

    function tituloFila(row, panel) {
        if (panel.getAttribute('data-acciones-nombre')) return panel.getAttribute('data-acciones-nombre');
        var contenedor = panel.parentElement && panel.parentElement.closest(CONTENEDOR);
        if (contenedor && contenedor.getAttribute('data-acciones-nombre')) return contenedor.getAttribute('data-acciones-nombre');
        var nodo = row.querySelector('.cengi-course-name, .col-name, .cengi-roster-person');
        if (!nodo) {
            // Primera celda con texto que no sea solo un número (p. ej. la columna ID).
            nodo = Array.prototype.find.call(row.querySelectorAll('td'), function (td) {
                var t = textoVisible(td);
                return t !== '' && !/^[#\d\s.,-]+$/.test(t) && !td.querySelector('.cengi-row-actions');
            });
            if (!nodo) return '';
        }
        var principal = nodo.querySelector('strong, b, a') || nodo;
        var texto = textoVisible(principal);
        return texto.length > 70 ? texto.slice(0, 67) + '…' : texto;
    }

    function preparar(panel) {
        if (panel.hasAttribute('data-menu-listo')) return;
        var contenedor = panel.parentElement && panel.parentElement.closest(CONTENEDOR);
        var row = panel.closest('tr') || panel.closest('[data-acciones-fila], article, li') || (contenedor && contenedor.parentElement);
        if (!row) return;
        var links = panel.querySelectorAll('a, button, input[type="submit"]');
        if (links.length < 2) return;
        panel.setAttribute('data-menu-listo', '');
        var deleteAction = panel.querySelector('.is-delete, .btn-danger, .is-peligro');
        if (deleteAction) {
            var bloque = deleteAction.closest('form') && panel.contains(deleteAction.closest('form')) ? deleteAction.closest('form') : deleteAction;
            panel.appendChild(bloque);
            deleteAction.classList.add('is-delete');
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'cengi-course-actions-toggle';
        button.innerHTML = '<span class="course-actions-dots" aria-hidden="true">⋯</span> Acciones <span class="course-actions-chevron" aria-hidden="true"></span>';
        button.setAttribute('aria-expanded', 'false');
        panel.id = panel.id || 'cengi-acciones-' + (++contador);
        button.setAttribute('aria-controls', panel.id);
        var titulo = tituloFila(row, panel);
        if (contenedor && !panel.hasAttribute('data-actions-title') && contenedor.getAttribute('data-actions-title')) {
            panel.setAttribute('data-actions-title', contenedor.getAttribute('data-actions-title'));
        }
        button.setAttribute('aria-label', titulo ? 'Acciones de ' + titulo : 'Acciones');
        panel.before(button);
        panel.classList.add('cengi-course-actions-panel');

        var heading = document.createElement('div');
        heading.className = 'course-actions-heading';
        var headingLabel = document.createElement('strong');
        headingLabel.textContent = panel.getAttribute('data-actions-title') || 'Acciones';
        heading.appendChild(headingLabel);
        if (titulo) {
            var subtitle = document.createElement('small');
            subtitle.textContent = titulo;
            heading.appendChild(subtitle);
        }
        panel.prepend(heading);
        panel.hidden = true;
        if (panel.showPopover) panel.setAttribute('popover', 'manual');

        links.forEach(function (link) {
            if (link.tagName === 'INPUT') return;
            link.querySelectorAll('.sr-only').forEach(function (node) { node.remove(); });
            // Los botones con texto lo conservan; los de solo ícono reciben su etiqueta.
            if (textoVisible(link) === '') {
                var label = link.getAttribute('aria-label') || link.getAttribute('data-tooltip') || link.getAttribute('title') || 'Abrir';
                var text = document.createElement('span');
                text.className = 'cengi-course-action-label';
                text.textContent = label;
                link.appendChild(text);
            }
            link.removeAttribute('data-tooltip');
        });

        button.addEventListener('click', function () {
            var same = opened && opened.panel === panel;
            close(false);
            if (same) return;
            panel.hidden = false;
            if (panel.showPopover) panel.showPopover();
            var rect = button.getBoundingClientRect();
            var width = panel.offsetWidth, height = panel.offsetHeight;
            panel.style.left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)) + 'px';
            panel.style.top = Math.max(8, rect.bottom + height + 8 > window.innerHeight ? rect.top - height - 5 : rect.bottom + 5) + 'px';
            button.setAttribute('aria-expanded', 'true');
            opened = {panel: panel, button: button};
            links[0].focus();
        });
        panel.addEventListener('click', function (event) {
            if (event.target.closest('a,button,input[type="submit"]')) setTimeout(function () { close(false); }, 0);
        });
        panel.addEventListener('keydown', function (event) {
            var position = Array.prototype.indexOf.call(links, document.activeElement);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                links[(position + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length].focus();
            }
        });
    }

    function init(raiz) {
        raiz = raiz || document;
        raiz.querySelectorAll('table:not([data-sin-menu]) > tbody > tr > td, table:not([data-sin-menu]) > tr > td').forEach(function (td) {
            if (celdaDeAcciones(td)) envolver(td);
        });
        raiz.querySelectorAll(CONTENEDOR).forEach(function (c) {
            if (c.hasAttribute('data-menu-envuelto') || c.querySelectorAll(CONTROLES + ', a, button').length < 2) return;
            c.setAttribute('data-menu-envuelto', '');
            envolver(c);
        });
        raiz.querySelectorAll('.cengi-row-actions').forEach(preparar);
    }

    window.cengiAccionesMenu = {init: init, cerrar: close};

    document.addEventListener('click', function (event) {
        if (opened && !opened.panel.contains(event.target) && !opened.button.contains(event.target)) close(false);
    });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && opened) { event.preventDefault(); close(true); } });
    document.addEventListener('focusin', function (event) { if (opened && !opened.panel.contains(event.target) && event.target !== opened.button) close(false); });
    window.addEventListener('resize', function () { close(false); });

    // Menús nativos <details class="cengi-acc">: se abre uno a la vez y se cierran al hacer
    // clic fuera, al elegir una opción o con Esc.
    function cerrarDetalles(excepto) {
        document.querySelectorAll('details.cengi-acc[open]').forEach(function (d) {
            if (d !== excepto) d.open = false;
        });
    }
    document.addEventListener('click', function (event) {
        var menu = event.target.closest && event.target.closest('details.cengi-acc');
        cerrarDetalles(menu);
        if (menu && event.target.closest('.cengi-acc-panel a, .cengi-acc-panel button')) {
            setTimeout(function () { menu.open = false; }, 0);
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        var abierto = document.querySelector('details.cengi-acc[open]');
        if (abierto) {
            abierto.open = false;
            abierto.querySelector('summary').focus();
        }
    });
    document.addEventListener('scroll', function (event) { if (opened && !opened.panel.contains(event.target)) close(false); }, true);

    // Tablas que se llenan con JavaScript después de cargar (p. ej. participantes de eventos).
    function observar() {
        if (!window.MutationObserver) return;
        var pendiente = null;
        new MutationObserver(function (cambios) {
            var hayFilas = cambios.some(function (c) {
                return Array.prototype.some.call(c.addedNodes, function (n) {
                    return n.nodeType === 1 && (n.tagName === 'TR' || n.tagName === 'TBODY' || n.tagName === 'TABLE' || n.matches(CONTENEDOR) || (n.querySelector && n.querySelector('tr, ' + CONTENEDOR)));
                });
            });
            if (!hayFilas || pendiente) return;
            pendiente = setTimeout(function () { pendiente = null; init(); }, 50);
        }).observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); observar(); });
    } else {
        init();
        observar();
    }
})();
