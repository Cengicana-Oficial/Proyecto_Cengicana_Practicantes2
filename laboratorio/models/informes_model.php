<?php
/**
 * informes_model.php — Informes de resultados (formato de Prototipos/laboratorio-workflow-v3.html).
 *
 * Un informe por lote. Flujo:
 *   Validación técnica valida los análisis (formulario "Aprobado")
 *   → cuando TODOS los análisis del lote están validados, el informe queda "Listo para aprobación"
 *   → el jefe de laboratorio lo revisa, agrega observaciones y lo APRUEBA Y FIRMA en esta misma
 *     pantalla (la firma electrónica dejó de ser una sección aparte): se guarda el PDF en
 *     Documentos (lab_documento + lab_firma_documento)
 *   → o lo DEVUELVE al técnico con el motivo: los formularios del lote vuelven a Validación técnica.
 *
 * Los valores del informe se calculan en el navegador con las mismas fórmulas de
 * hojas_definiciones.js que usa Validación técnica; al firmar se envían como datos (no HTML)
 * y el documento se arma aquí, escapado, para el PDF.
 */

require_once __DIR__ . '/documentos_model.php';
require_once __DIR__ . '/kanban_model.php';
require_once __DIR__ . '/trazabilidad_model.php';
require_once __DIR__ . '/validacion_tecnica_model.php';
require_once __DIR__ . '/../includes/workflow_trabajo_helper.php';
require_once __DIR__ . '/informe_formato_model.php';

const LAB_INFORME_ESTADOS = [
    'PREP' => 'En preparación',
    'LISTO' => 'Listo para aprobación',
    'DEVUELTO' => 'Devuelto al técnico',
    'FIRMADO' => 'Aprobado y firmado',
];

