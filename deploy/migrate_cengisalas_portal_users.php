<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso: php deploy/migrate_cengisalas_portal_users.php --check|--apply\n");
    exit(2);
}

require __DIR__ . '/../login/config/conexion.php';
require __DIR__ . '/../login/config/cengisalas_access.php';
require __DIR__ . '/../login/config/cengisalas_supabase.php';

$mysql = Conexion::conectar();
$pg = cengisalas_pg();
$portalUsers = $mysql->query('SELECT id,nombre,correo,es_superadmin FROM usuarios')->fetchAll(PDO::FETCH_ASSOC);
$profiles = $pg->query('SELECT id,email,portal_user_id,account_status::text AS account_status,is_active FROM public.profiles')->fetchAll(PDO::FETCH_ASSOC);
$profilesByPortalId = [];
$profilesByEmail = [];
foreach ($profiles as $profile) {
    $email = strtolower((string) $profile['email']);
    if (isset($profilesByEmail[$email])) {
        throw new RuntimeException('Hay perfiles duplicados para un mismo correo; se detuvo la migración.');
    }
    $profilesByEmail[$email] = $profile;
    if ($profile['portal_user_id'] !== null) {
        $profilesByPortalId[(int) $profile['portal_user_id']] = $profile;
    }
}

$toSync = [];
$toCreate = [];
$protectedProfileIds = [];
foreach ($portalUsers as $user) {
    $superadmin = (int) $user['es_superadmin'] === 1;
    $access = cengisalas_user_access($mysql, (int) $user['id'], $superadmin);
    if (!$superadmin && !$access) {
        continue;
    }
    $profile = $profilesByPortalId[(int) $user['id']]
        ?? $profilesByEmail[strtolower((string) $user['correo'])]
        ?? null;
    if ($profile === null) {
        $toCreate[] = [$user, $access, $superadmin];
        continue;
    }
    $id = (string) $profile['id'];
    if (isset($protectedProfileIds[$id])) {
        throw new RuntimeException('Dos usuarios del portal coinciden con el mismo perfil.');
    }
    $protectedProfileIds[$id] = true;
    $toSync[] = [$id, $user, $access, $superadmin];
}

$toDisable = array_values(array_filter($profiles,
    static fn(array $profile): bool => !isset($protectedProfileIds[(string) $profile['id']])));
$pendingDisable = count(array_filter($toDisable, static fn(array $profile): bool =>
    $profile['account_status'] !== 'deleted'
    || in_array($profile['is_active'], [true, 't', '1', 1], true)));

echo 'Usuarios autorizados del portal con perfil existente: ' . count($toSync) . PHP_EOL;
echo 'Usuarios autorizados sin perfil para provisionar: ' . count($toCreate) . PHP_EOL;
echo 'Perfiles originales sin acceso vigente: ' . count($toDisable)
    . ' (pendientes de desactivar: ' . $pendingDisable . ')' . PHP_EOL;
if ($argv[1] === '--check') {
    exit(0);
}

foreach ($toSync as [$id, $user, $access, $superadmin]) {
    cengisalas_sync_profile($pg, $id, $user, $access, $superadmin,
        cengisalas_user_permission_overrides($mysql, (int) $user['id']));
}
foreach ($toCreate as [$user, $access, $superadmin]) {
    $link = cengisalas_admin_request('POST', '/auth/v1/admin/generate_link', [
        'type' => 'magiclink',
        'email' => strtolower((string) $user['correo']),
    ]);
    $id = (string) ($link['id'] ?? $link['user']['id'] ?? '');
    if ($id === '') {
        throw new RuntimeException('Supabase no devolvió el perfil de un usuario del portal.');
    }
    cengisalas_sync_profile($pg, $id, $user, $access, $superadmin,
        cengisalas_user_permission_overrides($mysql, (int) $user['id']));
}

$pg->beginTransaction();
try {
    $history = $pg->prepare("INSERT INTO public.user_account_status_history(user_id,from_status,to_status,reason) VALUES (?,?::public.user_account_status,'deleted'::public.user_account_status,?)");
    $disable = $pg->prepare("UPDATE public.profiles SET is_active=false,account_status='deleted'::public.user_account_status,is_superadmin=false,role='user'::public.app_role,status_reason=?,deleted_at=COALESCE(deleted_at,now()),updated_at=now() WHERE id=?");
    $disableRoles = $pg->prepare('UPDATE public.profile_module_roles SET is_active=false,updated_at=now() WHERE profile_id=?');
    $disablePermissions = $pg->prepare('UPDATE public.profile_module_permissions SET allowed=false WHERE profile_id=?');
    $reason = 'Acceso anterior desactivado al unificar usuarios con el portal de módulos.';
    foreach ($toDisable as $profile) {
        $wasActive = in_array($profile['is_active'], [true, 't', '1', 1], true);
        if ($profile['account_status'] !== 'deleted' || $wasActive) {
            $history->execute([$profile['id'], $profile['account_status'], $reason]);
        }
        $disable->execute([$reason, $profile['id']]);
        $disableRoles->execute([$profile['id']]);
        $disablePermissions->execute([$profile['id']]);
    }
    $pg->commit();
} catch (Throwable $error) {
    $pg->rollBack();
    throw $error;
}

echo "Migración aplicada: únicamente los usuarios autorizados del portal conservan acceso.\n";
