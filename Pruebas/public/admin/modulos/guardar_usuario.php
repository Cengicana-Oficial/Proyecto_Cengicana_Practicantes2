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

$correo  = trim($_POST['correo']);
$password = $_POST['password'];
$cargo   = trim($_POST['cargo']);

/* validar datos */
if (empty($correo) || empty($password) || empty($cargo)) {
    header("Location: ../superadmin.php?modulo=usuarios");
    exit;
}

/* verificar si correo ya existe */
$check = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?");
$check->execute([$correo]);
$existe = $check->fetchColumn();

if ($existe > 0) {
    header("Location: ../superadmin.php?modulo=usuarios");
    exit;
}

/* insertar usuario */
$stmt = $conn->prepare("
    INSERT INTO usuarios (correo, password, cargo, estado)
    VALUES (?, ?, ?, 1)
");

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt->execute([
    $correo,
    $hashed_password,
    $cargo
]);

header("Location: ../superadmin.php?modulo=usuarios");
exit;
?>