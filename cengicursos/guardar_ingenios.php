<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");
require_once("menu.php");

$db = conectar();

$error = '';

try {

    $nombre = trim($_POST['nombre']);

    $stmt = $db->prepare("
        INSERT INTO ingenios
        (
            nombre_ingenios,
            creado
        )
        VALUES
        (
            ?, NOW()
        )
    ");

    $resultado = $stmt->execute([
        $nombre
    ]);

} catch (PDOException $e) {

    $resultado = false;
    $error = $e->getMessage();

}

?>

<html lang="es">

<head>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-theme.css" rel="stylesheet">
    <link href="css/proyecto.css" rel="stylesheet">

    <script src="js/jquery-3.1.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>

</head>

<body class="cengi-canvas">

<?php menu_render(); ?>

<div class="container">

    <div class="cengi-result-card <?php echo $resultado ? 'is-success' : 'is-error'; ?>">

        <?php if ($resultado) { ?>

            <h3>Registro guardado</h3>

        <?php } else { ?>

            <h3>Error al guardar</h3>
            <p><?php echo htmlspecialchars($error); ?></p>

        <?php } ?>

        <a href="ver_ingenios.php" class="btn btn-primary">
            Regresar
        </a>

    </div>

</div>

</body>

</html>