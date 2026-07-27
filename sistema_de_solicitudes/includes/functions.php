<?php
declare(strict_types=1);

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
        'solicitudes internas',
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

    $programStmt = $pdo->prepare(
        'SELECT up.programa_id, p.nombre AS programa
         FROM usuario_programa up
         INNER JOIN programas p ON p.id = up.programa_id
         WHERE up.usuario_id = ?'
    );
    $programStmt->execute([$id]);
    $program = $programStmt->fetch() ?: ['programa_id' => null, 'programa' => null];

    $user['modulo_ids'] = array_map('intval', $moduleStmt->fetchAll(PDO::FETCH_COLUMN));
    $user['programa_id'] = $program['programa_id'] !== null ? (int) $program['programa_id'] : null;
    $user['programa'] = $program['programa'];
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

function can_view_requests(array $user): bool
{
    return has_permission($user, 'solicitudes_internas.ver') || has_permission($user, 'solicitudes_internas.gestionar');
}

function can_view_received_requests(array $user): bool
{
    if (is_superadmin($user)) {
        return true;
    }

    return can_view_requests($user) && !empty($user['programa_id']);
}

function can_manage_requests(array $user): bool
{
    return has_permission($user, 'solicitudes_internas.gestionar');
}

function can_view_users(array $user): bool
{
    return has_permission($user, 'solicitudes_internas.usuarios.ver') || has_permission($user, 'solicitudes_internas.usuarios.gestionar');
}

function can_manage_users(array $user): bool
{
    return has_permission($user, 'solicitudes_internas.usuarios.gestionar');
}

function can_view_programs(array $user): bool
{
    return has_permission($user, 'solicitudes_internas.programas.ver') || has_permission($user, 'solicitudes_internas.programas.gestionar');
}

function can_manage_programs(array $user): bool
{
    return has_permission($user, 'solicitudes_internas.programas.gestionar');
}

function can_create_requests(array $user): bool
{
    if (is_superadmin($user)) {
        return true;
    }

    return has_permission($user, 'solicitudes_internas.crear') && !empty($user['programa_id']);
}

function role_is_superadmin(string $roleName): bool
{
    return normalize_text($roleName) === 'superadmin';
}

function role_label(string $roleName): string
{
    return ucwords(trim($roleName));
}

function badge_estado(string $estado): string
{
    $labels = [
        'recibido' => 'Recibido',
        'proceso' => 'En proceso',
        'completado' => 'Completado',
        'rechazado' => 'Rechazado',
    ];

    return '<span class="badge badge-' . e($estado) . '">' . e($labels[$estado] ?? $estado) . '</span>';
}

function badge_tipo(string $tipo): string
{
    $labels = [
        'compra' => 'Compra',
        'ti' => 'Soporte TI',
        'apoyo' => 'Apoyo',
    ];

    return '<span class="badge badge-tipo-' . e($tipo) . '">' . e($labels[$tipo] ?? $tipo) . '</span>';
}

function badge_prioridad(string $prioridad): string
{
    $labels = [
        'baja' => 'Baja',
        'media' => 'Media',
        'alta' => 'Alta',
    ];

    return '<span class="badge badge-priority-' . e($prioridad) . '">' . e($labels[$prioridad] ?? $prioridad) . '</span>';
}

function estado_label(string $estado): string
{
    return [
        'recibido' => 'Recibido',
        'proceso' => 'En proceso',
        'completado' => 'Completado',
        'rechazado' => 'Rechazado',
    ][$estado] ?? $estado;
}

function next_codigo(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare('SELECT COUNT(*) + 1 FROM solicitudes WHERE codigo LIKE ?');
    $stmt->execute(["SOL-{$year}-%"]);

    return 'SOL-' . $year . '-' . str_pad((string) $stmt->fetchColumn(), 3, '0', STR_PAD_LEFT);
}

function find_program_id_by_name(array $programs, string $name): ?int
{
    $normalized = normalize_text($name);

    foreach ($programs as $program) {
        if (normalize_text((string) $program['nombre']) === $normalized) {
            return (int) $program['id'];
        }
    }

    return null;
}

function can_view_request(array $user, array $request): bool
{
    if (is_superadmin($user)) {
        return true;
    }

    if ((int) $request['solicitante_id'] === (int) $user['id']) {
        return true;
    }

    return can_view_requests($user)
        && !empty($user['programa_id'])
        && (int) ($request['programa_destino_id'] ?? 0) === (int) $user['programa_id'];
}

