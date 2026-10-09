<?php
declare(strict_types=1);

const DIRECTORIO_AREAS = ['Agrícola','Fábrica','Cogeneración','Informática','Administración','Recursos Humanos','Investigación','Laboratorio','Gerencia general','Otro'];
const DIRECTORIO_NIVELES = ['Gerencia','Jefatura','Técnico','Operativo'];
const DIRECTORIO_ORIGENES = ['manual'=>'Registro manual','carga'=>'Carga masiva','comite'=>'Comités','reunion'=>'Reunión','curso'=>'Curso','evento'=>'Evento','seminario'=>'Seminario','servicio'=>'Servicio técnico','memoria'=>'Memoria de resultados','existente'=>'Base existente'];

function directorio_preparar(PDO $pdo): void
{
    catalogo_preparar($pdo);
    foreach (['nivel'=>"VARCHAR(80) NULL",'origen_tipo'=>"VARCHAR(30) NOT NULL DEFAULT 'existente'",'origen_detalle'=>'VARCHAR(255) NULL','origen_fecha'=>'DATE NULL','origen_por'=>'VARCHAR(180) NULL'] as $col=>$tipo) {
        app_ensure_column($pdo,'contactos',$col,"{$col} {$tipo}");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS contacto_segmentos (id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, nombre VARCHAR(100) NOT NULL, filtros TEXT NOT NULL, INDEX(usuario_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function directorio_clasificar(string $cargo): array
{
    $cargo = normalize_text($cargo);
    $area = '';
    foreach (['Cogeneración'=>'cogenera|energia|calder|turbina|electric','Fábrica'=>'fabrica|industrial|molino|elaboracion|proceso|mantenimiento|azucar|refineria','Informática'=>'informatica|\bti\b|sistemas|tecnologia de informacion|datos|desarroll','Recursos Humanos'=>'recursos humanos|rrhh|talento|personal|capacitacion|nomina','Administración'=>'administra|finanz|contab|compras|logistic|tesorer','Laboratorio'=>'laboratorio|analisis quimic','Investigación'=>'investiga|especialista|cientific','Agrícola'=>'agricol|campo|cosecha|riego|cultivo|siembra|fitosan|plagas|malezas|suelos|nutricion|zafra|agronom|variedad','Gerencia general'=>'gerente general|director general|presidente'] as $label=>$regex) {
        if (preg_match('/'.$regex.'/',$cargo)) { $area=$label; break; }
    }
    $nivel = $cargo === '' ? '' : (preg_match('/gerente|director|presidente|subgerente/',$cargo) ? 'Gerencia' : (preg_match('/jefe|jefa|superintendente|coordinador|encargad|supervisor/',$cargo) ? 'Jefatura' : (preg_match('/tecnico|especialista|analista|ingeniero|\bing\b|asistente|investigador/',$cargo) ? 'Técnico' : 'Operativo')));
    return [$area,$nivel];
}

function directorio_origen(PDO $pdo, int $id, string $tipo, string $detalle, string $actor): void
{
    $pdo->prepare("UPDATE contactos SET origen_tipo=?,origen_detalle=?,origen_fecha=CURRENT_DATE,origen_por=? WHERE id=? AND origen_tipo='existente'")->execute([$tipo,mb_substr($detalle,0,255),mb_substr($actor,0,180),$id]);
}

function directorio_historial(PDO $pdo, array $contactos): array
{
    $historial=[]; $porEmail=[]; $porNombre=[];
    foreach ($contactos as $c) {
        $id=(int)$c['id'];
        $historial[$id]=[['tipo'=>'origen','fecha'=>$c['origen_fecha'] ?? substr((string)$c['creado_en'],0,10),'titulo'=>'Ingresó al directorio · '.(DIRECTORIO_ORIGENES[$c['origen_tipo']] ?? 'Base existente'),'detalle'=>$c['origen_detalle'] ?? 'Registro previo a la trazabilidad']];
        if ($c['email']) $porEmail[strtolower(trim($c['email']))][]=$id;
        if ($c['nombre']) $porNombre[normalize_text($c['nombre'])][]=$id;
    }
    $agregar = static function(array $ids,array $evento) use (&$historial): void {
        foreach (array_unique($ids) as $id) { if (isset($historial[$id])) $historial[$id][]=$evento; }
    };
    foreach ($pdo->query('SELECT ra.contacto_id,r.fecha,r.temas,c.nombre FROM reunion_asistentes ra JOIN reuniones r ON r.id=ra.reunion_id LEFT JOIN comites c ON c.id=r.comite_id WHERE r.realizada=1') as $r) {
        $agregar([(int)$r['contacto_id']],['tipo'=>'reunion','fecha'=>$r['fecha'],'titulo'=>'Asistió a reunión · '.$r['nombre'],'detalle'=>$r['temas']]);
    }
    foreach ($pdo->query('SELECT * FROM correos_enviados') as $r) {
        $emails=json_decode((string)$r['destinatarios'],true);
        if (!is_array($emails)) $emails=preg_split('/[;,\s]+/',(string)$r['destinatarios']);
        $ids=[];
        foreach ($emails as $email) { if (is_string($email)) $ids=array_merge($ids,$porEmail[strtolower(trim($email))] ?? []); }
        $agregar($ids,['tipo'=>'correo','fecha'=>substr($r['enviado_en'],0,10),'titulo'=>'Recibió correo · '.$r['asunto'],'detalle'=>$r['grupo_destino']]);
    }
    foreach ($pdo->query('SELECT a.*,c.nombre AS comite FROM acuerdos a LEFT JOIN comites c ON c.id=a.comite_id') as $r) {
        $agregar($porNombre[normalize_text((string)$r['responsable'])] ?? [],['tipo'=>'paso','fecha'=>$r['fecha'],'titulo'=>'Responsable de próximo paso · '.$r['comite'],'detalle'=>$r['acuerdo']]);
    }
    foreach ($pdo->query('SELECT m.*,e.email AS revisor_email,e.nombre AS revisor_nombre FROM memorias_resultados m LEFT JOIN comite_editorial e ON e.id=m.revisor_id') as $r) {
        $agregar(array_merge($porNombre[normalize_text((string)$r['autor'])] ?? [], $porEmail[strtolower(trim((string)$r['autor_email']))] ?? []),['tipo'=>'memoria','fecha'=>$r['fecha'],'titulo'=>'Autor de artículo · '.$r['numero_memoria'],'detalle'=>$r['titulo']]);
        $agregar($porEmail[strtolower(trim((string)$r['revisor_email']))] ?? [],['tipo'=>'memoria','fecha'=>$r['fecha'],'titulo'=>'Revisor de artículo · '.$r['numero_memoria'],'detalle'=>$r['titulo']]);
    }
    if (app_table_exists($pdo,'evento_asistentes')) {
        foreach ($pdo->query("SELECT a.contacto_id,a.email,e.nombre,e.fecha,e.lugar FROM evento_asistentes a JOIN eventos_transferencia e ON e.id=a.evento_id WHERE e.realizada=1") as $r) {
            $ids=$r['contacto_id']?[(int)$r['contacto_id']]:($porEmail[strtolower(trim((string)$r['email']))] ?? []);
            $agregar($ids,['tipo'=>'evento','fecha'=>$r['fecha'],'titulo'=>'Asistió a evento · '.$r['nombre'],'detalle'=>$r['lugar']]);
        }
    }
    foreach ($historial as &$lista) usort($lista,static fn($a,$b)=>strcmp((string)$b['fecha'],(string)$a['fecha']));
    unset($lista);
    return $historial;
}

function directorio_datos(PDO $pdo, array $user): array
{
    $contactos=$pdo->query('SELECT * FROM contactos ORDER BY nombre')->fetchAll();
    $rel=[];
    foreach ($pdo->query('SELECT * FROM contacto_comite') as $r) $rel[(int)$r['contacto_id']][]=$r['comite_id'];
    $historial=directorio_historial($pdo,$contactos);
    foreach ($contactos as &$c) {
        $c['id']=(int)$c['id']; $c['comites']=$rel[$c['id']] ?? [];
        $c['historial']=$historial[$c['id']];
        $c['ultima']=null;
        foreach ($c['historial'] as $h) { if ($h['tipo']!=='origen' && $h['fecha']) { $c['ultima']=$h; break; } }
    }
    unset($c);
    $stmt=$pdo->prepare('SELECT id,nombre,filtros FROM contacto_segmentos WHERE usuario_id=? ORDER BY id'); $stmt->execute([(int)$user['id']]);
    $segmentos=$stmt->fetchAll();
    foreach ($segmentos as &$s) $s['filtros']=json_decode($s['filtros'],true);
    unset($s);
    $areas=array_values(array_unique(array_merge(DIRECTORIO_AREAS,array_filter(array_column($contactos,'area')))));
    return ['contactos'=>$contactos,'comites'=>$pdo->query('SELECT id,nombre,activo,lider_id FROM comites ORDER BY nombre')->fetchAll(),'segmentos'=>$segmentos,'areas'=>$areas,'niveles'=>DIRECTORIO_NIVELES,'origenes'=>DIRECTORIO_ORIGENES,'gestionar'=>can_manage_resource($user,'contactos'),'enviar'=>can_manage_resource($user,'correos'),'csrf'=>$_SESSION['directorio_csrf']];
}

function directorio_validar(array $d): array
{
    $out=[];
    foreach (['nombre'=>180,'cargo'=>180,'empresa'=>180,'telefono'=>40,'email'=>180,'area'=>180,'nivel'=>80] as $key=>$max) {
        $out[$key]=trim((string)($d[$key] ?? ''));
        if (mb_strlen($out[$key])>$max) throw new RuntimeException("El campo {$key} supera {$max} caracteres.");
    }
    if ($out['nombre']==='') throw new RuntimeException('Escribe el nombre completo.');
    $out['email']=strtolower($out['email']);
    if ($out['email']!=='' && !filter_var($out['email'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
    [$area,$nivel]=directorio_clasificar($out['cargo']);
    $out['area']=$out['area'] ?: $area; $out['nivel']=$out['nivel'] ?: $nivel;
    if ($out['nivel']!=='' && !in_array($out['nivel'],DIRECTORIO_NIVELES,true)) throw new RuntimeException('Nivel no válido.');
    $out['estado']=($d['estado'] ?? '')==='Inactivo'?'Inactivo':'Activo';
    return $out;
}

function directorio_guardar(PDO $pdo,array $d,string $actor): int
{
    $id=(int)($d['id'] ?? 0); $v=directorio_validar($d);
    if ($id) {
        $stmt=$pdo->prepare('SELECT id FROM contactos WHERE id=?'); $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('El contacto ya no existe.');
    }
    if ($v['email']!=='') {
        $stmt=$pdo->prepare('SELECT id FROM contactos WHERE LOWER(TRIM(email))=? AND id<>?'); $stmt->execute([$v['email'],$id]);
        if ($stmt->fetchColumn()) throw new RuntimeException('Ya existe un contacto con ese correo.');
    }
    $ids=array_values(array_unique(array_map('strval',(array)($d['comites'] ?? []))));
    $validos=$pdo->query('SELECT id FROM comites')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($ids,$validos)) throw new RuntimeException('Uno de los comités ya no existe.');
    $values=array_values($v);
    if ($id) $pdo->prepare('UPDATE contactos SET nombre=?,cargo=?,empresa=?,telefono=?,email=?,area=?,nivel=?,estado=? WHERE id=?')->execute([...$values,$id]);
    else {
        $pdo->prepare('INSERT INTO contactos (nombre,cargo,empresa,telefono,email,area,nivel,estado) VALUES (?,?,?,?,?,?,?,?)')->execute($values);
        $id=(int)$pdo->lastInsertId();
        directorio_origen($pdo,$id,'manual','Registrado en el directorio',$actor);
    }
    comites_sync_contacto_comites($pdo,$id,$ids);
    // Una persona retirada de un grupo deja de figurar como su líder.
    $stmt=$pdo->prepare('SELECT id FROM comites WHERE lider_id=?'); $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) { if (!in_array($cid,$ids,true)) $pdo->prepare('UPDATE comites SET lider_id=NULL WHERE id=?')->execute([$cid]); }
    return $id;
}

function directorio_dependencias(): void
{
    require_once dirname(__DIR__,2).'/cengicursos/vendor/autoload.php';
}

function directorio_importar(PDO $pdo,array $d,array $file,string $actor): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>5*1024*1024) throw new RuntimeException('Selecciona un archivo Excel o CSV de hasta 5 MB.');
    $tipo=(string)($d['origen_tipo'] ?? 'carga'); $detalle=trim((string)($d['origen_detalle'] ?? ''));
    if (!in_array($tipo,['carga','comite','curso','evento','seminario','servicio'],true)) throw new RuntimeException('Selecciona un origen válido.');
    if (!in_array($tipo,['carga','comite'],true) && $detalle==='') throw new RuntimeException('Indica el nombre del curso, evento, seminario o servicio.');
    $comites=$pdo->query('SELECT id,nombre FROM comites')->fetchAll(); $map=[];
    foreach ($comites as $c) { $map[normalize_text($c['nombre'])]=$c['id']; $map[normalize_text($c['id'])]=$c['id']; }
    $fijo=(string)($d['comite'] ?? '');
    if ($fijo!=='' && !in_array($fijo,array_column($comites,'id'),true)) throw new RuntimeException('Comité no válido.');
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if ($ext==='csv') $filas=catalogo_leer_lista($file);
    elseif (in_array($ext,['xls','xlsx'],true)) {
        directorio_dependencias();
        if ($ext==='xlsx') {
            $zip=new ZipArchive(); if ($zip->open($file['tmp_name'])!==true) throw new RuntimeException('Excel no válido.');
            $total=0; for($i=0;$i<$zip->numFiles;$i++) $total+=$zip->statIndex($i)['size']; $zip->close();
            if ($total>30*1024*1024) throw new RuntimeException('El Excel descomprimido supera el límite de 30 MB.');
        }
        $reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext==='xls'?'Xls':'Xlsx');
        $reader->setReadDataOnly(true);
        $info=$reader->listWorksheetInfo($file['tmp_name']);
        if (($info[0]['totalRows'] ?? 0)>2001 || ($info[0]['totalColumns'] ?? 0)>50) throw new RuntimeException('La lista admite hasta 2000 contactos y 50 columnas.');
        $reader->setLoadSheetsOnly($info[0]['worksheetName']);
        $book=$reader->load($file['tmp_name']); $rows=$book->getSheet(0)->toArray(null,false,false,false); $book->disconnectWorksheets();
        $headers=array_shift($rows) ?? []; $filas=[];
        foreach ($rows as $row) { $f=[]; foreach ($headers as $i=>$col) $f[normalize_text((string)$col)]=trim((string)($row[$i] ?? '')); if (implode('',$f)!=='') $filas[]=$f; }
    } else throw new RuntimeException('Usa un archivo CSV, XLSX o XLS.');
    if (!$filas) throw new RuntimeException('El archivo no tiene filas de datos.');
    $nuevos=0; $omitidos=0; $vinculados=0; $invalidos=0;
    foreach ($filas as $fila) {
        $f=[]; foreach ($fila as $key=>$value) $f[normalize_text((string)$key)]=$value;
        $fila=['nombre'=>$f['nombre completo'] ?? $f['nombre'] ?? '', 'cargo'=>$f['cargo'] ?? '', 'empresa'=>$f['empresa ingenio'] ?? $f['empresa'] ?? $f['ingenio'] ?? '', 'telefono'=>$f['telefono'] ?? '', 'email'=>$f['correo'] ?? $f['email'] ?? '', 'area'=>$f['area'] ?? '', 'nivel'=>$f['nivel'] ?? '', 'estado'=>$f['estado'] ?? 'Activo'];
        if (!$fila['area']) { [$inferida]=directorio_clasificar($fila['cargo']); $fila['area']=$inferida ?: ($d['area'] ?? ''); }
        try { $fila=directorio_validar($fila); } catch (RuntimeException $e) { $invalidos++; continue; }
        $stmt=$pdo->prepare('SELECT id FROM contactos WHERE LOWER(TRIM(email))=? AND email<>? LIMIT 1'); $stmt->execute([$fila['email'],'']); $existente=$stmt->fetchColumn();
        $grupos=$fijo!==''?[$fijo]:[];
        foreach (preg_split('/[;,]+/',(string)($f['comites'] ?? '')) as $nombre) { if (isset($map[normalize_text($nombre)])) $grupos[]=$map[normalize_text($nombre)]; }
        if ($existente) {
            $omitidos++;
            foreach(array_unique($grupos) as $grupo) { $q=$pdo->prepare('INSERT IGNORE INTO contacto_comite (contacto_id,comite_id) VALUES (?,?)'); $q->execute([$existente,$grupo]); $vinculados+=$q->rowCount(); }
            continue;
        }
        $id=directorio_guardar($pdo,$fila+['comites'=>$grupos],$actor);
        $pdo->prepare('UPDATE contactos SET origen_tipo=?,origen_detalle=? WHERE id=?')->execute([$tipo,mb_substr(($detalle ?: $file['name']).' · '.$file['name'],0,255),$id]); $nuevos++;
    }
    return "{$nuevos} contactos incorporados; {$omitidos} correos existentes omitidos; {$vinculados} vínculos a comités agregados; {$invalidos} filas inválidas omitidas.";
}

function directorio_mutar(PDO $pdo,array $user,string $accion,array $d,array $files): string
{
    $actor=(string)$user['nombre']; $mensaje='Cambios guardados.';
    $pdo->beginTransaction();
    try {
        if ($accion==='guardar') directorio_guardar($pdo,$d,$actor);
        elseif ($accion==='estado') $pdo->prepare("UPDATE contactos SET estado=IF(estado='Activo','Inactivo','Activo') WHERE id=?")->execute([(int)($d['id'] ?? 0)]);
        elseif ($accion==='eliminar') {
            $id=(int)($d['id'] ?? 0);
            $pdo->prepare('UPDATE comites SET lider_id=NULL WHERE lider_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM contactos WHERE id=?')->execute([$id]);
        } elseif ($accion==='clasificar') {
            $n=0;
            foreach ($pdo->query("SELECT id,cargo,area,nivel FROM contactos WHERE COALESCE(area,'')='' OR COALESCE(nivel,'')=''")->fetchAll() as $c) {
                [$area,$nivel]=directorio_clasificar((string)$c['cargo']);
                $area=$c['area'] ?: $area; $nivel=$c['nivel'] ?: $nivel;
                if ($area!==($c['area'] ?? '') || $nivel!==($c['nivel'] ?? '')) { $pdo->prepare('UPDATE contactos SET area=?,nivel=? WHERE id=?')->execute([$area,$nivel,$c['id']]); $n++; }
            }
            $mensaje="Se clasificaron {$n} contactos a partir del cargo.";
        } elseif ($accion==='masivo') {
            $campo=$d['campo'] ?? ''; $valor=(string)($d['valor'] ?? '');
            $areas=array_merge(DIRECTORIO_AREAS,$pdo->query("SELECT DISTINCT area FROM contactos WHERE area IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($campo,['area','nivel'],true) || !in_array($valor,$campo==='area'?$areas:DIRECTORIO_NIVELES,true)) throw new RuntimeException('Selecciona un área o nivel válido.');
            $stmt=$pdo->prepare("UPDATE contactos SET {$campo}=? WHERE id=?");
            foreach (array_unique(array_map('intval',(array)($d['ids'] ?? []))) as $id) $stmt->execute([$valor,$id]);
        } elseif ($accion==='importar') $mensaje=directorio_importar($pdo,$d,$files['lista'] ?? [],$actor);
        elseif ($accion==='segmento_guardar') {
            $nombre=trim((string)($d['nombre'] ?? '')); if ($nombre==='' || mb_strlen($nombre)>100) throw new RuntimeException('Escribe un nombre de segmento de hasta 100 caracteres.');
            $filtros=array_intersect_key((array)($d['filtros'] ?? []),array_flip(['q','area','nivel','comite','empresa','origen','estado']));
            foreach ($filtros as $valor) if (!is_string($valor) || mb_strlen($valor)>255) throw new RuntimeException('Filtro no válido.');
            $pdo->prepare('INSERT INTO contacto_segmentos(usuario_id,nombre,filtros) VALUES (?,?,?)')->execute([$user['id'],$nombre,json_encode($filtros,JSON_UNESCAPED_UNICODE)]);
        } elseif ($accion==='segmento_eliminar') $pdo->prepare('DELETE FROM contacto_segmentos WHERE id=? AND usuario_id=?')->execute([(int)($d['id'] ?? 0),$user['id']]);
        else throw new RuntimeException('Acción no válida.');
        $pdo->commit(); return $mensaje;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
