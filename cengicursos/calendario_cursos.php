<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();

$mes = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
$primerDia = $mes . '-01';
$ultimoDia = date('Y-m-t', strtotime($primerDia));
$planFiltro = (int) ($_GET['plan'] ?? 0);
$diaSel = cengi_pp_es_fecha($_GET['dia'] ?? '') ? $_GET['dia'] : (substr($hoy, 0, 7) === $mes ? $hoy : null);
$mesAnterior = date('Y-m', strtotime($primerDia . ' -1 month'));
$mesSiguiente = date('Y-m', strtotime($primerDia . ' +1 month'));

// Eventos del mes: inicios y cierres de cursos + sesiones (con conflictos de instructor).
$filtroPlanSql = $planFiltro ? ' AND p.id = ' . $planFiltro : '';
$stmt = $db->prepare("SELECT p.*, c.codigo_curso FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id
    WHERE p.estado <> 'cancelado' AND (p.fecha_inicio BETWEEN ? AND ? OR p.fecha_fin BETWEEN ? AND ?)$filtroPlanSql");
$stmt->execute([$primerDia, $ultimoDia, $primerDia, $ultimoDia]);
$planesMes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare("SELECT s.*, p.nombre AS plan_nombre, i.nombre AS instructor, st.nombre AS soporte
    FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    LEFT JOIN instructores i ON i.id = s.instructor_id LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id
    WHERE s.estado <> 'cancelada' AND p.estado <> 'cancelado' AND s.fecha BETWEEN ? AND ?$filtroPlanSql ORDER BY s.hora_inicio");
$stmt->execute([$primerDia, $ultimoDia]);
$sesionesMes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$conflictos = cengi_pp_conflictos($db);
$inscritos = cengi_pp_conteo_inscritos($db);

$eventos = [];
foreach ($planesMes as $p) {
    if ($p['fecha_inicio'] >= $primerDia && $p['fecha_inicio'] <= $ultimoDia) {
        $eventos[$p['fecha_inicio']][] = ['tipo' => 'inicio', 'orden' => 0, 'plan' => $p];
    }
    if ($p['fecha_fin'] !== $p['fecha_inicio'] && $p['fecha_fin'] >= $primerDia && $p['fecha_fin'] <= $ultimoDia) {
        $eventos[$p['fecha_fin']][] = ['tipo' => 'fin', 'orden' => 3, 'plan' => $p];
    }
}
foreach ($sesionesMes as $s) {
    $conflicto = isset($conflictos['instructor'][$s['id']]);
    $eventos[$s['fecha']][] = ['tipo' => $conflicto ? 'conflicto' : 'sesion', 'orden' => $conflicto ? 1 : 2, 'sesion' => $s];
}
foreach ($eventos as &$lista) {
    usort($lista, static function ($a, $b) {
        return $a['orden'] <=> $b['orden'];
    });
}
unset($lista);

function cengi_cal_etiqueta(array $e, array $inscritos)
{
    if ($e['tipo'] === 'inicio') {
        $p = $e['plan'];
        return 'Inicia' . (cengi_pp_convocado($p) ? ' (' . ($inscritos[$p['id']] ?? 0) . '/' . $p['cupo_minimo'] . ')' : '') . ': ' . $p['nombre'];
    }
    if ($e['tipo'] === 'fin') {
        return 'Finaliza: ' . $e['plan']['nombre'];
    }
    return cengi_pp_hora($e['sesion']['hora_inicio']) . ' ' . $e['sesion']['tema'];
}

// Paneles laterales
$stmt = $db->prepare("SELECT p.*, c.codigo_curso FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id
    WHERE p.estado <> 'cancelado' AND p.fecha_inicio BETWEEN ? AND ? ORDER BY p.fecha_inicio");
$stmt->execute([$hoy, cengi_pp_sumar_dias($hoy, 60)]);
$porIniciar = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare("SELECT * FROM planificacion_cursos WHERE estado <> 'cancelado' AND fecha_fin BETWEEN ? AND ? ORDER BY fecha_fin LIMIT 6");
$stmt->execute([$hoy, cengi_pp_sumar_dias($hoy, 45)]);
$finalizan = $stmt->fetchAll(PDO::FETCH_ASSOC);
$enRiesgo = array_filter($porIniciar, static function ($p) use ($hoy, $inscritos) {
    return cengi_pp_dias_entre($hoy, $p['fecha_inicio']) <= 30 && cengi_pp_semaforo_cupo($p, $inscritos[$p['id']] ?? 0)[0] !== 'verde';
});
$coloresSemaforo = ['verde' => '#73BC25', 'amarillo' => '#FFCC00', 'rojo' => '#E4544A', 'gris' => '#B9C2B3'];
$clasesSemaforo = ['verde' => 'is-active', 'amarillo' => 'is-upcoming', 'rojo' => 'is-rejected', 'gris' => 'is-neutral'];
$gruposPlanes = cengi_pp_planes_opciones($db);
$url = static function (array $extra) use ($mes, $planFiltro) {
    return 'calendario_cursos.php?' . http_build_query(array_filter($extra + ['mes' => $mes, 'plan' => $planFiltro ?: '']));
};
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Calendario general'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_pp_subnav('calendario_cursos.php'); ?>

    <section class="pp-card">
        <div class="pp-toolbar">
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="<?php echo cengi_pp_h($url(['mes' => $mesAnterior])); ?>" aria-label="Mes anterior">‹</a>
                <strong style="min-width:150px;text-align:center;font-size:15px;"><?php echo CENGI_PP_MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4); ?></strong>
                <a class="btn btn-default btn-sm" href="<?php echo cengi_pp_h($url(['mes' => $mesSiguiente])); ?>" aria-label="Mes siguiente">›</a>
                <a class="btn btn-default btn-sm" href="<?php echo cengi_pp_h($url(['mes' => date('Y-m'), 'dia' => $hoy])); ?>">Hoy</a>
                <form method="get" style="display:inline;">
                    <input type="hidden" name="mes" value="<?php echo $mes; ?>">
                    <select name="plan" aria-label="Curso" onchange="this.form.submit()">
                        <option value="">Todos los cursos</option>
                        <?php echo cengi_pp_options_planes($gruposPlanes, (string) $planFiltro); ?>
                    </select>
                </form>
            </div>
            <div class="pp-cal-legend">
                <span><i style="background:#CFE9B3;"></i>Inicio de curso</span>
                <span><i style="background:#FFD2B0;"></i>Finaliza curso</span>
                <span><i style="background:#FFE68A;"></i>Sesión programada</span>
                <span><i style="background:#F3B5AE;"></i>Conflicto de instructor</span>
            </div>
        </div>
    </section>

    <?php if ($enRiesgo): ?>
        <div class="cengi-feedback is-warning"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-bell"></span></div><div><p>
            <b><?php echo count($enRiesgo); ?> curso(s) inician en los próximos 30 días sin el cupo mínimo asegurado:</b><br>
            <?php foreach ($enRiesgo as $p): [, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($p, $inscritos[$p['id']] ?? 0); ?>
                <?php echo cengi_pp_h($p['nombre']) . ' — ' . cengi_pp_fecha_corta($p['fecha_inicio']) . ' (en ' . cengi_pp_dias_entre($hoy, $p['fecha_inicio']) . ' días): ' . cengi_pp_h($etiqueta . '. ' . $detalle); ?><br>
            <?php endforeach; ?>
        </p></div></div>
    <?php endif; ?>

    <div class="pp-two-col">
        <section class="pp-card"><div class="pp-card-body">
            <div class="pp-cal-grid">
                <?php foreach (['D', 'L', 'M', 'M', 'J', 'V', 'S'] as $d): ?><div class="pp-cal-dow"><?php echo $d; ?></div><?php endforeach; ?>
                <?php for ($i = 0, $inicio = (int) date('w', strtotime($primerDia)); $i < $inicio; $i++): ?><div></div><?php endfor; ?>
                <?php for ($d = 1, $total = (int) date('t', strtotime($primerDia)); $d <= $total; $d++):
                    $iso = sprintf('%s-%02d', $mes, $d);
                    $lista = $eventos[$iso] ?? [];
                    $dow = (int) date('w', strtotime($iso));
                    $clases = 'pp-cal-day' . ($lista ? ' has-events' : '') . ($iso === $hoy ? ' is-today' : '') . ($iso === $diaSel ? ' is-selected' : '') . ($dow === 0 || $dow === 6 ? ' is-weekend' : '');
                ?>
                    <a class="<?php echo $clases; ?>" href="<?php echo cengi_pp_h($url(['dia' => $iso])); ?>" aria-label="<?php echo cengi_pp_h(cengi_pp_fecha_larga($iso) . ': ' . count($lista) . ' actividad(es)'); ?>">
                        <span class="pp-cal-n"><?php echo $d; ?></span>
                        <?php foreach (array_slice($lista, 0, 3) as $e): $texto = cengi_cal_etiqueta($e, $inscritos); ?>
                            <span class="pp-cal-ev is-<?php echo $e['tipo']; ?>" title="<?php echo cengi_pp_h($texto . (isset($e['sesion']) ? ' — ' . $e['sesion']['plan_nombre'] : '')); ?>"><?php echo cengi_pp_h($texto); ?></span>
                        <?php endforeach; ?>
                        <?php if (count($lista) > 3): ?><span class="pp-cal-more">+<?php echo count($lista) - 3; ?> más</span><?php endif; ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div></section>

        <div>
            <section class="pp-card">
                <div class="pp-card-head"><div><h3><?php echo $diaSel ? cengi_pp_h(ucfirst(cengi_pp_fecha_larga($diaSel))) : 'Selecciona un día'; ?></h3><span class="pp-sub">Inicios, cierres y sesiones de ese día</span></div></div>
                <div class="pp-card-body">
                    <?php
                    $delDia = [];
                    if ($diaSel) {
                        if (substr($diaSel, 0, 7) === $mes) {
                            $delDia = $eventos[$diaSel] ?? [];
                        }
                    }
                    ?>
                    <?php if (!$diaSel): ?>
                        <span class="pp-sub">Haz clic en un día del calendario para ver el detalle.</span>
                    <?php elseif (!$delDia): ?>
                        <span class="pp-sub">Sin actividades este día. <a href="programa_sesiones.php">Programar una sesión</a></span>
                    <?php endif; ?>
                    <?php foreach ($delDia as $e): ?>
                        <div class="pp-day-item">
                            <?php if ($e['tipo'] === 'inicio' || $e['tipo'] === 'fin'): $p = $e['plan']; ?>
                                <strong><?php echo $e['tipo'] === 'inicio' ? 'Inicia' : 'Finaliza'; ?>: <?php echo cengi_pp_h($p['nombre']); ?></strong>
                                <span class="pp-sub"><?php echo cengi_pp_h($p['modalidad'] . ' · ' . cengi_pp_horario($p['hora_inicio'], $p['hora_fin'])); ?> · <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $p['estado']); ?></span>
                                <?php if ($e['tipo'] === 'inicio'): [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($p, $inscritos[$p['id']] ?? 0); ?>
                                    <span class="pp-sub"><span class="cengi-status-badge <?php echo $clasesSemaforo[$color]; ?>"><i></i><?php echo $etiqueta; ?></span> <?php echo cengi_pp_h($detalle); ?></span>
                                <?php endif; ?>
                            <?php else: $s = $e['sesion']; ?>
                                <strong><?php echo cengi_pp_horario($s['hora_inicio'], $s['hora_fin']) . ' · ' . cengi_pp_h($s['tema']); ?></strong>
                                <span class="pp-sub"><?php echo cengi_pp_h($s['plan_nombre'] . ' — ' . $s['modulo']); ?></span>
                                <span class="pp-sub"><?php echo cengi_pp_h(($s['instructor'] ?: 'Sin instructor') . ' · ' . $s['lugar'] . ($s['soporte'] ? ' · Soporte: ' . $s['soporte'] : '')); ?></span>
                                <?php if ($e['tipo'] === 'conflicto'): ?><span class="pp-chip is-danger">Conflicto de horario del instructor</span><?php endif; ?>
                                <span class="pp-sub"><a href="programa_sesiones.php?editar=<?php echo (int) $s['id']; ?>">Editar sesión</a></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="pp-card">
                <div class="pp-card-head"><div><h3>Cursos por iniciar</h3><span class="pp-sub">Próximos 60 días · asignados frente al cupo mínimo</span></div></div>
                <div class="pp-card-body">
                    <?php if (!$porIniciar): ?><span class="pp-sub">Ningún curso inicia en los próximos 60 días.</span><?php endif; ?>
                    <?php foreach ($porIniciar as $p):
                        $n = $inscritos[$p['id']] ?? 0;
                        [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($p, $n);
                        $dias = cengi_pp_dias_entre($hoy, $p['fecha_inicio']);
                    ?>
                        <a class="pp-sem" href="<?php echo cengi_pp_h('calendario_cursos.php?' . http_build_query(['mes' => substr($p['fecha_inicio'], 0, 7), 'dia' => $p['fecha_inicio']])); ?>" style="--pp-sem:<?php echo $coloresSemaforo[$color]; ?>;color:inherit;text-decoration:none;">
                            <span class="pp-sem-head"><b style="font-size:12.5px;"><?php echo cengi_pp_h($p['nombre']); ?></b><span class="cengi-status-badge <?php echo $clasesSemaforo[$color]; ?>"><i></i><?php echo $etiqueta; ?></span></span>
                            <span class="pp-sub"><?php echo cengi_pp_fecha($p['fecha_inicio']) . ' · ' . ($dias === 0 ? 'inicia hoy' : ($dias === 1 ? 'inicia mañana' : 'en ' . $dias . ' días')); ?></span>
                            <span class="pp-prog" style="margin-top:6px;"><span class="pp-prog-track"><span style="width:<?php echo min(100, (int) round($n * 100 / max(1, $p['cupo_minimo']))); ?>%"></span></span><span class="pp-num"><?php echo $n . '/' . (int) $p['cupo_minimo']; ?></span></span>
                            <span class="pp-sub"><?php echo cengi_pp_h($detalle); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="pp-card">
                <div class="pp-card-head"><h3>Finalizan pronto</h3></div>
                <div class="pp-card-body">
                    <?php if (!$finalizan): ?><span class="pp-sub">Nada finaliza en los próximos 45 días.</span><?php endif; ?>
                    <?php foreach ($finalizan as $p): ?>
                        <div class="pp-mini-row"><a href="<?php echo cengi_pp_h('calendario_cursos.php?' . http_build_query(['mes' => substr($p['fecha_fin'], 0, 7), 'dia' => $p['fecha_fin']])); ?>"><?php echo cengi_pp_h($p['nombre']); ?></a><b style="color:#B34E00;font-size:12px;"><?php echo cengi_pp_fecha($p['fecha_fin']); ?></b></div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</main>
</body>
</html>
