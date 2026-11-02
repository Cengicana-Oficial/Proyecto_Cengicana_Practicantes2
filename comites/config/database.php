<?php
declare(strict_types=1);

// Los plazos y la bitácora usan la fecha local de Guatemala, también fuera de Docker.
date_default_timezone_set('America/Guatemala');

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

/**
 * Tablas, columnas y llaves del modulo. Solo corre al desplegar (deploy/mysql/migrar-esquema.php,
 * con el usuario de migraciones): las peticiones no hacen DDL (AUD-2026-001/I-2).
 */
function app_esquema_database(PDO $pdo): void
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
            estado ENUM('Pendiente','En proceso','Completado','Cancelado') NOT NULL DEFAULT 'Pendiente',
            observaciones TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE SET NULL,
            FOREIGN KEY (reunion_id) REFERENCES reuniones(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $acuerdoEstado = $pdo->query("SHOW COLUMNS FROM acuerdos LIKE 'estado'")->fetch();
    if (!str_contains((string)$acuerdoEstado['Type'], "'Cancelado'")) {
        $pdo->exec("ALTER TABLE acuerdos MODIFY estado ENUM('Pendiente','En proceso','Completado','Cancelado') NOT NULL DEFAULT 'Pendiente'");
    }
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
    // Redes sociales: grupos/listas de WhatsApp a los que se envio y origen del registro (manual o importado).
    app_ensure_column($pdo, 'publicaciones', 'destinos', 'destinos TEXT NULL AFTER url');
    app_ensure_column($pdo, 'publicaciones', 'origen', "origen VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER destinos");
    // ID de la publicacion en Meta/LinkedIn para actualizarla en cada sincronizacion por API.
    app_ensure_column($pdo, 'publicaciones', 'externo_id', 'externo_id VARCHAR(120) NULL AFTER origen');
    // Campanita: avisos que cada usuario ya leyo (la clave identifica el aviso y su estado).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notificaciones_leidas (
            usuario_id INT NOT NULL,
            clave VARCHAR(190) NOT NULL,
            leido_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (usuario_id, clave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Notificaciones por correo (includes/correo-eventos.php): configuracion por evento,
    // ajustes generales y cola de envio.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS correo_eventos (
            evento VARCHAR(60) NOT NULL PRIMARY KEY,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            asunto VARCHAR(250) NOT NULL DEFAULT '',
            cuerpo TEXT NULL,
            destinatarios TEXT NULL,
            dias INT NULL,
            actualizado_por VARCHAR(180) NULL,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS correo_ajustes (
            clave VARCHAR(60) NOT NULL PRIMARY KEY,
            valor TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS correo_cola (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evento VARCHAR(60) NOT NULL,
            destinatario VARCHAR(190) NOT NULL,
            destinatario_original VARCHAR(190) NOT NULL,
            nombre VARCHAR(180) NOT NULL DEFAULT '',
            asunto VARCHAR(250) NOT NULL,
            cuerpo_html MEDIUMTEXT NOT NULL,
            enlace VARCHAR(500) NOT NULL DEFAULT '',
            referencia VARCHAR(190) NOT NULL DEFAULT '',
            estado ENUM('pendiente','enviando','enviado','error') NOT NULL DEFAULT 'pendiente',
            intentos TINYINT NOT NULL DEFAULT 0,
            error VARCHAR(250) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            enviado_en DATETIME NULL,
            KEY idx_correo_estado (estado, id),
            KEY idx_correo_referencia (evento, destinatario_original, referencia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Conexiones directas por API: configuracion visible y credenciales cifradas (AES-256-GCM).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS redes_conexiones (
            proveedor VARCHAR(20) PRIMARY KEY,
            config_json TEXT NULL,
            secreto TEXT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            ultimo_sync DATETIME NULL,
            ultimo_estado VARCHAR(10) NULL,
            ultimo_mensaje TEXT NULL,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Metricas mensuales del sitio web (Google Analytics 4), capturadas o importadas.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sitio_web_metricas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            periodo CHAR(7) NOT NULL UNIQUE,
            usuarios INT NOT NULL DEFAULT 0,
            nuevos_usuarios INT NOT NULL DEFAULT 0,
            sesiones INT NOT NULL DEFAULT 0,
            vistas INT NOT NULL DEFAULT 0,
            duracion_seg INT NOT NULL DEFAULT 0,
            rebote DECIMAL(5,2) NOT NULL DEFAULT 0,
            sesiones_redes INT NOT NULL DEFAULT 0,
            sesiones_whatsapp INT NOT NULL DEFAULT 0,
            notas TEXT NULL,
            fuente VARCHAR(60) NULL,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    app_ensure_foreign_key(
        $pdo,
        'documentos',
        'carpeta_id',
        'fk_documentos_carpeta',
        'FOREIGN KEY (carpeta_id) REFERENCES documento_carpetas(id) ON DELETE SET NULL'
    );
}

function app_bootstrap_database(PDO $pdo): void
{
    if (defined('ESQUEMA_MIGRANDO')) {
        app_esquema_database($pdo);
    }

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
        'eventos' => 'eventos de transferencia de tecnologia',
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
        'usuarios' => 'usuarios, roles y programas',
    ];

    $permissions = [
        'comites.informes.revisar' => 'Revision de informes cuatrimestrales por Direccion',
        'comites.memorias.entregar' => 'Entregar articulos propios para memoria de resultados',
        'comites.memorias.diagramar' => 'Diagramacion de articulos y consolidado MPR',
        'comites.memorias.aprobar_transferencia' => 'Aprobacion de version final del consolidado MPR',
        'comites.memorias.aprobar_direccion' => 'Aprobacion de liberacion del consolidado MPR',
        'comites.memorias.ver_todos' => 'Consultar todos los articulos de memoria de resultados',
        'comites.documentos.revisar_transferencia' => 'Primera y segunda revision de documentos para ingenios',
        'comites.documentos.aprobar_direccion' => 'Aprobacion final de documentos para ingenios',
    ];
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

    comites_bootstrap_roles($menuPdo, array_keys($permissions));
}

/**
 * Permisos de Comites por rol de la plataforma. Los roles Jefe de programa,
 * Colaborador de programa y Director son los mismos que usa Solicitudes
 * internas; el Jefe de Transferencia y el Director General se derivan del
 * programa asignado (ver comites_permisos_por_programa()).
 */
function comites_roles_definicion(array $todos): array
{
    $ver = static fn(array $secciones): array => array_map(static fn($s) => "comites.$s.ver", $secciones);
    return [
        'Superadmin' => $todos,
        'Director' => array_merge(
            $ver(['contactos', 'reuniones', 'eventos', 'acuerdos', 'documentos', 'biblioteca_interna', 'correos', 'tableros', 'informes', 'memorias', 'publicaciones', 'estadisticas', 'configuracion', 'usuarios']),
            ['comites.documentos.aprobar_direccion', 'comites.informes.revisar', 'comites.memorias.aprobar_direccion', 'comites.memorias.ver_todos']
        ),
        'Jefe de programa' => array_merge(
            $ver(['contactos', 'reuniones', 'eventos', 'acuerdos', 'documentos', 'biblioteca_interna', 'tableros', 'informes', 'memorias', 'estadisticas']),
            ['comites.acuerdos.gestionar', 'comites.memorias.entregar', 'comites.memorias.ver_todos']
        ),
        'Colaborador de programa' => array_merge(
            $ver(['reuniones', 'acuerdos', 'documentos', 'biblioteca_interna', 'memorias']),
            ['comites.memorias.entregar']
        ),
    ];
}

/**
 * Crea los roles que falten y les asigna los permisos de Comites solo si
 * todavia no tienen ninguno del modulo: lo que despues se edite desde la
 * gestion de roles de la plataforma se respeta.
 */
function comites_bootstrap_roles(PDO $menuPdo, array $todos): void
{
    if (!app_table_exists($menuPdo, 'roles') || !app_table_exists($menuPdo, 'rol_permiso')) {
        return;
    }
    $buscar = $menuPdo->prepare('SELECT id FROM roles WHERE nombre_rol = ? LIMIT 1');
    $crear = $menuPdo->prepare('INSERT INTO roles (nombre_rol) VALUES (?)');
    $tiene = $menuPdo->prepare("SELECT COUNT(*) FROM rol_permiso rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ? AND p.nombre_permiso LIKE 'comites.%'");
    $asignar = $menuPdo->prepare('INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE nombre_permiso = ?');
    foreach (comites_roles_definicion($todos) as $rol => $permisos) {
        $buscar->execute([$rol]);
        $rolId = (int) $buscar->fetchColumn();
        if (!$rolId) {
            $crear->execute([$rol]);
            $rolId = (int) $menuPdo->lastInsertId();
        }
        $tiene->execute([$rolId]);
        if ((int) $tiene->fetchColumn() > 0) {
            continue;
        }
        foreach ($permisos as $permiso) {
            $asignar->execute([$rolId, $permiso]);
        }
    }
}

$dbHost = app_env_value('DB_MENU_HOST', app_env_value('DB_HOST', '127.0.0.1'));
$dbPort = app_env_value('DB_MENU_PORT', app_env_value('DB_PORT', '3307'));
$dbUser = app_env_value('DB_MENU_USER', app_env_value('DB_USER', 'root'));
$dbPass = app_env_value('DB_MENU_PASS', app_env_value('DB_PASS', ''));
$menuDbName = app_env_value('DB_MENU_NAME', app_env_value('DB_NAME', 'usuarios_menu'));
$comitesDbName = app_env_value('DB_COMITES_NAME', app_env_value('COMITES_DB_NAME', 'sistema_comites'));
if (defined('ESQUEMA_MIGRANDO')) {
    // deploy/mysql/migrar-esquema.php: el esquema se crea con el usuario de migraciones.
    $dbUser = (string) getenv('MIGRADOR_USER');
    $dbPass = (string) getenv('MIGRADOR_PASS');
}

try {
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-06:00'",
    ];

    if (defined('ESQUEMA_MIGRANDO')) {
        $serverPdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
            $dbUser,
            $dbPass,
            $pdoOptions
        );

        $serverPdo->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $comitesDbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

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
    if (defined('ESQUEMA_MIGRANDO')) {
        throw $e;
    }
    http_response_code(500);
    exit(
        'No se pudo conectar a la base de datos. Verifica la configuracion de MySQL. Detalle: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}
