<?php if(!isset($lista,$todas,$db)){http_response_code(403);exit;}
/*
 * Vista Inscritos (inscripciones.php?vista=inscripciones). Incluye a quienes se asignaron
 * directamente al curso antes del proceso de validación: siguen inscritos, pero su
 * validación del ingenio o su pago propio quedan "por regularizar" y se gestionan aquí
 * con las mismas acciones que en Interesados (sin rechazar: la baja se hace desde el curso).
 * Administración puede seleccionar a los que tienen el pago pendiente y registrarlos como
 * pagados en grupo (pago_lote_modal.php), también cuando la proforma no se generó en el
 * sistema o no hubo validación previa.
 */
require_once __DIR__ . '/pago_lote_helpers.php';
function cengi_ins_pago_etiqueta(array $s) {
    if($s['tipo_pago']==='Exonerado')return 'Sin costo';
    if(cengi_ins_estado_cobro($s)==='pagado')return 'Pagado';
    if($s['tipo_pago']==='Propio')return in_array($s['pago_estado'],['en revisión'],true)?'Boleta por validar':'Pago por regularizar';
    if(($s['validacion_ingenio']??'')!=='validada')return 'Falta validación del ingenio';
    return !empty($s['proforma_id'])?'Pago proforma':'Pendiente de proforma';
}
$regularizar=($_GET['regularizar']??'')==='1';
$soloPagoPendiente=($_GET['pago']??'')==='pendiente';
$puedeLote=cengi_ins_puede_validar_pagos();
$stats=['Por regularizar'=>0,'Pago pendiente'=>0,'Pago proforma'=>0,'Pendiente de proforma'=>0,'Pagado'=>0];
foreach($todas as $s){if(cengi_ins_por_regularizar($s))$stats['Por regularizar']++;if(cengi_ins_lote_pagable($s))$stats['Pago pendiente']++;$label=cengi_ins_pago_etiqueta($s);if(isset($stats[$label]))$stats[$label]++;}
if($regularizar)$lista=array_values(array_filter($lista,'cengi_ins_por_regularizar'));
if($soloPagoPendiente)$lista=array_values(array_filter($lista,'cengi_ins_lote_pagable'));
$pagablesLista=$puedeLote?count(array_filter($lista,'cengi_ins_lote_pagable')):0;
$vistaAccion='inscripciones';
// KPI que funciona como filtro (se activa / desactiva al hacer clic).
$kpiFiltro=static function($label,$n,$param,$activo,$titulo)use($busqueda,$regularizar,$soloPagoPendiente){
    $q=array_filter(['vista'=>'inscripciones','regularizar'=>$regularizar?'1':'','pago'=>$soloPagoPendiente?'pendiente':'','q'=>$busqueda]);
    if($activo)unset($q[$param]);else $q[$param]=$param==='pago'?'pendiente':'1';
    return '<a class="ins-kpi '.($n?'is-naranja':'is-verde').($activo?' is-activo':'').'" href="?'.cengi_pp_h(http_build_query($q)).'" title="'.cengi_pp_h($titulo).'"><span class="ins-kpi-body"><span class="ins-kpi-val">'.(int)$n.'</span><span class="ins-kpi-label">'.cengi_pp_h($label).($activo?' · filtro activo':'').'</span></span></a>';
};
?>
<div class="ins-kpis"><?php foreach($stats as $label=>$n): ?><?php if($label==='Por regularizar'): echo $kpiFiltro($label,$n,'regularizar',$regularizar,$regularizar?'Ver todos los inscritos':'Ver solo asignados con validación o pago pendiente'); elseif($label==='Pago pendiente'): echo $kpiFiltro($label,$n,'pago',$soloPagoPendiente,$soloPagoPendiente?'Ver todos los inscritos':'Ver solo inscritos con el pago pendiente de registrar'); else: ?><div class="ins-kpi is-verde"><span class="ins-kpi-body"><span class="ins-kpi-val"><?= $n ?></span><span class="ins-kpi-label"><?= $label ?></span></span></div><?php endif; ?><?php endforeach; ?></div>
<section class="pp-card"><p>Participantes asignados al curso. Si alguien se asignó directamente antes de validar al ingenio o el pago, aparece como <b>por regularizar</b>: sigue en el curso y desde <b>Acciones</b> se completa la validación del ingenio o se solicita y valida la boleta de pago.<?php if($puedeLote): ?> Para registrar varios pagos a la vez (también si la proforma no se generó en el sistema), marca a las personas y usa <b>Registrar como pagados</b>.<?php endif; ?></p><form method="get" class="ins-toolbar"><input type="hidden" name="vista" value="inscripciones"><?php if($regularizar): ?><input type="hidden" name="regularizar" value="1"><?php endif; ?><?php if($soloPagoPendiente): ?><input type="hidden" name="pago" value="pendiente"><?php endif; ?><label class="ins-field">Buscar inscrito o curso<input name="q" type="search" value="<?= cengi_pp_h($busqueda) ?>"></label><button class="btn btn-default">Buscar</button></form></section>
<?php if($puedeLote && $pagablesLista): ?>
<div class="cob-lote ins-lote-barra" id="ins-lote-barra">
    <label class="cob-todos"><input type="checkbox" id="ins-lote-todos"> Seleccionar todos los pendientes de pago (<?= $pagablesLista ?>)</label>
    <span class="cob-lote-n"><b id="ins-lote-n">0</b> seleccionado(s)</span>
    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#lote-modal" id="ins-lote-btn" disabled><span class="glyphicon glyphicon-usd" aria-hidden="true"></span> Registrar como pagados</button>
    <span class="pp-sub cob-lote-ayuda">Por persona: monto pagado, forma y fecha del pago, y boleta, N.° de proforma o comentario. Se revisa el monto contra la cuota.</span>
