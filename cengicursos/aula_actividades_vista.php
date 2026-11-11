<?php if(!isset($db,$courseId,$assignment,$evVisibles)){http_response_code(403);exit;}
// Tareas y evaluaciones del curso: intento en curso o resultado (si se abrio uno) y la lista unica de actividades.
?>
<?php $attemptId=(int)($_GET['intento']??0); $attempt=$attemptId?(aula_rows($db,'SELECT t.*,e.titulo,e.habilitada FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE t.id=? AND t.asignacion_id=? AND e.curso_id=?',[$attemptId,$assignment['id'],$courseId])[0]??null):null;
// Configuracion de la evaluacion: cuando se ve la nota y la retroalimentacion (aula_nota_visible / aula_retro_visible).
$evIntento=$attempt?(aula_rows($db,'SELECT * FROM aula_evaluaciones WHERE id=?',[$attempt['evaluacion_id']])[0]??[]):[];
$fechaHora=static function($v){return aula_fecha_larga($v,true).' '.substr($v,11,5);}; ?>
<?php if($attempt && !$attempt['entregado']): $questions=json_decode($attempt['preguntas'],true); $answers=json_decode($attempt['respuestas'],true); $restante=max(0,strtotime($attempt['vence'])-time()); ?>
<section class="aula-card"><h2><?= aula_h($attempt['titulo']) ?></h2>
<div class="aula-cronometro" data-restante="<?= (int)$restante ?>" role="timer"><span data-cronometro-texto>Tiempo restante</span><b data-cronometro><?= aula_h(aula_duracion_texto(intdiv($restante,60))) ?></b><small>Termina: <?= aula_h($fechaHora($attempt['vence'])) ?> (Guatemala)</small></div>
<p class="aula-muted">Tus respuestas se guardan automáticamente cada 30 segundos; también puedes usar “Guardar respuestas”. Al terminar el tiempo la evaluación se entrega sola.</p>
<form method="post" enctype="multipart/form-data" id="aula-attempt"><?= aula_csrf() ?><input type="hidden" name="intento" value="<?= $attemptId ?>">
<?php foreach($questions as $n=>$p): $w=aula_valor_pregunta($p); ?><fieldset class="aula-question"><legend><?= ($n+1).'. '.aula_h($p['enunciado']) ?> <span class="aula-valor"><?= aula_h(aula_pts($w)) ?> pt<?= $w==1?'':'s' ?></span></legend>
<?php if($p['tipo']==='seleccion'): foreach(json_decode($p['opciones'],true) as $i=>$option): ?><label class="aula-option"><input type="radio" name="respuestas[<?= (int)$p['id'] ?>]" value="<?= $i ?>" <?= isset($answers[$p['id']])&&(string)$answers[$p['id']]===(string)$i?'checked':'' ?>> <?= aula_h($option) ?></label><?php endforeach; ?>
<?php elseif($p['tipo']==='multiple'): $marcadas=aula_respuesta_lista($answers[$p['id']]??''); ?><p class="aula-muted">Selecciona todas las respuestas correctas. Cada acierto suma y cada opción incorrecta marcada resta.</p><?php foreach(json_decode($p['opciones'],true) as $i=>$option): ?><label class="aula-option"><input type="checkbox" name="respuestas[<?= (int)$p['id'] ?>][]" value="<?= $i ?>" <?= in_array($i,$marcadas,true)?'checked':'' ?>> <?= aula_h($option) ?></label><?php endforeach; ?>
<?php else: ?><label>Tu respuesta<textarea name="respuestas[<?= (int)$p['id'] ?>]" rows="5" maxlength="20000"><?= aula_h($answers[$p['id']]??'') ?></textarea></label><?php if($p['criterio']): ?><p>Criterio de evaluación: <?= aula_h($p['criterio']) ?></p><?php endif; ?>
<?php if($p['tipo']==='tarea'): ?><label>Adjuntar tarea (PDF, máximo 5 MB)<input type="file" name="tarea_<?= (int)$p['id'] ?>" accept="application/pdf"></label><?php $attached=aula_rows($db,'SELECT id,nombre FROM aula_archivos WHERE intento_id=? AND pregunta_id=?',[$attemptId,$p['id']]); foreach($attached as $f): ?><p>Archivo guardado: <a href="aula_archivo.php?id=<?= (int)$f['id'] ?>"><?= aula_h($f['nombre']) ?></a></p><?php endforeach; endif; endif; ?></fieldset><?php endforeach; ?>
<div class="aula-inline"><button name="accion" value="guardar" class="btn btn-default">Guardar respuestas</button><button name="accion" value="entregar" class="btn btn-primary">Entregar evaluación</button><span id="aula-save-status" role="status"></span></div></form></section>
<script>
(function(){
    // Cronometro del intento: cuenta regresiva y entrega automatica unos segundos antes del vencimiento
    // (al vencer, el servidor solo conserva lo que ya estaba guardado).
    var c=document.querySelector('[data-cronometro]');if(!c)return;
    var box=c.closest('.aula-cronometro'),fin=Date.now()+parseInt(box.getAttribute('data-restante'),10)*1000,form=document.getElementById('aula-attempt'),enviado=false;
    function dos(n){return (n<10?'0':'')+n;}
    function tic(){
        var s=Math.max(0,Math.round((fin-Date.now())/1000)),h=Math.floor(s/3600),m=Math.floor(s%3600/60);
        c.textContent=(h?h+':'+dos(m):m)+':'+dos(s%60);
        box.classList.toggle('is-poco',s<=300);box.classList.toggle('is-critico',s<=60);
        if(s<=5&&!enviado&&form){
            enviado=true;box.querySelector('[data-cronometro-texto]').textContent='Tiempo terminado · entregando…';
            var b=form.querySelector('button[value="entregar"]');if(form.requestSubmit&&b)form.requestSubmit(b);else if(b)b.click();return;
        }
        setTimeout(tic,1000);
    }
    tic();
})();
</script>
<?php elseif($attempt): $notaVis=aula_nota_visible($evIntento);$retroVis=aula_retro_visible($evIntento);$verCorrectas=($evIntento['retro']??'acierto')==='correctas';
    $modoNota=$evIntento['nota_visible']??'manual';$retroModo=$evIntento['retro']??'acierto';$retroMomento=$evIntento['retro_momento']??'nota'; ?>
