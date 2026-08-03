<?php
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../legacy_analysis_model_helper.php';

function guardarMicros($conc_cu, $conc_zn, $conc_fe, $conc_mn, $blk_cu, $blk_zn, $blk_fe, $blk_mn, $cu_mgl, $zn_mgl, $fe_mgl, $mn_mgl, array $metadata = [])
{
    $conn = (new Conexion())->conectar();

    $id = labLegacyInsertAnalysisRow($conn, 'agua_micros', [
        'conc_cu' => $conc_cu,
        'conc_zn' => $conc_zn,
        'conc_fe' => $conc_fe,
        'conc_mn' => $conc_mn,
        'blk_cu' => $blk_cu,
        'blk_zn' => $blk_zn,
        'blk_fe' => $blk_fe,
        'blk_mn' => $blk_mn,
        'cu_mgl' => $cu_mgl,
        'zn_mgl' => $zn_mgl,
        'fe_mgl' => $fe_mgl,
        'mn_mgl' => $mn_mgl,
    ], $metadata);

    if ($id !== false) {
        return ['exito' => true, 'mensaje' => 'Micro Nutrientes guardados correctamente.', 'id' => $id];
    }

    return ['exito' => false, 'mensaje' => 'Error al guardar.'];
}
?>
