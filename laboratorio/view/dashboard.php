<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';

lab_require_module_access();

$pdo = Conexion::conectar();
$loteSeleccionado = null;
$errorMensaje = '';
$analisisPorGrupo = [];

$tipoReporte = $_GET['tipo_reporte'] ?? 'suelos';
if (!in_array($tipoReporte, ['suelos', 'aguas', 'foliares', 'cana'], true)) {
    $tipoReporte = 'suelos';
}

$idLoteSelected = !empty($_GET['lote']) ? (int) $_GET['lote'] : null;

$reportTypes = [
    'suelos' => [
        'label' => 'Suelos',
        'icon' => 'fa-seedling',
        'description' => 'Análisis físicos y químicos',
    ],
    'aguas' => [
        'label' => 'Aguas',
        'icon' => 'fa-droplet',
        'description' => 'Calidad y parámetros de agua',
    ],
    'foliares' => [
        'label' => 'Foliares',
        'icon' => 'fa-leaf',
        'description' => 'Estado nutricional de hojas',
    ],
    'cana' => [
        'label' => 'Caña',
        'icon' => 'fa-tractor',
        'description' => 'Resultados de materia prima',
    ],
];

$analysisMap = [
    'suelos' => [
        'suelos-textura' => ['nombre' => 'Textura', 'tabla' => 'analisis_textura', 'grupo' => 'fisico'],
        'suelos-humedad' => ['nombre' => 'Humedad', 'tabla' => 'suelo_humedad', 'grupo' => 'fisico'],
        'suelos-humedad-residual' => [
            'nombre' => 'Humedad Gravimetrica',
            'tablas' => ['laboratorio_humedad', 'suelo_humedad_gravimetrica', 'suelo_humedad_residual'],
            'grupo' => 'fisico'
        ],
        'suelos-dap' => ['nombre' => 'DAP (Densidad Aparente)', 'tabla' => 'suelo_dap', 'grupo' => 'fisico'],
        'suelos-cc' => ['nombre' => 'Capacidad de Campo', 'tabla' => 'suelo_cc', 'grupo' => 'fisico'],
        'suelos-pmp' => ['nombre' => 'Punto de Marchitez', 'tabla' => 'suelo_pmp', 'grupo' => 'fisico'],
        'suelos-ce' => ['nombre' => 'Conductividad Eléctrica', 'tabla' => 'suelo_ce', 'grupo' => 'quimico'],
        'suelos-ph' => ['nombre' => 'pH', 'tabla' => 'suelo_ph', 'grupo' => 'quimico'],
        'suelos-cic' => ['nombre' => 'CIC', 'tabla' => 'suelo_macros', 'grupo' => 'quimico'],
        'suelos-mo' => ['nombre' => '%MO (Materia Orgánica)', 'tabla' => 'MO_Porcentaje', 'grupo' => 'quimico'],
        'suelos-macroscic' => ['nombre' => 'Macronutrientes y CIC', 'tabla' => 'suelo_macros', 'grupo' => 'quimico'],
        'suelos-micros' => ['nombre' => 'Micronutrientes (Cu, Zn, Fe, Mn, K)', 'tabla' => 'suelo_micros', 'grupo' => 'quimico'],
        'suelos-nitrogeno' => ['nombre' => 'Nitrógeno', 'tabla' => 'suelo_nitrogeno', 'grupo' => 'quimico'],
        'suelos-boro' => ['nombre' => 'Boro', 'tabla' => 'suelo_boro', 'grupo' => 'quimico'],
        'suelos-azufre' => ['nombre' => 'Azufre', 'tabla' => 'suelo_azufre', 'grupo' => 'quimico'],
        'suelos-fosforo' => ['nombre' => 'Fósforo', 'tabla' => 'suelo_fosforo', 'grupo' => 'quimico'],
    ],
    'aguas' => [
        'aguas-conductividad' => ['nombre' => 'Conductividad Eléctrica', 'tabla' => 'agua_conductividad', 'grupo' => 'general'],
        'aguas-ph' => ['nombre' => 'pH', 'tabla' => 'agua_ph', 'grupo' => 'general'],
        'aguas-tds' => ['nombre' => 'Sólidos Totales Disueltos (TDS)', 'tabla' => 'agua_tds', 'grupo' => 'general'],
        'aguas-cloruros' => ['nombre' => 'Cloruros', 'tabla' => 'agua_cloruros', 'grupo' => 'general'],
        'aguas-dureza' => ['nombre' => 'Dureza', 'tabla' => 'agua_dureza', 'grupo' => 'general'],
        'aguas-alcalinidad' => ['nombre' => 'Alcalinidad', 'tabla' => 'agua_alcalinidad', 'grupo' => 'general'],
        'aguas-carbonatos' => ['nombre' => 'Carbonatos', 'tabla' => 'agua_carbonatos', 'grupo' => 'general'],
        'aguas-bicarbonatos' => ['nombre' => 'Bicarbonatos', 'tabla' => 'agua_bicarbonatos', 'grupo' => 'general'],
        'aguas-ras' => ['nombre' => 'Razón de Adsorción Sodio (RAS)', 'tabla' => 'agua_ras', 'grupo' => 'general'],
        'aguas-resistividad' => ['nombre' => 'Resistividad', 'tabla' => 'agua_resistividad', 'grupo' => 'general'],
        'aguas-salinidad' => ['nombre' => 'Salinidad', 'tabla' => 'agua_salinidad', 'grupo' => 'general'],
        'aguas-fosforo' => ['nombre' => 'Fósforo', 'tabla' => 'agua_fosforo', 'grupo' => 'general'],
        'aguas-boro' => ['nombre' => 'Boro', 'tabla' => 'agua_boro', 'grupo' => 'general'],
        'aguas-macros' => ['nombre' => 'Macronutrientes (Ca, Mg, K, Na)', 'tabla' => 'agua_macros', 'grupo' => 'general'],
        'aguas-micros' => ['nombre' => 'Micronutrientes (Cu, Zn, Fe, Mn)', 'tabla' => 'agua_micros', 'grupo' => 'general'],
    ],
    'foliares' => [
        'foliares-nitrogeno' => ['nombre' => 'Nitrógeno', 'tabla' => 'foliar_nitrogeno', 'grupo' => 'general'],
        'foliares-macros' => ['nombre' => 'Macronutrientes (Ca, Mg, K)', 'tabla' => 'foliar_macros', 'grupo' => 'general'],
        'foliares-fosforo' => ['nombre' => 'Fósforo', 'tabla' => 'foliar_fosforo', 'grupo' => 'general'],
        'foliares-boro' => ['nombre' => 'Boro', 'tabla' => 'foliar_boro', 'grupo' => 'general'],
        'foliares-micros' => ['nombre' => 'Micronutrientes (Cu, Zn, Fe, Mn)', 'tabla' => 'foliar_micros', 'grupo' => 'general'],
        'foliares-humedad' => ['nombre' => 'Humedad', 'tabla' => 'foliar_humedad', 'grupo' => 'general'],
        'foliares-quimicos' => ['nombre' => 'Químicos (pH, CE)', 'tabla' => 'foliar_quimicos', 'grupo' => 'general'],
    ],
    'cana' => [
        'cana-brixpol' => ['nombre' => 'Brix y Pol', 'tabla' => 'cana_brixpol', 'grupo' => 'general'],
        'cana-humedad' => ['nombre' => 'Porcentaje de Humedad', 'tabla' => 'cana_humedad', 'grupo' => 'general'],
        'cana-fibra' => ['nombre' => 'Determinación de Fibra', 'tabla' => 'cana_fibra', 'grupo' => 'general'],
        'cana-peso-seco' => ['nombre' => 'Peso Seco', 'tabla' => 'cana_peso_seco', 'grupo' => 'general'],
        'cana-ph' => ['nombre' => 'pH de Jugo', 'tabla' => 'cana_ph', 'grupo' => 'general'],
    ],
];

