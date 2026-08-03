<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarMacroscic($peso, $ppm_ca, $ppm_mg, $ppm_k, $ppm_na, $blk_ca, $blk_mg, $blk_k, $blk_na, $meq_ca, $meq_mg, $meq_k, $meq_na, $control, $cic_blanco, $cic_muestra, $cic_meq, array $metadata = [])
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_macros', [
        'peso' => $peso,
        'ppm_ca' => $ppm_ca,
        'ppm_mg' => $ppm_mg,
        'ppm_k' => $ppm_k,
        'ppm_na' => $ppm_na,
        'blk_ca' => $blk_ca,
        'blk_mg' => $blk_mg,
        'blk_k' => $blk_k,
        'blk_na' => $blk_na,
        'meq_ca' => $meq_ca,
        'meq_mg' => $meq_mg,
        'meq_k' => $meq_k,
        'meq_na' => $meq_na,
        'control' => $control,
        'cic_blanco' => $cic_blanco,
        'cic_muestra' => $cic_muestra,
        'cic_meq' => $cic_meq,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Macro Nutrientes y Cic guardados correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
