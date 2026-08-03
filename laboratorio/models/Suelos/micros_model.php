<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarMicros($peso, $conc_cu, $conc_zn, $conc_fe, $conc_mn, $conc_k, $blk_cu, $blk_zn, $blk_fe, $blk_mn, $blk_k, $ppm_cu, $ppm_zn, $ppm_fe, $ppm_mn, $ppm_k, $control, array $metadata = [])
{
    $conn = (new Conexion())->conectar();
    $id = labLegacyInsertAnalysisRow($conn, 'suelo_micros', [
        'peso' => $peso,
        'conc_cu' => $conc_cu,
        'conc_zn' => $conc_zn,
        'conc_fe' => $conc_fe,
        'conc_mn' => $conc_mn,
        'conc_k' => $conc_k,
        'blk_cu' => $blk_cu,
        'blk_zn' => $blk_zn,
        'blk_fe' => $blk_fe,
        'blk_mn' => $blk_mn,
        'blk_k' => $blk_k,
        'ppm_cu' => $ppm_cu,
        'ppm_zn' => $ppm_zn,
        'ppm_fe' => $ppm_fe,
        'ppm_mn' => $ppm_mn,
        'ppm_k' => $ppm_k,
        'control' => $control,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Micro Nutrientes guardados correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
