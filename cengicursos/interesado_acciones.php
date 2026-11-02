<?php
/*
 * Acciones de un interesado (inscripciones.php, vista Interesados). Solo se muestran los
 * pasos que aplican según quién paga:
 *  - Pago propio: con boleta (la subió el participante o se cargó) solo falta que
 *    Administración valide el pago. Sin boleta: reenviar el formulario al participante o
 *    cargarla Capacitación o Administración; siempre valida Administración.
 *  - Ingenio: validación por el ingenio, o por Capacitación indicando quién autoriza y el
 *    motivo; si la persona está en un grupo de pago, se puede aplicar a todo el grupo.
 */
$idAccion = (int) $s['id_solicitud'];
$activoAccion = $s['estado'] !== 'Rechazado';
$esIngenioAccion = $s['tipo_pago'] === 'Ingenio';
$esPropioAccion = $s['tipo_pago'] === 'Propio';
$capAccion = $activoAccion && (cengi_es_admin() || cengi_ins_delega_cengicana()) && ($esIngenioAccion || $esPropioAccion);
$igAccion = $activoAccion && $esIngenioAccion && cengi_es_ingenio() && cengi_ins_puede_validar_ingenio($s['nombre_ingenios']);
$adminAccion = $activoAccion && $esPropioAccion && cengi_ins_puede_validar_pagos();
$tieneBoleta = !empty($s['comprobante_ruta']);
$ingenioValidado = ($s['validacion_ingenio'] ?? '') === 'validada';
// Otras personas del mismo grupo de pago del ingenio que aún esperan validación.
$grupoAccion = $esIngenioAccion && $s['grupo_pago_id'] ? array_diff_key($gruposPendientes[(int) $s['grupo_pago_id']] ?? [], [$idAccion => true]) : [];
?>
<details class="ins-action-list">
 <summary class="ins-btn is-primary">Acciones <span aria-hidden="true">▾</span></summary>
 <div class="ins-action-options">
 <?php if ($esPropioAccion): ?>
  <?php if ($tieneBoleta): ?>
   <?php /* Con boleta (la subió el participante o se cargó): solo falta que Administración valide el pago. */ ?>
   <a class="ins-opt-boleta" target="_blank" rel="noopener" href="comprobante_inscripcion.php?s=<?= $idAccion ?>"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver boleta recibida<?= (string) $s['boleta'] !== '' ? ' · ' . cengi_pp_h($s['boleta']) : '' ?></a>
   <?php if ($s['pago_estado'] !== 'pagado'): ?>
    <?php if ($adminAccion): ?>
     <a class="ins-opt-principal" href="interesado_validar_pago.php?id=<?= $idAccion ?>&amp;via=administracion"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Validar pago (Administración)</a>
    <?php else: ?>
     <button disabled title="Administración revisa la boleta y confirma el pago">Pendiente de validación por Administración</button>
    <?php endif; ?>
   <?php endif; ?>
  <?php elseif ($activoAccion && $s['pago_estado'] !== 'pagado'): ?>
   <?php /* Sin boleta: reenviar el formulario o cargarla Capacitación/Administración; siempre valida Administración. */ ?>
   <?php if ($capAccion || $adminAccion): ?>
    <form method="post" onsubmit="return confirm('¿Enviar a <?= cengi_pp_h(addslashes($s['correo'])) ?> el correo con el enlace para adjuntar su boleta?');"><input type="hidden" name="csrf" value="<?= cengi_pp_h($_SESSION['ins_csrf']) ?>"><input type="hidden" name="accion" value="reenviar_formulario"><input type="hidden" name="id" value="<?= $idAccion ?>">
     <button><span class="glyphicon glyphicon-envelope" aria-hidden="true"></span> Reenviar formulario al participante</button></form>
   <?php endif; ?>
   <?php if ($capAccion): ?>
    <a href="interesado_validar_pago.php?id=<?= $idAccion ?>&amp;via=capacitacion"><span class="glyphicon glyphicon-upload" aria-hidden="true"></span> Cargar boleta (Capacitación)</a>
   <?php endif; ?>
   <?php if ($adminAccion): ?>
    <a href="interesado_validar_pago.php?id=<?= $idAccion ?>&amp;via=administracion"><span class="glyphicon glyphicon-upload" aria-hidden="true"></span> Cargar boleta y validar pago (Administración)</a>
   <?php endif; ?>
   <?php if ($capAccion || $adminAccion): $enlaceAccion = cengi_cob_url_comprobante($db, $s); ?>
    <details class="ins-authorization"><summary>Copiar el enlace del formulario</summary>
     <div class="ins-share-proof"><label>Enlace seguro para adjuntar la boleta<input class="form-control" readonly aria-label="Enlace seguro del participante" value="<?= cengi_pp_h($enlaceAccion) ?>" onclick="this.select()"></label><button type="button" onclick="var input=this.previousElementSibling.querySelector('input');input.select();if(navigator.clipboard){navigator.clipboard.writeText(input.value).then(()=>{this.textContent='Enlace copiado';}).catch(()=>{this.textContent='Seleccionado: Ctrl+C';});}else{this.textContent='Seleccionado: Ctrl+C';}">Copiar enlace</button><small>Para enviarlo por otro medio (WhatsApp, Teams...).</small></div>
    </details>
   <?php endif; ?>
  <?php endif; ?>
 <?php elseif ($esIngenioAccion): ?>
  <?php if ($ingenioValidado): ?>
   <button disabled>Validado por el ingenio</button>
  <?php else: ?>
   <?php if ($igAccion): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= cengi_pp_h($_SESSION['ins_csrf']) ?>"><input type="hidden" name="accion" value="autorizar_ingenio"><input type="hidden" name="id" value="<?= $idAccion ?>"><button>1. Validación por el ingenio</button></form>
   <?php endif; ?>
   <?php if ($capAccion): ?>
    <details class="ins-authorization"><summary><?= $igAccion ? '2.' : '1.' ?> Validar por Capacitación</summary>
     <form method="post"><input type="hidden" name="csrf" value="<?= cengi_pp_h($_SESSION['ins_csrf']) ?>"><input type="hidden" name="via" value="capacitacion"><input type="hidden" name="accion" value="autorizar_ingenio"><input type="hidden" name="id" value="<?= $idAccion ?>">
      <label>Quién autoriza por el ingenio<input class="form-control" name="autoriza" required maxlength="180" placeholder="Nombre y cargo"></label>
      <label>Motivo de la validación por Capacitación<textarea class="form-control" name="motivo" required maxlength="300" placeholder="Por qué valida Capacitación y cómo se autorizó (correo, llamada, acuerdo...)"></textarea></label>
      <?php if ($grupoAccion): ?>
       <label class="ins-grupo-check"><input type="checkbox" name="grupo" value="1" checked>
        <span>Aplicar a todo el grupo de pago (<?= count($grupoAccion) + 1 ?> personas)<small>También: <?= cengi_pp_h(implode(', ', array_slice($grupoAccion, 0, 6)) . (count($grupoAccion) > 6 ? ' y ' . (count($grupoAccion) - 6) . ' más' : '')) ?>. El motivo queda registrado en cada una.</small></span></label>
      <?php endif; ?>
      <button class="ins-btn is-primary">Confirmar validación</button></form></details>
   <?php elseif (!$igAccion): ?>
    <button disabled title="La valida el ingenio o Capacitación">Esperando validación del ingenio</button>
   <?php endif; ?>
  <?php endif; ?>
  <?php if ($s['proforma_id']): ?><a href="proforma.php?id=<?= (int) $s['proforma_id'] ?>"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver proforma N.° <?= (int) $s['proforma_numero'] ?></a><?php endif; ?>
 <?php endif; ?>
 <button class="ins-reject" type="button" <?= !$activoAccion || !$puedeGestionar ? 'disabled' : '' ?> data-toggle="modal" data-target="#motivo-modal" data-accion="rechazar" data-id="<?= $idAccion ?>" data-titulo="<?= cengi_pp_h('Rechazar: '.$s['nombre_participante']) ?>">Rechazar</button>
 <?php if (!$activoAccion && $puedeGestionar): ?><form method="post"><input type="hidden" name="csrf" value="<?= cengi_pp_h($_SESSION['ins_csrf']) ?>"><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id" value="<?= $idAccion ?>"><button>Reactivar interesado</button></form><?php endif; ?>
 </div>
</details>
