/**
 * Diseñador de diplomas (diplomas.php?tab=disenar).
 * Carga la actividad desde diplomas_api.php, edita la plantilla en el navegador (las imagenes
 * se reducen y se guardan como data URL), dibuja la vista previa con CengiDiplomas y emite,
 * imprime o envia por correo los documentos de los participantes marcados.
 */
(function () {
    'use strict';

    var D = window.CengiDiplomas;
    var CFG = window.CENGI_DIP_CONFIG || {};
    var API = 'diplomas_api.php';
    var $ = function (id) { return document.getElementById(id); };
    var esc = D.esc;

    var act = null, t = null, defecto = null, participantes = [], urlBase = '';
    var seleccion = {}, idxPrev = 0, campoSel = null, cargando = 0;

    var FILTROS = {
        curso: [['aprobados', 'Solo aprobados (nota final ≥ ' + ((window.CENGI_DIP_CONFIG || {}).notaAprobacion || 70) + ')'], ['todos', 'Todos los asignados']],
        evento: [['asistieron', 'Solo quienes asistieron (ingreso registrado)'], ['confirmados', 'Confirmados (con QR)'], ['todos', 'Todos los registrados']]
    };
    var NOMBRES_FUENTE = { serif: 'Serif (Georgia)', script: 'Manuscrita', sans: 'Sans (Arial)', cond: 'Condensada', elegante: 'Elegante (Palatino)' };

    function normal(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
    function estado(msg, error) {
        var e = $('dipEstado');
        e.textContent = msg || '';
        e.style.color = error ? '#B23223' : '';
    }
    function clonar(o) { return JSON.parse(JSON.stringify(o)); }

    function api(datos) {
        var fd = new FormData();
        Object.keys(datos).forEach(function (k) {
            if (Array.isArray(datos[k])) datos[k].forEach(function (v) { fd.append(k + '[]', v); });
            else fd.append(k, datos[k]);
        });
        return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(respuesta);
    }
    function respuesta(r) {
        return r.json().catch(function () { return { ok: false, error: 'Respuesta no válida del servidor (' + r.status + ').' }; })
            .then(function (j) { if (!j.ok) throw new Error(j.error || 'No fue posible completar la acción.'); return j; });
    }

    // ---------- imagenes: se reducen antes de guardarlas en la plantilla ----------
    function leerImagen(file, max, jpeg, cb) {
        if (!file || !/^image\/(png|jpeg|webp)$/.test(file.type)) { alert('Usa una imagen PNG, JPG o WEBP.'); return; }
        var lector = new FileReader();
        lector.onload = function () {
            var im = new Image();
            im.onload = function () {
                var k = Math.min(1, max / Math.max(im.width, im.height));
                var c = document.createElement('canvas');
                c.width = Math.round(im.width * k); c.height = Math.round(im.height * k);
                var g = c.getContext('2d');
                if (jpeg) { g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); }
                g.drawImage(im, 0, 0, c.width, c.height);
                cb(jpeg ? c.toDataURL('image/jpeg', 0.9) : c.toDataURL('image/png'), im.width, im.height);
            };
            im.onerror = function () { alert('No se pudo leer la imagen.'); };
            im.src = lector.result;
        };
        lector.readAsDataURL(file);
    }

    // ---------- participantes ----------
    function filtro() { return $('dipFiltro').value || 'todos'; }
    function visibles() {
        var f = filtro(), q = normal($('dipBuscar').value);
        return participantes.filter(function (p) {
            return p.filtros.indexOf(f) !== -1 && (!q || normal(p.nombre + ' ' + p.cui + ' ' + p.institucion + ' ' + (p.codigo || '')).indexOf(q) !== -1);
        });
    }
    function marcados() {
        return visibles().filter(function (p) { return seleccion[p.id]; });
    }
    function fechaCorta(s) {
        if (!s) return '';
        var p = String(s).substr(0, 10).split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function dibujarParticipantes() {
        var lista = visibles(), cuerpo = $('dipDest');
        if (!lista.length) {
            cuerpo.innerHTML = '<tr><td colspan="4" class="pp-empty">' + (participantes.length ? 'Nadie cumple el filtro o la búsqueda.' : 'La actividad no tiene participantes.') + '</td></tr>';
        } else {
            cuerpo.innerHTML = lista.map(function (p, i) {
                var doc = p.pdf ? '<span class="cengi-status-badge is-neutral"><i></i>PDF cargado</span>'
                    : p.codigo ? '<a class="pp-mono" href="validar_diploma.php?c=' + encodeURIComponent(p.codigo) + '" target="_blank" rel="noopener">' + esc(p.codigo) + '</a>' +
                        '<span class="pp-sub">' + (p.enviado ? 'Enviado ' + fechaCorta(p.enviado) : 'Emitido ' + fechaCorta(p.emitido)) + '</span>'
                    : '<span class="pp-sub">Sin emitir</span>';
                var badge = /^(Aprobado|Asistió)/.test(p.estado) ? 'is-active' : /^(No aprobado|Sin confirmar)/.test(p.estado) ? 'is-rejected' : 'is-upcoming';
                return '<tr data-i="' + i + '"' + (i === idxPrev ? ' class="is-preview"' : '') + '>' +
                    '<td><input type="checkbox" data-id="' + p.id + '"' + (seleccion[p.id] ? ' checked' : '') + (CFG.puedeGestionar ? '' : ' disabled') + ' aria-label="Marcar ' + esc(p.nombre) + '"></td>' +
                    '<td><a href="#" data-ver="' + i + '"><strong>' + esc(p.nombre) + '</strong></a><span class="pp-sub">' + esc([p.institucion, p.correo || 'sin correo'].filter(Boolean).join(' · ')) + '</span></td>' +
                    '<td><span class="cengi-status-badge ' + badge + '"><i></i>' + esc(p.estado) + '</span></td><td>' + doc + '</td></tr>';
            }).join('');
        }
        var n = marcados().length;
        $('dipSelTodo').checked = lista.length > 0 && n === lista.length;
        $('dipSelTodo').disabled = !CFG.puedeGestionar;
        if ($('dipBtnPdf')) {
            $('dipBtnPdf').disabled = !n || cargando > 0;
            $('dipBtnPdf').textContent = 'Imprimir / guardar PDF (' + n + ')';
            $('dipBtnMail').disabled = !n || cargando > 0;
            $('dipBtnMail').textContent = 'Enviar por correo (' + n + ')';
        }
    }

    // ---------- vista previa ----------
    function datosDe(p) {
        var codigo = p && p.codigo && !p.pdf ? p.codigo : '';
        return D.datos(act, p || { nombre: 'Nombre del participante', institucion: 'Institución', cui: '0000 00000 0000' }, t, codigo, codigo ? urlBase + encodeURIComponent(codigo) : urlBase + 'CEN-XX-0000-0000-XXXX');
    }
    function dibujarPrevia() {
        if (!act) return;
        var lista = visibles();
        if (idxPrev >= lista.length) idxPrev = Math.max(0, lista.length - 1);
        var p = lista[idxPrev];
        $('dipPrevNom').textContent = p ? (idxPrev + 1) + ' de ' + lista.length + ' · ' + p.nombre : 'Ejemplo (sin participantes)';
        var caja = $('dipPreview');
        var k = Math.min(1, (caja.clientWidth || 800) / 1123);
        var alto = t.estilo === 'canva' && t.canva ? t.canva.h : 794;
        caja.innerHTML = '<div class="dip-escala" style="transform:scale(' + k + ');">' + D.html(t, datosDe(p), { edit: t.estilo === 'canva' && CFG.puedeGestionar, campoSel: campoSel }) + '</div>';
        caja.style.height = Math.ceil(alto * k) + 'px';
        caja.dataset.k = k;
    }
    function refrescar() {
        dibujarPrevia();
        dibujarParticipantes();
    }

    // ---------- formulario de la plantilla ----------
    function visibilidad() {
        var canva = t.estilo === 'canva';
        [].forEach.call(document.querySelectorAll('.dip-estilos button'), function (b) { b.classList.toggle('sel', b.dataset.estilo === t.estilo); });
        $('dipSecCanva').hidden = !canva;
        $('dipDisenoOpts').hidden = canva;
        [].forEach.call(document.querySelectorAll('[data-clasico]'), function (s) { s.hidden = canva; });
        $('dipFondoSubir').hidden = t.fondo !== 'propia';
        $('dipFondoOpW').hidden = t.fondo !== 'propia';
    }
    function llenar() {
        [].forEach.call(document.querySelectorAll('#dip-editor [data-campo]'), function (el) {
            var v = t[el.dataset.campo];
            if (el.type === 'checkbox') el.checked = !!v;
            else el.value = v == null ? '' : v;
        });
        dibujarLogos();
        dibujarFirmas();
        dibujarCanva();
        visibilidad();
    }

    function slot(src, attr, i, texto) {
        var imagen = src ? '<img src="' + esc(src) + '" alt="">' : '<span class="pp-sub">' + texto + '</span>';
        return '<div class="dip-slot">' + imagen + (CFG.puedeGestionar
            ? '<label class="btn btn-default btn-xs" style="cursor:pointer;margin:0;">' + (src ? 'Cambiar' : 'Subir') + '<input type="file" accept="image/png,image/jpeg,image/webp" hidden ' + attr + '="' + i + '"></label>' +
              (src ? '<button type="button" class="btn btn-link btn-xs" data-quitar-' + attr.replace('data-', '') + '="' + i + '">Quitar</button>' : '')
            : '') + '</div>';
    }
    function dibujarLogos() {
        $('dipLogos').innerHTML = [0, 1, 2].map(function (i) {
            return slot((t.logos || [])[i], 'data-logo', i, ['Logo izquierdo', 'Logo central', 'Logo derecho'][i]);
        }).join('');
    }
    function dibujarFirmas() {
        var html = '';
        for (var i = 0; i < (t.nFirmas || 0); i++) {
            var f = t.firmas[i] || (t.firmas[i] = { img: null, nombre: '', cargo: '' });
            html += '<div class="dip-firma-ed">' + slot(f.img, 'data-firma', i, 'Firma ' + (i + 1)) +
                '<div style="flex:1;"><input class="form-control input-sm" placeholder="Nombre de quien firma" data-firma-k="nombre" data-i="' + i + '" value="' + esc(f.nombre) + '">' +
                '<input class="form-control input-sm" placeholder="Cargo" data-firma-k="cargo" data-i="' + i + '" value="' + esc(f.cargo) + '"></div></div>';
        }
        $('dipFirmas').innerHTML = html;
    }

    // ---------- diseño propio (Canva) ----------
    function campoActual() {
        return t.canva ? t.canva.campos.filter(function (c) { return c.id === campoSel; })[0] : null;
    }
    function etiquetaCampo(c) {
        if (c.tipo === 'qr') return 'QR de validación';
        var s = String(c.contenido || '').replace(/\s+/g, ' ');
        return s.length > 26 ? s.substr(0, 25) + '…' : s || '(vacío)';
    }
    function dibujarCanva() {
        if (!t.canva) return;
        var c = t.canva;
        $('dipCanvaMini').hidden = !c.fondo;
        if (c.fondo) $('dipCanvaMini').src = c.fondo;
        $('dipCanvaBtn').textContent = c.fondo ? 'Cambiar diseño' : 'Seleccionar archivo';
        $('dipCanvaNombre').textContent = c.fondo ? (c.nombre || 'Diseño cargado') + ' · ' + (t.papel === 'a4' ? 'A4' : 'carta') : 'Aún no has subido el diseño';
        $('dipCampos').innerHTML = c.campos.map(function (f) {
            return '<button type="button" class="dpc-chip' + (f.id === campoSel ? ' sel' : '') + '" data-sel="' + esc(f.id) + '">' + esc(etiquetaCampo(f)) + '</button>';
        }).join('');
        dibujarEditorCampo();
    }
    function dibujarEditorCampo() {
        var f = campoActual(), ed = $('dipCampoEd');
        if (!f) { ed.innerHTML = '<p class="pp-sub">Selecciona un campo o arrástralo sobre la vista previa.</p>'; return; }
        if (f.tipo === 'qr') {
            ed.innerHTML = '<div class="form-group"><label>Tamaño del QR (' + f.size + ' px)</label><input type="range" min="50" max="180" value="' + f.size + '" data-f="size" data-num="1"></div>' +
                '<button type="button" class="btn btn-link btn-sm" data-borrar-campo style="color:#B23223;padding-left:0;">Quitar QR</button>';
            return;
        }
        var fuentes = Object.keys(D.FUENTES).map(function (k) { return '<option value="' + k + '"' + (f.fuente === k ? ' selected' : '') + '>' + (NOMBRES_FUENTE[k] || k) + '</option>'; }).join('');
        var al = [['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']].map(function (a) { return '<option value="' + a[0] + '"' + (f.align === a[0] ? ' selected' : '') + '>' + a[1] + '</option>'; }).join('');
        ed.innerHTML = '<div class="form-group"><label>Contenido</label><textarea class="form-control" rows="2" data-f="contenido">' + esc(f.contenido) + '</textarea>' +
            '<div class="dip-campos">' + D.CAMPOS.map(function (c) { return '<button type="button" data-insertar-campo="' + c[0] + '">' + c[1] + '</button>'; }).join('') + '</div></div>' +
            '<div class="dip-grid2"><div class="form-group"><label>Letra</label><select class="form-control input-sm" data-f="fuente">' + fuentes + '</select></div>' +
            '<div class="form-group"><label>Tamaño (px)</label><input type="number" min="8" max="120" class="form-control input-sm" value="' + f.size + '" data-f="size" data-num="1"></div>' +
            '<div class="form-group"><label>Color</label><input type="color" class="form-control input-sm" value="' + esc(f.color) + '" data-f="color" style="padding:2px;"></div>' +
            '<div class="form-group"><label>Alineación</label><select class="form-control input-sm" data-f="align">' + al + '</select></div>' +
            '<div class="form-group is-full"><label>Ancho del cuadro (' + f.w + '%)</label><input type="range" min="10" max="95" value="' + f.w + '" data-f="w" data-num="1"></div></div>' +
            '<div style="display:flex;gap:12px;flex-wrap:wrap;font-size:12px;">' +
            ['bold', 'italic', 'upper'].map(function (k) { return '<label style="font-weight:400;"><input type="checkbox" data-f="' + k + '"' + (f[k] ? ' checked' : '') + '> ' + { bold: 'Negrita', italic: 'Cursiva', upper: 'Mayúsculas' }[k] + '</label>'; }).join('') + '</div>' +
            '<button type="button" class="btn btn-link btn-sm" data-borrar-campo style="color:#B23223;padding-left:0;">Quitar campo</button>';
    }

    // ---------- carga de la actividad ----------
    function cargar(clave) {
        act = null;
        $('dip-editor').hidden = true;
        $('dipDest').innerHTML = '<tr><td colspan="4" class="pp-empty">—</td></tr>';
        if (!clave) {
            $('dipPreview').innerHTML = '<p class="pp-sub" style="padding:40px;text-align:center;">Elige un curso o evento para empezar.</p>';
            $('dipPreview').style.height = '';
            return;
        }
        $('dipPreview').innerHTML = '<p class="pp-sub" style="padding:40px;text-align:center;">Cargando...</p>';
        fetch(API + '?actividad=' + encodeURIComponent(clave), { credentials: 'same-origin' }).then(respuesta).then(function (j) {
            act = j.actividad; t = j.plantilla; defecto = j.defecto; urlBase = j.urlBase; participantes = j.participantes;
            if (!Array.isArray(t.firmas)) t.firmas = [];
            var opciones = FILTROS[act.evento ? 'evento' : 'curso'];
            $('dipFiltro').innerHTML = opciones.map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('');
            // Por defecto, el filtro mas estricto que tenga gente; si nadie cumple, todos.
            var con = opciones.filter(function (o) { return participantes.some(function (p) { return p.filtros.indexOf(o[0]) !== -1; }); })[0];
            $('dipFiltro').value = con ? con[0] : 'todos';
            idxPrev = 0; campoSel = null;
            marcarTodos(true);
            $('dip-editor').hidden = false;
            llenar();
            if (!CFG.puedeGestionar) {
                [].forEach.call(document.querySelectorAll('#dip-editor input, #dip-editor select, #dip-editor textarea, #dip-editor button'), function (el) { el.disabled = true; });
            }
            estado('');
            refrescar();
            try { history.replaceState(null, '', 'diplomas.php?tab=disenar&actividad=' + encodeURIComponent(clave)); } catch (e) { /* sin historial */ }
        }).catch(function (e) {
            $('dipPreview').innerHTML = '<p class="pp-sub" style="padding:40px;text-align:center;color:#B23223;">' + esc(e.message) + '</p>';
        });
    }
    function recargarParticipantes() {
        return fetch(API + '?actividad=' + encodeURIComponent(act.clave), { credentials: 'same-origin' }).then(respuesta).then(function (j) {
            participantes = j.participantes;
            refrescar();
        });
    }
    function marcarTodos(valor) {
        visibles().forEach(function (p) { seleccion[p.id] = valor; });
        if (!valor) seleccion = {};
    }

    // ---------- acciones ----------
    function ocupado(si) {
        cargando += si ? 1 : -1;
        dibujarParticipantes();
    }
    function guardar() {
        ocupado(true);
        estado('Guardando...');
        api({ accion: 'guardar', clave: act.clave, plantilla: JSON.stringify(t) }).then(function () {
            estado('Plantilla guardada. Se usará para todos los participantes de esta actividad.');
        }).catch(function (e) { estado(e.message, true); }).then(function () { ocupado(false); });
    }
    function emitir(accion) {
        var lista = marcados();
        if (!lista.length) return Promise.reject(new Error('Marca al menos un participante.'));
        return api({ accion: accion, clave: act.clave, plantilla: JSON.stringify(t), ids: lista.map(function (p) { return p.id; }) }).then(function (j) {
            lista.forEach(function (p) { if (j.codigos[p.id]) { p.codigo = j.codigos[p.id]; p.pdf = false; } });
            return { lista: lista, r: j };
        });
    }
    function imprimir() {
        var conPdf = marcados().filter(function (p) { return p.pdf; }).length;
        if (conPdf && !confirm(conPdf + ' participante(s) ya tienen un PDF cargado manualmente. Si continúas, su documento se reemplaza por el del diseño. ¿Continuar?')) return;
        ocupado(true);
        estado('Emitiendo códigos...');
        emitir('emitir').then(function (x) {
            var paginas = x.lista.map(function (p) { return D.html(t, D.datos(act, p, t, p.codigo, x.r.urls[p.id])); });
            D.imprimir(paginas, t, 'css/diplomas.css');
            estado(paginas.length + ' documento(s) emitido(s). En el diálogo de impresión elige "Guardar como PDF" para obtener el archivo.');
            return recargarParticipantes();
        }).catch(function (e) { estado(e.message, true); alert(e.message); }).then(function () { ocupado(false); });
    }
    function enviar() {
        var lista = marcados(), sinCorreo = lista.filter(function (p) { return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(p.correo); }).length;
        var reenvio = lista.filter(function (p) { return p.enviado; }).length;
        var msg = 'Se enviará por correo el enlace al ' + String(t.documento || 'diploma').toLowerCase() + ' a ' + (lista.length - sinCorreo) + ' participante(s).';
        if (sinCorreo) msg += '\n' + sinCorreo + ' no tienen correo válido y se omitirán (se emite su código igual).';
        if (reenvio) msg += '\n' + reenvio + ' ya lo habían recibido y se les reenviará.';
        if (!confirm(msg + '\n\n¿Continuar?')) return;
        ocupado(true);
        estado('Enviando correos...');
        emitir('enviar').then(function (x) {
            var r = x.r, partes = [r.enviados + ' correo(s) enviado(s)'];
            if (r.sinCorreo.length) partes.push(r.sinCorreo.length + ' sin correo: ' + r.sinCorreo.join(', '));
            if (r.fallidos) partes.push(r.fallidos + ' fallido(s); revisa Comunicaciones');
            estado(partes.join(' · ') + '.', r.fallidos > 0);
            return recargarParticipantes();
        }).catch(function (e) { estado(e.message, true); alert(e.message); }).then(function () { ocupado(false); });
    }

    // ---------- eventos ----------
    $('dipAct').addEventListener('change', function () { seleccion = {}; cargar(this.value); });
    $('dipFiltro').addEventListener('change', function () { seleccion = {}; idxPrev = 0; marcarTodos(true); refrescar(); });
    $('dipBuscar').addEventListener('input', function () { idxPrev = 0; refrescar(); });
    $('dipSelTodo').addEventListener('change', function () { marcarTodos(this.checked); dibujarParticipantes(); });
    $('dipPrev').addEventListener('click', function () { if (!act) return; var n = visibles().length; idxPrev = n ? (idxPrev - 1 + n) % n : 0; refrescar(); });
    $('dipNext').addEventListener('click', function () { if (!act) return; var n = visibles().length; idxPrev = n ? (idxPrev + 1) % n : 0; refrescar(); });
    $('dipDest').addEventListener('change', function (e) {
        if (e.target.dataset.id) { seleccion[e.target.dataset.id] = e.target.checked; dibujarParticipantes(); }
    });
    $('dipDest').addEventListener('click', function (e) {
        var a = e.target.closest('[data-ver]');
        if (a) { e.preventDefault(); idxPrev = +a.dataset.ver; refrescar(); }
    });
    if ($('dipBtnPdf')) {
        $('dipBtnPdf').addEventListener('click', imprimir);
        $('dipBtnMail').addEventListener('click', enviar);
        $('dipGuardar').addEventListener('click', guardar);
        $('dipRestablecer').addEventListener('click', function () {
            if (!confirm('¿Volver al diseño predeterminado? Se pierden logos, firmas y textos personalizados (hasta que guardes, la plantilla guardada no cambia).')) return;
            t = clonar(defecto); campoSel = null;
            llenar(); refrescar();
            estado('Diseño restablecido. Pulsa "Guardar plantilla" para conservarlo.');
        });
    }

    var editor = $('dip-editor');
    function cambioCampo(el) {
        var k = el.dataset.campo;
        t[k] = el.type === 'checkbox' ? el.checked : el.dataset.numero ? Number(el.value) : el.value;
        if (k === 'nFirmas') dibujarFirmas();
        visibilidad();
        dibujarPrevia();
    }
    editor.addEventListener('input', function (e) {
        var el = e.target;
        if (el.dataset.campo && el.type !== 'file') cambioCampo(el);
        else if (el.dataset.firmaK) { t.firmas[+el.dataset.i][el.dataset.firmaK] = el.value; dibujarPrevia(); }
        else if (el.dataset.f && el.type !== 'checkbox' && el.tagName !== 'SELECT') {
            var f = campoActual();
            if (!f) return;
            f[el.dataset.f] = el.dataset.num ? Number(el.value) : el.value;
            if (el.dataset.f === 'contenido') $('dipCampos').querySelector('.sel').textContent = etiquetaCampo(f);
            if (el.type === 'range') { var lab = el.parentNode.querySelector('label'); lab.textContent = lab.textContent.replace(/\(\d+/, '(' + el.value); }
            dibujarPrevia();
        }
    });
    editor.addEventListener('change', function (e) {
        var el = e.target;
        if (el.type === 'file') return archivo(el);
        if (el.dataset.campo && (el.tagName === 'SELECT' || el.type === 'checkbox')) cambioCampo(el);
        else if (el.dataset.f && (el.type === 'checkbox' || el.tagName === 'SELECT')) {
            var f = campoActual();
            if (!f) return;
            f[el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value;
            dibujarPrevia();
        }
    });
    editor.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.estilo) {
            t.estilo = b.dataset.estilo;
            if (t.estilo === 'canva' && !t.canva) t.canva = D.canvaDefecto();
            campoSel = null;
            llenar(); dibujarPrevia();
        } else if (b.dataset.quitarLogo !== undefined) {
            t.logos[+b.dataset.quitarLogo] = null; dibujarLogos(); dibujarPrevia();
        } else if (b.dataset.quitarFirma !== undefined) {
            t.firmas[+b.dataset.quitarFirma].img = null; dibujarFirmas(); dibujarPrevia();
        } else if (b.dataset.sel) {
            campoSel = b.dataset.sel; dibujarCanva(); dibujarPrevia();
        } else if (b.dataset.agregar) {
            var nuevo = b.dataset.agregar === 'qr'
                ? { id: 'c' + Date.now(), tipo: 'qr', x: 88, y: 84, size: 86 }
                : { id: 'c' + Date.now(), tipo: 'texto', contenido: '{actividad}', x: 50, y: 50, w: 60, size: 22, color: '#1C2517', fuente: 'sans', bold: false, italic: false, upper: false, align: 'center' };
            t.canva.campos.push(nuevo);
            campoSel = nuevo.id; dibujarCanva(); dibujarPrevia();
        } else if (b.hasAttribute('data-borrar-campo')) {
            t.canva.campos = t.canva.campos.filter(function (c) { return c.id !== campoSel; });
            campoSel = null; dibujarCanva(); dibujarPrevia();
        } else if (b.dataset.insertarCampo) {
            var ta = b.closest('.form-group').querySelector('textarea');
            var ini = ta.selectionStart || ta.value.length, fin = ta.selectionEnd || ta.value.length;
            ta.value = ta.value.slice(0, ini) + b.dataset.insertarCampo + ta.value.slice(fin);
            ta.focus();
            ta.selectionStart = ta.selectionEnd = ini + b.dataset.insertarCampo.length;
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    function archivo(input) {
        var file = input.files && input.files[0];
        if (!file) return;
        if (input.dataset.imagen) {
            leerImagen(file, +input.dataset.max || 1600, true, function (src) { t[input.dataset.imagen] = src; dibujarPrevia(); estado('Imagen de fondo cargada.'); });
        } else if (input.dataset.logo !== undefined) {
            leerImagen(file, 600, false, function (src) { t.logos[+input.dataset.logo] = src; dibujarLogos(); dibujarPrevia(); });
        } else if (input.dataset.firma !== undefined) {
            leerImagen(file, 500, false, function (src) { t.firmas[+input.dataset.firma].img = src; dibujarFirmas(); dibujarPrevia(); });
        } else if (input.id === 'dipCanvaFile') {
            leerImagen(file, 2200, true, function (src, w, h) {
                t.canva.fondo = src;
                t.canva.nombre = file.name.substr(0, 80);
                t.canva.h = Math.round(1123 * h / w);
                // Proporcion A4 = 1.414; carta = 1.294.
                t.papel = Math.abs(w / h - 1.414) < Math.abs(w / h - 1.294) ? 'a4' : 'carta';
                $('dipPapel').value = t.papel;
                dibujarCanva(); dibujarPrevia();
                if (Math.min(Math.abs(w / h - 1.414), Math.abs(w / h - 1.294)) > 0.08) estado('Ojo: el diseño no tiene proporción carta ni A4 horizontal; puede quedar recortado al imprimir.', true);
            });
        }
        input.value = '';
    }
    $('dipCamposCuerpo').innerHTML = D.CAMPOS.map(function (c) { return '<button type="button" data-insertar-campo="' + c[0] + '">' + c[1] + '</button>'; }).join('');

    // Arrastrar campos del diseño propio sobre la vista previa.
    var arrastre = null;
    $('dipPreview').addEventListener('pointerdown', function (e) {
        var el = e.target.closest('.dpc-campo.ed');
        if (!el || !t || t.estilo !== 'canva') return;
        e.preventDefault();
        if (campoSel !== el.dataset.campo) {
            campoSel = el.dataset.campo;
            dibujarCanva();
            [].forEach.call($('dipPreview').querySelectorAll('.dpc-campo'), function (c) { c.classList.toggle('sel', c === el); });
        }
        var hoja = el.parentNode.getBoundingClientRect();
        arrastre = { el: el, hoja: hoja, f: campoActual(), dx: e.clientX, dy: e.clientY, x0: campoActual().x, y0: campoActual().y };
        el.setPointerCapture(e.pointerId);
    });
    $('dipPreview').addEventListener('pointermove', function (e) {
        if (!arrastre) return;
        var a = arrastre;
        a.f.x = Math.max(0, Math.min(100, Math.round((a.x0 + (e.clientX - a.dx) / a.hoja.width * 100) * 10) / 10));
        a.f.y = Math.max(0, Math.min(100, Math.round((a.y0 + (e.clientY - a.dy) / a.hoja.height * 100) * 10) / 10));
        a.el.style.left = a.f.x + '%';
        a.el.style.top = a.f.y + '%';
    });
    ['pointerup', 'pointercancel'].forEach(function (ev) {
        $('dipPreview').addEventListener(ev, function () { if (arrastre) { arrastre = null; dibujarPrevia(); } });
    });
    var temporizador;
    window.addEventListener('resize', function () { clearTimeout(temporizador); temporizador = setTimeout(dibujarPrevia, 120); });

    // ---------- emitidos y validacion ----------
    $('dipBuscarEm').addEventListener('input', function () {
        var q = normal(this.value);
        [].forEach.call($('dipEmitidos').querySelectorAll('tr[data-buscar]'), function (tr) { tr.hidden = q && tr.dataset.buscar.indexOf(q) === -1; });
    });
    function validar() {
        var codigo = $('dipValidar').value.trim().toUpperCase(), res = $('dipValRes');
        if (!codigo) return;
        res.innerHTML = '<p class="pp-sub">Validando...</p>';
        fetch(API + '?validar=' + encodeURIComponent(codigo), { credentials: 'same-origin' }).then(respuesta).then(function (j) {
            res.innerHTML = j.valido
                ? '<div class="dip-valido ok">✓ <b>Válido</b> · ' + esc(j.documento) + ' de <b>' + esc(j.nombre) + '</b> · ' + esc(j.actividad) + ' · emitido ' + fechaCorta(j.emitido) +
                  (j.enviado ? ' · enviado ' + fechaCorta(j.enviado) : '') + ' · <a href="' + esc(j.url) + '" target="_blank" rel="noopener">Ver documento</a></div>'
                : '<div class="dip-valido no">✗ No existe un documento emitido con el código <b>' + esc(codigo) + '</b>.</div>';
        }).catch(function (e) { res.innerHTML = '<div class="dip-valido no">' + esc(e.message) + '</div>'; });
    }
    $('dipBtnValidar').addEventListener('click', validar);
    $('dipValidar').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); validar(); } });

    if ($('dipAct').value) cargar($('dipAct').value);
})();