function can_manage_request(array $user, array $request): bool
{
    if (is_superadmin($user)) {
        return true;
    }

    return can_manage_requests($user)
        && !empty($user['programa_id'])
        && (int) ($request['programa_destino_id'] ?? 0) === (int) $user['programa_id'];
}

function request_scope_sql(array $user, string $alias = 's'): array
{
    if (is_superadmin($user)) {
        return ['1 = 1', []];
    }

    if (!empty($user['programa_id']) && can_view_requests($user)) {
        return [
            "({$alias}.solicitante_id = ? OR {$alias}.programa_destino_id = ?)",
            [(int) $user['id'], (int) $user['programa_id']],
        ];
    }

    return ["{$alias}.solicitante_id = ?", [(int) $user['id']]];
}

function sent_scope_sql(array $user, string $alias = 's'): array
{
    if (is_superadmin($user)) {
        return ['1 = 1', []];
    }

    return ["{$alias}.solicitante_id = ?", [(int) $user['id']]];
}

function received_scope_sql(array $user, string $alias = 's'): array
{
    if (is_superadmin($user)) {
        return ['1 = 1', []];
    }

    if (can_view_received_requests($user)) {
        return ["{$alias}.programa_destino_id = ?", [(int) $user['programa_id']]];
    }

    return ['1 = 0', []];
}

function management_scope_sql(array $user, string $alias = 's'): array
{
    if (is_superadmin($user)) {
        return ['1 = 1', []];
    }

    if (!empty($user['programa_id']) && can_manage_requests($user)) {
        return ["{$alias}.programa_destino_id = ?", [(int) $user['programa_id']]];
    }

    return ['1 = 0', []];
}

function fetch_module_users(PDO $menuPdo, PDO $pdo, array $user): array
{
    $moduleIds = module_ids($menuPdo);
    if (!$moduleIds) {
        return [];
    }

    $params = [];
    $where = [];
    $modulePlaceholders = implode(',', array_fill(0, count($moduleIds), '?'));

    $where[] = "EXISTS (
        SELECT 1
        FROM usuario_modulo um
        WHERE um.usuario_id = u.id
          AND um.modulo_id IN ({$modulePlaceholders})
    )";
    $params = array_merge($params, $moduleIds);

    $sql = "
        SELECT
            u.id,
            u.nombre,
            u.correo,
            u.rol_id,
            u.es_superadmin,
            u.ingenio_id,
            r.nombre_rol,
            i.nombre_ingenio AS ingenio,
            up.programa_id,
            p.nombre AS programa,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM usuario_modulo ums
                    WHERE ums.usuario_id = u.id
                      AND ums.modulo_id IN ({$modulePlaceholders})
                ) THEN 1 ELSE 0
            END AS tiene_modulo
        FROM usuarios u
        INNER JOIN roles r ON r.id = u.rol_id
        LEFT JOIN ingenios i ON i.id = u.ingenio_id
        LEFT JOIN " . $pdo->query('SELECT DATABASE()')->fetchColumn() . ".usuario_programa up ON up.usuario_id = u.id
        LEFT JOIN " . $pdo->query('SELECT DATABASE()')->fetchColumn() . ".programas p ON p.id = up.programa_id
    ";

    $params = array_merge($moduleIds, $params);

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY u.nombre';

    $stmt = $menuPdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function render_table(array $rows, string $id, array $user, bool $management = false): void
{
    ?>
    <div class="table-card">
        <div class="table-wrap">
            <table id="<?= e($id) ?>">
                <thead>
                <tr>
                    <th>Codigo</th>
                    <?php if ($management): ?><th>Solicitante</th><?php endif; ?>
                    <th>Tipo</th>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th>Titulo</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Accion</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $management ? 9 : 8 ?>">No hay solicitudes para mostrar.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr data-estado="<?= e($row['estado']) ?>">
                        <td><strong><?= e($row['codigo']) ?></strong></td>
                        <?php if ($management): ?><td><?= e($row['solicitante']) ?></td><?php endif; ?>
                        <td><?= badge_tipo($row['tipo']) ?></td>
                        <td><?= e($row['programa_origen']) ?></td>
                        <td><?= e($row['programa_destino'] ?: 'No aplica') ?></td>
                        <td><?= e($row['titulo']) ?></td>
                        <td><?= e(date('d/m/Y', strtotime($row['creado_en']))) ?></td>
                        <td><?= badge_estado($row['estado']) ?></td>
                        <td><a class="btn-outline btn-sm" href="index.php?view=detalle&id=<?= (int) $row['id'] ?>">Ver detalle</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
