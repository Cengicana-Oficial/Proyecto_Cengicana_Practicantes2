<?php
require_once __DIR__.'/planificacion_helpers.php';
require_once __DIR__.'/revisar_permisos.php';
cengi_require_gestionar_instructores();
if(cengi_es_administrador_ingenio()){http_response_code(403);exit('Acceso exclusivo de Capacitación.');}
$db=conectar();
$h=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};

// Filtros: año del curso de origen, área, curso y texto libre.
$anio=(int)($_GET['anio']??0);
$areaFiltro=mb_substr(trim((string)($_GET['area']??'')),0,255);
$cursoFiltro=mb_substr(trim((string)($_GET['curso']??'')),0,255);
$q=mb_substr(trim((string)($_GET['q']??'')),0,200);

$from=" FROM evaluaciones_instructor ei LEFT JOIN enlaces_evaluacion_instructor e ON e.id=ei.enlace_id LEFT JOIN cursos c ON c.id=e.curso_id LEFT JOIN ingenios i ON i.id=ei.ingenio_id";
$base=" WHERE TRIM(COALESCE(ei.capacitaciones_necesarias,''))<>''";
$year="YEAR(COALESCE(ei.fecha_curso,c.inicio,ei.creado))";
$area="COALESCE(NULLIF(TRIM(ei.area_participante),''),CASE WHEN LOWER(TRIM(ei.seccion)) NOT IN ('única','unica') THEN NULLIF(TRIM(ei.seccion),'') END,'Sin registrar')";
$curso="COALESCE(NULLIF(TRIM(ei.curso_nombre),''),c.nombre_cursos,'Sin registrar')";
$ingenio="COALESCE(i.nombre_ingenios,NULLIF(ei.ingenio_otro,''))";
$years=$db->query("SELECT DISTINCT $year AS anio $from $base ORDER BY anio DESC")->fetchAll(PDO::FETCH_COLUMN);

$params=[];
if($anio){$base.=" AND $year=?";$params[]=$anio;}
// Los rankings se calculan con año y búsqueda, pero sin su propio filtro, para poder cambiar de área o curso.
$condQ='';$paramsQ=[];
if($q!==''){$condQ=" AND (ei.cargo LIKE ? OR $area LIKE ? OR $curso LIKE ? OR ei.modulo_nombre LIKE ? OR ei.capacitaciones_necesarias LIKE ? OR $ingenio LIKE ?)";$paramsQ=array_fill(0,6,'%'.$q.'%');}
$condArea=$areaFiltro!==''?" AND $area=?":'';
$condCurso=$cursoFiltro!==''?" AND $curso=?":'';
$where=$base.$condQ.$condArea.$condCurso;
$paramsWhere=array_merge($params,$paramsQ,$areaFiltro!==''?[$areaFiltro]:[],$cursoFiltro!==''?[$cursoFiltro]:[]);

$cols="SELECT $year AS anio,ei.cargo,$area AS area,$ingenio AS ingenio,$curso AS curso,ei.modulo_nombre AS modulo,ei.capacitaciones_necesarias AS comentario";
if(in_array($_GET['exportar']??'',['excel','csv'],true)){
    require_once __DIR__.'/classes/export_helpers.php';
    $s=$db->prepare($cols.$from.$where.' ORDER BY anio DESC,ei.id DESC');$s->execute($paramsWhere);
    $filas=[];
    while($r=$s->fetch(PDO::FETCH_ASSOC)){
        $filas[]=[$r['anio'],$r['comentario'],$r['cargo']?:'Sin registrar',$r['area'],$r['ingenio']?:'—',$r['curso'].($r['modulo']?' · '.$r['modulo']:'')];
    }
    cengi_export_enviar_excel(['Año','Capacitaciones necesarias','Cargo del participante','Área','Ingenio / institución','Curso o diplomado de origen'],$filas,'Necesidades','necesidades-capacitacion'.($anio?'-'.$anio:''),[8,70,26,24,24,40],'xlsx');
}

$s=$db->prepare('SELECT COUNT(*),COUNT(DISTINCT '.$area.'),COUNT(DISTINCT '.$curso.'),COUNT(DISTINCT NULLIF(TRIM(ei.cargo),\'\'))'.$from.$where);$s->execute($paramsWhere);
[$total,$totalAreas,$totalCursos,$totalCargos]=array_map('intval',$s->fetch(PDO::FETCH_NUM));
$ranking=static function($expr,$condExtra,$paramsExtra)use($db,$from,$base,$params,$condQ,$paramsQ){
    $s=$db->prepare("SELECT $expr AS nombre,COUNT(*) AS n".$from.$base.$condQ.$condExtra." GROUP BY nombre ORDER BY n DESC,nombre LIMIT 8");
    $s->execute(array_merge($params,$paramsQ,$paramsExtra));return $s->fetchAll(PDO::FETCH_ASSOC);
};
$porArea=$ranking($area,$condCurso,$cursoFiltro!==''?[$cursoFiltro]:[]);
$porCurso=$ranking($curso,$condArea,$areaFiltro!==''?[$areaFiltro]:[]);

