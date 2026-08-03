<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.humedad');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/humedad_model.php';

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['blanco', 'control', 'humedad'];
    $resultados = [];

    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);
    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $resultados[] = guardarHumedadSuelo([
            'humedad' => lab_post_float('humedad', $fila),
        ]);
    }

    $resultado = lab_resultado_multiple($resultados, 'humedad');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/humedad_view.php';
