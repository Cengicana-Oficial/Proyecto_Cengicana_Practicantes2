<?php
/**
 * Banco de preguntas y evaluaciones del aula (Gestión del aula → Tareas y evaluaciones).
 * Esquema: migrations/20261111_aula_banco_preguntas.sql
 *   aula_preguntas.curso_modulo_id  módulo del curso al que pertenece la pregunta.
 *   aula_preguntas.correctas        JSON con los índices correctos de las preguntas "multiple".
 *   aula_evaluaciones.calificacion  'mayor' (nota más alta) o 'ultimo' (último intento).
 *   aula_preguntas.puntos / aula_evaluacion_preguntas.puntos  valor de la pregunta (banco / evaluación).
 *   aula_evaluaciones.nota_visible  'manual' (el docente publica), 'entrega' o 'cierre' (autocalificada).
 *   aula_evaluaciones.retro, retro_momento, retro_desde  retroalimentación al estudiante y desde cuándo.
 * Las funciones de cálculo y validación no requieren base de datos (tests/aula_test.php).
 */

const AULA_TIPOS_PREGUNTA = [
    'seleccion' => 'Selección múltiple · una respuesta correcta',
    'multiple' => 'Selección múltiple · varias respuestas correctas',
    'abierta' => 'Pregunta abierta',
    'tarea' => 'Tarea con entrega PDF',
];
const AULA_TIPOS_CORTOS = ['seleccion' => 'Una correcta', 'multiple' => 'Varias correctas', 'abierta' => 'Abierta', 'tarea' => 'Tarea'];
const AULA_MAX_OPCIONES = 10;
const AULA_LETRAS = 'ABCDEFGHIJ';

/** ¿Está aplicada la migración del banco (módulo, varias correctas y método de calificación)? */
function aula_banco_ready(PDO $db) {
    static $ok = null; if ($ok === null) $ok = new WeakMap();
    if (isset($ok[$db])) return $ok[$db];
    try {
        $db->query('SELECT curso_modulo_id, correctas, puntos FROM aula_preguntas LIMIT 0');
        $db->query('SELECT puntos FROM aula_evaluacion_preguntas LIMIT 0');
        $db->query('SELECT calificacion, nota_visible, retro, retro_momento, retro_desde FROM aula_evaluaciones LIMIT 0');
        return $ok[$db] = true;
    } catch (PDOException $e) { return $ok[$db] = false; }
}

/** Valor de una pregunta en puntos (1 si no se definió). */
function aula_valor_pregunta(array $p) {
    $v = isset($p['puntos']) && is_numeric($p['puntos']) ? (float) $p['puntos'] : 1.0;
    return $v > 0 ? $v : 1.0;
}

/** Puntos con formato corto: 1, 2.5. */
function aula_pts($v) { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); }

/** Valor enviado por formulario (0.01 a 1000 puntos). */
function aula_valor_valido($v, $default = 1.0) {
    $v = str_replace(',', '.', trim((string) $v));
    if ($v === '') return (float) $default;
    if (!is_numeric($v) || (float) $v < 0.01 || (float) $v > 1000) throw new InvalidArgumentException('El valor de cada pregunta debe estar entre 0.01 y 1000 puntos.');
    return round((float) $v, 2);
}

/**
 * ¿El estudiante ya puede ver su nota de esta evaluación? (también decide si alimenta Seguimiento)
 * manual: cuando el docente marca "Publicar notas"; entrega: al entregar; cierre: al cerrar la evaluación.
 */
function aula_nota_visible(array $e, $now = null) {
    $now = $now ?: date('Y-m-d H:i:s');
    if (!empty($e['publicar'])) return true;
    $modo = $e['nota_visible'] ?? 'manual';
    return $modo === 'entrega' || ($modo === 'cierre' && $now >= $e['cierra']);
}

