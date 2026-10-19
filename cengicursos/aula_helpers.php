<?php
require_once __DIR__.'/aula_integracion.php';
require_once __DIR__.'/estudiantes_acceso_helpers.php';
require_once __DIR__.'/aula_control.php';
require_once __DIR__.'/aula_vista.php';
// Sesiones, ventanas de asistencia y porcentajes se calculan en hora de Guatemala (el servidor puede estar en UTC).
date_default_timezone_set('America/Guatemala');
// Las funciones de calculo pueden probarse sin iniciar sesion ni conectar a MySQL.
function aula_h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function aula_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
function aula_rows(PDO $db, $sql, array $params = []) {
    $q = $db->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC);
}
function aula_ready(PDO $db) {
    try { $db->query('SELECT id FROM aula_intentos LIMIT 0'); return true; }
    catch (PDOException $e) { return false; }
}
function aula_csrf() {
    if (empty($_SESSION['aula_csrf'])) $_SESSION['aula_csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . aula_h($_SESSION['aula_csrf']) . '">';
}
function aula_check_csrf() {
    if (!hash_equals($_SESSION['aula_csrf'] ?? '', (string) ($_POST['csrf'] ?? '')) || empty($_SESSION['aula_csrf'])) {
        throw new RuntimeException('La sesión del formulario venció. Recarga la página.');
    }
}
function aula_assignment(PDO $db, $cursoId) {
    return aula_rows($db, 'SELECT a.*, p.nombre_participantes FROM asignaciones a
        JOIN participantes p ON p.id=a.participantes_id
        WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1
        AND (a.usuarios_id=? OR p.usuarios_id=?) AND ' . cengi_aula_acceso_sql($db, 'a.cursos_id', cengi_usuario_actual_id()) . ' LIMIT 1',
        [$cursoId, cengi_usuario_actual_id(), cengi_usuario_actual_id()])[0] ?? null;
}
function aula_courses(PDO $db) {
    return aula_rows($db, 'SELECT DISTINCT c.id,c.nombre_cursos,c.inicio,c.fin FROM cursos c
        JOIN asignaciones a ON a.cursos_id=c.id JOIN participantes p ON p.id=a.participantes_id
        WHERE a.estado_asignaciones=1 AND p.estado_participantes=1 AND (a.usuarios_id=? OR p.usuarios_id=?)
        AND ' . cengi_aula_acceso_sql($db, 'c.id', cengi_usuario_actual_id()) . ' ORDER BY c.inicio DESC,c.nombre_cursos', [cengi_usuario_actual_id(),cengi_usuario_actual_id()]);
}
function aula_require_teacher() {
    // Misma capacidad que Seguimiento por curso. El estudiante nunca administra el aula.
    if (cengi_es_estudiante() || !(cengi_puede_gestionar() || cengi_puede_calificar())) {
        http_response_code(403); exit('No tienes permiso para administrar el aula.');
    }
}
function aula_teacher_course(PDO $db, $id) {
    aula_require_teacher();
    // Respeta el alcance institucional de la cuenta; no basta con enviar un id por URL.
    $scope = cengi_scope_sql_por_nombre_ingenio('i', true);
    $rows = aula_rows($db, "SELECT c.* FROM cursos c JOIN ingenios i ON i.id=c.ingenio_id WHERE c.id=? $scope", [$id]);
    if (!$rows) { http_response_code(403); exit('El curso está fuera del alcance de tu cuenta.'); }
    return $rows[0];
}
function aula_grade(array $questions, array $answers, array $review = []) {
    if (!$questions) throw new InvalidArgumentException('La evaluación no contiene preguntas.');
    $earned = 0; $pending = false;
    foreach ($questions as $p) {
        $id = (string) $p['id'];
        if ($p['tipo'] === 'seleccion') {
            $answer = $answers[$id] ?? '';
            if ($answer !== '' && (string) $answer === (string) $p['correcta']) $earned++;
        } elseif (isset($review[$id]['puntos']) && is_numeric($review[$id]['puntos'])) {
            $points = (float) $review[$id]['puntos'];
            if ($points < 0 || $points > 1) throw new InvalidArgumentException('Los puntos deben estar entre 0 y 1.');
            $earned += $points;
        } else { $pending = true; }
    }
    return $pending ? null : round(100 * $earned / count($questions), 2);
}
function aula_url($url) {
    $url = trim((string) $url);
    return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https','http'], true) ? $url : null;
}
function aula_vimeo($url) {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($host, ['vimeo.com','www.vimeo.com','player.vimeo.com'], true)) return null;
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!preg_match('#^/(?:video/|manage/videos/|channels/[^/]+/|groups/[^/]+/videos/|showcase/\d+/video/)?(\d+)(?:/([a-zA-Z0-9]+))?/?$#', $path, $m)) return null;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $hash = $m[2] ?? ($query['h'] ?? '');
    return 'https://player.vimeo.com/video/' . $m[1] . (is_string($hash) && preg_match('/^[a-zA-Z0-9]+$/', $hash) ? '?h=' . rawurlencode($hash) : '');
}
function aula_datetime($input) {
    $value = str_replace('T', ' ', (string) $input);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);
    if (!$d || $d->format('Y-m-d H:i') !== $value) throw new InvalidArgumentException('La fecha no es válida.');
    return $d->format('Y-m-d H:i:s');
}
function aula_upload(PDO $db, $file, $cursoId, $title, $attemptId = null, $questionId = null, $allowVideo = false) {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
    $max=$allowVideo?15:5;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $max * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('No se pudo cargar el archivo. Máximo '.$max.' MB.');
    }
    // PDF evita contenido ejecutable/HTML; se sirve como descarga autenticada desde la BD.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($mime !== ($allowVideo?'video/mp4':'application/pdf')) throw new InvalidArgumentException($allowVideo?'El video debe ser MP4.':'El archivo debe ser PDF.');
    $name = preg_replace('/[^\pL\pN ._-]/u', '_', basename($file['name']));
    $q = $db->prepare('INSERT INTO aula_archivos(curso_id,intento_id,pregunta_id,titulo,nombre,mime,contenido)
        VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),mime=VALUES(mime),contenido=VALUES(contenido)');
    $q->execute([$cursoId,$attemptId,$questionId,mb_substr($title,0,180),mb_substr($name,0,180),$mime,file_get_contents($file['tmp_name'])]);
    return (int)$db->lastInsertId();
}
function aula_start(PDO $db, $evalId, array $assignment) {
    $db->beginTransaction();
    try {
        // Serializa inicios concurrentes para impedir exceder el limite de intentos.
        $db->prepare('SELECT id FROM asignaciones WHERE id=? FOR UPDATE')->execute([$assignment['id']]);
        $e = aula_rows($db, 'SELECT * FROM aula_evaluaciones WHERE id=? AND curso_id=? FOR UPDATE', [$evalId,$assignment['cursos_id']])[0] ?? null;
        $now = date('Y-m-d H:i:s');
        if (!$e || !$e['habilitada'] || $now < $e['abre'] || $now >= $e['cierra']) throw new RuntimeException('Esta evaluación no está disponible.');
        if(!empty($e['sesion_id']))aula_session($db,$assignment['cursos_id'],$e['sesion_id']);
        $attempts = aula_rows($db, 'SELECT * FROM aula_intentos WHERE evaluacion_id=? AND asignacion_id=? ORDER BY numero DESC', [$evalId,$assignment['id']]);
        if ($attempts && !$attempts[0]['entregado']) { $db->commit(); return (int) $attempts[0]['id']; }
        if (count($attempts) >= (int) $e['intentos']) throw new RuntimeException('Ya utilizaste los intentos disponibles.');
        $questions = aula_rows($db, 'SELECT p.* FROM aula_preguntas p JOIN aula_evaluacion_preguntas ep ON ep.pregunta_id=p.id WHERE ep.evaluacion_id=? AND p.curso_id=?', [$evalId,$assignment['cursos_id']]);
        if (!$questions) throw new RuntimeException('La evaluación todavía no contiene preguntas.');
        shuffle($questions); // Snapshot conserva enunciados, opciones y claves de este intento.
        $expires = min($e['cierra'], date('Y-m-d H:i:s', time() + (int) $e['minutos'] * 60));
        $q = $db->prepare('INSERT INTO aula_intentos(evaluacion_id,asignacion_id,numero,preguntas,respuestas,inicio,vence) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$evalId,$assignment['id'],count($attempts)+1,aula_json($questions),'{}',$now,$expires]);
        $id = (int) $db->lastInsertId(); $db->commit(); return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function aula_expire(PDO $db, $courseId, $assignmentId = null) {
    $params = [$courseId,date('Y-m-d H:i:s')];
    $scope = '';
    if ($assignmentId !== null) { $scope=' AND t.asignacion_id=?'; $params[]=$assignmentId; }
    $rows=aula_rows($db,'SELECT t.id FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id
        WHERE e.curso_id=? AND t.vence<=? AND t.entregado IS NULL'.$scope,$params);
    foreach($rows as $row) {
        $db->beginTransaction();
        try {
            $a=aula_rows($db,'SELECT * FROM aula_intentos WHERE id=? AND entregado IS NULL FOR UPDATE',[$row['id']])[0]??null;
            if($a) {
                $grade=aula_grade(json_decode($a['preguntas'],true),json_decode($a['respuestas'],true));
                $db->prepare('UPDATE aula_intentos SET entregado=vence,nota=? WHERE id=?')->execute([$grade,$a['id']]);
            }
            if($a)aula_grade_sync($db,$a['asignacion_id'],$courseId);
            $db->commit();
        } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
    }
}
function aula_save(PDO $db, $attemptId, array $assignment, $submit) {
    $db->beginTransaction();
    try {
        $a = aula_rows($db, 'SELECT t.*,e.habilitada FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE t.id=? AND t.asignacion_id=? FOR UPDATE', [$attemptId,$assignment['id']])[0] ?? null;
        if (!$a || $a['entregado']) throw new RuntimeException('El intento ya fue entregado o no te pertenece.');
        $now = date('Y-m-d H:i:s');
        $expired = $now >= $a['vence'];
        $questions = json_decode($a['preguntas'],true);
        $answers = json_decode($a['respuestas'],true);
        if (!$expired && $a['habilitada']) {
            $input = $_POST['respuestas'] ?? [];
            if (!is_array($input)) throw new InvalidArgumentException('Respuestas no válidas.');
            foreach ($questions as $p) {
                $v = $input[$p['id']] ?? '';
                if (!is_string($v) || mb_strlen($v) > 20000) throw new InvalidArgumentException('Respuesta demasiado extensa.');
                if ($p['tipo'] === 'seleccion' && $v !== '') {
                    $options = json_decode($p['opciones'],true);
                    if (!ctype_digit($v) || !isset($options[(int) $v])) throw new InvalidArgumentException('Opción no válida.');
                }
                $answers[(string) $p['id']] = $v;
                if ($p['tipo'] === 'tarea') {
                    aula_upload($db, $_FILES['tarea_' . $p['id']] ?? null, $assignment['cursos_id'], 'Entrega de tarea', $attemptId, $p['id']);
                }
            }
        }
        if (!$a['habilitada'] && !$expired) throw new RuntimeException('El docente deshabilitó la evaluación. Tus respuestas guardadas se conservan.');
        $final = $submit || $expired;
        if($submit && !$expired && aula_integrada($db)) {
            $kind=aula_rows($db,'SELECT tipo_actividad FROM aula_evaluaciones WHERE id=?',[$a['evaluacion_id']])[0]['tipo_actividad'];
            if($kind!=='evaluacion')foreach($questions as $p) {
                if($p['tipo']!=='tarea')continue;
                $file=aula_rows($db,'SELECT id FROM aula_archivos WHERE intento_id=? AND pregunta_id=?',[$attemptId,$p['id']]);
                if(trim($answers[$p['id']]??'')===''&&!$file)throw new InvalidArgumentException('Completa la tarea con una respuesta o adjunta su archivo antes de entregar.');
            }
        }
        $grade = $final ? aula_grade($questions,$answers) : null;
        $q = $db->prepare('UPDATE aula_intentos SET respuestas=?,entregado=?,nota=? WHERE id=?');
        $q->execute([aula_json($answers),$final ? $now : null,$grade,$attemptId]);
        if($final && aula_integrada($db))$db->prepare('UPDATE aula_intentos SET entrega_usuario=? WHERE id=?')->execute([$submit&&!$expired?1:0,$attemptId]);
        if($final)aula_grade_sync($db,$assignment['id'],$assignment['cursos_id']);
        $db->commit();
        return $expired ? 'El tiempo terminó. Se entregaron las respuestas previamente guardadas.' : ($final ? 'Evaluación entregada.' : 'Respuestas guardadas.');
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
