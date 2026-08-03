<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarAlcanilidad($ml_h2oso4, $normalidad_h2oso4, $vol_muestra, $alcanilidad_mgl, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'agua_alcalinidad', [
        'ml_h2so4' => $ml_h2oso4,
        'normalidad_h2so4' => $normalidad_h2oso4,
        'vol_muestra' => $vol_muestra,
        'alcalinidad_mgl' => $alcanilidad_mgl,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Alcalinidad guardada correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
