<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.nitrogeno');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/Suelos/nitrogeno_model.php';

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['peso', 'ml_blanco', 'ml_muestra', 'x_nitrogeno', 'control'];
    $resultados = [];
    $porcentaje_nitro = 0;
    $controlesPorLote = labSharedControlRowsByLote(['ml_blanco', 'control']);
    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);

    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $lote = lab_post_string('lote', $fila);
        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $peso = lab_post_float('peso', $fila);
        $ml_blanco = (float) (($controlesPorLote[$lote]['ml_blanco'] ?? 0));
        $ml_muestra = lab_post_float('ml_muestra', $fila);
        $normalidad = 0.0101;
        $x_nitrogeno = lab_post_float('x_nitrogeno', $fila);
        $control = (float) (($controlesPorLote[$lote]['control'] ?? 0));

        $porcentaje_nitro = $peso > 0
            ? (($ml_muestra - $ml_blanco) * 0.0099779 * 1.408 / $peso)
            : 0;

        $resultados[] = guardarNitrogeno(
            $peso,
            $ml_blanco,
            $ml_muestra,
            $porcentaje_nitro,
            $normalidad,
            $x_nitrogeno,
            $control
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'nitrogeno');
    $resultado['porcentaje_nitro'] = $porcentaje_nitro;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/nitrogeno_view.php';
?>
