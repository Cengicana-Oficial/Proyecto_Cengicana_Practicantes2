<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.humedad_gravimetrica');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/humedad_gravimetrica_model.php';

$labAnalysisContexto = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['Humedad'],
    'label' => 'Humedad gravimetrica en Suelos',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $campos = ['NoCaja', 'PesoCaja', 'PesoCajaHumeda', 'PesoCajaMseca'];
    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);

    $resultados = [];

    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {

        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);

        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $noCaja = lab_post_float('NoCaja', $fila);
        $pesoCaja = lab_post_float('PesoCaja', $fila);
        $pesoCajaMHumeda = lab_post_float('PesoCajaHumeda', $fila);
        $pesoCajaMseca = lab_post_float('PesoCajaMseca', $fila);

        // Cálculos
        $pesoHumedo = $pesoCajaMHumeda - $pesoCaja;
        $pesoSeco = $pesoCajaMseca - $pesoCaja;

        $humedadGravimetrica = ($pesoHumedo != 0)
            ? (($pesoCajaMHumeda - $pesoCajaMseca) * 100) / $pesoSeco
            : 0;

        $resultados[] = guardarHumedadGravimetrica([
            'NoCaja' => $noCaja,
            'PesoCaja' => $pesoCaja,
            'PesoCajaMHumeda' => $pesoCajaMHumeda,
            'PesoCajaMseca' => $pesoCajaMseca,
            'PesoSeco' => $pesoSeco,
            'PesoHumedo' => $pesoHumedo,
            'PorHGrav' => $humedadGravimetrica,
        ]);
    }

    $resultado = lab_resultado_multiple($resultados, 'humedad gravimétrica');
}

lab_analysis_redirect_after_success($resultado);

require_once __DIR__ . '/../../view/Suelos/humedad_gravimetrica_view.php';
