<?php if(!isset($db,$cid,$sessions)){http_response_code(403);exit;}
$courseTeams=aula_teams_url($db,$cid);
$surveys=aula_instructor_surveys($db,$cid);
$students=aula_rows($db,'SELECT a.id,p.nombre_participantes FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1 ORDER BY p.nombre_participantes',[$cid]);
?>
<details class="aula-card" id="conexion" open><summary>Enlace de Teams para las sesiones</summary><p>Usa un enlace general para todo el curso. Si una sesión tiene otro enlace, puedes guardarlo por separado. El estudiante verá el botón para entrar desde su aula.</p>
<form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="teams"><label>Enlace general del curso<input type="url" name="teams_url" maxlength="2048" value="<?= aula_h($courseTeams??'') ?>" placeholder="https://teams.microsoft.com/..."></label><button class="btn btn-primary">Guardar enlace general</button></form>
<details class="aula-small-details"><summary>Enlace diferente para una sesión</summary><form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="teams"><label>Sesión<select name="sesion_id"><?php foreach($sessions as $s): ?><option value="<?= (int)$s['id'] ?>"><?= aula_h($s['fecha'].' · '.$s['tema']) ?></option><?php endforeach; ?></select></label><label>Enlace especial (vacío = usar general)<input name="teams_url" type="url" maxlength="2048"></label><button class="btn btn-default">Guardar enlace de sesión</button></form></details>
</details>
<?php
// Respuestas por instructor (todas las del curso, incluidas las cargadas antes del aula) y alumnos asignados.
$encResp=aula_respuestas_instructor($db,$cid);
$encYa=array_map('intval',array_column($surveys,'instructor_id'));
$encCatalogo=array_values(array_filter(aula_instructores_catalogo($db),static function($i)use($encYa){return !in_array((int)$i['id'],$encYa,true);}));
$encInforme=function_exists('cengi_puede_generar_informe_instructor')&&cengi_puede_generar_informe_instructor();
$encAhora=date('Y-m-d H:i:s');
?>
<details class="aula-card" id="encuestas" open><summary>Evaluación de cada instructor · <?= count($surveys) ?></summary>
<p>Los instructores se toman de las sesiones del curso (o de su módulo / del curso). Cada estudiante evalúa una sola vez a cada instructor; por defecto se abre al terminar su última sesión y aparece en la sección <b>Evaluar al instructor</b> del estudiante.</p>
<div class="aula-enc-lista">
<?php foreach($surveys as $survey): $iid=(int)$survey['instructor_id'];$cfg=aula_rows($db,'SELECT * FROM aula_instructor_config WHERE curso_id=? AND instructor_id=?',[$cid,$iid])[0]??[];
    $abre=$survey['abre'];$estado=!$survey['habilitada']?['Deshabilitada','is-oculto']:(!$abre?['Sin fecha de apertura','is-programado']:($encAhora<$abre?['Abre el '.date('d/m/Y H:i',strtotime($abre)),'is-programado']:($survey['cierra']&&$encAhora>=$survey['cierra']?['Cerrada','is-oculto']:['Abierta para los estudiantes','is-complete']))); ?>
<article class="aula-ed-recurso aula-enc-item">
    <div class="aula-ed-recurso-cab">
        <span class="aula-ed-icono"><span class="glyphicon glyphicon-user" aria-hidden="true"></span></span>
        <span class="aula-ed-recurso-txt"><strong><?= aula_h($survey['instructor']) ?></strong>
            <small><?= !empty($survey['agregado'])?'Agregado desde el catálogo':(int)$survey['sesiones'].' sesión(es)' ?><?= $survey['abre_auto']?' · última sesión termina '.date('d/m/Y H:i',strtotime($survey['abre_auto'])):'' ?></small></span>
        <span class="aula-badge <?= $estado[1] ?>"><?= aula_h($estado[0]) ?></span>
        <span class="aula-ed-chip" title="Respuestas recibidas"><span class="glyphicon glyphicon-comment" aria-hidden="true"></span> <?= $encResp[$iid]??0 ?> respuesta(s)</span>
    </div>
    <div class="aula-ed-linea">
        <?php if($survey['token']): ?><a href="evaluacion.php?token=<?= rawurlencode($survey['token']) ?>" target="_blank" rel="noopener">Ver el formulario ↗</a><?php else: ?><span class="aula-falta">Sin enlace de evaluación: recarga la página para generarlo.</span><?php endif; ?>
        <?php if($encInforme&&($encResp[$iid]??0)): ?><a href="informe_instructor_curso_pdf.php?instructor_id=<?= $iid ?>&amp;curso_id=<?= $cid ?>" target="_blank" rel="noopener">Informe de resultados (PDF) ↗</a><?php endif; ?>
        <a href="ficha_instructor.php?id=<?= $iid ?>">Ficha del instructor</a>
    </div>
    <details class="aula-ed-editar"><summary>Cambiar disponibilidad</summary>
        <form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="encuesta"><input type="hidden" name="instructor_id" value="<?= $iid ?>">
            <div class="aula-courses"><label>Apertura personalizada<input type="datetime-local" name="abre" value="<?= aula_h(isset($cfg['abre'])&&$cfg['abre']?str_replace(' ','T',substr($cfg['abre'],0,16)):'') ?>"><small>Vacío = al terminar su última sesión.</small></label>
            <label>Cierre opcional<input type="datetime-local" name="cierra" value="<?= aula_h(isset($cfg['cierra'])&&$cfg['cierra']?str_replace(' ','T',substr($cfg['cierra'],0,16)):'') ?>"></label>
            <label class="aula-ed-check"><input type="checkbox" name="habilitada" <?= $survey['habilitada']?'checked':'' ?>> Evaluación habilitada</label></div>
            <div class="aula-ed-acciones"><button class="btn btn-primary btn-sm">Guardar disponibilidad</button><button class="btn btn-default btn-sm" name="modo" value="abrir_ahora" formnovalidate>Abrir ahora</button><button class="btn btn-default btn-sm" name="modo" value="cerrar_ahora" formnovalidate>Cerrar ahora</button></div></form>
    </details>
