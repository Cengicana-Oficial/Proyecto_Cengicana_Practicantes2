<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.humedad_residual');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/humedad_residual_model.php';

$labAnalysisContexto = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['Humedad residual'],
    'label' => 'Humedad Residual en Suelos',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['NoCaja', 'PesoCaja', 'PesoCajaMseca', 'PesoMuestraHumedo'];
    $resultados = [];
    $control = lab_post_float('control');
    $controlLote = lab_post_string('control_lote');
    $controlNumeroLaboratorio = lab_post_string('control_numero_laboratorio');
    $controlPesoCaja = lab_post_float('control_PesoCaja');
    $controlPesoCajaMseca = lab_post_float('control_PesoCajaMseca');
    $controlPesoMuestraHumedo = lab_post_float('control_PesoMuestraHumedo');

    if ($controlLote !== '' || $controlNumeroLaboratorio !== '') {
        $controlPesoCajaMHumeda = $controlPesoCaja + $controlPesoMuestraHumedo;
        $controlPorHGrav = $controlPesoMuestraHumedo != 0
            ? ((($controlPesoCajaMHumeda - $controlPesoCajaMseca) * 100) / $controlPesoMuestraHumedo)
            : 0;

        $resultados[] = guardarHumedadResidualSuelo([
            'Control' => $control,
            'PesoCaja' => $controlPesoCaja,
            'PesoCajaMHumeda' => $controlPesoCajaMHumeda,
            'PesoCajaMseca' => $controlPesoCajaMseca,
            'PesoMuestraHumedo' => $controlPesoMuestraHumedo,
            'PorHGrav' => $controlPorHGrav,
        ], [
            'no_lab' => $controlNumeroLaboratorio,
        ]);
    }

    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);
    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $control = lab_post_float('control', $fila);
        $pesoCaja = lab_post_float('PesoCaja', $fila);
        $pesoCajaMseca = lab_post_float('PesoCajaMseca', $fila);
        $PesoMuestraHumedo = lab_post_float('PesoMuestraHumedo', $fila);
        $pesoCajaMHumeda = $pesoCaja + $PesoMuestraHumedo;
        $porHGrav = $PesoMuestraHumedo != 0 ? ((($pesoCajaMHumeda - $pesoCajaMseca) * 100) / $PesoMuestraHumedo) : 0;

        $resultados[] = guardarHumedadResidualSuelo([
            'Control' => $control,
            'PesoCaja' => $pesoCaja,
            'PesoCajaMHumeda' => $pesoCajaMHumeda,
            'PesoCajaMseca' => $pesoCajaMseca,
            'PesoMuestraHumedo' => $PesoMuestraHumedo,
            'PorHGrav' => $porHGrav,
        ]);
    }

    $resultado = lab_resultado_multiple($resultados, 'humedad residual');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/humedad_residual_view.php';
