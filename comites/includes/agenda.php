<?php
declare(strict_types=1);
const AGENDA_MESES=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const AGENDA_FIJOS=[['nombre'=>'Análisis del primer tercio de zafra','mes'=>1],['nombre'=>'Análisis del segundo tercio de zafra','mes'=>3],['nombre'=>'Presentación de resultados de investigación','mes'=>7],['nombre'=>'Simposio de análisis de la zafra','mes'=>8]];
/** Clasificación de cada comentario de la evaluación de un evento, para darle seguimiento. */
const AGENDA_CATEGORIAS_COMENTARIO=['queja'=>'Queja','solicitud'=>'Solicitud','seguimiento'=>'Requiere seguimiento','sugerencia'=>'Sugerencia','sin_accion'=>'Sin acción'];
const AGENDA_SEGUIMIENTO=['pendiente'=>'Pendiente','en_proceso'=>'En proceso','atendido'=>'Atendido'];
/** Clasificaciones que requieren seguimiento: sin estado registrado cuentan como pendientes. */
const AGENDA_REQUIEREN_SEGUIMIENTO=['queja','solicitud','seguimiento'];
/**
 * Clasificación automática de un comentario por palabras clave y calificación, en orden de
 * prioridad. Se marca como "Automático" para que una persona la confirme o la cambie.
 */
const AGENDA_REGLAS_COMENTARIO=[
    // Palabras completas o frases con verbo: "malezas", "por la tarde" o "excelente presentación" no cuentan.
    'queja'=>'/\b(?:mal[oa]?s?\b|muy mal|p[eé]sim|deficient|no (?:funcion|se (?:escuch|ve[ií]a|ve\b|entend)|hubo|hab[ií]a|alcanz|me gust)|(?:comenz|empez|inici|lleg)\w* tarde|retras|desorganiz|molest|inc[oó]mod|fr[ií]o\b|calor\b|falt[oó]\b|queja|lamentabl|ruido|mal sonido|impuntual|aburrid)/iu',
    'solicitud'=>'/\b(?:(?:env[ií]|compart|mand|facilit|proporcion)\w*\s+(?:\w+\s+){0,3}(?:presentaci|material|diapositiva|informaci|archivo|grabaci|contacto)|quisiera|me gustar[ií]a (?:recibir|tener|obtener)|solicit|necesit|copia de|acceso a)/iu',
    'sugerencia'=>'/\b(suger|recomend|mejorar|ser[ií]a (bueno|mejor|interesante)|deber[ií]an|propon|propuest|incluir|m[aá]s (tiempo|pr[aá]ctic|ejemplos|espacio)|pr[oó]xim)/iu',
    'sin_accion'=>'/^\s*(n\/?a|no|nada|ningun[oa]?|todo (bien|excelente)|excelente|muy bien|bien|bueno|gracias|felicidades|ok)[\s.!]*$|\b(excelente|felicit|gracias|muy (buen[oa]s?|bien|interesante|[uú]til))\b/iu',
];
function agenda_clasificar_comentario(string $comentario,int $estrellas): ?string
{
    $texto=trim($comentario);
    if($texto==='')return null;
    foreach(AGENDA_REGLAS_COMENTARIO as $categoria=>$regla) if(preg_match($regla,$texto)) return $categoria==='sin_accion' && $estrellas<=2 ? 'queja' : $categoria;
    return $estrellas<=2 ? 'queja' : null;
}
/** Clasifica los comentarios aún sin clasificar de un evento. Devuelve cuántos se clasificaron. */
function agenda_autoclasificar(PDO $pdo,int $eventoId): int
{
    $q=$pdo->prepare("SELECT id,comentario,calificacion FROM evento_encuestas WHERE evento_id=? AND categoria IS NULL AND oculto=0 AND TRIM(COALESCE(comentario,''))<>''");$q->execute([$eventoId]);
    $u=$pdo->prepare("UPDATE evento_encuestas SET categoria=?,categoria_por='Automático',categoria_en=NOW() WHERE id=? AND categoria IS NULL");$n=0;
    foreach($q->fetchAll() as $c) if($cat=agenda_clasificar_comentario((string)$c['comentario'],(int)$c['calificacion'])){$u->execute([$cat,(int)$c['id']]);$n+=$u->rowCount();}
    return $n;
}
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
    // Respuestas sincronizadas desde la encuesta de Cengicursos (origen_id) y clasificación del comentario.
    foreach (['origen_id'=>'INT NULL','nombre'=>'VARCHAR(255) NULL','institucion'=>'VARCHAR(255) NULL','email'=>'VARCHAR(255) NULL','telefono'=>'VARCHAR(40) NULL',
        'detalle'=>'VARCHAR(255) NULL','categoria'=>'VARCHAR(20) NULL','categoria_por'=>'VARCHAR(180) NULL','categoria_en'=>'DATETIME NULL',
        'seguimiento_estado'=>'VARCHAR(20) NULL','seguimiento_nota'=>'TEXT NULL','seguimiento_por'=>'VARCHAR(180) NULL','seguimiento_en'=>'DATETIME NULL','oculto'=>'TINYINT(1) NOT NULL DEFAULT 0'] as $col=>$tipo) app_ensure_column($pdo,'evento_encuestas',$col,"{$col} {$tipo}");
    if(!$pdo->query("SHOW INDEX FROM evento_encuestas WHERE Key_name='evento_origen'")->fetch())$pdo->exec('ALTER TABLE evento_encuestas ADD UNIQUE KEY evento_origen(evento_id,origen_id)');
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
/**
 * Asistencia desde el Control QR de Cengicursos (misma instancia MySQL, lectura directa).
 * Cengicursos guarda el vínculo en eventos.transferencia_id; solo se copian los participantes
 * con ingreso registrado. Devuelve el evento de Cengicursos vinculado por evento de transferencia.
 */
