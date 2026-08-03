<?php

require_once("revisar_permisos.php");
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

<body>

<?php include('menu.php'); ?>

<div class="container">

    <div class="row">

        <h3 style="text-align: center">
            Modificar Registro
        </h3>

    </div>

    <form method="POST"
          action="actualizar_ingenios.php"
          autocomplete="off">

        <div class="form-group">

            <label for="nombre"
                   class="col-sm-2 control-label">

                Ingenio:

            </label>

            <div class="col-sm-10">

                <input
                    type="text"
                    name="nombre"
                    class="form-control"
                    value="<?php echo htmlspecialchars($row['nombre_ingenios']); ?>"
                >

            </div>

        </div>

        <input
            type="hidden"
            name="id"
            id="id"
            value="<?php echo $row['id']; ?>"
        >

        <div class="form-group">

            <div class="col-sm-offset-2 col-sm-10">

                <a href="index.php"
                   class="btn btn-default">

                    Regresar

                </a>

                <button type="submit"
                        class="btn btn-success">

                    Guardar

                </button>

            </div>

        </div>

    </form>

</div>

</body>
</html>