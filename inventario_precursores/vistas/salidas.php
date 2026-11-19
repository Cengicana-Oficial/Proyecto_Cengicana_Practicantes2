<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$labSel = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? 0);
$prodSel = (int) ($_GET['producto'] ?? 0);
$desde = inv_fecha_valida($_GET['desde'] ?? '') ?? date('Y') . '-01-01';
$hasta = inv_fecha_valida($_GET['hasta'] ?? '') ?? inv_hoy();
$filas = $alcance === -1 ? [] : inv_salidas($pdo, ['laboratorio_id' => $labSel, 'producto_id' => $prodSel, 'desde' => $desde, 'hasta' => $hasta]);
$puedeExportar = inv_puede($user, 'inventario.informes.exportar');

if (($_GET['export'] ?? '') === 'csv' && $puedeExportar) {
    $csv = [['No. vale', 'Fecha', 'Laboratorio', 'ID lote', 'Código', 'Producto', 'Cantidad', 'Unidad', 'Analista', 'Proyecto', 'Despachado por', 'Recibido por']];
    foreach ($filas as $s) {
        $csv[] = [$s['numero'], $s['fecha_entrega'], $s['lab_nombre'], $s['lote'], $s['prod_codigo'], $s['prod_nombre'], inv_dec($s['cantidad']), $s['unidad'], $s['solicitante'], $s['proyecto'], $s['despacho'], $s['recibe']];
    }
    inv_enviar_csv('salidas_' . $desde . '_' . $hasta, $csv);
}
$totales = [];
$vales = [];
foreach ($filas as $s) {
    $totales[$s['prod_codigo']] = ($totales[$s['prod_codigo']] ?? ['nombre' => $s['prod_nombre'], 'unidad' => $s['unidad'], 'total' => 0]);
    $totales[$s['prod_codigo']]['total'] += $s['cantidad'];
    $vales[$s['vale_id']] = true;
}
$labs = inv_laboratorios($pdo);
$productos = inv_productos($pdo);
$qs = ['view' => 'salidas', 'lab' => $labSel, 'producto' => $prodSel, 'desde' => $desde, 'hasta' => $hasta];
?>
<div class="row noprint"><div class="grow"><h1 class="pt">Informe de control de salidas</h1><p class="sub">Registro de descargas por vale, laboratorio y lote.</p></div>
  <?php if ($puedeExportar): ?><a class="btn" href="<?= e(inv_url($qs + ['export' => 'csv'])) ?>">⤓ Exportar CSV</a><?php endif; ?><button class="btn" type="button" data-imprimir>🖨 Imprimir</button></div>
<div class="card noprint"><form method="get" class="row" data-autosubmit><input type="hidden" name="view" value="salidas">
  <label class="f">Laboratorio<select name="lab" <?= $alcance !== 0 ? 'disabled' : '' ?>><option value="">Todos</option><?php foreach ($labs as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $labSel === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option><?php endforeach; ?></select></label>
  <label class="f">Producto<select name="producto"><option value="">Todos</option><?php foreach ($productos as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $prodSel === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['codigo'] . ' · ' . $p['nombre']) ?></option><?php endforeach; ?></select></label>
  <label class="f">Desde<input type="date" name="desde" value="<?= e($desde) ?>"></label>
  <label class="f">Hasta<input type="date" name="hasta" value="<?= e($hasta) ?>"></label><noscript><button class="btn">Filtrar</button></noscript></form></div>
<div class="kpis noprint"><div class="kpi"><div class="v"><?= count($vales) ?></div><div class="l">Vales despachados</div></div><div class="kpi"><div class="v"><?= count($filas) ?></div><div class="l">Líneas de descarga</div></div><div class="kpi"><div class="v"><?= count($totales) ?></div><div class="l">Productos distintos</div></div></div>
<div class="card doc"><div class="dh"><img src="../login/assets/img/logo-cengicana.png" alt="CENGICAÑA"><div class="t"><b>BODEGA CENTRAL - REGISTRO DE DESCARGAS / SALIDAS</b><span>Periodo <?= e(inv_fecha($desde)) ?> al <?= e(inv_fecha($hasta)) ?></span></div><div class="no">Emitido<b style="color:var(--gd)"><?= e(inv_fecha(inv_hoy())) ?></b><?= e($user['nombre']) ?></div></div>
<div class="tw"><table><thead><tr><th>No. vale</th><th>Fecha despacho</th><th>Laboratorio</th><th>ID lote</th><th>Código</th><th>Producto</th><th class="n">Cantidad</th><th>Analista</th><th>Proyecto / ensayo</th><th>Despachado por</th></tr></thead><tbody>
<?php foreach ($filas as $s): ?>
  <tr><td class="mono"><a class="linkish" href="<?= e(inv_url(['view' => 'vale', 'id' => (int) $s['vale_id']])) ?>"><?= e($s['numero']) ?></a></td><td><?= e(inv_fecha($s['fecha_entrega'])) ?></td><td><?= e($s['lab_nombre']) ?></td><td class="mono"><?= e($s['lote']) ?></td><td class="mono"><?= e($s['prod_codigo']) ?></td><td><?= e($s['prod_nombre']) ?></td><td class="n"><?= e(inv_fmt($s['cantidad'])) ?> <?= e($s['unidad']) ?></td><td><?= e($s['solicitante']) ?></td><td><?= e($s['proyecto']) ?></td><td><?= e($s['despacho']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$filas): ?><tr><td colspan="10" class="empty">Sin salidas en el periodo</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card"><h3>Totales descargados por producto</h3><table><thead><tr><th>Código</th><th>Producto</th><th class="n">Total</th><th>Unidad</th></tr></thead><tbody>
<?php foreach ($totales as $codigo => $t): ?><tr><td class="mono"><?= e($codigo) ?></td><td><?= e($t['nombre']) ?></td><td class="n"><?= e(inv_fmt($t['total'])) ?></td><td><?= e($t['unidad']) ?></td></tr><?php endforeach; ?>
<?php if (!$totales): ?><tr><td colspan="4" class="empty">—</td></tr><?php endif; ?></tbody></table></div>
