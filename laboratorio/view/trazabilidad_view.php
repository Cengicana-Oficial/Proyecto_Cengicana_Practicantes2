<?php
/**
 * Historial de versiones: una entrada por muestra + análisis con reanálisis o rechazo.
 *  - Flujo actual (Bandeja del analista → Validación técnica): cada envío de hoja es una versión
 *    (lab_hoja_formulario + historial_formulario). Ver lab_historial_versiones_flujo().
 *  - Formularios de captura anteriores: versiones guardadas en formulario_version.
 * Cada versión conserva sus datos originales; el resultado se calcula en el navegador con las
 * mismas fórmulas de Validación técnica (hojas_definiciones.js).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/workflow_trabajo_helper.php';
require_once __DIR__ . '/../models/trazabilidad_model.php';

lab_require_permission('laboratorio.trazabilidad.ver');

$pdo = Conexion::conectar();
$historial = [];
$errorCarga = '';
try {
    $historial = lab_historial_versiones_flujo($pdo);
} catch (Throwable $e) {
    error_log('[laboratorio][historial] flujo: ' . $e->getMessage());
    $errorCarga = 'No se pudo leer el historial del flujo de trabajo.';
}
try {
    foreach (lab_historial_versiones_muestras($pdo) as $item) {
        $item['origen'] = 'anterior';
        $item['estado_actual'] = end($item['versiones'])['estado'] ?? ['key' => 'revision', 'label' => 'EN REVISIÓN'];
        $historial[] = $item;
    }
} catch (Throwable $e) {
    error_log('[laboratorio][historial] formularios anteriores: ' . $e->getMessage());
}
usort($historial, static fn($a, $b) => strcmp((string) ($b['ultima_fecha'] ?? ''), (string) ($a['ultima_fecha'] ?? '')));

$seleccionInicial = $historial[0]['key'] ?? '';
$puedeVerHoja = lab_can('laboratorio.validacion_tecnica.ver');
$resumen = ['total' => count($historial), 'versiones' => 0, 'rechazos' => 0, 'aprobada' => 0, 'pendientes' => 0];
foreach ($historial as $item) {
    $resumen['versiones'] += count($item['versiones']);
    $resumen['rechazos'] += count(array_filter($item['versiones'], static fn($v) => $v['estado']['key'] === 'rechazada'));
    $actual = $item['estado_actual']['key'] ?? 'revision';
    if ($actual === 'aprobada') $resumen['aprobada']++; else $resumen['pendientes']++;
}

function hist_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hist_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return 'Sin fecha';
    }
    $ts = strtotime($fecha);
    return $ts ? date(strlen($fecha) > 10 ? 'd/m/Y H:i' : 'd/m/Y', $ts) : $fecha;
}

function hist_svg(string $name): string
{
    $paths = [
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'versions' => '<rect x="6" y="4" width="12" height="14" rx="2"/><path d="M9 8h6M9 12h6M8 21h8"/>',
        'error' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
        'empty' => '<path d="M4 6h16M7 3h10l1 3H6l1-3zM6 6l1 15h10l1-15M10 10v7M14 10v7"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'open' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    ];
    return '<svg class="history-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths['history']) . '</svg>';
}

lab_shell_head('Historial de versiones', 'Trazabilidad completa de rechazos y reanálisis', [
    '../css/lab_shell.css?v=20261006',
    '../styles/historial_versiones.css?v=' . (int) filemtime(__DIR__ . '/../styles/historial_versiones.css'),
]);
lab_shell_open('trazabilidad_view.php');
?>

<main class="history-page" data-history-page data-initial-selection="<?= hist_e($seleccionInicial) ?>">
    <?php if ($errorCarga !== ''): ?>
        <div class="history-alert history-alert--error"><?= hist_svg('error') ?><div><?= hist_e($errorCarga) ?></div></div>
    <?php endif; ?>

    <section class="history-summary" aria-label="Resumen del historial">
        <article><span>Muestras con reanálisis</span><strong><?= $resumen['total'] ?></strong></article>
        <article><span>Versiones registradas</span><strong><?= $resumen['versiones'] ?></strong></article>
        <article class="is-warn"><span>Rechazos y reanálisis</span><strong><?= $resumen['rechazos'] ?></strong></article>
        <article class="is-ok"><span>Ya aprobadas</span><strong><?= $resumen['aprobada'] ?></strong></article>
        <article><span>Pendientes</span><strong><?= $resumen['pendientes'] ?></strong></article>
    </section>

    <div class="history-grid">
        <aside class="history-card history-list-card">
            <div class="history-card-heading">
                <h2>Muestras con reanálisis</h2>
                <p>Cada combinación muestra + análisis mantiene su propio historial de versiones</p>
            </div>
            <?php if ($historial): ?>
                <div class="history-filters">
                    <label class="history-search"><?= hist_svg('search') ?><span class="sr-only">Buscar</span>
                        <input type="search" data-history-search placeholder="Buscar muestra, lote o análisis" autocomplete="off"></label>
                    <div class="history-chips" role="group" aria-label="Filtrar por estado actual">
                        <button type="button" class="is-on" data-history-filter="">Todas</button>
                        <button type="button" data-history-filter="revision">Pendientes</button>
                        <button type="button" data-history-filter="rechazada">Rechazadas</button>
                        <button type="button" data-history-filter="aprobada">Aprobadas</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="history-list" data-history-list>
                <?php if (!$historial): ?>
                    <div class="history-empty history-empty--compact">
                        <?= hist_svg('versions') ?>
                        <span>Sin reanálisis registrados.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($historial as $indice => $item): ?>
                        <?php $panelId = 'history-panel-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $item['key']);
                        $estadoActual = $item['estado_actual']['key'] ?? 'revision'; ?>
                        <button
                            type="button"
                            class="history-list-item<?= $indice === 0 ? ' is-active' : '' ?>"
                            data-history-select="<?= hist_e($item['key']) ?>"
                            data-history-title="<?= hist_e($item['muestra'] . ' — ' . $item['analisis']) ?>"
                            data-history-estado="<?= hist_e($estadoActual === 'aprobada' ? 'aprobada' : ($estadoActual === 'rechazada' ? 'rechazada' : 'revision')) ?>"
                            data-history-text="<?= hist_e(mb_strtolower($item['muestra'] . ' ' . $item['lote'] . ' ' . $item['analisis'], 'UTF-8')) ?>"
                            aria-pressed="<?= $indice === 0 ? 'true' : 'false' ?>"
                            aria-controls="<?= hist_e($panelId) ?>">
                            <span>
                                <strong><?= hist_e($item['muestra']) ?></strong>
                                <small><?= hist_e($item['lote']) ?> · <?= hist_e($item['analisis']) ?></small>
                            </span>
                            <span class="history-list-meta">
                                <b><?= count($item['versiones']) ?> versiones</b>
                                <span class="history-status history-status--<?= hist_e($estadoActual) ?>"><?= hist_e($item['estado_actual']['label'] ?? '') ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                    <div class="history-empty history-empty--compact" data-history-nomatch hidden><?= hist_svg('versions') ?><span>Ninguna muestra coincide con el filtro.</span></div>
                <?php endif; ?>
            </div>
        </aside>

        <section class="history-card history-detail-card">
            <div class="history-card-heading">
                <h2 data-history-current-title><?= $historial ? hist_e($historial[0]['muestra'] . ' — ' . $historial[0]['analisis']) : 'Selecciona una muestra' ?></h2>
                <p>Cada versión conserva sus datos originales; nada se sobrescribe</p>
            </div>

            <div class="history-timelines" data-history-timelines>
                <?php if (!$historial): ?>
                    <div class="history-empty">
                        <?= hist_svg('empty') ?>
                        <strong>No hay versiones para mostrar</strong>
                        <span>Cuando Validación técnica rechace un análisis o devuelva muestras a reanálisis, aquí aparecerá cada versión con su valor, analista, fecha y motivo.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($historial as $indice => $item): ?>
                        <?php $panelId = 'history-panel-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $item['key']); ?>
                        <div id="<?= hist_e($panelId) ?>" class="history-timeline" data-history-panel="<?= hist_e($item['key']) ?>" <?= $indice === 0 ? '' : 'hidden' ?>>
                            <?php foreach ($item['versiones'] as $versionIndex => $version): ?>
                                <article class="history-version history-version--<?= hist_e($version['estado']['key']) ?>">
                                    <div class="history-version-track">
                                        <span>v<?= (int) $version['numero'] ?></span>
                                        <?php if ($versionIndex < count($item['versiones']) - 1): ?><i></i><?php endif; ?>
                                    </div>
                                    <div class="history-version-content">
                                        <div class="history-version-head">
                                            <h3>Versión <?= (int) $version['numero'] ?><?= !empty($version['reanalisis']) ? ' <small>· devuelta a reanálisis</small>' : '' ?></h3>
                                            <span class="history-status history-status--<?= hist_e($version['estado']['key']) ?>"><?= hist_e($version['estado']['label']) ?></span>
                                        </div>
                                        <p class="history-version-meta">
                                            Capturada <?= hist_e(hist_fecha($version['fecha'])) ?> · <?= hist_e($version['usuario']) ?>
                                            <?php if (!empty($version['equipo'])): ?> · Equipo: <?= hist_e($version['equipo']) ?><?php endif; ?>
                                        </p>
                                        <?php if (isset($version['calc'])): ?>
                                            <p class="history-version-result" data-history-calc="<?= hist_e(json_encode($version['calc'], JSON_UNESCAPED_UNICODE)) ?>" hidden></p>
                                        <?php elseif (($version['valor'] ?? '-') !== '-'): ?>
                                            <p class="history-version-result">Valor: <strong><?= hist_e($version['valor']) ?></strong></p>
                                        <?php endif; ?>
                                        <?php if (!empty($version['lecturas'])): ?>
                                            <details class="history-version-data">
                                                <summary>Datos capturados (<?= count($version['lecturas']) ?>)</summary>
                                                <ul><?php foreach ($version['lecturas'] as $lectura): ?><li><?= hist_e($lectura) ?></li><?php endforeach; ?></ul>
                                            </details>
                                        <?php endif; ?>
                                        <?php if (!empty($version['motivo'])): ?>
                                            <div class="history-alert history-alert--error">
                                                <?= hist_svg('error') ?>
                                                <div><strong><?= !empty($version['reanalisis']) ? 'Motivo del reanálisis' : 'Motivo de rechazo' ?>:</strong> <?= hist_e($version['motivo']) ?></div>
                                            </div>
                                        <?php elseif (!empty($version['comentario'])): ?>
                                            <p class="history-version-comment"><?= hist_e($version['comentario']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($version['validado_por']) || ($puedeVerHoja && !empty($version['id_formulario']))): ?>
                                            <p class="history-version-foot">
                                                <?php if (!empty($version['validado_por'])): ?>
                                                    <?= $version['estado']['key'] === 'aprobada' ? 'Aprobada' : ($version['estado']['key'] === 'rechazada' ? 'Rechazada' : 'Revisada') ?> por <?= hist_e($version['validado_por']) ?> · <?= hist_e(hist_fecha($version['validado_fecha'])) ?>
                                                <?php endif; ?>
                                                <?php if ($puedeVerHoja && !empty($version['id_formulario'])): ?>
                                                    <a href="hoja_revision.php?id_formulario=<?= (int) $version['id_formulario'] ?>"><?= hist_svg('open') ?> Ver hoja de revisión</a>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<script src="../js/hojas_definiciones.js?v=<?= (int) filemtime(__DIR__ . '/../js/hojas_definiciones.js') ?>"></script>
<script src="../js/validacion_tecnica.js?v=<?= (int) filemtime(__DIR__ . '/../js/validacion_tecnica.js') ?>"></script>
<script src="../js/historial_versiones.js?v=<?= (int) filemtime(__DIR__ . '/../js/historial_versiones.js') ?>"></script>
<?php lab_shell_close(); ?>
