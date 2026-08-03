<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.cic');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/cic_model.php';

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['cic_blanco', 'cic_muestra', 'control'];
    $resultados = [];
    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);
    $controlesPorLote = labSharedControlRowsByLote(['cic_blanco', 'control']);

    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $lote = lab_post_string('lote', $fila);
        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $controlesLote = $controlesPorLote[$lote] ?? [];
        $cicBlanco = (float) ($controlesLote['cic_blanco'] ?? 0);
        $cicMuestra = lab_post_float('cic_muestra', $fila);
        $control = (float) ($controlesLote['control'] ?? 0);
        $cicMeq = (($cicMuestra - $cicBlanco) * 0.0298039 * 1000) / 5;

        $resultados[] = guardarCic([
            'cic_blanco' => $cicBlanco,
            'cic_muestra' => $cicMuestra,
            'cic_meq' => $cicMeq,
            'control' => $control,
        ]);
    }

    $resultado = lab_resultado_multiple($resultados, 'cic');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/cic_view.php';
