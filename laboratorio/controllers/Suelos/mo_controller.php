<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.mo');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/mo_model.php';

$labSkipFooterBaseSave = true;
$labAnalysisContexto = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['%MO'],
    'label' => 'Materia Organica en Suelos',
];
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = [
        'peso_muestra',
        'sulfato_ferroso_consumido',
        'm1_dicromato',
        'm2_dicromato',
        'dicromato_potasio',
        'blanco_sulfato_ferroso',
        'blanco_sulfato_ferroso_2',
    ];
    $resultados = [];
    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);
    $controlesPorLote = labSharedControlRowsByLote([
        'blanco_sulfato_ferroso',
        'blanco_sulfato_ferroso_2',
    ]);

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
        $pesoMuestra = lab_post_float('peso_muestra', $fila);
        $sulfatoFerrosoConsumido = lab_post_float('sulfato_ferroso_consumido', $fila);
        $m1Dicromato = lab_post_float('m1_dicromato', $fila);
        $m2Dicromato = lab_post_float('m2_dicromato', $fila);
        $dicromatoPotasio = lab_post_float('dicromato_potasio', $fila);
        $blancoSulfatoFerroso = (float) ($controlesLote['blanco_sulfato_ferroso'] ?? 0);
        $blancoSulfatoFerroso2 = (float) ($controlesLote['blanco_sulfato_ferroso_2'] ?? 0);

        $valSolucionFerroso = ($m1Dicromato + $m2Dicromato) / 2;
        $normalidadSulfatoFerroso = $valSolucionFerroso != 0 ? ($dicromatoPotasio / $valSolucionFerroso) : 0;
        $mlUtilSulfatoFerroso1N = ($blancoSulfatoFerroso + $blancoSulfatoFerroso2) / 2;
        $dicromatoConsumido = $valSolucionFerroso;
        $porcentajeCarbonoOrganico = $pesoMuestra != 0
            ? ((($mlUtilSulfatoFerroso1N - $sulfatoFerrosoConsumido) * $normalidadSulfatoFerroso * 0.39) / $pesoMuestra)
            : 0;
        $porcentajeMateriaOrganica = $porcentajeCarbonoOrganico * 1.724;

        $resultados[] = guardarMo([
            'peso_muestra' => $pesoMuestra,
            'sulfato_ferroso_consumido' => $sulfatoFerrosoConsumido,
            'porcentaje_carbono_organico' => $porcentajeCarbonoOrganico,
            'porcentaje_materia_organica' => $porcentajeMateriaOrganica,
            'm1_dicromato' => $m1Dicromato,
            'm2_dicromato' => $m2Dicromato,
            'val_solucion_ferroso' => $valSolucionFerroso,
            'normalidad_sulfato_ferroso' => $normalidadSulfatoFerroso,
            'ml_util_sulfato_ferroso1N' => $mlUtilSulfatoFerroso1N,
            'dicromato_potasio' => $dicromatoPotasio,
            'dicromato_consumido' => $dicromatoConsumido,
            'blanco_sulfato_ferroso' => $blancoSulfatoFerroso,
            'blanco_sulfato_ferroso_2' => $blancoSulfatoFerroso2,
        ]);
    }

    $resultado = lab_resultado_multiple($resultados, 'materia organica');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/mo_view.php';
