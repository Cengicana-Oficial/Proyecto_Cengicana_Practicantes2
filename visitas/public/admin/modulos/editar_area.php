<?php
require_once("../../../config/auth.php");
require_login();

if (!can_access('gestionar_areas')) {
    http_response_code(403);
    exit('No autorizado.');
}

$conn = conexion::conectar();

$id = $_POST['id_area'];
$nombre = trim($_POST['nombre_area']);
$profesional = trim($_POST['profesional_area'] ?? '');
$correo = trim($_POST['correo_area']);
$estado = (int) $_POST['estado'];

if ($nombre === '' || $profesional === '' || $correo === '') {
    http_response_code(422);
    exit('Completa el nombre del área, el profesional responsable y el correo electrónico.');
}

$stmt = $conn->prepare("
    UPDATE areas_interes
    SET nombre_area=?, profesional_area=?, correo_area=?, estado=?
    WHERE id_area=?
");
$stmt->execute([$nombre, $profesional, $correo, $estado, $id]);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$adminDir = dirname($_SERVER['SCRIPT_NAME']);
if (basename($adminDir) === 'modulos') {
    $adminDir = dirname($adminDir);
}
header("Location: {$scheme}://{$host}{$adminDir}/dashboard_unificado.php?modulo=solicitudes&vista=areas");
exit;
?>
