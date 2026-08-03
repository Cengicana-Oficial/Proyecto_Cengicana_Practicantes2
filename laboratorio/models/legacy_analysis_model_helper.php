<?php
require_once __DIR__ . '/../includes/legacy_analysis_form_helper.php';

if (!function_exists('labLegacyTableColumns')) {
    function labLegacyTableColumns(PDO $conn, string $table): array
    {
        static $cache = [];

        if (!isset($cache[$table])) {
            $stmt = $conn->query("SHOW COLUMNS FROM `$table`");
            $cache[$table] = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        }

        return $cache[$table];
    }
}

if (!function_exists('labLegacyInsertAnalysisRow')) {
    function labLegacyAnalysisRowExists(PDO $conn, string $table, array $columnas, array $metadata): bool
    {
        $codigoLote = trim((string) ($metadata['codigo_lote'] ?? $metadata['lote'] ?? ''));
        $numero = trim((string) ($metadata['no_lab'] ?? $metadata['numero_laboratorio'] ?? $metadata['numero_muestra'] ?? ''));
        if ($codigoLote === '' || $numero === '') {
            return false;
        }

        $joins = [];
        $conds = [];
        $params = [];

        if (in_array('id_lote', $columnas, true)) {
            $joins[] = 'INNER JOIN lote l ON l.id_lote = t.id_lote';
            $conds[] = 'l.codigo_lote = ?';
            $params[] = $codigoLote;
        } elseif (in_array('codigo_lote', $columnas, true)) {
            $conds[] = 't.codigo_lote = ?';
            $params[] = $codigoLote;
        } elseif (in_array('lote', $columnas, true)) {
            $conds[] = 't.lote = ?';
            $params[] = $codigoLote;
        } else {
            return false;
        }

        if (in_array('no_lab', $columnas, true)) {
            $conds[] = 't.no_lab = ?';
            $params[] = $numero;
        } elseif (in_array('numero_laboratorio', $columnas, true)) {
            $conds[] = 'CAST(t.numero_laboratorio AS CHAR) = ?';
            $params[] = $numero;
        } elseif (in_array('numero_muestra', $columnas, true)) {
            $conds[] = 'CAST(t.numero_muestra AS CHAR) = ?';
            $params[] = $numero;
        } else {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT 1
              FROM `{$table}` t
              " . implode("\n", $joins) . "
             WHERE " . implode(' AND ', $conds) . "
             LIMIT 1
        ");
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('labLegacyInsertAnalysisRow')) {
    function labLegacyInsertAnalysisRow(PDO $conn, string $table, array $data, array $metadata = [])
    {
        if (!$metadata && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $metadata = labLegacyAutoMetadataForInsert();
        }

        $columnas = labLegacyTableColumns($conn, $table);
        if (labLegacyAnalysisRowExists($conn, $table, $columnas, $metadata)) {
            return false;
        }

        $payload = [];

        foreach ($data as $columna => $valor) {
            if (in_array($columna, $columnas, true)) {
                $payload[$columna] = $valor;
            }
        }

        foreach (['id_solicitud', 'numero_laboratorio', 'id_lote', 'id_formulario', 'fecha', 'lote', 'codigo_lote', 'no_lab', 'numero_muestra'] as $columna) {
            if (
                array_key_exists($columna, $metadata)
                && !array_key_exists($columna, $payload)
                && in_array($columna, $columnas, true)
            ) {
                $payload[$columna] = $metadata[$columna];
            }
        }

        if (!$payload) {
            return false;
        }

        $columnasSql = implode(', ', array_map(static fn($columna) => "`$columna`", array_keys($payload)));
        $placeholders = implode(', ', array_fill(0, count($payload), '?'));
        $stmt = $conn->prepare("INSERT INTO `$table` ($columnasSql) VALUES ($placeholders)");

        if (!$stmt->execute(array_values($payload))) {
            return false;
        }

        return (int) $conn->lastInsertId();
    }
}
