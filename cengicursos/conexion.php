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
        error_log("Error MySQL hacia {$host}:{$puerto}/{$bdd}. Detalle: " . $e->getMessage());
        die('No fue posible conectar a la base de datos. Intente mas tarde.');
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

        error_log("Error MySQL de Cursos hacia {$host}:{$port}/{$dbname}. Detalle: " . $e->getMessage());
        die('No fue posible conectar a la base de datos. Intente mas tarde.');
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
 * Mueve un archivo ya subido por HTTP (tmp_name de $_FILES) a
 * uploads/{subcarpeta}/ dentro del repo, y devuelve una URL absoluta desde
 * la raiz del sitio ("/uploads/{subcarpeta}/archivo.pdf") lista para usarse
 * como href, tanto desde PHP como desde JS.
 *
 * Antes, cada punto de subida (diplomas.php, guardar_control.php) construia
 * la ruta de destino como una cadena RELATIVA de filesystem ("../uploads/..")
 * y guardaba ese mismo string relativo en la BD para usarlo despues como
 * href. Eso es fragil por dos razones: (1) move_uploaded_file() con una ruta
 * relativa depende del cwd del proceso PHP en ese momento, que no esta
 * garantizado que sea el directorio del script en todos los entornos/SAPIs
 * (puede diferir entre local y produccion aunque el codigo sea identico); y
 * (2) un string relativo solo se resuelve bien como URL si quien lo consume
 * lo hace desde la misma profundidad de directorio (o via `new URL(path,
 * location.href)` desde esa misma pagina), lo cual no es un invariante real
 * del sistema.
 *
 * Esta funcion usa __DIR__ (ubicacion real del archivo conexion.php en
 * cengicursos/, siempre correcta sin importar el cwd) para calcular la ruta
 * de escritura, y guarda/devuelve una URL raiz-absoluta ("/uploads/...").
 * cengicursos/ y uploads/ son directorios hermanos bajo el DocumentRoot (ver
 * dockerfiles/modulos-app/error-pages.conf, que ya asume rutas raiz-absolutas
 * como "/cengicursos/404.php"), asi que "/uploads/..." sirve igual sin
 * importar desde que pagina o profundidad se genere el enlace.
 *
 * Los registros historicos en BD que ya tienen el formato relativo antiguo
 * ("../uploads/...") siguen funcionando: el navegador resuelve igual de bien
 * un href relativo que uno raiz-absoluto: ambos formatos pueden convivir sin
 * necesidad de migrar datos existentes.
 *
 * Devuelve null si algo falla (y deja detalle en error_log para diagnostico).
 */
function cengi_guardar_archivo_subido($rutaTemporal, $subcarpeta, $nombreArchivo)
{
    $directorioAbsoluto = __DIR__ . '/../uploads/' . $subcarpeta;

    if (!is_dir($directorioAbsoluto)) {
        $creado = @mkdir($directorioAbsoluto, 0775, true);
        if (!$creado && !is_dir($directorioAbsoluto)) {
            error_log(sprintf(
                'cengicursos: no se pudo crear el directorio de subida "%s" (revisar el volumen "uploads/" montado en el host, ver docker-compose.prod.yml).',
                $directorioAbsoluto
            ));
            return null;
        }
    }

    if (!is_writable($directorioAbsoluto)) {
        error_log(sprintf(
            'cengicursos: el directorio de subida "%s" no es escribible por el usuario del servidor web (revisar chown/chmod del volumen montado; ver dockerfiles/modulos-app/entrypoint.sh).',
            realpath($directorioAbsoluto) ?: $directorioAbsoluto
        ));
        return null;
    }

    $rutaAbsolutaDestino = $directorioAbsoluto . '/' . $nombreArchivo;

    if (!move_uploaded_file($rutaTemporal, $rutaAbsolutaDestino)) {
        $ultimoError = error_get_last();
        error_log(sprintf(
            'cengicursos: move_uploaded_file("%s", "%s") fallo. Ultimo error PHP: %s',
            $rutaTemporal,
            $rutaAbsolutaDestino,
            $ultimoError['message'] ?? 'desconocido'
        ));
        return null;
    }

    return '/uploads/' . $subcarpeta . '/' . $nombreArchivo;
}

/**
 * Normaliza cualquier valor guardado historicamente en BD para un archivo de
 * subida (diplomas.pdf_path, control_cursos.diploma, etc.) a la URL
 * raiz-absoluta correcta ("/uploads/{subcarpeta}/archivo.pdf") que
 * cengi_guardar_archivo_subido() devuelve hoy en dia.
 *
 * Existen registros antiguos guardados antes de esa migracion con formatos
 * distintos: rutas relativas de filesystem ("../uploads/diplomas/x.pdf"),
 * rutas absolutas de filesystem completas ("/var/www/html/uploads/..." o
 * incluso con separadores de Windows), o rutas que arrastran el prefijo del
 * modulo ("cengicursos/uploads/..."). Ninguno de esos formatos es seguro de
 * usar tal cual como href: solo "funcionan por accidente" si la pagina que
 * los consume esta exactamente al mismo nivel de profundidad que cuando se
 * guardaron. Esta funcion no asume eso: siempre devuelve el mismo formato
 * raiz-absoluto sin importar como haya quedado guardado el valor original.
 *
 * Devuelve '' si el valor esta vacio. Si el valor ya es una URL http(s)
 * completa, se respeta tal cual.
 */
function cengi_normalizar_url_archivo($valor)
{
    $valor = trim((string) ($valor ?? ''));

    if ($valor === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $valor)) {
        return $valor;
    }

    // Normaliza separadores de Windows por si algun path legado se guardo con backslashes.
    $valor = str_replace('\\', '/', $valor);

    // "uploads/" es el unico segmento realmente relevante para armar la URL: sin importar
    // cuanto prefijo de filesystem o de modulo traiga por delante (".." , "/var/www/html",
    // "cengicursos", etc.), nos quedamos solo con esa parte.
    $posicionUploads = stripos($valor, 'uploads/');
    if ($posicionUploads !== false) {
        return '/' . substr($valor, $posicionUploads);
    }

    // Ya viene como URL raiz-absoluta ("/algo/..."): se respeta tal cual.
    if ($valor[0] === '/') {
        return $valor;
    }

    // Cualquier otro formato relativo no reconocido: se interpreta como relativo a la
    // raiz del sitio en vez de dejarlo depender de la profundidad de la pagina actual.
    return '/' . ltrim($valor, './');
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
        error_log("Error MySQL (usuarios_menu/PDO) hacia {$host}:{$port}/{$dbname}. Detalle: " . $e->getMessage());
        die('No fue posible conectar a la base de datos. Intente mas tarde.');
    }
}
