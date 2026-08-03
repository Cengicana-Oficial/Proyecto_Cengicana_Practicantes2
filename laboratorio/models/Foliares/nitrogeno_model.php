<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

function guardarNitrogenoFoliar(
    $peso,
    $ml_hcl_blanco,
    $ml_hcl_muestra,
    $porcentaje_nitrogeno,
    array $metadata = []
)
{
    global $conn;

    $id = labLegacyInsertAnalysisRow($conn, 'foliar_nitrogeno', [
        'peso' => $peso,
        'ml_hcl_blanco' => $ml_hcl_blanco,
        'ml_hcl_muestra' => $ml_hcl_muestra,
        'porcentaje_nitrogeno' => $porcentaje_nitrogeno,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Nitrogeno foliar guardado correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}
