<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../includes/formulario_revision_helper.php';

function labGenericLower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function labGenericConditions(array $values, string $column, array &$params): string
{
    $parts = [];
    foreach ($values as $value) {
        $lower = labGenericLower((string) $value);
        $parts[] = "LOWER($column) = ?";
        $params[] = $lower;
    }

    return '(' . implode(' OR ', $parts) . ')';
}

function labGenericDestino(array $config, string $codigoLote, string $numeroLaboratorio): array
{
    $pdo = Conexion::conectar();
    $params = [$codigoLote];
    $tipoCondition = labGenericConditions($config['tipos'] ?? [], 'tm.nombre', $params);
    $analisisCondition = labGenericConditions($config['analisis'] ?? [], 'ta.nombre', $params);

    $sql = "
        SELECT s.id_solicitud,
               l.id_lote,
               lr.id_rango,
               ta.id_tipo AS id_tipo_analisis,
               m.numero_muestra
          FROM lote l
          LEFT JOIN solicitud s ON s.id_lote = l.id_lote
          LEFT JOIN lote_rango lr ON lr.id_lote = l.id_lote
          LEFT JOIN muestra m
                 ON m.id_solicitud = s.id_solicitud
                AND (m.codigo_lab = ? OR CAST(m.numero_muestra AS CHAR) = ?)
          LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
          LEFT JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
          LEFT JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
         WHERE l.codigo_lote = ?
           AND ($tipoCondition OR tm.id_tipo IS NULL)
           AND (ta.id_tipo IS NULL OR (COALESCE(ta.activo, 1) = 1 AND ($analisisCondition)))
         ORDER BY s.id_solicitud DESC, lr.id_rango DESC
         LIMIT 1
    ";

    array_unshift($params, $numeroLaboratorio, $numeroLaboratorio);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return $row;
    }

    $stmt = $pdo->prepare("
        SELECT l.id_lote, lr.id_rango
          FROM lote l
          LEFT JOIN lote_rango lr ON lr.id_lote = l.id_lote
         WHERE l.codigo_lote = ?
         ORDER BY lr.id_rango DESC
         LIMIT 1
    ");
    $stmt->execute([$codigoLote]);
    $fallback = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $tipoParams = [];
    $tipoCondition = labGenericConditions($config['tipos'] ?? [], 'tm.nombre', $tipoParams);
    $analisisCondition = labGenericConditions($config['analisis'] ?? [], 'ta.nombre', $tipoParams);
    $stmt = $pdo->prepare("
        SELECT ta.id_tipo AS id_tipo_analisis
          FROM tipo_analisis ta
          INNER JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
         WHERE $tipoCondition
           AND COALESCE(ta.activo, 1) = 1
           AND $analisisCondition
         ORDER BY ta.id_tipo DESC
         LIMIT 1
    ");
    $stmt->execute($tipoParams);
    $tipo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return array_merge([
        'id_solicitud' => null,
        'id_lote' => null,
        'id_rango' => null,
        'id_tipo_analisis' => null,
        'numero_muestra' => is_numeric($numeroLaboratorio) ? (int) $numeroLaboratorio : null,
    ], $fallback, $tipo);
}

function labGenericCrearFormulario(array $destino, string $fecha, string $analista): int
{
    $pdo = Conexion::conectar();
    $estadoRevisar = labFormularioEstadoRevisarId();
    $stmt = $pdo->prepare("
        INSERT INTO formulario (id_estado, id_rango, id_tipo_analisis, fecha, analista)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $estadoRevisar,
        $destino['id_rango'] !== null ? (int) $destino['id_rango'] : null,
        $destino['id_tipo_analisis'] !== null ? (int) $destino['id_tipo_analisis'] : null,
        $fecha,
        $analista,
    ]);

    return (int) $pdo->lastInsertId();
}

