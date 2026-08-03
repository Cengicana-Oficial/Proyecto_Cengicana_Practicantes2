<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.pmp');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/Suelos/pmp_model.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['peso_caja', 'peso_caja_mhumeda', 'peso_caja_mseca', 'no_caja', 'control'];
    $resultados = [];
    $psh = 0;
    $pss = 0;
    $porcentaje_pmp = 0;
    $controlesPorLote = labSharedControlRowsByLote(['control']);
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

        $peso_caja = lab_post_float('peso_caja', $fila);
        $peso_caja_mhumeda = lab_post_float('peso_caja_mhumeda', $fila);
        $peso_caja_mseca = lab_post_float('peso_caja_mseca', $fila);
        $no_caja = lab_post_string('no_caja', $fila);
        $control = (float) (($controlesPorLote[$lote]['control'] ?? 0));

        $psh = $peso_caja_mhumeda - $peso_caja;
        $pss = $peso_caja_mseca - $peso_caja;
        $porcentaje_pmp = ($pss != 0) ? (($psh - $pss) / $pss) * 100 : 0;

        $resultados[] = guardarPMP(
            $peso_caja,
            $peso_caja_mhumeda,
            $peso_caja_mseca,
            $psh,
            $pss,
            $porcentaje_pmp,
            $no_caja,
            $control
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'pmp');
    $resultado['psh'] = $psh;
    $resultado['pss'] = $pss;
    $resultado['porcentaje_pmp'] = $porcentaje_pmp;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/pmp_view.php';
?>
