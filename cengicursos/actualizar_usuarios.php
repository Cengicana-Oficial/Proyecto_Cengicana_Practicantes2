<?php
	require_once("revisar_permisos.php");
	cengi_require_admin();
	require_once("conexion.php");
	$mysqli=conectar();
	$contra="";
	$id="";
	if (!empty($_POST['id']))
	{
	
	$id=(int)$_POST['id'];
	$nombre = $_POST['nombre'];
	$email = $_POST['email'];
	$ingenio = $_POST['ingenio'];
	$rol = $_POST['rol'];
	if (!empty($_POST['password'])){
		$contra = ", password='".md5($_POST['password'])."'";
	}
	

	$sql ="UPDATE users SET nombre='$nombre', email='$email', ingenio_id='$ingenio', rol='$rol' $contra where id =$id";
		$resultado =mysqli_query($mysqli, $sql) or die ('error en la seleccion de base de datos.');
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
	<?php include('menu.php'); menu_render(); ?>
	<div class="container">
		<div class="row">
			<div class="row" style="text-align: center;">
				<?php if($resultado) { ?>
				<h3>Registro Modificado</h3>
				<?php } else { ?>
				<h3>Error al Modificar - <?php echo $error; ?></h3>
				<?php } ?>
				<a href="index.php" class="btn btn-success">Regresar</a>
			</div>
		</div>
	</div>
</body>
</html>
