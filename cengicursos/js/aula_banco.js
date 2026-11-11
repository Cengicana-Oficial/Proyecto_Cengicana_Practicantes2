// Gestión del aula → Banco de preguntas y Evaluaciones (aula_evaluaciones_gestion.php).
(function () {
    'use strict';
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    // Tipo de pregunta: opciones y respuesta correcta solo en selección; criterio en abiertas y tareas.
    function ajustarTipo(form) {
        var sel = form.querySelector('[data-tipo-pregunta]'); if (!sel) return;
        var t = sel.value, conOpciones = t === 'seleccion' || t === 'multiple';
        $$('[data-solo-opciones]', form).forEach(function (el) { el.hidden = !conOpciones; });
        $$('[data-solo-criterio]', form).forEach(function (el) { el.hidden = conOpciones; });
        var lab = form.querySelector('[data-label-correcta]'), ayuda = form.querySelector('[data-ayuda-correcta]'), inp = form.querySelector('[name="correcta"]');
        if (lab) lab.textContent = t === 'multiple' ? 'Números de las opciones correctas' : 'Número de la opción correcta';
        if (ayuda) ayuda.textContent = t === 'multiple'
            ? 'Separa con coma (ej.: 1,3). Puntaje parcial: si hay 2 correctas y el estudiante marca 1, obtiene la mitad; cada incorrecta marcada resta.'
            : 'Escribe el número de la línea correcta (1 = primera opción).';
        if (inp) { inp.placeholder = t === 'multiple' ? 'Ej.: 1,3' : 'Ej.: 2'; inp.required = conOpciones; }
        var opts = form.querySelector('[name="opciones"]'); if (opts) opts.required = conOpciones;
    }
    $$('[data-form-pregunta]').forEach(function (form) {
        ajustarTipo(form);
        form.addEventListener('change', function (e) { if (e.target.matches('[data-tipo-pregunta]')) ajustarTipo(form); });
    });

    // Selector de preguntas de una evaluación: filtro por módulo, "seleccionar todas", valor y totales.
    function actualizarSelector(box) {
        var f = box.querySelector('[data-filtro-modulo]'), mod = f ? f.value : '';
        var items = $$('.aula-selector-lista > .aula-option', box), visibles = 0, marcadasVisibles = 0, marcadas = 0, puntos = 0;
        items.forEach(function (it) {
            var ver = mod === '' || it.getAttribute('data-modulo') === mod, cb = it.querySelector('input[name="preguntas[]"]'), val = it.querySelector('[data-valor]');
            it.hidden = !ver; it.classList.toggle('is-marcada', cb.checked);
            if (cb.checked) { marcadas++; puntos += val ? (parseFloat(String(val.value).replace(',', '.')) || 0) : 1; }
            if (ver) { visibles++; if (cb.checked) marcadasVisibles++; }
        });
        var todas = box.querySelector('[data-seleccionar-todas]');
        if (todas) { todas.checked = visibles > 0 && marcadasVisibles === visibles; todas.indeterminate = marcadasVisibles > 0 && marcadasVisibles < visibles; todas.disabled = !visibles; }
        var total = Math.round(puntos * 100) / 100;
        var c = box.querySelector('[data-contador-seleccion]'); if (c) c.textContent = marcadas + ' de ' + items.length + ' seleccionada(s) · ' + total + ' puntos';
        var v = box.querySelector('[data-selector-vacio]'); if (v) v.hidden = visibles > 0;
        var form = box.closest('form'), tp = form && form.querySelector('[data-total-puntos]');
        if (tp) tp.textContent = marcadas ? marcadas + ' pregunta(s) · ' + total + ' puntos en total (la nota se expresa sobre 100)' : '';
    }
    function marcarEdicion(box) {
        var form = box.closest('form'), flag = form && form.querySelector('[data-editar-preguntas]');
        if (flag) flag.disabled = false;
    }
    $$('[data-selector-preguntas]').forEach(function (box) {
        actualizarSelector(box);
        box.addEventListener('change', function (e) {
            if (e.target.matches('[data-seleccionar-todas]')) {
                var on = e.target.checked;
                $$('.aula-selector-lista > .aula-option', box).forEach(function (it) { if (!it.hidden) it.querySelector('input[name="preguntas[]"]').checked = on; });
                marcarEdicion(box);
            } else if (e.target.matches('input[name="preguntas[]"]')) marcarEdicion(box);
            else if (e.target.matches('[data-valor]')) {
                // Escribir un valor marca la pregunta.
                var cb = e.target.closest('.aula-option').querySelector('input[name="preguntas[]"]'); cb.checked = true; marcarEdicion(box);
            }
            actualizarSelector(box);
        });
        box.addEventListener('input', function (e) { if (e.target.matches('[data-valor]')) actualizarSelector(box); });
    });

    // Calificación y retroalimentación: mostrar solo los campos que aplican.
    $$('[data-resultados]').forEach(function (fs) {
        var form = fs.closest('form'), nota = fs.querySelector('[data-nota-visible]'), retro = fs.querySelector('[data-retro]'), mom = fs.querySelector('[data-retro-momento]');
        var campoMom = fs.querySelector('[data-retro-momento-campo]'), campoFecha = fs.querySelector('[data-retro-fecha-campo]'), manual = form && form.querySelector('[data-solo-manual]');
        var fecha = campoFecha && campoFecha.querySelector('input');
        function ajustar() {
            var sinRetro = retro.value === 'no', conFecha = !sinRetro && mom.value === 'fecha';
            campoMom.hidden = sinRetro; campoFecha.hidden = !conFecha; if (fecha) fecha.required = conFecha;
            if (manual) manual.hidden = nota.value !== 'manual';
            var optNota = mom.querySelector('option[value="nota"]');
            if (optNota) optNota.textContent = nota.value === 'cierre' ? 'Junto con la nota (al cerrar)' : (nota.value === 'manual' ? 'Junto con la nota (al publicarla)' : 'Junto con la nota (al entregar)');
        }
        [nota, retro, mom].forEach(function (s) { s.addEventListener('change', ajustar); }); ajustar();
    });

    // Atajos de disponibilidad: "Ahora" y "Mañana 07:00".
    function fechaLocal(d) { var p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); }
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-ev-ahora],[data-ev-manana]'); if (!a) return;
        e.preventDefault();
        var d = new Date(); if (a.hasAttribute('data-ev-manana')) { d.setDate(d.getDate() + 1); d.setHours(7, 0, 0, 0); }
        var i = a.closest('label').querySelector('input'); i.value = fechaLocal(d); i.dispatchEvent(new Event('change', { bubbles: true }));
        // Si el cierre quedó vacío o antes de la apertura, se propone una semana después.
        var cierre = a.closest('form').querySelector('[name="cierra"]');
        if (cierre && (!cierre.value || cierre.value <= i.value)) { var c = new Date(d); c.setDate(c.getDate() + 7); c.setHours(23, 59, 0, 0); cierre.value = fechaLocal(c); }
    });

    $$('[data-form-evaluacion]').forEach(function (form) {
        // Al elegir el módulo de la nota, el selector muestra las preguntas de ese módulo.
        var modNota = form.querySelector('[name="curso_modulo_id"]'), box = form.querySelector('[data-selector-preguntas]');
        if (modNota && box) modNota.addEventListener('change', function () {
            var f = box.querySelector('[data-filtro-modulo]');
            if (!f || modNota.value === '0' || !f.querySelector('option[value="' + modNota.value + '"]')) return;
            f.value = modNota.value; actualizarSelector(box);
        });
        // El método de calificación solo aplica con más de un intento.
        var intentos = form.querySelector('[data-intentos]'), metodo = form.querySelector('[data-solo-varios-intentos]');
        if (intentos && metodo) { var t = function () { metodo.hidden = (parseInt(intentos.value, 10) || 1) <= 1; }; intentos.addEventListener('input', t); t(); }
        // Exige al menos una pregunta al crear o al cambiar la lista.
        form.addEventListener('submit', function (e) {
            if (!box || !box.querySelector('input[name="preguntas[]"]')) return;
            var flag = form.querySelector('[data-editar-preguntas]');
            if (flag && flag.disabled) return;
            if (!box.querySelector('input[name="preguntas[]"]:checked')) { e.preventDefault(); alert('Selecciona al menos una pregunta del banco.'); }
        });
    });

    // Duración: al cambiar la unidad se convierte la cantidad (90 minutos ↔ 1.5 horas).
    $$('.aula-duracion').forEach(function (lab) {
        var n = lab.querySelector('[name="duracion"]'), u = lab.querySelector('[name="duracion_unidad"]');
        if (!n || !u) return;
        u.addEventListener('change', function () {
            var v = parseFloat(String(n.value).replace(',', '.')); if (isNaN(v)) return;
            n.value = u.value === 'horas' ? Math.round(v / 60 * 100) / 100 : Math.round(v * 60);
        });
    });

    // Banco: filtros por módulo, tipo y texto.
    var filtros = document.querySelector('[data-banco-filtros]'), lista = document.querySelector('[data-banco-lista]');
    if (filtros && lista) {
        var aplicar = function () {
            var fm = filtros.querySelector('[data-filtro-modulo]'), ft = filtros.querySelector('[data-filtro-tipo]'), fx = filtros.querySelector('[data-filtro-texto]');
            var mod = fm ? fm.value : '', tipo = ft ? ft.value : '', txt = fx ? fx.value.trim().toLowerCase() : '';
            var items = $$('.aula-pregunta', lista), n = 0;
            items.forEach(function (it) {
                var ok = (mod === '' || it.getAttribute('data-modulo') === mod) && (tipo === '' || it.getAttribute('data-tipo') === tipo) && (txt === '' || (it.getAttribute('data-texto') || '').indexOf(txt) !== -1);
                it.hidden = !ok; if (ok) n++;
            });
            var c = filtros.querySelector('[data-banco-contador]'); if (c) c.textContent = n === items.length ? items.length + ' pregunta(s)' : n + ' de ' + items.length + ' pregunta(s)';
            var v = document.querySelector('[data-banco-vacio]'); if (v) v.hidden = n > 0;
        };
        filtros.addEventListener('input', aplicar); filtros.addEventListener('change', aplicar); aplicar();
    }

    // Confirmación antes de eliminar.
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute && e.target.getAttribute('data-confirmar');
        if (msg && !window.confirm(msg)) e.preventDefault();
    }, true);
})();
