<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/analisis_generico_config.php';

lab_require_module_access();

$pdo = Conexion::conectar();
$loteSeleccionado = null;
$analisisFisicos = [];
$analisisQuimicos = [];
$errorMensaje = '';

// Mapeo de análisis de suelos a tablas
$mapeoAnalisisSuelos = [
    'suelos-textura' => ['nombre' => 'Textura', 'tabla' => 'analisis_textura', 'tipo' => 'fisico'],
    'suelos-humedad' => ['nombre' => 'Humedad', 'tabla' => 'suelo_humedad', 'tipo' => 'fisico'],
    'suelos-humedad-residual' => [
        'nombre' => 'Humedad Gravimetrica',
        'tablas' => ['laboratorio_humedad', 'suelo_humedad_gravimetrica', 'suelo_humedad_residual'],
        'tipo' => 'fisico'
    ],
    'suelos-dap' => ['nombre' => 'DAP (Densidad Aparente)', 'tabla' => 'suelo_dap', 'tipo' => 'fisico'],
    'suelos-cc' => ['nombre' => 'Capacidad de Campo', 'tabla' => 'suelo_cc', 'tipo' => 'fisico'],
    'suelos-pmp' => ['nombre' => 'Punto de Marchitez', 'tabla' => 'suelo_pmp', 'tipo' => 'fisico'],
    'suelos-ce' => ['nombre' => 'Conductividad Eléctrica', 'tabla' => 'suelo_ce', 'tipo' => 'quimico'],
    'suelos-ph' => ['nombre' => 'pH', 'tabla' => 'suelo_ph', 'tipo' => 'quimico'],
    'suelos-cic' => ['nombre' => 'CIC', 'tabla' => 'suelo_macros', 'tipo' => 'quimico'],
    'suelos-mo' => ['nombre' => '%MO (Materia Orgánica)', 'tabla' => 'MO_Porcentaje', 'tipo' => 'quimico'],
    'suelos-macroscic' => ['nombre' => 'Macronutrientes y CIC', 'tabla' => 'suelo_macros', 'tipo' => 'quimico'],
    'suelos-micros' => ['nombre' => 'Micronutrientes (Cu, Zn, Fe, Mn, K)', 'tabla' => 'suelo_micros', 'tipo' => 'quimico'],
    'suelos-nitrogeno' => ['nombre' => 'Nitrógeno', 'tabla' => 'suelo_nitrogeno', 'tipo' => 'quimico'],
    'suelos-boro' => ['nombre' => 'Boro', 'tabla' => 'suelo_boro', 'tipo' => 'quimico'],
    'suelos-azufre' => ['nombre' => 'Azufre', 'tabla' => 'suelo_azufre', 'tipo' => 'quimico'],
    'suelos-fosforo' => ['nombre' => 'Fósforo', 'tabla' => 'suelo_fosforo', 'tipo' => 'quimico'],
];

function suelosDashboardTableColumns(PDO $pdo, string $table): array
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

function suelosDashboardSampleKeys(PDO $pdo, int $idLote): array
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

function suelosDashboardCountRows(PDO $pdo, $tables, int $idLote): int
{
    $tables = is_array($tables) ? $tables : [$tables];
    $sampleKeys = null;

    foreach ($tables as $table) {
        $columns = suelosDashboardTableColumns($pdo, $table);
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
            $sampleKeys ??= suelosDashboardSampleKeys($pdo, $idLote);
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

// Obtener lista de lotes
$stmtLotes = $pdo->query("SELECT DISTINCT l.id_lote, l.codigo_lote FROM lote l ORDER BY l.codigo_lote");
$lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

// Si se selecciona un lote
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['lote'])) {
    $idLote = (int) $_GET['lote'];
    
    // Validar que existe el lote
    $stmtLote = $pdo->prepare("SELECT * FROM lote WHERE id_lote = ?");
    $stmtLote->execute([$idLote]);
    $lote = $stmtLote->fetch();
    
    if ($lote) {
        $loteSeleccionado = $lote;
        
        // Verificar qué análisis tienen datos para este lote
        foreach ($mapeoAnalisisSuelos as $key => $analisis) {
            try {
                $tablas = $analisis['tablas'] ?? $analisis['tabla'];
                $total = suelosDashboardCountRows($pdo, $tablas, $idLote);

                if ($total > 0) {
                    $analisis['id'] = $key;
                    $analisis['registros'] = $total;
                    
                    if ($analisis['tipo'] === 'fisico') {
                        $analisisFisicos[] = $analisis;
                    } else {
                        $analisisQuimicos[] = $analisis;
                    }
                }
            } catch (Exception $e) {
                // Tabla no existe, continuar
                continue;
            }
        }
    } else {
        $errorMensaje = "El lote seleccionado no existe.";
    }
}

