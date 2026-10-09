<?php
/**
 * documentos_model.php
 *
 * Repositorio de documentos (tablas de database/002_lab_flujo_generico.sql:
 * `lab_documento`, `lab_documento_historial`, `lab_firma_documento`). Escriben
 * en él Recepción (boleta v1 al registrar y nueva versión con el motivo al
 * editar) e Informes (informe aprobado y firmado por el jefe de laboratorio).
 * Si las tablas no existen, lab_documentos_esquema() las crea sin borrar nada.
 */

if (!function_exists('lab_documentos_esquema')) {
    /** Tablas del repositorio de documentos y firmas (si faltan). */
    function lab_documentos_esquema(PDO $pdo): void
    {
        // Mismas definiciones de database/002_lab_flujo_generico.sql, sin DROP: se crean si faltan.
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_documento (
            id_documento INT NOT NULL AUTO_INCREMENT, tipo_documento ENUM('boleta','informe') NOT NULL,
            id_lote INT NOT NULL, id_solicitud INT DEFAULT NULL, version INT NOT NULL DEFAULT 1,
            titulo VARCHAR(255) DEFAULT NULL, contenido_html LONGTEXT DEFAULT NULL, vigente TINYINT(1) NOT NULL DEFAULT 1,
            generado_por VARCHAR(255) DEFAULT NULL, generado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_documento), KEY fk_labdoc_lote (id_lote), KEY fk_labdoc_solicitud (id_solicitud),
            CONSTRAINT fk_labdoc_lote FOREIGN KEY (id_lote) REFERENCES lote (id_lote),
            CONSTRAINT fk_labdoc_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitud (id_solicitud)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_documento_historial (
            id_historial INT NOT NULL AUTO_INCREMENT, id_documento INT NOT NULL, version INT NOT NULL,
            cambios TEXT DEFAULT NULL, usuario VARCHAR(255) DEFAULT NULL, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_historial), KEY fk_labdochist_doc (id_documento),
            CONSTRAINT fk_labdochist_doc FOREIGN KEY (id_documento) REFERENCES lab_documento (id_documento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_firma_documento (
            id_firma INT NOT NULL AUTO_INCREMENT, id_documento INT NOT NULL, rol_firma VARCHAR(60) NOT NULL,
            firmante_id INT DEFAULT NULL, firmante_nombre VARCHAR(255) DEFAULT NULL, firmante_correo VARCHAR(255) DEFAULT NULL,
            firma_png LONGTEXT DEFAULT NULL, fecha_firma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_firma), KEY fk_labfirma_doc (id_documento),
            CONSTRAINT fk_labfirma_doc FOREIGN KEY (id_documento) REFERENCES lab_documento (id_documento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('lab_documentos_tabla_existe')) {
    function lab_documentos_tabla_existe(PDO $pdo, string $tabla): bool
    {
        static $cache = [];
        if (array_key_exists($tabla, $cache)) {
            return $cache[$tabla];
        }

        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$tabla]);
            $cache[$tabla] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$tabla] = false;
        }

        return $cache[$tabla];
    }
}

if (!function_exists('lab_documentos_listar')) {
    /**
     * @param string|null $tipoDocumento 'boleta' | 'informe' | null (todos)
     */
    function lab_documentos_listar(PDO $pdo, ?string $tipoDocumento = null): array
    {
        if (!lab_documentos_tabla_existe($pdo, 'lab_documento')) {
            return [];
        }

        $sql = "
            SELECT
                d.id_documento, d.tipo_documento, d.id_lote, d.id_solicitud,
                d.version, d.titulo, d.vigente, d.generado_por, d.generado_en,
                l.codigo_lote, sol.tipo_muestra,
                COALESCE(sol.cliente, 'Sin institución') AS cliente
            FROM lab_documento d
            LEFT JOIN lote l ON l.id_lote = d.id_lote
            LEFT JOIN (
                SELECT
                    id_lote,
                    COALESCE(
                        NULLIF(GROUP_CONCAT(DISTINCT NULLIF(TRIM(institucion), '') SEPARATOR ', '), ''),
                        'Sin institución'
                    ) AS cliente,
                    GROUP_CONCAT(DISTINCT tm.nombre ORDER BY tm.nombre SEPARATOR ', ') AS tipo_muestra
                FROM solicitud
                LEFT JOIN tipo_muestra tm ON tm.id_tipo = solicitud.id_tipo
                GROUP BY id_lote
            ) sol ON sol.id_lote = d.id_lote
        ";
        $params = [];
        if ($tipoDocumento !== null) {
            $sql .= ' WHERE d.tipo_documento = ?';
            $params[] = $tipoDocumento;
        }
        $sql .= ' ORDER BY d.generado_en DESC, d.id_documento DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lab_documento_obtener')) {
    function lab_documento_obtener(PDO $pdo, int $idDocumento): ?array
    {
        if ($idDocumento <= 0 || !lab_documentos_tabla_existe($pdo, 'lab_documento')) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                d.id_documento, d.tipo_documento, d.id_lote, d.id_solicitud,
                d.version, d.titulo, d.contenido_html, d.vigente,
                d.generado_por, d.generado_en, l.codigo_lote, sol.tipo_muestra,
                COALESCE(sol.cliente, 'Sin institución') AS cliente
            FROM lab_documento d
            LEFT JOIN lote l ON l.id_lote = d.id_lote
            LEFT JOIN (
                SELECT
                    id_lote,
                    COALESCE(
                        NULLIF(GROUP_CONCAT(DISTINCT NULLIF(TRIM(institucion), '') SEPARATOR ', '), ''),
                        'Sin institución'
                    ) AS cliente,
                    GROUP_CONCAT(DISTINCT tm.nombre ORDER BY tm.nombre SEPARATOR ', ') AS tipo_muestra
                FROM solicitud
                LEFT JOIN tipo_muestra tm ON tm.id_tipo = solicitud.id_tipo
                GROUP BY id_lote
            ) sol ON sol.id_lote = d.id_lote
            WHERE d.id_documento = ?
            LIMIT 1
        ");
        $stmt->execute([$idDocumento]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);

        return $documento ?: null;
    }
}

