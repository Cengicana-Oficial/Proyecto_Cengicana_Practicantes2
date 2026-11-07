<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Token CSRF de la sesión; cada formulario POST lo envía con csrf_field(). */
function csrf_token(): string
{
    $_SESSION['solicitudes_csrf'] ??= bin2hex(random_bytes(32));
    return $_SESSION['solicitudes_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valido(): bool
{
    return hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''));
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

/**
 * Lista de solicitudes con filtros (estado, tipo, prioridad), busqueda, orden por columna
 * y marca de novedades sin leer. La comparten "Enviadas" y "Recibidas".
 * $management = vista de quien recibe (muestra solicitante). $defaultFilter: estado inicial.
 */
function render_table(array $rows, string $id, array $user, bool $management = false, array $unreadIds = [], string $defaultFilter = 'todas'): void
{
    $abiertos = ['recibido', 'proceso'];
    $conteo = ['todas' => count($rows), 'abiertas' => 0, 'vencidas' => 0, 'recibido' => 0, 'proceso' => 0, 'completado' => 0, 'rechazado' => 0];
    $prioridadOrden = ['alta' => 3, 'media' => 2, 'baja' => 1];
    $preparadas = [];
    foreach ($rows as $row) {
        $abierta = in_array($row['estado'], $abiertos, true);
        $dias = $row['fecha_requerida'] ? dias_hasta((string) $row['fecha_requerida']) : null;
        $vencida = $abierta && $dias !== null && $dias < 0;
        $conteo[$row['estado']] = ($conteo[$row['estado']] ?? 0) + 1;
        $conteo['abiertas'] += $abierta ? 1 : 0;
        $conteo['vencidas'] += $vencida ? 1 : 0;
        $preparadas[] = [$row, $abierta, $dias, $vencida];
    }
    $chips = [
        'abiertas' => 'Abiertas', 'vencidas' => 'Vencidas', 'recibido' => 'Recibido', 'proceso' => 'En proceso',
        'completado' => 'Completado', 'rechazado' => 'Rechazado', 'todas' => 'Todas',
    ];
    if (!isset($chips[$defaultFilter])) {
        $defaultFilter = 'todas';
    }
    $tipos = ['compra' => 'Compra', 'ti' => 'Soporte TI', 'apoyo' => 'Apoyo'];
    ?>
    <div class="request-list" data-request-list="<?= e($id) ?>" data-default="<?= e($defaultFilter) ?>">
        <div class="request-toolbar">
            <div class="search-box"><i class="ti ti-search"></i><input class="form-control" type="search" data-list-search placeholder="Buscar por codigo, titulo, solicitante, programa..." aria-label="Buscar solicitud"></div>
            <select class="form-control" data-list-tipo aria-label="Tipo">
                <option value="">Todos los tipos</option>
                <?php foreach ($tipos as $valor => $etiqueta): ?><option value="<?= $valor ?>"><?= $etiqueta ?></option><?php endforeach; ?>
            </select>
            <select class="form-control" data-list-prioridad aria-label="Prioridad">
                <option value="">Toda prioridad</option>
                <option value="alta">Alta</option><option value="media">Media</option><option value="baja">Baja</option>
            </select>
        </div>
        <div class="filters request-chips" role="group" aria-label="Filtrar por estado">
            <?php foreach ($chips as $clave => $etiqueta): ?>
                <?php if ($clave === 'vencidas' && $conteo['vencidas'] === 0) continue; ?>
                <button type="button" class="filter-chip<?= $clave === $defaultFilter ? ' active' : '' ?><?= $clave === 'vencidas' ? ' is-alert' : '' ?>" data-list-estado="<?= $clave ?>" aria-pressed="<?= $clave === $defaultFilter ? 'true' : 'false' ?>">
                    <?= e($etiqueta) ?> <span class="chip-count"><?= (int) $conteo[$clave] ?></span>
                </button>
            <?php endforeach; ?>
            <span class="request-count" data-list-count aria-live="polite"></span>
        </div>
        <div class="table-card">
            <div class="table-wrap">
                <table id="<?= e($id) ?>" class="request-table">
                    <thead>
                    <tr>
                        <th><button type="button" data-sort="codigo">Codigo</button></th>
                        <th><button type="button" data-sort="titulo">Solicitud</button></th>
                        <th><button type="button" data-sort="prioridad">Prioridad</button></th>
                        <th><button type="button" data-sort="requerida">Requerida</button></th>
                        <th><button type="button" data-sort="estado">Estado</button></th>
                        <th><button type="button" data-sort="actividad">Actividad</button></th>
                        <th><span class="sr-only">Accion</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preparadas as [$row, $abierta, $dias, $vencida]): ?>
                        <?php
                        $nuevo = isset($unreadIds[(int) $row['id']]);
                        $actividad = (string) ($row['ultima_actividad'] ?: $row['creado_en']);
                        $sinMovimiento = $abierta ? (int) floor((time() - strtotime($actividad)) / 86400) : 0;
                        $busqueda = normalize_text(implode(' ', [$row['codigo'], $row['titulo'], $row['extracto'] ?? '', $row['solicitante'] ?? '', $row['programa_origen'], $row['programa_destino'] ?? '']));
                        ?>
                        <tr data-estado="<?= e($row['estado']) ?>" data-tipo="<?= e($row['tipo']) ?>" data-prioridad="<?= e($row['prioridad']) ?>"
                            data-abierta="<?= $abierta ? '1' : '0' ?>" data-vencida="<?= $vencida ? '1' : '0' ?>" data-buscar="<?= e($busqueda) ?>"
                            data-k-codigo="<?= e($row['codigo']) ?>" data-k-titulo="<?= e(normalize_text((string) $row['titulo'])) ?>"
                            data-k-prioridad="<?= $prioridadOrden[$row['prioridad']] ?? 0 ?>" data-k-requerida="<?= e((string) ($row['fecha_requerida'] ?: '9999-12-31')) ?>"
                            data-k-estado="<?= (int) array_search($row['estado'], ['recibido', 'proceso', 'completado', 'rechazado'], true) ?>" data-k-actividad="<?= e($actividad) ?>"
                            class="<?= $nuevo ? 'is-unread' : '' ?><?= $vencida ? ' is-late' : '' ?>">
                            <td data-label="Codigo">
                                <a class="request-code" href="index.php?view=detalle&id=<?= (int) $row['id'] ?>"><?= $nuevo ? '<span class="unread-dot" title="Tiene novedades sin leer"></span>' : '' ?><?= e($row['codigo']) ?></a>
                                <small><?= e(date('d/m/Y', strtotime((string) $row['creado_en']))) ?></small>
                            </td>
                            <td data-label="Solicitud" class="request-main">
                                <strong><?= e($row['titulo']) ?></strong>
                                <small>
                                    <?= badge_tipo((string) $row['tipo']) ?>
                                    <?php if ($management && !empty($row['solicitante'])): ?><span><i class="ti ti-user"></i><?= e($row['solicitante']) ?></span><?php endif; ?>
                                    <span><i class="ti ti-arrows-right"></i><?= e($row['programa_origen']) ?> → <?= e($row['programa_destino'] ?: 'No aplica') ?></span>
                                    <?php if ($row['monto_estimado'] !== null): ?><span><i class="ti ti-coin"></i>Q <?= e(number_format((float) $row['monto_estimado'], 2)) ?></span><?php endif; ?>
                                </small>
                            </td>
                            <td data-label="Prioridad"><?= badge_prioridad((string) $row['prioridad']) ?></td>
                            <td data-label="Requerida">
                                <?php if ($row['fecha_requerida']): ?>
                                    <?= e(date('d/m/Y', strtotime((string) $row['fecha_requerida']))) ?>
                                    <?php if ($abierta && $dias !== null && $dias <= 3): ?>
                                        <span class="due-chip <?= $dias < 0 ? 'is-late' : 'is-soon' ?>"><?= $dias < 0 ? 'Vencida ' . -$dias . ' d' : ($dias === 0 ? 'Hoy' : 'En ' . $dias . ' d') ?></span>
                                    <?php endif; ?>
                                <?php else: ?><span class="muted">—</span><?php endif; ?>
                            </td>
                            <td data-label="Estado"><?= badge_estado((string) $row['estado']) ?></td>
                            <td data-label="Actividad">
                                <?= e(date('d/m/Y', strtotime($actividad))) ?>
                                <?php if ($sinMovimiento >= 7): ?><small class="stale" title="Sin seguimiento desde hace <?= $sinMovimiento ?> dias">Sin movimiento <?= $sinMovimiento ?> d</small><?php endif; ?>
                            </td>
                            <td class="request-action"><a class="btn-outline btn-sm" href="index.php?view=detalle&id=<?= (int) $row['id'] ?>" aria-label="Ver detalle de <?= e($row['codigo']) ?>">Ver detalle</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="request-empty<?= $rows ? ' hidden' : '' ?>" data-list-empty>
                <i class="ti ti-inbox-off"></i>
                <strong><?= $rows ? 'Ninguna solicitud coincide con los filtros.' : 'No hay solicitudes para mostrar.' ?></strong>
                <?php if ($rows): ?><button type="button" class="btn-outline btn-sm" data-list-reset>Limpiar filtros</button><?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/** Ids de solicitudes con notificaciones sin leer del usuario: [id => true]. */
function unread_request_ids(PDO $pdo, array $user): array
{
    [$where, $params] = notification_scope_sql($user);
    $stmt = $pdo->prepare(
        "SELECT DISTINCT sg.solicitud_id
         FROM seguimientos sg
         INNER JOIN solicitudes s ON s.id = sg.solicitud_id
         WHERE {$where}
           AND NOT EXISTS (SELECT 1 FROM notificaciones_leidas nl WHERE nl.usuario_id = ? AND nl.seguimiento_id = sg.id)"
    );
    $stmt->execute(array_merge($params, [(int) $user['id']]));

    return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
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

/** Bytes de una directiva de php.ini como "25M". */
function ini_bytes(string $directiva): int
{
    $valor = trim((string) ini_get($directiva));
    $numero = (int) $valor;

    return match (strtolower(substr($valor, -1))) {
        'g' => $numero * 1073741824,
        'm' => $numero * 1048576,
        'k' => $numero * 1024,
        default => $numero,
    };
}

/**
 * Zona para adjuntar archivos (arrastrar y soltar o seleccionar). Es un <input type="file">
 * real: sin JavaScript funciona como el selector normal; app.js agrega la lista de archivos,
 * quitar uno a uno, vista previa de imagenes y validacion de tamano por archivo y total.
 */
function render_dropzone(string $id, string $name, string $hint = ''): void
{
    $extensiones = array_keys(ADJUNTOS_TIPOS);
    // Margen de 512 KB para el resto del formulario dentro de post_max_size.
    $maxTotal = max(ADJUNTOS_MAX_BYTES, ini_bytes('post_max_size') - 524288);
    $maxArchivos = max(1, (int) ini_get('max_file_uploads') ?: 20);
    ?>
    <div class="dropzone" data-dropzone data-max-bytes="<?= ADJUNTOS_MAX_BYTES ?>" data-max-total="<?= $maxTotal ?>" data-max-files="<?= $maxArchivos ?>" data-ext="<?= e(implode(',', $extensiones)) ?>">
        <input class="dropzone-input" type="file" id="<?= e($id) ?>" name="<?= e($name) ?>[]" multiple accept=".<?= e(implode(',.', $extensiones)) ?>">
        <label class="dropzone-area" for="<?= e($id) ?>">
            <span class="dropzone-icon"><i class="ti ti-cloud-upload"></i></span>
            <span class="dropzone-text"><strong>Arrastra archivos aqui</strong> o <span class="dropzone-link">selecciona desde tu equipo</span></span>
            <small><?= e($hint !== '' ? $hint : 'PDF, Word, Excel, JPG o PNG · hasta ' . round(ADJUNTOS_MAX_BYTES / 1048576) . ' MB por archivo y ' . floor($maxTotal / 1048576) . ' MB en total') ?></small>
        </label>
        <ul class="dropzone-files" data-dropzone-files aria-live="polite"></ul>
        <p class="dropzone-error hidden" data-dropzone-error role="alert"></p>
    </div>
    <?php
}

/** Enlace protegido a un adjunto (adjunto.php valida que el usuario pueda ver la solicitud). */
function adjunto_link(array $adjunto): string
{
    $extension = strtolower(pathinfo((string) $adjunto['nombre_original'], PATHINFO_EXTENSION));
    $icono = match ($extension) {
        'pdf' => 'ti-file-type-pdf',
        'jpg', 'jpeg', 'png' => 'ti-photo',
        'doc', 'docx' => 'ti-file-type-doc',
        'xls', 'xlsx' => 'ti-file-spreadsheet',
        default => 'ti-paperclip',
    };
    $bytes = (int) ($adjunto['tamano_bytes'] ?? 0);
    $tamano = $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';

    return '<a class="attachment-chip" href="adjunto.php?id=' . (int) $adjunto['id'] . '" target="_blank" rel="noopener" title="'
        . e((string) $adjunto['nombre_original']) . '"><i class="ti ' . $icono . '"></i><span>' . e((string) $adjunto['nombre_original'])
        . '</span>' . ($bytes > 0 ? '<small>' . e($tamano) . '</small>' : '') . '</a>';
}

/** Dias que faltan (negativo = vencida) para una fecha Y-m-d, contando desde hoy. */
function dias_hasta(string $fecha): ?int
{
    $objetivo = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$objetivo) {
        return null;
    }

    return (int) (new DateTimeImmutable('today'))->diff($objetivo)->format('%r%a');
}

/*
 * Buzon de ideas
 * --------------
 * Formulario publico (buzon.php): cualquiera con el link puede enviar una idea,
 * sin iniciar sesion. Las ideas se revisan dentro del modulo (view=buzon) con los
 * permisos `solicitudes_internas.buzon.ver` / `.buzon.gestionar`.
 */
const BUZON_AREAS = [
    'Procesos y trabajo de campo',
    'Investigación y calidad',
    'Seguridad y bienestar',
    'Recursos y eficiencia',
    'Comunicación y trabajo en equipo',
    'Otra',
];
const BUZON_ESTADOS = [
    'nueva' => 'Nueva',
    'en_analisis' => 'En análisis',
    'aprobada' => 'Aprobada',
    'rechazada' => 'Rechazada',
    'archivada' => 'Archivada',
];

/**
 * Ficha de revision de la idea (tabla idea_revisiones): campos de texto libre.
 * Clave => [titulo, ayuda, icono]. El costo y "requiere analisis" se manejan aparte.
 */
const IDEA_REVISION_CAMPOS = [
    'recursos' => ['Recursos necesarios', 'Personas, equipo, materiales, tiempo o servicios que se necesitan.', 'ti-tools'],
    'implicaciones_legales' => ['Implicaciones legales', 'Leyes, contratos, permisos o licencias que se deben cumplir.', 'ti-scale'],
    'implicaciones_reglamentarias' => ['Implicaciones reglamentarias', 'Normas, certificaciones, politicas de seguridad, ambientales o de calidad.', 'ti-clipboard-check'],
    'implicaciones_organizacionales' => ['Implicaciones organizacionales', 'Areas o procesos afectados, cambios de funciones, capacitacion, comunicacion.', 'ti-building'],
    'riesgos' => ['Riesgos', 'Que puede salir mal y como se mitigaria.', 'ti-alert-triangle'],
    'oportunidades' => ['Oportunidades', 'Beneficios adicionales, ahorros o mejoras que abre la idea.', 'ti-trending-up'],
    'requisitos' => ['Requisitos a cumplir', 'Condiciones que deben cumplirse antes o durante la implementacion.', 'ti-list-check'],
];

/**
 * Ponderacion de la idea: cada criterio se califica de 1 a 5 y pesa un % del puntaje
 * final (0-100). Costo y riesgo van invertidos: 5 = costo/riesgo muy bajo.
 * Clave => [titulo, pregunta, peso %, etiquetas de 1 a 5]. Columna: pond_<clave>.
 */
const IDEA_CRITERIOS = [
    'impacto' => ['Impacto y beneficio', '¿Cuanto mejora el proceso, la seguridad, la calidad o la productividad?', 30, ['Muy bajo', 'Bajo', 'Medio', 'Alto', 'Muy alto']],
    'viabilidad' => ['Viabilidad', '¿Que tan facil es implementarla con la capacidad y los recursos actuales?', 20, ['Muy dificil', 'Dificil', 'Posible', 'Facil', 'Muy facil']],
    'costo' => ['Costo', 'Mientras menor sea el costo, mayor puntaje.', 20, ['Muy alto', 'Alto', 'Medio', 'Bajo', 'Muy bajo']],
    'riesgo' => ['Riesgo', 'Mientras menor sea el riesgo, mayor puntaje.', 15, ['Muy alto', 'Alto', 'Medio', 'Bajo', 'Muy bajo']],
    'alineacion' => ['Alineacion institucional', '¿Apoya los objetivos y prioridades de CENGICAÑA?', 15, ['Ninguna', 'Baja', 'Media', 'Alta', 'Total']],
];
/** Puntaje minimo para recomendar la implementacion, y para "con reservas". */
const IDEA_PUNTAJE_RECOMENDADA = 70;
const IDEA_PUNTAJE_RESERVAS = 50;
const IDEA_TEXTO_MAX = 4000;
const BUZON_FOTO_TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
const BUZON_FOTO_MAX_BYTES = 5 * 1024 * 1024;
/** Segundos minimos entre dos envios desde la misma sesion (freno basico contra spam). */
const BUZON_ESPERA_SEGUNDOS = 30;

function can_view_ideas(array $user): bool
{
    return can_approve_ideas($user) || has_permission($user, 'solicitudes_internas.buzon.ver') || has_permission($user, 'solicitudes_internas.buzon.gestionar');
}

/** Direccion general decide; superadmin conserva acceso administrativo. */
function can_approve_ideas(array $user): bool
{
    return in_array(module_role($user), ['director', 'superadmin'], true);
}

function can_manage_ideas(array $user): bool
{
    return has_permission($user, 'solicitudes_internas.buzon.gestionar');
}

function badge_idea_estado(string $estado): string
{
    $clases = [
        'nueva' => 'recibido',
        'en_analisis' => 'proceso',
        'aprobada' => 'completado',
        'rechazada' => 'rechazado',
        'archivada' => 'programa',
    ];

    return '<span class="badge badge-' . e($clases[$estado] ?? 'recibido') . '">' . e(BUZON_ESTADOS[$estado] ?? $estado) . '</span>';
}

/**
 * Puede llenar la ficha de revision: quien gestiona el buzon o un responsable
 * asignado a la idea. Aprobar/rechazar sigue siendo solo de can_manage_ideas().
 */
function can_edit_idea_review(array $user, array $responsableIds): bool
{
    return can_manage_ideas($user) || (can_view_ideas($user) && in_array((int) $user['id'], $responsableIds, true));
}

/** @return int[] */
function idea_responsable_ids(PDO $pdo, int $ideaId): array
{
    $stmt = $pdo->prepare('SELECT usuario_id FROM idea_responsables WHERE idea_id = ?');
    $stmt->execute([$ideaId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Avance de la ficha: [puntos llenos, total] (campos de texto + costo + responsables). */
function idea_revision_avance(?array $revision, bool $tieneResponsables = false): array
{
    $total = count(IDEA_REVISION_CAMPOS) + 2;
    $llenos = $tieneResponsables ? 1 : 0;
    if (!$revision) {
        return [$llenos, $total];
    }

    foreach (array_keys(IDEA_REVISION_CAMPOS) as $campo) {
        if (trim((string) ($revision[$campo] ?? '')) !== '') {
            $llenos++;
        }
    }
    if ($revision['costo_estimado'] !== null || trim((string) ($revision['costo_detalle'] ?? '')) !== '') {
        $llenos++;
    }

    return [$llenos, $total];
}

/** Texto de un campo de la revision: recortado, con limite y null si viene vacio. */
function idea_texto_post(string $campo): ?string
{
    $valor = trim(str_replace("\r\n", "\n", (string) ($_POST[$campo] ?? '')));
    if ($valor === '') {
        return null;
    }
    if (mb_strlen($valor, 'UTF-8') > IDEA_TEXTO_MAX) {
        throw new RuntimeException('Un campo supera los ' . IDEA_TEXTO_MAX . ' caracteres permitidos.');
    }

    return $valor;
}

/** Monto en quetzales: acepta "1,500.50"; vacio = null. */
function idea_costo_post(string $campo): ?string
{
    $valor = str_replace([',', ' ', 'Q'], '', trim((string) ($_POST[$campo] ?? '')));
    if ($valor === '') {
        return null;
    }
    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $valor)) {
        throw new RuntimeException('El costo estimado debe ser un monto valido (ej. 1500.00).');
    }

    return $valor;
}

/** Bitacora de la idea (tabla idea_aportes, tipo 'sistema'). usuario_id 0 = formulario publico. */
function registrar_historial_idea(PDO $pdo, int $ideaId, int $userId, string $texto): void
{
    $pdo->prepare("INSERT INTO idea_aportes (idea_id, usuario_id, tipo, texto) VALUES (?, ?, 'sistema', ?)")
        ->execute([$ideaId, $userId, $texto]);
}

/** Calificaciones de la ponderacion [criterio => 1..5|null] tomadas de idea_revisiones. */
function idea_ponderacion_notas(?array $revision): array
{
    $notas = [];
    foreach (array_keys(IDEA_CRITERIOS) as $criterio) {
        $valor = $revision['pond_' . $criterio] ?? null;
        $notas[$criterio] = $valor !== null ? (int) $valor : null;
    }

    return $notas;
}

/** Puntaje 0-100 de la ponderacion, o null si falta calificar algun criterio. */
function idea_puntaje(array $notas): ?float
{
    $suma = 0.0;
    foreach (IDEA_CRITERIOS as $criterio => [, , $peso]) {
        if (($notas[$criterio] ?? null) === null) {
            return null;
        }
        $suma += $notas[$criterio] / 5 * $peso;
    }

    return round($suma, 1);
}

/** [clave css, etiqueta] de la recomendacion segun el puntaje. */
function idea_recomendacion(?float $puntaje): array
{
    if ($puntaje === null) {
        return ['pendiente', 'Sin ponderar'];
    }
    if ($puntaje >= IDEA_PUNTAJE_RECOMENDADA) {
        return ['alta', 'Recomendada para implementacion'];
    }
    if ($puntaje >= IDEA_PUNTAJE_RESERVAS) {
        return ['media', 'Con reservas'];
    }

    return ['baja', 'No recomendada'];
}

function badge_idea_puntaje(?float $puntaje): string
{
    [$clave, $etiqueta] = idea_recomendacion($puntaje);
    if ($puntaje === null) {
        return '<span class="idea-score is-pendiente">' . e($etiqueta) . '</span>';
    }

    return '<span class="idea-score is-' . e($clave) . '" title="' . e($etiqueta) . '"><strong>'
        . e(rtrim(rtrim(number_format($puntaje, 1), '0'), '.')) . '</strong>/100 · ' . e($etiqueta) . '</span>';
}

/** ¿Se puede avisar por correo a quien envio la idea? Devuelve el correo o ''. */
function idea_correo_remitente(array $idea): string
{
    $correo = trim((string) ($idea['correo'] ?? ''));

    return (int) ($idea['anonimo'] ?? 0) !== 1 && filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '';
}

/** Texto de un textarea con saltos de linea normalizados (el navegador cuenta \r\n como 1). */
function buzon_texto(string $campo): string
{
    return trim(str_replace("\r\n", "\n", (string) ($_POST[$campo] ?? '')));
}

/**
 * Valida el envio del formulario publico. Devuelve los datos limpios listos para
 * guardar o lanza RuntimeException con un mensaje para el visitante.
 */
function validar_idea(array $post): array
{
    $area = (string) ($post['area'] ?? '');
    if (!in_array($area, BUZON_AREAS, true)) {
        throw new RuntimeException('Elige un área de mejora.');
    }
    $areaOtra = $area === 'Otra' ? mb_substr(trim((string) ($post['area_otra'] ?? '')), 0, 80, 'UTF-8') : '';

    $textos = [];
    foreach (['situacion' => 800, 'propuesta' => 800, 'beneficio' => 500] as $campo => $max) {
        $valor = buzon_texto($campo);
        if (mb_strlen($valor, 'UTF-8') < 5) {
            throw new RuntimeException('Completa las preguntas 2, 3 y 4 antes de enviar tu idea.');
        }
        $textos[$campo] = mb_substr($valor, 0, $max, 'UTF-8');
    }

    $anonimo = !empty($post['anonimo']);
    $nombre = $anonimo ? '' : mb_substr(trim((string) ($post['nombre'] ?? '')), 0, 80, 'UTF-8');
    $correo = $anonimo ? '' : mb_substr(trim((string) ($post['correo'] ?? '')), 0, 120, 'UTF-8');
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Escribe un correo válido o déjalo en blanco.');
    }

    return [
        'area' => $area,
        'area_otra' => $areaOtra !== '' ? $areaOtra : null,
        'situacion' => $textos['situacion'],
        'propuesta' => $textos['propuesta'],
        'beneficio' => $textos['beneficio'],
        'anonimo' => $anonimo ? 1 : 0,
        'nombre' => $nombre !== '' ? $nombre : null,
        'correo' => $correo !== '' ? $correo : null,
    ];
}

/**
 * Valida y guarda la foto opcional en uploads/ideas/ con nombre aleatorio.
 * Devuelve ['ruta' => ..., 'mime' => ...] o null si no se envio foto.
 */
function guardar_foto_idea(?array $file): ?array
{
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('No se pudo recibir la foto. Intenta de nuevo o envía tu idea sin foto.');
    }
    if ((int) $file['size'] > BUZON_FOTO_MAX_BYTES) {
        throw new RuntimeException('La foto debe pesar menos de 5 MB.');
    }
    $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']));
    if (!isset(BUZON_FOTO_TIPOS[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('La foto debe ser JPG o PNG.');
    }

    $dir = __DIR__ . '/../uploads/ideas';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo guardar la foto. Intenta de nuevo o envía tu idea sin foto.');
    }
    $nombre = 'idea_' . bin2hex(random_bytes(16)) . '.' . BUZON_FOTO_TIPOS[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $nombre)) {
        throw new RuntimeException('No se pudo guardar la foto. Intenta de nuevo o envía tu idea sin foto.');
    }

    return ['ruta' => 'uploads/ideas/' . $nombre, 'mime' => $mime];
}

