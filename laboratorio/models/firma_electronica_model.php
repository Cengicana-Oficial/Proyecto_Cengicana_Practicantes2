<?php

require_once __DIR__ . '/documentos_model.php';
require_once __DIR__ . '/informes_model.php';

if (!function_exists('lab_firma_obtener_por_documento')) {
    function lab_firma_obtener_por_documento(PDO $pdo, int $idDocumento): ?array
    {
        if ($idDocumento <= 0 || !lab_documentos_tabla_existe($pdo, 'lab_firma_documento')) {
            return null;
        }

        $stmt = $pdo->prepare('
            SELECT id_firma, id_documento, rol_firma, firmante_id,
                   firmante_nombre, firmante_correo, firma_png, fecha_firma
            FROM lab_firma_documento
            WHERE id_documento = ?
            ORDER BY fecha_firma DESC, id_firma DESC
            LIMIT 1
        ');
        $stmt->execute([$idDocumento]);
        $firma = $stmt->fetch(PDO::FETCH_ASSOC);

        return $firma ?: null;
    }
}

if (!function_exists('lab_firma_detalle_lote')) {
    function lab_firma_detalle_lote(PDO $pdo, int $idLote): array
    {
        if ($idLote <= 0) {
            return ['correo' => '', 'observaciones' => ''];
        }

        $stmt = $pdo->prepare("
            SELECT
                GROUP_CONCAT(
                    DISTINCT COALESCE(NULLIF(TRIM(correo_ingresado), ''), NULLIF(TRIM(correo_recibido), ''))
                    SEPARATOR ', '
                ) AS correo,
                GROUP_CONCAT(
                    DISTINCT NULLIF(TRIM(observaciones), '')
                    SEPARATOR ' | '
                ) AS observaciones
            FROM solicitud
            WHERE id_lote = ?
        ");
        $stmt->execute([$idLote]);
        $detalle = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'correo' => trim((string) ($detalle['correo'] ?? '')),
            'observaciones' => trim((string) ($detalle['observaciones'] ?? '')),
        ];
    }
}

if (!function_exists('lab_firma_resumen_analisis')) {
    function lab_firma_resumen_analisis(PDO $pdo, int $idLote): array
    {
        if ($idLote <= 0) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT
                m.id_muestra,
                COALESCE(NULLIF(TRIM(m.codigo_lab), ''), CONCAT('Muestra ', m.numero_muestra)) AS muestra,
                ta.nombre AS analisis,
                CASE
                    WHEN MAX(LOWER(COALESCE(ef.nombre, '')) = 'aprobado') = 1 THEN 'Aprobado'
                    WHEN MAX(f.id_formulario IS NOT NULL) = 1 THEN 'En revisión'
                    ELSE 'Pendiente'
                END AS estado
            FROM solicitud s
            INNER JOIN muestra m ON m.id_solicitud = s.id_solicitud
            INNER JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
            INNER JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
            LEFT JOIN lote_rango lr
                   ON lr.id_lote = s.id_lote
                  AND m.numero_muestra BETWEEN lr.inicio AND lr.fin
            LEFT JOIN formulario f
                   ON f.id_rango = lr.id_rango
                  AND f.id_tipo_analisis = sa.id_tipo_analisis
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            WHERE s.id_lote = ?
            GROUP BY m.id_muestra, m.codigo_lab, m.numero_muestra, ta.id_tipo, ta.nombre
            ORDER BY m.numero_muestra ASC, ta.nombre ASC
        ");
        $stmt->execute([$idLote]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lab_firma_panel')) {
    function lab_firma_panel(PDO $pdo, ?int $idDocumentoSolicitado = null): array
    {
        $lotes = lab_informes_resumen_lotes($pdo);
        $lotesPorId = [];
        foreach ($lotes as $lote) {
            $lotesPorId[(int) ($lote['id_lote'] ?? 0)] = $lote;
        }

        $firmas = lab_firmas_listar($pdo);
        $firmasPorDocumento = [];
        foreach ($firmas as $firma) {
            $idDocumento = (int) ($firma['id_documento'] ?? 0);
            if ($idDocumento > 0 && !isset($firmasPorDocumento[$idDocumento])) {
                $firmasPorDocumento[$idDocumento] = $firma;
            }
        }

        $documentos = [];
        foreach (lab_documentos_listar($pdo, 'informe') as $documento) {
            if (empty($documento['vigente'])) {
                continue;
            }
            $idDocumento = (int) ($documento['id_documento'] ?? 0);
            $idLote = (int) ($documento['id_lote'] ?? 0);
            $documento['lote'] = $lotesPorId[$idLote] ?? [
                'id_lote' => $idLote,
                'codigo_lote' => $documento['codigo_lote'] ?? '-',
                'cliente' => 'Sin institución',
                'responsable' => '',
                'total_muestras' => 0,
                'analisis_requeridos' => 0,
                'analisis_ingresados' => 0,
                'analisis_aprobados' => 0,
            ];
            $documento['firmado'] = isset($firmasPorDocumento[$idDocumento]);
            $documentos[] = $documento;
        }

        $seleccionado = null;
        if ($idDocumentoSolicitado !== null) {
            foreach ($documentos as $documento) {
                if ((int) $documento['id_documento'] === $idDocumentoSolicitado) {
                    $seleccionado = $documento;
                    break;
                }
            }
        }
        if ($seleccionado === null) {
            foreach ($documentos as $documento) {
                if (empty($documento['firmado'])) {
                    $seleccionado = $documento;
                    break;
                }
            }
        }
        if ($seleccionado === null && $documentos) {
            $seleccionado = $documentos[0];
        }

        $detalle = ['correo' => '', 'observaciones' => ''];
        $resumen = [];
        $firmaSeleccionada = null;
        if ($seleccionado !== null) {
            $idLote = (int) ($seleccionado['id_lote'] ?? 0);
            $idDocumento = (int) ($seleccionado['id_documento'] ?? 0);
            $detalle = lab_firma_detalle_lote($pdo, $idLote);
            $resumen = lab_firma_resumen_analisis($pdo, $idLote);
            $firmaSeleccionada = lab_firma_obtener_por_documento($pdo, $idDocumento);
        }

        return [
            'tabla_documentos_disponible' => lab_documentos_tabla_existe($pdo, 'lab_documento'),
            'tabla_firmas_disponible' => lab_documentos_tabla_existe($pdo, 'lab_firma_documento'),
            'documentos' => $documentos,
            'seleccionado' => $seleccionado,
            'detalle' => $detalle,
            'resumen' => $resumen,
            'firma' => $firmaSeleccionada,
        ];
    }
}

if (!function_exists('lab_firma_normalizar_png')) {
    function lab_firma_normalizar_png(string $firma): string
    {
        $firma = trim($firma);
        if ($firma === '' || strlen($firma) > 4 * 1024 * 1024) {
            throw new InvalidArgumentException('La firma está vacía o excede el tamaño permitido.');
        }
        if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', $firma, $coincidencias)) {
            throw new InvalidArgumentException('El formato de la firma no es válido.');
        }

        $binario = base64_decode(preg_replace('/\s+/', '', $coincidencias[1]), true);
        if ($binario === false || strlen($binario) < 16 || substr($binario, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new InvalidArgumentException('La imagen de firma no es un PNG válido.');
        }

        return 'data:image/png;base64,' . base64_encode($binario);
    }
}

if (!function_exists('lab_firma_registrar')) {
    function lab_firma_registrar(PDO $pdo, int $idDocumento, string $firmaPng, array $usuario): void
    {
        if (!lab_documentos_tabla_existe($pdo, 'lab_documento')
            || !lab_documentos_tabla_existe($pdo, 'lab_firma_documento')) {
            throw new RuntimeException('Las tablas de documentos y firmas todavía no están disponibles.');
        }
        if ($idDocumento <= 0) {
            throw new InvalidArgumentException('Selecciona un informe válido.');
        }

        $firmaPng = lab_firma_normalizar_png($firmaPng);
        $pdo->beginTransaction();
        try {
            $stmtDocumento = $pdo->prepare("
                SELECT id_documento, id_lote
                FROM lab_documento
                WHERE id_documento = ? AND tipo_documento = 'informe' AND vigente = 1
                FOR UPDATE
            ");
            $stmtDocumento->execute([$idDocumento]);
            $documento = $stmtDocumento->fetch(PDO::FETCH_ASSOC);
            if (!$documento) {
                throw new RuntimeException('El informe seleccionado ya no está disponible para firma.');
            }

            $loteListo = null;
            foreach (lab_informes_resumen_lotes($pdo) as $lote) {
                if ((int) ($lote['id_lote'] ?? 0) === (int) $documento['id_lote']) {
                    $loteListo = $lote;
                    break;
                }
            }
            $requeridos = (int) ($loteListo['analisis_requeridos'] ?? 0);
            $aprobados = (int) ($loteListo['analisis_aprobados'] ?? 0);
            if ($requeridos <= 0 || $aprobados < $requeridos) {
                throw new RuntimeException('El informe no puede firmarse hasta aprobar todos los análisis del lote.');
            }

            $stmtExiste = $pdo->prepare('SELECT id_firma FROM lab_firma_documento WHERE id_documento = ? LIMIT 1');
            $stmtExiste->execute([$idDocumento]);
            if ($stmtExiste->fetchColumn()) {
                throw new RuntimeException('Este informe ya cuenta con una firma electrónica.');
            }

            $nombre = trim((string) ($usuario['nombre'] ?? ''));
            if ($nombre === '') {
                $nombre = 'Usuario autorizado';
            }
            $correo = trim((string) ($usuario['correo'] ?? ''));
            $idFirmante = !empty($usuario['id']) ? (int) $usuario['id'] : null;

            $stmtInsertar = $pdo->prepare('
                INSERT INTO lab_firma_documento
                    (id_documento, rol_firma, firmante_id, firmante_nombre, firmante_correo, firma_png)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmtInsertar->execute([
                $idDocumento,
                'jefe_laboratorio',
                $idFirmante,
                $nombre,
                $correo !== '' ? $correo : null,
                $firmaPng,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
