<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('cana.humedad');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/Cana/humedad_model.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $campos = ['no_bandeja', 'peso_bandeja', 'peso_muestra', 'peso_bandeja_seca'];
    $resultados = [];
    $peso_bandeja_humedad = 0;
    $porcentaje_humedad = 0;

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $no_bandeja = lab_post_float('no_bandeja', $fila);
        $peso_bandeja = lab_post_float('peso_bandeja', $fila);
        $peso_muestra = lab_post_float('peso_muestra', $fila);
        $peso_bandeja_seca = lab_post_float('peso_bandeja_seca', $fila);

        $peso_bandeja_humedad = $peso_bandeja + $peso_muestra;
        $porcentaje_humedad = $peso_muestra != 0
            ? (($peso_bandeja_humedad - $peso_bandeja_seca) * 100 / $peso_muestra)
            : 0;

        $resultados[] = guardarHumedad(
            $no_bandeja,
            $peso_bandeja,
            $peso_muestra,
            $peso_bandeja_seca,
            $peso_bandeja_humedad,
            $porcentaje_humedad
        );
    }

    $resultado = lab_resultado_multiple($resultados, 'humedad de cana');
    $resultado['peso_bandeja_humedad'] = $peso_bandeja_humedad;
    $resultado['porcentaje_humedad'] = $porcentaje_humedad;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Cana/humedad_view.php';
?>
