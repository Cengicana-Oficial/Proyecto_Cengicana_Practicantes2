<?php
declare(strict_types=1);

// Mismo patron de autenticacion/autorizacion que
// sistema_de_solicitudes/includes/functions.php, adaptado al modulo
// Comites, Transferencia y Comunicacion.

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalize_text(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }

    return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
}

function current_module_names(): array
{
    return [
        'comites, transferencia y comunicacion',
    ];
}

function module_ids(PDO $menuPdo): array
{
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $placeholders = implode(',', array_fill(0, count(current_module_names()), '?'));
    $stmt = $menuPdo->prepare(
        "SELECT id
         FROM modulos
         WHERE LOWER(nombre) IN ({$placeholders})"
    );
    $stmt->execute(current_module_names());

    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $ids;
}

function refresh_session_permissions(PDO $menuPdo, int $roleId): array
{
    $stmt = $menuPdo->prepare(
        'SELECT p.nombre_permiso
         FROM rol_permiso rp
         INNER JOIN permisos p ON p.id = rp.permiso_id
         WHERE rp.rol_id = ?'
    );
    $stmt->execute([$roleId]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['user_permissions'] = $permissions;

    return $permissions;
}

function current_user(PDO $menuPdo, PDO $pdo): array
{
    $id = (int) ($_SESSION['id_usuario'] ?? 0);
    if ($id <= 0) {
        return [];
    }

    $stmt = $menuPdo->prepare(
        'SELECT u.id, u.nombre, u.correo, u.rol_id, u.es_superadmin, r.nombre_rol
         FROM usuarios u
         INNER JOIN roles r ON r.id = u.rol_id
         WHERE u.id = ?'
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        return [];
    }

    $permissions = refresh_session_permissions($menuPdo, (int) $user['rol_id']);
    $moduleStmt = $menuPdo->prepare('SELECT modulo_id FROM usuario_modulo WHERE usuario_id = ?');
    $moduleStmt->execute([$id]);

    $user['modulo_ids'] = array_map('intval', $moduleStmt->fetchAll(PDO::FETCH_COLUMN));
    $user['permissions'] = $permissions;

    return $user;
}

function user_has_module_access(array $user, PDO $menuPdo): bool
{
    if (is_superadmin($user)) {
        return true;
    }

    return count(array_intersect($user['modulo_ids'] ?? [], module_ids($menuPdo))) > 0;
}

function is_superadmin(array $user): bool
{
    return (int) ($user['es_superadmin'] ?? 0) === 1;
}

function has_permission(array $user, string $permission): bool
{
    if (is_superadmin($user)) {
        return true;
    }

    return in_array($permission, $user['permissions'] ?? [], true);
}

function can_view_resource(array $user, string $recurso): bool
{
    return has_permission($user, "comites.{$recurso}.ver") || has_permission($user, "comites.{$recurso}.gestionar");
}

function can_manage_resource(array $user, string $recurso): bool
{
    return has_permission($user, "comites.{$recurso}.gestionar");
}

function role_label(string $roleName): string
{
    return ucwords(trim($roleName));
}

function fetch_module_users(PDO $menuPdo, array $user): array
{
    $moduleIds = module_ids($menuPdo);
    if (!$moduleIds) {
        return [];
    }

    $modulePlaceholders = implode(',', array_fill(0, count($moduleIds), '?'));

    $sql = "
        SELECT
            u.id,
            u.nombre,
            u.correo,
            u.rol_id,
            u.es_superadmin,
            u.ingenio_id,
            r.nombre_rol,
            i.nombre_ingenio AS ingenio
        FROM usuarios u
        INNER JOIN roles r ON r.id = u.rol_id
        LEFT JOIN ingenios i ON i.id = u.ingenio_id
        WHERE EXISTS (
            SELECT 1
            FROM usuario_modulo um
            WHERE um.usuario_id = u.id
              AND um.modulo_id IN ({$modulePlaceholders})
        )
        ORDER BY u.nombre
    ";

    $stmt = $menuPdo->prepare($sql);
    $stmt->execute($moduleIds);

    return $stmt->fetchAll();
}

function comite_options(PDO $pdo): array
{
    return $pdo->query('SELECT id, nombre FROM comites WHERE activo = 1 ORDER BY nombre')->fetchAll();
}
