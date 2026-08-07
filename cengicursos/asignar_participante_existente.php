<?php
// Asigna a un participante YA EXISTENTE a un curso, sin volver a pedir/actualizar
// sus datos personales. Es la variante "rapida" de guardar_participante_curso.php
// para el flujo "Participante existente" del modal en ver_participante_curso.php:
// aqui solo se toca la tabla asignaciones (mismo patron de las sentencias
// buscar/insertar/actualizar asignacion que usa guardar_participante_curso.php).

require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_admin('ver_cursos.php');

$db = conectar();

$participanteID = (int) ($_POST['participante_id'] ?? 0);
$cursoID = (int) ($_POST['curso_id'] ?? 0);
$usuarioID = cengi_usuario_actual_id();

if ($participanteID <= 0 || $cursoID <= 0) {
    header("Location: ver_participante_curso.php?id=" . $cursoID . "&error=asignacion");
    exit;
}

// Verifica que el participante exista y, si el usuario no ve todo por rol,
// que pertenezca a su propio ingenio (mismo scoping que directorio_participantes.php).
$condicionesParticipante = ['id = ?'];
$paramsParticipante = [$participanteID];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condicionesParticipante[] = 'ingenio_id = ?';
    $paramsParticipante[] = cengi_ingenio_id_actual();
}

$stmtParticipante = $db->prepare("
    SELECT id
    FROM participantes
    WHERE " . implode(' AND ', $condicionesParticipante) . "
    LIMIT 1
");
$stmtParticipante->execute($paramsParticipante);

if (!$stmtParticipante->fetchColumn()) {
    header("Location: ver_participante_curso.php?id=" . $cursoID . "&error=asignacion");
    exit;
}

$stmtBuscarAsignacion = $db->prepare("
    SELECT id
    FROM asignaciones
    WHERE participantes_id = ?
      AND cursos_id = ?
    LIMIT 1
");

$stmtInsertAsignacion = $db->prepare("
    INSERT INTO asignaciones (
        participantes_id,
        usuarios_id,
        cursos_id,
        estado_asignaciones,
        creado
    )
    VALUES (?, ?, ?, 1, NOW())
");

$stmtActualizarAsignacion = $db->prepare("
    UPDATE asignaciones
    SET
        usuarios_id = ?,
        estado_asignaciones = 1,
        actualizado = NOW()
    WHERE id = ?
");

try {
    $db->beginTransaction();

    $stmtBuscarAsignacion->execute([$participanteID, $cursoID]);
    $asignacionID = $stmtBuscarAsignacion->fetchColumn();

    if (!$asignacionID) {
        $stmtInsertAsignacion->execute([$participanteID, $usuarioID, $cursoID]);
    } else {
        $stmtActualizarAsignacion->execute([$usuarioID, $asignacionID]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    header("Location: ver_participante_curso.php?id=" . $cursoID . "&error=asignacion");
    exit;
}

header("Location: ver_participante_curso.php?id=" . $cursoID);
exit;