function dashboard_build_url(string $tipoReporte, ?int $idLote = null): string
{
    $params = ['tipo_reporte' => $tipoReporte];

    if ($idLote !== null && $idLote > 0) {
        $params['lote'] = $idLote;
    }

    return '?' . http_build_query($params);
}

function dashboard_report_label(string $tipoReporte): string
{
    switch ($tipoReporte) {
        case 'suelos':
            return 'Suelos';
        case 'aguas':
            return 'Aguas';
        case 'foliares':
            return 'Foliares';
        case 'cana':
            return 'Caña';
        default:
            return ucfirst($tipoReporte);
    }
}

$reportMeta = $reportTypes[$tipoReporte];

foreach ($reportTypes as $key => &$meta) {
    $meta['href'] = dashboard_build_url($key, $idLoteSelected);
    $meta['total_configurados'] = count($analysisMap[$key] ?? []);
    $meta['active'] = ($key === $tipoReporte);
}
unset($meta);

function dashboard_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }

    if (!array_key_exists($table, $cache)) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            $cache[$table] = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        } catch (Throwable $e) {
            $cache[$table] = [];
        }
    }

    return $cache[$table];
}

function dashboard_get_lote_sample_keys(PDO $pdo, int $idLote): array
{
    static $cache = [];

    if ($idLote <= 0) {
        return [];
    }

    if (!array_key_exists($idLote, $cache)) {
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT CAST(m.numero_muestra AS CHAR) AS valor
                  FROM muestra m
                  INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
                 WHERE s.id_lote = ?
                   AND m.numero_muestra IS NOT NULL
                UNION
                SELECT DISTINCT m.codigo_lab AS valor
                  FROM muestra m
                  INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
                 WHERE s.id_lote = ?
                   AND m.codigo_lab IS NOT NULL
                   AND m.codigo_lab <> ''
                ORDER BY valor
            ");
            $stmt->execute([$idLote, $idLote]);
            $cache[$idLote] = array_values(array_filter(array_map(static function ($row) {
                return trim((string) ($row['valor'] ?? ''));
            }, $stmt->fetchAll(PDO::FETCH_ASSOC))));
        } catch (Throwable $e) {
            $cache[$idLote] = [];
        }
    }

    return $cache[$idLote];
}

