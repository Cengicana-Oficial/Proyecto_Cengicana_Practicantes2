<?php
declare(strict_types=1);

function visitas_portal_role_id(PDO $db, string $role): int
{
    $label = cengisalas_roles()['visitas'][$role] ?? null;
    if ($label === null) throw new InvalidArgumentException('Selecciona un rol de Visitas válido.');
    $db->prepare('INSERT INTO roles(nombre_rol) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute(['Visitas - ' . $label]);
    $id = (int) $db->lastInsertId();
    $check = $db->prepare('SELECT COUNT(*) FROM rol_permiso WHERE rol_id=?');
    $check->execute([$id]);
    if ($id <= 0 || (int) $check->fetchColumn() !== 0) throw new RuntimeException('El rol base contiene permisos globales y requiere revisión.');
    return $id;
}

function visitas_create_user(PDO $db, array $input): int
{
    $name = trim((string) ($input['name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    $role = (string) ($input['role'] ?? '');
    $organizationId = filter_var($input['organization_id'] ?? null, FILTER_VALIDATE_INT);
    $departmentId = ($input['department_id'] ?? '') === '' ? null : filter_var($input['department_id'], FILTER_VALIDATE_INT);
    if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120
        || strlen($password) < 8 || strlen($password) > 72
        || $password !== (string) ($input['password_confirmation'] ?? '')
        || !isset(cengisalas_roles()['visitas'][$role]) || !$organizationId || $departmentId === false) {
        throw new InvalidArgumentException('Revisa nombre, correo, institución y rol. Las contraseñas deben coincidir y tener entre 8 y 72 caracteres.');
    }
    $duplicate = $db->prepare('SELECT id FROM usuarios WHERE LOWER(correo)=? LIMIT 1');
    $duplicate->execute([$email]);
    if ($duplicate->fetchColumn()) throw new DomainException('El correo ya está registrado en Módulos. Asigna su rol en la lista de usuarios.', 409);
    $pg = cengisalas_pg();
    $organization = $pg->prepare('SELECT id FROM public.organizations WHERE id=? AND is_active');
    $organization->execute([$organizationId]);
    if (!$organization->fetchColumn()) throw new InvalidArgumentException('Selecciona una institución activa.');
    if ($departmentId !== null) {
        $department = $pg->prepare('SELECT id FROM public.departments WHERE id=? AND organization_id=? AND is_active');
        $department->execute([$departmentId, $organizationId]);
        if (!$department->fetchColumn()) throw new InvalidArgumentException('El departamento no pertenece a la institución seleccionada.');
    }
    $profile = $pg->prepare('SELECT id FROM public.profiles WHERE LOWER(email)=? LIMIT 1');
    $profile->execute([$email]);
    if ($profile->fetchColumn()) throw new DomainException('Este correo ya tiene un perfil de CengiSalas. Solicita su vinculación con Módulos.', 409);
    $moduleId = (int) $db->query("SELECT id FROM modulos WHERE LOWER(nombre)='visitas' LIMIT 1")->fetchColumn();
    if (!$moduleId) throw new RuntimeException('Falta registrar el módulo Visitas.');
    $authUserId = null;
    $db->beginTransaction();
    try {
        $roleId = visitas_portal_role_id($db, $role);
        $db->prepare('INSERT INTO usuarios(nombre,correo,contrasena,rol_id,ingenio_id,es_superadmin) VALUES (?,?,?,?,NULL,0)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        $userId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO usuario_modulo(usuario_id,modulo_id) VALUES (?,?)')->execute([$userId, $moduleId]);
        $db->prepare("INSERT INTO cengisalas_module_access(usuario_id,module_code,role_code) VALUES (?,'visitas',?)")->execute([$userId, $role]);
        // Cuenta técnica compartida; la contraseña personal se usa solo en Módulos.
        $created = cengisalas_admin_request('POST', '/auth/v1/admin/users', [
            'email' => $email, 'password' => bin2hex(random_bytes(32)), 'email_confirm' => true,
            'user_metadata' => ['full_name' => $name],
        ]);
        $authUserId = (string) ($created['id'] ?? $created['user']['id'] ?? '');
        if ($authUserId === '') throw new RuntimeException('No se recibió la identidad del usuario.');
        cengisalas_sync_profile($pg, $authUserId, ['id' => $userId, 'nombre' => $name, 'correo' => $email], ['visitas' => $role], false);
        $pg->prepare('UPDATE public.profiles SET organization_id=?,department_id=?,updated_at=now() WHERE id=?')->execute([$organizationId, $departmentId, $authUserId]);
        cengisalas_apply_visitas_role($pg, $authUserId, $role);
        $db->commit();
        return $userId;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($authUserId) {
            try { cengisalas_admin_request('DELETE', '/auth/v1/admin/users/' . rawurlencode($authUserId)); }
            catch (Throwable $cleanupError) { error_log('Visitas: no se pudo retirar una cuenta técnica incompleta.'); }
        }
        if ($error instanceof PDOException && $error->getCode() === '23000') throw new DomainException('El correo ya está registrado en Módulos.', 409);
        throw $error;
    }
}
