<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");

$db = conectar();

$error = "";
$ingenio = "No se encontró el ingenio";

if (!empty($_GET['id'])) {

    $id = (int)$_GET['id'];

    try {

        $stmt = $db->prepare("
            SELECT nombre_ingenios
            FROM ingenios
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila) {
            $ingenio = $fila['nombre_ingenios'];
        }

        $stmtDelete = $db->prepare("
            DELETE FROM ingenios
            WHERE id = ?
        ");

        $resultado = $stmtDelete->execute([$id]);

    } catch (PDOException $e) {

        $resultado = false;
        $error = $e->getMessage();

        if (
            stripos($error, 'foreign key') !== false ||
            stripos($error, 'violates foreign key') !== false
        ) {
            $error = "El ingenio tiene cursos asociados";
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
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <meta charset="utf-8">
</head>

<body>

<?php include('menu.php'); ?>

<div class="container">

    <div class="row">

        <div class="row alert alert-info" style="text-align:center">

            <?php if ($resultado) { ?>

                <h3>
                    REGISTRO
                    <strong><?php echo strtoupper($ingenio); ?></strong>
                    ELIMINADO
                </h3>

            <?php } else { ?>

                <h3>
                    ERROR AL ELIMINAR:
                    <?php echo strtoupper($error); ?>
                </h3>

            <?php } ?>

            <a href="ver_ingenios.php" class="btn btn-success">
                Regresar
            </a>

        </div>

    </div>

</div>

</body>
</html>