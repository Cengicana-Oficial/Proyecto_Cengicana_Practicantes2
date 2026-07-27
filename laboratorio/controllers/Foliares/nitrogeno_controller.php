<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('foliares.nitrogeno');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/Foliares/nitrogeno_model.php';

if (!function_exists('calcularPorcentajeNitrogenoFoliar')) {
    function calcularPorcentajeNitrogenoFoliar(float $peso, float $mlHclBlanco, float $mlHclMuestra): float
    {
        if ($peso <= 0) {
            return 0.0;
        }

        return (($mlHclMuestra - $mlHclBlanco) * 0.0099779 * 1.408) / $peso;
    }
}

$config = lab_generic_analysis_config('foliares-nitrogeno');
if (!$config) {
    lab_forbidden('El formulario de nitrogeno foliar no esta configurado.');
}

$config['elemento'] = 'Nitrógeno';

$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => ['Nitrógeno'],
    'label' => 'Nitrógeno en Foliares',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;
$GLOBALS['labSkipFooterBaseSave'] = true;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $peso = lab_post_float('peso');
    $mlHclBlanco = lab_post_float('ml_hcl_blk');
    $mlHclMuestra = lab_post_float('ml_hcl_muestra');

    $porcentajeNitrogeno = calcularPorcentajeNitrogenoFoliar($peso, $mlHclBlanco, $mlHclMuestra);

    $resultado = guardarNitrogenoFoliar(
        $peso,
        $mlHclBlanco,
        $mlHclMuestra,
        $porcentajeNitrogeno
    );
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/analisis_generico_view.php';
