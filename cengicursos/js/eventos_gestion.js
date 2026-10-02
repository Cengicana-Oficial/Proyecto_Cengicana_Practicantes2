/*
 * Eventos y control QR (cengicursos/eventos_qr.php): "Editar evento", "Difusión",
 * "Encuestas y evaluación", informe por evento y pestaña "Evaluación de eventos".
 * Port de las funciones del prototipo SIGEC v29 (ppEvAbrirEvento, ppDifRender,
 * ppEvAbrirEncuestas, ppEvAnalisis, ppConclusiones, ppEvInformeHTML, renderEvaluacion).
 * Los datos vienen de eventos_gestion.php?accion=datos y las acciones se guardan con
 * POST AJAX a eventos_gestion.php.
 */
(function ($) {
    'use strict';

    var CFG = window.CENGI_EVT || {};
    var PUEDE = !!CFG.puedeGestionar;
    var HOY = CFG.hoy || new Date().toISOString().slice(0, 10);
    var ITEMS = CFG.colabItems || [];
    var GRUPOS = CFG.colabGrupos || [];
    var CANALES = CFG.canales || {};
    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    var LOGO = 'css/images/cengi.png';
    var DATOS = null;
    var ICON = function (paths) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' + paths + '</svg>'; };

    /* ---------- utilidades ---------- */
    function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    function isIso(v) { return /^\d{4}-\d{2}-\d{2}$/.test(String(v || '')); }
    function aFecha(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || '')); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; }
    function fmtFecha(iso) { var d = aFecha(iso); return d ? String(d.getDate()).padStart(2, '0') + ' ' + MESES[d.getMonth()] + ' ' + d.getFullYear() : '—'; }
    function fmtCorto(iso) { var d = aFecha(iso); return d ? String(d.getDate()).padStart(2, '0') + ' ' + MESES[d.getMonth()] : '—'; }
    function fmtTs(ts) { ts = String(ts || '').replace('T', ' '); return fmtFecha(ts.slice(0, 10)) + ' · ' + ts.slice(11, 16); }
    function fmtLargo(iso) { var d = aFecha(iso); return d ? d.toLocaleDateString('es-GT', {day: 'numeric', month: 'long', year: 'numeric'}) : 'Fecha por confirmar'; }
    function dias(a, b) { return Math.round((aFecha(a) - aFecha(b)) / 86400000); }
    function urlEncuesta(token) { return window.location.origin + '/cengicursos/encuesta_evento.php?t=' + encodeURIComponent(token); }
    function canalN(c) { return CANALES[c] || c || '—'; }
    function ev(id) { id = Number(id); return (DATOS || []).filter(function (e) { return e.id === id; })[0] || null; }
    function minus(t) { return t.charAt(0).toLowerCase() + t.slice(1); }
    function badge(cls, txt) { return '<span class="cengi-ev-badge ' + cls + '"><i></i>' + esc(txt) + '</span>'; }

    function qrSvg(texto, tam) {
        if (typeof qrcode !== 'function') return '<span class="mono">' + esc(texto) + '</span>';
        var qr = qrcode(0, 'M');
        qr.addData(String(texto), 'Byte');
        qr.make();
        return '<div style="width:' + tam + 'px;height:' + tam + 'px;">' + qr.createSvgTag({cellSize: 4, margin: 2, scalable: true}) + '</div>';
    }

    function toast(texto, error) {
        var $t = $('<div class="cengi-ev-toast' + (error ? ' is-error' : '') + '" role="status"></div>').text(texto).appendTo('body');
        setTimeout(function () { $t.addClass('is-visible'); }, 10);
        setTimeout(function () { $t.removeClass('is-visible'); setTimeout(function () { $t.remove(); }, 300); }, 3200);
    }

    function copiar(texto) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(function () { toast('Enlace copiado'); }, function () { window.prompt('Copia el enlace:', texto); });
        } else {
            window.prompt('Copia el enlace:', texto);
        }
    }

    function cargar(cb) {
        $.getJSON('eventos_gestion.php', {accion: 'datos'})
            .done(function (r) {
                if (!r || !r.ok) { toast((r && r.mensaje) || 'No fue posible cargar los datos.', true); return; }
                DATOS = r.eventos;
                if (cb) cb();
            })
            .fail(function () { toast('No fue posible cargar los datos. Verifica que la migración de eventos esté aplicada.', true); });
    }

    function enviar(datos, cb, $boton) {
        var esArchivo = datos instanceof FormData;
        if ($boton) $boton.prop('disabled', true);
        $.ajax({
            url: 'eventos_gestion.php', type: 'POST', data: datos, dataType: 'json',
            processData: !esArchivo, contentType: esArchivo ? false : 'application/x-www-form-urlencoded; charset=UTF-8'
        }).done(function (r) {
            if (r && r.mensaje) toast(r.mensaje, !r.ok);
            cargar(cb);
        }).fail(function (xhr) {
            var r = xhr.responseJSON;
            toast((r && r.mensaje) || 'No fue posible completar la acción.', true);
            if ($boton) $boton.prop('disabled', false);
        });
    }

    function imprimir(html, pagina) {
        var w = window.open('', '_blank');
        if (!w) { toast('Permite las ventanas emergentes para imprimir.', true); return; }
        var css = Array.prototype.map.call(document.querySelectorAll('style'), function (s) { return s.textContent; }).join('\n');
        w.document.write('<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Imprimir</title><style>' + css +
            '@page{size:' + pagina + ';margin:0;} body{margin:0;background:#fff;}</style></head><body class="cengi-eventos-qr-page">' + html + '</body></html>');
        w.document.close();
        w.focus();
        setTimeout(function () { w.print(); }, 400);
    }

    /* ---------- análisis (ppEvAnalisis / ppClasificar / ppConclusiones) ---------- */
    var RE_ELOGIO = /excelente|muy buen|muy útil|muy util|me gust[oó]|felicit|gracias|repetir|interesante|valioso/;
    var RE_MEJORA = /falt|mejor|más |mas |lento|tarde|retras|poco|calor|problema|no se|no hab|no fue|dif[ií]cil|incómod|incomod|agregar|publicar|compartir|señaliz|se traslap/;
    var TEMAS = [
        ['Organización y horarios', /organiz|agenda|horari|puntual|retras|tiempo|registro|fila|inicio|duraci|señaliz|parqueo/],
        ['Contenido y ponentes', /ponen|tema|conten|charla|expositor|demostr|práctic|practic|técnic|tecnic|informaci[oó]n t/],
        ['Auditorio e instalaciones', /auditor|sonido|audio|micr|aire|clima|calor|asiento|parqueo|instalac|pantalla|proyector|espacio/],
        ['Difusión y comunicación', /difus|invit|comunic|redes|correo|aviso|anticipaci|convocatoria/],
        ['Alimentación', /comida|almuerzo|refrigerio|caf[eé]|alimenta|agua/],
        ['Networking y seguimiento', /contacto|networking|empresas|conocer|seguimiento|listado|stand|comercial/]
    ];
    function clasificar(t) {
        var n = ' ' + String(t).toLowerCase() + ' ';
        if (RE_ELOGIO.test(n) && !RE_MEJORA.test(n)) return 'Comentarios positivos';
        for (var k = 0; k < TEMAS.length; k++) { if (TEMAS[k][1].test(n)) return TEMAS[k][0]; }
        return 'Otros';
    }
    function temas(textos, conPositivos) {
        var r = {};
        textos.filter(Boolean).forEach(function (t) { var k = clasificar(t); if (k === 'Comentarios positivos' && !conPositivos) return; (r[k] = r[k] || []).push(t); });
        return Object.keys(r).map(function (k) { return [k, r[k]]; }).sort(function (a, b) { return ((a[0] === 'Otros') - (b[0] === 'Otros')) || (b[1].length - a[1].length); });
    }
    function prom(a) { var v = a.filter(function (x) { return x != null && !isNaN(x); }); return v.length ? v.reduce(function (t, x) { return t + x; }, 0) / v.length : null; }

    function analisis(e) {
        var P = e.percep, est = P.map(function (x) { return x.estrellas; });
        var dist = [1, 2, 3, 4, 5].map(function (n) { return est.filter(function (x) { return x === n; }).length; });
        var personales = P.filter(function (x) { return x.pid; }).length;
        var C = e.colab;
        var items = ITEMS.map(function (it) { return $.extend({}, it, {prom: prom(C.map(function (c) { return c.r[it.k]; }))}); });
        var grupos = GRUPOS.map(function (g) { return {g: g, prom: prom(items.filter(function (i) { return i.g === g; }).map(function (i) { return i.prom; }))}; });
        var cuenta = function (campo, val) { return C.filter(function (c) { return c[campo] === val; }).length; };
        return {
            asist: e.asistentes, conf: e.confirmados, reg: e.registrados, pctAsist: e.confirmados ? e.asistentes / e.confirmados * 100 : null,
            percep: {
                positivos: P.map(function (x) { return x.mejora; }).filter(function (t) { return t && clasificar(t) === 'Comentarios positivos'; }),
                n: P.length, prom: prom(est), dist: dist,
                satisf: P.length ? (dist[3] + dist[4]) / P.length * 100 : null, insat: P.length ? (dist[0] + dist[1]) / P.length * 100 : null,
                personales: personales, qr: P.length - personales, tasa: e.asistentes ? Math.min(100, personales / e.asistentes * 100) : null,
                temas: temas(P.map(function (x) { return x.mejora; }))
            },
            colab: {
                activo: !!e.en_colaboracion, empresas: e.aliados.length, resp: C.length, items: items, grupos: grupos, indice: prom(items.map(function (i) { return i.prom; })),
                fortalecio: {si: cuenta('fortalecio', 'si'), parcial: cuenta('fortalecio', 'parcialmente'), no: cuenta('fortalecio', 'no')},
                participaria: {si: cuenta('participaria', 'si'), no: cuenta('participaria', 'no')},
                temasMejora: temas(C.map(function (c) { return c.mejorar; })),
                apoyos: C.filter(function (c) { return c.apoyo; }).map(function (c) { return {empresa: c.empresa, t: c.apoyo}; })
            }
        };
    }

    function difInfo(e) {
        var b = e.difusion.slice().sort(function (x, y) { return x.fecha.localeCompare(y.fecha); });
        var primera = b[0], porCanal = {};
        b.forEach(function (x) { porCanal[x.canal] = (porCanal[x.canal] || 0) + 1; });
        return {b: b, primera: primera, anticip: primera && e.fecha ? dias(e.fecha, primera.fecha) : null, canales: Object.keys(porCanal).length};
    }
    function difTiene(e) { return e.difusion.length > 0 || !!e.dif_flyer || !!e.dif_landing; }

    function conclusiones(e, a) {
        var out = [], rec = [];
        if (a.percep.n) {
            out.push('Los participantes calificaron el evento con <b>' + a.percep.prom.toFixed(1) + ' de 5</b>; el ' + Math.round(a.percep.satisf) + '% lo calificó con 4 o 5 estrellas' + (a.percep.insat ? ' y el ' + Math.round(a.percep.insat) + '% con 1 o 2' : '') + '.');
            var t = a.percep.temas.filter(function (x) { return x[0] !== 'Otros'; })[0];
            if (t) { out.push('El tema más mencionado en las oportunidades de mejora fue <b>' + esc(t[0].toLowerCase()) + '</b> (' + t[1].length + ' comentario' + (t[1].length > 1 ? 's' : '') + ').'); rec.push('Atender ' + t[0].toLowerCase() + ': ' + t[1][0]); }
            if (a.percep.tasa != null && a.percep.personales) out.push('Respondió el ' + Math.round(a.percep.tasa) + '% de los asistentes por enlace personal' + (a.percep.qr ? ', más ' + a.percep.qr + ' respuesta(s) anónimas por QR o enlace general' : '') + '.');
        } else {
            out.push('Aún no hay respuestas de participantes.');
        }
        if (a.colab.activo) {
            if (a.colab.resp) {
                var ok = a.colab.items.filter(function (i) { return i.prom != null; }).sort(function (x, y) { return y.prom - x.prom; });
                out.push('La colaboración obtuvo un índice de <b>' + a.colab.indice.toFixed(1) + ' de 5</b> (' + a.colab.resp + ' de ' + a.colab.empresas + ' empresa(s) respondieron).');
                if (ok.length) {
                    out.push('Mejor evaluado: <b>' + esc(minus(ok[0].t)) + '</b> (' + ok[0].prom.toFixed(1) + '). Por fortalecer: <b>' + esc(minus(ok[ok.length - 1].t)) + '</b> (' + ok[ok.length - 1].prom.toFixed(1) + ').');
                    ok.filter(function (i) { return i.prom < 4; }).forEach(function (i) { rec.push('Mejorar ' + minus(i.t) + ' (promedio ' + i.prom.toFixed(1) + ').'); });
                }
                out.push(a.colab.participaria.si + ' de ' + a.colab.resp + ' empresa(s) participaría nuevamente bajo esta modalidad.');
                a.colab.apoyos.forEach(function (x) { rec.push('Considerar para próximas alianzas (' + x.empresa + '): ' + x.t); });
            } else {
                out.push('Las empresas aliadas aún no han respondido la encuesta de colaboración.');
            }
        }
        if (difTiene(e)) {
            var d = difInfo(e);
            if (d.anticip != null && d.anticip < 15) rec.push('La difusión inició ' + d.anticip + ' días antes del evento: comenzar con al menos 15 días de anticipación.');
            if (d.b.length && d.canales < 2) rec.push('Divulgar por más de un canal (correo, comunidad de WhatsApp y redes sociales).');
        }
        if (a.pctAsist != null && a.conf && e.fecha && e.fecha < HOY && a.pctAsist < 70) rec.push('La asistencia fue del ' + Math.round(a.pctAsist) + '% de los confirmados: reforzar recordatorios 48 y 24 horas antes.');
        return {out: out, rec: rec};
    }

    /* ---------- informe (ppEvInformeHTML) ---------- */
    function barra(v, max, col) { return '<div style="background:#EDF0EA;border-radius:4px;height:10px;width:100%;"><div style="width:' + (max ? v / max * 100 : 0) + '%;height:10px;border-radius:4px;background:' + (col || '#2F6B12') + ';"></div></div>'; }
    function estr(v) { if (v == null) return '—'; var n = Math.round(v); return '<span style="color:#E0A800;letter-spacing:1px;">' + '★'.repeat(n) + '</span><span style="color:#D5D9D1;">' + '★'.repeat(5 - n) + '</span> <b>' + v.toFixed(1) + '</b>'; }

    function difInfHTML(e, n) {
        if (!difTiene(e)) return '';
        var inf = difInfo(e);
        var material = [e.dif_flyer ? 'flyer' : null, e.dif_landing ? 'landing page' : null].filter(Boolean).join(' y ') || '—';
        return '<div class="inf-sec">' + n + '. Difusión</div><div class="inf-2"><div><div class="inf-sub">Bitácora de divulgación</div>' +
            (inf.b.length ? inf.b.map(function (x) { return '<div class="inf-item"><span>' + fmtCorto(x.fecha) + ' · <b>' + esc(canalN(x.canal)) + '</b>' + (x.detalle ? ' — ' + esc(x.detalle) : '') + '</span></div>'; }).join('') : '<div class="inf-muted">Sin registros.</div>') +
            '</div><div><div class="inf-sub">Resumen</div><div class="inf-item"><span>Divulgaciones / canales</span><b>' + inf.b.length + ' / ' + inf.canales + '</b></div>' +
            '<div class="inf-item"><span>Inicio de la difusión</span><b>' + (inf.primera ? fmtFecha(inf.primera.fecha) + (inf.anticip != null ? ' (' + inf.anticip + ' días antes)' : '') : '—') + '</b></div>' +
            '<div class="inf-item"><span>Material</span><b>' + material + '</b></div>' +
            (e.en_colaboracion ? '<div class="inf-item"><span>Aprobación de la empresa</span><b>' + (e.dif_aprob_fecha ? fmtFecha(e.dif_aprob_fecha) : 'No registrada') + '</b></div>' : '') + '</div></div>';
    }

    function informeHTML(e) {
        var a = analisis(e), c = conclusiones(e, a), col = e.color || '#2F6B12', maxD = Math.max.apply(null, [1].concat(a.percep.dist));
        var kpi = function (v, l) { return '<div class="inf-kpi"><div class="v">' + v + '</div><div class="l">' + l + '</div></div>'; };
        var listaTemas = function (lista, max) {
            return lista.length ? lista.slice(0, max || 6).map(function (x) { return '<div class="inf-tema"><div class="inf-tema-h"><b>' + esc(x[0]) + '</b><span>' + x[1].length + '</span></div>' + x[1].slice(0, 3).map(function (t) { return '<div class="inf-cita">“' + esc(t) + '”</div>'; }).join('') + '</div>'; }).join('') : '<div class="inf-muted">Sin comentarios.</div>';
        };
        var empresas = e.en_colaboracion && e.aliados.length ? ' · En colaboración con ' + e.aliados.map(function (x) { return esc(x.nombre); }).join(', ') : '';
        var nDif = a.colab.activo ? 4 : 3;
        var html = '<div class="inf-doc"><div class="inf-head" style="border-color:' + esc(col) + ';"><div><div class="inf-k">INFORME DE EVALUACIÓN DEL EVENTO</div><div class="inf-t">' + esc(e.nombre) + '</div>' +
            '<div class="inf-s">' + esc(e.tipo) + ' · ' + fmtLargo(e.fecha) + (e.lugar ? ' · ' + esc(e.lugar) : '') + empresas + '</div></div><img src="' + LOGO + '" alt="" style="height:44px;max-width:170px;object-fit:contain;"></div>' +
            '<div class="inf-sec">1. Resumen ejecutivo</div><ul class="inf-ul">' + c.out.map(function (x) { return '<li>' + x + '</li>'; }).join('') + '</ul>' +
            '<div class="inf-kpis">' + kpi(a.reg, 'Registrados') + kpi(a.asist, 'Asistentes') + kpi(a.pctAsist != null ? Math.round(a.pctAsist) + '%' : '—', 'Asistencia de confirmados') +
            kpi(a.percep.prom != null ? a.percep.prom.toFixed(1) + ' ★' : '—', 'Percepción de participantes') +
            (a.colab.activo ? kpi(a.colab.indice != null ? a.colab.indice.toFixed(1) + ' ★' : '—', 'Índice de colaboración') : kpi(a.percep.n, 'Respuestas')) + '</div>' +
            '<div class="inf-sec">2. Percepción de los participantes</div><div class="inf-2"><div><div class="inf-sub">Distribución de calificaciones (' + a.percep.n + ' respuestas)</div>' +
            [5, 4, 3, 2, 1].map(function (n) { return '<div class="inf-dist"><span>' + n + ' ★</span>' + barra(a.percep.dist[n - 1], maxD, n >= 4 ? col : n === 3 ? '#E0A800' : '#C0392B') + '<b>' + a.percep.dist[n - 1] + '</b></div>'; }).join('') +
            '<div class="inf-muted" style="margin-top:6px;">' + a.percep.personales + ' por enlace personal' + (a.percep.tasa != null ? ' (' + Math.round(a.percep.tasa) + '% de asistentes)' : '') + ' · ' + a.percep.qr + ' por QR o enlace general</div>' +
            '<div class="inf-muted">Satisfechos (4-5 ★): <b>' + (a.percep.satisf != null ? Math.round(a.percep.satisf) + '%' : '—') + '</b> · Insatisfechos (1-2 ★): <b>' + (a.percep.insat != null ? Math.round(a.percep.insat) + '%' : '—') + '</b></div></div>' +
            '<div><div class="inf-sub">Oportunidades de mejora por tema</div>' + listaTemas(a.percep.temas) +
            (a.percep.positivos.length ? '<div class="inf-sub" style="margin-top:12px;">Lo que más valoraron</div>' + a.percep.positivos.slice(0, 4).map(function (t) { return '<div class="inf-cita">“' + esc(t) + '”</div>'; }).join('') : '') + '</div></div>';
        if (a.colab.activo) {
            html += '<div class="inf-sec">3. Evaluación de la colaboración</div>';
            if (a.colab.resp) {
                html += '<div class="inf-2"><div><div class="inf-sub">Calificación por aspecto (' + a.colab.resp + ' de ' + a.colab.empresas + ' empresa(s))</div>' +
                    GRUPOS.map(function (g) {
                        return '<div class="inf-grp"><div class="inf-grp-h"><b>' + esc(g) + '</b><span>' + estr(a.colab.grupos.filter(function (x) { return x.g === g; })[0].prom) + '</span></div>' +
                            a.colab.items.filter(function (i) { return i.g === g; }).map(function (i) {
                                return '<div class="inf-item"><span>' + esc(i.t) + '</span>' + (i.prom == null ? '<em class="inf-muted">No aplica</em>' : '<div style="flex:0 0 120px;">' + barra(i.prom, 5, i.prom >= 4.5 ? col : i.prom >= 3.5 ? '#E0A800' : '#C0392B') + '</div><b>' + i.prom.toFixed(1) + '</b>') + '</div>';
                            }).join('') + '</div>';
                    }).join('') + '</div>' +
                    '<div><div class="inf-sub">Resultado de la colaboración</div>' +
                    '<div class="inf-item"><span>¿Fortaleció el evento?</span><b>Sí ' + a.colab.fortalecio.si + ' · Parcialmente ' + a.colab.fortalecio.parcial + ' · No ' + a.colab.fortalecio.no + '</b></div>' +
                    '<div class="inf-item"><span>¿Participaría nuevamente?</span><b>Sí ' + a.colab.participaria.si + ' · No ' + a.colab.participaria.no + '</b></div>' +
                    '<div class="inf-sub" style="margin-top:12px;">Lo que debería mejorar el Centro</div>' + listaTemas(a.colab.temasMejora, 4) +
                    '<div class="inf-sub" style="margin-top:12px;">Apoyo o valor que esperan en futuras colaboraciones</div>' +
                    (a.colab.apoyos.length ? a.colab.apoyos.map(function (x) { return '<div class="inf-cita"><b>' + esc(x.empresa) + ':</b> ' + esc(x.t) + '</div>'; }).join('') : '<div class="inf-muted">Sin respuestas.</div>') + '</div></div>';
            } else {
                html += '<div class="inf-muted">Las empresas aliadas aún no han respondido.</div>';
            }
        }
        html += difInfHTML(e, nDif) +
            '<div class="inf-sec">' + (nDif + (difTiene(e) ? 1 : 0)) + '. Recomendaciones</div>' +
            (c.rec.length ? '<ol class="inf-ul">' + c.rec.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ol>' : '<div class="inf-muted">Sin recomendaciones: los resultados están dentro de lo esperado.</div>') +
            '<div class="inf-pie">Generado por SIGEC · CENGICAÑA · ' + fmtTs(new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString()) + '</div></div>';
        return html;
    }

    var informeEv = null;
    function abrirInforme(id) {
        var e = ev(id);
        if (!e) return;
        informeEv = e.id;
        $('#eiTituloInf').text('Informe · ' + e.nombre);
        $('#eiBodyInf').html(informeHTML(e));
        $('#eiDescargar').attr('href', 'eventos_gestion.php?accion=exportar_respuestas&evento_id=' + e.id);
        $('#modalEvInforme').modal('show');
    }
    $('#eiImprimir').on('click', function () { var e = ev(informeEv); if (e) imprimir('<div style="padding:12mm 14mm;">' + informeHTML(e) + '</div>', 'letter portrait'); });

    /* ---------- encuestas del evento (ppEvAbrirEncuestas) ---------- */
    var encuestasEv = null;
    function renderEncuestas() {
        var e = ev(encuestasEv);
        if (!e) return;
        var a = analisis(e);
        $('#eeTitulo').text('Encuestas · ' + e.nombre);
        $('#eeSub').text(fmtFecha(e.fecha) + (e.lugar ? ' · ' + e.lugar : '') + (e.en_colaboracion ? ' · evento en colaboración' : ''));
        var colab;
        if (e.en_colaboracion) {
            colab = e.aliados.length ? e.aliados.map(function (c) {
                var r = e.colab.filter(function (x) { return x.aliado_id === c.id; })[0], url = urlEncuesta(c.token);
                var estado = r ? badge('b-activo', 'Respondida') + '<div class="cengi-ev-sub">' + fmtTs(r.ts) + '</div>'
                    : (c.enviado_en ? badge('b-espera', 'Enviada ' + fmtCorto(c.enviado_en.slice(0, 10))) : badge('b-planificacion', 'Sin enviar')) +
                      (PUEDE ? '<button type="button" class="btn btn-primary btn-sm" data-enviar-colab="' + c.id + '">' + (c.enviado_en ? 'Reenviar' : 'Enviar por correo') + '</button>' : '');
                return '<div class="ee-fila"><div style="min-width:0;"><b>' + esc(c.nombre) + '</b><div class="cengi-ev-sub">' + esc(c.contacto || '') + ' · ' + esc(c.correo || 'sin correo') + '</div>' +
                    '<div class="ee-link"><input readonly class="mono" value="' + esc(url) + '"><button type="button" class="btn btn-default btn-sm" data-copiar="' + esc(url) + '">Copiar</button></div></div>' +
                    '<div class="ee-acc">' + estado + '<a class="btn btn-link btn-sm" href="' + esc(url) + '" target="_blank" rel="noopener">Abrir formulario</a></div></div>';
            }).join('') : '<div class="cengi-ev-sub">Agrega las empresas aliadas en “Editar evento”.</div>';
        } else {
            colab = '<div class="cengi-ev-sub" style="padding:6px 0;">Este evento no está marcado como “en colaboración”.' + (PUEDE ? ' <button type="button" class="cengi-ev-linkbtn" data-editar-evento="' + e.id + '">Marcarlo y agregar empresas</button>' : '') + '</div>';
        }
        var urlGeneral = urlEncuesta(e.token_percepcion);
        var sinResp = e.asistentes_sin_respuesta;
        $('#eeBody').html(
            '<div class="ee-card"><div class="ee-h"><div><b>① Encuesta de colaboración</b><div class="cengi-ev-sub">Para la empresa aliada: coordinación, aporte del Centro y valor percibido (7 calificaciones, 2 preguntas cerradas y 2 abiertas)</div></div>' +
            (e.en_colaboracion ? '<span class="cengi-ev-chip-ok">' + a.colab.resp + ' de ' + a.colab.empresas + ' respondida(s)</span>' : '') + '</div>' + colab + '</div>' +
            '<div class="ee-card"><div class="ee-h"><div><b>② Percepción de participantes</b><div class="cengi-ev-sub">Calificación general de 1 a 5 estrellas y oportunidades de mejora</div></div><span class="cengi-ev-chip-ok">' + a.percep.n + ' respuesta(s)</span></div>' +
            '<div class="ee-fila" style="align-items:center;"><div style="min-width:0;flex:1;"><b>Enlace general y QR</b><div class="cengi-ev-sub">Para proyectarlo al cierre del evento o compartirlo por WhatsApp (respuestas anónimas)</div>' +
            '<div class="ee-link"><input readonly class="mono" value="' + esc(urlGeneral) + '"><button type="button" class="btn btn-default btn-sm" data-copiar="' + esc(urlGeneral) + '">Copiar</button></div></div>' +
            '<div style="text-align:center;"><div style="padding:4px;border:1px solid #E4E7E1;border-radius:6px;background:#fff;display:inline-block;">' + qrSvg(urlGeneral, 96) + '</div><div><button type="button" class="cengi-ev-linkbtn" id="eeImprimirQr">Imprimir QR</button></div></div></div>' +
            '<div class="ee-fila"><div><b>Enlace personal a cada asistente</b><div class="cengi-ev-sub">' + e.asistentes + ' asistente(s) con ingreso · ' + (e.asistentes - sinResp) + ' respondieron' + (e.percep_enviada_en ? ' · enviado ' + fmtTs(e.percep_enviada_en) : '') + '</div></div>' +
            '<div class="ee-acc">' + (PUEDE ? '<button type="button" class="btn btn-primary btn-sm" id="eeEnviarPercep"' + (sinResp ? '' : ' disabled') + '>' + (e.percep_enviada_en ? 'Recordar a quienes no respondieron' : 'Enviar a asistentes') + ' (' + sinResp + ')</button>' : '') +
            '<a class="btn btn-link btn-sm" href="' + esc(urlGeneral) + '" target="_blank" rel="noopener">Abrir formulario</a></div></div></div>'
        );
        $('#eeFoot').html('<a class="btn btn-default" href="eventos_gestion.php?accion=exportar_respuestas&evento_id=' + e.id + '">Descargar respuestas</a><button type="button" class="btn btn-primary" id="eeVerInforme">Ver informe del evento</button>');
    }
    function abrirEncuestas(id) {
        encuestasEv = Number(id);
        $('#eeTitulo').text('Encuestas');
        $('#eeSub').text('');
        $('#eeBody').html('<div class="cengi-ev-sub">Cargando…</div>');
        $('#eeFoot').empty();
        $('#modalEvEncuestas').modal('show');
        cargar(renderEncuestas);
    }
    $('#modalEvEncuestas')
        .on('click', '[data-copiar]', function () { copiar($(this).attr('data-copiar')); })
        .on('click', '[data-enviar-colab]', function () {
            enviar({accion: 'enviar_colab', evento_id: encuestasEv, aliado_id: $(this).attr('data-enviar-colab')}, renderEncuestas, $(this));
        })
        .on('click', '#eeEnviarPercep', function () {
            enviar({accion: 'enviar_percep', evento_id: encuestasEv}, function () { renderEncuestas(); renderEvaluacion(); }, $(this));
        })
        .on('click', '#eeImprimirQr', function () {
            var e = ev(encuestasEv), url = urlEncuesta(e.token_percepcion);
            imprimir('<div style="width:210mm;height:297mm;display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:Arial,sans-serif;text-align:center;gap:10mm;">' +
                '<div style="font-size:14px;letter-spacing:.2em;color:#5B6459;">CENGICAÑA</div><div style="font-size:40px;font-weight:800;color:' + esc(e.color || '#2F6B12') + ';">¿Cómo fue tu experiencia?</div>' +
                '<div style="font-size:22px;">' + esc(e.nombre) + '</div>' + qrSvg(url, 380) + '<div style="font-size:18px;color:#3A4236;">Escanea el código y califica el evento · 1 minuto</div></div>', 'A4 portrait');
        })
        .on('click', '#eeVerInforme', function () { $('#modalEvEncuestas').modal('hide'); abrirInforme(encuestasEv); })
        .on('click', '[data-editar-evento]', function () { var id = $(this).attr('data-editar-evento'); $('#modalEvEncuestas').modal('hide'); abrirEvento(id); });

    /* ---------- difusión (ppDifRender) ---------- */
    var difEv = null;
    function renderDifusion() {
        var e = ev(difEv);
        if (!e) return;
        var inf = difInfo(e), colab = e.en_colaboracion && e.aliados.length, aliado = colab ? e.aliados[0] : null;
        var esImagen = e.dif_flyer && /\.(png|jpe?g)$/i.test(e.dif_flyer);
        $('#dfTitulo').text('Difusión · ' + e.nombre);
        $('#dfSub').text(fmtFecha(e.fecha) + (e.lugar ? ' · ' + e.lugar : ''));
        var anticipEstilo = inf.anticip != null && inf.anticip < 15 ? 'color:#B34E00;' : '';
        var opciones = Object.keys(CANALES).map(function (k) { return '<option value="' + esc(k) + '">' + esc(CANALES[k]) + '</option>'; }).join('');
        var aprob = '';
        if (colab) {
            aprob = '<div class="ca-sub">Aprobación de ' + esc(aliado.nombre) + '</div>' + (e.dif_aprob_fecha
                ? '<div class="ca-firma"><span>✓ <b>Aprobado</b> por ' + esc(e.dif_aprob_por) + ' · ' + fmtFecha(e.dif_aprob_fecha) + (e.dif_aprob_nota ? ' · ' + esc(e.dif_aprob_nota) : '') + '</span>' + (PUEDE ? '<button type="button" class="btn btn-link btn-sm" id="dfQuitarAprob">Cambiar</button>' : '') + '</div>'
                : (PUEDE ? '<div class="cengi-form-grid"><div class="form-group"><label class="control-label" for="dfAprPor">Aprobado por</label><input class="form-control" id="dfAprPor" value="' + esc((aliado.contacto || '') + ' (' + aliado.nombre + ')') + '"></div>' +
                    '<div class="form-group"><label class="control-label" for="dfAprFecha">Fecha</label><input type="date" class="form-control" id="dfAprFecha" value="' + HOY + '"></div>' +
                    '<div class="form-group cengi-form-full"><label class="control-label" for="dfAprNota">Nota</label><input class="form-control" id="dfAprNota" placeholder="Ej. Aprobó por correo con cambios en el logo"></div></div>' +
                    '<div class="ca-acc"><button type="button" class="btn btn-default btn-sm" id="dfAprobar">Registrar aprobación</button></div>' : '<div class="cengi-ev-sub">Sin aprobación registrada.</div>'));
        }
        var enlace = function (id, valor, ph) {
            return '<div style="display:flex;gap:6px;"><input class="form-control" id="' + id + '" value="' + esc(valor || '') + '" placeholder="' + ph + '"' + (PUEDE ? '' : ' readonly') + '>' +
                (valor ? '<a class="btn btn-link btn-sm" href="' + esc(valor) + '" target="_blank" rel="noopener">Abrir</a>' : '') + '</div>';
        };
        $('#dfBody').html(
            '<div class="df-kpis"><div><span>Divulgaciones registradas</span><b>' + inf.b.length + '</b></div><div><span>Canales utilizados</span><b>' + inf.canales + '</b></div>' +
            '<div><span>Inicio de la difusión</span><b>' + (inf.primera ? fmtCorto(inf.primera.fecha) : '—') + '</b></div>' +
            '<div><span>Anticipación al evento</span><b style="' + anticipEstilo + '">' + (inf.anticip != null ? inf.anticip + ' días' : '—') + '</b></div></div>' +
            '<div class="df-2"><div class="ee-card"><div class="ee-h"><div><b>Material promocional</b><div class="cengi-ev-sub">Solo la versión final; los editables se quedan en Canva</div></div></div>' +
            '<div class="df-flyer">' + (esImagen ? '<img src="' + esc(e.dif_flyer) + '" alt="Flyer del evento">' : '<div class="cengi-ev-sub" style="padding:18px;text-align:center;">' + (e.dif_flyer ? '<a href="' + esc(e.dif_flyer) + '" target="_blank" rel="noopener">' + esc(e.dif_flyer_nombre || 'Flyer') + '</a><br>(vista previa no disponible)' : 'Sin flyer') + '</div>') + '</div>' +
            (PUEDE ? '<div class="pp-doc" id="dfFlyerBox" style="margin-top:8px;"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="var(--tinta-suave,#4B5A45)" stroke-width="1.7"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>' +
                '<div><div class="nm">' + (e.dif_flyer ? 'Reemplazar el flyer (PNG, JPG o PDF)' : 'Arrastra aquí el flyer final (PNG, JPG o PDF)') + '</div><div class="meta">PDF, JPG o PNG · máximo 5 MB</div></div>' +
                '<div class="acts"><label class="btn btn-default btn-sm" style="cursor:pointer;margin:0;">' + (e.dif_flyer ? 'Reemplazar' : 'Seleccionar archivo') + '<input type="file" id="dfFlyerFile" accept=".pdf,.jpg,.jpeg,.png" style="display:none;"></label></div></div>' : '') +
            '<div class="cengi-form-grid" style="margin-top:10px;"><div class="form-group cengi-form-full"><label class="control-label" for="dfLanding">Landing page</label>' + enlace('dfLanding', e.dif_landing, 'https://...') + '</div>' +
            '<div class="form-group cengi-form-full"><label class="control-label" for="dfCanva">Diseño en Canva <span class="cengi-ev-opt">(enlace)</span></label>' + enlace('dfCanva', e.dif_canva, 'https://www.canva.com/design/...') + '</div></div>' +
            aprob + '</div>' +
            '<div class="ee-card"><div class="ee-h"><div><b>Bitácora de divulgación</b><div class="cengi-ev-sub">Dónde y cuándo se compartió el evento</div></div></div>' +
            (inf.b.length ? '<div class="df-bit">' + inf.b.map(function (x) {
                return '<div class="df-bit-f"><div class="df-bit-d">' + fmtCorto(x.fecha) + '</div><div style="flex:1;min-width:0;"><b>' + esc(canalN(x.canal)) + '</b>' + (x.detalle ? '<div class="cengi-ev-sub">' + esc(x.detalle) + '</div>' : '') +
                    (x.por ? '<div class="cengi-ev-sub">Registró ' + esc(x.por) + '</div>' : '') + '</div>' + (PUEDE ? '<button type="button" class="close" aria-label="Quitar" data-quitar-bit="' + x.id + '">&times;</button>' : '') + '</div>';
            }).join('') + '</div>' : '<div class="cengi-ev-sub">Aún no se ha registrado ninguna divulgación.</div>') +
            (PUEDE ? '<div class="cengi-form-grid" style="margin-top:12px;"><div class="form-group"><label class="control-label" for="dfCanal">Canal</label><select class="form-control" id="dfCanal">' + opciones + '</select></div>' +
                '<div class="form-group"><label class="control-label" for="dfFecha">Fecha</label><input type="date" class="form-control" id="dfFecha" value="' + HOY + '"></div>' +
                '<div class="form-group cengi-form-full"><label class="control-label" for="dfDetalle">Detalle <span class="cengi-ev-opt">(opcional)</span></label><input class="form-control" id="dfDetalle" maxlength="500" placeholder="Ej. Publicación en Facebook y LinkedIn; recordatorio en la comunidad de técnicos"></div></div>' +
                '<div class="ca-acc"><button type="button" class="btn btn-primary btn-sm" id="dfAgregarBit">Registrar divulgación</button></div>' : '') +
            (inf.anticip != null && inf.anticip < 15 && e.fecha >= HOY ? '<div class="cengi-ev-form-msg is-warn" style="display:block;margin-top:8px;">La difusión inició ' + inf.anticip + ' días antes del evento. Se recomienda comenzar con al menos 15 días de anticipación.</div>' : '') +
            '</div></div>'
        );
    }
    function abrirDifusion(id) {
        difEv = Number(id);
        $('#dfTitulo').text('Difusión');
        $('#dfSub').text('');
        $('#dfBody').html('<div class="cengi-ev-sub">Cargando…</div>');
        $('#modalDifusion').modal('show');
        cargar(renderDifusion);
    }
    function subirFlyer(f) {
        if (!f) return;
        if (!/\.(pdf|jpe?g|png)$/i.test(f.name)) { toast('Formato no permitido. Usa PDF, JPG o PNG.', true); return; }
        if (f.size > 5 * 1024 * 1024) { toast('El archivo supera los 5 MB.', true); return; }
        var fd = new FormData();
        fd.append('accion', 'dif_flyer');
        fd.append('evento_id', difEv);
        fd.append('flyer', f);
        enviar(fd, renderDifusion);
    }
    $('#modalDifusion')
        .on('change', '#dfFlyerFile', function () { subirFlyer(this.files[0]); this.value = ''; })
        .on('dragenter dragover', '#dfFlyerBox', function (ev2) { ev2.preventDefault(); $(this).addClass('over'); })
        .on('dragleave drop', '#dfFlyerBox', function (ev2) { ev2.preventDefault(); $(this).removeClass('over'); })
        .on('drop', '#dfFlyerBox', function (ev2) { var dt = ev2.originalEvent.dataTransfer; if (dt && dt.files[0]) subirFlyer(dt.files[0]); })
        .on('change', '#dfLanding, #dfCanva', function () {
            enviar({accion: 'dif_enlaces', evento_id: difEv, landing: $('#dfLanding').val().trim(), canva: $('#dfCanva').val().trim()}, renderDifusion);
        })
        .on('click', '#dfAprobar', function () {
            var por = $('#dfAprPor').val().trim(), fecha = $('#dfAprFecha').val();
            if (!por || !isIso(fecha)) { toast('Indica quién aprobó y la fecha.', true); return; }
            enviar({accion: 'dif_aprobar', evento_id: difEv, por: por, fecha: fecha, nota: $('#dfAprNota').val().trim()}, renderDifusion, $(this));
        })
        .on('click', '#dfQuitarAprob', function () { enviar({accion: 'dif_quitar_aprob', evento_id: difEv}, renderDifusion, $(this)); })
        .on('click', '#dfAgregarBit', function () {
            var fecha = $('#dfFecha').val();
            if (!isIso(fecha)) { toast('Indica la fecha de la divulgación.', true); return; }
            enviar({accion: 'dif_bitacora_agregar', evento_id: difEv, canal: $('#dfCanal').val(), fecha: fecha, detalle: $('#dfDetalle').val().trim()}, renderDifusion, $(this));
        })
        .on('click', '[data-quitar-bit]', function () {
            if (!window.confirm('¿Quitar este registro de la bitácora?')) return;
            enviar({accion: 'dif_bitacora_quitar', evento_id: difEv, id: $(this).attr('data-quitar-bit')}, renderDifusion);
        });

    /* ---------- pestaña "Evaluación de eventos" (renderEvaluacion) ---------- */
    function kpi(val, label, delta, color, bg, icono) {
        return '<div class="cengi-kpi"><div class="cengi-kpi-bar" style="background:' + color + ';"></div><div class="cengi-kpi-icon" style="background:' + bg + ';color:' + color + ';">' + ICON(icono) + '</div>' +
            '<div class="cengi-kpi-val">' + val + '</div><div class="cengi-kpi-label">' + label + '</div><div class="cengi-kpi-delta">' + delta + '</div></div>';
    }
    function renderEvaluacion() {
        if (!DATOS || !$('#tablaEvEval').length) return;
        var evs = DATOS.map(function (e) { return {e: e, a: analisis(e)}; }).sort(function (x, y) { return String(y.e.fecha).localeCompare(String(x.e.fecha)); });
        var conP = evs.filter(function (x) { return x.a.percep.n; }), conC = evs.filter(function (x) { return x.a.colab.resp; });
        var promP = prom(conP.map(function (x) { return x.a.percep.prom; })), promC = prom(conC.map(function (x) { return x.a.colab.indice; }));
        var totR = evs.reduce(function (s, x) { return s + x.a.percep.n + x.a.colab.resp; }, 0);
        var pendC = evs.reduce(function (s, x) { return s + (x.a.colab.activo ? x.a.colab.empresas - x.a.colab.resp : 0); }, 0);
        $('#evEvalKpis').html(
            kpi(promP != null ? promP.toFixed(1) + ' ★' : '—', 'Percepción promedio de participantes', conP.length + ' evento(s) evaluados', 'var(--cengi-primary,#73BC25)', '#EAF6DD', '<path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/>') +
            kpi(promC != null ? promC.toFixed(1) + ' ★' : '—', 'Índice de colaboración', conC.length + ' evento(s) con respuesta de aliados', '#3E7A12', '#EAF6DD', '<path d="M16 11a4 4 0 1 0-8 0"/><path d="M3 21v-1a7 7 0 0 1 18 0v1"/>') +
            kpi(totR, 'Respuestas recibidas', 'Participantes y empresas aliadas', 'var(--cengi-amarillo,#FFCC00)', '#FFF6DA', '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>') +
            kpi(pendC, 'Encuestas de colaboración pendientes', 'Empresas aliadas que no han respondido', pendC ? 'var(--cengi-naranja,#FF6B00)' : '#CED2D5', pendC ? '#FFE9D9' : '#EDEFEA', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>')
        );
        $('#tablaEvEval').html(evs.length ? evs.map(function (x) {
            var e = x.e, a = x.a, t = a.percep.temas.filter(function (z) { return z[0] !== 'Otros'; })[0];
            return '<tr><td class="cengi-ev-name">' + esc(e.nombre) + '<div class="cengi-ev-sub">' + fmtFecha(e.fecha) + ' · ' + esc(e.tipo) + '</div></td>' +
                '<td class="cengi-ev-num ev-td-dato" data-label="Asistentes">' + a.asist + '<span class="cengi-ev-sub"> / ' + a.conf + '</span></td>' +
                '<td class="ev-td-dato" data-label="Percepción">' + (a.percep.n ? estr(a.percep.prom) + '<div class="cengi-ev-sub">' + a.percep.n + ' resp. · ' + Math.round(a.percep.satisf) + '% satisfechos</div>' : '<span class="cengi-ev-sub">Sin respuestas</span>') + '</td>' +
                '<td class="ev-td-dato" data-label="Tema de mejora" style="font-size:12px;">' + (t ? esc(t[0]) : '—') + '</td>' +
                '<td class="ev-td-dato" data-label="Colaboración">' + (a.colab.activo ? (a.colab.resp ? estr(a.colab.indice) + '<div class="cengi-ev-sub">' + a.colab.resp + ' de ' + a.colab.empresas + ' aliada(s)</div>' : '<span class="cengi-ev-sub">' + a.colab.empresas + ' aliada(s), sin respuesta</span>') : '<span class="cengi-ev-sub">No es colaboración</span>') + '</td>' +
                '<td class="ev-td-acc" style="text-align:right;white-space:nowrap;"><button type="button" class="btn btn-default btn-sm" data-encuestas="' + e.id + '">Encuestas</button> <button type="button" class="btn btn-primary btn-sm" data-informe="' + e.id + '">Informe</button></td></tr>';
        }).join('') : '<tr><td colspan="6" class="cengi-ev-empty">No hay eventos registrados todavía.</td></tr>');
    }
    $('#tablaEvEval')
        .on('click', '[data-encuestas]', function () { abrirEncuestas($(this).attr('data-encuestas')); })
        .on('click', '[data-informe]', function () { abrirInforme($(this).attr('data-informe')); });

    $('.cengi-ev-tab').on('click', function () {
        var t = $(this).attr('data-evtab');
        $('.cengi-ev-tab').removeClass('active').filter(this).addClass('active');
        $('#evPanelEventos').toggle(t === 'eventos');
        $('#evPanelEvaluacion').toggle(t === 'evaluacion');
        $('#evEstadoF, #evBuscar').toggle(t === 'eventos');
        if (t === 'evaluacion') cargar(renderEvaluacion);
    });

    /* ---------- nuevo / editar evento (ppEvAbrirEvento / ppEvGuardarEvento) ---------- */
    var NOTAS_FIN = {
        empresa: 'El evento queda gratuito para los participantes: reciben su QR al inscribirse.',
        compartido: 'Configura abajo la cuota que pagarán los participantes (acceso pagado).',
        centro: 'CENGICAÑA asume el costo; el evento es gratuito para los participantes.'
    };
    var colabFilas = [];
    var eventoEditado = null;

    function leerColabFilas() {
        colabFilas = $('#evfColabLista .evf-colab').map(function () {
            var $f = $(this);
            return {id: $f.find('[name="aliado_id[]"]').val(), nombre: $f.find('[name="aliado_nombre[]"]').val(), contacto: $f.find('[name="aliado_contacto[]"]').val(), correo: $f.find('[name="aliado_correo[]"]').val()};
        }).get();
    }
    function renderColab() {
        var on = $('#evfColab').prop('checked');
        $('#evfColabBox').toggle(on);
        $('#evfColabNota').text(NOTAS_FIN[$('#evfColabFin').val()] || '');
        $('#evfColabLista').html(colabFilas.length ? colabFilas.map(function (c, k) {
            return '<div class="evf-colab"><input type="hidden" name="aliado_id[]" value="' + esc(c.id || '') + '">' +
                '<input class="form-control input-sm" name="aliado_nombre[]" maxlength="255" placeholder="Empresa' + (k === 0 ? ' (principal, recibe la propuesta)' : '') + '" value="' + esc(c.nombre) + '">' +
                '<input class="form-control input-sm" name="aliado_contacto[]" maxlength="255" placeholder="Contacto" value="' + esc(c.contacto || '') + '">' +
                '<input class="form-control input-sm" type="email" name="aliado_correo[]" maxlength="255" placeholder="Correo" value="' + esc(c.correo || '') + '">' +
                '<button type="button" class="close" aria-label="Quitar empresa" data-quitar-aliado="' + k + '">&times;</button></div>';
        }).join('') : '<div class="cengi-ev-sub">Agrega al menos una empresa aliada.</div>');
        // Si la empresa aliada o el Centro cubren el costo, el acceso queda gratuito; al volver a
        // "Compartido" (o desmarcar la colaboracion) se recupera el acceso y costo que tenia.
        var $acceso = $('#evtModalidadPago'), gratis = on && $('#evfColabFin').val() !== 'compartido';
        if (gratis && !$acceso.prop('disabled')) {
            $acceso.data('previo', $acceso.val()).val('Gratuito').prop('disabled', true);
        } else if (!gratis && $acceso.prop('disabled')) {
            $acceso.val($acceso.data('previo') || 'Gratuito').prop('disabled', false);
        }
        accesoCambio();
    }
    function accesoCambio() {
        // El costo se oculta pero no se borra: el servidor lo ignora si el acceso es gratuito.
        $('#evtCostoGrupo').toggle($('#evtModalidadPago').val() === 'Pagado');
    }
    $('#evtModalidadPago').on('change', accesoCambio);
    $('#evfColab, #evfColabFin').on('change', function () { leerColabFilas(); renderColab(); });
    $('#evfColabAgregar').on('click', function () { leerColabFilas(); colabFilas.push({id: '', nombre: '', contacto: '', correo: ''}); renderColab(); });
    $('#evfColabLista').on('click', '[data-quitar-aliado]', function () { leerColabFilas(); colabFilas.splice(Number($(this).attr('data-quitar-aliado')), 1); renderColab(); });

    function abrirEvento(id) {
        var e = id ? (CFG.eventos || {})[id] : null;
        eventoEditado = e;
        $('#evfId').val(e ? e.id : '');
        $('#evfNombre').val(e ? e.nombre : '');
        // Un tipo guardado que ya no esta en la lista se agrega para no perderlo al editar.
        if (e && !$('#evfTipo option').filter(function () { return this.value === e.tipo; }).length) $('#evfTipo').append($('<option>').text(e.tipo));
        $('#evfTipo').val(e ? e.tipo : 'Evento técnico');
        $('#evfFecha').val(e ? e.fecha : '');
        $('#evfHora').val(e ? e.hora : '');
        $('#evfLugar').val(e ? e.lugar : '');
        $('#evtModalidadPago').prop('disabled', false).removeData('previo').val(e ? e.modalidad_pago : 'Gratuito');
        $('#evfCupo').val(e ? e.cupo : '');
        $('#evtCosto').val(e && e.modalidad_pago === 'Pagado' ? e.costo : '');
        $('#evfEstado').val(e ? e.estado : 'Planificado');
        $('#evfEstadoGrupo').toggle(!!e);
        $('#evfColor').val(e && /^#[0-9a-fA-F]{6}$/.test(e.color) ? e.color : '#2F6B12');
        $('#evfDesc').val(e ? e.descripcion : '');
        $('#evfColab').prop('checked', !!(e && e.en_colaboracion));
        $('#evfColabMod').val(String(e ? e.colab_modalidad : 2));
        $('#evfColabFin').val(e ? e.colab_financia : 'empresa');
        colabFilas = e ? e.aliados.map(function (a) { return $.extend({}, a); }) : [];
        $('#evfTitulo').text(e ? 'Editar evento' : 'Nuevo evento');
        $('#evfMsg').removeClass('is-err').empty();
        renderColab();
        if (e && e.modalidad_pago === 'Pagado') $('#evtCosto').val(e.costo);
        $('#evtModal').modal('show');
    }

    $('#evfForm').on('submit', function (ev2) {
        leerColabFilas();
        var errs = [];
        if ($('#evfNombre').val().trim().length < 3) errs.push('Escribe el nombre del evento.');
        if (!isIso($('#evfFecha').val())) errs.push('Indica la fecha.');
        if (!$('#evfLugar').val().trim()) errs.push('Indica el lugar.');
        var colab = $('#evfColab').prop('checked');
        var pagado = $('#evtModalidadPago').val() === 'Pagado' && !(colab && $('#evfColabFin').val() !== 'compartido');
        if (pagado && !(Number($('#evtCosto').val()) > 0)) errs.push('Un evento pagado necesita costo nacional mayor que 0.');
        if (colab && !colabFilas.filter(function (c) { return String(c.nombre).trim(); }).length) errs.push('Agrega al menos una empresa aliada o desmarca “Evento en colaboración”.');
        if (errs.length) {
            ev2.preventDefault();
            $('#evfMsg').addClass('is-err').html(errs.map(esc).join('<br>'));
            return;
        }
        var e = eventoEditado;
        if (e && e.registrados && e.modalidad_pago !== (pagado ? 'Pagado' : 'Gratuito') &&
            !window.confirm('Cambiar el tipo de acceso afecta a los participantes ya registrados. ¿Continuar?')) {
            ev2.preventDefault();
            return;
        }
        if (e && e.en_colaboracion && colab) {
            var quitadas = e.aliados.filter(function (a) { return !colabFilas.some(function (c) { return String(c.id) === String(a.id) && String(c.nombre).trim(); }); });
            if (quitadas.length && !window.confirm('Se quitarán ' + quitadas.length + ' empresa(s) aliada(s) y sus respuestas a la encuesta. ¿Continuar?')) {
                ev2.preventDefault();
                return;
            }
        }
        $('#evtModalidadPago').prop('disabled', false);
    });

    /* ---------- funciones usadas desde los botones de la tabla ---------- */
    window.cengiEvtAbrirEvento = abrirEvento;
    window.cengiEvtDifusion = abrirDifusion;
    window.cengiEvtEncuestas = abrirEncuestas;
}(jQuery));
