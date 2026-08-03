<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.macros');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = lab_generic_analysis_config('aguas-macros');
if (!$config) {
    lab_forbidden('El formulario de macronutrientes de aguas no esta configurado.');
}

$resultado = null;
$labSkipFooterBaseSave = true;
$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => $config['elemento'] . ' en ' . $config['tipo'],
];
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;

if (!function_exists('aguasMacrosCalcularMgl')) {
    function aguasMacrosCalcularMgl(float $lectura, float $blanco): float
    {
        return $lectura - $blanco;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'ca_ml', 'mg_ml', 'k_ml', 'na_ml', 'blanco_ca', 'blanco_mg', 'blanco_k', 'blanco_na'];
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
        $configGuardar['fields'][] = ['name' => 'ca_mgl', 'label' => 'Ca mg/L'];
        $configGuardar['fields'][] = ['name' => 'mg_mgl', 'label' => 'Mg mg/L'];
        $configGuardar['fields'][] = ['name' => 'k_mgl', 'label' => 'K mg/L'];
        $configGuardar['fields'][] = ['name' => 'na_mgl', 'label' => 'Na mg/L'];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $caMl = lab_post_float('ca_ml', $fila);
            $mgMl = lab_post_float('mg_ml', $fila);
            $kMl = lab_post_float('k_ml', $fila);
            $naMl = lab_post_float('na_ml', $fila);
            $blancoCa = lab_post_float('blanco_ca', $fila);
            $blancoMg = lab_post_float('blanco_mg', $fila);
            $blancoK = lab_post_float('blanco_k', $fila);
            $blancoNa = lab_post_float('blanco_na', $fila);

            try {
                // La hoja de calculo replica una resta simple: lectura menos blanco.
                $caMgl = aguasMacrosCalcularMgl($caMl, $blancoCa);
                $mgMgl = aguasMacrosCalcularMgl($mgMl, $blancoMg);
                $kMgl = aguasMacrosCalcularMgl($kMl, $blancoK);
                $naMgl = aguasMacrosCalcularMgl($naMl, $blancoNa);

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
                    throw new RuntimeException('No se pudo identificar el tipo de analisis para macronutrientes.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el numero de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'ca_ml' => $caMl,
                    'mg_ml' => $mgMl,
                    'k_ml' => $kMl,
                    'na_ml' => $naMl,
                    'blanco_ca' => $blancoCa,
                    'blanco_mg' => $blancoMg,
                    'blanco_k' => $blancoK,
                    'blanco_na' => $blancoNa,
                    'ca_mgl' => $caMgl,
                    'mg_mgl' => $mgMgl,
                    'k_mgl' => $kMgl,
                    'na_mgl' => $naMgl,
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
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de macronutrientes.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultados[] = [
                    'exito' => true,
                    'mensaje' => 'Macronutrientes guardados correctamente.',
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

        $resultado = lab_resultado_multiple($resultados, 'macronutrientes');
    }
}

require_once __DIR__ . '/../../view/analisis_generico_view.php';
