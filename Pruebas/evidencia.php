<?php
require __DIR__ . '/bootstrap.php';
try {
    $u = require_user(); permit($u,1); $s = service((string)($_GET['id'] ?? '')); scope($u,$s);
    $name = basename((string)($s['Evidencia'] ?? ''));
    $file = uploads_directory() . '/' . $name;
    if (!$name || !is_file($file)) fail('Evidencia no disponible.',404);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'])) fail('Formato de evidencia no disponible.',415);
    header('Content-Type: ' . $mime); header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
    readfile($file);
} catch (Throwable $e) { if (!($e instanceof DomainException)) http_response_code(500); echo 'No se pudo abrir la evidencia.'; }
