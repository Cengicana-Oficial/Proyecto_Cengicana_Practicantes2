<?php

require_once("revisar_permisos.php");
cengi_require_admin();

require_once("conexion.php");

$db = conectar();

$error = "";
$curso = "No se encontró el curso";

if (!empty($_GET['id'])) {

    $id = (int)$_GET['id'];

    try {

        $stmt = $db->prepare("
            SELECT nombre_cursos
            FROM cursos
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {

            $resultado = false;
            $error = "No se encontró el curso";

        } else {

            $curso = $fila['nombre_cursos'];

            // El curso se elimina fisicamente junto con todas sus filas dependientes,
            // dentro de una transaccion. Solo hay que borrar a mano las relaciones cuya
            // llave foranea es RESTRICT (declarada sin ON DELETE); el resto se resuelve
            // solo por ON DELETE CASCADE al borrar la fila padre:
            //
            //   * asignaciones.cursos_id           -> cursos.id       (RESTRICT)  <- bloqueo real
            //   * solicitudes_inscripcion.curso_id -> cursos.id       (RESTRICT)
            //   * diplomas.asignacion_id           -> asignaciones.id (RESTRICT)
            //
            // Al borrar "asignaciones" se disparan los ON DELETE CASCADE de
            // control_cursos, asistencia y control_curso_modulos. Al borrar "cursos" se
            // disparan los de curso_modulos (y sus hijos), curso_contenidos,
            // enlaces_evaluacion_instructor y cargas_evaluaciones_instructor (estas dos
            // ultimas cascadean a su vez evaluaciones_instructor).
            //
            // Nota: la "desasignacion" de participantes (remover_participante_curso.php,
            // toggle_asignacion.php, eliminar_participante.php) solo pone
            // estado_asignaciones = 0; la fila de asignaciones sigue existiendo y por eso,
            // antes de este cambio, un curso que alguna vez tuvo participantes nunca
            // llegaba a ser eliminable.
            $db->beginTransaction();

            $stmtDiplomas = $db->prepare("
                DELETE FROM diplomas
                WHERE asignacion_id IN (
                    SELECT id FROM asignaciones WHERE cursos_id = ?
                )
            ");
            $stmtDiplomas->execute([$id]);

            $stmtAsignaciones = $db->prepare("
                DELETE FROM asignaciones
                WHERE cursos_id = ?
            ");
            $stmtAsignaciones->execute([$id]);

            $stmtSolicitudes = $db->prepare("
                DELETE FROM solicitudes_inscripcion
                WHERE curso_id = ?
            ");
            $stmtSolicitudes->execute([$id]);

            $stmtDelete = $db->prepare("
                DELETE FROM cursos
                WHERE id = ?
            ");

            $resultado = $stmtDelete->execute([$id]);

            $db->commit();
        }

    } catch (PDOException $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $resultado = false;
        error_log('No fue posible eliminar el curso: ' . $e->getMessage());

        if (
            stripos($e->getMessage(), 'foreign key') !== false ||
            stripos($e->getMessage(), 'violates foreign key') !== false
        ) {
            $error = "El curso tiene registros asociados que impiden eliminarlo";
        } else {
            $error = "No fue posible eliminar el curso";
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
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
    <link rel="stylesheet" type="text/css" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <meta charset="utf-8">
</head>

<body class="cengi-canvas">

<?php require_once('menu.php'); menu_render(); ?>

<div class="container">

    <div class="cengi-result-card <?php echo $resultado ? 'is-success' : 'is-error'; ?>">

        <?php if ($resultado) { ?>

            <h3>
                Registro
                <strong><?php echo strtoupper($curso); ?></strong>
                eliminado
            </h3>

        <?php } else { ?>

            <h3>Error al eliminar</h3>
            <p><?php echo htmlspecialchars(strtoupper($error)); ?></p>

        <?php } ?>

        <a href="ver_cursos.php" class="btn btn-success">
            Regresar
        </a>

    </div>

</div>

</body>
</html>