/** ¿El estudiante ya puede ver la retroalimentación (qué acertó y, si se eligió, las respuestas correctas)? */
function aula_retro_visible(array $e, $now = null) {
    $now = $now ?: date('Y-m-d H:i:s');
    if (($e['retro'] ?? 'acierto') === 'no') return false;
    switch ($e['retro_momento'] ?? 'nota') {
        case 'cierre': return $now >= $e['cierra'];
        case 'fecha': return !empty($e['retro_desde']) && $now >= $e['retro_desde'];
        default: return aula_nota_visible($e, $now);
    }
}

/** Texto corto del modo de calificación para las listas. */
function aula_resultado_texto(array $e) {
    $modo = $e['nota_visible'] ?? 'manual';
    if ($modo === 'entrega') return 'Autocalificada · nota al entregar';
    if ($modo === 'cierre') return 'Autocalificada · nota al cerrar';
    return !empty($e['publicar']) ? 'Notas publicadas' : 'Por publicar';
}

/** Configuración de resultados enviada por formulario. */
function aula_resultado_post(array $in) {
    $modo = in_array($in['nota_visible'] ?? '', ['manual', 'entrega', 'cierre'], true) ? $in['nota_visible'] : 'manual';
    $retro = in_array($in['retro'] ?? '', ['no', 'acierto', 'correctas'], true) ? $in['retro'] : 'acierto';
    $momento = in_array($in['retro_momento'] ?? '', ['nota', 'cierre', 'fecha'], true) ? $in['retro_momento'] : 'nota';
    $desde = null;
    if ($retro !== 'no' && $momento === 'fecha') {
        if (trim((string) ($in['retro_desde'] ?? '')) === '') throw new InvalidArgumentException('Indica desde qué fecha se muestra la retroalimentación.');
        $desde = aula_datetime($in['retro_desde']);
    }
    return [$modo, $retro, $momento, $desde];
}

/** Bloque "Calificación y retroalimentación" de los formularios de evaluación. */
function aula_resultado_campos(array $e = []) {
    $modo = $e['nota_visible'] ?? 'entrega'; $retro = $e['retro'] ?? 'acierto'; $momento = $e['retro_momento'] ?? 'nota';
    $desde = !empty($e['retro_desde']) ? str_replace(' ', 'T', substr($e['retro_desde'], 0, 16)) : '';
    $sel = static function ($name, array $opts, $actual, $attr = '') {
        $h = '<select name="' . $name . '"' . $attr . '>';
        foreach ($opts as $k => $t) $h .= '<option value="' . $k . '"' . ($actual === $k ? ' selected' : '') . '>' . aula_h($t) . '</option>';
        return $h . '</select>';
    };
    return '<fieldset class="aula-question aula-resultados" data-resultados><legend>Calificación y retroalimentación</legend>'
        . '<p class="aula-muted">Las preguntas de selección se califican solas. Las abiertas y tareas esperan la revisión docente; la nota se completa al revisarlas.</p>'
        . '<div class="aula-courses">'
        . '<label>¿Cuándo ve el estudiante su nota?' . $sel('nota_visible', ['entrega' => 'Autocalificada: al entregar', 'cierre' => 'Autocalificada: al cerrar la evaluación', 'manual' => 'Manual: cuando el docente publique las notas'], $modo, ' data-nota-visible') . '<small>La nota pasa a Seguimiento en ese mismo momento.</small></label>'
        . '<label>Retroalimentación al estudiante' . $sel('retro', ['no' => 'No mostrar retroalimentación', 'acierto' => 'Mostrar qué respondió bien o mal', 'correctas' => 'Mostrar además las respuestas correctas'], $retro, ' data-retro') . '</label>'
        . '<label data-retro-momento-campo>¿Cuándo se muestra?' . $sel('retro_momento', ['nota' => 'Junto con la nota', 'cierre' => 'Al cerrar la evaluación', 'fecha' => 'Desde una fecha'], $momento, ' data-retro-momento') . '<small>Mostrarla al cierre evita que se compartan las respuestas mientras la evaluación sigue abierta.</small></label>'
        . '<label data-retro-fecha-campo>Retroalimentación desde (Guatemala)<input type="datetime-local" name="retro_desde" value="' . aula_h($desde) . '"></label>'
        . '</div></fieldset>';
}

