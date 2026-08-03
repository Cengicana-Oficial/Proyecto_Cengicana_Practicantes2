<?php
	require_once "revisar_permisos.php";
	cengi_require_admin();
	require_once "menu.php";
	include("conexion.php");
	$mysqli = conectar();
	if (!empty($_GET['id'])) {
		$id = (int) $_GET['id'];

	$sql="SELECT * FROM categorias_cursos WHERE id=$id";
	$resultado=mysqli_query($mysqli,$sql) or die ("Error en la selección de datos");

	$row=$resultado->fetch_array(MYSQLI_ASSOC);
	}
	else
	{
		$resultado=false;
		$error="Debe indicar el id";
	} 
?>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
	<?php menu_render(); ?>
	<div class="container">
		<div class="cengi-hero">
			<span class="cengi-chip">Categorias</span>
			<h2>Modificar categoria</h2>
			<p>Actualiza la descripcion de la categoria seleccionada.</p>
		</div>

		<div class="panel panel-success">
			<div class="panel-heading">
				<h3 class="panel-title">Modificar Registro</h3>
			</div>

			<div class="panel-body">
		<form  method="POST" action="actualizar_categorias.php" autocomplete="off">
			<div class="cengi-form-grid">
				<div class="form-group">
					<label for="nombre" class="control-label">Categoría</label>
					<input type="text" name= "nombre"  class="form-control" value="<?php echo $row['descripcion_categorias_cursos']; ?>">
				</div>
			</div>

			<input type="hidden" name="id" id="id" value="<?php echo $row['id']; ?>">
			<div class="cengi-form-actions">
				<a href="index.php" class="btn btn-default">Regresar</a>
				<button type="submit" class="btn btn-success">Guardar</button>
			</div>
		</form>
	</div>
	</div>
</div>
</body>
</html>
