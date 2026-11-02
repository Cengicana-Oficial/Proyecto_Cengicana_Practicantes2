/**
 * Acuerdo de colaboracion de un evento (prototipo: ppColabAbrir / ppColabRender).
 * Pestañas: 1 Modalidad y roles · 2 Detalle economico · 3 Aceptacion, pago y liquidacion.
 * El calculo y las reglas viven en eventos_acuerdo_helpers.php; aqui solo se dibuja el estado
 * que devuelve eventos_acuerdo_api.php y se envian las acciones.
 */
(function () {
    'use strict';

    var API = 'eventos_acuerdo_api.php';
    var eventoId = null, tab = 'modalidad', S = null, P = {capacitacion: false, administracion: false}, cambios = false;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; });
    }
    function q(n) {
        return 'Q' + Number(n || 0).toLocaleString('es-GT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    function fecha(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(iso || ''));
        return m ? Number(m[3]) + ' ' + MESES[Number(m[2]) - 1] + ' ' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '') : '—';
    }
    function hoy() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function badge(estado) {
        var e = (S.estados || {})[estado] || [estado, 'is-neutral'];
        return '<span class="cengi-status-badge ' + e[1] + '"><i></i>' + esc(e[0]) + '</span>';
    }
    function aviso(m, error) {
        $('#caMsg').attr('class', 'cengi-feedback' + (error ? ' is-error' : '')).html('<div class="cengi-feedback-icon"><span class="glyphicon glyphicon-' + (error ? 'warning-sign' : 'ok') + '"></span></div><div><p>' + esc(m) + '</p></div>').toggle(!!m);
    }
    function dis(cond) { return cond ? ' disabled' : ''; }

    function enviar(datos, archivos) {
        var fd = new FormData();
        fd.append('evento_id', eventoId);
        Object.keys(datos).forEach(function (k) { fd.append(k, typeof datos[k] === 'object' ? JSON.stringify(datos[k]) : datos[k]); });
        Object.keys(archivos || {}).forEach(function (k) { if (archivos[k]) fd.append(k, archivos[k]); });
        $('#caBody, #caFoot').find('button').prop('disabled', true);
        return fetch(API, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) {
            return r.json().catch(function () { return {ok: false, error: 'Respuesta no válida del servidor (' + r.status + ').'}; });
        }).then(function (r) {
            if (!r.ok && r.confirmar) {
                render();
                if (window.confirm(r.error)) { datos.forzar = 1; return enviar(datos, archivos); }
                return r;
            }
            if (r.ok) { S = r.estado; cambios = true; }
            render();
            if (r.ok && r.mensaje && datos.accion !== 'config' && datos.accion !== 'consumo') aviso(r.mensaje);
            if (!r.ok) aviso(r.error, true);
            return r;
        });
    }
    function config(campo, valor) { return enviar({accion: 'config', campo: campo, valor: valor}); }

    function cargar() {
        $('#caBody').html('<p class="cengi-ev-sub">Cargando…</p>');
        $.getJSON(API, {evento: eventoId}).done(function (r) {
            if (!r.ok) { $('#caBody').html('<p>' + esc(r.error) + '</p>'); return; }
            S = r.estado; P = r.permisos; render();
        }).fail(function (x) {
            $('#caBody').html('<p>' + esc((x.responseJSON && x.responseJSON.error) || 'No fue posible cargar el acuerdo.') + '</p>');
        });
    }

    window.cengiEvtAcuerdo = function (id, pestana) {
        eventoId = Number(id);
        tab = pestana || 'modalidad';
        cambios = false;
        aviso('');
        $('#modalAcuerdo').modal('show');
        cargar();
    };
    $(document).on('click', '[data-acuerdo]', function () { window.cengiEvtAcuerdo($(this).attr('data-acuerdo'), $(this).attr('data-acuerdo-tab')); });
    $('#modalAcuerdo').on('click', '[data-catab]', function () { tab = $(this).attr('data-catab'); aviso(''); render(); })
        .on('hidden.bs.modal', function () { if (cambios) window.location.reload(); });

    function render() {
        if (!S) return;
        var ev = S.evento, a = S.acuerdo, cal = S.calculo, emp = S.empresas[0] || {}, M = S.modalidades[ev.modalidad];
        $('#caTitulo').text('Acuerdo de colaboración · ' + ev.nombre);
        $('#caSub').html(esc(emp.nombre || 'Sin empresa aliada') + ' · ' + esc(M.corto) + ' · ' + badge(a.estado) + ' · Aporte ' + q(cal.total));
        $('[data-catab]').removeClass('active').filter('[data-catab="' + tab + '"]').addClass('active');
        $('#caBody').html(tab === 'modalidad' ? htmlModalidad() : tab === 'costos' ? htmlCostos() : htmlSeguimiento());
        $('#caFoot').html('<a class="btn btn-default" target="_blank" rel="noopener" href="' + API + '?documento=propuesta&evento=' + ev.id + '">Ver propuesta económica</a>' +
            (S.orden[a.estado] >= 2 ? '<a class="btn btn-default" target="_blank" rel="noopener" href="' + API + '?documento=expediente&evento=' + ev.id + '">Expediente</a>' : '') +
            '<button type="button" class="btn btn-primary" data-dismiss="modal">Listo</button>');
    }

    /* ---------- 1 · Modalidad y roles ---------- */
    function htmlModalidad() {
        var ev = S.evento, a = S.acuerdo, ro = S.soloLectura || !P.capacitacion, emp = S.empresas[0] || {};
        var mods = [1, 2, 3].map(function (m) {
            var d = S.modalidades[m];
            return '<button type="button" class="ca-mod' + (ev.modalidad === m ? ' sel' : '') + '" style="--c:' + d.c + ';" data-ca-mod="' + m + '"' + dis(ro) + '><span class="ca-num">' + m + '</span><b>' + esc(d.n) + '</b><span>' + esc(d.desc) + '</span></button>';
        }).join('');
        var nota = ev.financia === 'compartido'
            ? '<div class="cengi-ev-aviso">La cuota de participantes se configura en “Editar evento” (acceso pagado). Actualmente: ' + (ev.modalidad_pago === 'Pagado' ? q(ev.costo) : 'el evento está como gratuito') + '.</div>'
            : ev.financia === 'empresa' ? '<div class="ca-ok">Los participantes se inscriben <b>sin costo</b> y reciben su QR al inscribirse; la empresa aliada cubre el aporte.</div>' : '';
        var tareas = function (resp) {
            return a.tareas.map(function (t, k) {
                return t.resp === resp ? '<label class="ca-tarea"><input type="checkbox" data-ca-tarea="' + k + '"' + (t.ok ? ' checked' : '') + dis(!P.capacitacion) + '> ' + esc(t.t) + '</label>' : '';
            }).join('');
        };
        var hechas = a.tareas.filter(function (t) { return t.ok; }).length;
        return '<div class="ca-mods">' + mods + '</div>' +
            '<div class="cengi-form-grid" style="margin-top:14px;">' +
            (ev.modalidad === 3 ? '<div class="form-group"><label class="control-label">Modo</label><select class="form-control" data-ca-campo="modo"' + dis(ro) + '><option value="presencial"' + (a.modo === 'presencial' ? ' selected' : '') + '>Presencial</option><option value="linea"' + (a.modo === 'linea' ? ' selected' : '') + '>En línea</option></select></div>' : '') +
            '<div class="form-group"><label class="control-label">¿Quién cubre el costo?</label><select class="form-control" data-ca-campo="financia"' + dis(ro) + '>' +
            [['empresa', 'La empresa aliada (participantes sin costo)'], ['compartido', 'Compartido (los participantes pagan una cuota)'], ['centro', 'CENGICAÑA asume el costo (alianza estratégica)']].map(function (o) {
                return '<option value="' + o[0] + '"' + (ev.financia === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
            }).join('') + '</select></div></div>' + nota +
            '<div class="ca-2"><div><div class="ca-t">Rol del Centro</div>' + tareas('Centro') + '</div><div><div class="ca-t">Responsabilidades de ' + esc(emp.nombre || 'la empresa') + '</div>' + tareas('Empresa') +
            (P.capacitacion ? '<div class="ca-fila"><input class="form-control input-sm" id="caNuevaTarea" placeholder="Agregar tarea o acuerdo..." maxlength="200"><select class="form-control input-sm" id="caNuevaResp" style="width:110px;"><option>Centro</option><option>Empresa</option></select><button type="button" class="btn btn-default btn-sm" data-ca="tarea-agregar">Agregar</button></div>' : '') +
            '</div></div><div class="cengi-ev-sub" style="margin-top:8px;">' + hechas + ' de ' + a.tareas.length + ' tareas cumplidas' + (S.soloLectura ? ' · la modalidad ya no se puede cambiar porque el aporte tiene pagos' : '') + '</div>';
    }

    /* ---------- 2 · Detalle economico ---------- */
    function htmlCostos() {
        var a = S.acuerdo, cal = S.calculo, T = S.tarifas, ro = S.soloLectura || !P.capacitacion, fiscalRo = !(P.capacitacion || P.administracion);
        var alims = cal.presencial ? '<div class="ca-t" style="margin-top:14px;">Servicios de alimentación por día</div><div class="ca-alim">' + (a.alims.length ? a.alims.map(function (x, k) {
            return '<div class="ca-alim-f"><select class="form-control input-sm" data-ca-alim="' + k + '" data-k="tipo"' + dis(ro) + '>' + Object.keys(S.alim).map(function (t) {
                return '<option value="' + t + '"' + (x.tipo === t ? ' selected' : '') + '>' + esc(S.alim[t].n) + ' (Q' + S.alim[t].r[0] + '–Q' + S.alim[t].r[1] + ')</option>';
            }).join('') + '</select><label>Por día <input type="number" min="1" max="6" class="form-control input-sm" style="width:64px;" value="' + x.tiempos + '" data-ca-alim="' + k + '" data-k="tiempos"' + dis(ro) + '></label>' +
                '<label>Q por persona <input type="number" min="0" step="0.01" class="form-control input-sm" style="width:84px;" value="' + x.precio + '" data-ca-alim="' + k + '" data-k="precio"' + dis(ro) + '></label>' +
                '<span class="cengi-ev-sub">' + (x.tiempos * a.participantes * a.dias) + ' unidades · <b>' + q(x.tiempos * a.participantes * a.dias * x.precio) + '</b></span>' +
                (ro ? '' : '<button type="button" class="close" aria-label="Quitar servicio" data-ca-alim-quitar="' + k + '">&times;</button>') + '</div>';
        }).join('') : '<div class="cengi-ev-sub">Sin servicio de alimentación.</div>') + '</div>' +
            (ro ? '' : '<div class="ca-fila"><button type="button" class="btn btn-default btn-sm" data-ca="alim-agregar" data-tipo="basica">+ Refacción</button><button type="button" class="btn btn-default btn-sm" data-ca="alim-agregar" data-tipo="almuerzo">+ Almuerzo</button><button type="button" class="btn btn-link btn-sm" data-ca="alim-jornada">Jornada completa: 2 refacciones + 1 almuerzo</button></div>') +
            '<div class="cengi-ev-sub">Las cantidades se multiplican por participantes y por días del evento.</div>' : '';
        var lineas = cal.lineas.map(function (l) {
            var u = l.extra != null ? '<input type="number" min="0" step="0.01" class="form-control input-sm ca-monto" value="' + l.u + '" data-ca-extra="' + l.extra + '"' + dis(ro) + '>'
                : l.alim != null ? q(l.u) : '<input type="number" min="0" step="0.01" class="form-control input-sm ca-monto" value="' + l.u + '" data-ca-precio="' + l.k + '" title="Tarifa vigente; ajústala si aplica una condición especial"' + dis(ro) + '>';
            return '<tr><td><b>' + esc(l.c) + '</b></td><td class="cengi-ev-sub">' + esc(l.d) + '</td><td class="cengi-ev-num">' + Number(l.cant) + ' <span class="cengi-ev-sub">' + esc(l.unidad) + '</span></td><td>' + u + '</td><td class="cengi-ev-num"><b>' + q(l.sub) + '</b></td>' +
                '<td>' + (l.extra != null && !ro ? '<button type="button" class="close" aria-label="Quitar" data-ca-extra-quitar="' + l.extra + '">&times;</button>' : '') + '</td></tr>';
        }).join('');
        var f = function (k, l, full) {
            return '<div class="form-group' + (full ? ' cengi-form-full' : '') + '"><label class="control-label">' + l + '</label><input class="form-control" data-ca-fiscal="' + k + '" value="' + esc(a['fiscal_' + k] || '') + '"' + dis(fiscalRo) + '></div>';
        };
        return '<div class="cengi-form-grid">' +
            (cal.presencial ? '<div class="form-group"><label class="control-label">Días de uso del auditorio</label><input type="number" min="1" class="form-control" value="' + a.dias + '" data-ca-campo="dias"' + dis(ro) + '></div>' : '') +
            '<div class="form-group"><label class="control-label">Participantes estimados</label><input type="number" min="1" class="form-control" value="' + a.participantes + '" data-ca-campo="participantes"' + dis(ro) + '></div>' +
            (cal.presencial ? '' : '<div class="form-group"><div class="cengi-ev-sub" style="padding-top:26px;">En línea: no incluye auditorio ni alimentación.</div></div>') + '</div>' + alims +
            '<div class="cengi-table-wrap ca-tabla"><table class="table"><thead><tr><th>Concepto</th><th>Descripción</th><th>Cantidad</th><th>Costo unitario</th><th>Subtotal</th><th></th></tr></thead><tbody>' + lineas +
            '<tr><td colspan="4" style="text-align:right;">Subtotal</td><td class="cengi-ev-num">' + q(cal.subtotal) + '</td><td></td></tr>' +
            '<tr><td colspan="4" style="text-align:right;">Descuento / condición especial</td><td><input type="number" min="0" step="0.01" class="form-control input-sm ca-monto" value="' + (a.descuento || 0) + '" data-ca-campo="descuento"' + dis(ro) + '></td><td></td></tr>' +
            '<tr class="ca-total"><td colspan="4" style="text-align:right;">Total del aporte</td><td class="cengi-ev-num">' + q(cal.total) + '</td><td></td></tr></tbody></table></div>' +
            (ro ? '' : '<div class="ca-fila"><input class="form-control input-sm" id="caExC" placeholder="Otro concepto (sonido adicional, mobiliario...)" maxlength="150"><input class="form-control input-sm" id="caExCant" type="number" min="1" value="1" style="width:80px;" aria-label="Cantidad"><input class="form-control input-sm" id="caExU" type="number" min="0" step="0.01" placeholder="Q" style="width:100px;" aria-label="Costo unitario"><button type="button" class="btn btn-default btn-sm" data-ca="extra-agregar">Agregar concepto</button></div>') +
            '<div class="ca-t" style="margin-top:18px;">Datos de la empresa para el recibo por donación</div><div class="cengi-form-grid">' +
            f('recibo', 'Recibo a nombre de') + f('nit', 'NIT') + f('direccion', 'Dirección fiscal', true) + f('descripcion', 'Descripción de la actividad') + f('objetivo', 'Objetivo de la actividad', true) + '</div>' +
            (fiscalRo ? '' : '<div class="ca-fila" style="justify-content:flex-end;"><button type="button" class="btn btn-default btn-sm" data-ca="fiscal">Guardar datos fiscales</button></div>') +
            '<div class="cengi-ev-sub" style="margin-top:8px;">Tarifas vigentes: auditorio ' + q(T.auditorioDia) + '/día · gestión y difusión ' + q(T.gestion1) + ' · coordinación ' + q(T.coord2) + ' · gastos administrativos ' + q(T.admin3) + ' (en línea ' + q(T.linea3) + ').' +
            ((P.capacitacion || P.administracion) ? ' <button type="button" class="cengi-ev-linkbtn" data-ca="tarifas">Actualizar tarifas</button>' : '') + '</div>';
    }

    /* ---------- 3 · Aceptacion, pago y liquidacion ---------- */
    function paso(n, t, est, cont) {
        return '<div class="ca-paso ' + est + '"><div class="ca-pn">' + (est === 'ok' ? '✓' : n) + '</div><div style="flex:1;min-width:0;"><b>' + t + '</b>' + cont + '</div></div>';
    }
    function cifras(lista) {
        return '<div class="ca-cifras">' + lista.map(function (c) { return '<div><span>' + c[0] + '</span><b' + (c[2] ? ' style="color:' + c[2] + ';"' : '') + '>' + c[1] + '</b></div>'; }).join('') + '</div>';
    }
    function tablaPagos(tipo) {
        var l = S.pagos.filter(function (p) { return p.tipo === tipo; });
        if (!l.length) return '';
        return '<table class="ca-ex"><thead><tr><th>Fecha</th><th>Forma</th><th>Referencia</th><th>Comprobante</th><th style="text-align:right;">Monto</th><th>Recibo</th><th></th></tr></thead><tbody>' + l.map(function (p) {
            var rc = S.recibos.filter(function (r) { return Number(r.id) === Number(p.recibo_id); })[0];
            return '<tr><td>' + fecha(p.fecha) + '</td><td>' + esc(p.forma) + (p.banco ? '<div class="cengi-ev-sub">' + esc(p.banco) + '</div>' : '') + '</td><td class="mono">' + esc(p.referencia) + '</td>' +
                '<td>' + (p.comprobante ? '<a target="_blank" rel="noopener" href="' + API + '?archivo=pago&evento=' + eventoId + '&id=' + p.id + '">' + esc(p.comprobante_nombre || 'Ver') + '</a>' : '<span class="cengi-ev-sub">—</span>') + '</td>' +
                '<td style="text-align:right;"><b>' + q(p.monto) + '</b></td><td>' + (rc ? (rc.estado === 'emitido' ? esc(rc.numero) : '<span style="color:#8A6600;">Solicitado</span>') : '<span class="cengi-ev-sub">Sin solicitar</span>') + '</td>' +
                '<td>' + (!rc && P.administracion && S.orden[S.acuerdo.estado] < 4 ? '<button type="button" class="close" title="Anular pago" data-ca-quitar-pago="' + p.id + '">&times;</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
    }
    function formPago(tipo, saldo) {
        return '<div class="ca-form" data-ca-form="' + tipo + '" hidden><div class="cengi-form-grid">' +
            '<div class="form-group"><label class="control-label">Forma de pago</label><select class="form-control" name="forma"><option>Transferencia</option><option>Depósito</option><option>Cheque</option></select></div>' +
            '<div class="form-group"><label class="control-label">Banco</label><input class="form-control" name="banco" placeholder="Banco de origen"></div>' +
            '<div class="form-group"><label class="control-label">Referencia / N.° de documento</label><input class="form-control" name="referencia"></div>' +
            '<div class="form-group"><label class="control-label">Fecha de acreditación</label><input type="date" class="form-control" name="fecha" value="' + hoy() + '"></div>' +
            '<div class="form-group"><label class="control-label">Monto recibido (Q)</label><input type="number" min="0" step="0.01" class="form-control" name="monto" value="' + saldo + '"></div>' +
            '<div class="form-group"><label class="control-label">Observaciones (opcional)</label><input class="form-control" name="obs"></div>' +
            '<div class="form-group cengi-form-full"><label class="control-label">Comprobante de pago (PDF, JPG o PNG)</label><input type="file" class="form-control" name="comprobante" accept=".pdf,.jpg,.jpeg,.png"></div></div>' +
            '<div class="ca-fila" style="justify-content:flex-end;"><button type="button" class="btn btn-link btn-sm" data-ca-form-cerrar="' + tipo + '">Cancelar</button><button type="button" class="btn btn-primary btn-sm" data-ca-pago="' + tipo + '">Guardar pago</button></div></div>';
    }
    function filaRecibo(r) {
        var sol = '<a class="btn btn-link btn-sm" target="_blank" rel="noopener" href="' + API + '?documento=solicitud&evento=' + eventoId + '&recibo=' + r.id + '">Solicitud</a>';
        if (r.estado === 'emitido') {
            return '<div class="ca-firma"><span><b>' + esc(r.numero) + '</b> · ' + (r.tipo === 'excedente' ? 'complementario' : 'aporte') + ' · ' + q(r.monto) + ' · emitido ' + fecha(r.fecha) + (r.enviado_en ? ' · enviado ' + fecha(r.enviado_en) : '') + '</span>' +
                '<a class="btn btn-link btn-sm" target="_blank" rel="noopener" href="' + API + '?archivo=recibo&evento=' + eventoId + '&id=' + r.id + '">Ver recibo</a>' + sol +
                (P.administracion && !r.enviado_en ? '<button type="button" class="btn btn-default btn-sm" data-ca="enviar-recibo" data-id="' + r.id + '">Enviar a la empresa</button>' : '') + '</div>';
        }
        return '<div class="ca-rec-pend"><div class="ca-fila" style="margin:0;"><span class="cengi-ev-pill is-amarillo">Solicitado ' + fecha(r.solicitado_en.substr(0, 10)) + '</span><span>' + (r.tipo === 'excedente' ? 'Recibo complementario' : 'Recibo del aporte') +
            ' por <b>' + q(r.monto) + '</b> a nombre de <b>' + esc(r.a_nombre) + '</b> (NIT ' + esc(r.nit) + ')</span>' + sol +
            (P.administracion ? '<button type="button" class="btn btn-link btn-sm" data-ca="recordar-recibo" data-id="' + r.id + '">Recordar al área financiera</button>' : '') + '</div>' +
            (P.administracion ? '<div class="cengi-form-grid" style="margin-top:8px;" data-ca-recibo-form="' + r.id + '"><div class="form-group"><label class="control-label">Número de recibo</label><input class="form-control" name="numero" placeholder="Serie y número del recibo emitido"></div>' +
                '<div class="form-group"><label class="control-label">Fecha de emisión</label><input type="date" class="form-control" name="fecha" value="' + hoy() + '"></div>' +
                '<div class="form-group cengi-form-full"><label class="control-label">Recibo emitido (archivo)</label><input type="file" class="form-control" name="archivo" accept=".pdf,.jpg,.jpeg,.png"></div></div>' +
                '<div class="ca-fila" style="justify-content:flex-end;"><button type="button" class="btn btn-primary btn-sm" data-ca="cargar-recibo" data-id="' + r.id + '">Cargar recibo emitido</button></div>' : '') + '</div>';
    }
    function htmlSeguimiento() {
        var ev = S.evento, a = S.acuerdo, R = S.resumen, ex = S.excedente, emp = S.empresas[0] || {}, o = S.orden[a.estado], admin = P.administracion, cap = P.capacitacion;
        // Paso 1: propuesta
        var p1 = '<div class="cengi-ev-sub">' + (o >= 1 ? 'Enviada ' + fecha(a.propuesta_enviada_en) + ' a ' + esc(a.propuesta_enviada_a || '') : 'Se envía el documento con el detalle económico y la hoja de aceptación (PDF) a ' + esc(emp.correo || 'la empresa (agrega su correo en “Editar evento”)') + '.') + '</div>' +
            (o <= 1 && cap ? '<div class="ca-fila"><button type="button" class="btn ' + (o === 0 ? 'btn-primary' : 'btn-default') + ' btn-sm" data-ca="enviar-propuesta">' + (o === 0 ? 'Enviar propuesta' : 'Reenviar propuesta') + '</button></div>' : '');
        // Paso 2: aceptacion
        var p2 = '';
        if (o >= 2) {
            p2 = '<div class="cengi-ev-sub">' + esc(a.acept_representante) + (a.acept_cargo ? ' · ' + esc(a.acept_cargo) : '') + ' · ' + fecha(a.acept_fecha) + ' · ' + a.acept_participantes + ' participantes estimados</div>' +
                (a.acept_archivo ? '<div class="ca-firma"><span><b>Hoja firmada:</b> ' + esc(a.acept_archivo_nombre) + ' · adjuntada ' + fecha(a.acept_archivo_fecha) + '</span><a class="btn btn-link btn-sm" target="_blank" rel="noopener" href="' + API + '?archivo=firma&evento=' + eventoId + '">Ver</a>' +
                    (cap ? '<label class="btn btn-link btn-sm" style="margin:0;cursor:pointer;">Reemplazar<input type="file" hidden accept=".pdf,.jpg,.jpeg,.png" data-ca-firma></label>' : '') + '</div>'
                    : '<div class="cengi-ev-aviso">Falta adjuntar la hoja de aceptación firmada.' + (cap ? ' <label class="cengi-ev-linkbtn" style="cursor:pointer;">Adjuntar hoja firmada<input type="file" hidden accept=".pdf,.jpg,.jpeg,.png" data-ca-firma></label>' : '') + '</div>');
        } else if (o === 1 && cap) {
            p2 = '<div class="cengi-ev-sub">Imprime el documento, que lo firme el representante, escanéalo (PDF o foto) y adjúntalo aquí.</div><div class="cengi-form-grid" style="margin-top:8px;" id="caAceptForm">' +
                '<div class="form-group"><label class="control-label">Representante</label><input class="form-control" name="representante" value="' + esc(emp.contacto || '') + '"></div>' +
                '<div class="form-group"><label class="control-label">Cargo</label><input class="form-control" name="cargo"></div>' +
                '<div class="form-group"><label class="control-label">Fecha de aceptación</label><input type="date" class="form-control" name="fecha" value="' + hoy() + '"></div>' +
                '<div class="form-group"><label class="control-label">Participantes estimados</label><input type="number" min="1" class="form-control" name="participantes" value="' + a.participantes + '"></div>' +
                '<div class="form-group cengi-form-full"><label class="control-label">Hoja de aceptación firmada</label><input type="file" class="form-control" name="archivo" accept=".pdf,.jpg,.jpeg,.png"></div></div>' +
                '<div class="ca-fila"><a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="' + API + '?documento=propuesta&evento=' + eventoId + '">Descargar documento para firma</a><button type="button" class="btn btn-primary btn-sm" data-ca="aceptar">Registrar aceptación</button></div>';
        }
        // Paso 3: pago del aporte y recibos
        var p3;
        if (o < 2) {
            p3 = '<div class="cengi-ev-sub">Se habilita cuando la empresa acepta la propuesta.</div>';
        } else {
            var venc = R.saldoAp > 0 && a.fecha_limite && a.fecha_limite < hoy();
            p3 = cifras([['Aporte acordado', q(R.aporte)], ['Recibido', q(R.pagAp), '#2F6B12'], ['Saldo', q(R.saldoAp), R.saldoAp ? '#B34E00' : '#2F6B12'],
                ['Fecha límite de pago', admin && o < 4 ? '<input type="date" class="form-control input-sm" value="' + (a.fecha_limite || '') + '" data-ca-limite style="width:150px;">' : fecha(a.fecha_limite), venc ? '#B23223' : '']]) +
                (venc ? '<div class="cengi-ev-aviso" style="background:#FBE3E0;color:#8E2A1E;">El plazo de pago venció el ' + fecha(a.fecha_limite) + '.' + (admin ? ' <button type="button" class="cengi-ev-linkbtn" data-ca="recordar-pago">Enviar recordatorio a la empresa</button>' : '') + '</div>' : '') +
                tablaPagos('aporte') +
                (R.saldoAp > 0 && admin && o < 4 ? '<div class="ca-fila"><button type="button" class="btn btn-primary btn-sm" data-ca-form-abrir="aporte">' + (R.pagAp ? 'Registrar otro abono' : 'Registrar pago') + '</button>' + (!venc ? '<button type="button" class="btn btn-link btn-sm" data-ca="recordar-pago">Recordar pago</button>' : '') + '</div>' + formPago('aporte', R.saldoAp) : '') +
                '<div class="ca-sub">Recibo contable por donación</div><div class="cengi-ev-sub" style="margin-bottom:6px;">El recibo lo emite el <b>área financiera</b> (' + esc(S.correoFinanciero) + ')' + (admin ? ' <button type="button" class="cengi-ev-linkbtn" data-ca="correo-financiero">cambiar</button>' : '') + '. Aquí se solicita con los datos del pago y, cuando lo emiten, se carga el archivo para enviarlo a la empresa.</div>' +
                (S.recibos.filter(function (r) { return r.tipo === 'aporte'; }).map(filaRecibo).join('') || '<div class="cengi-ev-sub">Aún no se ha solicitado el recibo.</div>') +
                (admin && S.pagos.some(function (p) { return p.tipo === 'aporte' && !p.recibo_id; }) ? '<div class="ca-fila"><button type="button" class="btn btn-default btn-sm" data-ca="solicitar-recibo" data-tipo="aporte">Solicitar recibo al área financiera</button></div>' : '') +
                (!a.fiscal_recibo || !a.fiscal_nit ? '<div class="cengi-ev-aviso">Para emitir el recibo faltan datos fiscales (' + [!a.fiscal_recibo && 'a nombre de', !a.fiscal_nit && 'NIT'].filter(Boolean).join(' y ') + '). <button type="button" class="cengi-ev-linkbtn" data-catab="costos">Completar en “Detalle económico”</button></div>' : '');
        }
        // Paso 4: liquidacion
        var p4;
        if (o < 3) {
            p4 = '<div class="cengi-ev-sub">Se habilita cuando el aporte está pagado.</div>';
        } else if (!R.realizado) {
            p4 = '<div class="cengi-ev-sub">Disponible después del evento (' + fecha(ev.fecha) + '). Ese día se registran los ingresos con QR y aquí se comparan con lo contratado.</div>';
        } else {
            var ro4 = o >= 4, exo = a.excedente && a.excedente.exonerado, cobro = a.excedente && a.excedente.cobro;
            p4 = cifras([['Participantes estimados', a.participantes], ['Registrados', ev.registrados], ['Asistieron (QR)', ev.asistentes], ['Asistencia vs. estimado', (a.participantes ? Math.round(ev.asistentes / a.participantes * 100) : 0) + '%']]) +
                (ex ? '<div class="ca-sub">Alimentación servida</div><table class="ca-ex"><thead><tr><th>Servicio</th><th>Contratadas</th><th>Servidas</th><th>Excedente</th><th>Monto</th></tr></thead><tbody>' + ex.items.map(function (x) {
                    return '<tr><td>' + esc(x.n) + '</td><td>' + x.contratadas + '</td><td><input type="number" min="0" class="form-control input-sm" style="width:80px;" value="' + x.consumidas + '" data-ca-consumo="' + x.i + '"' + dis(ro4) + '></td><td>' + x.extra + '</td><td>' + q(x.monto) + '</td></tr>';
                }).join('') + '</tbody></table><div class="cengi-ev-sub">Por defecto: asistentes con ingreso × servicios por día × días. Ajusta con el conteo real del proveedor.</div>' : '<div class="cengi-ev-sub">Sin servicio de alimentación contratado.</div>') +
                '<div class="ca-sub">Cargos adicionales</div>' + (a.cargos.length ? '<table class="ca-ex"><tbody>' + a.cargos.map(function (c, k) {
                    return '<tr><td>' + esc(c.c) + '</td><td style="width:120px;text-align:right;">' + q(c.monto) + '</td><td style="width:30px;">' + (ro4 ? '' : '<button type="button" class="close" aria-label="Quitar" data-ca-cargo-quitar="' + k + '">&times;</button>') + '</td></tr>';
                }).join('') + '</tbody></table>' : '<div class="cengi-ev-sub">Sin cargos adicionales.</div>') +
                (ro4 ? '' : '<div class="ca-fila"><input class="form-control input-sm" id="caCargoC" placeholder="Ej. Uso del auditorio fuera de horario (2 horas)" maxlength="150"><input class="form-control input-sm" id="caCargoM" type="number" min="0" step="0.01" placeholder="Q" style="width:100px;"><button type="button" class="btn btn-default btn-sm" data-ca="cargo-agregar">Agregar cargo</button></div>') +
                '<div class="ca-liq">' + [['Aporte acordado', q(R.aporte)], ['Excedente de alimentación', exo ? '<s>' + q(ex.monto) + '</s> exonerado' : q(R.excAlim)], ['Cargos adicionales', q(R.cargos)],
                    ['Total final del evento', q(R.totalFinal), 't'], ['Pagado', q(R.pagado)], ['Saldo por cobrar', q(R.saldoEx), 't']].map(function (x) { return '<div' + (x[2] ? ' class="t"' : '') + '><span>' + x[0] + '</span><b>' + x[1] + '</b></div>'; }).join('') + '</div>' +
                (exo ? '<div class="cengi-ev-sub">Excedente exonerado: ' + esc(exo.motivo) + ' (' + esc(exo.por) + ')</div>' : '') +
                (!ro4 && R.excTotal > 0 && admin ? '<div class="ca-fila">' + (cobro ? '<span class="cengi-ev-sub">Cobro enviado ' + fecha(cobro.ts) + ' · vence ' + fecha(cobro.vence) + (cobro.vence < hoy() && R.saldoEx ? ' · <b style="color:#B23223;">vencido</b>' : '') + '</span>' : '') +
                    (R.saldoEx ? '<button type="button" class="btn btn-default btn-sm" data-ca="cobrar-excedente">' + (cobro ? 'Reenviar cobro' : 'Enviar cobro a la empresa') + '</button><button type="button" class="btn btn-primary btn-sm" data-ca-form-abrir="excedente">Registrar pago del excedente</button>' : '') +
                    (ex && ex.monto && !exo ? '<button type="button" class="btn btn-link btn-sm" data-ca="exonerar">Exonerar excedente</button>' : '') + '</div>' + formPago('excedente', R.saldoEx) : '') +
                tablaPagos('excedente') + S.recibos.filter(function (r) { return r.tipo === 'excedente'; }).map(filaRecibo).join('') +
                (admin && S.pagos.some(function (p) { return p.tipo === 'excedente' && !p.recibo_id; }) ? '<div class="ca-fila"><button type="button" class="btn btn-default btn-sm" data-ca="solicitar-recibo" data-tipo="excedente">Solicitar recibo complementario</button></div>' : '') +
                (!ro4 ? (function () {
                    var pend = a.tareas.filter(function (t) { return !t.ok; }).length;
                    return '<div class="ca-fila" style="justify-content:flex-end;">' + (pend ? '<span class="cengi-ev-sub" style="color:#8A6600;">' + pend + ' tarea(s) del acuerdo sin marcar</span>' : '') +
                        (admin ? '<button type="button" class="btn btn-primary btn-sm" data-ca="liquidar"' + (R.saldoEx ? ' disabled title="Hay saldo por cobrar"' : '') + '>Liquidar acuerdo</button>' : '<span class="cengi-ev-sub">Administración liquida el acuerdo.</span>') + '</div>';
                }()) : '<div class="cengi-ev-sub" style="margin-top:6px;">Acuerdo liquidado.</div>');
        }
        // Paso 5: encuesta y cierre
        var p5;
        if (o < 4) {
            p5 = '<div class="cengi-ev-sub">Después de liquidar, se envía la encuesta a las empresas aliadas y se cierra el expediente.</div>';
        } else {
            p5 = S.empresas.map(function (c) {
                var r = c.respuesta;
                return '<div class="ca-enc"><div style="min-width:0;flex:1;"><b>' + esc(c.nombre) + '</b><div class="cengi-ev-sub">' + esc(c.contacto || '') + ' · ' + esc(c.correo || 'sin correo') + '</div></div>' +
                    (r ? '<span class="cengi-status-badge is-active"><i></i>Respondida</span><span class="cengi-ev-sub">' + fecha(r.creado) + (r.promedio != null ? ' · promedio ' + Number(r.promedio).toFixed(1) + ' ★' : '') + (r.participaria ? ' · participaría: ' + esc(r.participaria) : '') + '</span>'
                        : (c.enviado ? '<span class="cengi-status-badge is-waiting"><i></i>Enviada ' + fecha(c.enviado) + '</span>' : '<span class="cengi-status-badge is-neutral"><i></i>Sin enviar</span>') +
                        (cap && c.correo ? '<button type="button" class="btn btn-primary btn-sm" data-ca-encuesta="' + c.id + '">' + (c.enviado ? 'Reenviar' : 'Enviar encuesta') + '</button>' : '') +
                        '<button type="button" class="btn btn-link btn-sm" data-ca-copiar="' + esc(c.url) + '">Copiar enlace</button>') + '</div>';
            }).join('') + (S.indice != null ? '<div class="ca-cifras"><div><span>Índice de colaboración</span><b>' + Number(S.indice).toFixed(1) + ' ★</b></div></div>' : '') +
                (a.estado === 'cerrada' ? '<div class="cengi-ev-sub" style="margin-top:8px;">Expediente cerrado.</div>'
                    : '<div class="ca-fila" style="justify-content:flex-end;">' + (window.cengiEvtInforme ? '<button type="button" class="btn btn-link btn-sm" data-ca="informe">Informe del evento</button>' : '') +
                    '<a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="' + API + '?documento=expediente&evento=' + eventoId + '">Vista previa del expediente</a>' +
                    (cap ? '<button type="button" class="btn btn-primary btn-sm" data-ca="cerrar">Cerrar expediente</button>' : '') + '</div>');
        }
        var conRecibos = S.pagos.filter(function (p) { return p.tipo === 'aporte'; });
        var p3ok = o >= 3 && conRecibos.length && conRecibos.every(function (p) { var rc = S.recibos.filter(function (r) { return Number(r.id) === Number(p.recibo_id); })[0]; return rc && rc.estado === 'emitido'; });
        return paso(1, 'Enviar la propuesta a la empresa', o >= 1 ? 'ok' : 'act', p1) +
            paso(2, 'Registrar la aceptación firmada', o >= 2 && a.acept_archivo ? 'ok' : o >= 1 ? 'act' : '', p2) +
            paso(3, 'Pago del aporte y recibo por donación (Administración)', p3ok ? 'ok' : o >= 2 ? 'act' : '', p3) +
            paso(4, 'Liquidación después del evento', o >= 4 ? 'ok' : o === 3 && R.realizado ? 'act' : '', p4) +
            paso(5, 'Encuesta de colaboración y cierre del expediente', o >= 5 ? 'ok' : o === 4 ? 'act' : '', p5) +
            '<div class="ca-t" style="margin-top:14px;">Historial</div>' + (S.historial.length ? S.historial.map(function (h) {
                return '<div class="cengi-ev-sub">' + fecha(h.creado) + ' · ' + esc(h.texto) + ' · ' + esc(h.por) + (h.detalle ? ' — ' + esc(h.detalle) : '') + '</div>';
            }).join('') : '<div class="cengi-ev-sub">Sin movimientos.</div>');
    }

    /* ---------- eventos de la ventana ---------- */
    var $m = $('#modalAcuerdo');
    $m.on('click', '[data-ca-mod]', function () {
        var m = Number($(this).attr('data-ca-mod'));
        if (m === S.evento.modalidad) return;
        if (S.acuerdo.tareas.some(function (t) { return t.ok; }) && !window.confirm('Cambiar la modalidad reinicia las tareas del Centro y de la empresa y los precios ajustados. ¿Continuar?')) return;
        config('modalidad', m);
    });
    $m.on('change', '[data-ca-campo]', function () { config($(this).attr('data-ca-campo'), this.value); });
    $m.on('change', '[data-ca-tarea]', function () {
        var t = S.acuerdo.tareas.slice();
        t[Number($(this).attr('data-ca-tarea'))].ok = this.checked;
        config('tareas', t);
    });
    $m.on('change', '[data-ca-alim]', function () {
        var l = S.acuerdo.alims.slice(), k = Number($(this).attr('data-ca-alim')), campo = $(this).attr('data-k');
        l[k] = $.extend({}, l[k]);
        l[k][campo] = campo === 'tipo' ? this.value : Number(this.value);
        if (campo === 'tipo') l[k].precio = Math.round((S.alim[this.value].r[0] + S.alim[this.value].r[1]) / 2);
        config('alims', l);
    });
    $m.on('click', '[data-ca-alim-quitar]', function () { var l = S.acuerdo.alims.slice(); l.splice(Number($(this).attr('data-ca-alim-quitar')), 1); config('alims', l); });
    $m.on('change', '[data-ca-precio]', function () { var p = $.extend({}, S.acuerdo.precios); p[$(this).attr('data-ca-precio')] = this.value; config('precios', p); });
    $m.on('change', '[data-ca-extra]', function () { var l = S.acuerdo.extras.slice(), i = Number($(this).attr('data-ca-extra')); l[i] = $.extend({}, l[i], {u: Number(this.value)}); config('extras', l); });
    $m.on('click', '[data-ca-extra-quitar]', function () { var l = S.acuerdo.extras.slice(); l.splice(Number($(this).attr('data-ca-extra-quitar')), 1); config('extras', l); });
    $m.on('change', '[data-ca-consumo]', function () { enviar({accion: 'consumo', i: $(this).attr('data-ca-consumo'), valor: this.value}); });
    $m.on('change', '[data-ca-limite]', function () { enviar({accion: 'fecha_limite', fecha: this.value}); });
    $m.on('change', '[data-ca-firma]', function () { if (this.files[0]) enviar({accion: 'firma'}, {archivo: this.files[0]}); });
    $m.on('click', '[data-ca-cargo-quitar]', function () { enviar({accion: 'cargo_quitar', i: $(this).attr('data-ca-cargo-quitar')}); });
    $m.on('click', '[data-ca-quitar-pago]', function () { if (window.confirm('¿Anular este pago? Se quita del acuerdo junto con su comprobante.')) enviar({accion: 'quitar_pago', id: $(this).attr('data-ca-quitar-pago')}); });
    $m.on('click', '[data-ca-form-abrir]', function () { $('[data-ca-form="' + $(this).attr('data-ca-form-abrir') + '"]').prop('hidden', false)[0].scrollIntoView({block: 'nearest'}); });
    $m.on('click', '[data-ca-form-cerrar]', function () { $('[data-ca-form="' + $(this).attr('data-ca-form-cerrar') + '"]').prop('hidden', true); });
    $m.on('click', '[data-ca-pago]', function () {
        var tipo = $(this).attr('data-ca-pago'), $f = $('[data-ca-form="' + tipo + '"]'), v = function (n) { return $f.find('[name="' + n + '"]').val(); }, archivo = $f.find('[name="comprobante"]')[0].files[0];
        if (!archivo && !window.confirm('No adjuntaste el comprobante. ¿Guardar el pago sin comprobante?')) return;
        enviar({accion: 'pago', tipo: tipo, forma: v('forma'), banco: v('banco'), referencia: v('referencia'), fecha: v('fecha'), monto: v('monto'), obs: v('obs')}, {comprobante: archivo});
    });
    $m.on('click', '[data-ca-encuesta]', function () {
        var $b = $(this).prop('disabled', true);
        $.post('eventos_gestion.php', {accion: 'enviar_colab', evento_id: eventoId, aliado_id: $b.attr('data-ca-encuesta')}, null, 'json').always(function (r) {
            if (r && r.responseJSON) r = r.responseJSON;
            aviso(r && r.ok ? 'Encuesta de colaboración enviada.' : ((r && (r.mensaje || r.error)) || 'No se pudo enviar la encuesta.'), !(r && r.ok));
            cambios = true;
            cargar();
        });
    });
    $m.on('click', '[data-ca-copiar]', function () {
        var url = $(this).attr('data-ca-copiar');
        if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { aviso('Enlace de la encuesta copiado.'); }, function () { window.prompt('Copia el enlace:', url); });
        else window.prompt('Copia el enlace:', url);
    });
    $m.on('click', '[data-ca]', function () {
        var accion = $(this).attr('data-ca'), $b = $(this);
        switch (accion) {
            case 'tarea-agregar':
                var txt = $('#caNuevaTarea').val().trim();
                if (txt) config('tareas', S.acuerdo.tareas.concat([{t: txt, resp: $('#caNuevaResp').val(), ok: false}]));
                break;
            case 'alim-agregar':
                var l = S.acuerdo.alims.map(function (x) { return $.extend({}, x); }), tipo = $b.attr('data-tipo'), ex = l.filter(function (x) { return x.tipo === tipo; })[0];
                if (ex) ex.tiempos = Math.min(6, Number(ex.tiempos) + 1); else l.push({tipo: tipo, tiempos: 1, precio: Math.round((S.alim[tipo].r[0] + S.alim[tipo].r[1]) / 2)});
                config('alims', l);
                break;
            case 'alim-jornada':
                config('alims', [{tipo: 'basica', tiempos: 2, precio: 55}, {tipo: 'almuerzo', tiempos: 1, precio: 140}]);
                break;
            case 'extra-agregar':
                var c = $('#caExC').val().trim(), u = Number($('#caExU').val());
                if (!c || !(u >= 0) || $('#caExU').val() === '') { aviso('Escribe el concepto y su costo.', true); return; }
                config('extras', S.acuerdo.extras.concat([{c: c, cant: Number($('#caExCant').val()) || 1, u: u}]));
                break;
            case 'fiscal':
                var d = {accion: 'fiscal'};
                $m.find('[data-ca-fiscal]').each(function () { d[$(this).attr('data-ca-fiscal')] = this.value; });
                enviar(d);
                break;
            case 'tarifas':
                var T = S.tarifas, val = window.prompt('Tarifas vigentes separadas por coma:\nauditorio por día, gestión y difusión (1), coordinación (2), gastos administrativos (3), en línea (3)', [T.auditorioDia, T.gestion1, T.coord2, T.admin3, T.linea3].join(','));
                if (val === null) return;
                var n = val.split(',').map(function (x) { return Number(x.trim()); });
                if (n.length !== 5 || n.some(function (x) { return !(x >= 0); })) { aviso('Escribe los 5 valores numéricos separados por coma.', true); return; }
                enviar({accion: 'tarifas', auditorioDia: n[0], gestion1: n[1], coord2: n[2], admin3: n[3], linea3: n[4]});
                break;
            case 'enviar-propuesta':
                if (window.confirm('Se enviará la propuesta (PDF) por ' + q(S.calculo.total) + ' a ' + ((S.empresas[0] || {}).correo || 'la empresa') + '. ¿Continuar?')) enviar({accion: 'enviar_propuesta'});
                break;
            case 'aceptar':
                var $a = $('#caAceptForm'), g = function (k) { return $a.find('[name="' + k + '"]').val(); }, arch = $a.find('[name="archivo"]')[0].files[0];
                if (!arch && !window.confirm('No adjuntaste la hoja de aceptación firmada. ¿Registrar la aceptación de todos modos? Podrás adjuntarla después.')) return;
                enviar({accion: 'aceptar', representante: g('representante'), cargo: g('cargo'), fecha: g('fecha'), participantes: g('participantes')}, {archivo: arch});
                break;
            case 'recordar-pago':
                if (window.confirm('Se enviará un recordatorio del saldo del aporte a la empresa. ¿Continuar?')) enviar({accion: 'recordar_pago'});
                break;
            case 'solicitar-recibo':
                if (window.confirm('Se enviará la solicitud de recibo por donación al área financiera (' + S.correoFinanciero + '). ¿Continuar?')) enviar({accion: 'solicitar_recibo', tipo: $b.attr('data-tipo')});
                break;
            case 'recordar-recibo':
                enviar({accion: 'recordar_recibo', id: $b.attr('data-id')});
                break;
            case 'cargar-recibo':
                var $r = $('[data-ca-recibo-form="' + $b.attr('data-id') + '"]');
                enviar({accion: 'cargar_recibo', id: $b.attr('data-id'), numero: $r.find('[name="numero"]').val(), fecha: $r.find('[name="fecha"]').val()}, {archivo: $r.find('[name="archivo"]')[0].files[0]});
                break;
            case 'enviar-recibo':
                enviar({accion: 'enviar_recibo', id: $b.attr('data-id')});
                break;
            case 'correo-financiero':
                var correo = window.prompt('Correo del área financiera que emite los recibos por donación:', S.correoFinanciero);
                if (correo) enviar({accion: 'correo_financiero', correo: correo.trim()});
                break;
            case 'cargo-agregar':
                enviar({accion: 'cargo_agregar', concepto: $('#caCargoC').val(), monto: $('#caCargoM').val()});
                break;
            case 'cobrar-excedente':
                if (window.confirm('Se enviará a la empresa la liquidación con el saldo de ' + q(S.resumen.saldoEx) + ' (plazo de 7 días). ¿Continuar?')) enviar({accion: 'cobrar_excedente'});
                break;
            case 'exonerar':
                var motivo = window.prompt('Motivo para exonerar el excedente de alimentación (' + q(S.excedente.monto) + '):', '');
                if (motivo !== null) enviar({accion: 'exonerar', motivo: motivo});
                break;
            case 'liquidar':
                var pend = S.acuerdo.tareas.filter(function (t) { return !t.ok; }).length;
                if (pend && !window.confirm('Hay ' + pend + ' tarea(s) del acuerdo sin marcar como cumplidas. ¿Liquidar de todos modos?')) return;
                if ((S.resumen.pagosSinRecibo || S.resumen.recibosPendientes) && !window.confirm('Hay pagos cuyo recibo por donación aún no se ha cargado. ¿Liquidar de todos modos?')) return;
                enviar({accion: 'liquidar'});
                break;
            case 'cerrar':
                var faltan = S.empresas.filter(function (x) { return !x.respuesta; }).map(function (x) { return x.nombre; });
                if (faltan.length && !window.confirm(faltan.join(', ') + ' aún no responde la encuesta. ¿Cerrar el expediente sin su evaluación?')) return;
                enviar({accion: 'cerrar'});
                break;
            case 'informe':
                $m.modal('hide');
                window.cengiEvtInforme(eventoId);
                break;
        }
    });

    // Enlace directo: eventos_qr.php?acuerdo=<evento>[&acuerdo_tab=seguimiento]
    var directo = /[?&]acuerdo=(\d+)(?:&acuerdo_tab=(\w+))?/.exec(window.location.search);
    if (directo) window.cengiEvtAcuerdo(directo[1], directo[2]);
}());
