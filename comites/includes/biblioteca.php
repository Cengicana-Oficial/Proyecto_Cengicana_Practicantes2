<?php
declare(strict_types=1);

const BIB_TIPOS=['Boletin'=>'Boletín','Memoria'=>'Memoria','Informe'=>'Informe','Ayudamemoria'=>'Ayudamemoria','Presentacion'=>'Presentación','Fotografia'=>'Fotografía','Video'=>'Video','Otro'=>'Otro'];
const BIB_ESTADOS=['borrador'=>'Borrador','rev1'=>'En primera revisión','rev2'=>'En segunda revisión','rev3'=>'En aprobación final','devuelto'=>'Devuelto con observaciones','aprobado'=>'Aprobado · envío habilitado','rechazado'=>'Rechazado','informativo'=>'Informativo (sin aprobación)'];
const BIB_ETAPAS=['rev1'=>['nombre'=>'Primera revisión','rol'=>'Jefe de Transferencia'],'rev2'=>['nombre'=>'Segunda revisión','rol'=>'Jefe de Transferencia'],'rev3'=>['nombre'=>'Aprobación final','rol'=>'Director General']];

function biblioteca_esquema(PDO $pdo): void
{
    directorio_preparar($pdo);
    foreach(['flujo'=>'VARCHAR(20) NULL','requiere_aprobacion'=>'TINYINT(1) NOT NULL DEFAULT 1','cargado_por_id'=>'INT NULL','cargado_por_nombre'=>'VARCHAR(180) NULL','cargado_por_rol'=>'VARCHAR(180) NULL','pendiente_desde'=>'DATETIME NULL','devuelto_en'=>'VARCHAR(20) NULL','reenviar_a'=>'VARCHAR(20) NULL','archivo_bytes'=>'BIGINT NULL'] as $c=>$def)app_ensure_column($pdo,'documentos',$c,"{$c} {$def}");
    foreach(['accion'=>'VARCHAR(30) NULL','etapa'=>'VARCHAR(20) NULL','rol'=>'VARCHAR(180) NULL','version'=>'INT NULL','archivo_nombre'=>'VARCHAR(255) NULL','archivo_ruta'=>'VARCHAR(500) NULL','enlace'=>'VARCHAR(500) NULL'] as $c=>$def)app_ensure_column($pdo,'documento_historial',$c,"{$c} {$def}");
}
function biblioteca_preparar(PDO $pdo): void
{
    biblioteca_esquema($pdo);
    // Se conserva el estado anterior y su historial sin inventar aprobadores ni propietarios.
    $pdo->exec("UPDATE documentos SET flujo=CASE WHEN estado IN ('Aprobado','Publicado') THEN 'aprobado' WHEN estado='Rechazado' THEN 'rechazado' WHEN estado='Con observaciones' THEN 'devuelto' WHEN estado='Borrador' THEN 'borrador' ELSE 'rev1' END,pendiente_desde=actualizado_en,devuelto_en=IF(estado='Con observaciones','rev1',NULL),reenviar_a=IF(estado='Con observaciones','rev2',NULL),habilitado_envio=IF(estado IN ('Aprobado','Publicado'),1,0) WHERE ambito='ingenios' AND flujo IS NULL");
    $pdo->prepare("INSERT INTO documento_carpetas(ambito,nombre,parent_id) SELECT 'ingenios',?,NULL WHERE NOT EXISTS (SELECT 1 FROM documento_carpetas WHERE ambito='ingenios')")->execute(['Boletines '.date('Y')]);
}
function biblioteca_admin(array $u): bool { return is_superadmin($u)||(can_manage_resource($u,'documentos')&&trim(normalize_text((string)($u['nombre_rol'] ?? '')))==='administrador'); }
function biblioteca_propietario(array $u,array $r): bool { return biblioteca_admin($u)||(!empty($r['cargado_por_id'])&&(int)$r['cargado_por_id']===(int)($u['id'] ?? 0)); }
function biblioteca_revisor(array $u,string $flujo): bool
{
    if(!isset(BIB_ETAPAS[$flujo])||!can_view_resource($u,'documentos'))return false;
    if(biblioteca_admin($u))return true;
    $rol=trim(normalize_text((string)($u['nombre_rol'] ?? '')));
    return has_permission($u,'comites.documentos.'.($flujo==='rev3'?'aprobar_direccion':'revisar_transferencia')) || $rol===($flujo==='rev3'?'director general':'jefe de transferencia');
}
function biblioteca_fila(PDO $pdo,int $id,bool $lock=false): array
{
    $q=$pdo->prepare("SELECT * FROM documentos WHERE id=? AND ambito='ingenios'".($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('El documento no existe en esta biblioteca.');return $r;
}
function biblioteca_log(PDO $pdo,array $r,array $u,string $accion,string $etapa='',string $obs='',array $archivo=[]): void
{
    $pdo->prepare('INSERT INTO documento_historial(documento_id,estado,actor,fecha,observaciones,accion,etapa,rol,version,archivo_nombre,archivo_ruta,enlace) VALUES (?,?,?,CURDATE(),?,?,?,?,?,?,?,?)')->execute([$r['id'],BIB_ESTADOS[$r['flujo']] ?? $r['estado'],mb_substr((string)$u['nombre'],0,180),$obs,$accion,$etapa,mb_substr((string)($u['nombre_rol'] ?? 'Administrador'),0,180),(int)$r['version'],$archivo['nombre'] ?? null,$archivo['ruta'] ?? null,$archivo['enlace'] ?? null]);
}
function biblioteca_estado(PDO $pdo,int $id,string $flujo,?string $devuelto=null,?string $reenviar=null): void
{
    $estado=match($flujo){'aprobado'=>'Aprobado','devuelto'=>'Con observaciones','rechazado'=>'Rechazado','borrador'=>'Borrador','informativo'=>'Informativo',default=>'En revision'};
    $pdo->prepare('UPDATE documentos SET flujo=?,estado=?,habilitado_envio=?,pendiente_desde=NOW(),devuelto_en=?,reenviar_a=? WHERE id=?')->execute([$flujo,$estado,$flujo==='aprobado'?1:0,$devuelto,$reenviar,$id]);
}
function biblioteca_revision(PDO $pdo,array $r): string
{
    $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM documento_historial WHERE documento_id=?');$q->execute([$r['id']]);return $r['version'].':'.$r['flujo'].':'.$q->fetchColumn();
}
function biblioteca_datos(PDO $pdo,array $u): array
{
    $hist=[];foreach($pdo->query("SELECT h.* FROM documento_historial h JOIN documentos d ON d.id=h.documento_id WHERE d.ambito='ingenios' ORDER BY h.id") as $h)$hist[$h['documento_id']][]=$h;
    $rows=$pdo->query("SELECT * FROM documentos WHERE ambito='ingenios' ORDER BY COALESCE(pendiente_desde,creado_en) DESC,id DESC")->fetchAll();
    foreach($rows as &$r){$r['id']=(int)$r['id'];$r['carpeta_id']=(int)$r['carpeta_id'];$r['historial']=$hist[$r['id']] ?? [];$r['propietario']=biblioteca_propietario($u,$r);$r['revisar']=biblioteca_revisor($u,$r['flujo']);$r['revision']=biblioteca_revision($pdo,$r);$r['enviar']=$r['flujo']==='aprobado'&&$r['propietario']&&can_manage_resource($u,'correos');}unset($r);
    $contacts=[];$segments=[];
    if(can_manage_resource($u,'correos')){
        $groups=[];foreach($pdo->query('SELECT * FROM contacto_comite') as $c)$groups[$c['contacto_id']][]=$c['comite_id'];
        $contacts=$pdo->query("SELECT id,nombre,email,cargo,empresa,area,nivel,origen_tipo,estado FROM contactos WHERE estado='Activo' ORDER BY nombre")->fetchAll();
        foreach($contacts as &$c){$c['id']=(int)$c['id'];$c['comites']=$groups[$c['id']] ?? [];}unset($c);
        $q=$pdo->prepare('SELECT id,nombre,filtros FROM contacto_segmentos WHERE usuario_id=? ORDER BY nombre');$q->execute([(int)$u['id']]);$segments=$q->fetchAll();foreach($segments as &$s)$s['filtros']=json_decode($s['filtros'],true) ?: [];unset($s);
    }
    return ['documentos'=>$rows,'carpetas'=>$pdo->query("SELECT id,nombre,parent_id FROM documento_carpetas WHERE ambito='ingenios' ORDER BY nombre")->fetchAll(),'comites'=>$pdo->query('SELECT id,nombre FROM comites ORDER BY nombre')->fetchAll(),'contactos'=>$contacts,'segmentos'=>$segments,'tipos'=>BIB_TIPOS,'estados'=>BIB_ESTADOS,'etapas'=>BIB_ETAPAS,'gestionar'=>can_manage_resource($u,'documentos'),'csrf'=>$_SESSION['biblioteca_csrf'],'hoy'=>date('Y-m-d')];
}
function biblioteca_url(string $url): string
{
    $url=trim($url);if($url!==''&&(mb_strlen($url)>500||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true)))throw new RuntimeException('El enlace debe ser una dirección HTTP o HTTPS válida.');return $url;
}
function biblioteca_archivo(array $f): array
{
    if(($f['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return [];
    if(($f['error'] ?? 1)!==UPLOAD_ERR_OK)throw new RuntimeException('No se recibió el archivo completo. Comprueba el límite de 20 MB.');
    if(($f['size'] ?? 0)>20*1024*1024)throw new RuntimeException('Cada archivo admite hasta 20 MB.');
    $original=mb_substr(basename((string)$f['name']),0,180);$f['name']=bin2hex(random_bytes(12)).'_'.preg_replace('/[^A-Za-z0-9_.-]/','_',$original);
    $a=comites_guardar_archivo($f,'documentos',['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','webp','mp4','txt']);
    if($a['error'])throw new RuntimeException($a['error']);return ['nombre'=>$original,'ruta'=>$a['ruta'],'bytes'=>(int)$f['size']];
}
function biblioteca_guardar(PDO $pdo,array $u,array $d,?array $r,array $a): int
{
    if(!can_manage_resource($u,'documentos'))throw new RuntimeException('No tienes permiso para cargar o editar documentos.');
    if($r&&isset(BIB_ETAPAS[$r['flujo']]))throw new RuntimeException('El documento está en revisión. Sus datos están bloqueados.');
    $carpeta=(int)($d['carpeta_id'] ?? 0);if(!comites_carpeta_obtener($pdo,$carpeta,'ingenios'))throw new RuntimeException('Selecciona una carpeta de esta biblioteca.');
    $titulo=trim((string)($d['titulo'] ?? ''));if($titulo===''||mb_strlen($titulo)>220)throw new RuntimeException('Escribe un título de hasta 220 caracteres.');
    $tipo=(string)($d['tipo'] ?? 'Boletin');if(!isset(BIB_TIPOS[$tipo]))throw new RuntimeException('Tipo de documento no válido.');
    $fecha=trim((string)($d['fecha'] ?? ''));if($fecha!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha)||date('Y-m-d',strtotime($fecha))!==$fecha))throw new RuntimeException('Fecha no válida.');
    $url=biblioteca_url((string)($d['enlace'] ?? ''));$requiere=!empty($d['requiere_aprobacion']);
    // Un documento en proceso no puede saltarse la revisión al desmarcar la casilla.
    if($r&&$r['requiere_aprobacion']&&!$requiere&&$r['flujo']!=='borrador')throw new RuntimeException('Solo se puede cambiar el requisito de aprobación en borrador.');
    $archivo=$a['ruta'] ?? $r['archivo_ruta'] ?? null;$nombre=$a['nombre'] ?? $r['archivo_nombre'] ?? null;
    $cambio=$r&&(!empty($a)||$url!==($r['enlace'] ?? ''));
    $version=(int)($r['version'] ?? 1)+($cambio?1:0);
    $flujo=$r['flujo'] ?? 'borrador';if(!$requiere)$flujo='informativo';elseif($flujo==='informativo')$flujo='borrador';
    $iniciar=(!$r&&!empty($d['enviar_ahora'])&&$requiere)||($r&&$r['flujo']==='aprobado'&&$cambio&&$requiere);
    if($iniciar){if(!$archivo&&!$url)throw new RuntimeException('Carga un archivo o enlace antes de enviar a revisión.');$flujo='rev1';}
    $v=[$tipo,$carpeta,$titulo,$fecha ?: null,mb_substr(trim((string)($d['autor'] ?? '')),0,180),$url ?: null,trim((string)($d['descripcion'] ?? '')),$nombre,$archivo,$a['bytes'] ?? $r['archivo_bytes'] ?? null,$version,$requiere?1:0];
    if($r){$id=(int)$r['id'];$pdo->prepare('UPDATE documentos SET tipo=?,carpeta_id=?,titulo=?,fecha=?,autor=?,enlace=?,descripcion=?,archivo_nombre=?,archivo_ruta=?,archivo_bytes=?,version=?,requiere_aprobacion=? WHERE id=?')->execute([...$v,$id]);}
    else{$pdo->prepare("INSERT INTO documentos(tipo,carpeta_id,titulo,fecha,autor,enlace,descripcion,archivo_nombre,archivo_ruta,archivo_bytes,version,requiere_aprobacion,cargado_por_id,cargado_por_nombre,cargado_por_rol,ambito) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ingenios')")->execute([...$v,(int)$u['id'],mb_substr((string)$u['nombre'],0,180),mb_substr((string)($u['nombre_rol'] ?? 'Administrador'),0,180)]);$id=(int)$pdo->lastInsertId();}
    if(!$r||$flujo!==$r['flujo'])biblioteca_estado($pdo,$id,$flujo);
    $actual=biblioteca_fila($pdo,$id);
    biblioteca_log($pdo,$actual,$u,!$r?'creado':($cambio?'nueva_version':'editado'),$iniciar?'rev1':'','',['nombre'=>$nombre,'ruta'=>$archivo,'enlace'=>$url]);
    if($iniciar)biblioteca_log($pdo,$actual,$u,'enviado','rev1');return $id;
}
function biblioteca_mutar(PDO $pdo,array $u,string $accion,array $d,array $files=[]): string
{
    if(!can_view_resource($u,'documentos'))throw new RuntimeException('No tienes acceso a esta biblioteca.');
    $nuevos=[];$pdo->beginTransaction();
    try{
        $id=(int)($d['id'] ?? 0);$r=$id?biblioteca_fila($pdo,$id,true):null;
        if($r&&isset($d['revision'])&&!hash_equals(biblioteca_revision($pdo,$r),(string)$d['revision']))throw new RuntimeException('El documento cambió mientras lo consultabas. Recarga la biblioteca antes de continuar.');
        if($accion==='carpeta'){
            if(!can_manage_resource($u,'documentos'))throw new RuntimeException('No puedes crear carpetas.');
            $nombre=trim((string)($d['nombre'] ?? ''));if(!$nombre||mb_strlen($nombre)>160)throw new RuntimeException('Indica un nombre de hasta 160 caracteres.');
            $parent=(int)($d['parent_id'] ?? 0);if($parent&&!comites_carpeta_obtener($pdo,$parent,'ingenios'))throw new RuntimeException('Carpeta padre no válida.');
            $q=$pdo->prepare("SELECT COUNT(*) FROM documento_carpetas WHERE ambito='ingenios' AND parent_id <=> ? AND nombre=?");$q->execute([$parent ?: null,$nombre]);if($q->fetchColumn())throw new RuntimeException('Ya existe una carpeta con ese nombre aquí.');
            comites_carpeta_crear($pdo,'ingenios',$nombre,$parent ?: null);
        }elseif($accion==='guardar'){
            if(!can_manage_resource($u,'documentos')||($r&&isset(BIB_ETAPAS[$r['flujo']])))throw new RuntimeException('No puedes editar este documento mientras está en revisión.');
            $a=biblioteca_archivo($files['archivo'] ?? []);if($a)$nuevos[]=$a['ruta'];biblioteca_guardar($pdo,$u,$d,$r,$a);
        }elseif($accion==='lote'){
            if(!can_manage_resource($u,'documentos'))throw new RuntimeException('No puedes cargar documentos.');
            $filas=(array)($d['filas'] ?? []);if(!$filas||count($filas)>20)throw new RuntimeException('Selecciona entre 1 y 20 archivos.');
            foreach($filas as $i=>$fila){$a=biblioteca_archivo($files['archivo_'.$i] ?? []);if(!$a)throw new RuntimeException('Falta un archivo del lote.');$nuevos[]=$a['ruta'];biblioteca_guardar($pdo,$u,array_merge($d,$fila,['enviar_ahora'=>false]),null,$a);}
        }elseif($r&&$accion==='eliminar'){
            if(!can_manage_resource($u,'documentos'))throw new RuntimeException('No puedes eliminar documentos.');
            $pdo->prepare('DELETE FROM documento_historial WHERE documento_id=?')->execute([$id]);$pdo->prepare('UPDATE correos_enviados SET documento_id=NULL WHERE documento_id=?')->execute([$id]);$pdo->prepare('DELETE FROM documentos WHERE id=?')->execute([$id]);
        }elseif($r&&$accion==='revisar'){
            if(!biblioteca_revisor($u,$r['flujo']))throw new RuntimeException('Esta etapa corresponde a '.(BIB_ETAPAS[$r['flujo']]['rol'] ?? 'otro responsable').'.');
            $decision=(string)($d['decision'] ?? '');$obs=trim((string)($d['observaciones'] ?? ''));
            if(!in_array($decision,['aprobado','devuelto','rechazado'],true))throw new RuntimeException('Selecciona una decisión.');
            if($decision!=='aprobado'&&mb_strlen($obs)<5)throw new RuntimeException('Escribe las anotaciones o el motivo del rechazo (al menos 5 caracteres).');
            $a=$decision!=='aprobado'?biblioteca_archivo($files['archivo'] ?? []):[];if($a)$nuevos[]=$a['ruta'];
            $next=$decision==='aprobado'?($r['flujo']==='rev3'?'aprobado':'rev3'):$decision;
            biblioteca_estado($pdo,$id,$next,$decision==='devuelto'?$r['flujo']:null,$decision==='devuelto'?($r['flujo']==='rev1'?'rev2':$r['flujo']):null);
            $actual=biblioteca_fila($pdo,$id);biblioteca_log($pdo,$actual,$u,$decision,$r['flujo'],$obs,$a);
            $pdo->prepare('UPDATE documentos SET observaciones=?,causa_rechazo=? WHERE id=?')->execute([$obs,$decision==='rechazado'?$obs:null,$id]);
            if($decision==='aprobado'&&$r['flujo']==='rev1')biblioteca_log($pdo,$actual,$u,'omitido','rev2','Sin anotaciones en la primera revisión: pasa directo a la aprobación final.');
        }elseif($r&&in_array($accion,['enviar_revision','corregir','nueva_version'],true)){
            if(!biblioteca_propietario($u,$r)||!can_manage_resource($u,'documentos'))throw new RuntimeException('Esta acción corresponde a quien cargó el documento.');
            if($accion==='enviar_revision'){
                if($r['flujo']!=='borrador'||!$r['requiere_aprobacion'])throw new RuntimeException('Solo se envían borradores que requieren aprobación.');
                if(!$r['archivo_ruta']&&!$r['enlace'])throw new RuntimeException('Carga un archivo o registra un enlace antes de enviar.');
                biblioteca_estado($pdo,$id,'rev1');biblioteca_log($pdo,biblioteca_fila($pdo,$id),$u,'enviado','rev1');
            }elseif($accion==='nueva_version'){
                if($r['flujo']!=='rechazado')throw new RuntimeException('Solo se reinician versiones rechazadas.');
                $pdo->prepare('UPDATE documentos SET version=version+1 WHERE id=?')->execute([$id]);biblioteca_estado($pdo,$id,'borrador');biblioteca_log($pdo,biblioteca_fila($pdo,$id),$u,'nueva_version');
            }else{
                if($r['flujo']!=='devuelto')throw new RuntimeException('El documento no tiene observaciones pendientes.');
                $obs=trim((string)($d['observaciones'] ?? ''));if(mb_strlen($obs)<5)throw new RuntimeException('Describe cómo atendiste las observaciones.');
                $url=biblioteca_url((string)($d['enlace'] ?? ''));$a=biblioteca_archivo($files['archivo'] ?? []);if($a)$nuevos[]=$a['ruta'];
                if(!$a&&$url===($r['enlace'] ?? ''))throw new RuntimeException('Carga el archivo corregido o actualiza el enlace.');
                if(!$a&&!$url&&!$r['archivo_ruta'])throw new RuntimeException('La corrección necesita un archivo o enlace.');
                $pdo->prepare('UPDATE documentos SET archivo_nombre=?,archivo_ruta=?,archivo_bytes=?,enlace=?,version=version+1 WHERE id=?')->execute([$a['nombre'] ?? $r['archivo_nombre'],$a['ruta'] ?? $r['archivo_ruta'],$a['bytes'] ?? $r['archivo_bytes'],$url ?: null,$id]);
                $next=$r['reenviar_a'] ?: ($r['devuelto_en']==='rev1'?'rev2':$r['devuelto_en']);if(!isset(BIB_ETAPAS[$next]))$next='rev1';
                biblioteca_estado($pdo,$id,$next);$actual=biblioteca_fila($pdo,$id);biblioteca_log($pdo,$actual,$u,'reenviado',$next,$obs,['nombre'=>$actual['archivo_nombre'],'ruta'=>$actual['archivo_ruta'],'enlace'=>$url]);
            }
        }else throw new RuntimeException('Acción no válida.');
        $pdo->commit();return $accion==='lote'?count($d['filas']).' documentos cargados. Los que requieren aprobación quedaron en borrador.':'Cambios guardados.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($nuevos as $ruta){$path=biblioteca_ruta($ruta);if($path)unlink($path);}throw $e;}
}
function biblioteca_ruta(?string $ruta): ?string
{
    if(!$ruta)return null;$base=realpath(dirname(__DIR__,2).'/uploads/comites');$path=realpath(dirname(__DIR__).'/'.$ruta);
    return $base&&$path&&str_starts_with($path,$base.DIRECTORY_SEPARATOR)&&is_file($path)?$path:null;
}

/** Prepara destinatarios y contenido; no envía nada. También se usa en las pruebas. */
function biblioteca_correo_datos(PDO $pdo,array $u,array $r,array $d): array
{
    if(!can_view_resource($u,'documentos')||!can_manage_resource($u,'correos')||!biblioteca_propietario($u,$r)||$r['flujo']!=='aprobado')throw new RuntimeException('Solo quien cargó el documento aprobado puede enviarlo, con permiso de correos.');
    $ids=array_unique(array_map('intval',(array)($d['contactos'] ?? [])));if(!$ids||count($ids)>500)throw new RuntimeException('Selecciona entre 1 y 500 contactos.');
    $q=$pdo->prepare("SELECT id,nombre,email FROM contactos WHERE estado='Activo' AND id IN (".implode(',',array_fill(0,count($ids),'?')).')');$q->execute(array_values($ids));$dest=[];
    foreach($q as $c)if(filter_var($c['email'],FILTER_VALIDATE_EMAIL))$dest[strtolower(trim($c['email']))]=$c;
    if(!$dest)throw new RuntimeException('No hay destinatarios activos con correo válido.');
    $asunto=trim((string)($d['asunto'] ?? ''));$cuerpo=trim((string)($d['cuerpo'] ?? ''));if(!$asunto||mb_strlen($asunto)>220||!$cuerpo||mb_strlen($cuerpo)>15000)throw new RuntimeException('Revisa el asunto y el mensaje.');
    $modo=(string)($d['modo'] ?? 'archivo');if(!in_array($modo,['archivo','enlace'],true))throw new RuntimeException('Selecciona archivo o enlace.');
    $archivo=$modo==='archivo'?biblioteca_ruta($r['archivo_ruta']):null;$enlace=$modo==='enlace'?biblioteca_url((string)$r['enlace']):'';
    if($modo==='archivo'&&!$archivo)throw new RuntimeException('No hay un archivo disponible para adjuntar.');if($modo==='enlace'&&!$enlace)throw new RuntimeException('El documento no tiene enlace registrado.');
    $groups=[];$gids=array_unique(array_map('strval',(array)($d['comites'] ?? [])));if($gids){$q=$pdo->prepare('SELECT nombre FROM comites WHERE id IN ('.implode(',',array_fill(0,count($gids),'?')).')');$q->execute(array_values($gids));$groups=$q->fetchAll(PDO::FETCH_COLUMN);}
    return ['destinatarios'=>array_values($dest),'asunto'=>$asunto,'cuerpo'=>$cuerpo,'archivo'=>$archivo,'enlace'=>$enlace,'grupos'=>mb_substr(implode(', ',$groups) ?: 'Contactos seleccionados',0,160)];
}
function biblioteca_enviar(PDO $pdo,array $u,array $d): string
{
    // Bloquear la versión durante el envío impide que se comparta un archivo sustituido simultáneamente.
    $pdo->beginTransaction();
    try{
        $r=biblioteca_fila($pdo,(int)($d['id'] ?? 0),true);
        if(!hash_equals(biblioteca_revision($pdo,$r),(string)($d['revision'] ?? '')))throw new RuntimeException('El documento cambió. Recarga antes de enviar.');
        $v=biblioteca_correo_datos($pdo,$u,$r,$d);
        foreach(['MAIL_HOST','MAIL_USERNAME','MAIL_PASSWORD','MAIL_FROM_ADDRESS'] as $key)if(in_array(app_env_value($key),['','change-me'],true))throw new RuntimeException('Configura las credenciales SMTP en deploy/env/login.env. El correo no se ha enviado.');
        directorio_dependencias();$mail=new \PHPMailer\PHPMailer\PHPMailer(true);$mail->isSMTP();$mail->Host=app_env_value('MAIL_HOST');$mail->Port=(int)app_env_value('MAIL_PORT','2525');$mail->SMTPAuth=true;$mail->Username=app_env_value('MAIL_USERNAME');$mail->Password=app_env_value('MAIL_PASSWORD');$mail->SMTPSecure=$mail->Port===465?'ssl':'tls';$mail->CharSet='UTF-8';$mail->Timeout=20;
        $mail->setFrom(app_env_value('MAIL_FROM_ADDRESS'),app_env_value('MAIL_FROM_NAME','CENGICAÑA'));
        foreach($v['destinatarios'] as $c)$mail->addBCC($c['email'],$c['nombre']);
        $logo=(string)($d['logo'] ?? '');$logoHtml='';
        if($logo!==''){
            if(strlen($logo)>1500000||!preg_match('~^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$~',$logo,$m))throw new RuntimeException('Logo no válido. Usa PNG, JPG o WebP de hasta 1 MB.');
            $bin=base64_decode($m[2],true);$info=$bin?@getimagesizefromstring($bin):false;if(!$info||$info['mime']!==$m[1])throw new RuntimeException('Logo no válido.');
            $mail->addStringEmbeddedImage($bin,'biblioteca-logo','logo','base64',$m[1]);$logoHtml='<img src="cid:biblioteca-logo" alt="Logo institucional" style="max-height:70px;max-width:240px"><br>';
        }
        $mail->Subject=$v['asunto'];$mail->isHTML(true);$mail->Body=$logoHtml.'<h2>'.e($r['titulo']).'</h2><p>'.nl2br(e($v['cuerpo'])).'</p>'.($v['enlace']?'<p><a href="'.e($v['enlace']).'">Abrir documento</a></p>':'');$mail->AltBody=$v['cuerpo'].($v['enlace']?"\n".$v['enlace']:'');
        if($v['archivo'])$mail->addAttachment($v['archivo'],$r['archivo_nombre']);
        try{$mail->send();}catch(\PHPMailer\PHPMailer\Exception $e){error_log('Biblioteca SMTP: '.$e->getMessage());throw new RuntimeException('El servidor SMTP no confirmó el envío. Revisa la configuración antes de reintentar.');}
        $pdo->prepare('INSERT INTO correos_enviados(documento_id,asunto,cuerpo,destinatarios,grupo_destino,enviado_por) VALUES (?,?,?,?,?,?)')->execute([$r['id'],$v['asunto'],$v['cuerpo'],json_encode(array_column($v['destinatarios'],'email')),$v['grupos'],$u['nombre']]);
        biblioteca_log($pdo,$r,$u,'compartido','',count($v['destinatarios']).' destinatarios · '.$v['grupos']);$pdo->commit();return 'Correo enviado y registrado.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
