<?php if(!isset($db,$cid)){http_response_code(403);exit;}
// Gestion del aula -> Diplomas: estado del diploma de cada participante, con su nota y asistencia, y carga individual
// (PDF o enlace). Lo cargado aqui aparece en el aula del estudiante (Notas y diploma, Inicio, Mis cursos) y en Seguimiento.
$dipGestiona=function_exists('cengi_puede_gestionar_diplomas')&&cengi_puede_gestionar_diplomas();
try{$dipFilas=aula_rows($db,"SELECT a.id,p.nombre_participantes,cc.asistencia,cc.posevaluacion,cc.diploma,d.codigo_unico,d.pdf_path,d.emitido_en
    FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id LEFT JOIN control_cursos cc ON cc.asignacion_id=a.id
    LEFT JOIN diplomas d ON d.id=(SELECT MAX(x.id) FROM diplomas x WHERE x.tipo='curso' AND x.asignacion_id=a.id)
    WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1 ORDER BY p.nombre_participantes",[$cid]);}
catch(PDOException $e){$dipFilas=[];error_log('Aula diplomas: '.$e->getMessage());}
$dipListos=count(array_filter($dipFilas,static function($r){return $r['codigo_unico']||$r['diploma'];}));
$dipEnlace=static function($ruta){$u=function_exists('cengi_normalizar_url_archivo')?cengi_normalizar_url_archivo($ruta):trim((string)$ruta);return $u!==''?$u:null;};
?>
<section class="aula-card" id="diplomas-curso">
<div class="aula-editor-cab"><div><h2><span class="glyphicon glyphicon-certificate" aria-hidden="true"></span> Diplomas del curso <small class="aula-muted"><?= $dipListos ?> de <?= count($dipFilas) ?> publicados</small></h2>
<p>Carga el PDF o pega el enlace del diploma de cada participante. Se publica con código de validación y el estudiante lo ve al instante en <b>Notas y diploma</b>; también queda en su ficha de Seguimiento.</p></div>
<div class="aula-editor-herr"><a class="btn btn-default btn-sm" href="diplomas.php?tab=disenar">Emitir con el diseñador</a><a class="btn btn-default btn-sm" href="diplomas.php?tab=curso">Carga masiva de PDF</a></div></div>
<?php if(!$dipFilas): ?><p class="aula-muted">No hay participantes asignados.</p><?php else: ?>
<div class="aula-mi-asis-wrap"><table class="aula-mi-asis aula-dip-tabla">
<thead><tr><th>Participante</th><th>Asistencia</th><th>Nota final</th><th>Diploma</th><?php if($dipGestiona): ?><th>Cargar o cambiar</th><?php endif; ?></tr></thead><tbody>
<?php foreach($dipFilas as $r): $aid=(int)$r['id'];$archivo=$dipEnlace($r['pdf_path']?:$r['diploma']);$asis=is_numeric($r['asistencia'])?(float)$r['asistencia']:null; ?>
<tr id="dip-<?= $aid ?>"><td><strong><?= aula_h($r['nombre_participantes']) ?></strong></td>
<td class="<?= $asis!==null&&$asis<80?'is-bajo':'' ?>"><?= $asis!==null?round($asis).'%':'—' ?></td>
<td><?= is_numeric($r['posevaluacion'])?aula_h($r['posevaluacion']):'—' ?></td>
<td><?php if($r['codigo_unico']): ?><span class="aula-badge is-complete">✓ Publicado</span> <small class="aula-muted"><?= aula_h($r['codigo_unico']) ?><?= $r['emitido_en']?' · '.date('d/m/Y',strtotime($r['emitido_en'])):'' ?></small><br>
    <a href="validar_diploma.php?c=<?= rawurlencode($r['codigo_unico']) ?>" target="_blank" rel="noopener">Ver / validar ↗</a><?php if($archivo): ?> · <a href="<?= aula_h($archivo) ?>" target="_blank" rel="noopener"><?= preg_match('#^https?://#i',(string)$r['pdf_path'])?'Enlace':'PDF' ?> ↗</a><?php endif; ?>
<?php elseif($archivo): ?><span class="aula-badge is-complete">✓ Cargado en Seguimiento</span><br><a href="<?= aula_h($archivo) ?>" target="_blank" rel="noopener">Abrir ↗</a>
<?php else: ?><span class="aula-badge is-pending">Pendiente</span><?php endif; ?></td>
<?php if($dipGestiona): ?><td><form method="post" enctype="multipart/form-data" class="aula-dip-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="diploma_guardar"><input type="hidden" name="asignacion" value="<?= $aid ?>"><input type="hidden" name="ancla" value="dip-<?= $aid ?>">
    <input type="file" name="diploma_pdf" accept="application/pdf" aria-label="PDF del diploma de <?= aula_h($r['nombre_participantes']) ?>">
    <input type="url" name="enlace" maxlength="255" placeholder="o pega el enlace https://…" aria-label="Enlace del diploma">
    <button class="btn btn-primary btn-sm"><?= $r['codigo_unico']||$archivo?'Reemplazar':'Publicar' ?></button></form>
    <?php if($r['codigo_unico']&&$r['pdf_path']): ?><form method="post" class="aula-dip-quitar" onsubmit="return confirm('¿Quitar el diploma de <?= aula_h(addslashes($r['nombre_participantes'])) ?>? Su código de validación dejará de funcionar.')"><?= aula_csrf() ?><input type="hidden" name="accion" value="diploma_quitar"><input type="hidden" name="asignacion" value="<?= $aid ?>"><input type="hidden" name="ancla" value="diplomas-curso"><button class="btn btn-link btn-sm">Quitar</button></form><?php endif; ?></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>