if (!function_exists('lab_documentos_panel')) {
    function lab_documentos_panel(PDO $pdo, ?string $tipoDocumento = null): array
    {
        $tablaDisponible = lab_documentos_tabla_existe($pdo, 'lab_documento');
        $tablaHistorialDisponible = lab_documentos_tabla_existe($pdo, 'lab_documento_historial');
        $documentos = $tablaDisponible ? lab_documentos_listar($pdo, $tipoDocumento) : [];

        $historialPorDocumento = [];
        if ($tablaHistorialDisponible) {
            $stmt = $pdo->query('
                SELECT id_documento, COUNT(*) AS total
                FROM lab_documento_historial
                GROUP BY id_documento
            ');
            foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $fila) {
                $historialPorDocumento[(int) $fila['id_documento']] = (int) $fila['total'];
            }
        }

        $lotes = [];
        $corregidos = 0;
        foreach ($documentos as &$documento) {
            $idDocumento = (int) ($documento['id_documento'] ?? 0);
            $idLote = (int) ($documento['id_lote'] ?? 0);
            $totalCambios = $historialPorDocumento[$idDocumento] ?? 0;
            $documento['total_cambios'] = $totalCambios;
            if ((int) ($documento['version'] ?? 1) > 1) {
                $corregidos++;
            }
            if ($idLote > 0) {
                $lotes[$idLote] = true;
            }
        }
        unset($documento);

        return [
            'tabla_disponible' => $tablaDisponible,
            'tabla_historial_disponible' => $tablaHistorialDisponible,
            'documentos' => $documentos,
            'total' => count($documentos),
            'corregidos' => $corregidos,
            'lotes' => count($lotes),
        ];
    }
}

