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
$totalMuestras = 0;
$lotesPendientes = [];

foreach ($pendientes as $item) {
    $claveTipo = labCatalogoMuestrasClaveDesdePrefijo(null, (string) ($item['tipo_muestra'] ?? ''));
    $numeroMuestras = max(0, (int) ($item['numero_muestras'] ?? 0));
    $totalMuestras += $numeroMuestras;
    $lotesPendientes[(string) ($item['id_lote'] ?? '')] = true;

    if (!isset($pendientesPorTipo[$claveTipo])) {
        $pendientesPorTipo[$claveTipo] = [
            'clave' => $claveTipo,
            'label' => labCatalogoMuestrasEtiquetaModuloPlural($claveTipo),
            'orden' => labCatalogoMuestrasOrdenModulo($claveTipo),
            'analisis' => [],
        ];
    }

    $nombresAnalisis = array_values(array_unique(array_filter(array_map(
        'trim',
        explode('||', (string) ($item['analisis_pendientes'] ?? ''))
    ))));

    foreach ($nombresAnalisis as $nombreAnalisis) {
        $claveAnalisis = function_exists('mb_strtolower')
            ? mb_strtolower($nombreAnalisis, 'UTF-8')
            : strtolower($nombreAnalisis);

        if (!isset($pendientesPorTipo[$claveTipo]['analisis'][$claveAnalisis])) {
            $pendientesPorTipo[$claveTipo]['analisis'][$claveAnalisis] = [
                'nombre' => $nombreAnalisis,
                'muestras' => 0,
                'lotes' => [],
            ];
        }

        $claveLote = (string) ($item['id_lote'] ?? '') . ':' . (string) ($item['id_solicitud'] ?? '');
        $pendientesPorTipo[$claveTipo]['analisis'][$claveAnalisis]['muestras'] += $numeroMuestras;
        $pendientesPorTipo[$claveTipo]['analisis'][$claveAnalisis]['lotes'][$claveLote] = [
            'codigo_lote' => (string) ($item['codigo_lote'] ?? '-'),
            'numero_muestras' => $numeroMuestras,
            'id_solicitud' => (int) ($item['id_solicitud'] ?? 0),
        ];
    }
}

uasort($pendientesPorTipo, static function (array $left, array $right): int {
    return ($left['orden'] <=> $right['orden']) ?: strcasecmp($left['label'], $right['label']);
});

$totalColas = 0;
foreach ($pendientesPorTipo as &$grupo) {
    uasort($grupo['analisis'], static function (array $left, array $right): int {
        return strnatcasecmp($left['nombre'], $right['nombre']);
    });
    foreach ($grupo['analisis'] as &$analisis) {
        uasort($analisis['lotes'], static function (array $left, array $right): int {
            return strnatcasecmp($left['codigo_lote'], $right['codigo_lote']);
        });
    }
    unset($analisis);
    $totalColas += count($grupo['analisis']);
}
unset($grupo);

