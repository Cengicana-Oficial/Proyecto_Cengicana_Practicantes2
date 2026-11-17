<?php
declare(strict_types=1);
require_once __DIR__ . '/config/session.php';
cengi_session_start();
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/visitas_access.php';
require_once __DIR__ . '/config/cengisalas_supabase.php';
header('Cache-Control: no-store, private');
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}
try {
    $db = Conexion::conectar();
    $user = visitas_actor($db, (int) $_SESSION['id_usuario']);
    if (!$user) {
        http_response_code(403);
        exit('No tienes acceso asignado a Visitas.');
    }
    session_write_close();
    $link = cengisalas_admin_request('POST', '/auth/v1/admin/generate_link', [
        'type' => 'magiclink', 'email' => strtolower($user['correo']),
    ]);
    $profileId = (string) ($link['id'] ?? $link['user']['id'] ?? '');
    $tokenHash = (string) ($link['properties']['hashed_token'] ?? $link['hashed_token'] ?? '');
    if ($profileId === '' || $tokenHash === '') throw new RuntimeException('No se pudo preparar la sesión.');
    $superadmin = (int) $user['es_superadmin'] === 1;
    cengisalas_sync_profile(cengisalas_pg(), $profileId, $user,
        cengisalas_user_access($db, (int) $user['id'], $superadmin), $superadmin);
    cengisalas_apply_visitas_role(cengisalas_pg(), $profileId, $user['role_code']);
    $verified = cengisalas_admin_request('POST', '/auth/v1/verify', [
        'type' => 'email', 'token_hash' => $tokenHash,
    ]);
    if (($verified['user']['id'] ?? '') !== $profileId || empty($verified['access_token'])) {
        throw new RuntimeException('La identidad no coincide con la cuenta del portal.');
    }
    session_name('CENGISALAS_VISIT');
    session_id($_COOKIE['CENGISALAS_VISIT'] ?? '');
    cengi_session_start();
    session_regenerate_id(true);
    $_SESSION = [
        'portal_user_id' => (int) $user['id'],
        'cengisalas_authenticated' => true,
        'cengisalas_user_id' => $profileId,
        'cengisalas_access_token' => $verified['access_token'],
        'cengisalas_last_activity' => time(),
        'id_usuario' => $profileId,
        'rol' => $user['role_code'],
        'visitas_role' => $user['role_code'],
        'es_superadmin' => (int) $user['es_superadmin'],
        'module_permissions_loaded' => true,
        'user_permissions' => visitas_permissions($user['role_code']),
        'nombre' => $user['nombre'], 'correo' => $user['correo'], 'email' => $user['correo'],
        'usuario' => ['id_usuario' => $profileId, 'nombre' => $user['nombre'], 'correo' => $user['correo'], 'rol' => $user['role_code']],
    ];
    $target = $user['role_code'] === 'gestor_pagos' ? 'dashboard_pago.php' : 'dashboard_unificado.php?modulo=solicitudes';
    header('Location: ../visitas/public/admin/' . $target, true, 303);
} catch (Throwable $error) {
    error_log('Ingreso Visitas: ' . $error->getMessage());
    http_response_code(503);
    echo 'No fue posible abrir Visitas. <a href="Menu.php">Volver al menú</a>';
}
