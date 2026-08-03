<?php
declare(strict_types=1);

function app_env_value(string $key, string $default = ''): string
{
    static $env = null;

    if ($env === null) {
        $env = [];
        $envPaths = [
            __DIR__ . '/../.env',
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 2) . '/login/.env',
        ];

        foreach ($envPaths as $envPath) {
            if (!is_file($envPath)) {
                continue;
            }

            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$envKey, $envValue] = explode('=', $line, 2);
                $envKey = trim($envKey);
                if (!array_key_exists($envKey, $env)) {
                    $env[$envKey] = trim($envValue, " \t\n\r\0\x0B\"'");
                }
            }
        }
    }

    $value = getenv($key);
    if ($value !== false) {
        return (string) $value;
    }

    return $env[$key] ?? $default;
}

function app_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function app_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function app_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!app_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function app_foreign_key_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.table_constraints
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND constraint_name = ?
           AND constraint_type = ?'
    );
    $stmt->execute([$table, $constraint, 'FOREIGN KEY']);

    return (int) $stmt->fetchColumn() > 0;
}

function app_drop_foreign_key_if_exists(PDO $pdo, string $table, string $constraint): void
{
    if (app_foreign_key_exists($pdo, $table, $constraint)) {
        $pdo->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
    }
}

function app_normalize(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }

    return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
}

function app_bootstrap_database(PDO $pdo): void
{
    // El modulo ahora usa usuarios de la base principal `usuarios_menu`.
    // Estas FK antiguas apuntaban a la tabla local `usuarios` y rompen al guardar
    // solicitudes o seguimientos con IDs del login central.
    if (app_table_exists($pdo, 'solicitudes')) {
        app_drop_foreign_key_if_exists($pdo, 'solicitudes', 'solicitudes_ibfk_1');
    }
    if (app_table_exists($pdo, 'seguimientos')) {
        app_drop_foreign_key_if_exists($pdo, 'seguimientos', 'seguimientos_ibfk_2');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS programas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(120) NOT NULL UNIQUE,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuario_programa (
            usuario_id INT NOT NULL PRIMARY KEY,
            programa_id INT NOT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_usuario_programa_programa (programa_id)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS solicitudes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(20) NOT NULL UNIQUE,
            solicitante_id INT NOT NULL,
            programa_origen_id INT NOT NULL,
            programa_destino_id INT NULL,
            tipo ENUM('compra','ti','apoyo') NOT NULL,
            prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
            estado ENUM('recibido','proceso','completado','rechazado') NOT NULL DEFAULT 'recibido',
            titulo VARCHAR(180) NOT NULL,
            descripcion TEXT NOT NULL,
            fecha_requerida DATE NULL,
            proveedor_sugerido VARCHAR(160) NULL,
            monto_estimado DECIMAL(12,2) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (programa_origen_id) REFERENCES programas(id),
            FOREIGN KEY (programa_destino_id) REFERENCES programas(id)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS seguimientos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitud_id INT NOT NULL,
            usuario_id INT NOT NULL,
            estado ENUM('recibido','proceso','completado','rechazado') NOT NULL,
            observacion TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adjuntos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitud_id INT NOT NULL,
            nombre_original VARCHAR(255) NOT NULL,
            ruta VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NULL,
            tamano_bytes INT NULL,
            subido_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    if ((int) $pdo->query('SELECT COUNT(*) FROM programas')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO programas (id, nombre) VALUES
                (1, 'Variedades'),
                (2, 'Fisiologia y produccion agricola'),
                (3, 'Transferencia de Tecnologia'),
                (4, 'Manejo Integrado de Plagas y Enfermedades'),
                (5, 'Agromecanica Digital'),
                (6, 'Administracion'),
                (7, 'Laboratorio Agroindustrial'),
                (8, 'Direccion')"
        );
    }
}

function app_bootstrap_menu_data(PDO $menuPdo): void
{
    if (app_table_exists($menuPdo, 'modulos')) {
        $stmt = $menuPdo->prepare('SELECT id FROM modulos WHERE LOWER(nombre) = ? LIMIT 1');
        $stmt->execute(['solicitudes internas']);
        if (!$stmt->fetchColumn()) {
            $insert = $menuPdo->prepare('INSERT INTO modulos (nombre) VALUES (?)');
            $insert->execute(['Solicitudes internas']);
        }
    }

    if (!app_table_exists($menuPdo, 'permisos')) {
        return;
    }

    $permissions = [
        'solicitudes_internas.crear' => 'Permite crear solicitudes en el modulo Solicitudes internas',
        'solicitudes_internas.ver' => 'Permite ver las solicitudes del modulo Sistema de solicitudes',
        'solicitudes_internas.gestionar' => 'Permite gestionar las solicitudes del modulo Sistema de solicitudes',
        'solicitudes_internas.usuarios.ver' => 'Permite ver usuarios del modulo Sistema de solicitudes',
        'solicitudes_internas.usuarios.gestionar' => 'Permite crear, editar y eliminar usuarios del modulo Sistema de solicitudes',
        'solicitudes_internas.programas.ver' => 'Permite ver programas del modulo Sistema de solicitudes',
        'solicitudes_internas.programas.gestionar' => 'Permite crear, editar y eliminar programas del modulo Sistema de solicitudes',
    ];

    $stmt = $menuPdo->prepare(
        'INSERT IGNORE INTO permisos (nombre_permiso, descripcion) VALUES (?, ?)'
    );

    foreach ($permissions as $name => $description) {
        $stmt->execute([$name, $description]);
    }
}

$dbHost = app_env_value('DB_MENU_HOST', app_env_value('DB_HOST', '127.0.0.1'));
$dbPort = app_env_value('DB_MENU_PORT', app_env_value('DB_PORT', '3307'));
$dbUser = app_env_value('DB_MENU_USER', app_env_value('DB_USER', 'root'));
$dbPass = app_env_value('DB_MENU_PASS', app_env_value('DB_PASS', ''));
$menuDbName = app_env_value('DB_MENU_NAME', app_env_value('DB_NAME', 'usuarios_menu'));
$solicitudesDbName = app_env_value('DB_SOLICITUDES_NAME', app_env_value('SISTEMA_SOLICITUDES_DB_NAME', 'sistema_solicitudes'));

try {
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ];

    $serverPdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser,
        $dbPass,
        $pdoOptions
    );

    $serverPdo->exec(
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $solicitudesDbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$solicitudesDbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        $pdoOptions
    );

    $menuPdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$menuDbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        $pdoOptions
    );

    app_bootstrap_database($pdo);
    app_bootstrap_menu_data($menuPdo);
} catch (PDOException $e) {
    http_response_code(500);
    exit(
        'No se pudo conectar a la base de datos. Verifica la configuracion de MySQL. Detalle: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}
