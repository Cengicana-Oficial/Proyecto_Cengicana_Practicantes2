<?php if(!isset($db,$courseId,$assignment)){http_response_code(403);exit;}
// Ruta de aprendizaje: sesiones con grabaciones, materiales, actividades y docente, mas el material general del curso.
// Con muchas sesiones se filtra en el navegador (js/mi_aula.js) y se abre la de hoy o la proxima.
$resources=$ready?aula_resources($db,$courseId):[];
$links=aula_instructor_links($db,$courseId);
$anunciosSesion=[];foreach(aula_anuncios($db,$courseId,true) as $an)if($an['sesion_id'])$anunciosSesion[(int)$an['sesion_id']][]=$an;
$recSesion=[];foreach($resources as $r)if($r['sesion_id']&&aula_recurso_visible($r))$recSesion[(int)$r['sesion_id']][]=$r;
$evSesion=[];foreach($evVisibles as $e)if($e['sesion_id'])$evSesion[(int)$e['sesion_id']][]=$e;
$docSesion=[];foreach($links as $link)$docSesion[(int)$link['sesion_id']][$link['instructor']]=true;
$abrir=0;foreach($sessions as $s){if($s['fecha']>=$hoy){$abrir=(int)$s['id'];break;}}
if(!$abrir&&$sessions)$abrir=(int)end($sessions)['id'];
?>
<?php if($ver('sesiones')): ?>
<section class="aula-card"><h2>Ruta de aprendizaje</h2><p>Sesiones en orden, con sus grabaciones, materiales, actividades y docente.<?php if(isset($tabs['instructores'])): ?> La <a href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=instructores">evaluación del instructor</a> se responde una sola vez al habilitarse.<?php else: ?> La evaluación del instructor se responde una sola vez al habilitarse.<?php endif; ?></p>
<?php if(count($sessions)>4): ?>
<div class="aula-filtros" data-filtros=".aula-session-list > .aula-session">
    <input type="search" data-filtro-texto placeholder="Buscar por tema, módulo o fecha (dd/mm)" aria-label="Buscar sesión">
    <span class="aula-filtros-chips" role="group" aria-label="Filtrar sesiones">
        <button type="button" class="is-activo" data-filtro="todas">Todas <b><?= count($sessions) ?></b></button>
        <button type="button" data-filtro="proxima">Próximas <b data-filtro-cuenta="proxima"></b></button>
        <button type="button" data-filtro="realizada">Realizadas <b data-filtro-cuenta="realizada"></b></button>
        <button type="button" data-filtro="recursos">Con grabación o material <b data-filtro-cuenta="recursos"></b></button>
    </span>
    <?php if($abrir): ?><a class="btn btn-default btn-sm" href="#ses-<?= $abrir ?>" data-ir-sesion><span class="glyphicon glyphicon-screenshot" aria-hidden="true"></span> Ir a la sesión de hoy / próxima</a><?php endif; ?>
</div>
<p class="aula-muted" data-filtro-vacio hidden>Ninguna sesión coincide con la búsqueda.</p>
<?php endif; ?>
<div class="aula-session-list"><?php foreach($sessions as $index=>$s): $sid=(int)$s['id'];$w=$windows[$sid]??null;$recs=$recSesion[$sid]??[];$evs=$evSesion[$sid]??[];
    $videos=count(array_filter($recs,static function($r){return $r['tipo']==='video';}));$materiales=count($recs)-$videos;
    $estado=$s['fecha']>$hoy?'proxima':'realizada';$tags=$estado.($recs?' recursos':'');
    $abierta=aula_session_window($s,$w);$registrada=isset($registered[$sid]); ?>
<details class="aula-session<?= $s['fecha']===$hoy?' is-hoy':'' ?>" id="ses-<?= $sid ?>" data-tags="<?= $tags ?>" data-texto="<?= aula_h(mb_strtolower($s['tema'].' '.($s['modulo']??'').' '.date('d/m/Y',strtotime($s['fecha'])).' '.implode(' ',array_keys($docSesion[$sid]??[])))) ?>" <?= $sid===$abrir?'open':'' ?>><summary><span class="aula-session-number"><?= $index+1 ?></span><span><strong><?= aula_h($s['tema']) ?></strong><small><?= aula_h(aula_fecha_larga($s['fecha']).' · '.substr($s['hora_inicio'],0,5).'–'.substr($s['hora_fin'],0,5).(!empty($s['modulo'])?' · '.$s['modulo']:'')) ?></small></span>
    <span class="aula-session-indicadores"><?php if($videos): ?><span title="Grabaciones"><span class="glyphicon glyphicon-film" aria-hidden="true"></span> <?= $videos ?></span><?php endif; ?><?php if($materiales): ?><span title="Materiales"><span class="glyphicon glyphicon-paperclip" aria-hidden="true"></span> <?= $materiales ?></span><?php endif; ?><?php if($evs): ?><span title="Actividades"><span class="glyphicon glyphicon-check" aria-hidden="true"></span> <?= count($evs) ?></span><?php endif; ?></span>
    <span class="aula-badge <?= $registrada?'is-complete':($abierta?'is-pending':'') ?>"><?= $registrada?'✓ Asistencia registrada':($abierta?'Asistencia abierta':($s['fecha']===$hoy?'Hoy':($estado==='proxima'?'Programada':'Realizada'))) ?></span></summary>
