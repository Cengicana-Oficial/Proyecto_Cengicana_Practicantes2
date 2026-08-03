<?php
	require_once "revisar_permisos.php";
	cengi_require_admin();
	include("conexion.php");
	$mysqli = conectar();
	if (!empty($_GET['id'])) {
		$id= $_GET['id'];

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
<body>
	<?php include('menu.php'); ?>
	<div class="container">
		<div class="row">
				<h3 style="text-align: center">Modificar Registro</h3>
			</div>
		<form  method="POST" action="actualizar_categorias.php" autocomplete="off">	
			<div class="form-group">
					<label for="nombre" class="col-sm-2 control-label">Categoría:</label>
					<div class="col-sm-10">
						<input type="text" name= "nombre"  class="form-control" value="<?php echo $row['descripcion_categorias_cursos']; ?>"> 
					</div>
			</div>

			<input type="hidden" name="id" id="id" value="<?php echo $row['id']; ?>">
			<div class="form-group">
				<div class="col-sm-offset-2 col-sm-10">
					<a href="index.php" class="btn btn-default">Regresar</a>
					<button type="submit" class="btn btn-success">Guardar</button>
				</div>
			</div>
		</form>
	</div>
</body>
</html>