if (!function_exists('lab_informes_esquema')) {
    function lab_informes_esquema(PDO $pdo): void
    {
        if (!defined('ESQUEMA_MIGRANDO')) {
            return; // DDL solo al desplegar (deploy/mysql/migrar-esquema.php, AUD-2026-001/I-2).
        }
        lab_documentos_esquema($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_informe (
            id_lote INT NOT NULL PRIMARY KEY, codigo VARCHAR(20) NOT NULL, estado VARCHAR(12) NOT NULL,
            version INT NOT NULL DEFAULT 1, observaciones TEXT DEFAULT NULL, id_documento INT DEFAULT NULL,
            firmado_por VARCHAR(255) DEFAULT NULL, fecha_firma DATETIME DEFAULT NULL, resultados_json LONGTEXT DEFAULT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lab_informe_codigo (codigo),
            CONSTRAINT fk_lab_informe_lote FOREIGN KEY (id_lote) REFERENCES lote (id_lote)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        // Valores firmados (para regenerar PDF y Excel tal como se aprobaron).
        $columna = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_informe' AND COLUMN_NAME = 'resultados_json'");
        if (!(int) $columna->fetchColumn()) {
            $pdo->exec("ALTER TABLE lab_informe ADD COLUMN resultados_json LONGTEXT DEFAULT NULL");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS lab_informe_historial (
            id_historial INT NOT NULL AUTO_INCREMENT PRIMARY KEY, id_lote INT NOT NULL, accion VARCHAR(60) NOT NULL,
            detalle TEXT DEFAULT NULL, usuario VARCHAR(255) DEFAULT NULL, fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_lab_informe_hist_lote (id_lote)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('lab_informe_historial_agregar')) {
    function lab_informe_historial_agregar(PDO $pdo, int $idLote, string $accion, string $detalle, string $usuario): void
    {
        $pdo->prepare('INSERT INTO lab_informe_historial (id_lote, accion, detalle, usuario) VALUES (?, ?, ?, ?)')
            ->execute([$idLote, $accion, $detalle !== '' ? $detalle : null, $usuario !== '' ? $usuario : 'Sistema']);
    }
}

if (!function_exists('lab_informes_lotes')) {
    /**
     * Lotes con su avance de validación y el estado del informe. Crea el informe (código
     * INF-AAAA-NNNN) en cuanto el lote queda completamente validado, como sincronizarInformes().
     */
    function lab_informes_lotes(PDO $pdo): array
    {
        $tareas = lab_planificacion_tareas($pdo);
        $planes = $pdo->query('SELECT id_lote, prioridad FROM lab_plan_lote')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $informes = [];
        foreach ($pdo->query('SELECT * FROM lab_informe')->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $informes[(int) $fila['id_lote']] = $fila;
        }

        $lotes = [];
        foreach ($tareas as $t) {
            $id = (int) $t['id_lote'];
            if (!isset($lotes[$id])) {
                $lotes[$id] = [
                    'id_lote' => $id, 'codigo_lote' => (string) $t['codigo_lote'], 'cliente' => (string) $t['cliente'],
                    'tipo_muestra' => (string) $t['tipo_muestra'], 'fecha_estimada' => $t['fecha_estimada'],
                    'prioridad' => $planes[$id] ?? 'media', 'muestras' => [], 'total' => 0, 'validados' => 0,
                    'en_validacion' => 0, 'pendientes' => [],
                ];
            }
            $l = &$lotes[$id];
            $l['muestras'][(int) $t['id_muestra']] = true;
            $l['total']++;
            $clave = $t['estado']['key'];
            if ($clave === 'validado') {
                $l['validados']++;
            } else {
                if ($clave === 'validacion') $l['en_validacion']++;
                $l['pendientes'][$t['analisis_nombre']] = ($l['pendientes'][$t['analisis_nombre']] ?? 0) + 1;
            }
            unset($l);
        }

        foreach ($lotes as $id => &$l) {
            $inf = $informes[$id] ?? null;
            $listo = $l['total'] > 0 && $l['validados'] === $l['total'];
            if ($inf && $inf['estado'] === 'FIRMADO') {
                $estado = 'FIRMADO';
            } elseif ($listo) {
                $estado = 'LISTO';
                if (!$inf || $inf['estado'] !== 'LISTO') {
                    $inf = lab_informe_marcar_listo($pdo, $id, $inf);
                }
            } elseif ($inf && $inf['estado'] === 'DEVUELTO') {
                $estado = 'DEVUELTO';
            } else {
                $estado = 'PREP';
            }
            $l['numero_muestras'] = count($l['muestras']);
            unset($l['muestras']);
            $l['estado'] = $estado;
            $l['estado_texto'] = LAB_INFORME_ESTADOS[$estado];
            $l['informe'] = $inf;
            $l['porcentaje'] = $l['total'] ? (int) round($l['validados'] / $l['total'] * 100) : 0;
        }
        unset($l);

        $orden = ['LISTO' => 0, 'DEVUELTO' => 1, 'PREP' => 2, 'FIRMADO' => 3];
        $prioridad = ['alta' => 0, 'media' => 1, 'baja' => 2];
        uasort($lotes, static function ($a, $b) use ($orden, $prioridad) {
            return $orden[$a['estado']] <=> $orden[$b['estado']]
                ?: ($prioridad[$a['prioridad']] ?? 1) <=> ($prioridad[$b['prioridad']] ?? 1)
                ?: $b['id_lote'] <=> $a['id_lote']; // más recientes primero (paginación de 25)
        });

        return array_values($lotes);
    }
}

if (!function_exists('lab_informe_marcar_listo')) {
    function lab_informe_marcar_listo(PDO $pdo, int $idLote, ?array $informe): array
    {
        if ($informe) {
            $pdo->prepare("UPDATE lab_informe SET estado = 'LISTO', version = version + 1 WHERE id_lote = ?")->execute([$idLote]);
        } else {
            $anio = date('Y');
            $ultimo = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(codigo, '-', -1) AS UNSIGNED)) FROM lab_informe WHERE codigo LIKE ?");
            $ultimo->execute(['INF-' . $anio . '-%']);
            $codigo = 'INF-' . $anio . '-' . str_pad((string) ((int) $ultimo->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT IGNORE INTO lab_informe (id_lote, codigo, estado) VALUES (?, ?, 'LISTO')")->execute([$idLote, $codigo]);
        }
        lab_informe_historial_agregar($pdo, $idLote, 'Listo para aprobación', 'Todos los análisis del lote quedaron validados por el técnico.', 'Sistema');
        $stmt = $pdo->prepare('SELECT * FROM lab_informe WHERE id_lote = ?');
        $stmt->execute([$idLote]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('lab_informe_detalle')) {
    /**
     * Datos para el informe de un lote: encabezado (solicitud de Recepción), muestras con su
     * etiqueta de Identificación, análisis solicitados (con el nombre de su hoja de trabajo) y, por
     * muestra × análisis, el formulario cuyo snapshot contiene la lectura y su estado.
     */
    function lab_informe_detalle(PDO $pdo, int $idLote): ?array
    {
        $lote = null;
        foreach (lab_informes_lotes($pdo) as $fila) {
            if ((int) $fila['id_lote'] === $idLote) {
                $lote = $fila;
                break;
            }
        }
        if (!$lote) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT s.id_solicitud, s.institucion, s.responsable_envio, s.codigo_muestreo, s.fecha_muestreo,
                   s.fecha_ingreso, s.fecha_estimada, s.correo_ingresado, s.correo_recibido, tm.nombre AS tipo_muestra
            FROM solicitud s LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
            WHERE s.id_lote = ? ORDER BY s.id_solicitud
        ");
        $stmt->execute([$idLote]);
        $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Etiquetas completas de Identificación de muestra (campos tabulados o lectura QR / código).
        $etiquetas = [];
        try {
            require_once __DIR__ . '/../includes/identificacion_lote_helper.php';
            foreach ($solicitudes as $s) {
                foreach (labIdentificacionLotes($pdo, (int) $s['id_solicitud']) as $identificado) {
                    foreach ($identificado['muestras'] as $m) {
                        $etiquetas[(int) $m['id_muestra']] = $m['estado'] === 'pendiente' ? [] : (array) $m['etiqueta'];
                    }
                }
            }
        } catch (PDOException $e) {
            // Sin tablas de identificación: el informe sale sin la etiqueta de cada muestra.
        }

        $muestras = [];
        $analisis = [];
        $celdas = [];
        $celdasHoja = [];
        $formularios = [];
        foreach (wfTareas($pdo) as $t) {
            if ((int) $t['id_lote'] !== $idLote) continue;
            $idM = (int) $t['id_muestra'];
            $idA = (int) $t['id_tipo_analisis'];
            $hojaAnalisis = (string) ($t['analisis_hoja'] ?: $t['analisis_nombre']);
            $muestras[$idM] = ['id_muestra' => $idM, 'codigo' => (string) $t['codigo_muestra'], 'numero' => (int) $t['numero_muestra'], 'etiqueta_completa' => $etiquetas[$idM] ?? []];
            $analisis[$idA] = ['id_tipo_analisis' => $idA, 'nombre' => (string) $t['analisis_nombre'], 'analisis_hoja' => $hojaAnalisis, 'hoja' => $t['hoja']];
            $celda = ['estado' => $t['estado']['key'], 'id_formulario' => $t['id_formulario'] ? (int) $t['id_formulario'] : null];
            $celdas[$idM . ':' . $idA] = $celda;
            $celdasHoja[$idM . '|' . $hojaAnalisis] = $celda;
            if ($t['id_formulario']) $formularios[(int) $t['id_formulario']] = true;
        }
        uasort($muestras, static fn($a, $b) => $a['numero'] <=> $b['numero']);

        if ($analisis) {
            labCatalogoAnalisisAsegurarEsquema($pdo); // unidad y método del catálogo de análisis
            $ids = array_keys($analisis);
            $stmt = $pdo->prepare('SELECT id_tipo, unidad, metodo FROM tipo_analisis WHERE id_tipo IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $analisis[(int) $fila['id_tipo']]['unidad'] = (string) ($fila['unidad'] ?? '');
                $analisis[(int) $fila['id_tipo']]['metodo'] = (string) ($fila['metodo'] ?? '');
            }
        }

        // "Revisado por": técnicos que validaron los formularios del lote en Validación técnica.
        $revisores = [];
        if ($formularios) {
            $ids = array_keys($formularios);
            $stmt = $pdo->prepare("SELECT DISTINCT usuario FROM historial_formulario WHERE accion = 'validacion_tecnica' AND LOWER(estado_nuevo) LIKE '%aprob%' AND usuario IS NOT NULL AND id_formulario IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $revisores = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        $historial = $pdo->prepare('SELECT accion, detalle, usuario, fecha FROM lab_informe_historial WHERE id_lote = ? ORDER BY fecha, id_historial');
        $historial->execute([$idLote]);

        return [
            'lote' => $lote,
            'solicitudes' => $solicitudes,
            'muestras' => array_values($muestras),
            'analisis' => array_values($analisis),
            'celdas' => $celdas,
            'celdas_hoja' => $celdasHoja,
            'revisores' => $revisores,
            'snapshots' => lab_validacion_snapshots($pdo, array_keys($formularios)),
            'historial' => $historial->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}

if (!function_exists('lab_informe_valores_limpios')) {
    /** Solo se aceptan valores de columnas del formato y muestras del lote, como texto corto. */
    function lab_informe_valores_limpios(array $est, array $valores): array
    {
        $claves = array_flip(array_column($est['columnas'], 'key'));
        $limpios = [];
        foreach ($est['muestras'] as $m) {
            foreach ((array) ($valores[$m['id_muestra']] ?? []) as $clave => $valor) {
                if (isset($claves[$clave]) && is_scalar($valor)) {
                    $limpios[(int) $m['id_muestra']][$clave] = mb_substr(trim((string) $valor), 0, 80);
                }
            }
        }
        return $limpios;
    }
}

if (!function_exists('lab_informe_meta')) {
    /** Datos de firma y revisión para el renderizado (firmado o borrador). */
    function lab_informe_meta(PDO $pdo, array $detalle, bool $preview): array
    {
        $inf = $detalle['lote']['informe'] ?? null;
        $firma = null;
        if (!$preview && !empty($inf['id_documento'])) {
            $stmt = $pdo->prepare('SELECT firma_png, firmante_nombre, DATE_FORMAT(fecha_firma, "%d/%m/%Y, %H:%i") AS fh FROM lab_firma_documento WHERE id_documento = ? ORDER BY id_firma DESC LIMIT 1');
            $stmt->execute([(int) $inf['id_documento']]);
            $firma = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return [
            'codigo' => $inf['codigo'] ?? '',
            'observaciones' => (string) ($inf['observaciones'] ?? ''),
            'revisores' => $detalle['revisores'],
            'firmante' => $firma['firmante_nombre'] ?? ($inf['firmado_por'] ?? ''),
            'firma_png' => $firma['firma_png'] ?? '',
            'fecha_firma' => $inf['fecha_firma'] ?? null,
            'fecha_firma_txt' => $firma['fh'] ?? '',
            'preview' => $preview,
        ];
    }
}

if (!function_exists('lab_informe_firmar')) {
    /**
     * Aprueba y firma el informe de un lote listo: guarda el PDF (lab_documento) y la firma
     * (lab_firma_documento) con el formato del tipo de muestra, y conserva los valores firmados
     * (lab_informe.resultados_json) para regenerar el PDF y el Excel tal cual.
     * $resultados = [id_muestra => [clave de columna => 'valor mostrado']].
     */
    function lab_informe_firmar(PDO $pdo, int $idLote, string $observaciones, string $firmaPng, array $resultados, array $usuario): array
    {
        require_once __DIR__ . '/firma_electronica_model.php';
        $firmaPng = lab_firma_normalizar_png($firmaPng);
        $observaciones = mb_substr(trim($observaciones), 0, 2000);
        $firmante = trim((string) ($usuario['nombre'] ?? '')) ?: 'Jefe de laboratorio';

        $pdo->beginTransaction();
        try {
            $bloqueo = $pdo->prepare('SELECT estado FROM lab_informe WHERE id_lote = ? FOR UPDATE');
            $bloqueo->execute([$idLote]);
            $detalle = lab_informe_detalle($pdo, $idLote);
            if (!$detalle || $detalle['lote']['estado'] !== 'LISTO') {
                throw new RuntimeException('El informe no está listo para aprobación: todos los análisis del lote deben estar validados.');
            }

            $est = lab_informe_estructura($detalle);
            $limpios = lab_informe_valores_limpios($est, $resultados);

            // Hora de la base de datos: la misma de fecha_firma e historial (PHP no tiene zona horaria fija).
            $fecha = (string) $pdo->query("SELECT DATE_FORMAT(NOW(), '%d/%m/%Y, %H:%i')")->fetchColumn();
            $inf = $detalle['lote']['informe'];
            $html = lab_informe_reporte_html($est, $detalle, $limpios, [
                'codigo' => $inf['codigo'], 'observaciones' => $observaciones, 'revisores' => $detalle['revisores'],
                'firmante' => $firmante, 'firma_png' => $firmaPng, 'fecha_firma' => date('Y-m-d'), 'fecha_firma_txt' => $fecha, 'preview' => false,
            ]);
            $titulo = 'Informe ' . $inf['codigo'] . ' · Lote ' . $detalle['lote']['codigo_lote'];
            $idSolicitud = (int) ($detalle['solicitudes'][0]['id_solicitud'] ?? 0) ?: null;

            $existente = $pdo->prepare("SELECT id_documento, version FROM lab_documento WHERE tipo_documento = 'informe' AND id_lote = ? ORDER BY id_documento DESC LIMIT 1");
            $existente->execute([$idLote]);
            $documento = $existente->fetch(PDO::FETCH_ASSOC);
            if ($documento) {
                $idDocumento = (int) $documento['id_documento'];
                $version = (int) $documento['version'] + 1;
                $pdo->prepare('UPDATE lab_documento SET version = ?, titulo = ?, contenido_html = ?, vigente = 1, generado_por = ?, generado_en = NOW() WHERE id_documento = ?')
                    ->execute([$version, $titulo, $html, $firmante, $idDocumento]);
                $pdo->prepare('INSERT INTO lab_documento_historial (id_documento, version, cambios, usuario) VALUES (?, ?, ?, ?)')
                    ->execute([$idDocumento, $version, 'Informe aprobado y firmado nuevamente tras devolución.', $firmante]);
            } else {
                $pdo->prepare("INSERT INTO lab_documento (tipo_documento, id_lote, id_solicitud, version, titulo, contenido_html, generado_por) VALUES ('informe', ?, ?, 1, ?, ?, ?)")
                    ->execute([$idLote, $idSolicitud, $titulo, $html, $firmante]);
                $idDocumento = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO lab_firma_documento (id_documento, rol_firma, firmante_id, firmante_nombre, firmante_correo, firma_png) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$idDocumento, 'jefe_laboratorio', !empty($usuario['id']) ? (int) $usuario['id'] : null, $firmante, trim((string) ($usuario['correo'] ?? '')) ?: null, $firmaPng]);
            $pdo->prepare("UPDATE lab_informe SET estado = 'FIRMADO', observaciones = ?, id_documento = ?, firmado_por = ?, fecha_firma = NOW(), resultados_json = ? WHERE id_lote = ?")
                ->execute([$observaciones !== '' ? $observaciones : null, $idDocumento, $firmante, json_encode($limpios, JSON_UNESCAPED_UNICODE), $idLote]);
            lab_informe_historial_agregar($pdo, $idLote, 'Aprobado y firmado', $observaciones !== '' ? 'Observaciones: ' . $observaciones : '', $firmante);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        lab_trazabilidad_registrar_evento($pdo, [
            'id_lote' => $idLote, 'tipo_evento' => 'aprobado',
            'descripcion' => 'Informe ' . $inf['codigo'] . ' aprobado y firmado por el jefe de laboratorio. PDF guardado en Reportes.',
            'usuario_id' => $usuario['id'] ?? null, 'usuario_nombre' => $firmante,
        ]);

        return ['codigo' => $inf['codigo'], 'id_documento' => $idDocumento];
    }
}

if (!function_exists('lab_informe_devolver')) {
    /** Devuelve un informe listo al técnico: sus formularios validados vuelven a Validación técnica. */
    function lab_informe_devolver(PDO $pdo, int $idLote, string $motivo, array $usuario): array
    {
        $motivo = mb_substr(trim($motivo), 0, 1000);
        if (mb_strlen($motivo) < 5) {
            throw new InvalidArgumentException('Escriba el motivo de la devolución.');
        }
        $quien = trim((string) ($usuario['nombre'] ?? '')) ?: 'Jefe de laboratorio';

        $pdo->beginTransaction();
        try {
            $bloqueo = $pdo->prepare('SELECT codigo, estado FROM lab_informe WHERE id_lote = ? FOR UPDATE');
            $bloqueo->execute([$idLote]);
            $inf = $bloqueo->fetch(PDO::FETCH_ASSOC);
            if (!$inf || $inf['estado'] !== 'LISTO') {
                throw new RuntimeException('Solo se puede devolver un informe listo para aprobación.');
            }
            $formularios = $pdo->prepare("
                SELECT f.id_formulario, f.id_rango, f.id_tipo_analisis, ef.nombre AS estado
                FROM formulario f
                JOIN lote_rango lr ON lr.id_rango = f.id_rango
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                WHERE lr.id_lote = ? AND LOWER(COALESCE(ef.nombre, '')) LIKE '%aprob%'
            ");
            $formularios->execute([$idLote]);
            $lista = $formularios->fetchAll(PDO::FETCH_ASSOC);
            $idRevisar = lab_validacion_id_estado($pdo, 'Revisar');
            $nota = 'Informe ' . $inf['codigo'] . ' devuelto por el jefe de laboratorio: ' . $motivo;
            foreach ($lista as $f) {
                $pdo->prepare('UPDATE formulario SET id_estado = ? WHERE id_formulario = ?')->execute([$idRevisar, $f['id_formulario']]);
                if (!empty($f['id_tipo_analisis'])) {
                    $pdo->prepare('UPDATE lote_analisis SET estado = ? WHERE id_rango = ? AND id_tipo_analisis = ?')->execute(['Revisar', $f['id_rango'], $f['id_tipo_analisis']]);
                }
                $pdo->prepare("INSERT INTO historial_formulario (id_formulario, accion, estado_anterior, estado_nuevo, usuario, fecha, comentario) VALUES (?, 'devuelto_informe', ?, 'Revisar', ?, NOW(), ?)")
                    ->execute([$f['id_formulario'], $f['estado'], $quien, $nota]);
            }
            $pdo->prepare("UPDATE lab_informe SET estado = 'DEVUELTO' WHERE id_lote = ?")->execute([$idLote]);
            lab_informe_historial_agregar($pdo, $idLote, 'Devuelto al técnico', $motivo, $quien);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        lab_trazabilidad_registrar_evento($pdo, [
            'id_lote' => $idLote, 'tipo_evento' => 'rechazado', 'es_alerta' => true,
            'descripcion' => $nota, 'usuario_id' => $usuario['id'] ?? null, 'usuario_nombre' => $quien,
        ]);

        return ['codigo' => $inf['codigo'], 'formularios' => count($lista)];
    }
}
