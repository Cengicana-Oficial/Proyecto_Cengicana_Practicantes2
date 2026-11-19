<?php
declare(strict_types=1);

// Modulo: Inventario de precursores (SICIR). Mismo patron que comites/config/database.php:
// se conecta a la base central `usuarios_menu` ($menuPdo: autenticacion, menu, roles) y a su
// propia base (por defecto `sistema_inventario`, $pdo). Las peticiones web NO hacen DDL: el
// esquema y los datos del menu los crea deploy/mysql/migrar-esquema.php (modulo "inventario")
// con el usuario de migraciones (AUD-2026-001/I-2).

date_default_timezone_set('America/Guatemala');

function inv_env(string $key, string $default = ''): string
{
    static $env = null;

    if ($env === null) {
        $env = [];
        $rutas = [
            __DIR__ . '/../.env',
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 2) . '/login/.env',
        ];
        foreach ($rutas as $ruta) {
            if (!is_file($ruta)) {
                continue;
            }
            foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
                $linea = trim($linea);
                if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $linea, 2);
                $k = trim($k);
                if (!array_key_exists($k, $env)) {
                    $env[$k] = trim($v, " \t\n\r\0\x0B\"'");
                }
            }
        }
    }

    $valor = getenv($key);
    if ($valor !== false) {
        return (string) $valor;
    }

    return $env[$key] ?? $default;
}

$dbHost = inv_env('DB_MENU_HOST', inv_env('DB_HOST', '127.0.0.1'));
$dbPort = inv_env('DB_MENU_PORT', inv_env('DB_PORT', '3307'));
$dbUser = inv_env('DB_MENU_USER', inv_env('DB_USER', 'root'));
$dbPass = inv_env('DB_MENU_PASS', inv_env('DB_PASS', ''));
$menuDbName = inv_env('DB_MENU_NAME', inv_env('DB_NAME', 'usuarios_menu'));
$inventarioDbName = inv_env('DB_INVENTARIO_NAME', 'sistema_inventario');
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
        $servidorPdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, $pdoOptions);
        $servidorPdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $inventarioDbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$inventarioDbName};charset=utf8mb4", $dbUser, $dbPass, $pdoOptions);
    $menuPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$menuDbName};charset=utf8mb4", $dbUser, $dbPass, $pdoOptions);
} catch (PDOException $e) {
    if (defined('ESQUEMA_MIGRANDO')) {
        throw $e;
    }
    error_log('inventario_precursores: no se pudo conectar a MySQL: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo conectar a la base de datos. Intenta de nuevo en unos minutos o contacta al administrador.');
}

/**
 * Tablas del modulo. Solo corre al desplegar (deploy/mysql/migrar-esquema.php inventario).
 * Idempotente: CREATE TABLE IF NOT EXISTS.
 */
