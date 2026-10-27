<?php
function aula_control_ready(PDO $db) {
    static $ready=null;if($ready===null)$ready=new WeakMap();if(isset($ready[$db]))return true;
    try{$db->query('SELECT presente,origen,validada FROM aula_asistencias LIMIT 0');$db->query('SELECT curso_id FROM aula_instructor_config LIMIT 0');$db->query('SELECT curso_id FROM aula_curso_config LIMIT 0');$ready[$db]=true;return true;}
    catch(PDOException $e){return false;}
}
function aula_teams_url(PDO $db,$courseId,array $session=[]) {
    if(aula_control_ready($db)) {
        if(!empty($session['id'])) {
            $cfg=aula_rows($db,'SELECT teams_url FROM aula_sesion_config WHERE sesion_id=?',[$session['id']])[0]??[];
            if(aula_url($cfg['teams_url']??''))return $cfg['teams_url'];
        }
        if(aula_url($session['lugar']??''))return $session['lugar'];
        $cfg=aula_rows($db,'SELECT teams_url FROM aula_curso_config WHERE curso_id=?',[$courseId])[0]??[];
        if(aula_url($cfg['teams_url']??''))return $cfg['teams_url'];
    }
    return aula_url($session['lugar']??'');
}
function aula_instructor_surveys(PDO $db,$courseId,$assignmentId=null) {
    $links=aula_instructor_links($db,$courseId);$groups=[];$seen=[];
    foreach($links as $link) {
        $id=(int)$link['instructor_id'];
        if(!isset($groups[$id]))$groups[$id]=['instructor_id'=>$id,'instructor'=>$link['instructor'],'sesiones'=>0,'abre_auto'=>null,'token'=>$link['token_curso']];
        if(!isset($seen[$id][$link['sesion_id']])){$groups[$id]['sesiones']++;$seen[$id][$link['sesion_id']]=true;}
        $end=$link['fecha'].' '.$link['hora_fin'];if($link['hora_fin']<=$link['hora_inicio'])$end=date('Y-m-d H:i:s',strtotime($end.' +1 day'));
        $groups[$id]['abre_auto']=max($groups[$id]['abre_auto']??$end,$end);
    }
    // Instructores agregados desde Gestion del aula (invitados, conferencistas) aunque no tengan sesiones.
    if(aula_control_ready($db)) foreach(aula_rows($db,'SELECT c.instructor_id,i.nombre,(SELECT e.token FROM enlaces_evaluacion_instructor e WHERE e.curso_id=c.curso_id AND e.instructor_id=c.instructor_id AND e.curso_modulo_id IS NULL LIMIT 1) AS token FROM aula_instructor_config c JOIN instructores i ON i.id=c.instructor_id WHERE c.curso_id=?',[$courseId]) as $extra) {
        $id=(int)$extra['instructor_id'];
        if(!isset($groups[$id]))$groups[$id]=['instructor_id'=>$id,'instructor'=>$extra['nombre'],'sesiones'=>0,'abre_auto'=>null,'token'=>$extra['token'],'agregado'=>true];
    }
    foreach($groups as &$g) {
        $cfg=aula_control_ready($db)?(aula_rows($db,'SELECT * FROM aula_instructor_config WHERE curso_id=? AND instructor_id=?',[$courseId,$g['instructor_id']])[0]??[]):[];
        $g['habilitada']=$cfg['habilitada']??1;$g['abre']=$cfg['abre']??$g['abre_auto'];$g['cierra']=$cfg['cierra']??null;
        $g['respondida']=$assignmentId&&aula_control_ready($db)?(bool)aula_rows($db,'SELECT 1 FROM aula_instructor_respuestas WHERE asignacion_id=? AND instructor_id=?',[$assignmentId,$g['instructor_id']]):false;
        $now=date('Y-m-d H:i:s');$g['disponible']=$g['habilitada']&&$g['abre']&&$now>=$g['abre']&&(!$g['cierra']||$now<$g['cierra'])&&!$g['respondida'];
    }unset($g);
    return array_values($groups);
}
function aula_survey_participant(PDO $db, $courseId) {
    require_once __DIR__.'/revisar_permisos.php';
    if (!cengi_usuario_actual_id() || cengi_es_superadmin()) return [];
    $assignment = aula_assignment($db, $courseId);
    if (!$assignment) return [];
    return aula_rows($db, 'SELECT ingenio_id, puesto_participantes, area_participantes FROM participantes WHERE id=?', [$assignment['participantes_id']])[0] ?? [];
}
function aula_survey_context(PDO $db,array $link) {
    require_once __DIR__.'/revisar_permisos.php';
    if(cengi_es_superadmin())return ['prueba'=>true,'disponible'=>true,'curso_id'=>$link['curso_id'],'instructor_id'=>$link['instructor_id']];
    if(!aula_control_ready($db))return null;
    $cfg=aula_rows($db,'SELECT * FROM aula_instructor_config WHERE curso_id=? AND instructor_id=?',[$link['curso_id'],$link['instructor_id']])[0]??null;
    if(!$cfg)return null; // Encuestas historicas externas sin aula conservan su flujo.
    // Sin sesion o sin asignacion al curso, el enlace compartido sigue siendo el formulario publico;
    // el control de un envio y la ventana de apertura solo aplican al estudiante desde su aula.
    if(!cengi_usuario_actual_id())return null;
    $assignment=aula_assignment($db,$link['curso_id']);
    if(!$assignment)return null;
    foreach(aula_instructor_surveys($db,$link['curso_id'],$assignment['id']) as $survey) {
        if((int)$survey['instructor_id']===(int)$link['instructor_id'])return $survey+['asignacion_id'=>$assignment['id'],'curso_id'=>$link['curso_id']];
    }
    http_response_code(403);exit('Este instructor no está asignado a las sesiones activas del curso.');
}
function aula_survey_reserve(PDO $db,array $survey) {
    // El llamador mantiene la misma transaccion para la encuesta y su comprobante.
    if(!$db->inTransaction())throw new LogicException('La encuesta requiere una transacción.');
    $db->prepare('SELECT id FROM asignaciones WHERE id=? FOR UPDATE')->execute([$survey['asignacion_id']]);
    $db->prepare('SELECT instructor_id FROM aula_instructor_config WHERE curso_id=? AND instructor_id=? FOR UPDATE')->execute([$survey['curso_id'],$survey['instructor_id']]);
    $current=null;foreach(aula_instructor_surveys($db,$survey['curso_id'],$survey['asignacion_id']) as $s)if((int)$s['instructor_id']===(int)$survey['instructor_id'])$current=$s;
    if(!$current||!$current['disponible'])throw new RuntimeException('La evaluación no está habilitada o ya fue respondida.');
    $db->prepare('INSERT INTO aula_instructor_respuestas(asignacion_id,curso_id,instructor_id,enviado) VALUES(?,?,?,?)')->execute([$survey['asignacion_id'],$survey['curso_id'],$survey['instructor_id'],date('Y-m-d H:i:s')]);
}
function aula_survey_message(array $survey) {
    $message=$survey['respondida']?'Ya evaluaste a este instructor. Solo se registra una evaluación por estudiante e instructor en este curso.':(!$survey['habilitada']?'La evaluación del instructor está deshabilitada.':('La evaluación se habilita el '.$survey['abre'].($survey['cierra']?' y cierra el '.$survey['cierra']:'').'.'));
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Evaluación del instructor</title><link rel="stylesheet" href="css/bootstrap.min.css"></head><body><main style="max-width:650px;margin:40px auto;padding:24px"><h1 style="font-size:22px">Evaluación del instructor</h1><p role="status">'.aula_h($message).'</p><a href="mi_aula.php?curso='.(int)$survey['curso_id'].'&amp;seccion=instructores">Volver a mi aula</a></main></body></html>';exit;
}
function aula_attendance_records(PDO $db,$assignmentId) {
    $extra=aula_control_ready($db)?' AND r.presente=1 AND (r.origen=\'coordinacion\' OR r.validada=1 OR (r.registrado>=TIMESTAMP(s.fecha,s.hora_inicio) AND r.registrado<DATE_ADD(TIMESTAMP(s.fecha,s.hora_fin),INTERVAL IF(s.hora_fin<=s.hora_inicio,1,0) DAY)))':' AND r.registrado>=TIMESTAMP(s.fecha,s.hora_inicio) AND r.registrado<DATE_ADD(TIMESTAMP(s.fecha,s.hora_fin),INTERVAL IF(s.hora_fin<=s.hora_inicio,1,0) DAY)';
    return aula_rows($db,'SELECT r.* FROM aula_asistencias r JOIN curso_sesiones s ON s.id=r.sesion_id WHERE r.asignacion_id=? AND s.estado<>\'cancelada\''.$extra,[$assignmentId]);
}
function aula_attendance_correct(PDO $db,$courseId,$assignmentId,$sessionId,$present,$recorded,$reason) {
    aula_teacher_course($db,$courseId);
    $reason=trim($reason);if($reason===''||mb_strlen($reason)>500)throw new InvalidArgumentException('Indica el motivo de la corrección (hasta 500 caracteres).');
    $s=aula_session($db,$courseId,$sessionId);if(!$s)throw new InvalidArgumentException('Selecciona una sesión.');
    $date=aula_datetime($recorded);
    if($date>date('Y-m-d H:i:s')||$s['fecha']>date('Y-m-d'))throw new InvalidArgumentException('No se puede marcar asistencia futura.');
    $db->beginTransaction();
    try {
        $a=aula_rows($db,'SELECT id FROM asignaciones WHERE id=? AND cursos_id=? AND estado_asignaciones=1 FOR UPDATE',[$assignmentId,$courseId]);
        if(!$a)throw new InvalidArgumentException('El participante no está asignado al curso.');
        $old=aula_rows($db,'SELECT * FROM aula_asistencias WHERE sesion_id=? AND asignacion_id=?',[$sessionId,$assignmentId])[0]??null;
        $new=['presente'=>$present?1:0,'registrado'=>$date,'origen'=>'coordinacion','motivo'=>$reason,'responsable'=>mb_substr((string)($_SESSION['usuario']??'Coordinación'),0,255)];
        $db->prepare('INSERT INTO aula_asistencias(sesion_id,asignacion_id,registrado,presente,origen,validada,motivo,modificado_por) VALUES(?,?,?,?,\'coordinacion\',1,?,?) ON DUPLICATE KEY UPDATE registrado=VALUES(registrado),presente=VALUES(presente),origen=VALUES(origen),validada=1,motivo=VALUES(motivo),modificado_por=VALUES(modificado_por)')->execute([$sessionId,$assignmentId,$date,$present?1:0,$reason,cengi_usuario_actual_id()]);
        $db->prepare('INSERT INTO aula_asistencia_historial(sesion_id,asignacion_id,antes,despues,motivo,modificado_por,fecha) VALUES(?,?,?,?,?,?,?)')->execute([$sessionId,$assignmentId,$old?aula_json($old):null,aula_json($new),$reason,cengi_usuario_actual_id(),date('Y-m-d H:i:s')]);
        aula_attendance_sync($db,$assignmentId,$courseId);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function aula_video_source($input) {
    $input=trim($input);
    if(preg_match('/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']/i',$input,$m))$input=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
    return aula_vimeo($input)?:aula_url($input);
}
function aula_preview_context(PDO $db,$seedId,$courseId,$catalog=false) {
    aula_require_teacher();
    $seed=aula_rows($db,'SELECT a.*,p.nombre_participantes,p.estado_participantes FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1',[$seedId])[0]??null;
    if(!$seed){http_response_code(404);exit('La asignación no está disponible.');}
    aula_teacher_course($db,$seed['cursos_id']);
    $scope=cengi_scope_sql_por_nombre_ingenio('i',true).aula_scope_formador_sql($db);
    $courses=aula_rows($db,'SELECT DISTINCT c.id,c.nombre_cursos,c.inicio,c.fin FROM cursos c JOIN ingenios i ON i.id=c.ingenio_id JOIN asignaciones a ON a.cursos_id=c.id WHERE a.participantes_id=? AND a.estado_asignaciones=1 '.$scope.' ORDER BY c.inicio DESC,c.nombre_cursos',[$seed['participantes_id']]);
    if($catalog)return ['seed'=>$seed,'courses'=>$courses,'assignment'=>null,'courseId'=>0];
    $courseId=$courseId?:$seed['cursos_id'];aula_teacher_course($db,$courseId);
    $a=aula_rows($db,'SELECT a.*,p.nombre_participantes FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? AND a.participantes_id=? AND a.estado_asignaciones=1',[$courseId,$seed['participantes_id']])[0]??null;
    if(!$a){http_response_code(403);exit('El estudiante no está asignado a este curso.');}
    return ['seed'=>$seed,'courses'=>$courses,'assignment'=>$a,'courseId'=>$courseId];
}
function aula_preview_html($html,$seedId) {
    $html=preg_replace_callback('/href="(mi_aula\.php(?:\?[^"<>]*)?)"/',static function($m)use($seedId){$url=$m[1];return 'href="'.$url.(strpos($url,'?')===false?'?catalogo=1&amp;':'&amp;').'vista_previa='.(int)$seedId.'"';},$html);
    $html=preg_replace_callback('/<a\b([^>]*?)href="evaluacion\.php\?[^"<>]*"([^>]*)>/i',static function($m){
        $attrs=$m[1].$m[2];$attrs=preg_replace('/\s*target="[^"]*"/','',$attrs);
        if(preg_match('/class="/',$attrs))$attrs=preg_replace('/class="([^"]*)"/','class="$1 aula-preview-disabled"',$attrs,1);
        else $attrs.=' class="aula-preview-disabled"';
        return '<a '.$attrs.' href="#" aria-disabled="true">';
    },$html);
    $html=preg_replace('/(<form\b[^>]*>)/i','$1<fieldset disabled class="aula-preview-form">',$html);
    return str_replace('</form>','</fieldset></form>',$html);
}
