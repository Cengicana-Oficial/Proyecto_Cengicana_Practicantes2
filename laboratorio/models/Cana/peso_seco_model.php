<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarPesoSecoCana(
    $num_bandeja,
    $peso_bandeja,
    $peso_muestra,
    $bandeja_humeda,
    $bandeja_seca,
    $torta_seca,
    array $metadata = []
)
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'cana_peso_seco', [
        'num_bandeja' => $num_bandeja,
        'peso_bandeja' => $peso_bandeja,
        'peso_muestra' => $peso_muestra,
        'bandeja_humeda' => $bandeja_humeda,
        'bandeja_seca' => $bandeja_seca,
        'torta_seca' => $torta_seca,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Peso seco de caña guardado correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}
