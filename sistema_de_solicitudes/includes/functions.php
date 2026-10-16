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

/*
 * Roles del modulo Solicitudes internas
 * -------------------------------------
 * Los roles son roles de la plataforma (login/roles/roles.php); este modulo solo
 * lee los permisos `solicitudes_internas.*` del rol asignado al usuario:
 *
 * - superadmin: bandera global `usuarios.es_superadmin`. Ve y gestiona todo.
 * - director:   rol con `solicitudes_internas.ver_todas` (p. ej. "Director"). Ve,
 *               en solo lectura, todas las solicitudes entre programas; solo
 *               gestiona las dirigidas a su programa (Direccion).
 * - jefe:       rol con `solicitudes_internas.ver` / `.gestionar` (p. ej. "Jefe de
 *               programa"). Ve y gestiona las solicitudes que otros programas
 *               envian a SU programa, y ve las que el mismo envio. Nada mas.
 * - colaborador: rol con `solicitudes_internas.crear` (p. ej. "Colaborador de
 *               programa"). Crea solicitudes y solo ve las suyas.
 * - ninguno:    su rol no tiene permisos de este modulo.
 *
 * El programa del usuario se guarda en `usuario_programa` (base local).
 */
const MODULE_ROLE_LABELS = [
    'superadmin' => 'Superadmin',
    'director' => 'Director',
    'jefe' => 'Jefe de programa',
    'colaborador' => 'Colaborador',
    'ninguno' => 'Sin permisos en el modulo',
];

/** Nombre del programa que debe tener asignado un director. */
const DIRECTOR_PROGRAM_NAME = 'Direccion';

function module_role(array $user): string
{
    if (is_superadmin($user)) {
        return 'superadmin';
    }
    if (has_permission($user, 'solicitudes_internas.ver_todas')) {
        return 'director';
    }
    if (has_permission($user, 'solicitudes_internas.ver') || has_permission($user, 'solicitudes_internas.gestionar')) {
        return 'jefe';
    }
    if (has_permission($user, 'solicitudes_internas.crear')) {
        return 'colaborador';
    }

    return 'ninguno';
}

function module_role_label(string $role): string
{
    return MODULE_ROLE_LABELS[$role] ?? MODULE_ROLE_LABELS['ninguno'];
}

/** Jefe o director con programa: recibe y gestiona lo que llega a su programa. */
function is_program_head(array $user): bool
{
    return in_array(module_role($user), ['jefe', 'director'], true) && !empty($user['programa_id']);
}

/** Superadmin o director: ve todas las solicitudes (el director en solo lectura). */
function can_view_all_requests(array $user): bool
{
    return is_superadmin($user) || has_permission($user, 'solicitudes_internas.ver_todas');
}

function can_view_received_requests(array $user): bool
{
    return can_view_all_requests($user) || is_program_head($user);
}

