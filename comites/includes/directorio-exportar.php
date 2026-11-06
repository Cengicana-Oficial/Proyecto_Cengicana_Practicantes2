<?php
declare(strict_types=1);
require_once __DIR__.'/marca.php';
const DIRECTORIO_COLUMNAS = ['nombre'=>'Nombre completo','cargo'=>'Cargo','empresa'=>'Empresa / ingenio','area'=>'Área','nivel'=>'Nivel','email'=>'Correo','telefono'=>'Teléfono','comites'=>'Comités','estado'=>'Estado','origen'=>'Origen','origen_detalle'=>'Detalle del origen','origen_fecha'=>'Fecha de alta','ultima'=>'Última interacción'];

function directorio_valor(array $c,string $campo,array $comites): string
{
    if ($campo==='comites') return implode('; ',array_map(static fn($id)=>$comites[$id] ?? $id,$c['comites']));
    if ($campo==='origen') return DIRECTORIO_ORIGENES[$c['origen_tipo']] ?? 'Base existente';
    if ($campo==='ultima') return $c['ultima'] ? $c['ultima']['fecha'].' · '.$c['ultima']['titulo'] : '';
    return (string)($c[$campo] ?? '');
}

function directorio_seleccionar(array $datos,array $d): array
{
    $ids=array_unique(array_map('intval',(array)($d['ids'] ?? [])));
    return array_values(array_filter($datos['contactos'],static fn($c)=>in_array($c['id'],$ids,true)));
}

function directorio_reporte(array $lista,array $d,array $comites): string
{
    $cols=array_values(array_intersect(array_keys(DIRECTORIO_COLUMNAS),(array)($d['cols'] ?? ['nombre','cargo','empresa','area','email','telefono'])));
    if (!in_array('nombre',$cols,true)) array_unshift($cols,'nombre');
    $marca=marca_color($d['color'] ?? 'verde');
    $titulo=e(mb_substr((string)($d['titulo'] ?? 'Directorio de contactos'),0,180));
    $logo=(string)($d['logo'] ?? '');
    if (!preg_match('~^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~',$logo) || strlen($logo)>1500000) $logo='';
    $grupos=[]; $agrupar=(string)($d['agrupar'] ?? '');
    foreach ($lista as $c) {
        $grupo=in_array($agrupar,['area','nivel','empresa','comites'],true)?directorio_valor($c,$agrupar,$comites):'';
        $grupos[$grupo][]=$c;
    }
    if ($agrupar!=='') ksort($grupos,SORT_NATURAL|SORT_FLAG_CASE);
    $html='<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'.$titulo.'</title><style>body{font:12px "DejaVu Sans",Arial,sans-serif;margin:28px}h1{font-size:24px;margin:0}header{margin-bottom:20px}.cab{width:100%;border-collapse:collapse;margin:0 0 10px}.cab td{border:0;padding:0;vertical-align:top}.cab img{max-height:55px;max-width:170px}p{white-space:pre-wrap}table.lista{border-collapse:collapse;width:100%;margin:12px 0 24px;table-layout:fixed}table.lista th,table.lista td{text-align:left;padding:8px;border-bottom:1px solid '.MARCA_GRIS.';word-wrap:break-word}table.lista th{font-size:11px}table.lista tr:nth-child(even) td{background:#F7F8F6}h2{font-size:15px;border-left:4px solid '.$marca['color'].';padding-left:8px}footer{font-size:10px;color:#5F666C;border-top:1px solid '.MARCA_GRIS.';padding-top:6px}@media print{body{margin:10mm}thead{display:table-header-group}tr{page-break-inside:avoid}}'.marca_css($marca).'.cab td{border:0}</style></head><body><header><table class="cab"><tr><td><h1>'.$titulo.'</h1><p>CENGICAÑA · '.count($lista).' contactos · '.date('d/m/Y').'</p><p>'.e(mb_substr((string)($d['descripcion'] ?? ''),0,3000)).'</p></td><td style="text-align:right">'.($logo?'<img alt="Logo" src="'.e($logo).'">':marca_logo()).'</td></tr></table>'.marca_franja().'</header>';
    foreach ($grupos as $grupo=>$contactos) {
        if ($agrupar!=='') $html.='<h2>'.e($grupo ?: 'Sin clasificar').'</h2>';
        $html.='<table class="lista"><thead><tr>';
        foreach ($cols as $col) $html.='<th>'.e(DIRECTORIO_COLUMNAS[$col]).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($contactos as $c) { $html.='<tr>'; foreach ($cols as $col) $html.='<td>'.e(directorio_valor($c,$col,$comites)).'</td>'; $html.='</tr>'; }
        $html.='</tbody></table>';
    }
    return $html.'<footer>Directorio de contactos · CENGICAÑA</footer></body></html>';
}

