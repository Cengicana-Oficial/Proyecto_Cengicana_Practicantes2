<?php
require_once __DIR__ . '/../includes/auth.php';

lab_require_permission('laboratorio.lotes.ver');

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/solicitud_formulario_helpers.php';
require_once __DIR__ . '/../includes/estado_lote_helper.php';

asegurarColumnasFirmasSolicitud($conexion);

$correoIngresadoSelect = solicitudColumnExists($conexion, 'correo_ingresado')
    ? 's.correo_ingresado'
    : 'NULL';
$correoRecibidoSelect = solicitudColumnExists($conexion, 'correo_recibido')
    ? 's.correo_recibido'
    : 'NULL';
$firmaIngresoSelect = solicitudColumnExists($conexion, 'firma_ingreso')
    ? 's.firma_ingreso'
    : 'NULL';
$firmaRecibeSelect = solicitudColumnExists($conexion, 'firma_recibe')
    ? 's.firma_recibe'
    : 'NULL';

$busquedaLote = trim((string) ($_GET['buscar'] ?? ''));
$estadoFiltro = strtolower(trim((string) ($_GET['estado'] ?? '')));
$estadosPermitidos = ['pendiente', 'en_proceso', 'revision', 'aprobado'];
if (!in_array($estadoFiltro, $estadosPermitidos, true)) {
    $estadoFiltro = '';
}

$analisisRequeridosSql = "
    SELECT
        s.id_lote,
        COUNT(DISTINCT sa.id_tipo_analisis) AS analisis_requeridos
    FROM solicitud s
    INNER JOIN solicitud_analisis sa
        ON sa.id_solicitud = s.id_solicitud
    GROUP BY s.id_lote
";

$analisisIngresadosSql = "
    SELECT
        lr.id_lote,
        COUNT(DISTINCT f.id_tipo_analisis) AS analisis_ingresados,
        COUNT(
            DISTINCT CASE
                WHEN LOWER(COALESCE(ef.nombre, '')) = 'aprobado'
                THEN f.id_tipo_analisis
            END
        ) AS analisis_aprobados
    FROM lote_rango lr
    LEFT JOIN formulario f
        ON f.id_rango = lr.id_rango
    LEFT JOIN estado_formulario ef
        ON ef.id_estado = f.id_estado
    GROUP BY lr.id_lote
";

$filtrosLote = [];
$params = [];

if ($busquedaLote !== '') {
    $filtrosLote[] = 'l2.codigo_lote = ?';
    $params[] = $busquedaLote;
}

if ($estadoFiltro === 'pendiente') {
    $filtrosLote[] = 'COALESCE(ai2.analisis_ingresados, 0) = 0';
} elseif ($estadoFiltro === 'en_proceso') {
    $filtrosLote[] = 'COALESCE(ai2.analisis_ingresados, 0) > 0 AND COALESCE(ai2.analisis_ingresados, 0) < COALESCE(ar2.analisis_requeridos, 0)';
} elseif ($estadoFiltro === 'revision') {
    $filtrosLote[] = 'COALESCE(ar2.analisis_requeridos, 0) > 0 AND COALESCE(ai2.analisis_ingresados, 0) >= COALESCE(ar2.analisis_requeridos, 0) AND COALESCE(ai2.analisis_aprobados, 0) < COALESCE(ar2.analisis_requeridos, 0)';
} elseif ($estadoFiltro === 'aprobado') {
    $filtrosLote[] = 'COALESCE(ar2.analisis_requeridos, 0) > 0 AND COALESCE(ai2.analisis_aprobados, 0) >= COALESCE(ar2.analisis_requeridos, 0)';
}

$whereLotes = $filtrosLote ? 'WHERE ' . implode(' AND ', $filtrosLote) : '';
$limitarLotes = $busquedaLote === '';
$limitSql = $limitarLotes ? 'LIMIT 20' : '';

