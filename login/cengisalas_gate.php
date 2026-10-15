<?php
declare(strict_types=1);
require_once __DIR__ . '/config/session.php';
cengi_session_start();
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/cengisalas_access.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$module = (string) ($_GET['module'] ?? '');
if (!isset(cengisalas_roles()[$module]) || empty($_SESSION['id_usuario'])) {
    http_response_code(403);
    echo json_encode(['allowed' => false]);
    exit;
}
try {
    $db = Conexion::conectar();
    $stmt = $db->prepare('SELECT id,es_superadmin FROM usuarios WHERE id=?');
    $stmt->execute([(int) $_SESSION['id_usuario']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $superadmin = $user && (int) $user['es_superadmin'] === 1;
    $role = $user ? ($superadmin ? 'admin' : cengisalas_role($db, (int) $user['id'], $module)) : null;
    $allowed = $role !== null;
} catch (Throwable $error) {
    error_log('CengiSalas gate: ' . $error->getMessage());
    $allowed = false;
    $role = null;
    $superadmin = false;
}
http_response_code($allowed ? 200 : 403);
echo json_encode(['allowed' => (bool) $allowed, 'role' => $allowed ? $role : null, 'superadmin' => (bool) $superadmin, 'portal_user_id' => $allowed ? (int) $user['id'] : null]);
