<?php
/**
 * documentos_model.php
 *
 * Acceso de lectura a las tablas nuevas de database/002_lab_flujo_generico.sql
 * (`lab_documento`, `lab_documento_historial`, `lab_firma_documento`), que
 * hoy todavia no reciben escrituras desde ningun flujo del modulo (no hay
 * generador de boletas/informes en PDF persistido). Estas funciones
 * devuelven listados reales (posiblemente vacios) en vez de datos de
 * ejemplo: si la migracion 002 no se ha aplicado en el ambiente actual,
 * devuelven un array vacio en lugar de fallar.
 */

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
                l.codigo_lote
            FROM lab_documento d
            LEFT JOIN lote l ON l.id_lote = d.id_lote
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