<section class="aula-card"><h2><?= aula_h($attempt['titulo']) ?></h2>
<p class="aula-resultado"><?= !$notaVis?($modoNota==='cierre'?'Entregada. Tu nota se mostrará al cerrar la evaluación ('.aula_h($fechaHora($evIntento['cierra'])).').':'Entregada. Resultado pendiente de publicación.'):($attempt['nota']===null?'Entregada. Pendiente de revisión docente.':'Resultado: <b>'.aula_h($attempt['nota']).' / 100</b>') ?></p>
<?php if(!$retroVis&&$retroModo!=='no'&&($retroMomento!=='nota'||$notaVis)): ?><p class="aula-muted">La retroalimentación estará disponible <?= $retroMomento==='cierre'?'al cerrar la evaluación ('.aula_h($fechaHora($evIntento['cierra'])).')':($retroMomento==='fecha'&&!empty($evIntento['retro_desde'])?'desde el '.aula_h($fechaHora($evIntento['retro_desde'])):'más adelante') ?>.</p><?php endif; ?>
<?php if($retroVis): $savedAnswers=json_decode($attempt['respuestas'],true);$feedback=json_decode($attempt['revision']??'{}',true)?:[];
    foreach(json_decode($attempt['preguntas'],true) as $p): $w=aula_valor_pregunta($p);$options=json_decode((string)($p['opciones']??''),true)?:[];
    $txt=static function(array $idx)use($options){return implode(' · ',array_map(static function($i)use($options){return $options[$i]??'';},$idx));}; ?>
