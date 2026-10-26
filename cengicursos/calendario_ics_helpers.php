<?php
/**
 * Invitaciones de calendario (iCalendar, RFC 5545) de las sesiones para el instructor y
 * el soporte técnico. Outlook, Teams, Gmail y Apple Calendar las muestran como reunión con
 * "Aceptar / Rechazar". Cada sesión tiene un UID fijo: un cambio de fecha, horario o lugar
 * actualiza el mismo evento y una cancelación (o un cambio de instructor) lo quita del
 * calendario de quien ya no la tiene.
 *
 * Uso: tomar una instantánea antes de modificar (cengi_ics_instantanea), confirmar la
 * transacción y luego llamar a cengi_ics_sincronizar(). Cada envío queda en
 * "comunicaciones" con referencia ics:sesion:<id>; así se sabe a quién se invitó.
 */
require_once __DIR__ . '/planificacion_helpers.php';

const CENGI_ICS_ZONA = 'America/Guatemala';
const CENGI_ICS_CAT_INVITACION = 'Invitación de calendario';
const CENGI_ICS_CAT_CANCELACION = 'Cancelación de calendario';
// Campos que, al cambiar, obligan a actualizar la invitación ya enviada.
const CENGI_ICS_CAMPOS = ['planificacion_id', 'tema', 'modulo', 'fecha', 'hora_inicio', 'hora_fin', 'modalidad', 'lugar', 'instructor_id', 'soporte_id'];

/** Sesiones con su curso y el correo de instructor y soporte, indexadas por id. */
function cengi_ics_sesiones(PDO $db, array $ids = [], $planId = 0)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids && !$planId) {
        return [];
    }
    $donde = $planId ? 's.planificacion_id = ?' : 's.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $stmt = $db->prepare("SELECT s.*, p.nombre AS plan_nombre, p.estado AS plan_estado, c.codigo_curso,
            i.nombre AS instructor_nombre, i.correo AS instructor_correo, st.nombre AS soporte_nombre, st.correo AS soporte_correo
        FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
        LEFT JOIN cursos c ON c.id = p.curso_id
        LEFT JOIN instructores i ON i.id = s.instructor_id LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id
        WHERE $donde ORDER BY s.fecha, s.hora_inicio");
    $stmt->execute($planId ? [(int) $planId] : $ids);
    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $filas[(int) $s['id']] = $s;
    }
    return $filas;
}

/** Estado de las sesiones antes de un cambio (por ids o todas las de un plan). */
function cengi_ics_instantanea(PDO $db, array $ids = [], $planId = 0)
{
    try {
        return cengi_ics_sesiones($db, $ids, $planId);
    } catch (Throwable $e) {
        error_log('Calendario: no se pudo leer la instantánea: ' . $e->getMessage());
        return [];
    }
}

/** Se invita o actualiza solo una sesión programada, futura y con el curso vigente. */
function cengi_ics_vigente(?array $s)
{
    return cengi_ics_en_calendario($s) && $s['estado'] === 'programada' && $s['fecha'] >= cengi_pp_hoy();
}

/** La sesión debe seguir en el calendario de su gente (una impartida o pasada no se cancela). */
function cengi_ics_en_calendario(?array $s)
{
    return $s && $s['estado'] !== 'cancelada' && ($s['plan_estado'] ?? '') !== 'cancelado';
}

/** [correo => [nombre, rol]] de instructor y soporte con correo válido. */
function cengi_ics_personas(array $s)
{
    $personas = [];
    foreach (['instructor' => 'Instructor', 'soporte' => 'Soporte técnico'] as $clave => $rol) {
        $correo = mb_strtolower(trim((string) ($s[$clave . '_correo'] ?? '')), 'UTF-8');
        if ($s[$clave . '_id'] && filter_var($correo, FILTER_VALIDATE_EMAIL) && !isset($personas[$correo])) {
            $personas[$correo] = [(string) $s[$clave . '_nombre'], $rol];
        }
    }
    return $personas;
}

