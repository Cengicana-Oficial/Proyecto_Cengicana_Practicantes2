<?php
require_once __DIR__ . '/env.php';

class Conexion {
    private static $pdo = null;

    public static function conectar() {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        try {
            $host = self::env('DB_HOST');
            $port = self::env('DB_PORT', '3306');
            $db = self::env('DB_NAME');
            $user = self::env('DB_USER');
            $pass = self::env('DB_PASSWORD', self::env('DB_PASS', ''));

            if ($host === '' || $db === '' || $user === '') {
                throw new RuntimeException('Variables de base de datos incompletas en Laboratorio/.env.');
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $conn = new PDO($dsn, $user, $pass);

            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            self::$pdo = $conn;

            return self::$pdo;
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 1049) {
                throw new RuntimeException('Error de conexion: la base de datos configurada en DB_NAME no existe o no es accesible para este usuario.');
            }

            throw new RuntimeException('Error de conexion: ' . $e->getMessage());
        }
    }

    private static function env(string $key, string $default = ''): string {
        $value = getenv($key);

        if ($value === false && isset($_ENV[$key])) {
            $value = $_ENV[$key];
        }

        if ($value === false && isset($_SERVER[$key])) {
            $value = $_SERVER[$key];
        }

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return trim((string) $value);
    }
}

$conexion = Conexion::conectar();
?>
