<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$borrador = $_SESSION['inventario_borrador'] ?? [];
unset($_SESSION['inventario_borrador']);
$labId = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? ($borrador['laboratorio_id'] ?? 0));
$laboratorios = inv_laboratorios($pdo, true);
if ($alcance === 0 && $labId === 0 && $laboratorios) {
    $labId = (int) $laboratorios[0]['id'];
}
$lab = $alcance === -1 ? null : inv_laboratorio($pdo, $labId);
$lista = $lab ? inv_productos_de_lab($pdo, (int) $lab['id']) : [];
$libres = [];
if ($lab) {
    $disp = inv_disponible_por_producto(inv_lotes($pdo, ['laboratorio_id' => (int) $lab['id']]), (int) $lab['id']);
    $comp = inv_comprometido($pdo, (int) $lab['id']);
    foreach ($lista as $p) {
        $libres[(int) $p['id']] = max(0, ($disp[(int) $p['id']] ?? 0) - ($comp[(int) $p['id']] ?? 0));
    }
}
$jefe = $lab ? inv_nombre_usuario($menuPdo, (int) $lab['jefe_usuario_id']) : '';
$filas = $borrador['items'] ?? [['producto_id' => '', 'cantidad' => '', 'obs' => '']];
$proyecto = (string) ($borrador['proyecto'] ?? '');
$siguiente = sprintf('VAL-%d-%04d', (int) date('Y'), (int) $pdo->query('SELECT COALESCE(MAX(correlativo),0)+1 FROM vales WHERE anio = ' . (int) date('Y'))->fetchColumn());
$opciones = '<option value="">— Seleccione reactivo / material —</option>';
foreach ($lista as $p) {
    $opciones .= '<option value="' . (int) $p['id'] . '" data-unidad="' . e($p['unidad']) . '">'
        . e($p['codigo'] . ' · ' . $p['nombre'] . ' (' . inv_fmt($libres[(int) $p['id']]) . ' ' . $p['unidad'] . ')') . '</option>';
}
?>
<h1 class="pt">Nuevo vale de solicitud y descarga</h1>
<p class="sub">El listado de reactivos se filtra según la matriz autorizada del laboratorio y muestra la existencia libre (disponible menos lo comprometido en otros vales).</p>
<?php if ($alcance === -1): ?>
  <div class="alert warn">Su usuario no tiene un laboratorio asignado. Solicítelo al administrador del módulo.</div>
<?php elseif (!$lab): ?>
  <div class="alert warn">No hay laboratorios activos.</div>
