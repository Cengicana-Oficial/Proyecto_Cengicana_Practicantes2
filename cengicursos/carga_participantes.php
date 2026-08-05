<?php
ob_start();
session_start();

require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "menu.php";
require_once __DIR__ . "/classes/PHPExcel.php";
require_once __DIR__ . "/classes/PHPExcel/IOFactory.php";

cengi_require_carga_participantes("participantes.php");

$db = conectar();
$ingenioID = (int) ($_POST['ingenio'] ?? 0);
$userID = cengi_usuario_actual_id();
$cursoID = (int) ($_POST['curso'] ?? 0);

function cengi_carga_error($mensaje)
{
    throw new RuntimeException($mensaje);
}

function cengi_valor_excel($valor)
{
    if ($valor === null) {
        return '';
    }

    if (is_float($valor) || is_int($valor)) {
        return trim((string) $valor);
    }

    return trim((string) $valor);
}

function cengi_obtener_filas_archivo($archivoTemporal, $extension)
{
    $filas = [];

    if ($extension === 'csv') {
        $handle = fopen($archivoTemporal, 'r');
        if ($handle === false) {
            cengi_carga_error('No fue posible abrir el archivo CSV.');
        }

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $filas[] = $data;
        }

        fclose($handle);
        return $filas;
    }

    $libro = PHPExcel_IOFactory::load($archivoTemporal);
    $hoja = $libro->getActiveSheet();
    $maxFila = $hoja->getHighestRow();

    for ($fila = 1; $fila <= $maxFila; $fila++) {
        $filas[] = [
            cengi_valor_excel($hoja->getCellByColumnAndRow(0, $fila)->getCalculatedValue()),
            cengi_valor_excel($hoja->getCellByColumnAndRow(1, $fila)->getCalculatedValue()),
            cengi_valor_excel($hoja->getCellByColumnAndRow(2, $fila)->getCalculatedValue()),
            cengi_valor_excel($hoja->getCellByColumnAndRow(3, $fila)->getCalculatedValue()),
            cengi_valor_excel($hoja->getCellByColumnAndRow(4, $fila)->getCalculatedValue()),
            cengi_valor_excel($hoja->getCellByColumnAndRow(5, $fila)->getCalculatedValue()),
            cengi_valor_excel($hoja->getCellByColumnAndRow(6, $fila)->getCalculatedValue()),
        ];
    }

    return $filas;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Carga de participantes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
    <link rel="stylesheet" type="text/css" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas">
    <?php menu_render(); ?>
    <div class="container">
        <div class="cengi-hero">
            <span class="cengi-chip">Participantes</span>
            <h2>Resultado de la carga</h2>
            <p>Detalle de participantes creados, actualizados y asignados desde el archivo.</p>
        </div>

        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">Resultado de la carga</h3>
            </div>
            <div class="panel-body">
