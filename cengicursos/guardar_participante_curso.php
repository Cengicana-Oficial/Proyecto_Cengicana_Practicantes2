<?php
require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_admin("ver_cursos.php");

$db = conectar();

$cui = trim((string) ($_POST['cui'] ?? ''));
$nombre = trim((string) ($_POST['nombre'] ?? ''));
$ingenioID = (int) ($_POST['ingenio'] ?? 0);
$cursoID = (int) ($_POST['curso'] ?? 0);
$area = trim((string) ($_POST['area'] ?? ''));
$puesto = trim((string) ($_POST['puesto'] ?? ''));
$usuarioID = cengi_usuario_actual_id();

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $ingenioID = cengi_ingenio_id_actual();
}

if (
    $cui === '' ||
    $nombre === '' ||
    $ingenioID <= 0 ||
    $cursoID <= 0 ||
    $area === '' ||
    $puesto === ''
) {
    header("Location: agregar_participantes1.php?curso_id=" . $cursoID);
    exit;
}

$stmtBuscarParticipante = $db->prepare("
    SELECT id
    FROM participantes
    WHERE cui_participantes = ?
    LIMIT 1
");

$stmtInsertParticipante = $db->prepare("
    INSERT INTO participantes (
        ingenio_id,
        usuarios_id,
        cui_participantes,
        nombre_participantes,
        puesto_participantes,
        area_participantes,
        estado_participantes,
        creado
    )
    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
");

$stmtActualizarParticipante = $db->prepare("
    UPDATE participantes
    SET
        ingenio_id = ?,
        usuarios_id = ?,
        nombre_participantes = ?,
        puesto_participantes = ?,
        area_participantes = ?,
        estado_participantes = 1,
        actualizado = NOW()
    WHERE id = ?
");

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

    $stmtBuscarParticipante->execute([$cui]);
    $participanteID = $stmtBuscarParticipante->fetchColumn();

    if ($participanteID) {
        $stmtActualizarParticipante->execute([
            $ingenioID,
            $usuarioID,
            $nombre,
            $puesto,
            $area,
            $participanteID,
        ]);
    } else {
        $stmtInsertParticipante->execute([
            $ingenioID,
            $usuarioID,
            $cui,
            $nombre,
            $puesto,
            $area,
        ]);
        $participanteID = (int) $db->lastInsertId();
    }

    $stmtBuscarAsignacion->execute([
        $participanteID,
        $cursoID,
    ]);

    $asignacionID = $stmtBuscarAsignacion->fetchColumn();

    if (!$asignacionID) {
        $stmtInsertAsignacion->execute([
            $participanteID,
            $usuarioID,
            $cursoID,
        ]);
    } else {
        $stmtActualizarAsignacion->execute([
            $usuarioID,
            $asignacionID,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

header("Location: ver_participante_curso.php?id=" . $cursoID);
exit;
?>
