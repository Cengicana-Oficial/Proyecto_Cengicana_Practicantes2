<?php
require_once "revisar_permisos.php";
cengi_require_admin();
require_once "conexion.php";
$db = conectar();
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta name="viewport" content="width=device-widht, initial-scale=1">
	<link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="css/bootstrap-theme.css" rel="stylesheet">
	<link href="css/proyecto.css" rel="stylesheet">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
	<meta content="text/html;" http-equiv="content-type" charset="utf-8">
</head>
<body class="cengi-canvas">
	<?php include_once("menu.php"); menu_render(); ?>
	<div class="container" id="cargar_participantes">
		<div class="cengi-hero">
			<span class="cengi-chip">Participantes</span>
			<h2>Carga de participantes</h2>
			<p>Sube un archivo CSV con los participantes de un ingenio, delegado y curso.</p>
		</div>

		<div class="panel panel-success">
			<div class="panel-heading">
				<h3 class="panel-title">Carga de Participantes</h3>
			</div>
			<div class="panel-body">
				<form  method="POST" action="carga_participantes.php" enctype="multipart/form-data" autocomplete="off" accept-charset="UTF-8">
				<div class="cengi-form-grid">
				<div class="form-group">
					<label for="ingenio" class="control-label">Ingenio</label>
					<?php
						$stmtIngenios = $db->query("SELECT id, nombre_ingenios FROM cengi_cursos.ingenios ORDER BY nombre_ingenios");
					?>
					<select class="form-control" id="ingenio" name="ingenio" required>
					<?php while ($ingenio = $stmtIngenios->fetch(PDO::FETCH_ASSOC)) {?>
						<option value="<?php echo (int) $ingenio['id']; ?>"><?php echo htmlspecialchars($ingenio['nombre_ingenios']); ?></option>
					<?php } ?>
					</select>
				</div>

			<div class="form-group">
					<label for="user" class="control-label">Delegado</label>
					<?php
						$stmtDelegados = $db->query("SELECT id, nombre FROM cengi_cursos.users ORDER BY nombre");
					?>
					<select class="form-control" id="user" name="user" required>
					<?php while ($delegado = $stmtDelegados->fetch(PDO::FETCH_ASSOC)) {?>
						<option value="<?php echo (int) $delegado['id']; ?>"><?php echo htmlspecialchars($delegado['nombre']); ?></option>
					<?php } ?>
					</select>
				</div>

				<div class="form-group">
					<label for="curso" class="control-label">Curso</label>
					<?php
						$stmtCursos = $db->query("SELECT id, nombre_cursos FROM cengi_cursos.cursos ORDER BY nombre_cursos");
					?>
					<select class="form-control" id="curso" name="curso" required>
					<?php while ($curso = $stmtCursos->fetch(PDO::FETCH_ASSOC)) {?>
							<option value="<?php echo (int) $curso['id']; ?>"><?php echo htmlspecialchars($curso['nombre_cursos']); ?></option>
					<?php } ?>
					</select>
				</div>
				<div class="form-group">
					<label for="archivo" class="control-label">Archivo</label>
					<input id="archivo" accept=".csv" class="form-control" name="archivo" type="file" />
				</div>
				</div>

				<div class="cengi-form-actions">
					<button type="submit" class="btn btn-success">Guardar</button>
					<a href="index.php" class="btn btn-danger">Cancelar</a>
				</div>
			</form>
		</div>
	</div>
</div>
</body>
</html>