<?php
try {
    if (
        $ingenioID <= 0 ||
        $userID <= 0 ||
        $cursoID <= 0
    ) {
        cengi_carga_error('Debes seleccionar ingenio y curso antes de cargar el archivo.');
    }

    if (
        !isset($_FILES['archivo']) ||
        !is_uploaded_file($_FILES['archivo']['tmp_name'])
    ) {
        cengi_carga_error('No se recibio ningun archivo.');
    }

    $nombreArchivo = $_FILES['archivo']['name'] ?? '';
    $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    $permitidas = ['csv', 'xls', 'xlsx'];

    if (!in_array($extension, $permitidas, true)) {
        cengi_carga_error('Solo se permiten archivos CSV, XLS o XLSX.');
    }

    $filas = cengi_obtener_filas_archivo($_FILES['archivo']['tmp_name'], $extension);

    if (count($filas) <= 1) {
        cengi_carga_error('El archivo no contiene datos para importar.');
    }

    $stmtBuscarParticipante = $db->prepare("
        SELECT id
        FROM participantes
        WHERE cui_participantes = ?
        LIMIT 1
    ");

    $stmtInsertParticipante = $db->prepare("
        INSERT INTO participantes (
            ingenio_id,
            usuarios_id,
            cui_participantes,
            nombre_participantes,
            puesto_participantes,
            area_participantes,
            correo_participantes,
            grado_academico_participantes,
            telefono_participantes,
            estado_participantes,
            creado
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");

    $stmtActualizarParticipante = $db->prepare("
        UPDATE participantes
        SET
            ingenio_id = ?,
            usuarios_id = ?,
            nombre_participantes = ?,
            puesto_participantes = ?,
            area_participantes = ?,
            correo_participantes = COALESCE(NULLIF(?, ''), correo_participantes),
            grado_academico_participantes = COALESCE(NULLIF(?, ''), grado_academico_participantes),
            telefono_participantes = COALESCE(NULLIF(?, ''), telefono_participantes),
            actualizado = NOW()
        WHERE id = ?
    ");

    $stmtBuscarAsignacion = $db->prepare("
        SELECT id
        FROM asignaciones
        WHERE participantes_id = ?
          AND cursos_id = ?
        LIMIT 1
    ");

    $stmtInsertAsignacion = $db->prepare("
        INSERT INTO asignaciones (
            participantes_id,
            usuarios_id,
            cursos_id,
            estado_asignaciones,
            creado
        )
        VALUES (?, ?, ?, 1, NOW())
    ");

    $stmtActualizarAsignacion = $db->prepare("
        UPDATE asignaciones
        SET
            usuarios_id = ?,
            estado_asignaciones = 1,
            actualizado = NOW()
        WHERE id = ?
    ");

    $procesados = 0;
    $creados = 0;
    $actualizados = 0;
    $asignados = 0;
    $advertencias = [];

    $db->beginTransaction();

    foreach ($filas as $indice => $fila) {
        if ($indice === 0) {
            continue;
        }

        $cui = trim((string) ($fila[0] ?? ''));
        $nombre = trim((string) ($fila[1] ?? ''));
        $puesto = trim((string) ($fila[2] ?? ''));
        $area = trim((string) ($fila[3] ?? ''));
        $correo = trim((string) ($fila[4] ?? ''));
        $gradoAcademico = trim((string) ($fila[5] ?? ''));
        $telefono = trim((string) ($fila[6] ?? ''));
        $lineaReal = $indice + 1;

        if ($cui === '' && $nombre === '' && $puesto === '' && $area === '' && $correo === '' && $gradoAcademico === '' && $telefono === '') {
            continue;
        }

        if ($cui === '' || $nombre === '') {
            $advertencias[] = "Linea {$lineaReal}: se omitio porque faltan CUI o nombre.";
            continue;
        }

        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $advertencias[] = "Linea {$lineaReal}: se omitio porque el correo electronico no es valido.";
            continue;
        }

        $stmtBuscarParticipante->execute([$cui]);
        $participanteID = $stmtBuscarParticipante->fetchColumn();

        if ($participanteID) {
            $stmtActualizarParticipante->execute([
                $ingenioID,
                $userID,
                $nombre,
                $puesto,
                $area,
                $correo,
                $gradoAcademico,
                $telefono,
                $participanteID,
            ]);
            $actualizados++;
        } else {
            $stmtInsertParticipante->execute([
                $ingenioID,
                $userID,
                $cui,
                $nombre,
                $puesto,
                $area,
                $correo,
                $gradoAcademico,
                $telefono,
            ]);
            $participanteID = (int) $db->lastInsertId();
            $creados++;
        }

        $stmtBuscarAsignacion->execute([
            $participanteID,
            $cursoID,
        ]);
        $asignacionID = $stmtBuscarAsignacion->fetchColumn();

        if ($asignacionID) {
            $stmtActualizarAsignacion->execute([
                $userID,
                $asignacionID,
            ]);
        } else {
            $stmtInsertAsignacion->execute([
                $participanteID,
                $userID,
                $cursoID,
            ]);
            $asignados++;
        }

        $procesados++;
    }

    $db->commit();
    ob_end_clean();
    header('Location: participantes.php?curso_id=' . $cursoID . '&mensaje=carga');
    exit;
    ?>
                <div class="cengi-result-card is-success">
                    <h3>Carga completada</h3>
                    <p>
                        Se procesaron <?php echo $procesados; ?> filas, se crearon <?php echo $creados; ?> participantes, se actualizaron <?php echo $actualizados; ?> y se generaron <?php echo $asignados; ?> asignaciones nuevas.
                    </p>
    <?php if ($advertencias): ?>
                    <div class="alert alert-warning" style="text-align:left;">
                        <strong>Advertencias:</strong>
                        <ul class="mb-0">
                            <?php foreach ($advertencias as $advertencia) { ?>
                                <li><?php echo htmlspecialchars($advertencia); ?></li>
                            <?php } ?>
                        </ul>
                    </div>
    <?php endif; ?>
                    <a href="participantes.php" class="btn btn-success">Regresar</a>
                </div>
<?php
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Error en carga de participantes: ' . $e->getMessage());
    ob_end_clean();
    header('Location: participantes.php?curso_id=' . $cursoID . '&error=carga');
    exit;
    ?>
                <div class="cengi-result-card is-error">
                    <h3>No se pudo completar la carga</h3>
                    <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
                    <a href="participantes.php" class="btn btn-success">Regresar</a>
                </div>
<?php } ?>
            </div>
        </div>
    </div>
</body>
</html>
