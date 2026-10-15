<?php
require_once __DIR__ . "/session_namespace.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/PermissionManager.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login()
{
    $lastActivity = (int) ($_SESSION['cengisalas_last_activity'] ?? 0);
    $expired = $lastActivity > 0 && (time() - $lastActivity) > 300;

    if (empty($_SESSION['cengisalas_authenticated']) || !isset($_SESSION['id_usuario']) || $expired) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header("Location: /login/sso_visitas.php");
        exit;
    }

    $_SESSION['cengisalas_last_activity'] = time();
    PermissionManager::loadUserPermissions();
}

function can_access($permission)
{
    return PermissionManager::can($permission);
}

function can_access_any(array $permissions)
{
    foreach ($permissions as $permission) {
        if (can_access($permission)) {
            return true;
        }
    }

    return false;
}

function current_role_name()
{
    return strtolower(trim($_SESSION['rol'] ?? 'usuario'));
}
