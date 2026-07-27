<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarResistividad($lectura_resistividad, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'agua_resistividad', [
        'lectura_resistividad' => $lectura_resistividad,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Resistividad guardada correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
