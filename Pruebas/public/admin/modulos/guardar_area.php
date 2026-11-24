<?php
require_once("../../../config/conexion.php");
require_once("../../../config/auth.php"); visitas_exigir_permiso(["gestionar_areas", "gestionar_solicitudes"]);

$conn = conexion::conectar();

$nombre_area = trim($_POST['nombre_area']);
$correo_area = trim($_POST['correo_area']);

$stmt = $conn->prepare("
    INSERT INTO areas_interes (nombre_area, correo_area, estado)
    VALUES (?, ?, 1)
");

$stmt->execute([$nombre_area, $correo_area]);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$adminDir = dirname($_SERVER['SCRIPT_NAME']);
if (basename($adminDir) === 'modulos') {
    $adminDir = dirname($adminDir);
}
header("Location: {$scheme}://{$host}{$adminDir}/superadmin.php?modulo=areas");
exit;
?>