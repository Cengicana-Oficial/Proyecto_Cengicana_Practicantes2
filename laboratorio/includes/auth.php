<?php

require_once __DIR__ . '/../config/env.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function lab_session_get(array $keys)
{
    foreach ($keys as $key) {
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        }
    }

    foreach (['usuario', 'user', 'auth_user'] as $container) {
        if (!isset($_SESSION[$container]) || !is_array($_SESSION[$container])) {
            continue;
        }

        foreach ($keys as $key) {
            if (isset($_SESSION[$container][$key])) {
                return $_SESSION[$container][$key];
            }
        }
    }

    return null;
}

function lab_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value === 1;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'si'], true);
}

function lab_current_user(): array
{
    return [
        'id' => lab_session_get(['usuario_id', 'user_id', 'id_usuario', 'id']),
        'nombre' => lab_session_get(['usuario_nombre', 'nombre', 'name']),
        'correo' => lab_session_get(['usuario_correo', 'correo', 'email']),
        'rol_id' => lab_session_get(['rol_id', 'role_id']),
        'rol' => lab_session_get(['nombre_rol', 'rol', 'role']),
        'es_superadmin' => lab_bool(lab_session_get(['es_superadmin', 'is_superadmin', 'superadmin'])),
    ];
}

function lab_is_authenticated(): bool
{
    $user = lab_current_user();

    return !empty($user['id'])
        || !empty($user['correo'])
        || !empty($user['nombre'])
        || $user['es_superadmin'];
}

function lab_string_list($value): ?array
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        $value = preg_split('/[,;|]/', $value);
    }

    if (!is_array($value)) {
        return null;
    }

    $items = [];

    foreach ($value as $item) {
        if (is_array($item)) {
            $item = $item['codigo'] ?? $item['nombre'] ?? $item['name'] ?? $item['permiso'] ?? null;
        }

        $item = strtolower(trim((string) $item));

        if ($item !== '') {
            $items[] = $item;
        }
    }

    return array_values(array_unique($items));
}

function lab_session_modules(): ?array
{
    return lab_string_list(lab_session_get([
        'modulos',
        'modules',
        'modulos_acceso',
        'modulos_permitidos',
        'user_modules',
    ]));
}

function lab_session_permissions(): ?array
{
    return lab_string_list(lab_session_get([
        'permisos',
        'permissions',
        'permisos_usuario',
        'user_permissions',
        'lab_permissions',
    ]));
}

function lab_normalized_role(): string
{
    $role = strtolower(trim((string) lab_current_user()['rol']));

    $from = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'];
    $to = ['a', 'e', 'i', 'o', 'u', 'u', 'n'];

    $role = str_replace($from, $to, $role);

    if (function_exists('iconv')) {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $role);
        if ($normalized !== false) {
            $role = strtolower($normalized);
        }
    }

    return strtr($role, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ]);
}

function lab_role_is_laboratory_related(): bool
{
    $user = lab_current_user();
    $roleId = (int) ($user['rol_id'] ?? 0);
    $role = lab_normalized_role();

    return in_array($roleId, [1, 2, 3, 4], true)
        || strpos($role, 'superadmin') !== false
        || strpos($role, 'administrador') !== false
        || strpos($role, 'admin') !== false
        || strpos($role, 'jefa') !== false
        || strpos($role, 'tecnico') !== false
        || strpos($role, 'analista') !== false
        || strpos($role, 'laboratorista') !== false
        || strpos($role, 'recepcion') !== false;
}

function lab_is_technician(): bool
{
    $user = lab_current_user();
    $roleId = (int) ($user['rol_id'] ?? 0);
    $role = lab_normalized_role();

    return $roleId === 3 || strpos($role, 'tecnico') !== false;
}

function lab_can_view_error_forms(): bool
{
    return lab_can('laboratorio.formularios_erroneos.ver') || lab_can('laboratorio.consolidacion.ver') || lab_is_technician();
}

function lab_has_module_access(string $module = 'Laboratorio'): bool
{
    $user = lab_current_user();

    if ($user['es_superadmin']) {
        return true;
    }

    $modules = lab_session_modules();
    if ($modules !== null && !empty($modules)) {
        return in_array(strtolower($module), $modules, true);
    }

    return lab_role_is_laboratory_related();
}

function lab_all_permissions(): array
{
    return [
        'laboratorio.acceder',
        'laboratorio.solicitudes.ver',
        'laboratorio.solicitudes.crear',
        'laboratorio.solicitudes.editar',
        'laboratorio.lotes.ver',
        'laboratorio.labc.ver',
        'laboratorio.formularios_labc.ver',
        'laboratorio.analisis.ver',
        'laboratorio.analisis.crear',
        'laboratorio.analisis.editar',
        'laboratorio.analisis.editar_finalizado',
        'laboratorio.blanco_control.ver',
        'laboratorio.blanco_control.gestionar',
        'laboratorio.consolidacion.ver',
        'laboratorio.consolidacion.aprobar',
        'laboratorio.formularios_pendientes.ver',
        'laboratorio.formularios_erroneos.ver',
        'laboratorio.formularios.guardar_corregidos',
        'laboratorio.formularios.guardar_errores',
        'laboratorio.usuarios.gestionar',
        'laboratorio.roles.gestionar',
        'laboratorio.configuracion.gestionar',
    ];
}

