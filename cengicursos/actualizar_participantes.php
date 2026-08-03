<?php

require_once("revisar_permisos.php");
cengi_require_editar_participantes();

require_once("conexion.php");

$db = conectar();

if (!empty($_POST['id']))
{
    $id = (int)$_POST['id'];

    $ingenio = $_POST['ingenio'];
    $cui = trim($_POST['cui_participantes']);
    $nombre = trim($_POST['nombre_participantes']);
    $puesto = trim($_POST['puesto_participantes']);
    $area = trim($_POST['area_participantes']);
    $estado = trim($_POST['estado_participantes']);

    try {

        $sql = "
            UPDATE participantes
            SET
                ingenio_id = ?,
                cui_participantes = ?,
                nombre_participantes = ?,
                puesto_participantes = ?,
                area_participantes = ?,
                estado_participantes = ?
            WHERE id = ?
        ";

        if (!cengi_ve_todo_por_rol_o_ingenio()) {
            $sql .= " AND ingenio_id = " . (int) cengi_ingenio_id_actual();
        }

        $stmt = $db->prepare($sql);

        $resultado = $stmt->execute([
            $ingenio,
            $cui,
            $nombre,
            $puesto,
            $area,
            $estado,
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
			<h3>Registro actualizado</h3>
			<?php } else { ?>
			<h3>Error al modificar</h3>
			<p><?php echo htmlspecialchars($error); ?></p>
			<?php } ?>
			<a href="index.php" class="btn btn-success">Regresar</a>
		</div>
	</div>
</body>
</html>
