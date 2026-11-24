<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$labSel = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? 0);
$prodSel = (int) ($_GET['producto'] ?? 0);
$desde = inv_fecha_valida($_GET['desde'] ?? '') ?? date('Y') . '-01-01';
$hasta = inv_fecha_valida($_GET['hasta'] ?? '') ?? inv_hoy();
$filas = $alcance === -1 ? [] : inv_salidas($pdo, ['laboratorio_id' => $labSel, 'producto_id' => $prodSel, 'desde' => $desde, 'hasta' => $hasta]);
$puedeExportar = inv_puede($user, 'inventario.informes.exportar');

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

if (in_array($_GET['export'] ?? '', ['excel', 'pdf'], true)) {
    $nombreLab = 'Todos';
    foreach ($labs as $l) {
        $nombreLab = (int) $l['id'] === $labSel ? $l['nombre'] : $nombreLab;
    }
    $nombreProd = 'Todos';
    foreach ($productos as $p) {
        $nombreProd = (int) $p['id'] === $prodSel ? $p['codigo'] . ' · ' . $p['nombre'] : $nombreProd;
    }
    inv_exportar_si_pide([
        'archivo' => 'salidas_' . $desde . '_' . $hasta,
        'hoja' => 'Salidas',
        'titulo' => 'BODEGA CENTRAL - REGISTRO DE DESCARGAS / SALIDAS',
        'subtitulo' => 'Periodo ' . inv_fecha($desde) . ' al ' . inv_fecha($hasta),
        'emisor' => $user['nombre'],
        'meta' => [['Laboratorio', $nombreLab], ['Producto', $nombreProd], ['Periodo', inv_fecha($desde) . ' al ' . inv_fecha($hasta)],
            ['Vales despachados', (string) count($vales)], ['Líneas de descarga', (string) count($filas)], ['Productos distintos', (string) count($totales)]],
        'bloques' => [
            ['titulo' => 'Detalle de descargas', 'vacio' => 'Sin salidas en el periodo',
             // En Excel cantidad y unidad van separadas (para sumar); en el PDF van juntas ("0.75 L").
             'columnas' => [['No. vale', 't'], ['Fecha despacho', 'f'], ['Laboratorio', 't'], ['ID lote', 't'], ['Código', 't'], ['Producto', 't'], ['Cantidad', 'n', true], ['Unidad', 't', true], ['Cantidad', 'nu', 'pdf'], ['Analista', 't'], ['Proyecto / ensayo', 't'], ['Despachado por', 't'], ['Recibido por', 't', true]],
             'anchos_pdf' => [9.5, 7.5, 9.5, 9, 6.5, 15, 7, 12, 14, 10],
             'filas' => array_map(static fn(array $s): array => [$s['numero'], $s['fecha_entrega'], $s['lab_nombre'], $s['lote'], $s['prod_codigo'], $s['prod_nombre'], (int) $s['cantidad'], $s['unidad'], [(int) $s['cantidad'], $s['unidad']], $s['solicitante'], $s['proyecto'], $s['despacho'], $s['recibe']], $filas)],
            ['titulo' => 'Totales descargados por producto', 'vacio' => 'Sin salidas en el periodo',
             'ancho_tabla_pdf' => 60, 'anchos_pdf' => [18, 52, 18, 12],
             'columnas' => [['Código', 't'], ['Producto', 't'], ['Total', 'n'], ['Unidad', 't']],
             'filas' => array_map(static fn($codigo, array $t): array => [(string) $codigo, $t['nombre'], (int) $t['total'], $t['unidad']], array_keys($totales), $totales)],
        ],
        'compacto' => true,
    ], $puedeExportar);
}
?>
<div class="row noprint"><div class="grow"><h1 class="pt">Informe de control de salidas</h1><p class="sub">Registro de descargas por vale, laboratorio y lote.</p></div>
  <?= inv_menu_documentos($qs, $puedeExportar) ?></div>
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
