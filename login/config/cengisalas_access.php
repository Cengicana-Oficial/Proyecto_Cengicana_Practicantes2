<?php
declare(strict_types=1);

function cengisalas_roles(): array
{
    return [
        'salas' => ['admin' => 'Administrador de CengiSalas', 'user' => 'Usuario estándar', 'manager' => 'Gestor'],
        'visitas' => [
            'asistente_direccion' => 'Asistente Dirección',
            'director_general' => 'Director General',
            'coordinador_visitas' => 'Coordinador de Visitas',
            'gestor_pagos' => 'Gestor de Pagos',
        ],
    ];
}

function cengisalas_module_name(string $code): string
{
    return ['salas' => 'CengiSalas', 'visitas' => 'Visitas'][$code] ?? '';
}

function cengisalas_inherited_salas_role(PDO $db, int $roleId): ?string
{
    if ($roleId <= 0) return null;
    $stmt = $db->prepare("SELECT p.nombre_permiso FROM rol_permiso rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=? AND p.nombre_permiso IN ('cengisalas.salas.admin','cengisalas.salas.user','cengisalas.salas.manager')");
    $stmt->execute([$roleId]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($permissions) !== 1) return null;
    return ['cengisalas.salas.admin' => 'admin', 'cengisalas.salas.user' => 'user', 'cengisalas.salas.manager' => 'manager'][$permissions[0]] ?? null;
}

function cengisalas_inherited_salas_role_for_user(PDO $db, int $userId): ?string
{
    $stmt = $db->prepare('SELECT rol_id FROM usuarios WHERE id=?');
    $stmt->execute([$userId]);
    return cengisalas_inherited_salas_role($db, (int) $stmt->fetchColumn());
}

function cengisalas_user_permission_overrides(PDO $db, int $userId): array
{
    $stmt = $db->prepare("SELECT permission_code,allowed FROM cengisalas_permission_overrides WHERE usuario_id=? AND module_code='salas'");
    $stmt->execute([$userId]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array($row['permission_code'], ['ver_solicitudes_pago','ver_reportes'], true)) {
            $result[$row['permission_code']] = (bool) $row['allowed'];
        }
    }
    return $result;
}

function cengisalas_role(PDO $db, int $userId, string $module): ?string
{
    if (!isset(cengisalas_roles()[$module])) {
        return null;
    }
    $stmt = $db->prepare('SELECT role_code FROM cengisalas_module_access WHERE usuario_id = ? AND module_code = ?');
    $stmt->execute([$userId, $module]);
    $role = $stmt->fetchColumn();
    return $role !== false ? ($module === 'visitas' ? visitas_role_code((string) $role) : (string) $role) : null;
}

function cengisalas_user_access(PDO $db, int $userId, bool $superadmin): array
{
    if ($superadmin) {
        return ['salas' => 'admin', 'visitas' => 'coordinador_visitas'];
    }
    $stmt = $db->prepare('SELECT module_code, role_code FROM cengisalas_module_access WHERE usuario_id = ?');
    $stmt->execute([$userId]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['module_code'] === 'visitas') $row['role_code'] = visitas_role_code((string) $row['role_code']);
        if (isset(cengisalas_roles()[$row['module_code']][$row['role_code']])) {
            $result[$row['module_code']] = $row['role_code'];
        }
    }
    return $result;
}

// Compatibilidad durante la migración de asignaciones anteriores.
function visitas_role_code(string $role): string
{
    return ['admin' => 'coordinador_visitas', 'user' => 'asistente_direccion', 'payments' => 'gestor_pagos'][$role] ?? $role;
}
