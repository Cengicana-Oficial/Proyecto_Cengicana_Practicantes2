<?php
/**
 * validacion_tecnica_model.php
 *
 * Validacion tecnica: una linea por formulario capturado (formato del
 * prototipo laboratorio-workflow-v3). No crea tabla nueva: reutiliza
 * `formulario` + `estado_formulario` (igual que kanban_model.php) y considera
 * "pendiente de validar" cualquier formulario cuyo estado contenga "revis"
 * (cubre tanto el estado "En revision" sembrado por
 * database/002_lab_flujo_generico.sql para el tablero Kanban, como el
 * estado legado "Revisar" usado por includes/formulario_revision_helper.php).
 *
 * Aprobar/rechazar sigue el mismo patron transaccional que
 * lab_kanban_mover_tarjeta() de models/kanban_model.php: UPDATE
 * formulario.id_estado + INSERT historial_formulario + evento en
 * lab_evento_trazabilidad. Al validar se pueden devolver muestras puntuales
 * a reanalisis: cada una recibe un formulario propio en estado "Rechazado",
 * lo que la regresa a la bandeja del analista (el estado vigente de una
 * muestra es el del formulario mas reciente que la cubre).
 */

require_once __DIR__ . '/trazabilidad_model.php';
require_once __DIR__ . '/../includes/catalogo_analisis_helper.php';

/* Categorías de rechazo/reanálisis (prototipo, "Motivos de rechazo"); Control de calidad las agrupa.
   Se guardan al inicio del comentario del historial: "[Fuera de rango] detalle…". */
const LAB_MOTIVOS_RECHAZO = ['Fuera de rango', 'Error de digitación', 'Duplicado inconsistente', 'Falla de equipo', 'Control fuera de lo esperado', 'Otro'];

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

if (!function_exists('lab_validacion_lineas')) {
    /**
     * Formularios para la vista de validacion tecnica.
     * $modo: 'revision' (pendientes de validar) o 'validado' (aprobados mas
     * recientes, de solo lectura). Incluye el rango esperado del catalogo
     * (limite_min/limite_max) para marcar resultados fuera de rango.
     *
     * Algunos formularios heredados se guardaron sin id_tipo_analisis. En
     * esos casos se recupera el analisis asociado al rango en lote_analisis;
     * de otro modo la linea quedaba invisible y no mostraba sus botones.
     */
    function lab_validacion_lineas(PDO $pdo, string $modo = 'revision', ?int $idLote = null, ?string $tipoMuestra = null, int $limite = 0): array
    {
        labCatalogoAnalisisAsegurarEsquema($pdo);

        $sql = "
            SELECT
                f.id_formulario,
                f.analista,
                f.fecha,
                f.id_estado,
                ef.nombre AS estado_nombre,
                COALESCE(f.id_tipo_analisis, la_fallback.id_tipo_analisis) AS id_tipo_analisis,
                COALESCE(ta.nombre, 'Analisis sin identificar') AS analisis_nombre,
                ta.unidad,
                ta.limite_min,
                ta.limite_max,
                COALESCE(tm.nombre, 'Sin tipo') AS tipo_muestra,
                l.id_lote,
                l.codigo_lote,
                lr.id_rango,
                lr.inicio,
                lr.fin,
                (SELECT COALESCE(NULLIF(TRIM(mi.codigo_lab), ''), mi.numero_muestra)
                   FROM muestra mi INNER JOIN solicitud si ON si.id_solicitud = mi.id_solicitud
                  WHERE si.id_lote = l.id_lote AND mi.numero_muestra = lr.inicio LIMIT 1) AS codigo_inicio,
                (SELECT COALESCE(NULLIF(TRIM(mf.codigo_lab), ''), mf.numero_muestra)
                   FROM muestra mf INNER JOIN solicitud sf ON sf.id_solicitud = mf.id_solicitud
                  WHERE sf.id_lote = l.id_lote AND mf.numero_muestra = lr.fin LIMIT 1) AS codigo_fin
            FROM formulario f
            INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
            INNER JOIN lote l ON l.id_lote = lr.id_lote
            LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
            LEFT JOIN lote_analisis la_fallback
                   ON la_fallback.id = (
                        SELECT la2.id
                        FROM lote_analisis la2
                        WHERE la2.id_rango = f.id_rango
                          AND (
                              f.id_tipo_analisis IS NULL
                              OR la2.id_tipo_analisis = f.id_tipo_analisis
                          )
                        ORDER BY
                            CASE
                                WHEN LOWER(TRIM(COALESCE(la2.estado, ''))) IN ('pendiente', 'en revision', 'revisar') THEN 0
                                ELSE 1
                            END,
                            la2.id DESC
                        LIMIT 1
                   )
            LEFT JOIN tipo_analisis ta
                   ON ta.id_tipo = COALESCE(f.id_tipo_analisis, la_fallback.id_tipo_analisis)
            LEFT JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
            WHERE LOWER(TRIM(COALESCE(ef.nombre, ''))) LIKE ?
        ";
        $params = [$modo === 'validado' ? '%aprob%' : '%revis%'];
        if ($idLote) {
            $sql .= ' AND l.id_lote = ?';
            $params[] = $idLote;
        }
        if ($tipoMuestra !== null && $tipoMuestra !== '') {
            $sql .= " AND COALESCE(tm.nombre, 'Sin tipo') = ?";
            $params[] = $tipoMuestra;
        }
        $sql .= $modo === 'validado'
            ? ' ORDER BY f.id_formulario DESC'
            : ' ORDER BY l.codigo_lote ASC, f.fecha ASC, f.id_formulario ASC';
        if ($limite > 0) {
            $sql .= ' LIMIT ' . (int) $limite;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lab_validacion_snapshots')) {
    /** Hojas de trabajo (formato del prototipo) capturadas para los formularios dados. */
    function lab_validacion_snapshots(PDO $pdo, array $idsFormulario): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsFormulario))));
        if (!$ids) {
            return [];
        }
        try {
            $stmt = $pdo->prepare('SELECT id_formulario, datos FROM lab_hoja_formulario WHERE id_formulario IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                return [];
            }
            throw $e;
        }
        $snapshots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $datos = json_decode((string) $row['datos'], true);
            if (is_array($datos)) {
                $snapshots[(int) $row['id_formulario']] = $datos;
            }
        }

        return $snapshots;
    }
}

