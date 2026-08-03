<?php
	require_once "revisar_permisos.php";
	cengi_require_admin();
	include("conexion.php");
	$mysqli = conectar();
	if (!empty($_GET['id'])) {
		$id= $_GET['id'];

	$sql ="SELECT
	  u.id idusuario,
	  u.nombre,
	  u.email,  
	  u.ingenio_id,
	  i.nombre_ingenios,
	  u.rol
	FROM cengi_cursos.users u
	INNER JOIN cengi_cursos.ingenios i ON (u.id=$id)";

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
		<form  method="POST" action="actualizar_usuarios.php" autocomplete="off">	
			<div class="form-group">
					<label for="nombre" class="col-sm-2 control-label">Nombre:</label>
					<div class="col-sm-10">
						<input type="text" name= "nombre"  class="form-control" value="<?php echo $row['nombre']; ?>"> 
					</div>
			</div>

			<input type="hidden" name="id" id="id" value="<?php echo $row['idusuario']; ?>">
			<div class="form-group">
				<label for="email" class="col-sm-2 control-label">Email</label>
				<div class="col-sm-10">
					<input type="apellido" class="form-control" id="email" name="email" value="<?php echo $row['email']; ?>" placeholder="Email" required>
				</div>
			</div>

			<div class="form-group">
				<label for="ingenio" class="col-sm-2 control-label">Ingenio</label>
				<div class="col-sm-10">
				<?php
				//Consulta obtener todos los ingenios
				$sqling ="SELECT id, nombre_ingenios FROM cengi_cursos.ingenios";
						$ingenios =mysqli_query($mysqli, $sqling)or die ('error en la seleccion de base de datos.');
				?>
					<select class="form-control" id="ingenio" name="ingenio">
					<?php while ($ingenio = $ingenios->fetch_array(MYSQLI_ASSOC)) {?>
						<option value="<?php echo $ingenio['id']; ?>" <?php if($ingenio['id']==$row['ingenio_id']) echo 'selected'; ?>><?php echo $ingenio['nombre_ingenios']; ?></option>
					<?php } ?>
					</select>
				</div>
			</div>

			<div class="form-group">
					<label for="rol" class="col-sm-2 control-label">Rol</label>
					<div class="col-sm-10">
						<select class="form-control" id="rol" name="rol">
							<option value="Administrador" <?php if($row['rol']=='Administrador') echo 'selected'; ?>>Administrador</option>
							<option value="Delegado" <?php if($row['rol']=='Delegado') echo 'selected'; ?>>Delegado</option>
						</select>
					</div>
			</div>

			<div class="form-group">
				<label for="password" class="col-sm-2 control-label">Password</label>
				<div class="col-sm-10">
					<input type="password" class="form-control" id="password" name="password" value="" placeholder="Password">
				</div>
			</div>

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