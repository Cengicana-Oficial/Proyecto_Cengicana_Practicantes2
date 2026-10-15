<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/VisitStorage.php';
require_login();

if (!can_access_any(['gestionar_solicitudes', 'ver_solicitudes', 'ver_solicitudes_aprobadas'])) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$requestId = (int) ($_GET['request_id'] ?? 0);
$kind = (string) ($_GET['kind'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$conn = Conexion::conectar();

if ($kind === 'agenda') {
    $stmt = $conn->prepare('SELECT agenda_nombre AS nombre, agenda_ruta AS ruta FROM solicitud_gestion_visita WHERE id_solicitud = ?');
    $stmt->execute([$requestId]);
} elseif ($kind === 'photo') {
    $stmt = $conn->prepare('SELECT nombre_archivo AS nombre, ruta_archivo AS ruta FROM solicitud_fotos_visita WHERE id_solicitud = ? AND id_foto = ?');
    $stmt->execute([$requestId, $id]);
} else {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$file = $stmt->fetch(PDO::FETCH_ASSOC);
$relative = str_replace(['..', '\\'], '', (string) ($file['ruta'] ?? ''));

if (Conexion::driver() === 'pgsql') {
    if (!$file || $relative === '') {
        http_response_code(404);
        exit('Archivo no encontrado.');
    }
    VisitStorage::streamObject($relative, basename((string) $file['nombre']));
}

$path = dirname(__DIR__, 2) . '/uploads/gestion_visitas/' . $relative;
if (!$file || $relative === '' || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode(basename((string) $file['nombre'])) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
