<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarBrixMieles(
    $producto,
    $ingenio,
    $dia_zafra,
    $brix_obs,
    $brix_corr,
    array $metadata = []
) {
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'mieles_brix', [
        'producto' => $producto,
        'ingenio' => $ingenio,
        'dia_zafra' => $dia_zafra,
        'brix_obs' => $brix_obs,
        'brix_corr' => $brix_corr,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Brix de mieles guardado correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}
