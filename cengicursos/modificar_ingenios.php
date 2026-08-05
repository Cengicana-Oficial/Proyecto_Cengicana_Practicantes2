<?php

require_once("revisar_permisos.php");
cengi_require_gestionar_ingenios();
require_once("menu.php");
cengi_require_admin();

require_once("conexion.php");

$db = conectar();

if (!empty($_GET['id'])) {

    $id = (int)$_GET['id'];

    $stmt = $db->prepare("
        SELECT *
        FROM ingenios
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die("Ingenio no encontrado");
    }

} else {

    $resultado = false;
    $error = "Debe indicar el id";

}

?>

<html lang="es">

<?php include('head.php'); ?>

<body class="cengi-canvas">

<?php menu_render(); ?>

<div class="container">

    <div class="cengi-hero">
        <span class="cengi-chip">Ingenios</span>
        <h2>Modificar ingenio</h2>
        <p>Actualiza el nombre del ingenio seleccionado.</p>
    </div>

    <div class="panel panel-success">

        <div class="panel-heading">
            <h3 class="panel-title">Modificar Registro</h3>
        </div>

        <div class="panel-body">

    <form method="POST"
          action="actualizar_ingenios.php"
          autocomplete="off">

        <div class="cengi-form-grid">
            <div class="form-group">
                <label for="nombre" class="control-label">
                    Ingenio
                </label>

                <input
                    type="text"
                    name="nombre"
                    class="form-control"
                    value="<?php echo htmlspecialchars($row['nombre_ingenios']); ?>"
                    required
                >
            </div>
        </div>

        <input
            type="hidden"
            name="id"
            id="id"
            value="<?php echo $row['id']; ?>"
        >

        <div class="cengi-form-actions">
            <a href="ver_ingenios.php" class="btn btn-default">
                Regresar
            </a>

            <button type="submit" class="btn btn-success">
                Guardar
            </button>
        </div>

    </form>

        </div>

    </div>

</div>

</body>
</html>
