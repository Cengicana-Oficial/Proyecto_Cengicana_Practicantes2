<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.macroscic');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/Suelos/macroscic_model.php';

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $campos = [
        'peso', 'ppm_ca', 'ppm_mg', 'ppm_k', 'ppm_na',
        'blk_ca', 'blk_mg', 'blk_k', 'blk_na', 'control', 'cic_muestra',
    ];
    $resultados = [];
    $meq_ca = 0;
    $meq_mg = 0;
    $meq_k = 0;
    $meq_na = 0;
    $cic_meq = 0;

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $peso = lab_post_float('peso', $fila);
        $ppm_ca = lab_post_float('ppm_ca', $fila);
        $ppm_mg = lab_post_float('ppm_mg', $fila);
        $ppm_k = lab_post_float('ppm_k', $fila);
        $ppm_na = lab_post_float('ppm_na', $fila);
        $blk_ca = lab_post_float('blk_ca', $fila);
        $blk_mg = lab_post_float('blk_mg', $fila);
        $blk_k = lab_post_float('blk_k', $fila);
        $blk_na = lab_post_float('blk_na', $fila);
        $control = lab_post_float('control', $fila);
        $cic_muestra = lab_post_float('cic_muestra', $fila);

        $cic_blanco = 0.1;
        $meq_ca = $peso != 0 ? (($ppm_ca - $blk_ca) * 4.99) / $peso : 0;
        $meq_mg = $peso != 0 ? (($ppm_mg - $blk_mg) * 8.2264) / $peso : 0;
        $meq_k = $peso != 0 ? (($ppm_k - $blk_k) * 0.2557) / $peso : 0;
        $meq_na = $peso != 0 ? (($ppm_na - $blk_na) * 0.4348) / $peso : 0;
        $cic_meq = $peso != 0 ? (($cic_muestra - $cic_blanco) * 0.0298039 * 1000) / $peso : 0;

        $resultados[] = guardarMacroscic(
            $peso,
            $ppm_ca,
            $ppm_mg,
            $ppm_k,
            $ppm_na,
            $blk_ca,
            $blk_mg,
            $blk_k,
            $blk_na,
            $meq_ca,
            $meq_mg,
            $meq_k,
            $meq_na,
            $control,
            $cic_blanco,
            $cic_muestra,
            $cic_meq
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'macros y CIC');
    $resultado['meq_ca'] = $meq_ca;
    $resultado['meq_mg'] = $meq_mg;
    $resultado['meq_k'] = $meq_k;
    $resultado['meq_na'] = $meq_na;
    $resultado['cic_meq'] = $cic_meq;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/macroscic_view.php';
?>