function lab_default_permissions_for_role(): array
{
    if (!lab_has_module_access()) {
        return [];
    }

    $user = lab_current_user();
    $roleId = (int) ($user['rol_id'] ?? 0);
    $role = lab_normalized_role();

    if (
        $user['es_superadmin']
        || $roleId === 1
        || strpos($role, 'superadmin') !== false
        || strpos($role, 'jefa') !== false
        || $roleId === 2
        || strpos($role, 'administrador') !== false
        || $role === 'admin'
    ) {
        return lab_all_permissions();
    }

    if ($roleId === 3 || strpos($role, 'tecnico') !== false) {
        return [
            'laboratorio.acceder',
            'laboratorio.labc.ver',
            'laboratorio.formularios_labc.ver',
            'laboratorio.analisis.ver',
            'laboratorio.analisis.crear',
            'laboratorio.analisis.editar',
            'laboratorio.formularios_pendientes.ver',
        ];
    }

    if ($roleId === 4 || strpos($role, 'analista') !== false || strpos($role, 'laboratorista') !== false) {
        return [
            'laboratorio.acceder',
            'laboratorio.labc.ver',
            'laboratorio.formularios_labc.ver',
            'laboratorio.analisis.ver',
            'laboratorio.analisis.crear',
            'laboratorio.analisis.editar',
        ];
    }

    if (strpos($role, 'recepcion') !== false) {
        return [
            'laboratorio.acceder',
            'laboratorio.solicitudes.ver',
            'laboratorio.solicitudes.crear',
            'laboratorio.solicitudes.editar',
            'laboratorio.lotes.ver',
            'laboratorio.labc.ver',
            'laboratorio.formularios_labc.ver',
            'laboratorio.analisis.ver',
            'laboratorio.consolidacion.ver',
            'laboratorio.consolidacion.aprobar',
            'laboratorio.formularios_erroneos.ver',
            'laboratorio.formularios.guardar_corregidos',
            'laboratorio.formularios.guardar_errores',
        ];
    }

    return ['laboratorio.acceder'];
}

function lab_user_permissions(): array
{
    $permissions = lab_session_permissions();

    if ($permissions !== null && !empty($permissions)) {
        foreach ($permissions as $permission) {
            if (strpos((string) $permission, 'laboratorio.') === 0) {
                return $permissions;
            }
        }

        return array_values(array_unique(array_merge($permissions, lab_default_permissions_for_role())));
    }

    return lab_default_permissions_for_role();
}

function lab_can(string $permission): bool
{
    $user = lab_current_user();

    if (!lab_is_authenticated() || !lab_has_module_access()) {
        return false;
    }

    if ($user['es_superadmin']) {
        return true;
    }

    return in_array(strtolower($permission), lab_user_permissions(), true);
}

function lab_analysis_assignments(): ?array
{
    return lab_string_list(lab_session_get([
        'analisis_permitidos',
        'formularios_permitidos',
        'laboratorio_analisis',
        'lab_analyses',
    ]));
}

function lab_can_analysis(string $analysisKey): bool
{
    if (!lab_can('laboratorio.formularios_labc.ver') && !lab_can('laboratorio.analisis.ver')) {
        return false;
    }

    $assigned = lab_analysis_assignments();
    if ($assigned === null || in_array('*', $assigned, true)) {
        return true;
    }

    return in_array(strtolower($analysisKey), $assigned, true);
}

function lab_base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $needle = '/Laboratorio';
    $pos = stripos($script, $needle);

    if ($pos === false) {
        return '';
    }

    return substr($script, 0, $pos + strlen($needle));
}

function lab_login_url(): string
{
    $url = getenv('LAB_AUTH_LOGIN_URL') ?: getenv('MAIN_LOGIN_URL');
    if ($url) {
        return $url;
    }

    $base = lab_base_path();
    $parent = $base ? rtrim(str_replace('\\', '/', dirname($base)), '/') : '';

    return ($parent === '' ? '' : $parent) . '/login.php';
}

function lab_logout_url(): string
{
    $url = getenv('LAB_AUTH_LOGOUT_URL') ?: getenv('MAIN_LOGOUT_URL');
    if ($url) {
        return $url;
    }

    $base = lab_base_path();
    $parent = $base ? rtrim(str_replace('\\', '/', dirname($base)), '/') : '';

    return ($parent === '' ? '' : $parent) . '/Pruebas/public/admin/logout.php';
}

function lab_redirect_to_login(): void
{
    $loginUrl = lab_login_url();
    $next = $_SERVER['REQUEST_URI'] ?? '';

    if ($next !== '') {
        $separator = strpos($loginUrl, '?') === false ? '?' : '&';
        $loginUrl .= $separator . 'next=' . urlencode($next);
    }

    header('Location: ' . $loginUrl);
    exit;
}
    
function lab_forbidden(string $message = 'No tiene permisos para acceder a este recurso.'): void
{
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso denegado</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f6f8f3;color:#1f2a1f;padding:40px}';
    echo '.box{max-width:560px;margin:auto;background:#fff;border:1px solid #dfe7d8;border-radius:8px;padding:24px}';
    echo 'a{color:#2c6b2f}</style></head><body><div class="box">';
    echo '<h1>Acceso denegado</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<a href="' . htmlspecialchars(lab_login_url(), ENT_QUOTES, 'UTF-8') . '">Volver al inicio de sesion</a>';
    echo '</div></body></html>';
    exit;
}

function lab_require_login(): void
{
    if (!lab_is_authenticated()) {
        lab_redirect_to_login();
    }
}

function lab_require_module_access(): void
{
    lab_require_login();

    if (!lab_has_module_access()) {
        lab_forbidden('Su usuario no tiene acceso al modulo Laboratorio.');
    }
}

function lab_require_permission(string $permission): void
{
    lab_require_module_access();

    if (!lab_can($permission)) {
        lab_forbidden();
    }
}

function lab_require_analysis_access(string $analysisKey): void
{
    lab_require_module_access();

    if (!lab_can_analysis($analysisKey)) {
        lab_forbidden('No tiene permisos para acceder a este formulario de analisis.');
    }
}

function lab_can_any(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (lab_can($permission)) {
            return true;
        }
    }

    return false;
}

