<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarCC($peso_caja, $peso_caja_mhumeda, $peso_caja_mseca, $psh, $pss, $porcentaje_cc, $no_caja, $control, array $metadata = [])
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_cc', [
        'peso_caja' => $peso_caja,
        'peso_caja_mhumeda' => $peso_caja_mhumeda,
        'peso_caja_mseca' => $peso_caja_mseca,
        'psh' => $psh,
        'pss' => $pss,
        'porcentaje_cc' => $porcentaje_cc,
        'no_caja' => $no_caja,
        'control' => $control,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Capacidad de Campo guardada correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