if (!function_exists('lab_validacion_id_estado')) {
    function lab_validacion_id_estado(PDO $pdo, string $nombre): int
    {
        $stmt = $pdo->prepare('SELECT id_estado FROM estado_formulario WHERE LOWER(nombre) = LOWER(?) LIMIT 1');
        $stmt->execute([$nombre]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT INTO estado_formulario (nombre) VALUES (?)')->execute([$nombre]);
            $id = $pdo->lastInsertId();
        }

        return (int) $id;
    }
}

if (!function_exists('lab_validacion_resolver')) {
    /**
     * Aprueba o rechaza un formulario en validacion tecnica. $accion debe ser
     * 'aprobar' o 'rechazar'. Con 'aprobar', $reanalizar lista los id_muestra
     * de la hoja que se devuelven a reanalisis; el resto queda aprobado.
     */
    function lab_validacion_resolver(PDO $pdo, int $idFormulario, string $accion, string $usuario, ?int $usuarioId = null, string $comentario = '', array $reanalizar = [], string $categoria = ''): array
    {
        if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
            throw new InvalidArgumentException('Accion no valida.');
        }

        if ($accion === 'rechazar' && trim($comentario) === '') {
            throw new InvalidArgumentException('Debe indicar el motivo del rechazo.');
        }
        if ($accion === 'rechazar' || ($accion === 'aprobar' && $reanalizar)) {
            if (!in_array($categoria, LAB_MOTIVOS_RECHAZO, true)) {
                throw new InvalidArgumentException('Seleccione la categoría del motivo (rechazo o reanálisis).');
            }
            $comentario = '[' . $categoria . '] ' . trim($comentario);
        }

        $stmt = $pdo->prepare("
            SELECT f.id_formulario, f.id_estado, f.id_rango, f.id_tipo_analisis, f.fecha, f.analista,
                   ef.nombre AS estado_actual, lr.id_lote
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

        if (!lab_validacion_estado_es_revision($formulario['estado_actual'] ?? '')) {
            throw new RuntimeException('El analisis ya no esta pendiente de validacion tecnica.');
        }

        $reanalizar = $accion === 'aprobar' ? array_values(array_unique(array_map('intval', $reanalizar))) : [];
        $snapshot = null;
        $devueltas = [];
        if ($reanalizar) {
            $snapshot = lab_validacion_snapshots($pdo, [$idFormulario])[$idFormulario] ?? null;
            if (!$snapshot || empty($formulario['id_tipo_analisis'])) {
                throw new InvalidArgumentException('Este formulario no permite devolver muestras individuales; recházalo completo.');
            }
            $seleccion = array_map('intval', $snapshot['seleccion'] ?? []);
            if (array_diff($reanalizar, $seleccion)) {
                throw new InvalidArgumentException('Hay muestras marcadas que no pertenecen al formulario.');
            }
            if (!array_diff($seleccion, $reanalizar)) {
                throw new InvalidArgumentException('Marcaste todas las muestras: usa "Rechazar formulario completo".');
            }
            foreach ($snapshot['muestras'] ?? [] as $muestra) {
                if (in_array((int) $muestra['id_muestra'], $reanalizar, true)) {
                    $devueltas[(int) $muestra['id_muestra']] = $muestra;
                }
            }
            if (count($devueltas) !== count($reanalizar)) {
                throw new InvalidArgumentException('No se encontraron los datos de las muestras marcadas.');
            }
        }

        $nuevoEstadoNombre = $accion === 'aprobar' ? 'Aprobado' : 'Rechazado';
        $comentarioHistorial = $comentario !== ''
            ? $comentario
            : ($accion === 'aprobar' ? 'Aprobado en validacion tecnica.' : 'Rechazado en validacion tecnica.');
        $motivoReanalisis = $comentario !== '' ? $comentario : 'Reanalisis solicitado en validacion tecnica.';
        $formulariosReanalisis = [];

        $pdo->beginTransaction();
        try {
            $idNuevoEstado = lab_validacion_id_estado($pdo, $nuevoEstadoNombre);

            $update = $pdo->prepare('UPDATE formulario SET id_estado = ? WHERE id_formulario = ?');
            $update->execute([$idNuevoEstado, $idFormulario]);

            if (!empty($formulario['id_tipo_analisis'])) {
                $updateLoteAnalisis = $pdo->prepare('
                    UPDATE lote_analisis
                    SET estado = ?
                    WHERE id_rango = ? AND id_tipo_analisis = ?
                ');
                $updateLoteAnalisis->execute([
                    $nuevoEstadoNombre,
                    (int) $formulario['id_rango'],
                    (int) $formulario['id_tipo_analisis'],
                ]);
            }

            $hist = $pdo->prepare("
                INSERT INTO historial_formulario (id_formulario, accion, estado_anterior, estado_nuevo, usuario, fecha, comentario)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $hist->execute([
                $idFormulario,
                'validacion_tecnica',
                $formulario['estado_actual'] ?? null,
                $nuevoEstadoNombre,
                $usuario !== '' ? $usuario : null,
                $comentarioHistorial . ($devueltas ? ' (' . count($devueltas) . ' muestra(s) devuelta(s) a reanalisis)' : ''),
            ]);

            if ($devueltas) {
                // Cada muestra devuelta recibe un formulario "Rechazado" de un solo
                // numero: al ser el mas reciente que la cubre, vuelve a la bandeja.
                $idRechazado = lab_validacion_id_estado($pdo, 'Rechazado');
                $buscarRango = $pdo->prepare('SELECT id_rango FROM lote_rango WHERE id_lote = ? AND inicio = ? AND fin = ? LIMIT 1');
                $crearRango = $pdo->prepare('INSERT INTO lote_rango (id_lote, inicio, fin) VALUES (?, ?, ?)');
                $crearFormulario = $pdo->prepare('INSERT INTO formulario (id_estado, id_rango, id_tipo_analisis, fecha, analista) VALUES (?, ?, ?, ?, ?)');
                $guardarHoja = $pdo->prepare('INSERT INTO lab_hoja_formulario VALUES (?, ?, ?)');
                foreach ($devueltas as $idMuestra => $muestra) {
                    $numero = (int) $muestra['numero_muestra'];
                    $buscarRango->execute([(int) $formulario['id_lote'], $numero, $numero]);
                    $idRango = $buscarRango->fetchColumn();
                    if (!$idRango) {
                        $crearRango->execute([(int) $formulario['id_lote'], $numero, $numero]);
                        $idRango = $pdo->lastInsertId();
                    }
                    $crearFormulario->execute([$idRechazado, (int) $idRango, (int) $formulario['id_tipo_analisis'], $formulario['fecha'], $formulario['analista']]);
                    $idNuevo = (int) $pdo->lastInsertId();
                    $formulariosReanalisis[] = $idNuevo;

                    $hojaMuestra = $snapshot;
                    $hojaMuestra['seleccion'] = [$idMuestra];
                    $hojaMuestra['filas'] = [$idMuestra => $snapshot['filas'][$idMuestra] ?? []];
                    $hojaMuestra['muestras'] = [$muestra];
                    $guardarHoja->execute([$idNuevo, (string) ($snapshot['hoja'] ?? ''), json_encode($hojaMuestra, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                    $hist->execute([$idNuevo, 'reanalisis', 'Revisar', 'Rechazado', $usuario !== '' ? $usuario : null, $motivoReanalisis . ' (separada del formulario #' . $idFormulario . ')']);
                }

                // El formulario aprobado conserva solo las muestras validadas.
                $snapshot['seleccion'] = array_values(array_diff(array_map('intval', $snapshot['seleccion']), $reanalizar));
                $snapshot['filas'] = array_diff_key($snapshot['filas'] ?? [], array_flip($reanalizar));
                $snapshot['muestras'] = array_values(array_filter($snapshot['muestras'] ?? [], static function ($m) use ($reanalizar) {
                    return !in_array((int) $m['id_muestra'], $reanalizar, true);
                }));
                $pdo->prepare('UPDATE lab_hoja_formulario SET datos = ? WHERE id_formulario = ?')
                    ->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $idFormulario]);
            }

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
            ) . ($devueltas ? ' ' . count($devueltas) . ' muestra(s) devuelta(s) a reanalisis.' : '')
              . ($comentario !== '' ? ' Motivo: ' . $comentario : ''),
            'es_alerta' => $accion === 'rechazar' || (bool) $devueltas,
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuario,
        ]);

        return [
            'id_formulario' => $idFormulario,
            'estado_anterior' => $formulario['estado_actual'],
            'estado_nuevo' => $nuevoEstadoNombre,
            'reanalisis' => $formulariosReanalisis,
        ];
    }
}