function directorio_excel(array $hojas): string
{
    directorio_dependencias();
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    foreach ($hojas as $i=>$hoja) {
        $sheet=$i===0?$book->getActiveSheet():$book->createSheet(); $sheet->setTitle($hoja['nombre']);
        foreach ($hoja['filas'] as $r=>$fila) foreach (array_values($fila) as $c=>$valor) {
            // Texto explícito: los valores de usuarios nunca se ejecutan como fórmulas.
            $sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c+1).($r+1),(string)$valor,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        for ($c=1;$c<=count($hoja['filas'][0] ?? []);$c++) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setWidth(25);
    }
    $tmp=tempnam(sys_get_temp_dir(),'directorio-');
    try { (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp); return file_get_contents($tmp); }
    finally { $book->disconnectWorksheets(); unlink($tmp); }
}

function directorio_pdf(string $html,string $orientacion='landscape'): string
{
    directorio_dependencias();
    $options=new \Dompdf\Options(); $options->set('isRemoteEnabled',false); $options->set('isPhpEnabled',false);
    $pdf=new \Dompdf\Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4',$orientacion); $pdf->render(); return $pdf->output();
}

function directorio_enviar(PDO $pdo,array $user,array $d,string $html,string $contexto='Directorio de contactos'): void
{
    $para=array_values(array_unique(array_filter(array_map('trim',preg_split('/[;,\s]+/',(string)($d['para'] ?? ''))))));
    if (!$para || count($para)>50) throw new RuntimeException('Indica entre 1 y 50 destinatarios.');
    foreach ($para as $email) if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Destinatario no válido: '.$email);
    $asunto=trim((string)($d['asunto'] ?? '')); $cuerpo=trim((string)($d['cuerpo'] ?? ''));
    if ($asunto==='' || mb_strlen($asunto)>220 || mb_strlen($cuerpo)>15000) throw new RuntimeException('Revisa el asunto y el mensaje.');
    foreach (['MAIL_HOST','MAIL_USERNAME','MAIL_PASSWORD','MAIL_FROM_ADDRESS'] as $key) {
        if (in_array(app_env_value($key),['','change-me'],true)) throw new RuntimeException('Configura las credenciales SMTP en deploy/env/login.env para enviar correos. La lista aún no se ha enviado.');
    }
    directorio_dependencias();
    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP(); $mail->Host=app_env_value('MAIL_HOST'); $mail->Port=(int)app_env_value('MAIL_PORT','2525');
    $mail->SMTPAuth=true; $mail->Username=app_env_value('MAIL_USERNAME'); $mail->Password=app_env_value('MAIL_PASSWORD');
    $mail->SMTPSecure=$mail->Port===465?'ssl':'tls'; $mail->CharSet='UTF-8'; $mail->Timeout=20;
    $mail->setFrom(app_env_value('MAIL_FROM_ADDRESS'),app_env_value('MAIL_FROM_NAME','CENGICAÑA'));
    foreach ($para as $email) $mail->addBCC($email);
    $mail->Subject=$asunto; $mail->Body=$cuerpo;
    $mail->addStringAttachment(directorio_pdf($html),$contexto==='Directorio de contactos'?'directorio_contactos.pdf':'planificacion.pdf','base64','application/pdf');
    try { $mail->send(); } catch (\PHPMailer\PHPMailer\Exception $e) { error_log('Directorio SMTP: '.$e->getMessage()); throw new RuntimeException('El servidor SMTP no confirmó el envío. Revisa la configuración antes de reintentar.'); }
    $pdo->prepare('INSERT INTO correos_enviados(asunto,cuerpo,destinatarios,grupo_destino,enviado_por) VALUES (?,?,?,?,?)')->execute([$asunto,$cuerpo,json_encode($para),$contexto,$user['nombre']]);
}

// ---------------------------------------------------------------- Reporte de participacion

