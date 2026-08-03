<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('foliares.boro');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/Foliares/boro_model.php';

if (!function_exists('calcularPpmBSolucionFoliar')) {
    function calcularPpmBSolucionFoliar(float $absorbancia, float $absBlanco): float
    {
        $pendiente = 0.1414;
        if ($pendiente == 0.0) {
            return 0.0;
        }

        return ($absorbancia - $absBlanco) / $pendiente;
    }
}

if (!function_exists('calcularPpmBMuestraFoliar')) {
    function calcularPpmBMuestraFoliar(float $ppmBSolucion, float $pesoMuestra): float
    {
        if ($pesoMuestra <= 0) {
            return 0.0;
        }

        return ($ppmBSolucion * 50) / $pesoMuestra;
    }
}

$config = lab_generic_analysis_config('foliares-boro');
if (!$config) {
    lab_forbidden('El formulario de boro foliar no esta configurado.');
}

$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => 'Boro en Foliares',
];
$labAnalysisLegacyConfig = $labAnalysisContexto;
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;
$GLOBALS['labAnalysisLegacyConfig'] = $labAnalysisLegacyConfig;
$GLOBALS['labSkipFooterBaseSave'] = true;

$resultado = lab_analysis_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $campos = ['peso_muestra', 'abs_blanco', 'absorbancia'];
    $resultados = [];
    $ppmBSolucion = 0.0;
    $ppmBMuestra = 0.0;
    $erroresCurva = [];

    for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
        if (!lab_post_row_has_data($campos, $fila)) {
            continue;
        }

        $pesoMuestra = lab_post_float('peso_muestra', $fila);
        $absBlanco = lab_post_float('abs_blanco', $fila);
        $absorbancia = lab_post_float('absorbancia', $fila);

        $ppmBSolucion = calcularPpmBSolucionFoliar($absorbancia, $absBlanco);
        $ppmBMuestra = calcularPpmBMuestraFoliar($ppmBSolucion, $pesoMuestra);
        $metadata = function_exists('labLegacyAutoMetadataForInsert') ? labLegacyAutoMetadataForInsert() : [];

        $resultadoFila = guardarBoroFoliar(
            $pesoMuestra,
            $absBlanco,
            $absorbancia,
            $ppmBSolucion,
            $ppmBMuestra,
            $metadata
        );
        $resultados[] = $resultadoFila;

        if (!empty($resultadoFila['exito'])) {
            $idBoro = (int) ($resultadoFila['id'] ?? 0);
            $puntosCurva = $_POST['punto_curva'] ?? [];
            $absCurva = $_POST['abs_curva'] ?? [];

            if (!is_array($puntosCurva)) {
                $puntosCurva = [$puntosCurva];
            }
            if (!is_array($absCurva)) {
                $absCurva = [$absCurva];
            }

            foreach ($puntosCurva as $indice => $puntoCurvaRaw) {
                $absCurvaRaw = $absCurva[$indice] ?? '';
                $puntoCurvaTexto = trim((string) $puntoCurvaRaw);
                $absCurvaTexto = trim((string) $absCurvaRaw);

                if ($puntoCurvaTexto === '' && $absCurvaTexto === '') {
                    continue;
                }

                $idCurva = guardarCurvaBoroFoliar((float) $puntoCurvaRaw, (float) $absCurvaRaw);
                if (!$idCurva) {
                    $erroresCurva[] = 'No se pudo guardar el punto de curva ' . ($indice + 1) . '.';
                    continue;
                }

                if (!relacionarBoroCurvaFoliar($idBoro, $idCurva)) {
                    $erroresCurva[] = 'No se pudo relacionar el punto de curva ' . ($indice + 1) . '.';
                }
            }
        }
    }

    $resultado = lab_resultado_multiple($resultados, 'boro foliar');
    $resultado['ppm_b_solucion'] = $ppmBSolucion;
    $resultado['ppm_b_muestra'] = $ppmBMuestra;

    if ($erroresCurva) {
        $resultado = [
            'exito' => false,
            'mensaje' => 'El análisis principal se guardó correctamente, pero hubo errores al guardar la curva. ' . implode(' ', $erroresCurva),
        ];
    }
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Foliares/boro_view.php';
