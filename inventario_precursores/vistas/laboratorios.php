<?php
declare(strict_types=1);
$labs = inv_laboratorios($pdo);
$jefes = inv_usuarios_con_permiso($menuPdo, 'inventario.vales.autorizar');
$auditoria = $pdo->query('SELECT usuario_nombre, accion, creado_en FROM auditoria ORDER BY id DESC LIMIT 30')->fetchAll();
?>
<div class="row"><div class="grow"><h1 class="pt">Laboratorios</h1><p class="sub">Laboratorios propietarios de inventario y su jefe inmediato (firma de visto bueno).</p></div><button class="btn p" type="button" data-editar-lab="">＋ Nuevo laboratorio</button></div>
<div class="card"><div class="tw"><table><thead><tr><th>Código</th><th>Laboratorio</th><th>Jefe inmediato (VoBo)</th><th class="n">Productos</th><th class="n">Lotes con existencia</th><th class="n">Vales en trámite</th><th class="n">Usuarios</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($labs as $l):
    $d = inv_lab_dependencias($pdo, (int) $l['id']);
    $activo = (int) $l['activo'] === 1;
    $jefe = inv_nombre_usuario($menuPdo, (int) $l['jefe_usuario_id']);
    $datos = ['id' => (int) $l['id'], 'codigo' => $l['codigo'], 'nombre' => $l['nombre'], 'jefe' => (int) $l['jefe_usuario_id']];
    $bloqueado = $d['lotes_con_saldo'] > 0 || $d['vales_en_tramite'] > 0 || $d['usuarios'] > 0; ?>
  <tr style="<?= $activo ? '' : 'opacity:.6' ?>"><td class="mono"><?= e($l['codigo']) ?></td><td><b><?= e($l['nombre']) ?></b><?= !$activo && $l['baja_motivo'] ? '<div class="hint">Inactivo desde ' . e(inv_fecha($l['baja_en'])) . ' · ' . e($l['baja_motivo']) . '</div>' : '' ?></td>
    <td><?= e($jefe !== '' ? $jefe : '—') ?></td><td class="n"><?= $d['productos'] ?></td><td class="n"><?= $d['lotes_con_saldo'] ?></td><td class="n"><?= $d['vales_en_tramite'] ?></td><td class="n"><?= $d['usuarios'] ?></td>
    <td><?= $activo ? '<span class="pill p-ok">Activo</span>' : '<span class="pill p-mut">Inactivo</span>' ?></td>
    <td style="white-space:nowrap"><button type="button" class="btn sm" data-editar-lab="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>">Editar</button>
      <?php if ($activo): ?>
        <?php if ($d['historial'] === 0 && $d['usuarios'] === 0): ?>
          <button type="button" class="btn sm d" data-eliminar-lab="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>">🗑 Eliminar</button>
        <?php else: ?>
          <button type="button" class="btn sm d" data-baja-lab="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>" <?= $bloqueado ? 'disabled title="Tiene existencias, vales en trámite o usuarios asignados"' : '' ?>>Desactivar</button>
        <?php endif; ?>
      <?php else: ?>
        <form method="post" action="<?= e(inv_url(['view' => 'laboratorios'])) ?>" style="display:inline" data-confirmar="¿Reactivar el laboratorio <?= e($l['nombre']) ?>?"><?= inv_form_campos('lab_reactivar') ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="btn sm">↺ Reactivar</button></form>
      <?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="hint">Un laboratorio con movimientos (entradas o vales) no se borra: se <b>desactiva</b> para conservar la trazabilidad regulatoria, y solo si no tiene existencias, vales en trámite ni usuarios asignados. Solo se elimina definitivamente si nunca tuvo movimientos.</p></div>

<div class="card"><h3>Bitácora administrativa (últimos 30 movimientos)</h3><table><tbody>
<?php foreach ($auditoria as $a): ?><tr><td style="width:150px"><?= e(inv_fecha($a['creado_en'])) ?></td><td style="width:220px"><?= e($a['usuario_nombre']) ?></td><td><?= e($a['accion']) ?></td></tr><?php endforeach; ?>
<?php if (!$auditoria): ?><tr><td class="empty">Sin movimientos registrados</td></tr><?php endif; ?></tbody></table></div>

<dialog id="dlgLab" class="md"><form method="post" action="<?= e(inv_url(['view' => 'laboratorios'])) ?>">
  <?= inv_form_campos('lab_guardar') ?><input type="hidden" name="id" value="0">
  <header><h3 id="labTitulo">Nuevo laboratorio</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><div class="fg">
    <label class="f">Código (2 a 4 letras o números)<input name="codigo" maxlength="4" required></label><label class="f">Nombre<input name="nombre" maxlength="120" required></label>
    <label class="f">Jefe inmediato<select name="jefe_usuario_id"><option value="0">— Sin asignar —</option><?php foreach ($jefes as $id => $nombre): ?><option value="<?= (int) $id ?>"><?= e($nombre) ?></option><?php endforeach; ?></select></label></div>
    <p class="hint">Si el jefe aún no existe, créelo en Usuarios de la plataforma y asígnele un rol de este módulo con el permiso de visto bueno.</p></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn p" type="submit">Guardar</button></footer></form></dialog>

<dialog id="dlgBajaLab" class="md"><form method="post" action="<?= e(inv_url(['view' => 'laboratorios'])) ?>">
  <?= inv_form_campos('lab_baja') ?><input type="hidden" name="id" value="0">
  <header><h3 id="bajaTitulo">Desactivar laboratorio</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><label class="f">Motivo de la baja<input name="motivo" maxlength="255" required></label></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn d" type="submit">Desactivar</button></footer></form></dialog>

<dialog id="dlgEliminarLab" class="md"><form method="post" action="<?= e(inv_url(['view' => 'laboratorios'])) ?>">
  <?= inv_form_campos('lab_eliminar') ?><input type="hidden" name="id" value="0">
  <header><h3 id="elimTitulo">Eliminar laboratorio</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><div class="alert bad" style="margin-top:0">Este laboratorio nunca tuvo movimientos ni usuarios; se eliminará definitivamente.</div>
    <label class="f">Escriba el código <b id="elimCodigo"></b> para confirmar<input name="confirmar_codigo" maxlength="4" required autocomplete="off"></label></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn d" type="submit">Eliminar</button></footer></form></dialog>