// Si se solicita descargar Excel (ya manejado por el controlador)
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Análisis de Suelos</title>
    <link rel="stylesheet" href="../styles/index.css">
    <style>
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .lote-selector {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .lote-selector form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .lote-selector select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 200px;
        }
        .lote-selector button {
            padding: 8px 20px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        .lote-selector button:hover {
            background: #45a049;
        }
        .analisis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        .analisis-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .analisis-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .analisis-card h3 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 18px;
        }
        .analisis-info {
            font-size: 13px;
            color: #666;
            margin: 10px 0;
        }
        .analisis-info strong {
            color: #333;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .resumen {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #4CAF50;
        }
        .resumen h2 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .resumen p {
            margin: 5px 0;
            color: #666;
        }
        .sin-datos {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }
        .error-message {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <header class="doc-header">
            <div class="doc-header-left">
                <div class="logo-circle">LAB</div>
                <div>
                    <p class="doc-kicker">Laboratorio agrícola</p>
                    <h1 class="doc-title">Dashboard de Análisis de Suelos</h1>
                    <p class="doc-subtitle">Consulta los resultados de análisis por lote</p>
                </div>
            </div>
        </header>

        <div class="history-links">
            <a href="labc_index.php">← Volver</a>
        </div>

        <?php if (!empty($errorMensaje)): ?>
            <div class="error-message"><?= htmlspecialchars($errorMensaje) ?></div>
        <?php endif; ?>

        <div class="lote-selector">
            <form method="GET">
                <label for="lote" style="font-weight: bold;">Seleccionar Lote:</label>
                <select name="lote" id="lote" required>
                    <option value="">-- Seleccione un lote --</option>
                    <?php foreach ($lotes as $lote): ?>
                        <option value="<?= (int) $lote['id_lote'] ?>" <?= $loteSeleccionado && $loteSeleccionado['id_lote'] == $lote['id_lote'] ? 'selected' : '' ?>>
                            Lote: <?= htmlspecialchars($lote['codigo_lote']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Buscar Análisis</button>
            </form>
        </div>

        <?php if ($loteSeleccionado): ?>
            <div class="resumen">
                <h2>Resumen del Lote</h2>
                <p><strong>Código de Lote:</strong> <?= htmlspecialchars($loteSeleccionado['codigo_lote']) ?></p>
                <p><strong>Análisis Físicos Disponibles:</strong> <?= count($analisisFisicos) ?></p>
                <p><strong>Análisis Químicos Disponibles:</strong> <?= count($analisisQuimicos) ?></p>
            </div>

            <?php if (!empty($analisisFisicos) || !empty($analisisQuimicos)): ?>
                <form method="POST" action="../controllers/general_export_excel_controller.php" style="display: inline;">
                    <input type="hidden" name="id_lote" value="<?= (int)$loteSeleccionado['id_lote'] ?>">
                    <input type="hidden" name="tipo_reporte" value="suelos">
                    <button type="submit" class="btn-download-all">
                        📥 Descargar Excel con Todos los Resultados
                    </button>
                </form>

                <!-- ANÁLISIS FÍSICOS -->
                <?php if (!empty($analisisFisicos)): ?>
                    <h2 style="margin: 30px 0 20px 0; color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px;">
                        Físicos
                    </h2>
                    <div class="analisis-grid">
                        <?php foreach ($analisisFisicos as $analisis): ?>
                            <div class="analisis-card">
                                <h3><?= htmlspecialchars($analisis['nombre']) ?></h3>
                                <div class="analisis-info">
                                    <p><strong>Registros:</strong> <?= (int) $analisis['registros'] ?></p>
                                    <p><strong>Tabla:</strong> <code><?= htmlspecialchars($analisis['tabla']) ?></code></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- ANÁLISIS QUÍMICOS -->
                <?php if (!empty($analisisQuimicos)): ?>
                    <h2 style="margin: 30px 0 20px 0; color: #333; border-bottom: 2px solid #ff9800; padding-bottom: 10px;">
                        Químicos
                    </h2>
                    <div class="analisis-grid">
                        <?php foreach ($analisisQuimicos as $analisis): ?>
                            <div class="analisis-card">
                                <h3><?= htmlspecialchars($analisis['nombre']) ?></h3>
                                <div class="analisis-info">
                                    <p><strong>Registros:</strong> <?= (int) $analisis['registros'] ?></p>
                                    <p><strong>Tabla:</strong> <code><?= htmlspecialchars($analisis['tabla']) ?></code></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="sin-datos">
                    <p>No hay análisis de suelos disponibles para este lote.</p>
                </div>
            <?php endif; ?>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['lote'])): ?>
            <div class="sin-datos">
                <p>Selecciona un lote para ver los análisis disponibles</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
