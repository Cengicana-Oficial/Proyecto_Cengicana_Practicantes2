<?php
declare(strict_types=1);
require_once __DIR__.'/marca.php';
/**
 * Informe de evaluación de un evento de transferencia (HTML para ver/imprimir y PDF con Dompdf).
 * Misma estructura que el informe de eventos de Cengicursos: resumen ejecutivo, percepción de
 * los participantes y recomendaciones; agrega los comentarios clasificados con los datos de
 * contacto de quien los hizo, para darles seguimiento, y la asistencia por institución.
 */

function agenda_informe_fecha(?string $fecha): string
{
    if (!$fecha || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/',$fecha,$m)) return 'Fecha por definir';
    return (int)$m[3].' de '.mb_strtolower(AGENDA_MESES[(int)$m[2]-1]).' de '.$m[1];
}

function agenda_informe_evento(array $r,bool $pantalla=false): string
{
    $color='#73BC25'; // Verde principal de la marca (barras y acentos)
    $encuesta=$r['encuesta'];$n=count($encuesta);
    $notas=array_map(static fn($a)=>(int)$a['calificacion'],$encuesta);
    $promedio=$n?array_sum($notas)/$n:null;
    $dist=[];foreach([5,4,3,2,1] as $v)$dist[$v]=count(array_filter($notas,static fn($x)=>$x===$v));
    $satisf=$n?($dist[5]+$dist[4])/$n*100:null;$insat=$n?($dist[2]+$dist[1])/$n*100:null;
    // Logística y permanencia vienen en "detalle" de las respuestas sincronizadas desde Cengicursos.
    $logistica=[];$permanencia=[];
    foreach($encuesta as $a){
        if(preg_match('/Logística: (\d)/u',(string)$a['detalle'],$m))$logistica[]=(int)$m[1];
        if(preg_match('/Permanencia: ([^·]+)/u',(string)$a['detalle'],$m)){$k=trim($m[1]);$permanencia[$k]=($permanencia[$k] ?? 0)+1;}
    }
    $asistentes=array_values(array_filter($r['asistentes'],static fn($a)=>$a['estado']!=='descartado'));
    $participantes=max(count($asistentes),(int)$r['participantes']);
    $instituciones=[];foreach($asistentes as $a){$k=trim((string)$a['empresa']) ?: 'Sin institución';$instituciones[$k]=($instituciones[$k] ?? 0)+1;}
    arsort($instituciones);
    $ingenios=max(count(array_diff(array_map('strval',array_keys($instituciones)),['Sin institución'])),(int)$r['ingenios']);
    $tasa=$participantes&&$n?min(100,$n/$participantes*100):null;
    $comentarios=array_values(array_filter($encuesta,static fn($a)=>trim((string)$a['comentario'])!==''));
    $porCategoria=[];foreach($comentarios as $a)$porCategoria[$a['categoria'] ?: '']=($porCategoria[$a['categoria'] ?: ''] ?? 0)+1;
    $atencion=($porCategoria['queja'] ?? 0)+($porCategoria['solicitud'] ?? 0)+($porCategoria['seguimiento'] ?? 0);
    $pendientes=count(array_filter($comentarios,static fn($a)=>($a['seguimiento_estado'] ?: (in_array($a['categoria'],AGENDA_REQUIEREN_SEGUIMIENTO,true)?'pendiente':''))!=='' && $a['seguimiento_estado']!=='atendido'));
    $f1=static fn(?float $v,string $suf='')=>$v===null?'—':number_format($v,1).$suf;
    $pct=static fn(?float $v)=>$v===null?'—':round($v).'%';

    // 1. Resumen ejecutivo (mismos criterios que las conclusiones del informe de Cengicursos).
    $resumen=[];
    $resumen[]=($participantes===1?'Asistió <b>1</b> persona':"Asistieron <b>{$participantes}</b> personas").($ingenios?" de <b>{$ingenios}</b> ".($ingenios===1?'institución':'instituciones'):'').'.';
    if($n){
        $resumen[]="Respondieron la evaluación <b>{$n}</b> participantes".($tasa!==null?' ('.$pct($tasa).' de los asistentes)':'').'; percepción promedio <b>'.$f1($promedio,' ★').'</b> y <b>'.$pct($satisf).'</b> satisfechos (4-5 ★).';
        if($logistica)$resumen[]='La logística del evento obtuvo <b>'.$f1(array_sum($logistica)/count($logistica),' ★').'</b> en promedio.';
        if($atencion)$resumen[]="<b>{$atencion}</b> comentario(s) requieren atención (quejas, solicitudes o seguimiento)".($pendientes?"; <b>{$pendientes}</b> con seguimiento pendiente o en proceso":'; todos atendidos').'.';
    } else $resumen[]='Aún no hay respuestas de evaluación.';

    // Recomendaciones.
    $rec=[];
    // Solo lo que sigue sin atender: lo marcado como "Atendido" ya no genera recomendación.
    $abiertos=[];foreach($comentarios as $a)if(in_array($a['categoria'],AGENDA_REQUIEREN_SEGUIMIENTO,true) && $a['seguimiento_estado']!=='atendido')$abiertos[$a['categoria']]=($abiertos[$a['categoria']] ?? 0)+1;
    if(($abiertos['queja'] ?? 0))$rec[]='Atender las <b>'.$abiertos['queja'].'</b> queja(s) pendientes y comunicar a los participantes la respuesta.';
    if(($abiertos['solicitud'] ?? 0))$rec[]='Responder las <b>'.$abiertos['solicitud'].'</b> solicitud(es) pendientes (por ejemplo, envío de presentaciones o información).';
    if(($abiertos['seguimiento'] ?? 0))$rec[]='Dar seguimiento a <b>'.$abiertos['seguimiento'].'</b> comentario(s) marcados para seguimiento.';
    if(($porCategoria[''] ?? 0))$rec[]='Clasificar los <b>'.$porCategoria[''].'</b> comentario(s) pendientes en Comités → Eventos → Evaluación.';
    if($promedio!==null && $promedio<4)$rec[]='La percepción está por debajo de 4 ★: revisar contenido, ponentes y organización antes del próximo evento.';
    if($insat!==null && $insat>=20)$rec[]='El '.$pct($insat).' calificó con 1-2 ★: identificar las causas en sus comentarios.';
    if($tasa!==null && $tasa<40)$rec[]='La tasa de respuesta ('.$pct($tasa).') es baja: proyectar el QR de la encuesta al cierre del evento.';

    $logoRuta=__DIR__.'/../assets/img/cengicana-logo.png';
    $logo=is_file($logoRuta)?'<img src="data:image/png;base64,'.base64_encode((string)file_get_contents($logoRuta)).'" alt="CENGICAÑA" style="height:44px">':'';
    $kpi=static fn(string $v,string $l)=>'<td class="kpi"><div class="v">'.$v.'</div><div class="l">'.$l.'</div></td>';
    $barra=static fn(int $v,int $max,string $c)=>'<div class="bar"><div style="width:'.($max?round($v/$max*100):0).'%;background:'.$c.'"></div></div>';
    $maxD=max(1,...array_values($dist));
    $sub=[e((string)($r['modalidad'] ?? '')),agenda_informe_fecha($r['fecha'] ?: ($r['plan_fecha'] ?: null)),e((string)($r['lugar'] ?? ''))];

    $h='<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Informe · '.e($r['nombre']).'</title><style>
      body{font:11px "DejaVu Sans",Arial,sans-serif;color:#2b3328;margin:28px 34px}
      .k{font-size:9.5px;letter-spacing:1.5px;color:#5f666c;font-weight:bold}.t{font-size:21px;font-weight:bold;color:'.MARCA_OSCURO.';margin:4px 0}.s{color:#4b5a45}
      .head{width:100%;padding-bottom:10px}.head td{vertical-align:top}
      h2{font-size:12.5px;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid '.MARCA_GRIS.';padding-bottom:4px;margin:20px 0 8px;color:'.MARCA_OSCURO.'}
      h3{font-size:10px;letter-spacing:1px;text-transform:uppercase;color:#4b5a45;margin:4px 0 6px}
      ul{margin:4px 0 0 16px;padding:0}li{margin:3px 0}
      .kpis{width:100%;border-collapse:separate;border-spacing:6px 0;margin:10px -6px 0}.kpi{border:1px solid '.MARCA_GRIS.';border-top:3px solid #73BC25;border-radius:8px;padding:9px 10px;width:20%}
      .kpi .v{font-size:17px;font-weight:bold;color:'.MARCA_OSCURO.'}.kpi .l{font-size:9.5px;color:#4b5a45}
      .two{width:100%}.two>tbody>tr>td{width:50%;vertical-align:top;padding-right:14px}
      .dist{width:100%;border-collapse:collapse}.dist td{padding:2px 0}.bar{height:7px;background:#edf0ea;border-radius:4px}.bar div{height:7px;border-radius:4px}
      .muted{color:#6b7468;font-size:10px}
      table.list{width:100%;border-collapse:collapse;font-size:10px}table.list th{background:#EEF7E4;text-align:left;padding:5px;color:#2F5A0B;border-bottom:2px solid #73BC25}table.list td{padding:5px;border-bottom:1px solid #edf0ea;vertical-align:top}
      .tag{display:inline-block;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:bold}.queja{background:#FFF0E5;color:#9A3F00}.solicitud{background:#F5FAD9;color:#4A5C00}.seguimiento{background:#FFF8DB;color:#6B5500}.sugerencia{background:#EEF7E4;color:#2F5A0B}.sin_accion,.sin{background:#eef0ee;color:#5f666c}
      .foot{margin-top:22px;border-top:1px solid '.MARCA_GRIS.';padding-top:6px;text-align:right;color:#6b7468;font-size:9.5px}
    '.($pantalla?'body{margin:0;background:#EEF0EE;padding:24px 16px}.hoja{max-width:860px;margin:0 auto;background:#fff;padding:32px 40px;border-radius:12px;box-shadow:0 6px 24px #0000001a}
      .barra-acciones{max-width:860px;margin:0 auto 12px;display:flex;justify-content:flex-end;gap:8px}.barra-acciones button{background:'.MARCA_OSCURO.';color:#fff;border:0;border-radius:8px;padding:9px 16px;font:600 13px Arial,sans-serif;cursor:pointer}
      body{font-size:13px;font-family:"Segoe UI",Arial,sans-serif}.t{font-size:26px;font-weight:700}h2{font-size:14px}.kpi .v{font-size:22px}.kpi .l{font-size:11px}table.list{font-size:12px}.muted{font-size:11.5px}
      @media (max-width:760px){body{padding:10px}.hoja{padding:18px 16px;border-radius:8px}.kpis,.kpis tbody,.kpis tr,.two,.two>tbody,.two>tbody>tr,.two>tbody>tr>td{display:block;width:100%!important}.kpis{border-spacing:0;margin:10px 0 0}.kpis td.kpi{display:inline-block;width:calc(50% - 5px)!important;margin:0 4px 8px 0;box-sizing:border-box;vertical-align:top}.two>tbody>tr>td{padding:0 0 12px}.head td{display:block;text-align:left!important}.head img{margin-top:8px}table.list,table.list tbody,table.list tr,table.list td{display:block;width:auto!important}table.list tr:first-child{display:none}table.list tr{border-bottom:1px solid '.MARCA_GRIS.';padding:8px 0}table.list td{border:0;padding:2px 0}}
      @media print{body{background:#fff;padding:0}.hoja{box-shadow:none;padding:0;max-width:none}.barra-acciones{display:none}}':'').'
    </style></head><body>'.($pantalla?'<div class="barra-acciones"><button type="button" onclick="window.print()">Imprimir / guardar PDF</button></div><div class="hoja">':'');
    $h.='<table class="head"><tr><td><div class="k">INFORME DE EVALUACIÓN DEL EVENTO</div><div class="t">'.e($r['nombre']).'</div><div class="s">'.implode(' · ',array_filter($sub)).'</div></td><td style="text-align:right">'.$logo.'</td></tr></table>'.marca_franja();

    $h.='<h2>1. Resumen ejecutivo</h2><ul>'.implode('',array_map(static fn($t)=>"<li>{$t}</li>",$resumen)).'</ul>';
    $h.='<table class="kpis"><tr>'.$kpi((string)$participantes,'Asistentes').$kpi((string)$ingenios,'Instituciones').$kpi((string)$n,'Respuestas').$kpi($f1($promedio,' ★'),'Percepción promedio').$kpi($pct($satisf),'Satisfechos (4-5 ★)').'</tr></table>';

    $h.='<h2>2. Percepción de los participantes</h2><table class="two"><tr><td><h3>Distribución de calificaciones ('.$n.' respuestas)</h3><table class="dist">';
    foreach($dist as $v=>$c)$h.='<tr><td style="width:30px">'.$v.' ★</td><td>'.$barra($c,$maxD,$v>=4?$color:($v===3?'#E0A800':'#C0392B')).'</td><td style="width:26px;text-align:right"><b>'.$c.'</b></td></tr>';
    $h.='</table><p class="muted">Satisfechos (4-5 ★): <b>'.$pct($satisf).'</b> · Insatisfechos (1-2 ★): <b>'.$pct($insat).'</b></p></td><td>';
    if($logistica)$h.='<h3>Logística del evento</h3><p><b>'.$f1(array_sum($logistica)/count($logistica),' ★').'</b> <span class="muted">promedio de '.count($logistica).' respuestas</span></p>';
    if($permanencia){$h.='<h3>Permanencia en el evento</h3><table class="dist">';$maxP=max($permanencia);foreach($permanencia as $k=>$c)$h.='<tr><td style="width:110px">'.e((string)$k).'</td><td>'.$barra($c,$maxP,$color).'</td><td style="width:26px;text-align:right"><b>'.$c.'</b></td></tr>';$h.='</table>';}
    $h.='<h3'.($logistica || $permanencia ? ' style="margin-top:14px"' : '').'>Comentarios por clasificación</h3>';
    if($comentarios){$h.='<table class="dist">';foreach(AGENDA_CATEGORIAS_COMENTARIO+[''=>'Sin clasificar'] as $k=>$label)if(!empty($porCategoria[$k]))$h.='<tr><td><span class="tag '.($k ?: 'sin').'">'.e($label).'</span></td><td style="width:26px;text-align:right"><b>'.$porCategoria[$k].'</b></td></tr>';$h.='</table>';}
    else $h.='<p class="muted">Sin comentarios.</p>';
    $h.='</td></tr></table>';

    // 3. Comentarios con datos de contacto: primero los que requieren atención.
    $h.='<h2>3. Comentarios de los participantes</h2>';
    if($comentarios){
        $orden=array_flip(array_merge(['queja','solicitud','seguimiento',''],array_keys(AGENDA_CATEGORIAS_COMENTARIO)));
        usort($comentarios,static fn($x,$y)=>($orden[$x['categoria'] ?: ''] ?? 9)<=>($orden[$y['categoria'] ?: ''] ?? 9));
        $h.='<table class="list"><tr><th style="width:13%">Clasificación</th><th style="width:24%">Participante / contacto</th><th style="width:5%">★</th><th>Comentario</th><th style="width:22%">Seguimiento</th></tr>';
        foreach($comentarios as $a){
            $k=$a['categoria'] ?: '';
            $contacto=implode(' · ',array_filter([e((string)$a['institucion']),e((string)$a['email']),e((string)$a['telefono'])]));
            $estado=$a['seguimiento_estado'] ?: (in_array($k,AGENDA_REQUIEREN_SEGUIMIENTO,true)?'pendiente':'');
            $seguimiento=$estado?'<b>'.e(AGENDA_SEGUIMIENTO[$estado] ?? $estado).'</b>'.($a['seguimiento_nota']?'<br>'.nl2br(e((string)$a['seguimiento_nota'])):''):'<span class="muted">—</span>';
            $h.='<tr><td><span class="tag '.($k ?: 'sin').'">'.e(AGENDA_CATEGORIAS_COMENTARIO[$k] ?? 'Sin clasificar').'</span></td><td><b>'.e((string)($a['nombre'] ?: 'Anónimo')).'</b><br><span class="muted">'.($contacto ?: 'Sin datos de contacto').'</span></td><td>'.(int)$a['calificacion'].'</td><td>'.nl2br(e((string)$a['comentario'])).'</td><td>'.$seguimiento.'</td></tr>';
        }
        $h.='</table>';
    } else $h.='<p class="muted">Sin comentarios.</p>';

    // 4. Asistencia por institución.
    $h.='<h2>4. Asistencia por institución</h2>';
    if($instituciones){$h.='<table class="dist">';$maxI=max($instituciones);foreach(array_slice($instituciones,0,15,true) as $k=>$c)$h.='<tr><td style="width:190px">'.e((string)$k).'</td><td>'.$barra($c,$maxI,$color).'</td><td style="width:26px;text-align:right"><b>'.$c.'</b></td></tr>';$h.='</table>';
        if(count($instituciones)>15)$h.='<p class="muted">Y '.(count($instituciones)-15).' instituciones más.</p>';}
    else $h.='<p class="muted">Sin asistentes registrados.</p>';

    $h.='<h2>5. Recomendaciones</h2>'.($rec?'<ul>'.implode('',array_map(static fn($t)=>"<li>{$t}</li>",$rec)).'</ul>':'<p>Sin recomendaciones: los resultados están dentro de lo esperado.</p>');
    $h.='<div class="foot">Generado por Comités, Transferencia y Comunicación · CENGICAÑA · '.date('d').' '.mb_strtolower(mb_substr(AGENDA_MESES[(int)date('n')-1],0,3)).' '.date('Y · H:i').'</div>'.($pantalla?'</div>':'').'</body></html>';
    return $h;
}