$stmt = $conexion->prepare("
    SELECT
        l.id_lote,
        l.codigo_lote,
        COALESCE(ar.analisis_requeridos, 0) AS analisis_requeridos,
        COALESCE(ai.analisis_ingresados, 0) AS analisis_ingresados,
        COALESCE(ai.analisis_aprobados, 0) AS analisis_aprobados,
        s.id_solicitud,
        s.fecha_muestreo,
        s.numero_muestras,
        s.ingresado_por,
        s.recibido_por,
        {$correoIngresadoSelect} AS correo_ingresado,
        {$correoRecibidoSelect} AS correo_recibido,
        {$firmaIngresoSelect} AS firma_ingreso,
        {$firmaRecibeSelect} AS firma_recibe,
        s.fecha_ingreso,
        s.fecha_estimada,
        s.observaciones,
        tm.nombre AS tipo_muestra,
        mr.codigo_inicio,
        mr.codigo_fin,
        GROUP_CONCAT(DISTINCT ta.nombre ORDER BY ta.nombre SEPARATOR '||') AS analisis
    FROM lote l
    INNER JOIN (
        SELECT l2.id_lote
        FROM lote l2
        LEFT JOIN ({$analisisRequeridosSql}) ar2
            ON ar2.id_lote = l2.id_lote
        LEFT JOIN ({$analisisIngresadosSql}) ai2
            ON ai2.id_lote = l2.id_lote
        {$whereLotes}
        ORDER BY l2.id_lote DESC
        {$limitSql}
    ) lotes_filtrados
        ON lotes_filtrados.id_lote = l.id_lote
    LEFT JOIN ({$analisisRequeridosSql}) ar
        ON ar.id_lote = l.id_lote
    LEFT JOIN ({$analisisIngresadosSql}) ai
        ON ai.id_lote = l.id_lote
    LEFT JOIN solicitud s
        ON s.id_lote = l.id_lote
    LEFT JOIN tipo_muestra tm
        ON tm.id_tipo = s.id_tipo
    LEFT JOIN (
        SELECT
            m.id_solicitud,
            (
                SELECT mi.codigo_lab
                FROM muestra mi
                WHERE mi.id_solicitud = m.id_solicitud
                  AND mi.codigo_lab IS NOT NULL
                  AND mi.codigo_lab <> ''
                ORDER BY mi.numero_muestra ASC
                LIMIT 1
            ) AS codigo_inicio,
            (
                SELECT mf.codigo_lab
                FROM muestra mf
                WHERE mf.id_solicitud = m.id_solicitud
                  AND mf.codigo_lab IS NOT NULL
                  AND mf.codigo_lab <> ''
                ORDER BY mf.numero_muestra DESC
                LIMIT 1
            ) AS codigo_fin
        FROM muestra m
        GROUP BY m.id_solicitud
    ) mr
        ON mr.id_solicitud = s.id_solicitud
    LEFT JOIN solicitud_analisis sa
        ON sa.id_solicitud = s.id_solicitud
    LEFT JOIN tipo_analisis ta
        ON ta.id_tipo = sa.id_tipo_analisis
    GROUP BY
        l.id_lote,
        l.codigo_lote,
        ar.analisis_requeridos,
        ai.analisis_ingresados,
        ai.analisis_aprobados,
        s.id_solicitud,
        s.fecha_muestreo,
        s.numero_muestras,
        s.ingresado_por,
        s.recibido_por,
        correo_ingresado,
        correo_recibido,
        firma_ingreso,
        firma_recibe,
        s.fecha_ingreso,
        s.fecha_estimada,
        s.observaciones,
        tm.nombre,
        mr.codigo_inicio,
        mr.codigo_fin
    ORDER BY l.id_lote DESC, s.id_solicitud DESC
");

$stmt->execute($params);
$lotesRows = $stmt->fetchAll();
$lotes = [];

foreach ($lotesRows as $row) {
    $idLote = (int) $row['id_lote'];
    if (!isset($lotes[$idLote])) {
        $estadoLote = labCalcularEstadoLote(
            $row['analisis_requeridos'] ?? 0,
            $row['analisis_ingresados'] ?? 0,
            $row['analisis_aprobados'] ?? 0
        );
        $lotes[$idLote] = [
            'id_lote' => $idLote,
            'codigo_lote' => $row['codigo_lote'],
            'estado_lote' => $estadoLote,
            'analisis_requeridos' => (int) ($row['analisis_requeridos'] ?? 0),
            'analisis_ingresados' => (int) ($row['analisis_ingresados'] ?? 0),
            'analisis_aprobados' => (int) ($row['analisis_aprobados'] ?? 0),
            'numeros_laboratorio' => [],
            'solicitudes' => [],
        ];
    }

    if (!empty($row['id_solicitud'])) {
        $laboratorio = $row['codigo_inicio'] ?: null;
        if (!empty($row['codigo_inicio']) && !empty($row['codigo_fin']) && $row['codigo_inicio'] !== $row['codigo_fin']) {
            $laboratorio = $row['codigo_inicio'] . ' a ' . $row['codigo_fin'];
        }

        if ($laboratorio) {
            $lotes[$idLote]['numeros_laboratorio'][] = $laboratorio;
        }

        $lotes[$idLote]['solicitudes'][] = [
            'id_solicitud' => (int) $row['id_solicitud'],
            'tipo_muestra' => $row['tipo_muestra'] ?: '-',
            'fecha_muestreo' => $row['fecha_muestreo'] ?: '-',
            'numero_muestras' => $row['numero_muestras'] ?: '-',
            'laboratorio_inicio' => $row['codigo_inicio'] ?: '-',
            'laboratorio_fin' => $row['codigo_fin'] ?: '-',
            'fecha_ingreso' => $row['fecha_ingreso'] ?: '-',
            'fecha_estimada' => $row['fecha_estimada'] ?: '-',
            'ingresado_por' => $row['ingresado_por'] ?: '-',
            'recibido_por' => $row['recibido_por'] ?: '-',
            'correo_ingresado' => $row['correo_ingresado'] ?: '',
            'correo_recibido' => $row['correo_recibido'] ?: '',
            'firma_ingreso' => $row['firma_ingreso'] ?: '',
            'firma_recibe' => $row['firma_recibe'] ?: '',
            'observaciones' => $row['observaciones'] ?: '',
            'analisis' => !empty($row['analisis']) ? explode('||', $row['analisis']) : [],
        ];
    }
}

$lotes = array_values(array_map(static function ($lote) {
    $lote['numeros_laboratorio'] = implode(', ', array_values(array_unique($lote['numeros_laboratorio'])));
    if ($lote['numeros_laboratorio'] === '') {
        $lote['numeros_laboratorio'] = 'Sin numero asignado';
    }
    return $lote;
}, $lotes));

function eLotes($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Listado de Lotes</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../css/listar_lotes.css">
<script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
</head>

<body>
<nav class="institution-header">
    <div class="nav-brand">Laboratorio</div>
    <div class="branding-zone" aria-label="Branding institucional">
        <img
            class="nav-logo"
            src="../assets/cengicaña_sin_fondo.png"
            alt="CENGICAÑA"
        >
    </div>
</nav>

<main>
    <div class="page-shell">
        <section class="hero-admin">
            <section class="panel">
                <div class="panel-header">
                    <div class="hero-copy">
                        <a href="../index.php" class="back-link">
                            <i class="fa-solid fa-arrow-left"></i>
                            <span>Regresar</span>
                        </a>
                        <span class="eyebrow">
                            <i class="fa-solid fa-flask-vial"></i>
                            <span>Gestión de lotes del módulo</span>
                        </span>
                        <h1>Listado de Lotes</h1>
                        <p>Consulta de lotes registrados.</p>
                    </div>

                    <div class="stats-grid" aria-label="Resumen de lotes">
                        <article class="stat-card total-lotes">
                            <span class="stat-card-icon"><i class="fa-solid fa-boxes-stacked"></i></span>
                            <div>
                                <p class="stat-card-value"><?= count($lotes) ?></p>
                                <p class="stat-card-label">Lotes visibles</p>
                                <p class="stat-card-meta"><?= $limitarLotes ? 'Mostrando primeros' : 'Resultados' ?></p>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </section>

        <form class="toolbar" method="GET">
            <label class="search-box" for="buscar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="buscar" name="buscar" value="<?= eLotes($busquedaLote) ?>" placeholder="Ej. 20 o LT-2026-001">
            </label>

            <select id="estado" name="estado" class="filter-select" onchange="this.form.submit()">
                <option value="" <?= $estadoFiltro === '' ? 'selected' : '' ?>>Todos los estados</option>
                <option value="pendiente" <?= $estadoFiltro === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                <option value="en_proceso" <?= $estadoFiltro === 'en_proceso' ? 'selected' : '' ?>>En proceso</option>
                <option value="revision" <?= $estadoFiltro === 'revision' ? 'selected' : '' ?>>En revisión</option>
                <option value="aprobado" <?= $estadoFiltro === 'aprobado' ? 'selected' : '' ?>>Aprobado</option>
            </select>

            <div class="toolbar-actions">
                <button type="submit" class="search-button">Buscar</button>

                <?php if ($busquedaLote !== '' || $estadoFiltro !== ''): ?>
                    <a href="listar_lotes.php" class="btn-clear">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-area">
            <div class="table-shell">
                <table class="lotes-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Número de Laboratorio</th>
                            <th>Código de Lote</th>
                            <th>Estado</th>
                            <th>PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($lotes)): ?>
                        <tr>
                            <td colspan="5" class="empty-cell">No se encontraron lotes con los filtros seleccionados.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lotes as $lote): ?>
                        <?php
                            $pdfData = [
                                'id' => (int) $lote['id_lote'],
                                'codigo_lote' => $lote['codigo_lote'],
                                'numeros_laboratorio' => $lote['numeros_laboratorio'],
                                'solicitudes' => $lote['solicitudes'],
                            ];
                        ?>
                        <tr>
                            <td><?= (int) $lote['id_lote'] ?></td>
                            <td><?= eLotes($pdfData['numeros_laboratorio']) ?></td>
                            <td><?= eLotes($pdfData['codigo_lote']) ?></td>
                            <td>
                                <span class="estado-badge <?= eLotes($lote['estado_lote']['clase']) ?>">
                                    <?= eLotes($lote['estado_lote']['texto']) ?>
                                </span>
                            </td>
                            <td>
                                <button
                                    class="btn-pdf"
                                    type="button"
                                    data-lote='<?= eLotes(json_encode($pdfData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                    Descargar PDF
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="../js/pdf_tablas.js"></script>
<script>
document.querySelectorAll(".btn-pdf").forEach((button) => {
    button.addEventListener("click", async () => {
        const lote = JSON.parse(button.dataset.lote || "{}");
        const codigo = LabPdfTablas.normalizarTexto(lote.codigo_lote);

        await LabPdfTablas.crearPdfBoletaLote({
            lote,
            fileName: `lote_${LabPdfTablas.nombreArchivo(codigo)}.pdf`,
        });
    });
});
</script>
</body>
</html>
