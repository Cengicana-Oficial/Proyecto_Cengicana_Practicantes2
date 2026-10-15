<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
cengi_session_start();
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/cengisalas_access.php';
require_once __DIR__ . '/config/cengisalas_supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function roles_response(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function salas_portal_role_name(string $role): string
{
    return match ($role) {
        'user' => 'CengiSalas - Usuario estándar',
        'manager' => 'CengiSalas - Gestor',
        'admin' => 'CengiSalas - Administrador',
        default => throw new InvalidArgumentException('Rol de CengiSalas inválido.'),
    };
}

function salas_portal_role_id(PDO $db, string $role): int
{
    $name = salas_portal_role_name($role);
    $db->prepare('INSERT INTO roles(nombre_rol) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')
        ->execute([$name]);
    $roleId = (int) $db->lastInsertId();
    $check = $db->prepare('SELECT COUNT(*) FROM rol_permiso WHERE rol_id=?');
    $check->execute([$roleId]);
    if ($roleId <= 0 || (int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('El rol base del portal tiene permisos globales. Solicita su revisión al superadministrador.');
    }
    return $roleId;
}

$actorId = (int) ($_SESSION['id_usuario'] ?? 0);
if ($actorId <= 0) roles_response(401, ['error' => 'Inicia sesión en Módulos.']);

$operation = 'assign';
try {
    $db = Conexion::conectar();
    $actorQuery = $db->prepare('SELECT id,es_superadmin FROM usuarios WHERE id=?');
    $actorQuery->execute([$actorId]);
    $actor = $actorQuery->fetch(PDO::FETCH_ASSOC);
    if (!$actor || ((int) $actor['es_superadmin'] !== 1
        && cengisalas_role($db, $actorId, 'salas') !== 'admin')) {
        roles_response(403, ['error' => 'Solo un administrador de CengiSalas puede asignar roles.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $_SESSION['cengisalas_roles_csrf'] ??= bin2hex(random_bytes(32));
        $users = [];
        $query = $db->query("SELECT u.id,u.nombre,u.correo,u.es_superadmin,a.role_code
            FROM usuarios u LEFT JOIN cengisalas_module_access a
              ON a.usuario_id=u.id AND a.module_code='salas'
            ORDER BY u.nombre,u.id");
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $user) {
            $isSuperadmin = (int) $user['es_superadmin'] === 1;
            $users[] = [
                'id' => (int) $user['id'],
                'name' => (string) $user['nombre'],
                'email' => (string) $user['correo'],
                'role' => $isSuperadmin ? 'admin' : ($user['role_code'] ?: null),
                'source' => $isSuperadmin ? 'superadmin' : ($user['role_code'] ? 'assigned' : 'none'),
                'locked' => $isSuperadmin || (int) $user['id'] === $actorId,
            ];
        }
        roles_response(200, ['users' => $users, 'csrf' => $_SESSION['cengisalas_roles_csrf']]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') roles_response(405, ['error' => 'Método no permitido.']);
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrf === '' || !hash_equals((string) ($_SESSION['cengisalas_roles_csrf'] ?? ''), $csrf)) {
        roles_response(403, ['error' => 'La sesión de edición expiró. Abre de nuevo Roles y permisos.']);
    }
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (($payload['action'] ?? '') === 'create') {
        $operation = 'create';
        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $role = (string) ($payload['role'] ?? '');
        $organizationId = filter_var($payload['organization_id'] ?? null, FILTER_VALIDATE_INT);
        $departmentId = ($payload['department_id'] ?? null) === null || ($payload['department_id'] ?? '') === ''
            ? null : filter_var($payload['department_id'], FILTER_VALIDATE_INT);
        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 120
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120
            || strlen($password) < 8 || strlen($password) > 72
            || !in_array($role, ['user', 'manager', 'admin'], true)
            || !$organizationId || $departmentId === false) {
            roles_response(422, ['error' => 'Revisa nombre, correo, contraseña, institución y rol.']);
        }
        $duplicate = $db->prepare('SELECT id FROM usuarios WHERE LOWER(correo)=? LIMIT 1');
        $duplicate->execute([$email]);
        if ($duplicate->fetchColumn()) roles_response(409, ['error' => 'Ese correo ya está registrado en Módulos. Asígnale un rol desde esta misma pantalla.']);

        $pg = cengisalas_pg();
        $organization = $pg->prepare('SELECT id FROM public.organizations WHERE id=? AND is_active');
        $organization->execute([$organizationId]);
        if (!$organization->fetchColumn()) roles_response(422, ['error' => 'Selecciona una institución activa.']);
        if ($departmentId !== null) {
            $department = $pg->prepare('SELECT id FROM public.departments WHERE id=? AND organization_id=? AND is_active');
            $department->execute([$departmentId, $organizationId]);
            if (!$department->fetchColumn()) roles_response(422, ['error' => 'El departamento no pertenece a la institución seleccionada.']);
        }
        $profileCheck = $pg->prepare('SELECT id FROM public.profiles WHERE LOWER(email)=? LIMIT 1');
        $profileCheck->execute([$email]);
        if ($profileCheck->fetchColumn()) roles_response(409, ['error' => 'Ese correo ya tiene una cuenta de CengiSalas. Consulta al superadministrador antes de vincularlo.']);
        $module = $db->query("SELECT id FROM modulos WHERE LOWER(nombre)='cengisalas' LIMIT 1")->fetchColumn();
        if (!$module) throw new RuntimeException('El módulo CengiSalas no está registrado en el portal.');

        $authUserId = null;
        $db->beginTransaction();
        try {
            $portalRoleId = salas_portal_role_id($db, $role);
            $db->prepare('INSERT INTO usuarios(nombre,correo,contrasena,rol_id,ingenio_id,es_superadmin) VALUES (?,?,?,?,NULL,0)')
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $portalRoleId]);
            $newUserId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO usuario_modulo(usuario_id,modulo_id) VALUES (?,?)')->execute([$newUserId, (int) $module]);
            $db->prepare("INSERT INTO cengisalas_module_access(usuario_id,module_code,role_code) VALUES (?,'salas',?)")
                ->execute([$newUserId, $role]);

            // La clave de Supabase es técnica: la persona inicia sesión solo en Módulos.
            $created = cengisalas_admin_request('POST', '/auth/v1/admin/users', [
                'email' => $email,
                'password' => bin2hex(random_bytes(32)),
                'email_confirm' => true,
                'user_metadata' => ['full_name' => $name],
            ]);
            $authUserId = (string) ($created['id'] ?? $created['user']['id'] ?? '');
            if ($authUserId === '') throw new RuntimeException('Supabase no devolvió el identificador del usuario.');
            $newUser = ['id' => $newUserId, 'nombre' => $name, 'correo' => $email];
            cengisalas_sync_profile($pg, $authUserId, $newUser, ['salas' => $role], false);
            $profile = $pg->prepare('UPDATE public.profiles SET organization_id=?,department_id=?,updated_at=now() WHERE id=?');
            $profile->execute([(int) $organizationId, $departmentId === null ? null : (int) $departmentId, $authUserId]);
            cengisalas_apply_salas_role($pg, $authUserId, $role);
            $db->commit();
            roles_response(201, ['ok' => true, 'user_id' => $newUserId, 'role' => $role]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($authUserId !== null) {
                try {
                    cengisalas_admin_request('DELETE', '/auth/v1/admin/users/' . rawurlencode($authUserId));
                } catch (Throwable $cleanupError) {
                    error_log('No se pudo retirar la cuenta técnica incompleta de CengiSalas: ' . $cleanupError->getMessage());
                }
            }
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                roles_response(409, ['error' => 'Ese correo ya está registrado en Módulos.']);
            }
            throw $error;
        }
    }
    $targetId = filter_var($payload['user_id'] ?? null, FILTER_VALIDATE_INT);
    $role = (string) ($payload['role'] ?? '');
    if (!$targetId || !in_array($role, ['user', 'manager', 'admin'], true)) {
        roles_response(422, ['error' => 'Selecciona un usuario y uno de los tres roles de CengiSalas.']);
    }
    if ($targetId === $actorId) roles_response(422, ['error' => 'No puedes cambiar tu propio rol.']);

    $db->beginTransaction();
    try {
        $targetQuery = $db->prepare('SELECT u.id,u.nombre,u.correo,u.es_superadmin,r.nombre_rol AS portal_role FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=? FOR UPDATE');
        $targetQuery->execute([$targetId]);
        $target = $targetQuery->fetch(PDO::FETCH_ASSOC);
        if (!$target) throw new RuntimeException('El usuario del portal ya no existe.');
        if ((int) $target['es_superadmin'] === 1) {
            throw new RuntimeException('No se puede modificar a un superadministrador de Módulos.');
        }

        // Solo las cuentas creadas aquí usan roles base dedicados en Módulos.
        // No cambiamos el rol global de usuarios que llegaron de otros módulos.
        if (in_array($target['portal_role'], [salas_portal_role_name('user'), salas_portal_role_name('manager'), salas_portal_role_name('admin')], true)) {
            $portalRoleId = salas_portal_role_id($db, $role);
            $db->prepare('UPDATE usuarios SET rol_id=? WHERE id=?')->execute([$portalRoleId, $targetId]);
        }

        $db->prepare("INSERT INTO cengisalas_module_access(usuario_id,module_code,role_code)
            VALUES (?,'salas',?) ON DUPLICATE KEY UPDATE role_code=VALUES(role_code)")
            ->execute([$targetId, $role]);
        $db->prepare("INSERT INTO usuario_modulo(usuario_id,modulo_id)
            SELECT ?,m.id FROM modulos m WHERE LOWER(m.nombre)='cengisalas'
            ON DUPLICATE KEY UPDATE modulo_id=VALUES(modulo_id)")
            ->execute([$targetId]);

        // Solo esta asignación solicitada modifica el perfil de Salones.
        $link = cengisalas_admin_request('POST', '/auth/v1/admin/generate_link', [
            'type' => 'magiclink', 'email' => strtolower((string) $target['correo']),
        ]);
        $profileId = (string) ($link['id'] ?? $link['user']['id'] ?? '');
        if ($profileId === '') throw new RuntimeException('No se encontró el perfil de datos del usuario.');
        $pg = cengisalas_pg();
        $access = cengisalas_user_access($db, $targetId, false);
        cengisalas_sync_profile($pg, $profileId, $target, $access, false);
        cengisalas_apply_salas_role($pg, $profileId, $role);
        $db->commit();
        roles_response(200, ['ok' => true, 'role' => $role]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    error_log('CengiSalas roles: ' . $error->getMessage());
    roles_response(503, ['error' => $operation === 'create'
        ? 'No fue posible crear el usuario. No se activó su acceso en Módulos.'
        : 'No fue posible guardar el rol. No se aplicó el cambio.']);
}
