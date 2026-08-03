<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.micros');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/Suelos/micros_model.php';

$labAnalysisContexto = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['Micro Nutrientes (Cu, Zn, Fe, Mn, K)'],
    'label' => 'Micro Nutrientes en Suelos',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = [
        'peso', 'conc_cu', 'conc_zn', 'conc_fe', 'conc_mn', 'conc_k',
        'blk_cu', 'blk_zn', 'blk_fe', 'blk_mn', 'blk_k',
    ];
    $resultados = [];
    $ppm_cu = 0;
    $ppm_zn = 0;
    $ppm_fe = 0;
    $ppm_mn = 0;
    $ppm_k = 0;

    $controlLote = lab_post_string('control_lote');
    $controlNumeroLaboratorio = lab_post_string('control_numero_laboratorio');
    $controlPeso = lab_post_float('control_peso');
    $controlConcCu = lab_post_float('control_conc_cu');
    $controlConcZn = lab_post_float('control_conc_zn');
    $controlConcFe = lab_post_float('control_conc_fe');
    $controlConcMn = lab_post_float('control_conc_mn');
    $controlConcK = lab_post_float('control_conc_k');
    $controlBlkCu = lab_post_float('control_blk_cu');
    $controlBlkZn = lab_post_float('control_blk_zn');
    $controlBlkFe = lab_post_float('control_blk_fe');
    $controlBlkMn = lab_post_float('control_blk_mn');
    $controlBlkK = lab_post_float('control_blk_k');

    if ($controlLote !== '' || $controlNumeroLaboratorio !== '') {
        $ppm_cu = $controlPeso != 0 ? (($controlConcCu - $controlBlkCu) * 25) / $controlPeso : 0;
        $ppm_zn = $controlPeso != 0 ? (($controlConcZn - $controlBlkZn) * 25) / $controlPeso : 0;
        $ppm_fe = $controlPeso != 0 ? (($controlConcFe - $controlBlkFe) * 25) / $controlPeso : 0;
        $ppm_mn = $controlPeso != 0 ? (($controlConcMn - $controlBlkMn) * 25) / $controlPeso : 0;
        $ppm_k = $controlPeso != 0 ? (($controlConcK - $controlBlkK) * 25) / $controlPeso : 0;

        $resultados[] = guardarMicros(
            $controlPeso,
            $controlConcCu,
            $controlConcZn,
            $controlConcFe,
            $controlConcMn,
            $controlConcK,
            $controlBlkCu,
            $controlBlkZn,
            $controlBlkFe,
            $controlBlkMn,
            $controlBlkK,
            $ppm_cu,
            $ppm_zn,
            $ppm_fe,
            $ppm_mn,
            $ppm_k,
            0,
            ['id_formulario' => null]
        );
    }

    for ($fila = 0, $total = lab_post_row_count(array_merge($campos, ['lote', 'numero_laboratorio'])); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $peso = lab_post_float('peso', $fila);
        $conc_cu = lab_post_float('conc_cu', $fila);
        $conc_zn = lab_post_float('conc_zn', $fila);
        $conc_fe = lab_post_float('conc_fe', $fila);
        $conc_mn = lab_post_float('conc_mn', $fila);
        $conc_k = lab_post_float('conc_k', $fila);
        $blk_cu = lab_post_float('blk_cu', $fila);
        $blk_zn = lab_post_float('blk_zn', $fila);
        $blk_fe = lab_post_float('blk_fe', $fila);
        $blk_mn = lab_post_float('blk_mn', $fila);
        $blk_k = lab_post_float('blk_k', $fila);

        $ppm_cu = $peso != 0 ? (($conc_cu - $blk_cu) * 25) / $peso : 0;
        $ppm_zn = $peso != 0 ? (($conc_zn - $blk_zn) * 25) / $peso : 0;
        $ppm_fe = $peso != 0 ? (($conc_fe - $blk_fe) * 25) / $peso : 0;
        $ppm_mn = $peso != 0 ? (($conc_mn - $blk_mn) * 25) / $peso : 0;
        $ppm_k = $peso != 0 ? (($conc_k - $blk_k) * 25) / $peso : 0;

        $resultados[] = guardarMicros(
            $peso,
            $conc_cu,
            $conc_zn,
            $conc_fe,
            $conc_mn,
            $conc_k,
            $blk_cu,
            $blk_zn,
            $blk_fe,
            $blk_mn,
            $blk_k,
            $ppm_cu,
            $ppm_zn,
            $ppm_fe,
            $ppm_mn,
            $ppm_k,
            0
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'micros');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/micros_view.php';
?>
