<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$labFiltro = $alcance > 0 ? ['laboratorio_id' => $alcance] : [];
$lotes = $alcance === -1 ? [] : inv_lotes($pdo, $labFiltro);
$activos = array_filter($lotes, static fn($l) => $l['disp'] > 0 && $l['estado'] !== 'VENCIDO');
$proximos = array_filter($lotes, static fn($l) => $l['estado'] === 'PRÓXIMO A VENCER');
$vencidos = array_filter($lotes, static fn($l) => $l['estado'] === 'VENCIDO');

$bajos = [];
if ($alcance !== -1) {
    foreach (inv_laboratorios($pdo, true) as $lab) {
        if ($alcance > 0 && (int) $lab['id'] !== $alcance) {
            continue;
        }
        foreach (inv_balance($pdo, (int) $lab['id'], $lotes) as $b) {
            if ($b['disp'] < $b['minimo']) {
                $bajos[] = $b + ['lab' => $lab['nombre']];
            }
        }
    }
}
$cond = $alcance > 0 ? ' AND laboratorio_id = ' . (int) $alcance : '';
$sinAcceso = $alcance === -1;
$enTramite = $sinAcceso ? 0 : (int) $pdo->query("SELECT COUNT(*) FROM vales WHERE estado IN ('Pendiente','Autorizado')" . $cond)->fetchColumn();
$despachadosMes = $sinAcceso ? 0 : (int) $pdo->query("SELECT COUNT(*) FROM vales WHERE estado = 'Entregado' AND DATE_FORMAT(fecha_entrega, '%Y-%m') = '" . date('Y-m') . "'" . $cond)->fetchColumn();
$productos = $sinAcceso ? 0 : (int) $pdo->query(
    $alcance > 0
        ? 'SELECT COUNT(*) FROM productos p JOIN producto_laboratorio pl ON pl.producto_id = p.id AND pl.laboratorio_id = ' . (int) $alcance . ' WHERE p.activo = 1'
        : 'SELECT COUNT(*) FROM productos WHERE activo = 1'
)->fetchColumn();
$tareas = inv_tareas($pdo, $user);
$porLab = [];
if (!$sinAcceso && $alcance === 0) {
    $stmt = $pdo->prepare(
        "SELECT l.nombre, COUNT(vil.id) AS lineas FROM laboratorios l
         LEFT JOIN vales v ON v.laboratorio_id = l.id AND v.estado = 'Entregado' AND YEAR(v.fecha_entrega) = ?
         LEFT JOIN vale_items vi ON vi.vale_id = v.id
         LEFT JOIN vale_item_lotes vil ON vil.vale_item_id = vi.id
         WHERE l.activo = 1 GROUP BY l.id, l.nombre ORDER BY l.nombre"
    );
    $stmt->execute([(int) date('Y')]);
    $porLab = $stmt->fetchAll();
}
$max = max(1, ...array_map(static fn($r) => (int) $r['lineas'], $porLab ?: [['lineas' => 1]]));
?>
<h1 class="pt">Tablero de control<?= $alcance > 0 ? ' · Lab. ' . e($user['lab_nombre']) : ' · Bodega Central' ?></h1>
<p class="sub">Resumen al <?= e(inv_fecha(inv_hoy())) ?>. <?= $alcance > 0 ? 'Vista limitada a su laboratorio.' : 'Vista consolidada de todos los laboratorios.' ?></p>
<?php if ($sinAcceso): ?>
  <div class="alert warn">Su usuario no tiene un laboratorio asignado, por lo que no se muestran existencias. Solicite la asignación al administrador del módulo.</div>
<?php endif; ?>
<div class="kpis">
  <div class="kpi"><div class="v"><?= $productos ?></div><div class="l">Productos en catálogo<?= $alcance > 0 ? ' asignados' : '' ?></div></div>
  <div class="kpi"><div class="v"><?= count($activos) ?></div><div class="l">Lotes con existencia</div></div>
  <div class="kpi warn"><div class="v"><?= count($proximos) ?></div><div class="l">Lotes próximos a vencer (≤ <?= INV_DIAS_POR_VENCER ?> días)</div></div>
  <div class="kpi bad"><div class="v"><?= count($bajos) ?></div><div class="l">Productos bajo stock mínimo</div></div>
  <div class="kpi info"><div class="v"><?= $enTramite ?></div><div class="l">Vales en trámite</div></div>
  <div class="kpi"><div class="v"><?= $despachadosMes ?></div><div class="l">Vales despachados este mes</div></div>
</div>
<div class="row" style="align-items:stretch">
  <div class="card grow" style="min-width:340px"><h3>Mis tareas pendientes</h3>
    <?php foreach ($tareas as [$v, $texto]): ?>
      <div class="alert info"><div class="grow"><a class="linkish" href="<?= e(inv_url(['view' => 'vale', 'id' => (int) $v['id']])) ?>"><?= e($v['numero']) ?></a> · <?= e($v['lab_nombre']) ?> · <?= e($v['solicitante']) ?><div class="hint"><?= e($texto) ?></div></div><?= inv_pill_vale($v['estado']) ?></div>
    <?php endforeach; ?>
    <?php if (!$tareas): ?><div class="empty">Sin tareas pendientes ✔</div><?php endif; ?>
  </div>
  <div class="card grow" style="min-width:340px"><h3>Alertas de inventario</h3>
    <?php foreach ($vencidos as $l): ?>
      <div class="alert bad">⛔ <div><b><?= e($l['lote']) ?></b> · <?= e($l['prod_nombre']) ?> (<?= e($l['lab_nombre']) ?>) vencido el <?= e(inv_fecha($l['caducidad'])) ?> con <?= e(inv_fmt($l['disp'])) ?> <?= e($l['unidad']) ?></div></div>
    <?php endforeach; foreach ($proximos as $l): ?>
      <div class="alert warn">⚠ <div><b><?= e($l['lote']) ?></b> · <?= e($l['prod_nombre']) ?> (<?= e($l['lab_nombre']) ?>) vence en <?= (int) $l['dias'] ?> días (<?= e(inv_fecha($l['caducidad'])) ?>) · <?= e(inv_fmt($l['disp'])) ?> <?= e($l['unidad']) ?></div></div>
    <?php endforeach; foreach ($bajos as $b): ?>
      <div class="alert <?= $b['disp'] <= 0 ? 'bad' : 'warn' ?>">▼ <div><b><?= e($b['nombre']) ?></b> · <?= e($b['lab']) ?>: <?= e(inv_fmt($b['disp'])) ?> <?= e($b['unidad']) ?> (mínimo <?= e(inv_fmt($b['minimo'])) ?>)</div></div>
    <?php endforeach; ?>
    <?php if (!$vencidos && !$proximos && !$bajos): ?><div class="empty">Sin alertas</div><?php endif; ?>
  </div>
</div>
<?php if ($porLab): ?>
<div class="card"><h3>Líneas despachadas por laboratorio · <?= e(date('Y')) ?></h3>
  <?php foreach ($porLab as $r): ?>
    <div class="row" style="margin-bottom:8px"><div style="width:150px"><?= e($r['nombre']) ?></div><div class="bar grow"><i style="width:<?= round((int) $r['lineas'] / $max * 100, 1) ?>%"></i></div><div style="width:40px" class="n"><?= (int) $r['lineas'] ?></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
