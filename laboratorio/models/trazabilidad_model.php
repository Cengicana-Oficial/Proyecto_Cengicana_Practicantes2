<?php
/**
 * trazabilidad_model.php
 *
 * Trazabilidad completa de un lote: combina eventos genericos de
 * lote/solicitud (tabla nueva `lab_evento_trazabilidad`) con el
 * historial ya existente por analisis (`historial_formulario`, ligado a
 * `formulario` -> `lote_rango` -> `lote`), para no duplicar lo que el
 * modulo ya registraba.
 */

if (!function_exists('lab_trazabilidad_tabla_existe')) {
    function lab_trazabilidad_tabla_existe(PDO $pdo, string $tabla): bool
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

if (!function_exists('lab_trazabilidad_registrar_evento')) {
    /**
     * Registra un evento de trazabilidad a nivel de lote/solicitud.
     * Si la migracion 002_lab_flujo_generico.sql no se ha aplicado
     * todavia en el ambiente actual, falla en silencio (no rompe el
     * flujo que la esta llamando, ej. guardado de una solicitud).
     */
    function lab_trazabilidad_registrar_evento(PDO $pdo, array $datos): ?int
    {
        if (!lab_trazabilidad_tabla_existe($pdo, 'lab_evento_trazabilidad')) {
            return null;
        }

        $idLote = (int) ($datos['id_lote'] ?? 0);
        if ($idLote <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO lab_evento_trazabilidad
                    (id_lote, id_solicitud, id_formulario, codigo_muestra, tipo_evento, descripcion, es_alerta, usuario_id, usuario_nombre)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $idLote,
                !empty($datos['id_solicitud']) ? (int) $datos['id_solicitud'] : null,
                !empty($datos['id_formulario']) ? (int) $datos['id_formulario'] : null,
                $datos['codigo_muestra'] ?? null,
                (string) ($datos['tipo_evento'] ?? 'otro'),
                $datos['descripcion'] ?? null,
                !empty($datos['es_alerta']) ? 1 : 0,
                !empty($datos['usuario_id']) ? (int) $datos['usuario_id'] : null,
                $datos['usuario_nombre'] ?? null,
            ]);

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('lab_trazabilidad_obtener_lotes')) {
    function lab_trazabilidad_obtener_lotes(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT id_lote, codigo_lote FROM lote ORDER BY id_lote DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lab_historial_normalizar')) {
    function lab_historial_normalizar(string $valor): string
    {
        $valor = function_exists('mb_strtolower') ? mb_strtolower($valor, 'UTF-8') : strtolower($valor);
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
            if ($ascii !== false) {
                $valor = strtolower($ascii);
            }
        }
        return preg_replace('/[^a-z0-9]+/', '_', $valor) ?: '';
    }
}

if (!function_exists('lab_historial_snapshot_resultado')) {
    function lab_historial_snapshot_resultado(string $json, string $codigoMuestra, string $numeroMuestra): string
    {
        $datos = json_decode($json, true);
        if (!is_array($datos)) {
            return '-';
        }

        $clavesMuestra = ['numero_laboratorio', 'no_laboratorio', 'no_lab', 'numero_muestra', 'codigo_muestra', 'muestra'];
        $clavesIgnoradas = array_merge($clavesMuestra, [
            'id', 'id_formulario', 'id_solicitud', 'id_lote', 'id_muestra', 'id_encabezado',
            'fecha', 'fecha_creacion', 'created_at', 'updated_at', 'analista', 'usuario', 'observaciones', 'comentario',
        ]);
        $prioridades = [
            'resultado', 'resultado_final', 'valor_final', 'promedio', 'media', 'valor', 'ph', 'brix', 'pol',
            'porcentaje', 'conductividad', 'ce', 'densidad', 'cloruros', 'calcio', 'magnesio', 'sodio',
            'potasio', 'fosforo', 'nitrogeno', 'boro', 'ras', 'tds', 'salinidad',
        ];

        $candidatos = [];
        foreach (($datos['tablas'] ?? []) as $tabla) {
            foreach (($tabla['filas'] ?? []) as $fila) {
                if (!is_array($fila)) {
                    continue;
                }

                $identificadores = [];
                foreach ($fila as $campo => $valor) {
                    if (in_array(lab_historial_normalizar((string) $campo), $clavesMuestra, true)) {
                        $identificadores[] = trim((string) $valor);
                    }
                }
                $identificadores = array_filter($identificadores, static fn($valor) => $valor !== '');
                if ($identificadores
                    && !in_array($codigoMuestra, $identificadores, true)
                    && !in_array($numeroMuestra, $identificadores, true)) {
                    continue;
                }

                foreach ($fila as $campo => $valor) {
                    if (!is_scalar($valor) || trim((string) $valor) === '') {
                        continue;
                    }
                    $normalizado = lab_historial_normalizar((string) $campo);
                    if ($normalizado === '' || in_array($normalizado, $clavesIgnoradas, true) || strpos($normalizado, 'id_') === 0) {
                        continue;
                    }
                    $peso = 50;
                    foreach ($prioridades as $indice => $patron) {
                        if ($normalizado === $patron || strpos($normalizado, $patron) !== false) {
                            $peso = $indice;
                            break;
                        }
                    }
                    if ($peso < 50) {
                        $candidatos[] = ['peso' => $peso, 'valor' => trim((string) $valor)];
                    }
                }
            }
        }

        if (!$candidatos) {
            return '-';
        }
        usort($candidatos, static fn($a, $b) => $a['peso'] <=> $b['peso']);
        return (string) $candidatos[0]['valor'];
    }
}

if (!function_exists('lab_historial_estado_version')) {
    function lab_historial_estado_version(array $version, bool $esUltima): array
    {
        $tipo = lab_historial_normalizar((string) ($version['tipo_version'] ?? ''));
        $estadoActual = lab_historial_normalizar((string) ($version['estado_actual'] ?? ''));

        if ($tipo === 'con_errores' || ($esUltima && strpos($estadoActual, 'rechaz') !== false)) {
            return ['key' => 'rechazada', 'label' => 'RECHAZADA'];
        }
        if ($esUltima && strpos($estadoActual, 'aprob') !== false) {
            return ['key' => 'aprobada', 'label' => 'APROBADA'];
        }
        return ['key' => 'revision', 'label' => 'EN REVISIÓN'];
    }
}

if (!function_exists('lab_historial_versiones_muestras')) {
    /**
     * Historial para la pantalla del template: una entrada por combinación
     * muestra + análisis que tenga reanálisis, corrección o rechazo.
     */
    function lab_historial_versiones_muestras(PDO $pdo): array
    {
        if (!lab_trazabilidad_tabla_existe($pdo, 'formulario_version')) {
            return [];
        }

        $stmt = $pdo->query("
            SELECT
                v.id_version,
                v.id_formulario,
                v.version_numero,
                v.tipo_version,
                v.datos_json,
                v.usuario,
                v.fecha,
                v.comentario,
                f.analista,
                ef.nombre AS estado_actual,
                ta.nombre AS analisis_nombre,
                lr.inicio,
                lr.fin,
                l.id_lote,
                l.codigo_lote,
                tm.nombre AS tipo_muestra
            FROM formulario_version v
            INNER JOIN formulario f ON f.id_formulario = v.id_formulario
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
            LEFT JOIN lote_rango lr ON lr.id_rango = f.id_rango
            LEFT JOIN lote l ON l.id_lote = lr.id_lote
            LEFT JOIN solicitud s ON s.id_lote = l.id_lote
            LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
            ORDER BY v.id_formulario, v.version_numero, v.id_version
        ");
        $versiones = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if (!$versiones) {
            return [];
        }

        $muestrasPorLote = [];
        $muestrasStmt = $pdo->query("
            SELECT m.id_muestra, m.codigo_lab, m.numero_muestra, s.id_lote
            FROM muestra m
            INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
            ORDER BY s.id_lote, m.numero_muestra, m.id_muestra
        ");
        foreach (($muestrasStmt ? $muestrasStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $muestra) {
            $muestrasPorLote[(int) $muestra['id_lote']][] = $muestra;
        }

        $rechazos = [];
        if (lab_trazabilidad_tabla_existe($pdo, 'historial_formulario')) {
            $rechazosStmt = $pdo->query("
                SELECT id_formulario, comentario, fecha
                FROM historial_formulario
                WHERE LOWER(COALESCE(estado_nuevo, '')) LIKE '%rechaz%'
                ORDER BY fecha DESC, id_historial DESC
            ");
            foreach (($rechazosStmt ? $rechazosStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $rechazo) {
                $idFormulario = (int) $rechazo['id_formulario'];
                if (!isset($rechazos[$idFormulario])) {
                    $rechazos[$idFormulario] = $rechazo;
                }
            }
        }

        $porFormulario = [];
        foreach ($versiones as $version) {
            $porFormulario[(int) $version['id_formulario']][] = $version;
        }

        $pares = [];
        foreach ($porFormulario as $idFormulario => $versionesFormulario) {
            $primera = $versionesFormulario[0];
            $tieneRechazo = isset($rechazos[$idFormulario])
                || count(array_filter($versionesFormulario, static fn($version) => ($version['tipo_version'] ?? '') === 'con_errores')) > 0;
            if (count($versionesFormulario) < 2 && !$tieneRechazo) {
                continue;
            }

            $inicio = (int) ($primera['inicio'] ?? 0);
            $fin = (int) ($primera['fin'] ?? 0);
            $muestras = [];
            foreach (($muestrasPorLote[(int) ($primera['id_lote'] ?? 0)] ?? []) as $muestra) {
                $numero = (int) preg_replace('/\D+/', '', (string) ($muestra['numero_muestra'] ?? ''));
                if ($inicio > 0 && $fin > 0 && $numero > 0 && ($numero < $inicio || $numero > $fin)) {
                    continue;
                }
                $muestras[] = $muestra;
            }
            if (!$muestras) {
                $muestras[] = [
                    'id_muestra' => 0,
                    'codigo_lab' => $inicio === $fin ? (string) $inicio : trim($inicio . ' - ' . $fin),
                    'numero_muestra' => $inicio === $fin ? (string) $inicio : trim($inicio . ' - ' . $fin),
                ];
            }

            foreach ($muestras as $muestra) {
                $codigo = trim((string) ($muestra['codigo_lab'] ?? ''));
                $numero = trim((string) ($muestra['numero_muestra'] ?? ''));
                if ($codigo === '') {
                    $codigo = $numero !== '' ? $numero : 'Formulario #' . $idFormulario;
                }

                $timeline = [];
                $cantidad = count($versionesFormulario);
                foreach ($versionesFormulario as $indice => $version) {
                    $estado = lab_historial_estado_version($version, $indice === $cantidad - 1);
                    $motivo = null;
                    if ($estado['key'] === 'rechazada') {
                        $motivo = trim((string) ($version['comentario'] ?? ''));
                        if ($motivo === '' && isset($rechazos[$idFormulario])) {
                            $motivo = trim((string) ($rechazos[$idFormulario]['comentario'] ?? ''));
                        }
                    }
                    $timeline[] = [
                        'numero' => (int) $version['version_numero'],
                        'estado' => $estado,
                        'fecha' => $version['fecha'],
                        'usuario' => trim((string) ($version['usuario'] ?: $version['analista'] ?: 'Sistema')),
                        'valor' => lab_historial_snapshot_resultado((string) ($version['datos_json'] ?? ''), $codigo, $numero),
                        'motivo' => $motivo,
                        'comentario' => trim((string) ($version['comentario'] ?? '')),
                    ];
                }

                $pares[] = [
                    'key' => $idFormulario . '-' . (int) ($muestra['id_muestra'] ?? 0),
                    'id_formulario' => $idFormulario,
                    'muestra' => $codigo,
                    'lote' => (string) ($primera['codigo_lote'] ?? '-'),
                    'tipo_muestra' => (string) ($primera['tipo_muestra'] ?? '-'),
                    'analisis' => (string) ($primera['analisis_nombre'] ?? 'Análisis'),
                    'versiones' => $timeline,
                    'ultima_fecha' => (string) ($versionesFormulario[$cantidad - 1]['fecha'] ?? ''),
                ];
            }
        }

        usort($pares, static fn($a, $b) => strcmp((string) $b['ultima_fecha'], (string) $a['ultima_fecha']));
        return $pares;
    }
}

if (!function_exists('lab_trazabilidad_obtener_linea_tiempo')) {
    /**
     * Devuelve la linea de tiempo combinada (eventos genericos +
     * historial_formulario) de un lote, ordenada cronologicamente.
     */
    function lab_trazabilidad_obtener_linea_tiempo(PDO $pdo, int $idLote): array
    {
        $eventos = [];

        // 1) Eventos genericos de lote/solicitud (recepcion, boleta, entrega...).
        if (lab_trazabilidad_tabla_existe($pdo, 'lab_evento_trazabilidad')) {
            $stmt = $pdo->prepare("
                SELECT
                    et.fecha,
                    et.tipo_evento,
                    et.descripcion,
                    et.codigo_muestra,
                    et.es_alerta,
                    et.usuario_nombre,
                    NULL AS analisis_nombre
                FROM lab_evento_trazabilidad et
                WHERE et.id_lote = ?
                ORDER BY et.fecha ASC
            ");
            $stmt->execute([$idLote]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eventos[] = [
                    'fecha' => $row['fecha'],
                    'origen' => 'trazabilidad',
                    'titulo' => lab_trazabilidad_titulo_evento((string) $row['tipo_evento']),
                    'detalle' => $row['descripcion'] ?: ($row['codigo_muestra'] ? 'Muestra ' . $row['codigo_muestra'] : ''),
                    'usuario' => $row['usuario_nombre'],
                    'alerta' => (bool) $row['es_alerta'],
                ];
            }
        }

        // 2) Historial por analisis ya existente (formulario/historial_formulario),
        //    filtrado a los formularios que pertenecen a este lote via lote_rango.
        $stmt = $pdo->prepare("
            SELECT
                hf.fecha,
                hf.accion,
                hf.estado_anterior,
                hf.estado_nuevo,
                hf.usuario,
                hf.comentario,
                ta.nombre AS analisis_nombre
            FROM historial_formulario hf
            INNER JOIN formulario f ON f.id_formulario = hf.id_formulario
            INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
            LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
            WHERE lr.id_lote = ?
            ORDER BY hf.fecha ASC
        ");
        $stmt->execute([$idLote]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $detalle = trim((string) ($row['estado_anterior'] ?? '')) !== ''
                ? sprintf('%s -> %s', $row['estado_anterior'], $row['estado_nuevo'])
                : (string) ($row['estado_nuevo'] ?? '');
            if (!empty($row['comentario'])) {
                $detalle .= ($detalle !== '' ? ' — ' : '') . $row['comentario'];
            }

            $esRechazo = stripos((string) $row['estado_nuevo'], 'rechaz') !== false;

            $eventos[] = [
                'fecha' => $row['fecha'],
                'origen' => 'formulario',
                'titulo' => ($row['analisis_nombre'] ? $row['analisis_nombre'] . ' — ' : '') . (string) ($row['accion'] ?: 'Actualizacion'),
                'detalle' => $detalle,
                'usuario' => $row['usuario'],
                'alerta' => $esRechazo,
            ];
        }

        usort($eventos, static function ($a, $b) {
            return strcmp((string) $a['fecha'], (string) $b['fecha']);
        });

        return $eventos;
    }
}

if (!function_exists('lab_trazabilidad_actividad_reciente')) {
    /**
     * Actividad reciente en TODO el flujo (no filtrada por lote), usada
     * por el dashboard general. Misma combinacion de fuentes que
     * lab_trazabilidad_obtener_linea_tiempo() (lab_evento_trazabilidad +
     * historial_formulario), pero global y limitada a los N eventos mas
     * recientes.
     */
    function lab_trazabilidad_actividad_reciente(PDO $pdo, int $limite = 12): array
    {
        $eventos = [];

        if (lab_trazabilidad_tabla_existe($pdo, 'lab_evento_trazabilidad')) {
            $stmt = $pdo->prepare("
                SELECT
                    et.fecha,
                    et.tipo_evento,
                    et.descripcion,
                    et.codigo_muestra,
                    et.es_alerta,
                    et.usuario_nombre,
                    l.codigo_lote
                FROM lab_evento_trazabilidad et
                LEFT JOIN lote l ON l.id_lote = et.id_lote
                ORDER BY et.fecha DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limite, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eventos[] = [
                    'fecha' => $row['fecha'],
                    'titulo' => lab_trazabilidad_titulo_evento((string) $row['tipo_evento']),
                    'detalle' => trim((string) ($row['descripcion'] ?: ($row['codigo_muestra'] ? 'Muestra ' . $row['codigo_muestra'] : ''))),
                    'lote' => $row['codigo_lote'],
                    'usuario' => $row['usuario_nombre'],
                    'alerta' => (bool) $row['es_alerta'],
                ];
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                hf.fecha,
                hf.accion,
                hf.estado_anterior,
                hf.estado_nuevo,
                hf.usuario,
                hf.comentario,
                ta.nombre AS analisis_nombre,
                l.codigo_lote
            FROM historial_formulario hf
            INNER JOIN formulario f ON f.id_formulario = hf.id_formulario
            INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
            INNER JOIN lote l ON l.id_lote = lr.id_lote
            LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
            ORDER BY hf.fecha DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $detalle = trim((string) ($row['estado_anterior'] ?? '')) !== ''
                ? sprintf('%s -> %s', $row['estado_anterior'], $row['estado_nuevo'])
                : (string) ($row['estado_nuevo'] ?? '');

            $eventos[] = [
                'fecha' => $row['fecha'],
                'titulo' => ($row['analisis_nombre'] ? $row['analisis_nombre'] . ' — ' : '') . (string) ($row['accion'] ?: 'Actualizacion'),
                'detalle' => $detalle,
                'lote' => $row['codigo_lote'],
                'usuario' => $row['usuario'],
                'alerta' => stripos((string) $row['estado_nuevo'], 'rechaz') !== false,
            ];
        }

        usort($eventos, static function ($a, $b) {
            return strcmp((string) $b['fecha'], (string) $a['fecha']);
        });

        return array_slice($eventos, 0, $limite);
    }
}

if (!function_exists('lab_trazabilidad_titulo_evento')) {
    function lab_trazabilidad_titulo_evento(string $tipoEvento): string
    {
        $labels = [
            'recepcion' => 'Recepcion del lote',
            'boleta_generada' => 'Boleta de solicitud generada',
            'boleta_enviada' => 'Boleta enviada al cliente',
            'analisis_asignado' => 'Analisis asignado a analista',
            'analisis_en_proceso' => 'Analisis en proceso',
            'enviado_validacion' => 'Enviado a validacion tecnica',
            'aprobado' => 'Resultado aprobado',
            'rechazado' => 'Resultado rechazado',
            'informe_generado' => 'Informe generado',
            'informe_firmado' => 'Informe firmado',
            'entregado' => 'Entregado al cliente',
        ];

        return $labels[$tipoEvento] ?? ucfirst(str_replace('_', ' ', $tipoEvento));
    }
}
