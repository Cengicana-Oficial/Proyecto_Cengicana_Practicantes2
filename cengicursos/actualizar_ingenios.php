<?php

require_once("revisar_permisos.php");
cengi_require_gestionar_ingenios();

require_once("conexion.php");

$db = conectar();

if (!empty($_POST['id']))
{
    $id = (int)$_POST['id'];
    $nombre = trim($_POST['nombre']);

    try {

        $sql = "
            UPDATE ingenios
            SET
                nombre_ingenios = ?,
                actualizado = NOW()
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);

        $resultado = $stmt->execute([
            $nombre,
            $id
        ]);

    } catch (PDOException $e) {

        $resultado = false;
        $error = $e->getMessage();

    }

}
else
{
    $resultado = false;
    $error = "Debe indicar el id";
}

?>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
	<?php include('menu.php'); menu_render(); ?>
	<div class="container">
		<div class="cengi-result-card <?php echo $resultado ? 'is-success' : 'is-error'; ?>">
			<?php if ($resultado) { ?>
			<h3>Registro modificado</h3>
			<?php } else { ?>
			<h3>Error al modificar</h3>
			<p><?php echo htmlspecialchars($error); ?></p>
			<?php } ?>
			<a href="ver_ingenios.php" class="btn btn-success">Regresar</a>
		</div>
	</div>
</body>
</html>
