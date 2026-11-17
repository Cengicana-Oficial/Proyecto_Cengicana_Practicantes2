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
require_once __DIR__ . '/../includes/suelos_fisicos_helper.php';
require_once __DIR__ . '/../includes/textura_revision_helper.php';
require_once __DIR__ . '/../includes/parametros_validacion_helper.php';

function lab_validacion_corregir_texturas(PDO $pdo,array $ids,array $cambios,string $usuario): void {
    $leer=$pdo->prepare('SELECT datos FROM lab_hoja_formulario WHERE id_formulario=? FOR UPDATE');
    $guardar=$pdo->prepare('UPDATE lab_hoja_formulario SET datos=? WHERE id_formulario=?');
    $hist=$pdo->prepare("INSERT INTO historial_formulario(id_formulario,accion,estado_anterior,estado_nuevo,usuario,fecha,comentario) VALUES(?,'correccion_textura','Revisar','Revisar',?,NOW(),?)");
    foreach($cambios as $fid=>$filas){
        if(!ctype_digit((string)$fid)||!in_array((int)$fid,$ids,true)||!is_array($filas))throw new InvalidArgumentException('El formulario de la corrección no pertenece a la hoja.');
        $leer->execute([(int)$fid]);$raw=$leer->fetchColumn();
        if(!$raw)throw new InvalidArgumentException('No se encontró la captura de Textura.');
        $cap=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $revision=labTexturaAplicarRevision($cap,$filas,$usuario,date('Y-m-d H:i:s'));
        $guardar->execute([json_encode($revision['datos'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$fid]);
        foreach($revision['historial'] as $nota)$hist->execute([(int)$fid,$usuario,$nota]);
    }
}

/* Categorías de rechazo/reanálisis (prototipo, "Motivos de rechazo"); Control de calidad las agrupa.
   Se guardan al inicio del comentario del historial: "[Fuera de rango] detalle…". */
const LAB_MOTIVOS_RECHAZO = ['Fuera de rango', 'Error de digitación', 'Duplicado inconsistente', 'Falla de equipo', 'Control fuera de lo esperado', 'Otro'];

/* Los parámetros editables y sus reglas se definen en hojas_trabajo_v3.json. */
if (!function_exists('lab_validacion_corregir_encabezado')) {
    /**
     * Guarda en la hoja de cada formulario los parámetros corregidos por el
     * técnico (solo los permitidos y numéricos > 0) y deja constancia en el
     * historial. Se llama dentro de la transacción de la validación.
     */
    function lab_validacion_corregir_encabezado(PDO $pdo, array $ids, array $encabezado, string $usuario): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $stmt = $pdo->prepare('SELECT id_formulario, hoja, datos FROM lab_hoja_formulario WHERE id_formulario IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
        $stmt->execute($ids);
        $hojas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($hojas) !== count($ids)) {
            throw new InvalidArgumentException('Solo se pueden corregir parámetros de hojas capturadas con el formato de hoja de trabajo.');
        }

        $valores = [];
        foreach ($encabezado as $campo => $valor) {
            $campo = (string) $campo;
            if (!is_scalar($valor)&&$valor!==null) {
                throw new InvalidArgumentException('Ingrese un número válido en los parámetros de la corrida.');
            }
            $valor = str_replace(',', '.', trim((string) $valor));
            $valores[$campo] = $valor;
        }

        $guardar = $pdo->prepare('UPDATE lab_hoja_formulario SET datos = ? WHERE id_formulario = ?');
        $hist = $pdo->prepare("
            INSERT INTO historial_formulario (id_formulario, accion, estado_anterior, estado_nuevo, usuario, fecha, comentario)
            VALUES (?, 'correccion_parametro', 'Revisar', 'Revisar', ?, NOW(), ?)
        ");
        foreach ($hojas as $fila) {
            $campos=labParametrosValidacion($fila['hoja']);
            $permitidos = array_column($campos,'label','k');
            $datos = json_decode((string) $fila['datos'], true);
            if (!is_array($datos)) {
                throw new RuntimeException('La hoja del formulario #' . (int) $fila['id_formulario'] . ' está dañada.');
            }
            $cambios = [];
            foreach ($valores as $campo => $valor) {
                if (!isset($permitidos[$campo])) {
                    throw new InvalidArgumentException('El parámetro "' . $campo . '" no se puede editar en validación técnica.');
                }
                $valor=labParametroValidar($valor,$campos[$campo]);
                $anterior = (string) ($datos['encabezado'][$campo] ?? '');
                if ($anterior !== $valor) {
                    $datos['encabezado'][$campo] = $valor;
                    $cambios[] = $permitidos[$campo] . ': ' . ($anterior !== '' ? $anterior : '—') . ' → ' . $valor;
                }
            }
            if ($cambios) {
                $guardar->execute([json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (int) $fila['id_formulario']]);
                $hist->execute([(int) $fila['id_formulario'], $usuario !== '' ? $usuario : null, 'Corregido en validación técnica · ' . implode(' · ', $cambios)]);
            }
        }
    }
}

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

        if($accion==='aprobar'){
            $captura=$snapshot??(lab_validacion_snapshots($pdo,[$idFormulario])[$idFormulario]??null);
            if($captura)labSueloValidarCaptura($captura,$reanalizar);
        }
        $nuevoEstadoNombre = $accion === 'aprobar' ? 'Aprobado' : 'Rechazado';
        $comentarioHistorial = $comentario !== ''
            ? $comentario
            : ($accion === 'aprobar' ? 'Aprobado en validacion tecnica.' : 'Rechazado en validacion tecnica.');
        $motivoReanalisis = $comentario !== '' ? $comentario : 'Reanalisis solicitado en validacion tecnica.';
        $formulariosReanalisis = [];

        // Dentro de lab_validacion_resolver_grupo() ya hay una transacción abierta que cubre toda la hoja.
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
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

            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

if (!function_exists('lab_validacion_clave_grupo')) {
    /**
     * Clave que agrupa los formularios nacidos de un mismo envío de hoja de
     * trabajo (una hoja con varios análisis, p. ej. pH + CE, genera un
     * formulario por análisis y solicitud). Envíos nuevos traen `envio` en la
     * hoja; los anteriores se agrupan por hoja + analista + fecha + lote.
     */
    function lab_validacion_clave_grupo(array $linea, ?array $snapshot): string
    {
        if (!$snapshot) {
            return 'f:' . (int) $linea['id_formulario'];
        }
        if (!empty($snapshot['envio'])) {
            return 'e:' . $snapshot['envio'];
        }

        return 'h:' . implode('|', [
            (string) ($snapshot['hoja'] ?? ''),
            mb_strtolower(trim((string) ($snapshot['analista'] ?? $linea['analista'] ?? ''))),
            substr((string) ($snapshot['fecha'] ?? $linea['fecha'] ?? ''), 0, 10),
            (int) $linea['id_lote'],
        ]);
    }
}

if (!function_exists('lab_validacion_agrupar')) {
    /** Agrupa las líneas por envío de hoja, conservando el orden de aparición. */
    function lab_validacion_agrupar(array $lineas, array $snapshots): array
    {
        $grupos = [];
        foreach ($lineas as $linea) {
            $id = (int) $linea['id_formulario'];
            $clave = lab_validacion_clave_grupo($linea, $snapshots[$id] ?? null);
            $grupos[$clave] ??= ['clave' => $clave, 'lineas' => [], 'ids' => []];
            $grupos[$clave]['lineas'][] = $linea;
            $grupos[$clave]['ids'][] = $id;
        }

        // Dentro de la hoja, los análisis en el orden de su definición (p. ej. Caña: Brix, Pol, Rendimiento).
        static $hojas = null;
        if ($hojas === null) {
            $cfg = json_decode((string) @file_get_contents(__DIR__ . '/../config/hojas_trabajo_v3.json'), true);
            $hojas = is_array($cfg['hojas'] ?? null) ? $cfg['hojas'] : [];
        }
        foreach ($grupos as &$g) {
            $orden = static function (array $l) use ($snapshots, $hojas): int {
                $snap = $snapshots[(int) $l['id_formulario']] ?? [];
                $pos = array_search($snap['analisis'] ?? null, $hojas[$snap['hoja'] ?? '']['analisis'] ?? [], true);
                return $pos === false ? 99 : (int) $pos;
            };
            usort($g['lineas'], static fn($a, $b) => $orden($a) <=> $orden($b) ?: (int) $a['id_formulario'] <=> (int) $b['id_formulario']);
            $g['ids'] = array_map(static fn($l) => (int) $l['id_formulario'], $g['lineas']);
        }
        unset($g);

        return array_values($grupos);
    }
}

if (!function_exists('lab_validacion_resolver_grupo')) {
    /**
     * Valida o rechaza en conjunto todos los formularios de una hoja enviada,
     * en una sola transacción (o se resuelven todos o ninguno).
     * $reanalizar: [id_formulario => [id_muestra, ...]] muestras devueltas a
     * reanálisis por análisis; si se marcan todas las muestras de un
     * formulario, ese análisis se rechaza completo y el resto se aprueba.
     */
    function lab_validacion_resolver_grupo(PDO $pdo, array $ids, string $accion, string $usuario, ?int $usuarioId = null, string $comentario = '', array $reanalizar = [], string $categoria = '', array $encabezado = [], array $texturas = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids || count($ids) > 200) {
            throw new InvalidArgumentException('Seleccione los formularios de la hoja a validar.');
        }
        if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
            throw new InvalidArgumentException('Accion no valida.');
        }

        $marcas = [];
        if ($accion === 'aprobar') {
            foreach ($reanalizar as $idForm => $muestras) {
                $idForm = (int) $idForm;
                if (!in_array($idForm, $ids, true)) {
                    throw new InvalidArgumentException('Hay muestras marcadas de un formulario que no pertenece a la hoja.');
                }
                $muestras = array_values(array_unique(array_map('intval', (array) $muestras)));
                if ($muestras) {
                    $marcas[$idForm] = $muestras;
                }
            }
            if ($marcas && !in_array($categoria, LAB_MOTIVOS_RECHAZO, true)) {
                throw new InvalidArgumentException('Seleccione la categoría del motivo (rechazo o reanálisis).');
            }
        }

        // Análisis cuyas muestras se marcaron todas: se rechazan completos.
        $completos = [];
        if ($marcas) {
            $snapshots = lab_validacion_snapshots($pdo, array_keys($marcas));
            foreach ($marcas as $idForm => $muestras) {
                $seleccion = array_map('intval', $snapshots[$idForm]['seleccion'] ?? []);
                if ($seleccion && !array_diff($seleccion, $muestras) && !array_diff($muestras, $seleccion)) {
                    $completos[$idForm] = true;
                }
            }
            if (count($completos) === count($ids)) {
                throw new InvalidArgumentException('Marcaste todas las muestras: usa "Rechazar hoja completa".');
            }
        }

        $resultados = [];
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            if($texturas&&$accion==='aprobar')lab_validacion_corregir_texturas($pdo,$ids,$texturas,$usuario);
            if ($encabezado && $accion === 'aprobar') {
                lab_validacion_corregir_encabezado($pdo, $ids, $encabezado, $usuario);
            }
            foreach ($ids as $id) {
                if ($accion === 'rechazar') {
                    $resultados[] = lab_validacion_resolver($pdo, $id, 'rechazar', $usuario, $usuarioId, $comentario, [], $categoria);
                } elseif (isset($completos[$id])) {
                    $motivo = trim($comentario) !== '' ? $comentario : 'Reanálisis de todas las muestras de este análisis solicitado en validación técnica.';
                    $resultados[] = lab_validacion_resolver($pdo, $id, 'rechazar', $usuario, $usuarioId, $motivo, [], $categoria);
                } else {
                    $resultados[] = lab_validacion_resolver($pdo, $id, 'aprobar', $usuario, $usuarioId, $comentario, $marcas[$id] ?? [], isset($marcas[$id]) ? $categoria : '');
                }
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['formularios' => $resultados];
    }
}
