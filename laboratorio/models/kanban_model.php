<?php
/**
 * kanban_model.php
 *
 * Tablero de planificacion (Kanban) de analisis por lote. NO crea una
 * tabla nueva: reutiliza el par ya existente `formulario` (una fila por
 * analisis asignado dentro de un rango de lote, con `analista` y
 * `id_estado`) + `estado_formulario` (catalogo de estados) que ya usan
 * labc_bandeja_model.php, consolidacion_model.php, etc. Cada movimiento
 * de columna es un UPDATE de `formulario.id_estado` + un INSERT en
 * `historial_formulario` (igual patron que el resto del modulo) y,
 * cuando la migracion 002 esta aplicada, tambien un evento en
 * `lab_evento_trazabilidad`.
 */

require_once __DIR__ . '/trazabilidad_model.php';

if (!function_exists('lab_kanban_columnas_definicion')) {
    /**
     * Orden fijo de columnas del tablero. Los nombres deben coincidir
     * (sin distinguir mayusculas) con los sembrados en
     * database/002_lab_flujo_generico.sql.
     */
    function lab_kanban_columnas_definicion(): array
    {
        return [
            'Pendiente',
            'Asignado',
            'En proceso',
            'En revision',
            'Aprobado',
        ];
    }
}

if (!function_exists('lab_kanban_estados_catalogo')) {
    function lab_kanban_estados_catalogo(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT id_estado, nombre FROM estado_formulario');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $porNombre = [];
        foreach ($rows as $row) {
            $porNombre[mb_strtolower(trim((string) $row['nombre']))] = (int) $row['id_estado'];
        }

        return $porNombre;
    }
}

if (!function_exists('lab_kanban_obtener_o_crear_estado')) {
    function lab_kanban_obtener_o_crear_estado(PDO $pdo, string $nombre): int
    {
        $catalogo = lab_kanban_estados_catalogo($pdo);
        $clave = mb_strtolower(trim($nombre));

        if (isset($catalogo[$clave])) {
            return $catalogo[$clave];
        }

        $stmt = $pdo->prepare('INSERT INTO estado_formulario (nombre) VALUES (?)');
        $stmt->execute([$nombre]);

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('lab_kanban_columnas')) {
    /**
     * Devuelve las columnas del tablero con su id_estado resuelto (o null
     * si ese estado aun no existe en estado_formulario, ej. si no se ha
     * aplicado la migracion/seed).
     */
    function lab_kanban_columnas(PDO $pdo): array
    {
        $catalogo = lab_kanban_estados_catalogo($pdo);
        $columnas = [];

        foreach (lab_kanban_columnas_definicion() as $nombre) {
            $clave = mb_strtolower($nombre);
            $columnas[] = [
                'nombre' => $nombre,
                'id_estado' => $catalogo[$clave] ?? null,
            ];
        }

        return $columnas;
    }
}

if (!function_exists('lab_kanban_tarjetas')) {
    /**
     * Tarjetas del tablero: una por cada `formulario` (analisis asignado
     * a un rango de lote), con datos de lote/tipo de muestra/analisis.
     */
    function lab_kanban_tarjetas(PDO $pdo, ?int $idLote = null): array
    {
        $sql = "
            SELECT
                f.id_formulario,
                f.analista,
                f.fecha,
                f.id_estado,
                ef.nombre AS estado_nombre,
                ta.nombre AS analisis_nombre,
                tm.nombre AS tipo_muestra,
                l.id_lote,
                l.codigo_lote
            FROM formulario f
            INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
            INNER JOIN lote l ON l.id_lote = lr.id_lote
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
            LEFT JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
        ";
        $params = [];
        if ($idLote) {
            $sql .= ' WHERE l.id_lote = ?';
            $params[] = $idLote;
        }
        $sql .= ' ORDER BY f.fecha DESC, f.id_formulario DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lab_kanban_mover_tarjeta')) {
    /**
     * Mueve una tarjeta (formulario) a una nueva columna (estado),
     * dejando registro en historial_formulario y, si aplica, en
     * lab_evento_trazabilidad. Usa transaccion PDO igual que
     * solicitud_formulario.php.
     */
    function lab_kanban_mover_tarjeta(PDO $pdo, int $idFormulario, string $nuevoEstadoNombre, string $usuario, ?int $usuarioId = null): array
    {
        $stmt = $pdo->prepare("
            SELECT f.id_formulario, f.id_estado, f.id_rango, f.id_tipo_analisis, ef.nombre AS estado_actual, lr.id_lote
            FROM formulario f
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
            WHERE f.id_formulario = ?
            LIMIT 1
        ");
        $stmt->execute([$idFormulario]);
        $formulario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$formulario) {
            throw new RuntimeException('El analisis indicado no existe.');
        }

        $pdo->beginTransaction();

        try {
            $idNuevoEstado = lab_kanban_obtener_o_crear_estado($pdo, $nuevoEstadoNombre);

            $update = $pdo->prepare('UPDATE formulario SET id_estado = ? WHERE id_formulario = ?');
            $update->execute([$idNuevoEstado, $idFormulario]);

            $hist = $pdo->prepare("
                INSERT INTO historial_formulario (id_formulario, accion, estado_anterior, estado_nuevo, usuario, fecha, comentario)
                VALUES (?, 'cambio_kanban', ?, ?, ?, NOW(), ?)
            ");
            $hist->execute([
                $idFormulario,
                $formulario['estado_actual'] ?? null,
                $nuevoEstadoNombre,
                $usuario !== '' ? $usuario : null,
                'Movimiento en tablero de planificacion',
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $tipoEvento = 'analisis_en_proceso';
        $claveEstado = mb_strtolower($nuevoEstadoNombre);
        if (strpos($claveEstado, 'revision') !== false) {
            $tipoEvento = 'enviado_validacion';
        } elseif (strpos($claveEstado, 'aprob') !== false) {
            $tipoEvento = 'aprobado';
        } elseif (strpos($claveEstado, 'rechaz') !== false) {
            $tipoEvento = 'rechazado';
        } elseif (strpos($claveEstado, 'asignad') !== false) {
            $tipoEvento = 'analisis_asignado';
        }

        lab_trazabilidad_registrar_evento($pdo, [
            'id_lote' => (int) $formulario['id_lote'],
            'id_formulario' => $idFormulario,
            'tipo_evento' => $tipoEvento,
            'descripcion' => sprintf('Analisis movido de "%s" a "%s" en el tablero de planificacion.', $formulario['estado_actual'] ?? 'Sin estado', $nuevoEstadoNombre),
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuario,
        ]);

        return [
            'id_formulario' => $idFormulario,
            'estado_anterior' => $formulario['estado_actual'],
            'estado_nuevo' => $nuevoEstadoNombre,
        ];
    }
}
