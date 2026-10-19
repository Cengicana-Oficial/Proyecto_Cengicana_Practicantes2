/**
 * Control QR de eventos: vista por rol, pestañas de pagos / confirmacion / cobros, validacion
 * de pagos, confirmacion de participacion, recordatorios, gafetes impresos e ingreso con
 * camara. Las acciones van a eventos_pagos_api.php; ver eventos_pagos_helpers.php.
 */
(function () {
    'use strict';

    var CFG = window.CENGI_EVT || {};
    var API = 'eventos_pagos_api.php';

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; });
    }
    function normalizar(t) {
        return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
    function post(datos) {
        var fd = datos instanceof FormData ? datos : new FormData();
        if (!(datos instanceof FormData)) {
            Object.keys(datos).forEach(function (k) {
                if (Array.isArray(datos[k])) datos[k].forEach(function (v) { fd.append(k + '[]', v); });
                else fd.append(k, datos[k]);
            });
        }
        return fetch(API, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) {
            return r.json().catch(function () { return {ok: false, error: 'Respuesta no válida del servidor (' + r.status + ').'}; });
        });
    }
    // Aviso arriba de la pagina tras recargar (las listas y KPIs se calculan en el servidor).
    function recargar(mensaje, error, tab) {
        try { sessionStorage.setItem('cengiEvtAviso', JSON.stringify({m: mensaje, e: !!error})); } catch (e) { /* sin almacenamiento */ }
        var url = 'eventos_qr.php' + (tab ? '?tab=' + encodeURIComponent(tab) : '');
        if (window.location.pathname.indexOf('eventos_qr.php') !== -1 && window.location.search === (tab ? '?tab=' + tab : '')) window.location.reload();
        else window.location.href = url;
    }
    function tabActual() {
        return $('.cengi-ev-tab.active').attr('data-evtab') || 'eventos';
    }
    function mostrarAviso(m, error) {
        var $a = $('<div class="cengi-feedback' + (error ? ' is-error' : '') + '"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-' + (error ? 'warning-sign' : 'ok') + '"></span></div><div><p></p></div></div>');
        $a.find('p').text(m);
        $('.cengi-eventos-qr-page > .container').prepend($a);
    }
    try {
        var aviso = JSON.parse(sessionStorage.getItem('cengiEvtAviso') || 'null');
        sessionStorage.removeItem('cengiEvtAviso');
        if (aviso && aviso.m) mostrarAviso(aviso.m, aviso.e);
    } catch (e) { /* sin almacenamiento */ }

    // Si se hicieron cambios dentro de "Ver participantes", al cerrar se refrescan los indicadores.
    $('#modalEventoParticipantes').on('hidden.bs.modal', function () {
        if (window.CENGI_EVT_RECARGAR) recargar('', false, tabActual());
    });

    /* ---------- boton "Acciones" de cada evento ---------- */
    // Los enlaces del menu usan href="#": se evita que la pagina salte al inicio.
    $(document).on('click', '.cengi-ev-acciones a[href="#"]', function (e) { e.preventDefault(); });
    // La tabla tiene desplazamiento horizontal (recortaria el menu): al abrirlo se posiciona
    // fijo junto al boton, hacia arriba si no cabe abajo; se cierra al desplazarse.
    function ubicarAcciones($dd) {
        var $menu = $dd.children('.dropdown-menu'), r = $dd.children('.btn')[0].getBoundingClientRect();
        $menu.css({position: 'fixed', top: 0, left: 0, right: 'auto', bottom: 'auto'});
        var alto = $menu.outerHeight(), ancho = $menu.outerWidth();
        var arriba = r.bottom + alto + 8 > window.innerHeight && r.top - alto - 4 > 0;
        $menu.css({top: arriba ? r.top - alto - 4 : r.bottom + 4, left: Math.max(8, Math.min(r.right - ancho, window.innerWidth - ancho - 8))});
    }
    $(document).on('shown.bs.dropdown', '.cengi-ev-acciones', function () { ubicarAcciones($(this)); });
    $(document).on('hidden.bs.dropdown', '.cengi-ev-acciones', function () {
        $(this).children('.dropdown-menu').css({position: '', top: '', left: '', right: '', bottom: ''});
    });
    // Al desplazarse o cambiar el tamaño de la ventana el menu abierto sigue al boton.
    var reubicar = function () { $('.cengi-ev-acciones.open').each(function () { ubicarAcciones($(this)); }); };
    $(window).on('scroll resize', reubicar);
    $('.cengi-table-wrap').on('scroll', reubicar);

    /* ---------- vista por rol y pestañas ---------- */
    // La evaluación de eventos es página aparte (enlace .cengi-ev-tab-ir al final de las pestañas).
    var PESTANAS = {todos: ['eventos', 'inscritos', 'pagos', 'cobros'], admin: ['pagos', 'inscritos', 'cobros', 'eventos'], cap: ['eventos', 'inscritos'], consulta: ['eventos', 'inscritos', 'pagos', 'cobros']};
    function aplicarRol(rol, tab) {
        $('[data-evrol]').removeClass('sel').filter('[data-evrol="' + rol + '"]').addClass('sel');
        $('#evKpis .cengi-kpi').each(function () { $(this).toggle((' ' + $(this).attr('data-roles') + ' ').indexOf(' ' + rol + ' ') !== -1); });
        $('.cengi-ev-tareas-box').each(function () { $(this).toggle($(this).attr('data-rol') === rol); });
        var visibles = (PESTANAS[rol] || PESTANAS.consulta).filter(function (t) { return $('.cengi-ev-tab[data-evtab="' + t + '"]').length; });
        $('.cengi-ev-tab').each(function () {
            var t = $(this).attr('data-evtab');
            $(this).toggle(visibles.indexOf(t) !== -1).css('order', visibles.indexOf(t));
        });
        $('#evBtnNuevo').toggle(rol !== 'admin');
        $('.cengi-ev-tab-ir').toggle(rol !== 'admin');
        $('.cengi-ev-tab[data-evtab="' + (tab && visibles.indexOf(tab) !== -1 ? tab : visibles[0]) + '"]').trigger('click');
        try { localStorage.setItem('cengiEvtRol', rol); } catch (e) { /* sin almacenamiento */ }
    }
    $('[data-evrol]').on('click', function () { aplicarRol($(this).attr('data-evrol')); });
    $(document).on('click', '[data-ir-tab]', function () { $('.cengi-ev-tab[data-evtab="' + $(this).attr('data-ir-tab') + '"]').trigger('click'); });
    var rolGuardado = null;
    try { rolGuardado = localStorage.getItem('cengiEvtRol'); } catch (e) { /* sin almacenamiento */ }
    aplicarRol($('[data-evrol="' + rolGuardado + '"]').length ? rolGuardado : (CFG.rolInicial || 'consulta'), CFG.tabInicial);

    // La busqueda tambien filtra las listas de pagos, confirmacion y cobros.
    $('#evBuscar').on('input', function () {
        var q = normalizar(this.value).trim();
        $('.cengi-ev-lista tbody tr[data-buscar]').each(function () {
            $(this).toggle(!q || $(this).attr('data-buscar').indexOf(q) !== -1);
        });
    });

    /* ---------- pago (Administracion) ---------- */
    var pagoActual = null;
    function abrirPago(id) {
        $('#eqMsg').removeClass('is-err').empty();
        $('#eqForm')[0].reset();
        $('#eqInfo').html('<p class="cengi-ev-sub">Cargando…</p>');
        $('#modalEvPago').modal('show');
        $.getJSON(API, {participante: id}).done(function (r) {
            if (!r.ok) { $('#eqInfo').html('<p>' + esc(r.error) + '</p>'); return; }
            var p = pagoActual = r.participante;
            $('#eqId').val(p.id);
            $('#eqTitulo').text('Pago de ' + p.nombre);
            $('#eqSub').text(p.evento + (p.correo ? ' · ' + p.correo : ' · sin correo'));
            $('#eqInfo').html('<div class="cengi-ev-strip">' +
                '<div class="cengi-ev-mini-stat"><span class="ms-label">Debe pagar</span><span class="ms-val">' + esc(p.esperado) + '</span></div>' +
                '<div class="cengi-ev-mini-stat"><span class="ms-label">Estado</span><span class="ms-val" style="font-size:13px;">' + esc(p.estado_texto || 'Sin pago') + '</span></div>' +
                (p.recibo ? '<div class="cengi-ev-mini-stat"><span class="ms-label">Comprobante</span><span class="ms-val" style="font-size:13px;"><a href="' + API + '?recibo=' + p.id + '" target="_blank" rel="noopener">Ver archivo</a></span></div>' : '') +
                '</div>' + (p.nota ? '<p class="cengi-ev-sub" style="margin-top:8px;">Nota: ' + esc(p.nota) + '</p>' : '') +
                (p.validado_por ? '<p class="cengi-ev-sub">Resuelto por ' + esc(p.validado_por) + ' · ' + esc(p.validado_en) + '</p>' : '') +
                (p.confirmado ? '<p class="cengi-ev-sub"><b>La participación ya está confirmada.</b></p>' : ''));
            $('#eqOrigen').val(p.origen || 'nacional').closest('.form-group').toggle(p.costo_usd > 0);
            $('#eqBoleta').val(p.boleta);
            $('#eqBanco').val(p.banco);
            $('#eqFecha').val(p.fecha);
            $('#eqMonto').val(p.monto);
            $('#eqReciboAyuda').text(p.recibo ? '(ya tiene; sube otro solo para reemplazarlo)' : '(PDF, JPG o PNG)');
            $('#eqFoot [data-eq]').prop('disabled', !r.puedeValidar || p.confirmado);
        }).fail(function () { $('#eqInfo').html('<p>No fue posible cargar el pago.</p>'); });
    }
    function enviarPago(estado, forzar) {
        var fd = new FormData($('#eqForm')[0]);
        fd.append('accion', 'pago');
        fd.append('estado', estado);
        if (estado === 'rechazado') {
            var nota = window.prompt('Motivo del rechazo (se envía al participante):', '');
            if (nota === null) return;
            fd.append('nota', nota);
        }
        if (estado === 'exonerado') {
            var motivo = window.prompt('Motivo de la exoneración (opcional, p. ej. invitado especial):', '');
            if (motivo === null) return;
            fd.append('nota', motivo);
        }
        if (forzar) fd.append('forzar_monto', '1');
        $('#eqFoot button').prop('disabled', true);
        post(fd).then(function (r) {
            $('#eqFoot button').prop('disabled', false);
            if (!r.ok && r.confirmar) {
                if (window.confirm(r.error)) enviarPago(estado, true);
                return;
            }
            if (!r.ok) { $('#eqMsg').addClass('is-err').text(r.error); return; }
            $('#modalEvPago').modal('hide');
            if ($('#modalEventoParticipantes').hasClass('in')) {
                window.cengiEvtRecargarParticipantes();
                $('#evpGafetesFeedback').attr('class', 'cengi-feedback').html('<div class="cengi-feedback-icon"><span class="glyphicon glyphicon-ok"></span></div><div><p>' + esc(r.mensaje) + '</p></div>').show();
            } else {
                recargar(r.mensaje, false, tabActual());
            }
        });
    }
    $('#eqFoot').on('click', '[data-eq]', function () { enviarPago($(this).attr('data-eq')); });
    $(document).on('click', '[data-pago]', function () { abrirPago($(this).attr('data-pago')); });

    /* ---------- confirmar participacion (Capacitacion) ---------- */
    function confirmar(datos, texto) {
        if (!window.confirm(texto)) return;
        post($.extend({accion: 'confirmar'}, datos)).then(function (r) {
            if ($('#modalEventoParticipantes').hasClass('in')) {
                window.cengiEvtRecargarParticipantes();
                $('#evpGafetesFeedback').attr('class', 'cengi-feedback' + (r.ok ? '' : ' is-error')).html('<div class="cengi-feedback-icon"><span class="glyphicon glyphicon-' + (r.ok ? 'ok' : 'warning-sign') + '"></span></div><div><p>' + esc(r.ok ? r.mensaje : r.error) + '</p></div>').show();
            } else {
                recargar(r.ok ? r.mensaje : r.error, !r.ok, tabActual());
            }
        });
    }
    $(document).on('click', '[data-confirmar]', function () {
        confirmar({ids: [$(this).attr('data-confirmar')], evento_id: $(this).attr('data-evento')},
            '¿Confirmar la participación? Se habilita su código QR para el ingreso y se le envía el gafete por correo.');
    });
    $(document).on('click', '[data-confirmar-todos]', function () {
        var n = $('#evPanelInscritos [data-confirmar]').length;
        confirmar({todos: 1}, 'Se confirmarán ' + n + ' participación(es): se habilita su código QR y se envía el gafete. ¿Continuar?');
    });

    /* ---------- recordatorios de pago ---------- */
    function recordar(datos, texto) {
        if (!window.confirm(texto)) return;
        post($.extend({accion: 'recordar'}, datos)).then(function (r) {
            if ($('#modalEventoParticipantes').hasClass('in')) {
                window.cengiEvtRecargarParticipantes();
                $('#evpGafetesFeedback').attr('class', 'cengi-feedback' + (r.ok ? '' : ' is-error')).html('<div class="cengi-feedback-icon"><span class="glyphicon glyphicon-' + (r.ok ? 'ok' : 'warning-sign') + '"></span></div><div><p>' + esc(r.ok ? r.mensaje : r.error) + '</p></div>').show();
            } else {
                recargar(r.ok ? r.mensaje : r.error, !r.ok, tabActual());
            }
        });
    }
    $(document).on('click', '[data-recordar]', function () {
        var ev = window.cengiEvtEventoActual && window.cengiEvtEventoActual();
        recordar({ids: [$(this).attr('data-recordar')], evento_id: ev ? ev.id : ''}, 'Se enviará un recordatorio de pago con las instrucciones y el contacto de soporte. ¿Continuar?');
    });
    $(document).on('click', '[data-recordar-todos]', function () {
        recordar({todos: 1}, 'Se enviará un recordatorio de pago a todos los inscritos sin pago o con comprobante rechazado (eventos no finalizados). ¿Continuar?');
    });
    $('#evpRecordar').on('click', function () {
        var ev = window.cengiEvtEventoActual();
        recordar({todos: 1, evento_id: ev.id}, 'Se enviará un recordatorio de pago a los inscritos de este evento sin pago o con comprobante rechazado. ¿Continuar?');
    });

    /* ---------- ingreso en lote y gafetes impresos ---------- */
    $('#evpIngresoLote').on('click', function () {
        var ev = window.cengiEvtEventoActual(), sel = window.cengiEvtParticipantesSeleccionados().filter(function (p) { return !p.ingreso_en; });
        if (!sel.length) { window.alert('Los seleccionados ya tienen ingreso registrado.'); return; }
        if (!window.confirm('¿Registrar el ingreso de ' + sel.length + ' participante(s)? Solo se registra a quienes tienen la participación confirmada.')) return;
        post({accion: 'ingreso', evento_id: ev.id, ids: sel.map(function (p) { return p.id; })}).then(function (r) {
            window.cengiEvtRecargarParticipantes();
            $('#evpGafetesFeedback').attr('class', 'cengi-feedback' + (r.ok ? '' : ' is-error')).html('<div class="cengi-feedback-icon"><span class="glyphicon glyphicon-' + (r.ok ? 'ok' : 'warning-sign') + '"></span></div><div><p>' + esc(r.ok ? r.mensaje : r.error) + '</p></div>').show();
        });
    });

    function qrSvg(texto) {
        if (typeof window.qrcode !== 'function') return esc(texto);
        var q = window.qrcode(0, 'M');
        q.addData(String(texto), 'Byte');
        q.make();
        return q.createSvgTag({cellSize: 4, margin: 0, scalable: true});
    }
    $('#evpImprimirGafetes').on('click', function () {
        var sel = window.cengiEvtParticipantesSeleccionados();
        var listos = sel.filter(function (p) { return !!p.confirmado_en; });
        if (!listos.length) { window.alert('Solo se imprimen gafetes de participantes con la participación confirmada.'); return; }
        if (listos.length < sel.length && !window.confirm((sel.length - listos.length) + ' seleccionado(s) aún no tienen la participación confirmada y no se imprimirán. ¿Continuar con ' + listos.length + '?')) return;
        imprimirGafetes(listos);
    });
    // Gafetes con el diseno del evento (color, logo, tipo, fecha y lugar): tambien lo usa el modal "QR del participante".
    window.cengiEvtImprimirGafetes = imprimirGafetes;
    function imprimirGafetes(listos) {
        var ev = window.cengiEvtEventoActual();
        var detalle = [ev.fecha, ev.hora, ev.lugar].filter(Boolean).join(' · ');
        var colorBanda = /^#[0-9a-fA-F]{6}$/.test(ev.color || '') ? ev.color : '#1f5632';
        var logo = ev.logo ? '<div class="l"><img src="' + esc(ev.logo) + '" alt=""></div>' : '';
        var tarjetas = listos.map(function (p) {
            return '<div class="g"><div class="t">' + logo + '<div class="k">CENGICAÑA · ' + esc(ev.tipo || 'Evento') + '</div><div class="e">' + esc(ev.nombre) + '</div><div class="d">' + esc(detalle) + '</div></div>' +
                '<div class="b"><span class="c">' + esc(p.categoria_gafete || 'Participante') + '</span><div class="n">' + esc(p.nombre) + '</div><div class="i">' + esc(p.ingenio) + '</div>' +
                '<div class="q">' + qrSvg(p.codigo_qr) + '</div><div class="m">' + esc(p.codigo_qr) + '</div></div></div>';
        }).join('');
        var marco = document.createElement('iframe');
        marco.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
        document.body.appendChild(marco);
        var d = marco.contentWindow.document;
        d.open();
        d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Gafetes</title><style>@page{size:letter portrait;margin:10mm;}body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#294033;}' +
            '.w{display:grid;grid-template-columns:1fr 1fr;gap:8mm;}.g{border:1px solid #cfd8cc;border-radius:10px;overflow:hidden;break-inside:avoid;height:118mm;display:flex;flex-direction:column;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.l{background:#fff;border-radius:6px;display:inline-block;padding:3px 8px;margin-bottom:5px;}.l img{max-height:26px;max-width:150px;display:block;}' +
            '.t{background:' + colorBanda + ';color:#fff;text-align:center;padding:10px 12px;}.k{font-size:9px;letter-spacing:1px;text-transform:uppercase;opacity:.9;}.e{font-weight:700;font-size:15px;margin-top:4px;}.d{font-size:9px;opacity:.9;margin-top:2px;}' +
            '.b{flex:1;text-align:center;padding:10px;}.c{display:inline-block;background:#EAF6DD;color:#2F6B12;border-radius:20px;padding:2px 10px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;}' +
            '.n{font-size:18px;font-weight:700;margin-top:8px;}.i{font-size:12px;color:#55705f;margin:3px 0 8px;}.q svg{width:42mm;height:42mm;}.m{font-family:monospace;font-size:10px;color:#55705f;margin-top:4px;}</style></head><body><div class="w">' + tarjetas + '</div></body></html>');
        d.close();
        // Imprime cuando cargan las imagenes (logo del evento), con un limite de 4 s.
        var impreso = false, imprimir = function () {
            if (impreso) return;
            impreso = true;
            marco.contentWindow.focus();
            marco.contentWindow.print();
            setTimeout(function () { marco.remove(); }, 2000);
        };
        var pendientes = [].slice.call(d.images).filter(function (i) { return !i.complete; });
        var faltan = pendientes.length;
        pendientes.forEach(function (i) { i.onload = i.onerror = function () { if (--faltan <= 0) setTimeout(imprimir, 150); }; });
        setTimeout(faltan ? imprimir : function () { setTimeout(imprimir, 150); }, faltan ? 4000 : 0);
    }

    /* ---------- soporte de pagos ---------- */
    $(document).on('click', '[data-soporte]', function () { $('#etMsg').removeClass('is-err').empty(); $('#modalEvSoporte').modal('show'); });
    $('#etForm').on('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(this);
        fd.append('accion', 'soporte');
        post(fd).then(function (r) {
            if (!r.ok) { $('#etMsg').addClass('is-err').text(r.error); return; }
            $('#modalEvSoporte').modal('hide');
            recargar(r.mensaje, false, tabActual());
        });
    });

    /* ---------- registrar ingreso: camara (jsQR), codigo o busqueda en la puerta ---------- */
    var scan = {evento: null, stream: null, loop: null, ultimo: '', participantes: []};
    function resultado(r) {
        var ok = r.ok && r.estado === 'registrado', ya = r.estado === 'ya';
        $('#esResultado').html('<div class="cengi-ev-scan-res ' + (ok ? 'is-ok' : ya ? 'is-ya' : 'is-no') + '"><b>' + esc(r.nombre || (ok ? 'Ingreso registrado' : 'Código no válido')) + '</b>' +
            (r.categoria && r.categoria !== 'Participante' ? ' · ' + esc(r.categoria) : '') + '<div>' + esc(r.mensaje || r.error || '') + '</div></div>');
        if (ok || ya) cargarUltimos();
    }
    function registrar(datos) {
        return post($.extend({accion: 'ingreso', evento_id: scan.evento}, datos)).then(function (r) {
            resultado(r);
            var p = scan.participantes.filter(function (x) { return x.id === r.id; })[0];
            if (r.estado === 'registrado') scan.cambios = true;
            if (p && r.estado === 'registrado') p.ingreso_en = 'ahora';
            buscarPuerta();
            return r;
        });
    }
    function cargarUltimos() {
        $.getJSON(API, {ingresos: scan.evento}).done(function (r) {
            if (!r.ok) return;
            $('#esConteo').text(r.ingresos + ' de ' + r.confirmados + ' confirmados');
            $('#esUltimos').html(r.ultimos.length ? r.ultimos.map(function (u) {
                return '<div class="cengi-ev-puerta-fila"><span>' + esc(u.nombre) + (u.categoria && u.categoria !== 'Participante' ? ' <small>· ' + esc(u.categoria) + '</small>' : '') + '</span><small class="mono">' + esc(String(u.ingreso_en).substr(11, 5)) + '</small></div>';
            }).join('') : '<p class="cengi-ev-sub">Aún no hay ingresos.</p>');
        });
    }
    function buscarPuerta() {
        var q = normalizar($('#esBuscar').val()).trim();
        if (q.length < 2) { $('#esLista').empty(); return; }
        var lista = scan.participantes.filter(function (p) {
            return normalizar([p.nombre, p.cui, p.correo, p.ingenio, p.codigo_qr].join(' ')).indexOf(q) !== -1;
        }).slice(0, 8);
        $('#esLista').html(lista.length ? lista.map(function (p) {
            var accion = p.ingreso_en ? '<span class="cengi-status-badge is-active"><i></i>Ya ingresó</span>'
                : p.confirmado_en ? '<button type="button" class="btn btn-primary btn-xs" data-puerta="' + p.id + '">Registrar ingreso</button>'
                : '<span class="cengi-status-badge is-rejected"><i></i>No confirmada</span>';
            return '<div class="cengi-ev-puerta-fila"><span><b>' + esc(p.nombre) + '</b><small> · ' + esc(p.ingenio) + '</small></span>' + accion + '</div>';
        }).join('') : '<p class="cengi-ev-sub">Nadie coincide con la búsqueda.</p>');
    }
    function detenerCamara() {
        if (scan.loop) cancelAnimationFrame(scan.loop);
        scan.loop = null;
        if (scan.stream) scan.stream.getTracks().forEach(function (t) { t.stop(); });
        scan.stream = null;
        $('#esVideo').hide();
    }
    function iniciarCamara() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof window.jsQR !== 'function') return;
        navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}}).then(function (stream) {
            if (!$('#modalEvScan').hasClass('in')) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
            scan.stream = stream;
            var video = document.getElementById('esVideo'), lienzo = document.getElementById('esCanvas'), ctx = lienzo.getContext('2d', {willReadFrequently: true});
            video.srcObject = stream;
            video.play();
            $('#esVideo').show();
            $('#esSinCam').hide();
            var leer = function () {
                if (video.readyState === video.HAVE_ENOUGH_DATA) {
                    lienzo.width = video.videoWidth;
                    lienzo.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0, lienzo.width, lienzo.height);
                    var img = ctx.getImageData(0, 0, lienzo.width, lienzo.height);
                    var codigo = window.jsQR(img.data, img.width, img.height, {inversionAttempts: 'dontInvert'});
                    if (codigo && codigo.data && codigo.data !== scan.ultimo) {
                        scan.ultimo = codigo.data;
                        registrar({codigo: codigo.data});
                        setTimeout(function () { scan.ultimo = ''; }, 3000);
                    }
                }
                scan.loop = requestAnimationFrame(leer);
            };
            leer();
        }).catch(function () {
            $('#esSinCam').show().text('No se pudo usar la cámara (permiso denegado o sin cámara). Escribe el código del gafete o busca a la persona.');
        });
    }
    $(document).on('click', '[data-ingreso]', function () {
        scan.evento = Number($(this).attr('data-ingreso'));
        scan.participantes = [];
        scan.cambios = false;
        $('#esSub').text($(this).attr('data-ingreso-nombre') + ' · solo se aceptan códigos de participantes confirmados');
        $('#esCodigo, #esBuscar').val('');
        $('#esResultado, #esLista, #esUltimos').empty();
        $('#esSinCam').show().text('La cámara se activa al abrir esta ventana. Si no está disponible, escribe el código del gafete o busca a la persona.');
        $('#modalEvScan').modal('show');
        $.getJSON('eventos_qr.php', {accion: 'listar_participantes', evento_id: scan.evento}).done(function (r) { if (r.ok) scan.participantes = r.participantes; });
        cargarUltimos();
    });
    $('#modalEvScan').on('shown.bs.modal', iniciarCamara).on('hidden.bs.modal', function () {
        detenerCamara();
        if (scan.cambios) recargar('', false, tabActual());
    });
    $('#esRegistrar').on('click', function () { var c = $('#esCodigo').val().trim(); if (c) registrar({codigo: c}).then(function () { $('#esCodigo').val('').focus(); }); });
    $('#esCodigo').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#esRegistrar').trigger('click'); } });
    $('#esBuscar').on('input', buscarPuerta);
    $('#esLista').on('click', '[data-puerta]', function () { registrar({id: $(this).attr('data-puerta')}); });
})();
