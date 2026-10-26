<?php if(!isset($db,$courseId,$assignment,$evVisibles)){http_response_code(403);exit;}
// Tareas y evaluaciones del curso: intento en curso o resultado (si se abrio uno) y la lista unica de actividades.
?>
<?php $attemptId=(int)($_GET['intento']??0); $attempt=$attemptId?(aula_rows($db,'SELECT t.*,e.titulo,e.publicar,e.habilitada FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE t.id=? AND t.asignacion_id=? AND e.curso_id=?',[$attemptId,$assignment['id'],$courseId])[0]??null):null; ?>
<?php if($attempt && !$attempt['entregado']): $questions=json_decode($attempt['preguntas'],true); $answers=json_decode($attempt['respuestas'],true); ?>
<section class="aula-card"><h2><?= aula_h($attempt['titulo']) ?></h2><p>Vence: <?= aula_h($attempt['vence']) ?> (Guatemala). Guarda periódicamente tus respuestas. Al vencer se conservan únicamente las respuestas guardadas.</p>
<form method="post" enctype="multipart/form-data" id="aula-attempt"><?= aula_csrf() ?><input type="hidden" name="intento" value="<?= $attemptId ?>">
<?php foreach($questions as $n=>$p): ?><fieldset class="aula-question"><legend><?= ($n+1).'. '.aula_h($p['enunciado']) ?></legend>
<?php if($p['tipo']==='seleccion'): foreach(json_decode($p['opciones'],true) as $i=>$option): ?><label class="aula-option"><input type="radio" name="respuestas[<?= (int)$p['id'] ?>]" value="<?= $i ?>" <?= isset($answers[$p['id']])&&(string)$answers[$p['id']]===(string)$i?'checked':'' ?>> <?= aula_h($option) ?></label><?php endforeach; ?>
<?php else: ?><label>Tu respuesta<textarea name="respuestas[<?= (int)$p['id'] ?>]" rows="5" maxlength="20000"><?= aula_h($answers[$p['id']]??'') ?></textarea></label><?php if($p['criterio']): ?><p>Criterio de evaluación: <?= aula_h($p['criterio']) ?></p><?php endif; ?>
<?php if($p['tipo']==='tarea'): ?><label>Adjuntar tarea (PDF, máximo 5 MB)<input type="file" name="tarea_<?= (int)$p['id'] ?>" accept="application/pdf"></label><?php $attached=aula_rows($db,'SELECT id,nombre FROM aula_archivos WHERE intento_id=? AND pregunta_id=?',[$attemptId,$p['id']]); foreach($attached as $f): ?><p>Archivo guardado: <a href="aula_archivo.php?id=<?= (int)$f['id'] ?>"><?= aula_h($f['nombre']) ?></a></p><?php endforeach; endif; endif; ?></fieldset><?php endforeach; ?>
<div class="aula-inline"><button name="accion" value="guardar" class="btn btn-default">Guardar respuestas</button><button name="accion" value="entregar" class="btn btn-primary">Entregar evaluación</button><span id="aula-save-status" role="status"></span></div></form></section>
<?php elseif($attempt): ?><section class="aula-card"><h2><?= aula_h($attempt['titulo']) ?></h2><p><?= !$attempt['publicar']?'Resultado pendiente de publicación.':($attempt['nota']===null?'Pendiente de revisión docente.':'Resultado: '.aula_h($attempt['nota']).' / 100') ?></p>
<?php if($attempt['publicar']): $savedAnswers=json_decode($attempt['respuestas'],true);$feedback=json_decode($attempt['revision']??'{}',true)?:[]; foreach(json_decode($attempt['preguntas'],true) as $p): ?><div class="aula-question"><strong><?= aula_h($p['enunciado']) ?></strong>
<?php if($p['tipo']==='seleccion'): $options=json_decode($p['opciones'],true); ?><p>Tu respuesta: <?= aula_h($options[$savedAnswers[$p['id']]??'']??'Sin respuesta') ?></p><p><?= isset($savedAnswers[$p['id']])&&(string)$savedAnswers[$p['id']]!==''&&(string)$savedAnswers[$p['id']]===(string)$p['correcta']?'Correcta':'Incorrecta' ?></p>
<?php else: ?><p class="aula-answer"><?= aula_h($savedAnswers[$p['id']]??'Sin respuesta') ?></p><p><?= isset($feedback[$p['id']]['puntos'])?'Puntos: '.aula_h($feedback[$p['id']]['puntos']).' / 1':'Pendiente de revisión' ?></p><?php if(!empty($feedback[$p['id']]['comentario'])): ?><p>Retroalimentación: <?= aula_h($feedback[$p['id']]['comentario']) ?></p><?php endif; endif; ?></div><?php endforeach; endif; ?></section><?php endif; ?>
<?php
// Lista unica de tareas y evaluaciones: primero lo que se puede hacer ahora, luego lo proximo, lo entregado y lo cerrado.
$orden=['hacer'=>0,'proxima'=>1,'entregada'=>2,'cerrada'=>3];$filas=[];
foreach($evVisibles as $e){
    $history=aula_rows($db,'SELECT id,numero,entregado,nota,entrega_usuario FROM aula_intentos WHERE evaluacion_id=? AND asignacion_id=? ORDER BY numero DESC',[$e['id'],$assignment['id']]);
    $completed=!empty($entregadas[$e['id']]);$available=$e['abre']<=$ahora&&$e['cierra']>$ahora;
    $estado=$completed?'entregada':($available?'hacer':($e['abre']>$ahora?'proxima':'cerrada'));
    $filas[]=[$orden[$estado],$e['cierra'],$e,$history,$estado,$available];
}
usort($filas,static function($a,$b){return [$a[0],$a[1]]<=>[$b[0],$b[1]];});
$sesionPorId=array_column($sessions,null,'id');
?>
<section class="aula-card"><h2>Tareas y evaluaciones</h2>
<div class="aula-filtros" data-filtros=".aula-actividades > .aula-actividad">
    <span class="aula-filtros-chips" role="group" aria-label="Filtrar actividades">
        <button type="button" class="is-activo" data-filtro="todas">Todas <b><?= count($filas) ?></b></button>
        <button type="button" data-filtro="hacer">Por hacer <b data-filtro-cuenta="hacer"></b></button>
        <button type="button" data-filtro="proxima">Próximas <b data-filtro-cuenta="proxima"></b></button>
        <button type="button" data-filtro="entregada">Entregadas <b data-filtro-cuenta="entregada"></b></button>
        <button type="button" data-filtro="cerrada">Cerradas <b data-filtro-cuenta="cerrada"></b></button>
    </span>