<div class="aula-session-content">
<div class="aula-session-acciones">
<?php if($abierta&&!$registrada&&isset($tabs['asistencia'])): ?><a class="btn btn-primary btn-sm" href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=asistencia">Registrar asistencia</a><?php endif; ?>
<?php $sessionTeams=aula_teams_url($db,$courseId,$s);if($sessionTeams&&$s['fecha']>=$hoy): ?><a class="btn btn-default btn-sm aula-teams-button" href="<?= aula_h($sessionTeams) ?>" target="_blank" rel="noopener noreferrer"><span class="glyphicon glyphicon-facetime-video" aria-hidden="true"></span> Entrar a la sesión en Teams</a><?php endif; ?>
<?php foreach(array_keys($docSesion[$sid]??[]) as $docente): ?><span class="aula-muted">Docente: <strong><?= aula_h($docente) ?></strong></span><?php endforeach; ?>
</div>
<?php foreach($anunciosSesion[$sid]??[] as $anuncioSes)echo aula_anuncio_html($anuncioSes); ?>
<?php if($recs): ?><div class="aula-courses aula-recursos-sesion"><?php foreach($recs as $r): $reproductor=aula_video_html($r['titulo'],$r['url'],$r['archivo_id'],$r['mime']??''); ?><article class="aula-resource-card<?= $reproductor?' is-video':'' ?>"><span class="aula-badge"><?= $r['tipo']==='video'?'Grabación':'Material' ?></span><h3><?= aula_h($r['titulo']) ?></h3>
<?php if($reproductor): echo $reproductor; elseif($r['archivo_id']): ?><a href="aula_archivo.php?id=<?= (int)$r['archivo_id'] ?>">Abrir <?= $r['tipo']==='video'?'video':'documento' ?></a><?php elseif(aula_url($r['url'])): ?><a href="<?= aula_h($r['url']) ?>" target="_blank" rel="noopener noreferrer">Abrir recurso ↗</a><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
<?php foreach($evs as $e): $done=!empty($entregadas[$e['id']]); ?>
<div class="aula-row"><div><strong><?= aula_h($e['titulo']) ?></strong><p><?= ($e['tipo_actividad']==='evaluacion'?'Evaluación':'Tarea').' · Cierre: '.aula_h(aula_fecha_larga($e['cierra'],true).' '.substr($e['cierra'],11,5)) ?></p></div><span class="aula-badge <?= $done?'is-complete':'is-pending' ?>"><?= $done?'✓ Entregada':($e['cierra']<=$ahora?'Cerrada':'Pendiente') ?></span><?php if(isset($tabs['actividades'])): ?><a href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=actividades#act-<?= (int)$e['id'] ?>">Ver actividad →</a><?php endif; ?></div><?php endforeach; ?>
<?php if(!$recs&&!$evs): ?><p class="aula-muted"><?= $estado==='proxima'?'Las grabaciones y materiales se publican después de la sesión.':'Todavía no hay recursos o actividades publicados para esta sesión.' ?></p><?php endif; ?>
</div></details><?php endforeach; ?></div><?php if(!$sessions): ?><p>Las sesiones todavía no se han programado.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php if($ver('material')):
$oculto=aula_vista_ready($db)?' OR '.aula_recurso_oculto_sql('r'):'';
$materials=aula_rows($db,'SELECT * FROM curso_contenidos WHERE curso_id=? AND NOT EXISTS(SELECT 1 FROM aula_recursos r WHERE r.contenido_id=curso_contenidos.id AND (r.sesion_id IS NOT NULL'.$oculto.')) ORDER BY orden,id',[$courseId]);
$materials=array_values(array_filter($materials,static function($m){return (bool)aula_url($m['url']);}));
$files=$ready?aula_rows($db,'SELECT id,titulo,nombre,mime FROM aula_archivos WHERE curso_id=? AND intento_id IS NULL AND NOT EXISTS(SELECT 1 FROM aula_recursos r WHERE r.archivo_id=aula_archivos.id AND (r.sesion_id IS NOT NULL'.$oculto.')) ORDER BY creado DESC',[$courseId]):[];
if($materials||$files||!$ver('sesiones')): ?>
<section class="aula-card" id="material"><h2>Material general del curso</h2><p>Recursos que no pertenecen a una sesión en particular.</p>
<div class="aula-courses"><?php foreach($materials as $m): $url=aula_url($m['url']); $reproductor=aula_video_html($m['titulo'],$url); ?><article class="aula-resource-card<?= $reproductor?' is-video':'' ?>"><span class="aula-badge"><?= $reproductor?'Video':'Enlace' ?></span><h3><?= aula_h($m['titulo']) ?></h3><?php if($reproductor): echo $reproductor; else: ?><a href="<?= aula_h($url) ?>" target="_blank" rel="noopener noreferrer">Abrir recurso ↗</a><?php endif; ?></article><?php endforeach; ?>
<?php foreach($files as $f): ?><article class="aula-resource-card<?= $f['mime']==='video/mp4'?' is-video':'' ?>"><span class="aula-badge"><?= $f['mime']==='video/mp4'?'Video':'Documento' ?></span><h3><?= aula_h($f['titulo']) ?></h3><?php if($f['mime']==='video/mp4'): echo aula_video_html($f['titulo'],'',$f['id'],$f['mime']); else: ?><a href="aula_archivo.php?id=<?= (int)$f['id'] ?>">Descargar <?= aula_h($f['nombre']) ?></a><?php endif; ?></article><?php endforeach; ?></div>
<?php if(!$materials&&!$files): ?><p class="aula-muted">El docente aún no ha publicado materiales.</p><?php endif; ?></section>
<?php endif; endif; ?>
