<?php if(!isset($db,$courseId,$assignment)){http_response_code(403);exit;}
$surveys=$surveys??aula_instructor_surveys($db,$courseId,$assignment['id']); ?>
<section class="aula-card"><h2>Evaluación del instructor</h2><p>Evalúa una sola vez a cada instructor del curso, aunque imparta varias sesiones. La fecha de apertura indica cuándo podrás responder.</p>
<div class="aula-courses"><?php foreach($surveys as $survey): ?><article class="aula-survey-card"><div class="aula-survey-heading"><span class="glyphicon glyphicon-user" aria-hidden="true"></span><h3><?= aula_h($survey['instructor']) ?></h3></div><p><?= (int)$survey['sesiones'] ?> sesión(es) en este curso</p>
<?php if($survey['respondida']): ?><span class="aula-badge is-complete">✓ Evaluación completada</span>
<?php elseif($survey['disponible']&&$survey['token']): ?><span class="aula-badge is-complete">Disponible</span><p>Solo se permite un envío.</p><a class="btn btn-primary" href="evaluacion.php?token=<?= rawurlencode($survey['token']) ?>" target="_blank" rel="noopener">Evaluar al instructor</a>
<?php else: ?><span class="aula-badge is-pending"><?= !$survey['habilitada']?'Deshabilitada':($survey['cierra']&&date('Y-m-d H:i:s')>=$survey['cierra']?'Cerrada':'Próximamente') ?></span><p>Se habilita: <strong><?= aula_h($survey['abre']??'Al finalizar sus sesiones') ?></strong><?php if($survey['cierra']): ?><br>Cierra: <?= aula_h($survey['cierra']) ?><?php endif; ?></p><?php endif; ?></article><?php endforeach; ?></div>
<?php if(!$surveys): ?><p>Aún no hay instructores asignados a las sesiones.</p><?php endif; ?></section>
