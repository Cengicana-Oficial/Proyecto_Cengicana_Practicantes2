<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.boro');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = lab_generic_analysis_config('aguas-boro');
if (!$config) {
    lab_forbidden('El formulario de boro de aguas no esta configurado.');
}

$resultado = null;
$labSkipFooterBaseSave = true;
$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => $config['elemento'] . ' en ' . $config['tipo'],
];
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;

if (!function_exists('boroCalcularMgL')) {
    function boroCalcularMgL(float $absorbancia, float $absBlanco, float $intercepto, float $pendiente): float
    {
        if ($pendiente == 0.0) {
            throw new RuntimeException('La pendiente no puede ser cero para calcular el boro.');
        }

        // Replica la fórmula del formato físico: ((Absorbancia - Abs. blanco) - Intercepto) / Pendiente
        return (($absorbancia - $absBlanco) - $intercepto) / $pendiente;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'abs_blanco', 'absorbancia', 'pendiente', 'intercepto'];
    $resultados = [];
    $boroMgL = null;
    $fecha = trim((string) ($_POST['fecha'] ?? date('Y-m-d')));
    $analista = trim((string) ($_POST['analista'] ?? ''));

    if ($fecha === '' || $analista === '') {
        $resultado = [
            'exito' => false,
            'mensaje' => 'Complete fecha y analista para guardar el registro.',
        ];
    } else {
        // Inicializa el esquema de versionado fuera de la transacción para evitar commits implícitos en MySQL.
        labFormularioEnsureSchema();

        $configGuardar = $config;
        $configGuardar['fields'][] = [
            'name' => 'boro',
            'label' => 'Boro',
        ];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $absBlanco = lab_post_float('abs_blanco', $fila);
            $absorbancia = lab_post_float('absorbancia', $fila);
            $pendiente = lab_post_float('pendiente', $fila);
            $intercepto = lab_post_float('intercepto', $fila);

            try {
                $boroCalculado = boroCalcularMgL($absorbancia, $absBlanco, $intercepto, $pendiente);

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
                    throw new RuntimeException('No se pudo identificar el tipo de análisis para boro.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el numero de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'abs_blanco' => $absBlanco,
                    'absorbancia' => $absorbancia,
                    'pendiente' => $pendiente,
                    'intercepto' => $intercepto,
                    'boro' => $boroCalculado,
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
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de boro.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $boroMgL = $boroCalculado;

                $resultados[] = [
                    'exito' => true,
                    'mensaje' => 'Boro guardado correctamente.',
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

        $resultado = lab_resultado_multiple($resultados, 'boro');
    }
}

require_once __DIR__ . '/../../view/analisis_generico_view.php';
