<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/dashboard_helpers.php';
$year = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
$year = $year && $year >= 2000 && $year <= (int) date('Y') + 5 ? $year : (int) date('Y');
$data = cengi_dash_data(conectar(), $year);
extract($data, EXTR_SKIP);
$sessionPct = $sessions && $sessions['total'] ? round(100 * $sessions['impartidas'] / $sessions['total']) : null;
$attendancePct = $attendance && $attendance['registros'] ? round(100 * $attendance['ingresos'] / $attendance['registros']) : null;
$months = array_fill(0, 12, 0);
foreach ($monthly ?? [] as $row) { $months[(int) $row['mes'] - 1] = (int) $row['total']; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Avance de capacitación y eventos | Cengicursos</title>
    <link rel="icon" href="img/logo-comite-capacitacion.png">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo filemtime(__DIR__ . '/css/proyecto.css'); ?>">
    <link rel="stylesheet" href="css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/css/dashboard.css'); ?>">
</head>
<body class="cengi-canvas cengi-dashboard-page">
<?php menu_render(); ?>
<main class="container">
    <div class="dash-heading">
        <div><h1>Avance de capacitación y eventos</h1><p>Cursos iniciados en el año · sesiones y eventos por su fecha programada.</p></div>
        <form method="get" class="dash-period"><label for="anio">Año</label><input id="anio" name="anio" type="number" min="2000" max="<?php echo date('Y') + 5; ?>" value="<?php echo $year; ?>" required><button type="submit">Consultar</button></form>
    </div>
    <?php if ($courses === null || $people === null || $sessions === null || $monthly === null || $nextCourses === null || ($showEvents && ($events === null || $attendance === null || $nextEvents === null))): ?>
        <p class="dash-notice" role="status">No fue posible cargar algunos indicadores. Se muestran con «—»; no representan un valor de cero.</p>
    <?php endif; ?>
    <div class="dash-metrics">
        <?php foreach ([['Cursos del año', $courses, 'total', 'Oferta registrada', 'education'], ['En curso', $courses, 'activos', 'Según fechas de inicio y fin', 'time'], ['Personas inscritas', $people, 'personas', 'Personas únicas con asignación activa', 'user'], ['Inscripciones activas', $people, 'inscripciones', 'Una persona puede estar en varios cursos', 'list-alt']] as [$label, $row, $key, $help, $icon]): ?>
            <article class="dash-metric"><span class="glyphicon glyphicon-<?php echo $icon; ?>" aria-hidden="true"></span><span><?php echo $label; ?></span><strong><?php echo cengi_dash_n($row, $key); ?></strong><small><?php echo $help; ?></small></article>
        <?php endforeach; ?>
    </div>
    <div class="dash-grid">
        <section class="dash-panel"><h2>Ejecución de la capacitación</h2>
            <div class="dash-states">
                <?php foreach (['proximos' => 'Por iniciar', 'activos' => 'En curso', 'finalizados' => 'Finalizados por fecha'] as $key => $label): ?><div><strong><?php echo cengi_dash_n($courses, $key); ?></strong><span><?php echo $label; ?></span></div><?php endforeach; ?>
            </div>
            <div class="dash-progress-label"><strong>Sesiones impartidas</strong><b><?php echo $sessionPct === null ? '—' : $sessionPct . '%'; ?></b></div>
            <progress max="100" value="<?php echo $sessionPct ?? 0; ?>" aria-label="Porcentaje de sesiones impartidas"></progress>
            <p><?php echo cengi_dash_n($sessions, 'impartidas'); ?> de <?php echo cengi_dash_n($sessions, 'total'); ?> sesiones no canceladas del año.</p>
            <p class="dash-note"><?php echo cengi_dash_n($sessions, 'pendientes'); ?> sesiones con fecha pasada aún sin marcar como impartidas.</p>
            <?php if (cengi_puede_gestionar()): ?><a class="dash-link" href="programa_sesiones.php">Revisar programa de sesiones →</a><?php endif; ?>
        </section>
        <section class="dash-panel"><h2>Inicio de cursos por mes</h2><p>Distribución de la oferta registrada en <?php echo $year; ?>.</p>
            <?php if ($monthly === null): ?><p class="dash-empty">Indicador no disponible.</p><?php elseif (!array_sum($months)): ?><p class="dash-empty">No hay cursos con inicio registrado en este año.</p><?php else: ?>
                <div class="dash-months" role="list" aria-label="Cantidad de cursos por mes">
                    <?php foreach (['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'] as $i => $label): ?><div role="listitem" aria-label="<?php echo $label . ': ' . $months[$i] . ' cursos'; ?>"><b><?php echo $months[$i]; ?></b><div class="dash-bar-track"><span style="height:<?php echo round(100 * $months[$i] / max($months)); ?>%"></span></div><small><?php echo $label; ?></small></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php if ($showEvents): ?>
        <section class="dash-panel"><h2>Eventos del año <small>Alcance institucional</small></h2>
            <div class="dash-states"><div><strong><?php echo cengi_dash_n($events, 'total'); ?></strong><span>No cancelados</span></div><div><strong><?php echo cengi_dash_n($events, 'transcurridos'); ?></strong><span>Con fecha pasada</span></div><div><strong><?php echo cengi_dash_n($events, 'proximos'); ?></strong><span>Desde hoy</span></div></div>
            <div class="dash-progress-label"><strong>Asistencia registrada</strong><b><?php echo $attendancePct === null ? '—' : $attendancePct . '%'; ?></b></div>
            <progress class="dash-event-progress" max="100" value="<?php echo $attendancePct ?? 0; ?>" aria-label="Porcentaje de registros con ingreso a eventos"></progress>
            <p><?php echo cengi_dash_n($attendance, 'ingresos'); ?> ingresos de <?php echo cengi_dash_n($attendance, 'registros'); ?> registros en eventos hasta hoy.</p>
            <p class="dash-note">Se cuentan participaciones por evento, no personas únicas.</p><a class="dash-link" href="eventos_qr.php">Ver eventos y asistencia →</a>
        </section>
        <?php endif; ?>
        <section class="dash-panel"><h2>Próximas actividades <small>Hasta 5 por tipo · <?php echo $year; ?></small></h2>
            <?php foreach (array_merge([['Cursos', $nextCourses]], $showEvents ? [['Eventos', $nextEvents]] : []) as [$title, $items]): ?>
                <h3><?php echo $title; ?></h3>
                <?php if (!$items): ?><p class="dash-empty"><?php echo $items === null ? 'No se pudo cargar la agenda.' : 'Sin próximas actividades en el año seleccionado.'; ?></p><?php else: ?><ul class="dash-agenda"><?php foreach ($items as $item): ?><li><time datetime="<?php echo cengi_dash_h($item['fecha']); ?>"><?php echo date('d/m', strtotime($item['fecha'])); ?></time><span><?php echo cengi_dash_h($item['nombre']); ?></span></li><?php endforeach; ?></ul><?php endif; ?>
            <?php endforeach; ?>
        </section>
    </div>
    <p class="dash-footnote">Cursos e inscripciones según el alcance de tu cuenta. Finalizado por fecha no implica aprobación ni certificación. Los cursos sin fecha de inicio no se incluyen en el período.</p>
</main>
</body></html>
