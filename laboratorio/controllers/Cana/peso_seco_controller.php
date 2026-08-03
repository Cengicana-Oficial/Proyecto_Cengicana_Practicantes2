<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('cana.peso_seco');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/Cana/peso_seco_model.php';

$config = lab_generic_analysis_config('cana-peso-seco');
if (!$config) {
    lab_forbidden('El formulario de peso seco de caña no esta configurado.');
}

$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => 'Peso Seco de Caña',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;
$GLOBALS['labSkipFooterBaseSave'] = true;

if (!function_exists('calcularTortaSecaCana')) {
    function calcularTortaSecaCana(float $pesoBandeja, float $bandejaSeca): float
    {
        return $bandejaSeca - $pesoBandeja;
    }
}

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['num_bandeja', 'peso_bandeja', 'peso_muestra', 'bandeja_seca'];
    $resultados = [];
    $tortaSeca = 0.0;
    $bandejaHumeda = 0.0;

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $numBandeja = lab_post_float('num_bandeja', $fila);
        $pesoBandeja = lab_post_float('peso_bandeja', $fila);
        $pesoMuestra = lab_post_float('peso_muestra', $fila);
        $bandejaSeca = lab_post_float('bandeja_seca', $fila);

        $bandejaHumeda = $pesoBandeja + $pesoMuestra;
        $tortaSeca = calcularTortaSecaCana($pesoBandeja, $bandejaSeca);
        $metadata = function_exists('labLegacyAutoMetadataForInsert') ? labLegacyAutoMetadataForInsert() : [];

        $resultados[] = guardarPesoSecoCana(
            $numBandeja,
            $pesoBandeja,
            $pesoMuestra,
            $bandejaHumeda,
            $bandejaSeca,
            $tortaSeca,
            $metadata
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'peso seco de caña');
    $resultado['torta_seca'] = $tortaSeca;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/analisis_generico_view.php';
