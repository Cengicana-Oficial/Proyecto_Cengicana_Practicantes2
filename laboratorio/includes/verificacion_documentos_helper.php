<?php
/**
 * Verificación pública de documentos del laboratorio (boleta de recepción e informe de
 * resultados), con el mismo modelo que los diplomas de Cengicursos:
 *
 * - Cada documento emitido recibe un código único con una parte aleatoria
 *   (p. ej. LAB-I-2026-00042-K7QD), así no se pueden recorrer consecutivamente.
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
    }
}

if (!function_exists('lab_verif_url_base')) {
    /** URL pública del sitio: LAB_PUBLIC_URL o APP_URL del .env; si no, la del pedido actual. */
    function lab_verif_url_base(): string
    {
        foreach (['LAB_PUBLIC_URL', 'APP_URL'] as $var) {
            $v = trim((string) (getenv($var) ?: ($_ENV[$var] ?? '')));
            if ($v !== '') {
                return rtrim($v, '/');
            }
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}

if (!function_exists('lab_verif_url')) {
    function lab_verif_url(string $codigo): string
    {
        return lab_verif_url_base() . '/laboratorio/validar.php?c=' . rawurlencode($codigo);
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

if (!function_exists('lab_verif_nuevo_codigo')) {
    /** LAB-<R|I>-<año>-<correlativo>-<4 caracteres aleatorios sin ambiguos (0/O, 1/I)>. */
    function lab_verif_nuevo_codigo(PDO $pdo, string $tipo): string
    {
        $prefijo = 'LAB-' . ($tipo === 'informe' ? 'I' : 'R') . '-' . date('Y') . '-';
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $cuenta = $pdo->prepare('SELECT COUNT(*) FROM lab_documento_verificacion WHERE codigo LIKE ?');
        $cuenta->execute([$prefijo . '%']);
        $n = (int) $cuenta->fetchColumn();
        $existe = $pdo->prepare('SELECT COUNT(*) FROM lab_documento_verificacion WHERE codigo = ?');
        do {
            $n++;
            $azar = '';
            for ($i = 0; $i < 4; $i++) {
                $azar .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $codigo = $prefijo . str_pad((string) $n, 5, '0', STR_PAD_LEFT) . '-' . $azar;
            $existe->execute([$codigo]);
        } while ((int) $existe->fetchColumn() > 0);
        return $codigo;
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
        if ($idSolicitud <= 0 || !lab_verif_tabla_existe($pdo)) {
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
            return ['codigo' => $fila['codigo'], 'url' => lab_verif_url($fila['codigo'])];
        }
        for ($intento = 0; $intento < 3; $intento++) {
            try {
                $codigo = lab_verif_nuevo_codigo($pdo, 'boleta');
                $pdo->prepare("INSERT INTO lab_documento_verificacion (codigo, tipo, id_solicitud, id_lote, huella, datos, emitido_por) VALUES (?, 'boleta', ?, ?, ?, ?, ?)")
                    ->execute([$codigo, $idSolicitud, $datosPublicos['id_lote'] ?? null, hash('sha256', (string) $json), $json, $usuario !== '' ? $usuario : null]);
                return ['codigo' => $codigo, 'url' => lab_verif_url($codigo)];
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
