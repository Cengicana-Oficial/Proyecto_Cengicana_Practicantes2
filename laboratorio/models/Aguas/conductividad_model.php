<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarConductividad($lectura_conductividad, $temperatura, $ce, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'agua_conductividad', [
        'lectura_conductividad' => $lectura_conductividad,
        'temperatura' => $temperatura,
        'ce' => $ce,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Conductividad guardada correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
