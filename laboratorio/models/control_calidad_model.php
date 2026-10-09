<?php
/**
 * Control de calidad (ISO/IEC 17025): indicadores y listados para actuar, calculados con el
 * flujo actual (Validación técnica, historial_formulario), la planificación (reanálisis abiertos)
 * y el catálogo de equipos (lab_equipo). Si existen las tablas de los formularios anteriores
 * (formulario_error / tipo_error) también se suman sus no conformidades.
 *
 * En Validación técnica cada decisión queda en historial_formulario con accion
 * 'validacion_tecnica' y estado_nuevo Aprobado/Rechazado; el comentario empieza con la categoría
 * entre corchetes ("[Fuera de rango] …"). Un rechazo es un formulario rechazado completo o una
 * aprobación con muestras devueltas a reanálisis.
 */
require_once __DIR__ . '/kanban_model.php';

const LAB_CC_PERIODOS = [7, 30, 90];

function lab_cc_tabla_existe(PDO $pdo, string $tabla): bool
{
    static $cache = [];
    if (!array_key_exists($tabla, $cache)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$tabla]);
        $cache[$tabla] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$tabla];
}

/** "[Categoría] detalle" → ['Categoría', 'detalle']. */
function lab_cc_motivo(string $comentario): array
{
    $comentario = trim($comentario);
    if (preg_match('/^\[([^\]]+)\]\s*(.*)$/us', $comentario, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return ['Sin clasificar', $comentario];
}

/** Decisiones de Validación técnica entre hace $desde y hace $hasta días. */
function lab_cc_decisiones(PDO $pdo, int $desde, int $hasta): array
{
    $stmt = $pdo->prepare("
        SELECT hf.id_formulario, hf.estado_nuevo, hf.usuario, hf.fecha, hf.comentario,
               ta.nombre AS analisis, tm.nombre AS tipo_muestra, l.codigo_lote, lr.inicio, lr.fin
        FROM historial_formulario hf
        INNER JOIN formulario f ON f.id_formulario = hf.id_formulario
        LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
        LEFT JOIN tipo_muestra tm ON tm.id_tipo = ta.id_tipo_muestra
        LEFT JOIN lote_rango lr ON lr.id_rango = f.id_rango
        LEFT JOIN lote l ON l.id_lote = lr.id_lote
        WHERE hf.accion = 'validacion_tecnica'
          AND hf.fecha >= DATE_SUB(NOW(), INTERVAL ? DAY) AND hf.fecha < DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY hf.fecha DESC, hf.id_historial DESC
    ");
    $stmt->execute([$desde, $hasta]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($filas as &$f) {
        $rechazado = stripos((string) $f['estado_nuevo'], 'rechaz') !== false;
        $devueltas = preg_match('/\((\d+) muestra\(s\) devuelta\(s\) a reanalisis\)/u', (string) $f['comentario'], $m) ? (int) $m[1] : 0;
        $f['es_rechazo'] = $rechazado || $devueltas > 0;
        $f['tipo'] = $rechazado ? 'Rechazo completo' : ($devueltas ? 'Reanálisis de ' . $devueltas . ' muestra(s)' : 'Aprobado');
        [$f['categoria'], $f['detalle']] = lab_cc_motivo(preg_replace('/\s*\(\d+ muestra\(s\) devuelta\(s\) a reanalisis\)\s*$/u', '', (string) $f['comentario']));
    }
    unset($f);
    return $filas;
}

function lab_control_calidad_vacio(int $dias): array
{
    return [
        'dias' => in_array($dias, LAB_CC_PERIODOS, true) ? $dias : 30, 'decisiones' => 0, 'rechazos' => 0, 'tasa_rechazo' => 0.0,
        'variacion_rechazo' => 0.0, 'reanalisis_abiertos' => [], 'rechazos_recientes' => [], 'por_analisis' => [], 'motivos' => [],
        'equipos' => [], 'equipos_vencidos' => 0, 'equipos_por_vencer' => 0, 'equipos_disponibles' => false,
        'no_conformidades_mes' => 0, 'variacion_no_conformidades' => 0,
    ];
}

function lab_control_calidad(PDO $pdo, int $dias): array
{
    $r = lab_control_calidad_vacio($dias);
    $dias = $r['dias'];
    if (!lab_cc_tabla_existe($pdo, 'historial_formulario')) {
        return $r;
    }

    // ---- Tasa de rechazo del periodo y del periodo anterior
    $actual = lab_cc_decisiones($pdo, $dias, 0);
    $anterior = lab_cc_decisiones($pdo, $dias * 2, $dias);
    $rechazosActual = array_values(array_filter($actual, static fn($d) => $d['es_rechazo']));
    $tasa = static fn(array $lista) => $lista ? count(array_filter($lista, static fn($d) => $d['es_rechazo'])) / count($lista) * 100 : 0.0;
    $r['decisiones'] = count($actual);
    $r['rechazos'] = count($rechazosActual);
    $r['tasa_rechazo'] = round($tasa($actual), 1);
    $r['variacion_rechazo'] = round($tasa($actual) - $tasa($anterior), 1);
    $r['rechazos_recientes'] = array_slice($rechazosActual, 0, 50);

    // ---- Por análisis: decisiones, rechazos y tasa
    $porAnalisis = [];
    foreach ($actual as $d) {
        $k = ($d['tipo_muestra'] ?? '') . '|' . ($d['analisis'] ?? '');
        $porAnalisis[$k] ??= ['analisis' => (string) ($d['analisis'] ?? 'Análisis'), 'tipo_muestra' => (string) ($d['tipo_muestra'] ?? ''), 'decisiones' => 0, 'rechazos' => 0];
        $porAnalisis[$k]['decisiones']++;
        if ($d['es_rechazo']) $porAnalisis[$k]['rechazos']++;
    }
    foreach ($porAnalisis as &$a) $a['tasa'] = $a['decisiones'] ? round($a['rechazos'] / $a['decisiones'] * 100, 1) : 0.0;
    unset($a);
    usort($porAnalisis, static fn($a, $b) => $b['tasa'] <=> $a['tasa'] ?: $b['rechazos'] <=> $a['rechazos'] ?: strcmp($a['analisis'], $b['analisis']));
    $r['por_analisis'] = array_values($porAnalisis);

    // ---- Motivos (categorías elegidas en Validación técnica; reanálisis por muestra incluidos)
    $porMotivo = [];
    foreach ($rechazosActual as $d) $porMotivo[$d['categoria']] = ($porMotivo[$d['categoria']] ?? 0) + 1;
    if (lab_cc_tabla_existe($pdo, 'formulario_error') && lab_cc_tabla_existe($pdo, 'tipo_error')) {
        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(te.nombre), ''), 'Sin clasificar') AS motivo, COUNT(*) AS total
            FROM formulario_error fe LEFT JOIN tipo_error te ON te.id_error = fe.id_error
            WHERE fe.fecha >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY motivo");
        $stmt->execute([$dias]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) $porMotivo[$m['motivo']] = ($porMotivo[$m['motivo']] ?? 0) + (int) $m['total'];
    }
    arsort($porMotivo);
    $maximo = $porMotivo ? max($porMotivo) : 0;
    $total = array_sum($porMotivo);
    foreach ($porMotivo as $motivo => $n) {
        $r['motivos'][] = ['motivo' => (string) $motivo, 'total' => $n, 'ancho' => $maximo ? (int) round($n / $maximo * 100) : 0,
            'porcentaje' => $total ? round($n / $total * 100) : 0];
    }

    // ---- Reanálisis abiertos: muestras devueltas que esperan nueva captura
    $tareas = lab_planificacion_tareas($pdo);
    $abiertos = array_values(array_filter($tareas, static fn($t) => in_array($t['estado']['key'], ['rechazado', 'repetido'], true)));
    $motivoPorForm = [];
    $ids = array_values(array_unique(array_filter(array_map(static fn($t) => (int) ($t['id_formulario'] ?? 0), $abiertos))));
    if ($ids) {
        $stmt = $pdo->prepare("SELECT id_formulario, comentario, fecha, usuario FROM historial_formulario
            WHERE id_formulario IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND accion IN ('validacion_tecnica', 'reanalisis')
            ORDER BY fecha, id_historial");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) $motivoPorForm[(int) $h['id_formulario']] = $h;
    }
    foreach ($abiertos as $t) {
        $h = $motivoPorForm[(int) ($t['id_formulario'] ?? 0)] ?? null;
        [$categoria, $detalle] = lab_cc_motivo((string) ($h['comentario'] ?? ''));
        $desde = $h['fecha'] ?? $t['fecha_formulario'] ?? null;
        $r['reanalisis_abiertos'][] = [
            'muestra' => (string) $t['codigo_muestra'], 'lote' => (string) $t['codigo_lote'], 'analisis' => (string) $t['analisis_nombre'],
            'tipo_muestra' => (string) $t['tipo_muestra'], 'analista' => trim((string) ($t['analista'] ?? '')),
            'categoria' => $h ? $categoria : 'Sin clasificar', 'detalle' => preg_replace('/\s*\(separada del formulario #\d+\)\s*$/u', '', $detalle),
            'validador' => (string) ($h['usuario'] ?? ''), 'desde' => (string) $desde,
            'dias' => $desde ? max(0, (int) floor((time() - strtotime((string) $desde)) / 86400)) : null,
        ];
    }
    usort($r['reanalisis_abiertos'], static fn($a, $b) => ($b['dias'] ?? 0) <=> ($a['dias'] ?? 0));

    // ---- No conformidades del mes (rechazos del flujo + formularios con error anteriores)
    $mes = static function (string $desdeSql, string $hastaSql) use ($pdo): int {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM historial_formulario WHERE accion = 'validacion_tecnica'
            AND (LOWER(estado_nuevo) LIKE '%rechaz%' OR comentario LIKE '%devuelta(s) a reanalisis%')
            AND fecha >= $desdeSql AND fecha < $hastaSql")->fetchColumn();
        if (lab_cc_tabla_existe($pdo, 'formulario_error')) {
            $n += (int) $pdo->query("SELECT COUNT(*) FROM formulario_error WHERE fecha >= $desdeSql AND fecha < $hastaSql")->fetchColumn();
        }
        return $n;
    };
    $inicioMes = "DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')";
    $r['no_conformidades_mes'] = $mes($inicioMes, "DATE_ADD($inicioMes, INTERVAL 1 MONTH)");
    $r['variacion_no_conformidades'] = $r['no_conformidades_mes'] - $mes("DATE_SUB($inicioMes, INTERVAL 1 MONTH)", $inicioMes);

    // ---- Equipos y su calibración (Catálogo de análisis › Equipos)
    try {
        require_once __DIR__ . '/../includes/catalogo_control_helper.php';
        labCatalogoControlEsquema($pdo);
        foreach (labEquiposListar($pdo, true) as $e) {
            $clave = $e['estado']['clave'];
            if ($clave === 'vencida') $r['equipos_vencidos']++;
            if ($clave === 'por_vencer') $r['equipos_por_vencer']++;
            if ($clave !== 'vigente') {
                $r['equipos'][] = ['etiqueta' => $e['etiqueta'], 'estado' => $clave, 'estado_texto' => $e['estado']['texto'],
                    'proxima' => (string) ($e['proxima_calibracion'] ?? ''), 'analisis' => (int) $e['analisis']];
            }
        }
        $orden = ['vencida' => 0, 'por_vencer' => 1, 'sin' => 2];
        usort($r['equipos'], static fn($a, $b) => $orden[$a['estado']] <=> $orden[$b['estado']] ?: strcmp($a['proxima'], $b['proxima']));
        $r['equipos_disponibles'] = true;
    } catch (Throwable $e) {
        error_log('[laboratorio][control_calidad] equipos: ' . $e->getMessage());
    }

    return $r;
}
