<?php
/**
 * Modalidad asincrónica en la misma aula del curso (migrations/20261112_aula_asincronica.sql).
 *
 * - Cada inscripción puede marcarse como sincrónica (por defecto) o asincrónica, con un plazo opcional.
 * - El estudiante asincrónico ve las grabaciones de las sesiones. Se mide lo reproducido en YouTube, Vimeo y MP4
 *   (tramos de 5 s, sin contar los saltos); en Google Drive u otros enlaces, el tiempo con el reproductor abierto.
 * - Con al menos el 70 % puede marcar la grabación como vista. Al ver todas las grabaciones de una sesión, la sesión
 *   cuenta como asistida (origen "grabacion"), así que el porcentaje de Seguimiento y la regla del diploma no cambian.
 * - Sus evaluaciones no dependen de las fechas del grupo sincrónico: se habilitan al terminar las grabaciones de la
 *   sesión, del módulo o del curso al que pertenece la evaluación (y dentro de su plazo, si tiene uno).
 */

const AULA_VIDEO_MINIMO = 70;          // % de la grabación para poder marcarla como vista
const AULA_VIDEO_TRAMO = 5;            // segundos por tramo medido
const AULA_VIDEO_SIN_DURACION = 900;   // segundos de reproducción exigidos si no se conoce la duración (15 min)

function aula_async_ready(PDO $db) {
    static $ok = null; if ($ok === null) $ok = new WeakMap();
    if (isset($ok[$db])) return $ok[$db];
    try {
        $db->query('SELECT asignacion_id FROM aula_modalidad LIMIT 0');
        $db->query('SELECT segmentos FROM aula_video_avance LIMIT 0');
        $db->query('SELECT duracion_min FROM aula_recursos LIMIT 0');
        return $ok[$db] = true;
    } catch (PDOException $e) { return $ok[$db] = false; }
}

/** Modalidad de una inscripción: ['modalidad' => 'sincronica'|'asincronica', 'plazo' => 'Y-m-d'|null]. */
function aula_modalidad(PDO $db, $asignacionId) {
    if (!$asignacionId || !aula_async_ready($db)) return ['modalidad' => 'sincronica', 'plazo' => null];
    return aula_rows($db, 'SELECT modalidad, plazo FROM aula_modalidad WHERE asignacion_id=?', [(int) $asignacionId])[0]
        ?? ['modalidad' => 'sincronica', 'plazo' => null];
}
function aula_es_async(PDO $db, $asignacionId) { return aula_modalidad($db, $asignacionId)['modalidad'] === 'asincronica'; }

/** Modalidades del curso: [asignacion_id => fila]. */
function aula_modalidades_curso(PDO $db, $courseId) {
    if (!aula_async_ready($db)) return [];
    $m = [];
    foreach (aula_rows($db, 'SELECT m.* FROM aula_modalidad m JOIN asignaciones a ON a.id=m.asignacion_id WHERE a.cursos_id=?', [(int) $courseId]) as $r) $m[(int) $r['asignacion_id']] = $r;
    return $m;
}

