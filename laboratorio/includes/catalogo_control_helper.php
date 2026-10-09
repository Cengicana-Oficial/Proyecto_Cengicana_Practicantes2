<?php
/**
 * catalogo_control_helper.php
 *
 * Pestañas del Catálogo de análisis (prototipo v3) que complementan el catálogo maestro:
 *  - Equipos de laboratorio con su calendario de calibración (lab_equipo).
 *  - Métodos analíticos: se derivan del catálogo (método + norma por tipo de muestra).
 *  - Formato de captura de cada análisis (config/hojas_trabajo_v3.json).
 *  - Auditoría: cambios del catálogo y eventos de las bitácoras del flujo.
 */
require_once __DIR__ . '/workflow_trabajo_helper.php';

const LAB_EQUIPO_DIAS_AVISO = 30;

function labCatalogoControlEsquema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_equipo (
        id_equipo INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(160) NOT NULL,
        codigo VARCHAR(40) NOT NULL,
        ultima_calibracion DATE DEFAULT NULL,
        proxima_calibracion DATE DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        observaciones VARCHAR(500) DEFAULT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lab_equipo_codigo (codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_catalogo_auditoria (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        usuario VARCHAR(255) NOT NULL,
        accion VARCHAR(80) NOT NULL,
        referencia VARCHAR(255) NOT NULL,
        detalle TEXT DEFAULT NULL,
        KEY idx_lab_catalogo_auditoria_fecha (fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // Primera vez: los equipos ya escritos en el catálogo pasan al registro (sin fechas de calibración).
    if (!(int) $pdo->query('SELECT COUNT(*) FROM lab_equipo')->fetchColumn()) {
        $nombres = $pdo->query("SELECT DISTINCT TRIM(equipo_default) FROM tipo_analisis WHERE equipo_default IS NOT NULL AND TRIM(equipo_default) <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $insertar = $pdo->prepare('INSERT IGNORE INTO lab_equipo (nombre, codigo) VALUES (?, ?)');
        foreach ($nombres as $i => $texto) {
            [$nombre, $codigo] = labEquipoSepararCodigo((string) $texto);
            $insertar->execute([$nombre, $codigo !== '' ? $codigo : sprintf('EQ-%03d', $i + 1)]);
        }
    }
}

/** "Espectrofotómetro UV-Vis (EQ-014)" → ["Espectrofotómetro UV-Vis", "EQ-014"]. */
function labEquipoSepararCodigo(string $texto): array
{
    $texto = trim($texto);
    if (preg_match('/^(.*?)\s*\(([^()]{2,40})\)\s*$/u', $texto, $m)) {
        return [trim($m[1]), strtoupper(trim($m[2]))];
    }
    return [$texto, ''];
}

function labEquipoEtiqueta(array $equipo): string
{
    return trim($equipo['nombre'] . ($equipo['codigo'] !== '' ? ' (' . $equipo['codigo'] . ')' : ''));
}

/** Estado de calibración: vigente, por vencer (≤ 30 días), vencida o sin registro. */
function labEquipoEstado(?string $proxima): array
{
    if (!$proxima) {
        return ['clave' => 'sin', 'texto' => 'Sin calibración registrada'];
    }
    $dias = (int) floor((strtotime($proxima) - strtotime(date('Y-m-d'))) / 86400);
    if ($dias < 0) {
        return ['clave' => 'vencida', 'texto' => 'Vencida hace ' . abs($dias) . ' día' . (abs($dias) === 1 ? '' : 's')];
    }
    if ($dias <= LAB_EQUIPO_DIAS_AVISO) {
        return ['clave' => 'por_vencer', 'texto' => 'Por vencer · ' . $dias . ' día' . ($dias === 1 ? '' : 's')];
    }
    return ['clave' => 'vigente', 'texto' => 'Vigente'];
}

function labEquiposListar(PDO $pdo, bool $soloActivos = false): array
{
    $filas = $pdo->query('SELECT * FROM lab_equipo' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY activo DESC, nombre')->fetchAll(PDO::FETCH_ASSOC);
    $uso = [];
    foreach ($pdo->query("SELECT equipo_default, COUNT(*) AS n FROM tipo_analisis WHERE equipo_default IS NOT NULL GROUP BY equipo_default") as $r) {
        $uso[mb_strtolower(trim((string) $r['equipo_default']), 'UTF-8')] = (int) $r['n'];
    }
    foreach ($filas as &$f) {
        $f['estado'] = labEquipoEstado($f['proxima_calibracion']);
        $f['etiqueta'] = labEquipoEtiqueta($f);
        $f['analisis'] = $uso[mb_strtolower($f['etiqueta'], 'UTF-8')] ?? ($uso[mb_strtolower($f['nombre'], 'UTF-8')] ?? 0);
    }
    unset($f);
    return $filas;
}

/**
 * Equipos para las hojas de trabajo de la Bandeja del analista, tomados del Catálogo de análisis:
 *  - 'equipos': equipos activos (etiqueta "Nombre (CÓDIGO)") con su estado de calibración.
 *  - 'por_hoja': equipo por defecto de cada hoja de trabajo, según el "Equipo por defecto" de
 *    sus análisis en el catálogo, expresado con la etiqueta del equipo registrado.
 */
function labEquiposParaHojas(PDO $pdo): array
{
    $equipos = [];
    $porTexto = [];
    foreach (labEquiposListar($pdo, true) as $e) {
        $equipos[] = ['etiqueta' => $e['etiqueta'], 'estado' => $e['estado']['clave'], 'estado_texto' => $e['estado']['texto']];
        foreach ([$e['etiqueta'], $e['nombre'], $e['codigo']] as $texto) {
            if (trim((string) $texto) !== '') $porTexto[mb_strtolower(trim((string) $texto), 'UTF-8')] = $e['etiqueta'];
        }
    }

    $porHoja = [];
    $filas = $pdo->query("
        SELECT ta.nombre, ta.equipo_default, tm.nombre AS tipo_muestra
        FROM tipo_analisis ta
        INNER JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
        WHERE COALESCE(ta.activo, 1) = 1 AND ta.equipo_default IS NOT NULL AND TRIM(ta.equipo_default) <> ''
        ORDER BY ta.id_tipo
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $f) {
        $hoja = wfHoja((string) $f['tipo_muestra'], (string) $f['nombre']);
        if (!$hoja || isset($porHoja[$hoja['key']])) continue;
        $texto = trim((string) $f['equipo_default']);
        [, $codigo] = labEquipoSepararCodigo($texto);
        $porHoja[$hoja['key']] = $porTexto[mb_strtolower($texto, 'UTF-8')] ?? ($porTexto[mb_strtolower($codigo, 'UTF-8')] ?? $texto);
    }

    return ['equipos' => $equipos, 'por_hoja' => (object) $porHoja];
}

function labEquipoGuardar(PDO $pdo, array $datos, string $usuario): string
{
    $id = (int) ($datos['id_equipo'] ?? 0);
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $codigo = strtoupper(trim((string) ($datos['codigo'] ?? '')));
    if ($nombre === '' || $codigo === '') {
        throw new InvalidArgumentException('Indique el nombre y el código del equipo.');
    }
    if (mb_strlen($nombre) > 160 || mb_strlen($codigo) > 40) {
        throw new InvalidArgumentException('El nombre o el código del equipo es demasiado largo.');
    }
    $fecha = static function ($v): ?string {
        $v = trim((string) $v);
        if ($v === '') return null;
        $d = DateTime::createFromFormat('Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw new InvalidArgumentException('Fecha de calibración no válida.');
        return $v;
    };
    $ultima = $fecha($datos['ultima_calibracion'] ?? '');
    $proxima = $fecha($datos['proxima_calibracion'] ?? '');
    if ($ultima && $proxima && $proxima < $ultima) {
        throw new InvalidArgumentException('La próxima calibración no puede ser anterior a la última.');
    }
    $activo = !empty($datos['activo']) ? 1 : 0;
    $obs = trim((string) ($datos['observaciones'] ?? '')) ?: null;
    $dup = $pdo->prepare('SELECT id_equipo FROM lab_equipo WHERE codigo = ? AND id_equipo <> ?');
    $dup->execute([$codigo, $id]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Ya existe un equipo con el código ' . $codigo . '.');
    }
    if ($id > 0) {
        $prev = $pdo->prepare('SELECT nombre, codigo FROM lab_equipo WHERE id_equipo = ?');
        $prev->execute([$id]);
        $anterior = $prev->fetch(PDO::FETCH_ASSOC);
        if (!$anterior) throw new InvalidArgumentException('El equipo ya no existe.');
        $pdo->prepare('UPDATE lab_equipo SET nombre = ?, codigo = ?, ultima_calibracion = ?, proxima_calibracion = ?, activo = ?, observaciones = ? WHERE id_equipo = ?')
            ->execute([$nombre, $codigo, $ultima, $proxima, $activo, $obs, $id]);
        // Mantiene el vínculo con los análisis que lo usan como equipo por defecto.
        $antes = labEquipoEtiqueta($anterior);
        $ahora = labEquipoEtiqueta(['nombre' => $nombre, 'codigo' => $codigo]);
        if ($antes !== $ahora) {
            $pdo->prepare('UPDATE tipo_analisis SET equipo_default = ? WHERE equipo_default = ?')->execute([$ahora, $antes]);
        }
        labCatalogoAuditar($pdo, $usuario, 'Edición de equipo', $ahora, 'Calibración: ' . ($ultima ?: '—') . ' → próxima ' . ($proxima ?: '—') . ($activo ? '' : ' · fuera de servicio'));
        return 'equipo_updated';
    }
    $pdo->prepare('INSERT INTO lab_equipo (nombre, codigo, ultima_calibracion, proxima_calibracion, activo, observaciones) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$nombre, $codigo, $ultima, $proxima, $activo, $obs]);
    labCatalogoAuditar($pdo, $usuario, 'Alta de equipo', labEquipoEtiqueta(['nombre' => $nombre, 'codigo' => $codigo]), 'Próxima calibración: ' . ($proxima ?: 'sin registrar'));
    return 'equipo_created';
}

function labCatalogoAuditar(PDO $pdo, string $usuario, string $accion, string $referencia, string $detalle = ''): void
{
    $pdo->prepare('INSERT INTO lab_catalogo_auditoria (usuario, accion, referencia, detalle) VALUES (?, ?, ?, ?)')
        ->execute([$usuario !== '' ? $usuario : 'Usuario', mb_substr($accion, 0, 80), mb_substr($referencia, 0, 255), $detalle !== '' ? $detalle : null]);
}

/** Métodos analíticos: cada combinación método + norma + tipo de muestra del catálogo, con sus análisis. */
function labCatalogoMetodos(array $filas): array
{
    $metodos = [];
    foreach ($filas as $f) {
        $metodo = trim((string) ($f['metodo'] ?? ''));
        if ($metodo === '') continue;
        $norma = trim((string) ($f['norma'] ?? ''));
        $tipo = (string) ($f['nombre_muestra'] ?? '');
        $clave = mb_strtolower($metodo . '|' . $norma . '|' . $tipo, 'UTF-8');
        $metodos[$clave] ??= ['metodo' => $metodo, 'norma' => $norma, 'tipo' => $tipo, 'id_tipo_muestra' => (int) ($f['id_tipo_muestra'] ?? 0),
            'analisis' => [], 'ids' => [], 'activos' => 0];
        if (!in_array((string) $f['nombre'], $metodos[$clave]['analisis'], true)) $metodos[$clave]['analisis'][] = (string) $f['nombre'];
        $metodos[$clave]['ids'][] = (int) $f['id_tipo'];
        if ((int) ($f['activo'] ?? 1) === 1) $metodos[$clave]['activos']++;
    }
    uasort($metodos, static fn($a, $b) => strcmp($a['tipo'], $b['tipo']) ?: strcasecmp($a['metodo'], $b['metodo']));
    return array_values($metodos);
}

/**
 * Crea o edita un método analítico de un tipo de muestra. Un método es el par método + norma que
 * comparten varios análisis del catálogo, así que guardarlo actualiza tipo_analisis.metodo/norma:
 *  - los análisis marcados quedan con el método y la norma indicados;
 *  - los que tenían el método original y se desmarcaron quedan sin método.
 * Devuelve la clave del mensaje ('metodo_created' o 'metodo_updated').
 */
function labMetodoGuardar(PDO $pdo, array $datos, string $usuario): string
{
    $idTipoMuestra = (int) ($datos['id_tipo_muestra'] ?? 0);
    $metodo = trim((string) ($datos['metodo'] ?? ''));
    $norma = trim((string) ($datos['norma'] ?? ''));
    $metodoOriginal = trim((string) ($datos['metodo_original'] ?? ''));
    $normaOriginal = trim((string) ($datos['norma_original'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($datos['analisis'] ?? [])))));
    $esNuevo = $metodoOriginal === '';

    if ($idTipoMuestra <= 0) throw new InvalidArgumentException('Seleccione el tipo de muestra del método.');
    if ($metodo === '') throw new InvalidArgumentException('Indique el nombre del método.');
    if (mb_strlen($metodo) > 255 || mb_strlen($norma) > 255) throw new InvalidArgumentException('El método o la norma es demasiado largo (máximo 255 caracteres).');
    if (!$ids && $esNuevo) throw new InvalidArgumentException('Marque al menos un análisis que use este método.');

    $stmt = $pdo->prepare('SELECT ta.id_tipo, ta.nombre, TRIM(COALESCE(ta.metodo, \'\')) AS metodo, TRIM(COALESCE(ta.norma, \'\')) AS norma, tm.nombre AS tipo
        FROM tipo_analisis ta LEFT JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra WHERE ta.id_tipo_muestra = ?');
    $stmt->execute([$idTipoMuestra]);
    $delTipo = [];
    $tipoNombre = '';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $delTipo[(int) $f['id_tipo']] = $f;
        $tipoNombre = (string) $f['tipo'];
    }
    foreach ($ids as $id) {
        if (!isset($delTipo[$id])) throw new InvalidArgumentException('Uno de los análisis marcados no pertenece a este tipo de muestra.');
    }

    $mismo = static fn(array $f, string $m, string $n) => mb_strtolower($f['metodo'], 'UTF-8') === mb_strtolower($m, 'UTF-8')
        && mb_strtolower($f['norma'], 'UTF-8') === mb_strtolower($n, 'UTF-8');
    $quitar = [];
    if (!$esNuevo) {
        foreach ($delTipo as $id => $f) {
            if ($mismo($f, $metodoOriginal, $normaOriginal) && !in_array($id, $ids, true)) $quitar[] = $id;
        }
    }

    $pdo->beginTransaction();
    try {
        $asignar = $pdo->prepare('UPDATE tipo_analisis SET metodo = ?, norma = ? WHERE id_tipo = ?');
        foreach ($ids as $id) $asignar->execute([$metodo, $norma !== '' ? $norma : null, $id]);
        $limpiar = $pdo->prepare('UPDATE tipo_analisis SET metodo = NULL, norma = NULL WHERE id_tipo = ?');
        foreach ($quitar as $id) $limpiar->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $nombres = static fn(array $lista) => implode(', ', array_map(static fn($id) => $delTipo[$id]['nombre'], $lista));
    $detalle = ($esNuevo ? '' : 'Antes: ' . $metodoOriginal . ($normaOriginal !== '' ? ' · ' . $normaOriginal : '') . '. ')
        . 'Ahora: ' . $metodo . ($norma !== '' ? ' · ' . $norma : '') . '.'
        . ($ids ? ' Análisis: ' . $nombres($ids) . '.' : '')
        . ($quitar ? ' Quedaron sin método: ' . $nombres($quitar) . '.' : '');
    labCatalogoAuditar($pdo, $usuario, $esNuevo ? 'Método analítico creado' : 'Método analítico editado', $metodo . ' · ' . $tipoNombre, $detalle);

    return $esNuevo ? 'metodo_created' : 'metodo_updated';
}

/** Análisis sin método configurado (para avisar en la pestaña de métodos). */
function labCatalogoSinMetodo(array $filas): array
{
    return array_values(array_filter($filas, static fn($f) => trim((string) ($f['metodo'] ?? '')) === ''));
}

/** Formato de captura (hoja de trabajo) de un análisis: resumen y detalle para el visor. */
function labCatalogoFormato(string $tipoMuestra, string $analisis): ?array
{
    try {
        $h = wfHoja($tipoMuestra, $analisis);
    } catch (Throwable $e) {
        return null;
    }
    if (!$h) return null;
    $hoja = wfConfig()['hojas'][$h['key']] ?? null;
    if (!$hoja) return null;
    $labels = static fn(array $lista, string $k = 'label') => array_values(array_map(static fn($x) => (string) ($x[$k] ?? ''), $lista));
    $vistas = [];
    foreach ($hoja['vistas'] ?? [] as $v) {
        $vistas[] = ['titulo' => (string) ($v['titulo'] ?? implode(', ', $v['analisis'] ?? [])), 'cols' => $labels($v['cols'] ?? []), 'grafico' => !empty($v['grafico'])];
    }
    $cols = array_values(array_map(static fn($c) => (string) $c['label'] . (!empty($c['auto']) ? ' (auto)' : ''), $hoja['cols'] ?? []));
    $partes = [];
    if (!empty($hoja['controles'])) $partes[] = implode(' + ', $labels($hoja['controles']));
    if (!empty($hoja['encabezado']['campos'])) {
        $n = count($hoja['encabezado']['campos']);
        $partes[] = $n . ' campo' . ($n === 1 ? '' : 's') . ' de encabezado';
    }
    if (!empty($hoja['curva'])) $partes[] = 'curva';
    $nVal = array_sum(array_map(static fn($v) => count($v['cols']), $vistas));
    return [
        'clave' => $h['key'],
        'titulo' => (string) ($hoja['titulo'] ?? $analisis),
        'generico' => !empty($hoja['generico']),
        'resumen' => !empty($hoja['generico']) ? 'Captura directa del resultado' : count($cols) . ' col. analista · ' . $nVal . ' col. validación',
        'detalle' => !empty($hoja['generico']) ? '' : (implode(' · ', $partes) ?: 'sin filas de control'),
        'analisis' => array_values($hoja['analisis'] ?? []),
        'encabezado' => $labels($hoja['encabezado']['campos'] ?? []),
        'controles' => $labels($hoja['controles'] ?? []),
        'cols' => $cols,
        'curva' => !empty($hoja['curva']) ? count($hoja['curva']['def'] ?? []) . ' puntos (' . implode(', ', $hoja['curva']['def'] ?? []) . ' ' . ($hoja['curva']['unidadX'] ?? '') . ')' : '',
        'vistas' => $vistas,
        'formula' => (string) ($hoja['formula'] ?? ''),
    ];
}

/**
 * Auditoría: cambios del catálogo y eventos de las bitácoras del flujo (recepción, planificación,
 * hojas de trabajo, validación, informes y documentos), más recientes primero.
 */
function labCatalogoAuditoria(PDO $pdo, int $limite = 400): array
{
    $ev = [];
    $leer = static function (string $sql) use ($pdo, &$ev, $limite) {
        try {
            foreach ($pdo->query($sql . ' LIMIT ' . $limite) as $r) {
                $ev[] = ['fecha' => (string) $r['fecha'], 'usuario' => (string) ($r['usuario'] ?? ''), 'accion' => (string) $r['accion'],
                    'referencia' => (string) ($r['referencia'] ?? ''), 'detalle' => (string) ($r['detalle'] ?? ''), 'modulo' => (string) $r['modulo']];
            }
        } catch (Throwable $e) {
            // Tabla aún no creada en esta instalación.
        }
    };
    $leer("SELECT fecha, usuario, accion, referencia, detalle, 'Catálogo' AS modulo FROM lab_catalogo_auditoria ORDER BY fecha DESC");
    $leer("SELECT h.fecha_cambio AS fecha, h.usuario_nombre AS usuario, 'Edición de recepción' AS accion,
        CONCAT('Lote ', COALESCE(l.codigo_lote, '—'), ' · solicitud #', h.id_solicitud) AS referencia,
        CONCAT(h.resumen, IF(h.motivo IS NULL OR h.motivo = '', '', CONCAT(' · Motivo: ', h.motivo))) AS detalle, 'Recepción' AS modulo
        FROM solicitud_historial_cambios h LEFT JOIN solicitud s ON s.id_solicitud = h.id_solicitud LEFT JOIN lote l ON l.id_lote = s.id_lote ORDER BY h.fecha_cambio DESC");
    $leer("SELECT h.creado_en AS fecha, h.usuario, CONCAT('Identificación v', h.version) AS accion,
        CONCAT('Lote ', COALESCE(l.codigo_lote, '—')) AS referencia, CONCAT(h.cambios, ' muestra(s) · ', h.motivo) AS detalle, 'Identificación' AS modulo
        FROM lab_identificacion_historial h LEFT JOIN solicitud s ON s.id_solicitud = h.id_solicitud LEFT JOIN lote l ON l.id_lote = s.id_lote ORDER BY h.creado_en DESC");
    $leer("SELECT a.fecha, a.usuario, a.accion, IF(a.accion = 'Planificación', CONCAT('Lote ', COALESCE(l.codigo_lote, a.referencia)), a.referencia) AS referencia,
        '' AS detalle, IF(a.accion = 'Planificación', 'Planificación', 'Bandeja del analista') AS modulo
        FROM lab_workflow_auditoria a LEFT JOIN lote l ON a.accion = 'Planificación' AND l.id_lote = a.referencia ORDER BY a.fecha DESC");
    $leer("SELECT hf.fecha, hf.usuario, COALESCE(hf.accion, 'Actualización') AS accion,
        CONCAT('Lote ', COALESCE(l.codigo_lote, '—'), ' · ', COALESCE(ta.nombre, 'Análisis'), ' · muestras ', lr.inicio, '–', lr.fin) AS referencia,
        CONCAT_WS(' · ', IF(hf.estado_nuevo IS NULL, NULL, CONCAT('Estado: ', hf.estado_nuevo)), NULLIF(hf.comentario, '')) AS detalle, 'Validación' AS modulo
        FROM historial_formulario hf JOIN formulario f ON f.id_formulario = hf.id_formulario LEFT JOIN lote_rango lr ON lr.id_rango = f.id_rango
        LEFT JOIN lote l ON l.id_lote = lr.id_lote LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis ORDER BY hf.fecha DESC");
    $leer("SELECT h.fecha, h.usuario, CONCAT('Informe · ', h.accion) AS accion, CONCAT('Lote ', COALESCE(l.codigo_lote, '—')) AS referencia, h.detalle, 'Informes' AS modulo
        FROM lab_informe_historial h LEFT JOIN lote l ON l.id_lote = h.id_lote ORDER BY h.fecha DESC");
    $leer("SELECT h.fecha, h.usuario, IF(h.cambios LIKE 'Enviado por correo%', 'Envío por correo', CONCAT('Nueva versión de ', d.tipo_documento)) AS accion,
        CONCAT('Lote ', COALESCE(l.codigo_lote, '—'), ' · ', d.tipo_documento, ' v', h.version) AS referencia, h.cambios AS detalle, 'Reportes' AS modulo
        FROM lab_documento_historial h JOIN lab_documento d ON d.id_documento = h.id_documento LEFT JOIN lote l ON l.id_lote = d.id_lote ORDER BY h.fecha DESC");
    usort($ev, static fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    return array_slice($ev, 0, $limite);
}
