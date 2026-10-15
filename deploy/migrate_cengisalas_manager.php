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
    fwrite(STDERR, "Uso: php deploy/migrate_cengisalas_manager.php --check|--apply\n");
    exit(2);
}
try {
    $mysql = Conexion::conectar();
    $pg = cengisalas_pg();
    $mysqlRole = $mysql->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cengisalas_module_access' AND column_name='role_code'")->fetchColumn();
    $pgRole = $pg->query("SELECT COUNT(*) FROM public.app_module_roles r JOIN public.app_modules m ON m.id=r.module_id WHERE m.code='salas' AND r.code='manager'")->fetchColumn();
    $overrideTable = $mysql->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cengisalas_permission_overrides'")->fetchColumn();
    if ($mysqlRole === false) {
        throw new RuntimeException('Primero aplica la migración de acceso de CengiSalas.');
    }
    if ($mode === '--check') {
        echo 'Rol gestor MySQL: ' . (str_contains((string) $mysqlRole, "'manager'") ? 'instalado' : 'pendiente') . "\n";
        echo 'Rol gestor Supabase: ' . ((int) $pgRole === 1 ? 'instalado' : 'pendiente') . "\n";
        echo 'Permisos individuales: ' . ((int) $overrideTable === 1 ? 'instalados' : 'pendientes') . "\n";
        exit(0);
    }
    $mysql->exec("ALTER TABLE cengisalas_module_access MODIFY role_code ENUM('user','payments','admin','manager') NOT NULL");
    $mysql->exec("CREATE TABLE IF NOT EXISTS cengisalas_permission_overrides (usuario_id INT NOT NULL,module_code ENUM('salas','visitas') NOT NULL,permission_code VARCHAR(80) NOT NULL,allowed TINYINT(1) NOT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(usuario_id,module_code,permission_code),CONSTRAINT cengisalas_permission_user_fk FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $sql = file_get_contents(__DIR__ . '/../cengisalas/database/supabase/37_salas_manager.sql');
    if ($sql === false) {
        throw new RuntimeException('Falta la migración de Supabase para Gestor.');
    }
    $pg->exec($sql);
    echo "Rol gestor instalado en MySQL y Supabase.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migración detenida: ' . $error->getMessage() . "\n");
    exit(1);
}
