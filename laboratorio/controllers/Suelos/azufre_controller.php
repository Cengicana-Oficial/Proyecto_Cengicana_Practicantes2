<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.azufre');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/legacy_analysis_form_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/azufre_model.php';
require_once __DIR__ . '/../../models/conexion.php';

$conexion = new Conexion();
$conn = $conexion->conectar();
$labAnalysisLegacyConfig = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['Azufre', 'SO4'],
];

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['abs_blanco', 'absorbancia', 'control'];
    $resultados = [];
    $ppm_so4 = 0;
    $controlesPorLote = labSharedControlRowsByLote(['abs_blanco', 'control']);
    $rowFields = array_merge($campos, ['lote', 'numero_laboratorio']);

    for ($fila = 0, $total = lab_post_row_count($rowFields); $fila < $total; $fila++) {
        $lote = lab_post_string('lote', $fila);
        $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
        if (labSharedControlKeyFromNumero($numeroLaboratorio) !== null) {
            continue;
        }

        $absorbanciaRaw = lab_post_string('absorbancia', $fila);
        if ($absorbanciaRaw === '') {
            continue;
        }

        $controlesLote = $controlesPorLote[$lote] ?? [];
        $abs_blanco = (float) ($controlesLote['abs_blanco'] ?? 0);
        $absorbancia = is_numeric($absorbanciaRaw) ? (float) $absorbanciaRaw : 0.0;
        $control = (float) ($controlesLote['control'] ?? 0);

        $ppm_so4 = (($absorbancia * 1) - $abs_blanco) * (25 / 10) * (100 + 1.408 / 100);
        if ($ppm_so4 < 0) {
            $ppm_so4 = 0;
        }

        $resultadoFila = guardarAzufre($abs_blanco, $absorbancia, $ppm_so4, $control);
        $resultados[] = $resultadoFila;

        if (!empty($resultadoFila['exito']) && isset($resultadoFila['id'], $_POST['punto_curva'], $_POST['abs_curva'])) {
            foreach ($_POST['punto_curva'] as $i => $punto) {
                $id_curva = guardarCurvaAzufre((float) $punto, (float) ($_POST['abs_curva'][$i] ?? 0));
                if ($id_curva) {
                    relacionarAzufreCurva((int) $resultadoFila['id'], $id_curva);
                }
            }
        }
    }

    $resultado = lab_resultado_multiple($resultados, 'azufre');
    $resultado['ppm_so4'] = $ppm_so4;
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/azufre_view.php';
?>
