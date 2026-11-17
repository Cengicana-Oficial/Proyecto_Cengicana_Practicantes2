<?php
require_once("../../../config/auth.php");
require_login();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!PermissionManager::can('gestionar_solicitudes') && !PermissionManager::can('ocultar_solicitudes')) {
    header("Location: ../dashboard_unificado.php?modulo=solicitudes");
    exit;
}

/* verificar envío */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../dashboard.php");
    exit;
}

$conn = conexion::conectar();

$id_solicitud = $_POST['id_solicitud'] ?? null;
$is_unified = isset($_POST['from_unified']) && $_POST['from_unified'] == '1';
$is_superadmin = isset($_POST['from_superadmin']) && $_POST['from_superadmin'] == '1';
$estado = trim($_POST['estado'] ?? '');

if (!$id_solicitud) {
    $redirect = ($is_unified || $is_superadmin) ? '../dashboard_unificado.php?modulo=solicitudes' : '../dashboard.php';
    if (!$is_superadmin && $estado !== '') {
        $redirect .= '?estado=' . urlencode($estado);
    } elseif ($is_superadmin && $estado !== '') {
        $redirect .= '&estado=' . urlencode($estado);
    }
    header("Location: $redirect");
    exit;
}

/* verificar si ya está oculta */
$check = $conn->prepare("SELECT COUNT(*) FROM solicitudes_ocultas WHERE id_solicitud = ?");
$check->execute([$id_solicitud]);
$existe = $check->fetchColumn();

if ($existe == 0) {
    /* insertar en tabla de ocultas */
    $stmt = $conn->prepare("INSERT INTO solicitudes_ocultas (id_solicitud, fecha_ocultamiento) VALUES (?, NOW())");
    $stmt->execute([$id_solicitud]);
}

$redirect = ($is_unified || $is_superadmin) ? '../dashboard_unificado.php?modulo=solicitudes' : '../dashboard.php';
if (!$is_superadmin && $estado !== '') {
    $redirect .= '?estado=' . urlencode($estado);
} elseif ($is_superadmin && $estado !== '') {
    $redirect .= '&estado=' . urlencode($estado);
}

header("Location: $redirect");
exit;
?>
