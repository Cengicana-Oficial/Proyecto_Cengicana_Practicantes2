<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.alcanilidad');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = [
    'key' => 'aguas.alcanilidad',
    'tipo' => 'Aguas',
    'elemento' => 'Alcalinidad',
    'table' => 'agua_alcalinidad',
    'tipos' => ['agua', 'aguas'],
    'analisis' => ['Alcalinidad', 'Alcalinidades'],
];

$resultado = lab_analysis_take_flash();

$resultado = null;
$labSkipFooterBaseSave = true;
$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => $config['elemento'] . ' en ' . $config['tipo'],
];
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;

if (!function_exists('alcalinidadCalcularMgL')) {
    function alcalinidadCalcularMgL(float $mlH2so4, float $normalidadH2so4, float $volumenMuestra): float
    {
        if ($volumenMuestra == 0.0) {
            throw new RuntimeException('El volumen de la muestra no puede ser cero para calcular alcalinidad.');
        }

        // Fórmula: (mL H2SO4 * Normalidad H2SO4 * 50000) / Volumen muestra
        return ($mlH2so4 * $normalidadH2so4 * 50000) / $volumenMuestra;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'ml_h2oso4', 'volumen_muestra'];
    $resultados = [];
    $fecha = trim((string) ($_POST['fecha'] ?? date('Y-m-d')));
    $analista = trim((string) ($_POST['analista'] ?? ''));
    $normalidadH2so4 = 0.02;

    if ($fecha === '' || $analista === '') {
        $resultado = [
            'exito' => false,
            'mensaje' => 'Complete fecha y analista para guardar el registro.',
        ];
    } else {
        labFormularioEnsureSchema();

        $configGuardar = $config;
        $configGuardar['fields'] = [
            ['name' => 'ml_h2so4', 'label' => 'mL H2SO4'],
            ['name' => 'normalidad_h2so4', 'label' => 'Normalidad H2SO4'],
            ['name' => 'vol_muestra', 'label' => 'Volumen muestra'],
            ['name' => 'alcalinidad_mgl', 'label' => 'Alcalinidad mg/L'],
        ];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $mlH2so4 = lab_post_float('ml_h2oso4', $fila);
            $volumenMuestra = lab_post_float('volumen_muestra', $fila);

            try {
                $alcalinidadMgl = alcalinidadCalcularMgL($mlH2so4, $normalidadH2so4, $volumenMuestra);

                $pdo = Conexion::conectar();
                $useTransaction = !$pdo->inTransaction();
                if ($useTransaction) {
                    $pdo->beginTransaction();
                }

                $destino = labGenericDestino($config, $codigoLote, $numeroLaboratorio);
                if (empty($destino['id_lote'])) {
                    throw new RuntimeException('No se pudo identificar el lote "' . $codigoLote . '".');
                }

                if (empty($destino['id_solicitud'])) {
                    throw new RuntimeException('No se pudo identificar la solicitud asociada al lote "' . $codigoLote . '".');
                }

                if (empty($destino['id_tipo_analisis'])) {
                    throw new RuntimeException('No se pudo identificar el tipo de análisis para alcalinidad.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el numero de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'ml_h2so4' => $mlH2so4,
                    'normalidad_h2so4' => $normalidadH2so4,
                    'vol_muestra' => $volumenMuestra,
                    'alcalinidad_mgl' => $alcalinidadMgl,
                ];

                labGenericInsertarAnalisis(
                    $configGuardar,
                    $row,
                    $destino,
                    $idFormulario,
                    $fecha,
                    $codigoLote,
                    $numeroLaboratorio
                );
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de alcalinidad.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultados[] = [
                    'exito' => true,
                    'mensaje' => 'Alcalinidad guardada correctamente.',
                ];
            } catch (Throwable $e) {
                if (isset($pdo, $useTransaction) && $useTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $resultados[] = [
                    'exito' => false,
                    'mensaje' => $e->getMessage(),
                ];
            }
        }

        $resultado = lab_resultado_multiple($resultados, 'alcalinidad');
    }
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Aguas/alcanilidad_view.php';
?>
