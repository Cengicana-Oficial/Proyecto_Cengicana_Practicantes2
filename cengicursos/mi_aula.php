<?php
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/aula_helpers.php';
date_default_timezone_set('America/Guatemala');
$db = conectar();
$courses = aula_courses($db);
$courseId = (int) ($_GET['curso'] ?? 0);
$assignment = $courseId ? aula_assignment($db,$courseId) : null;
$previewId=(int)($_GET['vista_previa']??0);$preview=$previewId>0;$previewStudentId=null;
if($preview) {
    $context=aula_preview_context($db,$previewId,$courseId,!empty($_GET['catalogo']));
    $courses=$context['courses'];$assignment=$context['assignment'];$courseId=(int)$context['courseId'];$previewStudentId=$context['seed']['participantes_id'];
    if($_SERVER['REQUEST_METHOD']==='POST'){http_response_code(403);exit('La vista previa es solo de consulta. No registra asistencia, respuestas ni notas.');}
    ob_start(static function($html)use($previewId){return aula_preview_html($html,$previewId);});
}
if ($courseId && !$assignment) { http_response_code(403); exit('No estás asignado a este curso.'); }
$course = $assignment ? (aula_rows($db,'SELECT * FROM cursos WHERE id=?',[$courseId])[0] ?? null) : null;
$ready = aula_ready($db) && aula_integrada($db) && aula_control_ready($db);
// Secciones que coordinacion oculto y anuncio del curso (Gestion del aula -> Lo que ve el estudiante).
$vista = aula_vista_config($db, $courseId);
$ver = static function ($seccion) use ($vista) { return !in_array($seccion, $vista['ocultas'], true); };
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        aula_check_csrf();
        if (!$assignment || !$ready) throw new RuntimeException('El aula no está disponible.');
        $action = $_POST['accion'] ?? '';
        if ($action === 'iniciar') {
            $id = aula_start($db,(int) ($_POST['evaluacion'] ?? 0),$assignment);
            header('Location: mi_aula.php?curso='.$courseId.'&seccion=actividades&intento='.$id); exit;
        } elseif ($action === 'guardar' || $action === 'entregar' || $action === 'autoguardar') {
            $message = aula_save($db,(int) ($_POST['intento'] ?? 0),$assignment,$action === 'entregar');
        } elseif ($action === 'asistencia') {
            $throttle=$_SESSION['aula_asistencia_fallos']??['inicio'=>time(),'total'=>0];
            if(time()-$throttle['inicio']>300)$throttle=['inicio'=>time(),'total'=>0];
            if($throttle['total']>=5)throw new RuntimeException('Espera cinco minutos antes de volver a intentar.');
            try { aula_attend($db,$assignment,(int)($_POST['sesion']??0),(string)($_POST['codigo']??'')); }
            catch(RuntimeException $e){$throttle['total']++;$_SESSION['aula_asistencia_fallos']=$throttle;throw $e;}
            $message='Asistencia registrada y actualizada en Seguimiento del curso.';
        } else { throw new RuntimeException('Acción no válida.'); }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) { error_log('Aula: '.$e->getMessage()); $error='No se pudo guardar. Intenta nuevamente.'; }
        else $error=$e->getMessage();
    }
    if (($_POST['accion'] ?? '') === 'autoguardar') {
        header('Content-Type: application/json; charset=utf-8');
        if ($error) http_response_code(400);
        echo aula_json(['ok'=>$error==='','mensaje'=>$error ?: $message]); exit;
    }
}
if ($ready && $assignment && !$preview) { aula_expire($db,$courseId,$assignment['id']); aula_sync_course($db,$courseId,$assignment['id']); }
$control = $assignment ? (aula_rows($db,'SELECT * FROM control_cursos WHERE asignacion_id=?',[$assignment['id']])[0] ?? []) : [];
$sessions = $course ? aula_rows($db,'SELECT s.* FROM curso_sesiones s JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=? AND s.estado<>\'cancelada\' ORDER BY s.fecha,s.hora_inicio',[$courseId]) : [];
$registered=$assignment&&$ready?array_column(aula_attendance_records($db,$assignment['id']),'registrado','sesion_id'):[];
$windows = $course && $ready ? array_column(aula_rows($db,'SELECT v.* FROM aula_asistencia_ventanas v JOIN curso_sesiones s ON s.id=v.sesion_id JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=?',[$courseId]),null,'sesion_id') : [];
$evaluations = $course && $ready ? aula_rows($db,'SELECT * FROM aula_evaluaciones WHERE curso_id=? ORDER BY COALESCE((SELECT fecha FROM curso_sesiones WHERE id=sesion_id),DATE(abre)),orden,abre,id',[$courseId]) : [];
$ahora = date('Y-m-d H:i:s'); $hoy = date('Y-m-d');
// Actividades que el estudiante puede ver (coordinacion puede ocultar evaluaciones o tareas) y cuales ya entrego.
$evVisibles = array_values(array_filter($evaluations, static function ($e) use ($ver) { return $e['habilitada'] && $ver($e['tipo_actividad'] === 'evaluacion' ? 'evaluaciones' : 'tareas'); }));
$entregadas = $assignment && $ready ? array_column(aula_rows($db, 'SELECT evaluacion_id, MAX(entregado IS NOT NULL AND entrega_usuario=1) hecha FROM aula_intentos WHERE asignacion_id=? GROUP BY evaluacion_id', [$assignment['id']]), 'hecha', 'evaluacion_id') : [];
$porHacer = count(array_filter($evVisibles, static function ($e) use ($entregadas, $ahora) { return $e['abre'] <= $ahora && $e['cierra'] > $ahora && empty($entregadas[$e['id']]); }));
$abiertas = $ready ? array_values(array_filter($sessions, static function ($s) use ($windows, $registered) { return !isset($registered[$s['id']]) && aula_session_window($s, $windows[$s['id']] ?? null); })) : [];
$surveys = $course ? aula_instructor_surveys($db, $courseId, $assignment['id']) : [];
// Seis pestanas, solo las que tienen algo que mostrar. Los nombres anteriores siguen funcionando en enlaces y correos.
$tabs = ['resumen' => 'Inicio'];
if ($ver('sesiones') || $ver('material')) $tabs['sesiones'] = 'Sesiones y grabaciones';
if ($ver('asistencia')) $tabs['asistencia'] = 'Asistencia';
if ($ready && $evVisibles) $tabs['actividades'] = 'Tareas y evaluaciones';
if ($ver('notas') || $ver('diplomas')) $tabs['resultados'] = 'Notas y diploma';
if ($ver('instructores') && $surveys) $tabs['instructores'] = 'Evaluar al instructor';
$tab = (string) ($_GET['seccion'] ?? 'resumen');
$tab = ['material' => 'sesiones', 'evaluaciones' => 'actividades', 'tareas' => 'actividades', 'notas' => 'resultados', 'diplomas' => 'resultados'][$tab] ?? $tab;
if (!isset($tabs[$tab])) $tab = 'resumen';
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi aula | Cengicursos</title><link rel="stylesheet" href="css/bootstrap.min.css"><link rel="stylesheet" href="css/proyecto.css?v=<?= filemtime(__DIR__.'/css/proyecto.css') ?>"><link rel="stylesheet" href="css/cengi-tema.css?v=<?= filemtime(__DIR__.'/css/cengi-tema.css') ?>"><link rel="stylesheet" href="css/aula.css?v=<?= filemtime(__DIR__.'/css/aula.css') ?>"></head>
<body class="cengi-canvas cg-tema"><!-- El menu propio evita mostrar administracion al estudiante. --><?php menu_render($preview); ?>
<main class="container aula-page">
<?php if($preview): ?><div class="aula-preview-banner"><span class="glyphicon glyphicon-eye-open" aria-hidden="true"></span><div><strong>Vista del estudiante: <?= aula_h($context['seed']['nombre_participantes']) ?></strong><p>Solo consulta. No se enviarán evaluaciones ni se modificará su asistencia.<?php if ($courseId && $vista['ocultas']): ?> Secciones ocultas para el estudiante: <?= aula_h(implode(', ', array_map(static function ($k) { return AULA_SECCIONES_CONFIGURABLES[$k]; }, $vista['ocultas']))) ?>.<?php endif; ?></p></div><a href="aula_gestion.php?curso=<?= (int)($courseId ?: $context['seed']['cursos_id']) ?>#vista">Volver a Gestión del aula</a><a href="aulas.php?curso=<?= (int)$context['seed']['cursos_id'] ?>">Control de aulas</a></div><?php endif; ?>
<header class="aula-hero"><div><small>PORTAL DEL ESTUDIANTE</small><h1><?= aula_h($course ? $course['nombre_cursos'] : 'Mis cursos') ?></h1><p><?= $course ? 'Tus sesiones, resultados y recursos en un solo lugar.' : 'Selecciona un curso para entrar a tu aula.' ?></p></div><a href="../login/forgot_password.php">Recuperar mi contraseña</a></header>
<?php if ($message): ?><p class="alert alert-success" role="status"><?= aula_h($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-danger" role="alert"><?= aula_h($error) ?></p><?php endif; ?>
<?php if (!$course): ?>
<?php include __DIR__.'/aula_cursos_vista.php'; ?>
<?php if (!$courses): ?><section class="aula-card"><h2>Aún no tienes cursos vinculados</h2><p>Si ya estás inscrito, solicita a coordinación que vincule tu cuenta con tu ficha de participante.</p></section><?php endif; ?>
<?php else: ?>
<a class="aula-back" href="mi_aula.php">← Mis cursos</a><nav class="aula-tabs aula-tabs-estudiante" aria-label="Secciones del curso"><?php foreach ($tabs as $key=>$title): $aviso=$key==='actividades'&&$porHacer?$porHacer:($key==='asistencia'&&$abiertas?'Abierta':''); ?><a <?= $tab===$key ? 'aria-current="page"' : '' ?> href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=<?= $key ?>"><?= $title ?><?php if ($aviso!==''): ?> <b class="aula-tab-aviso"><?= $aviso ?></b><?php endif; ?></a><?php endforeach; ?></nav>
<?php if (!$ready): ?><p class="alert alert-info">Las evaluaciones y el registro de asistencia estarán disponibles cuando coordinación habilite el aula.</p><?php endif; ?>
<?php if ($tab==='resumen'): include __DIR__.'/aula_resumen_vista.php';
elseif ($tab==='asistencia'): include __DIR__.'/aula_asistencia_vista.php';
elseif ($tab==='sesiones'): include __DIR__.'/aula_sesiones_vista.php';
elseif ($tab==='actividades'): include __DIR__.'/aula_actividades_vista.php';
elseif ($tab==='resultados'): include __DIR__.'/aula_resultados_vista.php';
elseif ($tab==='instructores'): include __DIR__.'/aula_instructores_vista.php'; ?>
<?php endif; endif; ?>
</main>
<script>
(function(){
var form=document.getElementById('aula-attempt');if(!form)return;
var dirty=false,busy=false,revision=0,status=document.getElementById('aula-save-status');
form.addEventListener('input',function(){dirty=true;revision++;});
form.addEventListener('submit',function(){dirty=false;});
window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue='';}});
window.setInterval(async function(){
    if(!dirty||busy)return;busy=true;var sent=revision;
    var data=new FormData(form);data.set('accion','autoguardar');
    try{var response=await fetch(location.href,{method:'POST',body:data,credentials:'same-origin'});var result=await response.json();
        status.textContent=result.mensaje;if(result.ok&&revision===sent)dirty=false;
    }catch(e){status.textContent='No se pudo autoguardar. Usa Guardar respuestas.';}finally{busy=false;}
},30000);
})();
</script><script src="js/aula_video.js?v=<?= (int) @filemtime(__DIR__.'/js/aula_video.js') ?>"></script><script src="js/mi_aula.js?v=<?= (int) @filemtime(__DIR__.'/js/mi_aula.js') ?>"></script></body></html>
<?php if($preview)ob_end_flush(); ?>
