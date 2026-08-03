<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarNitrogeno($peso, $ml_blanco, $ml_muestra, $porcentaje_nitro, $normalidad, $x_nitrogeno, $control, array $metadata = [])
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_nitrogeno', [
        'peso' => $peso,
        'hcl_blanco' => $ml_blanco,
        'hcl_muestra' => $ml_muestra,
        'porcentaje_n' => $porcentaje_nitro,
        'normalidad' => $normalidad,
        'x_nitrogeno' => $x_nitrogeno,
        'control' => $control,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Porcentaje de nitrogeno guardado correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
