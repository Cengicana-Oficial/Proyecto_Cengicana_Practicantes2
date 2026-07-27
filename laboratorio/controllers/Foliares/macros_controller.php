<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('foliares.macros');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/Foliares/macros_model.php';

if (!function_exists('calcularPorcentajeMacro')) {
    function calcularPorcentajeMacro(float $concentracion, float $blanco, float $peso): float
    {
        if ($peso <= 0) {
            return 0.0;
        }

        return (($concentracion - $blanco) * 0.05) / $peso;
    }
}

$config = lab_generic_analysis_config('foliares-macros');
if (!$config) {
    lab_forbidden('El formulario de macronutrientes foliares no esta configurado.');
}

$config['elemento'] = 'Macronutrientes';

$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => 'Macronutrientes en Foliares',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;
$GLOBALS['labSkipFooterBaseSave'] = true;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $peso = lab_post_float('peso');
    $calcio = lab_post_float('calcio');
    $magnesio = lab_post_float('magnesio');
    $potasio = lab_post_float('potasio');
    $sodio = lab_post_float('sodio');
    $blk_calcio = lab_post_float('blk_calcio');
    $blk_magnesio = lab_post_float('blk_magnesio');
    $blk_potasio = lab_post_float('blk_potasio');
    $blk_sodio = lab_post_float('blk_sodio');

    $porcentaje_calcio = calcularPorcentajeMacro($calcio, $blk_calcio, $peso);
    $porcentaje_magnesio = calcularPorcentajeMacro($magnesio, $blk_magnesio, $peso);
    $porcentaje_potasio = calcularPorcentajeMacro($potasio, $blk_potasio, $peso);
    $porcentaje_sodio = calcularPorcentajeMacro($sodio, $blk_sodio, $peso);

    $resultado = guardarMacrosFoliares(
        $peso,
        $calcio,
        $magnesio,
        $potasio,
        $sodio,
        $blk_calcio,
        $blk_magnesio,
        $blk_potasio,
        $blk_sodio,
        $porcentaje_calcio,
        $porcentaje_magnesio,
        $porcentaje_potasio,
        $porcentaje_sodio
    );
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/analisis_generico_view.php';
