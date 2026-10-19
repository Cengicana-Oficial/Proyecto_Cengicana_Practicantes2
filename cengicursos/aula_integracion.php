<?php
require_once __DIR__.'/curso_form_helpers.php';

function aula_integrada(PDO $db) {
    static $ready=null;if($ready===null)$ready=new WeakMap();
    if(isset($ready[$db]))return true;
    try { $db->query('SELECT sincronizar FROM aula_evaluaciones LIMIT 0'); $db->query('SELECT entrega_usuario FROM aula_intentos LIMIT 0'); $db->query('SELECT id FROM aula_recursos LIMIT 0'); $db->query('SELECT campo FROM aula_notas_fuente LIMIT 0'); $ready[$db]=true;return true; }
    catch(PDOException $e) { return false; }
}
function aula_session(PDO $db,$courseId,$sessionId) {
    if(!$sessionId)return null;
    $s=aula_rows($db,'SELECT s.* FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? AND s.id=? AND s.estado<>\'cancelada\'',[$courseId,$sessionId])[0]??null;
    if(!$s)throw new InvalidArgumentException('La sesión no pertenece a este curso o fue cancelada.');
    return $s;
}
function aula_session_used(PDO $db,$sessionId) {
    if(!aula_integrada($db))return false;
    return (bool)aula_rows($db,'SELECT 1 FROM aula_recursos WHERE sesion_id=? UNION SELECT 1 FROM aula_evaluaciones WHERE sesion_id=? UNION SELECT 1 FROM aula_asistencias WHERE sesion_id=? LIMIT 1',[$sessionId,$sessionId,$sessionId]);
}
function aula_session_window(array $session, ?array $override = null, ?string $now = null) {
    $start=$session['fecha'].' '.$session['hora_inicio'];
    $end=$session['fecha'].' '.$session['hora_fin'];
    if($end<=$start)$end=date('Y-m-d H:i:s',strtotime($end.' +1 day'));
    if($override) {
        if(!$override['habilitada'])return false;
        if(!empty($override['horario_manual'])){$start=$override['abre'];$end=$override['cierra'];}
    }
    $now=$now??date('Y-m-d H:i:s');
    // Un horario especial de coordinacion puede abrir el registro antes de la fecha de la sesion.
    return $session['estado']!=='cancelada' && (!empty($override['horario_manual']) || $session['fecha']<=substr($now,0,10)) && $now>=$start && $now<$end;
}
/** Condicion SQL (alias s) de sesion ya impartida: marcada como impartida o con su horario ya terminado (hora de Guatemala en el parametro). */
const AULA_SESION_IMPARTIDA="(s.estado='impartida' OR DATE_ADD(TIMESTAMP(s.fecha,s.hora_fin),INTERVAL IF(s.hora_fin<=s.hora_inicio,1,0) DAY)<=?)";
/** Sesiones del curso ya impartidas (no canceladas): base del porcentaje de asistencia. Con la asignacion tambien
 *  cuenta la sesion en curso en la que el estudiante ya registro su asistencia (el mismo criterio del porcentaje). */
