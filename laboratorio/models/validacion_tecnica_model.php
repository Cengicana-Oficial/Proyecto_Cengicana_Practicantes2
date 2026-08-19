<?php
/**
 * validacion_tecnica_model.php
 *
 * Matriz de validacion tecnica: filas = lotes con analisis capturados
 * (`formulario`), columnas = tipo de analisis. No crea tabla nueva:
 * reutiliza `formulario` + `estado_formulario` (igual que kanban_model.php)
 * y considera "pendiente de validar" cualquier formulario cuyo estado
 * contenga "revis" (cubre tanto el estado "En revision" sembrado por
 * database/002_lab_flujo_generico.sql para el tablero Kanban, como el
 * estado legado "Revisar" usado por includes/formulario_revision_helper.php),
 * para no dejar fuera analisis que ya estan pendientes de revision bajo
 * cualquiera de los dos flujos existentes.
 *
 * Aprobar/rechazar sigue el mismo patron transaccional que
 * lab_kanban_mover_tarjeta() de models/kanban_model.php: UPDATE
 * formulario.id_estado + INSERT historial_formulario + evento en
 * lab_evento_trazabilidad.
 */

require_once __DIR__ . '/trazabilidad_model.php';

if (!function_exists('lab_validacion_estado_es_revision')) {
    function lab_validacion_estado_es_revision(?string $nombreEstado): bool
    {
        return strpos(mb_strtolower(trim((string) $nombreEstado)), 'revis') !== false;
    }
}

if (!function_exists('lab_validacion_contar_pendientes')) {
    function lab_validacion_contar_pendientes(PDO $pdo): int
    {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM formulario f
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            WHERE LOWER(TRIM(COALESCE(ef.nombre, ''))) LIKE '%revis%'
        ");

        return $stmt ? (int) $stmt->fetchColumn() : 0;
    }
}

if (!function_exists('lab_validacion_obtener_matriz')) {
    /**
     * Devuelve ['lotes' => [...], 'columnas' => [...tipo_analisis...],
     * 'celdas' => [id_lote][id_tipo_analisis] => fila de formulario].
     * Solo incluye lotes que tienen al menos un analisis pendiente de
     * validar (estado "revision"/"revisar"), para que la matriz sea
     * realmente la cola de trabajo del validador y no un listado completo
     * de todo el historico.
     */
    function lab_validacion_obtener_matriz(PDO $pdo, ?int $idLoteFiltro = null): array
    {
        $sql = "
            SELECT
                f.id_formulario,
                f.analista,
                f.fecha,
                f.id_estado,
                ef.nombre AS estado_nombre,
                ta.id_tipo AS id_tipo_analisis,
                ta.nombre AS analisis_nombre,
                l.id_lote,
                l.codigo_lote
            FROM formulario f
            INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
            INNER JOIN lote l ON l.id_lote = lr.id_lote
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
            WHERE l.id_lote IN (
                SELECT l2.id_lote
                FROM formulario f2
                INNER JOIN lote_rango lr2 ON lr2.id_rango = f2.id_rango
                INNER JOIN lote l2 ON l2.id_lote = lr2.id_lote
                LEFT JOIN estado_formulario ef2 ON ef2.id_estado = f2.id_estado
                WHERE LOWER(TRIM(COALESCE(ef2.nombre, ''))) LIKE '%revis%'
            )
        ";
        $params = [];
        if ($idLoteFiltro) {
            $sql .= ' AND l.id_lote = ?';
            $params[] = $idLoteFiltro;
        }
        $sql .= ' ORDER BY l.codigo_lote ASC, ta.nombre ASC, f.fecha DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lotes = [];
        $columnas = [];
        $celdas = [];

        foreach ($filas as $fila) {
            $idLote = (int) $fila['id_lote'];
            $idAnalisis = (int) $fila['id_tipo_analisis'];

            if (!isset($lotes[$idLote])) {
                $lotes[$idLote] = ['id_lote' => $idLote, 'codigo_lote' => $fila['codigo_lote']];
            }
            if ($idAnalisis > 0 && !isset($columnas[$idAnalisis])) {
                $columnas[$idAnalisis] = ['id_tipo_analisis' => $idAnalisis, 'nombre' => $fila['analisis_nombre'] ?: 'Analisis'];
            }
            if ($idAnalisis > 0) {
                // Si ya hay una celda para este lote+analisis, nos quedamos con la
                // mas reciente (ORDER BY f.fecha DESC ya la deja primero).
                if (!isset($celdas[$idLote][$idAnalisis])) {
                    $celdas[$idLote][$idAnalisis] = $fila;
                }
            }
        }

        usort($columnas, static function ($a, $b) {
            return strcasecmp((string) $a['nombre'], (string) $b['nombre']);
        });

        return [
            'lotes' => array_values($lotes),
            'columnas' => array_values($columnas),
            'celdas' => $celdas,
        ];
    }
}

