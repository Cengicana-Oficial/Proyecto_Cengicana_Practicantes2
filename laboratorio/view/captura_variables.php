<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../includes/captura_variables_registry.php';

lab_require_module_access();

if (!lab_can('laboratorio.formularios_pendientes.ver') && !lab_is_technician()) {
    lab_forbidden('No tiene permisos para capturar resultados de laboratorio.');
}

$analysisKey = trim((string) ($_GET['analisis'] ?? ''));
$capture = lab_captura_variables_por_clave($analysisKey);
if (!$capture) {
    http_response_code(404);
    exit('El analisis solicitado no esta configurado para captura.');
}

$hasController = trim((string) ($capture['controller'] ?? '')) !== '';
if ($hasController) {
    lab_require_analysis_access((string) $capture['key']);
}

$catalogAnalysis = null;
$typeKey = labCatalogoMuestrasClaveDesdePrefijo(null, (string) $capture['tipo']);
$aliases = array_map('lab_captura_variables_normalizar', (array) $capture['aliases']);
foreach (labCatalogoAnalisisFilas($conexion, true) as $catalogRow) {
    $rowType = labCatalogoMuestrasClaveDesdePrefijo(
        (string) ($catalogRow['prefijo_muestra'] ?? ''),
        (string) ($catalogRow['nombre_muestra'] ?? '')
    );
    $rowName = lab_captura_variables_normalizar((string) ($catalogRow['nombre'] ?? ''));
    if ($rowType === $typeKey && in_array($rowName, $aliases, true)) {
        $catalogAnalysis = $catalogRow;
        break;
    }
}