function agenda_sincronizar_cursos(PDO $pdo,?int $soloEvento=null): array
{
    if (defined('AGENDA_SIN_CURSOS')) return []; // pruebas con tablas temporales
    $base='`'.str_replace('`','``',app_env_value('CENGICURSOS_DB_NAME','cengi_cursos')).'`';
    try {
        $q=$pdo->prepare("SELECT e.id,e.nombre,e.fecha,e.transferencia_id,COALESCE(SUM(ep.ingreso_en IS NOT NULL),0) ingresos
            FROM {$base}.eventos e LEFT JOIN {$base}.evento_participantes ep ON ep.evento_id=e.id
            WHERE e.transferencia_id IS NOT NULL".($soloEvento?' AND e.transferencia_id=?':'').' GROUP BY e.id');
        $q->execute($soloEvento?[$soloEvento]:[]);$vinculos=$q->fetchAll();
    } catch(PDOException $e) { error_log('Sincronización con Cengicursos: '.$e->getMessage());return []; }
    $out=[];
    $personas=$pdo->prepare("SELECT COALESCE(NULLIF(p.nombre_participantes,''),ep.nombre_invitado) nombre,COALESCE(NULLIF(p.correo_participantes,''),ep.correo_invitado,'') email,
        COALESCE(NULLIF(p.telefono_participantes,''),ep.telefono_invitado,'') telefono,COALESCE(NULLIF(p.puesto_participantes,''),ep.cargo_invitado,'') cargo,
        COALESCE(ip.nombre_ingenios,ie.nombre_ingenios,NULLIF(ep.institucion_invitado,''),'') empresa
        FROM {$base}.evento_participantes ep LEFT JOIN {$base}.participantes p ON p.id=ep.participante_id
        LEFT JOIN {$base}.ingenios ip ON ip.id=p.ingenio_id LEFT JOIN {$base}.ingenios ie ON ie.id=ep.ingenio_id
        WHERE ep.evento_id=? AND ep.ingreso_en IS NOT NULL");
    $existe=$pdo->prepare('SELECT id FROM eventos_transferencia WHERE id=?');
    $contacto=$pdo->prepare('SELECT id FROM contactos WHERE LOWER(TRIM(email))=? AND email<>? LIMIT 1');
    $insertar=$pdo->prepare('INSERT IGNORE INTO evento_asistentes(evento_id,contacto_id,nombre,email,telefono,empresa,cargo,estado,clave) VALUES (?,?,?,?,?,?,?,?,?)');
    $totales=$pdo->prepare("SELECT COUNT(*) participantes,COUNT(DISTINCT NULLIF(empresa,'')) ingenios FROM evento_asistentes WHERE evento_id=?");
    foreach($vinculos as $v) {
        $id=(int)$v['transferencia_id'];$existe->execute([$id]);if(!$existe->fetchColumn())continue;
        $nuevos=0;$personas->execute([$v['id']]);
        foreach($personas->fetchAll() as $p) {
            $d=['nombre'=>mb_substr((string)$p['nombre'],0,180),'email'=>mb_substr($p['email'],0,180),'cargo'=>mb_substr($p['cargo'],0,180),'empresa'=>mb_substr($p['empresa'],0,180),'telefono'=>mb_substr($p['telefono'],0,40)];
            // Un correo mal escrito en la inscripción no impide registrar la asistencia.
            try{$c=directorio_validar($d);}catch(RuntimeException $e){try{$c=directorio_validar(['email'=>'']+$d);}catch(RuntimeException $e){continue;}}
            $contacto->execute([$c['email'],'']);$cid=$contacto->fetchColumn() ?: null;
            $insertar->execute([$id,$cid,$c['nombre'],$c['email'],$c['telefono'],$c['empresa'],$c['cargo'],$cid?'existente':'nuevo',hash('sha256',$c['email'] ?: normalize_text($c['nombre']))]);$nuevos+=$insertar->rowCount();
        }
        $respuestas=agenda_sincronizar_encuestas($pdo,$base,(int)$v['id'],$id);
        $totales->execute([$id]);$t=$totales->fetch();
        $pdo->prepare('UPDATE eventos_transferencia SET sincronizado=NOW(),fuente_asistentes=?,participantes=GREATEST(participantes,?),ingenios=GREATEST(ingenios,?) WHERE id=?')
            ->execute([mb_substr('Control QR de Cengicursos: '.$v['nombre'],0,255),$t['participantes'],$t['ingenios'],$id]);
        $out[$id]=['id'=>(int)$v['id'],'nombre'=>$v['nombre'],'fecha'=>$v['fecha'],'ingresos'=>(int)$v['ingresos'],'nuevos'=>$nuevos,'respuestas'=>$respuestas];
    }
    return $out;
}
/**
 * Respuestas de la encuesta de percepción de Cengicursos con los datos de contacto de quien
 * responde (correo y teléfono del registro). La clasificación del comentario es de Comités y no
 * se sobrescribe. Devuelve cuántas respuestas nuevas se copiaron.
 */
function agenda_sincronizar_encuestas(PDO $pdo,string $base,int $cursosId,int $eventoId): int
{
    try {
        $q=$pdo->prepare("SELECT r.id,r.estrellas,r.mejora,r.logistica,r.permanencia,
                COALESCE(NULLIF(r.nombre,''),NULLIF(p.nombre_participantes,''),ep.nombre_invitado) nombre,
                COALESCE(NULLIF(r.institucion,''),ip.nombre_ingenios,ie.nombre_ingenios,NULLIF(ep.institucion_invitado,'')) institucion,
                COALESCE(NULLIF(r.correo,''),NULLIF(p.correo_participantes,''),NULLIF(ep.correo_invitado,'')) email,
                COALESCE(NULLIF(p.telefono_participantes,''),NULLIF(ep.telefono_invitado,'')) telefono
            FROM {$base}.evento_encuesta_percep r LEFT JOIN {$base}.evento_participantes ep ON ep.id=r.evento_participante_id
            LEFT JOIN {$base}.participantes p ON p.id=ep.participante_id
            LEFT JOIN {$base}.ingenios ip ON ip.id=p.ingenio_id LEFT JOIN {$base}.ingenios ie ON ie.id=ep.ingenio_id
            WHERE r.evento_id=?");
        $q->execute([$cursosId]);$filas=$q->fetchAll();
    } catch(PDOException $e) { error_log('Encuestas desde Cengicursos: '.$e->getMessage());return 0; }
    $permanencia=['completo'=>'Todo el evento','mitad'=>'Más de la mitad','parcial'=>'Menos de la mitad'];
    $guardar=$pdo->prepare('INSERT INTO evento_encuestas(evento_id,origen_id,calificacion,comentario,nombre,institucion,email,telefono,detalle) VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE calificacion=VALUES(calificacion),comentario=VALUES(comentario),nombre=VALUES(nombre),institucion=VALUES(institucion),email=VALUES(email),telefono=VALUES(telefono),detalle=VALUES(detalle)');
    $nuevas=0;
    foreach($filas as $r) {
        $detalle=array_filter([$r['logistica']!==null?'Logística: '.(int)$r['logistica'].' ★':'',isset($permanencia[$r['permanencia']])?'Permanencia: '.$permanencia[$r['permanencia']]:'']);
        $guardar->execute([$eventoId,(int)$r['id'],(int)$r['estrellas'],trim((string)$r['mejora']),
            $r['nombre']?mb_substr($r['nombre'],0,255):null,$r['institucion']?mb_substr($r['institucion'],0,255):null,$r['email']?mb_substr(strtolower($r['email']),0,255):null,
            $r['telefono']?mb_substr($r['telefono'],0,40):null,$detalle?implode(' · ',$detalle):null]);
        $nuevas+=$guardar->rowCount()===1?1:0;
    }
    if($nuevas)agenda_autoclasificar($pdo,$eventoId); // las nuevas llegan con una clasificación propuesta
    return $nuevas;
}
/**
 * Contacto del directorio que probablemente es el mismo asistente aunque tenga otro correo:
 * nombre completo igual, o primer nombre y un apellido iguales. El teléfono no se usa porque
 * suele ser el PBX de la institución y lo comparten varios contactos.
 */
function agenda_coincidencia(string $nombre,array $indice): ?array
{
    $tokens=array_values(array_filter(explode(' ',normalize_text($nombre)),static fn($t)=>mb_strlen($t)>2));
    if (count($tokens)<2) return null;
    $completo=implode(' ',$tokens);$mejor=null;
    foreach ($indice as [$contacto,$otros]) {
        if (implode(' ',$otros)===$completo) return $contacto+['motivo'=>'Mismo nombre'];
        if (!$mejor && $otros && $otros[0]===$tokens[0] && array_intersect(array_slice($tokens,1),array_slice($otros,1))) $mejor=$contacto+['motivo'=>'Mismo nombre y apellido'];
    }
    return $mejor;
}
const AGENDA_CAMPOS_CONTACTO=['email'=>'correo','telefono'=>'teléfono','cargo'=>'cargo','empresa'=>'institución'];
/** Completa los campos vacíos del contacto con los del asistente, sin sobrescribir. Devuelve los campos completados. */
function agenda_completar_contacto(PDO $pdo,int $contacto,array $a): array
{
    $q=$pdo->prepare('SELECT email,telefono,cargo,empresa,area,nivel FROM contactos WHERE id=?');$q->execute([$contacto]);$c=$q->fetch();
    if(!$c)throw new RuntimeException('El contacto ya no existe.');
    $cambios=[];
    foreach(array_keys(AGENDA_CAMPOS_CONTACTO) as $campo) if(trim((string)$c[$campo])==='' && trim((string)($a[$campo] ?? ''))!=='') $cambios[$campo]=trim((string)$a[$campo]);
    if(isset($cambios['email'])){$q=$pdo->prepare('SELECT COUNT(*) FROM contactos WHERE LOWER(TRIM(email))=? AND id<>?');$q->execute([strtolower($cambios['email']),$contacto]);if($q->fetchColumn())unset($cambios['email']);}
    $completados=array_values(array_intersect_key(AGENDA_CAMPOS_CONTACTO,$cambios));
    if(isset($cambios['cargo'])){[$area,$nivel]=directorio_clasificar($cambios['cargo']);if(!$c['area']&&$area)$cambios['area']=$area;if(!$c['nivel']&&$nivel)$cambios['nivel']=$nivel;}
    if($cambios)$pdo->prepare('UPDATE contactos SET '.implode(',',array_map(static fn($k)=>"{$k}=?",array_keys($cambios))).' WHERE id=?')->execute([...array_values($cambios),$contacto]);
    return $completados;
}
function agenda_datos(PDO $pdo,array $user,string $tipo): array
{
    $cursos=$tipo==='eventos'?agenda_sincronizar_cursos($pdo):[];
    $rows=$pdo->query('SELECT * FROM '.agenda_tabla($tipo).' ORDER BY COALESCE(plan_mes,LEFT(fecha,7)),id')->fetchAll();
    $comites=$pdo->query('SELECT id,nombre,activo,logo FROM comites ORDER BY nombre')->fetchAll();
    foreach ($comites as &$c) $c['gestionar']=agenda_puede($pdo,$user,'reuniones',$c['id']); unset($c);
    $asistentes=[];$extras=[];
    $consulta=$tipo==='eventos'?'SELECT * FROM evento_asistentes ORDER BY nombre':'SELECT * FROM reunion_asistentes';
    foreach ($pdo->query($consulta) as $a) $asistentes[$a[$tipo==='eventos'?'evento_id':'reunion_id']][]=$tipo==='eventos'?$a:(int)$a['contacto_id'];
    // Los comentarios ocultos no cuentan en promedios, conteos ni informes; se listan aparte para restaurarlos.
    $ocultos=[];
    foreach ($pdo->query($tipo==='eventos'?'SELECT * FROM evento_encuestas ORDER BY id':'SELECT * FROM acuerdos WHERE reunion_id IS NOT NULL ORDER BY id') as $a) {
        if ($tipo==='eventos' && !empty($a['oculto'])) $ocultos[$a['evento_id']][]=$a; else $extras[$a[$tipo==='eventos'?'evento_id':'reunion_id']][]=$a;
    }
    $contactos=$pdo->query('SELECT id,nombre,email,cargo,empresa,telefono,estado FROM contactos ORDER BY nombre')->fetchAll();
    $porEmail=[]; foreach ($contactos as $c) if ($c['email']) $porEmail[strtolower(trim($c['email']))]=(int)$c['id'];
    $porId=array_column($contactos,null,'id');
    $indiceNombres=[]; if ($tipo==='eventos') foreach ($contactos as $c) $indiceNombres[]=[['id'=>(int)$c['id'],'nombre'=>$c['nombre'],'email'=>$c['email'],'empresa'=>$c['empresa']],array_values(array_filter(explode(' ',normalize_text((string)$c['nombre'])),static fn($t)=>mb_strlen($t)>2))];
    foreach ($rows as &$r) {
        $r['id']=(int)$r['id'];$r['estado']=agenda_estado($r);$r['historial']=json_decode((string)$r['reprogramaciones'],true) ?: [];
        $r['asistentes']=$asistentes[$r['id']] ?? []; $r[$tipo==='eventos'?'encuesta':'acuerdos']=$extras[$r['id']] ?? [];
        if ($tipo==='eventos') { $r['cursos']=$cursos[$r['id']] ?? null; $r['encuesta_ocultos']=$ocultos[$r['id']] ?? []; }
        if ($tipo==='eventos') foreach ($r['asistentes'] as &$a) {
            $a['id']=(int)$a['id'];
            if ($a['estado']==='nuevo' && isset($porEmail[strtolower(trim((string)$a['email']))])) { $a['estado']='existente';$a['contacto_id']=$porEmail[strtolower(trim((string)$a['email']))]; }
            // Búsqueda previa en el directorio para decidir si se vincula o se crea al integrar.
            $a['coincidencia']=$a['estado']==='nuevo'?agenda_coincidencia((string)$a['nombre'],$indiceNombres):null;
            // Ya en el directorio: qué datos trae el asistente que al contacto le faltan.
            $c=$a['contacto_id']?($porId[(int)$a['contacto_id']] ?? null):null;$a['contacto']=null;
            if ($c && in_array($a['estado'],['existente','integrado'],true)) {
                $faltan=[];foreach(AGENDA_CAMPOS_CONTACTO as $campo=>$label) if(trim((string)$c[$campo])==='' && trim((string)$a[$campo])!=='') $faltan[]=['campo'=>$label,'valor'=>$a[$campo]];
                $a['contacto']=['id'=>(int)$c['id'],'nombre'=>$c['nombre'],'email'=>$c['email'],'empresa'=>$c['empresa'],'faltan'=>$faltan,
                    'otro_correo'=>trim((string)$a['email'])!=='' && trim((string)$c['email'])!=='' && strtolower(trim((string)$a['email']))!==strtolower(trim((string)$c['email']))];
            }
        } unset($a);
        $r['gestionar']=agenda_puede($pdo,$user,$tipo,$r['comite_id'] ?? null);
    } unset($r);
    return ['tipo'=>$tipo,'registros'=>$rows,'comites'=>$comites,'contactos'=>$contactos,'estados'=>AGENDA_ESTADOS,'categorias'=>AGENDA_CATEGORIAS_COMENTARIO,'seguimientos'=>AGENDA_SEGUIMIENTO,'requieren_seguimiento'=>AGENDA_REQUIEREN_SEGUIMIENTO,'meses'=>AGENDA_MESES,'fijos'=>AGENDA_FIJOS,'hoy'=>date('Y-m-d'),'gestionar'=>agenda_puede($pdo,$user,$tipo),'gestionar_contactos'=>can_manage_resource($user,'contactos'),'enviar'=>can_manage_resource($user,'correos'),'csrf'=>$_SESSION['agenda_csrf']];
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
    } elseif($accion==='cursos_sincronizar') {
        $v=agenda_sincronizar_cursos($pdo,$id)[$id] ?? null;
        if(!$v)throw new RuntimeException('Este evento no está vinculado con un evento del Control QR de Cengicursos.');
        return "{$v['nuevos']} asistentes y {$v['respuestas']} respuestas de evaluación nuevas desde Cengicursos ({$v['ingresos']} con ingreso registrado).";
    } elseif($accion==='encuesta_categoria') {
        // Una o varias respuestas (acción en lote); confirmar una clasificación automática la vuelve manual.
        $categoria=(string)($d['categoria'] ?? '');if($categoria!=='' && !isset(AGENDA_CATEGORIAS_COMENTARIO[$categoria]))throw new RuntimeException('Clasificación no válida.');
        $ids=array_unique(array_map('intval',(array)($d['respuestas'] ?? [$d['respuesta'] ?? 0])));$n=0;
        $q=$pdo->prepare('UPDATE evento_encuestas SET categoria=?,categoria_por=?,categoria_en=? WHERE id=? AND evento_id=?');
        foreach($ids as $rid){$q->execute([$categoria ?: null,$categoria?mb_substr((string)$user['nombre'],0,180):null,$categoria?date('Y-m-d H:i:s'):null,$rid,$id]);$n+=$q->rowCount();}
        return $n===1?'Comentario clasificado.':"{$n} comentarios clasificados.";
    } elseif($accion==='encuesta_seguimiento') {
        // Estado del seguimiento y nota de retroalimentación (la nota solo cambia si se envía).
        $estado=(string)($d['estado'] ?? '');if(!isset(AGENDA_SEGUIMIENTO[$estado]))throw new RuntimeException('Estado de seguimiento no válido.');
        $ids=array_unique(array_map('intval',(array)($d['respuestas'] ?? [$d['respuesta'] ?? 0])));$n=0;
        $nota=array_key_exists('nota',$d)?mb_substr(trim((string)$d['nota']),0,2000):null;
        $q=$pdo->prepare('UPDATE evento_encuestas SET seguimiento_estado=?,seguimiento_nota=IF(?,?,seguimiento_nota),seguimiento_por=?,seguimiento_en=NOW() WHERE id=? AND evento_id=?');
        foreach($ids as $rid){$q->execute([$estado,$nota!==null?1:0,$nota!==null&&$nota!==''?$nota:null,mb_substr((string)$user['nombre'],0,180),$rid,$id]);$n+=$q->rowCount();}
        return $n===1?'Seguimiento guardado.':"Seguimiento actualizado en {$n} comentarios.";
    } elseif($accion==='encuesta_ocultar') {
        // Ocultar es reversible y sirve para todas las respuestas (las de Cengicursos no se borran: volverían al sincronizar).
        $ocultar=!empty($d['ocultar'])?1:0;$n=0;
        $q=$pdo->prepare('UPDATE evento_encuestas SET oculto=? WHERE id=? AND evento_id=?');
        foreach(array_unique(array_map('intval',(array)($d['respuestas'] ?? []))) as $rid){$q->execute([$ocultar,$rid,$id]);$n+=$q->rowCount();}
        return $ocultar?"{$n} comentario(s) ocultos: ya no cuentan en el promedio ni en el informe.":"{$n} comentario(s) restaurados.";
    } elseif($accion==='encuesta_eliminar') {
        // Solo las importadas por archivo; las de la encuesta de Cengicursos se ocultan.
        $ids=array_unique(array_map('intval',(array)($d['respuestas'] ?? [])));$n=0;$sincronizadas=0;
        $q=$pdo->prepare('DELETE FROM evento_encuestas WHERE id=? AND evento_id=? AND origen_id IS NULL');
        foreach($ids as $rid){$q->execute([$rid,$id]);$q->rowCount()?$n++:$sincronizadas++;}
        return "{$n} comentario(s) eliminados.".($sincronizadas?" {$sincronizadas} vienen de la encuesta de Cengicursos y no se pueden eliminar: ocúltalos.":'');
    } elseif($accion==='encuesta_autoclasificar') {
        $n=agenda_autoclasificar($pdo,$id);
        return $n?"{$n} comentarios clasificados automáticamente. Revísalos y confírmalos.":'No había comentarios que se pudieran clasificar automáticamente.';
    } elseif(in_array($accion,['integrar','descartar'],true)) {
        if(!can_manage_resource($user,'contactos'))throw new RuntimeException('Necesitas permiso para gestionar contactos.');
        $cid=(string)($d['comite_id'] ?? '');if($cid!=='')catalogo_fila($pdo,$cid);
        // Cada fila revisada trae su acción y los datos corregidos en pantalla; "ids" es el formato anterior.
        $filas=isset($d['filas'])?(array)$d['filas']:array_map(static fn($aid)=>['id'=>$aid,'accion'=>$accion==='descartar'?'descartar':'crear'],(array)($d['ids'] ?? []));
        $hechos=['crear'=>0,'vincular'=>0,'descartar'=>0,'omitir'=>0];
        foreach($filas as $f) {
            $f=(array)$f;$aid=(int)($f['id'] ?? 0);$op=(string)($f['accion'] ?? '');if(!isset($hechos[$op]))continue;
            $q=$pdo->prepare("SELECT * FROM evento_asistentes WHERE id=? AND evento_id=? AND estado='nuevo'");$q->execute([$aid,$id]);$a=$q->fetch();if(!$a)continue;
            if($op==='descartar'){$pdo->prepare("UPDATE evento_asistentes SET estado='descartado' WHERE id=?")->execute([$aid]);$hechos[$op]++;continue;}
            $editado=[];foreach(['nombre','email','telefono','cargo','empresa'] as $campo)$editado[$campo]=array_key_exists($campo,$f)?(string)$f[$campo]:(string)$a[$campo];
            try{$v=directorio_validar($editado);}catch(RuntimeException $e){throw new RuntimeException($a['nombre'].': '.$e->getMessage());}
            $pdo->prepare('UPDATE evento_asistentes SET nombre=?,email=?,telefono=?,cargo=?,empresa=? WHERE id=?')->execute([$v['nombre'],$v['email'],$v['telefono'],$v['cargo'],$v['empresa'],$aid]);
            $a=array_merge($a,array_intersect_key($v,$editado));$hechos[$op]++;
            if($op==='omitir')continue;
            $q=$pdo->prepare('SELECT id FROM contactos WHERE LOWER(TRIM(email))=? AND email<>? LIMIT 1');$q->execute([strtolower(trim((string)$a['email'])),'']);$contacto=$q->fetchColumn();
            // Posible coincidencia confirmada en pantalla: se vincula al contacto existente en lugar de duplicarlo.
            $vincular=$op==='vincular'?(int)($f['contacto_id'] ?? 0):(int)(((array)($d['vincular'] ?? []))[$aid] ?? 0);
            if($vincular){$q=$pdo->prepare('SELECT id FROM contactos WHERE id=?');$q->execute([$vincular]);$contacto=$q->fetchColumn();if(!$contacto)throw new RuntimeException('El contacto elegido para vincular ya no existe.');}
            if($contacto)agenda_completar_contacto($pdo,(int)$contacto,$a);
            if(!$contacto){$contacto=directorio_guardar($pdo,array_intersect_key($a,array_flip(['nombre','email','telefono','cargo','empresa']))+['comites'=>$cid?[$cid]:[]],(string)$user['nombre']);$pdo->prepare("UPDATE contactos SET origen_tipo='evento',origen_detalle=? WHERE id=?")->execute([mb_substr($r['nombre'].' · '.($r['fecha'] ?: $r['plan_mes']),0,255),$contacto]);}
            elseif($cid)$pdo->prepare('INSERT IGNORE INTO contacto_comite VALUES (?,?)')->execute([$contacto,$cid]);
            $pdo->prepare("UPDATE evento_asistentes SET contacto_id=?,estado='integrado' WHERE id=?")->execute([$contacto,$aid]);
        }
        $partes=array_filter(["{$hechos['crear']} creados en el directorio","{$hechos['vincular']} vinculados a contactos existentes","{$hechos['descartar']} descartados","{$hechos['omitir']} guardados como pendientes"],static fn($t)=>$t[0]!=='0');
        return $partes?ucfirst(implode(' · ',$partes)).'.':'No había asistentes por integrar.';
    } elseif($accion==='completar_contacto') {
        if(!can_manage_resource($user,'contactos'))throw new RuntimeException('Necesitas permiso para gestionar contactos.');
        $completados=0;$sinVinculo=0;
        foreach(array_unique(array_map('intval',(array)($d['asistentes'] ?? [$d['asistente'] ?? 0]))) as $aid) {
            $q=$pdo->prepare('SELECT * FROM evento_asistentes WHERE id=? AND evento_id=?');$q->execute([$aid,$id]);$a=$q->fetch();if(!$a)continue;
            $contacto=(int)$a['contacto_id'];
            if(!$contacto && trim((string)$a['email'])!==''){$q=$pdo->prepare('SELECT id FROM contactos WHERE LOWER(TRIM(email))=? LIMIT 1');$q->execute([strtolower(trim((string)$a['email']))]);$contacto=(int)$q->fetchColumn();}
            if(!$contacto){$sinVinculo++;continue;}
            $completados+=agenda_completar_contacto($pdo,$contacto,$a)?1:0;
            $pdo->prepare('UPDATE evento_asistentes SET contacto_id=? WHERE id=?')->execute([$contacto,(int)$a['id']]);
        }
        return ($completados?"{$completados} contacto(s) completado(s).":'Los contactos ya tenían esos datos.').($sinVinculo?" {$sinVinculo} sin contacto vinculado.":'');
    } elseif($accion==='encuesta_cargar') {
        $n=0;$invalidos=0;foreach(agenda_leer($files['lista'] ?? []) as $row) {
            $valor=$row['calificacion 1 a 5'] ?? $row['calificacion'] ?? $row['puntuacion'] ?? '';
            if(!preg_match('/^[1-5]$/',(string)$valor)){$invalidos++;continue;}
            $pdo->prepare('INSERT INTO evento_encuestas(evento_id,calificacion,comentario) VALUES (?,?,?)')->execute([$id,(int)$valor,trim((string)($row['comentario'] ?? ''))]);$n++;
        }agenda_autoclasificar($pdo,$id);return "{$n} respuestas agregadas; {$invalidos} calificaciones inválidas omitidas.";
    } elseif($accion==='encuesta_quitar')$pdo->prepare('DELETE FROM evento_encuestas WHERE evento_id=? AND origen_id IS NULL')->execute([$id]); // las de Cengicursos se conservan con su clasificación
    else throw new RuntimeException('Acción no válida.');
    return 'Cambios guardados.';
}
