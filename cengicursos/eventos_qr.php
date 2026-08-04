<?php
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_eventos();
$db = conectar();
$puedeGestionar = cengi_puede_gestionar_eventos();
$mensaje = '';
$mensajeTipo = 'success';
$eventoReabrirId = (int) ($_GET['evento_id'] ?? 0);

function cengi_evt_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_evt_generar_codigo(PDO $db)
{
    do {
        $codigo = 'EVT-' . date('Y') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("SELECT COUNT(*) FROM evento_participantes WHERE codigo_qr = ?");
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);
    return $codigo;
}

function cengi_evt_estado_badge($estado)
{
    $mapa = [
        'Planificado' => 'is-upcoming',
        'En curso' => 'is-active',
        'Finalizado' => 'is-finished',
        'Cancelado' => 'is-rejected',
    ];
    return $mapa[$estado] ?? 'is-neutral';
}

/* Respuesta JSON usada por el modal de participantes. */
if (($_GET['accion'] ?? '') === 'listar_participantes') {
    header('Content-Type: application/json; charset=UTF-8');
    $eventoId = (int) ($_GET['evento_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, nombre, tipo, fecha, estado FROM eventos WHERE id = ?");
    $stmt->execute([$eventoId]);
    $evento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evento) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensaje' => 'El evento no existe.']);
        exit;
    }
    $stmt = $db->prepare("
        SELECT ep.id, ep.nombre_invitado AS nombre, ep.cui_invitado AS cui,
               ep.codigo_qr, ep.ingreso_en,
               COALESCE(i.nombre_ingenios, 'Invitado externo') AS ingenio
        FROM evento_participantes ep
        LEFT JOIN participantes p ON p.id = ep.participante_id
        LEFT JOIN ingenios i ON i.id = p.ingenio_id
        WHERE ep.evento_id = ?
        ORDER BY ep.nombre_invitado, ep.id
    ");
    $stmt->execute([$eventoId]);
    echo json_encode([
        'ok' => true,
        'evento' => $evento,
        'participantes' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    if ($accion === 'crear_evento' && $puedeGestionar) {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $tipo = trim((string) ($_POST['tipo'] ?? 'Capacitación'));
        $fecha = trim((string) ($_POST['fecha'] ?? '')) ?: null;
        if ($nombre !== '') {
            $stmt = $db->prepare("INSERT INTO eventos (nombre, tipo, fecha, estado, creado_por) VALUES (?, ?, ?, 'Planificado', ?)");
            $stmt->execute([$nombre, $tipo, $fecha, cengi_usuario_actual_id()]);
            $mensaje = 'Evento creado correctamente.';
        }
    } elseif ($accion === 'registrar_participante' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $participanteId = (int) ($_POST['participante_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre_invitado'] ?? ''));
        $cui = trim((string) ($_POST['cui_invitado'] ?? ''));
        $eventoReabrirId = $eventoId;
        if ($eventoId > 0 && $participanteId > 0) {
            $stmt = $db->prepare("SELECT nombre_participantes, cui_participantes FROM participantes WHERE id = ?");
            $stmt->execute([$participanteId]);
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
            $nombre = $persona['nombre_participantes'] ?? $nombre;
            $cui = $persona['cui_participantes'] ?? $cui;
        }
        if ($eventoId > 0 && $nombre !== '') {
            $codigo = cengi_evt_generar_codigo($db);
            $stmt = $db->prepare("INSERT INTO evento_participantes (evento_id, participante_id, nombre_invitado, cui_invitado, codigo_qr) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$eventoId, $participanteId ?: null, $nombre, $cui, $codigo]);
            $mensaje = "Participante registrado. Su código QR es {$codigo}.";
        } else {
            $mensaje = 'Selecciona una persona o escribe el nombre del invitado.';
            $mensajeTipo = 'error';
        }
    } elseif ($accion === 'marcar_ingreso' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $participanteEventoId = (int) ($_POST['evento_participante_id'] ?? 0);
        $eventoReabrirId = $eventoId;
        if ($eventoId > 0 && $participanteEventoId > 0) {
            $stmt = $db->prepare("UPDATE evento_participantes SET ingreso_en = NOW() WHERE id = ? AND evento_id = ? AND ingreso_en IS NULL");
            $stmt->execute([$participanteEventoId, $eventoId]);
            $mensaje = 'Ingreso registrado correctamente.';
        }
    }
}

$eventos = $db->query("
    SELECT e.*,
      (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id) AS registrados,
      (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.ingreso_en IS NOT NULL) AS ingresos
    FROM eventos e ORDER BY e.fecha DESC, e.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$participantesDirectorio = $db->query("
    SELECT p.id, p.nombre_participantes, p.cui_participantes, i.nombre_ingenios
    FROM participantes p INNER JOIN ingenios i ON i.id = p.ingenio_id
    ORDER BY p.nombre_participantes LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$ejemploParticipante = $db->query("
    SELECT ep.nombre_invitado AS nombre, ep.codigo_qr, e.nombre AS evento,
           COALESCE(i.nombre_ingenios, 'Invitado externo') AS ingenio
    FROM evento_participantes ep INNER JOIN eventos e ON e.id = ep.evento_id
    LEFT JOIN participantes p ON p.id = ep.participante_id
    LEFT JOIN ingenios i ON i.id = p.ingenio_id
    ORDER BY e.fecha DESC, ep.id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas cengi-eventos-qr-page">
<?php menu_render(); ?>
<div class="container">
    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'warning-sign' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_evt_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <div class="cengi-two-col">
        <div>
            <div class="panel panel-success cengi-event-card">
                <div class="panel-heading cengi-event-heading">
                    <div>
                        <h3 class="panel-title">Eventos con control QR</h3>
                        <small>Seminarios y talleres con registro de ingreso por código QR</small>
                    </div>
                    <?php if ($puedeGestionar): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#evtModal"><span class="glyphicon glyphicon-plus"></span> Nuevo evento</button>
                    <?php endif; ?>
                </div>
                <div class="panel-body cengi-event-table-body">
                    <div class="cengi-table-wrap">
                        <table class="table cengi-events-table">
                            <thead><tr><th>Evento</th><th>Fecha</th><th>Registrados</th><th>Ingresos QR</th><th>% Asistencia</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                            <?php if (!$eventos): ?><tr><td colspan="7" class="text-center cengi-empty-cell">No hay eventos registrados todavía.</td></tr><?php endif; ?>
                            <?php foreach ($eventos as $evt): ?>
                                <?php $pct = (int) $evt['registrados'] > 0 ? round(((int) $evt['ingresos'] / (int) $evt['registrados']) * 100) : 0; ?>
                                <tr>
                                    <td><strong><?php echo cengi_evt_html($evt['nombre']); ?></strong><br><small class="text-muted"><?php echo cengi_evt_html($evt['tipo']); ?></small></td>
                                    <td><?php echo cengi_evt_html($evt['fecha'] ?: '—'); ?></td>
                                    <td><?php echo (int) $evt['registrados']; ?></td>
                                    <td><?php echo (int) $evt['ingresos']; ?></td>
                                    <td><div class="cengi-event-progress"><div class="cengi-progress-track"><div class="cengi-progress-fill" style="width:<?php echo (int) $pct; ?>%;"></div></div><span><?php echo (int) $pct; ?>%</span></div></td>
                                    <td><span class="cengi-status-badge <?php echo cengi_evt_estado_badge($evt['estado']); ?>"><i></i><?php echo cengi_evt_html($evt['estado']); ?></span></td>
                                    <td><button type="button" class="btn btn-default btn-sm cengi-view-participants" onclick="cengiEvtAbrirParticipantes(<?php echo (int) $evt['id']; ?>)">Ver participantes</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel panel-success cengi-event-card">
                <div class="panel-heading"><h3 class="panel-title">Flujo de registro por QR</h3></div>
                <div class="panel-body">
                    <div class="cengi-rail">
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-dot">1</div><div class="cengi-rail-label">Participante se registra</div></div>
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">2</div><div class="cengi-rail-label">Sistema genera QR</div></div>
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">3</div><div class="cengi-rail-label">QR en gafete</div></div>
                        <div class="cengi-rail-step is-now"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">4</div><div class="cengi-rail-label">Ingreso escaneando QR</div></div>
                        <div class="cengi-rail-step"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">5</div><div class="cengi-rail-label">Registro automático</div></div>
                    </div>
                    <div class="cengi-notice cengi-qr-notice"><span class="glyphicon glyphicon-info-sign"></span><span>Cada participante recibe un QR único y escaneable. En “Ver participantes” puedes abrirlo, descargarlo o marcar manualmente su ingreso.</span></div>
                </div>
            </div>
        </div>

        <div class="panel panel-success cengi-event-card">
            <div class="panel-heading"><h3 class="panel-title">Gafete de ejemplo</h3></div>
            <div class="panel-body cengi-example-badge-wrap">
                <div class="cengi-badge-card">
                    <div class="bc-top"><div class="cengi-badge-eyebrow">CENGICAÑA · Evento técnico</div><div class="cengi-badge-event"><?php echo cengi_evt_html($ejemploParticipante['evento'] ?? 'Sin eventos registrados'); ?></div></div>
                    <div class="bc-body">
                        <div class="cengi-badge-name"><?php echo cengi_evt_html($ejemploParticipante['nombre'] ?? 'Nombre del participante'); ?></div>
                        <div class="cengi-badge-company"><?php echo cengi_evt_html($ejemploParticipante['ingenio'] ?? 'Ingenio / institución'); ?></div>
                        <div class="cengi-qr-box" id="cengiQrEjemplo" data-codigo="<?php echo cengi_evt_html($ejemploParticipante['codigo_qr'] ?? 'EVT-' . date('Y') . '-0000'); ?>"></div>
                        <div class="mono cengi-badge-code"><?php echo cengi_evt_html($ejemploParticipante['codigo_qr'] ?? 'EVT-' . date('Y') . '-0000'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="modalEventoParticipantes" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog cengi-event-participants-dialog">
        <div class="modal-content">
            <div class="modal-header cengi-participants-modal-head">
                <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title" id="evpTitulo">Participantes del evento</h4>
                <div class="cengi-modal-hint">Registro individual de participantes y código QR</div>
            </div>
            <div class="modal-body cengi-participants-modal-body">
                <div class="cengi-participants-toolbar">
                    <input type="search" id="evpBuscar" class="form-control cengi-participant-search" placeholder="Buscar participante, ingenio o código QR…">
                    <button type="button" class="btn btn-default btn-sm" id="evpDescargar"><span class="glyphicon glyphicon-download-alt"></span> Descargar listado</button>
                    <?php if ($puedeGestionar): ?><button type="button" class="btn btn-primary btn-sm" id="evpMostrarRegistro"><span class="glyphicon glyphicon-plus"></span> Registrar participante</button><?php endif; ?>
                </div>

                <?php if ($puedeGestionar): ?>
                <form method="POST" id="evpRegistroForm" class="cengi-event-register-form" style="display:none;">
                    <input type="hidden" name="accion" value="registrar_participante">
                    <input type="hidden" name="evento_id" id="evpRegistroEventoId" value="0">
                    <div class="cengi-form-grid">
                        <div class="form-group cengi-form-full">
                            <label class="control-label">Participante del directorio</label>
                            <select name="participante_id" class="form-control">
                                <option value="0">— Invitado externo —</option>
                                <?php foreach ($participantesDirectorio as $p): ?>
                                    <option value="<?php echo (int) $p['id']; ?>"><?php echo cengi_evt_html($p['nombre_participantes'] . ' — ' . $p['nombre_ingenios']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="control-label">Nombre del invitado externo</label><input type="text" name="nombre_invitado" class="form-control"></div>
                        <div class="form-group"><label class="control-label">CUI del invitado externo</label><input type="text" name="cui_invitado" class="form-control"></div>
                        <div class="form-group cengi-form-full cengi-register-actions"><button type="button" class="btn btn-default btn-sm" id="evpCancelarRegistro">Cancelar</button><button type="submit" class="btn btn-success btn-sm">Generar QR y registrar</button></div>
                    </div>
                </form>
                <?php endif; ?>

                <div id="evpCargando" class="cengi-modal-loading"><span class="glyphicon glyphicon-refresh"></span> Cargando participantes…</div>
                <div class="cengi-table-wrap" id="evpTablaWrap" style="display:none;">
                    <table class="table cengi-event-participants-table">
                        <thead><tr><th>Participante</th><th>Ingenio</th><th>Código QR</th><th>Ingreso</th><th></th></tr></thead>
                        <tbody id="tablaEventoParticipantes"></tbody>
                    </table>
                </div>
                <div id="evpVacio" class="cengi-empty-participants" style="display:none;">Todavía no hay participantes registrados en este evento.</div>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="modalQrParticipante" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog cengi-qr-detail-dialog">
        <div class="modal-content">
            <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title">QR del participante</h4></div>
            <div class="modal-body cengi-qr-detail-body">
                <div class="cengi-badge-card cengi-individual-badge">
                    <div class="bc-top"><div class="cengi-badge-eyebrow">CENGICAÑA · Evento técnico</div><div class="cengi-badge-event" id="qrDetalleEvento"></div></div>
                    <div class="bc-body"><div class="cengi-badge-name" id="qrDetalleNombre"></div><div class="cengi-badge-company" id="qrDetalleIngenio"></div><div class="cengi-qr-box cengi-qr-box-large" id="qrDetalleImagen"></div><div class="mono cengi-badge-code" id="qrDetalleCodigo"></div></div>
                </div>
                <p class="cengi-qr-help">Al escanear este QR se obtiene el código único asignado a esta persona.</p>
            </div>
            <div class="modal-footer cengi-qr-detail-actions"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button><button type="button" class="btn btn-primary" id="qrDescargar"><span class="glyphicon glyphicon-download-alt"></span> Descargar QR</button></div>
        </div>
    </div>
</div>

<?php if ($puedeGestionar): ?>
<div class="modal fade" id="evtModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><form method="POST">
        <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title">Nuevo evento</h4></div>
        <div class="modal-body">
            <input type="hidden" name="accion" value="crear_evento">
            <div class="cengi-form-grid">
                <div class="form-group cengi-form-full"><label class="control-label">Nombre del evento</label><input type="text" name="nombre" class="form-control" required></div>
                <div class="form-group"><label class="control-label">Tipo</label><select name="tipo" class="form-control"><option>Capacitación</option><option>Seminario</option><option>Taller</option><option>Evento técnico</option><option>Feria</option></select></div>
                <div class="form-group"><label class="control-label">Fecha</label><input type="date" name="fecha" class="form-control"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Guardar</button></div>
    </form></div></div>
</div>
<?php endif; ?>
<script src="js/qrcode-generator.js"></script>
<script>
(function () {
    'use strict';
    var eventoActual = null;
    var participantes = [];
    var qrActual = '';

    function escapeHtml(valor) {
        return $('<div>').text(valor == null ? '' : String(valor)).html();
    }

    function iniciales(nombre) {
        var partes = String(nombre || '').trim().split(/\s+/).filter(Boolean);
        return (partes[0] ? partes[0].charAt(0) : '') + (partes[1] ? partes[1].charAt(0) : '');
    }

    function crearQr(codigo, contenedor, grande) {
        var elemento = typeof contenedor === 'string' ? document.getElementById(contenedor) : contenedor;
        if (!elemento) return;
        elemento.innerHTML = '';
        if (typeof qrcode !== 'function') {
            elemento.textContent = codigo;
            return;
        }
        var qr = qrcode(0, 'M');
        qr.addData(String(codigo), 'Byte');
        qr.make();
        elemento.innerHTML = qr.createSvgTag({cellSize: grande ? 6 : 3, margin: grande ? 16 : 8, scalable: true});
        var svg = elemento.querySelector('svg');
        if (svg) svg.setAttribute('aria-label', 'Código QR ' + codigo);
    }

    function renderParticipantes() {
        var busqueda = ($('#evpBuscar').val() || '').toLowerCase().trim();
        var filtrados = participantes.filter(function (p) {
            return !busqueda || [p.nombre, p.ingenio, p.codigo_qr, p.cui].join(' ').toLowerCase().indexOf(busqueda) !== -1;
        });
        $('#evpCargando').hide();
        $('#evpVacio').toggle(filtrados.length === 0).text(participantes.length === 0
            ? 'Todavía no hay participantes registrados en este evento.'
            : 'No se encontraron participantes con esa búsqueda.');
        $('#evpTablaWrap').toggle(filtrados.length > 0);

        $('#tablaEventoParticipantes').html(filtrados.map(function (p) {
            var indice = participantes.indexOf(p);
            var ingreso = p.ingreso_en
                ? '<span class="cengi-status-badge is-active"><i></i>Ingresó</span><small class="cengi-entry-time">' + escapeHtml(p.ingreso_en) + '</small>'
                : '<span class="cengi-status-badge is-neutral"><i></i>Sin ingreso</span>';
            var marcar = (!p.ingreso_en && <?php echo $puedeGestionar ? 'true' : 'false'; ?>)
                ? '<form method="POST" class="cengi-inline-entry-form"><input type="hidden" name="accion" value="marcar_ingreso"><input type="hidden" name="evento_id" value="' + Number(eventoActual.id) + '"><input type="hidden" name="evento_participante_id" value="' + Number(p.id) + '"><button type="submit" class="btn btn-success btn-xs">Marcar ingreso</button></form>'
                : '';
            return '<tr>' +
                '<td><div class="cengi-person-cell"><span class="cengi-avatar-sm">' + escapeHtml(iniciales(p.nombre)) + '</span><span><strong>' + escapeHtml(p.nombre) + '</strong>' + (p.cui ? '<small>CUI ' + escapeHtml(p.cui) + '</small>' : '') + '</span></div></td>' +
                '<td>' + escapeHtml(p.ingenio) + '</td>' +
                '<td><div class="cengi-person-qr"><span class="cengi-mini-qr" data-codigo="' + escapeHtml(p.codigo_qr) + '"></span><span class="mono">' + escapeHtml(p.codigo_qr) + '</span></div></td>' +
                '<td>' + ingreso + marcar + '</td>' +
                '<td><button type="button" class="cengi-action-btn is-view" title="Ver QR y gafete" onclick="cengiEvtVerQr(' + indice + ')"><span class="glyphicon glyphicon-qrcode"></span></button></td></tr>';
        }).join(''));
        $('#tablaEventoParticipantes .cengi-mini-qr').each(function () { crearQr($(this).attr('data-codigo'), this, false); });
    }

    window.cengiEvtAbrirParticipantes = function (eventoId) {
        eventoActual = {id: Number(eventoId)};
        participantes = [];
        $('#evpTitulo').text('Participantes del evento');
        $('#evpBuscar').val('');
        $('#evpRegistroEventoId').val(eventoId);
        $('#evpRegistroForm').hide();
        $('#evpTablaWrap, #evpVacio').hide();
        $('#evpCargando').show();
        $('#modalEventoParticipantes').modal('show');
        $.getJSON('eventos_qr.php', {accion: 'listar_participantes', evento_id: eventoId})
            .done(function (respuesta) {
                if (!respuesta.ok) return;
                eventoActual = respuesta.evento;
                participantes = respuesta.participantes || [];
                $('#evpTitulo').text(eventoActual.nombre);
                renderParticipantes();
            })
            .fail(function () {
                $('#evpCargando').hide();
                $('#evpVacio').text('No fue posible cargar los participantes. Intenta nuevamente.').show();
            });
    };

    window.cengiEvtVerQr = function (indice) {
        var p = participantes[indice];
        if (!p || !eventoActual) return;
        qrActual = p.codigo_qr;
        $('#qrDetalleEvento').text(eventoActual.nombre || 'Evento técnico');
        $('#qrDetalleNombre').text(p.nombre);
        $('#qrDetalleIngenio').text(p.ingenio);
        $('#qrDetalleCodigo').text(p.codigo_qr);
        crearQr(p.codigo_qr, 'qrDetalleImagen', true);
        $('#modalQrParticipante').modal('show');
    };

    $('#evpBuscar').on('input', renderParticipantes);
    $('#evpMostrarRegistro').on('click', function () { $('#evpRegistroForm').slideDown(150); });
    $('#evpCancelarRegistro').on('click', function () { $('#evpRegistroForm').slideUp(150); });

    $('#evpDescargar').on('click', function () {
        if (!eventoActual) return;
        var filas = [['Participante', 'CUI', 'Ingenio', 'Código QR', 'Ingreso']].concat(participantes.map(function (p) {
            return [p.nombre, p.cui || '', p.ingenio, p.codigo_qr, p.ingreso_en || 'Sin ingreso'];
        }));
        var csv = '\uFEFF' + filas.map(function (fila) {
            return fila.map(function (dato) { return '"' + String(dato).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\r\n');
        var enlace = document.createElement('a');
        enlace.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
        enlace.download = 'participantes-evento-' + eventoActual.id + '.csv';
        enlace.click();
        URL.revokeObjectURL(enlace.href);
    });

    $('#qrDescargar').on('click', function () {
        var svg = document.querySelector('#qrDetalleImagen svg');
        if (!svg || !qrActual) return;
        var contenido = '<' + '?xml version="1.0" encoding="UTF-8"?' + '>\n' + new XMLSerializer().serializeToString(svg);
        var enlace = document.createElement('a');
        enlace.href = URL.createObjectURL(new Blob([contenido], {type: 'image/svg+xml;charset=utf-8'}));
        enlace.download = 'QR-' + qrActual.replace(/[^A-Za-z0-9_-]/g, '-') + '.svg';
        enlace.click();
        URL.revokeObjectURL(enlace.href);
    });

    var ejemplo = document.getElementById('cengiQrEjemplo');
    if (ejemplo) crearQr(ejemplo.getAttribute('data-codigo'), ejemplo, true);
    <?php if ($eventoReabrirId > 0): ?>cengiEvtAbrirParticipantes(<?php echo (int) $eventoReabrirId; ?>);<?php endif; ?>
}());
</script>
</body>
</html>
