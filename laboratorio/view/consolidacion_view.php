<?php
/**
 * Control de calidad (ISO/IEC 17025). Datos: models/control_calidad_model.php, vía
 * controllers/consolidacion_controller.php (que define $calidad).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';

lab_require_permission('laboratorio.consolidacion.ver');

if (!isset($calidad)) {
    header('Location: ../controllers/consolidacion_controller.php');
    exit;
}
$errorCalidad = $errorCalidad ?? '';
$puedeVerHoja = lab_can('laboratorio.validacion_tecnica.ver');
$puedeVerCatalogo = lab_can('laboratorio.catalogo_analisis.ver');
$puedeBandeja = lab_can('laboratorio.formularios_pendientes.ver') || lab_is_technician();

function eCalidad($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fechaCalidad($fecha, bool $hora = true): string
{
    $ts = $fecha ? strtotime((string) $fecha) : false;
    return $ts ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '—';
}

/** Tendencia: en calidad, subir es malo (más rechazos) y bajar es bueno. */
function tendenciaCalidad(float $variacion, string $sufijo, string $periodo): array
{
    if (abs($variacion) < 0.05) {
        return ['clase' => 'trend-flat', 'texto' => 'Sin cambio vs ' . $periodo];
    }
    $valor = number_format(abs($variacion), abs($variacion - round($variacion)) > 0.05 ? 1 : 0);
    return $variacion < 0
        ? ['clase' => 'trend-down', 'texto' => '▼ ' . $valor . $sufijo . ' vs ' . $periodo]
        : ['clase' => 'trend-up', 'texto' => '▲ ' . $valor . $sufijo . ' vs ' . $periodo];
}