/**
 * Revision con los valores enviados en el formulario de analisis (sin validar),
 * para volver a mostrarlos cuando el guardado falla y no perder lo escrito.
 */
function idea_revision_desde_post(?array $revision): array
{
    $revision = $revision ?? [];
    $texto = static fn(string $campo): string => mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST[$campo] ?? ''))), 0, IDEA_TEXTO_MAX, 'UTF-8');
    foreach (array_merge(array_keys(IDEA_REVISION_CAMPOS), ['costo_detalle', 'analisis_detalle']) as $campo) {
        $revision[$campo] = $texto($campo);
    }
    $revision['requiere_analisis'] = !empty($_POST['requiere_analisis']) ? 1 : 0;
    $revision['costo_estimado'] = $texto('costo_estimado') !== '' ? $texto('costo_estimado') : null;
    if (isset($_POST['costo_items_enviados'])) {
        $items = [];
        foreach (array_slice((array) ($_POST['costo_items'] ?? []), 0, 100) as $fila) {
            if (!is_array($fila)) {
                continue;
            }
            $item = [];
            foreach (['categoria', 'descripcion', 'cantidad', 'unidad', 'precio'] as $campo) {
                $item[$campo] = is_scalar($fila[$campo] ?? null) ? mb_substr(trim((string) $fila[$campo]), 0, 250, 'UTF-8') : '';
            }
            $item['subtotal'] = number_format((float) $item['cantidad'] * (float) $item['precio'], 2, '.', '');
            $items[] = $item;
        }
        $revision['costo_items'] = $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;
    }

    return $revision;
}

