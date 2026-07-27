<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.fosforo');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/fosforo_model.php';
require_once __DIR__ . '/../../models/conexion.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['abs_blanco', 'absorbancia'];
    $resultados = [];
    $ppm_sol = 0;
    $ppm_p = 0;
    $controlesPorLote = labSharedControlRowsByLote(['abs_blanco', 'control']);
    $controlLote = lab_post_string('control_lote');
    $controlNumeroLaboratorio = lab_post_string('control_numero_laboratorio');
    $controlAbsBlanco = lab_post_float('control_abs_blanco');
    $controlAbsorbancia = lab_post_float('control_absorbancia');

    if ($controlLote !== '' || $controlNumeroLaboratorio !== '') {
        $ppm_sol = (($controlAbsorbancia - $controlAbsBlanco) / 0.0481);
        if ($ppm_sol < 0) {
            $ppm_sol = 0;
        }

        $ppm_p = $ppm_sol * 5;
        $resultados[] = guardarFosforo($controlAbsBlanco, $controlAbsorbancia, $ppm_sol, $ppm_p, 0);
    }

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

        $controlesLote = $controlesPorLote[$lote] ?? [];
        $abs_blanco = (float) ($controlesLote['abs_blanco'] ?? 0);
        $absorbancia = lab_post_float('absorbancia', $fila);
        $control = (float) ($controlesLote['control'] ?? 0);

        $ppm_sol = (($absorbancia - $abs_blanco) / 0.0481);
        if ($ppm_sol < 0) {
            $ppm_sol = 0;
        }

        $ppm_p = $ppm_sol * 5;
        $resultadoFila = guardarFosforo($abs_blanco, $absorbancia, $ppm_sol, $ppm_p, $control);
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

    $resultado = lab_resultado_multiple($resultados, 'fosforo');
    $resultado['ppm_sol'] = $ppm_sol;
    $resultado['ppm_p'] = $ppm_p;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/fosforo_view.php';
?>
