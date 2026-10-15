<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

class Conexion {
    private static $envLoaded = false;
    private static $loginEnv = null;

    private static function cargarEnv() {
        if (self::$envLoaded) {
            return;
        }

        // La configuracion del modulo vive fuera de public/ y nunca se versiona.
        // safeLoad permite que Apache use variables del sistema en produccion.
        // Carga siempre los valores del .env del módulo. En Apache puede
        // existir una variable vacía heredada del servicio; createImmutable
        // conservaría esa variable y dejaría la conexión sin credenciales.
        // Las variables del entorno del proceso siguen funcionando cuando no
        // hay archivo .env (safeLoad no falla si el archivo no existe).
        $dotenv = Dotenv::createMutable(dirname(__DIR__));
        $dotenv->safeLoad();
        self::$envLoaded = true;
    }

    private static function env($key, $default = null) {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    private static function isPlaceholder($value): bool {
        if (!is_string($value) || trim($value) === '') {
            return true;
        }

        return (bool) preg_match(
            '/(?:host-mysql-aiven|usuario_aiven|nombre_base_visitas|clave_aiven|project-ref|aws-0-region|REEMPLAZAR|clave_base_supabase)/i',
            $value
        );
    }

    private static function loginEnv($key, $default = null) {
        if (self::$loginEnv === null) {
            self::$loginEnv = [];
            $envCandidates = [
                dirname(__DIR__, 2) . '/login/.env',
                __DIR__ . '/../.env',
            ];
            foreach ($envCandidates as $candidate) {
                if (!is_file($candidate)) {
                    continue;
                }

                foreach (file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);

                    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                        continue;
                    }

                    [$envKey, $envValue] = explode('=', $line, 2);
                    $envKey = trim($envKey);
                    $envValue = trim($envValue, " \t\n\r\0\x0B\"'");
                    if (!array_key_exists($envKey, self::$loginEnv)
                        || self::isPlaceholder(self::$loginEnv[$envKey])) {
                        self::$loginEnv[$envKey] = $envValue;
                    }
                }
            }
        }

