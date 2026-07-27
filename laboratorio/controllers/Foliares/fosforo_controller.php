<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('foliares.fosforo');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../models/Foliares/fosforo_model.php';
require_once __DIR__ . '/../../models/conexion.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $campos = ['peso', 'abs_blanco', 'absorbancia', 'control'];
    $resultados = [];
    $ppm_p_sol = 0;
    $porcentaje_p = 0;

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $peso = lab_post_float('peso', $fila);
        $abs_blanco = lab_post_float('abs_blanco', $fila);
        $absorbancia = lab_post_float('absorbancia', $fila);
        $control = lab_post_float('control', $fila);

        $ppm_p_sol = (($absorbancia - $abs_blanco) / 0.0329);
        if ($ppm_p_sol < 0) {
            $ppm_p_sol = 0;
        }

        $porcentaje_p = $peso != 0 ? (($ppm_p_sol * 0.005) / $peso) : 0;
        if ($porcentaje_p < 0) {
            $porcentaje_p = 0;
        }

        $resultadoFila = guardarFosforo($peso, $abs_blanco, $absorbancia, $ppm_p_sol, $porcentaje_p, $control);
        $resultados[] = $resultadoFila;

        if (!empty($resultadoFila['exito']) && isset($resultadoFila['id'], $_POST['punto_curva'], $_POST['abs_curva'])) {
            foreach ($_POST['punto_curva'] as $i => $punto) {
                $id_curva = guardarCurvaFosforo((float) $punto, (float) ($_POST['abs_curva'][$i] ?? 0));
                if ($id_curva) {
                    relacionarFosforoCurva((int) $resultadoFila['id'], $id_curva);
                }
            }
        }
    }

    $resultado = lab_resultado_multiple($resultados, 'fosforo foliar');
    $resultado['ppm_p_sol'] = $ppm_p_sol;
    $resultado['porcentaje_p'] = $porcentaje_p;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Foliares/fosforo_view.php';
?>
