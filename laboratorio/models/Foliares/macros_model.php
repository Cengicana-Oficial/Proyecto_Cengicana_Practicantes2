<?php

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

function guardarMacrosFoliares(
    $peso,
    $calcio,
    $magnesio,
    $potasio,
    $sodio,
    $blk_calcio,
    $blk_magnesio,
    $blk_potasio,
    $blk_sodio,
    $porcentaje_calcio,
    $porcentaje_magnesio,
    $porcentaje_potasio,
    $porcentaje_sodio,
    array $metadata = []
)
{
    global $conn;

    $id = labLegacyInsertAnalysisRow($conn, 'foliar_macros', [
        'peso' => $peso,
        'calcio' => $calcio,
        'magnesio' => $magnesio,
        'potasio' => $potasio,
        'sodio' => $sodio,
        'blk_calcio' => $blk_calcio,
        'blk_magnesio' => $blk_magnesio,
        'blk_potasio' => $blk_potasio,
        'blk_sodio' => $blk_sodio,
        'porcentaje_calcio' => $porcentaje_calcio,
        'porcentaje_magnesio' => $porcentaje_magnesio,
        'porcentaje_potasio' => $porcentaje_potasio,
        'porcentaje_sodio' => $porcentaje_sodio,
    ], $metadata);

    if ($id !== false) {
        return [
            'exito' => true,
            'mensaje' => 'Macros foliares guardados correctamente.',
            'id' => $id,
        ];
    }

    return [
        'exito' => false,
        'mensaje' => 'Error al guardar.',
    ];
}
