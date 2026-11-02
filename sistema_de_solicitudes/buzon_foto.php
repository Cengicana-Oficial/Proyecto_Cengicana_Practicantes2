<?php
declare(strict_types=1);

// Entrega la foto de una idea del buzon solo a usuarios con permiso para verlas.

session_start();

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

$user = current_user($menuPdo, $pdo);
if (!$user || !user_has_module_access($user, $menuPdo) || !can_view_ideas($user)) {
    http_response_code(403);
    exit('No tienes permiso para ver esta foto.');
}

$stmt = $pdo->prepare('SELECT foto_ruta, foto_mime FROM ideas WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$idea = $stmt->fetch();

$path = $idea && $idea['foto_ruta'] ? __DIR__ . '/' . $idea['foto_ruta'] : '';
if ($path === '' || !isset(BUZON_FOTO_TIPOS[(string) $idea['foto_mime']]) || !is_file($path)) {
    http_response_code(404);
    exit('Foto no encontrada.');
}

header('Content-Type: ' . $idea['foto_mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
