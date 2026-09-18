<?php
declare(strict_types=1);

// Modulo: Comites, Transferencia y Comunicacion.
// Mismo patron que sistema_de_solicitudes/config/database.php: se conecta a la
// base central `usuarios_menu` (menuPdo) para autenticacion/menu, y crea/usa
// su propia base de datos (por defecto `sistema_comites`) con bootstrap
// idempotente de todas las tablas del modulo.

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

/**
 * Migracion defensiva e idempotente: agrega una columna si todavia no existe.
 * Se usa para que una base ya creada con una version anterior del esquema
 * (CREATE TABLE IF NOT EXISTS no vuelve a ejecutarse sobre tablas existentes)
 * quede alineada con las columnas nuevas definidas en app_bootstrap_database()
 * sin necesidad de recrear tablas manualmente en cada despliegue.
 */
function app_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN ' . $definition);
    }
}

/**
 * Migracion defensiva e idempotente equivalente a app_ensure_column() pero
 * para llaves foraneas: agrega la restriccion FOREIGN KEY solo si la columna
 * indicada todavia no tiene ninguna (se verifica por columna, no por nombre
 * de constraint, para no duplicar la FK en instalaciones nuevas donde la
 * columna ya se creo con la FK inline dentro del propio CREATE TABLE).
 */
