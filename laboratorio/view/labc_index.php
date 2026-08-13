<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/labc_bandeja_model.php';

$conexion = Conexion::conectar();

lab_require_module_access();

if (!lab_can_any([
    'laboratorio.labc.ver',
    'laboratorio.formularios_labc.ver',
    'laboratorio.analisis.ver',
    'laboratorio.catalogo_analisis.ver',
    'laboratorio.catalogo_muestras.ver',
    'laboratorio.blanco_control.ver',
    'laboratorio.consolidacion.ver',
])) {
    lab_forbidden('No tiene permisos para acceder al LABC.');
}

$canCreateSolicitud = lab_can('laboratorio.solicitudes.crear');
$canConsolidacion = lab_can('laboratorio.consolidacion.ver');
$canBlancoControl = lab_can('laboratorio.blanco_control.ver');

function labc_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function labc_visible_analysis(array $items): array
{
    return array_values(array_filter($items, static function ($item) {
        return lab_can_analysis($item['key']);
    }));
}

function labc_card_icon(string $key): string
{
    static $icons = [
        'suelos' => 'fa-mountain',
        'aguas' => 'fa-droplet',
        'foliares' => 'fa-leaf',
        'cana' => 'fa-tractor',
        'mieles' => 'fa-mug-hot',
    ];

    return $icons[$key] ?? 'fa-flask';
}

function labc_section_href(string $area): string
{
    return '?area=' . rawurlencode($area) . '#section-' . $area;
}

function labc_history_href(string $area): ?string
{
    switch ($area) {
        case 'suelos':
        case 'aguas':
        case 'foliares':
        case 'cana':
            return 'dashboard.php?tipo_reporte=' . rawurlencode($area);
        default:
            return null;
    }
}

$sampleTypeKeys = ['suelos', 'aguas', 'foliares', 'cana', 'mieles'];
$sampleTypesByKey = [];
foreach ($sampleTypeKeys as $sampleTypeKey) {
    $sampleTypesByKey[$sampleTypeKey] = labCatalogoMuestrasObtenerPorClave($conexion, $sampleTypeKey, true) ?: [
        'id_tipo' => 0,
        'nombre' => $sampleTypeKey,
        'prefijo' => strtoupper(substr($sampleTypeKey, 0, 1)),
        'clave' => $sampleTypeKey,
        'label' => ucfirst($sampleTypeKey),
        'label_plural' => ucfirst($sampleTypeKey),
    ];
}

$activeArea = trim((string) ($_GET['area'] ?? 'aguas'));
if (!in_array($activeArea, $sampleTypeKeys, true)) {
    $activeArea = 'aguas';
}

$suelosFisicos = labc_visible_analysis([
    ['key' => 'suelos.textura', 'href' => '../controllers/Suelos/textura_controller.php', 'label' => 'Textura'],
    ['key' => 'suelos.humedad_residual', 'href' => '../controllers/Suelos/humedad_residual_controller.php', 'label' => 'Humedad Residual'],
    ['key' => 'suelos.humedad_gravimetrica', 'href' => '../controllers/Suelos/humedad_gravimetrica_controller.php', 'label' => 'Humedad gravimetrica'],
    ['key' => 'suelos.dap', 'href' => '../controllers/Suelos/dap_controller.php', 'label' => 'Densidad aparente (DAP)'],
    ['key' => 'suelos.cc', 'href' => '../controllers/Suelos/cc_controller.php', 'label' => 'Capacidad de Campo'],
    ['key' => 'suelos.pmp', 'href' => '../controllers/Suelos/pmp_controller.php', 'label' => 'Punto de Marchitez Permanente'],
]);

$suelosQuimicos = labc_visible_analysis([
    ['key' => 'suelos.ph', 'href' => '../controllers/Suelos/ph_controller.php', 'label' => 'pH'],
    ['key' => 'suelos.mo', 'href' => '../controllers/Suelos/mo_controller.php', 'label' => '%MO'],
    ['key' => 'suelos.macroscic', 'href' => '../controllers/Suelos/macroscic_controller.php', 'label' => 'Macronutrientes y CIC'],
    ['key' => 'suelos.micros', 'href' => '../controllers/Suelos/micros_controller.php', 'label' => 'Micro Nutrientes (Cu, Zn, Fe, Mn, K)'],
    ['key' => 'suelos.nitrogeno', 'href' => '../controllers/Suelos/nitrogeno_controller.php', 'label' => 'Nitrógeno'],
    ['key' => 'suelos.boro', 'href' => '../controllers/Suelos/boro_controller.php', 'label' => 'Boro'],
    ['key' => 'suelos.azufre', 'href' => '../controllers/Suelos/azufre_controller.php', 'label' => 'Azufre'],
    ['key' => 'suelos.fosforo', 'href' => '../controllers/Suelos/fosforo_controller.php', 'label' => 'Fósforo'],
    ['key' => 'suelos.conductividad_electrica', 'href' => '../controllers/Suelos/conductividad_electrica_controller.php', 'label' => 'Conductividad Eléctrica'],
]);

