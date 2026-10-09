<?php
declare(strict_types=1);
const INF_ESTADOS=['pendiente'=>'Pendiente de entrega','entregado'=>'Entregado · por revisar','observado'=>'Revisado con pendientes','atendido'=>'Pendientes atendidos','revisado'=>'Revisado'];
const INF_AREAS=['Transferencia de Tecnología','Investigación','Variedades','Riego','Nutrición','Manejo Integrado de Plagas','Malezas y Madurantes','Agricultura de Precisión'];
function inf_preparar(PDO $pdo):void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS informe_areas(id INT AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(180) NOT NULL UNIQUE,correo VARCHAR(180) NOT NULL DEFAULT '',activo TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    app_ensure_column($pdo,'informe_areas','programa_id','programa_id INT NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS informe_control(id INT PRIMARY KEY,revision INT NOT NULL DEFAULT 1) ENGINE=InnoDB");$pdo->exec('INSERT IGNORE INTO informe_control(id) VALUES(1)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS informe_poa(anio INT PRIMARY KEY,filas LONGTEXT NULL,fuente VARCHAR(255) NULL,url TEXT NULL,sincronizado DATETIME NULL,revision INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(['anio'=>'INT NULL','cuatri'=>'INT NULL','area_id'=>'INT NULL','flujo'=>"VARCHAR(25) NOT NULL DEFAULT 'pendiente'",'enlace'=>'TEXT NULL','pendientes'=>'LONGTEXT NULL','bitacora'=>'LONGTEXT NULL','revision'=>'INT NOT NULL DEFAULT 1'] as $c=>$d)app_ensure_column($pdo,'informes_cuatrimestrales',$c,"$c $d");
    if(!(int)$pdo->query('SELECT COUNT(*) FROM informe_areas')->fetchColumn())foreach(INF_AREAS as $area)$pdo->prepare('INSERT IGNORE INTO informe_areas(nombre) VALUES(?)')->execute([$area]);
    foreach($pdo->query('SELECT * FROM informes_cuatrimestrales WHERE anio IS NULL')->fetchAll() as $r){
        if(!$r['area']||!preg_match('/\b(19\d{2}|20\d{2}|21\d{2})\b/',$r['periodo'],$year)||!preg_match('/\b(III|II|I|[123])\s*(?:cuatrimestre|cuat)/i',$r['periodo'],$term))continue;
        $c=['I'=>1,'II'=>2,'III'=>3][strtoupper($term[1])] ?? (int)$term[1];
        $pdo->prepare('INSERT IGNORE INTO informe_areas(nombre) VALUES(?)')->execute([$r['area']]);$q=$pdo->prepare('SELECT id FROM informe_areas WHERE nombre=?');$q->execute([$r['area']]);$aid=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT COUNT(*) FROM informes_cuatrimestrales WHERE anio=? AND cuatri=? AND area_id=?');$q->execute([(int)$year[1],$c,$aid]);if($q->fetchColumn())continue;
        $state=$r['evaluacion']==='Satisfactorio'?'revisado':(in_array($r['evaluacion'],['Con observaciones','No satisfactorio'],true)?'observado':($r['plan_operativo_ruta']&&$r['informe_ruta']?'entregado':'pendiente'));
        $pending=$state==='observado'?[['id'=>bin2hex(random_bytes(8)),'texto'=>$r['observaciones'] ?: $r['evaluacion'],'estado'=>'abierto','fecha'=>$r['fecha'],'respuesta'=>'']]:[];
        $log=[['accion'=>'Registro anterior conservado','actor'=>'Sistema','rol'=>'Migración','fecha'=>$r['creado_en'],'observaciones'=>$r['observaciones'],'archivos'=>[]]];
        $pdo->prepare('UPDATE informes_cuatrimestrales SET anio=?,cuatri=?,area_id=?,flujo=?,pendientes=?,bitacora=? WHERE id=? AND anio IS NULL')->execute([(int)$year[1],$c,$aid,$state,json_encode($pending),json_encode($log),$r['id']]);
    }
}
function inf_caps(array $u):array {
    $role=trim(normalize_text($u['nombre_rol'] ?? ''));$admin=is_superadmin($u)||($role==='administrador'&&can_manage_resource($u,'informes'));
    return ['admin'=>$admin,'gestionar'=>$admin||$role==='jefe de transferencia'||can_manage_resource($u,'informes'),'revisar'=>$admin||$role==='director general'||has_permission($u,'comites.informes.revisar'),'correo'=>strtolower(trim($u['correo'] ?? ''))];
}
function inf_area(PDO $pdo,int $id):array {$q=$pdo->prepare('SELECT * FROM informe_areas WHERE id=?');$q->execute([$id]);$a=$q->fetch();if(!$a)throw new RuntimeException('Área no válida.');return $a;}
function inf_entregar(array $u,array $area):bool {$c=inf_caps($u);return $c['gestionar']||($c['correo']!==''&&strcasecmp($c['correo'],trim((string)($area['correo'] ?? '')))===0)||(!empty($area['programa_id'])&&(int)$area['programa_id']===(int)($u['programa_id'] ?? 0)&&normalize_text((string)($u['nombre_rol'] ?? ''))==='jefe de programa');}
/** Programas de la plataforma (los de Solicitudes internas) para vincular cada área con su jefe. */
function inf_programas(PDO $pdo):array {try{return $pdo->query('SELECT id,nombre FROM '.comites_programas_db().'.programas WHERE activo=1 ORDER BY nombre')->fetchAll();}catch(PDOException $e){return [];}}
function inf_periodo(array $d):array {$y=(int)($d['anio'] ?? 0);$c=(int)($d['cuatri'] ?? 0);if($y<1900||$y>2199||$c<1||$c>3)throw new RuntimeException('Año o cuatrimestre no válido.');return [$y,$c];}
function inf_limite(int $y,int $c):string {return $c===3?($y+1).'-01-15':$y.($c===1?'-05-15':'-09-15');}
function inf_url(string $url):string {$url=trim($url);if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)))throw new RuntimeException('El enlace debe ser HTTP o HTTPS válido.');return $url;}
function inf_fila(PDO $pdo,int $id):array {$q=$pdo->prepare('SELECT * FROM informes_cuatrimestrales WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Informe no encontrado.');return $r;}
function inf_archivos(PDO $pdo,array $r):array {return array_merge(array_values(array_filter([!empty($r['plan_operativo_ruta'])?['nombre'=>$r['plan_operativo_nombre'],'ruta'=>$r['plan_operativo_ruta']]:null,!empty($r['informe_ruta'])?['nombre'=>$r['informe_nombre'],'ruta'=>$r['informe_ruta']]:null])),array_map(static fn($a)=>['nombre'=>$a['nombre'],'ruta'=>$a['ruta']],comites_informe_soportes($pdo,(int)$r['id'])));}
function inf_ruta(?string $ruta):?string {$base=realpath(dirname(__DIR__,2).'/uploads/comites/informes');$path=$ruta?realpath(dirname(__DIR__).'/'.$ruta):false;return $base&&$path&&str_starts_with($path,$base.DIRECTORY_SEPARATOR)&&is_file($path)?$path:null;}
function inf_upload(array $file,array $ext):?array {
    if(($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    if(($file['size'] ?? 0)>20*1024*1024)throw new RuntimeException('Cada archivo admite hasta 20 MB.');
    $name=mb_substr(basename((string)$file['name']),0,160);$file['name']=bin2hex(random_bytes(12)).'_'.preg_replace('/[^A-Za-z0-9_.-]/','_',$name);$a=comites_guardar_archivo($file,'informes',$ext);if($a['error'])throw new RuntimeException($a['error']);return ['nombre'=>$name,'ruta'=>$a['ruta']];
}
function inf_datos(PDO $pdo,array $u):array {
    $caps=inf_caps($u);$areas=$pdo->query('SELECT * FROM informe_areas ORDER BY id')->fetchAll();foreach($areas as &$a){$a['id']=(int)$a['id'];$a['programa_id']=(int)($a['programa_id'] ?? 0);$a['entregar']=inf_entregar($u,$a);if(!$caps['gestionar'])unset($a['correo']);}unset($a);
    $rows=$pdo->query('SELECT * FROM informes_cuatrimestrales ORDER BY anio DESC,cuatri,area,id')->fetchAll();foreach($rows as &$r){$r['id']=(int)$r['id'];$r['anio']=(int)$r['anio'];$r['cuatri']=(int)$r['cuatri'];$r['area_id']=(int)$r['area_id'];$r['pendientes']=json_decode((string)$r['pendientes'],true) ?: [];$r['bitacora']=json_decode((string)$r['bitacora'],true) ?: [];$r['soportes']=comites_informe_soportes($pdo,$r['id']);}unset($r);
    $poa=$pdo->query('SELECT * FROM informe_poa ORDER BY anio DESC')->fetchAll();foreach($poa as &$p)$p['filas']=json_decode((string)$p['filas'],true) ?: [];unset($p);
    return ['areas'=>$areas,'programas'=>$caps['gestionar']?inf_programas($pdo):[],'informes'=>$rows,'poa'=>$poa,'estados'=>INF_ESTADOS,'permisos'=>$caps,'revision_areas'=>(int)$pdo->query('SELECT revision FROM informe_control WHERE id=1')->fetchColumn(),'csrf'=>$_SESSION['informes_csrf'],'hoy'=>date('Y-m-d')];
}
function inf_poa_filas(array $rows):array {
    if(count($rows)>2000)throw new RuntimeException('El POA admite hasta 2000 filas.');$out=[];
    foreach($rows as $i=>$row){$r=[];foreach($row as $k=>$v)$r[trim(normalize_text((string)$k))]=trim((string)$v);if(!implode('',$r))continue;
        $area=$r['area'] ?? '';$act=$r['actividad'] ?? $r['indicador'] ?? $r['actividad indicador'] ?? '';$c=(int)($r['cuatrimestre'] ?? 0);$meta=$r['meta'] ?? '';$logro=$r['logro'] ?? $r['avance'] ?? $r['ejecutado'] ?? '';
        if(!$area||mb_strlen($area)>180||!$act||mb_strlen($act)>1000||$c<1||$c>3||!is_numeric($meta)||!is_numeric($logro)||(float)$meta<0||(float)$logro<0||!is_finite((float)$meta)||!is_finite((float)$logro))throw new RuntimeException('Fila '.($i+2).': revisa área, cuatrimestre, actividad, meta y logro.');
        $out[]=['area'=>$area,'cuatrimestre'=>$c,'actividad'=>$act,'meta'=>(float)$meta,'logro'=>(float)$logro,'unidad'=>mb_substr($r['unidad'] ?? '',0,80),'evidencia'=>inf_url($r['evidencia'] ?? $r['enlace de evidencia'] ?? '')];
    }if(!$out)throw new RuntimeException('No se encontraron filas válidas del POA.');return $out;
}
function inf_leer_poa(array $file):array {
    if(($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size'] ?? 0)>5*1024*1024)throw new RuntimeException('Selecciona Excel o CSV de hasta 5 MB.');$ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if($ext==='csv'){$handle=fopen($file['tmp_name'],'r');$first=(string)fgets($handle);rewind($handle);$delimiter=substr_count($first,';')>substr_count($first,',')?';':',';$headers=fgetcsv($handle,0,$delimiter);if(!$headers){fclose($handle);throw new RuntimeException('CSV vacío.');}$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);$rows=[];while(($row=fgetcsv($handle,0,$delimiter))!==false){$r=[];foreach($headers as $i=>$h)$r[(string)$h]=$row[$i] ?? '';$rows[]=$r;if(count($rows)>2000){fclose($handle);throw new RuntimeException('El POA admite hasta 2000 filas.');}}fclose($handle);return inf_poa_filas($rows);}
    if($ext==='xlsx'){$zip=new ZipArchive();if($zip->open($file['tmp_name'])!==true)throw new RuntimeException('Excel no válido.');$total=0;for($i=0;$i<$zip->numFiles;$i++)$total+=$zip->statIndex($i)['size'];$zip->close();if($total>30*1024*1024)throw new RuntimeException('Excel descomprimido supera 30 MB.');}
    if(!in_array($ext,['xls','xlsx'],true))throw new RuntimeException('Usa CSV, XLSX o XLS.');
    require_once dirname(__DIR__,2).'/cengicursos/vendor/autoload.php';$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext==='xls'?'Xls':'Xlsx');$reader->setReadDataOnly(true);$info=$reader->listWorksheetInfo($file['tmp_name']);if(($info[0]['totalRows'] ?? 0)>2001||($info[0]['totalColumns'] ?? 0)>50)throw new RuntimeException('Excel supera 2000 filas o 50 columnas.');$reader->setLoadSheetsOnly($info[0]['worksheetName']);$book=$reader->load($file['tmp_name']);$rows=$book->getSheet(0)->toArray(null,false,false,false);$book->disconnectWorksheets();$headers=array_shift($rows);$out=[];foreach($rows as $row){$r=[];foreach($headers as $i=>$h)$r[(string)$h]=$row[$i] ?? '';$out[]=$r;}return inf_poa_filas($out);
}
function inf_mutar(PDO $pdo,array $u,string $action,array $d,array $files=[]):string {
    if(!can_view_resource($u,'informes'))throw new RuntimeException('No tienes acceso a informes.');$caps=inf_caps($u);$new=[];$msg='Cambios guardados.';$pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM informe_control WHERE id=1 FOR UPDATE')->fetchColumn();
        $upload=function($key,$ext)use($files,&$new){$a=inf_upload($files[$key] ?? [],$ext);if($a)$new[]=$a;return $a;};
        if($action==='areas'){
            if(!$caps['gestionar'])throw new RuntimeException('Solo coordinación administra áreas.');$revision=(int)$pdo->query('SELECT revision FROM informe_control WHERE id=1')->fetchColumn();if((int)($d['revision'] ?? 0)!==$revision)throw new RuntimeException('Las áreas cambiaron. Recarga antes de guardar.');
            foreach((array)($d['areas'] ?? []) as $a){$old=inf_area($pdo,(int)$a['id']);$email=trim((string)($a['correo'] ?? ''));if($email&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Correo de responsable no válido.');$programa=(int)($a['programa'] ?? 0);$pdo->prepare('UPDATE informe_areas SET correo=?,activo=?,programa_id=? WHERE id=?')->execute([$email,!empty($a['activo'])?1:0,$programa>0?$programa:null,$old['id']]);}
            $name=trim((string)($d['nombre'] ?? ''));if($name){if(mb_strlen($name)>180)throw new RuntimeException('Nombre de área demasiado largo.');$q=$pdo->prepare('SELECT id FROM informe_areas WHERE nombre=?');$q->execute([$name]);if($q->fetchColumn())throw new RuntimeException('El área ya existe; puedes reactivarla.');$pdo->prepare('INSERT INTO informe_areas(nombre) VALUES(?)')->execute([$name]);}$pdo->exec('UPDATE informe_control SET revision=revision+1 WHERE id=1');
        }elseif($action==='poa'){
            if(!$caps['gestionar'])throw new RuntimeException('Solo coordinación configura el POA.');[$y]=inf_periodo(['anio'=>$d['anio'] ?? 0,'cuatri'=>1]);$q=$pdo->prepare('SELECT revision FROM informe_poa WHERE anio=?');$q->execute([$y]);if((int)$q->fetchColumn()!==(int)($d['revision'] ?? 0))throw new RuntimeException('El POA cambió. Recarga antes de sincronizar.');$rows=inf_leer_poa($files['poa'] ?? []);$url=inf_url((string)($d['url'] ?? ''));$source=$url?'Enlace publicado':('Archivo: '.basename((string)$files['poa']['name']));
            $pdo->prepare('INSERT INTO informe_poa(anio,filas,fuente,url,sincronizado) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE filas=VALUES(filas),fuente=VALUES(fuente),url=VALUES(url),sincronizado=NOW(),revision=revision+1')->execute([$y,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),mb_substr($source,0,255),$url]);
            $known=array_map('normalize_text',$pdo->query('SELECT nombre FROM informe_areas')->fetchAll(PDO::FETCH_COLUMN));$unknown=array_values(array_unique(array_filter(array_column($rows,'area'),fn($a)=>!in_array(normalize_text($a),$known,true))));$msg=count($rows).' filas sincronizadas.'.($unknown?' Áreas no reconocidas: '.implode(', ',$unknown):'');
        }else {
            [$y,$c]=inf_periodo($d);$area=inf_area($pdo,(int)($d['area_id'] ?? 0));$q=$pdo->prepare('SELECT * FROM informes_cuatrimestrales WHERE anio=? AND cuatri=? AND area_id=?');$q->execute([$y,$c,$area['id']]);$r=$q->fetch();
            if((int)($r['revision'] ?? 0)!==(int)($d['revision'] ?? 0))throw new RuntimeException('El informe cambió. Recarga antes de continuar.');$state=$r['flujo'] ?? 'pendiente';$pending=json_decode((string)($r['pendientes'] ?? ''),true) ?: [];$log=json_decode((string)($r['bitacora'] ?? ''),true) ?: [];$obs=trim((string)($d['observaciones'] ?? ''));
            if(in_array($action,['guardar','entregar','atender'],true)){
                if(!inf_entregar($u,$area))throw new RuntimeException('Solo el responsable del área o coordinación puede entregar.');if(!$r&&!$area['activo'])throw new RuntimeException('El área no está activa.');
                if($action==='atender'?$state!=='observado':!in_array($state,['pendiente','entregado'],true))throw new RuntimeException('El informe no admite cambios en esta etapa.');
                $plan=$upload('plan',['pdf','doc','docx','xls','xlsx']);$report=$upload('informe',['pdf','doc','docx']);$url=inf_url((string)($d['enlace'] ?? $r['enlace'] ?? ''));
                if($action==='entregar'&&(!($plan['ruta'] ?? $r['plan_operativo_ruta'] ?? null)||!($report['ruta'] ?? $r['informe_ruta'] ?? null)))throw new RuntimeException('Para entregar se necesitan el plan operativo y el informe.');
                if($action==='atender'){foreach($pending as &$p)if($p['estado']!=='resuelto'){$answer=trim((string)($d['respuestas'][$p['id']] ?? ''));if(!$answer)throw new RuntimeException('Responde cómo se atendió cada pendiente.');$p['respuesta']=$answer;$p['estado']='resuelto';$p['resuelto_en']=date('Y-m-d H:i:s');}unset($p);$state='atendido';}elseif($action==='entregar')$state='entregado';
                if($r&& (!$log||(count($log)===1&&($log[0]['accion'] ?? '')==='Registro anterior conservado')))$log[]=['accion'=>'Archivos anteriores conservados','fecha'=>$r['creado_en'],'actor'=>'Sistema','rol'=>'Migración','observaciones'=>$r['observaciones'],'archivos'=>inf_archivos($pdo,$r)];
                $values=[$y,$c,$area['id'],['','I','II','III'][$c].' cuatrimestre '.$y,$area['nombre'],$plan['nombre'] ?? $r['plan_operativo_nombre'] ?? null,$plan['ruta'] ?? $r['plan_operativo_ruta'] ?? null,$report['nombre'] ?? $r['informe_nombre'] ?? null,$report['ruta'] ?? $r['informe_ruta'] ?? null,$url,$state];
                if($r){$id=(int)$r['id'];$pdo->prepare('UPDATE informes_cuatrimestrales SET anio=?,cuatri=?,area_id=?,periodo=?,area=?,plan_operativo_nombre=?,plan_operativo_ruta=?,informe_nombre=?,informe_ruta=?,enlace=?,flujo=? WHERE id=?')->execute([...$values,$id]);}
                else{$pdo->prepare('INSERT INTO informes_cuatrimestrales(anio,cuatri,area_id,periodo,area,plan_operativo_nombre,plan_operativo_ruta,informe_nombre,informe_ruta,enlace,flujo,fecha) VALUES(?,?,?,?,?,?,?,?,?,?,?,CURDATE())')->execute($values);$id=(int)$pdo->lastInsertId();}
                foreach(array_map('intval',(array)($d['quitar'] ?? [])) as $sid)$pdo->prepare('DELETE FROM informe_soportes WHERE id=? AND informe_id=?')->execute([$sid,$id]);
                if(count($files)>22)throw new RuntimeException('Carga hasta 20 archivos complementarios.');foreach($files as $key=>$f)if(str_starts_with($key,'soporte_')){$a=$upload($key,['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip']);if($a)comites_informe_agregar_soporte($pdo,$id,$a['nombre'],$a['ruta']);}
                $label=$action==='guardar'?'Borrador guardado':($action==='atender'?'Pendientes atendidos':($r&&$r['flujo']==='entregado'?'Entrega actualizada':'Entregado'));
            }elseif($action==='revisar'){
                if(!$caps['revisar']||!$r||!in_array($state,['entregado','atendido'],true))throw new RuntimeException('La revisión corresponde a Dirección en informes entregados.');$id=(int)$r['id'];
                foreach((array)($d['nuevos'] ?? []) as $text){$text=trim((string)$text);if($text)$pending[]=['id'=>bin2hex(random_bytes(8)),'texto'=>$text,'estado'=>'abierto','fecha'=>date('Y-m-d H:i:s'),'respuesta'=>''];}
                $state=(string)($d['estado'] ?? '');if(!in_array($state,['observado','revisado'],true))throw new RuntimeException('Selecciona el resultado de revisión.');if($state==='observado'&&!array_filter($pending,fn($p)=>$p['estado']!=='resuelto'))throw new RuntimeException('Agrega al menos un pendiente.');$label=INF_ESTADOS[$state];
            }else throw new RuntimeException('Acción no válida.');
            $current=inf_fila($pdo,$id);$log[]=['accion'=>$label,'actor'=>$u['nombre'],'rol'=>$u['nombre_rol'] ?? 'Administrador','fecha'=>date('Y-m-d H:i:s'),'observaciones'=>$obs,'archivos'=>inf_archivos($pdo,$current)];
            $pdo->prepare('UPDATE informes_cuatrimestrales SET flujo=?,evaluacion=?,pendientes=?,bitacora=?,observaciones=?,revision=revision+1 WHERE id=?')->execute([$state,$state==='revisado'?'Satisfactorio':($state==='observado'?'Con observaciones':'En revision'),json_encode($pending,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($log,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$obs,$id]);
        }
        $pdo->commit();return $msg;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($new as $a){$p=inf_ruta($a['ruta']);if($p)unlink($p);}throw $e;}
}
function inf_descarga(PDO $pdo,array $u,array $q):array {
    if(!can_view_resource($u,'informes'))throw new RuntimeException('No tienes acceso a informes.');$r=inf_fila($pdo,(int)($q['id'] ?? 0));$key=$q['archivo'] ?? '';
    if($key==='plan')$a=['nombre'=>$r['plan_operativo_nombre'],'ruta'=>$r['plan_operativo_ruta']];elseif($key==='informe')$a=['nombre'=>$r['informe_nombre'],'ruta'=>$r['informe_ruta']];elseif($key==='soporte'){$a=null;foreach(comites_informe_soportes($pdo,(int)$r['id']) as $s)if((int)$s['id']===(int)($q['adjunto'] ?? 0))$a=$s;}elseif($key==='historial'){$logs=json_decode((string)$r['bitacora'],true) ?: [];$a=$logs[(int)($q['indice'] ?? -1)]['archivos'][(int)($q['adjunto'] ?? -1)] ?? null;}else $a=null;
    if(!$a||!inf_ruta($a['ruta']))throw new RuntimeException('El archivo no está disponible.');return $a;
}
