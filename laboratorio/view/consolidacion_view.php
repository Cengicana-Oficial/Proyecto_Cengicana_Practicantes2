<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/consolidacion_model.php';
lab_require_permission('laboratorio.consolidacion.ver');

$tiposMuestra = $tiposMuestra ?? [];
$tipoSeleccionado = $tipoSeleccionado ?? null;
$tipoActual = $tipoActual ?? null;
$loteSeleccionado = $loteSeleccionado ?? '';
$analisis = $analisis ?? [];
$filas = $filas ?? [];
$estados = $estados ?? [];

function eConsolidacion($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fechaConsolidacion($fecha)
{
    if (!$fecha) {
        return '-';
    }

    $timestamp = strtotime($fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : $fecha;
}

function diasDesdeIngresoConsolidacion($fecha)
{
    if (!$fecha) {
        return '-';
    }

    $timestamp = strtotime($fecha);
    if (!$timestamp) {
        return '-';
    }

    try {
        $fechaIngreso = new DateTimeImmutable(date('Y-m-d', $timestamp));
        $hoy = new DateTimeImmutable('today');
    } catch (Exception $e) {
        return '-';
    }

    $dias = (int) $fechaIngreso->diff($hoy)->format('%r%a');
    return (string) max(0, $dias);
}

function revisionUrlConsolidacion(array $fila)
{
    if (empty($fila['id_rango'])) {
        return '';
    }

    return '../controllers/formulario_revision_controller.php?id_rango=' . urlencode((string) $fila['id_rango']);
}

function estadoHtmlConsolidacion(array $fila)
{
    $estado = labCalcularEstadoLote(
        $fila['analisis_requeridos'] ?? 0,
        $fila['analisis_ingresados'] ?? 0,
        $fila['analisis_aprobados'] ?? 0
    );
    $url = revisionUrlConsolidacion($fila);

    if ($estado['codigo'] === 'pendiente' || $url === '') {
        return eConsolidacion($estado['texto']);
    }

    return '<a class="estado-link ' . eConsolidacion($estado['clase']) . '" href="' . eConsolidacion($url) . '">' . eConsolidacion($estado['texto']) . '</a>';
}

$filasPdf = [];
foreach ($filas as $fila) {
    $row = [
        fechaConsolidacion($fila['fecha_ingreso'] ?? null),
        diasDesdeIngresoConsolidacion($fila['fecha_ingreso'] ?? null),
        labCalcularEstadoLote(
            $fila['analisis_requeridos'] ?? 0,
            $fila['analisis_ingresados'] ?? 0,
            $fila['analisis_aprobados'] ?? 0
        )['texto'],
        fechaConsolidacion($fila['fecha_finalizacion'] ?? null),
        $fila['codigo_lote'] ?? '-',
        $fila['numero_muestras'] ?? '-',
        $fila['inicio'] ?? '-',
        $fila['fin'] ?? '-',
    ];

    foreach ($analisis as $item) {
        $estadoCelda = celdaConsolidacion(
            $estados,
            $fila['id_solicitud'],
            $fila['id_rango'] ?? null,
            $item['id_tipo']
        );
        $row[] = $estadoCelda['solicitado'] ? 'SI' : '-';
    }

    $filasPdf[] = $row;
}

$headersPdf = array_merge(
    ['Fecha ingreso', 'Dias', 'Estado', 'Fecha finalizacion', 'Lote', 'No. muestras', 'Empieza', 'Termina'],
    array_map(static function ($item) {
        return $item['nombre'];
    }, $analisis)
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hoja de consolidación</title>
    <link rel="stylesheet" href="../styles/consolidacion.css">
    <script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
</head>
<body>
<div class="page-wrap">
    <a href="../index.php" class="back-link">Volver</a>

    <header class="page-header">
        <div>
            <span class="eyebrow">Recepción</span>
            <h1>Hoja de consolidación</h1>
        </div>
        <div class="header-actions">
            <button class="pdf-button" id="btn-consolidacion-pdf" type="button">Descargar PDF</button>
            <form method="GET" class="filter-form">
                <label for="tipo">Tipo de muestra</label>
                <select id="tipo" name="tipo" onchange="this.form.submit()">
                    <?php foreach ($tiposMuestra as $tipo): ?>
                        <option value="<?= (int) $tipo['id_tipo'] ?>" <?= (int) $tipo['id_tipo'] === (int) $tipoSeleccionado ? 'selected' : '' ?>>
                            <?= eConsolidacion($tipo['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($loteSeleccionado !== ''): ?>
                    <input type="hidden" name="lote" value="<?= eConsolidacion($loteSeleccionado) ?>">
                <?php endif; ?>
            </form>
        </div>
    </header>

    <div class="summary-row">
        <span><?= $tipoActual ? eConsolidacion($tipoActual['nombre']) : 'Sin tipo de muestra' ?></span>
        <?php if ($loteSeleccionado !== ''): ?>
            <span>Lote <?= eConsolidacion($loteSeleccionado) ?></span>
        <?php endif; ?>
        <span><?= count($filas) ?> registros</span>
        <span><?= count($analisis) ?> análisis</span>
    </div>

    <?php if (empty($tiposMuestra)): ?>
        <div class="empty-state">No hay tipos de muestra registrados.</div>
    <?php elseif (empty($analisis)): ?>
        <div class="empty-state">No hay análisis registrados para este tipo de muestra.</div>
    <?php else: ?>
        <div class="table-shell">
            <table class="consolidacion-table">
                <thead>
                    <tr>
                        <th>Fecha ingreso</th>
                        <th>Días Transcurridos</th>
                        <th>Estado</th>
                        <th>Fecha finalizacion</th>
                        <th>Lote</th>
                        <th>No. muestras</th>
                        <th>Empieza</th>
                        <th>Termina</th>
                        <?php foreach ($analisis as $item): ?>
                            <th><?= eConsolidacion($item['nombre']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filas)): ?>
                        <tr>
                            <td colspan="<?= 8 + count($analisis) ?>" class="empty-cell">
                                No hay solicitudes para este tipo de muestra.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filas as $fila): ?>
                            <tr>
                                <td><?= eConsolidacion(fechaConsolidacion($fila['fecha_ingreso'] ?? null)) ?></td>
                                <td><?= eConsolidacion(diasDesdeIngresoConsolidacion($fila['fecha_ingreso'] ?? null)) ?></td>
                                <td><?= estadoHtmlConsolidacion($fila) ?></td>
                                <td><?= eConsolidacion(fechaConsolidacion($fila['fecha_finalizacion'] ?? null)) ?></td>
                                <td><?= eConsolidacion($fila['codigo_lote'] ?? '-') ?></td>
                                <td><?= eConsolidacion($fila['numero_muestras'] ?? '-') ?></td>
                                <td><?= eConsolidacion($fila['inicio'] ?? '-') ?></td>
                                <td><?= eConsolidacion($fila['fin'] ?? '-') ?></td>
                                <?php foreach ($analisis as $item): ?>
                                    <?php
                                        $estadoCelda = celdaConsolidacion(
                                            $estados,
                                            $fila['id_solicitud'],
                                            $fila['id_rango'] ?? null,
                                            $item['id_tipo']
                                        );
                                        $clase = $estadoCelda['completado']
                                            ? 'cell-requested cell-completed'
                                            : ($estadoCelda['solicitado'] ? 'cell-requested' : 'cell-empty');
                                        $texto = $estadoCelda['solicitado'] ? 'sí' : '-';
                                    ?>
                                    <td class="<?= $clase ?>"><?= $texto ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script src="../js/pdf_tablas.js"></script>
<script>
const consolidacionHeaders = <?= json_encode($headersPdf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const consolidacionRows = <?= json_encode($filasPdf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const consolidacionTipo = <?= json_encode($tipoActual['nombre'] ?? 'Sin tipo de muestra', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

document.getElementById("btn-consolidacion-pdf")?.addEventListener("click", async () => {
    await LabPdfTablas.crearPdfConsolidacion({
        titulo: "Consolidado de ingreso de analisis",
        subtitulo: consolidacionTipo,
        headers: consolidacionHeaders,
        rows: consolidacionRows.length ? consolidacionRows : [["-", "-", "-", "-", "-", "-", "-", "-"]],
        fileName: `consolidacion_${LabPdfTablas.nombreArchivo(consolidacionTipo)}.pdf`,
    });
});
</script>
</body>
</html>
