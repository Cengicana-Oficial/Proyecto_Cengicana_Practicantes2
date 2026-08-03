<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");

$db = conectar();

if (!empty($_POST['id']))
{
    $id = (int)$_POST['id'];

    $descripcion = $_POST['categorias'];
    $ingenio = $_POST['ingenio'];

    $tipo = $_POST['tipo'];

    $curso = $_POST['nombre_cursos'];
    $jornada = $_POST['jornada_cursos'];
    $horario = $_POST['horario'];
    $dias = $_POST['dias'];

    try {

        $sql = "
            UPDATE cursos
            SET
                categoria_curso_id = ?,
                ingenio_id = ?,
                tipo = ?,
                nombre_cursos = ?,
                jornada_cursos = ?,
                dias = ?,
                horario = ?
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);

        $resultado = $stmt->execute([
            $descripcion,
            $ingenio,
            $tipo,
            $curso,
            $jornada,
            $dias,
            $horario,
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

			<a href="index.php" class="btn btn-success">
				Regresar
			</a>

		</div>

	</div>

</body>
</html>
