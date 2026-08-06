<?php
require_once "revisar_permisos.php";
cengi_require_admin();
//require c'conexion.php
require_once "conexion.php";
$db = conectar();
require_once "menu.php";
?>

<!DOCTYPE html>
<html lang="es">

<head>
	<link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="css/bootstrap-theme.css" rel="stylesheet">
	<link href="css/bootstrap-datetimepicker.min.css" rel="stylesheet">
	<link href="css/proyecto.css" rel="stylesheet">

	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
	<script src="js/bootstrap-datetimepicker.min.js"></script>
</head>

<body class="cengi-canvas">

<?php menu_render(); ?>

<div class="container">

	<div class="cengi-hero">
		<span class="cengi-chip">Cursos</span>
		<h2>Agregar curso</h2>
		<p>Registra un nuevo curso, diplomado o seminario con su categoria, ingenio y calendario.</p>
	</div>

	<div class="panel panel-success">

		<div class="panel-heading">
			<h3 class="panel-title">Agregar Cursos</h3>
		</div>

		<div class="panel-body">

			<form method="POST" action="guardar_cursos.php" autocomplete="off">

				<div class="cengi-form-grid">

					<div class="form-group">
						<label for="categorias_cursos" class="control-label">
							Categoría
						</label>

						<?php
						$sqling = "SELECT id, descripcion_categorias_cursos
           FROM categorias_cursos";
		   $categorias = $db->query($sqling);

?>

						<select class="form-control"
								id="categorias_cursos"
								name="categorias_cursos"
								required>

<?php while ($categoria = $categorias->fetch(PDO::FETCH_ASSOC)) { ?>
								<option value="<?php echo $categoria['id']; ?>">
									<?php echo $categoria['descripcion_categorias_cursos']; ?>
								</option>

							<?php } ?>

						</select>
					</div>

					<div class="form-group">
						<label for="ingenio" class="control-label">
							Ingenio
						</label>

						<?php
						$sqling = "SELECT id, nombre_ingenios
           FROM ingenios";

$ingenios = $db->query($sqling);
						?>

						<select class="form-control"
								id="ingenio"
								name="ingenio"
								required>

<?php while ($ingenio = $ingenios->fetch(PDO::FETCH_ASSOC)) { ?>
								<option value="<?php echo $ingenio['id']; ?>">
									<?php echo $ingenio['nombre_ingenios']; ?>
								</option>

							<?php } ?>

						</select>
					</div>

					<div class="form-group">
						<label for="tipo" class="control-label">
							Tipo
						</label>
						<select class="form-control"
								id="tipo"
								name="tipo"
								required>

							<option value="Curso">Curso</option>
							<option value="Diplomado">Diplomado</option>
							<option value="Seminario">Seminario</option>

						</select>
					</div>

					<div class="form-group">
						<label for="nombre_cursos" class="control-label">
							Nombre
						</label>
						<input type="text"
							   name="nombre_cursos"
							   class="form-control"
							   required
							   placeholder="Nombre del Curso">
					</div>

					<div class="form-group">
						<label for="jornada_cursos" class="control-label">
							Jornada
						</label>

						<select class="form-control"
								id="jornada_cursos"
								name="jornada_cursos">

							<option value="Matutina">Matutina</option>
							<option value="Vespertina">Vespertina</option>
							<option value="Todo Completo">Todo Completo</option>

						</select>
					</div>

					<div class="form-group">
						<label for="dias" class="control-label">
							Días
						</label>
						<input type="text"
							   name="dias"
							   class="form-control"
							   required
							   placeholder="Días a ejecutarse">
					</div>

					<div class="form-group">
						<label for="horario" class="control-label">
							Horario
						</label>
						<input type="text"
							   name="horario"
							   class="form-control"
							   required
							   placeholder="Formato 24 horas">
					</div>

					<div class="form-group">
						<label for="inicio" class="control-label">
							Inicia
						</label>
						<input type="date"
							   name="inicio"
							   class="form-control"
							   required>
					</div>

					<div class="form-group">
						<label for="fin" class="control-label">
							Finaliza
						</label>
						<input type="date"
							   name="fin"
							   class="form-control"
							   required>
					</div>

				</div>

				<!-- BOTONES -->
				<div class="cengi-form-actions">

					<button type="submit" class="btn btn-success">
						Guardar
					</button>

					<a href="index.php" class="btn btn-danger">
						Cancelar
					</a>

				</div>

			</form>

		</div>

	</div>

</div>

</body>
</html>