function inv_esquema(PDO $pdo): void
{
    $motor = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // Firmas electronicas: una fila por cada firma estampada (nombre, imagen dibujada, momento).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS firmas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NULL,
            nombre VARCHAR(160) NOT NULL,
            imagen MEDIUMTEXT NOT NULL,
            firmado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )" . $motor
    );
    // Firma que el usuario decide conservar en su perfil para reutilizarla.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuario_firma (
            usuario_id INT NOT NULL PRIMARY KEY,
            imagen MEDIUMTEXT NOT NULL,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )" . $motor
    );

    // Correlativos atomicos: 'vale-AAAA' y 'lote-<id del laboratorio>'.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS secuencias (
            clave VARCHAR(40) NOT NULL PRIMARY KEY,
            ultimo INT NOT NULL DEFAULT 0
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS laboratorios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(4) NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            jefe_usuario_id INT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            baja_motivo VARCHAR(255) NULL,
            baja_en DATETIME NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_laboratorios_codigo (codigo)
        )" . $motor
    );

    // Un usuario de la plataforma pertenece a un solo laboratorio de este modulo.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuario_laboratorio (
            usuario_id INT NOT NULL PRIMARY KEY,
            laboratorio_id INT NOT NULL,
            KEY idx_usuario_laboratorio_lab (laboratorio_id),
            CONSTRAINT fk_usuario_laboratorio_lab FOREIGN KEY (laboratorio_id) REFERENCES laboratorios (id)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS productos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(20) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            tipo ENUM('Precursor fiscalizado','Reactivo','Material de laboratorio','Consumible') NOT NULL DEFAULT 'Precursor fiscalizado',
            concentracion VARCHAR(80) NULL,
            cas VARCHAR(40) NULL,
            unidad VARCHAR(20) NOT NULL,
            minimo DECIMAL(14,3) NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_productos_codigo (codigo)
        )" . $motor
    );

    // Matriz: que laboratorios estan autorizados a solicitar cada producto.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS producto_laboratorio (
            producto_id INT NOT NULL,
            laboratorio_id INT NOT NULL,
            PRIMARY KEY (producto_id, laboratorio_id),
            KEY idx_producto_laboratorio_lab (laboratorio_id),
            CONSTRAINT fk_prodlab_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE CASCADE,
            CONSTRAINT fk_prodlab_lab FOREIGN KEY (laboratorio_id) REFERENCES laboratorios (id)
        )" . $motor
    );

    // Entradas / compras: cada una es un lote del laboratorio propietario.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS entradas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fecha_ingreso DATE NOT NULL,
            laboratorio_id INT NOT NULL,
            producto_id INT NOT NULL,
            lote VARCHAR(30) NOT NULL,
            proveedor VARCHAR(160) NULL,
            factura VARCHAR(80) NULL,
            lote_proveedor VARCHAR(80) NULL,
            coa VARCHAR(80) NULL,
            caducidad DATE NOT NULL,
            cantidad DECIMAL(14,3) NOT NULL,
            recibido_por_id INT NULL,
            recibido_por VARCHAR(160) NOT NULL,
            anulada TINYINT(1) NOT NULL DEFAULT 0,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_entradas_lote (lote),
            KEY idx_entradas_lab_prod (laboratorio_id, producto_id, caducidad),
            KEY idx_entradas_fecha (fecha_ingreso),
            CONSTRAINT fk_entradas_lab FOREIGN KEY (laboratorio_id) REFERENCES laboratorios (id),
            CONSTRAINT fk_entradas_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS entrada_historial (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entrada_id INT NOT NULL,
            tipo ENUM('correccion','anulacion') NOT NULL,
            error_tipo VARCHAR(120) NOT NULL,
            motivo TEXT NOT NULL,
            documento VARCHAR(160) NULL,
            cambios MEDIUMTEXT NULL,
            usuario_id INT NULL,
            usuario_nombre VARCHAR(160) NOT NULL,
            firma_id INT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_entrada_historial_entrada (entrada_id),
            CONSTRAINT fk_entrada_historial_entrada FOREIGN KEY (entrada_id) REFERENCES entradas (id),
            CONSTRAINT fk_entrada_historial_firma FOREIGN KEY (firma_id) REFERENCES firmas (id)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero VARCHAR(20) NOT NULL,
            anio SMALLINT NOT NULL,
            correlativo INT NOT NULL,
            fecha DATE NOT NULL,
            laboratorio_id INT NOT NULL,
            solicitante_id INT NULL,
            solicitante VARCHAR(160) NOT NULL,
            proyecto VARCHAR(200) NOT NULL,
            jefe VARCHAR(160) NOT NULL DEFAULT '',
            estado ENUM('Pendiente','Autorizado','Entregado','Rechazado','Anulado') NOT NULL DEFAULT 'Pendiente',
            motivo VARCHAR(500) NULL,
            fecha_entrega DATE NULL,
            recibido_por VARCHAR(160) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_vales_numero (numero),
            UNIQUE KEY uk_vales_correlativo (anio, correlativo),
            KEY idx_vales_estado (estado),
            KEY idx_vales_lab_estado (laboratorio_id, estado),
            KEY idx_vales_entrega (fecha_entrega),
            CONSTRAINT fk_vales_lab FOREIGN KEY (laboratorio_id) REFERENCES laboratorios (id)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vale_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vale_id INT NOT NULL,
            producto_id INT NOT NULL,
            cantidad DECIMAL(14,3) NOT NULL,
            entregado DECIMAL(14,3) NULL,
            observaciones VARCHAR(255) NULL,
            UNIQUE KEY uk_vale_items_producto (vale_id, producto_id),
            KEY idx_vale_items_producto (producto_id),
            CONSTRAINT fk_vale_items_vale FOREIGN KEY (vale_id) REFERENCES vales (id) ON DELETE CASCADE,
            CONSTRAINT fk_vale_items_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
        )" . $motor
    );

    // Lotes asignados por FEFO al despachar: aqui nace el consumo de cada lote.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vale_item_lotes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vale_item_id INT NOT NULL,
            entrada_id INT NOT NULL,
            lote VARCHAR(30) NOT NULL,
            cantidad DECIMAL(14,3) NOT NULL,
            KEY idx_vale_item_lotes_item (vale_item_id),
            KEY idx_vale_item_lotes_entrada (entrada_id),
            CONSTRAINT fk_vil_item FOREIGN KEY (vale_item_id) REFERENCES vale_items (id) ON DELETE CASCADE,
            CONSTRAINT fk_vil_entrada FOREIGN KEY (entrada_id) REFERENCES entradas (id)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vale_firmas (
            vale_id INT NOT NULL,
            tipo ENUM('sol','aut','ent','rec') NOT NULL,
            firma_id INT NOT NULL,
            PRIMARY KEY (vale_id, tipo),
            CONSTRAINT fk_vale_firmas_vale FOREIGN KEY (vale_id) REFERENCES vales (id) ON DELETE CASCADE,
            CONSTRAINT fk_vale_firmas_firma FOREIGN KEY (firma_id) REFERENCES firmas (id)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vale_bitacora (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vale_id INT NOT NULL,
            usuario_id INT NULL,
            usuario_nombre VARCHAR(160) NOT NULL,
            accion VARCHAR(500) NOT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_vale_bitacora_vale (vale_id),
            CONSTRAINT fk_vale_bitacora_vale FOREIGN KEY (vale_id) REFERENCES vales (id) ON DELETE CASCADE
        )" . $motor
    );

    // Cierre mensual regulatorio. `datos` guarda la foto de las cifras al firmar "Elaborado por".
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS cierres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            anio SMALLINT NOT NULL,
            mes TINYINT NOT NULL,
            laboratorio_id INT NOT NULL DEFAULT 0,
            solo_fiscalizados TINYINT(1) NOT NULL DEFAULT 1,
            datos MEDIUMTEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cierres_periodo (anio, mes, laboratorio_id, solo_fiscalizados)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS cierre_firmas (
            cierre_id INT NOT NULL,
            tipo ENUM('elab','rev','apr') NOT NULL,
            firma_id INT NOT NULL,
            usuario_id INT NULL,
            PRIMARY KEY (cierre_id, tipo),
            CONSTRAINT fk_cierre_firmas_cierre FOREIGN KEY (cierre_id) REFERENCES cierres (id) ON DELETE CASCADE,
            CONSTRAINT fk_cierre_firmas_firma FOREIGN KEY (firma_id) REFERENCES firmas (id)
        )" . $motor
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS auditoria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NULL,
            usuario_nombre VARCHAR(160) NOT NULL,
            accion VARCHAR(500) NOT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_auditoria_fecha (creado_en)
        )" . $motor
    );
}

