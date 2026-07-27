<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.ras');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = lab_generic_analysis_config('aguas-ras');
if (!$config) {
    lab_forbidden('El formulario de RAS de aguas no esta configurado.');
}

$resultado = null;
$labSkipFooterBaseSave = true;
$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => $config['elemento'] . ' en ' . $config['tipo'],
];
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;

if (!function_exists('rasConvertirMeq')) {
    function rasConvertirMeq(float $valor, float $equivalente): float
    {
        if ($equivalente == 0.0) {
            throw new RuntimeException('El equivalente no puede ser cero para convertir a meq/L.');
        }

        return $valor / $equivalente;
    }
}

if (!function_exists('rasCalcularRas')) {
    function rasCalcularRas(float $naMeq, float $caMeq, float $mgMeq): float
    {
        $denominador = sqrt(($caMeq + $mgMeq) / 2);
        if ($denominador == 0.0) {
            throw new RuntimeException('No se puede calcular RAS con denominador cero.');
        }

        return $naMeq / $denominador;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'na_ug', 'ca_ug', 'mg_ug'];
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
        $configGuardar['fields'][] = ['name' => 'na_meq', 'label' => 'Na meq'];
        $configGuardar['fields'][] = ['name' => 'ca_meq', 'label' => 'Ca meq'];
        $configGuardar['fields'][] = ['name' => 'mg_meq', 'label' => 'Mg meq'];
        $configGuardar['fields'][] = ['name' => 'ras', 'label' => 'RAS'];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $naUg = lab_post_float('na_ug', $fila);
            $caUg = lab_post_float('ca_ug', $fila);
            $mgUg = lab_post_float('mg_ug', $fila);

            try {
                // Pesos equivalentes usados en el formato físico de RAS.
                $naMeq = rasConvertirMeq($naUg, 22.99);
                $caMeq = rasConvertirMeq($caUg, 20.04);
                $mgMeq = rasConvertirMeq($mgUg, 12.16);
                $ras = rasCalcularRas($naMeq, $caMeq, $mgMeq);

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
                    throw new RuntimeException('No se pudo identificar el tipo de análisis para RAS.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el numero de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'na_ug' => $naUg,
                    'ca_ug' => $caUg,
                    'mg_ug' => $mgUg,
                    'na_meq' => $naMeq,
                    'ca_meq' => $caMeq,
                    'mg_meq' => $mgMeq,
                    'ras' => $ras,
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
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de RAS.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultados[] = [
                    'exito' => true,
                    'mensaje' => 'RAS guardado correctamente.',
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

        $resultado = lab_resultado_multiple($resultados, 'ras');
    }
}

require_once __DIR__ . '/../../view/analisis_generico_view.php';