/** Cambia la modalidad (y el plazo) de varias inscripciones del curso. Devuelve cuántas se actualizaron. */
function aula_modalidad_guardar(PDO $db, $courseId, array $ids, $modalidad, $plazo, $userId) {
    if (!in_array($modalidad, ['sincronica', 'asincronica'], true)) throw new InvalidArgumentException('Elige la modalidad.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw new InvalidArgumentException('Marca al menos un participante.');
    $plazo = trim((string) $plazo);
    if ($plazo !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $plazo);
        if (!$d || $d->format('Y-m-d') !== $plazo) throw new InvalidArgumentException('La fecha límite no es válida.');
    }
    $validos = array_map('intval', array_column(aula_rows($db, 'SELECT id FROM asignaciones WHERE cursos_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([(int) $courseId], $ids)), 'id'));
    if (count($validos) !== count($ids)) throw new InvalidArgumentException('Todos los participantes deben pertenecer al curso.');
    $q = $db->prepare('INSERT INTO aula_modalidad(asignacion_id,modalidad,plazo,actualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE modalidad=VALUES(modalidad),plazo=VALUES(plazo),actualizado_por=VALUES(actualizado_por)');
    foreach ($validos as $id) $q->execute([$id, $modalidad, $modalidad === 'asincronica' && $plazo !== '' ? $plazo : null, $userId ?: null]);
    // La asistencia se recalcula con la regla de la nueva modalidad.
    foreach ($validos as $id) aula_attendance_sync($db, $id, $courseId);
    return count($validos);
}

/** Fuente de una grabación: 'mp4', 'youtube', 'vimeo' (medibles) u 'otro' (Drive y demás: se mide el tiempo). */
function aula_video_fuente(array $r) {
    if (!empty($r['archivo_id']) && ($r['mime'] ?? '') === 'video/mp4') return 'mp4';
    $v = aula_video_embed($r['url'] ?? '');
    if (!$v) return 'otro';
    return ['YouTube' => 'youtube', 'Vimeo' => 'vimeo'][$v['fuente']] ?? 'otro';
}

/** Grabaciones visibles de las sesiones del curso (las que cuentan para la modalidad asincrónica). */
function aula_videos_sesiones(PDO $db, $courseId) {
    $out = [];
    foreach (aula_rows($db, 'SELECT r.*,s.tema,s.fecha,s.modulo,c.url,f.mime FROM aula_recursos r JOIN curso_sesiones s ON s.id=r.sesion_id
        LEFT JOIN curso_contenidos c ON c.id=r.contenido_id LEFT JOIN aula_archivos f ON f.id=r.archivo_id
        WHERE r.curso_id=? AND r.tipo=\'video\' AND s.estado<>\'cancelada\' ORDER BY s.fecha,s.hora_inicio,r.orden,r.id', [(int) $courseId]) as $r) {
        if (!aula_recurso_visible($r)) continue;
        $r['fuente'] = aula_video_fuente($r);
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/** Avance del estudiante: [recurso_id => fila]. */
function aula_video_avances(PDO $db, $asignacionId) {
    if (!aula_async_ready($db)) return [];
    return array_column(aula_rows($db, 'SELECT * FROM aula_video_avance WHERE asignacion_id=?', [(int) $asignacionId]), null, 'recurso_id');
}

/** Tramos reproducidos en un mapa de bits. */
function aula_bits_contar($bin, $limite) {
    $n = 0;
    for ($i = 0, $len = strlen($bin); $i < $len; $i++) {
        $byte = ord($bin[$i]);
        for ($b = 0; $b < 8; $b++) if (($byte >> $b) & 1 && ($i * 8 + $b) < $limite) $n++;
    }
    return $n;
}

/** Porcentaje visto con los datos guardados de una grabación. */
function aula_video_porcentaje(array $video, array $av) {
    if ($video['fuente'] !== 'otro') {
        $tramos = (int) ceil(max(1, (int) $av['duracion']) / AULA_VIDEO_TRAMO);
        if (!(int) $av['duracion']) return 0.0;
        return min(100.0, round(100 * aula_bits_contar(base64_decode((string) $av['segmentos']) ?: '', $tramos) / $tramos, 2));
    }
    // Sin medición: tiempo con el reproductor abierto frente a la duración indicada por coordinación (o 15 min).
    $dur = (int) $video['duracion_min'] * 60;
    if ($dur > 0) return min(100.0, round(100 * (int) $av['segundos'] / $dur, 2));
    return min(100.0, round(AULA_VIDEO_MINIMO * (int) $av['segundos'] / AULA_VIDEO_SIN_DURACION, 2));
}

/** Grabación de la modalidad asincrónica disponible para el estudiante (o excepción). */
function aula_video_del_estudiante(PDO $db, array $assignment, $recursoId) {
    if (!aula_async_ready($db) || !aula_es_async($db, $assignment['id'])) throw new RuntimeException('El seguimiento de grabaciones es para la modalidad asincrónica.');
    $videos = aula_videos_sesiones($db, $assignment['cursos_id']);
    if (!isset($videos[(int) $recursoId])) throw new InvalidArgumentException('La grabación no está disponible.');
    return $videos[(int) $recursoId];
}

/**
 * Guarda el avance enviado por el reproductor (js/aula_avance.js).
 * Medible: 'segmentos' (base64, se combina con lo ya visto, nunca se pierde) y 'duracion' en segundos.
 * Sin medición: 'segundos' reproducidos desde el último envío (limitado al tiempo real transcurrido).
 */
function aula_video_registrar(PDO $db, array $assignment, $recursoId, array $in) {
    $video = aula_video_del_estudiante($db, $assignment, $recursoId);
    $db->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        $av = aula_rows($db, 'SELECT * FROM aula_video_avance WHERE asignacion_id=? AND recurso_id=? FOR UPDATE', [$assignment['id'], $recursoId])[0]
            ?? ['segmentos' => '', 'segundos' => 0, 'duracion' => 0, 'visto_en' => null, 'actualizado' => null];
        if ($video['fuente'] !== 'otro') {
            $dur = (int) ($in['duracion'] ?? 0);
            if ($dur < 1 || $dur > 6 * 3600) throw new InvalidArgumentException('Duración no válida.');
            $nuevo = base64_decode((string) ($in['segmentos'] ?? ''), true);
            if ($nuevo === false || strlen($nuevo) > (int) ceil($dur / AULA_VIDEO_TRAMO / 8) + 1) throw new InvalidArgumentException('Avance no válido.');
            $previo = base64_decode((string) $av['segmentos']) ?: '';
            $len = max(strlen($nuevo), strlen($previo)); $mezcla = '';
            for ($i = 0; $i < $len; $i++) $mezcla .= chr(ord($nuevo[$i] ?? "\0") | ord($previo[$i] ?? "\0"));
            $av['segmentos'] = base64_encode($mezcla); $av['duracion'] = max((int) $av['duracion'], $dur);
        } else {
            // No se acepta más tiempo del que realmente pasó desde el envío anterior.
            $transcurrido = $av['actualizado'] ? max(0, time() - strtotime($av['actualizado'])) + 5 : 120;
            $av['segundos'] = (int) $av['segundos'] + max(0, min((int) ($in['segundos'] ?? 0), 120, $transcurrido));
        }
        $pct = aula_video_porcentaje($video, $av);
        $db->prepare('INSERT INTO aula_video_avance(asignacion_id,recurso_id,segmentos,segundos,duracion,porcentaje,actualizado) VALUES(?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE segmentos=VALUES(segmentos),segundos=VALUES(segundos),duracion=VALUES(duracion),porcentaje=GREATEST(porcentaje,VALUES(porcentaje)),actualizado=VALUES(actualizado)')
            ->execute([$assignment['id'], $recursoId, $av['segmentos'] ?: null, (int) $av['segundos'], (int) $av['duracion'], $pct, $now]);
        $db->commit();
        $pct = max($pct, (float) ($av['porcentaje'] ?? 0));
        return ['porcentaje' => $pct, 'puede_marcar' => $pct >= AULA_VIDEO_MINIMO, 'visto' => !empty($av['visto_en'])];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/**
 * "Ya lo vi": marca la grabación (requiere el 70 %). Si con ella se completan las grabaciones de la sesión,
 * la sesión queda como asistida por grabación y se recalcula el porcentaje en Seguimiento.
 */
function aula_video_marcar_visto(PDO $db, array $assignment, $recursoId) {
    $video = aula_video_del_estudiante($db, $assignment, $recursoId);
    $plazo = aula_modalidad($db, $assignment['id'])['plazo'];
    if ($plazo && date('Y-m-d') > $plazo) throw new RuntimeException('Tu plazo para completar el curso terminó el ' . date('d/m/Y', strtotime($plazo)) . '.');
    $db->beginTransaction();
    try {
        $av = aula_rows($db, 'SELECT * FROM aula_video_avance WHERE asignacion_id=? AND recurso_id=? FOR UPDATE', [$assignment['id'], $recursoId])[0] ?? null;
        if (!$av || (float) $av['porcentaje'] < AULA_VIDEO_MINIMO) throw new RuntimeException('Mira al menos el ' . AULA_VIDEO_MINIMO . ' % de la grabación para marcarla como vista.');
        if (!$av['visto_en']) $db->prepare('UPDATE aula_video_avance SET visto_en=? WHERE asignacion_id=? AND recurso_id=?')->execute([date('Y-m-d H:i:s'), $assignment['id'], $recursoId]);
        $sesionId = (int) $video['sesion_id'];
        $deSesion = array_keys(array_filter(aula_videos_sesiones($db, $assignment['cursos_id']), static function ($v) use ($sesionId) { return (int) $v['sesion_id'] === $sesionId; }));
        $vistos = (int) aula_rows($db, 'SELECT COUNT(*) n FROM aula_video_avance WHERE asignacion_id=? AND visto_en IS NOT NULL AND recurso_id IN (' . implode(',', array_map('intval', $deSesion)) . ')', [$assignment['id']])[0]['n'];
        $completa = $vistos >= count($deSesion);
        if ($completa) {
            // No cambia una asistencia en vivo ya registrada; si estaba ausente, pasa a presente por grabación.
            $db->prepare("INSERT INTO aula_asistencias(sesion_id,asignacion_id,registrado,presente,origen,validada,motivo) VALUES(?,?,?,1,'grabacion',1,'Grabación vista (modalidad asincrónica)')
                ON DUPLICATE KEY UPDATE origen=IF(presente=1,origen,'grabacion'),motivo=IF(presente=1,motivo,'Grabación vista (modalidad asincrónica)'),registrado=IF(presente=1,registrado,VALUES(registrado)),validada=1,presente=1")
                ->execute([$sesionId, $assignment['id'], date('Y-m-d H:i:s')]);
            aula_attendance_sync($db, $assignment['id'], $assignment['cursos_id']);
        }
        $db->commit();
        return $completa ? 'Grabación vista. Completaste la sesión «' . $video['tema'] . '»: ya cuenta en tu asistencia.' : 'Grabación vista. Te faltan ' . (count($deSesion) - $vistos) . ' grabación(es) de esta sesión.';
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/**
 * Estado de una evaluación para un estudiante asincrónico: se habilita al ver las grabaciones que le corresponden
 * (las de su sesión; si no tiene sesión, las de su módulo; si tampoco, las de todo el curso).
 * Devuelve ['ok' => bool, 'vistas' => n, 'total' => n, 'motivo' => texto].
 */
function aula_async_eval_estado(array $e, array $videos, array $avances, array $modules, array $sessions, $plazo, $hoy = null) {
    $hoy = $hoy ?: date('Y-m-d');
    $modNombre = '';
    foreach ($modules as $m) if ((int) $m['id'] === (int) ($e['curso_modulo_id'] ?? 0)) $modNombre = (string) $m['nombre'];
    $pertenece = static function ($sesion) use ($e, $modNombre) {
        if (!empty($e['sesion_id'])) return (int) $sesion['id'] === (int) $e['sesion_id'];
        if ($modNombre !== '') return (string) ($sesion['modulo'] ?? '') === $modNombre;
        return true;
    };
    $sesionesIds = [];
    foreach ($sessions as $s) if (($s['estado'] ?? '') !== 'cancelada' && $pertenece($s)) $sesionesIds[(int) $s['id']] = $s;
    $req = array_filter($videos, static function ($v) use ($sesionesIds) { return isset($sesionesIds[(int) $v['sesion_id']]); });
    $vistas = count(array_filter($req, static function ($v) use ($avances) { return !empty($avances[(int) $v['id']]['visto_en']); }));
    $base = ['vistas' => $vistas, 'total' => count($req)];
    if ($plazo && $hoy > $plazo) return $base + ['ok' => false, 'motivo' => 'Tu plazo terminó el ' . date('d/m/Y', strtotime($plazo)) . '.'];
    $pendientes = array_filter($sesionesIds, static function ($s) use ($hoy) { return ($s['estado'] ?? '') !== 'impartida' && $s['fecha'] >= $hoy; });
    if ($pendientes) return $base + ['ok' => false, 'motivo' => 'Se habilita cuando terminen las sesiones' . ($modNombre !== '' ? ' del módulo' : '') . ' y veas sus grabaciones.'];
    if (!$req) return $base + ['ok' => false, 'motivo' => 'Aún no hay grabaciones publicadas para esta evaluación.'];
    if ($vistas < count($req)) return $base + ['ok' => false, 'motivo' => 'Se habilita al ver las grabaciones' . ($modNombre !== '' ? ' del módulo «' . $modNombre . '»' : (!empty($e['sesion_id']) ? ' de la sesión' : ' del curso')) . ': llevas ' . $vistas . ' de ' . count($req) . '.'];
    return $base + ['ok' => true, 'motivo' => ''];
}

/** Datos que necesita aula_async_eval_estado() para un estudiante (se cargan una vez por página). */
function aula_async_contexto(PDO $db, array $assignment) {
    static $cache = [];
    $k = (int) $assignment['id'];
    if (!isset($cache[$k])) {
        $cid = (int) $assignment['cursos_id'];
        $cache[$k] = [
            'videos' => aula_videos_sesiones($db, $cid),
            'avances' => aula_video_avances($db, $k),
            'modules' => aula_rows($db, 'SELECT id,nombre FROM curso_modulos WHERE curso_id=? ORDER BY orden,id', [$cid]),
            'sessions' => aula_rows($db, 'SELECT s.* FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? ORDER BY s.fecha,s.hora_inicio', [$cid]),
            'plazo' => aula_modalidad($db, $k)['plazo'],
        ];
    }
    return $cache[$k];
}
function aula_async_eval(PDO $db, array $assignment, array $e) {
    $c = aula_async_contexto($db, $assignment);
    return aula_async_eval_estado($e, $c['videos'], $c['avances'], $c['modules'], $c['sessions'], $c['plazo']);
}

/** Resumen de avance de un estudiante asincrónico: grabaciones vistas, total y porcentaje. */
function aula_async_resumen(array $videos, array $avances) {
    $vistas = count(array_filter($videos, static function ($v) use ($avances) { return !empty($avances[(int) $v['id']]['visto_en']); }));
    return ['vistas' => $vistas, 'total' => count($videos), 'porcentaje' => $videos ? round(100 * $vistas / count($videos)) : 0];
}