$porPagina=30;
$pages=max(1,(int)ceil($total/$porPagina));$page=max(1,min($pages,(int)($_GET['pagina']??1)));$offset=($page-1)*$porPagina;
$s=$db->prepare($cols.$from.$where." ORDER BY anio DESC,ei.id DESC LIMIT $porPagina OFFSET $offset");$s->execute($paramsWhere);$rows=$s->fetchAll(PDO::FETCH_ASSOC);

$filtros=['anio'=>$anio?:'','area'=>$areaFiltro,'curso'=>$cursoFiltro,'q'=>$q];
$url=static function(array $extra)use($filtros){return 'necesidades_capacitacion.php?'.http_build_query(array_filter(array_merge($filtros,$extra),static function($v){return $v!==''&&$v!==null&&$v!==0;}));};
$hayFiltros=$anio||$areaFiltro!==''||$cursoFiltro!==''||$q!=='';
// Resalta el término buscado dentro del comentario (sobre el texto ya escapado).
$resaltar=static function($texto)use($h,$q){$t=$h($texto);return $q===''?$t:preg_replace('/'.preg_quote($h($q),'/').'/iu','<mark>$0</mark>',$t);};
require_once __DIR__.'/menu.php';
?>
<!DOCTYPE html><html lang="es"><head><?php cengi_pp_head('Necesidades de capacitación'); ?><link rel="stylesheet" href="css/necesidades_capacitacion.css?v=<?=filemtime(__DIR__.'/css/necesidades_capacitacion.css')?>"></head>
<body class="cengi-canvas cg-tema"><?php menu_render(); ?>
<main class="container nc-page">
<?php cengi_nav_barra('necesidades_capacitacion.php'); ?>
<?php cengi_banner(null,null,'Instructores · planificación',
    '<a class="nc-btn is-claro" href="'.$h($url(['exportar'=>'excel','pagina'=>''])).'"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Exportar a Excel</a>'); ?>

<div class="nc-kpis">
    <div class="nc-kpi is-verde"><b><?=number_format($total)?></b><span>Comentarios con necesidades</span></div>
    <div class="nc-kpi is-verde2"><b><?=number_format($totalAreas)?></b><span>Áreas que solicitan</span></div>
    <div class="nc-kpi is-amarillo"><b><?=number_format($totalCursos)?></b><span>Cursos de origen</span></div>
    <div class="nc-kpi is-naranja"><b><?=number_format($totalCargos)?></b><span>Cargos distintos</span></div>
</div>

<section class="nc-panel nc-filtros">
<form method="get" class="nc-form">
    <label>Año del curso<select class="form-control" name="anio" onchange="this.form.submit()"><option value="">Todos los años</option><?php foreach($years as $y):if(!$y)continue;?><option value="<?=$h($y)?>" <?=$anio===(int)$y?'selected':''?>><?=$h($y)?></option><?php endforeach;?></select></label>
    <label class="nc-buscar">Buscar<span class="nc-search"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input class="form-control" type="search" name="q" value="<?=$h($q)?>" placeholder="Tema, cargo, área, curso o ingenio"></span></label>
    <?php if($areaFiltro!==''):?><input type="hidden" name="area" value="<?=$h($areaFiltro)?>"><?php endif;?>
    <?php if($cursoFiltro!==''):?><input type="hidden" name="curso" value="<?=$h($cursoFiltro)?>"><?php endif;?>
    <button class="nc-btn" type="submit">Buscar</button>
</form>
<?php if($hayFiltros):?><div class="nc-activos"><span>Filtrando por:</span>
    <?php foreach(['anio'=>'Año','area'=>'Área','curso'=>'Curso','q'=>'Texto'] as $k=>$label):if($filtros[$k]==='')continue;?>
        <a class="nc-chip" href="<?=$h($url([$k=>'','pagina'=>'']))?>" title="Quitar filtro"><?=$h($label)?>: <b><?=$h($filtros[$k])?></b> ✕</a>
    <?php endforeach;?>
    <a class="nc-limpiar" href="necesidades_capacitacion.php">Quitar todos</a></div><?php endif;?>
