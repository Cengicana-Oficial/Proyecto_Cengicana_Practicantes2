<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__) . '/login/config/conexion.php';
require_once dirname(__DIR__) . '/login/config/cengisalas_supabase.php';

$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso: php deploy/migrate_cengisalas.php --check|--apply\n");
    exit(2);
}
try {
    $mysql = Conexion::conectar();
    $pg = cengisalas_pg();
    $menu = $mysql->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('usuarios','modulos','usuario_modulo')")->fetchColumn();
    $supabase = $pg->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('profiles','app_modules','app_module_roles','app_module_permissions','profile_module_roles','profile_module_permissions')")->fetchColumn();
    if ((int) $menu !== 3 || (int) $supabase !== 6) {
        throw new RuntimeException('Faltan tablas de base para la integración; no se modificó ningún dato.');
    }
    if ($mode === '--check') {
        $centralRoles = $mysql->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cengisalas_module_access'")->fetchColumn();
        $portalColumn = $pg->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='profiles' AND column_name='portal_user_id'")->fetchColumn();
        cengisalas_admin_request('GET', '/auth/v1/admin/users?page=1&per_page=1');
        echo "MySQL y Supabase: tablas requeridas disponibles.\n";
        echo "Migración de roles: " . ((int) $centralRoles === 1 && (int) $portalColumn === 1 ? 'instalada' : 'pendiente') . ".\n";
        echo "Supabase Auth Admin: conexión disponible.\n";
        exit(0);
    }
    $pgSql = file_get_contents(__DIR__ . '/../cengisalas/database/supabase/36_portal_identity.sql');
    $mySql = file_get_contents(__DIR__ . '/mysql/migrations/20261001_cengisalas_access.sql');
    if ($pgSql === false || $mySql === false) {
        throw new RuntimeException('No se encontraron los archivos de migración.');
    }
    $pg->exec($pgSql);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $mySql) as $statement) {
        if (trim($statement) !== '') {
            $mysql->exec($statement);
        }
    }
    echo "Migración de acceso por módulo aplicada correctamente.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migración detenida: ' . $error->getMessage() . "\n");
    exit(1);
}
