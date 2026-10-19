<?php
require_once __DIR__.'/menu.php';require_once __DIR__.'/aula_helpers.php';
require_once __DIR__.'/aula_portadas.php';
date_default_timezone_set('America/Guatemala');aula_require_teacher();$db=conectar();
$scope=cengi_scope_sql_por_nombre_ingenio('i',true);$cid=(int)($_GET['curso']??0);$selected=(int)($_GET['participante']??0);
$course=$cid?aula_teacher_course($db,$cid):null;$ready=aula_integrada($db)&&aula_control_ready($db);
$coverMessage='';$coverError=false;
if($_SERVER['REQUEST_METHOD']==='POST' && $course) {
    try {
        aula_check_csrf();
        if(($_POST['accion']??'')==='portada_quitar') {
            $db->prepare('DELETE FROM aula_portadas WHERE curso_id=?')->execute([$cid]);
            $coverMessage='Se restauró la portada ilustrada del curso.';
        } elseif(($_POST['accion']??'')==='portada') {
            $f=$_FILES['portada']??[];
            if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new InvalidArgumentException('Selecciona una imagen JPG, PNG o WebP de hasta 3 MB.');
            if($f['size']>3*1024*1024) throw new InvalidArgumentException('La imagen supera los 3 MB.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$size=@getimagesize($f['tmp_name']);
            if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$size||$size[0]>6000||$size[1]>6000) throw new InvalidArgumentException('Usa una imagen JPG, PNG o WebP de hasta 6000 píxeles por lado.');
            $db->prepare('INSERT INTO aula_portadas(curso_id,mime,imagen,version) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime),imagen=VALUES(imagen),version=VALUES(version)')->execute([$cid,$mime,file_get_contents($f['tmp_name']),bin2hex(random_bytes(12))]);
            $coverMessage='Portada actualizada. También se mostrará en Mis cursos del estudiante.';
        } else throw new InvalidArgumentException('Acción no disponible.');
    } catch(Throwable $e) {
        $coverError=true;$coverMessage=$e instanceof InvalidArgumentException ? $e->getMessage() : 'No fue posible actualizar la portada. Recarga la página e intenta nuevamente.';
        if(!($e instanceof InvalidArgumentException)) error_log('Portada del aula: '.$e->getMessage());
    }
}
$courses=aula_rows($db,'SELECT c.id,c.nombre_cursos,c.inicio,c.fin,COUNT(DISTINCT a.id) participantes FROM cursos c JOIN ingenios i ON i.id=c.ingenio_id LEFT JOIN asignaciones a ON a.cursos_id=c.id AND a.estado_asignaciones=1 WHERE 1=1 '.$scope.' GROUP BY c.id ORDER BY c.inicio DESC,c.nombre_cursos');
$q=trim((string)($_GET['buscar']??''));$students=[];$sessions=[];$detail=null;
if($course) {
    $params=[$cid];$filter='';if($q!==''){$filter=' AND p.nombre_participantes LIKE ?';$params[]='%'.$q.'%';}
    $students=aula_rows($db,'SELECT a.id,a.participantes_id,p.nombre_participantes,cc.asistencia,cc.sesiones_asistidas,cc.posevaluacion FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id LEFT JOIN control_cursos cc ON cc.asignacion_id=a.id WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1'.$filter.' ORDER BY p.nombre_participantes',$params);
    if($selected)$detail=aula_rows($db,'SELECT a.*,p.nombre_participantes FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.id=? AND a.cursos_id=? AND a.estado_asignaciones=1',[$selected,$cid])[0]??null;
    $sessions=aula_rows($db,'SELECT s.* FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? AND s.estado<>\'cancelada\' ORDER BY s.fecha,s.hora_inicio',[$cid]);
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aulas y estudiantes | Cengicursos</title><link rel="stylesheet" href="css/bootstrap.min.css"><link rel="stylesheet" href="css/proyecto.css?v=<?= filemtime(__DIR__.'/css/proyecto.css') ?>"><link rel="stylesheet" href="css/cengi-tema.css?v=<?= filemtime(__DIR__.'/css/cengi-tema.css') ?>"><link rel="stylesheet" href="css/aula.css?v=<?= filemtime(__DIR__.'/css/aula.css') ?>"></head><body class="cengi-canvas cg-tema"><?php menu_render(); ?><main class="container aula-page">
<header class="aula-hero"><div><small>CONTROL GENERAL DE FORMACIÓN</small><h1>Aulas y estudiantes</h1><p>Consulta el avance de cada participante y revisa lo que ve en su aula.</p></div><?php if($course): ?><a href="aula_gestion.php?curso=<?= $cid ?>">Gestionar aula →</a><?php endif; ?></header>
<?php if($coverMessage): ?><div class="alert <?= $coverError?'alert-danger':'alert-success' ?>" role="status"><?= aula_h($coverMessage) ?></div><?php endif; ?>
<?php if(!$course): ?>
<div class="aula-catalog-toolbar"><div><strong><?= count($courses) ?> aulas disponibles</strong><span>Selecciona un curso para consultar estudiantes y avance.</span></div><label for="aulas-buscar">Buscar curso<input type="search" id="aulas-buscar" placeholder="Nombre del curso..."></label></div>
<div class="aula-courses aula-course-catalog aula-admin-catalog"><?php foreach($courses as $c): $state=$c['inicio']&&$c['inicio']>date('Y-m-d')?'Próximo':($c['fin']&&$c['fin']<date('Y-m-d')?'Finalizado':'En curso'); ?>
<article class="aula-card aula-course-card aula-visual-card" data-course-name="<?= aula_h(mb_strtolower($c['nombre_cursos'])) ?>">
<a class="aula-cover" href="aulas.php?curso=<?= (int)$c['id'] ?>" aria-label="<?= aula_h('Abrir aula: '.$c['nombre_cursos']) ?>"><img src="<?= aula_h(aula_portada_url($db,$c)) ?>" alt="" loading="lazy" width="900" height="400"><span class="aula-cover-state"><?= aula_h($state) ?></span></a>
<div class="aula-course-body"><span class="aula-catalog-eyebrow">AULA DE CAPACITACIÓN</span><h2><?= aula_h($c['nombre_cursos']) ?></h2><p class="aula-course-dates"><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?= aula_h($c['inicio']?:'Inicio por confirmar') ?> → <?= aula_h($c['fin']?:'Fin por confirmar') ?></p><span class="aula-badge"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> <?= (int)$c['participantes'] ?> participantes</span><div class="aula-course-footer"><a class="btn btn-primary" href="aulas.php?curso=<?= (int)$c['id'] ?>">Control de estudiantes <span aria-hidden="true">→</span></a><a href="aula_gestion.php?curso=<?= (int)$c['id'] ?>">Gestionar aula</a></div></div></article><?php endforeach; ?></div>
<p id="aulas-sin-resultados" class="aula-card" <?= $courses?'hidden':'' ?>>No hay aulas que coincidan con la búsqueda.</p>
<script>(function(){var field=document.getElementById('aulas-buscar'),cards=document.querySelectorAll('.aula-admin-catalog article');field.addEventListener('input',function(){var found=0;cards.forEach(function(card){var show=card.dataset.courseName.includes(field.value.trim().toLocaleLowerCase());card.hidden=!show;if(show)found++;});document.getElementById('aulas-sin-resultados').hidden=found>0;});})();</script>
<?php else: ?><a class="aula-back" href="aulas.php">← Todas las aulas</a>
<section class="aula-course-banner"><img src="<?= aula_h(aula_portada_url($db,$course)) ?>" alt="" width="900" height="400"><div><span class="aula-catalog-eyebrow">CONTROL DEL AULA</span><h2><?= aula_h($course['nombre_cursos']) ?></h2><p>Asistencia, avance académico y vista del estudiante.</p></div></section>
<details class="aula-card aula-cover-settings"><summary><span class="glyphicon glyphicon-picture" aria-hidden="true"></span> Personalizar imagen del curso</summary><p>La imagen aparecerá en el catálogo de aulas y en Mis cursos del estudiante. Recomendado: imagen horizontal de 900 × 400 px.</p><form method="post" enctype="multipart/form-data" class="aula-inline"><?= aula_csrf() ?><label>Portada del curso<input type="file" name="portada" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG o WebP · máximo 3 MB.</small></label><button class="btn btn-primary" name="accion" value="portada">Guardar imagen</button><button class="btn btn-default" name="accion" value="portada_quitar">Usar ilustración predeterminada</button></form></details>
<div class="aula-overview"><div><strong><?= count($students) ?></strong><span>Participantes encontrados</span></div><div><strong><?= count($sessions) ?></strong><span>Sesiones programadas</span></div><div><strong><?= $ready?count(aula_instructor_surveys($db,$cid)):0 ?></strong><span>Instructores por evaluar</span></div></div>
<form method="get" class="aula-inline aula-card"><input type="hidden" name="curso" value="<?= $cid ?>"><label>Buscar estudiante<input name="buscar" value="<?= aula_h($q) ?>" placeholder="Nombre del participante"></label><button class="btn btn-primary">Buscar</button></form>
<section class="aula-card"><h2>Avance por estudiante</h2><div class="aula-table-wrap"><table class="aula-student-table"><thead><tr><th>Estudiante</th><th>Asistencia</th><th>Nota final</th><th>Tareas entregadas</th><th>Evaluó instructores</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($students as $s):
$tasks=$ready?(int)aula_rows($db,'SELECT COUNT(DISTINCT e.id) total FROM aula_evaluaciones e JOIN aula_intentos t ON t.evaluacion_id=e.id WHERE e.curso_id=? AND e.tipo_actividad<>\'evaluacion\' AND t.asignacion_id=? AND t.entrega_usuario=1',[$cid,$s['id']])[0]['total']:0;
$answered=$ready?(int)aula_rows($db,'SELECT COUNT(*) total FROM aula_instructor_respuestas WHERE asignacion_id=?',[$s['id']])[0]['total']:0;
?><tr><td><strong><?= aula_h($s['nombre_participantes']) ?></strong></td><td><?= aula_h($s['asistencia']??'—') ?>%<br><small><?= aula_h($s['sesiones_asistidas']??0) ?> sesiones</small></td><td><?= aula_h($s['posevaluacion']??'Pendiente') ?></td><td><?= $tasks ?></td><td><?= $answered ?></td><td><div class="aula-student-actions"><a href="aulas.php?curso=<?= $cid ?>&amp;participante=<?= (int)$s['id'] ?>#detalle">Seguimiento</a><a class="btn btn-default btn-sm" href="mi_aula.php?curso=<?= $cid ?>&amp;vista_previa=<?= (int)$s['id'] ?>">Ver como estudiante</a><a href="aula_gestion.php?curso=<?= $cid ?>&amp;asignacion=<?= (int)$s['id'] ?>#correcciones">Corregir asistencia</a></div></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$students): ?><p>No hay participantes que coincidan con la búsqueda.</p><?php endif; ?></section>
<?php if($detail&&$ready): $records=array_column(aula_rows($db,'SELECT * FROM aula_asistencias WHERE asignacion_id=?',[$detail['id']]),null,'sesion_id');$valid=array_column(aula_attendance_records($db,$detail['id']),null,'sesion_id'); ?><section class="aula-card" id="detalle"><h2><?= aula_h($detail['nombre_participantes']) ?> · seguimiento del aula</h2><a href="mi_aula.php?vista_previa=<?= (int)$detail['id'] ?>&amp;catalogo=1">Ver sus cursos como estudiante →</a><h3>Asistencia por sesión</h3><?php foreach($sessions as $s):$r=$records[$s['id']]??null; ?><div class="aula-row"><div><strong><?= aula_h($s['tema']) ?></strong><p><?= aula_h($s['fecha']) ?></p></div><span class="aula-badge <?= isset($valid[$s['id']])?'is-complete':'is-pending' ?>"><?= $r?($r['presente']?(isset($valid[$s['id']])?'Presente':'Por revisar'):'Ausente'):'Sin registro' ?></span><span><?= aula_h($r['registrado']??'—') ?> · <?= aula_h($r['origen']??'') ?></span></div><?php endforeach; ?><h3>Evaluación de instructores</h3><?php foreach(aula_instructor_surveys($db,$cid,$detail['id']) as $survey): ?><div class="aula-row"><strong><?= aula_h($survey['instructor']) ?></strong><span><?= $survey['respondida']?'✓ Respondida una vez':'Pendiente' ?></span></div><?php endforeach; ?></section><?php endif;endif; ?>
</main></body></html>
