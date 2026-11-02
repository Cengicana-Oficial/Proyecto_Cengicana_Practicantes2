<?php
declare(strict_types=1);

function interna_preparar(PDO $pdo): void
{
    biblioteca_esquema($pdo);
    // Conserva documentos, versiones e historial anterior; el uso interno no requiere aprobación.
    $pdo->exec("UPDATE documentos SET flujo='informativo',requiere_aprobacion=0,habilitado_envio=0 WHERE ambito='interna' AND (flujo IS NULL OR flujo<>'informativo' OR requiere_aprobacion<>0 OR habilitado_envio<>0)");
    $pdo->prepare("INSERT INTO documento_carpetas(ambito,nombre,parent_id) SELECT 'interna',?,NULL WHERE NOT EXISTS (SELECT 1 FROM documento_carpetas WHERE ambito='interna')")->execute(['Memorias y presentaciones '.date('Y')]);
}
function interna_gestionar(array $u): bool
{
    return is_superadmin($u)||(can_manage_resource($u,'biblioteca_interna')&&trim(normalize_text((string)($u['nombre_rol'] ?? '')))==='administrador');
}
function interna_fila(PDO $pdo,int $id,bool $lock=false): array
{
    $q=$pdo->prepare("SELECT * FROM documentos WHERE id=? AND ambito='interna'".($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('El documento no existe en la biblioteca interna.');return $r;
}
function interna_datos(PDO $pdo,array $u): array
{
    $rows=$pdo->query("SELECT * FROM documentos WHERE ambito='interna' ORDER BY COALESCE(fecha,DATE(creado_en)) DESC,id DESC")->fetchAll();
    foreach($rows as &$r){$r['id']=(int)$r['id'];$r['carpeta_id']=(int)$r['carpeta_id'];$r['historial']=[];$r['revision']=biblioteca_revision($pdo,$r);$r['revisar']=false;$r['enviar']=false;}unset($r);
    return ['interna'=>true,'documentos'=>$rows,'carpetas'=>$pdo->query("SELECT id,nombre,parent_id FROM documento_carpetas WHERE ambito='interna' ORDER BY nombre")->fetchAll(),'comites'=>[],'contactos'=>[],'segmentos'=>[],'tipos'=>BIB_TIPOS,'estados'=>BIB_ESTADOS,'etapas'=>[],'gestionar'=>interna_gestionar($u),'csrf'=>$_SESSION['interna_csrf'],'hoy'=>date('Y-m-d')];
}
function interna_guardar(PDO $pdo,array $u,array $d,?array $r,array $a): int
{
    $carpeta=(int)($d['carpeta_id'] ?? 0);if(!comites_carpeta_obtener($pdo,$carpeta,'interna'))throw new RuntimeException('Selecciona una carpeta de la biblioteca interna.');
    $titulo=trim((string)($d['titulo'] ?? ''));if(!$titulo||mb_strlen($titulo)>220)throw new RuntimeException('Escribe un título de hasta 220 caracteres.');
    $tipo=(string)($d['tipo'] ?? 'Informe');if(!isset(BIB_TIPOS[$tipo]))throw new RuntimeException('Tipo no válido.');
    $fecha=trim((string)($d['fecha'] ?? ''));if($fecha!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha)||date('Y-m-d',strtotime($fecha))!==$fecha))throw new RuntimeException('Fecha no válida.');
    $url=biblioteca_url((string)($d['enlace'] ?? ''));$cambio=$r&&($a||$url!==($r['enlace'] ?? ''));
    $v=[$carpeta,$tipo,$titulo,$fecha ?: null,mb_substr(trim((string)($d['autor'] ?? '')),0,180),trim((string)($d['descripcion'] ?? '')),$url ?: null,$a['nombre'] ?? $r['archivo_nombre'] ?? null,$a['ruta'] ?? $r['archivo_ruta'] ?? null,$a['bytes'] ?? $r['archivo_bytes'] ?? null,(int)($r['version'] ?? 1)+($cambio?1:0)];
    if($r){$id=(int)$r['id'];$pdo->prepare("UPDATE documentos SET carpeta_id=?,tipo=?,titulo=?,fecha=?,autor=?,descripcion=?,enlace=?,archivo_nombre=?,archivo_ruta=?,archivo_bytes=?,version=?,flujo='informativo',requiere_aprobacion=0,habilitado_envio=0 WHERE id=? AND ambito='interna'")->execute([...$v,$id]);}
    else{$pdo->prepare("INSERT INTO documentos(carpeta_id,tipo,titulo,fecha,autor,descripcion,enlace,archivo_nombre,archivo_ruta,archivo_bytes,version,cargado_por_id,cargado_por_nombre,cargado_por_rol,ambito,flujo,estado,requiere_aprobacion,habilitado_envio) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'interna','informativo','Informativo',0,0)")->execute([...$v,(int)$u['id'],$u['nombre'],$u['nombre_rol'] ?? 'Administrador']);$id=(int)$pdo->lastInsertId();}
    $actual=interna_fila($pdo,$id);biblioteca_log($pdo,$actual,$u,!$r?'creado':($cambio?'nueva_version':'editado'),'','Documento de uso interno.',['nombre'=>$actual['archivo_nombre'],'ruta'=>$actual['archivo_ruta'],'enlace'=>$url]);return $id;
}
function interna_mutar(PDO $pdo,array $u,string $accion,array $d,array $files=[]): string
{
    if(!can_view_resource($u,'biblioteca_interna')||!interna_gestionar($u))throw new RuntimeException('La carga y edición de la biblioteca interna corresponden al Administrador.');
    $archivos=[];$pdo->beginTransaction();
    try{
        $id=(int)($d['id'] ?? 0);$r=$id?interna_fila($pdo,$id,true):null;
        if($r&&isset($d['revision'])&&!hash_equals(biblioteca_revision($pdo,$r),(string)$d['revision']))throw new RuntimeException('El documento cambió. Recarga la biblioteca antes de continuar.');
        if($accion==='carpeta'){
            $nombre=trim((string)($d['nombre'] ?? ''));if(!$nombre||mb_strlen($nombre)>160)throw new RuntimeException('Escribe un nombre de hasta 160 caracteres.');
            $parent=(int)($d['parent_id'] ?? 0);if($parent&&!comites_carpeta_obtener($pdo,$parent,'interna'))throw new RuntimeException('Carpeta padre no válida.');
            $q=$pdo->prepare("SELECT COUNT(*) FROM documento_carpetas WHERE ambito='interna' AND parent_id <=> ? AND nombre=?");$q->execute([$parent ?: null,$nombre]);if($q->fetchColumn())throw new RuntimeException('Ya existe una carpeta con ese nombre aquí.');comites_carpeta_crear($pdo,'interna',$nombre,$parent ?: null);
        }elseif($accion==='guardar'){
            $a=biblioteca_archivo($files['archivo'] ?? []);if($a)$archivos[]=$a['ruta'];interna_guardar($pdo,$u,$d,$r,$a);
        }elseif($accion==='lote'){
            $filas=(array)($d['filas'] ?? []);if(!$filas||count($filas)>20)throw new RuntimeException('Selecciona entre 1 y 20 archivos.');
            foreach($filas as $i=>$fila){$a=biblioteca_archivo($files['archivo_'.$i] ?? []);if(!$a)throw new RuntimeException('Falta un archivo del lote.');$archivos[]=$a['ruta'];interna_guardar($pdo,$u,array_merge($d,$fila),null,$a);}
        }elseif($accion==='eliminar'&&$r){
            $pdo->prepare('DELETE FROM documento_historial WHERE documento_id=?')->execute([$id]);$pdo->prepare('UPDATE correos_enviados SET documento_id=NULL WHERE documento_id=?')->execute([$id]);$pdo->prepare("DELETE FROM documentos WHERE id=? AND ambito='interna'")->execute([$id]);
        }else throw new RuntimeException('La biblioteca interna no admite revisiones, aprobaciones ni envíos.');
        $pdo->commit();return $accion==='lote'?count($d['filas']).' documentos internos cargados.':'Cambios guardados.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($archivos as $ruta){$path=biblioteca_ruta($ruta);if($path)unlink($path);}throw $e;}
}