</div>
<p class="aula-muted" data-filtro-vacio hidden>No hay actividades en este filtro.</p>
<div class="aula-actividades">
<?php foreach($filas as [$o,$c,$e,$history,$estado,$available]): $sesion=$sesionPorId[$e['sesion_id']]??null;
    [$texto,$clase]=['hacer'=>['Pendiente · disponible ahora','is-pending'],'proxima'=>['Se abre '.aula_fecha_larga($e['abre'],true).' '.substr($e['abre'],11,5),''],'entregada'=>['✓ Completada / entregada','is-complete'],'cerrada'=>['Vencida sin entrega','is-ausente']][$estado]; ?>
<div class="aula-row aula-actividad is-<?= $estado ?>" id="act-<?= (int)$e['id'] ?>" data-tags="<?= $estado ?>"><div>
    <span class="aula-badge is-tipo"><?= $e['tipo_actividad']==='evaluacion'?'Evaluación':'Tarea' ?></span> <strong><?= aula_h($e['titulo']) ?></strong> <span class="aula-badge <?= $clase ?>"><?= aula_h($texto) ?></span>
    <?php if($sesion): ?><p>Sesión: <?= aula_h(aula_fecha_larga($sesion['fecha'],true).' · '.$sesion['tema']) ?></p><?php endif; ?>
    <p><?= aula_h('Disponible del '.aula_fecha_larga($e['abre'],true).' '.substr($e['abre'],11,5).' al '.aula_fecha_larga($e['cierra'],true).' '.substr($e['cierra'],11,5)) ?> · <?= (int)$e['minutos'] ?> min · <?= (int)$e['intentos'] ?> intento(s)</p>
    <?php foreach($history as $h): ?><a class="aula-history" href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=actividades&amp;intento=<?= (int)$h['id'] ?>">Intento <?= (int)$h['numero'] ?> · <?= !$h['entregado']?'En curso':(!$e['publicar']?'Entregado':($h['nota']===null?'Por revisar':aula_h($h['nota']).' / 100')) ?></a><?php endforeach; ?></div>
<?php if($available && (count($history)<(int)$e['intentos'] || ($history&&!$history[0]['entregado']))): ?><form method="post"><?= aula_csrf() ?><input type="hidden" name="accion" value="iniciar"><input type="hidden" name="evaluacion" value="<?= (int)$e['id'] ?>"><button class="btn btn-primary"><?= $history&&!$history[0]['entregado']?'Continuar':'Iniciar' ?></button></form><?php endif; ?></div>
<?php endforeach; ?>
</div>
<?php if(!$filas): ?><p>Aún no hay actividades publicadas.</p><?php endif; ?></section>