/** Opciones escritas una por línea (se omiten las vacías). */
function aula_opciones_texto($raw) {
    if (is_array($raw)) $lines = $raw; else $lines = preg_split('/\r\n|\n|\r/', (string) $raw);
    return array_values(array_filter(array_map(static function ($v) { return trim((string) $v); }, $lines), static function ($v) { return $v !== ''; }));
}

/**
 * Convierte "2", "1, 3", "A,C" o "b y d" en índices base 0 ordenados y sin repetir.
 * Lanza InvalidArgumentException si alguna referencia no existe entre las $count opciones.
 */
function aula_indices_correctos($raw, $count) {
    $tokens = is_array($raw) ? $raw : aula_tokens_correctos($raw);
    $out = [];
    foreach ($tokens as $t) {
        $t = strtoupper(trim((string) $t));
        if ($t === '') continue;
        if (ctype_digit($t)) $i = (int) $t - 1;
        elseif (strlen($t) === 1 && strpos(AULA_LETRAS, $t) !== false) $i = strpos(AULA_LETRAS, $t);
        else throw new InvalidArgumentException('Respuesta correcta no válida: «' . $t . '». Usa el número (1, 2…) o la letra (A, B…) de la opción.');
        if ($i < 0 || $i >= $count) throw new InvalidArgumentException('La respuesta correcta «' . $t . '» no corresponde a ninguna de las ' . $count . ' opciones.');
        $out[$i] = $i;
    }
    sort($out);
    return array_values($out);
}

/** "1, 3", "A y C" o "b/d" → ["1","3"], ["A","C"], ["b","d"]. */
function aula_tokens_correctos($raw) {
    $raw = preg_replace('/(?<!\pL)y(?!\pL)/iu', ',', trim((string) $raw));
    return array_values(array_filter(preg_split('/[\s,;\/|+]+/u', $raw), static function ($t) { return $t !== ''; }));
}

/** Respuesta guardada de una pregunta "multiple" ("0,2") como lista de índices. */
function aula_respuesta_lista($v) {
    if (is_array($v)) $parts = $v; else $parts = (string) $v === '' ? [] : explode(',', (string) $v);
    $out = [];
    foreach ($parts as $p) { $p = trim((string) $p); if ($p !== '' && ctype_digit($p)) $out[(int) $p] = (int) $p; }
    sort($out);
    return array_values($out);
}

/** Índices correctos de una pregunta (snapshot o fila del banco). */
function aula_correctas_de(array $p) {
    if (($p['tipo'] ?? '') === 'multiple') return aula_respuesta_lista(json_decode((string) ($p['correctas'] ?? '[]'), true) ?: []);
    return isset($p['correcta']) && $p['correcta'] !== null && $p['correcta'] !== '' ? [(int) $p['correcta']] : [];
}

/**
 * Puntaje parcial de una pregunta con varias correctas (0 a 1):
 * (aciertos marcados − incorrectas marcadas) / total de correctas, sin bajar de 0.
 * Ej.: 2 correctas, marca 1 correcta → 0.5; marca 1 correcta y 1 incorrecta → 0.
 */
function aula_puntaje_multiple(array $correctas, array $marcadas) {
    if (!$correctas) return 0.0;
    $ok = count(array_intersect($marcadas, $correctas));
    $mal = count(array_diff($marcadas, $correctas));
    return max(0.0, ($ok - $mal) / count($correctas));
}

/** Duración enviada como cantidad + unidad (minutos u horas); acepta el campo antiguo "minutos". */
function aula_minutos_post(array $in) {
    if (!isset($in['duracion'])) return (int) ($in['minutos'] ?? 0);
    $v = str_replace(',', '.', trim((string) $in['duracion']));
    if (!is_numeric($v)) return 0;
    return (int) round((float) $v * (($in['duracion_unidad'] ?? 'minutos') === 'horas' ? 60 : 1));
}

