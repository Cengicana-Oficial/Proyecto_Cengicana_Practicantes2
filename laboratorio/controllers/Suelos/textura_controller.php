<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.textura');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/textura_model.php';

function obtenerTextura($arena, $limo, $arcilla, $total) {
    if (round($total, 2) != 100) {
        return "Error: La suma debe ser 100%. Actualmente: $total%";
    }

    if ($arcilla >= 40 && $limo >= 40 && $limo < 60 && $arena <= 20) {
        return "Arcillo limoso";
    } elseif ($arcilla >= 35 && $arena > 45 && $limo < 20) {
        return "Arcillo arenoso";
    } elseif ($arcilla >= 40 && $limo < 40 && $arena <= 45) {
        return "Arcilloso";
    } elseif ($arcilla >= 27 && $arcilla < 40 && $limo >= 40) {
        return "Franco arcillo limoso";
    } elseif ($arcilla >= 27 && $arcilla < 40 && $limo < 40 && $arena <= 45) {
        return "Franco arcilloso";
    } elseif ($arcilla >= 20 && $arcilla < 35 && $arena > 45 && $limo < 20) {
        return "Franco arcillo arenoso";
    } elseif ($arcilla >= 7 && $arcilla < 27 && $limo >= 28 && $limo <= 50 && $arena >= 23 && $arena <= 52) {
        return "Franco";
    } elseif ($limo >= 50 && $limo < 80 && $arcilla < 27) {
        return "Franco limoso";
    } elseif ($limo >= 80 && $arcilla < 12) {
        return "Limoso";
    } elseif ($arena >= 43 && $arena < 85 && $arcilla < 20) {
        return "Franco arenoso";
    } elseif ($arena >= 70 && $arena < 90 && $arcilla < 15) {
        return "Arenoso franco";
    } elseif ($arena >= 85 && $arcilla < 10 && $limo < 15) {
        return "Arenoso";
    } else {
        return "Clasificación no definida";
    }
}

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['blanco', 'control', 'porcentaje_hr', 'lectura_1', 'temp_1', 'lectura_2', 'temp_2', 'textura'];
    $resultados = [];
    $fecha = $_POST['fecha'] ?? date('Y-m-d');

    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);
    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $porcentajeHr = lab_post_float('porcentaje_hr', $fila);
        $lectura1 = lab_post_float('lectura_1', $fila);
        $temp1 = lab_post_float('temp_1', $fila);
        $lectura2 = lab_post_float('lectura_2', $fila);
        $temp2 = lab_post_float('temp_2', $fila);

        $factor = (100 - $porcentajeHr) != 0 ? 200 / (100 - $porcentajeHr) : 0;
        $lecturaCorregida1 = (($temp1 - 60) * 0.2) + $lectura1;
        $porcentajeLA = $lecturaCorregida1 * $factor;
        $lecturaCorregida2 = (($temp2 - 60) * 0.2) + $lectura2;
        $porcentajeArcilla = $lecturaCorregida2 * $factor;
        $porcentajeLimo = $porcentajeLA - $porcentajeArcilla;
        $porcentajeArena = 100 - $porcentajeLA;
        $totalCalculado = $porcentajeArcilla + $porcentajeLimo + $porcentajeArena;

        $texturaCalculada = obtenerTextura($porcentajeArena, $porcentajeLimo, $porcentajeArcilla, $totalCalculado);

        $resultados[] = guardarTexturaSuelo([
            'no_lab' => $numeroLaboratorio,
            'fecha' => $fecha,
            'porcentaje_hr' => $porcentajeHr,
            'lectura_1' => $lectura1,
            'temp_1' => $temp1,
            'lectura_corregida_1' => $lecturaCorregida1,
            'porcentaje_l_a' => $porcentajeLA,
            'lectura_2' => $lectura2,
            'temp_2' => $temp2,
            'lectura_corregida_2' => $lecturaCorregida2,
            'total' => $totalCalculado,
            'porcentaje_arcilla' => $porcentajeArcilla,
            'porcentaje_limo' => $porcentajeLimo,
            'porcentaje_arena' => $porcentajeArena,
            'textura' => $texturaCalculada,
        ]);
    }

    $resultado = lab_resultado_multiple($resultados, 'textura');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/textura_view.php';
