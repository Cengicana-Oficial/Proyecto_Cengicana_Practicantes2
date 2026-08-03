<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");
require_once("menu.php");

$db = conectar();

$error = '';

try {

    $categorias = (int)$_POST['categorias_cursos'];
    $ingenio = (int)$_POST['ingenio'];

    $tipo = $_POST['tipo'];

    $curso = $_POST['nombre_cursos'];
    $jornada = $_POST['jornada_cursos'];

    $dias = $_POST['dias'];
    $horario = $_POST['horario'];

    $inicio = $_POST['inicio'];
    $fin = $_POST['fin'];

    $stmt = $db->prepare("
        INSERT INTO cursos
        (
            categoria_curso_id,
            ingenio_id,
            tipo,
            nombre_cursos,
            jornada_cursos,
            dias,
            horario,
            inicio,
            fin,
            creado
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");

    $resultado = $stmt->execute([
        $categorias,
        $ingenio,
        $tipo,
        $curso,
        $jornada,
        $dias,
        $horario,
        $inicio,
        $fin
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

        <a href="ver_cursos.php" class="btn btn-primary">
            Regresar
        </a>

    </div>

</div>

</body>

</html>