<div class="aula-question"><strong><?= aula_h($p['enunciado']) ?></strong> <span class="aula-valor"><?= aula_h(aula_pts($w)) ?> pt<?= $w==1?'':'s' ?></span>
<?php if($p['tipo']==='seleccion'): $resp=(string)($savedAnswers[$p['id']]??'');$bien=$resp!==''&&$resp===(string)$p['correcta']; ?><p>Tu respuesta: <?= aula_h($resp!==''?($options[(int)$resp]??''):'Sin respuesta') ?></p><p class="<?= $bien?'aula-bien':'aula-mal' ?>"><?= $bien?'✓ Correcta':'✗ Incorrecta' ?> · <?= aula_h(aula_pts($bien?$w:0)) ?> / <?= aula_h(aula_pts($w)) ?></p><?php if($verCorrectas&&!$bien): ?><p>Respuesta correcta: <?= aula_h($options[(int)$p['correcta']]??'') ?></p><?php endif; ?>
<?php elseif($p['tipo']==='multiple'): $marcadas=aula_respuesta_lista($savedAnswers[$p['id']]??'');$frac=aula_puntaje_multiple(aula_correctas_de($p),$marcadas); ?><p>Tus respuestas: <?= $marcadas?aula_h($txt($marcadas)):'Sin respuesta' ?></p><p class="<?= $frac>=1?'aula-bien':($frac>0?'aula-parcial':'aula-mal') ?>"><?= $frac>=1?'✓ Correcta':($frac>0?'◐ Parcialmente correcta':'✗ Incorrecta') ?> · <?= aula_h(aula_pts($frac*$w)) ?> / <?= aula_h(aula_pts($w)) ?></p><?php if($verCorrectas&&$frac<1): ?><p>Respuestas correctas: <?= aula_h($txt(aula_correctas_de($p))) ?></p><?php endif; ?>
<?php else: ?><p class="aula-answer"><?= aula_h($savedAnswers[$p['id']]??'Sin respuesta') ?></p><p><?= isset($feedback[$p['id']]['puntos'])?'Puntos: '.aula_h(aula_pts((float)$feedback[$p['id']]['puntos']*$w)).' / '.aula_h(aula_pts($w)):'Pendiente de revisión' ?></p><?php if(!empty($feedback[$p['id']]['comentario'])): ?><p>Retroalimentación: <?= aula_h($feedback[$p['id']]['comentario']) ?></p><?php endif; endif; ?></div>
<?php endforeach; endif; ?></section><?php endif; ?>
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
    <p><?= aula_h('Disponible del '.aula_fecha_larga($e['abre'],true).' '.substr($e['abre'],11,5).' al '.aula_fecha_larga($e['cierra'],true).' '.substr($e['cierra'],11,5)) ?> · Tiempo para realizarla: <?= aula_h(aula_duracion_texto($e['minutos'])) ?> · <?= (int)$e['intentos'] ?> intento(s)<?= (int)$e['intentos']>1?' · cuenta '.(($e['calificacion']??'mayor')==='ultimo'?'el último intento':'la nota más alta'):'' ?></p>
    <?php foreach($history as $h): ?><a class="aula-history" href="mi_aula.php?curso=<?= $courseId ?>&amp;seccion=actividades&amp;intento=<?= (int)$h['id'] ?>">Intento <?= (int)$h['numero'] ?> · <?= !$h['entregado']?'En curso':(!aula_nota_visible($e)?'Entregado':($h['nota']===null?'Por revisar':aula_h($h['nota']).' / 100')) ?></a><?php endforeach; ?></div>
<?php if($available && (count($history)<(int)$e['intentos'] || ($history&&!$history[0]['entregado']))): ?><form method="post"><?= aula_csrf() ?><input type="hidden" name="accion" value="iniciar"><input type="hidden" name="evaluacion" value="<?= (int)$e['id'] ?>"><button class="btn btn-primary"><?= $history&&!$history[0]['entregado']?'Continuar':'Iniciar' ?></button></form><?php endif; ?></div>
<?php endforeach; ?>
</div>
<?php if(!$filas): ?><p>Aún no hay actividades publicadas.</p><?php endif; ?></section>
