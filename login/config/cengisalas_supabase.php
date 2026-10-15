<?php
declare(strict_types=1);

function cengisalas_settings(): array
{
    static $settings;
    if ($settings !== null) {
        return $settings;
    }
    $directory = dirname(__DIR__, 2) . '/visitas';
    $autoload = $directory . '/vendor/autoload.php';
    if (!is_file($autoload) || !is_file($directory . '/.env')) {
        throw new RuntimeException('Falta la configuración privada de CengiSalas en el contenedor.');
    }
    require_once $autoload;
    Dotenv\Dotenv::createImmutable($directory)->safeLoad();
    $settings = [];
    foreach (['SUPABASE_URL','SUPABASE_SECRET_KEY','VISITS_DB_HOST','VISITS_DB_PORT','VISITS_DB_NAME','VISITS_DB_USER','VISITS_DB_PASS'] as $key) {
        $settings[$key] = (string) ($_ENV[$key] ?? getenv($key) ?: '');
        if ($settings[$key] === '') {
            throw new RuntimeException('Configuración privada incompleta: ' . $key);
        }
    }
    return $settings;
}

function cengisalas_pg(): PDO
{
    $config = cengisalas_settings();
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        $config['VISITS_DB_HOST'], $config['VISITS_DB_PORT'], $config['VISITS_DB_NAME']);
    return new PDO($dsn, $config['VISITS_DB_USER'], $config['VISITS_DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

function cengisalas_admin_request(string $method, string $path, ?array $payload = null): array
{
    $config = cengisalas_settings();
    $handle = curl_init(rtrim($config['SUPABASE_URL'], '/') . $path);
    $headers = ['apikey: ' . $config['SUPABASE_SECRET_KEY'], 'Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'cengisalas-server/1.0',
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false || $status < 200 || $status >= 300) {
        error_log('CengiSalas Auth Admin HTTP ' . $status);
        throw new RuntimeException('No fue posible conectar el acceso de CengiSalas.');
    }
    return trim((string) $body) === '' ? [] : json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
}

function cengisalas_sync_profile(PDO $pg, string $profileId, array $user, array $access, bool $superadmin, array $overrides = []): void
{
    require_once __DIR__ . '/visitas_access.php';
    $adminSalas = $superadmin || ($access['salas'] ?? '') === 'admin';
    $pg->beginTransaction();
    try {
        $existing = $pg->prepare('SELECT portal_user_id FROM public.profiles WHERE id=? FOR UPDATE');
        $existing->execute([$profileId]);
        $profileOwner = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$profileOwner) {
            throw new RuntimeException('No se encontró el perfil de CengiSalas.');
        }
        if ($profileOwner['portal_user_id'] !== null && (int) $profileOwner['portal_user_id'] !== (int) $user['id']) {
            throw new RuntimeException('El perfil ya está vinculado a otra cuenta del portal.');
        }
        // Una configuración existente de CengiSalas es la fuente de verdad.
        // El portal solo asigna permisos iniciales si el perfil aún no los tiene.
        $configured = $pg->prepare('SELECT CASE WHEN EXISTS(SELECT 1 FROM public.profile_module_permissions WHERE profile_id=?) OR EXISTS(SELECT 1 FROM public.profile_module_roles WHERE profile_id=?) THEN 1 ELSE 0 END');
        $configured->execute([$profileId, $profileId]);
        $bootstrap = (int) $configured->fetchColumn() === 0;
        $org = $pg->prepare("SELECT id FROM public.organizations WHERE code = ? AND is_active LIMIT 1");
        $org->execute([str_ends_with(strtolower((string) $user['correo']), '@cengicana.org') ? 'CENGICANA' : 'EXTERNAL']);
        $orgId = $org->fetchColumn();
        $profile = $pg->prepare("UPDATE public.profiles SET email=?,full_name=?,organization_id=COALESCE(organization_id, ?),role=COALESCE(?::public.app_role,role),is_superadmin=?,portal_user_id=?,is_active=true,account_status='active'::public.user_account_status,status_reason=NULL,deleted_at=NULL,updated_at=now() WHERE id=?");
        $profile->execute([strtolower((string) $user['correo']), (string) $user['nombre'], $orgId ?: null, $bootstrap ? ($adminSalas ? 'admin' : 'user') : null, $superadmin ? 'true' : 'false', (int) $user['id'], $profileId]);
        if ($profile->rowCount() !== 1) {
            throw new RuntimeException('No se encontró el perfil de CengiSalas.');
        }
        if (!$bootstrap) {
            $pg->commit();
            return;
        }
        $roles = $pg->query("SELECT m.code AS module_code, r.id AS role_id, r.code AS role_code FROM public.app_module_roles r JOIN public.app_modules m ON m.id=r.module_id WHERE m.code IN ('salas','visitas')")->fetchAll();
        $roleInsert = $pg->prepare('INSERT INTO public.profile_module_roles(profile_id,module_role_id,is_active) VALUES (?,?,true)');
        foreach ($roles as $role) {
            $assigned = $superadmin ? ($role['module_code'] === 'visitas' ? 'coordinador_visitas' : 'admin') : ($access[$role['module_code']] ?? '');
            $baseRole = $role['module_code'] === 'visitas' ? visitas_role_code($assigned) : $assigned;
            if ($baseRole !== '' && $baseRole === $role['role_code']) {
                $roleInsert->execute([$profileId, $role['role_id']]);
            }
        }
        $permissions = $pg->query("SELECT p.id,m.code AS module_code,p.code AS permission_code FROM public.app_module_permissions p JOIN public.app_modules m ON m.id=p.module_id WHERE m.code IN ('salas','visitas')")->fetchAll();
        $permissionInsert = $pg->prepare('INSERT INTO public.profile_module_permissions(profile_id,permission_id,allowed) VALUES (?,?,?)');
        foreach ($permissions as $permission) {
            $module = $permission['module_code'];
            $role = $superadmin ? 'admin' : ($access[$module] ?? '');
            $allowed = $role === 'admin'
                || ($module === 'salas' && $role === 'user' && in_array($permission['permission_code'], ['ver_dashboard','ver_solicitudes'], true))
                || ($module === 'salas' && $role === 'manager' && in_array($permission['permission_code'], ['ver_dashboard','ver_solicitudes','ver_solicitudes_pago','ver_reportes'], true))
                || ($module === 'visitas' && in_array($permission['permission_code'], visitas_permissions($role), true));
            if (!$superadmin && $module === 'salas' && $role !== 'admin'
                && array_key_exists($permission['permission_code'], $overrides)) {
                $allowed = (bool) $overrides[$permission['permission_code']];
            }
            $permissionInsert->execute([$profileId, $permission['id'], $allowed ? 'true' : 'false']);
        }
        $pg->commit();
    } catch (Throwable $error) {
        $pg->rollBack();
        throw $error;
    }
}

function cengisalas_sync_existing_email(array $user, array $access, bool $superadmin, array $overrides = []): void
{
    $pg = cengisalas_pg();
    $find = $pg->prepare('SELECT id FROM public.profiles WHERE portal_user_id=? OR lower(email)=lower(?) ORDER BY (portal_user_id=?) DESC LIMIT 1');
    $find->execute([(int) $user['id'], (string) $user['correo'], (int) $user['id']]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        cengisalas_sync_profile($pg, (string) $id, $user, $access, $superadmin, $overrides);
    }
}

function cengisalas_apply_salas_role(PDO $pg, string $profileId, string $role): void
{
    if (!in_array($role, ['admin', 'manager', 'user'], true)) {
        throw new InvalidArgumentException('Rol de CengiSalas inválido.');
    }
    $allowedCodes = match ($role) {
        'admin' => null, // Todos los permisos del módulo.
        'manager' => ['ver_dashboard', 'ver_solicitudes', 'ver_solicitudes_pago', 'ver_reportes'],
        'user' => ['ver_dashboard', 'ver_solicitudes'],
    };
    $pg->beginTransaction();
    try {
        $profile = $pg->prepare('SELECT portal_user_id FROM public.profiles WHERE id=? FOR UPDATE');
        $profile->execute([$profileId]);
        $portalUserId = $profile->fetchColumn();
        if ($portalUserId === false || $portalUserId === null) {
            throw new RuntimeException('La cuenta no está vinculada al portal de Módulos.');
        }
        $roleQuery = $pg->prepare("SELECT r.id FROM public.app_module_roles r JOIN public.app_modules m ON m.id=r.module_id WHERE m.code='salas' AND r.code=?");
        $roleQuery->execute([$role]);
        $roleId = $roleQuery->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException('Falta instalar el rol de CengiSalas en Supabase.');
        }
        $pg->prepare("UPDATE public.profile_module_roles SET is_active=false,updated_at=now() WHERE profile_id=? AND module_role_id IN (SELECT r.id FROM public.app_module_roles r JOIN public.app_modules m ON m.id=r.module_id WHERE m.code='salas')")
            ->execute([$profileId]);
        $pg->prepare('INSERT INTO public.profile_module_roles(profile_id,module_role_id,is_active) VALUES (?,?,true) ON CONFLICT(profile_id,module_role_id) DO UPDATE SET is_active=true,updated_at=now()')
            ->execute([$profileId, $roleId]);
        $permissions = $pg->query("SELECT p.id,p.code FROM public.app_module_permissions p JOIN public.app_modules m ON m.id=p.module_id WHERE m.code='salas'")->fetchAll();
        $upsert = $pg->prepare('INSERT INTO public.profile_module_permissions(profile_id,permission_id,allowed) VALUES (?,?,?) ON CONFLICT(profile_id,permission_id) DO UPDATE SET allowed=excluded.allowed,updated_at=now()');
        foreach ($permissions as $permission) {
            $allowed = $allowedCodes === null || in_array($permission['code'], $allowedCodes, true);
            $upsert->execute([$profileId, $permission['id'], $allowed ? 'true' : 'false']);
        }
        $pg->prepare("INSERT INTO public.board_events(event_type,entity_type,entity_id) VALUES ('permissions_updated','user',?)")
            ->execute([$profileId]);
        $pg->commit();
    } catch (Throwable $error) {
        $pg->rollBack();
        throw $error;
    }
}

