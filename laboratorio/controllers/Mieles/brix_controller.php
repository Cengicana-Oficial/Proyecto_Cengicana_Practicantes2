<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('mieles.brix');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/Mieles/brix_model.php';

$config = lab_generic_analysis_config('mieles-brix');
if (!$config) {
    lab_forbidden('El formulario de brix de mieles no esta configurado.');
}

$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => 'Brix de Mieles',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;
$GLOBALS['labSkipFooterBaseSave'] = true;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['producto', 'ingenio', 'dia_zafra', 'brix_obs'];
    $resultados = [];

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $producto = lab_post_string('producto', $fila);
        $ingenio = lab_post_string('ingenio', $fila);
        $diaZafra = lab_post_string('dia_zafra', $fila);
        $brixObs = lab_post_float('brix_obs', $fila);
        $brixCorr = $brixObs * 2;
        $metadata = function_exists('labLegacyAutoMetadataForInsert') ? labLegacyAutoMetadataForInsert() : [];

        $resultados[] = guardarBrixMieles(
            $producto,
            $ingenio,
            $diaZafra,
            $brixObs,
            $brixCorr,
            $metadata
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'brix de mieles');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Mieles/brix_view.php';
