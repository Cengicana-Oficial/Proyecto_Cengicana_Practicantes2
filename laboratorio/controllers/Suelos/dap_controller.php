<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.dap');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/dap_model.php';

$labAnalysisContexto = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['Densidad aparente (DAP)'],
    'label' => 'Densidad Aparente (DAP) en Suelos',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['blanco', 'control', 'peso_caja', 'peso_muestra_seca', 'volumen_final'];
    $resultados = [];

    for ($fila = 0, $total = lab_post_row_count(array_merge($campos, ['lote', 'numero_laboratorio'])); $fila < $total; $fila++) {
        if (!lab_post_row_has_data(array_merge($campos, ['lote', 'numero_laboratorio']), $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $pesoCaja = lab_post_float('peso_caja', $fila);
        $pesoMuestraSeca = lab_post_float('peso_muestra_seca', $fila);
        $volumenFinal = lab_post_float('volumen_final', $fila);
        $pesoSueloSeco = $pesoMuestraSeca - $pesoCaja;
        $densidad = $volumenFinal != 0 ? ($pesoSueloSeco / $volumenFinal) : 0;

        $resultados[] = guardarDapSuelo([
            'peso_caja' => $pesoCaja,
            'peso_muestra_seca' => $pesoMuestraSeca,
            'volumen_final' => $volumenFinal,
            'peso_suelo_seco' => $pesoSueloSeco,
            'densidad' => $densidad,
        ]);
    }

    $resultado = lab_resultado_multiple($resultados, 'densidad aparente (DAP)');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/dap_view.php';