function labGenericLotesConAnalisisIngresado(array $config, array $lotes): array
{
    $lotes = array_values(array_unique(array_filter(array_map(static function ($lote) {
        return trim((string) $lote);
    }, $lotes))));

    if (!$lotes) {
        return [];
    }

    $pdo = Conexion::conectar();
    $params = [];
    $tipoCondition = labGenericConditions($config['tipos'] ?? [], 'tm.nombre', $params);
    $analisisCondition = labGenericConditions($config['analisis'] ?? [], 'ta.nombre', $params);
    $placeholders = implode(', ', array_fill(0, count($lotes), '?'));
    $params = array_merge($params, $lotes);

    $stmt = $pdo->prepare("
        SELECT DISTINCT l.codigo_lote
          FROM lote l
          INNER JOIN solicitud s ON s.id_lote = l.id_lote
          INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
          INNER JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
          INNER JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
          LEFT JOIN lote_rango lr ON lr.id_lote = l.id_lote
          INNER JOIN formulario f
                  ON f.id_rango = lr.id_rango
                 AND f.id_tipo_analisis = ta.id_tipo
         WHERE {$tipoCondition}
           AND COALESCE(ta.activo, 1) = 1
           AND {$analisisCondition}
           AND l.codigo_lote IN ({$placeholders})
    ");
    $stmt->execute($params);

    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function labGenericMuestraYaAnalizada(array $config, string $codigoLote, string $numeroLaboratorio): bool
{
    $codigoLote = trim($codigoLote);
    $numeroLaboratorio = trim($numeroLaboratorio);
    if ($codigoLote === '' || $numeroLaboratorio === '') {
        return false;
    }

    $pdo = Conexion::conectar();
    $table = $config['table'];
    $columnas = labGenericTableColumns($table);
    if (!$columnas) {
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

    $numeroNormalizado = null;
    if (preg_match('/^[A-Za-z]+-(\d+)-\d{2}-\d{2}$/', $numeroLaboratorio, $matches)) {
        $numeroNormalizado = (int) $matches[1];
    } elseif (is_numeric($numeroLaboratorio)) {
        $numeroNormalizado = (int) $numeroLaboratorio;
    } elseif (preg_match('/(\d+)/', $numeroLaboratorio, $matches)) {
        $numeroNormalizado = (int) $matches[1];
    }

    if (in_array('no_lab', $columnas, true)) {
        $conds[] = 't.no_lab = ?';
        $params[] = $numeroLaboratorio;
    } elseif (in_array('numero_laboratorio', $columnas, true)) {
        if ($numeroNormalizado === null) {
            return false;
        }
        $conds[] = 't.numero_laboratorio = ?';
        $params[] = $numeroNormalizado;
    } elseif (in_array('numero_muestra', $columnas, true)) {
        if ($numeroNormalizado === null) {
            return false;
        }
        $conds[] = 't.numero_muestra = ?';
        $params[] = $numeroNormalizado;
    } else {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT 1
          FROM `{$table}` t
          " . implode("\n", $joins) . "
         WHERE " . implode(' AND ', $conds) . "
         LIMIT 1
    ");
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function labGenericTableColumns(string $table): array
{
    $pdo = Conexion::conectar();
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function labGenericInsertarAnalisis(array $config, array $row, array $destino, int $idFormulario, string $fecha, string $codigoLote, string $numeroLaboratorio): void
{
    $pdo = Conexion::conectar();
    $table = $config['table'];
    $tableColumns = labGenericTableColumns($table);
    $data = [];

    foreach ($config['fields'] as $field) {
        $name = $field['name'];
        if (in_array($name, $tableColumns, true)) {
            $data[$name] = $row[$name] ?? null;
        }
    }

    $metadata = [
        'id_solicitud' => $destino['id_solicitud'] !== null ? (int) $destino['id_solicitud'] : null,
        'numero_laboratorio' => $destino['numero_muestra'] !== null ? (int) $destino['numero_muestra'] : null,
        'id_lote' => $destino['id_lote'] !== null ? (int) $destino['id_lote'] : null,
        'id_formulario' => $idFormulario,
        'fecha' => $fecha,
        'no_lab' => $numeroLaboratorio,
        'lote' => $codigoLote,
    ];

    foreach ($metadata as $column => $value) {
        if (in_array($column, $tableColumns, true) && !array_key_exists($column, $data)) {
            $data[$column] = $value;
        }
    }

    $columns = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $quotedColumns = implode(', ', array_map(fn($column) => "`$column`", $columns));
    $stmt = $pdo->prepare("INSERT INTO `$table` ($quotedColumns) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
}

function labGenericGuardarAnalisis(array $config, array $rows, string $fecha, string $analista): array
{
    if (!$rows) {
        return ['exito' => false, 'mensaje' => 'Ingrese al menos una fila para guardar.'];
    }

    if ($fecha === '' || $analista === '') {
        return ['exito' => false, 'mensaje' => 'Complete fecha y analista para guardar.'];
    }

    $guardados = 0;
    $errores = [];
    labFormularioEnsureSchema();
    foreach ($rows as $index => $row) {
        $codigoLote = trim((string) ($row['lote'] ?? ''));
        $numeroLaboratorio = trim((string) ($row['numero_laboratorio'] ?? ''));

        if ($codigoLote === '') {
            $errores[] = 'Fila ' . ($index + 1) . ': seleccione un lote.';
            continue;
        }

        if ($numeroLaboratorio === '') {
            $errores[] = 'Fila ' . ($index + 1) . ': seleccione un numero de laboratorio.';
            continue;
        }

        if (labGenericMuestraYaAnalizada($config, $codigoLote, $numeroLaboratorio)) {
            $errores[] = 'Fila ' . ($index + 1) . ': la muestra ' . $numeroLaboratorio . ' del lote ' . $codigoLote . ' ya tiene este analisis ingresado.';
            continue;
        }

        try {
            $pdo = Conexion::conectar();
            $useTransaction = !$pdo->inTransaction();
            if ($useTransaction) {
                $pdo->beginTransaction();
            }

            $destino = labGenericDestino($config, $codigoLote, $numeroLaboratorio);
            $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
            labGenericInsertarAnalisis($config, $row, $destino, $idFormulario, $fecha, $codigoLote, $numeroLaboratorio);
            labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis.');

            if ($useTransaction) {
                $pdo->commit();
            }

            $guardados++;
        } catch (Throwable $e) {
            if (isset($pdo, $useTransaction) && $useTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errores[] = 'Fila ' . ($index + 1) . ': ' . $e->getMessage();
        }
    }

    if ($errores) {
        return [
            'exito' => false,
            'mensaje' => 'Se guardaron ' . $guardados . ' fila(s), pero hubo errores. ' . implode(' ', $errores),
        ];
    }

    return ['exito' => true, 'mensaje' => 'Filas guardadas correctamente: ' . $guardados . '.'];
}
