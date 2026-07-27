<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.ph');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/ph_model.php';

$labAnalysisContexto = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['pH'],
    'label' => 'pH en Suelos',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['blanco', 'control', 'ph', 'temperatura'];
    $resultados = [];
    $filasEspeciales = [
        ['prefix' => 'agua_control'],
        ['prefix' => 'control'],
    ];

    foreach ($filasEspeciales as $filaEspecial) {
        $prefix = $filaEspecial['prefix'];
        $tieneDatos = false;

        foreach ($campos as $campo) {
            if (trim(lab_post_string($prefix . '_' . $campo)) !== '') {
                $tieneDatos = true;
                break;
            }
        }

        if (!$tieneDatos) {
            continue;
        }

        $resultados[] = guardarPhSuelo([
            'ph' => lab_post_float($prefix . '_ph'),
            'temperatura' => lab_post_float($prefix . '_temperatura'),
        ], [
            'id_formulario' => null,
        ]);
    }

    for ($fila = 0, $total = lab_post_row_count(array_merge($campos, ['lote', 'numero_laboratorio'])); $fila < $total; $fila++) {
        if (!lab_post_row_has_data(array_merge($campos, ['lote', 'numero_laboratorio']), $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $resultados[] = guardarPhSuelo([
            'ph' => lab_post_float('ph', $fila),
            'temperatura' => lab_post_float('temperatura', $fila),
        ]);
    }

    $hayLotesNormales = false;
    foreach ((array) ($_POST['lote'] ?? []) as $lotePosteado) {
        if (trim((string) $lotePosteado) !== '') {
            $hayLotesNormales = true;
            break;
        }
    }

    if (!$hayLotesNormales) {
        $labSkipFooterBaseSave = true;
    }

    $resultado = lab_resultado_multiple($resultados, 'ph');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/ph_view.php';
