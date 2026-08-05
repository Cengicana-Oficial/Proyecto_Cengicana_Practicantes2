<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';

cengi_require_eliminar_participantes('participantes.php');

$db = conectar();
$asignacionId = (int) ($_GET['asignacion_id'] ?? 0);
$cursoId = (int) ($_GET['curso_id'] ?? 0);
$destino = 'participantes.php?curso_id=' . $cursoId;

if ($asignacionId <= 0 || $cursoId <= 0) {
    header('Location: ' . $destino . '&error=remover');
    exit;
}

try {
    $sqlScope = "
        SELECT a.id
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        WHERE a.id = ? AND a.cursos_id = ? AND a.estado_asignaciones = 1
    ";
    $paramsScope = [$asignacionId, $cursoId];

    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $sqlScope .= ' AND p.ingenio_id = ?';
        $paramsScope[] = cengi_ingenio_id_actual();
    }

    $stmtScope = $db->prepare($sqlScope);
    $stmtScope->execute($paramsScope);
    if (!$stmtScope->fetchColumn()) {
        throw new RuntimeException('Asignacion fuera del alcance permitido.');
    }

    $stmt = $db->prepare("
        UPDATE asignaciones
        SET estado_asignaciones = 0, actualizado = NOW()
        WHERE id = ? AND cursos_id = ? AND estado_asignaciones = 1
    ");
    $stmt->execute([$asignacionId, $cursoId]);

    header('Location: ' . $destino . '&estado=activo&mensaje=removido');
} catch (Throwable $e) {
    error_log('Error al remover participante del curso: ' . $e->getMessage());
    header('Location: ' . $destino . '&error=remover');
}
exit;
