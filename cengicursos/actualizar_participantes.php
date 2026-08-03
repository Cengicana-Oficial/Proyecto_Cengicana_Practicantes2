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
$estado = (int) ($_POST['estado_participantes'] ?? 1) === 1 ? 1 : 0;
$destino = 'participantes.php?curso_id=' . $cursoId;

if ($id <= 0 || $ingenioId <= 0 || $cui === '' || $nombre === '' || $puesto === '' || $area === '') {
    header('Location: ' . $destino . '&error=actualizar');
    exit;
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $ingenioId = cengi_ingenio_id_actual();
}

try {
    $sql = 'UPDATE participantes
            SET ingenio_id = ?, cui_participantes = ?, nombre_participantes = ?,
                puesto_participantes = ?, area_participantes = ?, estado_participantes = ?, actualizado = NOW()
            WHERE id = ?';
    $params = [$ingenioId, $cui, $nombre, $puesto, $area, $estado, $id];
    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $sql .= ' AND ingenio_id = ?';
        $params[] = cengi_ingenio_id_actual();
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    header('Location: ' . $destino . '&mensaje=actualizado');
} catch (Throwable $e) {
    error_log('Error al actualizar participante: ' . $e->getMessage());
    header('Location: ' . $destino . '&error=actualizar');
}
exit;
