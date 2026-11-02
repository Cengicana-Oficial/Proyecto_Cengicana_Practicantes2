<?php
/** Notificaciones de la campanita del shell (ver includes/notificaciones_helper.php). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/notificaciones_helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!lab_is_authenticated() || !lab_has_module_access()) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesión no válida.']);
    exit;
}

try {
    $items = lab_notificaciones($conexion, !empty($_GET['fresco']));
    echo json_encode([
        'total' => array_sum(array_column($items, 'cantidad')),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[laboratorio][notificaciones] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudieron cargar las notificaciones.']);
}
