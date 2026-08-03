<?php
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_eventos();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_eventos();
$mensaje = '';
$mensajeTipo = 'success';

function cengi_evt_generar_codigo(PDO $db)
{
    do {
        $codigo = 'EVT-' . date('Y') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("SELECT COUNT(*) FROM evento_participantes WHERE codigo_qr = ?");
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $codigo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    if ($accion === 'crear_evento' && $puedeGestionar) {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $tipo = trim((string) ($_POST['tipo'] ?? 'Capacitacion'));
        $fecha = trim((string) ($_POST['fecha'] ?? '')) ?: null;

        if ($nombre !== '') {
            $stmt = $db->prepare("INSERT INTO eventos (nombre, tipo, fecha, estado, creado_por) VALUES (?, ?, ?, 'Planificado', ?)");
            $stmt->execute([$nombre, $tipo, $fecha, cengi_usuario_actual_id()]);
            $mensaje = 'Evento creado correctamente.';
        }
    } elseif ($accion === 'cambiar_estado_evento' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $estado = trim((string) ($_POST['estado'] ?? ''));
        if ($eventoId > 0 && in_array($estado, ['Planificado', 'En curso', 'Finalizado', 'Cancelado'], true)) {
            $stmt = $db->prepare("UPDATE eventos SET estado = ? WHERE id = ?");
            $stmt->execute([$estado, $eventoId]);
            $mensaje = 'Estado del evento actualizado.';
        }
    } elseif ($accion === 'registrar_participante' && $puedeGestionar) {
        $eventoId = (int) ($_POST['evento_id'] ?? 0);
        $participanteId = (int) ($_POST['participante_id'] ?? 0);
        $nombreInvitado = trim((string) ($_POST['nombre_invitado'] ?? ''));
        $cuiInvitado = trim((string) ($_POST['cui_invitado'] ?? ''));

        if ($eventoId > 0) {
            if ($participanteId > 0) {
                $stmtP = $db->prepare("SELECT nombre_participantes, cui_participantes FROM participantes WHERE id = ?");
                $stmtP->execute([$participanteId]);
                $p = $stmtP->fetch(PDO::FETCH_ASSOC);
                $nombreInvitado = $p['nombre_participantes'] ?? $nombreInvitado;
                $cuiInvitado = $p['cui_participantes'] ?? $cuiInvitado;
            } else {
                $participanteId = null;
            }

            if ($nombreInvitado !== '') {
                $codigo = cengi_evt_generar_codigo($db);
                $stmt = $db->prepare("
                    INSERT INTO evento_participantes (evento_id, participante_id, nombre_invitado, cui_invitado, codigo_qr)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$eventoId, $participanteId ?: null, $nombreInvitado, $cuiInvitado, $codigo]);
                $mensaje = "Participante registrado con codigo {$codigo}.";
            }
        }
    } elseif ($accion === 'marcar_ingreso' && $puedeGestionar) {
        $evtParticipanteId = (int) ($_POST['evento_participante_id'] ?? 0);
        if ($evtParticipanteId > 0) {
            $stmt = $db->prepare("UPDATE evento_participantes SET ingreso_en = NOW() WHERE id = ? AND ingreso_en IS NULL");
            $stmt->execute([$evtParticipanteId]);
            $mensaje = 'Ingreso registrado (simulado, sin lector QR fisico todavia).';
        }
    }
}