$dias = (int) $calidad['dias'];
$tendenciaRechazo = tendenciaCalidad((float) $calidad['variacion_rechazo'], ' pts', 'los ' . $dias . ' días anteriores');
$tendenciaNC = tendenciaCalidad((float) $calidad['variacion_no_conformidades'], '', 'el mes anterior');
$abiertos = $calidad['reanalisis_abiertos'];
$equiposAlerta = $calidad['equipos_vencidos'] + $calidad['equipos_por_vencer'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de calidad</title>
    <link rel="stylesheet" href="../styles/control_calidad.css?v=<?= filemtime(__DIR__ . '/../styles/control_calidad.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=20261006">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('consolidacion_controller.php', 'Control de calidad', 'Indicadores ISO/IEC 17025'); ?>
    <div class="quality-page">
        <?php if ($errorCalidad !== ''): ?><div class="quality-alert" role="alert"><?= eCalidad($errorCalidad) ?></div><?php endif; ?>

        <nav class="quality-period" aria-label="Periodo de los indicadores">
            <span>Periodo:</span>
            <?php foreach (LAB_CC_PERIODOS as $p): ?>
                <a href="?dias=<?= $p ?>" class="<?= $p === $dias ? 'is-on' : '' ?>"<?= $p === $dias ? ' aria-current="true"' : '' ?>>Últimos <?= $p ?> días</a>
            <?php endforeach; ?>
        </nav>

        <section class="quality-kpi-grid" aria-label="Indicadores de control de calidad">
            <article class="quality-kpi">
                <span class="quality-kpi-label">Tasa de rechazo</span>
                <strong class="quality-kpi-value"><?= eCalidad(number_format((float) $calidad['tasa_rechazo'], 1)) ?>%</strong>
                <span class="quality-kpi-sub"><?= (int) $calidad['rechazos'] ?> de <?= (int) $calidad['decisiones'] ?> validaciones</span>
                <span class="quality-kpi-trend <?= eCalidad($tendenciaRechazo['clase']) ?>"><?= eCalidad($tendenciaRechazo['texto']) ?></span>
            </article>
            <article class="quality-kpi">
                <span class="quality-kpi-label">Reanálisis abiertos</span>
                <strong class="quality-kpi-value<?= count($abiertos) ? ' is-warning' : '' ?>"><?= count($abiertos) ?></strong>
                <span class="quality-kpi-sub">Muestras devueltas que esperan nueva captura</span>
                <a class="quality-kpi-link" href="#reanalisis">Ver lista</a>
            </article>
            <article class="quality-kpi">
                <span class="quality-kpi-label">Equipos por calibrar</span>
                <strong class="quality-kpi-value<?= $calidad['equipos_vencidos'] ? ' is-danger' : ($equiposAlerta ? ' is-warning' : '') ?>"><?= $calidad['equipos_disponibles'] ? $equiposAlerta : '—' ?></strong>
                <span class="quality-kpi-sub"><?= $calidad['equipos_disponibles']
                    ? (int) $calidad['equipos_vencidos'] . ' vencido(s) · ' . (int) $calidad['equipos_por_vencer'] . ' por vencer (≤ 30 días)'
                    : 'Catálogo de equipos no disponible' ?></span>
                <a class="quality-kpi-link" href="#equipos">Ver equipos</a>
            </article>
            <article class="quality-kpi">
                <span class="quality-kpi-label">No conformidades (mes)</span>
                <strong class="quality-kpi-value"><?= (int) $calidad['no_conformidades_mes'] ?></strong>
                <span class="quality-kpi-sub">Rechazos y reanálisis del mes en curso</span>
                <span class="quality-kpi-trend <?= eCalidad($tendenciaNC['clase']) ?>"><?= eCalidad($tendenciaNC['texto']) ?></span>
            </article>
        </section>

        <div class="quality-two">
            <section class="quality-card" aria-labelledby="cc-motivos">
                <header class="quality-card-head"><h2 id="cc-motivos">Motivos de rechazo — últimos <?= $dias ?> días</h2></header>
                <?php if ($calidad['motivos']): ?>
                    <?php foreach ($calidad['motivos'] as $motivo): ?>
                        <div class="quality-bar-row">
                            <div class="quality-bar-name"><?= eCalidad($motivo['motivo']) ?></div>
                            <div class="quality-bar-track" aria-hidden="true"><span style="width: <?= (int) $motivo['ancho'] ?>%"></span></div>
                            <div class="quality-bar-value"><?= (int) $motivo['total'] ?> <small><?= (int) $motivo['porcentaje'] ?>%</small></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="quality-empty">No se registraron rechazos en este periodo.</p>
                <?php endif; ?>
            </section>

            <section class="quality-card" aria-labelledby="cc-analisis">
                <header class="quality-card-head"><h2 id="cc-analisis">Tasa de rechazo por análisis</h2></header>
                <?php if ($calidad['por_analisis']): ?>
                    <div class="quality-table-wrap"><table class="quality-table">
                        <thead><tr><th>Análisis</th><th class="num">Validaciones</th><th class="num">Rechazos</th><th class="num">Tasa</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($calidad['por_analisis'], 0, 12) as $a): ?>
                            <tr>
                                <td><strong><?= eCalidad($a['analisis']) ?></strong><?php if ($a['tipo_muestra'] !== ''): ?><small><?= eCalidad($a['tipo_muestra']) ?></small><?php endif; ?></td>
                                <td class="num"><?= (int) $a['decisiones'] ?></td>
                                <td class="num"><?= (int) $a['rechazos'] ?></td>
                                <td class="num"><span class="quality-rate<?= $a['tasa'] >= 20 ? ' is-danger' : ($a['tasa'] > 0 ? ' is-warning' : '') ?>"><?= eCalidad(number_format($a['tasa'], 1)) ?>%</span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else: ?>
                    <p class="quality-empty">Sin validaciones técnicas en este periodo.</p>
                <?php endif; ?>
            </section>
        </div>

        <section class="quality-card" id="reanalisis" aria-labelledby="cc-reanalisis">
            <header class="quality-card-head">
                <h2 id="cc-reanalisis">Reanálisis abiertos <span class="quality-count"><?= count($abiertos) ?></span></h2>
                <?php if ($puedeBandeja && $abiertos): ?><a class="quality-link" href="../view/solicitudes_pendientes_tecnico.php">Ir a la Bandeja del analista</a><?php endif; ?>
            </header>
            <?php if ($abiertos): ?>
                <div class="quality-table-wrap"><table class="quality-table">
                    <thead><tr><th>Muestra</th><th>Análisis</th><th>Motivo</th><th>Analista</th><th>Devuelta</th><th class="num">Días abierta</th></tr></thead>
                    <tbody>
                    <?php foreach ($abiertos as $a): ?>
                        <tr>
                            <td><strong class="mono"><?= eCalidad($a['muestra']) ?></strong><small>Lote <?= eCalidad($a['lote']) ?></small></td>
                            <td><?= eCalidad($a['analisis']) ?><small><?= eCalidad($a['tipo_muestra']) ?></small></td>
                            <td><span class="quality-chip"><?= eCalidad($a['categoria']) ?></span><?php if ($a['detalle'] !== ''): ?><small><?= eCalidad($a['detalle']) ?></small><?php endif; ?></td>
                            <td><?= eCalidad($a['analista'] ?: '—') ?></td>
                            <td><?= eCalidad(fechaCalidad($a['desde'])) ?><?php if ($a['validador'] !== ''): ?><small>por <?= eCalidad($a['validador']) ?></small><?php endif; ?></td>
                            <td class="num"><span class="quality-rate<?= ($a['dias'] ?? 0) > 5 ? ' is-danger' : (($a['dias'] ?? 0) > 2 ? ' is-warning' : '') ?>"><?= $a['dias'] !== null ? (int) $a['dias'] : '—' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="quality-empty">No hay muestras devueltas a reanálisis pendientes.</p>
            <?php endif; ?>
        </section>

        <section class="quality-card" aria-labelledby="cc-rechazos">
            <header class="quality-card-head"><h2 id="cc-rechazos">Rechazos y reanálisis — últimos <?= $dias ?> días <span class="quality-count"><?= (int) $calidad['rechazos'] ?></span></h2></header>
            <?php if ($calidad['rechazos_recientes']): ?>
                <div class="quality-table-wrap"><table class="quality-table">
                    <thead><tr><th>Fecha</th><th>Lote / muestras</th><th>Análisis</th><th>Decisión</th><th>Motivo</th><th>Validó</th><?php if ($puedeVerHoja): ?><th><span class="sr-only">Hoja</span></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($calidad['rechazos_recientes'] as $d): ?>
                        <tr>
                            <td><?= eCalidad(fechaCalidad($d['fecha'])) ?></td>
                            <td><strong class="mono"><?= eCalidad($d['codigo_lote'] ?? '—') ?></strong><small>Muestras <?= (int) $d['inicio'] ?><?= (int) $d['fin'] !== (int) $d['inicio'] ? '–' . (int) $d['fin'] : '' ?></small></td>
                            <td><?= eCalidad($d['analisis'] ?? '—') ?><small><?= eCalidad($d['tipo_muestra'] ?? '') ?></small></td>
                            <td><span class="quality-chip<?= str_starts_with($d['tipo'], 'Rechazo') ? ' is-danger' : ' is-warning' ?>"><?= eCalidad($d['tipo']) ?></span></td>
                            <td><strong><?= eCalidad($d['categoria']) ?></strong><?php if ($d['detalle'] !== ''): ?><small><?= eCalidad($d['detalle']) ?></small><?php endif; ?></td>
                            <td><?= eCalidad($d['usuario'] ?: '—') ?></td>
                            <?php if ($puedeVerHoja): ?><td><a class="quality-link" href="../view/hoja_revision.php?id_formulario=<?= (int) $d['id_formulario'] ?>">Ver hoja</a></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="quality-empty">Sin rechazos ni reanálisis en este periodo.</p>
            <?php endif; ?>
        </section>

        <section class="quality-card" id="equipos" aria-labelledby="cc-equipos">
            <header class="quality-card-head">
                <h2 id="cc-equipos">Calibración de equipos</h2>
                <?php if ($puedeVerCatalogo): ?><a class="quality-link" href="../catalogo_analisis.php?tab=equipos">Gestionar equipos</a><?php endif; ?>
            </header>
            <?php if (!$calidad['equipos_disponibles']): ?>
                <p class="quality-empty">El catálogo de equipos no está disponible.</p>
            <?php elseif ($calidad['equipos']): ?>
                <div class="quality-table-wrap"><table class="quality-table">
                    <thead><tr><th>Equipo</th><th>Estado</th><th>Próxima calibración</th><th class="num">Análisis que lo usan</th></tr></thead>
                    <tbody>
                    <?php foreach ($calidad['equipos'] as $e): ?>
                        <tr>
                            <td><strong><?= eCalidad($e['etiqueta']) ?></strong></td>
                            <td><span class="quality-chip<?= $e['estado'] === 'vencida' ? ' is-danger' : ($e['estado'] === 'por_vencer' ? ' is-warning' : '') ?>"><?= eCalidad($e['estado_texto']) ?></span></td>
                            <td><?= eCalidad($e['proxima'] !== '' ? fechaCalidad($e['proxima'], false) : 'Sin registro') ?></td>
                            <td class="num"><?= (int) $e['analisis'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="quality-empty">Todos los equipos en servicio tienen la calibración vigente.</p>
            <?php endif; ?>
        </section>
    </div>
<?php lab_shell_content_close(); ?>
</body>
</html>