$aguasAnalyses = labc_visible_analysis([
    ['key' => 'aguas.macros', 'href' => '../controllers/Aguas/macros_controller.php', 'label' => 'Macronutrientes'],
    ['key' => 'aguas.ras', 'href' => '../controllers/Aguas/ras_controller.php', 'label' => 'RAS'],
    ['key' => 'aguas.boro', 'href' => '../controllers/Aguas/boro_controller.php', 'label' => 'Boro'],
    ['key' => 'aguas.ph', 'href' => '../controllers/Aguas/ph_controller.php', 'label' => 'pH'],
    ['key' => 'aguas.salinidad', 'href' => '../controllers/Aguas/salinidad_controller.php', 'label' => 'Salinidad'],
    ['key' => 'aguas.dureza', 'href' => '../controllers/Aguas/dureza_controller.php', 'label' => 'Dureza'],
    ['key' => 'aguas.carbonatos', 'href' => '../controllers/Aguas/carbonatos_controller.php', 'label' => 'Carbonatos'],
    ['key' => 'aguas.micros', 'href' => '../controllers/Aguas/micros_controller.php', 'label' => 'Micro Nutrientes (Cu, Zn, Fe, Mn)'],
    ['key' => 'aguas.fosforo', 'href' => '../controllers/Aguas/fosforo_controller.php', 'label' => 'Fósforo'],
    ['key' => 'aguas.conductividad', 'href' => '../controllers/Aguas/conductividad_controller.php', 'label' => 'Conductividad Eléctrica'],
    ['key' => 'aguas.tds', 'href' => '../controllers/Aguas/tds_controller.php', 'label' => 'TDS'],
    ['key' => 'aguas.resistividad', 'href' => '../controllers/Aguas/resistividad_controller.php', 'label' => 'Resistividad'],
    ['key' => 'aguas.cloruros', 'href' => '../controllers/Aguas/cloruros_controller.php', 'label' => 'Cloruros'],
    ['key' => 'aguas.alcanilidad', 'href' => '../controllers/Aguas/alcanilidad_controller.php', 'label' => 'Alcalinidad'],
    ['key' => 'aguas.bicarbonato', 'href' => '../controllers/Aguas/bicarbonato_controller.php', 'label' => 'Bicarbonatos'],
]);

$foliaresAnalyses = labc_visible_analysis([
    ['key' => 'foliares.macros', 'href' => '../controllers/Foliares/macros_controller.php', 'label' => 'Macronutrientes'],
    ['key' => 'foliares.nitrogeno', 'href' => '../controllers/Foliares/nitrogeno_controller.php', 'label' => 'Nitrógeno'],
    ['key' => 'foliares.boro', 'href' => '../controllers/Foliares/boro_controller.php', 'label' => 'Boro'],
    ['key' => 'foliares.micros', 'href' => '../controllers/Foliares/micros_controller.php', 'label' => 'Micro Nutrientes (Cu, Zn, Fe, Mn, K)'],
    ['key' => 'foliares.fosforo', 'href' => '../controllers/Foliares/fosforo_controller.php', 'label' => 'Fósforo'],
]);

$canaAnalyses = labc_visible_analysis([
    ['key' => 'cana.peso_seco', 'href' => '../controllers/Cana/peso_seco_controller.php', 'label' => 'Peso seco'],
    ['key' => 'cana.fibra', 'href' => '../controllers/Cana/fibra_controller.php', 'label' => 'Fibra'],
    ['key' => 'cana.humedad', 'href' => '../controllers/Cana/humedad_controller.php', 'label' => '% de Humedad'],
    ['key' => 'cana.brixpol', 'href' => '../controllers/Cana/brixpol_controller.php', 'label' => 'Determinación de Brix y Pol'],
]);