function app_ensure_foreign_key(PDO $pdo, string $table, string $column, string $constraintName, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $stmt->execute([$table, $column]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD CONSTRAINT `'
            . str_replace('`', '``', $constraintName) . '` ' . $definition
        );
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
    // Catalogo de los 14 comites tecnicos (ids tipo slug, referenciados por
    // el resto de las tablas transaccionales del modulo).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comites (
            id VARCHAR(40) PRIMARY KEY,
            nombre VARCHAR(160) NOT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Directorio de contactos (personas de ingenios y CENGICANA vinculadas a comites).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS contactos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(180) NOT NULL,
            cargo VARCHAR(180) NULL,
            empresa VARCHAR(180) NULL,
            telefono VARCHAR(40) NULL,
            email VARCHAR(180) NULL,
            estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS contacto_comite (
            contacto_id INT NOT NULL,
            comite_id VARCHAR(40) NOT NULL,
            PRIMARY KEY (contacto_id, comite_id),
            FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE CASCADE,
            FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Reuniones de comite y asistentes.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reuniones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comite_id VARCHAR(40) NULL,
            fecha DATE NULL,
            realizada TINYINT(1) NOT NULL DEFAULT 0,
            temas TEXT NULL,
            proximos_pasos TEXT NULL,
            responsable VARCHAR(180) NULL,
            cumplimiento INT NOT NULL DEFAULT 0,
            ayuda_memoria_nombre VARCHAR(255) NULL,
            ayuda_memoria_ruta VARCHAR(255) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reunion_asistentes (
            reunion_id INT NOT NULL,
            contacto_id INT NOT NULL,
            PRIMARY KEY (reunion_id, contacto_id),
            FOREIGN KEY (reunion_id) REFERENCES reuniones(id) ON DELETE CASCADE,
            FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Acuerdos y su seguimiento (pueden originarse desde una reunion o registrarse sueltos).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS acuerdos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comite_id VARCHAR(40) NULL,
            reunion_id INT NULL,
            fecha DATE NULL,
            acuerdo TEXT NOT NULL,
            responsable VARCHAR(180) NULL,
            plazo DATE NULL,
            fecha_seguimiento DATE NULL,
            avance INT NOT NULL DEFAULT 0,
            estado ENUM('Pendiente','En proceso','Completado') NOT NULL DEFAULT 'Pendiente',
            observaciones TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE SET NULL,
            FOREIGN KEY (reunion_id) REFERENCES reuniones(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Carpetas anidadas de la biblioteca documental. `ambito` separa el arbol
    // de "documentos" (ingenios) del de "biblioteca_interna" (interna); nunca
    // se mezclan. Debe crearse antes que `documentos` porque esta ultima
    // referencia `carpeta_id` a esta tabla.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS documento_carpetas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ambito ENUM('ingenios','interna') NOT NULL DEFAULT 'ingenios',
            nombre VARCHAR(160) NOT NULL,
            parent_id INT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES documento_carpetas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Biblioteca documental. `ambito` separa "ingenios" (boletines/publico externo)
    // de "interna" (memorias/presentaciones de uso interno), ya que el prototipo
    // las presenta como dos secciones de menu distintas sobre el mismo modelo.
    // `carpeta` (texto libre) se conserva por compatibilidad con instalaciones
    // previas, pero ya no se usa: la navegacion real es por `carpeta_id`
    // (carpetas anidadas en documento_carpetas).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ambito ENUM('ingenios','interna') NOT NULL DEFAULT 'ingenios',
            tipo ENUM('Boletin','Memoria','Informe','Ayudamemoria','Presentacion','Fotografia','Video','Otro') NOT NULL DEFAULT 'Otro',
            carpeta VARCHAR(160) NULL,
            carpeta_id INT NULL,
            titulo VARCHAR(220) NOT NULL,
            fecha DATE NULL,
            comite_id VARCHAR(40) NULL,
            autor VARCHAR(180) NULL,
            enlace VARCHAR(500) NULL,
            descripcion TEXT NULL,
            archivo_nombre VARCHAR(255) NULL,
            archivo_ruta VARCHAR(255) NULL,
            version INT NOT NULL DEFAULT 1,
            estado VARCHAR(80) NOT NULL DEFAULT 'En revision',
            observaciones TEXT NULL,
            causa_rechazo TEXT NULL,
            habilitado_envio TINYINT(1) NOT NULL DEFAULT 0,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE SET NULL,
            FOREIGN KEY (carpeta_id) REFERENCES documento_carpetas(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS documento_historial (
            id INT AUTO_INCREMENT PRIMARY KEY,
            documento_id INT NOT NULL,
            estado VARCHAR(120) NOT NULL,
            actor VARCHAR(180) NULL,
            fecha DATE NULL,
            observaciones TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Correos y envios estandarizados (referencian opcionalmente un documento).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS correos_enviados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            documento_id INT NULL,
            asunto VARCHAR(220) NOT NULL,
            cuerpo TEXT NULL,
            destinatarios TEXT NULL,
            grupo_destino VARCHAR(160) NULL,
            enviado_por VARCHAR(180) NULL,
            enviado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Tableros de indicadores (Power BI u otros, enlace externo).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tableros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(180) NOT NULL,
            descripcion TEXT NULL,
            url VARCHAR(500) NULL,
            tipo VARCHAR(80) NULL,
            estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Informes cuatrimestrales (plan operativo + informe + soportes/evidencias).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS informes_cuatrimestrales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            periodo VARCHAR(80) NOT NULL,
            area VARCHAR(180) NULL,
            plan_operativo_nombre VARCHAR(255) NULL,
            plan_operativo_ruta VARCHAR(255) NULL,
            informe_nombre VARCHAR(255) NULL,
            informe_ruta VARCHAR(255) NULL,
            evaluacion VARCHAR(80) NOT NULL DEFAULT 'En revision',
            observaciones TEXT NULL,
            fecha DATE NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS informe_soportes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            informe_id INT NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            ruta VARCHAR(255) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (informe_id) REFERENCES informes_cuatrimestrales(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Comite editorial (revisores) para la Memoria de resultados.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comite_editorial (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(180) NOT NULL,
            cargo VARCHAR(180) NULL,
            email VARCHAR(180) NULL,
            estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS memorias_resultados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero_memoria VARCHAR(40) NULL UNIQUE,
            titulo VARCHAR(220) NOT NULL,
            autor VARCHAR(180) NULL,
            autor_email VARCHAR(180) NULL,
            fecha DATE NULL,
            archivo_nombre VARCHAR(255) NULL,
            archivo_ruta VARCHAR(255) NULL,
            version INT NOT NULL DEFAULT 1,
            revisor_id INT NULL,
            estado VARCHAR(80) NOT NULL DEFAULT 'Recibida',
            criterios_archivo_nombre VARCHAR(255) NULL,
            criterios_archivo_ruta VARCHAR(255) NULL,
            revision_archivo VARCHAR(255) NULL,
            observaciones_revisor TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (revisor_id) REFERENCES comite_editorial(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS memoria_track (
            id INT AUTO_INCREMENT PRIMARY KEY,
            memoria_id INT NOT NULL,
            etapa VARCHAR(120) NOT NULL,
            fecha DATE NULL,
            estado VARCHAR(80) NULL,
            observaciones TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (memoria_id) REFERENCES memorias_resultados(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Publicaciones en redes sociales con metricas basicas de alcance/interaccion.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS publicaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NULL,
            plataforma VARCHAR(40) NULL,
            tipo VARCHAR(60) NULL,
            titulo VARCHAR(220) NOT NULL,
            copy_texto TEXT NULL,
            material VARCHAR(255) NULL,
            estado ENUM('Borrador','Programada','Publicada','Pausada') NOT NULL DEFAULT 'Borrador',
            responsable VARCHAR(180) NULL,
            url VARCHAR(500) NULL,
            alcance INT NOT NULL DEFAULT 0,
            impresiones INT NOT NULL DEFAULT 0,
            interacciones INT NOT NULL DEFAULT 0,
            reacciones INT NOT NULL DEFAULT 0,
            comentarios INT NOT NULL DEFAULT 0,
            compartidos INT NOT NULL DEFAULT 0,
            clics INT NOT NULL DEFAULT 0,
            reproducciones INT NOT NULL DEFAULT 0,
            guardados INT NOT NULL DEFAULT 0,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Migracion defensiva: alinea bases creadas antes de agregar estas columnas.
    app_ensure_column($pdo, 'reuniones', 'proximos_pasos', 'proximos_pasos TEXT NULL AFTER temas');
    app_ensure_column($pdo, 'reuniones', 'ayuda_memoria_nombre', 'ayuda_memoria_nombre VARCHAR(255) NULL AFTER cumplimiento');
    app_ensure_column($pdo, 'reuniones', 'ayuda_memoria_ruta', 'ayuda_memoria_ruta VARCHAR(255) NULL AFTER ayuda_memoria_nombre');
    app_ensure_column($pdo, 'acuerdos', 'fecha_seguimiento', 'fecha_seguimiento DATE NULL AFTER plazo');
    app_ensure_column($pdo, 'acuerdos', 'observaciones', 'observaciones TEXT NULL AFTER estado');
    app_ensure_column($pdo, 'documentos', 'carpeta_id', 'carpeta_id INT NULL AFTER carpeta');
    app_ensure_foreign_key(
        $pdo,
        'documentos',
        'carpeta_id',
        'fk_documentos_carpeta',
        'FOREIGN KEY (carpeta_id) REFERENCES documento_carpetas(id) ON DELETE SET NULL'
    );

    // Migracion de datos: instalaciones que ya tenian documentos antes de
    // agregar `carpeta_id` (o filas insertadas sin carpeta) quedarian
    // invisibles en la nueva navegacion por carpetas (solo se listan
    // documentos dentro de una carpeta). Se reubican en una carpeta raiz
    // "Documentos existentes" por ambito, generada una sola vez.
    foreach (['ingenios', 'interna'] as $ambitoMigracion) {
        $huerfanosStmt = $pdo->prepare('SELECT COUNT(*) FROM documentos WHERE ambito = ? AND carpeta_id IS NULL');
        $huerfanosStmt->execute([$ambitoMigracion]);
        if ((int) $huerfanosStmt->fetchColumn() === 0) {
            continue;
        }

        $carpetaStmt = $pdo->prepare(
            "SELECT id FROM documento_carpetas WHERE ambito = ? AND parent_id IS NULL AND nombre = 'Documentos existentes' LIMIT 1"
        );
        $carpetaStmt->execute([$ambitoMigracion]);
        $carpetaDestinoId = $carpetaStmt->fetchColumn();

        if (!$carpetaDestinoId) {
            // No se usa comites_carpeta_crear() (includes/domain.php) porque
            // ese archivo todavia no esta cargado cuando corre el bootstrap.
            $pdo->prepare('INSERT INTO documento_carpetas (ambito, nombre, parent_id) VALUES (?, ?, NULL)')
                ->execute([$ambitoMigracion, 'Documentos existentes']);
            $carpetaDestinoId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE documentos SET carpeta_id = ? WHERE ambito = ? AND carpeta_id IS NULL')
            ->execute([(int) $carpetaDestinoId, $ambitoMigracion]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM comites')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO comites (id, nombre) VALUES (?, ?)');
        foreach ([
            ['riego', 'Comite de Riego'],
            ['nutricion', 'Comite de Nutricion'],
            ['malezas', 'Comite de Malezas y Madurantes'],
            ['canamip', 'Comite CAÑAMIP'],
            ['capacitacion', 'Comite de Capacitacion'],
            ['cosecha', 'Comite de Cosecha'],
            ['rtk', 'Comite RTK'],
            ['siembra', 'Comite de Siembra'],
            ['variedades', 'Comite de Variedades'],
            ['agri-inteligente', 'Comite de Agricultura Inteligente'],
            ['investigacion', 'Comite de Investigacion'],
            ['estrategico', 'Comite Estrategico'],
            ['tactico', 'Comite Tactico'],
            ['industrial', 'Comite Industrial'],
        ] as [$id, $nombre]) {
            $insert->execute([$id, $nombre]);
        }
    }
}

function app_bootstrap_menu_data(PDO $menuPdo): void
{
    $moduleName = 'Comites, Transferencia y Comunicacion';

    if (app_table_exists($menuPdo, 'modulos')) {
        $stmt = $menuPdo->prepare('SELECT id FROM modulos WHERE LOWER(nombre) = LOWER(?) LIMIT 1');
        $stmt->execute([$moduleName]);
        if (!$stmt->fetchColumn()) {
            $insert = $menuPdo->prepare('INSERT INTO modulos (nombre) VALUES (?)');
            $insert->execute([$moduleName]);
        }
    }

    if (!app_table_exists($menuPdo, 'permisos')) {
        return;
    }

    $recursos = [
        'contactos' => 'directorio de contactos',
        'reuniones' => 'reuniones de comites',
        'acuerdos' => 'acuerdos y seguimiento',
        'documentos' => 'biblioteca documental para ingenios',
        'biblioteca_interna' => 'biblioteca documental interna',
        'correos' => 'correos y envios',
        'tableros' => 'tableros',
        'informes' => 'informes cuatrimestrales',
        'memorias' => 'memoria de resultados',
        'publicaciones' => 'redes sociales y publicaciones',
        'estadisticas' => 'estadisticas',
        'configuracion' => 'configuracion del modulo',
    ];

    $permissions = [];
    foreach ($recursos as $recurso => $descripcion) {
        $permissions["comites.{$recurso}.ver"] = "Permite ver {$descripcion} del modulo Comites, Transferencia y Comunicacion";
        $permissions["comites.{$recurso}.gestionar"] = "Permite gestionar {$descripcion} del modulo Comites, Transferencia y Comunicacion";
    }

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
$comitesDbName = app_env_value('DB_COMITES_NAME', app_env_value('COMITES_DB_NAME', 'sistema_comites'));

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
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $comitesDbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$comitesDbName};charset=utf8mb4",
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
