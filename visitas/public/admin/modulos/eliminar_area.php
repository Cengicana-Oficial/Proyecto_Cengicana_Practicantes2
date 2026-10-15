<?php
require_once("../../../config/auth.php");
require_login();

if (!can_access('gestionar_areas')) {
    http_response_code(403);
    exit('No autorizado.');
}

try {
    $conn = conexion::conectar();

    if (!isset($_GET['id'])) {
        die("ID no recibido");
    }

    $id = (int) $_GET['id'];

    $stmt = $conn->prepare("
        DELETE FROM areas_interes
        WHERE id_area = ?
    ");

    $stmt->execute([$id]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $adminDir = dirname($_SERVER['SCRIPT_NAME']);
    if (basename($adminDir) === 'modulos') {
        $adminDir = dirname($adminDir);
    }
    header("Location: {$scheme}://{$host}{$adminDir}/dashboard_unificado.php?modulo=solicitudes&vista=areas");
    exit;

} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