function dashboard_count_analysis_rows(PDO $pdo, $tables, int $idLote): int
{
    $tables = is_array($tables) ? $tables : [$tables];
    $sampleKeys = null;

    foreach ($tables as $table) {
        $columns = dashboard_table_columns($pdo, $table);
        if (!$columns) {
            continue;
        }

        $joins = [];
        $conditions = [];
        $params = [];

        if (in_array('id_lote', $columns, true)) {
            $conditions[] = 't.id_lote = ?';
            $params[] = $idLote;
        }

        if (in_array('id_solicitud', $columns, true)) {
            $joins['solicitud'] = 'LEFT JOIN solicitud s ON s.id_solicitud = t.id_solicitud';
            $conditions[] = 's.id_lote = ?';
            $params[] = $idLote;
        }

        if (in_array('id_formulario', $columns, true)) {
            $joins['formulario'] = 'LEFT JOIN formulario f ON f.id_formulario = t.id_formulario';
            $joins['lote_rango'] = 'LEFT JOIN lote_rango lr ON lr.id_rango = f.id_rango';
            $conditions[] = 'lr.id_lote = ?';
            $params[] = $idLote;
        }

        if (in_array('no_lab', $columns, true) || in_array('numero_laboratorio', $columns, true) || in_array('numero_muestra', $columns, true)) {
            $sampleKeys ??= dashboard_get_lote_sample_keys($pdo, $idLote);
            if ($sampleKeys) {
                $placeholders = implode(', ', array_fill(0, count($sampleKeys), '?'));

                if (in_array('no_lab', $columns, true)) {
                    $conditions[] = "t.no_lab IN ($placeholders)";
                    array_push($params, ...$sampleKeys);
                } elseif (in_array('numero_laboratorio', $columns, true)) {
                    $conditions[] = "CAST(t.numero_laboratorio AS CHAR) IN ($placeholders)";
                    array_push($params, ...$sampleKeys);
                } elseif (in_array('numero_muestra', $columns, true)) {
                    $conditions[] = "CAST(t.numero_muestra AS CHAR) IN ($placeholders)";
                    array_push($params, ...$sampleKeys);
                }
            }
        }

        if (!$conditions) {
            continue;
        }

        $sql = 'SELECT COUNT(*) AS total FROM `' . $table . '` t '
            . implode(' ', $joins)
            . ' WHERE (' . implode(' OR ', $conditions) . ')';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $total = (int) ($stmt->fetchColumn() ?: 0);
            if ($total > 0) {
                return $total;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return 0;
}

$stmtLotes = $pdo->query("SELECT DISTINCT l.id_lote, l.codigo_lote FROM lote l ORDER BY l.codigo_lote");
$lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

if ($idLoteSelected) {
    $stmtLote = $pdo->prepare("SELECT * FROM lote WHERE id_lote = ?");
    $stmtLote->execute([$idLoteSelected]);
    $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

    if ($lote) {
        $loteSeleccionado = $lote;

        foreach ($analysisMap[$tipoReporte] as $key => $analisis) {
            try {
                $tablas = $analisis['tablas'] ?? $analisis['tabla'];
                $total = dashboard_count_analysis_rows($pdo, $tablas, $idLoteSelected);

                if ($total > 0) {
                    $analisis['id'] = $key;
                    $analisis['registros'] = $total;
                    $grupo = $analisis['grupo'] ?? 'general';

                    if (!isset($analisisPorGrupo[$grupo])) {
                        $analisisPorGrupo[$grupo] = [];
                    }

                    $analisisPorGrupo[$grupo][] = $analisis;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        $ordenGrupos = ['fisico', 'quimico', 'general'];
        $analisisPorGrupoOrdenado = [];
        foreach ($ordenGrupos as $grupo) {
            if (!empty($analisisPorGrupo[$grupo])) {
                $analisisPorGrupoOrdenado[$grupo] = $analisisPorGrupo[$grupo];
            }
        }
        foreach ($analisisPorGrupo as $grupo => $items) {
            if (!isset($analisisPorGrupoOrdenado[$grupo])) {
                $analisisPorGrupoOrdenado[$grupo] = $items;
            }
        }
        $analisisPorGrupo = $analisisPorGrupoOrdenado;
    } else {
        $errorMensaje = 'El lote seleccionado no existe.';
    }
}

$analisisTotal = 0;
foreach ($analisisPorGrupo as $grupoItems) {
    $analisisTotal += count($grupoItems);
}

$grupoLabels = [
    'fisico' => 'Análisis físicos',
    'quimico' => 'Análisis químicos',
    'general' => 'Análisis registrados',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard General de Informes</title>
    <link rel="stylesheet" href="../styles/base.css">
    <link rel="stylesheet" href="../css/dashboard.css?v=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="brand-mark" aria-hidden="true">
                    <i class="fa-solid fa-flask-vial"></i>
                </div>
                <div class="brand-copy">
                    <span class="brand-kicker">Laboratorio Agroindustrial</span>
                    <strong>Lab Portal</strong>
                    <span class="brand-version">v1.2.4</span>
                </div>
            </div>

            <div class="sidebar-section-title">Navegación</div>
            <nav class="sidebar-nav" aria-label="Navegación del dashboard">
                <a href="dashboard.php" class="sidebar-link active" aria-current="page">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>
                <?php foreach ($reportTypes as $key => $meta): ?>
                    <a
                        href="<?= htmlspecialchars($meta['href']) ?>"
                        class="sidebar-link"
                        aria-current="false">
                        <i class="fa-solid <?= htmlspecialchars($meta['icon']) ?>"></i>
                        <span><?= htmlspecialchars($meta['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="user-avatar" aria-hidden="true">
                    <i class="fa-solid fa-user"></i>
                </div>
                <div class="user-copy">
                    <strong>Técnico de Lab.</strong>
                    <span>Activo ahora</span>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="content-shell">
                <header class="topbar">
                    <div class="topbar-brand">
                        <span class="topbar-kicker">Panel de informes</span>
                        <h2>Dashboard General de Informes</h2>
                    </div>

                    <div class="topbar-actions">
                        <a href="../index.php" class="topbar-link">Inicio</a>
                        <a href="<?= htmlspecialchars(dashboard_build_url($tipoReporte, $idLoteSelected)) ?>" class="topbar-link active">Informes</a>
                        <a href="labc_index.php" class="topbar-link">Analisis</a>
                        <div class="topbar-avatar" aria-hidden="true">LAB</div>
                    </div>
                </header>

                <section class="page-intro">
                    <a href="labc_index.php" class="back-link">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        <span>Regresar al panel</span>
                    </a>

                    <div class="hero-copy-block">
                        <p class="hero-kicker">Dashboard General de Informes</p>
                        <h1 class="hero-title">Consulta y analiza los resultados técnicos de los diferentes laboratorios.</h1>
                        <p class="hero-copy">
                            Selecciona una categoría para comenzar la búsqueda de reportes históricos y datos técnicos de sus lotes.
                        </p>
                    </div>

                    <?php if (!empty($errorMensaje)): ?>
                        <div class="alert-box"><?= htmlspecialchars($errorMensaje) ?></div>
                    <?php endif; ?>

                    <div class="report-grid" role="tablist" aria-label="Tipos de reporte">
                        <?php foreach ($reportTypes as $key => $meta): ?>
                            <a
                                href="<?= htmlspecialchars($meta['href']) ?>"
                                class="report-card <?= $meta['active'] ? 'active' : '' ?>"
                                data-type="<?= htmlspecialchars($key) ?>"
                                aria-current="<?= $meta['active'] ? 'page' : 'false' ?>">
                                <span class="report-card-icon" aria-hidden="true">
                                    <i class="fa-solid <?= htmlspecialchars($meta['icon']) ?>"></i>
                                </span>
                                <span class="report-card-label"><?= htmlspecialchars($meta['label']) ?></span>
                                <span class="report-card-note"><?= (int) $meta['total_configurados'] ?> análisis configurados</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="search-card">
                    <form method="GET" class="search-form">
                        <input type="hidden" name="tipo_reporte" value="<?= htmlspecialchars($tipoReporte) ?>">

                        <div class="field-group">
                            <label for="lote" class="field-label">Lote a consultar</label>
                            <div class="select-wrap">
                                <i class="fa-solid fa-location-dot select-icon" aria-hidden="true"></i>
                                <select name="lote" id="lote" class="search-select" required>
                                    <option value="">Seleccione un lote o finca...</option>
                                    <?php foreach ($lotes as $lote): ?>
                                        <option value="<?= (int) $lote['id_lote'] ?>" <?= $loteSeleccionado && (int) $loteSeleccionado['id_lote'] === (int) $lote['id_lote'] ? 'selected' : '' ?>>
                                            Lote: <?= htmlspecialchars($lote['codigo_lote']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down select-caret" aria-hidden="true"></i>
                            </div>
                        </div>

                        <button type="submit" class="search-button">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <span>Buscar reporte</span>
                        </button>
                    </form>
                </section>

                <section class="results-panel">
                    <?php if ($loteSeleccionado && $analisisTotal > 0): ?>
                        <div class="results-header">
                            <div class="results-copy-wrap">
                                <div class="results-badges">
                                    <span class="badge">
                                        <i class="fa-solid <?= htmlspecialchars($reportMeta['icon']) ?>" aria-hidden="true"></i>
                                        <?= htmlspecialchars($reportMeta['label']) ?>
                                    </span>
                                    <?php if ($tipoReporte === 'suelos'): ?>
                                        <span class="badge alt">Físicos: <?= isset($analisisPorGrupo['fisico']) ? count($analisisPorGrupo['fisico']) : 0 ?></span>
                                        <span class="badge warn">Químicos: <?= isset($analisisPorGrupo['quimico']) ? count($analisisPorGrupo['quimico']) : 0 ?></span>
                                    <?php else: ?>
                                        <span class="badge alt">Resultados: <?= (int) $analisisTotal ?></span>
                                    <?php endif; ?>
                                </div>

                                <h2 class="results-title">Lote <?= htmlspecialchars($loteSeleccionado['codigo_lote']) ?></h2>
                                <p class="results-text">
                                    Se encontraron <strong><?= (int) $analisisTotal ?></strong> análisis con registros para este lote.
                                </p>
                            </div>

                            <form method="POST" action="../controllers/general_export_excel_controller.php" class="export-form">
                                <input type="hidden" name="id_lote" value="<?= (int) $loteSeleccionado['id_lote'] ?>">
                                <input type="hidden" name="tipo_reporte" value="<?= htmlspecialchars($tipoReporte) ?>">
                                <button type="submit" class="export-button">
                                    <i class="fa-solid fa-file-excel" aria-hidden="true"></i>
                                    <span>Descargar reporte</span>
                                </button>
                            </form>
                        </div>

                        <?php foreach ($analisisPorGrupo as $grupo => $items): ?>
                            <section class="analysis-section">
                                <div class="analysis-section-header">
                                    <div class="analysis-section-title"><?= htmlspecialchars($grupoLabels[$grupo] ?? 'Análisis') ?></div>
                                    <span class="code-chip"><?= count($items) ?> resultados</span>
                                </div>

                                <div class="analysis-grid">
                                    <?php foreach ($items as $analisis): ?>
                                        <article class="analysis-card <?= $grupo === 'fisico' ? 'physical' : ($grupo === 'quimico' ? 'chemical' : 'general') ?>">
                                            <h4><?= htmlspecialchars($analisis['nombre']) ?></h4>
                                            <div class="analysis-card-meta">
                                                <span>Registros: <strong><?= (int) $analisis['registros'] ?></strong></span>
                                                <span>Tabla: <code><?= htmlspecialchars($analisis['tabla']) ?></code></span>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    <?php elseif ($loteSeleccionado): ?>
                        <div class="no-data">
                            <div>
                                <div class="empty-icon">
                                    <i class="fa-solid fa-microscope" aria-hidden="true"></i>
                                </div>
                                <h3 class="no-data-title">Listo para el análisis</h3>
                                <p class="no-data-copy">
                                    No se encontraron análisis registrados para el lote
                                    <strong><?= htmlspecialchars($loteSeleccionado['codigo_lote']) ?></strong>
                                    en la categoría <strong><?= htmlspecialchars(dashboard_report_label($tipoReporte)) ?></strong>.
                                    Puedes cambiar la categoría o seleccionar otro lote para seguir explorando.
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div>
                                <div class="empty-icon">
                                    <i class="fa-solid fa-microscope" aria-hidden="true"></i>
                                </div>
                                <h3 class="empty-title">Listo para el análisis</h3>
                                <p class="empty-copy">
                                    Por favor, selecciona un lote y haz clic en <strong>Buscar reporte</strong> para visualizar los datos técnicos,
                                    gráficos de tendencia y recomendaciones agronómicas.
                                </p>
                                <div class="placeholder-grid" aria-hidden="true">
                                    <div class="placeholder-card"></div>
                                    <div class="placeholder-card"></div>
                                    <div class="placeholder-card"></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