</div>
<?php endif; ?>
<section class="ins-table-card"><div class="pp-table-wrap"><table class="cengi-org-table ins-table"><thead><tr><?php if($puedeLote): ?><th class="ins-col-check"></th><?php endif; ?><th>Participante</th><th>Curso</th><th>Quién paga</th><th>Estado de pago</th><th>Validado por / verificado por</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($lista as $s): $pendienteReg=cengi_ins_por_regularizar($s); $etiquetaPago=cengi_ins_pago_etiqueta($s); ?><tr><?php if($puedeLote): ?><td class="ins-col-check" data-label="Seleccionar"><?php if(cengi_ins_lote_pagable($s)): ?><input type="checkbox" class="ins-lote-check" name="claves[]" value="s:<?= (int)$s['id_solicitud'] ?>" form="ins-lote-form"<?= cengi_ins_lote_datos($s) ?> aria-label="Seleccionar a <?= cengi_pp_h($s['nombre_participante']) ?>"><?php endif; ?></td><?php endif; ?><td data-label="Participante"><strong><?= cengi_pp_h($s['nombre_participante']) ?></strong><span class="pp-sub"><?= cengi_pp_h($s['nombre_ingenios']) ?></span><?php if(strpos((string)$s['asignado_por'],'Asignaci')===0): ?><span class="pp-sub">Asignado antes del proceso de validación</span><?php endif; ?></td><td data-label="Curso"><?= cengi_pp_h($s['plan_nombre']) ?></td><td data-label="Quién paga"><?= cengi_pp_h($s['tipo_pago']) ?><?php if($s['tipo_pago']==='Propio'&&($s['cuota']??'')==='asociado'&&$s['ingenio_id']): ?><span class="pp-sub">cuota de ingenio (asociado)</span><?php endif; ?></td><td data-label="Estado de pago"><span class="ins-pill <?= in_array($etiquetaPago,['Pagado','Sin costo','Pago proforma'],true)?'is-verde':'is-naranja' ?>"><?= $etiquetaPago ?></span><?php if($s['proforma_id']): ?><span class="pp-sub">Proforma #<?= (int)$s['proforma_numero'] ?></span><?php endif; ?><?php if($s['monto']!==null && $s['tipo_pago']!=='Exonerado'): ?><span class="pp-sub">Cuota <?= cengi_ins_dinero($s['monto'],$s['moneda']) ?></span><?php endif; ?><?php if(cengi_ins_estado_cobro($s)==='pagado' && $s['pago_forma']): ?><span class="pp-sub"><?= cengi_pp_h($s['pago_forma'].($s['pago_referencia']?' · '.$s['pago_referencia']:'').($s['pago_fecha']?' · '.cengi_pp_fecha($s['pago_fecha']):'')) ?></span><?php endif; ?><?php if($s['tipo_pago']==='Propio'): ?><span class="ins-boleta"><?= (string)$s['boleta']!=='' ? 'Boleta '.cengi_pp_h($s['boleta']) : 'Sin número de boleta' ?><?= $s['pago_fecha'] ? ' · '.cengi_pp_fecha($s['pago_fecha']) : '' ?><?= $s['monto_comprobante']!==null ? ' · '.cengi_ins_dinero($s['monto_comprobante'],$s['moneda']) : '' ?></span><?php if(!empty($s['comprobante_ruta'])): ?><a class="ins-comprobante" target="_blank" rel="noopener" href="comprobante_inscripcion.php?s=<?= (int)$s['id_solicitud'] ?>"><span class="glyphicon glyphicon-paperclip" aria-hidden="true"></span> Ver comprobante</a><?php else: ?><span class="pp-sub ins-sin-comprobante">Sin comprobante adjunto</span><?php endif; ?><?php endif; ?></td><td data-label="Validado por / verificado por"><?php if($s['tipo_pago']==='Propio'&&!empty($s['pago_validado_por'])): ?><span class="pp-sub">Validado por: <?= cengi_pp_h($s['pago_validado_por']) ?> · <?= cengi_pp_h($s['pago_validado_rol']) ?></span><span class="pp-sub">Evidencia: <?= cengi_pp_h($s['pago_validacion_evidencia']) ?></span>Verificado por: <?php endif; ?><?= cengi_pp_h($s['tipo_pago']==='Ingenio'?$s['validacion_ingenio_por']:$s['pago_verificado_por']) ?><span class="pp-sub"><?= cengi_pp_h($s['tipo_pago']==='Ingenio'?$s['validacion_ingenio_nota']:$s['pago_nota']) ?></span></td><td class="is-actions" data-label="Acciones"><?php if($pendienteReg): ?><?php require __DIR__ . '/interesado_acciones.php'; ?><?php else: ?><a class="ins-btn" href="inscripcion_detalle.php?id=<?= (int)$s['id_solicitud'] ?>">Ver detalle</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$lista): ?><tr><td colspan="<?= $puedeLote?7:6 ?>">No hay inscritos con estos filtros.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if($puedeLote && $pagablesLista): ?>
<form method="post" id="ins-lote-form"><input type="hidden" name="csrf" value="<?= cengi_pp_h($_SESSION['ins_csrf']) ?>"><input type="hidden" name="regularizar" value="<?= $regularizar?'1':'' ?>"><input type="hidden" name="q" value="<?= cengi_pp_h($busqueda) ?>">
<?php $loteFormId='ins-lote-form'; require __DIR__ . '/pago_lote_modal.php'; ?>
</form>
<link rel="stylesheet" href="css/cobranza.css?v=<?= (int)@filemtime(__DIR__.'/css/cobranza.css') ?>">
<script>
(function(){
    var casillas=document.querySelectorAll('.ins-lote-check'),todos=document.getElementById('ins-lote-todos');
    var actualizar=function(){var n=[].filter.call(casillas,function(c){return c.checked;}).length;document.getElementById('ins-lote-n').textContent=n;document.getElementById('ins-lote-btn').disabled=!n;document.getElementById('ins-lote-barra').classList.toggle('is-activo',n>0);if(todos){todos.checked=n>0&&n===casillas.length;todos.indeterminate=n>0&&n<casillas.length;}};
    casillas.forEach(function(c){c.addEventListener('change',actualizar);});
    if(todos)todos.addEventListener('change',function(){var v=this.checked;casillas.forEach(function(c){c.checked=v;});actualizar();});
    var form=document.getElementById('ins-lote-form');
    if(form)form.addEventListener('submit',function(e){if(e.defaultPrevented)return;var b=form.querySelectorAll('button[type="submit"]');setTimeout(function(){b.forEach(function(x){x.disabled=true;});},0);});
    actualizar();
})();
</script>
<?php endif; ?>
</main></body></html>
