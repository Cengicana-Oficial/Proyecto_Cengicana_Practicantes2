<?php if(!isset($db,$courseId,$assignment)){http_response_code(403);exit;}
// Notas oficiales por modulo y diploma del curso en una sola pestana.
$dips=$ver('diplomas')?aula_rows($db,"SELECT codigo_unico FROM diplomas WHERE tipo='curso' AND asignacion_id=? ORDER BY emitido_en DESC",[$assignment['id']]):[];
?>
<div class="aula-overview">
    <div><strong><?= is_numeric($control['posevaluacion']??null)?aula_h($control['posevaluacion']).' / 100':'Pendiente' ?></strong><span>Nota final del curso</span></div>
    <div><strong><?= is_numeric($control['asistencia']??null)?aula_h(round((float)$control['asistencia'])).'%':'Pendiente' ?></strong><span>Asistencia (sobre las sesiones impartidas)</span></div>
    <div><strong><?= $dips||!empty($control['diploma'])?'Disponible':'En proceso' ?></strong><span>Diploma</span></div>
</div>
<?php if($ver('diplomas')): ?>
<section class="aula-card"><h2>Mi diploma</h2>
<?php foreach($dips as $d): ?><p><a class="btn btn-primary" href="validar_diploma.php?c=<?= rawurlencode($d['codigo_unico']) ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-certificate" aria-hidden="true"></span> Ver diploma / imprimir</a></p><?php endforeach; ?>
<?php if(!empty($control['diploma'])&&preg_match('#^https://#i',$control['diploma'])): ?><p><a href="<?= aula_h($control['diploma']) ?>" target="_blank" rel="noopener noreferrer">Abrir mi diploma ↗</a></p>
<?php elseif(!empty($control['diploma'])): ?><p><a href="aula_archivo.php?diploma=<?= (int)$assignment['id'] ?>">Descargar mi diploma (PDF)</a></p><?php endif; ?>
<?php if(!$dips&&empty($control['diploma'])): ?><p>Tu diploma aún no ha sido emitido. Se habilita aquí cuando coordinación lo emita, al cumplir la nota y la asistencia mínimas del curso.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php if($ver('notas')): $mods=aula_rows($db,'SELECT m.nombre,cc.evaluacion,cc.posevaluacion FROM curso_modulos m LEFT JOIN control_curso_modulos cc ON cc.curso_modulo_id=m.id AND cc.asignacion_id=? WHERE m.curso_id=? ORDER BY m.orden,m.id',[$assignment['id'],$courseId]); ?>
<section class="aula-card"><h2>Resultados por módulo</h2>
<?php if($mods): ?><div class="aula-mi-asis-wrap"><table class="aula-mi-asis"><thead><tr><th>Módulo</th><th>Pre-evaluación</th><th>Post-evaluación</th></tr></thead><tbody>
<?php foreach($mods as $m): ?><tr><td><?= aula_h($m['nombre']) ?></td><td><?= aula_h($m['evaluacion']??'Pendiente') ?></td><td><strong><?= aula_h($m['posevaluacion']??'Pendiente') ?></strong></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p class="aula-muted">El curso no tiene módulos con nota por separado.</p><?php endif; ?>
<p>Las evaluaciones del aula muestran sus resultados al publicarlos el docente. Las notas publicadas se integran automáticamente al módulo o curso configurado.</p></section>
<?php endif; ?>
