<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/VisitStorage.php';
require_login();

if (!can_access_any(['ver_pagos', 'gestionar_pagos'])) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$requestId = (int) ($_GET['id_solicitud'] ?? 0);
$conn = Conexion::conectar();
$stmt = $conn->prepare('SELECT ruta_comprobante_pago,nombre_comprobante_pago FROM solicitud_museo WHERE id_solicitud=?');
$stmt->execute([$requestId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
$relative = str_replace(['..', '\\'], '', (string) ($file['ruta_comprobante_pago'] ?? ''));
if (!$file || $relative === '') {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}

if (Conexion::driver() === 'pgsql') {
    VisitStorage::streamObject($relative, basename((string) $file['nombre_comprobante_pago']));
}

$path = dirname(__DIR__, 2) . '/uploads/comprobantes/' . basename($relative);
if (!is_file($path)) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
$mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', basename((string) $file['nombre_comprobante_pago'])) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
