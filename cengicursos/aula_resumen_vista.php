<?php if(!isset($db,$assignment,$courseId)){http_response_code(403);exit;}
// Inicio del curso: avisos, la sesion de hoy (o la proxima) con su registro de asistencia, ultima grabacion y pendientes.
$diplomaCount=(int)aula_rows($db,"SELECT COUNT(*) total FROM diplomas WHERE tipo='curso' AND asignacion_id=?",[$assignment['id']])[0]['total'];
if(!$diplomaCount&&!empty($control['diploma']))$diplomaCount=1;
$pendientes=array_values(array_filter($evVisibles,static function($e)use($entregadas,$ahora){return $e['cierra']>$ahora&&empty($entregadas[$e['id']]);}));
usort($pendientes,static function($a,$b){return strcmp($a['cierra'],$b['cierra']);});
$finSesion=static function($s){$fin=$s['fecha'].' '.$s['hora_fin'];return $s['hora_fin']<=$s['hora_inicio']?date('Y-m-d H:i:s',strtotime($fin.' +1 day')):$fin;};
// Sesion destacada: la que tiene el registro abierto; si no, la de hoy o la proxima.
$foco=$abiertas[0]??null;
if(!$foco)foreach($sessions as $s){if($finSesion($s)>=$ahora||$s['fecha']===$hoy){$foco=$s;break;}}
$focoN=0;$siguientes=[];foreach($sessions as $i=>$s){if($foco&&(int)$s['id']===(int)$foco['id'])$focoN=$i+1;elseif($focoN&&count($siguientes)<3)$siguientes[]=[$i+1,$s];}
$recursos=$ready&&$ver('sesiones')?array_values(array_filter(aula_resources($db,$courseId),static function($r)use($hoy){return $r['sesion_id']&&$r['fecha']<=$hoy&&aula_recurso_visible($r);})):[];
$ultimaGrabacion=null;foreach($recursos as $r)if($r['tipo']==='video')$ultimaGrabacion=$r;
$focoRecursos=$foco?count(array_filter($recursos,static function($r)use($foco){return (int)$r['sesion_id']===(int)$foco['id'];})):0;
$teams=aula_teams_url($db,$courseId);
$impartidas=aula_sesiones_impartidas($db,$courseId,$assignment['id']);
?>
<?php foreach (aula_anuncios($db, $courseId, true) as $anuncioCurso) if (!$anuncioCurso['sesion_id'] || ($foco && (int)$anuncioCurso['sesion_id']===(int)$foco['id'])) echo aula_anuncio_html($anuncioCurso); ?>
<?php if (trim($vista['anuncio_titulo'].$vista['anuncio'])!==''): ?><section class="aula-anuncio" aria-label="Anuncio del curso"><span class="glyphicon glyphicon-bullhorn" aria-hidden="true"></span><div><?php if ($vista['anuncio_titulo']!==''): ?><h2><?= aula_h($vista['anuncio_titulo']) ?></h2><?php endif; ?><?php if ($vista['anuncio']!==''): ?><p><?= nl2br(aula_h($vista['anuncio'])) ?></p><?php endif; ?></div></section><?php endif; ?>

<div class="aula-overview is-4">
    <div><strong><?= is_numeric($control['asistencia']??null)?aula_h(round((float)$control['asistencia'])).'%':'—' ?></strong><span>Mi asistencia (sobre las sesiones impartidas)</span></div>
    <div><strong><?= (int)($control['sesiones_asistidas']??count($registered)) ?> / <?= $impartidas ?></strong><span>Sesiones asistidas de las impartidas (<?= count($sessions) ?> en el programa)</span></div>
    <div><strong><?= count($pendientes) ?></strong><span>Tareas y evaluaciones pendientes</span></div>
    <div><strong><?= is_numeric($control['posevaluacion']??null)?aula_h($control['posevaluacion']):'—' ?></strong><span>Nota final<?= is_numeric($control['posevaluacion']??null)?' / 100':' (pendiente)' ?></span></div>
</div>

<?php if($diplomaCount&&$ver('diplomas')): ?><div class="aula-diploma-ready"><span class="glyphicon glyphicon-certificate" aria-hidden="true"></span><div><strong>Tu diploma está disponible</strong><p>Emitido por Coordinación de Capacitación.</p></div><a class="btn btn-primary" href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=resultados">Ver mi diploma →</a></div><?php endif; ?>