/** Consolidados de participacion de una lista de contactos (ya con 'participacion'). */
function directorio_participacion_resumen(array $lista,array $comites): array
{
    $grupo=static function(array $cs): array {
        $p=array_column($cs,'participacion'); $medibles=array_filter($p,static fn($x)=>$x['nivel']!=='sin_comite');
        $conv=array_sum(array_column($p,'convocadas')); $asis=array_sum(array_column($p,'asistidas'));
        $evPos=array_sum(array_column($p,'eventos_posibles')); $evAsis=array_sum(array_map(static fn($x)=>min($x['eventos'],max($x['eventos_posibles'],$x['eventos'])),$p));
        return ['n'=>count($cs),'medibles'=>count($medibles),'indice'=>$medibles?array_sum(array_column($medibles,'indice'))/count($medibles):null,
            'asistencia'=>$conv?$asis/$conv*100:null,'eventos_posibles'=>$evPos,'asistencia_eventos'=>$evPos?min(100,$evAsis/$evPos*100):null,
            'con_eventos'=>count(array_filter($p,static fn($x)=>$x['eventos']>0)),'activos'=>count(array_filter($p,static fn($x)=>in_array($x['nivel'],['muy_activo','activo'],true))),
            'inactivos'=>count(array_filter($p,static fn($x)=>$x['nivel']==='inactivo')),'eventos'=>array_sum(array_column($p,'eventos')),
            'articulos'=>array_sum(array_column($p,'autor'))+array_sum(array_column($p,'revisor')),'pasos'=>array_sum(array_column($p,'pasos')),
            'pasos_completados'=>array_sum(array_column($p,'pasos_completados')),'pasos_vencidos'=>array_sum(array_column($p,'pasos_vencidos'))];
    };
    $agrupar=static function(callable $clave) use ($lista,$grupo): array {
        $g=[]; foreach ($lista as $c) foreach ((array)$clave($c) as $k) $g[$k][]=$c;
        $r=array_map($grupo,$g); uasort($r,static fn($a,$b)=>($b['indice']??-1)<=>($a['indice']??-1)); return $r;
    };
    // Empresas escritas distinto (mayusculas, tildes) se agrupan con la primera grafia.
    $canon=[];
    $niveles=array_fill_keys(array_keys(DIRECTORIO_PARTICIPACION),0);
    foreach ($lista as $c) $niveles[$c['participacion']['nivel']]++;
    $porComite=[];
    foreach ($lista as $c) foreach ($c['participacion']['comites'] as $g) {
        if (!$g['convocadas']) continue;
        $k=$comites[$g['id']] ?? $g['id']; $porComite[$k]??=['integrantes'=>0,'convocadas'=>0,'asistidas'=>0,'sin_asistir'=>0];
        $porComite[$k]['integrantes']++; $porComite[$k]['convocadas']+=$g['convocadas']; $porComite[$k]['asistidas']+=$g['asistidas']; if (!$g['asistidas']) $porComite[$k]['sin_asistir']++;
    }
    foreach ($porComite as &$g) $g['pct']=$g['asistidas']/$g['convocadas']*100;
    unset($g); uasort($porComite,static fn($a,$b)=>$b['pct']<=>$a['pct']);
    $medibles=array_values(array_filter($lista,static fn($c)=>$c['participacion']['nivel']!=='sin_comite'));
    usort($medibles,static fn($a,$b)=>$b['participacion']['indice']<=>$a['participacion']['indice']);
    $atencion=array_values(array_filter($lista,static fn($c)=>(bool)array_filter($c['participacion']['alertas'],static fn($a)=>$a[0]==='mejorar')));
    usort($atencion,static fn($a,$b)=>$a['participacion']['indice']<=>$b['participacion']['indice']);
    return ['general'=>$grupo($lista),'niveles'=>$niveles,'perfiles'=>$agrupar(static fn($c)=>$c['participacion']['perfil_nombre']),
        'empresas'=>$agrupar(static function($c) use (&$canon) { $e=trim((string)$c['empresa']); return $e==='' ? 'Sin empresa' : ($canon[normalize_text($e)] ??= $e); }),'areas'=>$agrupar(static fn($c)=>$c['area'] ?: 'Sin área'),
        'comites'=>$porComite,'top'=>array_slice($medibles,0,10),'atencion'=>$atencion];
}

/**
 * Conclusiones y acciones sugeridas en lenguaje claro para el informe a Direccion,
 * a partir de los consolidados. Devuelve [conclusiones, acciones] (HTML con <b>).
 */
