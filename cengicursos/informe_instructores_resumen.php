<?php
require_once __DIR__.'/revisar_permisos.php';
require_once __DIR__.'/informe_instructores_resumen_helpers.php';
require_once __DIR__.'/evaluacion_meta_helpers.php';
cengi_require_generar_informe_instructor();
if(cengi_es_administrador_ingenio()){http_response_code(403);exit('No tienes permiso para consultar las evaluaciones de los instructores.');}
date_default_timezone_set('America/Guatemala');
$db=conectar();
$h=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};

// Dos vistas: resultados de la evaluación por instructor y participación (meta del 40 %) por curso/módulo.
$vista=($_GET['vista']??'')==='participacion'?'participacion':'resultados';
$anio=(int)($_GET['anio']??0);if($anio<2000||$anio>2100)$anio=0;
$q=mb_substr(trim((string)($_GET['q']??'')),0,200);
$soloEvaluados=!empty($_GET['evaluados']);
$soloBajo=!empty($_GET['bajo']);
$ordenes=['nombre'=>'Nombre (A–Z)','nota'=>'Mejor nota del instructor','recomienda'=>'Más recomendado','respuestas'=>'Más respuestas','participacion'=>'Menor participación'];
$orden=isset($ordenes[$_GET['orden']??''])?$_GET['orden']:'nombre';
// Meta configurable: la usan este informe y Seguimiento por curso (evaluacion_meta_helpers.php).
$puedeConfigurarMeta=cengi_eval_meta_puede_configurar();
if(empty($_SESSION['eval_meta_csrf']))$_SESSION['eval_meta_csrf']=bin2hex(random_bytes(16));
$errorMeta='';
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['accion']??'')==='guardar_meta'){
    try{
        if(!$puedeConfigurarMeta)throw new InvalidArgumentException('No tienes permiso para cambiar la meta.');
        if(!hash_equals($_SESSION['eval_meta_csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Recarga la página e inténtalo de nuevo.');
        require_once __DIR__.'/planificacion_helpers.php';
        cengi_eval_meta_guardar($db,$_POST['porcentaje']??'',cengi_pp_usuario_nombre());
        header('Location: informe_instructores_resumen.php?vista=participacion&meta_guardada=1#configurar-meta');exit;
    }catch(InvalidArgumentException $e){$errorMeta=$e->getMessage();}
}
$configMeta=cengi_eval_meta_config($db);
$meta=(int)$configMeta['porcentaje'];

// ---------------------------------------------------------------- participación (meta)
$participacion=cengi_eval_meta_global($db,$anio);
foreach($participacion as &$f)$f['codigo']=cengi_pp_codigo_instructor($f['instructor_id'],$f['codigo_catedratico']);unset($f);
if($q!==''){
    $participacion=array_values(array_filter($participacion,static function($f)use($q){
        return mb_stripos($f['instructor'].' '.$f['codigo'].' '.$f['nombre_cursos'].' '.$f['codigo_curso'].' '.$f['modulo'],$q)!==false;
    }));
}
$metaPorInstructor=cengi_eval_meta_por_instructor($participacion);
$estadosMeta=[
    'cumple'=>['Cumple la meta','is-bien'],
    'en_curso'=>['Bajo la meta · en curso','is-medio'],
    'cerrado'=>['Cerró bajo la meta','is-bajo'],
    'por_iniciar'=>['Por iniciar','is-sin'],
    'sin_inscritos'=>['Sin inscritos','is-sin'],
];
$cuentan=array_filter($participacion,static function($f){return $f['cuenta'];});
$kpiMeta=[
    'evaluables'=>count($cuentan),
    'cumple'=>count(array_filter($cuentan,static function($f){return $f['estado']==='cumple';})),
    'en_curso'=>count(array_filter($cuentan,static function($f){return $f['estado']==='en_curso';})),
    'cerrado'=>count(array_filter($cuentan,static function($f){return $f['estado']==='cerrado';})),
];
$sumIns=array_sum(array_column($cuentan,'inscritos'));
$kpiMeta['porcentaje']=$sumIns?round(array_sum(array_column($cuentan,'respuestas'))/$sumIns*100):null;
$partVisible=$soloBajo?array_values(array_filter($participacion,static function($f){return in_array($f['estado'],['en_curso','cerrado'],true);})):$participacion;
// Agrupado por instructor para la vista de participación.
$grupos=[];
foreach($partVisible as $f){
    $id=(int)$f['instructor_id'];
    $grupos[$id]=$grupos[$id]??['id'=>$id,'nombre'=>$f['instructor'],'codigo'=>$f['codigo'],'filas'=>[]];
    $grupos[$id]['filas'][]=$f;
}
if($orden==='participacion'){
    uasort($grupos,static function($a,$b)use($metaPorInstructor){
        $pa=$metaPorInstructor[$a['id']]['porcentaje']??null;$pb=$metaPorInstructor[$b['id']]['porcentaje']??null;
        return [$pa===null,(float)$pa,$a['nombre']]<=>[$pb===null,(float)$pb,$b['nombre']];
    });
}
$grupos=array_values($grupos);

// ---------------------------------------------------------------- resultados por instructor
$todos=cengi_informe_instructores_resumen($db,$anio,$q);
$rows=$soloEvaluados?array_values(array_filter($todos,static function($r){return $r['respuestas']>0;})):$todos;
foreach($rows as &$r)$r['meta']=$metaPorInstructor[$r['id']]??null;unset($r);
if($orden!=='nombre'){
    if($orden==='participacion'){
        usort($rows,static function($a,$b){$pa=$a['meta']['porcentaje']??null;$pb=$b['meta']['porcentaje']??null;return [$pa===null,(float)$pa,$a['nombre']]<=>[$pb===null,(float)$pb,$b['nombre']];});
    }else{
        $campo=['nota'=>'nota','recomienda'=>'recomienda_instructor','respuestas'=>'respuestas'][$orden];
        // Sin evaluaciones al final; empates por nombre.
        usort($rows,static function($a,$b)use($campo){return [$b[$campo]!==null,(float)$b[$campo],$a['nombre']]<=>[$a[$campo]!==null,(float)$a[$campo],$b['nombre']];});
    }
}

// Indicadores sobre lo filtrado; el promedio general pondera por número de respuestas.
$evaluados=array_filter($rows,static function($r){return $r['respuestas']>0;});
$respuestas=array_sum(array_column($rows,'respuestas'));
$promedio=static function($campo)use($evaluados,$respuestas){
    if(!$respuestas)return null;$suma=0;foreach($evaluados as $r)$suma+=(float)$r[$campo]*$r['respuestas'];return $suma/$respuestas;
};
$general=['nota'=>$promedio('nota'),'recomienda_instructor'=>$promedio('recomienda_instructor'),'recomienda_lugar'=>$promedio('recomienda_lugar'),'tema_logistica'=>$promedio('tema_logistica')];

// [clave, título, escala, sufijo, umbral verde, umbral amarillo, título corto]
$metricas=[
    ['nota','Nota del instructor','1–4','/4',3.5,3,'Nota'],
    ['recomienda_instructor','Recomendaría al instructor','1–10','/10',8.5,7,'Recomienda al instructor'],
    ['recomienda_lugar','Recomendaría lugar / plataforma','1–10','/10',8.5,7,'Recomienda lugar'],
    ['tema_logistica','Tema y logística','%','%',85,70,'Tema y logística'],
];
$nivel=static function($valor,$verde,$amarillo){return $valor===null?'is-sin':($valor>=$verde?'is-bien':($valor>=$amarillo?'is-medio':'is-bajo'));};
$formato=static function($valor,$sufijo){return $valor===null?'—':number_format((float)$valor,$sufijo==='%'?0:2).$sufijo;};
// Meta por instructor: verde si todos sus cursos cumplen; amarillo si cumple la mitad o más.
$nivelMeta=static function($m){return !$m||!$m['evaluables']?'is-sin':($m['cumple']===$m['evaluables']?'is-bien':($m['cumple']*2>=$m['evaluables']?'is-medio':'is-bajo'));};
$textoMeta=static function($m){return !$m||!$m['evaluables']?'—':$m['cumple'].' de '.$m['evaluables'];};
$descripcion=($anio?'Cursos del año '.$anio:'Todos los años').($q!==''?' · Búsqueda: '.$q:'')
    .($vista==='resultados'&&$soloEvaluados?' · Solo con evaluaciones':'').($vista==='participacion'&&$soloBajo?' · Solo bajo la meta':'');
$fechaCurso=static function($f){return $f?date('d/m/Y',strtotime($f)):'—';};

// ---------------------------------------------------------------- Excel
if(in_array($_GET['exportar']??'',['excel','csv'],true)){
    require_once __DIR__.'/classes/export_helpers.php';
    $filas=[];
    if($vista==='participacion'){
        foreach($partVisible as $f){
            $filas[]=[$f['codigo'],$f['instructor'],$f['codigo_curso']?:'',$f['nombre_cursos'],$f['modulo']?:'Todo el curso',$fechaCurso($f['inicio']),$fechaCurso($f['fin']),
                (string)$f['inscritos'],(string)$f['respuestas'],(string)$f['meta'],$f['inscritos']?$f['porcentaje'].'%':'—',(string)$f['faltan'],$estadosMeta[$f['estado']][0]];
        }
        cengi_export_enviar_excel(['Código del instructor','Instructor','Código del curso','Curso o diplomado','Módulo','Inicio','Fin','Inscritos','Respuestas','Meta ('.$meta.' %)','Participación','Faltan','Estado'],
            $filas,'Participación','participacion-evaluacion'.($anio?'-'.$anio:''),[14,28,14,40,26,12,12,10,11,11,13,9,22],'xlsx');
    }
    foreach($rows as $r){
        $fila=[$r['codigo'],$r['nombre'],implode("\n",$r['asignaciones'])?:'Sin asignaciones',(string)$r['respuestas']];
        foreach($metricas as [$k])$fila[]=$r[$k]===null?'Sin evaluaciones':number_format((float)$r[$k],2,'.','');
        $fila[]=$textoMeta($r['meta']);
        $fila[]=$r['meta']&&$r['meta']['porcentaje']!==null?$r['meta']['porcentaje'].'%':'—';
        $filas[]=$fila;
    }
    cengi_export_enviar_excel(['Código del instructor','Nombre del instructor','Cursos / módulos asignados','Respuestas','Nota del instructor (1–4)','Recomendaría al instructor (1–10)','Recomendaría lugar/plataforma (1–10)','Tema y logística (%)','Cursos que cumplen la meta del '.$meta.' %','Participación promedio'],
        $filas,'Consolidado','informe-instructores'.($anio?'-'.$anio:''),[14,30,60,12,16,18,20,16,18,14],'xlsx');
}

// ---------------------------------------------------------------- PDF
if(($_GET['pdf']??'')==='1'){
    require_once __DIR__.'/vendor/autoload.php';
    require_once __DIR__.'/classes/export_helpers.php';
    $color=static function($clase){return ['is-bien'=>'#2f6a12','is-medio'=>'#7a6000','is-bajo'=>'#b34a00','is-sin'=>'#8a968f'][$clase];};
    $logoRuta=__DIR__.'/img/logo-comite-capacitacion-pdf.png'; // versión liviana para Dompdf
    $logo=is_file($logoRuta)?'data:image/png;base64,'.base64_encode((string)file_get_contents($logoRuta)):'';
    $tituloPdf=$vista==='participacion'?'Participación en la evaluación del instructor':'Informe consolidado por instructor';
    ob_start(); ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
    @page { margin: 26px 28px 34px; }
    body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #263238; font-size: 9px; }
    table { border-collapse: collapse; width: 100%; }
    .banda { background: #1f5632; color: #fff; border-radius: 8px; }
    .banda td { padding: 12px 16px; vertical-align: middle; }
    .banda small { color: #D9F2A6; font-size: 7.5px; letter-spacing: .1em; text-transform: uppercase; }
    .banda h1 { margin: 3px 0 2px; font-size: 17px; }
    .banda p { margin: 0; font-size: 8.5px; color: #e3efd9; }
    .logo { background: #fff; border-radius: 6px; padding: 4px 7px; } .logo img { height: 34px; }
    .franja td { height: 4px; padding: 0; }
    .kpis { margin: 12px 0; } .kpis td { width: 20%; padding: 0 4px; }
    .kpi { border: 1px solid #e0e5dc; border-top: 3px solid #73BC25; border-radius: 6px; padding: 7px 9px; }
    .kpi b { display: block; font-size: 15px; color: #1f5632; } .kpi span { font-size: 7.5px; color: #55605a; }
    .datos th { background: #f2f9e9; color: #1f5632; font-size: 7.5px; text-align: left; padding: 6px 5px; border-bottom: 1px solid #cfe3b6; }
    .datos td { padding: 5px; border-bottom: 1px solid #e8ece4; vertical-align: top; }
    .datos thead { display: table-header-group; } .datos tr { page-break-inside: avoid; }
    .datos .num { text-align: center; white-space: nowrap; font-weight: bold; }
    .datos tr.grupo td { background: #f7f9f4; border-top: 1px solid #cfe3b6; font-size: 9.5px; padding: 6px 5px; }
    .sub { color: #6b7a72; font-size: 7.5px; }
    .nota { margin-top: 8px; color: #6b7a72; font-size: 7px; }
    .pie { position: fixed; bottom: -20px; left: 0; font-size: 7px; color: #6b7a72; }
</style></head><body>
<table class="banda"><tr><td><small>Instructores · informe</small><h1><?=$h($tituloPdf)?></h1><p><?=$h($descripcion)?> · Generado el <?=date('d/m/Y H:i')?></p></td>
<?php if($logo!==''):?><td style="text-align:right;width:140px"><span class="logo"><img src="<?=$logo?>" alt=""></span></td><?php endif;?></tr></table>
<table class="franja"><tr><td style="background:#73BC25"></td><td style="background:#A3D300"></td><td style="background:#FFCC00"></td><td style="background:#FF6B00"></td></tr></table>
<?php if($vista==='participacion'):?>
<table class="kpis"><tr>
    <td><div class="kpi"><b><?=$kpiMeta['evaluables']?></b><span>Evaluaciones a cumplir (cursos/módulos iniciados)</span></div></td>
    <td><div class="kpi"><b style="color:#2f6a12"><?=$kpiMeta['cumple']?></b><span>Cumplen la meta del <?=$meta?> %</span></div></td>
    <td><div class="kpi"><b style="color:#7a6000"><?=$kpiMeta['en_curso']?></b><span>Bajo la meta · curso en marcha</span></div></td>
    <td><div class="kpi"><b style="color:#b34a00"><?=$kpiMeta['cerrado']?></b><span>Cerraron bajo la meta</span></div></td>
    <td><div class="kpi"><b><?=$kpiMeta['porcentaje']===null?'—':$kpiMeta['porcentaje'].' %'?></b><span>Participación global</span></div></td>
</tr></table>
<table class="datos"><thead><tr><th style="width:30%">Curso o diplomado</th><th>Módulo</th><th>Período</th><th class="num">Inscritos</th><th class="num">Respuestas</th><th class="num">Meta</th><th class="num">Participación</th><th>Estado</th></tr></thead><tbody>
<?php foreach($grupos as $g):$m=$metaPorInstructor[$g['id']]??null;?>
    <tr class="grupo"><td colspan="8"><b><?=$h($g['nombre'])?></b> <span class="sub"><?=$h($g['codigo'])?> · cumple <?=$h($textoMeta($m))?> · participación <?=$m&&$m['porcentaje']!==null?$m['porcentaje'].' %':'—'?></span></td></tr>
    <?php foreach($g['filas'] as $f):[$est,$cls]=$estadosMeta[$f['estado']];?><tr>
        <td><?=$h($f['nombre_cursos'])?><?php if($f['codigo_curso']):?><br><span class="sub"><?=$h($f['codigo_curso'])?></span><?php endif;?></td>
        <td><?=$h($f['modulo']?:'Todo el curso')?></td>
        <td><?=$h($fechaCurso($f['inicio']).' – '.$fechaCurso($f['fin']))?></td>
        <td class="num"><?=$f['inscritos']?></td><td class="num"><?=$f['respuestas']?></td><td class="num"><?=$f['meta']?></td>
        <td class="num" style="color:<?=$color($cls)?>"><?=$f['inscritos']?$f['porcentaje'].' %':'—'?></td>
        <td style="color:<?=$color($cls)?>"><?=$h($est)?><?=$f['faltan']&&$f['cuenta']?' · falta'.($f['faltan']===1?'':'n').' '.$f['faltan']:''?></td>
    </tr><?php endforeach;?>
<?php endforeach;?>
<?php if(!$grupos):?><tr><td colspan="8">No hay evaluaciones para estos filtros.</td></tr><?php endif;?>
</tbody></table>
<p class="nota">Meta: al menos el <?=$meta?> % de los participantes activos de cada curso o diplomado evalúa a cada instructor (por curso o por módulo). Solo cuentan los cursos que ya iniciaron y tienen inscritos. La participación global suma respuestas ÷ inscritos de las evaluaciones que cuentan.</p>
<?php else:?>
<table class="kpis"><tr>
    <td><div class="kpi"><b><?=count($rows)?></b><span>Instructores (<?=count($evaluados)?> evaluados)</span></div></td>
    <td><div class="kpi"><b><?=number_format($respuestas)?></b><span>Respuestas de participantes</span></div></td>
    <?php foreach(array_slice($metricas,0,2) as [$k,$t,,$suf]):?><td><div class="kpi"><b><?=$h($formato($general[$k],$suf))?></b><span><?=$h($t)?> · promedio</span></div></td><?php endforeach;?>
    <td><div class="kpi"><b><?=$kpiMeta['cumple']?> de <?=$kpiMeta['evaluables']?></b><span>Evaluaciones que cumplen la meta del <?=$meta?> %</span></div></td>
</tr></table>
<table class="datos"><thead><tr><th style="width:18%">Instructor</th><th style="width:22%">Cursos / módulos asignados</th><th class="num">Resp.</th><?php foreach($metricas as [,$t,$esc]):?><th class="num"><?=$h($t)?><br><span class="sub">(<?=$h($esc)?>)</span></th><?php endforeach;?><th class="num">Meta <?=$meta?> %<br><span class="sub">(cursos que cumplen)</span></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr>
    <td><b><?=$h($r['nombre'])?></b><br><span class="sub"><?=$h($r['codigo'])?></span></td>
    <td><?=$r['asignaciones']?nl2br($h(implode("\n",$r['asignaciones']))):'<span class="sub">Sin asignaciones</span>'?></td>
    <td class="num"><?=$r['respuestas']?:'—'?></td>
    <?php foreach($metricas as [$k,,,$suf,$v1,$v2]):?><td class="num" style="color:<?=$color($nivel($r[$k],$v1,$v2))?>"><?=$h($formato($r[$k],$suf))?></td><?php endforeach;?>
    <td class="num" style="color:<?=$color($nivelMeta($r['meta']))?>"><?=$h($textoMeta($r['meta']))?><?php if($r['meta']&&$r['meta']['porcentaje']!==null):?><br><span class="sub"><?=$r['meta']['porcentaje']?> % particip.</span><?php endif;?></td>
</tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8">No hay instructores para estos filtros.</td></tr><?php endif;?>
</tbody></table>
<p class="nota">La nota promedia los cinco criterios de «Acerca del instructor». Tema y logística = promedio de relevancia del tema y logística del evento ÷ 4 × 100. Cada respuesta pesa igual. El año usa el inicio del curso. «—» = sin evaluaciones. Colores: verde ≥ 3.5 / 8.5 / 85 %, amarillo ≥ 3 / 7 / 70 %, naranja por debajo. Meta: al menos el <?=$meta?> % de los participantes evalúa a cada instructor en cada curso o módulo iniciado.</p>
<?php endif;?>
<div class="pie">CENGICAÑA · Comité de Capacitación</div>
</body></html>
    <?php
    $html=ob_get_clean();
    $opciones=new Dompdf\Options();$opciones->set('isRemoteEnabled',false);$opciones->set('isHtml5ParserEnabled',true);$opciones->set('defaultFont','DejaVu Sans');
    $dompdf=new Dompdf\Dompdf($opciones);$dompdf->loadHtml($html,'UTF-8');$dompdf->setPaper('letter','landscape');$dompdf->render();
    $canvas=$dompdf->getCanvas();$canvas->page_text($canvas->get_width()-90,$canvas->get_height()-24,'Página {PAGE_NUM} de {PAGE_COUNT}',null,7,[0.42,0.48,0.45]);
    cengi_export_enviar_pdf($dompdf->output(),($vista==='participacion'?'participacion-evaluacion':'informe-instructores').($anio?'-'.$anio:'').'.pdf',false);
}

$years=$db->query('SELECT DISTINCT YEAR(inicio) FROM cursos WHERE inicio IS NOT NULL ORDER BY YEAR(inicio) DESC')->fetchAll(PDO::FETCH_COLUMN);
$porPagina=25;
$total=$vista==='participacion'?count($grupos):count($rows);
$pages=max(1,(int)ceil($total/$porPagina));$page=max(1,min($pages,(int)($_GET['pagina']??1)));
$visible=array_slice($rows,($page-1)*$porPagina,$porPagina);
$gruposVisibles=array_slice($grupos,($page-1)*$porPagina,$porPagina);
$filtros=['vista'=>$vista==='participacion'?'participacion':'','anio'=>$anio?:'','q'=>$q,'evaluados'=>$soloEvaluados?1:'','bajo'=>$soloBajo?1:'','orden'=>$orden==='nombre'?'':$orden];
$url=static function(array $extra)use($filtros){return 'informe_instructores_resumen.php?'.http_build_query(array_filter(array_merge($filtros,$extra),static function($v){return $v!==''&&$v!==null;}));};
$hayFiltros=$anio||$q!==''||($vista==='resultados'&&$soloEvaluados)||($vista==='participacion'&&$soloBajo);
require_once __DIR__.'/menu.php';
?>
<!DOCTYPE html><html lang="es"><head><?php cengi_pp_head('Informe consolidado de instructores'); ?>
<link rel="stylesheet" href="css/necesidades_capacitacion.css?v=<?=filemtime(__DIR__.'/css/necesidades_capacitacion.css')?>">
<link rel="stylesheet" href="css/informe_instructores.css?v=<?=filemtime(__DIR__.'/css/informe_instructores.css')?>"></head>
<body class="cengi-canvas cg-tema"><?php menu_render(); ?>
<main class="container nc-page ir-page">
<?php cengi_nav_barra('informe_instructores_resumen.php'); ?>
<?php cengi_banner(null,null,'Instructores · informes',
    '<a class="nc-btn is-claro" href="'.$h($url(['pdf'=>1,'pagina'=>''])).'" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver PDF</a>'
   .'<a class="nc-btn is-linea-claro" href="'.$h($url(['exportar'=>'excel','pagina'=>''])).'"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Excel</a>'); ?>

<nav class="ir-vistas" aria-label="Vistas del informe">
    <a href="<?=$h($url(['vista'=>'','pagina'=>'','bajo'=>'']))?>" class="<?=$vista==='resultados'?'is-active':''?>"<?=$vista==='resultados'?' aria-current="page"':''?>><span class="glyphicon glyphicon-star" aria-hidden="true"></span><span><b>Resultados de la evaluación</b><small>Nota y recomendación por instructor</small></span></a>
    <a href="<?=$h($url(['vista'=>'participacion','pagina'=>'','evaluados'=>'']))?>" class="<?=$vista==='participacion'?'is-active':''?>"<?=$vista==='participacion'?' aria-current="page"':''?>><span class="glyphicon glyphicon-stats" aria-hidden="true"></span><span><b>Participación · meta del <?=$meta?> %</b><small>Respuestas por curso y módulo</small></span><?php if($kpiMeta['en_curso']+$kpiMeta['cerrado']):?><em><?=$kpiMeta['en_curso']+$kpiMeta['cerrado']?> bajo meta</em><?php endif;?></a>
</nav>

<?php if($vista==='participacion'):?>
<div class="nc-kpis ir-kpis">
    <div class="nc-kpi"><b><?=$kpiMeta['evaluables']?></b><span>Evaluaciones a cumplir · cursos o módulos iniciados</span></div>
    <div class="nc-kpi is-verde2"><b class="is-bien"><?=$kpiMeta['cumple']?></b><span>Cumplen la meta del <?=$meta?> %</span></div>
    <div class="nc-kpi is-amarillo"><b class="is-medio"><?=$kpiMeta['en_curso']?></b><span>Bajo la meta · curso en marcha (aún se puede recordar)</span></div>
    <div class="nc-kpi is-naranja"><b class="is-bajo"><?=$kpiMeta['cerrado']?></b><span>Cerraron bajo la meta</span></div>
    <div class="nc-kpi is-gris"><b class="<?=$kpiMeta['porcentaje']===null?'is-sin':($kpiMeta['porcentaje']>=$meta?'is-bien':'is-bajo')?>"><?=$kpiMeta['porcentaje']===null?'—':$kpiMeta['porcentaje'].' %'?></b><span>Participación global</span></div>
</div>
<?php else:?>
<div class="nc-kpis ir-kpis">
    <div class="nc-kpi"><b><?=count($rows)?></b><span>Instructores · <?=count($evaluados)?> con evaluaciones</span></div>
    <div class="nc-kpi is-gris"><b><?=number_format($respuestas)?></b><span>Respuestas de participantes</span></div>
    <?php foreach(array_slice($metricas,0,2) as $i=>[$k,$t,,$suf,$v1,$v2]):?>
    <div class="nc-kpi <?=['is-verde2','is-amarillo'][$i]?>"><b class="<?=$nivel($general[$k],$v1,$v2)?>"><?=$h($formato($general[$k],$suf))?></b><span><?=$h($t)?> · promedio</span></div>
    <?php endforeach;?>
    <a class="nc-kpi is-naranja ir-kpi-link" href="<?=$h($url(['vista'=>'participacion','pagina'=>'','evaluados'=>'']))?>"><b class="<?=$kpiMeta['evaluables']&&$kpiMeta['cumple']===$kpiMeta['evaluables']?'is-bien':($kpiMeta['evaluables']?'is-bajo':'is-sin')?>"><?=$kpiMeta['cumple']?> de <?=$kpiMeta['evaluables']?></b><span>Evaluaciones que cumplen la meta del <?=$meta?> % →</span></a>
</div>
<?php endif;?>

<section class="nc-panel">
<form method="get" class="nc-form">
    <?php if($vista==='participacion'):?><input type="hidden" name="vista" value="participacion"><?php endif;?>
    <label>Año del curso<select class="form-control" name="anio" onchange="this.form.submit()"><option value="">Todos los años</option><?php foreach($years as $y):?><option value="<?=$h($y)?>" <?=$anio===(int)$y?'selected':''?>><?=$h($y)?></option><?php endforeach;?></select></label>
    <label class="nc-buscar">Buscar<span class="nc-search"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input class="form-control" type="search" name="q" value="<?=$h($q)?>" placeholder="Instructor, código, curso o módulo"></span></label>
    <label>Ordenar por<select class="form-control" name="orden" onchange="this.form.submit()"><?php foreach($ordenes as $k=>$t):if($vista==='participacion'&&!in_array($k,['nombre','participacion'],true))continue;?><option value="<?=$k?>" <?=$orden===$k?'selected':''?>><?=$h($t)?></option><?php endforeach;?></select></label>
    <?php if($vista==='participacion'):?>
    <label class="ir-check"><input type="checkbox" name="bajo" value="1" <?=$soloBajo?'checked':''?> onchange="this.form.submit()"> Solo bajo la meta</label>
    <?php else:?>
    <label class="ir-check"><input type="checkbox" name="evaluados" value="1" <?=$soloEvaluados?'checked':''?> onchange="this.form.submit()"> Solo con evaluaciones</label>
    <?php endif;?>
    <button class="nc-btn" type="submit">Buscar</button>
</form>
<?php if($hayFiltros):?><div class="nc-activos"><span>Filtrando por:</span>
    <?php if($anio):?><a class="nc-chip" href="<?=$h($url(['anio'=>'','pagina'=>'']))?>">Año: <b><?=$anio?></b> ✕</a><?php endif;?>
    <?php if($q!==''):?><a class="nc-chip" href="<?=$h($url(['q'=>'','pagina'=>'']))?>">Texto: <b><?=$h($q)?></b> ✕</a><?php endif;?>
    <?php if($vista==='resultados'&&$soloEvaluados):?><a class="nc-chip" href="<?=$h($url(['evaluados'=>'','pagina'=>'']))?>">Solo con evaluaciones ✕</a><?php endif;?>
    <?php if($vista==='participacion'&&$soloBajo):?><a class="nc-chip" href="<?=$h($url(['bajo'=>'','pagina'=>'']))?>">Solo bajo la meta ✕</a><?php endif;?>
    <a class="nc-limpiar" href="<?=$h('informe_instructores_resumen.php'.($vista==='participacion'?'?vista=participacion':''))?>">Quitar todos</a></div><?php endif;?>
</section>

<?php if($vista==='participacion'):?>
<section class="nc-panel ir-meta-config" id="configurar-meta">
    <div class="ir-meta-actual">
        <span class="ir-meta-valor"><?=$meta?><small>%</small></span>
        <div>
            <h2>Meta de participación</h2>
            <p>Porcentaje mínimo de participantes de cada curso o diplomado que debe evaluar a cada instructor. Se aplica aquí y en <b>Seguimiento por curso</b> (aviso, pestaña y recordatorios).</p>
            <small class="ir-muted"><?=$configMeta['actualizado_por']!==''?'Último cambio: '.$h($configMeta['actualizado_por']).' · '.$h(date('d/m/Y H:i',strtotime($configMeta['actualizado']))):'Valor por defecto del sistema ('.CENGI_META_EVAL_INSTRUCTOR.' %).'?></small>
        </div>
    </div>
    <?php if($puedeConfigurarMeta):?>
    <form method="post" action="<?=$h($url(['pagina'=>'']))?>#configurar-meta" class="ir-meta-form">
        <input type="hidden" name="accion" value="guardar_meta"><input type="hidden" name="csrf" value="<?=$h($_SESSION['eval_meta_csrf'])?>">
        <label>Nueva meta<span class="ir-meta-input"><input class="form-control" type="number" name="porcentaje" min="1" max="100" step="1" value="<?=$meta?>" required><b>%</b></span></label>
        <button class="nc-btn" type="submit" onclick="return confirm('¿Cambiar la meta de participación? Se recalculan todos los cursos y el informe.');">Guardar meta</button>
    </form>
    <?php endif;?>
    <?php if($errorMeta!==''):?><div class="alert alert-danger ir-meta-alerta" role="alert"><?=$h($errorMeta)?></div><?php endif;?>
    <?php if(!empty($_GET['meta_guardada'])):?><div class="alert alert-success ir-meta-alerta" role="status">Meta actualizada al <?=$meta?> %. Ya se aplica en este informe y en Seguimiento por curso.</div><?php endif;?>
</section>

<section class="nc-panel">
    <div class="nc-lista-head"><h2>Participación en la evaluación por instructor</h2><span><?=$total?> instructor<?=$total===1?'':'es'?><?=$pages>1?' · página '.$page.' de '.$pages:''?></span></div>
    <?php foreach($gruposVisibles as $g):$m=$metaPorInstructor[$g['id']]??null;$nm=$nivelMeta($m);?>
    <article class="ir-grupo">
        <header class="ir-grupo-head">
            <div><a class="ir-nombre" href="ficha_instructor.php?id=<?=$g['id']?>"><?=$h($g['nombre'])?></a><span class="ir-codigo"><?=$h($g['codigo'])?></span></div>
            <div class="ir-grupo-res">
                <span class="ir-score <?=$nm?>"><?=$h($textoMeta($m))?></span><small>cursos/módulos cumplen</small>
                <span class="ir-grupo-pct">Participación <b><?=$m&&$m['porcentaje']!==null?$m['porcentaje'].' %':'—'?></b></span>
            </div>
        </header>
        <div class="table-responsive"><table class="table ir-table ir-part">
            <thead><tr><th>Curso o diplomado</th><th>Módulo</th><th>Período</th><th class="ir-c">Respuestas</th><th class="ir-part-barra-th">Participación (meta <?=$meta?> %)</th><th>Estado</th><th><span class="sr-only">Acción</span></th></tr></thead>
            <tbody>
            <?php foreach($g['filas'] as $f):[$est,$cls]=$estadosMeta[$f['estado']];?>
            <tr>
                <td><b><?=$h($f['nombre_cursos'])?></b><?php if($f['codigo_curso']):?><span class="ir-codigo"><?=$h($f['codigo_curso'])?></span><?php endif;?></td>
                <td><?=$f['modulo']?$h($f['modulo']):'<span class="ir-muted">Todo el curso</span>'?></td>
                <td class="ir-nowrap"><?=$h($fechaCurso($f['inicio']))?><br><span class="ir-muted"><?=$h($fechaCurso($f['fin']))?></span></td>
                <td class="ir-c"><b><?=$f['respuestas']?></b> <span class="ir-muted">de <?=$f['inscritos']?></span></td>
                <td><?php if($f['inscritos']):?>
                    <span class="ir-part-barra <?=$cls?>"><i style="width:<?=min(100,$f['porcentaje'])?>%"></i><em style="left:<?=$meta?>%"></em></span>
                    <span class="ir-part-num"><b><?=$f['porcentaje']?> %</b><?=$f['cuenta']&&$f['faltan']?' · falta'.($f['faltan']===1?'':'n').' '.$f['faltan']:''?></span>
                <?php else:?><span class="ir-muted">Sin inscritos</span><?php endif;?></td>
                <td><span class="ir-estado <?=$cls?>"><?=$h($est)?></span></td>
                <td class="ir-c"><a class="ir-ir" href="ver_participante_curso.php?id=<?=(int)$f['curso_id']?>#evaluacion" title="Abrir el curso para ver quién falta y enviar recordatorio"><?=$f['estado']==='en_curso'?'Recordar →':'Ver →'?></a></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table></div>
    </article>
    <?php endforeach;?>
    <?php if(!$gruposVisibles):?>
    <div class="nc-vacio"><span class="glyphicon glyphicon-stats" aria-hidden="true"></span><p><b><?=$hayFiltros?'No hay evaluaciones para estos filtros.':'Todavía no hay enlaces de evaluación de instructores.'?></b></p><?php if($hayFiltros):?><a class="nc-btn is-linea" href="informe_instructores_resumen.php?vista=participacion">Quitar filtros</a><?php endif;?></div>
    <?php endif;?>
    <?php if($pages>1):?><nav class="nc-paginacion" aria-label="Paginación">
        <?php if($page>1):?><a href="<?=$h($url(['pagina'=>$page-1]))?>">← Anterior</a><?php endif;?>
        <?php for($n=max(1,$page-3);$n<=min($pages,$page+3);$n++):?><a href="<?=$h($url(['pagina'=>$n]))?>" class="<?=$n===$page?'is-active':''?>"<?=$n===$page?' aria-current="page"':''?>><?=$n?></a><?php endfor;?>
        <?php if($page<$pages):?><a href="<?=$h($url(['pagina'=>$page+1]))?>">Siguiente →</a><?php endif;?>
    </nav><?php endif;?>
    <div class="ir-leyenda"><span><i class="is-bien"></i> Cumple la meta</span><span><i class="is-medio"></i> Bajo la meta · el curso sigue abierto</span><span><i class="is-bajo"></i> Cerró bajo la meta</span><span><i class="is-sin"></i> Por iniciar o sin inscritos (no cuenta)</span></div>
    <p class="nc-note">Meta: al menos el <?=$meta?> % de los participantes activos de cada curso o diplomado evalúa a cada instructor, por curso o por módulo según su enlace de evaluación. Solo cuentan los cursos que ya iniciaron y tienen inscritos. «Recordar →» abre el curso en Seguimiento por curso, donde se ve quién falta y se envía el recordatorio.</p>
</section>

<?php else:?>
<section class="nc-panel">
    <div class="nc-lista-head"><h2>Resultados por instructor</h2><span><?=$total?> instructor<?=$total===1?'':'es'?><?=$pages>1?' · página '.$page.' de '.$pages:''?></span></div>
    <?php if($visible):?>
    <div class="table-responsive"><table class="table ir-table">
        <thead><tr><th>Instructor</th><th>Cursos y módulos asignados</th><th class="ir-c">Respuestas</th>
        <?php foreach($metricas as [,$t,$esc,,,,$corto]):?><th class="ir-c" title="<?=$h($t)?>"><?=$h($corto)?><small><?=$h($esc)?></small></th><?php endforeach;?>
        <th class="ir-c" title="Cursos o módulos iniciados en los que al menos el <?=$meta?> % de los participantes evaluó al instructor">Meta <?=$meta?> %<small>cursos que cumplen</small></th></tr></thead>
        <tbody>
        <?php foreach($visible as $r):$n=count($r['asignaciones']);?>
        <tr class="<?=$r['respuestas']?'':'is-sin-eval'?>">
            <td><a class="ir-nombre" href="ficha_instructor.php?id=<?=(int)$r['id']?>"><?=$h($r['nombre'])?></a><span class="ir-codigo"><?=$h($r['codigo'])?></span></td>
            <td class="ir-asig"><?php if(!$n):?><span class="ir-muted">Sin asignaciones</span>
                <?php elseif($n<=2):?><ul><?php foreach($r['asignaciones'] as $a):?><li><?=$h($a)?></li><?php endforeach;?></ul>
                <?php else:?><ul><?php foreach(array_slice($r['asignaciones'],0,2) as $a):?><li><?=$h($a)?></li><?php endforeach;?></ul>
                    <details><summary>Ver <?=$n-2?> más</summary><ul><?php foreach(array_slice($r['asignaciones'],2) as $a):?><li><?=$h($a)?></li><?php endforeach;?></ul></details><?php endif;?></td>
            <td class="ir-c"><?=$r['respuestas']?'<b>'.$r['respuestas'].'</b>':'<span class="ir-muted">—</span>'?></td>
            <?php foreach($metricas as [$k,,,$suf,$v1,$v2]):$clase=$nivel($r[$k],$v1,$v2);?>
            <td class="ir-c"><?php if($r[$k]===null):?><span class="ir-muted" title="Sin evaluaciones">—</span><?php else:
                $pct=$suf==='%'?(float)$r[$k]:((float)$r[$k]/($suf==='/4'?4:10))*100;?>
                <span class="ir-score <?=$clase?>"><?=$h($formato($r[$k],$suf))?></span><span class="ir-barra <?=$clase?>" aria-hidden="true"><i style="width:<?=max(0,min(100,round($pct)))?>%"></i></span><?php endif;?></td>
            <?php endforeach;?>
            <td class="ir-c"><?php if(!$r['meta']||!$r['meta']['evaluables']):?><span class="ir-muted" title="Sin cursos iniciados con inscritos">—</span><?php else:?>
                <a class="ir-score <?=$nivelMeta($r['meta'])?>" href="<?=$h($url(['vista'=>'participacion','q'=>$r['nombre'],'pagina'=>'','evaluados'=>'']))?>" title="Ver el detalle por curso"><?=$h($textoMeta($r['meta']))?></a>
                <small class="ir-meta-pct"><?=$r['meta']['porcentaje']?> % participación</small><?php endif;?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
    <?php else:?>
    <div class="nc-vacio"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><p><b>No hay instructores para estos filtros.</b></p><?php if($hayFiltros):?><a class="nc-btn is-linea" href="informe_instructores_resumen.php">Quitar filtros</a><?php endif;?></div>
    <?php endif;?>

    <?php if($pages>1):?><nav class="nc-paginacion" aria-label="Paginación">
        <?php if($page>1):?><a href="<?=$h($url(['pagina'=>$page-1]))?>">← Anterior</a><?php endif;?>
        <?php for($n=max(1,$page-3);$n<=min($pages,$page+3);$n++):?><a href="<?=$h($url(['pagina'=>$n]))?>" class="<?=$n===$page?'is-active':''?>"<?=$n===$page?' aria-current="page"':''?>><?=$n?></a><?php endfor;?>
        <?php if($page<$pages):?><a href="<?=$h($url(['pagina'=>$page+1]))?>">Siguiente →</a><?php endif;?>
    </nav><?php endif;?>

    <div class="ir-leyenda"><span><i class="is-bien"></i> Bien (≥ 3.5 · ≥ 8.5 · ≥ 85 %)</span><span><i class="is-medio"></i> Aceptable (≥ 3 · ≥ 7 · ≥ 70 %)</span><span><i class="is-bajo"></i> Por mejorar</span><span>— Sin evaluaciones</span></div>
    <p class="nc-note">Una fila por instructor. La nota promedia los cinco criterios de «Acerca del instructor». Las recomendaciones están en escala 1–10. Tema y logística = promedio de relevancia del tema y logística del evento ÷ 4 × 100. Cada respuesta pesa igual y los promedios generales se ponderan por número de respuestas. «Meta <?=$meta?> %» = cursos o módulos iniciados en los que al menos el <?=$meta?> % de los participantes evaluó al instructor (verde: todos; amarillo: la mitad o más). El filtro de año usa el inicio del curso. Las notas no incluyen exámenes de los participantes.</p>
</section>
<?php endif;?>
</main></body></html>