<?php else: ?>
<form method="post" action="<?= e(inv_url(['view' => 'nuevo'])) ?>" <?= inv_firma_attr([['p' => 'sol', 'titulo' => 'Solicitado por: analista / solicitante', 'nombre' => $user['nombre'], 'fijo' => true]]) ?>>
  <?= inv_form_campos('vale_crear') ?>
  <input type="hidden" name="laboratorio_id" value="<?= (int) $lab['id'] ?>">
  <div class="card"><h3>1. Datos del laboratorio y solicitante</h3>
    <div class="fg">
      <label class="f">No. de vale (provisional)<input value="<?= e($siguiente) ?>" disabled></label>
      <label class="f">Fecha de solicitud<input value="<?= e(inv_fecha(inv_hoy())) ?>" disabled></label>
      <?php if ($alcance === 0): ?>
        <label class="f">Laboratorio solicitante<select data-ir-lab="<?= e(inv_url(['view' => 'nuevo'])) ?>">
          <?php foreach ($laboratorios as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === (int) $lab['id'] ? 'selected' : '' ?>>Laboratorio <?= e($l['nombre']) ?></option><?php endforeach; ?>
        </select></label>
      <?php else: ?>
        <label class="f">Laboratorio solicitante<input value="Laboratorio <?= e($lab['nombre']) ?>" disabled></label>
      <?php endif; ?>
      <label class="f">Solicitante<input value="<?= e($user['nombre']) ?>" disabled></label>
      <label class="f" style="grid-column:span 2">Proyecto / ensayo<input name="proyecto" value="<?= e($proyecto) ?>" maxlength="200" required placeholder="Ej. Titulación de acidez en muestras de jugo"></label>
      <label class="f">Jefe / supervisor inmediato<input value="<?= e($jefe !== '' ? $jefe : 'Sin asignar') ?>" disabled></label>
    </div>
  </div>
  <div class="card">
    <div class="row"><h3 class="grow" style="margin:0">2. Detalle de reactivos solicitados <span class="hint">· <?= count($lista) ?> productos autorizados para Lab. <?= e($lab['nombre']) ?></span></h3><button type="button" class="btn" id="agregarLinea">＋ Agregar línea</button></div>
    <div class="tw" style="margin-top:10px"><table><thead><tr><th>#</th><th style="min-width:260px">Código / reactivo</th><th>Unidad</th><th class="n">Libre para solicitar</th><th class="n">Cant. solicitada</th><th>Observaciones / uso</th><th></th></tr></thead>
    <tbody id="lineas" data-libres="<?= e(json_encode($libres)) ?>">
    <?php foreach ($filas as $i => $f):
        $sel = (int) ($f['producto_id'] ?? 0); ?>
      <tr class="linea"><td class="num"><?= $i + 1 ?></td>
        <td><select class="in prod" name="items[<?= $i ?>][producto_id]" style="width:100%"><?= $sel > 0 ? str_replace('value="' . $sel . '"', 'value="' . $sel . '" selected', $opciones) : $opciones ?></select></td>
        <td class="unidad"></td><td class="n libre"></td>
        <td class="n"><input class="in cant" name="items[<?= $i ?>][cantidad]" value="<?= e($f['cantidad'] ?? '') ?>" inputmode="decimal" style="width:100px;text-align:right" aria-label="Cantidad solicitada"><div class="hint exceso" style="color:var(--red)" hidden></div></td>
        <td><input class="in" name="items[<?= $i ?>][obs]" value="<?= e($f['obs'] ?? '') ?>" maxlength="255" style="width:100%" aria-label="Observaciones"></td>
        <td><button type="button" class="btn sm d quitar" aria-label="Quitar línea">✕</button></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <template id="plantillaLinea"><tr class="linea"><td class="num"></td>
        <td><select class="in prod" name="items[__][producto_id]" style="width:100%"><?= $opciones ?></select></td>
        <td class="unidad"></td><td class="n libre"></td>
        <td class="n"><input class="in cant" name="items[__][cantidad]" inputmode="decimal" style="width:100px;text-align:right" aria-label="Cantidad solicitada"><div class="hint exceso" style="color:var(--red)" hidden></div></td>
        <td><input class="in" name="items[__][obs]" maxlength="255" style="width:100%" aria-label="Observaciones"></td>
        <td><button type="button" class="btn sm d quitar" aria-label="Quitar línea">✕</button></td></tr></template>
    <?php if (!$lista): ?><div class="alert warn">Este laboratorio no tiene reactivos asignados en la matriz. Solicite a Administración que los asigne.</div><?php endif; ?>
    <p class="hint">El lote se asigna en bodega por FEFO (primero en vencer, primero en salir) al momento del despacho.</p>
  </div>
  <div class="card"><h3>3. Autorizaciones</h3><p class="hint" style="margin-top:-6px">Al enviar, el solicitante firma electrónicamente. Luego el vale pasa al jefe inmediato para su visto bueno.</p>
    <div class="row"><a class="btn" href="<?= e(inv_url(['view' => 'nuevo'])) ?>">Limpiar</a><div class="grow"></div><button class="btn p" type="submit" <?= $lista ? '' : 'disabled' ?>>✍ Firmar y enviar a autorización</button></div></div>
</form>
<?php endif; ?>
