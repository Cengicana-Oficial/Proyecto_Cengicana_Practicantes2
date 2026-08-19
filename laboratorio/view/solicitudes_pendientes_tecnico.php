<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogo_muestras_helper.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../models/bandeja_analista_model.php';

lab_require_module_access();

if (!lab_can('laboratorio.formularios_pendientes.ver') && !lab_is_technician()) {
    lab_forbidden('No tiene permisos para ver formularios pendientes.');
}

$pendientes = lab_bandeja_analista_pendientes($conexion);
$pendientesPorTipo = [];

foreach ($pendientes as $item) {
    $claveTipo = labCatalogoMuestrasClaveDesdePrefijo(null, (string) ($item['tipo_muestra'] ?? ''));
    $ordenTipo = labCatalogoMuestrasOrdenModulo($claveTipo);
    $labelTipo = labCatalogoMuestrasEtiquetaModuloPlural($claveTipo);

    if (!isset($pendientesPorTipo[$claveTipo])) {
        $pendientesPorTipo[$claveTipo] = [
            'clave' => $claveTipo,
            'label' => $labelTipo,
            'orden' => $ordenTipo,
            'items' => [],
        ];
    }

    $pendientesPorTipo[$claveTipo]['items'][] = $item;
}

uasort($pendientesPorTipo, static function (array $left, array $right): int {
    return ($left['orden'] <=> $right['orden']) ?: strcasecmp($left['label'], $right['label']);
});

foreach ($pendientesPorTipo as &$grupo) {
    usort($grupo['items'], static function (array $left, array $right): int {
        $leftCode = (string) ($left['codigo_lote'] ?? '');
        $rightCode = (string) ($right['codigo_lote'] ?? '');
        return strnatcasecmp($leftCode, $rightCode)
            ?: ((int) ($right['fecha_ingreso'] ? strtotime((string) $right['fecha_ingreso']) : 0) <=> (int) ($left['fecha_ingreso'] ? strtotime((string) $left['fecha_ingreso']) : 0));
    });
}
unset($grupo);

function ePendientes($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fechaPendiente($fecha): string
{
    if (!$fecha) {
        return '-';
    }

    $timestamp = strtotime((string) $fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : (string) $fecha;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes pendientes</title>
    <link rel="stylesheet" href="../css/solicitudes_pendientes.css?v=<?= filemtime(__DIR__ . '/../css/solicitudes_pendientes.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('solicitudes_pendientes_tecnico.php', 'Solicitudes pendientes', 'Analisis solicitados por lote que aun no tienen formulario ingresado'); ?>
    <div class="solpend-content">
    <header class="page-header">
        <div>
            <span class="eyebrow">Tecnico</span>
            <h1>Solicitudes pendientes</h1>
            <p>Analisis solicitados por lote que aun no tienen formulario ingresado.</p>
            <a href="labc_index.php" class="cengi-btn cengi-btn-ghost cengi-btn-sm" style="margin-top:10px;">
                <span class="material-symbols-outlined" style="font-size:15px;">science</span>
                Ir a la bandeja de captura (LABC)
            </a>
        </div>
        <div class="count-pill">
            <?= count($pendientes) ?> lotes
        </div>
    </header>

    <?php if (empty($pendientes)): ?>
        <section class="empty-state">
            No hay solicitudes de analisis pendientes por lote.
        </section>
    <?php else: ?>
        <?php foreach ($pendientesPorTipo as $grupo): ?>
            <details class="pending-type-section">
                <summary class="pending-type-header">
                    <div class="pending-type-summary-copy">
                        <span class="eyebrow">Tipo de muestra</span>
                        <h2 class="pending-type-title"><?= ePendientes($grupo['label']) ?></h2>
                    </div>
                    <span class="count-pill"><?= count($grupo['items']) ?> lotes</span>
                </summary>

                <div class="pending-grid">
                    <?php foreach ($grupo['items'] as $item): ?>
                        <?php $analisis = array_filter(explode('||', (string) ($item['analisis_pendientes'] ?? ''))); ?>
                        <article class="pending-card">
                            <div class="pending-card-head">
                                <div>
                                    <span class="kicker">Lote</span>
                                    <h2><?= ePendientes($item['codigo_lote'] ?? '-') ?></h2>
                                </div>
                                <span class="type-pill"><?= ePendientes($item['tipo_muestra'] ?? '-') ?></span>
                            </div>

                            <div class="pending-meta">
                                <span>Solicitud #<?= (int) ($item['id_solicitud'] ?? 0) ?></span>
                                <span><?= ePendientes($item['numero_muestras'] ?? '-') ?> muestras</span>
                                <span>Ingreso <?= ePendientes(fechaPendiente($item['fecha_ingreso'] ?? null)) ?></span>
                                <span>Estimada <?= ePendientes(fechaPendiente($item['fecha_estimada'] ?? null)) ?></span>
                            </div>

                            <div class="analysis-block">
                                <strong><?= (int) ($item['total_pendientes'] ?? count($analisis)) ?> analisis pendientes</strong>
                                <div class="analysis-list">
                                    <?php foreach ($analisis as $nombreAnalisis): ?>
                                        <span><?= ePendientes($nombreAnalisis) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
<?php lab_shell_content_close(); ?>
</body>
</html>