</article>
<?php endforeach; ?>
<?php if(!$surveys): ?><p class="aula-muted">Aún no hay instructores: asígnalos a las sesiones en Programa de sesiones o agrégalos aquí.</p><?php endif; ?>
</div>
<?php if($encCatalogo): ?>
<details class="aula-ed-nuevo"><summary><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Agregar instructor a evaluar</summary>
    <form method="post" class="aula-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="instructor_agregar">
        <p class="aula-muted">Para un instructor invitado o conferencista que no aparece en las sesiones. Se toma del catálogo de instructores.</p>
        <div class="aula-courses">
            <label>Instructor<select name="instructor_id" required><option value="">Selecciona…</option><?php foreach($encCatalogo as $i): ?><option value="<?= (int)$i['id'] ?>"><?= aula_h($i['nombre']) ?></option><?php endforeach; ?></select></label>
            <label>Apertura<input type="datetime-local" name="abre"><small>Vacío = de inmediato.</small></label>
            <label>Cierre opcional<input type="datetime-local" name="cierra"></label>
        </div>
        <div class="aula-ed-acciones"><button class="btn btn-primary btn-sm">Agregar evaluación</button></div>
    </form>
</details>
<?php endif; ?>
</details>
<details class="aula-card" id="correcciones"><summary>Marcar o corregir asistencia de un estudiante</summary><p>Puedes marcar presencia o ausencia, corregir la hora y guardar el motivo. Se actualiza Seguimiento y se conserva el historial de cambios.</p>
<form method="post" class="aula-form" id="aula-correction-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="corregir_asistencia"><div class="aula-courses"><label>Participante<select name="asignacion_id" id="aula-correction-student"><?php foreach($students as $student): ?><option value="<?= (int)$student['id'] ?>" <?= (int)($_GET['asignacion']??0)===(int)$student['id']?'selected':'' ?>><?= aula_h($student['nombre_participantes']) ?></option><?php endforeach; ?></select></label><label>Sesión<select name="sesion_id" id="aula-correction-session"><?php foreach($sessions as $s): ?><option value="<?= (int)$s['id'] ?>" data-date="<?= aula_h($s['fecha'].'T'.substr($s['hora_inicio'],0,5)) ?>"><?= aula_h($s['fecha'].' · '.$s['tema']) ?></option><?php endforeach; ?></select></label><label>Estado<select name="presente" id="aula-correction-state"><option value="1">Presente</option><option value="0">Ausente</option></select></label><label>Fecha y hora del registro<input type="datetime-local" name="registrado" id="aula-correction-date" required></label></div><label>Motivo de la corrección<textarea name="motivo" maxlength="500" required rows="2"></textarea></label><button class="btn btn-primary">Guardar corrección de asistencia</button></form>
<?php $history=aula_rows($db,'SELECT h.*,p.nombre_participantes,s.tema FROM aula_asistencia_historial h JOIN asignaciones a ON a.id=h.asignacion_id JOIN participantes p ON p.id=a.participantes_id JOIN curso_sesiones s ON s.id=h.sesion_id WHERE a.cursos_id=? ORDER BY h.id DESC LIMIT 30',[$cid]);if($history): ?><h3>Últimas correcciones</h3><?php foreach($history as $h): $audit=json_decode($h['despues'],true); ?><div class="aula-row"><div><strong><?= aula_h($h['nombre_participantes'].' · '.$h['tema']) ?></strong><p><?= aula_h($h['motivo']) ?></p></div><span class="aula-muted"><?= aula_h($h['fecha']) ?> · <?= aula_h($audit['responsable']??'Coordinación') ?></span></div><?php endforeach;endif; ?>
</details>
<?php $saved=[];foreach(aula_rows($db,'SELECT r.asignacion_id,r.sesion_id,r.registrado,r.presente FROM aula_asistencias r JOIN asignaciones a ON a.id=r.asignacion_id WHERE a.cursos_id=?',[$cid]) as $r)$saved[$r['asignacion_id']][$r['sesion_id']]=['fecha'=>str_replace(' ','T',substr($r['registrado'],0,16)),'presente'=>$r['presente']]; ?>
<script>(function(){var saved=<?= json_encode($saved,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;var s=document.getElementById('aula-correction-session'),p=document.getElementById('aula-correction-student'),d=document.getElementById('aula-correction-date'),state=document.getElementById('aula-correction-state');function fill(){if(!s||!s.selectedOptions[0])return;var current=(saved[p.value]||{})[s.value];d.value=current?current.fecha:s.selectedOptions[0].dataset.date;state.value=current?String(current.presente):'1';}if(s){s.addEventListener('change',fill);p.addEventListener('change',fill);fill();}})();</script>
