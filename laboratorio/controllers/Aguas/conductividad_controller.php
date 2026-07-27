<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.conductividad');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/Aguas/conductividad_model.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $campos = ['lectura_conductividad', 'temperatura'];
    $resultados = [];
    $ce = 0;

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $lectura_conductividad = lab_post_float('lectura_conductividad', $fila);
        $temperatura = lab_post_float('temperatura', $fila);
        $ce = (($lectura_conductividad * 0.9985) / 1000);

        $resultados[] = guardarConductividad($lectura_conductividad, $temperatura, $ce);
    }

    $resultado = lab_resultado_multiple($resultados, 'conductividad');
    $resultado['ce'] = $ce;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Aguas/conductividad_view.php';
?>