<div class="aula-inicio">
<?php if($foco): $w=$windows[$foco['id']]??null;$abierta=$ready&&aula_session_window($foco,$w);$registrada=isset($registered[$foco['id']]);$esHoy=$foco['fecha']===$hoy;$sesTeams=aula_teams_url($db,$courseId,$foco); ?>
<section class="aula-card aula-foco<?= $abierta&&!$registrada?' is-abierta':'' ?>">
    <span class="aula-foco-etiqueta"><?= $abierta?'Asistencia abierta ahora':($esHoy?'Sesión de hoy':'Próxima sesión') ?></span>
    <h2><span class="aula-session-number"><?= $focoN ?></span> <?= aula_h($foco['tema']) ?></h2>
    <p class="aula-foco-cuando"><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?= aula_h(aula_fecha_larga($foco['fecha'])) ?> · <span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?= aula_h(substr($foco['hora_inicio'],0,5).'–'.substr($foco['hora_fin'],0,5)) ?><?= !empty($foco['modalidad'])?' · '.aula_h($foco['modalidad']):'' ?></p>
    <div class="aula-foco-acciones">
    <?php if($registrada): ?><span class="aula-badge is-complete">✓ Tu asistencia ya está registrada</span>
    <?php elseif($abierta): ?><form method="post" class="aula-foco-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="asistencia"><input type="hidden" name="sesion" value="<?= (int)$foco['id'] ?>"><?php if($w&&$w['codigo_hash']!==''): ?><label>Código de la sesión<input name="codigo" maxlength="30" required autocomplete="off"></label><?php endif; ?><button class="btn btn-primary"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Registrar mi asistencia</button></form>
    <?php else: ?><span class="aula-muted">El registro de asistencia se habilita durante el horario de la sesión.</span><?php endif; ?>
    <?php if($sesTeams): ?><a class="btn btn-default aula-teams-button" href="<?= aula_h($sesTeams) ?>" target="_blank" rel="noopener noreferrer"><span class="glyphicon glyphicon-facetime-video" aria-hidden="true"></span> Entrar a Teams</a><?php endif; ?>
    <?php if(isset($tabs['sesiones'])): ?><a href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=sesiones#ses-<?= (int)$foco['id'] ?>">Ver la sesión<?= $focoRecursos?' · '.$focoRecursos.' recurso(s)':'' ?> →</a><?php endif; ?>
    </div>
    <?php if($siguientes): ?><div class="aula-foco-siguientes"><strong>Después</strong><?php foreach($siguientes as [$n,$s]): ?><span><b><?= $n ?>.</b> <?= aula_h(aula_fecha_larga($s['fecha'],true).' · '.substr($s['hora_inicio'],0,5)) ?> — <?= aula_h($s['tema']) ?></span><?php endforeach; ?></div><?php endif; ?>
</section>
<?php elseif($sessions): ?><section class="aula-card aula-foco"><span class="aula-foco-etiqueta">Curso finalizado</span><h2>Todas las sesiones ya se realizaron</h2><p>Puedes repasar las grabaciones y materiales en Sesiones y grabaciones.</p></section>
<?php else: ?><section class="aula-card aula-foco"><h2>Aún no hay sesiones programadas</h2><p>Coordinación publicará el calendario del curso.</p></section><?php endif; ?>

<div class="aula-inicio-lado">
<?php if($teams&&(!$foco||!aula_teams_url($db,$courseId,$foco))): ?><div class="aula-connection-card"><span class="glyphicon glyphicon-facetime-video" aria-hidden="true"></span><div><strong>Sesiones en Teams</strong><p>Este enlace se utiliza para las sesiones del curso.</p></div><a class="btn btn-primary" href="<?= aula_h($teams) ?>" target="_blank" rel="noopener noreferrer">Entrar a Teams ↗</a></div><?php endif; ?>
<section class="aula-card"><h2>Por entregar</h2>
<?php foreach(array_slice($pendientes,0,4) as $task): $abierto=$task['abre']<=$ahora; ?><div class="aula-row"><div><strong><?= aula_h($task['titulo']) ?></strong><p><?= $abierto?'Cierra '.aula_h(aula_fecha_larga($task['cierra'],true).' '.substr($task['cierra'],11,5)):'Se abre '.aula_h(aula_fecha_larga($task['abre'],true).' '.substr($task['abre'],11,5)) ?></p></div><span class="aula-badge <?= $abierto?'is-pending':'' ?>"><?= $task['tipo_actividad']==='evaluacion'?'Evaluación':'Tarea' ?></span></div><?php endforeach; ?>
<?php if(!$pendientes): ?><p>No tienes entregas pendientes. ✓</p><?php elseif(isset($tabs['actividades'])): ?><a href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=actividades">Ver todas →</a><?php endif; ?>
</section>
</div>
</div>

<?php if($ultimaGrabacion): ?><section class="aula-card aula-ultima-grabacion"><h2>Última grabación publicada <small><?= aula_h($ultimaGrabacion['tema'].' · '.aula_fecha_larga($ultimaGrabacion['fecha'],true)) ?></small></h2>
<?php $rep=aula_video_html($ultimaGrabacion['titulo'],$ultimaGrabacion['url'],$ultimaGrabacion['archivo_id'],$ultimaGrabacion['mime']??''); if($rep): echo $rep; elseif($ultimaGrabacion['archivo_id']): ?><a href="aula_archivo.php?id=<?= (int)$ultimaGrabacion['archivo_id'] ?>">Abrir video</a><?php elseif(aula_url($ultimaGrabacion['url'])): ?><a href="<?= aula_h($ultimaGrabacion['url']) ?>" target="_blank" rel="noopener noreferrer">Abrir grabación ↗</a><?php endif; ?>
<p><a href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=sesiones">Todas las grabaciones y materiales →</a></p></section><?php endif; ?>
