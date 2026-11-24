<?php
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/PermissionManager.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login()
{
    if (!isset($_SESSION['id_usuario'])) {
        header("Location: ../../../login/login.php");
        exit;
    }

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

/**
 * Para endpoints de acción (guardar, eliminar, enviar): exige sesión y al menos uno de los
 * permisos, que son los mismos que muestran la opción en el menú. Responde 403 (o JSON si
 * $json) en lugar de redirigir, porque estos archivos están en carpetas de distinta profundidad.
 * Con $permisos vacío solo pasa el superadmin.
 */
function visitas_exigir_permiso(array $permisos, $json = false)
{
    $ok = isset($_SESSION['id_usuario']);
    if ($ok) {
        PermissionManager::loadUserPermissions();
        $ok = $permisos ? can_access_any($permisos) : (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
    }
    if ($ok) {
        return;
    }
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
    } else {
        echo 'No tiene permiso para realizar esta acción.';
    }
    exit;
}

function current_role_name()
{
    return strtolower(trim($_SESSION['rol'] ?? 'usuario'));
}
