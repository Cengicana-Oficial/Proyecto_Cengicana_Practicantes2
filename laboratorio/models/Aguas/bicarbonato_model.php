<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarBicarbonato($ml_acl, $ml_carbonatos, $normalidad_h2oso4, $volumen_muestra, $bicarbonatos_mgl, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'agua_bicarbonatos', [
        'ml_hcl' => $ml_acl,
        'ml_carbonatos' => $ml_carbonatos,
        'normalidad_h2so4' => $normalidad_h2oso4,
        'volumen_muestra' => $volumen_muestra,
        'bicarbonatos_mgl' => $bicarbonatos_mgl,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Bicarbonatos guardados correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