if (!function_exists('lab_documentos_contar')) {
    function lab_documentos_contar(PDO $pdo, ?string $tipoDocumento = null): int
    {
        if (!lab_documentos_tabla_existe($pdo, 'lab_documento')) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) FROM lab_documento';
        $params = [];
        if ($tipoDocumento !== null) {
            $sql .= ' WHERE tipo_documento = ?';
            $params[] = $tipoDocumento;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('lab_documentos_historial')) {
    function lab_documentos_historial(PDO $pdo, int $idDocumento): array
    {
        if (!lab_documentos_tabla_existe($pdo, 'lab_documento_historial')) {
            return [];
        }

        $stmt = $pdo->prepare('
            SELECT id_historial, id_documento, version, cambios, usuario, fecha
            FROM lab_documento_historial
            WHERE id_documento = ?
            ORDER BY version DESC, fecha DESC
        ');
        $stmt->execute([$idDocumento]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lab_firmas_listar')) {
    function lab_firmas_listar(PDO $pdo): array
    {
        if (!lab_documentos_tabla_existe($pdo, 'lab_firma_documento')) {
            return [];
        }

        $stmt = $pdo->query('
            SELECT
                fd.id_firma, fd.id_documento, fd.rol_firma, fd.firmante_id,
                fd.firmante_nombre, fd.firmante_correo, fd.fecha_firma,
                (fd.firma_png IS NOT NULL AND fd.firma_png <> \'\') AS tiene_imagen,
                d.titulo AS documento_titulo, d.tipo_documento, d.id_lote,
                l.codigo_lote
            FROM lab_firma_documento fd
            LEFT JOIN lab_documento d ON d.id_documento = fd.id_documento
            LEFT JOIN lote l ON l.id_lote = d.id_lote
            ORDER BY fd.fecha_firma DESC, fd.id_firma DESC
        ');

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
}

if (!function_exists('lab_firmas_documentos_pendientes')) {
    /**
     * Documentos vigentes de tipo "informe" que todavia no tienen ninguna
     * fila en lab_firma_documento (candidatos a firmar).
     */
    function lab_firmas_documentos_pendientes(PDO $pdo): array
    {
        if (!lab_documentos_tabla_existe($pdo, 'lab_documento')) {
            return [];
        }

        $tieneFirmas = lab_documentos_tabla_existe($pdo, 'lab_firma_documento');

        $sql = "
            SELECT d.id_documento, d.titulo, d.version, d.generado_en, d.id_lote, l.codigo_lote
            FROM lab_documento d
            LEFT JOIN lote l ON l.id_lote = d.id_lote
            WHERE d.tipo_documento = 'informe' AND d.vigente = 1
        ";
        if ($tieneFirmas) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM lab_firma_documento fd WHERE fd.id_documento = d.id_documento)';
        }
        $sql .= ' ORDER BY d.generado_en DESC';

        $stmt = $pdo->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
}

if (!function_exists('lab_documento_boleta_html')) {
    /** Boleta de recepción para el repositorio (HTML escapado, apto para Dompdf). */
    function lab_documento_boleta_html(array $b): string
    {
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $f = static fn($v) => $v && strtotime((string) $v) ? date('d/m/Y', strtotime((string) $v)) : '—';
        $fila = static fn($k1, $v1, $k2, $v2) => '<tr><td class="k">' . $e($k1) . '</td><td>' . $e($v1 !== '' && $v1 !== null ? $v1 : '—') . '</td><td class="k">' . $e($k2) . '</td><td>' . $e($v2 !== '' && $v2 !== null ? $v2 : '—') . '</td></tr>';
        $firma = static fn($png, $nombre, $rol) => '<td class="firma">' . ($png ? '<img src="' . $e($png) . '" alt="Firma">' : '<div class="sin">Sin firma</div>') . '<div class="linea"><b>' . $e($nombre ?: '—') . '</b><br>' . $e($rol) . '</div></td>';
        $analisis = implode('', array_map(static fn($a) => '<li>' . $e($a['nombre']) . '</li>', $b['analisis'] ?? []));

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
            @page{margin:28px 32px}body{font-family:DejaVu Sans,sans-serif;color:#20242a;font-size:10.5px}
            .head{border-bottom:3px solid #73bc25;padding-bottom:8px;margin-bottom:12px}h1{font-size:17px;margin:0}
            .sub{color:#55705f}table{width:100%;border-collapse:collapse}.meta td{padding:5px 7px;border:1px solid #dfe3e5}
            .meta td.k{background:#f5f7f4;font-weight:bold;width:20%}h2{font-size:12px;margin:14px 0 6px;color:#1f5632}
            ul{columns:2;margin:0;padding-left:16px}.obs{padding:8px;border:1px solid #dfe3e5;background:#f7f8f8}
            .firmas td.firma{width:50%;padding:18px 12px 0;vertical-align:bottom}.firmas img{height:55px}
            .firmas .sin{height:55px;color:#999;padding-top:20px}.linea{border-top:1px solid #20242a;padding-top:3px}
        </style></head><body>
            <div class="head"><h1>Boleta de recepción · Lote ' . $e($b['lote'] ?? '') . '</h1><div class="sub">CENGILABS · Laboratorio Agroindustrial · ' . $e($b['tipo'] ?? '') . '</div></div>
            <table class="meta">'
            . $fila('Cliente / institución', $b['institucion'] ?? '', 'Responsable del envío', $b['responsableEnvio'] ?? '')
            . $fila('Número de lote', $b['lote'] ?? '', 'Código de muestreo', $b['codigoMuestreo'] ?? '')
            . $fila('Fecha de muestreo', $f($b['fechaMuestreo'] ?? null), 'Fecha de ingreso', $f($b['fechaIngreso'] ?? null))
            . $fila('Fecha estimada', $f($b['fechaEstimada'] ?? null), 'Número de muestras', $b['numeroMuestras'] ?? '')
            . $fila('Número de laboratorio', trim(($b['numeroLaboratorioInicio'] ?? '') . ' a ' . ($b['numeroLaboratorioFin'] ?? ''), ' a'), 'Solicitud', '#' . (int) ($b['idSolicitud'] ?? 0))
            . '</table>
            <h2>Análisis solicitados</h2><ul>' . ($analisis ?: '<li>—</li>') . '</ul>
            ' . (trim((string) ($b['observaciones'] ?? '')) !== '' ? '<h2>Observaciones</h2><div class="obs">' . nl2br($e($b['observaciones'])) . '</div>' : '') . '
            <table class="firmas"><tr>' . $firma($b['firmaIngreso'] ?? '', $b['ingresadoPor'] ?? '', 'Ingresado por')
            . $firma($b['firmaRecibe'] ?? '', $b['recibidoPor'] ?? '', 'Recibido por') . '</tr></table>
        </body></html>';
    }
}

if (!function_exists('lab_documento_boleta_guardar')) {
    /**
     * Guarda la boleta de una recepción en el repositorio: v1 al registrar; con $motivo
     * (edición de la recepción) sube la versión y deja el motivo en el historial del documento.
     */
    function lab_documento_boleta_guardar(PDO $pdo, int $idSolicitud, string $usuario, string $motivo = '', string $resumen = ''): void
    {
        require_once __DIR__ . '/../includes/recepcion_boleta_helper.php';
        $datos = labRecepcionObtenerBoleta($pdo, $idSolicitud);
        if (!$datos) return;
        $lote = $pdo->prepare('SELECT id_lote FROM solicitud WHERE id_solicitud = ?');
        $lote->execute([$idSolicitud]);
        $idLote = (int) $lote->fetchColumn();
        $titulo = 'Boleta de recepción · Lote ' . ($datos['lote'] ?? '') . ' · Solicitud #' . $idSolicitud;
        $html = lab_documento_boleta_html($datos);

        $existe = $pdo->prepare("SELECT id_documento, version FROM lab_documento WHERE tipo_documento = 'boleta' AND id_solicitud = ? ORDER BY id_documento DESC LIMIT 1");
        $existe->execute([$idSolicitud]);
        $doc = $existe->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            $pdo->prepare("INSERT INTO lab_documento (tipo_documento, id_lote, id_solicitud, version, titulo, contenido_html, generado_por) VALUES ('boleta', ?, ?, 1, ?, ?, ?)")
                ->execute([$idLote, $idSolicitud, $titulo, $html, $usuario]);
            return;
        }
        if ($motivo === '') return; // sin edición: la boleta vigente no cambia
        $version = (int) $doc['version'] + 1;
        $pdo->prepare('UPDATE lab_documento SET id_lote = ?, version = ?, titulo = ?, contenido_html = ?, generado_por = ?, generado_en = NOW() WHERE id_documento = ?')
            ->execute([$idLote, $version, $titulo, $html, $usuario, (int) $doc['id_documento']]);
        $pdo->prepare('INSERT INTO lab_documento_historial (id_documento, version, cambios, usuario) VALUES (?, ?, ?, ?)')
            ->execute([(int) $doc['id_documento'], $version, 'Motivo: ' . $motivo . ($resumen !== '' ? ' · ' . $resumen : ''), $usuario]);
    }
}

if (!function_exists('lab_documentos_sincronizar_boletas')) {
    /** Crea la boleta v1 de las recepciones que todavía no tienen documento (recepciones anteriores). */
    function lab_documentos_sincronizar_boletas(PDO $pdo): int
    {
        $faltan = $pdo->query("SELECT s.id_solicitud, COALESCE(NULLIF(TRIM(s.recibido_por), ''), 'Recepción') FROM solicitud s
            WHERE NOT EXISTS (SELECT 1 FROM lab_documento d WHERE d.tipo_documento = 'boleta' AND d.id_solicitud = s.id_solicitud)")->fetchAll(PDO::FETCH_NUM);
        foreach ($faltan as [$idSolicitud, $usuario]) {
            lab_documento_boleta_guardar($pdo, (int) $idSolicitud, (string) $usuario);
        }
        return count($faltan);
    }
}

if (!function_exists('lab_documentos_correos_lote')) {
    /** Correos registrados en la recepción del lote (quien ingresa y quien recibe), sin repetir. */
    function lab_documentos_correos_lote(PDO $pdo, int $idLote): array
    {
        if ($idLote <= 0) {
            return [];
        }
        try {
            $stmt = $pdo->prepare('SELECT correo_ingresado, correo_recibido FROM solicitud WHERE id_lote = ?');
            $stmt->execute([$idLote]);
            $filas = $stmt->fetchAll(PDO::FETCH_NUM) ?: [];
        } catch (Throwable $e) {
            return []; // instalaciones sin las columnas de correo
        }
        $correos = [];
        foreach ($filas as $fila) {
            foreach ($fila as $correo) {
                $correo = trim((string) $correo);
                if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $correos[strtolower($correo)] = $correo;
                }
            }
        }

        return array_values($correos);
    }
}
