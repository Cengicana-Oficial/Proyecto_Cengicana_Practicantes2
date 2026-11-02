/**
 * Asistencia con un clic en Gestion del aula (pestaña Asistencia y editor por sesion). Envia la accion
 * asistencia_marcar a aula_gestion.php y actualiza botones, contadores y porcentajes sin recargar.
 * Eventos delegados: el contenido de cada sesion del editor se carga despues.
 */
(function () {
    'use strict';
    var base = document.querySelector('[data-asis-url]');
    if (!base) return;
    var url = base.getAttribute('data-asis-url');
    var csrf = base.getAttribute('data-csrf');

    function aviso(texto, error) {
        var t = document.getElementById('aula-asis-aviso');
        if (!t) {
            t = document.createElement('div');
            t.id = 'aula-asis-aviso';
            t.className = 'aula-asis-aviso';
            t.setAttribute('role', 'status');
            document.body.appendChild(t);
        }
        t.textContent = texto;
        t.className = 'aula-asis-aviso is-visible' + (error ? ' is-error' : '');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.className = 'aula-asis-aviso'; }, error ? 5000 : 1800);
    }

    function pintar(sid, aid, dato) {
        var sel = '[data-sesion="' + sid + '"][data-asignacion="' + aid + '"]';
        document.querySelectorAll('[data-asis-celda]' + sel).forEach(function (b) {
            b.className = b.className.replace(/\bis-(presente|ausente|vacio)\b/, 'is-' + dato.estado);
            b.setAttribute('data-estado', dato.estado);
            var compacto = b.classList.contains('is-compacto');
            b.textContent = { presente: compacto ? '✓' : '✓ Presente', ausente: compacto ? '✗' : '✗ Ausente', vacio: compacto ? '·' : 'Sin registro' }[dato.estado];
            b.title = (dato.detalle || 'Sin registro') + ' — clic para cambiar';
        });
        document.querySelectorAll('.aula-asis-fila' + sel + ', .aula-ed-asis-fila' + sel).forEach(function (fila) {
            fila.querySelectorAll('[data-asis-marcar]').forEach(function (op) {
                op.classList.toggle('is-activo', (op.getAttribute('data-presente') === '1') === (dato.estado === 'presente') && dato.estado !== 'vacio');
            });
            var det = fila.querySelector('[data-asis-detalle]');
            if (det) det.textContent = dato.detalle ? ' · ' + dato.detalle : '';
        });
        if (dato.pct !== null && dato.pct !== undefined) {
            document.querySelectorAll('[data-asis-pct="' + aid + '"]').forEach(function (p) {
                p.textContent = dato.pct + '%';
                p.classList.toggle('is-bajo', dato.pct < 80);
            });
        }
    }

    function enviar(sid, ids, presente, botones) {
        var fd = new FormData();
        fd.append('accion', 'asistencia_marcar');
        fd.append('csrf', csrf);
        fd.append('sesion_id', sid);
        fd.append('presente', presente ? '1' : '0');
        ids.forEach(function (id) { fd.append('asignaciones[]', id); });
        botones.forEach(function (b) { b.disabled = true; b.classList.add('is-guardando'); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Respuesta no válida del servidor.' }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error(res.error || 'No se pudo guardar.');
                Object.keys(res.estados).forEach(function (aid) { pintar(sid, aid, res.estados[aid]); });
                document.querySelectorAll('[data-asis-contador="' + sid + '"]').forEach(function (c) {
                    var total = c.getAttribute('data-total');
                    c.textContent = c.getAttribute('data-corto') ? res.presentes + '/' + total : res.presentes + ' de ' + total + ' presentes';
                });
                aviso(ids.length > 1 ? 'Asistencia guardada para ' + ids.length + ' participantes.' : 'Asistencia guardada.');
            })
            .catch(function (e) { aviso(e.message || 'No se pudo guardar la asistencia.', true); })
            .then(function () { botones.forEach(function (b) { b.disabled = false; b.classList.remove('is-guardando'); }); });
    }

    document.addEventListener('click', function (e) {
        var celda = e.target.closest('[data-asis-celda]');
        var marcar = e.target.closest('[data-asis-marcar]');
        var todos = e.target.closest('[data-asis-todos]');
        if (celda) {
            // Casilla: sin registro o ausente -> presente; presente -> ausente.
            enviar(celda.getAttribute('data-sesion'), [celda.getAttribute('data-asignacion')], celda.getAttribute('data-estado') !== 'presente', [celda]);
        } else if (marcar) {
            var fila = marcar.closest('[data-asignacion]');
            enviar(fila.getAttribute('data-sesion'), [fila.getAttribute('data-asignacion')], marcar.getAttribute('data-presente') === '1', [marcar]);
        } else if (todos) {
            var sid = todos.getAttribute('data-sesion');
            var lista = document.querySelector('[data-asis-lista="' + sid + '"]') || todos.closest('[data-asis-lista]') || document;
            var ids = [];
            lista.querySelectorAll('[data-sesion="' + sid + '"][data-asignacion]').forEach(function (f) {
                if (f.matches('.aula-asis-fila, .aula-ed-asis-fila') && ids.indexOf(f.getAttribute('data-asignacion')) === -1) ids.push(f.getAttribute('data-asignacion'));
            });
            if (!ids.length) return;
            var presente = todos.getAttribute('data-presente') === '1';
            if (!confirm('¿Marcar a ' + ids.length + ' participante(s) como ' + (presente ? 'presentes' : 'ausentes') + '?')) return;
            enviar(sid, ids, presente, [todos]);
        }
    });
})();
