<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogo_muestras_helper.php';
require_once __DIR__ . '/../conexion.php';

lab_require_module_access();

if (!lab_can('laboratorio.formularios_pendientes.ver') && !lab_is_technician()) {
    lab_forbidden('No tiene permisos para ver formularios pendientes.');
}

$stmt = $conexion->query("
    SELECT
        l.id_lote,
        l.codigo_lote,
        s.id_solicitud,
        s.fecha_ingreso,
        s.fecha_estimada,
        s.numero_muestras,
        tm.id_tipo AS id_tipo_muestra,
        tm.nombre AS tipo_muestra,
        COUNT(DISTINCT ta.id_tipo) AS total_pendientes,
        GROUP_CONCAT(DISTINCT ta.nombre ORDER BY ta.nombre SEPARATOR '||') AS analisis_pendientes
    FROM solicitud s
    INNER JOIN lote l
        ON l.id_lote = s.id_lote
    INNER JOIN tipo_muestra tm
        ON tm.id_tipo = s.id_tipo
    INNER JOIN solicitud_analisis sa
        ON sa.id_solicitud = s.id_solicitud
    INNER JOIN tipo_analisis ta
        ON ta.id_tipo = sa.id_tipo_analisis
    WHERE NOT EXISTS (
        SELECT 1
          FROM lote_rango lr2
          INNER JOIN formulario f
            ON f.id_rango = lr2.id_rango
           AND f.id_tipo_analisis = ta.id_tipo
         WHERE lr2.id_lote = l.id_lote
    )
      AND ta.id_tipo_muestra = s.id_tipo
    GROUP BY
        l.id_lote,
        l.codigo_lote,
        s.id_solicitud,
        s.fecha_ingreso,
        s.fecha_estimada,
        s.numero_muestras,
        tm.id_tipo,
        tm.nombre
    HAVING total_pendientes > 0
    ORDER BY
        CASE LOWER(tm.nombre)
            WHEN 'suelos' THEN 10
            WHEN 'agua' THEN 20
            WHEN 'foliares' THEN 30
            WHEN 'cañas' THEN 40
            WHEN 'cana' THEN 40
            WHEN 'mieles' THEN 50
            WHEN 'miel' THEN 50
            ELSE 90
        END,
        l.codigo_lote ASC,
        s.fecha_ingreso DESC,
        l.id_lote DESC
");

$pendientes = $stmt ? $stmt->fetchAll() : [];
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
</head>
<body>
<nav>
    <div class="nav-brand">Laboratorio</div>
    <div class="nav-links">
        <a href="../index.php" class="nav-link back">&larr; Regresar</a>
    </div>
</nav>

<main>
    <header class="page-header">
        <div>
            <span class="eyebrow">Tecnico</span>
            <h1>Solicitudes pendientes</h1>
            <p>Analisis solicitados por lote que aun no tienen formulario ingresado.</p>
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
</main>
</body>
</html>
