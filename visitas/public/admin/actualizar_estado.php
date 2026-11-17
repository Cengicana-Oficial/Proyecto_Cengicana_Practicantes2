<?php
require_once("../../config/auth.php");
require_login();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!PermissionManager::can('gestionar_solicitudes')) {
    header("Location: dashboard_unificado.php?modulo=solicitudes");
    exit;
}

/* verificar envío */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard_unificado.php?modulo=solicitudes");
    exit;
}

$conn = conexion::conectar();

/* datos recibidos */
$id_solicitud = $_POST['id_solicitud'];
$id_estado = $_POST['id_estado'];

/*
=====================================
ACTUALIZAR ESTADO Y FECHA DE REGISTRO
=====================================
*/
$stmt = $conn->prepare("
    UPDATE solicitudes
    SET id_estado = ?, fecha_registro = NOW()
    WHERE id_solicitud = ?
");

$stmt->execute([$id_estado, $id_solicitud]);

// Redirigir de vuelta al dashboard sin enviar correo
header("Location: dashboard_unificado.php?modulo=solicitudes");
exit;
?>
