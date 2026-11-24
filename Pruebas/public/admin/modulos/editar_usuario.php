<?php
session_start();
require_once("../../../config/conexion.php");
require_once("../../../config/auth.php"); visitas_exigir_permiso(["gestionar_usuarios"]);

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* verificar login */
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit;
}

/* verificar envío */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../superadmin.php?modulo=usuarios");
    exit;
}

$conn = conexion::conectar();

$id_usuario = (int) $_POST['id_usuario'];
$correo     = trim($_POST['correo']);
$cargo      = trim($_POST['cargo']);
$estado     = (int) $_POST['estado'];

/* validar datos */
if (!$id_usuario || empty($correo) || empty($cargo)) {
    header("Location: ../superadmin.php?modulo=usuarios");
    exit;
}

/* verificar si correo ya existe en otro usuario */
$check = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ? AND id_usuario != ?");
$check->execute([$correo, $id_usuario]);
$existe = $check->fetchColumn();

if ($existe > 0) {
    header("Location: ../superadmin.php?modulo=usuarios");
    exit;
}

/* actualizar usuario */
$stmt = $conn->prepare("
    UPDATE usuarios
    SET correo = ?, cargo = ?, estado = ?
    WHERE id_usuario = ?
");

$stmt->execute([
    $correo,
    $cargo,
    $estado,
    $id_usuario
]);

header("Location: ../superadmin.php?modulo=usuarios");
exit;
?>