function directorio_participacion_conclusiones(array $r): array
{
    $g=$r['general']; $c=[]; $a=[]; $n=max(1,$g['n']);
    $pct=static fn($v)=>number_format((float)$v,0).'%';
    if ($g['asistencia']!==null) {
        $c[]='La asistencia a las reuniones de comités fue de <b>'.$pct($g['asistencia']).'</b>'.($g['asistencia']>=70?' (buen nivel).':($g['asistencia']>=50?' (nivel medio: hay margen de mejora).':' (nivel bajo: requiere atención).'));
    } else {
        $c[]='Aún no hay reuniones de comité realizadas con asistencia registrada para los contactos analizados.';
    }
    $activos=$r['niveles']['muy_activo']+$r['niveles']['activo'];
    $c[]='<b>'.$activos.' de '.$g['n'].'</b> contactos ('.$pct($activos/$n*100).') están activos o muy activos; <b>'.$r['niveles']['inactivo'].'</b> están inactivos'.($r['niveles']['ocasional']?' y '.$r['niveles']['ocasional'].($r['niveles']['ocasional']===1?' participa':' participan').' de forma ocasional':'').'.';
    if (count($r['comites'])>=2) {
        $mejor=array_key_first($r['comites']); $peor=array_key_last($r['comites']);
        $c[]='El comité con mayor asistencia es <b>'.e((string)$mejor).'</b> ('.$pct($r['comites'][$mejor]['pct']).') y el de menor asistencia es <b>'.e((string)$peor).'</b> ('.$pct($r['comites'][$peor]['pct']).').';
        if ($r['comites'][$peor]['pct']<50) $a[]='Revisar con la coordinación de <b>'.e((string)$peor).'</b> la convocatoria, el horario y la agenda de sus reuniones (asistencia de '.$pct($r['comites'][$peor]['pct']).').';
    } elseif (count($r['comites'])===1) {
        $k=array_key_first($r['comites']);
        $c[]='En <b>'.e((string)$k).'</b> se registraron '.$r['comites'][$k]['asistidas'].' asistencias de '.$r['comites'][$k]['convocadas'].' posibles ('.$pct($r['comites'][$k]['pct']).').';
    }
    $empresas=array_filter($r['empresas'],static fn($x)=>$x['indice']!==null);
    if (count($empresas)>=2) {
        $mejor=array_key_first($empresas); $peor=array_key_last($empresas);
        $c[]='Por empresa, la mayor participación es de <b>'.e((string)$mejor).'</b> (índice '.number_format($empresas[$mejor]['indice'],0).') y la menor de <b>'.e((string)$peor).'</b> (índice '.number_format($empresas[$peor]['indice'],0).').';
        if ($empresas[$peor]['indice']<30) $a[]='Contactar a <b>'.e((string)$peor).'</b> para confirmar a sus representantes en los comités o designar suplentes.';
    }
    if ($g['eventos_posibles']) {
        $c[]='<b>'.$g['con_eventos'].'</b> contactos asistieron a al menos un evento de transferencia (asistencia a eventos de '.$pct($g['asistencia_eventos']).'; es opcional y suma al índice).';
        if ($g['asistencia_eventos']!==null && $g['asistencia_eventos']<30) $a[]='Promover los eventos de transferencia entre los integrantes de comités: hoy la asistencia a eventos es de '.$pct($g['asistencia_eventos']).'.';
    }
    $perf=$r['perfiles'];
    if (count($perf)===2 && ($x=reset($perf))['asistencia']!==null && ($y=end($perf))['asistencia']!==null) {
        $c[]='Asistencia a comités: <b>'.e((string)array_key_first($perf)).'</b> '.$pct($x['asistencia']).' frente a <b>'.e((string)array_key_last($perf)).'</b> '.$pct($y['asistencia']).'.';
    }
    if ($r['atencion']) {
        $c[]='<b>'.count($r['atencion']).'</b> contacto(s) requieren seguimiento por inasistencia (ver «Requieren seguimiento»).';
        $a[]='Contactar a los '.count($r['atencion']).' integrantes con inasistencia para confirmar su participación; si no pueden continuar, solicitar su reemplazo al ingenio o área correspondiente.';
    }
    if ($r['niveles']['sin_comite']) $a[]='Programar la primera reunión de los comités que aún no han sesionado ('.$r['niveles']['sin_comite'].' contacto(s) sin actividad para medir).';
    if ($g['asistencia']!==null && $g['asistencia']>=70 && !$r['atencion']) $a[]='Mantener la dinámica actual: la participación es buena y no hay contactos con alertas.';
    return [$c,$a];
}

