<?php
declare(strict_types=1);

const DIRECTORIO_AREAS = ['Agrícola','Fábrica','Cogeneración','Informática','Administración','Recursos Humanos','Investigación','Laboratorio','Gerencia general','Otro'];
const DIRECTORIO_NIVELES = ['Gerencia','Jefatura','Técnico','Operativo'];
const DIRECTORIO_ORIGENES = ['manual'=>'Registro manual','carga'=>'Carga masiva','comite'=>'Comités','reunion'=>'Reunión','curso'=>'Curso','evento'=>'Evento','seminario'=>'Seminario','servicio'=>'Servicio técnico','memoria'=>'Memoria de resultados','existente'=>'Base existente'];

function directorio_preparar(PDO $pdo): void
{
    if (!defined('ESQUEMA_MIGRANDO')) return; // DDL solo al desplegar (deploy/mysql/migrar-esquema.php, AUD-2026-001/I-2).
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

const DIRECTORIO_PARTICIPACION = ['muy_activo'=>'Muy activo','activo'=>'Activo','ocasional'=>'Ocasional','inactivo'=>'Inactivo','solo_eventos'=>'Solo eventos','sin_comite'=>'Sin actividad para medir'];
/**
 * Como se mide la participacion: la asistencia a las reuniones de sus comites es
 * obligatoria para quien esta asignado y es la base del indice (0-100). Asistir a los
 * eventos realizados es opcional: suma un bono de hasta BONO_EVENTOS puntos, nunca resta.
 */
const DIRECTORIO_BONO_EVENTOS = 15;
/** Periodos de analisis de participacion (meses hacia atras). */
const DIRECTORIO_PERIODOS = [3=>'Últimos 3 meses',6=>'Últimos 6 meses',12=>'Últimos 12 meses',24=>'Últimos 24 meses'];
const DIRECTORIO_UMBRALES = ['muy_activo'=>80,'activo'=>60,'ocasional'=>30];
/** Agrupacion informativa en reportes (no cambia la forma de medir). */
const DIRECTORIO_PERFILES = ['interno'=>['nombre'=>'CENGICAÑA'],'ingenio'=>['nombre'=>'Ingenios y externos']];

/** Perfil del contacto segun su empresa: CENGICANA (interno) o ingenio / externo. */
function directorio_perfil(array $c): string
{
    return str_contains(normalize_text((string)($c['empresa'] ?? '')),'cengica') ? 'interno' : 'ingenio';
}

/**
 * Participacion de cada contacto en los ultimos 12 meses (o desde su alta): asistencia a
 * las reuniones realizadas de sus comites (obligatoria) y a los eventos realizados
 * (opcional, bono). Articulos y proximos pasos se informan pero no suman al indice.
 * Devuelve el indice 0-100 con su nivel, roles, actividad por mes y alertas.
 */
function directorio_participacion(PDO $pdo, array $contactos, array $historial, int $meses=12): array
{
    $hoy=new DateTimeImmutable('today'); $desde12=$hoy->modify("-$meses months")->format('Y-m-d');
    $reuniones=[];
    foreach ($pdo->query("SELECT r.id,r.comite_id,r.fecha,c.nombre FROM reuniones r LEFT JOIN comites c ON c.id=r.comite_id WHERE r.realizada=1 AND r.fecha IS NOT NULL ORDER BY r.fecha") as $r) $reuniones[$r['comite_id']][]=$r;
    $asistio=[];
    foreach ($pdo->query('SELECT reunion_id,contacto_id FROM reunion_asistentes') as $r) $asistio[(int)$r['contacto_id']][(int)$r['reunion_id']]=true;
    $porNombre=[];
    foreach ($contactos as $c) if ($c['nombre']) $porNombre[normalize_text($c['nombre'])][]=(int)$c['id'];
    $pasos=[];
    foreach ($pdo->query('SELECT responsable,estado,plazo,fecha FROM acuerdos') as $a) {
        foreach ($porNombre[normalize_text((string)$a['responsable'])] ?? [] as $id) $pasos[$id][]=$a;
    }
    $lideres=[];
    foreach ($pdo->query('SELECT id,nombre,lider_id FROM comites WHERE lider_id IS NOT NULL') as $g) $lideres[(int)$g['lider_id']][]=$g['nombre'];
    $meses=[];
    for ($i=11;$i>=0;$i--) $meses[]=$hoy->modify('first day of this month')->modify("-$i months")->format('Y-m');
    // Fechas de los eventos realizados: cada contacto se compara con los que hubo desde su alta.
    $fechasEventos=app_table_exists($pdo,'eventos_transferencia')
        ? $pdo->query("SELECT fecha FROM eventos_transferencia WHERE realizada=1 AND cancelada=0 AND fecha>='$desde12'")->fetchAll(PDO::FETCH_COLUMN) : [];

    $res=[];
    foreach ($contactos as $c) {
        $id=(int)$c['id'];
        $alta=(string)($c['origen_fecha'] ?: substr((string)$c['creado_en'],0,10));
        $inicio=max($desde12,$alta);
        // Asistencia por comite: reuniones realizadas desde su alta (maximo 12 meses).
        $comites=[]; $convocadas=0; $asistidas=0;
        foreach ($c['comites'] as $cid) {
            $lista=array_values(array_filter($reuniones[$cid] ?? [],static fn($r)=>$r['fecha']>=$inicio));
            if (!$lista) { $comites[]=['id'=>$cid,'nombre'=>null,'convocadas'=>0,'asistidas'=>0,'pct'=>null,'ultimas'=>[],'faltas_seguidas'=>0]; continue; }
            $marcas=array_map(static fn($r)=>['fecha'=>$r['fecha'],'asistio'=>isset($asistio[$id][(int)$r['id']])],$lista);
            $n=count($marcas); $si=count(array_filter($marcas,static fn($m)=>$m['asistio']));
            $seguidas=0; foreach (array_reverse($marcas) as $m) { if ($m['asistio']) break; $seguidas++; }
            $comites[]=['id'=>$cid,'nombre'=>$lista[0]['nombre'],'convocadas'=>$n,'asistidas'=>$si,'pct'=>$si/$n*100,'ultimas'=>array_slice($marcas,-8),'faltas_seguidas'=>$seguidas];
            $convocadas+=$n; $asistidas+=$si;
        }
        $h=array_filter($historial[$id] ?? [],static fn($e)=>$e['tipo']!=='origen' && (string)$e['fecha']>=$desde12);
        $cuenta=static fn(string $tipo,string $prefijo='')=>count(array_filter($h,static fn($e)=>$e['tipo']===$tipo && ($prefijo===''||str_starts_with($e['titulo'],$prefijo))));
        $eventos=$cuenta('evento'); $correos=$cuenta('correo');
        $autor=$cuenta('memoria','Autor'); $revisor=$cuenta('memoria','Revisor');
        $misPasos=array_filter($pasos[$id] ?? [],static fn($a)=>!$a['fecha']||$a['fecha']>=$desde12);
        $completados=count(array_filter($misPasos,static fn($a)=>$a['estado']==='Completado'));
        $vencidos=count(array_filter($misPasos,static fn($a)=>$a['plazo'] && $a['plazo']<$hoy->format('Y-m-d') && !in_array($a['estado'],['Completado','Cancelado'],true)));
        $activos=array_filter($historial[$id] ?? [],static fn($e)=>in_array($e['tipo'],['reunion','evento','memoria','paso'],true) && $e['fecha']);
        $ultima=$activos ? max(array_column($activos,'fecha')) : null;
        $dias=$ultima ? (int)$hoy->diff(new DateTimeImmutable($ultima))->days : null;

        // Indice: % de asistencia a las reuniones de sus comites (obligatoria) + bono por
        // asistir a los eventos realizados desde su alta (opcional, hasta DIRECTORIO_BONO_EVENTOS).
        $perfil=directorio_perfil($c);
        $eventosPosibles=count(array_filter($fechasEventos,static fn($f)=>$f>=$inicio));
        $pctEventos=$eventos ? min(1,$eventos/max(1,$eventosPosibles)) : ($eventosPosibles ? 0.0 : null);
        $pctComites=$convocadas ? $asistidas/$convocadas*100 : null;
        $bono=$pctEventos!==null ? $pctEventos*DIRECTORIO_BONO_EVENTOS : 0;
        if ($pctComites!==null) {
            $indice=(int)round(min(100,$pctComites+$bono));
            $nivel=$indice>=DIRECTORIO_UMBRALES['muy_activo'] ? 'muy_activo' : ($indice>=DIRECTORIO_UMBRALES['activo'] ? 'activo' : ($indice>=DIRECTORIO_UMBRALES['ocasional'] ? 'ocasional' : 'inactivo'));
        } elseif ($eventos) {
            // Sin reuniones de comite que medir, pero asiste a eventos: se mide por eventos.
            $indice=(int)round($pctEventos*100); $nivel='solo_eventos';
        } else {
            $indice=0; $nivel='sin_comite';
        }
        $componentes=[
            'Asistencia a comités'=>[$pctComites===null ? 0 : (int)round($pctComites),100,$pctComites!==null,'obligatoria'],
            'Eventos (bono)'=>[(int)round($bono),DIRECTORIO_BONO_EVENTOS,$pctEventos!==null,'opcional'],
        ];

        $roles=[];
        if (isset($lideres[$id])) $roles[]='Líder de '.implode(', ',$lideres[$id]);
        if ($asistidas) $roles[]='Asiste a reuniones';
        if ($eventos) $roles[]='Asiste a eventos';
        if ($autor) $roles[]='Autor en memoria';
        if ($revisor) $roles[]='Revisor de artículos';
        if ($misPasos) $roles[]='Responsable de próximos pasos';
        if (!$roles && $correos) $roles[]='Solo recibe correos';

        $porMes=array_fill_keys($meses,0);
        foreach ($activos as $e) { $m=substr((string)$e['fecha'],0,7); if (isset($porMes[$m])) $porMes[$m]++; }

        $alertas=[];
        foreach ($comites as $g) {
            if ($g['faltas_seguidas']>=2) $alertas[]=['mejorar','No asistió a las últimas '.$g['faltas_seguidas'].' reuniones de '.$g['nombre'].'.'];
            elseif ($g['convocadas']>=3 && $g['pct']<50) $alertas[]=['mejorar','Asistencia baja en '.$g['nombre'].': '.$g['asistidas'].' de '.$g['convocadas'].' reuniones.'];
        }
        if ($vencidos) $alertas[]=['info',$vencidos.' próximo(s) paso(s) vencido(s) sin completar (no afecta el índice).'];
        if ($c['comites'] && !$convocadas && !$activos) $alertas[]=['info','Sus comités aún no tienen reuniones realizadas desde su ingreso: todavía no hay participación que medir.'];
        elseif ($c['comites'] && ($dias===null || $dias>90)) $alertas[]=['mejorar',$dias===null ? 'Integra comités pero no tiene participación registrada.' : 'Sin participación activa en '.$dias.' días.'];
        if (!$asistidas && !$eventos && $correos>=3) $alertas[]=['info','Recibe correos ('.$correos.') pero no asiste: conviene invitarlo personalmente.'];
        if ($revisor+$autor>=2) $alertas[]=['bien','Aporta al comité editorial ('.($autor+$revisor).' artículos).'];
        if ($convocadas>=2 && $asistidas===$convocadas) $alertas[]=['bien','Asistió a todas las reuniones de sus comités ('.$asistidas.').'];
        if ($eventosPosibles>=2 && !$eventos && $convocadas) $alertas[]=['info','No asistió a ninguno de los '.$eventosPosibles.' eventos realizados (opcional: asistir suma hasta +'.DIRECTORIO_BONO_EVENTOS.' puntos).'];
        if ($eventos>=2) $alertas[]=['bien','Asistió a '.$eventos.' eventos de transferencia.'];

        $res[$id]=['indice'=>$indice,'nivel'=>$nivel,'convocadas'=>$convocadas,'asistidas'=>$asistidas,'pct_asistencia'=>$convocadas ? round($asistidas/$convocadas*100) : null,
            'comites'=>$comites,'eventos'=>$eventos,'correos'=>$correos,'autor'=>$autor,'revisor'=>$revisor,'pasos'=>count($misPasos),'pasos_completados'=>$completados,'pasos_vencidos'=>$vencidos,
            'ultima_activa'=>$ultima,'dias'=>$dias,'roles'=>$roles,'por_mes'=>$porMes,'alertas'=>$alertas,
            'eventos_posibles'=>$eventosPosibles,'pct_eventos'=>$pctEventos===null ? null : (int)round($pctEventos*100),
            'perfil'=>$perfil,'perfil_nombre'=>DIRECTORIO_PERFILES[$perfil]['nombre'],'componentes'=>$componentes];
    }
    return $res;
}

function directorio_datos(PDO $pdo, array $user, int $meses=12): array
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
    $participacion=directorio_participacion($pdo,$contactos,$historial,$meses);
    foreach ($contactos as &$c) $c['participacion']=$participacion[$c['id']];
    unset($c);
    $stmt=$pdo->prepare('SELECT id,nombre,filtros FROM contacto_segmentos WHERE usuario_id=? ORDER BY id'); $stmt->execute([(int)$user['id']]);
    $segmentos=$stmt->fetchAll();
    foreach ($segmentos as &$s) $s['filtros']=json_decode($s['filtros'],true);
    unset($s);
    $areas=array_values(array_unique(array_merge(DIRECTORIO_AREAS,array_filter(array_column($contactos,'area')))));
    return ['contactos'=>$contactos,'comites'=>$pdo->query('SELECT id,nombre,activo,lider_id FROM comites ORDER BY nombre')->fetchAll(),'segmentos'=>$segmentos,'areas'=>$areas,'niveles'=>DIRECTORIO_NIVELES,'origenes'=>DIRECTORIO_ORIGENES,'participacion_niveles'=>DIRECTORIO_PARTICIPACION,'perfiles'=>DIRECTORIO_PERFILES,'umbrales'=>DIRECTORIO_UMBRALES,'bono_eventos'=>DIRECTORIO_BONO_EVENTOS,'meses'=>$meses,'periodos'=>DIRECTORIO_PERIODOS,'gestionar'=>can_manage_resource($user,'contactos'),'enviar'=>can_manage_resource($user,'correos'),'csrf'=>$_SESSION['directorio_csrf']];
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
