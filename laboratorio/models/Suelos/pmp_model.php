<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarPMP($peso_caja, $peso_caja_mhumeda, $peso_caja_mseca, $psh, $pss, $porcentaje_pmp, $no_caja, $control, array $metadata = [])
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_pmp', [
        'peso_caja' => $peso_caja,
        'peso_caja_mhumeda' => $peso_caja_mhumeda,
        'peso_caja_mseca' => $peso_caja_mseca,
        'psh' => $psh,
        'pss' => $pss,
        'porcentaje_pmp' => $porcentaje_pmp,
        'no_caja' => $no_caja,
        'control' => $control,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Punto de Marchitez Permanente guardado correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