// Visitas comparte identidades, pero sus permisos se asignan por separado.
function cengisalas_apply_visitas_role(PDO $pg, string $profileId, string $role): void
{
    require_once __DIR__ . '/visitas_access.php';
    $allowed = visitas_permissions($role);
    if (!$allowed) throw new InvalidArgumentException('Rol de Visitas inválido.');
    $pg->beginTransaction();
    try {
        $pg->prepare("UPDATE public.profile_module_roles SET is_active=false,updated_at=now()
            WHERE profile_id=? AND module_role_id IN (SELECT r.id FROM public.app_module_roles r
            JOIN public.app_modules m ON m.id=r.module_id WHERE m.code='visitas')")->execute([$profileId]);
        $query = $pg->prepare("SELECT r.id FROM public.app_module_roles r JOIN public.app_modules m ON m.id=r.module_id WHERE m.code='visitas' AND r.code=?");
        $query->execute([visitas_role_code($role)]);
        $roleId = $query->fetchColumn();
        if (!$roleId) throw new RuntimeException('Falta el rol de Visitas en la base de datos.');
        $pg->prepare('INSERT INTO public.profile_module_roles(profile_id,module_role_id,is_active) VALUES (?,?,true) ON CONFLICT(profile_id,module_role_id) DO UPDATE SET is_active=true,updated_at=now()')->execute([$profileId, $roleId]);
        $permissions = $pg->query("SELECT p.id,p.code FROM public.app_module_permissions p JOIN public.app_modules m ON m.id=p.module_id WHERE m.code='visitas'")->fetchAll();
        $save = $pg->prepare('INSERT INTO public.profile_module_permissions(profile_id,permission_id,allowed) VALUES (?,?,?) ON CONFLICT(profile_id,permission_id) DO UPDATE SET allowed=excluded.allowed,updated_at=now()');
        foreach ($permissions as $permission) {
            $save->execute([$profileId, $permission['id'], in_array($permission['code'], $allowed, true) ? 'true' : 'false']);
        }
        $pg->commit();
    } catch (Throwable $error) {
        $pg->rollBack();
        throw $error;
    }
}
