<?php
require_once __DIR__.'/../models/kanban_model.php';
require_once __DIR__.'/catalogo_muestras_helper.php';
require_once __DIR__.'/captura_variables_registry.php';

function wfSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_plan_lote (id_lote INT PRIMARY KEY, prioridad VARCHAR(8) NOT NULL DEFAULT 'media', version INT NOT NULL DEFAULT 0, FOREIGN KEY(id_lote) REFERENCES lote(id_lote)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_plan_asignacion (id_muestra INT NOT NULL, id_tipo_analisis INT NOT NULL, usuario_id INT NOT NULL, analista VARCHAR(255) NOT NULL, PRIMARY KEY(id_muestra,id_tipo_analisis), FOREIGN KEY(id_muestra) REFERENCES muestra(id_muestra), FOREIGN KEY(id_tipo_analisis) REFERENCES tipo_analisis(id_tipo)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_hoja_borrador (usuario_key VARCHAR(80) NOT NULL, hoja VARCHAR(180) NOT NULL, version INT NOT NULL, datos LONGTEXT NOT NULL, actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(usuario_key,hoja)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_hoja_formulario (id_formulario INT PRIMARY KEY, hoja VARCHAR(180) NOT NULL, datos LONGTEXT NOT NULL, FOREIGN KEY(id_formulario) REFERENCES formulario(id_formulario)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_workflow_auditoria (id INT AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(255) NOT NULL, accion VARCHAR(60) NOT NULL, referencia VARCHAR(180) NOT NULL, datos LONGTEXT NOT NULL, fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Caña: "Fibra bruta" se muestra como "% Fibra" (nombre del catálogo y de la hoja) en Planificación y Validación técnica.
    $pdo->exec("UPDATE tipo_analisis SET nombre='% Fibra' WHERE nombre='Fibra bruta' AND id_tipo_muestra IN (SELECT id_tipo FROM tipo_muestra WHERE UPPER(prefijo)='C')
        AND NOT EXISTS (SELECT 1 FROM (SELECT id_tipo_muestra FROM tipo_analisis WHERE nombre='% Fibra') f WHERE f.id_tipo_muestra=tipo_analisis.id_tipo_muestra)");
}
function wfConfig(): array { static $c;return $c??=json_decode(file_get_contents(__DIR__.'/../config/hojas_trabajo_v3.json'),true,512,JSON_THROW_ON_ERROR); }
function wfTipo(string $tipo): string {return ['suelos'=>'Suelo','foliares'=>'Foliar','agua'=>'Agua','cana'=>'Caña','miel'=>'Miel','mieles'=>'Miel'][labCatalogoMuestrasClaveDesdePrefijo(null,$tipo)]??$tipo;}
function wfHoja(string $tipo,string $analisis): ?array {
    $tipo=wfTipo($tipo);$norm=lab_captura_variables_normalizar($analisis);
    $aliases=['nitrogeno'=>'Nitrógeno total','capacidad de campo'=>'Capacidad de campo (CC)','punto de marchitez permanente'=>'Punto de marchitez permanente (PMP)','densidad aparente dap'=>'Densidad aparente','porcentaje de humedad'=>'% Humedad','fibra'=>'% Fibra','fibra bruta'=>'% Fibra','alcalinidad'=>'Alcalinidad total','alcanilidad'=>'Alcalinidad total','cic (capacidad de intercambio cationico)'=>'Capacidad de intercambio catiónico (CIC)','ras'=>'Razón de adsorción de sodio (RAS)','tds'=>'Sólidos totales disueltos'];
    foreach(wfConfig()['catalogo'] as $c)if($c['tipoMuestra']===$tipo && lab_captura_variables_normalizar($c['analisis'])===$norm){$key=$tipo.'|'.$c['analisis'];return ['key'=>wfConfig()['alias'][$key]??$key,'analisis'=>$c['analisis']];}
    if(isset($aliases[$norm]) && !($tipo==='Foliar'&&$norm==='nitrogeno'))return wfHoja($tipo,$aliases[$norm]);
    $legacy=lab_captura_variables_resolver($tipo,$analisis);
    if($legacy) foreach(wfConfig()['catalogo'] as $c)if($c['tipoMuestra']===$tipo){$match=lab_captura_variables_resolver($tipo,$c['analisis']);if($match && $match['key']===$legacy['key'])return ['key'=>wfConfig()['alias'][$tipo.'|'.$c['analisis']]??$tipo.'|'.$c['analisis'],'analisis'=>$c['analisis']];}
    return null;
}
function wfUsuarioKey(array $user): string {return !empty($user['id'])?'id:'.(int)$user['id']:'nombre:'.hash('sha256',(string)($user['nombre']??''));}
function wfUsuarios(): array {
    static $users;if($users!==null)return $users;
    require_once __DIR__.'/../config/env.php';
    $env=lab_env_central();
    $db=new PDO('mysql:host='.$env['DB_HOST'].';port='.($env['DB_PORT']??3306).';dbname='.$env['DB_NAME'].';charset=utf8mb4',$env['DB_USER'],$env['DB_PASS']??$env['DB_PASSWORD']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    return $users=$db->query("SELECT DISTINCT u.id,u.nombre FROM usuarios u JOIN usuario_modulo um ON um.usuario_id=u.id JOIN modulos m ON m.id=um.modulo_id WHERE LOWER(m.nombre)='laboratorio' ORDER BY u.nombre")->fetchAll(PDO::FETCH_ASSOC);
}
function wfAudit(PDO $pdo,array $user,string $action,string $ref,array $data):void {$pdo->prepare('INSERT INTO lab_workflow_auditoria(usuario,accion,referencia,datos) VALUES(?,?,?,?)')->execute([(string)($user['nombre']??'Usuario'),$action,$ref,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
function wfTareas(PDO $pdo):array {
    $tasks=lab_planificacion_tareas($pdo);$plans=$pdo->query('SELECT * FROM lab_plan_lote')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $started=[];foreach($pdo->query('SELECT usuario_key,hoja,datos FROM lab_hoja_borrador') as $saved){$cap=json_decode($saved['datos'],true);foreach($cap['seleccion']??[] as $id)if(array_filter($cap['filas'][$id]??[],fn($v)=>is_scalar($v)&&trim((string)$v)!==''))$started[$id.':'.$saved['hoja']][]=$saved['usuario_key'];}
    $assign=[];foreach($pdo->query('SELECT * FROM lab_plan_asignacion') as $a)$assign[$a['id_muestra'].':'.$a['id_tipo_analisis']]=$a;
    foreach($tasks as &$t){
        $a=$assign[$t['id_muestra'].':'.$t['id_tipo_analisis']]??null;$plan=$plans[$t['id_lote']]??[];
        $t['plan_version']=(int)($plan['version']??0);$priority=$plan['prioridad']??'media';$t['prioridad']=['key'=>$priority,'label'=>ucfirst($priority),'rank'=>['baja'=>1,'media'=>2,'alta'=>3][$priority]];
        $t['usuario_id']=$a?(int)$a['usuario_id']:null;
        if($a && in_array($t['estado']['key'],['pendiente','proceso','rechazado','repetido'],true))$t['analista']=$a['analista'];
        $h=wfHoja($t['tipo_muestra'],$t['analisis_nombre']);$t['hoja']=$h['key']??null;$t['analisis_hoja']=$h['analisis']??$t['analisis_nombre'];
        if($t['estado']['key']==='pendiente' && !empty($started[$t['id_muestra'].':'.$t['hoja']]))$t['estado']=['key'=>'proceso','label'=>'En análisis','icon'=>'◐'];
    }unset($t);
    return $tasks;
}
function wfPendiente(array $t):bool {return in_array($t['estado']['key'],['pendiente','proceso','rechazado','repetido'],true);}
function wfPuedeCapturar(array $t,array $user,bool $manager):bool {
    $legacy=lab_captura_variables_resolver($t['tipo_muestra'],$t['analisis_nombre']);
    if(function_exists('lab_can_analysis') && !lab_can_analysis($legacy['key'] ?? ('workflow.'.($t['hoja'] ?? $t['id_tipo_analisis']))))return false;
    return $manager || (!empty($user['id']) && (int)$t['usuario_id']===(int)$user['id']) || (!$t['usuario_id'] && !empty($user['nombre']) && $t['analista']===$user['nombre']);}
function wfPlanGuardar(PDO $pdo,array $data,array $user):array {
    $id=(int)($data['id_lote']??0);$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id_lote FROM lote WHERE id_lote=? FOR UPDATE');$lock->execute([$id]);if(!$lock->fetchColumn())throw new InvalidArgumentException('El lote no existe.');
        $tasks=array_values(array_filter(wfTareas($pdo),fn($t)=>(int)$t['id_lote']===$id));
        if(!$tasks)throw new InvalidArgumentException('El lote no tiene análisis solicitados.');
        $version=$tasks[0]['plan_version'];if((int)($data['version']??-1)!==$version)throw new RuntimeException('El lote cambió. Recargue antes de asignar.',409);
        $priority=$data['prioridad']??$tasks[0]['prioridad']['key'];if(!in_array($priority,['alta','media','baja'],true))throw new InvalidArgumentException('Prioridad inválida.');
        if(isset($data['usuario_id'])){
            $users=array_column(wfUsuarios(),null,'id');$uid=(int)$data['usuario_id'];if(!isset($users[$uid]))throw new InvalidArgumentException('Seleccione un usuario del laboratorio.');
            $analysis=(int)($data['id_tipo_analisis']??0);$count=0;
            $update=$pdo->prepare('INSERT INTO lab_plan_asignacion VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE usuario_id=VALUES(usuario_id),analista=VALUES(analista)');
            foreach($tasks as $t)if(wfPendiente($t)&&(!$analysis||$analysis===(int)$t['id_tipo_analisis'])){$update->execute([$t['id_muestra'],$t['id_tipo_analisis'],$uid,$users[$uid]['nombre']]);$count++;}
            if(!$count)throw new InvalidArgumentException('No hay tareas pendientes para esta asignación.');
        }
        $pdo->prepare('INSERT INTO lab_plan_lote VALUES(?,?,?) ON DUPLICATE KEY UPDATE prioridad=VALUES(prioridad),version=VALUES(version)')->execute([$id,$priority,$version+1]);
        wfAudit($pdo,$user,'Planificación',(string)$id,$data);if($own)$pdo->commit();return ['version'=>$version+1];
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function wfBorrador(PDO $pdo,string $hoja,array $user):array {
    $q=$pdo->prepare('SELECT version,datos FROM lab_hoja_borrador WHERE usuario_key=? AND hoja=?');$q->execute([wfUsuarioKey($user),$hoja]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return ['version'=>(int)($r['version']??0),'datos'=>$r?json_decode($r['datos'],true,512,JSON_THROW_ON_ERROR):null];
}
function wfTexto($value,int $max=1000):string {if(!is_scalar($value)&&$value!==null)throw new InvalidArgumentException('Valor de campo inválido.');$v=trim((string)$value);if(mb_strlen($v)>$max)throw new InvalidArgumentException('Un campo excede su longitud máxima.');return $v;}
function wfCelda($value,array $col):string {
    $v=wfTexto($value,250);if($v==='')return '';
    if(($col['tipo']??'')==='sel'){if(!in_array($v,$col['opts'],true))throw new InvalidArgumentException('Opción inválida: '.$col['label']);}
    elseif(($col['tipo']??'')!=='txt'&&!preg_match('/^n\s*\/?\s*d$/i',$v)){
        $v=str_replace(',','.',$v);if(!is_numeric($v)||!is_finite((float)$v))throw new InvalidArgumentException('Ingrese un número válido en '.$col['label']);
    }return $v;
}
function wfNormalizar(array $raw,array $h,array $pool,bool $send):array {
    $cap=['encabezado'=>[],'controles'=>[],'ctrlExtra'=>[],'filas'=>[],'seleccion'=>[],'curva'=>[]];
    foreach(['fecha','analista','equipo','obs'] as $k)$cap[$k]=wfTexto($raw[$k]??'', $k==='obs'?2000:255);
    if($send&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$cap['fecha'])||date('Y-m-d',strtotime($cap['fecha']))!==$cap['fecha']||$cap['analista']===''))throw new InvalidArgumentException('Complete fecha de análisis y analista.');
    foreach($h['encabezado']['campos']??[] as $c)$cap['encabezado'][$c['k']]=wfCelda($raw['encabezado'][$c['k']]??'', $c);
    $ctrls=$h['controles'];
    if(count($raw['ctrlExtra']??[])>20)throw new InvalidArgumentException('Demasiados controles.');
    foreach($raw['ctrlExtra']??[] as $i=>$extra){$id='CONTROL_EXTRA_'.($i+1);$c=['id'=>$id,'label'=>'Control adicional '.($i+1)];$ctrls[]=$c;$cap['ctrlExtra'][]=$c;}
    $pedidos=[];foreach((array)($raw['seleccion']??[]) as $id)foreach($pool[(int)$id]??[] as $t)$pedidos[$t['analisis_hoja']]=true;
    foreach($ctrls as $ctrl){$cap['controles'][$ctrl['id']]=[];foreach($h['cols'] as $c){if(!empty($c['auto'])||in_array($c['k'],$ctrl['sin']??[],true))continue;
        $v=wfCelda($raw['controles'][$ctrl['id']][$c['k']]??'',$c);if($send&&$ctrl['id']==='BLANCO'&&empty($c['opc'])&&(empty($c['analisis'])||isset($pedidos[$c['analisis']]))&&$v==='')throw new InvalidArgumentException('Complete el blanco: '.$c['label']);$cap['controles'][$ctrl['id']][$c['k']]=$v;
    }}
    if(!is_array($raw['seleccion']??null)||count($raw['seleccion'])>2000)throw new InvalidArgumentException('Selección inválida.');
    foreach($raw['seleccion'] as $id){$id=(int)$id;if(!isset($pool[$id])||isset($cap['filas'][$id]))throw new InvalidArgumentException('Hay muestras repetidas, ya enviadas o ajenas a esta hoja.');
        $cap['seleccion'][]=$id;$cap['filas'][$id]=[];
        foreach($h['cols'] as $c){if(!empty($c['auto']))continue;$applies=empty($c['analisis'])||in_array($c['analisis'],array_column($pool[$id],'analisis_hoja'),true);if(!$applies)continue;
            $v=wfCelda($raw['filas'][$id][$c['k']]??'',$c);if($send&&empty($c['opc'])&&$v==='')throw new InvalidArgumentException('Complete '.$pool[$id][0]['codigo_muestra'].': '.$c['label']);$cap['filas'][$id][$c['k']]=$v;
        }
    }
    if($send&&!$cap['seleccion'])throw new InvalidArgumentException('Seleccione al menos una muestra.');
    if(!empty($h['curva'])){
        if(count($raw['curva']??[])>100)throw new InvalidArgumentException('Demasiados puntos de curva.');
        $points=0;$sxx=0;
        foreach($raw['curva']??[] as $point){$c=wfCelda($point['c']??'',['label'=>'Concentración']);$a=wfCelda($point['a']??'',['label'=>'Absorbancia']);$cap['curva'][]=['c'=>$c,'a'=>$a];if(is_numeric($c)&&is_numeric($a)){$points++;$sxx+=(float)$c*(float)$c;}}
        if($send&&!((float)($cap['encabezado']['pend']??0))&&($points<2||!$sxx))throw new InvalidArgumentException('Complete al menos dos puntos válidos de la curva o su pendiente.');
    }
    return $cap;
}
function wfGuardarHoja(PDO $pdo,array $data,array $user,bool $manager):array {
    $key=wfTexto($data['hoja']??'',180);$h=wfConfig()['hojas'][$key]??null;if(!$h)throw new InvalidArgumentException('Hoja no configurada.');
    $send=($data['accion']??'')==='enviar';$raw=$data['datos']??null;if(!is_array($raw))throw new InvalidArgumentException('Datos inválidos.');
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        // Mismo orden de bloqueos que la planificación; serializa asignaciones y envíos por lote.
        $ids=array_map('intval',$raw['seleccion']??[]);sort($ids);
        if($ids){$q=$pdo->prepare('SELECT l.id_lote FROM lote l WHERE l.id_lote IN (SELECT s.id_lote FROM solicitud s JOIN muestra m ON m.id_solicitud=s.id_solicitud WHERE m.id_muestra IN ('.implode(',',array_fill(0,count($ids),'?')).')) ORDER BY l.id_lote FOR UPDATE');$q->execute($ids);$q->fetchAll();}
        $pdo->prepare('INSERT IGNORE INTO lab_hoja_borrador(usuario_key,hoja,version,datos) VALUES(?,?,0,\'null\')')->execute([wfUsuarioKey($user),$key]);
        $lock=$pdo->prepare('SELECT version FROM lab_hoja_borrador WHERE usuario_key=? AND hoja=? FOR UPDATE');$lock->execute([wfUsuarioKey($user),$key]);$version=(int)$lock->fetchColumn();
        if((int)($data['version']??-1)!==$version)throw new RuntimeException('La hoja cambió en otra pestaña. Recargue antes de guardar.',409);
        $pool=[];foreach(wfTareas($pdo) as $t)if($t['hoja']===$key&&wfPendiente($t)&&wfPuedeCapturar($t,$user,$manager))$pool[(int)$t['id_muestra']][]=$t;
        $cap=wfNormalizar($raw,$h,$pool,$send);
        // La identidad del analista proviene de la sesión, nunca del contenido enviado.
        $cap['analista']=(string)($user['nombre']??$user['correo']??'Usuario');$cap['hoja']=$key;
        $forms=[];
        if($send){
            $groups=[];foreach($cap['seleccion'] as $id)foreach($pool[$id] as $t)$groups[$t['id_solicitud'].':'.$t['id_tipo_analisis']][]=$t;
            $state=$pdo->query("SELECT id_estado FROM estado_formulario WHERE LOWER(nombre) IN ('revisar','en revision') ORDER BY id_estado LIMIT 1")->fetchColumn();if(!$state){$pdo->prepare('INSERT INTO estado_formulario(nombre) VALUES(?)')->execute(['Revisar']);$state=(int)$pdo->lastInsertId();}
            foreach($groups as $tasks){
                usort($tasks,fn($a,$b)=>(int)$a['numero_muestra']<=>(int)$b['numero_muestra']);$segments=[];
                foreach($tasks as $t){$last=count($segments)-1;if($last<0||(int)$t['numero_muestra']!==(int)end($segments[$last])['numero_muestra']+1)$segments[]=[$t];else $segments[$last][]=$t;}
                foreach($segments as $segment){$first=$segment[0];$end=end($segment);
                    $q=$pdo->prepare('SELECT id_rango FROM lote_rango WHERE id_lote=? AND inicio=? AND fin=? LIMIT 1');$q->execute([$first['id_lote'],$first['numero_muestra'],$end['numero_muestra']]);$range=$q->fetchColumn();
                    if(!$range){$pdo->prepare('INSERT INTO lote_rango(id_lote,inicio,fin) VALUES(?,?,?)')->execute([$first['id_lote'],$first['numero_muestra'],$end['numero_muestra']]);$range=$pdo->lastInsertId();}
                    $pdo->prepare('INSERT INTO formulario(id_estado,id_rango,id_tipo_analisis,fecha,analista) VALUES(?,?,?,?,?)')->execute([$state,$range,$first['id_tipo_analisis'],$cap['fecha'],$cap['analista']]);$fid=(int)$pdo->lastInsertId();$forms[]=$fid;
                    $snapshot=$cap;$snapshot['seleccion']=array_map('intval',array_column($segment,'id_muestra'));$snapshot['filas']=array_intersect_key($cap['filas'],array_flip($snapshot['seleccion']));$snapshot['muestras']=$segment;$snapshot['analisis']=$first['analisis_hoja'];
                    $pdo->prepare('INSERT INTO lab_hoja_formulario VALUES(?,?,?)')->execute([$fid,$key,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                    $pdo->prepare("INSERT INTO historial_formulario(id_formulario,accion,estado_anterior,estado_nuevo,usuario,fecha,comentario) VALUES(?,'Hoja enviada',NULL,'Revisar',?,NOW(),?)")->execute([$fid,$cap['analista'],'Captura con formato del prototipo: '.$key]);
                }
            }
        }
        $stored=$send?null:$cap;
        $pdo->prepare('UPDATE lab_hoja_borrador SET version=?,datos=? WHERE usuario_key=? AND hoja=?')->execute([$version+1,json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),wfUsuarioKey($user),$key]);
        wfAudit($pdo,$user,$send?'Enviar hoja':'Guardar avance',$key,['muestras'=>$cap['seleccion'],'formularios'=>$forms,'version'=>$version+1]);
        if($own)$pdo->commit();return ['version'=>$version+1,'datos'=>$stored,'formularios'=>$forms];
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function wfVinculos(PDO $pdo,array $tasks):array {
    $ids=array_fill_keys(array_map('intval',array_column($tasks,'id_muestra')),true);$result=[];
    $q=$pdo->query("SELECT h.hoja,h.datos FROM lab_hoja_formulario h JOIN formulario f ON f.id_formulario=h.id_formulario LEFT JOIN estado_formulario e ON e.id_estado=f.id_estado WHERE LOWER(COALESCE(e.nombre,'')) NOT LIKE '%rechaz%' ORDER BY h.id_formulario DESC");
    foreach($q as $row){$cap=json_decode($row['datos'],true);foreach($cap['filas']??[] as $id=>$fields)if(isset($ids[$id])&&!isset($result[$row['hoja']][$id]))$result[$row['hoja']][$id]=['campos'=>$fields,'encabezado'=>$cap['encabezado'],'controles'=>$cap['controles'],'curva'=>$cap['curva'],'origen'=>'Hoja enviada'];}
    foreach($pdo->query('SELECT hoja,datos FROM lab_hoja_borrador ORDER BY actualizado_en DESC') as $row){$cap=json_decode($row['datos'],true);foreach($cap['seleccion']??[] as $id)if(isset($ids[$id])&&!isset($result[$row['hoja']][$id]))$result[$row['hoja']][$id]=['campos'=>$cap['filas'][$id]??[],'encabezado'=>$cap['encabezado'],'controles'=>$cap['controles'],'curva'=>$cap['curva'],'origen'=>'Avance guardado'];}
    return $result;
}

/** Datos extra de la bandeja (formato del prototipo): borradores del usuario para el avance
 *  de cada cola y muestras enviadas hoy a validación técnica, por analista. */
function wfResumenBandeja(PDO $pdo,array $user):array {
    $drafts=[];
    $q=$pdo->prepare('SELECT hoja,datos FROM lab_hoja_borrador WHERE usuario_key=?');$q->execute([wfUsuarioKey($user)]);
    foreach($q as $r){$cap=json_decode($r['datos'],true);if(is_array($cap)&&!empty($cap['seleccion']))$drafts[$r['hoja']]=['seleccion'=>array_map('intval',$cap['seleccion']),'filas'=>(object)($cap['filas']??[])];}
    $today=[];
    $q=$pdo->query("SELECT hf.usuario,h.datos FROM historial_formulario hf JOIN lab_hoja_formulario h ON h.id_formulario=hf.id_formulario WHERE hf.accion='Hoja enviada' AND hf.fecha>=CURDATE()");
    foreach($q as $r){$cap=json_decode($r['datos'],true);foreach($cap['seleccion']??[] as $id)$today[(string)$r['usuario']][(int)$id]=true;}
    return ['borradores'=>(object)$drafts,'capturadas_hoy'=>(object)array_map('count',$today)];
}

/** Estado de Identificación por solicitud para Planificación (prototipo): versión guardada,
 *  muestras sin identificar y un resumen corto de la etiqueta de cada muestra. */
function wfIdentificacionResumen(PDO $pdo):array {
    require_once __DIR__.'/identificacion_lote_helper.php';
    $out=[];
    foreach(labIdentificacionLotes($pdo) as $lote){
        $etiquetas=[];$pend=0;
        foreach($lote['muestras'] as $m){
            if($m['estado']==='pendiente'){$pend++;continue;}
            $e=$m['etiqueta'];$txt=trim((string)($e['info']??''));
            if($txt==='')$txt=implode(' · ',array_filter(array_map('strval',array_diff_key($e['campos']??[],['obs'=>1])),fn($v)=>trim($v)!==''));
            $etiquetas[$m['id_muestra']]=mb_substr(preg_replace('/\s+/',' ',$txt),0,60);
        }
        $out[$lote['id_solicitud']]=['version'=>$lote['version'],'pendientes'=>$pend,'total'=>count($lote['muestras']),'etiquetas'=>(object)$etiquetas];
    }
    return $out;
}

/** Seguimiento (Planificación): fechas e hitos por lote — recepción, informe firmado y entrega por correo.
 *  Un lote con informe firmado pasa a "Finalizados" para no saturar la vista de trabajo en curso. */
function wfSeguimientoLotes(PDO $pdo):array {
    $out=[];
    foreach($pdo->query("SELECT id_lote, MIN(fecha_ingreso) AS recepcion FROM solicitud GROUP BY id_lote") as $r)
        $out[(int)$r['id_lote']]=['recepcion'=>$r['recepcion'],'informe'=>null,'enviado'=>null];
    try{
        foreach($pdo->query("SELECT id_lote,codigo,estado,version,firmado_por,fecha_firma FROM lab_informe") as $r)
            $out[(int)$r['id_lote']]['informe']=['codigo'=>$r['codigo'],'estado'=>$r['estado'],'version'=>(int)$r['version'],'firmado_por'=>$r['firmado_por'],'fecha_firma'=>$r['fecha_firma']];
    }catch(Throwable $e){}
    try{
        foreach($pdo->query("SELECT d.id_lote, MAX(h.fecha) AS enviado FROM lab_documento_historial h JOIN lab_documento d ON d.id_documento=h.id_documento
            WHERE d.tipo_documento='informe' AND h.cambios LIKE 'Enviado por correo%' GROUP BY d.id_lote") as $r)
            $out[(int)$r['id_lote']]['enviado']=$r['enviado'];
    }catch(Throwable $e){}
    return $out;
}

/** Línea de tiempo de un lote: cada evento con fecha, etapa, descripción y usuario (de las bitácoras existentes). */
function wfSeguimientoEventos(PDO $pdo,int $idLote):array {
    $ev=[];
    $add=static function(string $etapa,$fecha,string $texto,$usuario='',string $detalle='')use(&$ev){if($fecha)$ev[]=['etapa'=>$etapa,'fecha'=>(string)$fecha,'texto'=>$texto,'usuario'=>(string)($usuario??''),'detalle'=>$detalle];};
    $q=$pdo->prepare('SELECT id_solicitud,fecha_ingreso,recibido_por,numero_muestras FROM solicitud WHERE id_lote=?');$q->execute([$idLote]);
    $solicitudes=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($solicitudes as $s)$add('recepcion',$s['fecha_ingreso'],'Recepción registrada · solicitud #'.$s['id_solicitud'].' · '.(int)$s['numero_muestras'].' muestras',$s['recibido_por']);
    $ids=array_map('intval',array_column($solicitudes,'id_solicitud'));
    if($ids){
        $in=implode(',',$ids);
        try{foreach($pdo->query("SELECT fecha_cambio,usuario_nombre,resumen,motivo FROM solicitud_historial_cambios WHERE id_solicitud IN ($in)") as $r)
            $add('recepcion',$r['fecha_cambio'],'Recepción editada · '.$r['resumen'],$r['usuario_nombre'],(string)($r['motivo']??''));}catch(Throwable $e){}
        try{foreach($pdo->query("SELECT version,metodo,motivo,usuario,cambios,creado_en FROM lab_identificacion_historial WHERE id_solicitud IN ($in)") as $r)
            $add('identificacion',$r['creado_en'],'Identificación v'.$r['version'].' ('.(['qr'=>'Código QR','barras'=>'Código de barras','tabular'=>'Ingreso manual'][$r['metodo']]??$r['metodo']).') · '.(int)$r['cambios'].' muestra(s)',$r['usuario'],(int)$r['version']>1?(string)$r['motivo']:'');}catch(Throwable $e){}
    }
    try{
        $q=$pdo->prepare("SELECT hf.accion,hf.estado_nuevo,hf.usuario,hf.fecha,hf.comentario,ta.nombre AS analisis,lr.inicio,lr.fin
            FROM historial_formulario hf JOIN formulario f ON f.id_formulario=hf.id_formulario JOIN lote_rango lr ON lr.id_rango=f.id_rango
            LEFT JOIN tipo_analisis ta ON ta.id_tipo=f.id_tipo_analisis WHERE lr.id_lote=? ORDER BY hf.fecha DESC LIMIT 400");
        $q->execute([$idLote]);
        foreach($q as $r){$acc=(string)$r['accion'];$estado=mb_strtolower((string)$r['estado_nuevo']);
            $etapa=$acc==='Hoja enviada'?'analisis':'validacion';
            $texto=($r['analisis']?:'Análisis').' · '.($acc==='Hoja enviada'?'enviado a validación técnica':($acc?:'actualizado')).($r['estado_nuevo']&&$acc!=='Hoja enviada'?' → '.$r['estado_nuevo']:'').' · muestras '.$r['inicio'].($r['fin']!=$r['inicio']?'–'.$r['fin']:'');
            $add($etapa,$r['fecha'],$texto,$r['usuario'],str_contains($estado,'rechaz')?(string)$r['comentario']:'');}
    }catch(Throwable $e){}
    try{$q=$pdo->prepare('SELECT accion,detalle,usuario,fecha FROM lab_informe_historial WHERE id_lote=?');$q->execute([$idLote]);
        foreach($q as $r)$add('informe',$r['fecha'],'Informe · '.$r['accion'],$r['usuario'],(string)($r['detalle']??''));}catch(Throwable $e){}
    try{$q=$pdo->prepare("SELECT h.cambios,h.usuario,h.fecha FROM lab_documento_historial h JOIN lab_documento d ON d.id_documento=h.id_documento WHERE d.id_lote=? AND d.tipo_documento='informe'");$q->execute([$idLote]);
        foreach($q as $r){$envio=str_starts_with((string)$r['cambios'],'Enviado por correo');$add($envio?'entrega':'informe',$r['fecha'],$envio?'Informe enviado por correo':'Informe corregido',$r['usuario'],$envio?substr((string)$r['cambios'],strlen('Enviado por correo a: ')):(string)$r['cambios']);}}catch(Throwable $e){}
    usort($ev,fn($a,$b)=>strcmp($b['fecha'],$a['fecha']));
    return $ev;
}
