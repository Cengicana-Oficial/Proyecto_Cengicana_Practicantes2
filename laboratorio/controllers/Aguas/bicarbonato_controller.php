<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.bicarbonato');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = [
    'key' => 'aguas.bicarbonato',
    'tipo' => 'Aguas',
    'elemento' => 'Bicarbonatos',
    'table' => 'agua_bicarbonatos',
    'tipos' => ['agua', 'aguas'],
    'analisis' => ['Bicarbonatos', 'Bicarbonato'],
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

if (!function_exists('bicarbonatosCalcularMgL')) {
    function bicarbonatosCalcularMgL(float $mlAlcalinidad, float $mlCarbonatos, float $normalidadH2so4, float $volumenMuestra): float
    {
        if ($volumenMuestra == 0.0) {
            throw new RuntimeException('El volumen de la muestra no puede ser cero para calcular bicarbonatos.');
        }

        // Replica la fórmula del formato físico: (Alcalinidad total - 2 * Carbonatos) * Normalidad * 50000 / Volumen
        return ($mlAlcalinidad - (2 * $mlCarbonatos)) * $normalidadH2so4 * 50000 / $volumenMuestra;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'ml_acl', 'ml_carbonatos', 'volumen_muestra'];
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
            ['name' => 'ml_hcl', 'label' => 'mL H2SO4 alcalinidad total'],
            ['name' => 'ml_carbonatos', 'label' => 'mL H2SO4 carbonatos'],
            ['name' => 'normalidad_h2so4', 'label' => 'Normalidad H2SO4'],
            ['name' => 'volumen_muestra', 'label' => 'Volumen muestra'],
            ['name' => 'bicarbonatos_mgl', 'label' => 'Bicarbonatos mg/L'],
        ];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $mlAcl = lab_post_float('ml_acl', $fila);
            $mlCarbonatos = lab_post_float('ml_carbonatos', $fila);
            $volumenMuestra = lab_post_float('volumen_muestra', $fila);

            try {
                $bicarbonatosMgl = bicarbonatosCalcularMgL($mlAcl, $mlCarbonatos, $normalidadH2so4, $volumenMuestra);

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
                    throw new RuntimeException('No se pudo identificar el tipo de análisis para bicarbonatos.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el numero de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'ml_hcl' => $mlAcl,
                    'ml_carbonatos' => $mlCarbonatos,
                    'normalidad_h2so4' => $normalidadH2so4,
                    'volumen_muestra' => $volumenMuestra,
                    'bicarbonatos_mgl' => $bicarbonatosMgl,
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
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de bicarbonatos.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultados[] = [
                    'exito' => true,
                    'mensaje' => 'Bicarbonatos guardados correctamente.',
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

        $resultado = lab_resultado_multiple($resultados, 'bicarbonatos');
    }
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Aguas/bicarbonato_view.php';
