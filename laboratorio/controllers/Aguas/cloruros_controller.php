<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.cloruros');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = [
    'key' => 'aguas.cloruros',
    'tipo' => 'Aguas',
    'elemento' => 'Cloruros',
    'table' => 'agua_cloruros',
    'tipos' => ['agua', 'aguas'],
    'analisis' => ['Cloruros'],
    'fields' => [
        ['name' => 'ml_muestra', 'label' => 'mL muestra'],
        ['name' => 'ml_agno3_blanco', 'label' => 'mL AgNO3 blanco'],
        ['name' => 'ml_agno3_muestra', 'label' => 'mL AgNO3 muestra'],
        ['name' => 'normalidad_agno3', 'label' => 'Normalidad AgNO3'],
        ['name' => 'cloruros_mgl', 'label' => 'Cloruros mg/L'],
    ],
];

$resultado = lab_analysis_take_flash();
$resultado = null;
$labSkipFooterBaseSave = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'ml_muestra', 'ml_agno3_blanco', 'ml_agno3_muestra'];
    $resultados = [];
    $cloruros_mgl = 0;
    $fecha = trim((string) ($_POST['fecha'] ?? date('Y-m-d')));
    $analista = trim((string) ($_POST['analista'] ?? ''));

    if ($fecha === '' || $analista === '') {
        $resultado = [
            'exito' => false,
            'mensaje' => 'Complete fecha y analista para guardar el registro.',
        ];
    } else {
        labFormularioEnsureSchema();

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $ml_muestra = lab_post_float('ml_muestra', $fila);
            $ml_agno3_blanco = lab_post_float('ml_agno3_blanco', $fila);
            $ml_agno3_muestra = lab_post_float('ml_agno3_muestra', $fila);
            $normalidad_agno3 = 0.0141;
            $cloruros_mgl = $ml_muestra != 0
                ? (($ml_agno3_muestra - $ml_agno3_blanco) * $normalidad_agno3 * 35450) / $ml_muestra
                : 0;

            try {
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
                    throw new RuntimeException('No se pudo identificar el tipo de análisis para cloruros.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el número de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'ml_muestra' => $ml_muestra,
                    'ml_agno3_blanco' => $ml_agno3_blanco,
                    'ml_agno3_muestra' => $ml_agno3_muestra,
                    'normalidad_agno3' => $normalidad_agno3,
                    'cloruros_mgl' => $cloruros_mgl,
                ];

                labGenericInsertarAnalisis(
                    $config,
                    $row,
                    $destino,
                    $idFormulario,
                    $fecha,
                    $codigoLote,
                    $numeroLaboratorio
                );
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de cloruros.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultados[] = ['exito' => true, 'mensaje' => 'Cloruros guardados correctamente.'];
            } catch (Throwable $e) {
                if (isset($pdo, $useTransaction) && $useTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $resultados[] = ['exito' => false, 'mensaje' => $e->getMessage()];
            }
        }

        $resultado = lab_resultado_multiple($resultados, 'cloruros');
        $resultado['cloruros_mgl'] = $cloruros_mgl;
    }
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Aguas/cloruros_view.php';
