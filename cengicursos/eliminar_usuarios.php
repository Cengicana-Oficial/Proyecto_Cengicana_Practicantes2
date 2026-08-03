<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");

$mysqli = conectar_usuarios_menu();

if (!empty($_GET['idusuarios'])) {

    $id = (int)$_GET['idusuarios'];

    $sql = "DELETE FROM users WHERE id = $id";

    $resultado = mysqli_query($mysqli, $sql);

    if (!$resultado) {
        $error = mysqli_error($mysqli);
    }

} else {

    $resultado = false;
    $error = "Debe indicar el id";

}

?>

<html lang="es">

<?php include('head.php'); ?>

<body class="cengi-canvas">

<?php include('menu.php'); menu_render(); ?>

<div class="container">

    <div class="row">

        <div class="row alert alert-info" style="text-align:center">

            <?php if ($resultado) { ?>

                <h3>REGISTRO ELIMINADO</h3>

            <?php } else { ?>

                <h3>
                    ERROR AL GUARDAR -
                    <?php echo $error; ?>
                </h3>

            <?php } ?>

            <a href="index.php" class="btn btn-success">
                Regresar
            </a>

        </div>

    </div>

</div>

</body>
</html>