function can_manage_requests(array $user): bool
{
    return is_superadmin($user)
        || (has_permission($user, 'solicitudes_internas.gestionar') && !empty($user['programa_id']));
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

/**
 * Roles de la plataforma que se pueden asignar desde este modulo: los que tienen
 * permisos de solicitudes (crear/ver/gestionar). Nunca incluye Superadmin.
 * Devuelve [rol_id => ['nombre' => string, 'modulo' => 'director'|'jefe'|'colaborador']].
 */
function assignable_module_roles(PDO $menuPdo): array
{
    $rows = $menuPdo->query(
        "SELECT r.id, r.nombre_rol,
                SUM(pe.nombre_permiso = 'solicitudes_internas.ver_todas') AS es_director,
                SUM(pe.nombre_permiso IN ('solicitudes_internas.ver', 'solicitudes_internas.gestionar')) AS es_jefe
         FROM roles r
         INNER JOIN rol_permiso rp ON rp.rol_id = r.id
         INNER JOIN permisos pe ON pe.id = rp.permiso_id
         WHERE pe.nombre_permiso IN ('solicitudes_internas.crear', 'solicitudes_internas.ver', 'solicitudes_internas.gestionar', 'solicitudes_internas.ver_todas')
           AND r.id <> 1
           AND LOWER(r.nombre_rol) <> 'superadmin'
         GROUP BY r.id, r.nombre_rol
         ORDER BY es_director DESC, es_jefe DESC, r.nombre_rol"
    )->fetchAll();

    $roles = [];
    foreach ($rows as $row) {
        $kind = 'colaborador';
        if ((int) $row['es_director'] > 0) {
            $kind = 'director';
        } elseif ((int) $row['es_jefe'] > 0) {
            $kind = 'jefe';
        }
        $roles[(int) $row['id']] = ['nombre' => $row['nombre_rol'], 'modulo' => $kind];
    }

    return $roles;
}

/**
 * Asigna el rol de plataforma enviado en el formulario (campo rol_id). Vacio = no cambiar.
 * Valida que sea un rol del modulo, que un jefe tenga programa y que un director
 * tenga asignado el programa Direccion ($directionProgramId).
 */
function apply_posted_platform_role(PDO $menuPdo, int $userId, int $programId, ?int $directionProgramId): void
{
    $roleId = (int) ($_POST['rol_id'] ?? 0);
    $roles = assignable_module_roles($menuPdo);

    if ($roleId > 0 && !isset($roles[$roleId])) {
        throw new RuntimeException('El rol seleccionado no tiene permisos de Solicitudes internas.');
    }

    $targetStmt = $menuPdo->prepare('SELECT es_superadmin FROM usuarios WHERE id = ?');
    $targetStmt->execute([$userId]);
    $target = $targetStmt->fetch();
    if (!$target) {
        throw new RuntimeException('El usuario seleccionado no existe.');
    }
    // Rol actual EN ESTE MODULO (login/config/roles_modulo.php), no el de otros modulos.
    require_once __DIR__ . '/../../login/config/roles_modulo.php';
    $target['rol_id'] = (int) (cengi_rm_rol_en_modulo($menuPdo, $userId, current_module_names()[0])['rol_id'] ?? 0);

    $effectiveRoleId = $roleId > 0 ? $roleId : (int) $target['rol_id'];
    $effectiveKind = $roles[$effectiveRoleId]['modulo'] ?? null;
    if ($effectiveKind === 'director' && (int) $target['es_superadmin'] !== 1 && ($directionProgramId === null || $programId !== $directionProgramId)) {
        throw new RuntimeException('Un director debe tener asignado el programa ' . DIRECTOR_PROGRAM_NAME . ': solo gestiona las solicitudes dirigidas a ese programa.');
    }
    if ($effectiveKind === 'jefe' && $programId <= 0) {
        throw new RuntimeException('Un jefe de programa necesita tener un programa asignado.');
    }

    if ($roleId <= 0 || $roleId === (int) $target['rol_id']) {
        return;
    }
    if ((int) $target['es_superadmin'] === 1) {
        throw new RuntimeException('El rol de un superadmin solo se cambia desde la gestion de usuarios de la plataforma.');
    }

    // Solo cambia su rol en Solicitudes internas; sus roles en otros modulos no se tocan.
    foreach (module_ids($menuPdo) as $moduloId) {
        cengi_rm_asignar($menuPdo, $userId, $moduloId, $roleId);
    }
}

/** Guarda el programa del usuario en el modulo; sin programa se elimina la asignacion. */
function save_user_program(PDO $pdo, int $userId, int $programId): void
{
    if ($programId <= 0) {
        $pdo->prepare('DELETE FROM usuario_programa WHERE usuario_id = ?')->execute([$userId]);
        return;
    }

    $pdo->prepare(
        'INSERT INTO usuario_programa (usuario_id, programa_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE programa_id = VALUES(programa_id)'
    )->execute([$userId, $programId]);
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
    if (can_view_all_requests($user)) {
        return true;
    }

    if ((int) $request['solicitante_id'] === (int) $user['id']) {
        return true;
    }

    return is_program_head($user)
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
    if (can_view_all_requests($user)) {
        return ['1 = 1', []];
    }

    if (is_program_head($user)) {
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
    if (can_view_all_requests($user)) {
        return ['1 = 1', []];
    }

    if (can_view_received_requests($user)) {
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

    $modulePlaceholders = implode(',', array_fill(0, count($moduleIds), '?'));
    $params = $moduleIds;

    // El rol y los permisos que se muestran son los del usuario EN ESTE MODULO
    // (usuario_modulo.rol_id), no su rol en otros modulos.
    $sql = "
        SELECT
            u.id,
            u.nombre,
            u.correo,
            um.rol_id,
            u.es_superadmin,
            u.ingenio_id,
            COALESCE(r.nombre_rol, '') AS nombre_rol,
            i.nombre_ingenio AS ingenio,
            up.programa_id,
            (
                SELECT GROUP_CONCAT(pe.nombre_permiso)
                FROM rol_permiso rp
                INNER JOIN permisos pe ON pe.id = rp.permiso_id
                WHERE rp.rol_id = um.rol_id
                  AND pe.nombre_permiso LIKE 'solicitudes\_internas.%'
            ) AS permisos_solicitudes,
            p.nombre AS programa,
            1 AS tiene_modulo
        FROM usuarios u
        INNER JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id IN ({$modulePlaceholders})
        LEFT JOIN roles r ON r.id = um.rol_id
        LEFT JOIN ingenios i ON i.id = u.ingenio_id
        LEFT JOIN " . $pdo->query('SELECT DATABASE()')->fetchColumn() . ".usuario_programa up ON up.usuario_id = u.id
        LEFT JOIN " . $pdo->query('SELECT DATABASE()')->fetchColumn() . ".programas p ON p.id = up.programa_id
    ";

    $sql .= ' ORDER BY u.nombre';

    $stmt = $menuPdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        // Permite usar has_permission()/module_role() sobre cada usuario listado.
        $row['permissions'] = array_filter(explode(',', (string) $row['permisos_solicitudes']));
    }
    unset($row);

    return $rows;
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

/*
 * Notificaciones (campanita)
 * --------------------------
 * Cada registro de `seguimientos` es un evento. Un usuario ve los eventos que
 * hicieron OTROS usuarios sobre:
 *  - las solicitudes que el envio (cambios de estado / seguimiento);
 *  - jefe de programa: las solicitudes que recibe su programa;
 *  - superadmin: la creacion de cualquier solicitud nueva.
 * Las lecturas se guardan en `notificaciones_leidas`.
 */
const NOTIFICATION_DAYS = 30;

function notification_scope_sql(array $user): array
{
    $conditions = ['s.solicitante_id = ?'];
    $params = [(int) $user['id']];

    if (is_superadmin($user)) {
        $conditions[] = 'sg.id = (SELECT MIN(f.id) FROM seguimientos f WHERE f.solicitud_id = s.id)';
    } elseif (is_program_head($user)) {
        $conditions[] = 's.programa_destino_id = ?';
        $params[] = (int) $user['programa_id'];
    }

    $where = 'sg.usuario_id <> ?
        AND sg.creado_en >= NOW() - INTERVAL ' . NOTIFICATION_DAYS . ' DAY
        AND (' . implode(' OR ', $conditions) . ')';

    return [$where, array_merge([(int) $user['id']], $params)];
}

function fetch_notifications(PDO $pdo, string $menuDbName, array $user, int $limit = 15): array
{
    [$where, $params] = notification_scope_sql($user);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM seguimientos sg
         INNER JOIN solicitudes s ON s.id = sg.solicitud_id
         WHERE {$where}
           AND NOT EXISTS (
               SELECT 1 FROM notificaciones_leidas nl
               WHERE nl.usuario_id = ? AND nl.seguimiento_id = sg.id
           )"
    );
    $countStmt->execute(array_merge($params, [(int) $user['id']]));

    $listStmt = $pdo->prepare(
        "SELECT sg.id, sg.estado, sg.observacion, sg.creado_en,
                s.id AS solicitud_id, s.codigo, s.titulo, s.solicitante_id,
                po.nombre AS programa_origen,
                u.nombre AS autor,
                (sg.id = (SELECT MIN(f.id) FROM seguimientos f WHERE f.solicitud_id = s.id)) AS es_nueva,
                EXISTS (
                    SELECT 1 FROM notificaciones_leidas nl
                    WHERE nl.usuario_id = ? AND nl.seguimiento_id = sg.id
                ) AS leida
         FROM seguimientos sg
         INNER JOIN solicitudes s ON s.id = sg.solicitud_id
         INNER JOIN programas po ON po.id = s.programa_origen_id
         LEFT JOIN {$menuDbName}.usuarios u ON u.id = sg.usuario_id
         WHERE {$where}
         ORDER BY sg.creado_en DESC, sg.id DESC
         LIMIT " . max(1, $limit)
    );
    $listStmt->execute(array_merge([(int) $user['id']], $params));

    return [
        'unread' => (int) $countStmt->fetchColumn(),
        'items' => $listStmt->fetchAll(),
    ];
}

/** Marca como leidas las notificaciones del usuario (todas, o solo las de una solicitud). */
function mark_notifications_read(PDO $pdo, array $user, ?int $requestId = null): void
{
    [$where, $params] = notification_scope_sql($user);
    $sql = "INSERT IGNORE INTO notificaciones_leidas (usuario_id, seguimiento_id)
            SELECT ?, sg.id
            FROM seguimientos sg
            INNER JOIN solicitudes s ON s.id = sg.solicitud_id
            WHERE {$where}";
    $params = array_merge([(int) $user['id']], $params);

    if ($requestId !== null) {
        $sql .= ' AND s.id = ?';
        $params[] = $requestId;
    }

    $pdo->prepare($sql)->execute($params);
}

/** Texto e icono de una notificacion para el usuario que la ve. */
function notification_view(array $item, array $user): array
{
    $author = $item['autor'] ?: 'Un usuario';

    if ((int) $item['es_nueva'] === 1) {
        return [
            'icon' => 'ti-inbox',
            'tone' => 'nueva',
            'text' => "Nueva solicitud {$item['codigo']} de {$item['programa_origen']}: {$item['titulo']}",
        ];
    }

    $status = estado_label((string) $item['estado']);
    $icons = ['recibido' => 'ti-mail', 'proceso' => 'ti-loader-2', 'completado' => 'ti-circle-check', 'rechazado' => 'ti-circle-x'];
    $text = (int) $item['solicitante_id'] === (int) $user['id']
        ? "Tu solicitud {$item['codigo']} paso a \"{$status}\" ({$author})"
        : "{$author} actualizo {$item['codigo']} a \"{$status}\"";

    return [
        'icon' => $icons[$item['estado']] ?? 'ti-bell',
        'tone' => (string) $item['estado'],
        'text' => $text,
    ];
}

function time_ago(string $datetime): string
{
    $seconds = max(0, time() - strtotime($datetime));
    if ($seconds < 60) {
        return 'hace un momento';
    }
    if ($seconds < 3600) {
        $minutes = intdiv($seconds, 60);
        return "hace {$minutes} min";
    }
    if ($seconds < 86400) {
        $hours = intdiv($seconds, 3600);
        return $hours === 1 ? 'hace 1 hora' : "hace {$hours} horas";
    }
    $days = intdiv($seconds, 86400);
    if ($days < 7) {
        return $days === 1 ? 'ayer' : "hace {$days} dias";
    }

    return date('d/m/Y', strtotime($datetime));
}

/**
 * Tipos de adjunto permitidos: extension => tipos MIME reales aceptados (finfo). Los de
 * Office antiguos (doc/xls) se detectan como CDFV2; los OOXML (docx/xlsx) a veces como zip.
 */
const ADJUNTOS_TIPOS = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
    'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
];
const ADJUNTOS_MAX_BYTES = 20 * 1024 * 1024;

/**
 * Valida los archivos de un input multiple ($_FILES['campo']) antes de guardar nada.
 * Devuelve [['tmp', 'nombre', 'extension', 'mime', 'tamano'], ...] o lanza RuntimeException.
 */
function validar_adjuntos(?array $files): array
{
    if (!$files || empty($files['name'][0])) {
        return [];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $validos = [];
    foreach ($files['name'] as $index => $name) {
        $errorCode = (int) $files['error'][$index];
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $nombre = basename((string) $name);
        if ($errorCode !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$index])) {
            throw new RuntimeException("No se pudo recibir el archivo {$nombre}.");
        }
        if ((int) $files['size'][$index] > ADJUNTOS_MAX_BYTES) {
            throw new RuntimeException("El archivo {$nombre} supera los 20 MB.");
        }
        $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $mime = strtolower((string) $finfo->file($files['tmp_name'][$index]));
        if (!isset(ADJUNTOS_TIPOS[$extension]) || !in_array($mime, ADJUNTOS_TIPOS[$extension], true)) {
            throw new RuntimeException("El archivo {$nombre} no es un tipo permitido (PDF, JPG, PNG, Word o Excel).");
        }
        $validos[] = [
            'tmp' => $files['tmp_name'][$index],
            'nombre' => mb_substr($nombre, 0, 255, 'UTF-8'),
            'extension' => $extension,
            'mime' => $mime,
            'tamano' => (int) $files['size'][$index],
        ];
    }
    return $validos;
}

/**
 * Guarda los adjuntos ya validados en uploads/ con nombre aleatorio (nunca el del usuario)
 * y los registra en la solicitud.
 */
function guardar_adjuntos(PDO $pdo, int $requestId, string $prefijo, array $adjuntos): void
{
    $uploadDir = __DIR__ . '/../uploads';
    $insert = $pdo->prepare(
        'INSERT INTO adjuntos (solicitud_id, nombre_original, ruta, mime_type, tamano_bytes) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($adjuntos as $adjunto) {
        $targetName = $prefijo . '_' . bin2hex(random_bytes(12)) . '.' . $adjunto['extension'];
        if (move_uploaded_file($adjunto['tmp'], $uploadDir . '/' . $targetName)) {
            $insert->execute([$requestId, $adjunto['nombre'], 'uploads/' . $targetName, $adjunto['mime'], $adjunto['tamano']]);
        }
    }
}

