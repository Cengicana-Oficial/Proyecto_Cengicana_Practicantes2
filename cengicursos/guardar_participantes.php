<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");
require_once("validador_archivos.php");
require_once("menu.php");

$db = conectar();

$error = 0;
$resultado = false;

if (empty($_FILES['archivo']['type'])) {

    $error = 1;

} else {

    if (!esCsv($_FILES['archivo']['type'])) {
        $error = 2;
    }
}

if ($error === 0) {

    $ingenio = (int)$_POST['ingenio'];

    $archivotmp = $_FILES['archivo']['tmp_name'];

    $lineas = file($archivotmp);

    $i = 0;

    try {

        foreach ($lineas as $linea) {

            if ($i++ === 0) {
                continue;
            }

            $datos = str_getcsv($linea);

            if (count($datos) < 4) {
                continue;
            }

            $cui = trim($datos[0]);
            $nombre = trim($datos[1]);
            $puesto = trim($datos[2]);
            $area = trim($datos[3]);
            $formatoNuevo = count($datos) >= 7;
            $correo = $formatoNuevo ? trim($datos[4]) : '';
            $gradoAcademico = $formatoNuevo ? trim($datos[5]) : '';
            $telefono = $formatoNuevo ? trim($datos[6]) : '';
            $estado = count($datos) >= 8 ? trim($datos[7]) : (!$formatoNuevo && isset($datos[4]) ? trim($datos[4]) : 1);

            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $stmt = $db->prepare("
                INSERT INTO participantes
                (
                    ingenio_id,
                    cui_participantes,
                    nombre_participantes,
                    puesto_participantes,
                    area_participantes,
                    correo_participantes,
                    grado_academico_participantes,
                    telefono_participantes,
                    estado_participantes
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $ingenio,
                $cui,
                $nombre,
                $puesto,
                $area,
                $correo,
                $gradoAcademico,
                $telefono,
                $estado
            ]);
        }

        $resultado = true;

    } catch (PDOException $e) {

        $resultado = false;
        $errorTexto = $e->getMessage();

    }
}

?>
 	

<html lang="es">
	<head>

		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link href="css/bootstrap.min.css" rel="stylesheet">
		<link href="css/bootstrap-theme.css" rel="stylesheet">
		<link href="css/proyecto.css" rel="stylesheet">
		<script src="js/jquery-3.1.1.min.js"></script>
		<script src="js/bootstrap.min.js"></script>
	</head>

	<body class="cengi-canvas">
		<?php menu_render(); ?>
		<div class="container">
			<div class="cengi-result-card <?php echo $resultado ? 'is-success' : 'is-error'; ?>">
				<?php if ($resultado) { ?>
					<h3>Registro guardado</h3>
				<?php } else { ?>
					<h3>Error al guardar</h3>
					<p><?php echo htmlspecialchars($errorTexto ?? mensajeError($error)); ?></p>
				<?php } ?>

				<a href="index.php" class="btn btn-success">Regresar</a>
			</div>
		</div>
	</body>
</html>
