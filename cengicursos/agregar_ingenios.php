<?php require_once "menu.php"; cengi_require_admin(); ?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta name="viewport" content="width=device-widht, initial-scale=1">
	<link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="css/bootstrap-theme.css" rel="stylesheet">
	<link href="css/proyecto.css" rel="stylesheet">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas">
	<?php menu_render();?>
	<div class="container">
		<div class="cengi-hero">
			<span class="cengi-chip">Ingenios</span>
			<h2>Agregar ingenio</h2>
			<p>Registra un nuevo ingenio disponible para cursos y participantes.</p>
		</div>

		<div class="panel panel-success">
			<div class="panel-heading">
				<h3 class="panel-title"> Nuevo Registro de Ingenios</h3>
			</div>

			<div class="panel-body">
		<form  method="POST" action="guardar_ingenios.php" autocomplete="off">
			<div class="cengi-form-grid">
				<div class="form-group">
					<label for="nombre" class="control-label">Nombre</label>
					<input type="text" name= "nombre"  class="form-control" required placeholder="Nombre">
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
