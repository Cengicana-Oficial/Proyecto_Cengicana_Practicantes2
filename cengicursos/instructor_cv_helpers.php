<?php
require_once __DIR__.'/conexion.php';
function cengi_cv_campos() {
 return [
 'Datos del instructor'=>['nombre'=>['Nombre completo',255,true],'identidad'=>['Documento de identidad',40,true],'correo'=>['Correo electrónico',180,true],'telefono'=>['Teléfono de contacto',50,true],'colegiado'=>['Colegiado activo (si aplica)',80,false],'linkedin'=>['Perfil de LinkedIn (enlace)',255,false]],
 'Formación académica'=>['titulo'=>['Título académico principal',255,true],'institucion_egreso'=>['Institución de egreso',255,true],'especializacion'=>['Especialización',1000,false]],
 'Competencias y experiencia profesional'=>['habilidades'=>['Habilidades técnicas',5000,false],'certificaciones'=>['Certificaciones relevantes',5000,false],'experiencia'=>['Experiencia profesional (enumerar)',10000,true]],
 'Datos laborales'=>['lugar_trabajo'=>['Lugar de trabajo',255,false],'direccion'=>['Dirección laboral',500,false],'telefono_laboral'=>['Teléfono laboral',50,false]],
 'Experiencia docente'=>['anios_docencia'=>['Años de enseñanza',10,false],'cursos_impartidos'=>['Cursos impartidos previamente (enumerar)',10000,false]]
 ];
}
function cengi_cv_ready(PDO $db){try{$db->query('SELECT instructor_id FROM instructor_cv_digital LIMIT 0');return true;}catch(PDOException $e){return false;}}
function cengi_cv_leer(PDO $db,$id){$q=$db->prepare('SELECT * FROM instructor_cv_digital WHERE instructor_id=?');$q->execute([(int)$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if($r)$r['valores']=json_decode($r['datos'],true)?:[];return $r?:null;}
function cengi_cv_generar_enlace(PDO $db,$id,$usuario){
 $q=$db->prepare('SELECT id FROM instructores WHERE id=?');$q->execute([(int)$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Instructor no disponible.');
 $token=bin2hex(random_bytes(32));
 $db->prepare('INSERT INTO instructor_cv_enlaces(instructor_id,token_hash,expira_en,cerrado,creado_por) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),0,?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expira_en=VALUES(expira_en),cerrado=0,borrador_datos=NULL,creado_por=VALUES(creado_por),creado=NOW()')->execute([(int)$id,hash('sha256',$token),$usuario]);
 return $token;
}
function cengi_cv_enlace(PDO $db,$token,$bloquear=false){
 if(!preg_match('/^[a-f0-9]{64}$/D',(string)$token))return null;
 $q=$db->prepare('SELECT e.instructor_id,e.expira_en,e.borrador_datos FROM instructor_cv_enlaces e JOIN instructores i ON i.id=e.instructor_id WHERE token_hash=? AND cerrado=0 AND expira_en>NOW()'.($bloquear?' FOR UPDATE':''));$q->execute([hash('sha256',$token)]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function cengi_cv_normalizar(array $post,$final){
 $datos=[];
 foreach(cengi_cv_campos() as $campos)foreach($campos as $key=>$meta){
  if(isset($post[$key])&&!is_scalar($post[$key]))throw new InvalidArgumentException('Formato de campo inválido.');
  $valor=trim((string)($post[$key]??''));
  if(mb_strlen($valor)>$meta[1])throw new InvalidArgumentException('El campo '.$meta[0].' supera el tamaño permitido.');
  if($final&&$meta[2]&&$valor==='')throw new InvalidArgumentException('Completa: '.$meta[0].'.');
  $datos[$key]=$valor;
 }
 if($datos['correo']!==''&&!filter_var($datos['correo'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('El correo no es válido.');
 $datos['linkedin']=cengi_cv_linkedin($datos['linkedin']??'');
 if($datos['anios_docencia']!==''&&(!is_numeric($datos['anios_docencia'])||(float)$datos['anios_docencia']<0||(float)$datos['anios_docencia']>80))throw new InvalidArgumentException('Revisa los años de enseñanza (0 a 80).');
 return $datos;
}
function cengi_cv_guardar_publico(PDO $db,$token,array $post,$final){
 if(!$db->inTransaction())throw new LogicException('Se requiere transacción.');
 $link=cengi_cv_enlace($db,$token,true);if(!$link)throw new InvalidArgumentException('El enlace venció o ya fue utilizado. Solicita uno nuevo a Capacitación.');
 $datos=cengi_cv_normalizar($post,$final);$id=$link['instructor_id'];
 $actual=cengi_cv_leer($db,$id);
 if(!empty($actual['valores']['foto']))$datos['foto']=$actual['valores']['foto'];
 $json=json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$estado=$final?'enviado':'borrador';
 if(!$actual||$actual['estado']==='borrador'){
  $db->prepare('INSERT INTO instructor_cv_digital(instructor_id,datos,estado,enviado_en) VALUES(?,?,?,IF(?=1,NOW(),NULL)) ON DUPLICATE KEY UPDATE datos=VALUES(datos),estado=VALUES(estado),validado_por=NULL,validado_en=NULL,enviado_en=VALUES(enviado_en),actualizado=NOW()')->execute([$id,$json,$estado,$final?1:0]);
 }
 // Las actualizaciones de un CV ya enviado quedan en borrador hasta confirmar el nuevo envío.
 if($actual&&$actual['estado']!=='borrador'&&$final)$db->prepare("UPDATE instructor_cv_digital SET datos=?,estado='enviado',validado_por=NULL,validado_en=NULL,enviado_en=NOW(),actualizado=NOW() WHERE instructor_id=?")->execute([$json,$id]);
 $db->prepare('INSERT INTO instructor_cv_historial(instructor_id,accion,datos,usuario) VALUES(?,?,?,?)')->execute([$id,$final?'enviar':'borrador',$json,'Instructor (enlace individual)']);
 if(!$final)$db->prepare('UPDATE instructor_cv_enlaces SET borrador_datos=? WHERE instructor_id=?')->execute([$json,$id]);
 if($final)$db->prepare('UPDATE instructor_cv_enlaces SET cerrado=1 WHERE instructor_id=?')->execute([$id]);
 return $id;
}
/** Documentos opcionales del formulario: mismo expediente y versiones que la carga interna. */
function cengi_cv_documentos(PDO $db,$id){
 $q=$db->prepare("SELECT id,tipo,nombre_original,tamano,creado FROM instructor_documentos WHERE instructor_id=? AND vigente=1 AND tipo IN ('carta','titulo') ORDER BY id DESC");$q->execute([(int)$id]);$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $d)if(!isset($out[$d['tipo']]))$out[$d['tipo']]=$d;return $out;
}
function cengi_cv_validar_archivo(array $archivo){
 $error=(int)($archivo['error']??UPLOAD_ERR_NO_FILE);
 if($error===UPLOAD_ERR_NO_FILE)return null;
 if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('No se pudo recibir el documento. Usa un PDF, JPG o PNG de máximo 5 MB.');
 $tmp=(string)($archivo['tmp_name']??'');
 if(!is_file($tmp)||filesize($tmp)===0||filesize($tmp)>5*1024*1024)throw new InvalidArgumentException('Cada documento debe tener contenido y pesar como máximo 5 MB.');
 $extension=strtolower(pathinfo((string)($archivo['name']??''),PATHINFO_EXTENSION));$mimes=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
 if(!isset($mimes[$extension])||(new finfo(FILEINFO_MIME_TYPE))->file($tmp)!==$mimes[$extension])throw new InvalidArgumentException('El documento debe ser un PDF, JPG o PNG válido.');
 return ['extension'=>$extension,'nombre'=>mb_substr(basename((string)$archivo['name']),0,255),'tamano'=>filesize($tmp)];
}
function cengi_cv_registrar_documento(PDO $db,$id,$tipo,array $meta,$ruta,$usuario='Instructor (enlace individual)'){
 if(!$db->inTransaction()||!in_array($tipo,['carta','titulo'],true))throw new LogicException('Documento fuera del flujo permitido.');
 $db->prepare('UPDATE instructor_documentos SET vigente=0 WHERE instructor_id=? AND tipo=?')->execute([(int)$id,$tipo]);
 $db->prepare('INSERT INTO instructor_documentos(instructor_id,tipo,nombre_original,ruta,tamano,emisor,fecha_emision,descripcion,subido_por_nombre) VALUES(?,?,?,?,?,?,NULL,?,?)')->execute([(int)$id,$tipo,$meta['nombre'],$ruta,$meta['tamano'],'','Cargado desde el CV digital',$usuario]);
 $docId=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO instructor_cv_historial(instructor_id,accion,datos,usuario) VALUES(?,'documento',?,?)")->execute([(int)$id,json_encode(['documento_id'=>$docId,'tipo'=>$tipo,'nombre'=>$meta['nombre']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$usuario]);
 return $docId;
}
function cengi_cv_cargar_documentos(PDO $db,$id,array $files,array &$rutas,$usuario='Instructor (enlace individual)'){
 $validados=[];
 foreach(['carta_formacion'=>'carta','titulo_diploma'=>'titulo'] as $campo=>$tipo){
  $file=$files[$campo]??['error'=>UPLOAD_ERR_NO_FILE];$meta=cengi_cv_validar_archivo($file);
  if($meta){if(!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('No se recibió un archivo válido.');$validados[]=[$tipo,$file,$meta];}
 }
 foreach($validados as [$tipo,$file,$meta]){
  $ruta=cengi_guardar_archivo_subido($file['tmp_name'],'instructores',(int)$id.'_'.$tipo.'_'.bin2hex(random_bytes(12)).'.'.$meta['extension']);
  if(!$ruta)throw new InvalidArgumentException('No fue posible guardar el documento. Intenta nuevamente.');
  $rutas[]=$ruta;cengi_cv_registrar_documento($db,$id,$tipo,$meta,$ruta,$usuario);
 }
}
function cengi_cv_limpiar_cargas(array $rutas){
 $base=realpath(__DIR__.'/../uploads/instructores');
 if(!$base)return;
 foreach($rutas as $ruta){$url=cengi_normalizar_url_archivo($ruta);$path=$url?realpath(__DIR__.'/..'.$url):false;if($path&&strpos($path,$base.DIRECTORY_SEPARATOR)===0&&is_file($path))unlink($path);}
}
/** Consulta viva: instructor principal, módulos, planificación y sesiones. */
function cengi_cv_cursos_asignados(PDO $db,$id){
 $q=$db->prepare("SELECT c.id,c.nombre_cursos nombre,c.inicio fecha FROM cursos c WHERE c.instructor_id=?
 OR EXISTS(SELECT 1 FROM curso_modulo_instructores mi JOIN curso_modulos m ON m.id=mi.curso_modulo_id WHERE m.curso_id=c.id AND mi.instructor_id=?)
 OR EXISTS(SELECT 1 FROM planificacion_cursos p WHERE p.curso_id=c.id AND p.estado<>'cancelado' AND (p.instructor_id=? OR EXISTS(SELECT 1 FROM curso_sesiones s WHERE s.planificacion_id=p.id AND s.instructor_id=? AND s.estado<>'cancelada')))");$q->execute(array_fill(0,4,(int)$id));$out=$q->fetchAll(PDO::FETCH_ASSOC);
 $q=$db->prepare("SELECT -p.id id,p.nombre,p.fecha_inicio fecha FROM planificacion_cursos p WHERE p.curso_id IS NULL AND p.estado<>'cancelado' AND (p.instructor_id=? OR EXISTS(SELECT 1 FROM curso_sesiones s WHERE s.planificacion_id=p.id AND s.instructor_id=? AND s.estado<>'cancelada'))");$q->execute([(int)$id,(int)$id]);$out=array_merge($out,$q->fetchAll(PDO::FETCH_ASSOC));
 usort($out,static function($a,$b){return strcmp((string)$b['fecha'],(string)$a['fecha'])?:strcmp($a['nombre'],$b['nombre']);});return $out;
}function cengi_cv_editar_interno(PDO $db,$id,array $datos,$revision,$usuario){
 if(!$db->inTransaction())throw new LogicException('Se requiere transacción.');
 $q=$db->prepare('SELECT * FROM instructor_cv_digital WHERE instructor_id=? FOR UPDATE');$q->execute([(int)$id]);$cv=$q->fetch(PDO::FETCH_ASSOC);
 if(!$cv||!hash_equals(hash('sha256',$cv['datos']),(string)$revision))throw new InvalidArgumentException('La ficha cambió. Recarga antes de guardar.');
 $valores=cengi_cv_normalizar($datos,true);$anteriores=json_decode($cv['datos'],true)?:[];if(!empty($anteriores['foto']))$valores['foto']=$anteriores['foto'];$json=json_encode($valores,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $db->prepare("UPDATE instructor_cv_digital SET datos=?,estado='enviado',validado_por=NULL,validado_en=NULL,actualizado=NOW() WHERE instructor_id=?")->execute([$json,(int)$id]);
 $db->prepare("INSERT INTO instructor_cv_historial(instructor_id,accion,datos,usuario) VALUES(?,'editar_capacitacion',?,?)")->execute([(int)$id,$json,$usuario]);
}

function cengi_cv_validar_foto(array $file){
 if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
 if(($file['error']??null)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('No se pudo recibir la foto. Usa JPG o PNG de máximo 5 MB.');
 $tmp=$file['tmp_name']??'';$ext=strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION));
 if(!is_file($tmp)||filesize($tmp)<1||filesize($tmp)>5*1024*1024)throw new InvalidArgumentException('La foto debe pesar como máximo 5 MB.');
 $types=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];$size=@getimagesize($tmp);
 if(!isset($types[$ext])||!$size||(new finfo(FILEINFO_MIME_TYPE))->file($tmp)!==$types[$ext]||$size[0]>8000||$size[1]>8000)throw new InvalidArgumentException('Selecciona una foto JPG o PNG válida, de hasta 8000 × 8000 píxeles.');
 return $ext;
}
function cengi_cv_cargar_foto(PDO $db,$id,array $files,array &$rutas,$usuario){
 if(!$db->inTransaction())throw new LogicException('Se requiere transacción.');
 $file=$files['foto_instructor']??[];$ext=cengi_cv_validar_foto($file);if(!$ext)return;
 if(!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('No se recibió una foto válida.');
 $ruta=cengi_guardar_archivo_subido($file['tmp_name'],'instructores',(int)$id.'_foto_'.bin2hex(random_bytes(12)).'.'.$ext);
 if(!$ruta)throw new InvalidArgumentException('No se pudo guardar la foto. Intenta nuevamente.');$rutas[]=$ruta;
 $cv=cengi_cv_leer($db,$id);if(!$cv)throw new InvalidArgumentException('Guarda primero el CV digital.');
 $valores=$cv['valores'];$valores['foto']=$ruta;$json=json_encode($valores,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $db->prepare('UPDATE instructor_cv_digital SET datos=?,actualizado=NOW() WHERE instructor_id=?')->execute([$json,$id]);
 $q=$db->prepare('SELECT borrador_datos FROM instructor_cv_enlaces WHERE instructor_id=?');$q->execute([$id]);$draft=$q->fetchColumn();
 if($draft){$v=json_decode($draft,true)?:[];$v['foto']=$ruta;$db->prepare('UPDATE instructor_cv_enlaces SET borrador_datos=? WHERE instructor_id=?')->execute([json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);}
 $db->prepare("INSERT INTO instructor_cv_historial(instructor_id,accion,datos,usuario) VALUES(?,'foto',?,?)")->execute([$id,json_encode(['foto'=>$ruta],JSON_THROW_ON_ERROR),$usuario]);
}

// ---------------------------------------------------------------- formato de lectura (web y PDF)
/** Etiqueta sin las instrucciones del formulario: «(enumerar)», «(si aplica)». */
function cengi_cv_etiqueta($label){return trim(preg_replace('/\s*\((enumerar|si aplica|enlace)\)/iu','',(string)$label));}
/** Renglones de un texto largo, sin viñetas ni numeración escritas a mano. */
function cengi_cv_lineas($valor){return array_values(array_filter(array_map(static function($l){return trim(preg_replace('/^[\-\*•·\d]+[\.\)]?\s+/u','',$l));},preg_split('/\R/u',(string)$valor)),'strlen'));}
/** «3 años», «1 año», «2.5 años». */
function cengi_cv_anios($valor){if(!is_numeric($valor))return (string)$valor;$n=rtrim(rtrim(number_format((float)$valor,1),'0'),'.');return $n.' año'.((float)$valor==1?'':'s');}
/**
 * Enlace de LinkedIn normalizado (https://…linkedin.com/…) o '' si viene vacío.
 * Acepta «linkedin.com/in/usuario» sin esquema; rechaza otros dominios para no publicar enlaces arbitrarios.
 */
function cengi_cv_linkedin($valor){
 $valor=trim((string)$valor);if($valor==='')return '';
 if(!preg_match('#^https?://#i',$valor))$valor='https://'.ltrim($valor,'/');
 $partes=parse_url($valor);$host=strtolower((string)($partes['host']??''));
 if(!filter_var($valor,FILTER_VALIDATE_URL)||!preg_match('/(^|\.)linkedin\.com$/',$host)||trim((string)($partes['path']??''),'/')==='')
  throw new InvalidArgumentException('El perfil de LinkedIn debe ser un enlace de linkedin.com, por ejemplo https://www.linkedin.com/in/tu-usuario');
 return 'https://'.$host.rtrim((string)$partes['path'],'/').(isset($partes['query'])?'?'.$partes['query']:'');
}
/** Texto corto para mostrar el enlace: «linkedin.com/in/usuario». */
function cengi_cv_linkedin_corto($url){return preg_replace('#^https?://(www\.)?#i','',rtrim((string)$url,'/'));}