function ePendientes($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bandejaSvgIcono(string $nombre): string
{
    static $trazos = [
        'analisis' => '<path d="M9 3h6M10 3v5.2L5.6 18a2 2 0 0 0 1.8 3h9.2a2 2 0 0 0 1.8-3L14 8.2V3"/><path d="M8 15h8"/>',
        'lotes' => '<path d="M4 7.5h16v12.5H4z"/><path d="M3 4h18v3.5H3zM9 12h6"/>',
        'muestras' => '<path d="M8 3h8M9 3v13a3 3 0 0 0 6 0V3"/><path d="M9 9h6M11 13h4"/>',
        'suelos' => '<path d="m3 18 5.2-7 3.3 4.2L14.5 11l6.5 7H3Z"/><path d="m6.7 13 1.5-2 1.1 1.4"/>',
        'agua' => '<path d="M12 3s6 6.4 6 11a6 6 0 0 1-12 0c0-4.6 6-11 6-11Z"/><path d="M9.5 15.5c.7 1.2 1.6 1.7 3 1.7"/>',
        'foliares' => '<path d="M20 4C10 4 5 8.2 5 14c0 3.1 2.2 5 5 5 6 0 9-7 10-15Z"/><path d="M4 21c2.4-5.3 6.2-8.8 11-11"/>',
        'cana' => '<path d="M12 21V9M12 12c-4.2 0-7-2.2-7-6 4.2 0 7 2.2 7 6ZM12 16c4.2 0 7-2.2 7-6-4.2 0-7 2.2-7 6Z"/>',
        'mieles' => '<path d="m8 4 4-2 4 2 1 4-2 3H9L7 8l1-4Z"/><path d="M9 11 6 14l1 5 5 3 5-3 1-5-3-3"/>',
        'completo' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.7 2.7L16.5 9"/>',
        'buscar' => '<circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/>',
        'sin-resultados' => '<circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4M4 4l16 16"/>',
        'flecha' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];

    $trazo = $trazos[$nombre] ?? $trazos['analisis'];
    return '<svg class="tray-svg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $trazo . '</svg>';
}

function bandejaHrefCaptura(string $clave): string
{
    if ($clave === 'agua') {
        $clave = 'aguas';
    }
    $areas = ['suelos', 'aguas', 'foliares', 'cana', 'mieles'];
    return in_array($clave, $areas, true)
        ? 'labc_index.php?area=' . rawurlencode($clave) . '#section-' . rawurlencode($clave)
        : 'labc_index.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bandeja del analista</title>
    <link rel="stylesheet" href="../css/solicitudes_pendientes.css?v=<?= filemtime(__DIR__ . '/../css/solicitudes_pendientes.css') ?>">
    <link rel="stylesheet" href="../css/lab_shell.css?v=1">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('solicitudes_pendientes_tecnico.php', 'Bandeja del analista', 'Colas de análisis pendientes de captura'); ?>
    <div class="analyst-tray">
        <p class="tray-intro">Colas de análisis pendientes de captura. El trabajo se presenta por <strong>tipo de muestra</strong> y <strong>análisis</strong>; cada fila reúne los lotes y muestras que todavía requieren resultados.</p>

        <section class="tray-kpis" aria-label="Resumen de la bandeja">
            <article class="tray-kpi">
                <span class="tray-kpi-icon"><?= bandejaSvgIcono('analisis') ?></span>
                <div><span class="tray-kpi-label">Análisis pendientes</span><strong class="tray-kpi-value"><?= $totalColas ?></strong><small>colas de captura</small></div>
            </article>
            <article class="tray-kpi">
                <span class="tray-kpi-icon"><?= bandejaSvgIcono('lotes') ?></span>
                <div><span class="tray-kpi-label">Lotes pendientes</span><strong class="tray-kpi-value"><?= count($lotesPendientes) ?></strong><small>con trabajo pendiente</small></div>
            </article>
            <article class="tray-kpi">
                <span class="tray-kpi-icon"><?= bandejaSvgIcono('muestras') ?></span>
                <div><span class="tray-kpi-label">Muestras en cola</span><strong class="tray-kpi-value"><?= $totalMuestras ?></strong><small>por capturar</small></div>
            </article>
        </section>

        <?php if (empty($pendientesPorTipo)): ?>
            <section class="tray-empty">
                <span class="tray-empty-icon"><?= bandejaSvgIcono('completo') ?></span>
                <h2>Sin análisis pendientes</h2>
                <p>No hay solicitudes de análisis pendientes de captura en este momento.</p>
            </section>
        <?php else: ?>
            <section class="tray-toolbar" aria-label="Filtros de la bandeja">
                <div class="tray-filter">
                    <label for="filterSampleType">Tipo de muestra</label>
                    <select id="filterSampleType">
                        <option value="">Todos los tipos de muestra</option>
                        <?php foreach ($pendientesPorTipo as $grupo): ?>
                            <option value="<?= ePendientes($grupo['clave']) ?>"><?= ePendientes($grupo['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tray-search">
                    <label for="filterAnalysis">Buscar en la cola</label>
                    <div class="tray-search-control">
                        <span class="tray-search-icon"><?= bandejaSvgIcono('buscar') ?></span>
                        <input id="filterAnalysis" type="search" placeholder="Análisis, lote o solicitud..." autocomplete="off">
                    </div>
                </div>
            </section>

            <div class="tray-section-title">
                <div><span class="tray-eyebrow">Trabajo disponible</span><h1>Cola de captura</h1></div>
                <span class="tray-visible-count" id="visibleQueueCount"><?= $totalColas ?> análisis</span>
            </div>

            <div id="analysisQueue">
                <?php foreach ($pendientesPorTipo as $grupo): ?>
                    <section class="sample-cube" data-sample-type="<?= ePendientes($grupo['clave']) ?>">
                        <header class="sample-cube-head">
                            <span class="sample-cube-icon"><?= bandejaSvgIcono($grupo['clave']) ?></span>
                            <div><h2><?= ePendientes($grupo['label']) ?></h2><p><?= count($grupo['analisis']) ?> análisis pendiente<?= count($grupo['analisis']) === 1 ? '' : 's' ?></p></div>
                        </header>

                        <div class="tray-table-wrap">
                            <table class="tray-table">
                                <thead><tr><th>Análisis</th><th>Lotes pendientes</th><th>Muestras</th><th><span class="sr-only">Acción</span></th></tr></thead>
                                <tbody>
                                    <?php foreach ($grupo['analisis'] as $analisis): ?>
                                        <?php
                                            $textoBusqueda = $analisis['nombre'] . ' ' . implode(' ', array_map(
                                                static function (array $lote): string {
                                                    return $lote['codigo_lote'] . ' ' . $lote['id_solicitud'];
                                                },
                                                $analisis['lotes']
                                            ));
                                        ?>
                                        <tr class="analysis-queue-row" data-search="<?= ePendientes($textoBusqueda) ?>">
                                            <td class="analysis-name">
                                                <strong><?= ePendientes($analisis['nombre']) ?></strong>
                                                <small><?= (int) $analisis['muestras'] ?> muestra<?= (int) $analisis['muestras'] === 1 ? '' : 's' ?> en total</small>
                                            </td>
                                            <td class="lot-chip-cell">
                                                <?php foreach ($analisis['lotes'] as $lote): ?>
                                                    <span class="lot-chip" title="Solicitud #<?= (int) $lote['id_solicitud'] ?>">
                                                        <strong><?= ePendientes($lote['codigo_lote']) ?></strong>
                                                        <small><?= (int) $lote['numero_muestras'] ?> muestra<?= (int) $lote['numero_muestras'] === 1 ? '' : 's' ?></small>
                                                    </span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="sample-count"><strong><?= (int) $analisis['muestras'] ?></strong><span>pendiente<?= (int) $analisis['muestras'] === 1 ? '' : 's' ?></span></td>
                                            <td class="queue-action"><a class="capture-button" href="<?= ePendientes(bandejaHrefCaptura($grupo['clave'])) ?>">Capturar <?= bandejaSvgIcono('flecha') ?></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <section class="tray-empty tray-empty-filter" id="filterEmptyState" hidden>
                <span class="tray-empty-icon"><?= bandejaSvgIcono('sin-resultados') ?></span>
                <h2>Sin resultados</h2>
                <p>No hay análisis pendientes que coincidan con los filtros actuales.</p>
                <button type="button" id="clearFilters">Limpiar filtros</button>
            </section>
        <?php endif; ?>
    </div>
<?php lab_shell_content_close(); ?>

<?php if (!empty($pendientesPorTipo)): ?>
<script>
(function () {
    const typeFilter = document.getElementById('filterSampleType');
    const searchFilter = document.getElementById('filterAnalysis');
    const cubes = Array.from(document.querySelectorAll('.sample-cube'));
    const emptyState = document.getElementById('filterEmptyState');
    const visibleCount = document.getElementById('visibleQueueCount');
    const clearButton = document.getElementById('clearFilters');

    function normalize(value) {
        return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    function applyFilters() {
        const selectedType = typeFilter.value;
        const query = normalize(searchFilter.value);
        let visibleRows = 0;
        cubes.forEach(function (cube) {
            const typeMatches = !selectedType || cube.dataset.sampleType === selectedType;
            let cubeRows = 0;
            cube.querySelectorAll('.analysis-queue-row').forEach(function (row) {
                const show = typeMatches && (!query || normalize(row.dataset.search).includes(query));
                row.hidden = !show;
                if (show) { cubeRows += 1; visibleRows += 1; }
            });
            cube.hidden = cubeRows === 0;
        });
        visibleCount.textContent = visibleRows + ' análisis';
        emptyState.hidden = visibleRows !== 0;
    }

    typeFilter.addEventListener('change', applyFilters);
    searchFilter.addEventListener('input', applyFilters);
    clearButton.addEventListener('click', function () {
        typeFilter.value = '';
        searchFilter.value = '';
        searchFilter.focus();
        applyFilters();
    });
}());
</script>
<?php endif; ?>
</body>
</html>
