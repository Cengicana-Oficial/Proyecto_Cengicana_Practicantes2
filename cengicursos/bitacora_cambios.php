<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';

const CENGI_NOTIF_ESTADOS = [
    'pendiente' => 'Pendiente de notificar',
    'enviada' => 'Notificado',
    'sin inscritos' => 'Sin inscritos',
    'omitida' => 'No notificado (decisión)',
    'no aplica' => 'No aplica',
];

/** Texto de la programacion vigente de un plan (para avisos e historial). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $plan = cengi_pp_plan($db, (int) ($_POST['plan_id'] ?? 0));
    $cambiosSel = array_map('intval', (array) ($_POST['cambios'] ?? []));
    try {
        if (!$plan || !$cambiosSel) {
            throw new InvalidArgumentException('Marca al menos un cambio.');
        }
        $marcadores = implode(',', array_fill(0, count($cambiosSel), '?'));
        if ($accion === 'notificar') {
            $asunto = trim((string) ($_POST['asunto'] ?? ''));
            $mensajeCorreo = trim((string) ($_POST['mensaje'] ?? ''));
            $elegidos = array_flip(array_map('strval', (array) ($_POST['destinatarios'] ?? [])));
            $destinatarios = array_values(array_filter(cengi_pp_inscritos($db, $plan), static function ($i) use ($elegidos) {
                return isset($elegidos[$i['clave']]);
            }));
            if (!$destinatarios || $asunto === '' || $mensajeCorreo === '') {
                throw new InvalidArgumentException('Marca al menos un destinatario y escribe el asunto y el mensaje.');
            }
            [$comId, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, [
                'categoria' => 'Cambios de programación', 'origen' => 'cambios', 'planificacion_id' => (int) $plan['id'],
                'asunto' => $asunto, 'mensaje' => $mensajeCorreo,
            ], $destinatarios);
            if ($enviados === 0) {
                throw new InvalidArgumentException('No se pudo enviar el aviso (' . $fallidos . ' correo(s) fallaron). Revisa la configuración de correo; los cambios siguen pendientes.');
            }
            $db->prepare("UPDATE planificacion_cambios SET notificacion = 'enviada', comunicacion_id = ? WHERE planificacion_id = ? AND notificacion = 'pendiente' AND id IN ($marcadores)")
                ->execute(array_merge([$comId, (int) $plan['id']], $cambiosSel));
            $codigo = $fallidos ? 'enviado_parcial' : 'enviado';
        } elseif ($accion === 'omitir') {
            $db->prepare("UPDATE planificacion_cambios SET notificacion = 'omitida' WHERE planificacion_id = ? AND notificacion = 'pendiente' AND id IN ($marcadores)")
                ->execute(array_merge([(int) $plan['id']], $cambiosSel));
            $codigo = 'omitido';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        $_GET = array_diff_key($_GET, array_flip(['notificar', 'msg']));
        cengi_pp_redirigir('bitacora_cambios.php', ['plan' => $plan['id'], 'msg' => $codigo]);
    } catch (Throwable $e) {
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = cengi_pp_h($e->getMessage());
        } else {
            error_log('Bitacora de cambios: ' . $e->getMessage());
            $mensaje = 'No fue posible enviar el aviso.';
        }
    }
}

$mensajes = [
    'enviado' => 'Aviso enviado a los inscritos. Los cambios quedan como notificados.',
    'enviado_parcial' => 'Aviso enviado, pero algunos correos fallaron: revisa el detalle en Comunicaciones.',
    'omitido' => 'Cambios marcados como “No notificado”.',
];
if ($mensaje === '' && isset($mensajes[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h($mensajes[$_GET['msg']]);
    $mensajeTipo = ($_GET['msg'] === 'enviado_parcial') ? 'warning' : 'success';
}

// ---------------------------------------------------------------- filtros y datos
$tab = ($_GET['tab'] ?? '') === 'notificaciones' ? 'notificaciones' : 'cambios';
$planId = (int) ($_GET['plan'] ?? 0);
$planSel = $planId ? cengi_pp_plan($db, $planId) : null;
$anio = (string) ($_GET['anio'] ?? ($planSel ? $planSel['anio'] : cengi_pp_anio_actual()));
if ($anio !== 'todos' && !ctype_digit($anio)) {
    $anio = (string) cengi_pp_anio_actual();
}
$tipoFiltro = trim((string) ($_GET['tipo'] ?? ''));
$notifFiltro = array_key_exists($_GET['notif'] ?? '', CENGI_NOTIF_ESTADOS) ? $_GET['notif'] : '';
$busqueda = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($anio !== 'todos') {
    $where[] = 'p.anio = ?';
    $params[] = (int) $anio;
}
if ($planSel) {
    $where[] = 'c.planificacion_id = ?';
    $params[] = $planSel['id'];
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$stmt = $db->prepare("SELECT c.*, p.nombre AS plan_nombre, p.anio, cu.codigo_curso, co.enviado_en AS notif_en, co.enviados AS notif_destinatarios
    FROM planificacion_cambios c
    INNER JOIN planificacion_cursos p ON p.id = c.planificacion_id
    LEFT JOIN cursos cu ON cu.id = p.curso_id
    LEFT JOIN comunicaciones co ON co.id = c.comunicacion_id
    $whereSql ORDER BY c.creado DESC, c.id DESC");
$stmt->execute($params);
$base = $stmt->fetchAll(PDO::FETCH_ASSOC);
$tipos = array_values(array_unique(array_column($base, 'tipo')));
sort($tipos);

$cambios = array_values(array_filter($base, static function ($c) use ($tipoFiltro, $notifFiltro, $busqueda) {
    if (($tipoFiltro !== '' && $c['tipo'] !== $tipoFiltro) || ($notifFiltro !== '' && $c['notificacion'] !== $notifFiltro)) {
        return false;
    }
    return $busqueda === '' || strpos(cengi_texto_normalizado($c['detalle'] . ' ' . $c['motivo'] . ' ' . $c['antes'] . ' ' . $c['despues'] . ' ' . $c['plan_nombre']), cengi_texto_normalizado($busqueda)) !== false;
}));

if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Fecha y hora', 'Año', 'Código', 'Curso/Diplomado', 'Tipo de cambio', 'Detalle', 'Antes', 'Después', 'Motivo', 'Usuario', 'Afecta a participantes', 'Notificación']];
    foreach ($cambios as $c) {
        $filas[] = [$c['creado'], cengi_pp_anio_texto($c['anio']), $c['codigo_curso'], $c['plan_nombre'], $c['tipo'], $c['detalle'], $c['antes'], $c['despues'], $c['motivo'],
            $c['usuario_nombre'], $c['afecta'] ? 'Sí' : 'No', CENGI_NOTIF_ESTADOS[$c['notificacion']] . ($c['notif_en'] ? ' ' . $c['notif_en'] : '')];
    }
    cengi_pp_csv('Bitacora_de_cambios', $filas);
}

$stmt = $db->prepare("SELECT co.*, p.nombre AS plan_nombre FROM comunicaciones co INNER JOIN planificacion_cursos p ON p.id = co.planificacion_id
    WHERE co.origen = 'cambios'" . ($anio !== 'todos' ? ' AND p.anio = ' . (int) $anio : '') . ($planSel ? ' AND p.id = ' . (int) $planSel['id'] : '') . ' ORDER BY co.creado DESC LIMIT 200');
$stmt->execute();
$notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$destPorCom = [];
if ($notificaciones) {
    $ids = implode(',', array_map('intval', array_column($notificaciones, 'id')));
    foreach ($db->query("SELECT comunicacion_id, nombre, correo, estado, error FROM comunicacion_destinatarios WHERE comunicacion_id IN ($ids) ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $destPorCom[$d['comunicacion_id']][] = $d;
    }
}

$pendientesBase = array_filter($base, static function ($c) {
    return $c['notificacion'] === 'pendiente';
});
$planesPendientes = array_unique(array_column($pendientesBase, 'planificacion_id'));
$personasPendientes = 0;
foreach ($planesPendientes as $pid) {
    $personasPendientes += count(cengi_pp_inscritos($db, cengi_pp_plan($db, $pid)));
}

// Notificacion de un plan: cambios pendientes, inscritos y mensaje sugerido.
$notificar = null;
if ($planSel) {
    $pendientesPlan = array_values(array_filter($base, static function ($c) {
        return $c['notificacion'] === 'pendiente';
    }));
    $inscritosPlan = cengi_pp_inscritos($db, $planSel);
    $notificar = [
        'plan' => $planSel, 'pendientes' => $pendientesPlan, 'inscritos' => $inscritosPlan, 'vigente' => cengi_pp_programacion_vigente($db, $planSel),
        'asunto' => ($planSel['estado'] === 'cancelado' ? 'Cancelación' : (array_filter($pendientesPlan, static function ($c) {
            return $c['tipo'] === 'Reprogramación';
        }) ? 'Reprogramación' : 'Actualización')) . ': ' . $planSel['nombre'],
    ];
}
$abrirNotificar = $notificar && !empty($_GET['notificar']) && $notificar['pendientes'];
$anios = cengi_pp_anios_disponibles($db, $anio === 'todos' ? cengi_pp_anio_actual() : (int) $anio);
$gruposPlanes = cengi_pp_planes_opciones($db, true);
$filtrosActivos = array_filter(['anio' => $anio, 'plan' => $planSel['id'] ?? '', 'tipo' => $tipoFiltro, 'notif' => $notifFiltro, 'q' => $busqueda], static function ($x) {
    return $x !== '' && $x !== null;
});

function cengi_bit_badge(array $c)
{
    switch ($c['notificacion']) {
        case 'pendiente':
            return '<span class="cengi-status-badge is-pending"><i></i>Pendiente</span> <a class="pp-link" href="bitacora_cambios.php?plan=' . (int) $c['planificacion_id'] . '&notificar=1">Notificar</a>';
        case 'enviada':
            return '<span class="cengi-status-badge is-active"><i></i>Notificado</span><span class="pp-sub">' . (int) $c['notif_destinatarios'] . ' pers. · ' . cengi_pp_fecha($c['notif_en']) . '</span>';
        case 'omitida':
            return '<span class="cengi-status-badge is-neutral"><i></i>No notificado</span>';
        case 'sin inscritos':
            return '<span class="pp-sub" title="Quien se inscriba después recibe la programación vigente">Sin inscritos aún</span>';
        default:
            return '<span class="pp-sub">No aplica</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Bitácora de cambios'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_pp_subnav('bitacora_cambios.php'); ?>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <div class="cengi-feedback is-warning" style="margin-bottom:16px;"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-info-sign"></span></div>
        <div><p>Cada cambio hecho en Planificación o en Programa de sesiones queda registrado con fecha, hora, usuario, valor anterior y nuevo. Los cambios que afectan a los participantes (fechas, horario, lugar, modalidad, instructor o cancelación) quedan <b>pendientes de notificar</b> hasta que se envíe el aviso a los inscritos.</p></div></div>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi(count($base), 'Cambios registrados', count(array_filter($base, static function ($c) {
            return $c['afecta'];
        })) . ' afectan a participantes', '#3E7A12', 'time');
        echo cengi_pp_kpi(count($pendientesBase), 'Pendientes de notificar', $pendientesBase ? $personasPendientes . ' participante(s) sin avisar' : 'Todo comunicado', $pendientesBase ? '#B34E00' : '#5B6459', 'bell', $pendientesBase ? '#FFE9D9' : '#EDEFEA');
        echo cengi_pp_kpi(array_sum(array_column($notificaciones, 'enviados')), 'Avisos enviados', count($notificaciones) . ' notificación(es)', '#1f5632', 'envelope');
        echo cengi_pp_kpi(count(array_filter($base, static function ($c) {
            return $c['tipo'] === 'Reprogramación';
        })), 'Reprogramaciones', count(array_filter($base, static function ($c) {
            return $c['tipo'] === 'Curso cancelado';
        })) . ' cancelación(es)', '#8A6600', 'calendar', '#FFF6DA');
        ?>
    </div>

    <?php if ($notificar): $p = $notificar['plan']; ?>
        <section class="pp-card">
            <div class="pp-card-head">
                <div><h3>Historial: <?php echo cengi_pp_h($p['nombre']); ?></h3>
                    <span class="pp-sub"><?php echo $p['codigo_curso'] ? '<span class="pp-mono">' . cengi_pp_h($p['codigo_curso']) . '</span> · ' : ''; ?>Año <?php echo cengi_pp_anio_texto($p['anio']); ?> · <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $p['estado']); ?></span></div>
                <div class="pp-toolbar-group">
                    <a class="btn btn-default btn-sm" href="programa_sesiones.php?ver=<?php echo (int) $p['id']; ?>">Programa de sesiones</a>
                    <?php if ($notificar['pendientes']): ?><button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#notificar-modal">Notificar <?php echo count($notificar['pendientes']); ?> cambio(s)</button><?php endif; ?>
                    <a class="btn btn-default btn-sm" href="bitacora_cambios.php?anio=<?php echo (int) $p['anio']; ?>">Ver todos los cursos</a>
                </div>
            </div>
            <div class="pp-card-body pp-two-col">
                <div><h5 style="font-weight:700;margin-top:0;">Programación vigente</h5><div class="pp-msgbox"><?php echo cengi_pp_h($notificar['vigente']); ?></div></div>
                <div><h5 style="font-weight:700;margin-top:0;">Inscritos (<?php echo count($notificar['inscritos']); ?>)</h5>
                    <?php foreach (array_slice($notificar['inscritos'], 0, 8) as $i): ?><div class="pp-mini-row"><span><?php echo cengi_pp_h($i['nombre']); ?></span><span class="pp-sub"><?php echo cengi_pp_h($i['correo']); ?></span></div><?php endforeach; ?>
                    <?php if (count($notificar['inscritos']) > 8): ?><span class="pp-sub">y <?php echo count($notificar['inscritos']) - 8; ?> más…</span><?php endif; ?>
                    <?php if (!$notificar['inscritos']): ?><span class="pp-sub"><?php echo $p['curso_id'] ? 'Nadie se ha inscrito todavía.' : 'El plan aún no está aprobado: no hay curso ni inscritos.'; ?></span><?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="pp-card">
        <nav class="pp-tabs">
            <a href="bitacora_cambios.php?<?php echo cengi_pp_h(http_build_query($filtrosActivos)); ?>" class="<?php echo $tab === 'cambios' ? 'is-active' : ''; ?>">Cambios</a>
            <a href="bitacora_cambios.php?<?php echo cengi_pp_h(http_build_query($filtrosActivos + ['tab' => 'notificaciones'])); ?>" class="<?php echo $tab === 'notificaciones' ? 'is-active' : ''; ?>">Notificaciones enviadas <span class="pp-chip"><?php echo count($notificaciones); ?></span></a>
        </nav>
        <form class="pp-toolbar" method="get">
            <input type="hidden" name="tab" value="<?php echo $tab; ?>">
            <div class="pp-toolbar-group">
                <select name="anio" aria-label="Año" onchange="this.form.plan.value='';this.form.submit()">
                    <option value="todos" <?php echo $anio === 'todos' ? 'selected' : ''; ?>>Todos los años</option>
                    <?php foreach ($anios as $z): ?><option value="<?php echo $z; ?>" <?php echo (string) $z === $anio ? 'selected' : ''; ?>>Año <?php echo cengi_pp_anio_texto($z); ?></option><?php endforeach; ?>
                </select>
                <select name="plan" aria-label="Curso" onchange="this.form.submit()">
                    <option value="">Todos los cursos</option>
                    <?php echo cengi_pp_options_planes($anio === 'todos' ? $gruposPlanes : array_intersect_key($gruposPlanes, [(int) $anio => 1]), (string) ($planSel['id'] ?? '')); ?>
                </select>
                <?php if ($tab === 'cambios'): ?>
                    <select name="tipo" aria-label="Tipo de cambio" onchange="this.form.submit()">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($tipos as $t): ?><option <?php echo $tipoFiltro === $t ? 'selected' : ''; ?>><?php echo cengi_pp_h($t); ?></option><?php endforeach; ?>
                    </select>
                    <select name="notif" aria-label="Notificación" onchange="this.form.submit()">
                        <option value="">Toda notificación</option>
                        <?php foreach (CENGI_NOTIF_ESTADOS as $clave => $texto): ?><option value="<?php echo $clave; ?>" <?php echo $notifFiltro === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                    </select>
                    <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Buscar en el detalle o motivo..." aria-label="Buscar">
                <?php endif; ?>
            </div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="bitacora_cambios.php?<?php echo cengi_pp_h(http_build_query($filtrosActivos + ['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar bitácora</a>
            </div>
        </form>
    </section>

    <section class="cengi-courses-section">
        <div class="pp-table-wrap">
            <?php if ($tab === 'cambios'): ?>
                <table class="cengi-org-table">
                    <thead><tr><th>Fecha y hora</th><th>Curso / Diplomado</th><th>Tipo de cambio</th><th>Antes → después</th><th>Motivo</th><th>Usuario</th><th>Notificación</th></tr></thead>
                    <tbody>
                        <?php if (!$cambios): ?><tr><td colspan="7" class="pp-empty">No hay cambios con esos filtros.</td></tr><?php endif; ?>
                        <?php foreach ($cambios as $c): ?>
                            <tr>
                                <td class="pp-num"><?php echo date('d/m/Y · H:i', strtotime($c['creado'])); ?></td>
                                <td style="min-width:170px;"><strong><a href="bitacora_cambios.php?plan=<?php echo (int) $c['planificacion_id']; ?>"><?php echo cengi_pp_h($c['plan_nombre']); ?></a></strong>
                                    <span class="pp-sub"><?php echo $c['codigo_curso'] ? '<span class="pp-mono">' . cengi_pp_h($c['codigo_curso']) . '</span> · ' : ''; ?>Año <?php echo cengi_pp_anio_texto($c['anio']); ?></span></td>
                                <td><span class="pp-chip<?php echo $c['afecta'] ? ' is-warn' : ''; ?>"><?php echo cengi_pp_h($c['tipo']); ?></span></td>
                                <td style="min-width:230px;"><div class="pp-diff"><?php echo cengi_pp_h($c['detalle']); ?>
                                    <?php if ($c['antes'] !== '' || $c['despues'] !== ''): ?><br><s><?php echo cengi_pp_h($c['antes'] ?: '—'); ?></s> → <b><?php echo cengi_pp_h($c['despues'] ?: '—'); ?></b><?php endif; ?></div></td>
                                <td style="min-width:150px;font-size:12px;"><?php echo $c['motivo'] !== '' ? cengi_pp_h($c['motivo']) : '<span class="pp-sub">—</span>'; ?></td>
                                <td style="font-size:12px;"><?php echo cengi_pp_h($c['usuario_nombre']); ?></td>
                                <td style="min-width:140px;"><?php echo cengi_bit_badge($c); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="cengi-org-table">
                    <thead><tr><th>Fecha y hora</th><th>Curso / Diplomado</th><th>Asunto</th><th>Destinatarios</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        <?php if (!$notificaciones): ?><tr><td colspan="6" class="pp-empty">Aún no se han enviado avisos de cambios.</td></tr><?php endif; ?>
                        <?php foreach ($notificaciones as $n): ?>
                            <tr>
                                <td class="pp-num"><?php echo date('d/m/Y · H:i', strtotime($n['enviado_en'] ?: $n['creado'])); ?></td>
                                <td><strong><?php echo cengi_pp_h($n['plan_nombre']); ?></strong></td>
                                <td style="min-width:220px;"><?php echo cengi_pp_h($n['asunto']); ?></td>
                                <td><?php echo (int) $n['enviados']; ?> enviado(s)<?php echo $n['fallidos'] ? '<span class="pp-sub" style="color:#B23223;">' . (int) $n['fallidos'] . ' fallido(s)</span>' : ''; ?></td>
                                <td><?php echo cengi_pp_badge(['enviada' => ['Enviada', 'is-active'], 'fallida' => ['Fallida', 'is-rejected']], $n['estado']); ?></td>
                                <td><button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ver-modal"
                                    data-com="<?php echo cengi_pp_h(json_encode(['asunto' => $n['asunto'], 'mensaje' => $n['mensaje'], 'sub' => date('d/m/Y H:i', strtotime($n['enviado_en'] ?: $n['creado'])) . ' · ' . $n['plan_nombre'], 'dest' => $destPorCom[$n['id']] ?? []], JSON_UNESCAPED_UNICODE)); ?>">Ver mensaje</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php if ($notificar && $notificar['pendientes']): ?>
<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="notificar-modal" tabindex="-1" role="dialog" aria-labelledby="notificar-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="notificar-form">
            <input type="hidden" name="plan_id" value="<?php echo (int) $notificar['plan']['id']; ?>">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Notificar cambios</span>
                <h4 class="modal-title" id="notificar-title"><?php echo cengi_pp_h($notificar['plan']['nombre']); ?></h4>
                <p><?php echo count($notificar['inscritos']); ?> inscrito(s) · se envía un correo personalizado a cada persona</p>
            </div>
            <div class="modal-body">
                <div class="pp-two-col" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label>Cambios a comunicar</label>
                        <div class="pp-check-list" id="nt-cambios">
                            <?php foreach ($notificar['pendientes'] as $c): ?>
                                <label><input type="checkbox" name="cambios[]" value="<?php echo (int) $c['id']; ?>" checked>
                                    <span><b><?php echo cengi_pp_h($c['tipo']); ?></b> · <?php echo date('d/m H:i', strtotime($c['creado'])); ?><br><span class="pp-sub"><?php echo cengi_pp_h($c['detalle'] . ($c['antes'] !== '' || $c['despues'] !== '' ? ': ' . $c['antes'] . ' → ' . $c['despues'] : '')); ?></span></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group"><label>Destinatarios</label>
                        <div class="pp-check-list" id="nt-dest">
                            <?php foreach ($notificar['inscritos'] as $i): ?>
                                <label><input type="checkbox" name="destinatarios[]" value="<?php echo cengi_pp_h($i['clave']); ?>" <?php echo filter_var($i['correo'], FILTER_VALIDATE_EMAIL) ? 'checked' : 'disabled'; ?>>
                                    <span><?php echo cengi_pp_h($i['nombre']); ?><br><span class="pp-sub"><?php echo cengi_pp_h(($i['correo'] ?: 'Sin correo registrado') . ($i['asignado'] ? '' : ' · inscrito, aún sin asignar')); ?></span></span></label>
                            <?php endforeach; ?>
                            <?php if (!$notificar['inscritos']): ?><label><span class="pp-sub">No hay inscritos.</span></label><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label for="nt-asunto">Asunto</label><input type="text" class="form-control" id="nt-asunto" name="asunto" maxlength="255" value="<?php echo cengi_pp_h($notificar['asunto']); ?>" required></div>
                <div class="form-group"><label for="nt-mensaje">Mensaje <small>({nombre} se reemplaza por el nombre de cada persona)</small></label><textarea class="form-control" id="nt-mensaje" name="mensaje" rows="10" required></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-link" name="accion" value="omitir" formnovalidate onclick="return confirm('Estos cambios quedarán marcados como “No notificado” en la bitácora. ¿Continuar?');">No notificar</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">Más tarde</button>
                <button type="submit" class="btn btn-primary" name="accion" value="notificar" id="nt-btn">Enviar</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-course-form-modal pp-modal" id="ver-modal" tabindex="-1" role="dialog" aria-labelledby="ver-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            <h4 class="modal-title" id="ver-title"></h4><p id="ver-sub"></p>
        </div>
        <div class="modal-body"><h5 style="font-weight:700;">Mensaje</h5><div class="pp-msgbox" id="ver-msg"></div><h5 style="font-weight:700;margin-top:16px;" id="ver-dest-t"></h5><div id="ver-dest"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    $('#ver-modal').on('show.bs.modal', function (e) {
        var c = JSON.parse(e.relatedTarget.getAttribute('data-com'));
        document.getElementById('ver-title').textContent = c.asunto;
        document.getElementById('ver-sub').textContent = c.sub;
        var primero = c.dest[0] ? c.dest[0].nombre : 'participante';
        document.getElementById('ver-msg').textContent = c.mensaje.replace(/\{nombre\}/g, primero);
        document.getElementById('ver-dest-t').textContent = 'Destinatarios (' + c.dest.length + ')';
        document.getElementById('ver-dest').innerHTML = c.dest.map(function (d) {
            return '<div class="pp-mini-row"><span>' + esc(d.nombre) + '<span class="pp-sub">' + esc(d.correo) + '</span></span><span class="pp-chip ' + (d.estado === 'enviado' ? 'is-ok' : 'is-danger') + '" title="' + esc(d.error) + '">' + esc(d.estado) + '</span></div>';
        }).join('');
    });

    <?php if ($notificar && $notificar['pendientes']): ?>
    var PLAN = <?php echo json_encode(['nombre' => $notificar['plan']['nombre'], 'codigo' => $notificar['plan']['codigo_curso'], 'cancelado' => $notificar['plan']['estado'] === 'cancelado', 'vigente' => $notificar['vigente']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var CAMBIOS = <?php echo json_encode(array_column(array_map(static function ($c) {
        return ['id' => (int) $c['id'], 'texto' => '• ' . $c['tipo'] . ' — ' . $c['detalle'] . ($c['antes'] !== '' || $c['despues'] !== '' ? ': ' . ($c['antes'] ?: '—') . ' → ' . ($c['despues'] ?: '—') : '') . '.' . ($c['motivo'] !== '' ? "\n  Motivo: " . $c['motivo'] : '')];
    }, array_reverse($notificar['pendientes'])), 'texto', 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var FIRMA = '\n\nCoordinación de Capacitación — CENGICAÑA';
    function regenerar() {
        var sel = [].filter.call(document.querySelectorAll('#nt-cambios input'), function (c) { return c.checked; }).map(function (c) { return CAMBIOS[c.value]; });
        document.getElementById('nt-mensaje').value = sel.length ? 'Hola {nombre}:\n\nTe informamos ' + (sel.length > 1 ? 'los siguientes cambios' : 'un cambio') + ' en ' + PLAN.nombre + (PLAN.codigo ? ' (' + PLAN.codigo + ')' : '') + ':\n' + sel.join('\n') + '\n\n' +
            (PLAN.cancelado ? 'Si realizaste un pago, se reintegrará en los próximos 10 días hábiles.' : 'Programación vigente:\n' + PLAN.vigente) + FIRMA : '';
    }
    function contar() {
        var n = document.querySelectorAll('#nt-dest input:checked').length;
        document.getElementById('nt-btn').textContent = n ? 'Enviar a ' + n + ' participante(s)' : 'Enviar';
        document.getElementById('nt-btn').disabled = !n;
    }
    document.querySelectorAll('#nt-cambios input').forEach(function (c) { c.addEventListener('change', regenerar); });
    document.querySelectorAll('#nt-dest input').forEach(function (c) { c.addEventListener('change', contar); });
    regenerar(); contar();
    document.getElementById('notificar-form').addEventListener('submit', function () { var b = document.getElementById('nt-btn'); setTimeout(function () { b.disabled = true; b.textContent = 'Enviando…'; }, 0); });
    <?php if ($abrirNotificar): ?>$('#notificar-modal').modal('show');<?php endif; ?>
    <?php endif; ?>
})();
</script>
</body>
</html>
