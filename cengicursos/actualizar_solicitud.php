<?php
require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_editar_solicitudes('solicitudes.php');

$db = conectar();
$id = (int) ($_POST['id_solicitud'] ?? 0);

if ($id <= 0) {
    header("Location: solicitudes.php");
    exit;
}

$stmtActual = $db->prepare("
    SELECT
        s.id_solicitud,
        s.estado,
        i.nombre_ingenios
    FROM solicitudes_inscripcion s
    LEFT JOIN ingenios i ON i.id = s.ingenio_id
    WHERE s.id_solicitud = ?
    LIMIT 1
");
$stmtActual->execute([$id]);
$actual = $stmtActual->fetch(PDO::FETCH_ASSOC);

if (!$actual || $actual['estado'] !== 'Pendiente') {
    header("Location: solicitudes.php");
    exit;
}

if (
    !cengi_ve_todo_por_rol_o_ingenio() &&
    cengi_texto_normalizado($actual['nombre_ingenios'] ?? '') !== cengi_texto_normalizado(cengi_ingenio_nombre_actual())
) {
    header("Location: solicitudes.php");
    exit;
}

$ingenioId = (int) ($_POST['ingenio_id'] ?? 0);

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $ingenioId = cengi_ingenio_id_actual();
}

$stmt = $db->prepare("
    UPDATE solicitudes_inscripcion
    SET
        nombre_participante = ?,
        cui_participante = ?,
        puesto_participante = ?,
        area_participante = ?,
        grado_academico = ?,
        correo = ?,
        telefono = ?,
        ingenio_id = ?,
        curso_id = ?,
        tipo_pago = ?
    WHERE id_solicitud = ?
");

$stmt->execute([
    trim((string) ($_POST['nombre_participante'] ?? '')),
    trim((string) ($_POST['cui_participante'] ?? '')),
    trim((string) ($_POST['puesto_participante'] ?? '')),
    trim((string) ($_POST['area_participante'] ?? '')),
    trim((string) ($_POST['grado_academico'] ?? '')),
    trim((string) ($_POST['correo'] ?? '')),
    trim((string) ($_POST['telefono'] ?? '')),
    $ingenioId,
    (int) ($_POST['curso_id'] ?? 0),
    trim((string) ($_POST['tipo_pago'] ?? '')),
    $id,
]);

header("Location: solicitudes.php");
exit;
?>
