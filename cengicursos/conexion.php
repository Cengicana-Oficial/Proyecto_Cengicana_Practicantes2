<?php

function leer_env_simple($archivo)
{
    if (!is_file($archivo)) {
        return [];
    }

    $variables = [];

    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);

        if ($linea === '' || strpos($linea, '#') === 0 || strpos($linea, '=') === false) {
            continue;
        }

        [$clave, $valor] = explode('=', $linea, 2);

        $variables[trim($clave)] = trim($valor, " \t\n\r\0\x0B\"'");
    }

    return $variables;
}

function cengicursos_env()
{
    static $env = null;

    if ($env !== null) {
        return $env;
    }

    $env = leer_env_simple(__DIR__ . '/.env');
    $loginEnv = leer_env_simple(__DIR__ . '/../login/.env');

    foreach ($loginEnv as $clave => $valor) {
        $env['LOGIN_' . $clave] = $valor;
    }

    return $env;
}

/**
 * MYSQL (MENU / USUARIOS)
 */
function cengicursos_abrir_mysqli($host, $puerto, $usuario, $pass, $bdd)
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $con = mysqli_init();
        $con->real_connect($host, $usuario, $pass, $bdd, (int)$puerto);
        $con->set_charset("utf8mb4");

        return $con;
    } catch (mysqli_sql_exception $e) {
        die(
            "Error MySQL hacia {$host}:{$puerto}/{$bdd}. Detalle: " .
            $e->getMessage()
        );
    }
}

/**
 * MYSQL (CURSOS)
 */
function conectar()
{
    static $conexion = null;

    if ($conexion instanceof PDO) {
        return $conexion;
    }

    $env = cengicursos_env();

    $host = $env['CENGICURSOS_DB_HOST'] ?? 'mysql';
    $port = $env['CENGICURSOS_DB_PORT'] ?? '3306';
    $dbname = $env['CENGICURSOS_DB_NAME'] ?? 'cengi_cursos';
    $user = $env['CENGICURSOS_DB_USER'] ?? '';
    $pass = $env['CENGICURSOS_DB_PASS'] ?? '';

    if ($host === '' || $dbname === '' || $user === '') {
        die('Error: variables CENGICURSOS_DB_* incompletas.');
    }

    try {

        $conexion = new PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        return $conexion;

    } catch (PDOException $e) {

        die(
            "Error MySQL de Cursos hacia {$host}:{$port}/{$dbname}. Detalle: " .
            $e->getMessage()
        );
    }
}

/**
 * MYSQL MENU / LOGIN
 */
function conectar_usuarios_menu()
{
    static $conexionUsuarios = null;

    if ($conexionUsuarios instanceof mysqli) {
        return $conexionUsuarios;
    }

    $env = cengicursos_env();

    $server = $env['DB_MENU_HOST']
        ?? $env['LOGIN_DB_HOST']
        ?? '127.0.0.1';

    $puerto = (int)(
        $env['DB_MENU_PORT']
        ?? $env['LOGIN_DB_PORT']
        ?? 3306
    );

    $usuario = $env['DB_MENU_USER']
        ?? $env['LOGIN_DB_USER']
        ?? 'root';

    $pass = $env['DB_MENU_PASS']
        ?? $env['LOGIN_DB_PASS']
        ?? '';

    $bdd = $env['DB_MENU_NAME']
        ?? $env['LOGIN_DB_NAME']
        ?? 'usuarios_menu';

    $conexionUsuarios = cengicursos_abrir_mysqli(
        $server,
        $puerto,
        $usuario,
        $pass,
        $bdd
    );

    return $conexionUsuarios;
}

/**
 * Conexion PDO hacia usuarios_menu, usada unicamente por las pantallas de
 * Cengicursos que necesitan reutilizar los helpers de PDO de
 * login/config/permisos_roles.php (roles.php). El resto del modulo sigue
 * usando conectar_usuarios_menu() (mysqli) para no romper nada existente.
 */
function conectar_usuarios_menu_pdo()
{
    static $conexion = null;

    if ($conexion instanceof PDO) {
        return $conexion;
    }

    $env = cengicursos_env();

    $host = $env['DB_MENU_HOST'] ?? $env['LOGIN_DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_MENU_PORT'] ?? $env['LOGIN_DB_PORT'] ?? '3306';
    $dbname = $env['DB_MENU_NAME'] ?? $env['LOGIN_DB_NAME'] ?? 'usuarios_menu';
    $user = $env['DB_MENU_USER'] ?? $env['LOGIN_DB_USER'] ?? 'root';
    $pass = $env['DB_MENU_PASS'] ?? $env['LOGIN_DB_PASS'] ?? '';

    try {
        $conexion = new PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return $conexion;
    } catch (PDOException $e) {
        die(
            "Error MySQL (usuarios_menu/PDO) hacia {$host}:{$port}/{$dbname}. Detalle: " .
            $e->getMessage()
        );
    }
}