/** Texto legible de una duración en minutos: "45 min", "2 h", "1 h 30 min". */
function aula_duracion_texto($min) {
    $min = (int) $min;
    if ($min < 60) return $min . ' min';
    $h = intdiv($min, 60); $m = $min % 60;
    return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
}

/** Campo "Duración" con selector de unidad. Las horas admiten fracciones (1.5 h = 90 min). */
function aula_duracion_campo($minutos = 60, $label = 'Tiempo para realizar la prueba') {
    $minutos = max(1, (int) $minutos);
    $horas = $minutos >= 60 && $minutos % 30 === 0;
    $valor = $horas ? rtrim(rtrim(number_format($minutos / 60, 2, '.', ''), '0'), '.') : (string) $minutos;
    return '<label class="aula-duracion">' . aula_h($label)
        . '<span class="aula-duracion-campos"><input type="number" name="duracion" min="0.5" max="10080" step="any" required value="' . aula_h($valor) . '" aria-label="Cantidad">'
        . '<select name="duracion_unidad" aria-label="Unidad"><option value="minutos"' . ($horas ? '' : ' selected') . '>minuto(s)</option><option value="horas"' . ($horas ? ' selected' : '') . '>hora(s)</option></select></span>'
        . '<small>Máximo 7 días (168 horas).</small></label>';
}

/** Selector del método de calificación cuando hay varios intentos. */
function aula_calificacion_campo($actual = 'mayor') {
    $o = ['mayor' => 'Nota más alta de los intentos', 'ultimo' => 'Nota del último intento'];
    $html = '<label>Calificación con varios intentos<select name="calificacion">';
    foreach ($o as $k => $t) $html .= '<option value="' . $k . '"' . ($actual === $k ? ' selected' : '') . '>' . $t . '</option>';
    return $html . '</select><small>Se usa para la nota que pasa a Seguimiento.</small></label>';
}

function aula_calificacion_valida($v) { return in_array($v, ['mayor', 'ultimo'], true) ? $v : 'mayor'; }

/**
 * Valida una pregunta (formulario o fila de la carga masiva) y devuelve los campos a guardar.
 * $modules: módulos del curso (id, nombre). $ext: migración del banco aplicada.
 */
function aula_pregunta_datos(array $in, array $modules, $ext = true) {
    $type = (string) ($in['tipo'] ?? '');
    $text = trim((string) ($in['enunciado'] ?? ''));
    $criteria = trim((string) ($in['criterio'] ?? ''));
    if (!isset(AULA_TIPOS_PREGUNTA[$type])) throw new InvalidArgumentException('Selecciona el tipo de pregunta.');
    if ($type === 'multiple' && !$ext) throw new InvalidArgumentException('Para preguntas con varias respuestas correctas aplica la migración 20261111_aula_banco_preguntas.sql.');
    if ($text === '' || mb_strlen($text) > 10000) throw new InvalidArgumentException('Completa el enunciado de la pregunta (máximo 10000 caracteres).');
    if (mb_strlen($criteria) > 10000) throw new InvalidArgumentException('El criterio admite hasta 10000 caracteres.');
    $moduleId = (int) ($in['curso_modulo_id'] ?? 0);
    if ($moduleId && !in_array($moduleId, array_map('intval', array_column($modules, 'id')), true)) throw new InvalidArgumentException('El módulo seleccionado no pertenece al curso.');
    $options = null; $correct = null; $corrects = null;
    if ($type === 'seleccion' || $type === 'multiple') {
        $options = aula_opciones_texto($in['opciones'] ?? '');
        if (count($options) < 2 || count($options) > AULA_MAX_OPCIONES) throw new InvalidArgumentException('Ingresa de 2 a ' . AULA_MAX_OPCIONES . ' opciones (una por línea).');
        foreach ($options as $o) if (mb_strlen($o) > 1000) throw new InvalidArgumentException('Cada opción admite hasta 1000 caracteres.');
        $idx = aula_indices_correctos($in['correcta'] ?? '', count($options));
        if ($type === 'seleccion') {
            if (count($idx) !== 1) throw new InvalidArgumentException('Indica una sola opción correcta. Si hay varias, usa el tipo «varias respuestas correctas».');
            $correct = $idx[0];
        } else {
            if (count($idx) < 2) throw new InvalidArgumentException('Indica al menos dos opciones correctas (por ejemplo: 1,3). Si solo hay una, usa el tipo «una respuesta correcta».');
            $corrects = $idx;
        }
    }
    return [
        'tipo' => $type,
        'curso_modulo_id' => $moduleId ?: null,
        'tema' => mb_substr(trim((string) ($in['tema'] ?? '')), 0, 160),
        'enunciado' => $text,
        'opciones' => $options ? aula_json($options) : null,
        'correcta' => $correct,
        'correctas' => $corrects ? aula_json($corrects) : null,
        'criterio' => $criteria,
        'puntos' => aula_valor_valido($in['puntos'] ?? ''),
    ];
}

