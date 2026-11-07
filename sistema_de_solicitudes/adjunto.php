<?php
declare(strict_types=1);

// Entrega un adjunto de solicitud solo a quien puede ver esa solicitud.
// La carpeta uploads/ no se publica como archivos estaticos (no-php-uploads.conf).

session_start();

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

$user = current_user($menuPdo, $pdo);
if (!$user || !user_has_module_access($user, $menuPdo)) {
    http_response_code(403);
    exit('Inicia sesion para descargar este archivo.');
}

$stmt = $pdo->prepare(
    'SELECT a.nombre_original, a.ruta, a.mime_type, s.solicitante_id, s.programa_destino_id
     FROM adjuntos a
     INNER JOIN solicitudes s ON s.id = a.solicitud_id
     WHERE a.id = ?'
);
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$adjunto = $stmt->fetch();

if (!$adjunto || !can_view_request($user, $adjunto)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$uploads = realpath(__DIR__ . '/uploads');
$path = realpath(__DIR__ . '/' . $adjunto['ruta']);
if ($uploads === false || $path === false || !str_starts_with($path, $uploads . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = (string) $adjunto['mime_type'];
if (!isset(ADJUNTOS_TIPOS[$extension]) || !in_array($mime, ADJUNTOS_TIPOS[$extension], true)) {
    $mime = 'application/octet-stream';
}
// PDF e imagenes se pueden ver en el navegador; Word/Excel se descargan.
$enLinea = in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true) && !isset($_GET['descargar']);
$nombre = str_replace(['"', "\r", "\n"], '', (string) $adjunto['nombre_original']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $nombre) . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=3600');
readfile($path);
