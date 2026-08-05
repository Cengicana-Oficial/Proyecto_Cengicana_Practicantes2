<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");

$db = conectar();

$error = "";
$categoria = "No se encontró la categoría";

if (!empty($_GET['id'])) {

    $id = (int)$_GET['id'];

    try {

        $stmt = $db->prepare("
            SELECT descripcion_categorias_cursos
            FROM categorias_cursos
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila) {
            $categoria = $fila['descripcion_categorias_cursos'];
        }

        $stmtDelete = $db->prepare("
            DELETE FROM categorias_cursos
            WHERE id = ?
        ");

        $resultado = $stmtDelete->execute([$id]);

    } catch (PDOException $e) {

        $resultado = false;
        error_log('No fue posible eliminar la categoria: ' . $e->getMessage());

        if (
            stripos($e->getMessage(), 'foreign key') !== false ||
            stripos($e->getMessage(), 'violates foreign key') !== false
        ) {
            $error = "la categoria tiene cursos asociados";
        } else {
            $error = "No fue posible eliminar la categoria";
        }
    }

}
else
{
    $resultado = false;
    $error = "Debe indicar el id";
}

?>
 
<html lang="es">
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
	<link rel="stylesheet" type="text/css" href="css/proyecto.css">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
	<meta charset="utf-8">
</head>
	<body class="cengi-canvas">
		<?php menu_render(); ?>
		<div class="container">
			<div class="cengi-result-card <?php echo $resultado ? 'is-success' : 'is-error'; ?>">
				<?php if ($resultado) { ?>
					<h3>Registro <strong><?php echo strtoupper($categoria); ?></strong> eliminado</h3>
				<?php } else { ?>
					<h3>Error al eliminar</h3>
					<p><?php echo htmlspecialchars(strtoupper($error)); ?></p>
				<?php } ?>

				<a href="ver_cursos.php" class="btn btn-success">Regresar</a>
			</div>
		</div>
	</body>
</html>