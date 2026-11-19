<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$labSel = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? 0);
$estSel = (string) ($_GET['estado'] ?? '');
$estados = ['ACTIVO', 'PRÓXIMO A VENCER', 'VENCIDO', 'AGOTADO'];
if (!in_array($estSel, $estados, true)) {
    $estSel = '';
}
$lotes = $alcance === -1 ? [] : array_values(array_filter(
    inv_lotes($pdo, $labSel > 0 ? ['laboratorio_id' => $labSel] : []),
    static fn($l) => $estSel === '' || $l['estado'] === $estSel
));
?>
<h1 class="pt">Estado de lotes y disponibilidad (FEFO)</h1>
<p class="sub">Desglose de cada lote por laboratorio propietario: ingresado, consumido por vales despachados y existencia.</p>
<div class="card">
  <form method="get" class="row" style="margin-bottom:12px" data-autosubmit><input type="hidden" name="view" value="lotes">
    <?php if ($alcance === 0): ?>
      <select class="in" name="lab" aria-label="Laboratorio"><option value="">Todos los laboratorios</option>
        <?php foreach (inv_laboratorios($pdo) as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $labSel === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <select class="in" name="estado" aria-label="Estado"><option value="">Todos los estados</option><?php foreach ($estados as $e1): ?><option <?= $estSel === $e1 ? 'selected' : '' ?>><?= e($e1) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn">Filtrar</button></noscript></form>
  <div class="tw"><table><thead><tr><th>ID lote</th><th>Lab. propietario</th><th>Código</th><th>Producto</th><th>Ingreso</th><th>Caducidad</th><th class="n">Ingresado</th><th class="n">Consumido</th><th class="n">Disponible</th><th style="width:130px">Uso</th><th>Estado</th></tr></thead><tbody>
  <?php foreach ($lotes as $l): $pct = $l['ingresado'] > 0 ? max(0, $l['disp']) / $l['ingresado'] * 100 : 0; ?>
    <tr><td class="mono"><?= e($l['lote']) ?></td><td><?= e($l['lab_nombre']) ?></td><td class="mono"><?= e($l['prod_codigo']) ?></td><td><?= e($l['prod_nombre']) ?></td><td><?= e(inv_fecha($l['fecha_ingreso'])) ?></td>
      <td><?= e(inv_fecha($l['caducidad'])) ?><?= $l['estado'] === 'PRÓXIMO A VENCER' ? ' <span class="hint">(' . (int) $l['dias'] . ' d)</span>' : '' ?></td>
      <td class="n"><?= e(inv_fmt($l['ingresado'])) ?></td><td class="n"><?= e(inv_fmt($l['consumido'])) ?></td><td class="n"><b><?= e(inv_fmt($l['disp'])) ?></b> <?= e($l['unidad']) ?></td>
      <td><div class="bar"><i class="<?= $l['estado'] === 'VENCIDO' ? 'b' : ($l['estado'] === 'PRÓXIMO A VENCER' ? 'w' : '') ?>" style="width:<?= round($pct, 1) ?>%"></i></div></td><td><?= inv_pill_lote($l['estado']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$lotes): ?><tr><td colspan="11" class="empty">Sin lotes</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
