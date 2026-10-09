<?php
declare(strict_types=1);
const MEM_ESTADOS=['entregado'=>'Entregado · sin revisor','revision'=>'En revisión','observaciones'=>'Observaciones al autor','listo'=>'Listo para diagramación','diagramacion'=>'Descargado por diagramación'];
const MEM_MPR=['armado'=>'En diagramación','revision_final'=>'En revisión final','aprobado_jt'=>'Aprobado por Jefe de Transferencia','aprobado'=>'Aprobado para liberación','publicado'=>'Publicado'];
function mem_preparar(PDO $pdo): void
{
    directorio_preparar($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS memoria_ediciones(id INT AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(220) NOT NULL,anio INT NOT NULL,limite_entrega DATE NULL,dias_revision INT NOT NULL DEFAULT 15,criterios TEXT NULL,plantilla TEXT NULL,mpr LONGTEXT NULL,revision INT NOT NULL DEFAULT 1,creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS memoria_config(id INT PRIMARY KEY,comite_id VARCHAR(40) NOT NULL DEFAULT 'editorial') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS memoria_avisos(id INT AUTO_INCREMENT PRIMARY KEY,email VARCHAR(180) NOT NULL,articulo_id INT NULL,mensaje VARCHAR(500) NOT NULL,leido TINYINT(1) NOT NULL DEFAULT 0,creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO memoria_config(id) VALUES (1)");
    $pdo->exec("INSERT IGNORE INTO comites(id,nombre) VALUES ('editorial','Comité Editorial')");
    foreach(['edicion_id'=>'INT NULL','flujo'=>'VARCHAR(25) NULL','area'=>'VARCHAR(180) NULL','coautores'=>'TEXT NULL','autor_usuario_id'=>'INT NULL','revisor_contacto_id'=>'INT NULL','limite_revision'=>'DATE NULL','revision_nombre'=>'VARCHAR(255) NULL','recomendacion'=>'VARCHAR(60) NULL','descargado_diag'=>'DATETIME NULL','expediente'=>'LONGTEXT NULL','revision_control'=>'INT NOT NULL DEFAULT 1'] as $c=>$d)app_ensure_column($pdo,'memorias_resultados',$c,"{$c} {$d}");
    app_ensure_column($pdo,'comite_editorial','contacto_id','contacto_id INT NULL');
    foreach($pdo->query('SELECT * FROM comite_editorial WHERE contacto_id IS NULL')->fetchAll() as $old){
        $q=$pdo->prepare('SELECT id FROM contactos WHERE email=? AND email<>? LIMIT 1');$q->execute([trim((string)$old['email']),'']);$cid=$q->fetchColumn();
        if(!$cid){$validEmail=filter_var($old['email'],FILTER_VALIDATE_EMAIL)?$old['email']:'';$cid=directorio_guardar($pdo,['nombre'=>$old['nombre'],'email'=>$validEmail,'cargo'=>$old['cargo'] ?? '','estado'=>$old['estado'],'comites'=>['editorial']],'Migración del comité editorial');$pdo->prepare("UPDATE contactos SET origen_tipo='memoria',origen_detalle='Revisor del comité editorial existente' WHERE id=?")->execute([$cid]);}
        $pdo->prepare('INSERT IGNORE INTO contacto_comite(contacto_id,comite_id) VALUES (?,?)')->execute([$cid,'editorial']);$pdo->prepare('UPDATE comite_editorial SET contacto_id=? WHERE id=?')->execute([$cid,$old['id']]);
    }
    $pdo->exec('UPDATE memorias_resultados m JOIN comite_editorial c ON c.id=m.revisor_id SET m.revisor_contacto_id=c.contacto_id WHERE m.revisor_contacto_id IS NULL');
    $unassigned=(int)$pdo->query('SELECT COUNT(*) FROM memorias_resultados WHERE edicion_id IS NULL')->fetchColumn();
    if($unassigned){$q=$pdo->prepare('SELECT id FROM memoria_ediciones WHERE nombre=? LIMIT 1');$q->execute(['Memorias existentes']);$eid=$q->fetchColumn();if(!$eid){$pdo->prepare('INSERT INTO memoria_ediciones(nombre,anio) VALUES (?,?)')->execute(['Memorias existentes',(int)date('Y')]);$eid=$pdo->lastInsertId();}$pdo->prepare('UPDATE memorias_resultados SET edicion_id=? WHERE edicion_id IS NULL')->execute([$eid]);}
    if(!(int)$pdo->query('SELECT COUNT(*) FROM memoria_ediciones')->fetchColumn())$pdo->prepare('INSERT INTO memoria_ediciones(nombre,anio) VALUES (?,?)')->execute(['Memoria de Presentación de Resultados '.date('Y'),(int)date('Y')]);
    $pdo->exec("UPDATE memorias_resultados SET flujo=CASE WHEN estado IN ('En revision','En revisión') THEN 'revision' WHEN estado IN ('Con observaciones','Observaciones') THEN 'observaciones' WHEN estado IN ('Aprobada','Aprobado','Lista para diagramacion') THEN 'listo' WHEN estado IN ('Diagramacion','Publicada','Publicado') THEN 'diagramacion' ELSE 'entregado' END WHERE flujo IS NULL");
}
function mem_caps(array $u): array
{
    $r=trim(normalize_text((string)($u['nombre_rol'] ?? '')));$admin=is_superadmin($u)||($r==='administrador'&&can_manage_resource($u,'memorias'));
    $diag=$admin||in_array($r,['diagramacion','asistente de direccion'],true)||has_permission($u,'comites.memorias.diagramar');
    $jt=$admin||$r==='jefe de transferencia'||has_permission($u,'comites.memorias.aprobar_transferencia');$dir=$admin||$r==='director general'||has_permission($u,'comites.memorias.aprobar_direccion');
    $coord=$admin||can_manage_resource($u,'memorias')||$diag||$jt;
    return ['admin'=>$admin,'coord'=>$coord,'diag'=>$diag,'jt'=>$jt,'dir'=>$dir,'todos'=>$coord||$dir||$r==='jefe de area'||has_permission($u,'comites.memorias.ver_todos'),'entregar'=>$coord||$r==='autor'||has_permission($u,'comites.memorias.entregar'),'contactos'=>can_manage_resource($u,'contactos')||can_manage_resource($u,'configuracion')];
}
function mem_revisores(PDO $pdo): array
{
    $group=$pdo->query('SELECT comite_id FROM memoria_config WHERE id=1')->fetchColumn();$q=$pdo->prepare("SELECT c.* FROM contactos c JOIN contacto_comite cc ON cc.contacto_id=c.id WHERE cc.comite_id=? AND c.estado='Activo' ORDER BY c.nombre");$q->execute([$group]);return $q->fetchAll();
}
function mem_autor(array $u,array $r): bool { return (int)($r['autor_usuario_id'] ?? 0)===(int)($u['id'] ?? -1)||(!empty($u['correo'])&&!empty($r['autor_email'])&&strcasecmp(trim($u['correo']),trim($r['autor_email']))===0); }
function mem_revisor(PDO $pdo,array $u,array $r): bool
{
    if(empty($u['correo'])||empty($r['revisor_contacto_id']))return false;$q=$pdo->prepare("SELECT COUNT(*) FROM contactos WHERE id=? AND estado='Activo' AND LOWER(TRIM(email))=?");$q->execute([$r['revisor_contacto_id'],strtolower(trim($u['correo']))]);return (bool)$q->fetchColumn();
}
function mem_puede_ver(PDO $pdo,array $u,array $r): bool { return can_view_resource($u,'memorias')&&(mem_caps($u)['todos']||mem_autor($u,$r)||mem_revisor($pdo,$u,$r)); }
function mem_final(PDO $pdo,array $u): bool
{
    $c=mem_caps($u);if($c['coord']||$c['dir'])return true;foreach(mem_revisores($pdo) as $r)if(!empty($u['correo'])&&strcasecmp(trim($r['email']),trim($u['correo']))===0)return true;return false;
}
function mem_fila(PDO $pdo,int $id,bool $lock=false): array { $q=$pdo->prepare('SELECT * FROM memorias_resultados WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('El artículo no existe.');return $r; }
function mem_edicion(PDO $pdo,int $id,bool $lock=false): array { $q=$pdo->prepare('SELECT * FROM memoria_ediciones WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('La memoria no existe.');return $r; }
function mem_mpr(array $e): array { return json_decode((string)$e['mpr'],true) ?: ['estado'=>'armado','version'=>0,'archivo'=>null,'versiones'=>[],'observaciones'=>[],'bitacora'=>[],'publicacion'=>null]; }
function mem_fecha(string $v,bool $required=false): ?string { if($v===''&&!$required)return null;if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new RuntimeException('Fecha no válida.');return $v; }
function mem_archivo(array $file,bool $required=false,array $ext=['pdf','doc','docx','xls','xlsx']): ?array
{
    if(($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){if($required)throw new RuntimeException('Falta un archivo obligatorio.');return null;}
    if(($file['size'] ?? 0)>20*1024*1024)throw new RuntimeException('El archivo supera 20 MB.');
    $name=mb_substr(basename((string)$file['name']),0,160);$file['name']=bin2hex(random_bytes(12)).'_'.preg_replace('/[^A-Za-z0-9_.-]/','_',$name);$a=comites_guardar_archivo($file,'memorias',$ext);if($a['error'])throw new RuntimeException($a['error']);return ['nombre'=>$name,'ruta'=>$a['ruta']];
}
function mem_log(array $u,string $accion,int $version,string $obs='',array $files=[]): array { return ['fecha'=>date('Y-m-d H:i:s'),'accion'=>$accion,'actor'=>$u['nombre'],'rol'=>$u['nombre_rol'] ?? 'Administrador','version'=>$version,'observaciones'=>$obs,'archivos'=>array_values(array_filter($files))]; }
function mem_registrar(PDO $pdo,array $u,int $id,string $accion,string $obs='',array $files=[]): void
{
    $r=mem_fila($pdo,$id);$log=json_decode((string)$r['expediente'],true) ?: [];$log[]=mem_log($u,$accion,(int)$r['version'],$obs,$files);
    $pdo->prepare('UPDATE memorias_resultados SET expediente=?,revision_control=revision_control+1 WHERE id=?')->execute([json_encode($log,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
    comites_memoria_log_track($pdo,$id,$accion,MEM_ESTADOS[$r['flujo']],$obs);
}
function mem_avisar(PDO $pdo,string $email,int $id,string $msg): void { if(filter_var($email,FILTER_VALIDATE_EMAIL))$pdo->prepare('INSERT INTO memoria_avisos(email,articulo_id,mensaje) VALUES (?,?,?)')->execute([strtolower(trim($email)),$id,mb_substr($msg,0,500)]); }
function mem_datos(PDO $pdo,array $u): array
{
    $caps=mem_caps($u);$rev=mem_revisores($pdo);$rows=[];$counts=[];
    foreach($pdo->query('SELECT * FROM memorias_resultados ORDER BY numero_memoria,id') as $r){if($r['flujo']==='revision')$counts[$r['revisor_contacto_id']]=($counts[$r['revisor_contacto_id']] ?? 0)+1;if(!mem_puede_ver($pdo,$u,$r))continue;$r['id']=(int)$r['id'];$r['edicion_id']=(int)$r['edicion_id'];$r['asignar']=$caps['coord']&&in_array($r['flujo'],['entregado','revision'],true);$r['revisar']=($caps['admin']||mem_revisor($pdo,$u,$r))&&$r['flujo']==='revision';$r['corregir']=($caps['admin']||mem_autor($u,$r))&&$r['flujo']==='observaciones';$r['diagramar']=$caps['diag']&&in_array($r['flujo'],['listo','diagramacion'],true);$r['historial']=json_decode((string)$r['expediente'],true) ?: [];$r['anterior']=comites_memoria_track($pdo,$r['id']);$rows[]=$r;}
    foreach($rev as &$r){$r['id']=(int)$r['id'];$r['carga']=$counts[$r['id']] ?? 0;}unset($r);
    $ediciones=$pdo->query('SELECT * FROM memoria_ediciones ORDER BY anio DESC,id DESC')->fetchAll();$final=mem_final($pdo,$u);
    foreach($ediciones as &$e){$e['id']=(int)$e['id'];$e['criterios']=json_decode((string)$e['criterios'],true);$e['plantilla']=json_decode((string)$e['plantilla'],true);$e['mpr']=$final?mem_mpr($e):null;}unset($e);
    $q=$pdo->prepare('SELECT id,articulo_id,mensaje,creado_en FROM memoria_avisos WHERE email=? AND leido=0 ORDER BY id DESC LIMIT 50');$q->execute([strtolower(trim((string)($u['correo'] ?? '')))]);
    return ['articulos'=>$rows,'ediciones'=>$ediciones,'revisores'=>$rev,'comite'=>$pdo->query('SELECT comite_id FROM memoria_config WHERE id=1')->fetchColumn(),'comites'=>$caps['coord']?$pdo->query('SELECT id,nombre FROM comites ORDER BY nombre')->fetchAll():[],'contactos'=>$caps['coord']&&$caps['contactos']?$pdo->query("SELECT id,nombre,email,cargo,empresa FROM contactos WHERE estado='Activo' ORDER BY nombre")->fetchAll():[],'estados'=>MEM_ESTADOS,'estados_mpr'=>MEM_MPR,'permisos'=>$caps,'final'=>$final,'usuario'=>['nombre'=>$u['nombre'],'correo'=>$u['correo'] ?? ''],'avisos'=>$q->fetchAll(),'csrf'=>$_SESSION['memorias_csrf'],'hoy'=>date('Y-m-d')];
}
function mem_mutar(PDO $pdo,array $u,string $action,array $d,array $files=[]): string
{
    if(!can_view_resource($u,'memorias'))throw new RuntimeException('No tienes acceso a Memoria de resultados.');
    $caps=mem_caps($u);$new=[];$pdo->beginTransaction();
    // Serializa las transiciones y numeración para evitar asignaciones y aprobaciones simultáneas.
    try{
        $pdo->query('SELECT id FROM memoria_config WHERE id=1 FOR UPDATE')->fetchColumn();
        $upload=function(string $key,bool $required=false,array $ext=['pdf','doc','docx','xls','xlsx'])use($files,&$new):?array{$a=mem_archivo($files[$key] ?? [],$required,$ext);if($a)$new[]=$a;return $a;};
        if($action==='aviso_leido'){$pdo->prepare('UPDATE memoria_avisos SET leido=1 WHERE id=? AND email=?')->execute([(int)($d['id'] ?? 0),strtolower(trim((string)($u['correo'] ?? '')))]);}
        elseif($action==='edicion'){
            if(!$caps['coord'])throw new RuntimeException('Solo coordinación editorial configura memorias.');$id=(int)($d['id'] ?? 0);$r=$id?mem_edicion($pdo,$id,true):null;
            if($r&&isset($d['revision'])&&(int)$d['revision']!==(int)$r['revision'])throw new RuntimeException('La memoria cambió. Recarga antes de guardar.');
            $name=trim((string)($d['nombre'] ?? ''));$year=(int)($d['anio'] ?? date('Y'));$days=(int)($d['dias_revision'] ?? 15);if(!$name||mb_strlen($name)>220||$year<1900||$year>2199||$days<1||$days>365)throw new RuntimeException('Revisa el nombre, año y días de revisión (1–365).');
            $criteria=$upload('criterios');$template=$upload('plantilla',false,['pdf','doc','docx']);$v=[$name,$year,mem_fecha((string)($d['limite_entrega'] ?? '')),$days,$criteria?json_encode($criteria):($r['criterios'] ?? null),$template?json_encode($template):($r['plantilla'] ?? null)];
            if($id)$pdo->prepare('UPDATE memoria_ediciones SET nombre=?,anio=?,limite_entrega=?,dias_revision=?,criterios=?,plantilla=?,revision=revision+1 WHERE id=?')->execute([...$v,$id]);else $pdo->prepare('INSERT INTO memoria_ediciones(nombre,anio,limite_entrega,dias_revision,criterios,plantilla) VALUES (?,?,?,?,?,?)')->execute($v);
        }elseif(in_array($action,['grupo','revisor_agregar','revisor_quitar','revisor_nuevo'],true)){
            if(!$caps['coord'])throw new RuntimeException('Solo coordinación editorial administra revisores.');
            if($action==='grupo'){$cid=(string)($d['comite_id'] ?? '');catalogo_fila($pdo,$cid);$pdo->prepare('UPDATE memoria_config SET comite_id=? WHERE id=1')->execute([$cid]);}
            else{
                if(!$caps['contactos'])throw new RuntimeException('Necesitas permiso de contactos o configuración para modificar el grupo.');$cid=$pdo->query('SELECT comite_id FROM memoria_config WHERE id=1')->fetchColumn();$contact=(int)($d['contacto_id'] ?? 0);
                if($action==='revisor_nuevo'){if(!filter_var($d['email'] ?? '',FILTER_VALIDATE_EMAIL))throw new RuntimeException('Escribe un correo válido.');$contact=directorio_guardar($pdo,array_intersect_key($d,array_flip(['nombre','email','cargo','empresa']))+['comites'=>[$cid]],$u['nombre']);$pdo->prepare("UPDATE contactos SET origen_tipo='memoria',origen_detalle='Revisor registrado para memoria de resultados' WHERE id=?")->execute([$contact]);}
                elseif($action==='revisor_agregar'){$q=$pdo->prepare("SELECT COUNT(*) FROM contactos WHERE id=? AND estado='Activo'");$q->execute([$contact]);if(!$q->fetchColumn())throw new RuntimeException('Selecciona un contacto activo.');$pdo->prepare('INSERT IGNORE INTO contacto_comite(contacto_id,comite_id) VALUES (?,?)')->execute([$contact,$cid]);}
                else{$pdo->prepare('DELETE FROM contacto_comite WHERE contacto_id=? AND comite_id=?')->execute([$contact,$cid]);$pdo->prepare('UPDATE comites SET lider_id=NULL WHERE id=? AND lider_id=?')->execute([$cid,$contact]);}
            }
        }elseif($action==='entregar'){
            if(!$caps['entregar'])throw new RuntimeException('No tienes permiso para entregar artículos.');$ed=mem_edicion($pdo,(int)($d['edicion_id'] ?? 0),true);$title=trim((string)($d['titulo'] ?? ''));$author=$caps['coord']?trim((string)($d['autor'] ?? '')):$u['nombre'];$email=$caps['coord']?trim((string)($d['autor_email'] ?? '')):($u['correo'] ?? '');
            if(!$title||mb_strlen($title)>220||!$author||mb_strlen($author)>180||($email&&!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new RuntimeException('Revisa el título, autor y correo.');$a=$upload('archivo',true,['pdf','doc','docx']);
            $prefix='MR-'.$ed['anio'].'-';$q=$pdo->prepare('SELECT numero_memoria FROM memorias_resultados WHERE numero_memoria LIKE ?');$q->execute([$prefix.'%']);$max=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $n)if(ctype_digit(substr($n,strlen($prefix))))$max=max($max,(int)substr($n,strlen($prefix)));$numero=$prefix.str_pad((string)($max+1),3,'0',STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO memorias_resultados(numero_memoria,titulo,autor,autor_email,fecha,archivo_nombre,archivo_ruta,edicion_id,area,coautores,autor_usuario_id,flujo,estado) VALUES (?,?,?,?,CURDATE(),?,?,?,?,?,?,'entregado','Recibida')")->execute([$numero,$title,$author,$email,$a['nombre'],$a['ruta'],$ed['id'],mb_substr(trim((string)($d['area'] ?? '')),0,180),trim((string)($d['coautores'] ?? '')),$caps['coord']?null:(int)$u['id']]);$id=(int)$pdo->lastInsertId();mem_registrar($pdo,$u,$id,'entregado','',[$a]);
        }elseif(str_starts_with($action,'mpr_')){
            mem_mpr_mutar($pdo,$u,$action,$d,$upload);
        }else{
            $id=(int)($d['id'] ?? 0);$r=mem_fila($pdo,$id,true);if(!mem_puede_ver($pdo,$u,$r))throw new RuntimeException('No tienes acceso a este artículo.');if(isset($d['revision'])&&(int)$d['revision']!==(int)$r['revision_control'])throw new RuntimeException('El artículo cambió. Recarga antes de continuar.');
            $obs=trim((string)($d['observaciones'] ?? ''));
            if($action==='asignar'){
                if(!$caps['coord']||!in_array($r['flujo'],['entregado','revision'],true))throw new RuntimeException('El artículo no admite asignación en este estado.');$cid=(int)($d['revisor_id'] ?? 0);$reviewer=null;foreach(mem_revisores($pdo) as $rv)if((int)$rv['id']===$cid)$reviewer=$rv;if(!$reviewer||!filter_var($reviewer['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Selecciona un revisor activo con correo válido.');
                $deadline=mem_fecha((string)($d['limite_revision'] ?? ''),true);if($deadline<date('Y-m-d'))throw new RuntimeException('La fecha límite debe ser hoy o posterior.');
                $normal=static fn($s)=>trim(preg_replace('/^(dra?|ing|lic)\s+/','',normalize_text($s)));$authors=array_map($normal,array_merge([$r['autor']],explode(',',(string)$r['coautores'])));$conflict=in_array($normal($reviewer['nombre']),$authors,true)||($r['autor_email']&&strcasecmp($r['autor_email'],$reviewer['email'])===0);if($conflict&&empty($d['confirmar_conflicto']))throw new RuntimeException('El revisor coincide con el autor o un coautor. Confirma el posible conflicto de interés.');
                $q=$pdo->prepare('SELECT id FROM comite_editorial WHERE contacto_id=? LIMIT 1');$q->execute([$cid]);$ceid=$q->fetchColumn();if(!$ceid){$pdo->prepare('INSERT INTO comite_editorial(nombre,cargo,email,contacto_id) VALUES (?,?,?,?)')->execute([$reviewer['nombre'],$reviewer['cargo'],$reviewer['email'],$cid]);$ceid=$pdo->lastInsertId();}else $pdo->prepare("UPDATE comite_editorial SET nombre=?,email=?,estado='Activo' WHERE id=?")->execute([$reviewer['nombre'],$reviewer['email'],$ceid]);
                $pdo->prepare("UPDATE memorias_resultados SET revisor_id=?,revisor_contacto_id=?,limite_revision=?,flujo='revision',estado='En revision' WHERE id=?")->execute([$ceid,$cid,$deadline,$id]);mem_registrar($pdo,$u,$id,$r['flujo']==='revision'?'reasignado':'asignado','Revisor: '.$reviewer['nombre'].' · plazo '.$deadline.($conflict?' · Conflicto confirmado':'').' · '.$obs);mem_avisar($pdo,$reviewer['email'],$id,'Revisión asignada: '.$r['numero_memoria'].' · plazo '.$deadline);
            }elseif(in_array($action,['revisar','declinar'],true)){
                if($r['flujo']!=='revision'||(!$caps['admin']&&!mem_revisor($pdo,$u,$r)))throw new RuntimeException('La revisión corresponde al revisor asignado.');
                if($action==='declinar'){if(mb_strlen($obs)<4)throw new RuntimeException('Indica el motivo para declinar.');$pdo->prepare("UPDATE memorias_resultados SET flujo='entregado',estado='Recibida',revisor_id=NULL,revisor_contacto_id=NULL,limite_revision=NULL WHERE id=?")->execute([$id]);mem_registrar($pdo,$u,$id,'declinado',$obs);}
                else{$recommend=(string)($d['recomendacion'] ?? '');if(!in_array($recommend,['Aceptar sin cambios','Cambios menores','Cambios mayores'],true))throw new RuntimeException('Selecciona una recomendación.');$a=$upload('revision_archivo',true,['pdf','doc','docx']);$c=$upload('criterios',true);$pdo->prepare("UPDATE memorias_resultados SET flujo='observaciones',estado='Con observaciones',revision_archivo=?,revision_nombre=?,criterios_archivo_nombre=?,criterios_archivo_ruta=?,recomendacion=?,observaciones_revisor=? WHERE id=?")->execute([$a['ruta'],$a['nombre'],$c['nombre'],$c['ruta'],$recommend,$obs,$id]);mem_registrar($pdo,$u,$id,'revisado',$recommend.' · '.$obs,[$a,$c]);mem_avisar($pdo,(string)$r['autor_email'],$id,'Observaciones disponibles: '.$r['numero_memoria']);}
            }elseif($action==='corregir'){
                if($r['flujo']!=='observaciones'||(!$caps['admin']&&!mem_autor($u,$r)))throw new RuntimeException('La corrección corresponde al autor del artículo.');if(mb_strlen($obs)<5)throw new RuntimeException('Describe cómo atendiste las observaciones.');$a=$upload('archivo',true,['pdf','doc','docx']);$pdo->prepare("UPDATE memorias_resultados SET archivo_nombre=?,archivo_ruta=?,version=version+1,flujo='listo',estado='Aprobada' WHERE id=?")->execute([$a['nombre'],$a['ruta'],$id]);mem_registrar($pdo,$u,$id,'corregido',$obs,[$a]);
            }elseif($action==='diagramar'){
                if(!$caps['diag']||!in_array($r['flujo'],['listo','diagramacion'],true))throw new RuntimeException('Solo diagramación descarga la versión corregida para el consolidado.');if(!mem_ruta($r['archivo_ruta']))throw new RuntimeException('El archivo no está disponible.');if($r['flujo']==='listo'){$pdo->prepare("UPDATE memorias_resultados SET flujo='diagramacion',estado='Diagramacion',descargado_diag=NOW() WHERE id=?")->execute([$id]);mem_registrar($pdo,$u,$id,'diagramacion','Descarga solicitada por diagramación.');}
            }elseif($action==='eliminar'){
                if(!$caps['coord'])throw new RuntimeException('Solo coordinación puede eliminar artículos.');$pdo->prepare('DELETE FROM memoria_track WHERE memoria_id=?')->execute([$id]);$pdo->prepare('DELETE FROM memoria_avisos WHERE articulo_id=?')->execute([$id]);$pdo->prepare('DELETE FROM memorias_resultados WHERE id=?')->execute([$id]);
            }else throw new RuntimeException('Acción no válida.');
        }
        $pdo->commit();return 'Cambios guardados.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($new as $a){$p=mem_ruta($a['ruta']);if($p)unlink($p);}throw $e;}
}
function mem_mpr_mutar(PDO $pdo,array $u,string $action,array $d,callable $upload): void
{
    if(!mem_final($pdo,$u))throw new RuntimeException('No tienes acceso al consolidado.');$e=mem_edicion($pdo,(int)($d['id'] ?? 0),true);if(isset($d['revision'])&&(int)$d['revision']!==(int)$e['revision'])throw new RuntimeException('El consolidado cambió. Recarga antes de continuar.');$m=mem_mpr($e);$caps=mem_caps($u);$obs=trim((string)($d['observaciones'] ?? ''));$pending=array_filter($m['observaciones'],static fn($o)=>$o['estado']!=='atendida');$files=[];
    if($action==='mpr_cargar'){
        if(!$caps['diag']||!in_array($m['estado'],['armado','revision_final','aprobado_jt','aprobado'],true))throw new RuntimeException('Diagramación carga el consolidado antes de su publicación.');
        $q=$pdo->prepare("SELECT COUNT(*) FROM memorias_resultados WHERE edicion_id=? AND flujo NOT IN ('listo','diagramacion')");$q->execute([$e['id']]);if($q->fetchColumn()&&empty($d['aceptar_pendientes']))throw new RuntimeException('Confirma que cargarás el consolidado con artículos pendientes.');if($pending&&mb_strlen($obs)<5)throw new RuntimeException('Describe cómo se atendieron las observaciones.');
        $a=$upload('archivo',true,['pdf','doc','docx','indd','zip']);$files=[$a];if($m['archivo'])$m['versiones'][]=$m['archivo']+['version'=>$m['version']];$m['version']++;$m['archivo']=$a;$m['estado']='revision_final';$ids=array_map('strval',(array)($d['atendidas'] ?? []));foreach($m['observaciones'] as &$o)if($o['estado']!=='atendida'&&in_array((string)$o['id'],$ids,true)){$o['estado']='atendida';$o['respuesta']=$obs;$o['atendida_en']=$m['version'];}unset($o);$log=$m['version']===1?'cargado':'atendidas';
    }elseif($action==='mpr_observar'){
        $reviewMember=false;foreach(mem_revisores($pdo) as $rv)if(!empty($u['correo'])&&strcasecmp($rv['email'],$u['correo'])===0)$reviewMember=true;
        if(!($caps['admin']||$caps['jt']||$caps['dir']||$reviewMember)||!in_array($m['estado'],['revision_final','aprobado_jt'],true))throw new RuntimeException('No puedes agregar observaciones en esta etapa.');if(mb_strlen($obs)<5)throw new RuntimeException('Escribe la observación.');$a=$upload('archivo',false,['pdf','doc','docx','png','jpg','jpeg']);$m['observaciones'][]=['id'=>bin2hex(random_bytes(8)),'autor'=>$u['nombre'],'rol'=>$u['nombre_rol'] ?? 'Administrador','fecha'=>date('Y-m-d H:i:s'),'texto'=>$obs,'seccion'=>mb_substr(trim((string)($d['seccion'] ?? '')),0,180),'archivo'=>$a,'version'=>$m['version'],'estado'=>'pendiente'];$files=[$a];$m['estado']='revision_final';$log='observacion';
    }elseif($action==='mpr_aprobar'){
        $jt=$m['estado']==='revision_final';if(($jt&&!$caps['jt'])||(!$jt&&($m['estado']!=='aprobado_jt'||!$caps['dir'])))throw new RuntimeException('Primero aprueba Transferencia y luego Dirección General.');if($pending&&empty($d['aceptar_pendientes']))throw new RuntimeException('Hay observaciones pendientes. Confirma la aprobación de todos modos.');if($pending)$obs.=' · Aprobación con '.count($pending).' observaciones pendientes confirmada.';$m['estado']=$jt?'aprobado_jt':'aprobado';$log=$m['estado'];
    }elseif($action==='mpr_publicar'){
        if(!$caps['diag']||$m['estado']!=='aprobado')throw new RuntimeException('Solo diagramación publica después de la liberación.');$url=trim((string)($d['enlace'] ?? ''));if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true)))throw new RuntimeException('Enlace de publicación no válido.');$m['publicacion']=['enlace'=>$url,'fecha'=>mem_fecha((string)($d['fecha'] ?? ''),true)];$m['estado']='publicado';$log='publicado';$obs=$url.' · '.$obs;
    }else throw new RuntimeException('Acción del consolidado no válida.');
    $m['bitacora'][]=mem_log($u,$log,(int)$m['version'],$obs,$files);$pdo->prepare('UPDATE memoria_ediciones SET mpr=?,revision=revision+1 WHERE id=?')->execute([json_encode($m,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$e['id']]);
}
function mem_ruta(?string $ruta): ?string
{
    if(!$ruta)return null;$base=realpath(dirname(__DIR__,2).'/uploads/comites');$path=realpath(dirname(__DIR__).'/'.$ruta);return $base&&$path&&str_starts_with($path,$base.DIRECTORY_SEPARATOR)&&is_file($path)?$path:null;
}
function mem_descarga(PDO $pdo,array $u,array $q): array
{
    if(!can_view_resource($u,'memorias'))throw new RuntimeException('Acceso denegado.');$scope=$q['scope'] ?? 'articulo';$key=(string)($q['archivo'] ?? 'actual');$index=(int)($q['indice'] ?? -1);
    if($scope==='articulo'){$r=mem_fila($pdo,(int)($q['id'] ?? 0));if(!mem_puede_ver($pdo,$u,$r))throw new RuntimeException('No tienes acceso al artículo.');$a=match($key){'actual'=>['nombre'=>$r['archivo_nombre'],'ruta'=>$r['archivo_ruta']],'revision'=>['nombre'=>$r['revision_nombre'] ?: 'Revision','ruta'=>$r['revision_archivo']],'criterios'=>['nombre'=>$r['criterios_archivo_nombre'],'ruta'=>$r['criterios_archivo_ruta']],default=>null};if($key==='historial'){$log=json_decode((string)$r['expediente'],true) ?: [];$a=$log[$index]['archivos'][(int)($q['adjunto'] ?? 0)] ?? null;}}
    elseif($scope==='edicion'){$e=mem_edicion($pdo,(int)($q['id'] ?? 0));$a=in_array($key,['criterios','plantilla'],true)?json_decode((string)$e[$key],true):null;}
    elseif($scope==='mpr'){if(!mem_final($pdo,$u))throw new RuntimeException('No tienes acceso al consolidado.');$m=mem_mpr(mem_edicion($pdo,(int)($q['id'] ?? 0)));$a=match($key){'actual'=>$m['archivo'],'version'=>$m['versiones'][$index] ?? null,'observacion'=>$m['observaciones'][$index]['archivo'] ?? null,default=>null};}
    else $a=null;
    if(!$a||!mem_ruta($a['ruta'] ?? null))throw new RuntimeException('El archivo no está disponible.');return $a;
}
