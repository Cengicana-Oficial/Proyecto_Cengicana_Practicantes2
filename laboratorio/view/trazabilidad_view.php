<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/trazabilidad_model.php';

lab_require_permission('laboratorio.trazabilidad.ver');

$pdo = Conexion::conectar();
$historial = lab_historial_versiones_muestras($pdo);
$seleccionInicial = $historial[0]['key'] ?? '';
$tablaDisponible = lab_trazabilidad_tabla_existe($pdo, 'formulario_version');

function hist_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hist_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return 'Pendiente de captura';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha;
}

function hist_svg(string $name): string
{
    $paths = [
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'versions' => '<rect x="6" y="4" width="12" height="14" rx="2"/><path d="M9 8h6M9 12h6M8 21h8"/>',
        'error' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
        'empty' => '<path d="M4 6h16M7 3h10l1 3H6l1-3zM6 6l1 15h10l1-15M10 10v7M14 10v7"/>',
    ];
    return '<svg class="history-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths['history']) . '</svg>';
}

lab_shell_head('Historial de versiones', 'Trazabilidad completa de reanálisis', [
    '../css/lab_shell.css?v=1',
    '../styles/historial_versiones.css?v=' . (int) filemtime(__DIR__ . '/../styles/historial_versiones.css'),
]);
lab_shell_open('trazabilidad_view.php');
?>

<main class="history-page" data-history-page data-initial-selection="<?= hist_e($seleccionInicial) ?>">
    <?php if (!$tablaDisponible): ?>
        <div class="history-alert history-alert--info">
            <?= hist_svg('history') ?>
            <div>El almacenamiento de versiones todavía no está habilitado en esta base de datos. La pantalla comenzará a mostrar los reanálisis cuando esté disponible la tabla <code>formulario_version</code>.</div>
        </div>
    <?php endif; ?>

    <div class="history-grid">
        <aside class="history-card history-list-card">
            <div class="history-card-heading">
                <h2>Muestras con reanálisis</h2>
                <p>Cada combinación muestra + análisis mantiene su propio historial de versiones</p>
            </div>

            <div class="history-list" data-history-list>
                <?php if (!$historial): ?>
                    <div class="history-empty history-empty--compact">
                        <?= hist_svg('versions') ?>
                        <span>Sin reanálisis registrados.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($historial as $indice => $item): ?>
                        <?php $panelId = 'history-panel-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $item['key']); ?>
                        <button
                            type="button"
                            class="history-list-item<?= $indice === 0 ? ' is-active' : '' ?>"
                            data-history-select="<?= hist_e($item['key']) ?>"
                            data-history-title="<?= hist_e($item['muestra'] . ' — ' . $item['analisis']) ?>"
                            aria-pressed="<?= $indice === 0 ? 'true' : 'false' ?>"
                            aria-controls="<?= hist_e($panelId) ?>">
                            <span>
                                <strong><?= hist_e($item['muestra']) ?></strong>
                                <small><?= hist_e($item['lote']) ?> · <?= hist_e($item['analisis']) ?></small>
                            </span>
                            <b><?= count($item['versiones']) ?> versiones</b>
                        </button>
                    <?php endforeach; ?>
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
                        <span>Los rechazos y reanálisis aparecerán aquí automáticamente, conservando el valor, analista, fecha y motivo de cada versión.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($historial as $indice => $item): ?>
                        <?php $panelId = 'history-panel-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $item['key']); ?>
                        <div
                            id="<?= hist_e($panelId) ?>"
                            class="history-timeline"
                            data-history-panel="<?= hist_e($item['key']) ?>"
                            <?= $indice === 0 ? '' : 'hidden' ?>>
                            <?php foreach ($item['versiones'] as $versionIndex => $version): ?>
                                <article class="history-version history-version--<?= hist_e($version['estado']['key']) ?>">
                                    <div class="history-version-track">
                                        <span>v<?= (int) $version['numero'] ?></span>
                                        <?php if ($versionIndex < count($item['versiones']) - 1): ?><i></i><?php endif; ?>
                                    </div>
                                    <div class="history-version-content">
                                        <div class="history-version-head">
                                            <h3>Versión <?= (int) $version['numero'] ?></h3>
                                            <span class="history-status history-status--<?= hist_e($version['estado']['key']) ?>"><?= hist_e($version['estado']['label']) ?></span>
                                        </div>
                                        <p class="history-version-meta">
                                            <?= hist_e(hist_fecha($version['fecha'])) ?> · <?= hist_e($version['usuario']) ?>
                                            <?php if ($version['valor'] !== '-'): ?> · Valor: <strong><?= hist_e($version['valor']) ?></strong><?php endif; ?>
                                        </p>
                                        <?php if (!empty($version['motivo'])): ?>
                                            <div class="history-alert history-alert--error">
                                                <?= hist_svg('error') ?>
                                                <div><strong>Motivo de rechazo:</strong> <?= hist_e($version['motivo']) ?></div>
                                            </div>
                                        <?php elseif (!empty($version['comentario'])): ?>
                                            <p class="history-version-comment"><?= hist_e($version['comentario']) ?></p>
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

<script src="../js/historial_versiones.js?v=<?= (int) filemtime(__DIR__ . '/../js/historial_versiones.js') ?>"></script>
<?php lab_shell_close(); ?>