</section>

<?php if($total>0):?>
<div class="nc-rankings">
<?php foreach([['Áreas que más solicitan','area',$porArea,$areaFiltro,'verde'],['Cursos donde surgieron','curso',$porCurso,$cursoFiltro,'amarillo']] as [$titulo,$clave,$lista,$activo,$tono]):$max=max(1,(int)($lista[0]['n']??1));?>
    <section class="nc-panel nc-ranking is-<?=$tono?>"><h2><?=$h($titulo)?></h2><ol>
    <?php foreach($lista as $it):$sel=$activo!==''&&$activo===$it['nombre'];?>
        <li><a href="<?=$h($url([$clave=>$sel?'':$it['nombre'],'pagina'=>'']))?>" class="<?=$sel?'is-active':''?>" title="<?=$sel?'Quitar filtro':'Filtrar por '.$h($it['nombre'])?>">
            <span class="nc-rk-nombre"><?=$h($it['nombre'])?></span><span class="nc-rk-n"><?=(int)$it['n']?></span>
            <span class="nc-rk-barra" aria-hidden="true"><i style="width:<?=round(100*$it['n']/$max)?>%"></i></span></a></li>
    <?php endforeach;?></ol></section>
<?php endforeach;?>
</div>
<?php endif;?>

<section class="nc-panel">
    <div class="nc-lista-head"><h2>Comentarios de los participantes</h2><span><?=number_format($total)?> resultado<?=$total===1?'':'s'?><?=$pages>1?' · página '.$page.' de '.$pages:''?></span></div>
    <?php if($rows):?>
    <ul class="nc-lista">
    <?php foreach($rows as $r):?>
        <li class="nc-item">
            <p class="nc-comentario"><?=$resaltar($r['comentario'])?></p>
            <div class="nc-meta">
                <span class="nc-tag is-anio"><?=$h($r['anio']?:'Sin año')?></span>
                <a class="nc-tag" href="<?=$h($url(['area'=>$r['area'],'pagina'=>'']))?>" title="Filtrar por esta área"><span class="glyphicon glyphicon-map-marker" aria-hidden="true"></span> <?=$h($r['area'])?></a>
                <span class="nc-dato"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> <?=$h($r['cargo']?:'Cargo sin registrar')?><?=$r['ingenio']?' · '.$h($r['ingenio']):''?></span>
                <a class="nc-dato nc-curso" href="<?=$h($url(['curso'=>$r['curso'],'pagina'=>'']))?>" title="Filtrar por este curso"><span class="glyphicon glyphicon-education" aria-hidden="true"></span> <?=$h($r['curso'])?><?=$r['modulo']?' · '.$h($r['modulo']):''?></a>
            </div>
        </li>
    <?php endforeach;?>
    </ul>
    <?php elseif($hayFiltros):?>
    <div class="nc-vacio"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><p><b>No hay comentarios con estos filtros.</b></p><a class="nc-btn is-linea" href="necesidades_capacitacion.php">Quitar filtros</a></div>
    <?php else:?>
    <div class="nc-vacio"><span class="glyphicon glyphicon-comment" aria-hidden="true"></span><p><b>Todavía no hay comentarios registrados.</b></p>
        <p>Aparecen aquí cuando los participantes responden la pregunta <i>«¿Qué otras capacitaciones considera necesarias?»</i> en la evaluación del instructor, ya sea desde el enlace de evaluación o con la carga masiva de evaluaciones.</p>
        <a class="nc-btn is-linea" href="instructores.php">Ir a Instructores</a></div>
    <?php endif;?>

    <?php if($pages>1):?><nav class="nc-paginacion" aria-label="Paginación">
        <?php if($page>1):?><a href="<?=$h($url(['pagina'=>$page-1]))?>">← Anterior</a><?php endif;?>
        <?php for($n=max(1,$page-3);$n<=min($pages,$page+3);$n++):?><a href="<?=$h($url(['pagina'=>$n]))?>" class="<?=$n===$page?'is-active':''?>"<?=$n===$page?' aria-current="page"':''?>><?=$n?></a><?php endfor;?>
        <?php if($page<$pages):?><a href="<?=$h($url(['pagina'=>$page+1]))?>">Siguiente →</a><?php endif;?>
    </nav><?php endif;?>
    <p class="nc-note">El año es el del curso de origen y sirve para planificar el siguiente. El área se toma de la ficha del participante al responder; los datos históricos sin área identificable aparecen como «Sin registrar», y las cargas masivas usan la sección como área cuando contiene un área específica.</p>
</section>
</main></body></html>