$samples = [];
$idAnalysis = (int) ($catalogAnalysis['id_tipo'] ?? 0);
if ($idAnalysis > 0) {
    $stmt = $conexion->prepare("
        SELECT DISTINCT
            m.id_muestra,
            m.codigo_lab,
            m.numero_muestra,
            l.id_lote,
            l.codigo_lote,
            s.id_solicitud,
            s.fecha_ingreso,
            s.fecha_estimada
        FROM solicitud s
        INNER JOIN lote l ON l.id_lote = s.id_lote
        INNER JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
        INNER JOIN muestra m ON m.id_solicitud = s.id_solicitud
        WHERE sa.id_tipo_analisis = ?
          AND NOT EXISTS (
              SELECT 1
              FROM lote_rango lr_done
              INNER JOIN formulario f_done
                      ON f_done.id_rango = lr_done.id_rango
                     AND f_done.id_tipo_analisis = sa.id_tipo_analisis
              WHERE lr_done.id_lote = l.id_lote
          )
        ORDER BY l.codigo_lote ASC, m.numero_muestra ASC, m.id_muestra ASC
    ");
    $stmt->execute([$idAnalysis]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$lots = [];
foreach ($samples as $sample) {
    $lots[(int) ($sample['id_lote'] ?? 0)] = (string) ($sample['codigo_lote'] ?? '');
}

$rangeParts = [];
$minimum = $catalogAnalysis['limite_min'] ?? null;
$maximum = $catalogAnalysis['limite_max'] ?? null;
$unit = trim((string) ($catalogAnalysis['unidad'] ?? ''));
if ($minimum !== null && $minimum !== '') {
    $rangeParts[] = (string) $minimum;
}
if ($maximum !== null && $maximum !== '') {
    $rangeParts[] = (string) $maximum;
}
$expectedRange = $rangeParts ? implode(' - ', $rangeParts) . ($unit !== '' ? ' ' . $unit : '') : 'Sin rango configurado';

$frameUrl = '';
if ($hasController) {
    $controllerUrl = '../controllers/' . ltrim((string) $capture['controller'], '/');
    $frameUrl = $controllerUrl . '?' . http_build_query([
        'embed' => 1,
        'from' => 'bandeja',
        'analisis' => (string) $capture['key'],
        'catalogo_id' => $idAnalysis,
    ]);
}

function capture_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function capture_svg(string $name): string
{
    $paths = [
        'back' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'grid' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
        'tablet' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'save' => '<path d="M5 4h12l2 2v14H5zM8 4v6h8V4M8 16h8"/>',
        'send' => '<path d="m4 4 17 8-17 8 4-8zM8 12h13"/>',
        'sample' => '<path d="M8 3h8M9 3v13a3 3 0 0 0 6 0V3M9 9h6"/>',
        'lot' => '<path d="M4 7h16v13H4zM3 4h18v3H3M9 12h6"/>',
        'range' => '<path d="M4 12h16M7 9l-3 3 3 3M17 9l3 3-3 3"/>',
    ];
    return '<svg class="capture-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths['sample']) . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Captura de variables</title>
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
    <link rel="stylesheet" href="../styles/captura_variables_template.css?v=<?= (int) filemtime(__DIR__ . '/../styles/captura_variables_template.css') ?>">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('captura_variables.php', 'Captura de variables', (string) $capture['label'] . ' · ' . labCatalogoMuestrasEtiquetaModulo($typeKey)); ?>
    <div class="capture-page" data-capture-page>
        <div class="capture-topline">
            <a class="capture-back" href="solicitudes_pendientes_tecnico.php">
                <?= capture_svg('back') ?> Volver a mis colas
            </a>
            <div class="capture-segment" role="group" aria-label="Vista de captura">
                <button type="button" class="is-active" data-capture-mode="grid" aria-pressed="true"><?= capture_svg('grid') ?> Vista grilla</button>
                <button type="button" data-capture-mode="tablet" aria-pressed="false"><?= capture_svg('tablet') ?> Vista tablet</button>
            </div>
        </div>

        <section class="capture-summary" aria-label="Resumen de la cola">
            <div class="capture-heading">
                <span class="capture-kicker">Cola de captura</span>
                <h1><?= capture_e($capture['label']) ?> <span>· <?= capture_e(labCatalogoMuestrasEtiquetaModulo($typeKey)) ?></span></h1>
                <p><?= count($samples) ?> muestra<?= count($samples) === 1 ? '' : 's' ?> de <?= count($lots) ?> lote<?= count($lots) === 1 ? '' : 's' ?> pendiente<?= count($lots) === 1 ? '' : 's' ?>.</p>
            </div>
            <div class="capture-actions">
                <button type="button" class="capture-button capture-button--ghost" data-capture-save<?= $hasController ? '' : ' data-capture-disabled disabled title="Variables pendientes de configurar"' ?>><?= capture_svg('save') ?> Guardar todos</button>
                <button type="button" class="capture-button capture-button--primary" data-capture-submit<?= $hasController ? '' : ' data-capture-disabled disabled title="Variables pendientes de configurar"' ?>><?= capture_svg('send') ?> Enviar cola a validacion tecnica</button>
            </div>
        </section>

        <section class="capture-metrics" aria-label="Datos del analisis">
            <article><span><?= capture_svg('sample') ?></span><div><small>Muestras pendientes</small><strong><?= count($samples) ?></strong></div></article>
            <article><span><?= capture_svg('lot') ?></span><div><small>Lotes incluidos</small><strong><?= count($lots) ?></strong></div></article>
            <article><span><?= capture_svg('range') ?></span><div><small>Rango esperado</small><strong><?= capture_e($expectedRange) ?></strong></div></article>
        </section>

        <?php if (!$hasController): ?>
            <div class="capture-alert">Este analisis aun no tiene un formulario tecnico ni variables de almacenamiento configuradas. La cola se muestra aqui, pero el guardado permanecera deshabilitado hasta completar esa configuracion.</div>
        <?php elseif (!$catalogAnalysis): ?>
            <div class="capture-alert">El analisis existe en el flujo, pero no se encontro su registro activo en el catalogo. El formulario original seguira disponible.</div>
        <?php endif; ?>

        <section class="capture-workbench">
            <?php if ($hasController): ?>
                <div class="capture-frame-state" data-frame-state>Cargando variables del analisis…</div>
                <iframe
                    class="capture-frame"
                    data-capture-frame
                    src="<?= capture_e($frameUrl) ?>"
                    title="Formulario de captura de <?= capture_e($capture['label']) ?>"></iframe>
            <?php else: ?>
                <div class="capture-pending-head">
                    <div><span class="capture-kicker">Muestras de la cola</span><strong>Variables pendientes de configurar</strong></div>
                    <span><?= count($samples) ?> muestra<?= count($samples) === 1 ? '' : 's' ?></span>
                </div>
                <div class="capture-pending-wrap">
                    <table class="capture-pending-table">
                        <thead><tr><th>Muestra</th><th>Lote</th><th>Variable</th><th>Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($samples as $sample): ?>
                            <tr>
                                <td data-label="Muestra"><strong><?= capture_e($sample['codigo_lab'] ?: $sample['numero_muestra']) ?></strong></td>
                                <td data-label="Lote"><?= capture_e($sample['codigo_lote']) ?></td>
                                <td data-label="Variable"><span class="capture-unconfigured">Por configurar</span></td>
                                <td data-label="Estado"><span class="capture-status-pending">Pendiente</span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$samples): ?>
                            <tr><td colspan="4" class="capture-empty">No hay muestras pendientes para este analisis.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <script src="../js/captura_variables.js?v=<?= (int) filemtime(__DIR__ . '/../js/captura_variables.js') ?>"></script>
<?php lab_shell_close(); ?>
