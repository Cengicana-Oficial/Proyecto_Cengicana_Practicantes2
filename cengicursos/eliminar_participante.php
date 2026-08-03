<?php

require_once("conexion.php");
require_once("revisar_permisos.php");

cengi_require_eliminar_participantes("participantes.php");

$db = conectar();

$error = "";
$participante = "No se encontró el participante";
$resultado = false;

if (!empty($_GET['id'])) {

    $id = (int)$_GET['id'];

    try {

        // ==========================
        // OBTENER PARTICIPANTE
        // ==========================

        $sqlNombre = "
            SELECT nombre_participantes
            FROM participantes
            WHERE id = ?
        ";

        if (!cengi_ve_todo_por_rol_o_ingenio()) {
            $sqlNombre .= " AND ingenio_id = " . (int) cengi_ingenio_id_actual();
        }

        $stmtNombre = $db->prepare($sqlNombre);

        $stmtNombre->execute([$id]);

        $fila = $stmtNombre->fetch(PDO::FETCH_ASSOC);

        if ($fila) {

            $participante = $fila['nombre_participantes'];

            $db->beginTransaction();

            $sqlAsignaciones = "
                UPDATE asignaciones
                SET
                    estado_asignaciones = 0,
                    actualizado = NOW()
                WHERE participantes_id = ?
            ";
            $stmtAsignaciones = $db->prepare($sqlAsignaciones);
            $stmtAsignaciones->execute([$id]);

            $sqlDelete = "
                UPDATE participantes
                SET
                    estado_participantes = 0,
                    actualizado = NOW()
                WHERE id = ?
            ";

            if (!cengi_ve_todo_por_rol_o_ingenio()) {
                $sqlDelete .= " AND ingenio_id = " . (int) cengi_ingenio_id_actual();
            }

            $stmtDelete = $db->prepare($sqlDelete);

            $resultado = $stmtDelete->execute([$id]);

            $db->commit();

        } else {

            $error = "NO SE ENCONTRO EL PARTICIPANTE";

        }

    } catch (PDOException $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $resultado = false;
        $error = $e->getMessage();

    }

} else {

    $error = "DEBE INDICAR EL ID";

}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Eliminar Participante</title>

    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
    <link rel="stylesheet" type="text/css" href="css/proyecto.css">

    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>

</head>

<body class="cengi-canvas">

<?php require_once('menu.php'); menu_render(); ?>

<div class="container">

    <div class="cengi-result-card <?php echo $resultado ? 'is-success' : 'is-error'; ?>">

        <?php if ($resultado) { ?>

            <h3>
                Participante
                <strong><?php echo strtoupper($participante); ?></strong>
                desactivado correctamente
            </h3>

        <?php } else { ?>

            <h3>Error al eliminar</h3>
            <p><?php echo strtoupper($error); ?></p>

        <?php } ?>

        <a href="participantes.php" class="btn btn-success">
            Regresar
        </a>

    </div>

</div>

</body>

</html>
