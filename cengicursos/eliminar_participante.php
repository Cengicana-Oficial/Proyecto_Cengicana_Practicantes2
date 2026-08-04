<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';

cengi_require_eliminar_participantes('participantes.php');

$db = conectar();
$id = (int) ($_GET['id'] ?? 0);
$cursoId = (int) ($_GET['curso_id'] ?? 0);
$destino = 'participantes.php?curso_id=' . $cursoId;

if ($id <= 0) {
    header('Location: ' . $destino . '&error=eliminar');
    exit;
}

try {
    $sqlScope = 'SELECT id FROM participantes WHERE id = ?';
    $paramsScope = [$id];
    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $sqlScope .= ' AND ingenio_id = ?';
        $paramsScope[] = cengi_ingenio_id_actual();
    }
    $stmtScope = $db->prepare($sqlScope);
    $stmtScope->execute($paramsScope);
    if (!$stmtScope->fetchColumn()) {
        throw new RuntimeException('Participante fuera del alcance permitido.');
    }

    $db->beginTransaction();
    $stmt = $db->prepare('UPDATE asignaciones SET estado_asignaciones = 0, actualizado = NOW() WHERE participantes_id = ?');
    $stmt->execute([$id]);
    $stmt = $db->prepare('UPDATE participantes SET estado_participantes = 0, actualizado = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    $db->commit();
    header('Location: ' . $destino . '&mensaje=desactivado');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Error al desactivar participante: ' . $e->getMessage());
    header('Location: ' . $destino . '&error=eliminar');
}
exit;
