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
$analistasPorAnalisis = $tipoSeleccionado ? obtenerAnalistasConsolidacion((int) $tipoSeleccionado) : [];

if (!function_exists('eConsolidacion')) {
    function eConsolidacion($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('revisionUrlConsolidacion')) {
    function revisionUrlConsolidacion(array $fila): string
    {
        $idRango = trim((string) ($fila['id_rango'] ?? ''));
        if ($idRango === '') {
            return '';
        }

        return '../controllers/formulario_revision_controller.php?id_rango=' . urlencode($idRango);
    }
}

if (!function_exists('consolidacionLoteEtiqueta')) {
    function consolidacionLoteEtiqueta(array $fila): string
    {
        $codigo = trim((string) ($fila['codigo_lote'] ?? ''));
        if ($codigo !== '') {
            return $codigo;
        }

        $idRango = trim((string) ($fila['id_rango'] ?? ''));
        if ($idRango !== '') {
            return 'Rango ' . $idRango;
        }

        return 'Sin codigo';
    }
}

if (!function_exists('consolidacionEstadoGeneral')) {
    function consolidacionEstadoGeneral(array $lotesPendientes): array
    {
        $estado = [
            'codigo' => 'pendiente',
            'texto' => 'Pendiente',
            'clase' => 'estado-pendiente',
        ];

        foreach ($lotesPendientes as $lote) {
            $loteEstado = $lote['estado'] ?? [];
            $codigo = (string) ($loteEstado['codigo'] ?? '');

            if ($codigo === 'revision') {
                return [
                    'codigo' => 'revision',
                    'texto' => 'En revision',
                    'clase' => 'estado-revision',
                ];
            }

            if ($codigo === 'en_proceso') {
                $estado = [
                    'codigo' => 'en_proceso',
                    'texto' => 'En proceso',
                    'clase' => 'estado-en-proceso',
                ];
            }
        }

        return $estado;
    }
}

if (!function_exists('consolidacionAnalistasBloque')) {
    function consolidacionAnalistasBloque(array $analistasPorAnalisis, string $idAnalisis, array $pendientes): array
    {
        $analistas = [];
        $vistos = [];
        $analistasAnalisis = $analistasPorAnalisis[$idAnalisis] ?? [];

        foreach ($pendientes as $pendiente) {
            $idRango = trim((string) ($pendiente['id_rango'] ?? ''));
            if ($idRango === '' || empty($analistasAnalisis[$idRango])) {
                continue;
            }

            foreach ($analistasAnalisis[$idRango] as $analista) {
                $nombre = trim((string) $analista);
                if ($nombre === '' || isset($vistos[$nombre])) {
                    continue;
                }

                $vistos[$nombre] = true;
                $analistas[] = $nombre;
            }
        }

        sort($analistas, SORT_NATURAL | SORT_FLAG_CASE);

        return $analistas;
    }
}

if (!function_exists('consolidacionProgresoBloque')) {
    function consolidacionProgresoBloque(array $pendientes): array
    {
        $requeridos = 0;
        $aprobados = 0;

        foreach ($pendientes as $pendiente) {
            $requeridos += max(0, (int) ($pendiente['analisis_requeridos'] ?? 0));
            $aprobados += max(0, (int) ($pendiente['analisis_aprobados'] ?? 0));
        }

        $porcentaje = $requeridos > 0
            ? (int) round(min(1, $aprobados / $requeridos) * 100)
            : 0;

        return [
            'requeridos' => $requeridos,
            'aprobados' => $aprobados,
            'porcentaje' => $porcentaje,
            'texto' => $aprobados . ' / ' . $requeridos,
        ];
    }
}

if (!function_exists('consolidacionPrioridadBloque')) {
    function consolidacionPrioridadBloque(): array
    {
        return [
            'texto' => 'No disponible',
            'clase' => 'is-neutral',
            'ayuda' => 'No existe un campo de prioridad estructurado en el backend actual.',
        ];
    }
}

$bloquesAnalisis = [];

foreach ($analisis as $item) {
    $idAnalisis = trim((string) ($item['id_tipo'] ?? ''));
    if ($idAnalisis === '') {
        continue;
    }

    $pendientes = [];
    $vistos = [];
    $lotesDistintos = 0;
    $progresoRequeridos = 0;
    $progresoAprobados = 0;

    foreach ($filas as $fila) {
        $idSolicitud = (int) ($fila['id_solicitud'] ?? 0);
        $idRango = normalizarRangoConsolidacion($fila['id_rango'] ?? null);

        if (trim((string) ($fila['id_formulario_revision'] ?? '')) === '') {
            continue;
        }

        $estadoCelda = celdaConsolidacion($estados, $idSolicitud, $idRango, $idAnalisis);

        if (empty($estadoCelda['solicitado']) || !empty($estadoCelda['completado'])) {
            continue;
        }

        if (isset($vistos[$idRango])) {
            continue;
        }

        $vistos[$idRango] = true;
        $lotesDistintos++;
        $progresoRequeridos += max(0, (int) ($fila['analisis_requeridos'] ?? 0));
        $progresoAprobados += max(0, (int) ($fila['analisis_aprobados'] ?? 0));

        $estadoLote = labCalcularEstadoLote(
            $fila['analisis_requeridos'] ?? 0,
            $fila['analisis_ingresados'] ?? 0,
            $fila['analisis_aprobados'] ?? 0
        );

        $pendientes[] = [
            'id_rango' => $idRango,
            'codigo_lote' => consolidacionLoteEtiqueta($fila),
            'rango_inicio' => trim((string) ($fila['inicio'] ?? '')),
            'rango_fin' => trim((string) ($fila['fin'] ?? '')),
            'numero_muestras' => max(0, (int) ($fila['numero_muestras'] ?? 0)),
            'estado' => $estadoLote,
            'url' => revisionUrlConsolidacion($fila),
        ];
    }

    if (empty($pendientes)) {
        continue;
    }

    $muestrasPendientes = 0;
    foreach ($pendientes as $pendiente) {
        $muestrasPendientes += (int) ($pendiente['numero_muestras'] ?? 0);
    }

    $bloquesAnalisis[] = [
        'id_tipo' => $idAnalisis,
        'nombre' => trim((string) ($item['nombre'] ?? 'Análisis')),
        'pendientes' => $pendientes,
        'muestras_pendientes' => $muestrasPendientes,
        'lotes_distintos' => $lotesDistintos,
        'estado' => consolidacionEstadoGeneral($pendientes),
        'lote_principal' => $pendientes[0] ?? null,
        'analistas' => consolidacionAnalistasBloque($analistasPorAnalisis, $idAnalisis, $pendientes),
        'prioridad' => consolidacionPrioridadBloque(),
        'progreso' => [
            'requeridos' => $progresoRequeridos,
            'aprobados' => $progresoAprobados,
            'porcentaje' => $progresoRequeridos > 0 ? (int) round(min(1, $progresoAprobados / $progresoRequeridos) * 100) : 0,
            'texto' => $progresoAprobados . ' / ' . $progresoRequeridos,
        ],
    ];
}

$tipoNombreConsolidacion = trim((string) ($tipoActual['nombre'] ?? 'Sin tipo de muestra'));
if ($tipoNombreConsolidacion === '') {
    $tipoNombreConsolidacion = 'Sin tipo de muestra';
}

$headersPdf = ['Analisis', 'Lotes pendientes', 'Muestras', 'Estado', 'Lotes'];
$filasPdf = [];
foreach ($bloquesAnalisis as $bloque) {
    $lotes = [];
    foreach ($bloque['pendientes'] as $pendiente) {
        $lotes[] = (string) ($pendiente['codigo_lote'] ?? '-');
    }

    $filasPdf[] = [
        $bloque['nombre'],
        (string) count($bloque['pendientes']),
        (string) ($bloque['muestras_pendientes'] ?? 0),
        (string) ($bloque['estado']['texto'] ?? '-'),
        implode(', ', $lotes),
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hoja de Consolidacion</title>
    <link rel="stylesheet" href="../styles/consolidacion.css">
    <script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
</head>
<body>
<div class="page-shell">
    <a href="../index.php" class="btn-volver back-link">
        <span aria-hidden="true">&larr;</span>
        <span>Volver</span>
    </a>

    <section class="hero-card consolidacion-hero" aria-labelledby="consolidacion-title">
        <div class="hero-headline">

            <h1 id="consolidacion-title">Hoja de Consolidación</h1>
            <p>
                Selecciona el tipo de muestra y entra al trabajo pendiente por analisis.
            </p>
        </div>
    </section>

    <?php if (empty($tiposMuestra)): ?>
        <div class="empty-state">No hay tipos de muestra registrados.</div>
    <?php else: ?>
        <section class="consolidacion-toolbar" aria-label="Filtros de la bandeja">
            <form method="GET" class="filter-form consolidacion-filter">
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

            <div class="consolidacion-toolbar__actions">
                <button class="pdf-button" id="btn-consolidacion-pdf" type="button">Descargar PDF</button>
            </div>
        </section>

        <?php if (empty($bloquesAnalisis)): ?>
            <div class="empty-state">No hay analisis con trabajo pendiente para este tipo de muestra.</div>
        <?php else: ?>
            <section class="analysis-board" aria-label="Analisis pendientes">
                <?php foreach ($bloquesAnalisis as $bloque): ?>
                    <article class="analysis-row">
                        <div class="analysis-main">
                            <div class="analysis-head">
                                <h2><?= eConsolidacion($bloque['nombre']) ?></h2>

                            </div>


                        </div>

                        <div class="analysis-lots" aria-label="Lotes pendientes">
                            <?php foreach ($bloque['pendientes'] as $pendiente): ?>
                                <?php if (!empty($pendiente['url'])): ?>
                                    <a class="status-chip lot-chip" href="<?= eConsolidacion($pendiente['url']) ?>">
                                        <span class="lot-chip__primary">
                                            <?= eConsolidacion($pendiente['codigo_lote']) ?>
                                        </span>
                                        <span class="lot-chip__secondary">
                                            <?= eConsolidacion('Lab ' . ($pendiente['rango_inicio'] ?? '') . ' - ' . ($pendiente['rango_fin'] ?? '')) ?>
                                        </span>
                                    </a>
                                <?php else: ?>
                                    <span class="status-chip lot-chip is-neutral" aria-disabled="true">
                                        <span class="lot-chip__primary">
                                            <?= eConsolidacion($pendiente['codigo_lote']) ?>
                                        </span>
                                        <span class="lot-chip__secondary">
                                            <?= eConsolidacion('Lab ' . ($pendiente['rango_inicio'] ?? '') . ' - ' . ($pendiente['rango_fin'] ?? '')) ?>
                                        </span>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="analysis-progress" aria-label="Progreso">
                            <span class="analysis-label">Progreso</span>
                            <div class="analysis-progress__value">
                                <?= eConsolidacion($bloque['progreso']['texto'] ?? '0 / 0') ?>
                            </div>
                            <div
                                class="analysis-progress__track"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="<?= (int) ($bloque['progreso']['porcentaje'] ?? 0) ?>"
                                aria-label="Avance del análisis"
                            >
                                <span style="width: <?= (int) ($bloque['progreso']['porcentaje'] ?? 0) ?>%"></span>
                            </div>
                        </div>

                        <div class="analysis-actions">
                            <?php if (!empty($bloque['lote_principal']['url'])): ?>
                                <a class="lab-table-button analysis-action" href="<?= eConsolidacion($bloque['lote_principal']['url']) ?>">
                                    Revisar
                                </a>
                            <?php else: ?>
                                <span class="lab-table-button analysis-action is-disabled" aria-disabled="true">
                                    Revisar
                                </span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="../js/pdf_tablas.js"></script>
<script>
const consolidacionHeaders = <?= json_encode($headersPdf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const consolidacionRows = <?= json_encode($filasPdf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const consolidacionTipo = <?= json_encode($tipoNombreConsolidacion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

document.getElementById('btn-consolidacion-pdf')?.addEventListener('click', async () => {
    await LabPdfTablas.crearPdfConsolidacion({
        titulo: 'Hoja de Consolidacion',
        subtitulo: consolidacionTipo,
        headers: consolidacionHeaders,
        rows: consolidacionRows.length ? consolidacionRows : [['-', '-', '-', '-', '-']],
        fileName: `consolidacion_${LabPdfTablas.nombreArchivo(consolidacionTipo)}.pdf`,
    });
});
</script>
</body>
</html>
