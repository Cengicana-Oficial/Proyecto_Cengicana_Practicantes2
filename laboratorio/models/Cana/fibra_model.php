<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarFibraCana(
    $vd,
    $brix_jugo,
    $torta_humeda,
    $torta_seca,
    $fibra,
    array $metadata = []
)
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'cana_fibra', [
        'vd' => $vd,
        'brix_jugo' => $brix_jugo,
        'torta_humeda' => $torta_humeda,
        'torta_seca' => $torta_seca,
        'fibra' => $fibra,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Fibra de caña guardada correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}
