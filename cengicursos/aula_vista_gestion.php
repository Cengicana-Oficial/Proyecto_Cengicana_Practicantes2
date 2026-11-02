<?php if(!isset($db,$cid,$sessions)){http_response_code(403);exit;}
// Gestion del aula -> Lo que ve el estudiante: vista previa, secciones, anuncio y visibilidad de recursos.
$vistaCfg=aula_vista_config($db,$cid);$vistaLista=aula_vista_ready($db);
$vistaAlumnos=aula_rows($db,'SELECT a.id,p.nombre_participantes,COALESCE(a.usuarios_id,p.usuarios_id) AS cuenta FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1 ORDER BY p.nombre_participantes',[$cid]);
$vistaRecursos=$vistaLista?aula_resources($db,$cid):[];
$vistaSecciones=['resumen'=>'Resumen']+AULA_SECCIONES_CONFIGURABLES;
?>
<section class="aula-card aula-vista" id="vista">
<div class="aula-vista-cab"><div><h2><span class="glyphicon glyphicon-eye-open" aria-hidden="true"></span> Lo que ve el estudiante</h2>
<p>Revisa el aula tal como la ve un participante y decide qué secciones y recursos están disponibles, como en Moodle.</p></div></div>

<div class="aula-vista-grid">
<div class="aula-vista-bloque">
<h3>1. Ver como estudiante</h3>
<?php if($vistaAlumnos): ?>
<form method="get" action="mi_aula.php" target="_blank" class="aula-form">
<input type="hidden" name="curso" value="<?= $cid ?>">
<label>Participante<select name="vista_previa"><?php foreach($vistaAlumnos as $al): ?><option value="<?= (int)$al['id'] ?>"><?= aula_h($al['nombre_participantes']) ?><?= $al['cuenta']?'':' · sin cuenta vinculada' ?></option><?php endforeach; ?></select></label>
<label>Abrir en<select name="seccion"><?php foreach($vistaSecciones as $clave=>$titulo): ?><option value="<?= $clave ?>"><?= aula_h($titulo) ?><?= in_array($clave,$vistaCfg['ocultas'],true)?' (oculta)':'' ?></option><?php endforeach; ?></select></label>
<button class="btn btn-primary"><span class="glyphicon glyphicon-new-window" aria-hidden="true"></span> Ver como estudiante</button>
<small>Se abre en otra pestaña, solo de consulta: no registra asistencia, intentos ni notas. Muestra los datos reales de ese participante.</small>
</form>
<?php else: ?>
<p class="aula-muted">Para la vista previa se necesita al menos un participante asignado al curso.</p>
<?php endif; ?>
</div>

<div class="aula-vista-bloque">
<h3>2. Secciones visibles</h3>
<?php if(!$vistaLista): ?><p class="alert alert-info">Aplica la migración <code>20261021_aula_vista_estudiante.sql</code>.</p><?php else: ?>
<form method="post" class="aula-form">
<?= aula_csrf() ?>
<input type="hidden" name="accion" value="vista">
<fieldset class="aula-vista-secciones"><legend>Secciones visibles en el menú del estudiante</legend>
<label class="is-fija"><input type="checkbox" checked disabled> Resumen <small>(siempre)</small></label>
<?php foreach(AULA_SECCIONES_CONFIGURABLES as $clave=>$titulo): ?><label><input type="checkbox" name="secciones[]" value="<?= $clave ?>" <?= in_array($clave,$vistaCfg['ocultas'],true)?'':'checked' ?>> <?= aula_h($titulo) ?></label><?php endforeach; ?>
</fieldset>
<p class="aula-muted">Los mensajes del Resumen (bienvenida, enlace de Teams, avisos) se publican en <a href="#anuncios">Anuncios</a>.</p>
<button class="btn btn-primary">Guardar secciones visibles</button>
</form>
<?php endif; ?>
</div>
</div>

<?php if($vistaLista): ?>
<h3 class="aula-vista-sub">3. Visibilidad de grabaciones y materiales <small>· <?= count($vistaRecursos) ?> recurso(s)</small></h3>
<?php if(!$vistaRecursos): ?><p class="aula-muted">Aún no hay recursos publicados. Agrégalos en <a href="#material">Material y videos</a>.</p><?php endif; ?>
<div class="aula-vista-recursos">
<?php foreach($vistaRecursos as $r): [$estado,$tono]=aula_recurso_estado($r); ?>
<form method="post" class="aula-vista-recurso">
<?= aula_csrf() ?>
<input type="hidden" name="accion" value="recurso_visibilidad"><input type="hidden" name="recurso" value="<?= (int)$r['id'] ?>">
<span class="glyphicon glyphicon-<?= $r['tipo']==='video'?'film':'file' ?>" aria-hidden="true"></span>
<span class="aula-vista-recurso-txt"><strong><?= aula_h($r['titulo']) ?></strong><small><?= $r['sesion_id']?aula_h(date('d/m/Y',strtotime($r['fecha'])).' · '.$r['tema']):'Material general del curso' ?></small></span>
<span class="aula-badge <?= $tono ?>"><?= aula_h($estado) ?></span>
<label class="aula-vista-check"><input type="checkbox" name="visible" <?= (int)$r['visible']?'checked':'' ?>> Visible</label>
<label class="aula-vista-fecha">Desde<input type="datetime-local" name="disponible_desde" value="<?= $r['disponible_desde']?aula_h(str_replace(' ','T',substr($r['disponible_desde'],0,16))):'' ?>"></label>
<button class="btn btn-default btn-sm">Guardar</button>
</form>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