/** Cuántas personas asignadas no tienen correo válido (no se les puede invitar). */
function cengi_ics_sin_correo(array $s)
{
    $faltan = 0;
    foreach (['instructor', 'soporte'] as $clave) {
        if ($s[$clave . '_id'] && !filter_var(trim((string) ($s[$clave . '_correo'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            $faltan++;
        }
    }
    return $faltan;
}

/** [sesion_id => [correo => true]] de quienes tienen hoy la invitación vigente (la última fue invitación, no cancelación). */
function cengi_ics_invitados(PDO $db, array $ids)
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $stmt = $db->prepare("SELECT c.referencia, c.categoria, LOWER(d.correo) AS correo
        FROM comunicaciones c INNER JOIN comunicacion_destinatarios d ON d.comunicacion_id = c.id
        WHERE c.referencia IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND d.estado = 'enviado'
        ORDER BY c.id");
    $stmt->execute(array_map(static function ($id) {
        return 'ics:sesion:' . $id;
    }, $ids));
    $invitados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $id = (int) substr($f['referencia'], 11);
        if ($f['categoria'] === CENGI_ICS_CAT_INVITACION) {
            $invitados[$id][$f['correo']] = true;
        } else {
            unset($invitados[$id][$f['correo']]);
        }
    }
    return $invitados;
}

// ---------------------------------------------------------------- formato iCalendar

function cengi_ics_texto($texto)
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\\;', '\\,', '\\n', '\\n', ''], (string) $texto);
}

/**
 * Pliega una línea a 75 octetos sin partir caracteres UTF-8 ni secuencias de escape
 * (\n, \, ...): algunos lectores de Outlook no las reconstruyen si quedan en dos líneas.
 */
function cengi_ics_linea($linea)
{
    $salida = '';
    $actual = '';
    preg_match_all('/\\\\.|./us', $linea, $unidades);
    foreach ($unidades[0] as $caracter) {
        if (strlen($actual) + strlen($caracter) > ($salida === '' ? 75 : 74)) {
            $salida .= ($salida === '' ? '' : "\r\n ") . $actual;
            $actual = '';
        }
        $actual .= $caracter;
    }
    return $salida . ($salida === '' ? '' : "\r\n ") . $actual . "\r\n";
}

function cengi_ics_utc($fecha, $hora)
{
    $local = new DateTimeImmutable($fecha . ' ' . substr((string) $hora, 0, 8), new DateTimeZone(CENGI_ICS_ZONA));
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

function cengi_ics_organizador()
{
    $env = function_exists('cengicursos_env') ? cengicursos_env() : [];
    return [(string) ($env['MAIL_FROM_NAME'] ?? 'CENGICAÑA'), (string) ($env['MAIL_FROM_ADDRESS'] ?? '')];
}

function cengi_ics_resumen(array $s)
{
    return $s['plan_nombre'] . ' — ' . $s['tema'];
}

function cengi_ics_descripcion(array $s)
{
    return implode("\n", array_filter([
        'Curso: ' . $s['plan_nombre'] . ($s['codigo_curso'] ? ' (' . $s['codigo_curso'] . ')' : ''),
        $s['modulo'] !== '' && $s['modulo'] !== 'Sesión única' ? 'Módulo: ' . $s['modulo'] : null,
        'Tema: ' . $s['tema'],
        'Modalidad: ' . $s['modalidad'],
        'Lugar o enlace: ' . $s['lugar'],
        'Instructor: ' . ($s['instructor_nombre'] ?: 'Por asignar'),
        $s['soporte_nombre'] ? 'Soporte técnico: ' . $s['soporte_nombre'] : null,
        '',
        'Programado desde Cengicursos — Coordinación de Capacitación, CENGICAÑA.',
    ], static function ($l) {
        return $l !== null;
    }));
}

/**
 * Un VCALENDAR con un evento por sesión.
 * $metodo: REQUEST (invitación), CANCEL o PUBLISH (descarga para importar).
 * $asistentes: [correo => [nombre, rol]] (solo REQUEST/CANCEL).
 */
function cengi_ics_calendario(array $sesiones, $metodo, array $asistentes = [], $nombreCalendario = '')
{
    $ics = "BEGIN:VCALENDAR\r\nPRODID:-//CENGICANA//Cengicursos//ES\r\nVERSION:2.0\r\nCALSCALE:GREGORIAN\r\nMETHOD:" . $metodo . "\r\n";
    $publicar = $metodo === 'PUBLISH';
    if ($publicar) {
        // Nombre con el que Outlook, Google o Apple muestran el calendario importado.
        $ics .= cengi_ics_linea('X-WR-CALNAME:' . cengi_ics_texto($nombreCalendario !== '' ? $nombreCalendario : 'Sesiones CENGICAÑA'))
            . "X-WR-TIMEZONE:" . CENGI_ICS_ZONA . "\r\n";
    }
    [$orgNombre, $orgCorreo] = cengi_ics_organizador();
    $ahora = gmdate('Ymd\THis\Z');
    foreach ($sesiones as $s) {
        $lineas = [
            'BEGIN:VEVENT',
            // La descarga usa otro UID para no mezclarse con la invitación en el mismo buzón.
            'UID:cengicursos-sesion-' . (int) $s['id'] . ($publicar ? '-pub' : '') . '@cengicana.org',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'SEQUENCE:' . max(0, time() - 1700000000),
            'DTSTART:' . cengi_ics_utc($s['fecha'], $s['hora_inicio']),
            'DTEND:' . cengi_ics_utc($s['fecha'], $s['hora_fin']),
            'SUMMARY:' . cengi_ics_texto(cengi_ics_resumen($s)),
            'LOCATION:' . cengi_ics_texto($s['lugar']),
            'DESCRIPTION:' . cengi_ics_texto(cengi_ics_descripcion($s)),
            'STATUS:' . ($metodo === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
        ];
        if (preg_match('~https?://\S+~i', (string) $s['lugar'], $m)) {
            $lineas[] = 'URL:' . $m[0];
        }
        if (!$publicar && filter_var($orgCorreo, FILTER_VALIDATE_EMAIL)) {
            $lineas[] = 'ORGANIZER;CN="' . str_replace('"', '', $orgNombre) . '":mailto:' . $orgCorreo;
            foreach ($asistentes as $correo => [$nombre]) {
                $lineas[] = 'ATTENDEE;CN="' . str_replace('"', '', $nombre) . '";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $correo;
            }
        }
        // Recordatorio 30 minutos antes, solo en sesiones que aún no ocurren.
        if ($metodo !== 'CANCEL' && cengi_ics_utc($s['fecha'], $s['hora_inicio']) > $ahora) {
            array_push($lineas, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . cengi_ics_texto(cengi_ics_resumen($s)), 'TRIGGER:-PT30M', 'END:VALARM');
        }
        $lineas[] = 'END:VEVENT';
        foreach ($lineas as $linea) {
            $ics .= cengi_ics_linea($linea);
        }
    }
    return $ics . "END:VCALENDAR\r\n";
}

/**
 * Encabezados de descarga con un nombre de archivo legible: versión sin acentos para
 * navegadores antiguos y versión UTF-8 (RFC 5987) con acentos para los actuales.
 */
function cengi_descarga_encabezados($tipoContenido, $nombre, $extension)
{
    $nombre = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', (string) $nombre)));
    $nombre = mb_substr($nombre !== '' ? $nombre : 'archivo', 0, 120, 'UTF-8');
    $ascii = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I',
        'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', '—' => '-', '–' => '-', '·' => '-', '°' => '']);
    $ascii = trim(preg_replace('/[^A-Za-z0-9 ._()-]+/', '', $ascii)) ?: 'archivo';
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . $tipoContenido);
    header('Content-Disposition: attachment; filename="' . $ascii . '.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode($nombre . '.' . $extension));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

// ---------------------------------------------------------------- envío

/**
 * Envía una invitación (REQUEST) o cancelación (CANCEL) de una sesión a $destinatarios
 * ([correo => [nombre, rol]]) y la registra en comunicaciones. $nueva distingue el texto
 * "te invitamos" de "se actualizó". Devuelve [enviados, fallidos].
 */
function cengi_ics_enviar(PDO $db, array $s, $metodo, array $destinatarios, array $asistentes, $nueva = true)
{
    require_once __DIR__ . '/correo.php';
    if (!$destinatarios) {
        return [0, 0];
    }
    $fechaLarga = cengi_pp_fecha_larga($s['fecha']);
    $cuando = $fechaLarga . ', ' . cengi_pp_horario($s['hora_inicio'], $s['hora_fin']);
    if ($metodo === 'CANCEL') {
        $categoria = CENGI_ICS_CAT_CANCELACION;
        $asunto = 'Cancelada: ' . $s['tema'] . ' — ' . $s['plan_nombre'] . ' (' . cengi_pp_fecha_corta($s['fecha']) . ')';
        $mensaje = "Hola {nombre}:\n\nLa sesión «" . $s['tema'] . '» de ' . $s['plan_nombre'] . ' del ' . $cuando
            . " fue cancelada o ya no está a tu cargo. Se quitará de tu calendario.\n\nSi tienes dudas, responde a la Coordinación de Capacitación.";
    } else {
        $categoria = CENGI_ICS_CAT_INVITACION;
        $asunto = ($nueva ? 'Invitación: ' : 'Actualizada: ') . $s['tema'] . ' — ' . $s['plan_nombre'] . ' (' . cengi_pp_fecha_corta($s['fecha']) . ')';
        $mensaje = "Hola {nombre}:\n\n" . ($nueva ? 'Se te asignó una sesión de ' : 'Cambió la información de tu sesión de ') . $s['plan_nombre'] . ":\n\n"
            . '• Tema: ' . $s['tema'] . "\n• Fecha: " . $fechaLarga . "\n• Horario: " . cengi_pp_horario($s['hora_inicio'], $s['hora_fin'])
            . "\n• Modalidad: " . $s['modalidad'] . "\n• Lugar o enlace: " . $s['lugar'] . "\n• Tu rol: {rol}\n\n"
            . ($nueva ? 'Acepta la invitación para agregarla a tu calendario de Outlook. Si algo cambia, recibirás la actualización y tu calendario se ajustará solo.'
                : 'Tu calendario se actualiza con estos datos; acepta la actualización si tu correo lo solicita.');
    }
    $ical = cengi_ics_calendario([$s], $metodo, $asistentes);

    // Si el plan se eliminó, la comunicación queda sin plan (la llave foránea no admite uno inexistente).
    $existePlan = $db->prepare('SELECT id FROM planificacion_cursos WHERE id = ?');
    $existePlan->execute([(int) $s['planificacion_id']]);
    $planId = $existePlan->fetchColumn() ? (int) $s['planificacion_id'] : null;

    $db->prepare("INSERT INTO comunicaciones (categoria, origen, referencia, planificacion_id, asunto, mensaje, estado, creado_por_nombre)
        VALUES (?, 'sistema', ?, ?, ?, ?, 'enviando', ?)")->execute([
        $categoria, 'ics:sesion:' . (int) $s['id'], $planId, mb_substr($asunto, 0, 255, 'UTF-8'), $mensaje, cengi_pp_usuario_nombre(),
    ]);
    $comunicacionId = (int) $db->lastInsertId();
    $stmtDest = $db->prepare('INSERT INTO comunicacion_destinatarios (comunicacion_id, nombre, correo, estado, error) VALUES (?, ?, ?, ?, ?)');

    $GLOBALS['cengi_correo_sin_registro'] = true;
    $enviados = 0;
    $fallidos = 0;
    foreach ($destinatarios as $correo => [$nombre, $rol]) {
        $texto = str_replace(['{nombre}', '{rol}'], [$nombre ?: 'colaborador', $rol], $mensaje);
        // Sin el logo incrustado: PHPMailer solo agrega la parte text/calendar a correos sin imágenes embebidas.
        $html = preg_replace("~<img src='cid:logo_cengicana'[^>]*>~", "<b style='color:#176b3a;font-size:18px;'>CENGICAÑA</b>", cengi_pp_correo_html($asunto, $texto));
        $error = '';
        try {
            $ok = cengi_enviar_correo($correo, $nombre, $asunto, $html, [], $ical);
            if (!$ok) {
                $error = 'El servidor de correo rechazó el envío.';
            }
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 255, 'UTF-8');
        }
        $stmtDest->execute([$comunicacionId, $nombre, $correo, $ok ? 'enviado' : 'fallido', $error]);
        $ok ? $enviados++ : $fallidos++;
    }
    unset($GLOBALS['cengi_correo_sin_registro']);
    $db->prepare('UPDATE comunicaciones SET estado = ?, enviados = ?, fallidos = ?, enviado_en = NOW() WHERE id = ?')
        ->execute([$enviados > 0 || $fallidos === 0 ? 'enviada' : 'fallida', $enviados, $fallidos, $comunicacionId]);
    return [$enviados, $fallidos];
}

/**
 * Lleva los calendarios de instructores y soporte al estado actual de las sesiones.
 * - $antes: instantánea previa al cambio (cengi_ics_instantanea); detecta cambios y sesiones eliminadas.
 * - $ids: sesiones nuevas o tocadas que no estaban en la instantánea.
 * - $invitar: ids cuyas personas aún no invitadas deben recibir la invitación
 *   (casilla "Enviar invitación de Outlook"). Sin ella solo se actualiza o cancela
 *   lo que ya se había enviado.
 * Nunca lanza excepción: un fallo de correo no debe deshacer lo ya guardado.
 * Devuelve ['enviadas' => n, 'canceladas' => n, 'fallidas' => n, 'sin_correo' => n].
 */
function cengi_ics_sincronizar(PDO $db, array $antes, array $ids = [], array $invitar = [], array $soloCorreos = [])
{
    // $soloCorreos: limita las invitaciones nuevas a estas personas (p. ej. solo el instructor filtrado).
    $soloCorreos = array_flip(array_map(static function ($c) {
        return mb_strtolower(trim((string) $c), 'UTF-8');
    }, $soloCorreos));
    $resultado = ['enviadas' => 0, 'canceladas' => 0, 'fallidas' => 0, 'sin_correo' => 0];
    try {
        $todos = array_values(array_unique(array_merge(array_keys($antes), array_map('intval', $ids), array_map('intval', $invitar))));
        if (!$todos) {
            return $resultado;
        }
        @set_time_limit(300);
        $invitar = array_flip(array_map('intval', $invitar));
        $actuales = cengi_ics_sesiones($db, $todos);
        $invitados = cengi_ics_invitados($db, $todos);
        foreach ($todos as $id) {
            $previa = $antes[$id] ?? null;
            $actual = $actuales[$id] ?? null;
            $yaInvitados = $invitados[$id] ?? [];
            $conservan = cengi_ics_en_calendario($actual) ? cengi_ics_personas($actual) : [];
            $personas = cengi_ics_vigente($actual) ? $conservan : [];

            // Quien tenía la invitación y ya no corresponde (cancelada, eliminada, pasada a otro): cancelación.
            $quitar = array_diff_key($yaInvitados, $conservan);
            if ($quitar && ($actual ?: $previa)) {
                $base = $actual ?: $previa;
                $destinatarios = [];
                foreach (array_keys($quitar) as $correo) {
                    $destinatarios[$correo] = cengi_ics_personas($previa ?: $base)[$correo] ?? ['', 'Participante'];
                }
                [$ok, $mal] = cengi_ics_enviar($db, $base, 'CANCEL', $destinatarios, $destinatarios);
                $resultado['canceladas'] += $ok;
                $resultado['fallidas'] += $mal;
            }
            if (!$personas) {
                if (isset($invitar[$id]) && $actual && cengi_ics_vigente($actual)) {
                    $resultado['sin_correo'] += cengi_ics_sin_correo($actual);
                }
                continue;
            }

            $cambio = $previa && array_diff_assoc(array_intersect_key(array_map('strval', $actual), array_flip(CENGI_ICS_CAMPOS)),
                array_intersect_key(array_map('strval', $previa), array_flip(CENGI_ICS_CAMPOS)));
            $nuevos = isset($invitar[$id]) ? array_diff_key($personas, $yaInvitados) : [];
            if ($soloCorreos) {
                $nuevos = array_intersect_key($nuevos, $soloCorreos);
            }
            $actualizar = $cambio ? array_intersect_key($personas, $yaInvitados) : [];
            if (isset($invitar[$id])) {
                $resultado['sin_correo'] += cengi_ics_sin_correo($actual);
            }
            foreach ([[$nuevos, true], [$actualizar, false]] as [$destinatarios, $esNueva]) {
                if ($destinatarios) {
                    [$ok, $mal] = cengi_ics_enviar($db, $actual, 'REQUEST', $destinatarios, $personas, $esNueva);
                    $resultado['enviadas'] += $ok;
                    $resultado['fallidas'] += $mal;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Calendario: no se pudieron sincronizar las invitaciones: ' . $e->getMessage());
        $resultado['fallidas']++;
    }
    return $resultado;
}

/** Texto para el mensaje de la página tras sincronizar (vacío si no hubo nada). */
function cengi_ics_resumen_envio(array $r)
{
    $partes = [];
    if ($r['enviadas']) {
        $partes[] = $r['enviadas'] . ' invitación(es) de calendario enviada(s)';
    }
    if ($r['canceladas']) {
        $partes[] = $r['canceladas'] . ' cancelación(es) enviada(s)';
    }
    if ($r['sin_correo']) {
        $partes[] = $r['sin_correo'] . ' persona(s) sin correo registrado: no se les pudo invitar';
    }
    if ($r['fallidas']) {
        $partes[] = $r['fallidas'] . ' envío(s) fallido(s) (revisa Comunicaciones)';
    }
    return $partes ? ucfirst(implode('; ', $partes)) . '.' : '';
}

/** Parámetros de URL con el resultado, para mostrarlo después del redirect. */
function cengi_ics_parametros(array $r)
{
    return array_filter(['inv' => $r['enviadas'], 'invc' => $r['canceladas'], 'invs' => $r['sin_correo'], 'invf' => $r['fallidas']]);
}

function cengi_ics_desde_parametros(array $get)
{
    return ['enviadas' => (int) ($get['inv'] ?? 0), 'canceladas' => (int) ($get['invc'] ?? 0), 'sin_correo' => (int) ($get['invs'] ?? 0), 'fallidas' => (int) ($get['invf'] ?? 0)];
}

// ---------------------------------------------------------------- programación por persona

/**
 * Sesiones programadas desde hoy de un curso (o de un año), opcionalmente solo las de un
 * instructor ($instructor = id, o 'sin' para las que no tienen). Filas de cengi_ics_sesiones.
 */
function cengi_ics_proximas(PDO $db, $planId, $anio, $instructor = '')
{
    $sql = "SELECT s.id FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
        WHERE s.estado = 'programada' AND s.fecha >= ? AND p.estado <> 'cancelado' AND " . ($planId ? 's.planificacion_id = ?' : 'p.anio = ?');
    $params = [cengi_pp_hoy(), $planId ? (int) $planId : (int) $anio];
    if ($instructor === 'sin') {
        $sql .= ' AND s.instructor_id IS NULL';
    } elseif ((int) $instructor > 0) {
        $sql .= ' AND s.instructor_id = ?';
        $params[] = (int) $instructor;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return cengi_ics_sesiones($db, $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Agrupa sesiones por persona (instructor y soporte técnico). Con $soloInstructorId solo
 * se incluye a ese instructor. Devuelve [clave => ['nombre', 'correo', 'rol', 'sesiones' => [...]]],
 * con correo '' si la persona no tiene uno válido.
 */
function cengi_ics_personas_programacion(array $sesiones, $soloInstructorId = 0)
{
    $personas = [];
    foreach ($sesiones as $s) {
        foreach (['instructor' => 'Instructor', 'soporte' => 'Soporte técnico'] as $clave => $rol) {
            if (!$s[$clave . '_id'] || ($soloInstructorId && ($clave !== 'instructor' || (int) $s['instructor_id'] !== (int) $soloInstructorId))) {
                continue;
            }
            $k = $clave[0] . ':' . (int) $s[$clave . '_id'];
            if (!isset($personas[$k])) {
                $correo = mb_strtolower(trim((string) $s[$clave . '_correo']), 'UTF-8');
                $personas[$k] = ['nombre' => (string) $s[$clave . '_nombre'], 'correo' => filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '', 'rol' => $rol, 'sesiones' => []];
            }
            $personas[$k]['sesiones'][] = $s;
        }
    }
    uasort($personas, static function ($a, $b) {
        return [$a['rol'], $a['nombre']] <=> [$b['rol'], $b['nombre']];
    });
    return $personas;
}

const CENGI_ICS_NOTA_PROGRAMACION = "Hola {nombre}:\n\nTe compartimos la programación de tus sesiones como {rol} de {curso}. Revisa las fechas, los horarios y el lugar o enlace de cada una. Si tienes algún inconveniente con alguna fecha, avísanos lo antes posible para reprogramarla.";

/** Tabla HTML (estilos en línea, apta para correo) con las sesiones de una persona. */
function cengi_ics_tabla_sesiones(array $sesiones)
{
    $varios = count(array_unique(array_column($sesiones, 'planificacion_id'))) > 1;
    $celda = "padding:8px 10px;border-bottom:1px solid #e4e7e1;font-size:13px;vertical-align:top;color:#294033;";
    $html = "<table cellpadding='0' cellspacing='0' width='100%' style='border-collapse:collapse;margin:18px 0 6px;border:1px solid #e4e7e1;'>"
        . "<tr style='background:#1f5632;color:#ffffff;'>"
        . "<th align='left' style='padding:8px 10px;font-size:12px;'>Fecha</th><th align='left' style='padding:8px 10px;font-size:12px;'>Horario</th>"
        . "<th align='left' style='padding:8px 10px;font-size:12px;'>" . ($varios ? 'Curso y tema' : 'Tema') . "</th><th align='left' style='padding:8px 10px;font-size:12px;'>Lugar o enlace</th></tr>";
    foreach (array_values($sesiones) as $n => $s) {
        $fondo = $n % 2 ? '#f7f9f4' : '#ffffff';
        $tema = ($varios ? '<b>' . cengi_pp_h($s['plan_nombre']) . '</b><br>' : '') . cengi_pp_h($s['tema'])
            . ($s['modulo'] !== '' && $s['modulo'] !== 'Sesión única' ? "<br><span style='color:#6b7766;font-size:12px;'>" . cengi_pp_h($s['modulo']) . '</span>' : '');
        $html .= "<tr style='background:{$fondo};'>"
            . "<td style='{$celda}white-space:nowrap;'><b>" . cengi_pp_h(ucfirst(cengi_pp_fecha_larga($s['fecha']))) . '</b></td>'
            . "<td style='{$celda}white-space:nowrap;'>" . cengi_pp_h(cengi_pp_horario($s['hora_inicio'], $s['hora_fin'])) . '</td>'
            . "<td style='{$celda}'>" . $tema . '</td>'
            . "<td style='{$celda}'>" . cengi_pp_h($s['modalidad']) . '<br>' . cengi_pp_h($s['lugar']) . '</td></tr>';
    }
    return $html . '</table>';
}

/**
 * Envía a una persona la nota con la programación de sus sesiones. Con $adjuntarIcs agrega
 * un .ics para importarlas (si se envían invitaciones de Outlook no se adjunta, para no
 * duplicar los eventos). Queda registrada en comunicaciones. Devuelve true si se envió.
 */
function cengi_ics_enviar_programacion(PDO $db, array $persona, $nota, $adjuntarIcs, $planId = null, $asunto = null, $cierreTexto = null)
{
    require_once __DIR__ . '/correo.php';
    $sesiones = $persona['sesiones'];
    $cursos = array_values(array_unique(array_column($sesiones, 'plan_nombre')));
    $curso = count($cursos) === 1 ? $cursos[0] : 'varios cursos';
    $asunto = $asunto ?: 'Programación de tus sesiones — ' . (count($cursos) === 1 ? $cursos[0] : count($sesiones) . ' sesiones');
    $texto = str_replace(['{nombre}', '{rol}', '{curso}'], [$persona['nombre'], mb_strtolower($persona['rol'], 'UTF-8'), $curso], trim((string) $nota));
    $cierre = count($sesiones) . ' sesión(es) del ' . cengi_pp_fecha_corta($sesiones[0]['fecha']) . ' al ' . cengi_pp_fecha_corta(end($sesiones)['fecha']) . '.'
        . ($cierreTexto !== null ? "\n" . $cierreTexto : ($adjuntarIcs ? "\nAdjuntamos el archivo «programacion.ics»: ábrelo para agregar todas las sesiones a tu calendario de Outlook."
            : "\nRecibirás por separado la invitación de Outlook de cada sesión; acéptalas para que queden en tu calendario y se actualicen solas si algo cambia."));
    // El marcador sobrevive al escape del cuerpo y se reemplaza por la tabla.
    $html = str_replace('[[TABLA_SESIONES]]', cengi_ics_tabla_sesiones($sesiones), cengi_pp_correo_html($asunto, $texto . "\n[[TABLA_SESIONES]]\n" . $cierre));
    $adjuntos = $adjuntarIcs ? [['contenido' => cengi_ics_calendario($sesiones, 'PUBLISH', [], 'Mis sesiones — ' . $curso), 'nombre' => 'programacion.ics', 'tipo' => 'text/calendar; charset=utf-8; method=PUBLISH']] : [];

    $resumen = $texto . "\n\n" . implode("\n", array_map(static function ($s) {
        return '• ' . cengi_pp_fecha($s['fecha']) . ' ' . cengi_pp_horario($s['hora_inicio'], $s['hora_fin']) . ' — ' . $s['plan_nombre'] . ': ' . $s['tema'];
    }, $sesiones));
    $db->prepare("INSERT INTO comunicaciones (categoria, origen, planificacion_id, asunto, mensaje, estado, creado_por_nombre) VALUES ('Programación de sesiones', 'manual', ?, ?, ?, 'enviando', ?)")
        ->execute([$planId ?: null, mb_substr($asunto, 0, 255, 'UTF-8'), $resumen, cengi_pp_usuario_nombre()]);
    $comunicacionId = (int) $db->lastInsertId();

    $GLOBALS['cengi_correo_sin_registro'] = true;
    $error = '';
    try {
        $ok = cengi_enviar_correo($persona['correo'], $persona['nombre'], $asunto, $html, $adjuntos);
        if (!$ok) {
            $error = 'El servidor de correo rechazó el envío.';
        }
    } catch (Throwable $e) {
        $ok = false;
        $error = mb_substr($e->getMessage(), 0, 255, 'UTF-8');
    }
    unset($GLOBALS['cengi_correo_sin_registro']);
    $db->prepare('INSERT INTO comunicacion_destinatarios (comunicacion_id, nombre, correo, estado, error) VALUES (?, ?, ?, ?, ?)')
        ->execute([$comunicacionId, $persona['nombre'], $persona['correo'], $ok ? 'enviado' : 'fallido', $error]);
    $db->prepare('UPDATE comunicaciones SET estado = ?, enviados = ?, fallidos = ?, enviado_en = NOW() WHERE id = ?')
        ->execute([$ok ? 'enviada' : 'fallida', $ok ? 1 : 0, $ok ? 0 : 1, $comunicacionId]);
    return $ok;
}
