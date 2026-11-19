<?php
declare(strict_types=1);
$tab = (string) ($_GET['tab'] ?? 'Todas');
$tabs = ['Todas', 'Corregidas', 'Anuladas'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'Todas';
}
$sql = "SELECT en.*, l.nombre AS lab_nombre, p.codigo AS prod_codigo, p.nombre AS prod_nombre, p.unidad,
            COALESCE((SELECT SUM(vil.cantidad) FROM vale_item_lotes vil JOIN vale_items vi ON vi.id = vil.vale_item_id JOIN vales v ON v.id = vi.vale_id
                      WHERE vil.entrada_id = en.id AND v.estado = 'Entregado'), 0) AS consumido,
            (SELECT COUNT(*) FROM entrada_historial h WHERE h.entrada_id = en.id AND h.tipo = 'correccion') AS correcciones,
            (SELECT COUNT(*) FROM entrada_historial h WHERE h.entrada_id = en.id) AS eventos
        FROM entradas en JOIN laboratorios l ON l.id = en.laboratorio_id JOIN productos p ON p.id = en.producto_id";
$todas = $pdo->query($sql . ' ORDER BY en.fecha_ingreso DESC, en.id DESC LIMIT 500')->fetchAll();
$cuentas = ['Todas' => count($todas), 'Corregidas' => 0, 'Anuladas' => 0];
foreach ($todas as $t) {
    $cuentas['Corregidas'] += (int) $t['correcciones'] > 0 ? 1 : 0;
    $cuentas['Anuladas'] += (int) $t['anulada'] === 1 ? 1 : 0;
}
$filas = array_values(array_filter($todas, static function ($t) use ($tab) {
    return $tab === 'Todas' || ($tab === 'Anuladas' ? (int) $t['anulada'] === 1 : (int) $t['correcciones'] > 0);
}));

$laboratorios = inv_laboratorios($pdo, true);
$todosLabs = inv_laboratorios($pdo, false);
$matrizPorLab = [];
foreach ($pdo->query('SELECT pl.laboratorio_id, p.id, p.codigo, p.nombre, p.unidad, p.activo FROM producto_laboratorio pl JOIN productos p ON p.id = pl.producto_id ORDER BY p.codigo')->fetchAll() as $r) {
    $matrizPorLab[(int) $r['laboratorio_id']][] = ['id' => (int) $r['id'], 'texto' => $r['codigo'] . ' · ' . $r['nombre'] . ' (' . $r['unidad'] . ')', 'activo' => (int) $r['activo']];
}
$puedeCorregir = inv_puede($user, 'inventario.entradas.corregir');
$puedeAnular = inv_puede($user, 'inventario.entradas.anular');
$historiales = [];
foreach ($filas as $f) {
    if ((int) $f['eventos'] > 0) {
        $historiales[(int) $f['id']] = inv_entrada_historial($pdo, (int) $f['id']);
    }
}
$campoFecha = static function (string $v): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? inv_fecha($v) : ($v === '' ? '—' : $v);
};
$datosJs = ['matriz' => $matrizPorLab, 'errores' => INV_ERRORES_ENTRADA];
?>
<div class="row"><div class="grow"><h1 class="pt">Registro de entradas / compras</h1><p class="sub">Cada ingreso genera un lote. Los errores se corrigen con justificación y firma; el historial conserva el valor original.</p></div>
  <button class="btn p" type="button" data-abrir="dlgEntrada">＋ Registrar ingreso</button></div>