$mielesAnalyses = labc_visible_analysis([
    ['key' => 'mieles.brix', 'href' => '../controllers/Mieles/brix_controller.php', 'label' => 'Brix'],
]);

$analysisRegistryByArea = [
    'suelos' => array_merge($suelosFisicos, $suelosQuimicos),
    'aguas' => $aguasAnalyses,
    'foliares' => $foliaresAnalyses,
    'cana' => $canaAnalyses,
    'mieles' => $mielesAnalyses,
];

$sampleTypeConfigs = [
    'suelos' => [
        'id' => 'suelos',
        'nav_label' => 'Suelos',
        'title' => 'Bandeja de Suelos',
        'subtitle' => 'Revisa los análisis pendientes para el tipo de muestra seleccionado.',
        'theme' => 'suelos',
        'history_url' => labc_history_href('suelos'),
        'analyses' => $analysisRegistryByArea['suelos'],
    ],
    'aguas' => [
        'id' => 'aguas',
        'nav_label' => 'Aguas',
        'title' => 'Bandeja de Aguas',
        'subtitle' => 'Revisa los análisis pendientes para el tipo de muestra seleccionado.',
        'theme' => 'aguas',
        'history_url' => labc_history_href('aguas'),
        'analyses' => $analysisRegistryByArea['aguas'],
    ],
    'foliares' => [
        'id' => 'foliares',
        'nav_label' => 'Foliares',
        'title' => 'Bandeja de Foliares',
        'subtitle' => 'Revisa los análisis pendientes para el tipo de muestra seleccionado.',
        'theme' => 'foliares',
        'history_url' => labc_history_href('foliares'),
        'analyses' => $analysisRegistryByArea['foliares'],
    ],
    'cana' => [
        'id' => 'cana',
        'nav_label' => 'Caña',
        'title' => 'Bandeja de Caña',
        'subtitle' => 'Revisa los análisis pendientes para el tipo de muestra seleccionado.',
        'theme' => 'cana',
        'history_url' => labc_history_href('cana'),
        'analyses' => $analysisRegistryByArea['cana'],
    ],
    'mieles' => [
        'id' => 'mieles',
        'nav_label' => 'Mieles',
        'title' => 'Bandeja de Mieles',
        'subtitle' => 'Revisa los análisis pendientes para el tipo de muestra seleccionado.',
        'theme' => 'mieles',
        'history_url' => null,
        'analyses' => $analysisRegistryByArea['mieles'],
    ],
];

$sections = [];
$sectionsById = [];
foreach ($sampleTypeKeys as $key) {
    if (isset($sampleTypeConfigs[$key])) {
        $section = $sampleTypeConfigs[$key];
        $sections[] = $section;
        $sectionsById[$key] = $section;
    }
}

$activeSection = $sectionsById[$activeArea] ?? ($sections[0] ?? null);
$activeSampleTypeId = (int) ($sampleTypesByKey[$activeArea]['id_tipo'] ?? 0);
$activeAnalysisRegistry = $sampleTypeConfigs[$activeArea]['analyses'] ?? [];

