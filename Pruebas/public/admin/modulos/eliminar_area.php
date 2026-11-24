<?php
require_once("../../../config/conexion.php");
require_once("../../../config/auth.php"); visitas_exigir_permiso(["gestionar_areas", "gestionar_solicitudes"]);

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
    header("Location: {$scheme}://{$host}{$adminDir}/superadmin.php?modulo=areas");
    exit;

} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>