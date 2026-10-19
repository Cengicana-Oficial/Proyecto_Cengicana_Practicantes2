<?php if(!isset($db,$courses)){http_response_code(403);exit;}
require_once __DIR__.'/aula_portadas.php';
$profile=[];$totalPending=0;$totalDiplomas=0;
foreach($courses as $c) {
    $a=!empty($preview)?(aula_rows($db,'SELECT * FROM asignaciones WHERE cursos_id=? AND participantes_id=? AND estado_asignaciones=1 LIMIT 1',[$c['id'],$previewStudentId])[0]??null):aula_assignment($db,$c['id']);
    if(!$a)continue;
    if($ready&&empty($preview)){aula_expire($db,$c['id'],$a['id']);aula_sync_course($db,$c['id'],$a['id']);}
    $cc=aula_rows($db,'SELECT * FROM control_cursos WHERE asignacion_id=?',[$a['id']])[0]??[];
    $schedule=aula_rows($db,'SELECT s.* FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? AND s.estado<>\'cancelada\' ORDER BY s.fecha,s.hora_inicio',[$c['id']]);
    $next=null;foreach($schedule as $s)if($s['fecha'].' '.$s['hora_fin']>=date('Y-m-d H:i:s')){$next=$s;break;}
    $diplomas=(int)aula_rows($db,"SELECT COUNT(*) total FROM diplomas WHERE tipo='curso' AND asignacion_id=?",[$a['id']])[0]['total'];
    if(!$diplomas&&!empty($cc['diploma']))$diplomas=1;
    $pending=$ready?(int)aula_rows($db,'SELECT COUNT(*) total FROM aula_evaluaciones e WHERE e.curso_id=? AND e.tipo_actividad<>\'evaluacion\' AND e.habilitada=1 AND e.cierra>=? AND NOT EXISTS(SELECT 1 FROM aula_intentos t WHERE t.evaluacion_id=e.id AND t.asignacion_id=? AND t.entregado IS NOT NULL AND t.entrega_usuario=1)',[$c['id'],date('Y-m-d H:i:s'),$a['id']])[0]['total']:0;
    $state=$c['inicio']&&$c['inicio']>date('Y-m-d')?'Próximo':($c['fin']&&$c['fin']<date('Y-m-d')?'Finalizado':'En curso');
    $profile[]=['curso'=>$c,'control'=>$cc,'total'=>count($schedule),'impartidas'=>aula_sesiones_impartidas($db,$c['id'],$a['id']),'proxima'=>$next,'diplomas'=>$diplomas,'pendientes'=>$pending,'estado'=>$state];$totalPending+=$pending;$totalDiplomas+=$diplomas;
}
?>
<div class="aula-overview"><div><strong><?= count($courses) ?></strong><span>Cursos asignados</span></div><div><strong><?= $totalPending ?></strong><span>Tareas y actividades pendientes</span></div><div><strong><?= $totalDiplomas ?></strong><span>Diplomas disponibles</span></div></div>
<div class="aula-course-filter"><label for="aula-course-search">Buscar mis cursos</label><input id="aula-course-search" type="search" placeholder="Nombre del curso..."><select id="aula-course-state" aria-label="Filtrar por estado"><option value="">Todos los estados</option><option>En curso</option><option>Próximo</option><option>Finalizado</option></select></div>
<div class="aula-courses aula-course-catalog"><?php foreach($profile as $p):$c=$p['curso'];$cc=$p['control'];$pct=is_numeric($cc['asistencia']??null)?max(0,min(100,(float)$cc['asistencia'])):0; ?>
<article class="aula-card aula-course-card aula-visual-card" data-course-name="<?= aula_h(mb_strtolower($c['nombre_cursos'])) ?>" data-course-state="<?= aula_h($p['estado']) ?>">
<a class="aula-cover" href="mi_aula.php?curso=<?= (int)$c['id'] ?>" aria-label="<?= aula_h('Entrar al aula: '.$c['nombre_cursos']) ?>"><img src="<?= aula_h(aula_portada_url($db,$c)) ?>" alt="" loading="lazy" width="900" height="400"></a><div class="aula-course-body"><div class="aula-course-top"><span class="aula-course-icon glyphicon glyphicon-education" aria-hidden="true"></span><span class="aula-badge"><?= $p['estado'] ?></span><?php if($p['diplomas']): ?><a class="aula-badge is-complete" href="mi_aula.php?curso=<?= (int)$c['id'] ?>&amp;seccion=diplomas">✓ Diploma disponible</a><?php endif; ?></div>
<h2><?= aula_h($c['nombre_cursos']) ?></h2><p class="aula-course-dates"><?= aula_h($c['inicio']?:'Inicio por confirmar') ?> → <?= aula_h($c['fin']?:'Fin por confirmar') ?></p>
<div class="aula-course-metrics"><div><strong><?= aula_h($cc['sesiones_asistidas']??0) ?> / <?= $p['impartidas'] ?></strong><span>Sesiones asistidas de las impartidas (<?= $p['total'] ?> en el programa)</span></div><div><strong><?= aula_h(is_numeric($cc['posevaluacion']??null)?$cc['posevaluacion']:'—') ?></strong><span>Nota final / 100</span></div><div><strong><?= $p['pendientes'] ?></strong><span>Tareas pendientes</span></div></div>
<div class="aula-progress"><span style="width:<?= $pct ?>%"></span></div><p class="aula-muted">Asistencia: <?= is_numeric($cc['asistencia']??null)?aula_h(round((float)$cc['asistencia'])).'% de las sesiones impartidas':($p['impartidas']?'Sin registros':'El curso aún no tiene sesiones impartidas') ?></p>
<div class="aula-next-session"><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span><div><small>Próxima sesión</small><strong><?= aula_h($p['proxima']?$p['proxima']['tema']:'Sin sesiones próximas') ?></strong><?php if($p['proxima']): ?><span><?= aula_h($p['proxima']['fecha'].' · '.substr($p['proxima']['hora_inicio'],0,5)) ?></span><?php endif; ?></div></div>
<div class="aula-course-footer"><a class="btn btn-primary" href="mi_aula.php?curso=<?= (int)$c['id'] ?>">Entrar al aula →</a><a href="mi_aula.php?curso=<?= (int)$c['id'] ?>&amp;seccion=sesiones">Sesiones y grabaciones</a></div>
</div></article><?php endforeach; ?></div><p id="aula-course-no-results" class="aula-card" hidden>No hay cursos que coincidan con el filtro.</p>
<script>(function(){var search=document.getElementById('aula-course-search'),state=document.getElementById('aula-course-state'),cards=document.querySelectorAll('.aula-course-card');function filter(){var count=0;cards.forEach(function(c){var visible=c.dataset.courseName.includes(search.value.toLocaleLowerCase().trim())&&(!state.value||c.dataset.courseState===state.value);c.hidden=!visible;if(visible)count++;});document.getElementById('aula-course-no-results').hidden=count>0;}search.addEventListener('input',filter);state.addEventListener('change',filter);})();</script>
