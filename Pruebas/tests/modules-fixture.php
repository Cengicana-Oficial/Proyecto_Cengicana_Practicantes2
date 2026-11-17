<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('TEST_ROOT');
if (!$root || !is_dir($root)) throw new RuntimeException('Falta la carpeta aislada de pruebas.');
$db = new PDO(getenv('TEST_MODULES_DSN'), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (($argv[1] ?? '') === 'menu-access') {
    require $root . '/Pruebas/module_access.php';
    echo tech_module_has_access(202) ? 'yes' : 'no';
    exit;
}
if (($argv[1] ?? '') === 'revoke') { $db->exec('UPDATE usuarios SET es_superadmin=0 WHERE id=101'); exit; }
if (($argv[1] ?? '') === 'restore') { $db->exec('UPDATE usuarios SET es_superadmin=1 WHERE id=101'); exit; }
if (($argv[1] ?? '') === 'delete') { $db->exec('DELETE FROM usuarios WHERE id=101'); exit; }
$db->exec('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, es_superadmin INTEGER)');
$db->exec("INSERT INTO usuarios VALUES (101,'Superadmin de prueba','admin@example.test',1),(202,'Usuario de prueba','user@example.test',0)");
file_put_contents($root . '/login/config/conexion.php', <<<'PHP'
<?php
class Conexion {
    public static function conectar(): PDO {
        return new PDO(getenv('TEST_MODULES_DSN'), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
}
PHP);
file_put_contents($root . '/login/login.php', <<<'PHP'
<?php
// Fixture served only by the isolated local test server.
require __DIR__ . '/config/session.php';
cengi_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = [];
    if ($_POST['user'] === 'admin') $_SESSION['id_usuario'] = 101;
    if ($_POST['user'] === 'regular') $_SESSION['id_usuario'] = 202;
    // Stale/local credentials must not grant access.
    $_SESSION['uid'] = 1;
    $_SESSION['es_superadmin'] = 1;
    session_regenerate_id(true);
}
header('Content-Type: application/json');
echo json_encode(['authenticated'=>isset($_SESSION['id_usuario'])]);
PHP);
