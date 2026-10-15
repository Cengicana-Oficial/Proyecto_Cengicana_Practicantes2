<?php
require_once("../../../config/auth.php");
require_login();

if (!can_access('gestionar_areas')) {
    http_response_code(403);
    exit('No autorizado.');
}

$conn = conexion::conectar();

$nombre_area = trim($_POST['nombre_area']);
$profesional_area = trim($_POST['profesional_area'] ?? '');
$correo_area = trim($_POST['correo_area']);

if ($nombre_area === '' || $profesional_area === '' || $correo_area === '') {
    http_response_code(422);
    exit('Completa el nombre del área, el profesional responsable y el correo electrónico.');
}

$stmt = $conn->prepare("
    INSERT INTO areas_interes (nombre_area, profesional_area, correo_area, estado)
    VALUES (?, ?, ?, 1)
");

$stmt->execute([$nombre_area, $profesional_area, $correo_area]);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$adminDir = dirname($_SERVER['SCRIPT_NAME']);
if (basename($adminDir) === 'modulos') {
    $adminDir = dirname($adminDir);
}
header("Location: {$scheme}://{$host}{$adminDir}/dashboard_unificado.php?modulo=solicitudes&vista=areas");
exit;
?>