$eventos = $db->query("
    SELECT
        e.*,
        (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id) AS registrados,
        (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.ingreso_en IS NOT NULL) AS ingresos
    FROM eventos e
    ORDER BY e.fecha DESC, e.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$eventoActivoId = (int) ($_GET['evento_id'] ?? 0);
$eventoActivo = null;
$participantesEvento = [];
$ejemploParticipante = null;

if ($eventoActivoId > 0) {
    $stmt = $db->prepare("SELECT * FROM eventos WHERE id = ?");
    $stmt->execute([$eventoActivoId]);
    $eventoActivo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($eventoActivo) {
        $stmt = $db->prepare("
            SELECT ep.*, i.nombre_ingenios
            FROM evento_participantes ep
            LEFT JOIN participantes p ON p.id = ep.participante_id
            LEFT JOIN ingenios i ON i.id = p.ingenio_id
            WHERE ep.evento_id = ?
            ORDER BY ep.id DESC
        ");
        $stmt->execute([$eventoActivoId]);
        $participantesEvento = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ejemploParticipante = $participantesEvento[0] ?? null;
    }
}

$participantesDirectorio = $db->query("
    SELECT p.id, p.nombre_participantes, p.cui_participantes, i.nombre_ingenios
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    ORDER BY p.nombre_participantes
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

function cengi_evt_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
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
?>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
<?php menu_render(); ?>
<div class="container">

    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-ok"></span></div>
            <div><p><?php echo cengi_evt_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <div class="cengi-two-col">
        <div>
            <div class="panel panel-success">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 class="panel-title">Eventos con control QR</h3>
                        <small>Seminarios y talleres con registro de ingreso por codigo QR</small>
                    </div>
                    <?php if ($puedeGestionar): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#evtModal">
                            <span class="glyphicon glyphicon-plus"></span> Nuevo evento
                        </button>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <div class="cengi-table-wrap">
                        <table class="table table-striped table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Evento</th>
                                    <th>Fecha</th>
                                    <th>Registrados</th>
                                    <th>Ingresos QR</th>
                                    <th>% Asistencia</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$eventos): ?>
                                    <tr><td colspan="7" class="text-center">No hay eventos registrados todavia.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($eventos as $evt): ?>
                                    <?php $pct = ((int) $evt['registrados'] > 0) ? round(($evt['ingresos'] / $evt['registrados']) * 100) : 0; ?>
                                    <tr>
                                        <td><strong><?php echo cengi_evt_html($evt['nombre']); ?></strong><br><small class="text-muted"><?php echo cengi_evt_html($evt['tipo']); ?></small></td>
                                        <td><?php echo cengi_evt_html($evt['fecha'] ?: '—'); ?></td>
                                        <td><?php echo (int) $evt['registrados']; ?></td>
                                        <td><?php echo (int) $evt['ingresos']; ?></td>
                                        <td><?php echo $pct; ?>%</td>
                                        <td><span class="cengi-status-badge <?php echo cengi_evt_estado_badge($evt['estado']); ?>"><i></i><?php echo cengi_evt_html($evt['estado']); ?></span></td>
                                        <td>
                                            <a href="eventos_qr.php?evento_id=<?php echo (int) $evt['id']; ?>" class="cengi-action-btn is-view" data-tooltip="Ver participantes"><span class="glyphicon glyphicon-list-alt"></span></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel panel-success">
                <div class="panel-heading"><h3 class="panel-title">Flujo de registro por QR</h3></div>
                <div class="panel-body">
                    <div class="cengi-rail">
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-dot">1</div><div class="cengi-rail-label">Participante se registra</div></div>
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">2</div><div class="cengi-rail-label">Sistema genera codigo QR</div></div>
                        <div class="cengi-rail-step is-done"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">3</div><div class="cengi-rail-label">QR impreso en gafete</div></div>
                        <div class="cengi-rail-step is-now"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">4</div><div class="cengi-rail-label">Ingreso marcado manualmente</div></div>
                        <div class="cengi-rail-step"><div class="cengi-rail-line"></div><div class="cengi-rail-dot">5</div><div class="cengi-rail-label">Escaneo automatico (pendiente)</div></div>
                    </div>
                    <div class="cengi-notice">
                        <span class="glyphicon glyphicon-info-sign"></span>
                        <span>El codigo QR se genera y guarda para cada participante (columna <code>codigo_qr</code>), pero el escaneo con camara/lector fisico todavia no esta implementado: el ingreso se marca manualmente con el boton "Marcar ingreso" hasta integrar un lector real.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel panel-success">
            <div class="panel-heading"><h3 class="panel-title">Gafete de ejemplo</h3></div>
            <div class="panel-body" style="display:flex;justify-content:center;">
                <div class="cengi-badge-card">
                    <div class="bc-top">
                        <div style="font-size:10px;letter-spacing:.1em;text-transform:uppercase;opacity:.85;">CENGICAÑA · Evento tecnico</div>
                        <div style="font-family:'Space Grotesk';font-weight:700;font-size:14px;margin-top:4px;">
                            <?php echo cengi_evt_html($eventoActivo['nombre'] ?? ($eventos[0]['nombre'] ?? 'Sin eventos aun')); ?>
                        </div>
                    </div>
                    <div class="bc-body">
                        <div style="font-weight:700;font-family:'Space Grotesk';font-size:16px;">
                            <?php echo cengi_evt_html($ejemploParticipante['nombre_invitado'] ?? 'Nombre del participante'); ?>
                        </div>
                        <div style="font-size:12px;color:var(--cengi-muted);margin:2px 0 14px 0;">
                            <?php echo cengi_evt_html($ejemploParticipante['nombre_ingenios'] ?? 'Ingenio / institucion'); ?>
                        </div>
                        <div class="cengi-qr-box">
                            <svg viewBox="0 0 100 100" width="90" height="90"><rect width="100" height="100" fill="white"/>
                                <g fill="#1E2A1A">
                                    <rect x="6" y="6" width="24" height="24"/><rect x="12" y="12" width="12" height="12" fill="white"/>
                                    <rect x="70" y="6" width="24" height="24"/><rect x="76" y="12" width="12" height="12" fill="white"/>
                                    <rect x="6" y="70" width="24" height="24"/><rect x="12" y="76" width="12" height="12" fill="white"/>
                                    <rect x="40" y="6" width="6" height="6"/><rect x="52" y="6" width="6" height="6"/><rect x="40" y="18" width="6" height="6"/>
                                    <rect x="60" y="40" width="6" height="6"/><rect x="40" y="40" width="6" height="6"/><rect x="46" y="52" width="6" height="6"/>
                                    <rect x="70" y="52" width="6" height="6"/><rect x="82" y="70" width="6" height="6"/><rect x="70" y="82" width="6" height="6"/>
                                    <rect x="40" y="70" width="6" height="6"/><rect x="52" y="82" width="6" height="6"/><rect x="40" y="88" width="6" height="6"/>
                                    <rect x="88" y="40" width="6" height="6"/><rect x="76" y="40" width="6" height="6"/><rect x="58" y="60" width="6" height="6"/>
                                </g>
                            </svg>
                        </div>
                        <div class="mono" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--cengi-muted);margin-top:10px;">
                            <?php echo cengi_evt_html($ejemploParticipante['codigo_qr'] ?? 'EVT-' . date('Y') . '-0000'); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="panel-body" style="padding-top:0;">
                <p class="text-muted" style="font-size:11.5px;">
                    El QR mostrado es una representacion grafica de ejemplo (SVG estatico). Todavia no se genera una imagen QR real escaneable: pendiente integrar una libreria de generacion de QR (no hay ninguna instalada en <code>composer.json</code> de este monorepo).
                </p>
            </div>
        </div>
    </div>

    <?php if ($eventoActivo): ?>
    <div class="panel panel-success" style="margin-top:16px;">
        <div class="panel-heading">
            <h3 class="panel-title">Participantes de: <?php echo cengi_evt_html($eventoActivo['nombre']); ?></h3>
        </div>
        <div class="panel-body">
            <?php if ($puedeGestionar): ?>
            <form method="POST" class="cengi-form-grid" style="margin-bottom:18px;">
                <input type="hidden" name="accion" value="registrar_participante">
                <input type="hidden" name="evento_id" value="<?php echo (int) $eventoActivo['id']; ?>">
                <div class="form-group">
                    <label class="control-label">Participante del directorio (opcional)</label>
                    <select name="participante_id" class="form-control">
                        <option value="0">— Invitado externo (usar campos de abajo) —</option>
                        <?php foreach ($participantesDirectorio as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>"><?php echo cengi_evt_html($p['nombre_participantes'] . ' — ' . $p['nombre_ingenios']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="control-label">Nombre (si es invitado externo)</label>
                    <input type="text" name="nombre_invitado" class="form-control">
                </div>
                <div class="form-group">
                    <label class="control-label">CUI (si es invitado externo)</label>
                    <input type="text" name="cui_invitado" class="form-control">
                </div>
                <div class="form-group cengi-form-full">
                    <button type="submit" class="btn btn-success"><span class="glyphicon glyphicon-plus"></span> Registrar participante</button>
                </div>
            </form>
            <?php endif; ?>

            <div class="cengi-table-wrap">
                <table class="table table-striped table-bordered">
                    <thead><tr><th>Participante</th><th>Ingenio</th><th>Codigo QR</th><th>Ingreso</th><?php if ($puedeGestionar): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php if (!$participantesEvento): ?>
                            <tr><td colspan="5" class="text-center">Todavia no hay participantes registrados en este evento.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($participantesEvento as $ep): ?>
                            <tr>
                                <td><?php echo cengi_evt_html($ep['nombre_invitado']); ?></td>
                                <td><?php echo cengi_evt_html($ep['nombre_ingenios'] ?: '—'); ?></td>
                                <td class="mono" style="font-family:'JetBrains Mono',monospace;"><?php echo cengi_evt_html($ep['codigo_qr']); ?></td>
                                <td>
                                    <?php if ($ep['ingreso_en']): ?>
                                        <span class="cengi-status-badge is-active"><i></i><?php echo cengi_evt_html($ep['ingreso_en']); ?></span>
                                    <?php else: ?>
                                        <span class="cengi-status-badge is-neutral"><i></i>Sin ingreso</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($puedeGestionar): ?>
                                <td>
                                    <?php if (!$ep['ingreso_en']): ?>
                                        <form method="POST">
                                            <input type="hidden" name="accion" value="marcar_ingreso">
                                            <input type="hidden" name="evento_participante_id" value="<?php echo (int) $ep['id']; ?>">
                                            <button type="submit" class="btn btn-success btn-xs">Marcar ingreso</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($puedeGestionar): ?>
<div class="modal fade" id="evtModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="model-header">
                    <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h4 class="modal-title">Nuevo evento</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="crear_evento">
                    <div class="cengi-form-grid">
                        <div class="form-group cengi-form-full">
                            <label class="control-label">Nombre del evento</label>
                            <input type="text" name="nombre" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Tipo</label>
                            <select name="tipo" class="form-control">
                                <option>Capacitacion</option>
                                <option>Seminario</option>
                                <option>Taller</option>
                                <option>Evento tecnico</option>
                                <option>Feria</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Fecha</label>
                            <input type="date" name="fecha" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>
