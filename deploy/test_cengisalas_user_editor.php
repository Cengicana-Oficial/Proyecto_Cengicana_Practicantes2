<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['roles','usuario'], true)) {
    fwrite(STDERR, "Uso: php deploy/test_cengisalas_user_editor.php roles|usuario\n");
    exit(2);
}
require_once dirname(__DIR__) . '/login/config/conexion.php';
$db = Conexion::conectar();
$actorId = $db->query('SELECT id FROM usuarios WHERE es_superadmin=1 ORDER BY id LIMIT 1')->fetchColumn();
if ($actorId === false) {
    fwrite(STDERR, "No hay superadministrador para la prueba de lectura.\n");
    exit(1);
}
$targetId = $db->query('SELECT id FROM usuarios WHERE es_superadmin=0 ORDER BY id LIMIT 1')->fetchColumn() ?: $actorId;
error_reporting(E_ERROR);
session_start();
$_SESSION['id_usuario'] = (int) $actorId;
$_SESSION['es_superadmin'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$mode = $argv[1];
if ($mode === 'usuario') {
    $_GET['id'] = (int) $targetId;
    chdir(dirname(__DIR__) . '/login/usuarios');
    $page = 'editar_usuario.php';
    $expected = ['name="role_salas"','name="role_visitas"','name="salas_paid"','name="salas_reports"'];
} else {
    chdir(dirname(__DIR__) . '/login/roles');
    $page = 'roles.php';
    $expected = ['CengiSalas · permisos por usuario','editar_usuario.php?id='];
}
ob_start();
require $page;
$html = (string) ob_get_clean();
session_destroy();
foreach ($expected as $fragment) {
    if (!str_contains($html, $fragment)) {
        throw new RuntimeException('Falta un control de CengiSalas en la vista ' . $mode . '.');
    }
}
echo "Vista {$mode} y controles de permisos verificados.\n";
