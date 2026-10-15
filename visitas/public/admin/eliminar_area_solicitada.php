<?php
require_once("../../config/auth.php");
require_login();
if (!PermissionManager::can('gestionar_solicitudes')) {
    header("Location: dashboard_unificado.php?modulo=solicitudes");
    exit;
}

$conn = conexion::conectar();

$id_solicitud = $_POST['id_solicitud'];
$id_area = $_POST['id_area'];

$sql = "
    DELETE FROM solicitud_areas
    WHERE id_solicitud = ?
    AND id_area = ?
";

$stmt = $conn->prepare($sql);
$stmt->execute([$id_solicitud, $id_area]);

// Verificar si viene del superadmin
$from_unified = isset($_POST['from_unified']) && $_POST['from_unified'] == '1';
$from_superadmin = isset($_POST['from_superadmin']) && $_POST['from_superadmin'] == '1';

header("Location: " . (($from_unified || $from_superadmin) ? "dashboard_unificado.php?modulo=solicitudes" : "dashboard.php"));
exit;
?>
