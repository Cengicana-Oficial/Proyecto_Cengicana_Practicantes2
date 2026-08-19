<?php

require_once __DIR__ . '/documentos_model.php';

if (!function_exists('lab_informes_resumen_lotes')) {
    function lab_informes_resumen_lotes(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT
                l.id_lote,
                l.codigo_lote,
                COALESCE(sol.cliente, 'Sin institución') AS cliente,
                COALESCE(sol.responsable, '') AS responsable,
                COALESCE(sol.tipos_muestra, 'Sin tipo') AS tipos_muestra,
                COALESCE(mu.total_muestras, sol.muestras_declaradas, 0) AS total_muestras,
                COALESCE(sol.analisis_requeridos, 0) AS analisis_requeridos,
                COALESCE(av.analisis_ingresados, 0) AS analisis_ingresados,
                COALESCE(av.analisis_aprobados, 0) AS analisis_aprobados
            FROM lote l
            LEFT JOIN (
                SELECT
                    s.id_lote,
                    COALESCE(NULLIF(GROUP_CONCAT(DISTINCT NULLIF(TRIM(s.institucion), '') ORDER BY s.institucion SEPARATOR ', '), ''), 'Sin institución') AS cliente,
                    COALESCE(NULLIF(GROUP_CONCAT(DISTINCT NULLIF(TRIM(s.responsable_envio), '') ORDER BY s.responsable_envio SEPARATOR ', '), ''), '') AS responsable,
                    GROUP_CONCAT(DISTINCT tm.nombre ORDER BY tm.nombre SEPARATOR ', ') AS tipos_muestra,
                    SUM(COALESCE(s.numero_muestras, 0)) AS muestras_declaradas,
                    COUNT(DISTINCT CONCAT(sa.id_solicitud, ':', sa.id_tipo_analisis)) AS analisis_requeridos
                FROM solicitud s
                LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
                LEFT JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
                GROUP BY s.id_lote
            ) sol ON sol.id_lote = l.id_lote
            LEFT JOIN (
                SELECT s.id_lote, COUNT(DISTINCT m.id_muestra) AS total_muestras
                FROM solicitud s
                INNER JOIN muestra m ON m.id_solicitud = s.id_solicitud
                GROUP BY s.id_lote
            ) mu ON mu.id_lote = l.id_lote
            LEFT JOIN (
                SELECT
                    lr.id_lote,
                    COUNT(DISTINCT CONCAT(f.id_rango, ':', f.id_tipo_analisis)) AS analisis_ingresados,
                    COUNT(DISTINCT CASE
                        WHEN LOWER(COALESCE(ef.nombre, '')) = 'aprobado'
                        THEN CONCAT(f.id_rango, ':', f.id_tipo_analisis)
                    END) AS analisis_aprobados
                FROM lote_rango lr
                LEFT JOIN formulario f ON f.id_rango = lr.id_rango
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                GROUP BY lr.id_lote
            ) av ON av.id_lote = l.id_lote
            ORDER BY l.codigo_lote ASC, l.id_lote ASC
        ");

        $lotes = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($lotes as &$lote) {
            $requeridos = max(0, (int) ($lote['analisis_requeridos'] ?? 0));
            $ingresados = max(0, (int) ($lote['analisis_ingresados'] ?? 0));
            $aprobados = max(0, (int) ($lote['analisis_aprobados'] ?? 0));
            $porcentaje = $requeridos > 0 ? (int) round(min(1, $aprobados / $requeridos) * 100) : 0;

            if ($requeridos > 0 && $aprobados >= $requeridos) {
                $estado = ['codigo' => 'aprobado', 'texto' => 'LISTO PARA INFORME'];
            } elseif ($ingresados > 0) {
                $estado = ['codigo' => 'proceso', 'texto' => 'EN PROCESO'];
            } else {
                $estado = ['codigo' => 'recibido', 'texto' => 'RECIBIDA'];
            }

            $lote['analisis_requeridos'] = $requeridos;
            $lote['analisis_ingresados'] = $ingresados;
            $lote['analisis_aprobados'] = $aprobados;
            $lote['progreso'] = $porcentaje;
            $lote['estado'] = $estado;
        }
        unset($lote);

        return $lotes;
    }
}

if (!function_exists('lab_informes_panel')) {
    function lab_informes_panel(PDO $pdo): array
    {
        $lotes = lab_informes_resumen_lotes($pdo);
        $lotesPorId = [];
        $lotesConAvance = 0;
        foreach ($lotes as $lote) {
            $lotesPorId[(int) $lote['id_lote']] = $lote;
            if ((int) ($lote['analisis_ingresados'] ?? 0) > 0) {
                $lotesConAvance++;
            }
        }

        $documentos = lab_documentos_listar($pdo, 'informe');
        $firmas = lab_firmas_listar($pdo);
        $documentosFirmados = [];
        foreach ($firmas as $firma) {
            $documentosFirmados[(int) ($firma['id_documento'] ?? 0)] = true;
        }

        $informes = [];
        $porTipo = [];
        $pendientesFirma = 0;
        $firmados = 0;

        foreach ($documentos as $documento) {
            if (empty($documento['vigente'])) {
                continue;
            }

            $idDocumento = (int) ($documento['id_documento'] ?? 0);
            $idLote = (int) ($documento['id_lote'] ?? 0);
            $lote = $lotesPorId[$idLote] ?? [
                'id_lote' => $idLote,
                'codigo_lote' => $documento['codigo_lote'] ?? '-',
                'cliente' => 'Sin institución',
                'responsable' => '',
                'tipos_muestra' => 'Otros',
                'total_muestras' => 0,
            ];
            $estaFirmado = isset($documentosFirmados[$idDocumento]);
            $estaFirmado ? $firmados++ : $pendientesFirma++;

            $informe = array_merge($documento, [
                'lote' => $lote,
                'firmado' => $estaFirmado,
                'estado' => $estaFirmado ? 'FIRMADO' : 'LISTO PARA FIRMA',
            ]);
            $informes[] = $informe;

            $tipos = array_values(array_filter(array_map('trim', explode(',', (string) ($lote['tipos_muestra'] ?? 'Otros')))));
            if (!$tipos) {
                $tipos = ['Otros'];
            }
            foreach ($tipos as $tipo) {
                $porTipo[$tipo][] = $informe;
            }
        }

        uksort($porTipo, 'strnatcasecmp');

        return [
            'tabla_documentos_disponible' => lab_documentos_tabla_existe($pdo, 'lab_documento'),
            'lotes' => $lotes,
            'informes' => $informes,
            'informes_por_tipo' => $porTipo,
            'pendientes_firma' => $pendientesFirma,
            'firmados' => $firmados,
            'lotes_con_avance' => $lotesConAvance,
        ];
    }
}

