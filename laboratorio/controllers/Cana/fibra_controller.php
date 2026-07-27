<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('cana.fibra');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/Cana/fibra_model.php';

$config = lab_generic_analysis_config('cana-fibra');
if (!$config) {
    lab_forbidden('El formulario de fibra de caña no esta configurado.');
}

$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => 'Fibra de Caña',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;
$GLOBALS['labSkipFooterBaseSave'] = true;

if (!function_exists('calcularFibraTanimotoCana')) {
    function calcularFibraTanimotoCana(float $brixJugo, float $tortaHumeda, float $tortaSeca): float
    {
        $denominador = 5 * (100 - $brixJugo);
        if ($denominador == 0.0) {
            return 0.0;
        }

        return ((100 * $tortaSeca) - ($brixJugo * $tortaHumeda)) / $denominador;
    }
}

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['vd', 'brix_jugo', 'torta_humeda', 'torta_seca'];
    $resultados = [];
    $fibra = 0.0;

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $vd = lab_post_string('vd', $fila);
        $brixJugo = lab_post_float('brix_jugo', $fila);
        $tortaHumeda = lab_post_float('torta_humeda', $fila);
        $tortaSeca = lab_post_float('torta_seca', $fila);

        $fibra = calcularFibraTanimotoCana($brixJugo, $tortaHumeda, $tortaSeca);
        $metadata = function_exists('labLegacyAutoMetadataForInsert') ? labLegacyAutoMetadataForInsert() : [];

        $resultados[] = guardarFibraCana(
            $vd,
            $brixJugo,
            $tortaHumeda,
            $tortaSeca,
            $fibra,
            $metadata
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'fibra de caña');
    $resultado['fibra'] = $fibra;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/analisis_generico_view.php';
