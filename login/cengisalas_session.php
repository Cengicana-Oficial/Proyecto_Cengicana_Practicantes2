<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
cengi_session_start();
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/cengisalas_access.php';
require_once __DIR__ . '/config/cengisalas_supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function cengisalas_session_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cengisalas_session_error(405, 'Método no permitido.');
}

$portalUserId = (int) ($_SESSION['id_usuario'] ?? 0);
if ($portalUserId <= 0) {
    cengisalas_session_error(401, 'Inicia sesión en el portal de Módulos.');
}

try {
    $db = Conexion::conectar();
    $stmt = $db->prepare('SELECT id,nombre,correo,es_superadmin FROM usuarios WHERE id=?');
    $stmt->execute([$portalUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        cengisalas_session_error(401, 'La cuenta del portal ya no existe.');
    }
    $superadmin = (int) $user['es_superadmin'] === 1;
    $access = cengisalas_user_access($db, $portalUserId, $superadmin);
    if (!isset($access['salas'])) {
        cengisalas_session_error(403, 'Tu cuenta no tiene acceso a CengiSalas.');
    }

    // La identidad y el permiso proceden del portal. Supabase recibe solo
    // una sesión técnica para que sus políticas de datos sigan funcionando.
    session_write_close();
    $link = cengisalas_admin_request('POST', '/auth/v1/admin/generate_link', [
        'type' => 'magiclink',
        'email' => strtolower((string) $user['correo']),
    ]);
    $profileId = (string) ($link['id'] ?? $link['user']['id'] ?? '');
    $tokenHash = (string) ($link['properties']['hashed_token'] ?? $link['hashed_token'] ?? '');
    if ($profileId === '' || $tokenHash === '') {
        throw new RuntimeException('No se pudo preparar la sesión de datos.');
    }
    cengisalas_sync_profile(cengisalas_pg(), $profileId, $user, $access, $superadmin);
    $verified = cengisalas_admin_request('POST', '/auth/v1/verify', [
        'type' => 'email',
        'token_hash' => $tokenHash,
    ]);
    if (($verified['user']['id'] ?? null) !== $profileId
        || empty($verified['access_token']) || empty($verified['refresh_token'])) {
        throw new RuntimeException('La sesión de datos no corresponde a la cuenta del portal.');
    }
    echo json_encode([
        'allowed' => true,
        'portal_user_id' => $portalUserId,
        'role' => $access['salas'],
        'superadmin' => $superadmin,
        'user_id' => $profileId,
        'access_token' => $verified['access_token'],
        'refresh_token' => $verified['refresh_token'],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('CengiSalas portal session: ' . $error->getMessage());
    cengisalas_session_error(503, 'No fue posible iniciar CengiSalas con la sesión del portal.');
}
