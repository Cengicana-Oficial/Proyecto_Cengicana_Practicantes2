<?php
declare(strict_types=1);
$q = trim((string) ($_GET['q'] ?? ''));
$productos = array_values(array_filter(inv_productos($pdo), static function ($p) use ($q) {
    return $q === '' || stripos($p['codigo'] . ' ' . $p['nombre'] . ' ' . $p['tipo'], $q) !== false;
}));
$labs = inv_laboratorios($pdo, true);
$conSaldo = [];
foreach (inv_lotes($pdo) as $l) {
    if ($l['disp'] > 0) {
        $conSaldo[$l['producto_id'] . '-' . $l['laboratorio_id']] = true;
    }
}
$proximos = [];
foreach (INV_PREFIJO_TIPO as $tipo => $pref) {
    $max = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 5) AS UNSIGNED)), 0) FROM productos WHERE codigo LIKE '" . $pref . "-%'")->fetchColumn();
    $proximos[$tipo] = sprintf('%s-%03d', $pref, $max + 1);
}
$clases = ['Precursor fiscalizado' => 'p-bad', 'Reactivo' => 'p-info', 'Material de laboratorio' => 'p-pur', 'Consumible' => 'p-pur'];
?>
<div class="row"><div class="grow"><h1 class="pt">Catálogo y matriz de asignación</h1><p class="sub">Agregue precursores, reactivos o materiales y marque qué laboratorios están autorizados a solicitarlos.</p></div><button class="btn p" type="button" data-nuevo-producto>＋ Nuevo producto</button></div>
<div class="card" id="catalogoDatos" data-proximos="<?= e(json_encode($proximos)) ?>">
  <form method="get" class="row" style="margin-bottom:10px"><input type="hidden" name="view" value="catalogo"><input class="in" style="width:300px" name="q" value="<?= e($q) ?>" placeholder="Buscar código, nombre o tipo…" aria-label="Buscar"><button class="btn">Buscar</button><span class="hint">Use el botón de cada laboratorio para asignar o quitar el producto.</span></form>
  <div class="tw"><table><thead><tr><th>Código</th><th>Producto</th><th>Tipo</th><th>No. CAS</th><th>Unidad</th><th class="n">Mínimo</th><?php foreach ($labs as $l): ?><th style="text-align:center;font-size:11.5px">Lab<br><?= e($l['nombre']) ?></th><?php endforeach; ?><th>Estado</th><th></th></tr></thead><tbody>
  <?php foreach ($productos as $p): ?>
    <tr style="<?= (int) $p['activo'] ? '' : 'opacity:.55' ?>"><td class="mono"><?= e($p['codigo']) ?></td><td><b><?= e($p['nombre']) ?></b><?= $p['concentracion'] ? '<div class="hint">' . e($p['concentracion']) . '</div>' : '' ?></td>
      <td><span class="pill <?= e($clases[$p['tipo']] ?? 'p-mut') ?>"><?= e($p['tipo']) ?></span></td><td><?= e($p['cas']) ?></td><td><?= e($p['unidad']) ?></td><td class="n"><?= e(inv_fmt($p['minimo'])) ?></td>
      <?php foreach ($labs as $l): $asignado = in_array((int) $l['id'], $p['labs'], true); $saldo = isset($conSaldo[$p['id'] . '-' . $l['id']]); ?>
        <td style="text-align:center"><form method="post" action="<?= e(inv_url(['view' => 'catalogo'])) ?>" <?= $asignado && $saldo ? 'data-confirmar="El laboratorio tiene existencia de este producto. ¿Quitar la asignación de todas formas?" data-marcar-confirmado' : '' ?>>
          <?= inv_form_campos('matriz_alternar') ?><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="laboratorio_id" value="<?= (int) $l['id'] ?>">
          <button type="submit" class="chk <?= $asignado ? 'on' : '' ?>" aria-pressed="<?= $asignado ? 'true' : 'false' ?>" aria-label="<?= e(($asignado ? 'Quitar ' : 'Asignar ') . $p['nombre'] . ' a ' . $l['nombre']) ?>"><?= $asignado ? 'X' : '' ?></button></form></td>
      <?php endforeach; ?>
      <td><?= (int) $p['activo'] ? '<span class="pill p-ok">Activo</span>' : '<span class="pill p-mut">Inactivo</span>' ?></td>
      <td><button type="button" class="btn sm" data-editar-producto="<?= e(json_encode(['id' => $p['id'], 'codigo' => $p['codigo'], 'nombre' => $p['nombre'], 'tipo' => $p['tipo'], 'concentracion' => (string) $p['concentracion'], 'cas' => (string) $p['cas'], 'unidad' => $p['unidad'], 'minimo' => inv_input_cantidad($p['minimo']), 'activo' => (int) $p['activo'], 'labs' => $p['labs']], JSON_UNESCAPED_UNICODE)) ?>">Editar</button></td></tr>
  <?php endforeach; ?>
  <?php if (!$productos): ?><tr><td colspan="<?= 8 + count($labs) ?>" class="empty">Sin productos</td></tr><?php endif; ?>
  </tbody></table></div></div>

<dialog id="dlgProducto" class="md"><form method="post" action="<?= e(inv_url(['view' => 'catalogo'])) ?>">
  <?= inv_form_campos('producto_guardar') ?><input type="hidden" name="id" value="0">
  <header><h3 id="prodTitulo">Nuevo producto</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><div class="fg">
    <label class="f">Tipo<select name="tipo"><?php foreach (INV_TIPOS_PRODUCTO as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="f">Código<input name="codigo" maxlength="20" required pattern="[A-Za-z]{3}-[0-9]{3,4}"></label>
    <label class="f" style="grid-column:span 2">Nombre<input name="nombre" maxlength="160" required></label>
    <label class="f">Concentración / presentación<input name="concentracion" maxlength="80" placeholder="Ej. 99.8 %, PA"></label>
    <label class="f">No. CAS<input name="cas" maxlength="40"></label>
    <label class="f">Unidad de medida<select name="unidad"><?php foreach (INV_UNIDADES as $u1): ?><option><?= e($u1) ?></option><?php endforeach; ?></select></label>
    <label class="f">Stock mínimo (alerta)<input name="minimo" inputmode="decimal" value="0"></label>
    <label class="f">Estado<select name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></label></div>
    <div style="margin-top:14px"><div class="hint" style="font-weight:600;margin-bottom:6px">Laboratorios autorizados</div><div class="row"><?php foreach ($labs as $l): ?><label class="row" style="gap:6px"><input type="checkbox" name="labs[]" value="<?= (int) $l['id'] ?>"> <?= e($l['nombre']) ?></label><?php endforeach; ?></div></div></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn p" type="submit">Guardar</button></footer></form></dialog>