/** Reporte de participacion en HTML (vista, PDF o impresion). */
function directorio_participacion_html(array $lista,array $d,array $comites): string
{
    $r=directorio_participacion_resumen($lista,$comites); $g=$r['general'];
    $titulo=e(mb_substr((string)($d['titulo'] ?? '') ?: 'Reporte de participación de contactos',0,180));
    $num=static fn($v,int $dec=0)=>$v===null?'—':number_format((float)$v,$dec);
    $tabla=static function(array $enc,array $filas): string {
        if (!$filas) return '<p class="vacio">Sin datos.</p>';
        // Columnas numericas (cifras, %, x/y, barras) a la derecha; texto a la izquierda.
        $esNum=static fn($v)=>is_int($v)||is_float($v)||str_contains((string)$v,'class="barra"')||preg_match('/^[\d.,\/%—\s()venc.]+$/u',strip_tags((string)$v));
        $num=[]; foreach (array_values($filas[0]) as $i=>$v) $num[$i]=$i>0 && $esNum($v);
        $h='<table><thead><tr>'; foreach ($enc as $i=>$t) $h.='<th'.(!empty($num[$i])?' class="n"':'').'>'.e($t).'</th>'; $h.='</tr></thead><tbody>';
        foreach ($filas as $f) { $h.='<tr>'; foreach (array_values($f) as $i=>$v) $h.='<td'.(!empty($num[$i])?' class="n"':'').'>'.$v.'</td>'; $h.='</tr>'; }
        return $h.'</tbody></table>';
    };
    $barra=static fn($pct)=>$pct===null?'—':'<span style="white-space:nowrap"><span class="barra" style="width:60px"><span style="width:'.max(1,min(60,(int)round($pct*0.6))).'px"></span></span> '.number_format($pct,0).'%</span>';
    $filasGrupo=static fn(array $grupos)=>array_map(static fn($k,$x)=>[e((string)$k),$x['n'],$num($x['indice']),$barra($x['asistencia']),$barra($x['asistencia_eventos']),$x['con_eventos'],$x['activos'],$x['inactivos']],array_keys($grupos),$grupos);
    $encGrupo=['','Contactos','Índice prom.','Asistencia a comités','Asistencia a eventos','Fueron a eventos','Activos','Inactivos'];
    $color=['muy_activo'=>MARCA_OSCURO,'activo'=>'#73BC25','ocasional'=>'#FFCC00','inactivo'=>'#FF6B00','solo_eventos'=>'#A3D300','sin_comite'=>MARCA_GRIS];
    $total=max(1,$g['n']);
    $u=DIRECTORIO_UMBRALES; $bono=DIRECTORIO_BONO_EVENTOS;

    $h='<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'.$titulo.'</title><style>'
        .'@page{margin:16mm 12mm}body{font:10.5px "DejaVu Sans",Arial,sans-serif;color:#334155;margin:0}@media screen{body{max-width:960px;margin:24px auto;padding:0 16px}}'
        .'header{background:'.MARCA_OSCURO.';color:#fff;padding:18px 20px 14px;border-radius:8px 8px 0 0}header h1{margin:0 0 4px;font-size:20px}header p{margin:0;color:#EEF7E4}header td{border:0;padding:0;background:none!important;vertical-align:top}'
        .'h2{font-size:13px;color:'.MARCA_OSCURO.';margin:18px 0 6px;border-bottom:2px solid #EEF7E4;padding-bottom:3px;page-break-after:avoid}'
        .'.kpis{width:100%;border-collapse:separate;border-spacing:6px;margin-top:10px}.kpis td{border:1px solid '.MARCA_GRIS.';border-top:3px solid #73BC25;border-radius:6px;padding:7px 9px;width:25%;background:#fff}.kpis b{display:block;font-size:17px;color:'.MARCA_OSCURO.'}.kpis small{color:#5F666C}'
        .'table{width:100%;border-collapse:collapse;margin:4px 0 8px}th{background:#EEF7E4;color:#2F5A0B;border-bottom:2px solid #73BC25;text-align:left;padding:4px 6px;font-size:9px}td{padding:4px 6px;border-bottom:1px solid '.MARCA_GRIS.';vertical-align:top}tr:nth-child(even) td{background:#F7F8F6}.n{text-align:right}'
        .'table.marca-franja td{background:none}table.marca-franja{margin:0 0 6px}'
        .'.barra{display:inline-block;width:100px;height:7px;background:#eef0f2;vertical-align:middle}.barra span{display:block;height:7px;background:#73BC25}'
        .'table.niveles{margin:6px 0}table.niveles td{padding:0;border:0;height:12px}.ley span{display:inline-block;width:9px;height:9px;margin:0 4px 0 10px}'
        .'.filtros{margin:8px 0 0;padding:6px 10px;background:#F7FBF1;border:1px solid #EEF7E4;border-radius:6px}.lista{margin:4px 0 8px;padding-left:18px;line-height:1.55}.lista li{margin-bottom:3px}'
        .'.nota{color:#64748b;line-height:1.5}.vacio{color:#94a3b8;font-style:italic}.no-print{position:fixed;top:12px;right:12px}.no-print button{background:'.MARCA_OSCURO.';color:#fff;border:0;border-radius:6px;padding:8px 12px;font-weight:bold;cursor:pointer}@media print{.no-print{display:none}thead{display:table-header-group}tr{page-break-inside:avoid}}'
        .'</style></head><body>'.(!empty($d['imprimir'])?'<div class="no-print"><button onclick="window.print()">Imprimir / guardar PDF</button></div>':'')
        .'<header><h1>'.$titulo.'</h1><p>CENGICAÑA · Comités, Transferencia y Comunicación · '.$g['n'].' contactos · '.e(mb_strtolower(DIRECTORIO_PERIODOS[(int)($d['meses'] ?? 12)] ?? 'últimos 12 meses')).' · generado el '.date('d/m/Y H:i').'</p></header>'.marca_franja()
        .'<p class="filtros"><b>Contactos analizados:</b> '.e(mb_substr(trim((string)($d['filtros_texto'] ?? '')) ?: 'todos los contactos activos',0,500)).'</p>';
    if (trim((string)($d['descripcion'] ?? ''))!=='') $h.='<p class="nota">'.nl2br(e(mb_substr((string)$d['descripcion'],0,3000))).'</p>';

    $h.='<h2>Resumen general</h2><table class="kpis"><tr>'
        .'<td><small>Índice promedio</small><b>'.$num($g['indice']).' / 100</b><small>'.$g['medibles'].' contactos medibles</small></td>'
        .'<td><small>Asistencia a comités (obligatoria)</small><b>'.$num($g['asistencia']).'%</b><small>de las reuniones realizadas de sus comités</small></td>'
        .'<td><small>Asistencia a eventos (opcional)</small><b>'.$num($g['asistencia_eventos']).'%</b><small>'.$g['con_eventos'].' contactos asistieron a algún evento</small></td>'
        .'<td><small>Requieren atención</small><b>'.count($r['atencion']).'</b><small>con alertas de participación</small></td></tr></table>';
    $h.='<table class="niveles"><tr>'; foreach ($r['niveles'] as $k=>$n) if ($n) $h.='<td style="width:'.round($n/$total*100,1).'%;background:'.$color[$k].'"></td>'; $h.='</tr></table><p class="ley">';
    foreach ($r['niveles'] as $k=>$n) $h.='<span style="background:'.$color[$k].'"></span>'.e(DIRECTORIO_PARTICIPACION[$k]).': <b>'.$n.'</b> ('.number_format($n/$total*100,0).'%)';
    $h.='</p>';

    [$conclusiones,$acciones]=directorio_participacion_conclusiones($r);
    $h.='<h2>Conclusiones clave</h2><ul class="lista">'.implode('',array_map(static fn($t)=>'<li>'.$t.'</li>',$conclusiones)).'</ul>';
    if ($acciones) $h.='<h2>Acciones sugeridas</h2><ol class="lista">'.implode('',array_map(static fn($t)=>'<li>'.$t.'</li>',$acciones)).'</ol>';
    $ejecutivo=($d['tipo'] ?? '')==='ejecutivo';

    $h.='<h2>Asistencia por comité</h2>'.$tabla(['Comité','Integrantes','Reuniones posibles','Asistencias','Asistencia','Sin asistir'],array_map(static fn($k,$x)=>[e((string)$k),$x['integrantes'],$x['convocadas'],$x['asistidas'],$barra($x['pct']),$x['sin_asistir']],array_keys($r['comites']),$r['comites']));
    $h.='<h2>Participación por empresa / ingenio</h2>'.$tabla($encGrupo,$filasGrupo($r['empresas']));
    $h.='<h2>CENGICAÑA frente a ingenios y externos</h2>'.$tabla($encGrupo,$filasGrupo($r['perfiles']))
        .'<p class="nota">Todos se miden con el mismo criterio (ver metodología); esta tabla solo compara ambos grupos.</p>';
    if (!$ejecutivo) {
        $h.='<h2>Participación por área</h2>'.$tabla($encGrupo,$filasGrupo($r['areas']));
        $h.='<h2>Quienes más participan</h2>'.$tabla(['Contacto','Perfil','Índice','Nivel','Asistencia','Roles'],array_map(static fn($c)=>['<b>'.e($c['nombre']).'</b><br><span class="nota">'.e((string)$c['empresa']).'</span>',e($c['participacion']['perfil_nombre']),$c['participacion']['indice'],e(DIRECTORIO_PARTICIPACION[$c['participacion']['nivel']]),$c['participacion']['pct_asistencia']===null?'—':$c['participacion']['pct_asistencia'].'%',e(implode(', ',$c['participacion']['roles']))],$r['top']));
    }
    $atencion=$ejecutivo ? array_slice($r['atencion'],0,10) : $r['atencion'];
    $h.='<h2>Requieren seguimiento'.($ejecutivo && count($r['atencion'])>10 ? ' (10 de '.count($r['atencion']).')' : '').'</h2>'.$tabla(['Contacto','Empresa','Índice','Motivo'],array_map(static fn($c)=>['<b>'.e($c['nombre']).'</b>',e((string)$c['empresa']),$c['participacion']['indice'],e(implode(' · ',array_column(array_filter($c['participacion']['alertas'],static fn($a)=>$a[0]==='mejorar'),1)))],$atencion));
    if (!empty($d['detalle']) && !$ejecutivo) {
        $orden=$lista; usort($orden,static fn($a,$b)=>$b['participacion']['indice']<=>$a['participacion']['indice']);
        $h.='<h2>Detalle por contacto</h2>'.$tabla(['Contacto','Perfil','Comités','Índice','Nivel','Asist. comités','Eventos','Bono','Última participación'],array_map(static function($c) use ($comites) {
            $p=$c['participacion'];
            return ['<b>'.e($c['nombre']).'</b><br><span class="nota">'.e(trim($c['cargo'].' · '.$c['empresa'],' ·')).'</span>',e($p['perfil_nombre']),e(implode(', ',array_map(static fn($id)=>$comites[$id] ?? $id,$c['comites']))),
                $p['nivel']==='sin_comite'?'—':$p['indice'],e(DIRECTORIO_PARTICIPACION[$p['nivel']]),$p['convocadas']?$p['asistidas'].'/'.$p['convocadas'].' ('.$p['pct_asistencia'].'%)':'—',
                $p['eventos_posibles']||$p['eventos']?$p['eventos'].'/'.max($p['eventos_posibles'],$p['eventos']):'—','+'.$p['componentes']['Eventos (bono)'][0],$p['ultima_activa']?date('d/m/Y',strtotime($p['ultima_activa'])):'—'];
        },$orden));
    }
    $h.='<h2>Metodología</h2><p class="nota">La participación se mide en los '.e(mb_strtolower(DIRECTORIO_PERIODOS[(int)($d['meses'] ?? 12)] ?? 'últimos 12 meses')).' (o desde que el contacto ingresó al directorio) y con el mismo criterio para todos:</p>'
        .$tabla(['Componente','Carácter','Cómo se calcula'],[
            ['Asistencia a comités','Obligatoria para quien está asignado a un comité','% de las reuniones <b>realizadas</b> de sus comités a las que asistió. Es la base del índice (0-100).'],
            ['Asistencia a eventos','Opcional','% de los eventos de transferencia realizados a los que asistió. Suma un bono de hasta <b>+'.$bono.' puntos</b>; no asistir no resta.'],
        ]).'<p class="nota"><b>Índice = asistencia a comités + bono por eventos</b> (máximo 100). Quien no integra comités pero asiste a eventos se clasifica como «Solo eventos» y su índice es su % de asistencia a eventos. Los artículos y próximos pasos se informan, pero no suman al índice.</p>'
        .'<p class="nota">Niveles: Muy activo ≥ '.$u['muy_activo'].' · Activo ≥ '.$u['activo'].' · Ocasional ≥ '.$u['ocasional'].' · Inactivo &lt; '.$u['ocasional'].'. «Sin actividad para medir»: sus comités aún no realizan reuniones y no asistió a eventos.</p>';
    return $h.'</body></html>';
}

