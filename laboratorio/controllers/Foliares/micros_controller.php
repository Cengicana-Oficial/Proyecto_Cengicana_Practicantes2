<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('foliares.micros');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/Foliares/micros_model.php';

$labAnalysisContexto = [
    'tipos' => ['foliares', 'foliar'],
    'analisis' => ['Micro Nutrientes (Cu, Zn, Fe, Mn, K)'],
    'label' => 'Micro Nutrientes Foliares',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;
$GLOBALS['labSkipFooterBaseSave'] = true;

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $campos = ['peso', 'conc_cu', 'conc_zn', 'conc_fe', 'conc_mn', 'blk_cu', 'blk_zn', 'blk_fe', 'blk_mn', 'control'];
    $resultados = [];
    $ppm_cu = 0;
    $ppm_zn = 0;
    $ppm_fe = 0;
    $ppm_mn = 0;

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $peso = lab_post_float('peso', $fila);
        $conc_cu = lab_post_float('conc_cu', $fila);
        $conc_zn = lab_post_float('conc_zn', $fila);
        $conc_fe = lab_post_float('conc_fe', $fila);
        $conc_mn = lab_post_float('conc_mn', $fila);
        $blk_cu = lab_post_float('blk_cu', $fila);
        $blk_zn = lab_post_float('blk_zn', $fila);
        $blk_fe = lab_post_float('blk_fe', $fila);
        $blk_mn = lab_post_float('blk_mn', $fila);
        $control = lab_post_float('control', $fila);

        $ppm_cu = $peso != 0 ? (($conc_cu - $blk_cu) * 50) / $peso : 0;
        $ppm_zn = $peso != 0 ? (($conc_zn - $blk_zn) * 50) / $peso : 0;
        $ppm_fe = $peso != 0 ? (($conc_fe - $blk_fe) * 50) / $peso : 0;
        $ppm_mn = $peso != 0 ? (($conc_mn - $blk_mn) * 50) / $peso : 0;

        $resultados[] = guardarMicros(
            $peso,
            $conc_cu,
            $conc_zn,
            $conc_fe,
            $conc_mn,
            $blk_cu,
            $blk_zn,
            $blk_fe,
            $blk_mn,
            $ppm_cu,
            $ppm_zn,
            $ppm_fe,
            $ppm_mn,
            $control
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'micros foliares');
    $resultado['ppm_cu'] = $ppm_cu;
    $resultado['ppm_zn'] = $ppm_zn;
    $resultado['ppm_fe'] = $ppm_fe;
    $resultado['ppm_mn'] = $ppm_mn;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Foliares/micros_view.php';
?>
