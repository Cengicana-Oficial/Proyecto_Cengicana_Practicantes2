<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.micros');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../includes/analisis_generico_config.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = lab_generic_analysis_config('aguas-micros');
if (!$config) {
    lab_forbidden('El formulario de micro nutrientes de aguas no esta configurado.');
}

$resultado = null;
$labSkipFooterBaseSave = true;
$labAnalysisContexto = [
    'tipos' => $config['tipos'],
    'analisis' => $config['analisis'],
    'label' => $config['elemento'] . ' en ' . $config['tipo'],
];
$GLOBALS['labAnalysisContexto'] = $labAnalysisContexto;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'conc_cu', 'conc_zn', 'conc_fe', 'conc_mn', 'blk_cu', 'blk_zn', 'blk_fe', 'blk_mn'];
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
        $configGuardar['fields'][] = ['name' => 'cu_mgl', 'label' => 'Cu mg/L'];
        $configGuardar['fields'][] = ['name' => 'zn_mgl', 'label' => 'Zn mg/L'];
        $configGuardar['fields'][] = ['name' => 'fe_mgl', 'label' => 'Fe mg/L'];
        $configGuardar['fields'][] = ['name' => 'mn_mgl', 'label' => 'Mn mg/L'];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $concCu = lab_post_float('conc_cu', $fila);
            $concZn = lab_post_float('conc_zn', $fila);
            $concFe = lab_post_float('conc_fe', $fila);
            $concMn = lab_post_float('conc_mn', $fila);
            $blkCu = lab_post_float('blk_cu', $fila);
            $blkZn = lab_post_float('blk_zn', $fila);
            $blkFe = lab_post_float('blk_fe', $fila);
            $blkMn = lab_post_float('blk_mn', $fila);

            try {
                // En el formato físico, cada resultado es concentración menos su blanco correspondiente.
                $cuMgl = $concCu - $blkCu;
                $znMgl = $concZn - $blkZn;
                $feMgl = $concFe - $blkFe;
                $mnMgl = $concMn - $blkMn;

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
                    throw new RuntimeException('No se pudo identificar el tipo de análisis para micro nutrientes.');
                }

                if (empty($destino['numero_muestra'])) {
                    throw new RuntimeException('No se pudo identificar el numero de laboratorio "' . $numeroLaboratorio . '".');
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                $row = [
                    'lote' => $codigoLote,
                    'numero_laboratorio' => $numeroLaboratorio,
                    'conc_cu' => $concCu,
                    'conc_zn' => $concZn,
                    'conc_fe' => $concFe,
                    'conc_mn' => $concMn,
                    'blk_cu' => $blkCu,
                    'blk_zn' => $blkZn,
                    'blk_fe' => $blkFe,
                    'blk_mn' => $blkMn,
                    'cu_mgl' => $cuMgl,
                    'zn_mgl' => $znMgl,
                    'fe_mgl' => $feMgl,
                    'mn_mgl' => $mnMgl,
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
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de micro nutrientes.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultados[] = [
                    'exito' => true,
                    'mensaje' => 'Micro nutrientes guardados correctamente.',
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

        $resultado = lab_resultado_multiple($resultados, 'micros de agua');
    }
}

require_once __DIR__ . '/../../view/analisis_generico_view.php';
