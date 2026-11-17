// Estadisticas y "¿Que funciona?": selector de periodo, cambio de metrica y tooltip de columnas.
(function () {
    var sel = document.getElementById('est-periodo-sel');
    var rango = document.getElementById('est-rango');
    if (sel && rango) {
        sel.addEventListener('change', function () {
            if (sel.value === 'rango') { rango.hidden = false; return; }
            sel.form.submit();
        });
    }
    document.querySelectorAll('.est-seg button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.est-seg button').forEach(function (b) { b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
            document.querySelectorAll('[data-serie-panel]').forEach(function (p) { p.hidden = p.dataset.seriePanel !== btn.dataset.serie; });
        });
    });
    var tip = document.getElementById('est-tooltip');
    if (!tip) { return; }
    var mostrar = function (el) {
        var r = el.getBoundingClientRect();
        tip.textContent = el.dataset.tip;
        tip.hidden = false;
        tip.style.left = Math.min(window.innerWidth - tip.offsetWidth - 8, Math.max(8, r.left + r.width / 2 - tip.offsetWidth / 2)) + 'px';
        tip.style.top = Math.max(8, r.top - tip.offsetHeight - 6) + 'px';
    };
    document.querySelectorAll('.est-col').forEach(function (col) {
        col.addEventListener('mouseenter', function () { mostrar(col); });
        col.addEventListener('focus', function () { mostrar(col); });
        col.addEventListener('mouseleave', function () { tip.hidden = true; });
        col.addEventListener('blur', function () { tip.hidden = true; });
    });
})();
