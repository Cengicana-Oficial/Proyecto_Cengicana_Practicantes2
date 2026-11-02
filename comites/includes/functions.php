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

    $stmt = $menuPdo->prepare('SELECT u.id, u.nombre, u.correo, u.es_superadmin FROM usuarios u WHERE u.id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        return [];
    }

    // Rol y permisos del usuario EN ESTE MODULO (login/config/roles_modulo.php): el rol que
    // tenga en otros modulos no aplica aqui.
    require_once __DIR__ . '/../../login/config/roles_modulo.php';
    $rolModulo = cengi_rm_activar_modulo($menuPdo, current_module_names()[0]);
    $user['rol_id'] = $rolModulo['rol_id'] ?? 0;
    $user['nombre_rol'] = $rolModulo['nombre_rol'] ?? '';
    $permissions = $_SESSION['user_permissions'];
    $moduleStmt = $menuPdo->prepare('SELECT modulo_id FROM usuario_modulo WHERE usuario_id = ?');
    $moduleStmt->execute([$id]);

    $user['modulo_ids'] = array_map('intval', $moduleStmt->fetchAll(PDO::FETCH_COLUMN));
    $programa = comites_programa_usuario($menuPdo, $id);
    $user['programa_id'] = $programa['id'] ?? null;
    $user['programa'] = $programa['nombre'] ?? null;
    $derivados = comites_permisos_por_programa((string) $user['nombre_rol'], $user['programa']);
    $user['perfil_comites'] = $derivados['perfil'];
    $user['permissions'] = array_values(array_unique(array_merge($permissions, $derivados['permisos'])));

    return $user;
}

/** Base de datos de Solicitudes internas, donde viven los programas compartidos de la plataforma. */
function comites_programas_db(): string
{
    $nombre = app_env_value('DB_SOLICITUDES_NAME', app_env_value('SISTEMA_SOLICITUDES_DB_NAME', 'sistema_solicitudes'));
    return '`' . str_replace('`', '``', $nombre) . '`';
}

/** Programa asignado al usuario (el mismo que usa Solicitudes internas), o null. */
function comites_programa_usuario(PDO $menuPdo, int $usuarioId): ?array
{
    try {
        $db = comites_programas_db();
        $stmt = $menuPdo->prepare("SELECT p.id, p.nombre FROM {$db}.usuario_programa up JOIN {$db}.programas p ON p.id = up.programa_id WHERE up.usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $fila = $stmt->fetch();
        return $fila ? ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre']] : null;
    } catch (PDOException $e) {
        return null; // Solicitudes internas aun no creo sus tablas.
    }
}

/**
 * Permisos que se derivan del rol + programa. El Jefe de programa de
 * Transferencia de Tecnologia es quien administra el modulo (Jefe de
 * Transferencia): gestiona todas las secciones y hace las revisiones de
 * Transferencia. La gestion de usuarios queda para el superadmin o para
 * quien tenga el permiso explicito.
 */
function comites_permisos_por_programa(string $rol, ?string $programa): array
{
    $rol = normalize_text($rol);
    if ($programa !== null && in_array($rol, ['jefe de programa', 'jefe de transferencia'], true) && str_contains(normalize_text($programa), 'transferencia')) {
        $permisos = ['comites.documentos.revisar_transferencia', 'comites.memorias.aprobar_transferencia', 'comites.memorias.ver_todos', 'comites.memorias.entregar', 'comites.usuarios.ver'];
        foreach (['contactos', 'reuniones', 'eventos', 'acuerdos', 'documentos', 'biblioteca_interna', 'correos', 'tableros', 'informes', 'memorias', 'publicaciones', 'estadisticas', 'configuracion'] as $seccion) {
            $permisos[] = "comites.$seccion.ver";
            $permisos[] = "comites.$seccion.gestionar";
        }
        return ['perfil' => 'Jefe de Transferencia', 'permisos' => $permisos];
    }
    if ($rol === 'director' && $programa !== null && str_contains(normalize_text($programa), 'direccion')) {
        return ['perfil' => 'Director General', 'permisos' => []];
    }
    return ['perfil' => null, 'permisos' => []];
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
            um.rol_id,
            u.es_superadmin,
            u.ingenio_id,
            COALESCE(r.nombre_rol, '') AS nombre_rol,
            i.nombre_ingenio AS ingenio
        FROM usuarios u
        INNER JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id IN ({$modulePlaceholders})
        LEFT JOIN roles r ON r.id = um.rol_id -- rol del usuario en este modulo
        LEFT JOIN ingenios i ON i.id = u.ingenio_id
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
