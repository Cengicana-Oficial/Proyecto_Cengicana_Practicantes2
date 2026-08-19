<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/consolidacion_model.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';

lab_require_permission('laboratorio.consolidacion.ver');

$indicadoresCalidad = $indicadoresCalidad ?? obtenerIndicadoresControlCalidad();

function eCalidad($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tendenciaCalidad(float $variacion, string $sufijo = ''): array
{
    if (abs($variacion) < 0.05) {
        return ['clase' => 'trend-flat', 'texto' => '— sin cambio'];
    }

    $baja = $variacion < 0;
    $valor = number_format(abs($variacion), abs($variacion - round($variacion)) > 0.05 ? 1 : 0);
    return [
        'clase' => $baja ? 'trend-down' : 'trend-up',
        'texto' => ($baja ? '▼ ' : '▲ ') . $valor . $sufijo,
    ];
}

$tendenciaRechazo = tendenciaCalidad((float) ($indicadoresCalidad['variacion_rechazo'] ?? 0), ' pts');
$tendenciaNoConformidad = tendenciaCalidad((float) ($indicadoresCalidad['variacion_no_conformidades'] ?? 0));
$motivosRechazo = $indicadoresCalidad['motivos_rechazo'] ?? [];
$sinRechazos = empty($motivosRechazo);

if ($sinRechazos) {
    $motivosRechazo = [
        ['motivo' => 'Fuera de rango', 'total' => 0, 'porcentaje' => 0],
        ['motivo' => 'Error de digitación', 'total' => 0, 'porcentaje' => 0],
        ['motivo' => 'Duplicado inconsistente', 'total' => 0, 'porcentaje' => 0],
        ['motivo' => 'Falla de equipo', 'total' => 0, 'porcentaje' => 0],
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de calidad</title>
    <link rel="stylesheet" href="../styles/control_calidad.css?v=<?= filemtime(__DIR__ . '/../styles/control_calidad.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('consolidacion_controller.php', 'Control de calidad', 'Indicadores ISO/IEC 17025'); ?>
    <div class="quality-page">
        <section class="quality-kpi-grid" aria-label="Indicadores de control de calidad">
            <article class="quality-kpi">
                <span class="quality-kpi-label">Tasa de rechazo</span>
                <strong class="quality-kpi-value"><?= eCalidad(number_format((float) ($indicadoresCalidad['tasa_rechazo'] ?? 0), 1)) ?>%</strong>
                <span class="quality-kpi-trend <?= eCalidad($tendenciaRechazo['clase']) ?>"><?= eCalidad($tendenciaRechazo['texto']) ?></span>
            </article>

            <article class="quality-kpi">
                <span class="quality-kpi-label">Reanálisis abiertos</span>
                <strong class="quality-kpi-value"><?= (int) ($indicadoresCalidad['reanalisis_abiertos'] ?? 0) ?></strong>
                <span class="quality-kpi-trend trend-flat">— abiertos actualmente</span>
            </article>

            <article class="quality-kpi">
                <span class="quality-kpi-label">Equipos por calibrar</span>
                <strong class="quality-kpi-value is-warning">
                    <?= !empty($indicadoresCalidad['equipos_disponibles']) ? (int) ($indicadoresCalidad['equipos_por_calibrar'] ?? 0) : '—' ?>
                </strong>
                <span class="quality-kpi-trend <?= !empty($indicadoresCalidad['equipos_disponibles']) ? 'trend-up' : 'trend-flat' ?>">
                    <?= !empty($indicadoresCalidad['equipos_disponibles']) ? '▲ vence en 5 días' : '— sin catálogo de equipos' ?>
                </span>
            </article>

            <article class="quality-kpi">
                <span class="quality-kpi-label">No conformidades (mes)</span>
                <strong class="quality-kpi-value"><?= (int) ($indicadoresCalidad['no_conformidades_mes'] ?? 0) ?></strong>
                <span class="quality-kpi-trend <?= eCalidad($tendenciaNoConformidad['clase']) ?>"><?= eCalidad($tendenciaNoConformidad['texto']) ?></span>
            </article>
        </section>

        <div class="quality-section-title">
            <h1>Motivos de rechazo — últimos 30 días</h1>
        </div>

        <section class="quality-chart-card" aria-label="Motivos de rechazo de los últimos 30 días">
            <?php foreach ($motivosRechazo as $motivo): ?>
                <div class="quality-bar-row">
                    <div class="quality-bar-name"><?= eCalidad($motivo['motivo'] ?? 'Sin clasificar') ?></div>
                    <div class="quality-bar-track" aria-hidden="true">
                        <span style="width: <?= max(0, min(100, (int) ($motivo['porcentaje'] ?? 0))) ?>%"></span>
                    </div>
                    <div class="quality-bar-value"><?= (int) ($motivo['total'] ?? 0) ?></div>
                </div>
            <?php endforeach; ?>

            <?php if ($sinRechazos): ?>
                <p class="quality-chart-empty">No se registraron rechazos durante los últimos 30 días.</p>
            <?php endif; ?>
        </section>
    </div>
<?php lab_shell_content_close(); ?>
</body>
</html>
