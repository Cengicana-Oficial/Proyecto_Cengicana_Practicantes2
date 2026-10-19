<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
// php cengicursos/tests/aula_test.php
// Integracion aislada opcional: --mysql crea y elimina exclusivamente codex_aula_test_*.
require_once __DIR__.'/../aula_helpers.php';
date_default_timezone_set('America/Guatemala');
function check($value,$message) { if(!$value)throw new RuntimeException($message); }
function expect_failure(callable $fn,$message) { try{$fn();}catch(RuntimeException | InvalidArgumentException $e){return;}throw new RuntimeException($message); }
function cengi_usuario_actual_id(){return 12;}
function conectar(){return $GLOBALS['aula_test_db'];}
function menu_render(){echo '<aside>Menú de prueba</aside>';}
function cengi_es_estudiante(){return !empty($GLOBALS['aula_test_student']);}
function cengi_puede_gestionar(){return !cengi_es_estudiante();}
function cengi_puede_calificar(){return !cengi_es_estudiante();}
function cengi_es_admin(){return false;}
function cengi_scope_sql_por_nombre_ingenio($alias,$where){return '';}
function render_aula($file,array $get) {
    $_GET=$get;$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
    $source=file_get_contents(__DIR__.'/../'.$file);
    $source=preg_replace('#require_once __DIR__\s*\.\s*\'/menu.php\';#','',$source);
    $source=str_replace('__DIR__',var_export(realpath(__DIR__.'/..'),true),$source);
    ob_start();try{eval('?>'.$source);return ob_get_contents();}finally{ob_end_clean();}
}
function preview_aula($html,$name) {
    global $argv;
    if(!in_array('--preview',$argv,true))return;
    $dir=__DIR__.'/../../output';if(!is_dir($dir))mkdir($dir,0775,true);
    file_put_contents($dir.'/aula-'.$name.'-preview.html',str_replace('href="css/','href="../cengicursos/css/',$html));
}
$q=[['id'=>1,'tipo'=>'seleccion','correcta'=>0],['id'=>2,'tipo'=>'seleccion','correcta'=>1]];
check(aula_grade($q,['1'=>'0','2'=>'1'])===100.0,'Calificacion completa');
check(aula_grade($q,['1'=>'','2'=>'0'])===0.0,'Vacio no equivale a opcion cero');
check(aula_grade($q,['1'=>'0','2'=>'0'])===50.0,'Calificacion parcial');
$mixed=array_merge($q,[['id'=>3,'tipo'=>'abierta'],['id'=>4,'tipo'=>'tarea']]);
check(aula_grade($mixed,['1'=>'0','2'=>'1'])===null,'Revision pendiente no debe figurar como cero');
check(aula_grade($mixed,['1'=>'0','2'=>'1'],['3'=>['puntos'=>0.5],'4'=>['puntos'=>1]])===87.5,'Puntaje docente y automatico');
try { aula_grade($mixed,[],['3'=>['puntos'=>2]]); throw new RuntimeException('Acepto puntos fuera de rango'); } catch(InvalidArgumentException $e){}
check(aula_url('javascript:alert(1)')===null,'Esquema inseguro');
check(aula_vimeo('https://vimeo.com/123456/abc123')==='https://player.vimeo.com/video/123456?h=abc123','Privacidad Vimeo');
check(aula_vimeo('https://vimeo.com.evil.test/123')===null,'Host falso Vimeo');
check(aula_vimeo('https://player.vimeo.com/video/123?h=xyz')==='https://player.vimeo.com/video/123?h=xyz','Hash del reproductor');
check(aula_datetime('2026-10-11T08:30')==='2026-10-11 08:30:00','Fechas Guatemala');
$slot=['fecha'=>'2026-10-11','hora_inicio'=>'08:00:00','hora_fin'=>'09:00:00','estado'=>'programada'];
check(!aula_session_window($slot,null,'2026-10-11 07:59:59'),'No abrir antes de sesion');
check(aula_session_window($slot,null,'2026-10-11 08:00:00'),'Abrir al inicio');
check(!aula_session_window($slot,null,'2026-10-11 09:00:00'),'Cerrar al fin');
check(!aula_session_window($slot,['habilitada'=>0],'2026-10-11 08:30:00'),'Bloqueo docente');
try{aula_datetime('2026-02-30T08:30');throw new RuntimeException('Fecha inexistente aceptada');}catch(InvalidArgumentException $e){}
$_SESSION=['aula_csrf'=>'token'];$_POST=['csrf'=>'incorrecto'];expect_failure('aula_check_csrf','CSRF no validado');
echo "Calificacion, validacion y CSRF: OK\n";
if(!in_array('--mysql',$argv,true))exit;
$env=[];
foreach(file(__DIR__.'/../../deploy/env/mysql.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    if(strpos(trim($line),'#')===0||strpos($line,'=')===false)continue;
    [$key,$value]=explode('=',$line,2);$env[trim($key)]=trim($value," \t\n\r\0\x0B\"'");
}
$root=new PDO('mysql:host=127.0.0.1;port=33063;charset=utf8mb4','root',$env['MYSQL_ROOT_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='codex_aula_test_'.bin2hex(random_bytes(5));
check((bool)preg_match('/^codex_aula_test_[a-f0-9]{10}$/',$name),'Nombre de base aislada');
$root->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');
try {
 $db=new PDO('mysql:host=127.0.0.1;port=33063;dbname='.$name.';charset=utf8mb4','root',$env['MYSQL_ROOT_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('CREATE TABLE cursos(id INT UNSIGNED PRIMARY KEY); CREATE TABLE participantes(id INT UNSIGNED PRIMARY KEY,usuarios_id INT,nombre_participantes VARCHAR(100),estado_participantes TINYINT); CREATE TABLE asignaciones(id INT UNSIGNED PRIMARY KEY,cursos_id INT UNSIGNED,participantes_id INT UNSIGNED,usuarios_id INT,estado_asignaciones TINYINT); CREATE TABLE curso_sesiones(id INT UNSIGNED PRIMARY KEY)');
 $sql=file_get_contents(__DIR__.'/../migrations/20261018_aula_estudiantes.sql');$db->exec($sql);$db->exec($sql);
 $db->exec("INSERT INTO cursos VALUES(1),(2); INSERT INTO participantes VALUES(1,12,'Estudiante',1),(2,13,'Otro',1),(3,12,'Inactivo',0); INSERT INTO asignaciones VALUES(1,1,1,12,1),(2,2,2,13,1),(3,2,3,12,1)");
 check((int)aula_assignment($db,1)['id']===1,'Asignacion propia');check(aula_assignment($db,2)===null,'No debe acceder a otro curso/inactivo');
 $stmt=$db->prepare('INSERT INTO aula_preguntas(id,curso_id,tipo,enunciado,opciones,correcta,criterio,creado_por) VALUES(1,1,\'seleccion\',\'Pregunta\',?,0,\'\',1)');$stmt->execute([aula_json(['Correcta','Incorrecta'])]);
 $db->prepare('INSERT INTO aula_evaluaciones(id,curso_id,titulo,habilitada,abre,cierra,minutos,intentos,creado_por) VALUES(1,1,\'Prueba\',1,?,?,60,1,1)')->execute([date('Y-m-d H:i:s',time()-60),date('Y-m-d H:i:s',time()+3600)]);
 $db->exec('INSERT INTO aula_evaluacion_preguntas VALUES(1,1)');$assignment=aula_assignment($db,1);
 $id=aula_start($db,1,$assignment);check(aula_start($db,1,$assignment)===$id,'Iniciar dos veces reanuda');
 expect_failure(function()use($db){aula_start($db,1,['id'=>2,'cursos_id'=>2]);},'Curso ajeno aceptado');
 $_POST=['respuestas'=>['1'=>'0']];$_FILES=[];aula_save($db,$id,$assignment,false);
 $db->exec("UPDATE aula_preguntas SET enunciado='Editada',correcta=1 WHERE id=1");
 aula_save($db,$id,$assignment,true);
 $a=aula_rows($db,'SELECT * FROM aula_intentos WHERE id=?',[$id])[0];check((float)$a['nota']===100.0,'Snapshot de clave correcto');check(json_decode($a['preguntas'],true)[0]['enunciado']==='Pregunta','Snapshot de enunciado');
 expect_failure(function()use($db,$assignment){aula_start($db,1,$assignment);},'Limite de intentos no aplicado');
 expect_failure(function()use($db,$id,$assignment){aula_save($db,$id,$assignment,false);},'Entrega modificable');
 $db->exec('UPDATE aula_evaluaciones SET intentos=3');$id2=aula_start($db,1,$assignment);
 $_POST=['respuestas'=>['1'=>'1']];aula_save($db,$id2,$assignment,false);
 $db->prepare('UPDATE aula_intentos SET vence=? WHERE id=?')->execute([date('Y-m-d H:i:s',time()-1),$id2]);
 $_POST=['respuestas'=>['1'=>'0']];aula_save($db,$id2,$assignment,true);$a=aula_rows($db,'SELECT * FROM aula_intentos WHERE id=?',[$id2])[0];check((float)$a['nota']===100.0,'Respuestas tardias rechazadas; conserva guardadas');
 $id3=aula_start($db,1,$assignment);$db->prepare('UPDATE aula_intentos SET vence=? WHERE id=?')->execute([date('Y-m-d H:i:s',time()-1),$id3]);aula_expire($db,1,1);check(aula_rows($db,'SELECT entregado FROM aula_intentos WHERE id=?',[$id3])[0]['entregado']!==null,'Cierre de intento vencido');
 $db->exec('INSERT INTO curso_sesiones VALUES(1)');$db->exec("INSERT IGNORE INTO aula_asistencias VALUES(1,1,NOW()); INSERT IGNORE INTO aula_asistencias VALUES(1,1,NOW())");check((int)$db->query('SELECT COUNT(*) FROM aula_asistencias')->fetchColumn()===1,'Asistencia duplicada');
 // Renderizar todos los submenus con fixtures, sin sesion ni informacion de produccion.
 $db->exec("ALTER TABLE cursos ADD nombre_cursos VARCHAR(100) DEFAULT 'Curso de prueba', ADD inicio DATE DEFAULT '2026-10-01', ADD fin DATE DEFAULT '2026-12-01', ADD ingenio_id INT DEFAULT 1,ADD instructor_id INT NULL;
 CREATE TABLE ingenios(id INT PRIMARY KEY,nombre_ingenios VARCHAR(100)); INSERT INTO ingenios VALUES(1,'Prueba');
 CREATE TABLE planificacion_cursos(id INT PRIMARY KEY,curso_id INT); INSERT INTO planificacion_cursos VALUES(1,1);
 ALTER TABLE curso_sesiones ADD planificacion_id INT DEFAULT 1, ADD tema VARCHAR(100) DEFAULT 'Sesion de prueba', ADD fecha DATE DEFAULT '2026-10-20', ADD hora_inicio TIME DEFAULT '08:00:00', ADD hora_fin TIME DEFAULT '09:00:00', ADD lugar VARCHAR(100) DEFAULT 'Virtual', ADD modalidad VARCHAR(100) DEFAULT 'Virtual', ADD estado VARCHAR(20) DEFAULT 'programada';
 CREATE TABLE control_cursos(asignacion_id INT PRIMARY KEY,asistencia VARCHAR(50),posevaluacion VARCHAR(50),diploma VARCHAR(255)); INSERT INTO control_cursos VALUES(1,'80',NULL,'');
 ALTER TABLE control_cursos ADD id_control INT AUTO_INCREMENT UNIQUE, ADD evaluacion VARCHAR(50), ADD sesiones_asistidas SMALLINT;
 CREATE TABLE curso_modulos(id INT UNSIGNED PRIMARY KEY,curso_id INT,nombre VARCHAR(100),acepta_pre TINYINT,acepta_post TINYINT,orden INT,horas DECIMAL(8,2));
 CREATE TABLE control_curso_modulos(id INT AUTO_INCREMENT PRIMARY KEY,curso_modulo_id INT,asignacion_id INT,evaluacion VARCHAR(50),posevaluacion VARCHAR(50),UNIQUE(asignacion_id,curso_modulo_id));
 CREATE TABLE curso_contenidos(id INT UNSIGNED PRIMARY KEY,curso_id INT,titulo VARCHAR(100),url VARCHAR(500),orden INT);
 INSERT INTO curso_contenidos VALUES(1,1,'Video','https://vimeo.com/123456/abc123',1);
 CREATE TABLE diplomas(asignacion_id INT,tipo VARCHAR(20),codigo_unico VARCHAR(60),emitido_en DATETIME);
 ALTER TABLE curso_sesiones ADD modulo VARCHAR(100) DEFAULT 'Sesion unica', ADD instructor_id INT NULL;
 CREATE TABLE instructores(id INT UNSIGNED PRIMARY KEY,nombre VARCHAR(100));
 CREATE TABLE curso_modulo_instructores(curso_modulo_id INT,instructor_id INT);
 CREATE TABLE enlaces_evaluacion_instructor(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,curso_id INT,instructor_id INT,curso_modulo_id INT NULL,token VARCHAR(32));");
 $integration=file_get_contents(__DIR__.'/../migrations/20261019_aula_integracion.sql');$db->exec($integration);$db->exec($integration);
 $controlSchema=file_get_contents(__DIR__.'/../migrations/20261020_aula_control.sql');$db->exec($controlSchema);$db->exec($controlSchema);
 $GLOBALS['aula_test_db']=$db;$GLOBALS['aula_test_student']=true;
 foreach(['resumen','sesiones','asistencia','notas','material','evaluaciones','tareas','instructores','diplomas'] as $tab) {
    $html=render_aula('mi_aula.php',['curso'=>1,'seccion'=>$tab]);check(strpos($html,'Curso de prueba')!==false,'Render del submenu '.$tab);
    if($tab==='material')check(strpos($html,'https://player.vimeo.com/video/123456?h=abc123')!==false,'Embed Vimeo');
 }
 $html=render_aula('mi_aula.php',[]);check(strpos($html,'Entrar al aula')!==false,'Entrada mis cursos');
 $db->exec('UPDATE aula_evaluaciones SET intentos=4');$id4=aula_start($db,1,$assignment);
 $html=render_aula('mi_aula.php',['curso'=>1,'seccion'=>'evaluaciones','intento'=>$id4]);check(strpos($html,'name="respuestas[1]"')!==false,'Formulario de examen');check(strpos($html,'"correcta"')===false,'Claves no expuestas');
 $GLOBALS['aula_test_student']=false;$html=render_aula('aula_gestion.php',['curso'=>1]);check(strpos($html,'Banco de preguntas')!==false,'Gestion docente');
 // Asistencia automatica, horario real, cancelaciones, cambios y ficha oficial.
 $db->prepare('UPDATE curso_sesiones SET fecha=?,hora_inicio=?,hora_fin=? WHERE id=1')->execute([date('Y-m-d'),date('H:i:s',time()-120),date('H:i:s',time()+120)]);
 $db->exec("INSERT INTO curso_sesiones(id,fecha) VALUES(2,'2099-01-01')");
 aula_attend($db,$assignment,1);aula_attend($db,$assignment,1);
 $official=aula_rows($db,'SELECT * FROM control_cursos WHERE asignacion_id=1')[0];check((float)$official['asistencia']===100.0&&(int)$official['sesiones_asistidas']===1,'Asistencia integrada e idempotente (sesion futura fuera del calculo)');
 $db->exec("UPDATE curso_sesiones SET fecha='2000-01-01' WHERE id=2");aula_attendance_sync($db,1,1);check((float)aula_rows($db,'SELECT asistencia FROM control_cursos WHERE asignacion_id=1')[0]['asistencia']===50.0,'Sesion impartida sin asistencia baja el porcentaje');
 $db->exec("UPDATE curso_sesiones SET fecha='2099-01-01' WHERE id=2");
 expect_failure(function()use($db,$assignment){aula_attend($db,$assignment,2);},'Asistencia fuera de horario');
 expect_failure(function()use($db){aula_session($db,2,1);},'Sesion de otro curso');
 $db->exec("UPDATE curso_sesiones SET fecha='2099-01-01' WHERE id=1");aula_attendance_sync($db,1,1);check((int)aula_rows($db,'SELECT sesiones_asistidas FROM control_cursos WHERE asignacion_id=1')[0]['sesiones_asistidas']===0,'Reprogramacion invalida registro anterior');
 $db->prepare('UPDATE curso_sesiones SET fecha=? WHERE id=1')->execute([date('Y-m-d')]);
 $db->exec("UPDATE curso_sesiones SET estado='cancelada' WHERE id=2");aula_attendance_sync($db,1,1);check((float)aula_rows($db,'SELECT asistencia FROM control_cursos WHERE asignacion_id=1')[0]['asistencia']===100.0,'Cancelacion ajusta denominador');
 // Notas por modulo: mejor intento + pesos de actividades + ponderacion existente por horas.
 $db->exec("INSERT INTO curso_modulos VALUES(1,1,'Modulo 1',1,1,1,2),(2,1,'Modulo 2',1,1,2,6);
 INSERT INTO control_curso_modulos(curso_modulo_id,asignacion_id,evaluacion,posevaluacion) VALUES(1,1,'55','33'),(2,1,'60','90');
 UPDATE aula_evaluaciones SET sincronizar=1,publicar=1,curso_modulo_id=1,peso=1 WHERE id=1;
 INSERT INTO aula_evaluaciones(id,curso_id,titulo,abre,cierra,minutos,intentos,creado_por,sincronizar,publicar,curso_modulo_id,peso,tipo_actividad,sesion_id) SELECT 2,curso_id,'Tarea 2',abre,cierra,minutos,1,1,1,1,1,3,'tarea',1 FROM aula_evaluaciones WHERE id=1;
 INSERT INTO aula_intentos(evaluacion_id,asignacion_id,numero,preguntas,respuestas,inicio,vence,entregado,nota,entrega_usuario) SELECT 2,1,1,preguntas,respuestas,inicio,vence,NOW(),60,1 FROM aula_intentos WHERE id=1;");
 aula_sync_course($db,1,1);
 check((float)aula_rows($db,'SELECT posevaluacion FROM control_curso_modulos WHERE asignacion_id=1 AND curso_modulo_id=1')[0]['posevaluacion']===70.0,'Promedio pesos y mejor intento');
 check((float)aula_rows($db,'SELECT posevaluacion FROM control_cursos WHERE asignacion_id=1')[0]['posevaluacion']===85.0,'Nota final por horas');
 check((float)aula_rows($db,'SELECT evaluacion FROM control_curso_modulos WHERE asignacion_id=1 AND curso_modulo_id=1')[0]['evaluacion']===55.0,'No alterar pre-evaluacion');
 $db->exec('UPDATE aula_evaluaciones SET publicar=0');aula_sync_course($db,1,1);check((float)aula_rows($db,'SELECT posevaluacion FROM control_curso_modulos WHERE asignacion_id=1 AND curso_modulo_id=1')[0]['posevaluacion']===33.0,'Restaurar nota historica al retirar publicacion');
 $db->exec("INSERT INTO instructores VALUES(1,'Docente de prueba');UPDATE curso_sesiones SET instructor_id=1,modulo='Modulo 1' WHERE id=1");aula_sync_course($db,1,1);$links=aula_instructor_links($db,1);check(!empty($links[0]['token'])&&$links[0]['instructor']==='Docente de prueba','Vinculo instructor modulo sesion');
 $db->exec('UPDATE curso_sesiones SET instructor_id=NULL WHERE id=1;UPDATE cursos SET instructor_id=1 WHERE id=1');aula_sync_course($db,1,1);check(aula_instructor_links($db,1)[0]['instructor']==='Docente de prueba','Herencia del instructor del curso');
 $db->exec("INSERT INTO aula_recursos(curso_id,sesion_id,tipo,titulo,contenido_id) VALUES(1,1,'video','Grabacion de la sesion',1); INSERT INTO diplomas VALUES(1,'curso','DIPLOMA-PRUEBA',NOW());UPDATE aula_evaluaciones SET publicar=1,habilitada=1;");
 $GLOBALS['aula_test_student']=true;$html=render_aula('mi_aula.php',['curso'=>1,'seccion'=>'sesiones']);check(strpos($html,'Grabacion de la sesion')!==false&&strpos($html,'evaluación del instructor')!==false,'Recursos e instructor por sesion');
 preview_aula($html,'sesion');
 $html=render_aula('mi_aula.php',['curso'=>1,'seccion'=>'tareas']);check(strpos($html,'Completada / entregada')!==false,'Tareas completadas');
 $html=render_aula('mi_aula.php',[]);check(strpos($html,'Diploma disponible')!==false,'Diploma visible al emitirse');preview_aula($html,'cursos');
 $GLOBALS['aula_test_student']=false;preview_aula(render_aula('aula_gestion.php',['curso'=>1]),'gestion');
 // Encuesta unica por instructor/estudiante, independientemente del numero de sesiones.
 $db->exec("INSERT INTO curso_sesiones(id,fecha,instructor_id) VALUES(3,'2099-01-02',1),(4,'2099-01-03',1),(5,'2099-01-04',1),(6,'2099-01-05',1)");aula_sync_course($db,1,1);
 $surveys=aula_instructor_surveys($db,1,1);check(count($surveys)===1&&$surveys[0]['sesiones']===5,'Una encuesta para cinco sesiones');check(!$surveys[0]['disponible'],'No abrir antes de ultima sesion');
 $db->prepare('UPDATE aula_instructor_config SET abre=? WHERE curso_id=1 AND instructor_id=1')->execute([date('Y-m-d H:i:s',time()-60)]);
 $survey=aula_instructor_surveys($db,1,1)[0]+['asignacion_id'=>1,'curso_id'=>1];check($survey['disponible'],'Apertura personalizada');
 $db->beginTransaction();aula_survey_reserve($db,$survey);$db->commit();
 expect_failure(function()use($db,$survey){$db->beginTransaction();try{aula_survey_reserve($db,$survey);}finally{$db->rollBack();}},'Segunda evaluacion permitida');
 check(aula_instructor_surveys($db,1,1)[0]['respondida'],'Estado respondida');
 // Enlaces generales y por sesion; iframe de Vimeo se normaliza sin ejecutar HTML.
 $db->exec("INSERT INTO aula_curso_config(curso_id,teams_url) VALUES(1,'https://teams.microsoft.com/l/meetup-join/course');INSERT INTO aula_sesion_config VALUES(1,'https://teams.microsoft.com/l/meetup-join/session')");
 check(aula_teams_url($db,1)==='https://teams.microsoft.com/l/meetup-join/course','Teams general');check(aula_teams_url($db,1,['id'=>1])==='https://teams.microsoft.com/l/meetup-join/session','Teams por sesion');
 check(aula_video_source('<iframe src="https://player.vimeo.com/video/123?h=abc&amp;autoplay=0" onload="evil()"></iframe>')==='https://player.vimeo.com/video/123?h=abc','Embed Vimeo seguro');
 // Correcciones con hora, ausencia, historial y protección frente a reemplazo del alumno.
 $manualDate=date('Y-m-d\TH:i',time()-300);
 aula_attendance_correct($db,1,1,1,true,$manualDate,'Registro confirmado por coordinación');check((int)aula_rows($db,'SELECT sesiones_asistidas FROM control_cursos WHERE asignacion_id=1')[0]['sesiones_asistidas']===1,'Asistencia manual fuera de horario');
 aula_attendance_correct($db,1,1,1,false,$manualDate,'Corrección a ausencia');check((int)aula_rows($db,'SELECT sesiones_asistidas FROM control_cursos WHERE asignacion_id=1')[0]['sesiones_asistidas']===0,'Ausencia oficial');
 expect_failure(function()use($db,$assignment){aula_attend($db,$assignment,1);},'Alumno sobreescribe correccion');
 check((int)$db->query('SELECT COUNT(*) FROM aula_asistencia_historial')->fetchColumn()===2,'Historial completo');
 expect_failure(function()use($db,$manualDate){aula_attendance_correct($db,1,2,1,true,$manualDate,'Curso ajeno');},'Corregir asignacion de otro curso');
 // Horario especial habilitado: conserva el registro tardio incluso al cerrar ventana.
 $db->exec("INSERT INTO participantes VALUES(4,12,'Otro estudiante de prueba',1);INSERT INTO asignaciones VALUES(4,1,4,12,1)");
 $db->prepare('UPDATE curso_sesiones SET hora_inicio=?,hora_fin=? WHERE id=1')->execute([date('H:i:s',time()-3600),date('H:i:s',time()-1800)]);
 $db->prepare('INSERT INTO aula_asistencia_ventanas(sesion_id,abre,cierra,codigo_hash,habilitada,creado_por,horario_manual) VALUES(1,?,?,\'\',1,12,1)')->execute([date('Y-m-d H:i:s',time()-120),date('Y-m-d H:i:s',time()+120)]);
 $late=aula_rows($db,'SELECT * FROM asignaciones WHERE id=4')[0];aula_attend($db,$late,1);
 $db->exec('UPDATE aula_asistencia_ventanas SET habilitada=0 WHERE sesion_id=1');check(count(aula_attendance_records($db,4))===1,'Registro tardio validado');
 // Vista previa: no crea intentos, corrige asistencia ni expira respuestas al consultar.
 $before=aula_json(aula_rows($db,'SELECT * FROM aula_asistencias ORDER BY asignacion_id,sesion_id'));$attemptCount=$db->query('SELECT COUNT(*) FROM aula_intentos')->fetchColumn();
 $html=render_aula('mi_aula.php',['curso'=>1,'vista_previa'=>1,'seccion'=>'asistencia']);check(strpos($html,'Vista del estudiante')!==false&&strpos($html,'fieldset disabled')===false,'Vista previa y estado de ausencia');
 $html=render_aula('mi_aula.php',['curso'=>1,'vista_previa'=>4,'seccion'=>'tareas']);check(strpos($html,'vista_previa=4')!==false&&strpos($html,'fieldset disabled')!==false,'Contexto y formularios deshabilitados en vista previa');
 $html=render_aula('mi_aula.php',['curso'=>1,'vista_previa'=>4,'seccion'=>'instructores']);check(strpos($html,'aula-preview-disabled')!==false&&strpos($html,'href="evaluacion.php?')===false,'Encuesta deshabilitada en vista previa');
 check($before===aula_json(aula_rows($db,'SELECT * FROM aula_asistencias ORDER BY asignacion_id,sesion_id'))&&$attemptCount===$db->query('SELECT COUNT(*) FROM aula_intentos')->fetchColumn(),'Vista previa modifica datos');
 $html=render_aula('aulas.php',['curso'=>1]);check(strpos($html,'Ver como estudiante')!==false,'Control general de aulas');
 echo "Control: encuesta unica, apertura, Teams, correcciones auditadas, registro tardio y vista previa: OK\n";
 echo "Integracion: horario, asistencia oficial, reprogramacion, notas ponderadas, instructor, tareas y diplomas: OK\n";
 echo "Renderizado de cursos, nueve submenus, examen y gestion docente: OK\n";
 echo "MySQL: migracion idempotente, propiedad, intentos, snapshots, cierre, notas y asistencia: OK\n";
} finally { $root->exec('DROP DATABASE `'.$name.'`'); }
