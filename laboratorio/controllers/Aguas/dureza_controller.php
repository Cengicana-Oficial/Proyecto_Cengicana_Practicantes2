<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.dureza');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = lab_generic_analysis_config('aguas-dureza');
if (!$config) {
    lab_forbidden('El formulario de dureza de aguas no esta configurado.');
}

$resultado = null;
$labSkipFooterBaseSave = true;
$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => $config['elemento'] . ' en ' . $config['tipo'],
];
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;

if (!function_exists('durezaCalcularMgL')) {
    function durezaCalcularMgL(float $mlEdta, float $mlMuestra): float
    {
        if ($mlMuestra == 0.0) {
            throw new RuntimeException('El volumen de la muestra no puede ser cero para calcular la dureza.');
        }

        // Replica la fórmula del formato físico: (mL EDTA * 1000) / mL muestra
        return ($mlEdta * 1000) / $mlMuestra;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'ml_edta', 'ml_muestra'];
    $resultados = [];
    $fecha = trim((string) ($_POST['fecha'] ?? date('Y-m-d')));
    $analista = trim((string) ($_POST['analista'] ?? ''));

    if ($fecha === '' || $analista === '') {
        $resultado = [
            'exito' => false,
            'mensaje' => 'Complete fecha y analista para guardar el registro.',
        ];
    } else {
        labFormularioEnsureSchema();

        $configGuardar = $config;
        $configGuardar['fields'][] = [
            'name' => 'dureza',
            'label' => 'Dureza',
        ];

        $resultadosGuardado = [];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $mlEdta = lab_post_float('ml_edta', $fila);
            $mlMuestra = lab_post_float('ml_muestra', $fila);

            try {
                $durezaCalculada = durezaCalcularMgL($mlEdta, $mlMuestra);

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
                    throw new RuntimeException('No se pudo identificar el tipo de análisis para dureza.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el numero de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'ml_edta' => $mlEdta,
                    'ml_muestra' => $mlMuestra,
                    'dureza' => $durezaCalculada,
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
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de dureza.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultadosGuardado[] = [
                    'exito' => true,
                    'mensaje' => 'Dureza guardada correctamente.',
                ];
            } catch (Throwable $e) {
                if (isset($pdo, $useTransaction) && $useTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $resultadosGuardado[] = [
                    'exito' => false,
                    'mensaje' => $e->getMessage(),
                ];
            }
        }

        $resultado = lab_resultado_multiple($resultadosGuardado, 'dureza');
    }
}

require_once __DIR__ . '/../../view/analisis_generico_view.php';
