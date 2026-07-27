<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('cana.brixpol');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../models/Cana/brixpol_model.php';

$labSkipFooterBaseSave = true;
$GLOBALS['labSkipFooterBaseSave'] = true;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['brix', 'pol', 'peso_torta'];
    $resultados = [];

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $brix = lab_post_float('brix', $fila);
        $pol = lab_post_float('pol', $fila);
        $pesoTorta = lab_post_float('peso_torta', $fila);
        $metadata = function_exists('labLegacyAutoMetadataForInsert') ? labLegacyAutoMetadataForInsert() : [];

        $resultados[] = guardarBrixPol($brix, $pol, $pesoTorta, $metadata);
    }

    $resultado = lab_resultado_multiple($resultados, 'brix y pol');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Cana/brixpol_view.php';
