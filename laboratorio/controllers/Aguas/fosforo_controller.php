<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.fosforo');

require_once __DIR__ . '/../../includes/analisis_post_helper.php';
require_once __DIR__ . '/../../includes/formulario_revision_helper.php';
require_once __DIR__ . '/../../models/conexion.php';
require_once __DIR__ . '/../../models/analisis_generico_model.php';

$config = [
    'key' => 'aguas.fosforo',
    'tipo' => 'Aguas',
    'elemento' => 'Fosforo',
    'table' => 'agua_fosforo',
    'tipos' => ['agua', 'aguas'],
    'analisis' => ['Fosforo', 'Fósforo'],
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

if (!function_exists('fosforoGuardarCurvaAgua')) {
    function fosforoGuardarCurvaAgua(PDO $pdo, array $destino, int $idFormulario, int $idFosforo, array $puntosCurva, array $absCurva): void
    {
        foreach ($puntosCurva as $i => $punto) {
            $puntoValor = is_numeric($punto) ? (float) $punto : null;
            $absValor = isset($absCurva[$i]) && is_numeric($absCurva[$i]) ? (float) $absCurva[$i] : null;

            if ($puntoValor === null || $absValor === null) {
                continue;
            }

            $stmtCurva = $pdo->prepare("
                INSERT INTO curva_fosforo_ag (id_formulario, punto_curva, absorbancia)
                VALUES (?, ?, ?)
            ");
            $stmtCurva->execute([$idFormulario, $puntoValor, $absValor]);
            $idCurva = (int) $pdo->lastInsertId();

            $stmtRelacion = $pdo->prepare("
                INSERT INTO agua_fosforo_curva
                    (id_solicitud, numero_laboratorio, id_lote, id_formulario, id_fosforo, id_curva_fosforo)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtRelacion->execute([
                $destino['id_solicitud'] !== null ? (int) $destino['id_solicitud'] : null,
                $destino['numero_muestra'] !== null ? (int) $destino['numero_muestra'] : null,
                $destino['id_lote'] !== null ? (int) $destino['id_lote'] : null,
                $idFormulario,
                $idFosforo,
                $idCurva,
            ]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = ['lote', 'numero_laboratorio', 'abs_blanco', 'absorbancia'];
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
        $configGuardar['fields'] = [
            ['name' => 'abs_blanco', 'label' => 'Absorbancia blanco'],
            ['name' => 'absorbancia', 'label' => 'Absorbancia muestra'],
            ['name' => 'ppm_sol', 'label' => 'PPM solucion'],
            ['name' => 'ppm_p', 'label' => 'PPM fosforo'],
        ];

        for ($fila = 0, $total = lab_post_row_count($campos); $fila < $total; $fila++) {
            if (!lab_post_row_has_data($campos, $fila)) {
                continue;
            }

            $codigoLote = lab_post_string('lote', $fila);
            $numeroLaboratorio = lab_post_string('numero_laboratorio', $fila);
            $absBlanco = lab_post_float('abs_blanco', $fila);
            $absorbancia = lab_post_float('absorbancia', $fila);

            try {
                $ppmSol = ($absorbancia - $absBlanco) / 0.0312;
                if ($ppmSol < 0) {
                    $ppmSol = 0;
                }

                $ppmP = $ppmSol * 5;

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
                    throw new RuntimeException('No se pudo identificar el tipo de analisis para fosforo.');
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
                    'ppm_sol' => $ppmSol,
                    'ppm_p' => $ppmP,
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

                $idFosforo = (int) $pdo->lastInsertId();
                if ($idFosforo > 0 && isset($_POST['punto_curva'], $_POST['abs_curva']) && is_array($_POST['punto_curva']) && is_array($_POST['abs_curva'])) {
                    fosforoGuardarCurvaAgua($pdo, $destino, $idFormulario, $idFosforo, $_POST['punto_curva'], $_POST['abs_curva']);
                }

                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis de fosforo.');

                if ($useTransaction) {
                    $pdo->commit();
                }

                $resultados[] = [
                    'exito' => true,
                    'mensaje' => 'Fosforo guardado correctamente.',
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

        $resultado = lab_resultado_multiple($resultados, 'fosforo de agua');
    }
}

lab_analysis_redirect_after_success($resultado);
require_once __DIR__ . '/../../view/Aguas/fosforo_view.php';
