<?php
/**
 * Verificación pública de documentos del laboratorio (boleta de recepción e informe de
 * resultados), con el mismo modelo que los diplomas de Cengicursos:
 *
 * - Cada documento emitido recibe un código único con una parte aleatoria
 *   (p. ej. LAB-I-2026-00042-K7QD3M9XW2HP), así no se pueden recorrer consecutivamente.
 * - Su QR apunta a laboratorio/validar.php?c=<código>, una página pública que confirma si el
 *   documento es auténtico y muestra solo los datos ya impresos en él.
 * - El informe guarda la huella SHA-256 de su contenido; al firmar una nueva versión, la
 *   anterior queda como "reemplazada" y la página lo indica.
 * - El mismo QR sirve dentro del laboratorio: el escáner reconoce el enlace (un solo QR).
 */

if (!function_exists('lab_verificacion_esquema')) {
    /** DDL solo desde deploy/mysql/migrar-esquema.php (AUD-2026-001/I-2). */
    function lab_verificacion_esquema(PDO $pdo): void
    {
        if (!defined('ESQUEMA_MIGRANDO')) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_documento_verificacion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(32) NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            id_solicitud INT NULL,
            id_lote INT NULL,
            id_documento INT NULL,
            version INT NOT NULL DEFAULT 1,
            huella CHAR(64) NULL,
            datos LONGTEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'vigente',
            reemplazado_por VARCHAR(32) NULL,
            emitido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            emitido_por VARCHAR(255) NULL,
            actualizado_en DATETIME NULL,
            UNIQUE KEY uk_lab_verif_codigo (codigo),
            KEY idx_lab_verif_solicitud (tipo, id_solicitud),
            KEY idx_lab_verif_lote (tipo, id_lote)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Correlativo por tipo y año, incrementado de forma atómica (sin carrera entre pedidos).
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_verif_contador (
            clave VARCHAR(24) NOT NULL PRIMARY KEY,
            ultimo INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Intentos fallidos de verificación pública por IP (hash), para limitar la adivinación de códigos.
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_verif_intentos (
            ip_hash CHAR(64) NOT NULL PRIMARY KEY,
            intentos INT NOT NULL DEFAULT 0,
            ventana_inicio DATETIME NOT NULL,
            KEY idx_lab_verif_intentos_ventana (ventana_inicio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('lab_verif_url_base')) {
    /**
     * URL pública del sitio: LAB_PUBLIC_URL o APP_URL del .env. Nunca se deduce de la cabecera Host
     * (la controla quien hace el pedido y el QR impreso apuntaría a otro dominio). Sin ninguna de
     * las dos devuelve '' y los documentos salen sin QR.
     */
    function lab_verif_url_base(): string
    {
        foreach (['LAB_PUBLIC_URL', 'APP_URL'] as $var) {
            $v = trim((string) (getenv($var) ?: ($_ENV[$var] ?? '')));
            if ($v !== '') {
                return rtrim($v, '/');
            }
        }
        static $avisado = false;
        if (!$avisado) {
            $avisado = true;
            error_log('[laboratorio][verificacion] LAB_PUBLIC_URL/APP_URL no configuradas: los documentos se emiten sin QR de verificación.');
        }
        return '';
    }
}

if (!function_exists('lab_verif_url')) {
    /** Enlace público de verificación, o '' si no hay URL pública configurada (no se genera QR). */
    function lab_verif_url(string $codigo): string
    {
        $base = lab_verif_url_base();
        return $base === '' ? '' : $base . '/laboratorio/validar.php?c=' . rawurlencode($codigo);
    }
}

if (!function_exists('lab_verif_tabla_existe')) {
    function lab_verif_tabla_existe(PDO $pdo): bool
    {
        static $existe = null;
        if ($existe === null) {
            try {
                $pdo->query('SELECT 1 FROM lab_documento_verificacion LIMIT 1');
                $existe = true;
            } catch (Throwable $e) {
                $existe = false;
            }
        }
        return $existe;
    }
}

if (!function_exists('lab_verif_correlativo_siguiente')) {
    /**
     * Siguiente correlativo de la serie (p. ej. 'LAB-I-2026') sin carrera entre pedidos: un
     * UPSERT atómico sobre lab_verif_contador. Si esa tabla aún no existe (migración pendiente)
     * cae a MAX+1, que ya no puede repetir el código completo porque este lleva parte aleatoria.
     */
    function lab_verif_correlativo_siguiente(PDO $pdo, string $serie): int
    {
        $max = static function () use ($pdo, $serie): int {
            $inicio = strlen($serie) + 2; // posición (1-based) tras "<serie>-"
            $q = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING(codigo, $inicio), '-', 1) AS UNSIGNED)), 0) FROM lab_documento_verificacion WHERE codigo LIKE ?");
            $q->execute([$serie . '-%']);
            return (int) $q->fetchColumn();
        };
        try {
            // Primera vez: arranca después del mayor ya emitido; después incrementa bajo bloqueo de fila.
            $pdo->prepare('INSERT INTO lab_verif_contador (clave, ultimo) VALUES (?, LAST_INSERT_ID(?)) ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)')
                ->execute([$serie, $max() + 1]);
            return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        } catch (PDOException $e) {
            error_log('[laboratorio][verificacion] contador: ' . $e->getMessage());
            return $max() + 1;
        }
    }
}