<div class="card" id="entradasDatos" data-matriz="<?= e(json_encode($matrizPorLab, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="tabs"><?php foreach ($tabs as $t): ?><a class="tab <?= $tab === $t ? 'on' : '' ?>" href="<?= e(inv_url(['view' => 'entradas', 'tab' => $t])) ?>"><?= e($t) ?> (<?= $cuentas[$t] ?>)</a><?php endforeach; ?></div>
  <div class="tw"><table><thead><tr><th>Acciones</th><th>ID / fecha</th><th>Lab. propietario</th><th>ID lote</th><th>Producto</th><th>Proveedor / documentos</th><th>Caducidad</th><th class="n">Cantidad</th><th class="n">Consumido</th><th>Recibido por</th><th>Estado</th></tr></thead><tbody>
  <?php foreach ($filas as $f):
      $anulada = (int) $f['anulada'] === 1;
      $consumido = inv_milli($f['consumido']);
      $datos = [
          'id' => (int) $f['id'], 'codigo' => sprintf('ING-%03d', (int) $f['id']), 'lote' => $f['lote'], 'fecha_ingreso' => $f['fecha_ingreso'],
          'laboratorio_id' => (int) $f['laboratorio_id'], 'producto_id' => (int) $f['producto_id'], 'cantidad' => inv_input_cantidad(inv_milli($f['cantidad'])),
          'caducidad' => $f['caducidad'], 'proveedor' => (string) $f['proveedor'], 'factura' => (string) $f['factura'],
          'lote_proveedor' => (string) $f['lote_proveedor'], 'coa' => (string) $f['coa'], 'consumido' => inv_fmt($consumido),
          'consumido_milli' => $consumido, 'unidad' => $f['unidad'], 'producto' => $f['prod_nombre'], 'bloqueado' => $consumido > 0,
      ]; ?>
    <tr class="<?= $anulada ? 'anul' : '' ?>">
      <td style="white-space:nowrap">
        <?php if (!$anulada && $puedeCorregir): ?><button type="button" class="btn sm" data-corregir="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>" title="Corregir error">✎ Corregir</button> <?php endif; ?>
        <?php if (!$anulada && $puedeAnular): ?><button type="button" class="btn sm d" data-anular="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>" <?= $consumido > 0 ? 'disabled title="El lote ya tiene consumo; no puede anularse"' : 'title="Anular ingreso"' ?>>⊘</button> <?php endif; ?>
        <?php if ((int) $f['eventos'] > 0): ?><button type="button" class="btn sm" data-abrir="dlgHist<?= (int) $f['id'] ?>" title="Historial">🕘</button><?php endif; ?>
      </td>
      <td class="mono"><?= e($datos['codigo']) ?><div class="hint"><?= e(inv_fecha($f['fecha_ingreso'])) ?></div></td>
      <td><?= e($f['lab_nombre']) ?></td><td class="mono"><?= e($f['lote']) ?></td>
      <td><span class="mono"><?= e($f['prod_codigo']) ?></span> <?= e($f['prod_nombre']) ?></td>
      <td><?= e($f['proveedor']) ?><div class="hint"><?= e(trim($f['factura'] . ' ' . $f['lote_proveedor'] . ' ' . $f['coa'])) ?></div></td>
      <td><?= e(inv_fecha($f['caducidad'])) ?></td>
      <td class="n"><?= e(inv_fmt(inv_milli($f['cantidad']))) ?> <?= e($f['unidad']) ?></td><td class="n"><?= e(inv_fmt($consumido)) ?></td>
      <td><?= e($f['recibido_por']) ?></td>
      <td><?= $anulada ? '<span class="pill p-bad">Anulada</span>' : ((int) $f['correcciones'] > 0 ? '<span class="pill p-warn">Corregida (' . (int) $f['correcciones'] . ')</span>' : '<span class="pill p-ok">Vigente</span>') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$filas): ?><tr><td colspan="11" class="empty">No hay entradas</td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="hint">✎ Corregir requiere el permiso «Corregir entradas»; ⊘ Anular solo es posible si el lote no tiene consumo. 🕘 muestra el historial.<?= count($todas) === 500 ? ' Se muestran las 500 más recientes.' : '' ?></p>
</div>