function aula_sesiones_impartidas(PDO $db,$courseId,$assignmentId=null) {
    $propia=$assignmentId&&aula_control_ready($db)?' OR EXISTS(SELECT 1 FROM aula_asistencias r WHERE r.sesion_id=s.id AND r.asignacion_id='.(int)$assignmentId.' AND r.presente=1)':'';
    return (int)aula_rows($db,'SELECT COUNT(*) total FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? AND s.estado<>\'cancelada\' AND ('.AULA_SESION_IMPARTIDA.$propia.')',[$courseId,date('Y-m-d H:i:s')])[0]['total'];
}
// El porcentaje se calcula sobre las sesiones ya impartidas (no sobre todo el programa): quien asistio a todas
// las realizadas tiene 100%. Una sesion en curso cuenta solo si el estudiante ya registro su asistencia.
function aula_attendance_sync(PDO $db,$assignmentId,$courseId) {
    $valid=aula_control_ready($db)?' AND r.presente=1 AND (r.origen=\'coordinacion\' OR r.validada=1 OR (r.registrado>=TIMESTAMP(s.fecha,s.hora_inicio) AND r.registrado<DATE_ADD(TIMESTAMP(s.fecha,s.hora_fin),INTERVAL IF(s.hora_fin<=s.hora_inicio,1,0) DAY)))':' AND r.registrado>=TIMESTAMP(s.fecha,s.hora_inicio) AND r.registrado<DATE_ADD(TIMESTAMP(s.fecha,s.hora_fin),INTERVAL IF(s.hora_fin<=s.hora_inicio,1,0) DAY)';
    $row=aula_rows($db,'SELECT COUNT(DISTINCT IF('.AULA_SESION_IMPARTIDA.' OR r.sesion_id IS NOT NULL,s.id,NULL)) total, COUNT(DISTINCT r.sesion_id) asistidas
        FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id
        LEFT JOIN aula_asistencias r ON r.sesion_id=s.id AND r.asignacion_id=?'.$valid.'
        WHERE pc.curso_id=? AND s.estado<>\'cancelada\'',[date('Y-m-d H:i:s'),$assignmentId,$courseId])[0];
    // Una cuenta sin registros del aula conserva la asistencia historica importada.
    $had=aula_rows($db,'SELECT 1 FROM aula_asistencias r JOIN asignaciones a ON a.id=r.asignacion_id WHERE a.id=? AND a.cursos_id=? LIMIT 1',[$assignmentId,$courseId]);
    if(!$had)return;
    // Sin sesiones impartidas todavia no hay porcentaje (se muestra "—" en lugar de 0%).
    $count=(int)$row['asistidas'];$pct=$row['total']?round(100*$count/$row['total'],2):null;
    $db->prepare("INSERT INTO control_cursos(asignacion_id,asistencia,sesiones_asistidas,diploma) VALUES(?,?,?,'')
        ON DUPLICATE KEY UPDATE asistencia=VALUES(asistencia),sesiones_asistidas=VALUES(sesiones_asistidas)")->execute([$assignmentId,$pct,$count]);
}
function aula_attend(PDO $db,array $assignment,$sessionId,$code='') {
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM asignaciones WHERE id=? FOR UPDATE')->execute([$assignment['id']]);
        $s=aula_session($db,$assignment['cursos_id'],$sessionId);
        $override=aula_rows($db,'SELECT * FROM aula_asistencia_ventanas WHERE sesion_id=?',[$sessionId])[0]??null;
        if(!$s || !aula_session_window($s,$override))throw new RuntimeException('La asistencia se habilita únicamente durante el horario programado de la sesión.');
        if($override && $override['codigo_hash']!=='' && !password_verify(strtoupper(trim($code)),$override['codigo_hash']))throw new RuntimeException('El código de asistencia no coincide.');
        if(aula_control_ready($db)) {
            $old=aula_rows($db,'SELECT origen FROM aula_asistencias WHERE sesion_id=? AND asignacion_id=?',[$sessionId,$assignment['id']])[0]??null;
            if($old&&$old['origen']==='coordinacion')throw new RuntimeException('Coordinación ya revisó esta asistencia. Solicita una corrección si es necesario.');
        }
        $start=$s['fecha'].' '.$s['hora_inicio'];$end=$s['fecha'].' '.$s['hora_fin'];
        if($end<=$start)$end=date('Y-m-d H:i:s',strtotime($end.' +1 day'));
        $db->prepare('INSERT INTO aula_asistencias(sesion_id,asignacion_id,registrado) VALUES(?,?,?)
            ON DUPLICATE KEY UPDATE registrado=IF(registrado>=? AND registrado<?,registrado,VALUES(registrado))')
            ->execute([$sessionId,$assignment['id'],date('Y-m-d H:i:s'),$start,$end]);
        if(aula_control_ready($db)&&!empty($override['horario_manual']))$db->prepare('UPDATE aula_asistencias SET registrado=?,validada=1 WHERE sesion_id=? AND asignacion_id=?')->execute([date('Y-m-d H:i:s'),$sessionId,$assignment['id']]);
        aula_attendance_sync($db,$assignment['id'],$assignment['cursos_id']);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function aula_eval_binding(PDO $db,$courseId,array $input) {
    $sessionId=(int)($input['sesion_id']??0);$moduleId=(int)($input['curso_modulo_id']??0);
    $session=aula_session($db,$courseId,$sessionId);
    $modules=aula_rows($db,'SELECT * FROM curso_modulos WHERE curso_id=? ORDER BY orden,id',[$courseId]);
    if(!$moduleId && $session) foreach($modules as $m) if(($session['modulo']??'')===$m['nombre'])$moduleId=(int)$m['id'];
    $field=(string)($input['campo_nota']??'posevaluacion');
    $kind=(string)($input['tipo_actividad']??'evaluacion');$sync=isset($input['sincronizar'])?1:0;
    $weight=(float)($input['peso']??1);
    if(!in_array($field,['evaluacion','posevaluacion'],true)||!in_array($kind,['evaluacion','tarea','actividad'],true)||$weight<=0||$weight>1000)throw new InvalidArgumentException('Revisa el tipo, destino de la nota y peso (mayor que 0, hasta 1000).');
    if($moduleId) {
        $selected=null;foreach($modules as $m)if((int)$m['id']===$moduleId)$selected=$m;
        if(!$selected)throw new InvalidArgumentException('El módulo no pertenece a este curso.');
        if($sync && empty($selected[$field==='evaluacion'?'acepta_pre':'acepta_post']))throw new InvalidArgumentException('El módulo no tiene habilitado ese tipo de nota. Revísalo en Cursos.');
        if($session && !empty($session['modulo']) && $selected['nombre']!==$session['modulo'])throw new InvalidArgumentException('Selecciona el módulo al que pertenece la sesión.');
    }elseif($modules&&$sync)throw new InvalidArgumentException('Este curso tiene módulos: selecciona el módulo para alimentar su nota.');
    return [$sessionId?:null,$moduleId?:null,$kind,$field,$weight,$sync,(int)($input['orden']??0)];
}
function aula_grade_sync(PDO $db,$assignmentId,$courseId) {
    if(!aula_integrada($db))return;
    $db->prepare('SELECT id FROM asignaciones WHERE id=? FOR UPDATE')->execute([$assignmentId]);
    $evals=aula_rows($db,'SELECT e.*,MAX(t.nota) AS mejor FROM aula_evaluaciones e
        LEFT JOIN aula_intentos t ON t.evaluacion_id=e.id AND t.asignacion_id=? AND t.entregado IS NOT NULL
        WHERE e.curso_id=? AND e.sincronizar=1 GROUP BY e.id',[$assignmentId,$courseId]);
    $groups=[];
    foreach($evals as $e) {
        $key=(int)$e['curso_modulo_id'].'/'.$e['campo_nota'];
        if(!isset($groups[$key]))$groups[$key]=['modulo'=>(int)$e['curso_modulo_id'],'campo'=>$e['campo_nota'],'suma'=>0,'peso'=>0];
        if($e['publicar'] && $e['mejor']!==null) {$groups[$key]['suma']+=(float)$e['mejor']*(float)$e['peso'];$groups[$key]['peso']+=(float)$e['peso'];}
    }
    $sources=aula_rows($db,'SELECT * FROM aula_notas_fuente WHERE asignacion_id=?',[$assignmentId]);
    foreach($sources as $s) {
        $key=$s['modulo_id'].'/'.$s['campo'];
        if(!isset($groups[$key]))$groups[$key]=['modulo'=>(int)$s['modulo_id'],'campo'=>$s['campo'],'suma'=>0,'peso'=>0];
    }
    $moduleTouched=false;
    foreach($groups as $g) {
        $mid=$g['modulo'];$field=$g['campo'];
        $source=aula_rows($db,'SELECT * FROM aula_notas_fuente WHERE asignacion_id=? AND modulo_id=? AND campo=?',[$assignmentId,$mid,$field])[0]??null;
        if(!$g['peso']&&!$source)continue;
        $table=$mid?'control_curso_modulos':'control_cursos';$where=$mid?'asignacion_id=? AND curso_modulo_id=?':'asignacion_id=?';$params=$mid?[$assignmentId,$mid]:[$assignmentId];
        $existing=aula_rows($db,"SELECT $field FROM $table WHERE $where",$params)[0]??null;
        if(!$source) {
            $db->prepare('INSERT INTO aula_notas_fuente(asignacion_id,modulo_id,campo,nota_anterior) VALUES(?,?,?,?)')->execute([$assignmentId,$mid,$field,$existing[$field]??null]);
        }
        $grade=$g['peso']?round($g['suma']/$g['peso'],2):$source['nota_anterior'];
        if($mid) {
            $db->prepare("INSERT INTO control_curso_modulos(asignacion_id,curso_modulo_id,$field) VALUES(?,?,?) ON DUPLICATE KEY UPDATE $field=VALUES($field)")->execute([$assignmentId,$mid,$grade]);$moduleTouched=true;
        }else {
            $db->prepare("INSERT INTO control_cursos(asignacion_id,$field,diploma) VALUES(?,?,'') ON DUPLICATE KEY UPDATE $field=VALUES($field)")->execute([$assignmentId,$grade]);
        }
        if(!$g['peso'])$db->prepare('DELETE FROM aula_notas_fuente WHERE asignacion_id=? AND modulo_id=? AND campo=?')->execute([$assignmentId,$mid,$field]);
    }
    if($moduleTouched)cengi_recalcular_resumen_curso($db,$assignmentId,$courseId);
}
function aula_sync_course(PDO $db,$courseId,$assignmentId=null) {
    if(!aula_integrada($db))return;
    $teachers=aula_rows($db,'SELECT DISTINCT i.id AS instructor_id,m.id AS modulo_id FROM curso_sesiones s
        JOIN planificacion_cursos pc ON pc.id=s.planificacion_id JOIN cursos c ON c.id=pc.curso_id
        LEFT JOIN curso_modulos m ON m.curso_id=pc.curso_id AND m.nombre=s.modulo
        JOIN instructores i ON i.id=COALESCE(s.instructor_id,(SELECT MIN(mi.instructor_id) FROM curso_modulo_instructores mi WHERE mi.curso_modulo_id=m.id),c.instructor_id)
        WHERE pc.curso_id=? AND s.estado<>\'cancelada\'',[$courseId]);
    foreach($teachers as $teacher) {
        cengi_asegurar_enlace_evaluacion_instructor($db,$courseId,$teacher['instructor_id']);
        if($teacher['modulo_id'])cengi_asegurar_enlace_evaluacion_instructor($db,$courseId,$teacher['instructor_id'],$teacher['modulo_id']);
        if(aula_control_ready($db))$db->prepare('INSERT IGNORE INTO aula_instructor_config(curso_id,instructor_id) VALUES(?,?)')->execute([$courseId,$teacher['instructor_id']]);
    }
    $params=[$courseId];$extra='';if($assignmentId){$extra=' AND id=?';$params[]=$assignmentId;}
    $assignments=aula_rows($db,'SELECT id FROM asignaciones WHERE cursos_id=? AND estado_asignaciones=1'.$extra.'
        AND (EXISTS(SELECT 1 FROM aula_asistencias r WHERE r.asignacion_id=asignaciones.id)
          OR EXISTS(SELECT 1 FROM aula_notas_fuente f WHERE f.asignacion_id=asignaciones.id)
          OR EXISTS(SELECT 1 FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE t.asignacion_id=asignaciones.id AND e.sincronizar=1))',$params);
    foreach($assignments as $a) {
        $owns=!$db->inTransaction();if($owns)$db->beginTransaction();
        try{
            $db->prepare('SELECT id FROM asignaciones WHERE id=? FOR UPDATE')->execute([$a['id']]);
            aula_attendance_sync($db,$a['id'],$courseId);aula_grade_sync($db,$a['id'],$courseId);
            if($owns)$db->commit();
        }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();throw $e;}
    }
}
function aula_resources(PDO $db,$courseId) {
    return aula_rows($db,'SELECT r.*,s.tema,s.fecha,s.hora_inicio,c.url,f.nombre,f.mime
        FROM aula_recursos r LEFT JOIN curso_sesiones s ON s.id=r.sesion_id
        LEFT JOIN curso_contenidos c ON c.id=r.contenido_id LEFT JOIN aula_archivos f ON f.id=r.archivo_id
        WHERE r.curso_id=? AND (s.id IS NULL OR s.estado<>\'cancelada\') ORDER BY s.fecha IS NULL,s.fecha,s.hora_inicio,r.orden,r.id',[$courseId]);
}
function aula_instructor_links(PDO $db,$courseId) {
    return aula_rows($db,'SELECT s.id AS sesion_id,s.tema,s.fecha,s.hora_inicio,s.hora_fin,i.id AS instructor_id,i.nombre AS instructor,ec.token AS token_curso,
        COALESCE(em.token,ec.token) AS token FROM curso_sesiones s
        JOIN planificacion_cursos pc ON pc.id=s.planificacion_id JOIN cursos c ON c.id=pc.curso_id
        LEFT JOIN curso_modulos m ON m.curso_id=pc.curso_id AND m.nombre=s.modulo
        JOIN instructores i ON i.id=COALESCE(s.instructor_id,(SELECT MIN(mi.instructor_id) FROM curso_modulo_instructores mi WHERE mi.curso_modulo_id=m.id),c.instructor_id)
        LEFT JOIN enlaces_evaluacion_instructor em ON em.curso_id=pc.curso_id AND em.instructor_id=i.id AND em.curso_modulo_id=m.id
        LEFT JOIN enlaces_evaluacion_instructor ec ON ec.curso_id=pc.curso_id AND ec.instructor_id=i.id AND ec.curso_modulo_id IS NULL
        WHERE pc.curso_id=? AND s.estado<>\'cancelada\' ORDER BY s.fecha,s.hora_inicio',[$courseId]);
}
function aula_binding_fields(array $sessions,array $modules,array $current=[]) {
    ob_start(); ?>
    <div class="aula-courses">
        <label>Sesión / actividad<select name="sesion_id"><option value="0">Curso completo</option><?php foreach($sessions as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)($current['sesion_id']??0)===(int)$s['id']?'selected':'' ?>><?= aula_h($s['fecha'].' · '.$s['tema']) ?></option><?php endforeach; ?></select></label>
        <label>Módulo de la nota<select name="curso_modulo_id"><option value="0"><?= $modules?'Elegir módulo (o tomar el de la sesión)':'Nota del curso' ?></option><?php foreach($modules as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)($current['curso_modulo_id']??0)===(int)$m['id']?'selected':'' ?>><?= aula_h($m['nombre']) ?></option><?php endforeach; ?></select></label>
        <label>Tipo<select name="tipo_actividad"><?php foreach(['evaluacion'=>'Evaluación','tarea'=>'Tarea','actividad'=>'Actividad de sesión'] as $key=>$title): ?><option value="<?= $key ?>" <?= ($current['tipo_actividad']??'evaluacion')===$key?'selected':'' ?>><?= $title ?></option><?php endforeach; ?></select></label>
        <label>Destino de la nota<select name="campo_nota"><option value="posevaluacion">Pos-evaluación / nota final</option><option value="evaluacion" <?= ($current['campo_nota']??'')==='evaluacion'?'selected':'' ?>>Pre-evaluación</option></select></label>
        <label>Peso en el promedio<input type="number" name="peso" min="0.01" max="1000" step="0.01" value="<?= aula_h($current['peso']??1) ?>" required></label>
        <label>Orden en la sesión<input type="number" name="orden" value="<?= (int)($current['orden']??0) ?>"></label>
        <label><input type="checkbox" name="sincronizar" <?= !array_key_exists('sincronizar',$current)||$current['sincronizar']?'checked':'' ?>> Alimentar Seguimiento al publicar notas</label>
    </div>
    <?php return ob_get_clean();
}
