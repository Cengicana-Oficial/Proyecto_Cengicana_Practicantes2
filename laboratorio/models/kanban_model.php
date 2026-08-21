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

if (!function_exists('lab_planificacion_estado')) {
    function lab_planificacion_estado(?string $estado, int $versiones = 0): array
    {
        $nombre = mb_strtolower(trim((string) $estado));
        if ($nombre === '') {
            return ['key' => 'pendiente', 'label' => 'Pendiente', 'icon' => '○'];
        }
        if (strpos($nombre, 'rechaz') !== false) {
            return ['key' => 'rechazado', 'label' => 'Rechazado', 'icon' => '✕'];
        }
        if ($versiones > 1 && strpos($nombre, 'aprob') === false) {
            return ['key' => 'repetido', 'label' => 'Repetido', 'icon' => '↻'];
        }
        if (strpos($nombre, 'aprob') !== false) {
            return ['key' => 'validado', 'label' => 'Validado', 'icon' => '✓'];
        }
        if (strpos($nombre, 'revis') !== false || strpos($nombre, 'valid') !== false) {
            return ['key' => 'validacion', 'label' => 'Finalizado (en validacion)', 'icon' => '●'];
        }
        return ['key' => 'proceso', 'label' => 'En proceso', 'icon' => '◐'];
    }
}

if (!function_exists('lab_planificacion_prioridad')) {
    function lab_planificacion_prioridad($fechaEstimada): array
    {
        $fecha = trim((string) $fechaEstimada);
        if ($fecha === '') {
            return ['key' => 'baja', 'label' => 'Baja', 'rank' => 1];
        }

        $hoy = new DateTimeImmutable('today');
        $vence = DateTimeImmutable::createFromFormat('Y-m-d', substr($fecha, 0, 10));
        if (!$vence) {
            return ['key' => 'baja', 'label' => 'Baja', 'rank' => 1];
        }
        $dias = (int) $hoy->diff($vence)->format('%r%a');
        if ($dias <= 1) {
            return ['key' => 'alta', 'label' => 'Alta', 'rank' => 3];
        }
        if ($dias <= 4) {
            return ['key' => 'media', 'label' => 'Media', 'rank' => 2];
        }
        return ['key' => 'baja', 'label' => 'Baja', 'rank' => 1];
    }
}

