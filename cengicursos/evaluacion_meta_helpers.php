<?php
/**
 * Meta de participación en la evaluación del instructor: al menos un porcentaje de los
 * participantes de cada curso o diplomado (40 % por defecto, configurable desde el informe
 * consolidado) debe evaluar a cada instructor, por curso o por módulo según su enlace.
 *
 * Las respuestas por el Aula registran quién respondió (aula_instructor_respuestas), así
 * que se puede saber quién falta. Las del enlace público y las cargas masivas son
 * anónimas: cuentan para el porcentaje, pero no identifican al participante.
 */
require_once __DIR__ . '/aula_helpers.php';

/** Valor por defecto; el porcentaje vigente se configura y se lee con cengi_eval_meta_porcentaje(). */
const CENGI_META_EVAL_INSTRUCTOR = 40;
const CENGI_META_EVAL_CLAVE = 'meta_eval_instructor';

/** Configuración guardada en eventos_config: ['porcentaje' => int, 'actualizado_por' => '', 'actualizado' => '']. */
function cengi_eval_meta_config(PDO $db)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['porcentaje' => CENGI_META_EVAL_INSTRUCTOR, 'actualizado_por' => '', 'actualizado' => ''];
    try {
        $stmt = $db->prepare('SELECT valor FROM eventos_config WHERE clave = ?');
        $stmt->execute([CENGI_META_EVAL_CLAVE]);
        $guardado = json_decode((string) $stmt->fetchColumn(), true);
        if (is_array($guardado) && isset($guardado['porcentaje'])) {
            $cache = array_merge($cache, $guardado);
            $cache['porcentaje'] = max(1, min(100, (int) $cache['porcentaje']));
        }
    } catch (Throwable $e) {
        error_log('Meta de evaluación del instructor: ' . $e->getMessage());
    }
    return $cache;
}

/** Porcentaje mínimo de participantes que debe evaluar a cada instructor (1–100). */
function cengi_eval_meta_porcentaje(PDO $db)
{
    return (int) cengi_eval_meta_config($db)['porcentaje'];
}

/** Quién puede cambiar la meta: administración o quien gestiona instructores. */
function cengi_eval_meta_puede_configurar()
{
    return cengi_es_admin() || cengi_puede_gestionar_instructores();
}

