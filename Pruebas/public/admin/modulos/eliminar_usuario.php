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
if (!isset($_GET['id'])) {
    header("Location: ../superadmin.php?modulo=usuarios");
    exit;
}

$conn = conexion::conectar();

$id_usuario = (int) $_GET['id'];

/* validar id */
if (!$id_usuario) {
    header("Location: ../superadmin.php?modulo=usuarios");
    exit;
}

/* desactivar usuario (no eliminar físicamente) */
$stmt = $conn->prepare("
    UPDATE usuarios
    SET estado = 0
    WHERE id_usuario = ?
");

$stmt->execute([$id_usuario]);

header("Location: ../superadmin.php?modulo=usuarios");
exit;
?>