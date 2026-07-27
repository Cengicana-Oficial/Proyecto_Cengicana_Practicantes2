<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.boro');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/shared_lot_controls_helper.php';
require_once __DIR__ . '/../../models/Suelos/boro_model.php';
require_once __DIR__ . '/../../models/conexion.php';

$conexion = new Conexion();
$conn = $conexion->conectar();

$labAnalysisContexto = [
    'tipos' => ['suelos', 'suelo'],
    'analisis' => ['Boro'],
    'label' => 'Boro de Suelos',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;

$resultado = lab_analysis_take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['abs_blanco', 'absorbancia'];
    $resultados = [];
    $ppm_b = 0;
    $controlesPorLote = labSharedControlRowsByLote(['abs_blanco', 'control']);
    $controlLote = lab_post_string('control_lote');
    $controlNumeroLaboratorio = lab_post_string('control_numero_laboratorio');
    $controlAbsBlanco = lab_post_float('control_abs_blanco');
    $controlAbsorbancia = lab_post_float('control_absorbancia');

    if ($controlLote !== '' || $controlNumeroLaboratorio !== '') {
        $ppm_b = (($controlAbsorbancia - $controlAbsBlanco) * 40 * (100 + 1.408)) / (20 * 100);
        if ($ppm_b < 0) {
            $ppm_b = 0;
        }

        $resultados[] = guardarBoro($controlAbsBlanco, $controlAbsorbancia, $ppm_b, 0);
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

        $absorbanciaRaw = lab_post_string('absorbancia', $fila);
        if ($absorbanciaRaw === '') {
            continue;
        }

        $controlesLote = $controlesPorLote[$lote] ?? [];
        $abs_blanco = (float) ($controlesLote['abs_blanco'] ?? 0);
        $absorbancia = is_numeric($absorbanciaRaw) ? (float) $absorbanciaRaw : 0.0;
        $control = (float) ($controlesLote['control'] ?? 0);

        $ppm_b = (($absorbancia - $abs_blanco) * 40 * (100 + 1.408)) / (20 * 100);
        if ($ppm_b < 0) {
            $ppm_b = 0;
        }

        $resultadoFila = guardarBoro($abs_blanco, $absorbancia, $ppm_b, $control);
        $resultados[] = $resultadoFila;

        if (!empty($resultadoFila['exito']) && isset($resultadoFila['id_boro'], $_POST['punto_curva'], $_POST['abs_curva'])) {
            foreach ($_POST['punto_curva'] as $i => $punto) {
                $id_curva = guardarCurvaBoro((float) $punto, (float) ($_POST['abs_curva'][$i] ?? 0));
                if ($id_curva) {
                    relacionarBoroCurva((int) $resultadoFila['id_boro'], $id_curva);
                }
            }
        }
    }

    $resultado = lab_resultado_multiple($resultados, 'boro');
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Suelos/boro_view.php';
?>
