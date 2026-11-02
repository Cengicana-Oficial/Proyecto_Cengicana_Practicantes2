<?php
require_once "revisar_permisos.php";
require_once "conexion.php";
require_once __DIR__ . '/inscripcion_registro.php';

cengi_require_admin('ver_cursos.php');

$db = conectar();
$id = (int) ($_GET['id'] ?? 0);
$cursoId = (int) ($_GET['curso_id'] ?? 0);
$estado = (int) ($_GET['estado'] ?? 0);
$nuevoEstado = $estado === 1 ? 0 : 1;

if ($id > 0 && $cursoId > 0) {
    $stmtScope = $db->prepare("
        SELECT participantes_id
        FROM asignaciones
        WHERE id = ? AND cursos_id = ?
        LIMIT 1
    ");
    $stmtScope->execute([$id, $cursoId]);
    $participanteId = (int) $stmtScope->fetchColumn();

    if ($participanteId > 0) {
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                UPDATE asignaciones
                SET estado_asignaciones = ?, actualizado = NOW()
                WHERE id = ? AND cursos_id = ?
            ");
            if ($nuevoEstado === 1) {
                cengi_ins_alta_curso($db, $participanteId, $cursoId);
            } else {
                $stmt->execute([$nuevoEstado, $id, $cursoId]);
            }

            if ($nuevoEstado === 1) {
                $stmtParticipante = $db->prepare("
                    UPDATE participantes
                    SET estado_participantes = 1, actualizado = NOW()
                    WHERE id = ?
                ");
                $stmtParticipante->execute([$participanteId]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

header("Location: ver_participante_curso.php?id=" . $cursoId);
exit;
?>
