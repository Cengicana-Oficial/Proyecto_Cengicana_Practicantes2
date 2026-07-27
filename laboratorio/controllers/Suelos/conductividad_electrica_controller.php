<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.conductividad_electrica');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/conductividad_electrica_model.php';

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $campos = [
        'lectura_ce',
        'temperatura'
    ];

    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);

    $resultados = [];

    // Guarda la lectura de la fila anterior
    $lecturaAnterior = null;

    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {

        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);

        // Ignorar filas creadas por los controles compartidos
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $lectura = lab_post_float('lectura_ce', $fila);
        $temperatura = lab_post_float('temperatura', $fila);

        // Primera fila (Agua)
        if ($lecturaAnterior === null) {
            $ce = 0;
        } else {
            $ce = (($lectura - $lecturaAnterior) * 0.994) / 1000;
        }

        // La lectura actual pasa a ser la anterior para la siguiente fila
        $lecturaAnterior = $lectura;

        $resultados[] = guardarConductividadElectrica(
            [
                'LecturaCE' => $lectura,
                'Temperatura' => $temperatura,
                'CE' => $ce,
            ],

        );
    }

    $resultado = lab_resultado_multiple(
        $resultados,
        'conductividad eléctrica'
    );
}

lab_analysis_redirect_after_success($resultado);

require_once __DIR__ . '/../../view/Suelos/conductividad_electrica_view.php';