if (!function_exists('lab_planificacion_tareas')) {
    function lab_planificacion_tareas(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT
                m.id_muestra,
                m.codigo_lab,
                m.numero_muestra,
                s.id_solicitud,
                s.id_lote,
                s.fecha_ingreso,
                s.fecha_estimada,
                COALESCE(NULLIF(TRIM(s.institucion), ''), 'Sin cliente') AS cliente,
                l.codigo_lote,
                tm.nombre AS tipo_muestra,
                ta.id_tipo AS id_tipo_analisis,
                ta.nombre AS analisis_nombre,
                ultimo.id_formulario,
                ultimo.versiones,
                f.analista,
                f.fecha AS fecha_formulario,
                ef.nombre AS estado_nombre
            FROM muestra m
            INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
            INNER JOIN lote l ON l.id_lote = s.id_lote
            INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
            INNER JOIN solicitud_analisis sa ON sa.id_solicitud = s.id_solicitud
            INNER JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
            LEFT JOIN (
                SELECT
                    lr.id_lote,
                    f2.id_tipo_analisis,
                    MAX(f2.id_formulario) AS id_formulario,
                    COUNT(*) AS versiones
                FROM formulario f2
                INNER JOIN lote_rango lr ON lr.id_rango = f2.id_rango
                GROUP BY lr.id_lote, f2.id_tipo_analisis
            ) ultimo
                ON ultimo.id_lote = s.id_lote
               AND ultimo.id_tipo_analisis = ta.id_tipo
            LEFT JOIN formulario f ON f.id_formulario = ultimo.id_formulario
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            WHERE COALESCE(ta.activo, 1) = 1
            ORDER BY tm.nombre, ta.nombre, l.codigo_lote, m.numero_muestra, m.id_muestra
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['estado'] = lab_planificacion_estado($row['estado_nombre'] ?? null, (int) ($row['versiones'] ?? 0));
            $row['prioridad'] = lab_planificacion_prioridad($row['fecha_estimada'] ?? null);
            $row['codigo_muestra'] = trim((string) ($row['codigo_lab'] ?? '')) !== ''
                ? (string) $row['codigo_lab']
                : (string) $row['numero_muestra'];
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('lab_planificacion_colas')) {
    function lab_planificacion_colas(array $tareas): array
    {
        $colas = [];
        foreach ($tareas as $tarea) {
            $key = (string) $tarea['tipo_muestra'] . '|' . (int) $tarea['id_tipo_analisis'];
            if (!isset($colas[$key])) {
                $colas[$key] = [
                    'key' => $key,
                    'tipo_muestra' => (string) $tarea['tipo_muestra'],
                    'id_tipo_analisis' => (int) $tarea['id_tipo_analisis'],
                    'analisis_nombre' => (string) $tarea['analisis_nombre'],
                    'items' => [],
                    'lotes' => [],
                    'clientes' => [],
                    'analistas' => [],
                    'form_ids' => [],
                    'estado_counts' => [],
                    'prioridad' => ['key' => 'baja', 'label' => 'Baja', 'rank' => 1],
                    'fecha_estimada' => null,
                ];
            }

            $cola =& $colas[$key];
            $cola['items'][] = $tarea;
            $cola['lotes'][(int) $tarea['id_lote']] = (string) $tarea['codigo_lote'];
            $cola['clientes'][(string) $tarea['cliente']] = (string) $tarea['cliente'];
            if (!empty($tarea['analista'])) {
                $cola['analistas'][(string) $tarea['analista']] = (string) $tarea['analista'];
            }
            if (!empty($tarea['id_formulario'])) {
                $cola['form_ids'][(int) $tarea['id_formulario']] = (int) $tarea['id_formulario'];
            }
            $estadoKey = (string) ($tarea['estado']['key'] ?? 'pendiente');
            $cola['estado_counts'][$estadoKey] = ($cola['estado_counts'][$estadoKey] ?? 0) + 1;
            if ((int) ($tarea['prioridad']['rank'] ?? 0) > (int) ($cola['prioridad']['rank'] ?? 0)) {
                $cola['prioridad'] = $tarea['prioridad'];
            }
            if (!empty($tarea['fecha_estimada']) && ($cola['fecha_estimada'] === null || $tarea['fecha_estimada'] < $cola['fecha_estimada'])) {
                $cola['fecha_estimada'] = $tarea['fecha_estimada'];
            }
            unset($cola);
        }

        foreach ($colas as &$cola) {
            $total = count($cola['items']);
            $validos = (int) ($cola['estado_counts']['validado'] ?? 0);
            if ($total > 0 && $validos === $total) {
                $cola['estado'] = ['key' => 'validado', 'label' => 'APROBADA'];
                $cola['kanban'] = 'finalizadas';
            } elseif (!empty($cola['estado_counts']['validacion']) || !empty($cola['estado_counts']['rechazado']) || !empty($cola['estado_counts']['repetido'])) {
                $cola['estado'] = ['key' => 'validacion', 'label' => 'EN REVISIÓN'];
                $cola['kanban'] = 'validacion';
            } elseif (!empty($cola['estado_counts']['proceso']) || $validos > 0) {
                $cola['estado'] = ['key' => 'proceso', 'label' => 'EN PROCESO'];
                $cola['kanban'] = 'proceso';
            } else {
                $cola['estado'] = ['key' => 'pendiente', 'label' => 'RECIBIDA'];
                $cola['kanban'] = 'pendientes';
            }
            $cola['lotes'] = array_values($cola['lotes']);
            $cola['clientes'] = array_values($cola['clientes']);
            $cola['analistas'] = array_values($cola['analistas']);
            $cola['form_ids'] = array_values($cola['form_ids']);
        }
        unset($cola);

        return array_values($colas);
    }
}

if (!function_exists('lab_planificacion_resumen_muestras')) {
    function lab_planificacion_resumen_muestras(array $tareas): array
    {
        $porMuestra = [];
        foreach ($tareas as $tarea) {
            $porMuestra[(int) $tarea['id_muestra']][] = (string) ($tarea['estado']['key'] ?? 'pendiente');
        }

        $resumen = ['pendientes' => 0, 'proceso' => 0, 'finalizadas' => 0];
        foreach ($porMuestra as $estados) {
            if ($estados && count(array_filter($estados, static fn($estado) => $estado === 'validado')) === count($estados)) {
                $resumen['finalizadas']++;
            } elseif (count(array_filter($estados, static fn($estado) => $estado !== 'pendiente')) > 0) {
                $resumen['proceso']++;
            } else {
                $resumen['pendientes']++;
            }
        }

        return $resumen;
    }
}

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
