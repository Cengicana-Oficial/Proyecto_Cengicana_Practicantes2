<?php
require_once("../../../config/conexion.php");
require_once("../../../config/auth.php"); visitas_exigir_permiso(["gestionar_areas", "gestionar_solicitudes"]);

$conn = conexion::conectar();

$id = $_POST['id_area'];
$nombre = trim($_POST['nombre_area']);
$correo = trim($_POST['correo_area']);
$estado = (int) $_POST['estado'];

$stmt = $conn->prepare("
    UPDATE areas_interes
    SET nombre_area=?, correo_area=?, estado=?
    WHERE id_area=?
");
$stmt->execute([$nombre, $correo, $estado, $id]);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$adminDir = dirname($_SERVER['SCRIPT_NAME']);
if (basename($adminDir) === 'modulos') {
    $adminDir = dirname($adminDir);
}
header("Location: {$scheme}://{$host}{$adminDir}/superadmin.php?modulo=areas");
exit;
?>