/** Hojas de Excel del reporte de participacion (numeros reales para analizar). */
function directorio_participacion_hojas(array $lista,array $comites,array $d=[]): array
{
    $r=directorio_participacion_resumen($lista,$comites);
    $red=static fn($v,int $dec=1)=>$v===null?'':round((float)$v,$dec);
    $grupoFilas=static function(string $titulo,array $grupos) use ($red): array {
        $f=[[$titulo,'Contactos','Índice promedio','Asistencia a comités (%)','Asistencia a eventos (%)','Fueron a eventos','Activos','Inactivos','Artículos','Pasos','Pasos completados']];
        foreach ($grupos as $k=>$x) $f[]=[(string)$k,$x['n'],$red($x['indice']),$red($x['asistencia']),$red($x['asistencia_eventos']),$x['con_eventos'],$x['activos'],$x['inactivos'],$x['articulos'],$x['pasos'],$x['pasos_completados']];
        return $f;
    };
    $detalle=[['Nombre','Cargo','Empresa','Área','Nivel jerárquico','Perfil','Comités','Índice','Nivel de participación','Reuniones posibles','Reuniones asistidas','Asistencia a comités (%)','Eventos realizados','Eventos asistidos','Asistencia a eventos (%)','Bono eventos','Artículos (autor+revisor)','Pasos','Pasos completados','Días desde última participación','Roles','Alertas']];
    foreach ($lista as $c) {
        $p=$c['participacion'];
        $detalle[]=[$c['nombre'],(string)$c['cargo'],(string)$c['empresa'],(string)$c['area'],(string)$c['nivel'],$p['perfil_nombre'],implode('; ',array_map(static fn($id)=>$comites[$id] ?? $id,$c['comites'])),
            $p['indice'],DIRECTORIO_PARTICIPACION[$p['nivel']],$p['convocadas'],$p['asistidas'],$p['pct_asistencia']===null?'':(int)$p['pct_asistencia'],
            $p['eventos_posibles'],$p['eventos'],$p['pct_eventos']===null?'':$p['pct_eventos'],$p['componentes']['Eventos (bono)'][0],
            $p['autor']+$p['revisor'],$p['pasos'],$p['pasos_completados'],$p['dias']===null?'':$p['dias'],implode('; ',$p['roles']),implode(' | ',array_column($p['alertas'],1))];
    }
    $comitesF=[['Comité','Integrantes','Reuniones posibles','Asistencias','Asistencia (%)','Integrantes sin asistir']];
    foreach ($r['comites'] as $k=>$x) $comitesF[]=[(string)$k,$x['integrantes'],$x['convocadas'],$x['asistidas'],round($x['pct'],1),$x['sin_asistir']];
    $g=$r['general']; [$conclusiones,$acciones]=directorio_participacion_conclusiones($r);
    $texto=static fn(string $h)=>html_entity_decode(strip_tags($h),ENT_QUOTES,'UTF-8');
    $resumen=[['Participación de contactos',''],['Periodo',DIRECTORIO_PERIODOS[(int)($d['meses'] ?? 12)] ?? 'Últimos 12 meses'],
        ['Contactos analizados',trim((string)($d['filtros_texto'] ?? '')) ?: 'Todos los contactos activos'],['Generado',date('d/m/Y H:i')],['',''],
        ['Indicador','Valor'],['Contactos',$g['n']],['Índice promedio',$red($g['indice'])],['Asistencia a comités (%)',$red($g['asistencia'])],
        ['Asistencia a eventos (%)',$red($g['asistencia_eventos'])],['Activos o muy activos',$g['activos']],['Inactivos',$g['inactivos']],['Requieren seguimiento',count($r['atencion'])]];
    foreach (DIRECTORIO_PARTICIPACION as $k=>$n) $resumen[]=['Nivel: '.$n,$r['niveles'][$k]];
    $resumen[]=['','']; $resumen[]=['Conclusiones',''];
    foreach ($conclusiones as $t) $resumen[]=['• '.$texto($t),''];
    if ($acciones) { $resumen[]=['','']; $resumen[]=['Acciones sugeridas','']; foreach ($acciones as $i=>$t) $resumen[]=[($i+1).'. '.$texto($t),'']; }
    return [['nombre'=>'Resumen','filas'=>$resumen],['nombre'=>'Detalle por contacto','filas'=>$detalle],['nombre'=>'Por perfil','filas'=>$grupoFilas('Perfil',$r['perfiles'])],['nombre'=>'Por empresa','filas'=>$grupoFilas('Empresa / ingenio',$r['empresas'])],
        ['nombre'=>'Por comité','filas'=>$comitesF],['nombre'=>'Por área','filas'=>$grupoFilas('Área',$r['areas'])]];
}