        return self::$loginEnv[$key] ?? $default;
    }

    public static function conectar() {
        self::cargarEnv();

        // Supabase PostgreSQL es el backend predeterminado. El modo MySQL se
        // conserva únicamente para instalaciones antiguas que todavía lo
        // indiquen explícitamente en su .env.
        $driver = strtolower((string) self::env('VISITS_DB_DRIVER', 'pgsql'));
        if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
            return self::conectarSupabase();
        }

        $host = self::env('DB_HOST');
        $usingLegacyConnection = self::isPlaceholder($host);
        $port = self::env('DB_PORT', self::loginEnv('DB_PORT', 3306));
        $db   = self::env('DB_NAME');
        $user = self::env('DB_USER');
        $pass = self::env('DB_PASS', self::env('DB_PASSWORD'));

        // Un .env recién copiado puede conservar los ejemplos. En ese caso se
        // mantiene la conexión heredada de Aiven hasta terminar la migración.
        $host = $usingLegacyConnection ? self::loginEnv('DB_HOST') : $host;
        $port = $usingLegacyConnection ? self::loginEnv('DB_PORT', 3306) : $port;
        $db = self::isPlaceholder($db) ? 'Pruebas' : $db;
        $user = self::isPlaceholder($user) ? self::loginEnv('DB_USER') : $user;
        $pass = self::isPlaceholder($pass)
            ? self::loginEnv('DB_PASS', self::loginEnv('DB_PASSWORD', ''))
            : $pass;

        if (!$host || !$db || !$user) {
            throw new RuntimeException('Faltan las variables de conexion de la base de Visitas.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $sslMode = self::env('DB_SSL_MODE', self::loginEnv('DB_SSL_MODE', ''));
        if (strtoupper((string) $sslMode) === 'REQUIRED') {
            $caPath = __DIR__ . '/ca.pem';
            if (!is_file($caPath)) {
                throw new RuntimeException('No se encontro el certificado SSL de Aiven.');
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        return new PDO(
            "mysql:host=$host;port=$port;dbname=$db;charset=utf8",
            $user,
            $pass,
            $options
        );
    }

    public static function conectarUsuariosMenu() {
        self::cargarEnv();

        $driver = strtolower((string) self::env('VISITS_DB_DRIVER', 'pgsql'));
        if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
            return self::conectarSupabase();
        }

        $host = self::env('DB_MENU_HOST', self::loginEnv('DB_HOST'));
        $port = self::env('DB_MENU_PORT', self::loginEnv('DB_PORT', 3306));
        $db   = self::env('DB_MENU_NAME', self::loginEnv('DB_NAME', 'usuarios_menu'));
        $user = self::env('DB_MENU_USER', self::loginEnv('DB_USER'));
        $pass = self::env('DB_MENU_PASS', self::loginEnv('DB_PASS', self::loginEnv('DB_PASSWORD', '')));

        if (!$host || !$db || !$user) {
            throw new RuntimeException('Faltan las variables de conexion heredadas de usuarios/menu.');
        }

        return new PDO(
            "mysql:host=$host;port=$port;dbname=$db;charset=utf8",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private static function conectarSupabase() {
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException(
                'La extension pdo_pgsql no esta habilitada en PHP. Habilita extension=pdo_pgsql en php.ini y reinicia Apache.'
            );
        }

        $databaseUrl = (string) self::env(
            'VISITS_DATABASE_URL',
            self::env('SUPABASE_DATABASE_URL', '')
        );
        if ($databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            if ($parts === false || empty($parts['host']) || empty($parts['user'])) {
                throw new RuntimeException('VISITS_DATABASE_URL no tiene un formato PostgreSQL valido.');
            }
            $host = $parts['host'];
            $port = $parts['port'] ?? 5432;
            $db = ltrim((string) ($parts['path'] ?? '/postgres'), '/');
            $user = rawurldecode((string) $parts['user']);
            $pass = rawurldecode((string) ($parts['pass'] ?? ''));
            parse_str((string) ($parts['query'] ?? ''), $query);
            $sslMode = $query['sslmode'] ?? 'require';
        } else {
            $host = self::env('VISITS_DB_HOST');
            $port = self::env('VISITS_DB_PORT', 5432);
            $db = self::env('VISITS_DB_NAME', 'postgres');
            $user = self::env('VISITS_DB_USER');
            $pass = self::env('VISITS_DB_PASS', '');
            $sslMode = self::env('VISITS_DB_SSLMODE', 'require');
        }

        if (!$host || !$db || !$user || self::isPlaceholder($host) || self::isPlaceholder($user)) {
            throw new RuntimeException('Faltan las variables VISITS_DB_* para conectar Visitas con Supabase.');
        }

        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname={$db};sslmode={$sslMode}",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("set search_path to visits, public");
        return $pdo;
    }

    public static function driver(): string {
        self::cargarEnv();
        $driver = strtolower((string) self::env('VISITS_DB_DRIVER', 'pgsql'));
        return in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? 'pgsql' : 'mysql';
    }

    public static function setting(string $key, $default = null) {
        self::cargarEnv();
        return self::env($key, $default);
    }

    public static function groupConcat(string $expression, bool $distinct = false, ?string $orderBy = null): string {
        if (self::driver() === 'pgsql') {
            $distinctSql = $distinct ? 'distinct ' : '';
            // PostgreSQL exige que, cuando se usa DISTINCT, el ORDER BY de
            // string_agg repita exactamente la misma expresión del primer
            // argumento (incluido el casteo a text).
            $orderSql = $orderBy ? ' order by (' . $orderBy . ')::text' : '';
            return "string_agg({$distinctSql}({$expression})::text, ', '{$orderSql})";
        }

        $distinctSql = $distinct ? 'DISTINCT ' : '';
        $orderSql = $orderBy ? ' ORDER BY ' . $orderBy : '';
        return "GROUP_CONCAT({$distinctSql}{$expression}{$orderSql} SEPARATOR ', ')";
    }
}
