<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';

cengi_require_subir_diploma('ver_cursos.php');

$db = conectar();
$asignacionId = (int) ($_GET['asignacion_id'] ?? 0);
$cursoId = (int) ($_GET['curso_id'] ?? 0);

$destino = 'ver_participante_curso.php?id=' . $cursoId;
$destinoExito = $destino . '&mensaje=diploma_eliminado';
$destinoError = $destino . '&error=eliminar_diploma';

if ($asignacionId <= 0 || $cursoId <= 0) {
    header('Location: ' . $destinoError);
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

    $stmtDiploma = $db->prepare('SELECT diploma FROM control_cursos WHERE asignacion_id = ?');
    $stmtDiploma->execute([$asignacionId]);
    $diplomaActual = (string) $stmtDiploma->fetchColumn();

    if (trim($diplomaActual) === '') {
        throw new RuntimeException('No hay diploma cargado para esta asignacion.');
    }

    // Borrado del archivo fisico: best-effort, no debe detener la limpieza del registro
    // en BD si el archivo ya no existe o no se puede borrar (ver conexion.php,
    // cengi_normalizar_url_archivo() / cengi_guardar_archivo_subido()).
    $urlNormalizada = cengi_normalizar_url_archivo($diplomaActual);
    if (strpos($urlNormalizada, '/uploads/') === 0) {
        $rutaFisica = __DIR__ . '/..' . $urlNormalizada;
        if (is_file($rutaFisica) && !@unlink($rutaFisica)) {
            error_log('No fue posible eliminar el archivo fisico del diploma: ' . $rutaFisica);
        }
    }

    $stmtUpdate = $db->prepare("UPDATE control_cursos SET diploma = '' WHERE asignacion_id = ?");
    $stmtUpdate->execute([$asignacionId]);

    header('Location: ' . $destinoExito);
} catch (Throwable $e) {
    error_log('Error al eliminar diploma de participante: ' . $e->getMessage());
    header('Location: ' . $destinoError);
}
exit;