if (!function_exists('lab_verif_nuevo_codigo')) {
    /**
     * LAB-<R|I>-<año>-<correlativo>-<12 caracteres aleatorios (60 bits) sin ambiguos (0/O, 1/I)>.
     * Los códigos emitidos antes (4 caracteres) siguen validando: la búsqueda es por código exacto.
     */
    function lab_verif_nuevo_codigo(PDO $pdo, string $tipo): string
    {
        $serie = 'LAB-' . ($tipo === 'informe' ? 'I' : 'R') . '-' . date('Y');
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $existe = $pdo->prepare('SELECT COUNT(*) FROM lab_documento_verificacion WHERE codigo = ?');
        do {
            $n = lab_verif_correlativo_siguiente($pdo, $serie);
            $azar = '';
            for ($i = 0; $i < 12; $i++) {
                $azar .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $codigo = $serie . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT) . '-' . $azar;
            $existe->execute([$codigo]);
        } while ((int) $existe->fetchColumn() > 0);
        return $codigo;
    }
}

if (!function_exists('lab_verif_hash_ip')) {
    function lab_verif_hash_ip(): string
    {
        return hash('sha256', 'lab-verif|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'));
    }
}

if (!function_exists('lab_verif_bloqueado')) {
    /** true si la IP agotó sus intentos fallidos en la ventana; null si no se pudo comprobar (tabla ausente). */
    function lab_verif_bloqueado(PDO $pdo, int $maximo = 10, int $minutos = 15): ?bool
    {
        try {
            $q = $pdo->prepare('SELECT intentos FROM lab_verif_intentos WHERE ip_hash = ? AND ventana_inicio >= (NOW() - INTERVAL ' . (int) $minutos . ' MINUTE)');
            $q->execute([lab_verif_hash_ip()]);
            return (int) $q->fetchColumn() >= $maximo;
        } catch (Throwable $e) {
            error_log('[laboratorio][verificacion] limite: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('lab_verif_registrar_fallo')) {
    /** Cuenta un intento fallido; abre una ventana nueva si la anterior ya venció. false si no se pudo. */
    function lab_verif_registrar_fallo(PDO $pdo, int $minutos = 15): bool
    {
        try {
            $m = (int) $minutos;
            $pdo->prepare("INSERT INTO lab_verif_intentos (ip_hash, intentos, ventana_inicio) VALUES (?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    intentos = IF(ventana_inicio < (NOW() - INTERVAL $m MINUTE), 1, intentos + 1),
                    ventana_inicio = IF(ventana_inicio < (NOW() - INTERVAL $m MINUTE), NOW(), ventana_inicio)")
                ->execute([lab_verif_hash_ip()]);
            if (random_int(1, 50) === 1) { // limpieza ocasional de ventanas viejas
                $pdo->exec('DELETE FROM lab_verif_intentos WHERE ventana_inicio < (NOW() - INTERVAL 1 DAY)');
            }
            return true;
        } catch (Throwable $e) {
            error_log('[laboratorio][verificacion] registrar fallo: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lab_verif_enmascarar')) {
    /** Enmascara un nombre para la vista pública, conservando el inicio de cada palabra. */
    function lab_verif_enmascarar(string $texto): string
    {
        $salida = [];
        foreach (preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $largo = mb_strlen($p);
            $visibles = $largo >= 5 ? 2 : 1;
            $salida[] = mb_substr($p, 0, $visibles) . str_repeat('*', $largo - $visibles);
        }
        return implode(' ', $salida);
    }
}

if (!function_exists('lab_verif_qr_png')) {
    /** QR como data URI PNG (para el HTML del informe que Dompdf convierte a PDF). */
    function lab_verif_qr_png(string $texto): ?string
    {
        $autoload = __DIR__ . '/../../cengicursos/vendor/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        require_once $autoload;
        if (!class_exists('\chillerlan\QRCode\QRCode')) {
            return null;
        }
        try {
            $opciones = new \chillerlan\QRCode\QROptions([
                'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M,
                'scale' => 5,
                'imageTransparent' => false,
            ]);
            $png = (new \chillerlan\QRCode\QRCode($opciones))->render($texto);
            return strpos((string) $png, 'data:') === 0 ? $png : 'data:image/png;base64,' . base64_encode((string) $png);
        } catch (Throwable $e) {
            error_log('[laboratorio][verificacion] QR: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('lab_verif_boleta')) {
    /**
     * Código de verificación de la boleta de una recepción (uno por recepción). Si la recepción
     * se edita, se actualizan sus datos públicos y la página muestra la versión vigente.
     * Devuelve ['codigo', 'url'] o null si la tabla aún no existe en el ambiente.
     */
    function lab_verif_boleta(PDO $pdo, int $idSolicitud, array $datosPublicos, string $usuario = ''): ?array
    {
        if ($idSolicitud <= 0 || lab_verif_url_base() === '' || !lab_verif_tabla_existe($pdo)) {
            return null;
        }
        $json = json_encode($datosPublicos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $q = $pdo->prepare("SELECT codigo, datos FROM lab_documento_verificacion WHERE tipo = 'boleta' AND id_solicitud = ? ORDER BY id DESC LIMIT 1");
        $q->execute([$idSolicitud]);
        $fila = $q->fetch(PDO::FETCH_ASSOC);
        if ($fila) {
            if ($fila['datos'] !== $json) {
                $pdo->prepare("UPDATE lab_documento_verificacion SET datos = ?, huella = ?, actualizado_en = NOW() WHERE codigo = ?")
                    ->execute([$json, hash('sha256', (string) $json), $fila['codigo']]);
            }
            $url = lab_verif_url($fila['codigo']);
            return $url === '' ? null : ['codigo' => $fila['codigo'], 'url' => $url];
        }
        for ($intento = 0; $intento < 3; $intento++) {
            try {
                $codigo = lab_verif_nuevo_codigo($pdo, 'boleta');
                $pdo->prepare("INSERT INTO lab_documento_verificacion (codigo, tipo, id_solicitud, id_lote, huella, datos, emitido_por) VALUES (?, 'boleta', ?, ?, ?, ?, ?)")
                    ->execute([$codigo, $idSolicitud, $datosPublicos['id_lote'] ?? null, hash('sha256', (string) $json), $json, $usuario !== '' ? $usuario : null]);
                $url = lab_verif_url($codigo);
                return $url === '' ? null : ['codigo' => $codigo, 'url' => $url];
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e; // otra falla distinta a código duplicado (carrera entre dos pedidos)
                }
            }
        }
        return null;
    }
}

if (!function_exists('lab_verif_informe_registrar')) {
    /**
     * Registra el código ya impreso en el informe firmado con la huella SHA-256 de su contenido.
     * Las versiones anteriores del informe del lote quedan "reemplazadas" por esta.
     */
    function lab_verif_informe_registrar(PDO $pdo, string $codigo, int $idLote, ?int $idSolicitud, int $idDocumento, int $version, string $html, array $datosPublicos, string $usuario): void
    {
        if (!lab_verif_tabla_existe($pdo)) {
            return;
        }
        $pdo->prepare("UPDATE lab_documento_verificacion SET estado = 'reemplazado', reemplazado_por = ?, actualizado_en = NOW() WHERE tipo = 'informe' AND id_lote = ? AND estado = 'vigente'")
            ->execute([$codigo, $idLote]);
        $pdo->prepare("INSERT INTO lab_documento_verificacion (codigo, tipo, id_solicitud, id_lote, id_documento, version, huella, datos, emitido_por) VALUES (?, 'informe', ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$codigo, $idSolicitud, $idLote, $idDocumento, $version, hash('sha256', $html),
                json_encode($datosPublicos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $usuario !== '' ? $usuario : null]);
    }
}

if (!function_exists('lab_verif_buscar')) {
    function lab_verif_buscar(PDO $pdo, string $codigo): ?array
    {
        if ($codigo === '' || !lab_verif_tabla_existe($pdo)) {
            return null;
        }
        $q = $pdo->prepare('SELECT * FROM lab_documento_verificacion WHERE codigo = ? LIMIT 1');
        $q->execute([$codigo]);
        $fila = $q->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            return null;
        }
        $fila['datos'] = json_decode((string) $fila['datos'], true) ?: [];
        return $fila;
    }
}
