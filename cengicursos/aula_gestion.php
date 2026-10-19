<?php
require_once __DIR__.'/menu.php';
require_once __DIR__.'/aula_helpers.php';
date_default_timezone_set('America/Guatemala');
aula_require_teacher();
$db=conectar();
$scope=cengi_scope_sql_por_nombre_ingenio('i',true);
$courses=aula_rows($db,"SELECT c.id,c.nombre_cursos FROM cursos c JOIN ingenios i ON i.id=c.ingenio_id WHERE 1=1 $scope ORDER BY c.nombre_cursos");
$cid=(int)($_GET['curso']??($courses[0]['id']??0));
$course=$cid?aula_teacher_course($db,$cid):null;
$ready=aula_ready($db)&&aula_integrada($db)&&aula_control_ready($db);$error='';$message=$_SESSION['aula_gestion_mensaje']??'';
unset($_SESSION['aula_gestion_mensaje']);
// Asistencia con un clic (js/aula_asistencia.js): marca a uno o varios participantes de una sesion y responde JSON
// con el estado de cada uno, los presentes de la sesion y el % oficial actualizado. Queda en el historial.
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['accion']??'')==='asistencia_marcar') {
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    try {
        aula_check_csrf();
        if(!$course||!$ready)throw new RuntimeException('El aula no está disponible.');
        $sid=(int)($_POST['sesion_id']??0);$s=aula_session($db,$cid,$sid);if(!$s)throw new InvalidArgumentException('Selecciona una sesión.');
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['asignaciones']??[])))));
        if(!$ids||count($ids)>500)throw new InvalidArgumentException('Indica los participantes.');
        $presente=($_POST['presente']??'1')==='1';
        $motivo=trim((string)($_POST['motivo']??''))?:'Registro rápido de asistencia (coordinación)';
        foreach($ids as $aid)aula_attendance_correct($db,$cid,$aid,$sid,$presente,$s['fecha'].'T'.substr($s['hora_inicio'],0,5),$motivo);
        $mapa=aula_asistencia_mapa($db,$cid);$oficial=aula_asistencia_oficial($db,$cid);$estados=[];
        foreach($ids as $aid){$r=$mapa[$sid][$aid]??null;$estados[$aid]=['estado'=>$r?((int)$r['presente']?'presente':'ausente'):'vacio','detalle'=>$r?((int)$r['presente']?'Presente':'Ausente').' '.date('d/m H:i',strtotime($r['registrado'])).' (coordinación)':'','pct'=>isset($oficial[$aid])&&is_numeric($oficial[$aid]['asistencia'])?round((float)$oficial[$aid]['asistencia']):null];}
        echo json_encode(['ok'=>true,'sesion'=>$sid,'estados'=>$estados,'presentes'=>count(array_filter($mapa[$sid]??[],static function($r){return (int)$r['presente']===1;}))]);
    } catch(Throwable $e) {
        http_response_code(400);
        if($e instanceof PDOException)error_log('Gestión aula (asistencia): '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException?'No se pudo guardar la asistencia.':$e->getMessage()]);
    }
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
    aula_check_csrf();
    if(!$course||!$ready)throw new RuntimeException('Aplica la migración del aula antes de guardar.');
    $action=$_POST['accion']??'';
    if($action==='teams') {
        $url=trim((string)($_POST['teams_url']??''));$sid=(int)($_POST['sesion_id']??0);
        if($url!==''&&(!aula_url($url)||strlen($url)>2048))throw new InvalidArgumentException('Ingresa un enlace válido de hasta 2048 caracteres.');
        if($sid){aula_session($db,$cid,$sid);$db->prepare('INSERT INTO aula_sesion_config(sesion_id,teams_url) VALUES(?,?) ON DUPLICATE KEY UPDATE teams_url=VALUES(teams_url)')->execute([$sid,$url]);}
        else $db->prepare('INSERT INTO aula_curso_config(curso_id,teams_url) VALUES(?,?) ON DUPLICATE KEY UPDATE teams_url=VALUES(teams_url)')->execute([$cid,$url]);
        $message='Enlace de Teams guardado. Aparece en el resumen y en las sesiones.';
    } elseif($action==='encuesta') {
        $iid=(int)($_POST['instructor_id']??0);$known=false;
        foreach(aula_instructor_surveys($db,$cid) as $survey)if((int)$survey['instructor_id']===$iid)$known=true;
        if(!$known)throw new InvalidArgumentException('El instructor no pertenece a las sesiones del curso.');
        $open=trim((string)($_POST['abre']??''));$close=trim((string)($_POST['cierra']??''));$open=$open!==''?aula_datetime($open):null;$close=$close!==''?aula_datetime($close):null;
        // Botones rapidos: abrir desde este momento (habilitada) o cerrar ya.
        $encModo=(string)($_POST['modo']??'');$encAhora=date('Y-m-d H:i:00');
        if($encModo==='abrir_ahora'){$open=$encAhora;$_POST['habilitada']=1;if($close&&$close<=$open)$close=null;}
        elseif($encModo==='cerrar_ahora'){$close=$encAhora;if(!$open||$open>=$close)$open=date('Y-m-d H:i:00',strtotime('-1 minute'));}
        if($open&&$close&&$close<=$open)throw new InvalidArgumentException('El cierre debe ser posterior a la apertura.');
        $db->prepare('INSERT INTO aula_instructor_config(curso_id,instructor_id,habilitada,abre,cierra) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE habilitada=VALUES(habilitada),abre=VALUES(abre),cierra=VALUES(cierra)')->execute([$cid,$iid,isset($_POST['habilitada'])?1:0,$open,$close]);$message=$encModo==='abrir_ahora'?'Evaluación del instructor abierta desde este momento.':($encModo==='cerrar_ahora'?'Evaluación del instructor cerrada.':'Evaluación única del instructor configurada.');
    } elseif($action==='corregir_asistencia') {
        aula_attendance_correct($db,$cid,(int)($_POST['asignacion_id']??0),(int)($_POST['sesion_id']??0),($_POST['presente']??'0')==='1',(string)($_POST['registrado']??''),(string)($_POST['motivo']??''));
        $message='Asistencia corregida y sincronizada. El cambio quedó en el historial.';
    } elseif($action==='pregunta') {
        $type=(string)($_POST['tipo']??'');$text=trim((string)($_POST['enunciado']??''));
        $criteria=trim((string)($_POST['criterio']??''));
        if(!in_array($type,['seleccion','abierta','tarea'],true)||$text===''||mb_strlen($text)>10000||mb_strlen($criteria)>10000)throw new InvalidArgumentException('Completa el enunciado de la pregunta (máximo 10000 caracteres).');
        $options=null;$correct=null;
        if($type==='seleccion') {
            $options=array_values(array_filter(array_map('trim',preg_split('/\r\n|\n|\r/',(string)($_POST['opciones']??''))),static function($v){return $v!=='';}));
            $correct=filter_var($_POST['correcta']??null,FILTER_VALIDATE_INT);
            if(count($options)<2||count($options)>10||$correct===false||!isset($options[$correct-1]))throw new InvalidArgumentException('Ingresa de 2 a 10 opciones e indica el número de la correcta.');
            $correct--;
        }
        $db->prepare('INSERT INTO aula_preguntas(curso_id,tipo,tema,enunciado,opciones,correcta,criterio,creado_por) VALUES(?,?,?,?,?,?,?,?)')->execute([$cid,$type,mb_substr(trim($_POST['tema']??''),0,160),$text,$options?aula_json($options):null,$correct,$criteria,cengi_usuario_actual_id()]);
        $message='Pregunta agregada al banco.';
    } elseif($action==='evaluacion') {
        $title=trim((string)($_POST['titulo']??''));$open=aula_datetime($_POST['abre']??'');$close=aula_datetime($_POST['cierra']??'');
        $mins=(int)($_POST['minutos']??0);$tries=(int)($_POST['intentos']??0);
        $selected=$_POST['preguntas']??[];
        if(!is_array($selected)||count($selected)>200)throw new InvalidArgumentException('Selecciona hasta 200 preguntas.');
        $ids=array_values(array_unique(array_map('intval',$selected)));
        if($title===''||mb_strlen($title)>180||$close<=$open||$mins<1||$mins>10080||$tries<1||$tries>5||!$ids)throw new InvalidArgumentException('Revisa título, fechas, duración (hasta 7 días), intentos (1–5) y preguntas.');
        $db->beginTransaction();
        $valid=aula_rows($db,'SELECT id FROM aula_preguntas WHERE curso_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([$cid],$ids));
        if(count($valid)!==count($ids))throw new RuntimeException('Todas las preguntas deben pertenecer al curso.');
        $binding=aula_eval_binding($db,$cid,$_POST);
        $db->prepare('INSERT INTO aula_evaluaciones(curso_id,titulo,abre,cierra,minutos,intentos,creado_por,sesion_id,curso_modulo_id,tipo_actividad,campo_nota,peso,sincronizar,orden) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$cid,$title,$open,$close,$mins,$tries,cengi_usuario_actual_id()],$binding));
        $eid=(int)$db->lastInsertId();$q=$db->prepare('INSERT INTO aula_evaluacion_preguntas(evaluacion_id,pregunta_id) VALUES(?,?)');foreach($ids as $id)$q->execute([$eid,$id]);$db->commit();$message='Evaluación creada deshabilitada. Habilítala cuando esté lista.';
    } elseif($action==='estado') {
        $binding=aula_eval_binding($db,$cid,$_POST);
        $open=aula_datetime($_POST['abre']??'');$close=aula_datetime($_POST['cierra']??'');$mins=(int)($_POST['minutos']??0);$tries=(int)($_POST['intentos']??0);
        if($close<=$open||$mins<1||$mins>10080||$tries<1||$tries>5)throw new InvalidArgumentException('Revisa las fechas (el cierre después de la apertura), la duración (hasta 7 días) y los intentos (1–5).');
        $db->prepare('UPDATE aula_evaluaciones SET habilitada=?,publicar=?,sesion_id=?,curso_modulo_id=?,tipo_actividad=?,campo_nota=?,peso=?,sincronizar=?,orden=?,abre=?,cierra=?,minutos=?,intentos=? WHERE id=? AND curso_id=?')->execute(array_merge([isset($_POST['habilitada'])?1:0,isset($_POST['publicar'])?1:0],$binding,[$open,$close,$mins,$tries,(int)($_POST['evaluacion']??0),$cid]));
        aula_sync_course($db,$cid);$message='Evaluación vinculada y notas sincronizadas con Seguimiento.';
    } elseif($action==='revision') {
        $db->beginTransaction();
        $a=aula_rows($db,'SELECT t.* FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE t.id=? AND e.curso_id=? AND t.entregado IS NOT NULL FOR UPDATE',[(int)($_POST['intento']??0),$cid])[0]??null;
        if(!$a)throw new RuntimeException('El intento no está disponible para revisión.');
        $questions=json_decode($a['preguntas'],true);$answers=json_decode($a['respuestas'],true);$review=[];
        foreach($questions as $p) {
            if($p['tipo']==='seleccion')continue;
            $v=$_POST['puntos'][$p['id']]??'';$feedback=trim((string)($_POST['comentarios'][$p['id']]??''));
            if(mb_strlen($feedback)>5000||!is_numeric($v)||(float)$v<0||(float)$v>1)throw new InvalidArgumentException('Califica cada respuesta entre 0 y 1 y limita el comentario a 5000 caracteres.');
            $review[(string)$p['id']]=['puntos'=>(float)$v,'comentario'=>$feedback];
        }
        $grade=aula_grade($questions,$answers,$review);
        $db->prepare('UPDATE aula_intentos SET revision=?,nota=?,revisado_por=?,revisado_en=? WHERE id=?')->execute([aula_json($review),$grade,cengi_usuario_actual_id(),date('Y-m-d H:i:s'),$a['id']]);aula_grade_sync($db,$a['asignacion_id'],$cid);$db->commit();$message='Revisión guardada. Si los resultados están publicados, la nota se actualizó en Seguimiento.';
    } elseif($action==='ventana') {
        $sid=(int)($_POST['sesion']??0);$code=strtoupper(trim((string)($_POST['codigo']??'')));
        $s=aula_session($db,$cid,$sid);
        if(!$s||($code!==''&&!preg_match('/^[A-Z0-9-]{6,30}$/',$code)))throw new InvalidArgumentException('Selecciona una sesión y deja el código vacío o usa 6–30 letras o números.');
        $open=$s['fecha'].' '.$s['hora_inicio'];$close=$s['fecha'].' '.$s['hora_fin'];if($close<=$open)$close=date('Y-m-d H:i:s',strtotime($close.' +1 day'));
        $manual=isset($_POST['horario_manual'])?1:0;
        if($manual){$open=aula_datetime($_POST['abre']??'');$close=aula_datetime($_POST['cierra']??'');if($close<=$open)throw new InvalidArgumentException('El cierre debe ser posterior a la apertura.');}
        $db->prepare('INSERT INTO aula_asistencia_ventanas(sesion_id,abre,cierra,codigo_hash,habilitada,creado_por) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE abre=VALUES(abre),cierra=VALUES(cierra),codigo_hash=VALUES(codigo_hash),habilitada=VALUES(habilitada),creado_por=VALUES(creado_por)')->execute([$sid,$open,$close,$code!==''?password_hash($code,PASSWORD_DEFAULT):'',isset($_POST['habilitada'])?1:0,cengi_usuario_actual_id()]);$message='Asistencia vinculada al horario de la sesión. Los registros alimentan Seguimiento automáticamente.';
        $db->prepare('UPDATE aula_asistencia_ventanas SET horario_manual=? WHERE sesion_id=?')->execute([$manual,$sid]);
    } elseif($action==='ventana_rapida') {
        // Botones rapidos: abrir ahora N minutos, extender hasta una fecha, volver al horario, cerrar o solo cambiar el codigo.
        $sid=(int)($_POST['sesion']??0);$s=aula_session($db,$cid,$sid);if(!$s)throw new InvalidArgumentException('La sesión no pertenece a este curso.');
        $w=aula_rows($db,'SELECT * FROM aula_asistencia_ventanas WHERE sesion_id=?',[$sid])[0]??null;
        $ini=$s['fecha'].' '.$s['hora_inicio'];$fin=$s['fecha'].' '.$s['hora_fin'];if($fin<=$ini)$fin=date('Y-m-d H:i:s',strtotime($fin.' +1 day'));
        $ahora=date('Y-m-d H:i:s');$modo=(string)($_POST['modo']??'');
        $manual=$w?(int)$w['horario_manual']:0;$abre=$manual?$w['abre']:$ini;$cierra=$manual?$w['cierra']:$fin;$hab=$w?(int)$w['habilitada']:1;
        if($modo==='ahora'){$min=max(5,min(1440,(int)($_POST['minutos']??30)));$manual=1;$hab=1;$abre=$ahora;$cierra=date('Y-m-d H:i:s',strtotime("+$min minutes"));$message='Registro abierto ahora por '.$min.' minutos (hasta las '.substr($cierra,11,5).').';}
        elseif($modo==='hasta'){$cierra=aula_datetime($_POST['hasta']??'');if($cierra<=$ahora)throw new InvalidArgumentException('La nueva hora de cierre debe ser posterior a este momento.');$manual=1;$hab=1;$abre=min($abre,$ini,$ahora);$message='Registro extendido hasta el '.date('d/m/Y H:i',strtotime($cierra)).'.';}
        elseif($modo==='auto'){$manual=0;$hab=1;$abre=$ini;$cierra=$fin;$message='El registro vuelve a seguir el horario de la sesión ('.substr($ini,11,5).'–'.substr($fin,11,5).').';}
        elseif($modo==='cerrar'){$hab=0;$message='Registro de asistencia cerrado para esta sesión. Coordinación aún puede marcar la asistencia.';}
        elseif($modo==='codigo'){$message='Código de asistencia actualizado.';}
        else throw new InvalidArgumentException('Acción de registro no válida.');
        $hash='';
        if(isset($_POST['pedir_codigo'])){$code=strtoupper(trim((string)($_POST['codigo']??'')));
            if($code!==''){if(!preg_match('/^[A-Z0-9-]{6,30}$/',$code))throw new InvalidArgumentException('El código usa 6–30 letras, números o guiones.');$hash=password_hash($code,PASSWORD_DEFAULT);$message.=' Código: '.$code.'.';}
            elseif($w&&$w['codigo_hash']!=='')$hash=$w['codigo_hash'];
            else throw new InvalidArgumentException('Escribe el código que dictarás a los estudiantes.');}
        $db->prepare('INSERT INTO aula_asistencia_ventanas(sesion_id,abre,cierra,codigo_hash,habilitada,creado_por) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE abre=VALUES(abre),cierra=VALUES(cierra),codigo_hash=VALUES(codigo_hash),habilitada=VALUES(habilitada),creado_por=VALUES(creado_por)')->execute([$sid,$abre,$cierra,$hash,$hab,cengi_usuario_actual_id()]);
        $db->prepare('UPDATE aula_asistencia_ventanas SET horario_manual=? WHERE sesion_id=?')->execute([$manual,$sid]);
    } elseif($action==='diploma_guardar'||$action==='diploma_quitar') {
        // Diploma de un participante: PDF cargado o enlace externo (Drive, Canva...). Queda con codigo de validacion
        // en Diplomas y en la ficha de Seguimiento (control_cursos.diploma), y el estudiante lo ve en su aula.
        if(!cengi_puede_gestionar_diplomas())throw new RuntimeException('Tu rol no puede gestionar diplomas.');
        $aid=(int)($_POST['asignacion']??0);
        if(!aula_rows($db,'SELECT 1 FROM asignaciones WHERE id=? AND cursos_id=? AND estado_asignaciones=1',[$aid,$cid]))throw new InvalidArgumentException('El participante no está asignado a este curso.');
        $dip=aula_rows($db,"SELECT id,codigo_unico,pdf_path FROM diplomas WHERE tipo='curso' AND asignacion_id=? ORDER BY id DESC LIMIT 1",[$aid])[0]??null;
        if($action==='diploma_quitar'){
            if(!$dip||!$dip['pdf_path'])throw new InvalidArgumentException('Ese diploma se emitió con el diseñador: adminístralo en Diplomas.');
            $db->prepare('DELETE FROM diplomas WHERE id=?')->execute([$dip['id']]);
            $db->prepare("UPDATE control_cursos SET diploma='' WHERE asignacion_id=?")->execute([$aid]);
            $message='Diploma quitado. El estudiante ya no lo verá en su aula.';
        } else {
            $enlace=trim((string)($_POST['enlace']??''));$ruta=null;
            $archivo=$_FILES['diploma_pdf']??null;
            if($archivo&&$archivo['error']!==UPLOAD_ERR_NO_FILE){
                if($archivo['error']!==UPLOAD_ERR_OK||$archivo['size']>15*1024*1024)throw new InvalidArgumentException('El PDF no se pudo cargar (máximo 15 MB).');
                if((new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name'])!=='application/pdf')throw new InvalidArgumentException('El diploma debe ser un archivo PDF.');
                $ruta=cengi_guardar_archivo_subido($archivo['tmp_name'],'diplomas_curso',time().'_'.$aid.'_'.preg_replace('/[^a-zA-Z0-9_.-]/','_',basename($archivo['name'])));
                if(!$ruta)throw new RuntimeException('No se pudo guardar el PDF.');
            } elseif($enlace!==''){
                if(mb_strlen($enlace)>255||!preg_match('#^https://#i',$enlace)||!filter_var($enlace,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Pega un enlace completo que empiece con https:// (hasta 255 caracteres).');
                $ruta=$enlace;
            } else throw new InvalidArgumentException('Selecciona el PDF del diploma o pega su enlace.');
            if($dip){$db->prepare('UPDATE diplomas SET pdf_path=?,emitido_en=NOW() WHERE id=?')->execute([$ruta,$dip['id']]);$codigo=$dip['codigo_unico'];}
            else{
                do{$codigo='CEN-DIP-'.str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);}while(aula_rows($db,'SELECT 1 FROM diplomas WHERE codigo_unico=?',[$codigo]));
                $db->prepare("INSERT INTO diplomas(tipo,asignacion_id,codigo_unico,pdf_path,emitido_en,creado_por) VALUES('curso',?,?,?,NOW(),?)")->execute([$aid,$codigo,$ruta,cengi_usuario_actual_id()]);
            }
            $db->prepare("INSERT INTO control_cursos(asignacion_id,diploma) VALUES(?,?) ON DUPLICATE KEY UPDATE diploma=VALUES(diploma)")->execute([$aid,$ruta]);
            $message='Diploma publicado (código '.$codigo.'). El estudiante ya lo ve en Notas y diploma.';
        }
    } elseif($action==='vincular') {
        if(!cengi_es_admin())throw new RuntimeException('Solo administración puede vincular cuentas.');
        $aid=(int)($_POST['asignacion']??0);$cuenta=trim((string)($_POST['usuario']??''));
        // Se acepta el correo de la cuenta (mas facil que su numero interno) o el ID.
        if(ctype_digit($cuenta))$uid=(int)$cuenta;
        else{$u=conectar_usuarios_menu_pdo()->prepare('SELECT id FROM usuarios WHERE LOWER(correo)=LOWER(?) LIMIT 2');$u->execute([$cuenta]);$encontradas=$u->fetchAll(PDO::FETCH_COLUMN);if(count($encontradas)!==1)throw new RuntimeException($encontradas?'Hay varias cuentas con ese correo: usa el ID.':'No existe una cuenta con ese correo. Créala en Usuarios del menú principal.');$uid=(int)$encontradas[0];}
        $menu=conectar_usuarios_menu_pdo();$role=cengi_rm_rol_en_modulo($menu,$uid,'cursos');
        if(!$role||!in_array(cengi_texto_normalizado($role['nombre_rol']),['estudiante','alumno','participante'],true))throw new RuntimeException('La cuenta debe tener acceso a Cursos con rol Estudiante, Alumno o Participante. Configúrala primero en Usuarios del menú principal.');
        $db->beginTransaction();
        $a=aula_rows($db,'SELECT a.id,a.usuarios_id,p.usuarios_id AS cuenta_participante FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.id=? AND a.cursos_id=? AND a.estado_asignaciones=1 FOR UPDATE',[$aid,$cid])[0]??null;
        if(!$a)throw new RuntimeException('La asignación no está activa en este curso.');
        if((!empty($a['usuarios_id'])&&(int)$a['usuarios_id']!==$uid)||(!empty($a['cuenta_participante'])&&(int)$a['cuenta_participante']!==$uid))throw new RuntimeException('Esta ficha ya está vinculada a otra cuenta. Revisa la identidad antes de reasignarla.');
        $other=aula_rows($db,'SELECT a.id FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? AND a.id<>? AND a.estado_asignaciones=1 AND (a.usuarios_id=? OR p.usuarios_id=?)',[$cid,$aid,$uid,$uid]);
        if($other)throw new RuntimeException('La cuenta ya está vinculada a otro participante de este curso.');
        $db->prepare('UPDATE asignaciones SET usuarios_id=? WHERE id=?')->execute([$uid,$aid]);$db->commit();$message='Cuenta vinculada al curso.';
    } elseif($action==='material') {
        $title=trim((string)($_POST['titulo']??''));$url=trim((string)($_POST['url']??''));
        $sid=(int)($_POST['sesion_id']??0);aula_session($db,$cid,$sid);
        $type=($_POST['tipo_recurso']??'material')==='video'?'video':'material';$contentId=null;$fileId=null;
        if($type==='video'&&$url!==''){$url=aula_video_source($url);if(!$url)throw new InvalidArgumentException('Pega el enlace o código de inserción válido del video.');}
        if($title===''||mb_strlen($title)>180)throw new InvalidArgumentException('Indica un título de hasta 180 caracteres.');
        $db->beginTransaction();
        if($url!=='') {
            if(!aula_url($url)||strlen($url)>500)throw new InvalidArgumentException('Ingresa una URL http/https válida.');
            $db->prepare('INSERT INTO curso_contenidos(curso_id,titulo,url,orden,creado_por,creado_por_nombre) VALUES(?,?,?,0,?,?)')->execute([$cid,$title,$url,cengi_usuario_actual_id(),$_SESSION['usuario']??'']);
            $contentId=(int)$db->lastInsertId();
        } else {
            if(($_FILES['material']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)throw new InvalidArgumentException('Selecciona un PDF o ingresa un enlace.');
            $fileId=aula_upload($db,$_FILES['material'],$cid,$title,null,null,$type==='video');
        }
        $desde=trim((string)($_POST['disponible_desde']??''));$desde=$desde!==''?aula_datetime($desde):null;
        $db->prepare('INSERT INTO aula_recursos(curso_id,sesion_id,tipo,titulo,contenido_id,archivo_id,orden) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$sid?:null,$type,$title,$contentId,$fileId,(int)($_POST['orden']??0)]);$recursoId=(int)$db->lastInsertId();
        if(aula_vista_ready($db))$db->prepare('UPDATE aula_recursos SET visible=?,disponible_desde=? WHERE id=?')->execute([isset($_POST['visible'])?1:0,$desde,$recursoId]);$db->commit();$message='Recurso publicado en la sesión del curso.';
    } elseif($action==='vista') {
        // Lo que ve el estudiante: secciones visibles y anuncio del curso.
        $visibles=array_values(array_intersect((array)($_POST['secciones']??[]),array_keys(AULA_SECCIONES_CONFIGURABLES)));
        aula_vista_guardar($db,$cid,$visibles,'',''); // el anuncio se gestiona en Anuncios (aula_anuncios)
        $message='Vista del estudiante actualizada. Usa “Ver como estudiante” para revisarla.';
    } elseif($action==='recurso_visibilidad') {
        if(!aula_vista_ready($db))throw new RuntimeException('Aplica la migración 20261021_aula_vista_estudiante.sql.');
        $desde=trim((string)($_POST['disponible_desde']??''));$desde=$desde!==''?aula_datetime($desde):null;
        $q=$db->prepare('UPDATE aula_recursos SET visible=?,disponible_desde=? WHERE id=? AND curso_id=?');
        $q->execute([isset($_POST['visible'])?1:0,$desde,(int)($_POST['recurso']??0),$cid]);
        if(!$q->rowCount()&&!aula_rows($db,'SELECT 1 FROM aula_recursos WHERE id=? AND curso_id=?',[(int)($_POST['recurso']??0),$cid]))throw new InvalidArgumentException('El recurso no pertenece al curso.');
        $message='Visibilidad del recurso actualizada.';
    } elseif($action==='recurso_editar') {
        // Editor por sesion: titulo, enlace o archivo, sesion, orden y visibilidad de un recurso publicado.
        if(!aula_vista_ready($db))throw new RuntimeException('Aplica la migración 20261021_aula_vista_estudiante.sql.');
        $title=trim((string)($_POST['titulo']??''));if($title===''||mb_strlen($title)>180)throw new InvalidArgumentException('Indica un título de hasta 180 caracteres.');
        $sid=(int)($_POST['sesion_id']??0);aula_session($db,$cid,$sid);
        $desde=trim((string)($_POST['disponible_desde']??''));$desde=$desde!==''?aula_datetime($desde):null;
        $db->beginTransaction();
        $r=aula_rows($db,'SELECT * FROM aula_recursos WHERE id=? AND curso_id=? FOR UPDATE',[(int)($_POST['recurso']??0),$cid])[0]??null;
        if(!$r)throw new InvalidArgumentException('El recurso no pertenece al curso.');
        if($r['contenido_id']) {
            $url=trim((string)($_POST['url']??''));if($r['tipo']==='video'&&$url!=='')$url=aula_video_source($url)?:'';
            if(!aula_url($url)||strlen($url)>500)throw new InvalidArgumentException($r['tipo']==='video'?'Pega el enlace o código de inserción válido del video.':'Ingresa una URL http/https válida.');
            $db->prepare('UPDATE curso_contenidos SET titulo=?,url=? WHERE id=? AND curso_id=?')->execute([$title,$url,$r['contenido_id'],$cid]);
        }
        $fileId=$r['archivo_id'];
        if($r['archivo_id']&&($_FILES['material']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
            $fileId=aula_upload($db,$_FILES['material'],$cid,$title,null,null,$r['tipo']==='video');
        }
        $db->prepare('UPDATE aula_recursos SET titulo=?,sesion_id=?,orden=?,visible=?,disponible_desde=?,archivo_id=? WHERE id=?')->execute([$title,$sid?:null,(int)($_POST['orden']??0),isset($_POST['visible'])?1:0,$desde,$fileId,$r['id']]);
        if($fileId!==$r['archivo_id'])$db->prepare('DELETE FROM aula_archivos WHERE id=? AND curso_id=? AND intento_id IS NULL AND NOT EXISTS(SELECT 1 FROM aula_recursos WHERE archivo_id=?)')->execute([$r['archivo_id'],$cid,$r['archivo_id']]);
        elseif($r['archivo_id'])$db->prepare('UPDATE aula_archivos SET titulo=? WHERE id=? AND curso_id=?')->execute([mb_substr($title,0,180),$r['archivo_id'],$cid]);
        $db->commit();$message='Recurso actualizado.';
    } elseif($action==='recurso_eliminar') {
        $db->beginTransaction();
        $r=aula_rows($db,'SELECT * FROM aula_recursos WHERE id=? AND curso_id=? FOR UPDATE',[(int)($_POST['recurso']??0),$cid])[0]??null;
        if(!$r)throw new InvalidArgumentException('El recurso no pertenece al curso.');
        $db->prepare('DELETE FROM aula_recursos WHERE id=?')->execute([$r['id']]);
        // El enlace o archivo se borra solo si ningun otro recurso lo usa.
        if($r['contenido_id'])$db->prepare('DELETE FROM curso_contenidos WHERE id=? AND curso_id=? AND NOT EXISTS(SELECT 1 FROM aula_recursos WHERE contenido_id=?)')->execute([$r['contenido_id'],$cid,$r['contenido_id']]);
        if($r['archivo_id'])$db->prepare('DELETE FROM aula_archivos WHERE id=? AND curso_id=? AND intento_id IS NULL AND NOT EXISTS(SELECT 1 FROM aula_recursos WHERE archivo_id=?)')->execute([$r['archivo_id'],$cid,$r['archivo_id']]);
        $db->commit();$message='Recurso eliminado: el estudiante ya no lo ve.';
    } elseif($action==='evaluacion_rapida') {
        // Edicion rapida desde la sesion: disponibilidad, fechas, duracion e intentos.
        $open=aula_datetime($_POST['abre']??'');$close=aula_datetime($_POST['cierra']??'');$mins=(int)($_POST['minutos']??0);$tries=(int)($_POST['intentos']??0);
        if($close<=$open||$mins<1||$mins>10080||$tries<1||$tries>5)throw new InvalidArgumentException('Revisa fechas (el cierre después de la apertura), duración (hasta 7 días) e intentos (1–5).');
        $q=$db->prepare('UPDATE aula_evaluaciones SET habilitada=?,publicar=?,abre=?,cierra=?,minutos=?,intentos=? WHERE id=? AND curso_id=?');
        $q->execute([isset($_POST['habilitada'])?1:0,isset($_POST['publicar'])?1:0,$open,$close,$mins,$tries,(int)($_POST['evaluacion']??0),$cid]);
        if(!$q->rowCount()&&!aula_rows($db,'SELECT 1 FROM aula_evaluaciones WHERE id=? AND curso_id=?',[(int)($_POST['evaluacion']??0),$cid]))throw new InvalidArgumentException('La actividad no pertenece al curso.');
        aula_sync_course($db,$cid);$message='Actividad actualizada. Si publicaste notas, Seguimiento ya está sincronizado.';
    } elseif($action==='anuncio_guardar') {
        if(!aula_vista_ready($db))throw new RuntimeException('Aplica la migración 20261022_aula_anuncios.sql.');
        $title=trim((string)($_POST['titulo']??''));$msg=trim((string)($_POST['mensaje']??''));$url=trim((string)($_POST['enlace_url']??''));
        if($title===''||mb_strlen($title)>180||mb_strlen($msg)>5000)throw new InvalidArgumentException('Indica un título (hasta 180 caracteres) y un mensaje de hasta 5000.');
        if($url!==''&&(!aula_url($url)||strlen($url)>2048))throw new InvalidArgumentException('El enlace debe ser una dirección http/https válida.');
        $sid=(int)($_POST['sesion_id']??0);aula_session($db,$cid,$sid);
        $desde=trim((string)($_POST['publicar_desde']??''));$desde=$desde!==''?aula_datetime($desde):null;
        $datos=[$sid?:null,$title,$msg!==''?$msg:null,$url,mb_substr(trim((string)($_POST['enlace_texto']??'')),0,80),$desde,isset($_POST['visible'])?1:0,isset($_POST['fijado'])?1:0];
        $aid=(int)($_POST['anuncio']??0);
        if($aid){
            $q=$db->prepare('UPDATE aula_anuncios SET sesion_id=?,titulo=?,mensaje=?,enlace_url=?,enlace_texto=?,publicar_desde=?,visible=?,fijado=? WHERE id=? AND curso_id=?');
            $q->execute(array_merge($datos,[$aid,$cid]));
            if(!$q->rowCount()&&!aula_rows($db,'SELECT 1 FROM aula_anuncios WHERE id=? AND curso_id=?',[$aid,$cid]))throw new InvalidArgumentException('El anuncio no pertenece al curso.');
        } else {
            $db->prepare('INSERT INTO aula_anuncios(curso_id,sesion_id,titulo,mensaje,enlace_url,enlace_texto,publicar_desde,visible,fijado,creado_por,creado_por_nombre) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge([$cid],$datos,[cengi_usuario_actual_id()?:null,mb_substr((string)($_SESSION['usuario']??''),0,255)]));
        }
        // "Guardar tambien como enlace de Teams": el mismo enlace queda como el Teams del curso o de la sesion.
        if(!empty($_POST['guardar_teams'])&&$url!=='') {
            if($sid)$db->prepare('INSERT INTO aula_sesion_config(sesion_id,teams_url) VALUES(?,?) ON DUPLICATE KEY UPDATE teams_url=VALUES(teams_url)')->execute([$sid,$url]);
            else $db->prepare('INSERT INTO aula_curso_config(curso_id,teams_url) VALUES(?,?) ON DUPLICATE KEY UPDATE teams_url=VALUES(teams_url)')->execute([$cid,$url]);
        }
        $message=$aid?'Anuncio actualizado.':'Anuncio publicado en el aula del estudiante.';
    } elseif($action==='anuncio_eliminar') {
        $q=$db->prepare('DELETE FROM aula_anuncios WHERE id=? AND curso_id=?');$q->execute([(int)($_POST['anuncio']??0),$cid]);
        if(!$q->rowCount())throw new InvalidArgumentException('El anuncio no pertenece al curso.');
        $message='Anuncio eliminado.';
    } elseif($action==='tarea_rapida') {
        // Tarea de la sesion en un paso: crea la consigna (pregunta tipo tarea, con entrega de texto o PDF) y la actividad.
        $sid=(int)($_POST['sesion_id']??0);$s=aula_session($db,$cid,$sid);if(!$s)throw new InvalidArgumentException('Selecciona una sesión.');
        $title=trim((string)($_POST['titulo']??''));$instr=trim((string)($_POST['enunciado']??''));$crit=trim((string)($_POST['criterio']??''));
        $open=aula_datetime($_POST['abre']??'');$close=aula_datetime($_POST['cierra']??'');$tries=(int)($_POST['intentos']??1);
        if($title===''||mb_strlen($title)>180||$instr===''||mb_strlen($instr)>10000||mb_strlen($crit)>10000)throw new InvalidArgumentException('Indica el título y las instrucciones de la tarea.');
        if($close<=$open||$tries<1||$tries>5)throw new InvalidArgumentException('El cierre debe ser posterior a la apertura e intentos entre 1 y 5.');
        $mins=(int)max(1,min(10080,ceil((strtotime($close)-strtotime($open))/60)));
        $mod=aula_rows($db,'SELECT id FROM curso_modulos WHERE curso_id=? AND nombre=? LIMIT 1',[$cid,(string)($s['modulo']??'')])[0]['id']??null;
        $db->beginTransaction();
        $db->prepare("INSERT INTO aula_preguntas(curso_id,tipo,tema,enunciado,opciones,correcta,criterio,creado_por) VALUES(?,'tarea',?,?,NULL,NULL,?,?)")->execute([$cid,mb_substr($s['tema'],0,160),$instr,$crit,cengi_usuario_actual_id()]);
        $pid=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO aula_evaluaciones(curso_id,titulo,habilitada,abre,cierra,minutos,intentos,creado_por,sesion_id,curso_modulo_id,tipo_actividad,campo_nota,peso,sincronizar,orden) VALUES(?,?,?,?,?,?,?,?,?,?,'tarea','posevaluacion',1,1,0)")
            ->execute([$cid,$title,isset($_POST['habilitada'])?1:0,$open,$close,$mins,$tries,cengi_usuario_actual_id(),$sid,$mod]);
        $db->prepare('INSERT INTO aula_evaluacion_preguntas(evaluacion_id,pregunta_id) VALUES(?,?)')->execute([(int)$db->lastInsertId(),$pid]);
        $db->commit();$message='Tarea creada en la sesión'.(isset($_POST['habilitada'])?' y habilitada para los estudiantes.':'. Habilítala cuando esté lista.');
    } elseif($action==='sesion_impartida') {
        // Registrar la sesion como impartida (mismas reglas que Calendario / Programa de sesiones).
        require_once __DIR__.'/planificacion_helpers.php';
        $db->beginTransaction();
        $s=aula_rows($db,'SELECT s.* FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? AND s.id=? FOR UPDATE',[$cid,(int)($_POST['sesion_id']??0)])[0]??null;
        if(!$s)throw new InvalidArgumentException('La sesión no pertenece al curso.');
        if($s['estado']!=='programada')throw new InvalidArgumentException('Solo se registra como impartida una sesión programada.');
        if($s['fecha']>date('Y-m-d'))throw new InvalidArgumentException('No se puede registrar como impartida una sesión futura.');
        $plan=cengi_pp_plan($db,$s['planificacion_id']);if(!$plan||$plan['estado']==='cancelado')throw new InvalidArgumentException('El curso de esta sesión está cancelado.');
        $db->prepare("UPDATE curso_sesiones SET estado='impartida' WHERE id=?")->execute([$s['id']]);
        cengi_pp_log($db,$plan,'Sesión impartida','Sesión del '.cengi_pp_fecha($s['fecha']).' — '.$s['tema'].' (desde el aula)',['antes'=>'programada','despues'=>'impartida','sesion_id'=>(int)$s['id'],'afecta'=>false]);
        cengi_pp_asignar_modulos($db,$s['planificacion_id']);
        $db->commit();$message='Sesión registrada como impartida: ya cuenta para el pago del instructor.';
    } elseif($action==='instructor_agregar') {
        // Evaluacion de un instructor del catalogo que no aparece en las sesiones (invitado, conferencista...).
        $iid=(int)($_POST['instructor_id']??0);
        if(!aula_rows($db,'SELECT 1 FROM instructores WHERE id=? AND estado=1',[$iid]))throw new InvalidArgumentException('Selecciona un instructor activo.');
        $open=trim((string)($_POST['abre']??''));$open=$open!==''?aula_datetime($open):date('Y-m-d H:i:s');
        $close=trim((string)($_POST['cierra']??''));$close=$close!==''?aula_datetime($close):null;
        if($close&&$close<=$open)throw new InvalidArgumentException('El cierre debe ser posterior a la apertura.');
        cengi_asegurar_enlace_evaluacion_instructor($db,$cid,$iid);
        $db->prepare('INSERT INTO aula_instructor_config(curso_id,instructor_id,habilitada,abre,cierra) VALUES(?,?,1,?,?) ON DUPLICATE KEY UPDATE habilitada=1,abre=VALUES(abre),cierra=VALUES(cierra)')->execute([$cid,$iid,$open,$close]);
        $message='Instructor agregado: los estudiantes verán su evaluación en “Evaluar al instructor”.';
    } elseif($action==='asistencia_lote') {
        // Marca presentes / ausentes a varios participantes de una sesion (queda en el historial con el motivo).
        $sid=(int)($_POST['sesion_id']??0);$s=aula_session($db,$cid,$sid);if(!$s)throw new InvalidArgumentException('Selecciona una sesión.');
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['asignaciones']??[])))));
        if(!$ids)throw new InvalidArgumentException('Marca al menos un participante.');
        $presente=($_POST['presente']??'1')==='1';$motivo=trim((string)($_POST['motivo']??''))?:'Registro de asistencia por coordinación';
        foreach($ids as $aid)aula_attendance_correct($db,$cid,$aid,$sid,$presente,$s['fecha'].'T'.substr($s['hora_inicio'],0,5),$motivo);
        $message=count($ids).' participante(s) marcado(s) como '.($presente?'presentes':'ausentes').'. Seguimiento actualizado.';
    } else throw new InvalidArgumentException('Acción no válida.');
    $_SESSION['aula_gestion_mensaje']=$message;
    $anchors=['pregunta'=>'banco','evaluacion'=>'evaluaciones','estado'=>'evaluaciones','revision'=>'revision','ventana'=>'asistencia','material'=>'material','teams'=>'conexion','encuesta'=>'encuestas','corregir_asistencia'=>'asistencia','vista'=>'vista','recurso_visibilidad'=>'vista','anuncio_guardar'=>'anuncios','anuncio_eliminar'=>'anuncios','instructor_agregar'=>'encuestas'];
    $reviewSuffix=$action==='revision'?'&revision='.(int)($_POST['intento']??0):'';
    // Los formularios del editor por sesion indican a que sesion volver (ancla ses-<id>).
    $ancla=preg_match('/^[a-z0-9-]{1,40}$/',(string)($_POST['ancla']??''))?$_POST['ancla']:($anchors[$action]??'');
    $listaSuffix=(int)($_POST['lista']??0)>0?'&lista='.(int)$_POST['lista']:'';
    header('Location: aula_gestion.php?curso='.$cid.$reviewSuffix.$listaSuffix.'#'.$ancla);exit;
 } catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack();
    if($e instanceof PDOException){error_log('Gestión aula: '.$e->getMessage());$error='No se pudo guardar. Verifica la migración del aula e intenta de nuevo.';}else $error=$e->getMessage();
 }
}
if($course&&$ready){aula_expire($db,$cid);aula_sync_course($db,$cid);}
// El anuncio unico anterior (Lo que ve el estudiante) pasa a la lista de Anuncios una sola vez.
if($course&&$ready&&aula_vista_ready($db)) {
    $legado=aula_vista_config($db,$cid);
    if(trim($legado['anuncio_titulo'].$legado['anuncio'])!=='') {
        $db->prepare('INSERT INTO aula_anuncios(curso_id,titulo,mensaje,fijado,creado_por,creado_por_nombre) VALUES(?,?,?,1,?,?)')->execute([$cid,$legado['anuncio_titulo']?:'Anuncio del curso',$legado['anuncio']!==''?$legado['anuncio']:null,cengi_usuario_actual_id()?:null,mb_substr((string)($_SESSION['usuario']??''),0,255)]);
        $db->prepare("UPDATE aula_curso_config SET anuncio_titulo='',anuncio=NULL WHERE curso_id=?")->execute([$cid]);
    }
}
$bank=$course&&$ready?aula_rows($db,'SELECT * FROM aula_preguntas WHERE curso_id=? ORDER BY tema,id',[$cid]):[];
$evals=$course&&$ready?aula_rows($db,'SELECT * FROM aula_evaluaciones WHERE curso_id=? ORDER BY id DESC',[$cid]):[];
$sessions=$course?aula_rows($db,'SELECT s.* FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? AND s.estado<>\'cancelada\' ORDER BY s.fecha,s.hora_inicio',[$cid]):[];
$modules=$course?aula_rows($db,'SELECT * FROM curso_modulos WHERE curso_id=? ORDER BY orden,id',[$cid]):[];
if($course&&$ready) foreach($sessions as $session) {
    if(empty($session['instructor_id']))continue;
    cengi_asegurar_enlace_evaluacion_instructor($db,$cid,$session['instructor_id']);
    foreach($modules as $module) if(($session['modulo']??'')===$module['nombre'])cengi_asegurar_enlace_evaluacion_instructor($db,$cid,$session['instructor_id'],$module['id']);
}
// Instructores tomados del modulo o del curso (sesiones sin instructor): tambien necesitan su enlace de evaluacion.
if($course&&$ready) foreach(array_unique(array_map('intval',array_column(aula_instructor_links($db,$cid),'instructor_id'))) as $instructorAula)cengi_asegurar_enlace_evaluacion_instructor($db,$cid,$instructorAula);
// Editor por sesion: el contenido de cada sesion se pide al abrirla (aula_sesiones_gestion.php).
if(isset($_GET['ed_sesion'])) {
    header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');
    if(!$course||!$ready){http_response_code(404);exit;}
    $edSoloSesion=(int)$_GET['ed_sesion'];
    include __DIR__.'/aula_sesiones_gestion.php';
    exit;
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gestión del aula | Cengicursos</title><link rel="stylesheet" href="css/bootstrap.min.css"><link rel="stylesheet" href="css/proyecto.css?v=<?= filemtime(__DIR__.'/css/proyecto.css') ?>"><link rel="stylesheet" href="css/cengi-tema.css?v=<?= filemtime(__DIR__.'/css/cengi-tema.css') ?>"><link rel="stylesheet" href="css/aula.css?v=<?= filemtime(__DIR__.'/css/aula.css') ?>"></head><body class="cengi-canvas cg-tema"><?php menu_render(); ?><main class="container aula-page">
<header class="aula-hero"><div><small>COORDINACIÓN Y DOCENCIA</small><h1>Gestión del aula</h1><a href="aulas.php?curso=<?= $cid ?>">Control de aulas y vista por estudiante →</a><p>Publica recursos, prepara evaluaciones y retroalimenta a tus participantes.</p></div></header>
<form method="get" class="aula-inline aula-card"><label>Curso <select name="curso"><?php foreach($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===$cid?'selected':'' ?>><?= aula_h($c['nombre_cursos']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Abrir</button><?php if($cid): ?><a href="ver_participante_curso.php?id=<?= $cid ?>">Seguimiento y notas oficiales →</a><?php endif; ?></form>
<?php if($error): ?><p class="alert alert-danger" role="alert"><?= aula_h($error) ?></p><?php endif; if($message): ?><p class="alert alert-success" role="status"><?= aula_h($message) ?></p><?php endif; ?>
<?php if(!$ready): ?><p class="alert alert-info">Para habilitar esta sección aplica la migración <code>20261020_aula_control.sql</code> en la base de cursos.</p><?php elseif($course): ?>
<?php $porRevisar=(int)(aula_rows($db,'SELECT COUNT(*) AS n FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE e.curso_id=? AND t.entregado IS NOT NULL AND t.nota IS NULL',[$cid])[0]['n']??0); ?>
<nav class="aula-paneles" role="tablist" aria-label="Secciones de la gestión del aula">
    <button type="button" data-panel-tab="sesiones"><span class="glyphicon glyphicon-th-list" aria-hidden="true"></span> Sesiones</button>
    <button type="button" data-panel-tab="asistencia"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> Asistencia</button>
    <button type="button" data-panel-tab="evaluaciones"><span class="glyphicon glyphicon-check" aria-hidden="true"></span> Tareas y evaluaciones<?= $porRevisar?' <b>'.$porRevisar.'</b>':'' ?></button>
    <button type="button" data-panel-tab="anuncios"><span class="glyphicon glyphicon-bullhorn" aria-hidden="true"></span> Anuncios</button>
    <button type="button" data-panel-tab="instructores"><span class="glyphicon glyphicon-education" aria-hidden="true"></span> Instructores</button>
    <button type="button" data-panel-tab="inicio"><span class="glyphicon glyphicon-eye-open" aria-hidden="true"></span> Vista del estudiante</button>
    <button type="button" data-panel-tab="diplomas"><span class="glyphicon glyphicon-certificate" aria-hidden="true"></span> Diplomas</button>
    <button type="button" data-panel-tab="config"><span class="glyphicon glyphicon-cog" aria-hidden="true"></span> Configuración</button>
</nav>
<?php include __DIR__.'/aula_vista_gestion.php'; include __DIR__.'/aula_anuncios_gestion.php'; include __DIR__.'/aula_sesiones_gestion.php'; include __DIR__.'/aula_asistencia_gestion.php'; include __DIR__.'/aula_control_gestion.php'; include __DIR__.'/aula_diplomas_gestion.php'; ?>
<details class="aula-card" id="banco" open><summary>Banco de preguntas · <?= count($bank) ?></summary><form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="pregunta"><div class="aula-courses"><label>Tipo<select name="tipo"><option value="seleccion">Selección múltiple (una respuesta correcta)</option><option value="abierta">Pregunta abierta</option><option value="tarea">Tarea con entrega PDF</option></select></label><label>Tema / módulo<input name="tema" maxlength="160"></label></div><label>Enunciado<textarea name="enunciado" required maxlength="10000" rows="3"></textarea></label><div class="aula-courses"><label>Opciones (una por línea, solo selección múltiple)<textarea name="opciones" rows="4"></textarea></label><label>Número de la opción correcta<input name="correcta" type="number" min="1" max="10"><small>Se omiten líneas vacías. Se autocalifica sobre estas opciones.</small></label></div><label>Criterios para preguntas abiertas / tareas<textarea name="criterio" rows="2" maxlength="10000"></textarea></label><button class="btn btn-primary">Agregar al banco</button></form>
<?php foreach($bank as $p): ?><div class="aula-row"><span><strong>#<?= (int)$p['id'] ?> · <?= aula_h($p['tema']) ?></strong><br><?= aula_h($p['enunciado']) ?></span><span class="aula-badge"><?= aula_h($p['tipo']) ?></span></div><?php endforeach; ?></details>
<details class="aula-card" id="evaluaciones"><summary>Evaluaciones · <?= count($evals) ?></summary><form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="evaluacion"><label>Título<input name="titulo" required maxlength="180"></label><?= aula_binding_fields($sessions,$modules,['sesion_id'=>(int)($_GET['sesion']??0)]) ?><div class="aula-courses"><label>Apertura (Guatemala)<input type="datetime-local" name="abre" required></label><label>Cierre (Guatemala)<input type="datetime-local" name="cierra" required></label><label>Duración en minutos<input type="number" name="minutos" value="60" min="1" max="10080" required></label><label>Intentos permitidos<input type="number" name="intentos" value="1" min="1" max="5" required></label></div><fieldset class="aula-question"><legend>Preguntas del banco</legend><p>Todas tienen el mismo peso. El orden se mezcla al iniciar cada intento.</p><?php foreach($bank as $p): ?><label class="aula-option"><input type="checkbox" name="preguntas[]" value="<?= (int)$p['id'] ?>"> #<?= (int)$p['id'] ?> <?= aula_h($p['enunciado']) ?></label><?php endforeach; ?></fieldset><button class="btn btn-primary">Crear evaluación</button></form>
<?php $evAhora=date('Y-m-d H:i:s'); foreach($evals as $e): $evEstado=!$e['habilitada']?['Deshabilitada','is-oculto']:($evAhora<$e['abre']?['Abre '.aula_fecha_larga($e['abre'],true).' '.substr($e['abre'],11,5),'is-programado']:($evAhora>=$e['cierra']?['Cerrada el '.aula_fecha_larga($e['cierra'],true).' '.substr($e['cierra'],11,5),'is-oculto']:['Abierta · cierra '.aula_fecha_larga($e['cierra'],true).' '.substr($e['cierra'],11,5),'is-complete'])); ?><details class="aula-eval-config" id="ev-<?= (int)$e['id'] ?>"><summary><?= aula_h($e['titulo']) ?> · <?= aula_h($e['tipo_actividad']) ?> · <?= $e['publicar']?'Notas publicadas':'Por publicar' ?> <span class="aula-badge <?= $evEstado[1] ?>"><?= aula_h($evEstado[0]) ?></span></summary><form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="evaluacion" value="<?= (int)$e['id'] ?>"><input type="hidden" name="ancla" value="ev-<?= (int)$e['id'] ?>"><div class="aula-courses aula-eval-fechas"><label>Apertura (Guatemala)<input type="datetime-local" name="abre" required value="<?= aula_h(str_replace(' ','T',substr($e['abre'],0,16))) ?>"></label><label>Cierre (Guatemala)<input type="datetime-local" name="cierra" required value="<?= aula_h(str_replace(' ','T',substr($e['cierra'],0,16))) ?>"><small><a href="#" data-ev-extender="1">+1 día</a> · <a href="#" data-ev-extender="7">+1 semana</a></small></label><label>Duración por intento (min)<input type="number" name="minutos" min="1" max="10080" required value="<?= (int)$e['minutos'] ?>"></label><label>Intentos permitidos<input type="number" name="intentos" min="1" max="5" required value="<?= (int)$e['intentos'] ?>"></label></div><p class="aula-muted">Cambiar el cierre afecta a los intentos que se inicien desde ahora; el tiempo de un intento ya iniciado no cambia.</p><?= aula_binding_fields($sessions,$modules,$e) ?><div class="aula-inline"><label><input type="checkbox" name="habilitada" <?= $e['habilitada']?'checked':'' ?>> Habilitada</label><label><input type="checkbox" name="publicar" <?= $e['publicar']?'checked':'' ?>> Publicar notas y alimentar seguimiento</label><button class="btn btn-primary">Guardar relación y estado</button></div></form></details><?php endforeach; ?></details>
<details class="aula-card" id="revision"><summary>Revisión docente y retroalimentación</summary>
<?php $attempts=aula_rows($db,'SELECT t.id,t.numero,t.nota,t.entregado,e.titulo,p.nombre_participantes FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id JOIN asignaciones a ON a.id=t.asignacion_id JOIN participantes p ON p.id=a.participantes_id WHERE e.curso_id=? ORDER BY t.id DESC LIMIT 100',[$cid]); foreach($attempts as $a): ?><div class="aula-row"><span><?= aula_h($a['nombre_participantes'].' · '.$a['titulo']) ?> · Intento <?= (int)$a['numero'] ?></span><a href="aula_gestion.php?curso=<?= $cid ?>&amp;revision=<?= (int)$a['id'] ?>#revision"><?= !$a['entregado']?'En curso':($a['nota']===null?'Por revisar':aula_h($a['nota']).' / 100') ?></a></div><?php endforeach; ?>
<?php $reviewId=(int)($_GET['revision']??0);$a=$reviewId?(aula_rows($db,'SELECT t.*,e.titulo FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE t.id=? AND e.curso_id=? AND t.entregado IS NOT NULL',[$reviewId,$cid])[0]??null):null; if($a): $qs=json_decode($a['preguntas'],true);$ans=json_decode($a['respuestas'],true);$rev=json_decode($a['revision']??'{}',true)?:[]; ?>
<form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="revision"><input type="hidden" name="intento" value="<?= $reviewId ?>"><h2><?= aula_h($a['titulo']) ?></h2><?php foreach($qs as $p): ?><fieldset class="aula-question"><legend><?= aula_h($p['enunciado']) ?></legend><p class="aula-answer"><?php if($p['tipo']==='seleccion'): $opts=json_decode($p['opciones'],true); echo aula_h($opts[$ans[$p['id']]??'']??'Sin respuesta'); else: echo aula_h($ans[$p['id']]??'Sin respuesta'); endif; ?></p>
<?php if($p['tipo']==='seleccion'): ?><p>Correcta: <?= aula_h($opts[$p['correcta']]??'') ?> · Calificación automática.</p><?php else: ?><p>Criterio: <?= aula_h($p['criterio']) ?></p><?php foreach(aula_rows($db,'SELECT id,nombre FROM aula_archivos WHERE intento_id=? AND pregunta_id=?',[$reviewId,$p['id']]) as $f): ?><p><a href="aula_archivo.php?id=<?= (int)$f['id'] ?>"><?= aula_h($f['nombre']) ?></a></p><?php endforeach; ?><label>Puntos (0–1; admite parciales)<input type="number" name="puntos[<?= (int)$p['id'] ?>]" min="0" max="1" step="0.01" required value="<?= aula_h($rev[$p['id']]['puntos']??'') ?>"></label><label>Retroalimentación<textarea name="comentarios[<?= (int)$p['id'] ?>]" maxlength="5000" rows="2"><?= aula_h($rev[$p['id']]['comentario']??'') ?></textarea></label><?php endif; ?></fieldset><?php endforeach; ?><button class="btn btn-primary">Guardar revisión y calcular nota</button></form><?php endif; ?></details>
<details class="aula-card" id="asistencia"><summary>Registro de asistencia</summary><form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="ventana"><label>Sesión<select name="sesion" required><?php foreach($sessions as $s): ?><option value="<?= (int)$s['id'] ?>"><?= aula_h($s['fecha'].' '.$s['tema']) ?></option><?php endforeach; ?></select></label><div class="aula-courses"><p>Por defecto utiliza Programa de sesiones. También puedes habilitar un horario especial para un registro tardío.</p><label><input type="checkbox" name="horario_manual"> Usar horario especial</label><label>Abrir registro especial<input type="datetime-local" name="abre"></label><label>Cerrar registro especial<input type="datetime-local" name="cierra"></label><label>Código opcional (vacío = registro directo)<input name="codigo" maxlength="30" autocomplete="off"></label><label><input type="checkbox" name="habilitada" checked> Registro habilitado</label></div><button class="btn btn-primary">Guardar ventana</button></form>
<?php $regs=aula_rows($db,'SELECT s.tema,s.fecha,p.nombre_participantes,r.registrado FROM aula_asistencias r JOIN curso_sesiones s ON s.id=r.sesion_id JOIN asignaciones a ON a.id=r.asignacion_id JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? ORDER BY r.registrado DESC LIMIT 100',[$cid]);foreach($regs as $r): ?><div class="aula-row"><span><?= aula_h($r['nombre_participantes'].' · '.$r['tema']) ?></span><span><?= aula_h($r['registrado']) ?></span></div><?php endforeach; ?><p>Los registros actualizan las sesiones asistidas y el porcentaje del participante en Seguimiento. El porcentaje usa el total de sesiones programadas no canceladas.</p></details>
<details class="aula-card" id="material"><summary>Material y videos</summary><form method="post" enctype="multipart/form-data" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="material"><label>Título / sesión<input name="titulo" maxlength="180" required></label><div class="aula-courses"><label>Sesión<select name="sesion_id"><option value="0">Material general del curso</option><?php foreach($sessions as $session): ?><option value="<?= (int)$session['id'] ?>" <?= (int)($_GET['sesion']??0)===(int)$session['id']?'selected':'' ?>><?= aula_h($session['fecha'].' · '.$session['tema']) ?></option><?php endforeach; ?></select></label><label>Tipo<select name="tipo_recurso"><option value="material">Material PDF / enlace</option><option value="video">Grabación de sesión (YouTube, Vimeo, Google Drive o MP4)</option></select></label><label>Orden<input name="orden" type="number" value="0"></label></div><label>Enlace del video (YouTube, Vimeo, Google Drive, Loom) o recurso externo<input name="url" type="text" maxlength="3000" placeholder="https://youtube.com/watch?v=... o https://vimeo.com/..."></label><label>O subir PDF (5 MB) / video MP4 (15 MB)<input type="file" name="material" accept="application/pdf,video/mp4"></label><div class="aula-courses"><label><input type="checkbox" name="visible" checked> Visible para los estudiantes</label><label>Disponible desde (opcional)<input type="datetime-local" name="disponible_desde"><small>Vacío = de inmediato. Útil para publicar la grabación después de la sesión.</small></label></div><button class="btn btn-primary">Publicar material</button></form><p>Para editar los enlaces existentes utiliza Materiales en Seguimiento por curso.</p></details>
<?php if(cengi_es_admin()): ?><details class="aula-card" id="cuentas"><summary>Vincular cuentas de estudiantes</summary><p>La cuenta se crea en Usuarios del menú principal, con acceso a Cursos y rol Estudiante. Aquí se vincula con su inscripción existente.</p><form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="vincular"><label>Participante del curso<select name="asignacion"><?php foreach(aula_rows($db,'SELECT a.id,a.usuarios_id,p.nombre_participantes FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? AND a.estado_asignaciones=1 ORDER BY p.nombre_participantes',[$cid]) as $p): ?><option value="<?= (int)$p['id'] ?>"><?= aula_h($p['nombre_participantes']) ?> · Cuenta <?= aula_h($p['usuarios_id']?:'sin vincular') ?></option><?php endforeach; ?></select></label><label>Correo o ID de la cuenta del estudiante<input name="usuario" maxlength="255" required placeholder="correo@ejemplo.com"></label><button class="btn btn-primary">Vincular cuenta</button></form></details><?php endif; ?>
<?php if(cengi_puede_gestionar()): ?><section class="aula-card" id="notificaciones"><h2>Notificaciones de sesiones</h2><p>Los recordatorios y avisos se administran en Comunicaciones. El envío automático utiliza el proceso programado de comunicaciones existente.</p><a href="comunicaciones.php">Configurar recordatorios →</a></section><?php endif; ?>
<?php endif; ?></main><script src="js/aula_video.js?v=<?= (int) @filemtime(__DIR__."/js/aula_video.js") ?>"></script><script>
(function(){
    // Pestanas de Gestion del aula: cada tarjeta pertenece a una pestana; los enlaces #id activan la suya.
    var mapa={vista:'inicio',anuncios:'anuncios',sesiones:'sesiones',material:'sesiones','asistencia-rapida':'asistencia',asistencia:'asistencia',correcciones:'asistencia',banco:'evaluaciones',evaluaciones:'evaluaciones',revision:'evaluaciones',encuestas:'instructores',conexion:'config',cuentas:'config',notificaciones:'config','diplomas-curso':'diplomas'};
    var nav=document.querySelector('.aula-paneles');
    var clave='aulaPanel-'+(new URLSearchParams(location.search).get('curso')||'');
    Object.keys(mapa).forEach(function(id){var el=document.getElementById(id);if(el)el.setAttribute('data-panel',mapa[id]);});
    function activar(panel){
        if(!nav||!nav.querySelector('[data-panel-tab="'+panel+'"]'))return;
        document.querySelectorAll('[data-panel]').forEach(function(el){el.hidden=el.getAttribute('data-panel')!==panel;});
        nav.querySelectorAll('[data-panel-tab]').forEach(function(b){var on=b.getAttribute('data-panel-tab')===panel;b.classList.toggle('is-activo',on);b.setAttribute('aria-selected',on?'true':'false');});
        try{sessionStorage.setItem(clave,panel);}catch(e){}
    }
    function desdeHash(){
        var h=location.hash.slice(1);if(!h)return false;
        if(h.indexOf('p-')===0){activar(h.slice(2));return true;}
        var el=document.getElementById(h);if(!el)return false;
        var top=el.closest('[data-panel]');if(top)activar(top.getAttribute('data-panel'));
        if(el.tagName==='DETAILS')el.open=true;
        setTimeout(function(){el.scrollIntoView({behavior:'smooth',block:'start'});},60);
        return true;
    }
    if(nav)nav.addEventListener('click',function(e){var b=e.target.closest('[data-panel-tab]');if(!b)return;activar(b.getAttribute('data-panel-tab'));history.replaceState(null,'',location.pathname+location.search+'#p-'+b.getAttribute('data-panel-tab'));window.scrollTo({top:nav.offsetTop-10,behavior:'smooth'});});
    if(location.search.indexOf('revision=')!==-1){var r=document.getElementById('revision');if(r){r.open=true;activar('evaluaciones');}}
    else if(location.search.indexOf('lista=')!==-1&&!location.hash)activar('asistencia');
    else if(!desdeHash()){var guardado=null;try{guardado=sessionStorage.getItem(clave);}catch(e){}activar(guardado||'sesiones');}
    window.addEventListener('hashchange',desdeHash);
    // Evaluaciones: correr el cierre +1 dia / +1 semana sin escribir la fecha.
    document.addEventListener('click',function(e){var a=e.target.closest('[data-ev-extender]');if(!a)return;e.preventDefault();var i=a.closest('label').querySelector('input');var d=new Date((i.value||new Date().toISOString().slice(0,16))+':00');if(isNaN(d))return;var hoy=new Date();if(d<hoy)d=hoy;d.setDate(d.getDate()+parseInt(a.getAttribute('data-ev-extender'),10));var p=function(n){return (n<10?'0':'')+n;};i.value=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+'T'+p(d.getHours())+':'+p(d.getMinutes());i.dispatchEvent(new Event('change'));});
})();
</script><script src="js/aula_asistencia.js?v=<?= (int) @filemtime(__DIR__."/js/aula_asistencia.js") ?>"></script></body></html>