/** Categorias generales para el presupuesto de implementacion. */
const IDEA_COSTO_CATEGORIAS = ['Materiales e insumos', 'Equipos', 'Mano de obra', 'Servicios', 'Transporte', 'Capacitacion', 'Otros'];

function idea_costos_post(): array
{
    if (!isset($_POST['costo_items_enviados'])) {
        return ['items' => null, 'total' => idea_costo_post('costo_estimado')];
    }
    $filas = $_POST['costo_items'] ?? [];
    if (!is_array($filas) || count($filas) > 100) throw new RuntimeException('El desglose admite hasta 100 items.');
    $items = [];
    $total = 0;
    foreach ($filas as $fila) {
        if (!is_array($fila)) throw new RuntimeException('Item de costo invalido.');
        foreach (['categoria', 'descripcion', 'cantidad', 'unidad', 'precio'] as $campo) {
            if (!isset($fila[$campo]) || !is_scalar($fila[$campo])) throw new RuntimeException('Item de costo incompleto.');
            $fila[$campo] = trim((string) $fila[$campo]);
        }
        if (!in_array($fila['categoria'], IDEA_COSTO_CATEGORIAS, true) || $fila['descripcion'] === '' || mb_strlen($fila['descripcion']) > 250 || mb_strlen($fila['unidad']) > 40
            || !preg_match('/^\d{1,7}(\.\d{1,2})?$/', $fila['cantidad']) || (float) $fila['cantidad'] <= 0
            || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $fila['precio'])) {
            throw new RuntimeException('Revise los items: descripcion, cantidad positiva y costo unitario con hasta dos decimales.');
        }
        $subtotal = (int) round((float) $fila['cantidad'] * (float) $fila['precio'] * 100);
        $total += $subtotal;
        if ($total > 999999999999) throw new RuntimeException('El costo total excede el monto permitido.');
        $items[] = ['categoria' => $fila['categoria'], 'descripcion' => $fila['descripcion'], 'cantidad' => $fila['cantidad'], 'unidad' => $fila['unidad'],
            'precio' => number_format((float) $fila['precio'], 2, '.', ''), 'subtotal' => number_format($subtotal / 100, 2, '.', '')];
    }
    return ['items' => $items ? json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        'total' => $items ? number_format($total / 100, 2, '.', '') : idea_costo_post('costo_estimado')];
}
