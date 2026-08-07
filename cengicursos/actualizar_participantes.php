<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';

cengi_require_editar_participantes('participantes.php');

$db = conectar();
$id = (int) ($_POST['id'] ?? 0);
$cursoId = (int) ($_POST['curso_id'] ?? 0);
$ingenioId = (int) ($_POST['ingenio'] ?? 0);
$cui = trim((string) ($_POST['cui_participantes'] ?? ''));
$nombre = trim((string) ($_POST['nombre_participantes'] ?? ''));
$puesto = trim((string) ($_POST['puesto_participantes'] ?? ''));
$area = trim((string) ($_POST['area_participantes'] ?? ''));
$correo = trim((string) ($_POST['correo_participantes'] ?? ''));
$gradoAcademico = trim((string) ($_POST['grado_academico_participantes'] ?? ''));
$telefono = trim((string) ($_POST['telefono_participantes'] ?? ''));
$estado = (int) ($_POST['estado_participantes'] ?? 1) === 1 ? 1 : 0;
$destino = 'participantes.php?curso_id=' . $cursoId;

if ($id <= 0 || $ingenioId <= 0 || $cui === '' || $nombre === '' || $puesto === '' || $area === '') {
    header('Location: ' . $destino . '&error=actualizar');
    exit;
}

if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    header('Location: ' . $destino . '&error=correo');
    exit;
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $ingenioId = cengi_ingenio_id_actual();
}

try {
    $db->beginTransaction();

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

    $asignacionId = 0;
    if ($cursoId > 0) {
        $stmtAsignacionScope = $db->prepare("
            SELECT id
            FROM asignaciones
            WHERE participantes_id = ? AND cursos_id = ?
            LIMIT 1
        ");
        $stmtAsignacionScope->execute([$id, $cursoId]);
        $asignacionId = (int) $stmtAsignacionScope->fetchColumn();
        if ($asignacionId <= 0) {
            throw new RuntimeException('Asignacion del curso no encontrada.');
        }
    }

    $campos = [
        'ingenio_id = ?',
        'cui_participantes = ?',
        'nombre_participantes = ?',
        'puesto_participantes = ?',
        'area_participantes = ?',
        'correo_participantes = ?',
        'grado_academico_participantes = ?',
        'telefono_participantes = ?',
    ];
    $params = [$ingenioId, $cui, $nombre, $puesto, $area, $correo, $gradoAcademico, $telefono];

    if ($cursoId <= 0 || $estado === 1) {
        $campos[] = 'estado_participantes = ?';
        $params[] = $estado;
    }

    $campos[] = 'actualizado = NOW()';
    $params[] = $id;

    $sql = 'UPDATE participantes SET ' . implode(', ', $campos) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($cursoId > 0) {
        $stmtAsignacion = $db->prepare("
            UPDATE asignaciones
            SET estado_asignaciones = ?, actualizado = NOW()
            WHERE id = ?
        ");
        $stmtAsignacion->execute([$estado, $asignacionId]);
    }

    $db->commit();
    header('Location: ' . $destino . '&mensaje=actualizado');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Error al actualizar participante: ' . $e->getMessage());
    $codigoError = $e instanceof PDOException && $e->getCode() === '23000' ? 'cui_duplicado' : 'actualizar';
    header('Location: ' . $destino . '&error=' . $codigoError);
}
exit;
