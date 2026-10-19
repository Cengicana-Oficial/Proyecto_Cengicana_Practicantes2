/**
 * Portal del estudiante (mi_aula.php): filtros por etiqueta y busqueda en listas largas
 * (sesiones, asistencia, actividades) y apertura de la sesion o actividad indicada en el enlace (#ses-ID, #act-ID).
 * Cada bloque [data-filtros="selector de elementos"] filtra los elementos con data-tags y, si hay, data-texto.
 */
(function () {
    'use strict';
    document.querySelectorAll('[data-filtros]').forEach(function (barra) {
        var items = Array.prototype.slice.call(document.querySelectorAll(barra.getAttribute('data-filtros')));
        var texto = barra.querySelector('[data-filtro-texto]');
        var vacio = barra.parentNode.querySelector('[data-filtro-vacio]');
        var actual = 'todas';
        function etiquetas(el) { return (el.getAttribute('data-tags') || '').split(' '); }
        barra.querySelectorAll('[data-filtro-cuenta]').forEach(function (b) {
            var tag = b.getAttribute('data-filtro-cuenta');
            var n = items.filter(function (el) { return etiquetas(el).indexOf(tag) !== -1; }).length;
            b.textContent = n;
            if (!n) b.closest('button').classList.add('is-vacio');
        });
        function aplicar() {
            var q = texto ? texto.value.trim().toLowerCase() : '';
            var visibles = 0;
            items.forEach(function (el) {
                var ok = (actual === 'todas' || etiquetas(el).indexOf(actual) !== -1)
                    && (!q || (el.getAttribute('data-texto') || el.textContent.toLowerCase()).indexOf(q) !== -1);
                el.hidden = !ok;
                if (ok) visibles++;
            });
            if (vacio) vacio.hidden = visibles > 0;
        }
        barra.addEventListener('click', function (e) {
            var b = e.target.closest('[data-filtro]');
            if (!b) return;
            actual = b.getAttribute('data-filtro');
            barra.querySelectorAll('[data-filtro]').forEach(function (x) { x.classList.toggle('is-activo', x === b); });
            aplicar();
        });
        if (texto) texto.addEventListener('input', aplicar);
        barra._limpiar = function () {
            actual = 'todas';
            if (texto) texto.value = '';
            barra.querySelectorAll('[data-filtro]').forEach(function (x) { x.classList.toggle('is-activo', x.getAttribute('data-filtro') === 'todas'); });
            aplicar();
        };
    });

    // Abre y muestra la sesion o actividad del enlace, aunque un filtro la estuviera ocultando.
    function irA() {
        var id = location.hash.slice(1);
        if (!/^(ses|act)-\d+$/.test(id)) return;
        var el = document.getElementById(id);
        if (!el) return;
        if (el.hidden) document.querySelectorAll('[data-filtros]').forEach(function (b) { if (b._limpiar) b._limpiar(); });
        if (el.tagName === 'DETAILS') el.open = true;
        el.classList.add('is-destacada');
        setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
        setTimeout(function () { el.classList.remove('is-destacada'); }, 2500);
    }
    irA();
    window.addEventListener('hashchange', irA);
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-ir-sesion]');
        if (a && a.getAttribute('href') === location.hash) { e.preventDefault(); irA(); }
    });
})();