function cengi_eval_meta_guardar(PDO $db, $porcentaje, $usuario)
{
    $porcentaje = filter_var($porcentaje, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($porcentaje === false) {
        throw new InvalidArgumentException('Indica un porcentaje entero entre 1 y 100.');
    }
    $valor = ['porcentaje' => $porcentaje, 'actualizado_por' => (string) $usuario, 'actualizado' => date('Y-m-d H:i:s')];
    $db->prepare('INSERT INTO eventos_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([CENGI_META_EVAL_CLAVE, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

/** Participantes activos del curso (todos los ingenios: la meta es del curso completo). */
function cengi_eval_meta_participantes(PDO $db, $cursoId)
{
    $stmt = $db->prepare("SELECT a.id AS asignacion_id, p.nombre_participantes AS nombre, p.correo_participantes AS correo, i.nombre_ingenios AS ingenio
        FROM asignaciones a JOIN participantes p ON p.id = a.participantes_id LEFT JOIN ingenios i ON i.id = p.ingenio_id
        WHERE a.cursos_id = ? AND a.estado_asignaciones = 1 AND p.estado_participantes = 1 ORDER BY p.nombre_participantes");
    $stmt->execute([(int) $cursoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Avance de cada enlace de evaluación del curso hacia la meta.
 * Devuelve ['inscritos' => n, 'meta' => respuestas necesarias, 'enlaces' => [...], 'bajo_meta' => n].
 */
function cengi_eval_meta_curso(PDO $db, $cursoId)
{
    $participantes = cengi_eval_meta_participantes($db, $cursoId);
    $inscritos = count($participantes);
    $meta = (int) ceil($inscritos * cengi_eval_meta_porcentaje($db) / 100);

    $stmt = $db->prepare("SELECT e.id, e.token, e.instructor_id, i.nombre AS instructor, e.curso_modulo_id, m.nombre AS modulo, m.orden,
            COUNT(ei.id) AS respuestas
        FROM enlaces_evaluacion_instructor e
        JOIN instructores i ON i.id = e.instructor_id
        LEFT JOIN curso_modulos m ON m.id = e.curso_modulo_id
        LEFT JOIN evaluaciones_instructor ei ON ei.enlace_id = e.id
        WHERE e.curso_id = ?
        GROUP BY e.id, e.token, e.instructor_id, i.nombre, e.curso_modulo_id, m.nombre, m.orden
        ORDER BY (e.curso_modulo_id IS NOT NULL), m.orden, i.nombre");
    $stmt->execute([(int) $cursoId]);
    $enlaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Quién respondió por el Aula, por instructor (el Aula registra por curso e instructor).
    $respondieron = [];
    $conAula = [];
    if (aula_control_ready($db)) {
        $q = $db->prepare('SELECT instructor_id FROM aula_instructor_config WHERE curso_id = ?');
        $q->execute([(int) $cursoId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $conAula[(int) $id] = true;
        }
        $q = $db->prepare('SELECT instructor_id, asignacion_id FROM aula_instructor_respuestas WHERE curso_id = ?');
        $q->execute([(int) $cursoId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $respondieron[(int) $r['instructor_id']][(int) $r['asignacion_id']] = true;
        }
    }

    // Último recordatorio enviado por enlace (queda registrado en Comunicaciones).
    $recordatorios = [];
    if ($enlaces) {
        $refs = array_map(static function ($e) { return 'eval-instructor-' . (int) $e['id']; }, $enlaces);
        $q = $db->prepare('SELECT referencia, MAX(creado) AS fecha, COUNT(*) AS veces, SUM(enviados) AS enviados FROM comunicaciones WHERE referencia IN (' . implode(',', array_fill(0, count($refs), '?')) . ') GROUP BY referencia');
        $q->execute($refs);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $recordatorios[$r['referencia']] = $r;
        }
    }

    $bajoMeta = 0;
    foreach ($enlaces as &$e) {
        $e['respuestas'] = (int) $e['respuestas'];
        $e['porcentaje'] = $inscritos ? round($e['respuestas'] / $inscritos * 100) : 0;
        $e['faltan'] = max(0, $meta - $e['respuestas']);
        $e['cumple'] = $inscritos > 0 && $e['faltan'] === 0;
        $e['rastreable'] = isset($conAula[(int) $e['instructor_id']]);
        $e['pendientes'] = null;
        if ($e['rastreable']) {
            $hechos = $respondieron[(int) $e['instructor_id']] ?? [];
            $e['pendientes'] = array_values(array_filter($participantes, static function ($p) use ($hechos) {
                return !isset($hechos[(int) $p['asignacion_id']]);
            }));
        }
        $e['recordatorio'] = $recordatorios['eval-instructor-' . (int) $e['id']] ?? null;
        if (!$e['cumple']) {
            $bajoMeta++;
        }
    }
    unset($e);

    return ['inscritos' => $inscritos, 'meta' => $meta, 'porcentaje_meta' => cengi_eval_meta_porcentaje($db), 'enlaces' => $enlaces, 'bajo_meta' => $bajoMeta, 'participantes' => $participantes];
}

/**
 * Avance hacia la meta de todos los enlaces de evaluación (cada curso o módulo por instructor),
 * para el informe consolidado. $anio filtra por el inicio del curso (0 = todos).
 * estado: por_iniciar | sin_inscritos (no cuentan) · cumple · en_curso (bajo meta, curso abierto)
 *         · cerrado (bajo meta y el curso ya terminó).
 */
function cengi_eval_meta_global(PDO $db, $anio = 0)
{
    $params = [];
    $filtro = '';
    if ((int) $anio > 0) {
        $filtro = ' AND YEAR(c.inicio) = ?';
        $params[] = (int) $anio;
    }
    $stmt = $db->prepare("SELECT e.id, e.curso_id, e.instructor_id, i.nombre AS instructor, i.codigo_catedratico,
            c.codigo_curso, c.nombre_cursos, c.inicio, c.fin, m.nombre AS modulo, m.orden,
            (SELECT COUNT(*) FROM evaluaciones_instructor ei WHERE ei.enlace_id = e.id) AS respuestas,
            (SELECT COUNT(*) FROM asignaciones a JOIN participantes p ON p.id = a.participantes_id
                WHERE a.cursos_id = e.curso_id AND a.estado_asignaciones = 1 AND p.estado_participantes = 1) AS inscritos
        FROM enlaces_evaluacion_instructor e
        JOIN instructores i ON i.id = e.instructor_id
        JOIN cursos c ON c.id = e.curso_id
        LEFT JOIN curso_modulos m ON m.id = e.curso_modulo_id
        WHERE 1 = 1 $filtro
        ORDER BY i.nombre, c.inicio DESC, c.nombre_cursos, (e.curso_modulo_id IS NOT NULL), m.orden");
    $stmt->execute($params);
    $hoy = date('Y-m-d');
    $pctMeta = cengi_eval_meta_porcentaje($db);
    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $f['respuestas'] = (int) $f['respuestas'];
        $f['inscritos'] = (int) $f['inscritos'];
        $f['meta'] = (int) ceil($f['inscritos'] * $pctMeta / 100);
        $f['porcentaje'] = $f['inscritos'] ? round($f['respuestas'] / $f['inscritos'] * 100) : 0;
        $f['faltan'] = max(0, $f['meta'] - $f['respuestas']);
        if (empty($f['inicio']) || $f['inicio'] > $hoy) {
            $f['estado'] = 'por_iniciar';
        } elseif ($f['inscritos'] === 0) {
            $f['estado'] = 'sin_inscritos';
        } elseif ($f['faltan'] === 0) {
            $f['estado'] = 'cumple';
        } else {
            $f['estado'] = (!empty($f['fin']) && $f['fin'] < $hoy) ? 'cerrado' : 'en_curso';
        }
        $f['cuenta'] = in_array($f['estado'], ['cumple', 'en_curso', 'cerrado'], true);
        $filas[] = $f;
    }
    return $filas;
}

/** Resumen por instructor de cengi_eval_meta_global(): [instructor_id => evaluables, cumple, respuestas, inscritos, porcentaje]. */
function cengi_eval_meta_por_instructor(array $filas)
{
    $res = [];
    foreach ($filas as $f) {
        $id = (int) $f['instructor_id'];
        $res[$id] = $res[$id] ?? ['evaluables' => 0, 'cumple' => 0, 'respuestas' => 0, 'inscritos' => 0, 'porcentaje' => null];
        if (!$f['cuenta']) {
            continue;
        }
        $res[$id]['evaluables']++;
        $res[$id]['cumple'] += $f['estado'] === 'cumple' ? 1 : 0;
        $res[$id]['respuestas'] += $f['respuestas'];
        $res[$id]['inscritos'] += $f['inscritos'];
    }
    foreach ($res as &$r) {
        $r['porcentaje'] = $r['inscritos'] ? round($r['respuestas'] / $r['inscritos'] * 100) : null;
    }
    unset($r);
    return $res;
}

/** URL pública del formulario de evaluación (APP_URL del .env o el host actual). */
function cengi_eval_meta_url($token)
{
    $env = function_exists('cengicursos_env') ? cengicursos_env() : [];
    if (!empty($env['APP_URL'])) {
        $base = rtrim((string) $env['APP_URL'], '/');
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/cengicursos/evaluacion.php?token=' . rawurlencode((string) $token);
}

/**
 * Envía el recordatorio de un enlace: a quienes faltan (si el Aula lo registra) o a todos
 * los inscritos (si las respuestas son anónimas). Queda en Comunicaciones.
 * Devuelve [enviados, fallidos, destinatarios].
 */
function cengi_eval_meta_recordar(PDO $db, $cursoId, $enlaceId, array $curso)
{
    require_once __DIR__ . '/planificacion_helpers.php';
    $resumen = cengi_eval_meta_curso($db, $cursoId);
    $enlace = null;
    foreach ($resumen['enlaces'] as $e) {
        if ((int) $e['id'] === (int) $enlaceId) {
            $enlace = $e;
        }
    }
    if (!$enlace) {
        throw new InvalidArgumentException('El enlace de evaluación no pertenece a este curso.');
    }
    $destinatarios = $enlace['rastreable'] ? $enlace['pendientes'] : $resumen['participantes'];
    $destinatarios = array_values(array_filter($destinatarios, static function ($p) {
        return filter_var(trim((string) $p['correo']), FILTER_VALIDATE_EMAIL);
    }));
    if (!$destinatarios) {
        throw new InvalidArgumentException('No hay participantes con correo válido a quienes enviar el recordatorio.');
    }

    $que = $enlace['instructor'] . ($enlace['modulo'] ? ' (módulo ' . $enlace['modulo'] . ')' : '');
    $mensaje = "Hola {nombre}:\n\n"
        . 'Te invitamos a evaluar a ' . $que . ' en el curso «' . $curso['nombre_cursos'] . "».\n\n"
        . "Tu opinión es anónima y muy importante: con ella mejoramos los cursos, los materiales y la selección de instructores. "
        . 'Necesitamos que al menos el ' . cengi_eval_meta_porcentaje($db) . " % de los participantes responda; te toma menos de 3 minutos.\n\n"
        . 'Responde aquí: ' . cengi_eval_meta_url($enlace['token']) . "\n"
        . ($enlace['rastreable'] ? "(Ingresa con tu usuario del Aula virtual.)\n" : '')
        . "\nSi ya respondiste, muchas gracias y puedes ignorar este mensaje.";

    [, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, [
        'categoria' => 'Evaluación del instructor',
        'origen' => 'manual',
        'referencia' => 'eval-instructor-' . (int) $enlace['id'],
        'asunto' => 'Evalúa a tu instructor · ' . $curso['nombre_cursos'],
        'mensaje' => $mensaje,
    ], $destinatarios);
    return [$enviados, $fallidos, count($destinatarios)];
}