/**
 * Registra el modulo, sus permisos y sus roles en la base central. Solo al desplegar.
 * Los roles se crean con sus permisos solo si todavia no tienen ninguno de inventario: lo que
 * despues se edite en Roles y permisos de la plataforma se respeta.
 */
function inv_asegurar_menu(PDO $menuPdo): void
{
    require_once dirname(__DIR__, 2) . '/login/config/permisos_inventario.php';

    $existe = static function (string $tabla) use ($menuPdo): bool {
        $stmt = $menuPdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$tabla]);
        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$existe('modulos')) {
        return;
    }
    $stmt = $menuPdo->prepare('SELECT id FROM modulos WHERE LOWER(nombre) = LOWER(?) LIMIT 1');
    $stmt->execute([INVENTARIO_MODULO]);
    $moduloId = (int) $stmt->fetchColumn();
    if ($moduloId === 0) {
        $menuPdo->prepare('INSERT INTO modulos (nombre) VALUES (?)')->execute([INVENTARIO_MODULO]);
        $moduloId = (int) $menuPdo->lastInsertId();
    }

    if (!$existe('permisos')) {
        return;
    }
    $insertar = $menuPdo->prepare(
        'INSERT INTO permisos (nombre_permiso, descripcion) VALUES (?, ?) ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)'
    );
    foreach (permisos_inventario_todos() as $nombre => [, $descripcion]) {
        $insertar->execute([$nombre, $descripcion]);
    }

    if (!$existe('roles') || !$existe('rol_permiso')) {
        return;
    }
    $buscar = $menuPdo->prepare('SELECT id FROM roles WHERE nombre_rol = ? LIMIT 1');
    $crear = $menuPdo->prepare('INSERT INTO roles (nombre_rol) VALUES (?)');
    $tiene = $menuPdo->prepare("SELECT COUNT(*) FROM rol_permiso rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ? AND p.nombre_permiso LIKE 'inventario.%'");
    $asignar = $menuPdo->prepare('INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE nombre_permiso = ?');
    $propios = roles_inventario_propios();
    $vincular = $existe('rol_modulo') ? $menuPdo->prepare('INSERT IGNORE INTO rol_modulo (rol_id, modulo_id) VALUES (?, ?)') : null;

    foreach (roles_inventario_definicion() as $rol => $permisos) {
        $buscar->execute([$rol]);
        $rolId = (int) $buscar->fetchColumn();
        if ($rolId === 0) {
            if (!in_array($rol, $propios, true)) {
                continue; // Superadmin / Administrador: solo se completan si ya existen.
            }
            $crear->execute([$rol]);
            $rolId = (int) $menuPdo->lastInsertId();
        }
        if ($vincular !== null && in_array($rol, $propios, true)) {
            $vincular->execute([$rolId, $moduloId]);
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

/** Datos iniciales del catalogo del prototipo: 6 laboratorios y 14 productos. Solo si la base esta vacia. */
function inv_sembrar_catalogo(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM laboratorios')->fetchColumn() > 0) {
        return;
    }
    $lab = $pdo->prepare('INSERT INTO laboratorios (codigo, nombre) VALUES (?, ?)');
    $ids = [];
    foreach ([['AGR', 'Agroindustrial'], ['BIO', 'Biotecnología'], ['ENT', 'Entomología'], ['FME', 'Fitomejoramiento'], ['FIT', 'Fitopatología'], ['IND', 'Industrial']] as [$codigo, $nombre]) {
        $lab->execute([$codigo, $nombre]);
        $ids[$codigo] = (int) $pdo->lastInsertId();
    }
    $prod = $pdo->prepare('INSERT INTO productos (codigo, nombre, tipo, cas, unidad, minimo) VALUES (?, ?, ?, ?, ?, ?)');
    $matriz = $pdo->prepare('INSERT IGNORE INTO producto_laboratorio (producto_id, laboratorio_id) VALUES (?, ?)');
    $fisc = 'Precursor fiscalizado';
    foreach ([
        ['REC-001', 'Acetona', $fisc, '67-64-1', 'L', 3, ['ENT']],
        ['REC-002', 'Ácido Acético', $fisc, '64-19-7', 'L', 4, ['AGR', 'BIO']],
        ['REC-003', 'Ácido Clorhídrico', $fisc, '7647-01-0', 'L', 3, ['AGR', 'IND']],
        ['REC-004', 'Ácido Sulfúrico', $fisc, '7664-93-9', 'L', 2, ['AGR', 'FME', 'IND']],
        ['REC-005', 'Alcohol Etílico', $fisc, '64-17-5', 'L', 5, ['AGR', 'BIO', 'ENT', 'FIT']],
        ['REC-006', 'Etanol Absoluto', $fisc, '64-17-5', 'L', 2, ['BIO']],
        ['REC-007', 'Carbonato de Sodio', $fisc, '497-19-8', 'Kg', 0.5, ['BIO']],
        ['REC-008', 'Cloruro de Amonio', $fisc, '12125-02-9', 'Kg', 1, ['AGR']],
        ['REC-009', 'Hidróxido de Sodio', $fisc, '1310-73-2', 'Kg', 1, ['AGR', 'FIT', 'IND']],
        ['REC-010', 'Hidróxido de Potasio', $fisc, '1310-58-3', 'Kg', 0.5, ['ENT']],
        ['REC-011', 'Alcohol Isopropílico', $fisc, '67-63-0', 'L', 2, ['BIO', 'IND']],
        ['REC-012', 'Alcohol Metílico', $fisc, '67-56-1', 'L', 3, ['IND']],
        ['MAT-001', 'Puntas para micropipeta 1000 µL', 'Material de laboratorio', null, 'Caja', 5, ['BIO', 'FIT']],
        ['REA-001', 'Fenolftaleína 1% (indicador)', 'Reactivo', '77-09-8', 'mL', 100, ['AGR', 'IND']],
    ] as [$codigo, $nombre, $tipo, $cas, $unidad, $minimo, $labs]) {
        $prod->execute([$codigo, $nombre, $tipo, $cas, $unidad, $minimo]);
        $productoId = (int) $pdo->lastInsertId();
        foreach ($labs as $l) {
            $matriz->execute([$productoId, $ids[$l]]);
        }
    }
}