if (!function_exists('lab_validacion_resolver')) {
    /**
     * Aprueba o rechaza un analisis (formulario) puntual dentro de la
     * matriz de validacion tecnica. $accion debe ser 'aprobar' o
     * 'rechazar'. Mismo patron transaccional que lab_kanban_mover_tarjeta().
     */
    function lab_validacion_resolver(PDO $pdo, int $idFormulario, string $accion, string $usuario, ?int $usuarioId = null, string $comentario = ''): array
    {
        if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
            throw new InvalidArgumentException('Accion no valida.');
        }

        $stmt = $pdo->prepare("
            SELECT f.id_formulario, f.id_estado, f.id_rango, ef.nombre AS estado_actual, lr.id_lote
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

        $nuevoEstadoNombre = $accion === 'aprobar' ? 'Aprobado' : 'Rechazado';
        $comentarioHistorial = $comentario !== ''
            ? $comentario
            : ($accion === 'aprobar' ? 'Aprobado en validacion tecnica.' : 'Rechazado en validacion tecnica.');

        $pdo->beginTransaction();
        try {
            $stmtEstado = $pdo->prepare('SELECT id_estado FROM estado_formulario WHERE LOWER(nombre) = LOWER(?) LIMIT 1');
            $stmtEstado->execute([$nuevoEstadoNombre]);
            $idNuevoEstado = $stmtEstado->fetchColumn();

            if (!$idNuevoEstado) {
                $insertEstado = $pdo->prepare('INSERT INTO estado_formulario (nombre) VALUES (?)');
                $insertEstado->execute([$nuevoEstadoNombre]);
                $idNuevoEstado = $pdo->lastInsertId();
            }

            $update = $pdo->prepare('UPDATE formulario SET id_estado = ? WHERE id_formulario = ?');
            $update->execute([(int) $idNuevoEstado, $idFormulario]);

            $hist = $pdo->prepare("
                INSERT INTO historial_formulario (id_formulario, accion, estado_anterior, estado_nuevo, usuario, fecha, comentario)
                VALUES (?, 'validacion_tecnica', ?, ?, ?, NOW(), ?)
            ");
            $hist->execute([
                $idFormulario,
                $formulario['estado_actual'] ?? null,
                $nuevoEstadoNombre,
                $usuario !== '' ? $usuario : null,
                $comentarioHistorial,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        lab_trazabilidad_registrar_evento($pdo, [
            'id_lote' => (int) $formulario['id_lote'],
            'id_formulario' => $idFormulario,
            'tipo_evento' => $accion === 'aprobar' ? 'aprobado' : 'rechazado',
            'descripcion' => sprintf(
                'Analisis %s en validacion tecnica (de "%s" a "%s").',
                $accion === 'aprobar' ? 'aprobado' : 'rechazado',
                $formulario['estado_actual'] ?? 'Sin estado',
                $nuevoEstadoNombre
            ) . ($comentario !== '' ? ' Motivo: ' . $comentario : ''),
            'es_alerta' => $accion === 'rechazar',
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