$tray = $activeSampleTypeId > 0
    ? labcBandejaConsultarAnalisisPendientes($conexion, $activeSampleTypeId, $activeAnalysisRegistry)
    : [
        'tipo_muestra' => null,
        'analisis' => [],
        'resumen' => [
            'analisis_pendientes' => 0,
            'lotes_pendientes' => 0,
            'muestras_pendientes' => 0,
        ],
    ];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bandeja del analista</title>
    <link rel="stylesheet" href="../styles/base.css">
    <link rel="stylesheet" href="../css/formularios_dashboard.css?v=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <div class="lab-app">
        <aside class="lab-sidebar">
            <div class="lab-brand">
                <div class="lab-brand-mark">LAB</div>
                <div class="lab-brand-copy">
                    <span class="lab-brand-kicker">Gestion LAB</span>
                    <strong>Bandeja Operativa</strong>
                </div>
            </div>

            <nav class="lab-nav" aria-label="Tipos de muestra">
                <?php foreach ($sections as $section): ?>
                    <a
                        class="lab-nav-item <?= $activeArea === $section['id'] ? 'active' : '' ?>"
                        href="<?= labc_e(labc_section_href($section['id'])) ?>"
                        aria-current="<?= $activeArea === $section['id'] ? 'page' : 'false' ?>">
                        <span class="lab-nav-icon"><i class="fa-solid <?= labc_e(labc_card_icon($section['id'])) ?>"></i></span>
                        <span class="lab-nav-text">
                            <strong><?= labc_e($section['nav_label']) ?></strong>
                        </span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-footer">
                <?php if ($canConsolidacion || $canBlancoControl): ?>
                    <a class="sidebar-download" href="dashboard.php">
                        <i class="fa-solid fa-download"></i>
                        <span>Descargar Reportes</span>
                    </a>
                <?php endif; ?>

                <div class="sidebar-links">
                    <a href="../index.php">
                        <span>Inicio</span>
                    </a>
                    <a href="<?= labc_e(lab_logout_url()) ?>">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Cerrar Sesión</span>
                    </a>
                </div>
            </div>
        </aside>

        <main class="lab-main">
            <header class="lab-topbar">
                <div class="search-shell">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="formSearch"
                        placeholder="Buscar análisis o lotes..."
                        autocomplete="off"
                        aria-label="Buscar análisis o lotes">
                </div>

                <div class="top-actions">
                    <?php if ($canCreateSolicitud): ?>
                        <a class="primary-action" href="menu_solicitud.php">
                            <i class="fa-solid fa-plus"></i>
                            <span>Nueva solicitud</span>
                        </a>
                    <?php endif; ?>

                    <a class="text-action" href="dashboard.php">Informes</a>

                    <button type="button" class="icon-action" aria-label="Notificaciones">
                        <i class="fa-regular fa-bell"></i>
                    </button>

                    <button type="button" class="icon-action" aria-label="Configuración">
                        <i class="fa-solid fa-gear"></i>
                    </button>

                    <div class="avatar-chip" aria-hidden="true">LAB</div>
                </div>
            </header>

            <section class="hero-card">
                <div class="hero-headline">
                    <div class="hero-labels">
                        <span class="hero-chip">Bandeja operativa</span>
                        <span class="hero-chip alt"><?= labc_e($activeSection['nav_label']) ?> seleccionado</span>
                    </div>

                    <h1>Bandeja del analista</h1>
                    <p>Selecciona un tipo de muestra para revisar únicamente los análisis que tienen trabajo pendiente.</p>
                </div>

                <div class="hero-stats">
                    <span class="hero-stat">
                        <strong><?= (int) ($tray['resumen']['analisis_pendientes'] ?? 0) ?></strong>
                        <small>Análisis</small>
                    </span>
                    <span class="hero-stat">
                        <strong><?= (int) ($tray['resumen']['lotes_pendientes'] ?? 0) ?></strong>
                        <small>Lotes</small>
                    </span>
                    <span class="hero-stat">
                        <strong><?= (int) ($tray['resumen']['muestras_pendientes'] ?? 0) ?></strong>
                        <small>Muestras</small>
                    </span>
                </div>
            </section>

            <div class="content-stack">
                <section
                    class="form-section tray-section is-active"
                    id="section-<?= labc_e($activeSection['id']) ?>"
                    data-section="<?= labc_e($activeSection['id']) ?>"
                    data-label="<?= labc_e($activeSection['nav_label']) ?>">
                    <div class="section-head tray-head">
                        <div>
                            <p class="section-kicker">Tipo de muestra</p>
                            <h2><?= labc_e($activeSection['title']) ?></h2>
                            <p class="section-copy"><?= labc_e($activeSection['subtitle']) ?></p>
                        </div>

                        <div class="section-actions">
                            <?php if ($activeSection['history_url'] !== null): ?>
                                <a class="history-btn" href="<?= labc_e($activeSection['history_url']) ?>">
                                    <i class="fa-regular fa-clock"></i>
                                    <span>Ver historial</span>
                                </a>
                            <?php else: ?>
                                <span class="history-btn disabled" aria-disabled="true">
                                    <i class="fa-regular fa-clock"></i>
                                    <span>Ver historial</span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (empty($tray['analisis'])): ?>
                        <div class="empty-worktray">
                            <div class="empty-worktray-icon">
                                <i class="fa-solid fa-flask-vial"></i>
                            </div>
                            <h3>No hay análisis pendientes</h3>
                            <p>El tipo de muestra seleccionado no tiene trabajo pendiente en este momento.</p>
                        </div>
                    <?php else: ?>
                        <div class="analysis-tray" id="analysisTray">
                            <?php foreach ($tray['analisis'] as $analysis): ?>
                                <?php
                                    $estadoCodigo = (string) ($analysis['estado']['codigo'] ?? 'pendiente');
                                    $analysisClasses = ['analysis-row', 'analysis-theme-' . $activeSection['theme'], 'state-' . $estadoCodigo];
                                    $searchText = trim(
                                        ($analysis['nombre'] ?? '') . ' ' .
                                        ($analysis['progreso']['texto'] ?? '') . ' ' .
                                        implode(' ', array_map(static function (array $chip): string {
                                            return trim((string) ($chip['codigo_lote'] ?? '') . ' ' . (string) ($chip['rango_inicio'] ?? '') . ' ' . (string) ($chip['rango_fin'] ?? ''));
                                        }, $analysis['lotes_pendientes'] ?? []))
                                    );
                                ?>
                                <article
                                    class="<?= labc_e(implode(' ', $analysisClasses)) ?>"
                                    data-search="<?= labc_e($searchText) ?>">
                                    <div class="analysis-main">
                                        <div class="analysis-title-row">
                                            <div>
                                                <p class="analysis-kicker">Análisis</p>
                                                <h3><?= labc_e($analysis['nombre'] ?? '') ?></h3>
                                            </div>


                                        </div>

                                        <div class="analysis-meta">
                                            <span class="analysis-meta-pill">
                                                <strong><?= (int) ($analysis['lotes_distintos'] ?? 0) ?></strong>
                                                <small>Lotes pendientes</small>
                                            </span>

                                            <span class="analysis-meta-pill">
                                                <strong><?= (int) ($analysis['muestras_pendientes'] ?? 0) ?></strong>
                                                <small>Muestras</small>
                                            </span>


                                        </div>
                                    </div>

                                    <div class="analysis-lots">
                                        <?php foreach (($analysis['lotes_pendientes'] ?? []) as $chip): ?>
                                            <span class="analysis-lot-chip">
                                                <strong><?= labc_e('Lote ' . ($chip['codigo_lote'] ?: 'Sin código')) ?></strong>
                                                <small><?= labc_e(labcBandejaEtiquetaRango($chip['rango_inicio'] ?? null, $chip['rango_fin'] ?? null)) ?></small>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="analysis-progress">
                                        <div class="analysis-progress-head">
                                            <span>Avance</span>
                                            <strong><?= labc_e($analysis['progreso']['texto'] ?? '0 / 0') ?></strong>
                                        </div>
                                        <div class="progress-track" aria-hidden="true">
                                            <span style="width: <?= (int) ($analysis['progreso']['porcentaje'] ?? 0) ?>%"></span>
                                        </div>
                                    </div>

                                    <div class="analysis-action">
                                        <a class="primary-action analysis-button" href="<?= labc_e($analysis['href'] ?? '#') ?>">
                                            <span><?= labc_e($analysis['accion'] ?? 'Capturar') ?></span>
                                            <i class="fa-solid fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="no-results" id="noResults" hidden>
                    <div class="no-results-card">
                        <div class="no-results-icon">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                        <h3>No se encontraron análisis</h3>
                        <p>Prueba con otro término para filtrar la bandeja o limpia la búsqueda para volver a ver todos los análisis pendientes.</p>
                    </div>
                </section>
            </div>

            <a class="floating-action" href="menu_solicitud.php" aria-label="Nueva solicitud">
                <i class="fa-solid fa-plus"></i>
            </a>
        </main>
    </div>

    <script>
    (function () {
        const searchInput = document.getElementById('formSearch');
        const sections = Array.from(document.querySelectorAll('.form-section'));
        const noResults = document.getElementById('noResults');

        function filterRows() {
            const query = (searchInput.value || '').trim().toLowerCase();
            let visibleRows = 0;

            sections.forEach((section) => {
                const rows = Array.from(section.querySelectorAll('.analysis-row'));
                if (rows.length === 0) {
                    section.hidden = false;
                    return;
                }

                let sectionVisible = false;

                rows.forEach((row) => {
                    const text = (row.dataset.search || row.textContent || '').toLowerCase();
                    const match = !query || text.includes(query);
                    row.hidden = !match;
                    if (match) {
                        sectionVisible = true;
                        visibleRows += 1;
                    }
                });

                section.hidden = !sectionVisible;
            });

            noResults.hidden = visibleRows !== 0;
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterRows);
            filterRows();
        }
    })();
    </script>
</body>
</html>