/** Inserta ($id = 0) o actualiza una pregunta del banco. Devuelve su id. */
function aula_pregunta_guardar(PDO $db, $cid, array $d, $ext, $id = 0, $userId = 0) {
    if ($id) {
        if (!aula_rows($db, 'SELECT 1 FROM aula_preguntas WHERE id=? AND curso_id=?', [$id, $cid])) throw new InvalidArgumentException('La pregunta no pertenece al curso.');
        if ($ext) $db->prepare('UPDATE aula_preguntas SET tipo=?,curso_modulo_id=?,tema=?,enunciado=?,opciones=?,correcta=?,correctas=?,criterio=?,puntos=? WHERE id=? AND curso_id=?')
            ->execute([$d['tipo'], $d['curso_modulo_id'], $d['tema'], $d['enunciado'], $d['opciones'], $d['correcta'], $d['correctas'], $d['criterio'], $d['puntos'], $id, $cid]);
        else $db->prepare('UPDATE aula_preguntas SET tipo=?,tema=?,enunciado=?,opciones=?,correcta=?,criterio=? WHERE id=? AND curso_id=?')
            ->execute([$d['tipo'], $d['tema'], $d['enunciado'], $d['opciones'], $d['correcta'], $d['criterio'], $id, $cid]);
        return (int) $id;
    }
    if ($ext) $db->prepare('INSERT INTO aula_preguntas(curso_id,curso_modulo_id,tipo,tema,enunciado,opciones,correcta,correctas,criterio,puntos,creado_por) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $d['curso_modulo_id'], $d['tipo'], $d['tema'], $d['enunciado'], $d['opciones'], $d['correcta'], $d['correctas'], $d['criterio'], $d['puntos'], $userId]);
    else $db->prepare('INSERT INTO aula_preguntas(curso_id,tipo,tema,enunciado,opciones,correcta,criterio,creado_por) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$cid, $d['tipo'], $d['tema'], $d['enunciado'], $d['opciones'], $d['correcta'], $d['criterio'], $userId]);
    return (int) $db->lastInsertId();
}

/**
 * Elimina una pregunta del banco y la retira de las evaluaciones que la usan.
 * Los intentos ya iniciados conservan su copia de la pregunta, así que sus notas no cambian.
 */
function aula_pregunta_eliminar(PDO $db, $cid, $id) {
    if (!aula_rows($db, 'SELECT 1 FROM aula_preguntas WHERE id=? AND curso_id=?', [$id, $cid])) throw new InvalidArgumentException('La pregunta no pertenece al curso.');
    $q = $db->prepare('DELETE FROM aula_evaluacion_preguntas WHERE pregunta_id=?'); $q->execute([$id]);
    $db->prepare('DELETE FROM aula_preguntas WHERE id=? AND curso_id=?')->execute([$id, $cid]);
    return $q->rowCount();
}

/** Ids de preguntas enviados por un formulario, validados contra el curso. */
function aula_preguntas_del_curso(PDO $db, $cid, $selected) {
    if (!is_array($selected) || count($selected) > 500) throw new InvalidArgumentException('Selecciona hasta 500 preguntas.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $selected))));
    if (!$ids) throw new InvalidArgumentException('Selecciona al menos una pregunta del banco.');
    $valid = aula_rows($db, 'SELECT id FROM aula_preguntas WHERE curso_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$cid], $ids));
    if (count($valid) !== count($ids)) throw new RuntimeException('Todas las preguntas deben pertenecer al curso.');
    return $ids;
}

/**
 * Reemplaza las preguntas de una evaluación con su valor en puntos ($valores[id]; si falta, el del banco).
 * Los intentos ya iniciados conservan su copia de preguntas y valores.
 */
function aula_eval_guardar_preguntas(PDO $db, $eid, array $ids, $valores, $ext) {
    $valores = is_array($valores) ? $valores : [];
    $banco = $ext ? array_column(aula_rows($db, 'SELECT id,puntos FROM aula_preguntas WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), 'puntos', 'id') : [];
    $db->prepare('DELETE FROM aula_evaluacion_preguntas WHERE evaluacion_id=?')->execute([$eid]);
    $q = $db->prepare($ext ? 'INSERT INTO aula_evaluacion_preguntas(evaluacion_id,pregunta_id,puntos) VALUES(?,?,?)' : 'INSERT INTO aula_evaluacion_preguntas(evaluacion_id,pregunta_id) VALUES(?,?)');
    $total = 0.0;
    foreach ($ids as $id) {
        $v = aula_valor_valido($valores[$id] ?? '', $banco[$id] ?? 1);
        $total += $v;
        $q->execute($ext ? [$eid, $id, $v] : [$eid, $id]);
    }
    return $total;
}

/** Texto normalizado para comparar encabezados, tipos y módulos ("Opción A" → "OPCION_A"). */
function aula_norm($v) {
    $v = trim((string) $v);
    $v = strtr($v, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    return trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($v)), '_');
}

/** Tipo de pregunta escrito en la plantilla ("Una correcta", "Varias correctas", "Abierta", "Tarea"). */
function aula_tipo_desde_texto($v, $correctCount, $hasOptions) {
    $n = aula_norm($v);
    if ($n === '') return $hasOptions ? ($correctCount > 1 ? 'multiple' : 'seleccion') : 'abierta';
    if (preg_match('/VARIAS|MULTIPLES_CORRECTAS|MULTIPLE$/', $n) && strpos($n, 'UNA') === false) return 'multiple';
    if (preg_match('/UNA|UNICA|SELECCION/', $n)) return 'seleccion';
    if (strpos($n, 'ABIERTA') !== false) return 'abierta';
    if (strpos($n, 'TAREA') !== false) return 'tarea';
    return '';
}

/**
 * Lee la plantilla de carga masiva (filas ya extraídas del Excel/CSV) y valida cada pregunta.
 * Columnas: TIPO, MODULO, TEMA, ENUNCIADO, OPCION_A … OPCION_J, CORRECTA, CRITERIO.
 * Devuelve [preguntas válidas, errores por fila]. No escribe en la base de datos.
 */
function aula_banco_filas(array $rows, array $modules, $ext = true) {
    $header = null; $headerRow = 0;
    foreach ($rows as $n => $r) {
        $names = array_map('aula_norm', $r);
        if (in_array('ENUNCIADO', $names, true) || in_array('PREGUNTA', $names, true)) { $header = $names; $headerRow = $n; break; }
    }
    if ($header === null) throw new InvalidArgumentException('No se encontró la fila de encabezados (debe incluir la columna ENUNCIADO). Descarga la plantilla y úsala como base.');
    $col = static function (array $names) use ($header) { foreach ($names as $name) { $i = array_search($name, $header, true); if ($i !== false) return $i; } return null; };
    $cTipo = $col(['TIPO', 'TIPO_DE_PREGUNTA']); $cMod = $col(['MODULO']); $cTema = $col(['TEMA']);
    $cEnun = $col(['ENUNCIADO', 'PREGUNTA']); $cCorr = $col(['CORRECTA', 'CORRECTAS', 'RESPUESTA_CORRECTA', 'RESPUESTAS_CORRECTAS']);
    $cCrit = $col(['CRITERIO', 'CRITERIO_DE_EVALUACION']); $cPts = $col(['PUNTOS', 'VALOR', 'PUNTEO']);
    $cOpts = [];
    for ($i = 0; $i < AULA_MAX_OPCIONES; $i++) { $l = AULA_LETRAS[$i]; $c = $col(['OPCION_' . $l, 'OPCION' . $l, 'OPCION_' . ($i + 1)]); if ($c !== null) $cOpts[] = $c; }
    $modsByName = []; $modsByOrder = [];
    foreach (array_values($modules) as $k => $m) { $modsByName[aula_norm($m['nombre'])] = (int) $m['id']; $modsByOrder[(string) ($k + 1)] = (int) $m['id']; }
    $cell = static function ($r, $c) { return $c === null ? '' : trim((string) ($r[$c] ?? '')); };
    $ok = []; $errors = [];
    foreach ($rows as $n => $r) {
        if ($n <= $headerRow) continue;
        $text = $cell($r, $cEnun);
        $opts = array_values(array_filter(array_map(static function ($c) use ($r, $cell) { return $cell($r, $c); }, $cOpts), static function ($v) { return $v !== ''; }));
        if ($text === '' && !$opts && $cell($r, $cTipo) === '') continue; // fila vacía
        $line = $n + 1;
        try {
            $corrRaw = $cell($r, $cCorr);
            $countCorr = count(aula_tokens_correctos($corrRaw));
            $type = aula_tipo_desde_texto($cell($r, $cTipo), $countCorr, (bool) $opts);
            if ($type === '') throw new InvalidArgumentException('Tipo «' . $cell($r, $cTipo) . '» no reconocido (usa: Una correcta, Varias correctas, Abierta o Tarea).');
            $modText = $cell($r, $cMod); $moduleId = 0;
            if ($modText !== '') {
                $moduleId = $modsByName[aula_norm($modText)] ?? ($modsByOrder[$modText] ?? 0);
                if (!$moduleId) throw new InvalidArgumentException($modules ? 'El módulo «' . $modText . '» no existe en el curso.' : 'El curso no tiene módulos: deja vacía la columna MODULO.');
            }
            $ok[] = aula_pregunta_datos(['tipo' => $type, 'curso_modulo_id' => $moduleId, 'tema' => $cell($r, $cTema), 'enunciado' => $text, 'opciones' => $opts, 'correcta' => $corrRaw, 'criterio' => $cell($r, $cCrit), 'puntos' => $cell($r, $cPts)], $modules, $ext);
        } catch (InvalidArgumentException $e) {
            $errors[] = 'Fila ' . $line . ': ' . $e->getMessage();
        }
    }
    return [$ok, $errors];
}

/** Filas de un archivo .xlsx, .xls o .csv subido (máximo 2 MB y 1000 filas). */
function aula_banco_leer_archivo($file) {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Selecciona el archivo con las preguntas.');
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('No se pudo cargar el archivo (máximo 2 MB).');
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) throw new InvalidArgumentException('El archivo debe ser Excel (.xlsx) o CSV.');
    require_once __DIR__ . '/vendor/autoload.php';
    try {
        if ($ext === 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
            $sample = (string) file_get_contents($file['tmp_name'], false, null, 0, 4096);
            $reader->setDelimiter(substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',');
            $reader->setInputEncoding(mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252');
        } else {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
            $reader->setReadDataOnly(true);
        }
        $book = $reader->load($file['tmp_name']);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('No se pudo leer el archivo. Verifica que sea la plantilla de preguntas en Excel o CSV.');
    }
    $sheet = $book->getSheetByName('Preguntas') ?: $book->getSheet(0);
    $rows = $sheet->toArray('', true, false, false);
    $book->disconnectWorksheets();
    if (count($rows) > 1001) throw new InvalidArgumentException('La carga admite hasta 1000 preguntas por archivo.');
    return $rows;
}
