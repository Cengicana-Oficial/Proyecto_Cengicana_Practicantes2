<?php
declare(strict_types=1);
const AGENDA_MESES=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const AGENDA_FIJOS=[['nombre'=>'Análisis del primer tercio de zafra','mes'=>1],['nombre'=>'Análisis del segundo tercio de zafra','mes'=>3],['nombre'=>'Presentación de resultados de investigación','mes'=>7],['nombre'=>'Simposio de análisis de la zafra','mes'=>8]];
const AGENDA_ESTADOS=['planificada'=>'Planificada','reprogramada'=>'Reprogramada','realizada'=>'Realizada','sin_registrar'=>'Sin registrar','cancelada'=>'Cancelada'];

function agenda_preparar(PDO $pdo): void
{
    if (!defined('ESQUEMA_MIGRANDO')) return; // DDL solo al desplegar (deploy/mysql/migrar-esquema.php, AUD-2026-001/I-2).
    directorio_preparar($pdo);
    $antes=$pdo->query('SHOW COLUMNS FROM reuniones')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['plan_mes'=>'CHAR(7) NULL','plan_fecha'=>'DATE NULL','plan_temas'=>'TEXT NULL','cancelada'=>'TINYINT(1) NOT NULL DEFAULT 0','motivo_cancelacion'=>'TEXT NULL','reprogramaciones'=>'TEXT NULL'] as $col=>$tipo) app_ensure_column($pdo,'reuniones',$col,"{$col} {$tipo}");
    // Las pendientes antiguas conservan su fecha como planificación. Las realizadas no reciben un plan inventado.
    if (!in_array('plan_mes',$antes,true)) $pdo->exec("UPDATE reuniones SET plan_mes=DATE_FORMAT(fecha,'%Y-%m'),plan_fecha=fecha,plan_temas=temas WHERE realizada=0 AND fecha IS NOT NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS eventos_transferencia (
      id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(220) NOT NULL, fijo TINYINT(1) NOT NULL DEFAULT 0, fijo_clave VARCHAR(20) NULL UNIQUE,
      sobre_la_marcha TINYINT(1) NOT NULL DEFAULT 0, plan_mes CHAR(7) NOT NULL, plan_fecha DATE NULL, fecha DATE NULL,
      lugar VARCHAR(255) NULL, modalidad VARCHAR(40) NOT NULL DEFAULT 'Presencial', publico TEXT NULL, objetivo TEXT NULL,
      responsable VARCHAR(180) NOT NULL DEFAULT 'Jefe de Transferencia', realizada TINYINT(1) NOT NULL DEFAULT 0,
      cancelada TINYINT(1) NOT NULL DEFAULT 0, motivo_cancelacion TEXT NULL, reprogramaciones TEXT NULL,
      participantes INT NOT NULL DEFAULT 0, ingenios INT NOT NULL DEFAULT 0, informe_nombre VARCHAR(255) NULL, informe_ruta VARCHAR(500) NULL,
      notas TEXT NULL, sync_url TEXT NULL, fuente_asistentes VARCHAR(255) NULL, sincronizado DATETIME NULL,
      creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP, actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS evento_asistentes (
      id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT NOT NULL, contacto_id INT NULL, nombre VARCHAR(180) NOT NULL,
      email VARCHAR(180) NULL, telefono VARCHAR(40) NULL, empresa VARCHAR(180) NULL, cargo VARCHAR(180) NULL,
      estado VARCHAR(20) NOT NULL DEFAULT 'nuevo', clave VARCHAR(64) NOT NULL, UNIQUE KEY evento_persona(evento_id,clave),
      FOREIGN KEY(evento_id) REFERENCES eventos_transferencia(id) ON DELETE CASCADE,
      FOREIGN KEY(contacto_id) REFERENCES contactos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS evento_encuestas (
      id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT NOT NULL, calificacion TINYINT NOT NULL, comentario TEXT NULL,
      FOREIGN KEY(evento_id) REFERENCES eventos_transferencia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agenda_fecha(string $fecha,bool $opcional=true): ?string
{
    if ($fecha==='' && $opcional) return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$fecha,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new RuntimeException('Fecha no válida. Usa AAAA-MM-DD.');
    return $fecha;
}
function agenda_mes(string $mes): string
{
    if (!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/',$mes)) throw new RuntimeException('Mes no válido.');
    return $mes;
}
function agenda_plan(array $d): array
{
    $mes=agenda_mes((string)($d['plan_mes'] ?? ''));
    $fecha=agenda_fecha((string)($d['plan_fecha'] ?? ''));
    if ($fecha && substr($fecha,0,7)!==$mes) throw new RuntimeException('La fecha tentativa debe estar dentro del mes elegido.');
    return [$mes,$fecha];
}
function agenda_estado(array $r,?string $hoy=null): string
{
    if ((int)$r['cancelada']) return 'cancelada';
    if ((int)$r['realizada']) return 'realizada';
    $hoy ??= date('Y-m-d');
    $limite=$r['plan_fecha'] ?: (!empty($r['plan_mes'])?date('Y-m-t',strtotime($r['plan_mes'].'-01')):($r['fecha'] ?? null));
    if ($limite && $limite<$hoy) return 'sin_registrar';
    return !empty(json_decode((string)($r['reprogramaciones'] ?? ''),true))?'reprogramada':'planificada';
}
function agenda_tabla(string $tipo): string { return $tipo==='eventos'?'eventos_transferencia':'reuniones'; }
function agenda_fila(PDO $pdo,string $tipo,int $id): array
{
    $q=$pdo->prepare('SELECT * FROM '.agenda_tabla($tipo).' WHERE id=?');$q->execute([$id]);
    $r=$q->fetch(); if (!$r) throw new RuntimeException('El registro ya no existe.'); return $r;
}
function agenda_puede(PDO $pdo,array $user,string $tipo,?string $comite=null): bool
{
    if (can_manage_resource($user,$tipo==='eventos'?'eventos':'reuniones')) return true;
    if ($tipo==='eventos' || !$comite || !can_view_resource($user,'reuniones') || empty($user['correo'])) return false;
    $q=$pdo->prepare("SELECT COUNT(*) FROM comites c JOIN contactos ct ON ct.id=c.lider_id WHERE c.id=? AND LOWER(ct.email)=LOWER(?) AND ct.estado='Activo'");$q->execute([$comite,$user['correo']]); return (bool)$q->fetchColumn();
}
function agenda_datos(PDO $pdo,array $user,string $tipo): array
{
    $rows=$pdo->query('SELECT * FROM '.agenda_tabla($tipo).' ORDER BY COALESCE(plan_mes,LEFT(fecha,7)),id')->fetchAll();
    $comites=$pdo->query('SELECT id,nombre,activo,logo FROM comites ORDER BY nombre')->fetchAll();
    foreach ($comites as &$c) $c['gestionar']=agenda_puede($pdo,$user,'reuniones',$c['id']); unset($c);
    $asistentes=[];$extras=[];
    $consulta=$tipo==='eventos'?'SELECT * FROM evento_asistentes ORDER BY nombre':'SELECT * FROM reunion_asistentes';
    foreach ($pdo->query($consulta) as $a) $asistentes[$a[$tipo==='eventos'?'evento_id':'reunion_id']][]=$tipo==='eventos'?$a:(int)$a['contacto_id'];
    foreach ($pdo->query($tipo==='eventos'?'SELECT * FROM evento_encuestas ORDER BY id':'SELECT * FROM acuerdos WHERE reunion_id IS NOT NULL ORDER BY id') as $a) $extras[$a[$tipo==='eventos'?'evento_id':'reunion_id']][]=$a;
    $contactos=$pdo->query('SELECT id,nombre,email,cargo,empresa,telefono,estado FROM contactos ORDER BY nombre')->fetchAll();
    $porEmail=[]; foreach ($contactos as $c) if ($c['email']) $porEmail[strtolower(trim($c['email']))]=(int)$c['id'];
    foreach ($rows as &$r) {
        $r['id']=(int)$r['id'];$r['estado']=agenda_estado($r);$r['historial']=json_decode((string)$r['reprogramaciones'],true) ?: [];
        $r['asistentes']=$asistentes[$r['id']] ?? []; $r[$tipo==='eventos'?'encuesta':'acuerdos']=$extras[$r['id']] ?? [];
        if ($tipo==='eventos') foreach ($r['asistentes'] as &$a) {
            $a['id']=(int)$a['id'];
            if ($a['estado']==='nuevo' && isset($porEmail[strtolower(trim((string)$a['email']))])) { $a['estado']='existente';$a['contacto_id']=$porEmail[strtolower(trim((string)$a['email']))]; }
        } unset($a);
        $r['gestionar']=agenda_puede($pdo,$user,$tipo,$r['comite_id'] ?? null);
    } unset($r);
    return ['tipo'=>$tipo,'registros'=>$rows,'comites'=>$comites,'contactos'=>$contactos,'estados'=>AGENDA_ESTADOS,'meses'=>AGENDA_MESES,'fijos'=>AGENDA_FIJOS,'hoy'=>date('Y-m-d'),'gestionar'=>agenda_puede($pdo,$user,$tipo),'gestionar_contactos'=>can_manage_resource($user,'contactos'),'enviar'=>can_manage_resource($user,'correos'),'csrf'=>$_SESSION['agenda_csrf']];
}

function agenda_archivo(array $file,string $carpeta): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return ['nombre'=>null,'ruta'=>null];
    if (($file['size'] ?? 0)>20*1024*1024) throw new RuntimeException('El documento supera 20 MB.');
    $nombreOriginal=(string)$file['name'];
    $file['name']=bin2hex(random_bytes(8)).'_'.mb_substr($nombreOriginal,-150);
    $resultado=comites_guardar_archivo($file,$carpeta,['pdf','doc','docx','xls','xlsx']);
    if ($resultado['error']) throw new RuntimeException($resultado['error']);
    return ['nombre'=>mb_substr($nombreOriginal,0,255),'ruta'=>$resultado['ruta']];
}
/** Lectura compartida para planificación, asistentes y encuestas, sin evaluar fórmulas. */
function agenda_leer(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>5*1024*1024) throw new RuntimeException('Selecciona un archivo CSV o Excel de hasta 5 MB.');
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$rows=[];
    if ($ext==='csv') {
        $f=fopen($file['tmp_name'],'r');$first=(string)fgets($f);rewind($f);$delimiter=substr_count($first,';')>substr_count($first,',')?';':',';
        while (($row=fgetcsv($f,0,$delimiter))!==false) { $rows[]=$row;if(count($rows)>2001){fclose($f);throw new RuntimeException('Máximo 2000 filas.');} } fclose($f);
    } elseif(in_array($ext,['xls','xlsx'],true)) {
        directorio_dependencias();
        if ($ext==='xlsx') { $z=new ZipArchive();if($z->open($file['tmp_name'])!==true)throw new RuntimeException('Excel no válido.');$total=0;for($i=0;$i<$z->numFiles;$i++)$total+=$z->statIndex($i)['size'];$z->close();if($total>30*1024*1024)throw new RuntimeException('Excel demasiado grande.'); }
        $reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext==='xls'?'Xls':'Xlsx');$reader->setReadDataOnly(true);$info=$reader->listWorksheetInfo($file['tmp_name']);
        if (!$info || $info[0]['totalRows']>2001 || $info[0]['totalColumns']>50) throw new RuntimeException('Máximo 2000 filas y 50 columnas.');
        $reader->setLoadSheetsOnly($info[0]['worksheetName']);$book=$reader->load($file['tmp_name']);$rows=$book->getSheet(0)->toArray(null,false,false,false);$book->disconnectWorksheets();
    } else throw new RuntimeException('Formato no permitido. Usa CSV, XLSX o XLS.');
    $headers=array_map(static fn($v)=>trim(normalize_text(str_replace("\xEF\xBB\xBF",'',(string)$v))),array_shift($rows) ?? []);$result=[];
    foreach ($rows as $row) { $d=[];foreach($headers as $i=>$key)$d[$key]=trim((string)($row[$i] ?? ''));if(implode('',$d)!=='')$result[]=$d; }
    if (!$result) throw new RuntimeException('El archivo no contiene datos.');return $result;
}
function agenda_planificar(PDO $pdo,array $user,array $d): int
{
    $cid=(string)($d['comite_id'] ?? '');$c=catalogo_fila($pdo,$cid);
    if (!agenda_puede($pdo,$user,'reuniones',$cid)) throw new RuntimeException('No puedes planificar reuniones de este comité.');
    if (!(int)$c['activo']) throw new RuntimeException('Selecciona un comité activo.');
    [$mes,$fecha]=agenda_plan($d);$paso=(int)($d['repetir'] ?? 0);
    if (!in_array($paso,[0,1,2,3,6],true)) throw new RuntimeException('Periodicidad no válida.');
    $total=0;$m=(int)substr($mes,5);$anio=substr($mes,0,4);
    do {
        $actual=$anio.'-'.str_pad((string)$m,2,'0',STR_PAD_LEFT);
        $dia=$fecha?(int)substr($fecha,8):null;
        $f=$dia?$actual.'-'.str_pad((string)min($dia,(int)date('t',strtotime($actual.'-01'))),2,'0',STR_PAD_LEFT):null;
        $q=$pdo->prepare('SELECT COUNT(*) FROM reuniones WHERE comite_id=? AND plan_mes=? AND cancelada=0');$q->execute([$cid,$actual]);
        if ($q->fetchColumn() && empty($d['permitir_duplicados'])) throw new RuntimeException('Ya existe una reunión en '.$actual.'. Marca “Permitir otra reunión en el mismo mes” si corresponde.');
        $pdo->prepare('INSERT INTO reuniones(comite_id,plan_mes,plan_fecha,plan_temas,fecha,responsable) VALUES (?,?,?,?,?,?)')->execute([$cid,$actual,$f,trim((string)($d['plan_temas'] ?? '')),$f,mb_substr(trim((string)($d['responsable'] ?? '')),0,180)]);
        $total++;$m+=$paso;
    } while($paso && $m<=12);
    return $total;
}
function agenda_reprogramar(PDO $pdo,string $tipo,array $r,array $d,string $actor): void
{
    if ((int)$r['realizada'] || (int)$r['cancelada']) throw new RuntimeException('Solo se pueden reprogramar registros pendientes.');
    [$mes,$fecha]=agenda_plan($d);$motivo=trim((string)($d['motivo'] ?? ''));
    if(mb_strlen($motivo)<3)throw new RuntimeException('Indica el motivo de la reprogramación.');
    $hist=json_decode((string)$r['reprogramaciones'],true) ?: [];
    $hist[]=['de'=>$r['plan_fecha'] ?: $r['plan_mes'],'a'=>$fecha ?: $mes,'motivo'=>$motivo,'fecha'=>date('c'),'por'=>$actor];
    $pdo->prepare('UPDATE '.agenda_tabla($tipo).' SET plan_mes=?,plan_fecha=?,fecha=?,reprogramaciones=? WHERE id=?')->execute([$mes,$fecha,$fecha,json_encode($hist,JSON_UNESCAPED_UNICODE),$r['id']]);
}

function agenda_mutar(PDO $pdo,array $user,string $tipo,string $accion,array $d,array $files=[]): string
{
    $id=(int)($d['id'] ?? 0);$r=$id?agenda_fila($pdo,$tipo,$id):null;
    $cid=$r['comite_id'] ?? ($d['comite_id'] ?? null);
    if (!agenda_puede($pdo,$user,$tipo,$cid)) throw new RuntimeException('No tienes permiso para realizar esta acción.');
    $tabla=agenda_tabla($tipo);$mensaje='Cambios guardados.';$archivoNuevo=null;
    $pdo->beginTransaction();
    try {
        if ($accion==='planificar' && $tipo==='reuniones') $mensaje=agenda_planificar($pdo,$user,$d).' reunión(es) planificada(s).';
        elseif ($accion==='importar_plan' && $tipo==='reuniones') {
            $map=[];foreach($pdo->query('SELECT id,nombre FROM comites') as $c){$map[normalize_text($c['id'])]=$c['id'];$map[normalize_text($c['nombre'])]=$c['id'];}
            $anio=(int)($d['anio'] ?? date('Y'));$n=0;$invalidas=[];
            foreach(agenda_leer($files['lista'] ?? []) as $i=>$fila){
                $mes=normalize_text((string)($fila['mes'] ?? ''));$cid=$map[normalize_text((string)($fila['comite'] ?? ''))] ?? '';
                $nm=array_search($mes,array_map('normalize_text',AGENDA_MESES),true);if($nm!==false)$mes=(string)($nm+1);
                if(ctype_digit($mes))$mes=$anio.'-'.str_pad($mes,2,'0',STR_PAD_LEFT);
                $mes=str_replace(' ','-',$mes);
                try { agenda_mes($mes);agenda_fecha((string)($fila['fecha tentativa'] ?? ''));if(!$cid)throw new RuntimeException('Comité no válido.'); }
                catch(RuntimeException $e){$invalidas[]=$i+2;continue;}
                $n+=agenda_planificar($pdo,$user,['comite_id'=>$cid,'plan_mes'=>$mes,'plan_fecha'=>$fila['fecha tentativa'] ?? '','plan_temas'=>$fila['temas a revisar'] ?? '','responsable'=>$fila['responsable'] ?? '','permitir_duplicados'=>$d['permitir_duplicados'] ?? false]);
            }
            $mensaje="Se cargaron {$n} reuniones.".($invalidas?' Filas con comité o fecha no válida: '.implode(', ',$invalidas):'');
        } elseif ($accion==='guardar_plan' && $tipo==='reuniones' && $r) {
            if((int)$r['realizada'] || (int)$r['cancelada'])throw new RuntimeException('Solo se puede editar la planificación pendiente.');
            $pdo->prepare('UPDATE reuniones SET plan_temas=?,responsable=? WHERE id=?')->execute([trim((string)($d['plan_temas'] ?? '')),mb_substr(trim((string)($d['responsable'] ?? '')),0,180),$id]);
        } elseif ($accion==='guardar_reunion' && $tipo==='reuniones') {
            if ($r && (int)$r['cancelada']) throw new RuntimeException('La reunión está cancelada.');
            $comite=(string)($d['comite_id'] ?? $cid);catalogo_fila($pdo,$comite);
            if(!agenda_puede($pdo,$user,'reuniones',$comite))throw new RuntimeException('No puedes editar reuniones de ese comité.');
            $realizada=!empty($d['realizada'])?1:0;
            $fecha=agenda_fecha((string)($d['fecha'] ?? ''),!$realizada);
            if($realizada && $fecha>date('Y-m-d'))throw new RuntimeException('La ejecución debe ser hoy o anterior.');
            $asistentes=array_unique(array_map('intval',(array)($d['asistentes'] ?? [])));
            $validos=array_map('intval',$pdo->query('SELECT id FROM contactos')->fetchAll(PDO::FETCH_COLUMN));
            if(array_diff($asistentes,$validos))throw new RuntimeException('Selecciona asistentes del directorio.');
            foreach((array)($d['nuevos'] ?? []) as $nuevo){
                if(!can_manage_resource($user,'contactos'))throw new RuntimeException('Necesitas permiso para crear contactos.');
                $v=directorio_validar($nuevo);$q=$pdo->prepare('SELECT id FROM contactos WHERE email=? AND email<>? LIMIT 1');$q->execute([$v['email'],'']);$contacto=$q->fetchColumn();
                if(!$contacto){$contacto=directorio_guardar($pdo,$v+['comites'=>[$comite]],(string)$user['nombre']);$pdo->prepare("UPDATE contactos SET origen_tipo='reunion',origen_detalle=? WHERE id=?")->execute(['Asistente de reunión · '.$comite.' · '.$fecha,$contacto]);}
                $asistentes[]=(int)$contacto;
            }
            $pasos=(array)($d['acuerdos'] ?? []);
            foreach($pasos as $p){agenda_fecha((string)($p['plazo'] ?? ''));agenda_fecha((string)($p['fecha_seguimiento'] ?? ''));}
            $a=agenda_archivo($files['documento'] ?? [],'reuniones');$archivoNuevo=$a['ruta'];
            $nombre=$a['nombre'] ?? ($r['ayuda_memoria_nombre'] ?? null);$ruta=$a['ruta'] ?? ($r['ayuda_memoria_ruta'] ?? null);
            if(!empty($d['quitar_documento']) && !$archivoNuevo){$nombre=null;$ruta=null;}
            $responsable=mb_substr(trim((string)($d['responsable'] ?? '')),0,180);
            $valores=[$comite,$fecha,$realizada,trim((string)($d['temas'] ?? '')),$responsable,$nombre,$ruta];
            if($id)$pdo->prepare('UPDATE reuniones SET comite_id=?,fecha=?,realizada=?,temas=?,responsable=?,ayuda_memoria_nombre=?,ayuda_memoria_ruta=? WHERE id=?')->execute([...$valores,$id]);
            else{$pdo->prepare('INSERT INTO reuniones(comite_id,fecha,realizada,temas,responsable,ayuda_memoria_nombre,ayuda_memoria_ruta) VALUES (?,?,?,?,?,?,?)')->execute($valores);$id=(int)$pdo->lastInsertId();}
            if(!$realizada && !empty($r['plan_mes']))$pdo->prepare('UPDATE reuniones SET plan_temas=? WHERE id=?')->execute([$d['temas'] ?? '',$id]);
            comites_sync_reunion_asistentes($pdo,$id,array_unique($asistentes));comites_sync_reunion_acuerdos($pdo,$id,$comite,$fecha,$responsable,$pasos);
        } elseif ($accion==='reprogramar' && $r) agenda_reprogramar($pdo,$tipo,$r,$d,(string)$user['nombre']);
        elseif ($accion==='cancelar' && $r) {
            if((int)$r['realizada'] || (int)$r['cancelada'])throw new RuntimeException('Solo se pueden cancelar registros pendientes.');
            $motivo=trim((string)($d['motivo'] ?? ''));if(mb_strlen($motivo)<3)throw new RuntimeException('Indica el motivo de cancelación.');
            $pdo->prepare("UPDATE {$tabla} SET cancelada=1,motivo_cancelacion=? WHERE id=?")->execute([$motivo,$id]);
        } elseif ($accion==='eliminar' && $r) {
            if(!can_manage_resource($user,$tipo==='eventos'?'eventos':'reuniones'))throw new RuntimeException('Solo la administración puede eliminar registros.');
            if($tipo==='eventos' && (int)$r['fijo'])throw new RuntimeException('Un evento fijo se puede cancelar, no eliminar.');
            if($tipo==='reuniones'){
                $pdo->prepare('DELETE FROM acuerdos WHERE reunion_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM reunion_asistentes WHERE reunion_id=?')->execute([$id]);
            }else{
                $pdo->prepare('DELETE FROM evento_asistentes WHERE evento_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM evento_encuestas WHERE evento_id=?')->execute([$id]);
            }
            $pdo->prepare("DELETE FROM {$tabla} WHERE id=?")->execute([$id]);
        } elseif($tipo==='eventos') {
            $mensaje=agenda_evento_mutar($pdo,$user,$accion,$d,$files,$r,$archivoNuevo);
        } else throw new RuntimeException('Acción no válida.');
        $pdo->commit();return $mensaje;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($archivoNuevo && is_file($archivoNuevo))unlink($archivoNuevo);throw $e;}
}

function agenda_evento_mutar(PDO $pdo,array $user,string $accion,array $d,array $files,?array $r,?string &$archivoNuevo): string
{
    $id=(int)($r['id'] ?? 0);
    if($accion==='fijos') {
        $anio=(int)($d['anio'] ?? 0);agenda_mes($anio.'-01');$n=0;
        foreach(AGENDA_FIJOS as $i=>$f){$stmt=$pdo->prepare("INSERT IGNORE INTO eventos_transferencia(nombre,fijo,fijo_clave,plan_mes,lugar,publico) VALUES (?,1,?,?,'Auditorio CENGICAÑA','Gerentes agrícolas y personal técnico de ingenios')");$stmt->execute([$f['nombre'],$anio.'-'.$i,$anio.'-'.str_pad((string)$f['mes'],2,'0',STR_PAD_LEFT)]);$n+=$stmt->rowCount();}
        return "Se agregaron {$n} eventos fijos.";
    }
    if($accion==='guardar_evento') {
        if($r && (int)$r['cancelada'])throw new RuntimeException('El evento está cancelado.');
        $nombre=trim((string)($d['nombre'] ?? ''));if(!$nombre||mb_strlen($nombre)>220)throw new RuntimeException('Escribe un nombre de hasta 220 caracteres.');
        if($r && (int)$r['fijo'])$nombre=$r['nombre'];
        [$mes,$fecha]=agenda_plan($d);$modalidad=(string)($d['modalidad'] ?? 'Presencial');
        if(!in_array($modalidad,['Presencial','Virtual','Híbrido'],true))throw new RuntimeException('Modalidad no válida.');
        $hist=json_decode((string)($r['reprogramaciones'] ?? ''),true) ?: [];
        if($r && (($r['plan_fecha'] ?: $r['plan_mes'])!==($fecha ?: $mes)))$hist[]=['de'=>$r['plan_fecha'] ?: $r['plan_mes'],'a'=>$fecha ?: $mes,'motivo'=>'Cambio de fecha','fecha'=>date('c'),'por'=>$user['nombre']];
        $v=[$nombre,$mes,$fecha,mb_substr(trim((string)($d['lugar'] ?? '')),0,255),$modalidad,trim((string)($d['publico'] ?? '')),trim((string)($d['objetivo'] ?? '')),empty($r['fijo'])&&!empty($d['sobre_la_marcha'])?1:0,json_encode($hist,JSON_UNESCAPED_UNICODE)];
        if($id)$pdo->prepare('UPDATE eventos_transferencia SET nombre=?,plan_mes=?,plan_fecha=?,lugar=?,modalidad=?,publico=?,objetivo=?,sobre_la_marcha=?,reprogramaciones=? WHERE id=?')->execute([...$v,$id]);
        else $pdo->prepare('INSERT INTO eventos_transferencia(nombre,plan_mes,plan_fecha,lugar,modalidad,publico,objetivo,sobre_la_marcha,reprogramaciones) VALUES (?,?,?,?,?,?,?,?,?)')->execute($v);
        return 'Evento guardado.';
    }
    if(!$r)throw new RuntimeException('Selecciona un evento.');
    if((int)$r['cancelada'])throw new RuntimeException('El evento está cancelado.');
    if($accion==='ejecutar_evento') {
        $fecha=agenda_fecha((string)($d['fecha'] ?? ''),false);if($fecha>date('Y-m-d'))throw new RuntimeException('La ejecución debe ser hoy o anterior.');
        $n=(int)($d['participantes'] ?? 0);$ingenios=(int)($d['ingenios'] ?? 0);if($n<0||$ingenios<0)throw new RuntimeException('Las cantidades no pueden ser negativas.');
        $a=agenda_archivo($files['documento'] ?? [],'eventos');$archivoNuevo=$a['ruta'];
        $nombre=$a['nombre'] ?? $r['informe_nombre'];$ruta=$a['ruta'] ?? $r['informe_ruta'];if(!empty($d['quitar_documento'])&&!$archivoNuevo){$nombre=null;$ruta=null;}
        $pdo->prepare('UPDATE eventos_transferencia SET realizada=1,fecha=?,participantes=?,ingenios=?,informe_nombre=?,informe_ruta=?,notas=? WHERE id=?')->execute([$fecha,$n,$ingenios,$nombre,$ruta,trim((string)($d['notas'] ?? '')),$id]);
    } elseif($accion==='asistentes_cargar') {
        $filas=isset($d['filas'])?(array)$d['filas']:agenda_leer($files['lista'] ?? []);
        if(count($filas)>2000)throw new RuntimeException('Máximo 2000 asistentes.');$n=0;$duplicados=0;$invalidos=0;
        foreach($filas as $fila) {
            $v=[];foreach($fila as $k=>$value)$v[trim(normalize_text((string)$k))]=$value;
            try{$c=directorio_validar(['nombre'=>$v['nombre completo'] ?? $v['nombre'] ?? '','email'=>$v['correo'] ?? $v['email'] ?? '','cargo'=>$v['cargo'] ?? '','empresa'=>$v['empresa ingenio'] ?? $v['empresa'] ?? '','telefono'=>$v['telefono'] ?? '']);}catch(RuntimeException $e){$invalidos++;continue;}
            $q=$pdo->prepare('SELECT id FROM contactos WHERE LOWER(TRIM(email))=? AND email<>? LIMIT 1');$q->execute([$c['email'],'']);$contacto=$q->fetchColumn() ?: null;
            $clave=hash('sha256',$c['email'] ?: normalize_text($c['nombre']));
            $q=$pdo->prepare('INSERT IGNORE INTO evento_asistentes(evento_id,contacto_id,nombre,email,telefono,empresa,cargo,estado,clave) VALUES (?,?,?,?,?,?,?,?,?)');$q->execute([$id,$contacto,$c['nombre'],$c['email'],$c['telefono'],$c['empresa'],$c['cargo'],$contacto?'existente':'nuevo',$clave]);$q->rowCount()?$n++:$duplicados++;
        }
        $fuente=isset($files['lista'])?'Archivo: '.$files['lista']['name']:'Enlace CSV';
        $url=(string)($d['sync_url'] ?? '');if($url!==''&&!preg_match('~^https?://~i',$url))throw new RuntimeException('Enlace no válido.');
        $q=$pdo->prepare("SELECT COUNT(*) participantes,COUNT(DISTINCT NULLIF(empresa,'')) ingenios FROM evento_asistentes WHERE evento_id=?");$q->execute([$id]);$totales=$q->fetch();
        $pdo->prepare('UPDATE eventos_transferencia SET sincronizado=NOW(),fuente_asistentes=?,sync_url=?,participantes=GREATEST(participantes,?),ingenios=GREATEST(ingenios,?) WHERE id=?')->execute([mb_substr($fuente,0,255),$url ?: $r['sync_url'],$totales['participantes'],$totales['ingenios'],$id]);
        return "{$n} asistentes agregados; {$duplicados} repetidos y {$invalidos} inválidos omitidos.";
    } elseif(in_array($accion,['integrar','descartar'],true)) {
        if(!can_manage_resource($user,'contactos'))throw new RuntimeException('Necesitas permiso para gestionar contactos.');
        $cid=(string)($d['comite_id'] ?? '');if($cid!=='')catalogo_fila($pdo,$cid);
        foreach(array_unique(array_map('intval',(array)($d['ids'] ?? []))) as $aid) {
            $q=$pdo->prepare("SELECT * FROM evento_asistentes WHERE id=? AND evento_id=? AND estado='nuevo'");$q->execute([$aid,$id]);$a=$q->fetch();if(!$a)continue;
            if($accion==='descartar'){$pdo->prepare("UPDATE evento_asistentes SET estado='descartado' WHERE id=?")->execute([$aid]);continue;}
            $q=$pdo->prepare('SELECT id FROM contactos WHERE LOWER(TRIM(email))=? AND email<>? LIMIT 1');$q->execute([strtolower(trim((string)$a['email'])),'']);$contacto=$q->fetchColumn();
            if(!$contacto){$contacto=directorio_guardar($pdo,array_intersect_key($a,array_flip(['nombre','email','telefono','cargo','empresa']))+['comites'=>$cid?[$cid]:[]],(string)$user['nombre']);$pdo->prepare("UPDATE contactos SET origen_tipo='evento',origen_detalle=? WHERE id=?")->execute([mb_substr($r['nombre'].' · '.($r['fecha'] ?: $r['plan_mes']),0,255),$contacto]);}
            elseif($cid)$pdo->prepare('INSERT IGNORE INTO contacto_comite VALUES (?,?)')->execute([$contacto,$cid]);
            $pdo->prepare("UPDATE evento_asistentes SET contacto_id=?,estado='integrado' WHERE id=?")->execute([$contacto,$aid]);
        }
    } elseif($accion==='encuesta_cargar') {
        $n=0;$invalidos=0;foreach(agenda_leer($files['lista'] ?? []) as $row) {
            $valor=$row['calificacion 1 a 5'] ?? $row['calificacion'] ?? $row['puntuacion'] ?? '';
            if(!preg_match('/^[1-5]$/',(string)$valor)){$invalidos++;continue;}
            $pdo->prepare('INSERT INTO evento_encuestas(evento_id,calificacion,comentario) VALUES (?,?,?)')->execute([$id,(int)$valor,trim((string)($row['comentario'] ?? ''))]);$n++;
        }return "{$n} respuestas agregadas; {$invalidos} calificaciones inválidas omitidas.";
    } elseif($accion==='encuesta_quitar')$pdo->prepare('DELETE FROM evento_encuestas WHERE evento_id=?')->execute([$id]);
    else throw new RuntimeException('Acción no válida.');
    return 'Cambios guardados.';
}
