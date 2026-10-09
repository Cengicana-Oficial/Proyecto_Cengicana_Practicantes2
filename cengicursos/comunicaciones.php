<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';
$reabrir = null;

/** Destinatarios de una comunicacion manual segun la audiencia elegida. */
function cengi_com_audiencia(PDO $db, $audiencia, $planId, $manual)
{
    if ($audiencia === 'curso' || $audiencia === 'instructores_curso') {
        $plan = cengi_pp_plan($db, $planId);
        if (!$plan) {
            throw new InvalidArgumentException('Selecciona el curso.');
        }
        if ($audiencia === 'curso') {
            return [cengi_pp_inscritos($db, $plan), $plan];
        }
        $stmt = $db->prepare("SELECT DISTINCT i.nombre, i.correo FROM instructores i
            WHERE i.id IN (SELECT instructor_id FROM curso_sesiones WHERE planificacion_id = ? AND estado <> 'cancelada') OR i.id = ?");
        $stmt->execute([$plan['id'], (int) $plan['instructor_id']]);
        return [$stmt->fetchAll(PDO::FETCH_ASSOC), $plan];
    }
    if ($audiencia === 'instructores') {
        return [$db->query("SELECT nombre, correo FROM instructores WHERE estado = 1 AND correo <> ''")->fetchAll(PDO::FETCH_ASSOC), null];
    }
    if ($audiencia === 'soporte') {
        return [$db->query("SELECT nombre, correo FROM soporte_tecnico WHERE estado = 1 AND correo <> ''")->fetchAll(PDO::FETCH_ASSOC), null];
    }
    $destinatarios = [];
    foreach (preg_split('/[\s,;]+/', (string) $manual, -1, PREG_SPLIT_NO_EMPTY) as $correo) {
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Correo no válido: ' . cengi_pp_h($correo));
        }
        $destinatarios[] = ['nombre' => '', 'correo' => $correo];
    }
    return [$destinatarios, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $codigo = '';
    try {
        if ($accion === 'ejecutar') {
            $r = cengi_pp_ejecutar_pendientes($db, cengi_pp_usuario_nombre());
            $mensaje = cengi_pp_h('Se procesaron ' . $r['recordatorios'] . ' recordatorio(s) y ' . $r['programadas'] . ' envío(s) programado(s): '
                . $r['enviados'] . ' correo(s) enviados' . ($r['fallidos'] ? ', ' . $r['fallidos'] . ' fallidos' : '') . '.');
            $mensajeTipo = $r['fallidos'] ? 'warning' : 'success';
        } elseif ($accion === 'nueva') {
            $asunto = trim((string) ($_POST['asunto'] ?? ''));
            $texto = trim((string) ($_POST['mensaje'] ?? ''));
            $cuando = ($_POST['cuando'] ?? 'ahora') === 'programar' ? str_replace('T', ' ', (string) ($_POST['programada_para'] ?? '')) : null;
            if ($asunto === '' || $texto === '') {
                throw new InvalidArgumentException('Escribe el asunto y el mensaje.');
            }
            if ($cuando !== null && (strtotime($cuando) === false || strtotime($cuando) <= time())) {
                throw new InvalidArgumentException('La fecha programada debe ser futura.');
            }
            [$destinatarios, $plan] = cengi_com_audiencia($db, (string) ($_POST['audiencia'] ?? ''), (int) ($_POST['plan_id'] ?? 0), $_POST['manual'] ?? '');
            if (!$destinatarios) {
                throw new InvalidArgumentException('La audiencia elegida no tiene destinatarios con correo.');
            }
            [, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, [
                'categoria' => 'Comunicación manual', 'origen' => 'manual', 'planificacion_id' => $plan['id'] ?? null,
                'asunto' => $asunto, 'mensaje' => $texto, 'variables' => $plan ? cengi_pp_variables($plan) : [],
                'programada_para' => $cuando ? date('Y-m-d H:i:00', strtotime($cuando)) : null,
            ], $destinatarios);
            if ($cuando) {
                $codigo = 'programada';
            } elseif ($enviados === 0) {
                throw new RuntimeException('Ningún correo se pudo enviar (' . $fallidos . ' fallidos). Revisa la configuración de correo; el detalle quedó en Enviadas.');
            } else {
                $codigo = $fallidos ? 'parcial' : 'enviada';
            }
        } elseif ($accion === 'regla') {
            $clave = (string) ($_POST['clave'] ?? '');
            $reglas = cengi_pp_reglas($db);
            if (!isset($reglas[$clave])) {
                throw new InvalidArgumentException('Regla no válida.');
            }
            if (isset($_POST['solo_activo'])) {
                $db->prepare('UPDATE comunicacion_reglas SET activo = ? WHERE clave = ?')->execute([!empty($_POST['activo']) ? 1 : 0, $clave]);
            } else {
                $asunto = trim((string) ($_POST['asunto'] ?? ''));
                $texto = trim((string) ($_POST['mensaje'] ?? ''));
                $dias = max(0, min(30, (int) ($_POST['dias_antes'] ?? 1)));
                if ($asunto === '' || $texto === '') {
                    $reabrir = ['clave' => $clave, 'asunto' => $asunto, 'mensaje' => $texto, 'dias_antes' => $dias];
                    throw new InvalidArgumentException('Escribe el asunto y el mensaje de la plantilla.');
                }
                $db->prepare('UPDATE comunicacion_reglas SET asunto = ?, mensaje = ?, dias_antes = ? WHERE clave = ?')->execute([$asunto, $texto, $dias, $clave]);
            }
            $codigo = 'regla';
        } elseif ($accion === 'recordatorio') {
            // Enviar ahora u omitir un recordatorio de la agenda.
            $regla = (string) ($_POST['regla'] ?? '');
            $referencia = (string) ($_POST['referencia'] ?? '');
            $item = null;
            foreach (cengi_pp_recordatorios($db, cengi_pp_sumar_dias($hoy, -7), cengi_pp_sumar_dias($hoy, 14)) as $r) {
                if ($r['regla'] === $regla && $r['referencia'] === $referencia && !$r['hecho']) {
                    $item = $r;
                    break;
                }
            }
            if (!$item) {
                throw new InvalidArgumentException('Ese recordatorio ya se envió o ya no aplica.');
            }
            $omitir = ($_POST['modo'] ?? '') === 'omitir';
            $datos = ['categoria' => 'Recordatorio automático', 'origen' => 'recordatorio', 'regla' => $regla, 'referencia' => $referencia,
                'planificacion_id' => $item['plan_id'], 'asunto' => $item['asunto'], 'mensaje' => $item['mensaje'], 'variables' => $item['variables']];
            if ($omitir) {
                // Se registra como cancelado para que el cron no lo envie.
                $db->prepare("INSERT INTO comunicaciones (categoria, origen, regla, referencia, planificacion_id, asunto, mensaje, estado, creado_por_nombre) VALUES (?, 'recordatorio', ?, ?, ?, ?, ?, 'cancelada', ?)")
                    ->execute(['Recordatorio automático', $regla, $referencia, $item['plan_id'], cengi_pp_llenar($item['asunto'], $item['variables']), cengi_pp_llenar($item['mensaje'], $item['variables']), cengi_pp_usuario_nombre()]);
                $codigo = 'omitido';
            } else {
                if (!$item['destinatarios']) {
                    throw new InvalidArgumentException('Este recordatorio no tiene destinatarios.');
                }
                [, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, $datos, $item['destinatarios']);
                $codigo = $fallidos ? 'parcial' : 'enviada';
            }
        } elseif ($accion === 'cancelar_programada') {
            $db->prepare("UPDATE comunicaciones SET estado = 'cancelada' WHERE id = ? AND estado = 'programada'")->execute([(int) ($_POST['id'] ?? 0)]);
            $codigo = 'cancelada';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        if ($codigo !== '') {
            $_GET = array_diff_key($_GET, array_flip(['msg']));
            cengi_pp_redirigir('comunicaciones.php', ['msg' => $codigo]);
        }
    } catch (Throwable $e) {
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException && !$e instanceof PDOException) {
            $mensaje = $e instanceof InvalidArgumentException ? $e->getMessage() : cengi_pp_h($e->getMessage());
        } else {
            error_log('Comunicaciones: ' . $e->getMessage());
            $mensaje = (string) $e->getCode() === '23000' ? 'Ese recordatorio ya se registró.' : 'No fue posible completar la acción.';
        }
        if ($accion === 'nueva') {
            $reabrir = ['nueva' => array_intersect_key($_POST, array_flip(['audiencia', 'plan_id', 'manual', 'asunto', 'mensaje', 'cuando', 'programada_para']))];
        }
    }
}

$mensajes = [
    'enviada' => 'Comunicación enviada.',
    'parcial' => 'Comunicación enviada, pero algunos correos fallaron: revisa el detalle.',
    'programada' => 'Comunicación programada: se enviará en la fecha indicada.',
    'regla' => 'Regla de recordatorio actualizada.',
    'omitido' => 'Recordatorio omitido: no se enviará.',
    'cancelada' => 'Envío programado cancelado.',
];
if ($mensaje === '' && isset($mensajes[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h($mensajes[$_GET['msg']]);
    $mensajeTipo = $_GET['msg'] === 'parcial' ? 'warning' : 'success';
}

// ---------------------------------------------------------------- datos
$tab = in_array($_GET['tab'] ?? '', ['programados', 'reglas'], true) ? $_GET['tab'] : 'enviadas';
$busqueda = trim((string) ($_GET['q'] ?? ''));
$categoriaFiltro = trim((string) ($_GET['categoria'] ?? ''));

$enviadas = $db->query("SELECT co.*, p.nombre AS plan_nombre FROM comunicaciones co LEFT JOIN planificacion_cursos p ON p.id = co.planificacion_id
    WHERE co.estado IN ('enviada', 'fallida', 'enviando') ORDER BY COALESCE(co.enviado_en, co.creado) DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
$categorias = array_values(array_unique(array_column($enviadas, 'categoria')));
sort($categorias);
$destPorCom = [];
if ($enviadas) {
    $ids = implode(',', array_map('intval', array_column($enviadas, 'id')));
    foreach ($db->query("SELECT comunicacion_id, nombre, correo, estado, error FROM comunicacion_destinatarios WHERE comunicacion_id IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $destPorCom[$d['comunicacion_id']][] = $d;
    }
}
$lista = array_values(array_filter($enviadas, static function ($n) use ($busqueda, $categoriaFiltro, $destPorCom) {
    if ($categoriaFiltro !== '' && $n['categoria'] !== $categoriaFiltro) {
        return false;
    }
    if ($busqueda === '') {
        return true;
    }
    $texto = $n['asunto'] . ' ' . $n['plan_nombre'] . ' ' . implode(' ', array_map(static function ($d) {
        return $d['nombre'] . ' ' . $d['correo'];
    }, $destPorCom[$n['id']] ?? []));
    return strpos(cengi_texto_normalizado($texto), cengi_texto_normalizado($busqueda)) !== false;
}));

if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Fecha', 'Categoría', 'Asunto', 'Actividad', 'Destinatario', 'Correo', 'Estado', 'Error']];
    foreach ($lista as $n) {
        foreach ($destPorCom[$n['id']] ?? [] as $d) {
            $filas[] = [$n['enviado_en'] ?: $n['creado'], $n['categoria'], $n['asunto'], $n['plan_nombre'], $d['nombre'], $d['correo'], $d['estado'], $d['error']];
        }
    }
    cengi_pp_csv('Comunicaciones', $filas);
}

$agenda = cengi_pp_recordatorios($db, $hoy, cengi_pp_sumar_dias($hoy, 14));
$programadasManual = $db->query("SELECT co.*, p.nombre AS plan_nombre, (SELECT COUNT(*) FROM comunicacion_destinatarios d WHERE d.comunicacion_id = co.id) AS total_dest
    FROM comunicaciones co LEFT JOIN planificacion_cursos p ON p.id = co.planificacion_id
    WHERE co.estado = 'programada' ORDER BY co.programada_para")->fetchAll(PDO::FETCH_ASSOC);
$paraHoy = array_filter(cengi_pp_recordatorios($db, cengi_pp_sumar_dias($hoy, -7), $hoy), static function ($x) {
    return !$x['hecho'] && $x['destinatarios'];
});
$vencidasManual = array_filter($programadasManual, static function ($c) {
    return strtotime($c['programada_para']) <= time();
});
$mesActual = array_filter($enviadas, static function ($n) {
    return substr((string) ($n['enviado_en'] ?: $n['creado']), 0, 7) === date('Y-m');
});
$pendientesAgenda = array_filter($agenda, static function ($x) {
    return !$x['hecho'];
});
$reglas = cengi_pp_reglas($db);
$planesAprobados = $db->query("SELECT p.id, p.anio, p.nombre, p.estado, p.fecha_inicio, p.fecha_fin, c.codigo_curso FROM planificacion_cursos p
    LEFT JOIN cursos c ON c.id = p.curso_id WHERE p.estado IN ('aprobado', 'reprogramado') ORDER BY p.anio DESC, p.fecha_inicio")->fetchAll(PDO::FETCH_ASSOC);
$gruposAprobados = [];
foreach ($planesAprobados as $p) {
    $gruposAprobados[(int) $p['anio']][] = $p;
}

$agendaPorDia = [];
foreach ($agenda as $item) {
    $agendaPorDia[$item['fecha_envio']][] = ['tipo' => 'regla', 'item' => $item];
}
foreach ($programadasManual as $c) {
    $agendaPorDia[substr($c['programada_para'], 0, 10)][] = ['tipo' => 'manual', 'item' => $c];
}
ksort($agendaPorDia);
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Comunicaciones'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_pp_subnav('comunicaciones.php'); ?>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi(count($enviadas), 'Comunicaciones enviadas', array_sum(array_column($enviadas, 'enviados')) . ' correo(s) entregados al servidor', '#3E7A12', 'envelope');
        echo cengi_pp_kpi(count($mesActual), 'Este mes', array_sum(array_column($mesActual, 'enviados')) . ' destinatario(s)', '#1f5632', 'calendar');
        echo cengi_pp_kpi(count($paraHoy) + count($vencidasManual), 'Pendientes para hoy', ($paraHoy || $vencidasManual) ? 'Se envían en la ejecución diaria' : 'Al día', ($paraHoy || $vencidasManual) ? '#B34E00' : '#5B6459', 'time', ($paraHoy || $vencidasManual) ? '#FFE9D9' : '#EDEFEA');
        echo cengi_pp_kpi(count($pendientesAgenda) + count($programadasManual), 'Programados (14 días)', array_sum(array_map(static function ($x) {
            return count($x['destinatarios']);
        }, $pendientesAgenda)) . ' destinatario(s) en recordatorios', '#8A6600', 'bell', '#FFF6DA');
        ?>
    </div>

    <?php if ($paraHoy || $vencidasManual): ?>
        <div class="cengi-feedback is-warning"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-bell"></span></div><div>
            <p>Hay <b><?php echo count($paraHoy) + count($vencidasManual); ?></b> envío(s) pendiente(s) para hoy. Se envían solos cada mañana con <code>cron_comunicaciones.php</code>; también puedes enviarlos ahora.</p>
            <form method="post" style="margin-top:6px;"><input type="hidden" name="accion" value="ejecutar"><button type="submit" class="btn btn-primary btn-sm" onclick="this.disabled=true;this.form.submit();">Enviar pendientes de hoy</button></form>
        </div></div>
    <?php endif; ?>

    <section class="pp-card">
        <nav class="pp-tabs">
            <?php foreach (['enviadas' => 'Enviadas', 'programados' => 'Programados (14 días)', 'reglas' => 'Reglas de recordatorios'] as $clave => $texto): ?>
                <a href="comunicaciones.php?tab=<?php echo $clave; ?>" class="<?php echo $tab === $clave ? 'is-active' : ''; ?>"><?php echo $texto; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($tab === 'enviadas'): ?>
            <form class="pp-toolbar" method="get">
                <div class="pp-toolbar-group">
                    <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Buscar asunto, actividad o destinatario..." aria-label="Buscar" style="min-width:300px;">
                    <select name="categoria" aria-label="Categoría" onchange="this.form.submit()">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias as $c): ?><option <?php echo $categoriaFiltro === $c ? 'selected' : ''; ?>><?php echo cengi_pp_h($c); ?></option><?php endforeach; ?>
                    </select>
                    <span class="pp-sub"><?php echo count($lista); ?> comunicación(es)</span>
                </div>
                <div class="pp-toolbar-group">
                    <a class="btn btn-default btn-sm" href="comunicaciones.php?<?php echo cengi_pp_h(http_build_query(array_filter(['q' => $busqueda, 'categoria' => $categoriaFiltro]) + ['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar</a>
                    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#nueva-modal"><span class="glyphicon glyphicon-plus"></span> Nueva comunicación</button>
                </div>
            </form>
        <?php else: ?>
            <div class="pp-toolbar"><span class="pp-sub"><?php echo $tab === 'reglas' ? 'Activa o desactiva cada recordatorio y ajusta cuándo se envía. Los cambios aplican de inmediato a la agenda de programados.' : 'Recordatorios automáticos según las reglas activas y comunicaciones programadas manualmente.'; ?></span>
                <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#nueva-modal"><span class="glyphicon glyphicon-plus"></span> Nueva comunicación</button></div>
        <?php endif; ?>
    </section>

    <?php if ($tab === 'enviadas'): ?>
        <section class="cengi-courses-section">
            <div class="pp-table-wrap">
                <table class="cengi-org-table">
                    <thead><tr><th>Fecha</th><th>Categoría</th><th>Asunto</th><th>Destinatarios</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        <?php if (!$lista): ?><tr><td colspan="6" class="pp-empty">No hay comunicaciones con esos filtros.</td></tr><?php endif; ?>
                        <?php foreach (array_slice($lista, 0, 200) as $n): $dest = $destPorCom[$n['id']] ?? []; ?>
                            <tr>
                                <td class="pp-num"><?php echo date('d/m/Y · H:i', strtotime($n['enviado_en'] ?: $n['creado'])); ?></td>
                                <td><span class="pp-chip"><?php echo cengi_pp_h($n['categoria']); ?></span></td>
                                <td style="min-width:240px;"><strong><?php echo cengi_pp_h($n['asunto'] ?: '(sin asunto)'); ?></strong><span class="pp-sub"><?php echo cengi_pp_h($n['plan_nombre'] ? 'Curso · ' . $n['plan_nombre'] : ($n['origen'] === 'sistema' ? 'Enviado por otro módulo de Cengicursos' : 'General')); ?></span></td>
                                <td style="font-size:12px;"><?php echo count($dest) === 1 ? cengi_pp_h($dest[0]['nombre'] ?: $dest[0]['correo']) . '<span class="pp-sub">' . cengi_pp_h($dest[0]['correo']) . '</span>' : count($dest) . ' destinatarios'; ?></td>
                                <td><?php echo $n['estado'] === 'fallida' ? '<span class="cengi-status-badge is-rejected"><i></i>Fallida</span>' : ($n['fallidos'] ? '<span class="cengi-status-badge is-pending"><i></i>' . (int) $n['fallidos'] . ' fallido(s)</span>' : '<span class="cengi-status-badge is-active"><i></i>Enviada</span>'); ?></td>
                                <td><button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ver-modal" data-com="<?php echo cengi_pp_h(json_encode([
                                    'asunto' => $n['asunto'], 'mensaje' => $n['mensaje'], 'sub' => date('d/m/Y H:i', strtotime($n['enviado_en'] ?: $n['creado'])) . ' · ' . $n['categoria'] . ' · por ' . $n['creado_por_nombre'], 'dest' => $dest,
                                ], JSON_UNESCAPED_UNICODE)); ?>">Ver</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($tab === 'programados'): ?>
        <section class="pp-card"><div class="pp-card-body">
            <?php if (!$agendaPorDia): ?><span class="pp-sub">No hay recordatorios programados en los próximos 14 días con las reglas activas.</span><?php endif; ?>
            <?php foreach ($agendaPorDia as $dia => $items): ?>
                <div class="pp-dia-head"><?php echo ($dia <= $hoy ? 'Hoy · ' : '') . cengi_pp_h(cengi_pp_fecha_larga(max($dia, $hoy))); ?></div>
                <?php foreach ($items as $entrada): $x = $entrada['item']; ?>
                    <div class="pp-mini-row" style="align-items:center;">
                        <?php if ($entrada['tipo'] === 'regla'): ?>
                            <span style="flex:1;min-width:0;"><b><?php echo cengi_pp_h(str_replace('{nombre}', '', cengi_pp_llenar($x['asunto'], $x['variables']))); ?></b>
                                <span class="pp-sub"><?php echo cengi_pp_h($x['regla_nombre'] . ' · ' . $x['actividad']); ?> · <?php echo count($x['destinatarios']); ?> destinatario(s)</span></span>
                            <?php if ($x['hecho']): ?>
                                <span class="cengi-status-badge is-active"><i></i>Procesado</span>
                            <?php elseif (!$x['destinatarios']): ?>
                                <span class="pp-chip">Sin destinatarios</span>
                            <?php else: ?>
                                <span class="cengi-status-badge <?php echo $dia <= $hoy ? 'is-pending' : 'is-upcoming'; ?>"><i></i><?php echo $dia <= $hoy ? 'Hoy' : 'Programado'; ?></span>
                                <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="recordatorio"><input type="hidden" name="regla" value="<?php echo cengi_pp_h($x['regla']); ?>"><input type="hidden" name="referencia" value="<?php echo cengi_pp_h($x['referencia']); ?>">
                                    <button type="submit" name="modo" value="enviar" class="btn btn-default btn-sm" onclick="return confirm('¿Enviar este recordatorio ahora a <?php echo count($x['destinatarios']); ?> destinatario(s)?');">Enviar ahora</button>
                                    <button type="submit" name="modo" value="omitir" class="btn btn-link btn-sm" onclick="return confirm('¿Omitir este envío? No se enviará en la fecha programada.');">Omitir</button></form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="flex:1;min-width:0;"><b><?php echo cengi_pp_h($x['asunto']); ?></b>
                                <span class="pp-sub">Comunicación manual · <?php echo date('H:i', strtotime($x['programada_para'])); ?> · <?php echo cengi_pp_h($x['plan_nombre'] ?: 'General'); ?> · <?php echo (int) $x['total_dest']; ?> destinatario(s)</span></span>
                            <span class="cengi-status-badge is-upcoming"><i></i>Programada</span>
                            <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Cancelar este envío programado?');"><input type="hidden" name="accion" value="cancelar_programada"><input type="hidden" name="id" value="<?php echo (int) $x['id']; ?>"><button type="submit" class="btn btn-link btn-sm">Cancelar</button></form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div></section>
    <?php else: ?>
        <section class="pp-card"><div class="pp-card-body">
            <?php foreach ($reglas as $r): ?>
                <div class="pp-regla">
                    <form method="post" class="cengi-inline-form">
                        <input type="hidden" name="accion" value="regla"><input type="hidden" name="clave" value="<?php echo cengi_pp_h($r['clave']); ?>"><input type="hidden" name="solo_activo" value="1">
                        <label class="pp-switch" title="<?php echo (int) $r['activo'] ? 'Activa' : 'Inactiva'; ?>"><input type="checkbox" name="activo" value="1" <?php echo (int) $r['activo'] ? 'checked' : ''; ?> onchange="this.form.submit()" aria-label="Activar <?php echo cengi_pp_h($r['nombre']); ?>"><span></span></label>
                    </form>
                    <div><b><?php echo cengi_pp_h($r['nombre']); ?></b><span class="pp-sub"><?php echo cengi_pp_h($r['descripcion']); ?></span>
                        <span class="pp-sub">Se envía <b><?php echo (int) $r['dias_antes']; ?> día(s) antes</b> · Asunto: <i><?php echo cengi_pp_h($r['asunto']); ?></i></span></div>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#regla-modal" data-regla="<?php echo cengi_pp_h(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>">Editar mensaje</button>
                </div>
            <?php endforeach; ?>
        </div></section>
    <?php endif; ?>
</main>

<div class="modal fade cengi-course-form-modal pp-modal" id="ver-modal" tabindex="-1" role="dialog" aria-labelledby="ver-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><h4 class="modal-title" id="ver-title"></h4><p id="ver-sub"></p></div>
        <div class="modal-body"><div id="ver-msg-wrap"><h5 style="font-weight:700;">Mensaje</h5><div class="pp-msgbox" id="ver-msg"></div></div><h5 style="font-weight:700;margin-top:16px;" id="ver-dest-t"></h5><div id="ver-dest" style="max-height:240px;overflow-y:auto;"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal" id="regla-modal" tabindex="-1" role="dialog" aria-labelledby="regla-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post">
            <input type="hidden" name="accion" value="regla"><input type="hidden" name="clave" id="rg-clave">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Plantilla de recordatorio</span><h4 class="modal-title" id="regla-title"></h4></div>
            <div class="modal-body">
                <div class="form-group"><label for="rg-dias">Días antes de la fecha</label><input type="number" class="form-control" id="rg-dias" name="dias_antes" min="0" max="30" style="max-width:120px;"></div>
                <div class="form-group"><label for="rg-asunto">Asunto</label><input type="text" class="form-control" id="rg-asunto" name="asunto" maxlength="255" required></div>
                <div class="form-group"><label for="rg-mensaje">Mensaje</label><textarea class="form-control" id="rg-mensaje" name="mensaje" rows="9" required></textarea>
                    <p class="help-block">Variables: {nombre} {curso} {codigo} {fecha} {horario} {modalidad} {tema} {lugar}</p></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar plantilla</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal" id="nueva-modal" tabindex="-1" role="dialog" aria-labelledby="nueva-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="nueva-form" autocomplete="off">
            <input type="hidden" name="accion" value="nueva">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Comunicaciones</span><h4 class="modal-title" id="nueva-title">Nueva comunicación</h4></div>
            <div class="modal-body">
                <div class="pp-form-msg" id="nv-msg"></div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group"><label for="nv-aud">Destinatarios</label>
                        <select class="form-control" id="nv-aud" name="audiencia">
                            <option value="curso">Participantes asignados a un curso</option>
                            <option value="instructores_curso">Instructores de un curso</option>
                            <option value="instructores">Todos los instructores activos</option>
                            <option value="soporte">Personal de soporte técnico</option>
                            <option value="manual">Correos escritos a mano</option>
                        </select></div>
                    <div class="form-group" id="nv-plan-wrap"><label for="nv-plan">Curso</label>
                        <select class="form-control" id="nv-plan" name="plan_id"><?php echo $gruposAprobados ? cengi_pp_options_planes($gruposAprobados, '') : '<option value="">No hay cursos aprobados</option>'; ?></select></div>
                    <div class="form-group is-full" id="nv-manual-wrap" hidden><label for="nv-manual">Correos <small>(separados por coma o salto de línea)</small></label><textarea class="form-control" id="nv-manual" name="manual" rows="2"></textarea></div>
                    <div class="form-group is-full"><label for="nv-asunto">Asunto</label><input type="text" class="form-control" id="nv-asunto" name="asunto" maxlength="255" required></div>
                    <div class="form-group is-full"><label for="nv-mensaje">Mensaje</label><textarea class="form-control" id="nv-mensaje" name="mensaje" rows="8" required>Hola {nombre}:

</textarea>
                        <p class="help-block">Variables: {nombre}; con un curso elegido también {curso} {codigo} {fecha} {horario} {modalidad}.</p></div>
                    <div class="form-group"><label for="nv-cuando">Envío</label><select class="form-control" id="nv-cuando" name="cuando"><option value="ahora">Enviar ahora</option><option value="programar">Programar para una fecha</option></select></div>
                    <div class="form-group" id="nv-fecha-wrap" hidden><label for="nv-fecha">Fecha y hora</label><input type="datetime-local" class="form-control" id="nv-fecha" name="programada_para"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="nv-btn">Enviar</button></div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var REABRIR = <?php echo json_encode($reabrir, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var ERROR = <?php echo json_encode($mensajeTipo === 'error' ? $mensaje : '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    function $id(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    $('#ver-modal').on('show.bs.modal', function (e) {
        var c = JSON.parse(e.relatedTarget.getAttribute('data-com')), primero = c.dest[0] ? (c.dest[0].nombre || 'participante') : 'participante';
        $id('ver-title').textContent = c.asunto; $id('ver-sub').textContent = c.sub;
        $id('ver-msg-wrap').hidden = !c.mensaje;
        $id('ver-msg').textContent = (c.mensaje || '').replace(/\{nombre\}/g, primero);
        $id('ver-dest-t').textContent = 'Destinatarios (' + c.dest.length + ')';
        $id('ver-dest').innerHTML = c.dest.map(function (d) {
            return '<div class="pp-mini-row"><span>' + esc(d.nombre || d.correo) + '<span class="pp-sub">' + esc(d.correo) + (d.error ? ' · ' + esc(d.error) : '') + '</span></span><span class="pp-chip ' + (d.estado === 'enviado' ? 'is-ok' : d.estado === 'fallido' ? 'is-danger' : '') + '">' + esc(d.estado) + '</span></div>';
        }).join('');
    });
    $('#regla-modal').on('show.bs.modal', function (e) {
        var r = JSON.parse(e.relatedTarget.getAttribute('data-regla'));
        $id('regla-title').textContent = r.nombre; $id('rg-clave').value = r.clave; $id('rg-dias').value = r.dias_antes;
        $id('rg-asunto').value = r.asunto; $id('rg-mensaje').value = r.mensaje;
    });

    function audiencia() {
        var v = $id('nv-aud').value;
        $id('nv-plan-wrap').hidden = v !== 'curso' && v !== 'instructores_curso';
        $id('nv-manual-wrap').hidden = v !== 'manual';
    }
    function cuando() {
        var prog = $id('nv-cuando').value === 'programar';
        $id('nv-fecha-wrap').hidden = !prog; $id('nv-fecha').required = prog;
        $id('nv-btn').textContent = prog ? 'Programar' : 'Enviar';
    }
    $id('nv-aud').addEventListener('change', audiencia);
    $id('nv-cuando').addEventListener('change', cuando);
    $id('nueva-form').addEventListener('submit', function () { var b = $id('nv-btn'); setTimeout(function () { b.disabled = true; }, 0); });
    if (REABRIR && REABRIR.nueva) {
        var d = REABRIR.nueva;
        ['aud:audiencia', 'plan:plan_id', 'manual:manual', 'asunto:asunto', 'mensaje:mensaje', 'cuando:cuando', 'fecha:programada_para'].forEach(function (par) {
            var p = par.split(':'); if (d[p[1]] !== undefined) $id('nv-' + p[0]).value = d[p[1]];
        });
        audiencia(); cuando();
        if (ERROR) { $id('nv-msg').className = 'pp-form-msg is-visible is-error'; $id('nv-msg').innerHTML = ERROR; }
        $('#nueva-modal').modal('show');
    }
    if (REABRIR && REABRIR.clave) {
        $id('regla-title').textContent = 'Editar plantilla'; $id('rg-clave').value = REABRIR.clave; $id('rg-dias').value = REABRIR.dias_antes;
        $id('rg-asunto').value = REABRIR.asunto; $id('rg-mensaje').value = REABRIR.mensaje;
        $('#regla-modal').modal('show');
    }
    audiencia(); cuando();
})();
</script>
</body>
</html>