<dialog id="dlgEntrada" class="md"><form method="post" action="<?= e(inv_url(['view' => 'entradas'])) ?>">
  <?= inv_form_campos('entrada_registrar') ?>
  <header><h3>Registrar ingreso a Bodega Central</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><div class="fg">
    <label class="f">Fecha de ingreso<input type="date" name="fecha_ingreso" value="<?= e(inv_hoy()) ?>" max="<?= e(inv_hoy()) ?>" required></label>
    <label class="f">Laboratorio comprador / propietario<select name="laboratorio_id" data-matriz-lab="#entProducto" required>
      <?php foreach ($laboratorios as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['nombre']) ?></option><?php endforeach; ?></select></label>
    <label class="f" style="grid-column:span 2">Producto (según matriz del laboratorio)<select name="producto_id" id="entProducto" required></select></label>
    <label class="f">Cantidad ingresada<input name="cantidad" inputmode="decimal" required></label>
    <label class="f">Fecha de caducidad<input type="date" name="caducidad" required></label>
    <label class="f">Proveedor<input name="proveedor" maxlength="160"></label><label class="f">No. factura / remisión<input name="factura" maxlength="80"></label>
    <label class="f">No. lote proveedor<input name="lote_proveedor" maxlength="80"></label><label class="f">Certificado (CoA)<input name="coa" maxlength="80"></label>
  </div><p class="hint">El ID de lote interno se genera automáticamente (LOT-<i>LAB</i>-###).</p></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn p" type="submit">Guardar ingreso</button></footer></form></dialog>

<?php if ($puedeCorregir): ?>
<dialog id="dlgCorregir" class="md lg"><form method="post" action="<?= e(inv_url(['view' => 'entradas'])) ?>" id="formCorregir" <?= inv_firma_attr([['p' => 'cor', 'titulo' => 'Corrección de entrada', 'nombre' => $user['nombre'], 'fijo' => true]]) ?>>
  <?= inv_form_campos('entrada_corregir') ?><input type="hidden" name="id">
  <header><h3 id="corTitulo">✎ Corregir entrada</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd">
    <div class="alert warn" id="corBloqueo" hidden style="margin-top:0"></div>
    <div class="sh">1. MOTIVO DE LA CORRECCIÓN</div>
    <div class="fg"><label class="f">Tipo de error<select name="error_tipo" required><option value="">— Seleccione —</option><?php foreach (INV_ERRORES_ENTRADA as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select></label>
      <label class="f" style="grid-column:span 2">Descripción del error y justificación<textarea name="motivo" rows="2" required minlength="10" maxlength="1000" placeholder="Ej. Se digitó 0 L en lugar de 20 L según factura FAC-10029"></textarea></label>
      <label class="f">Documento de soporte (opcional)<input name="documento" maxlength="160"></label></div>
    <div class="sh" style="margin-top:14px">2. DATOS CORREGIDOS</div>
    <div class="fg">
      <label class="f">Fecha de ingreso<input type="date" name="fecha_ingreso" max="<?= e(inv_hoy()) ?>" required></label>
      <label class="f">Laboratorio propietario<select name="laboratorio_id" data-matriz-lab="#corProducto" data-incluir-inactivos="1" required><?php foreach ($todosLabs as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['nombre']) ?></option><?php endforeach; ?></select></label>
      <label class="f" style="grid-column:span 2">Producto<select name="producto_id" id="corProducto" required></select></label>
      <label class="f">Cantidad ingresada<input name="cantidad" inputmode="decimal" required></label>
      <label class="f">Fecha de caducidad<input type="date" name="caducidad" required></label>
      <label class="f">Proveedor<input name="proveedor" maxlength="160"></label><label class="f">Factura / remisión<input name="factura" maxlength="80"></label>
      <label class="f">Lote proveedor<input name="lote_proveedor" maxlength="80"></label><label class="f">Certificado (CoA)<input name="coa" maxlength="80"></label></div>
  </div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn p" type="submit">✍ Firmar y guardar corrección</button></footer></form></dialog>
<?php endif; ?>

<?php if ($puedeAnular): ?>
<dialog id="dlgAnularEntrada" class="md"><form method="post" action="<?= e(inv_url(['view' => 'entradas'])) ?>" id="formAnularEntrada" <?= inv_firma_attr([['p' => 'anu', 'titulo' => 'Anulación de entrada', 'nombre' => $user['nombre'], 'fijo' => true]]) ?>>
  <?= inv_form_campos('entrada_anular') ?><input type="hidden" name="id">
  <header><h3 id="anuTitulo">⊘ Anular entrada</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><div class="alert bad" id="anuResumen" style="margin-top:0"></div>
    <div class="fg"><label class="f">Tipo de error<select name="error_tipo" required><?php foreach (INV_ERRORES_ANULACION as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select></label>
      <label class="f" style="grid-column:span 2">Justificación<textarea name="motivo" rows="2" required minlength="10" maxlength="1000"></textarea></label></div></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn d" type="submit">✍ Firmar y anular</button></footer></form></dialog>
<?php endif; ?>

<?php foreach ($historiales as $entradaId => $hist): ?>
<dialog id="dlgHist<?= $entradaId ?>" class="md lg"><div>
  <header><h3>🕘 Historial de <?= e(sprintf('ING-%03d', $entradaId)) ?></h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd">
  <?php foreach ($hist as $h): ?>
    <div class="card" style="margin-bottom:10px"><div class="row"><b class="grow"><?= $h['tipo'] === 'anulacion' ? '⊘ Anulación' : '✎ Corrección' ?>: <?= e($h['error_tipo']) ?></b><span class="hint"><?= e(inv_fecha($h['creado_en'])) ?> · <?= e($h['usuario_nombre']) ?></span></div>
      <p style="margin:6px 0"><?= e($h['motivo']) ?><?= $h['documento'] ? ' <span class="hint">· Soporte: ' . e($h['documento']) . '</span>' : '' ?></p>
      <?php if ($h['cambios']): ?><table class="diff"><thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead><tbody>
        <?php foreach ($h['cambios'] as $c): ?><tr><td><?= e($c['campo']) ?></td><td class="old"><?= e($campoFecha((string) $c['antes'])) ?></td><td class="new"><?= e($campoFecha((string) $c['despues'])) ?></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
      <div class="row" style="margin-top:8px"><span class="hint">Firma:</span><?php if ($h['firma_imagen']): ?><img src="<?= e($h['firma_imagen']) ?>" alt="Firma" style="height:34px"><?php endif; ?><b style="font-size:12px"><?= e($h['usuario_nombre']) ?></b></div></div>
  <?php endforeach; ?></div>
  <footer><button type="button" class="btn" data-cerrar>Cerrar</button></footer></div></dialog>
<?php endforeach; ?>
