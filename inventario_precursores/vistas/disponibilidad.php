<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$labSel = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? 0);
$labs = $alcance === -1 ? [] : array_values(array_filter(inv_laboratorios($pdo), static fn($l) => $labSel === 0 || (int) $l['id'] === $labSel));
$lotes = $alcance === -1 ? [] : inv_lotes($pdo, $labSel > 0 ? ['laboratorio_id' => $labSel] : []);
$balances = [];
foreach ($labs as $l) {
    $balances[(int) $l['id']] = inv_balance($pdo, (int) $l['id'], $lotes);
}
$puedeExportar = inv_puede($user, 'inventario.informes.exportar');
if (($_GET['export'] ?? '') === 'csv' && $puedeExportar) {
    $csv = [['Laboratorio', 'Código', 'Producto', 'Unidad', 'Ingresado', 'Consumido', 'Disponible', 'Comprometido', 'Libre', 'Mínimo', 'Estado']];
    foreach ($labs as $l) {
        foreach ($balances[(int) $l['id']] as $b) {
            $csv[] = [$l['nombre'], $b['codigo'], $b['nombre'], $b['unidad'], inv_dec($b['ing']), inv_dec($b['con']), inv_dec($b['disp']), inv_dec($b['comp']), inv_dec(max(0, $b['libre'])), inv_dec($b['minimo']), $b['estado'][0]];
        }
    }
    inv_enviar_csv('disponibilidad_' . inv_hoy(), $csv);
}
?>
<div class="row noprint"><div class="grow"><h1 class="pt">Disponibilidad de reactivos por laboratorio</h1><p class="sub">Balance consolidado: total ingresado, total consumido, comprometido en vales en trámite y existencia libre.</p></div>
  <?php if ($alcance === 0): ?><form method="get" data-autosubmit><input type="hidden" name="view" value="disponibilidad"><select class="in" name="lab" aria-label="Laboratorio"><option value="">Todos los laboratorios</option>
    <?php foreach (inv_laboratorios($pdo) as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $labSel === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option><?php endforeach; ?></select></form><?php endif; ?>
  <?php if ($puedeExportar): ?><a class="btn" href="<?= e(inv_url(['view' => 'disponibilidad', 'lab' => $labSel, 'export' => 'csv'])) ?>">⤓ CSV</a><?php endif; ?><button class="btn" type="button" data-imprimir>🖨 Imprimir</button></div>
<?php if ($alcance === -1): ?><div class="alert warn">Su usuario no tiene un laboratorio asignado.</div><?php endif; ?>
<?php foreach ($labs as $l): ?>
<div class="card"><h3>LABORATORIO: <?= e(mb_strtoupper($l['nombre'], 'UTF-8')) ?><?= (int) $l['activo'] === 1 ? '' : ' (inactivo)' ?></h3><div class="tw"><table><thead><tr><th>Código</th><th>Producto</th><th>Unidad</th><th class="n">Total ingresado</th><th class="n">Total consumido</th><th class="n">Stock disponible</th><th class="n">Comprometido</th><th class="n">Libre p/ solicitar</th><th class="n">Mínimo</th><th class="noprint" style="width:120px">Existencia</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($balances[(int) $l['id']] as $b): [$texto, $clase] = $b['estado']; $pct = $b['ing'] > 0 ? max(0, $b['disp']) / $b['ing'] * 100 : 0; ?>
  <tr><td class="mono"><?= e($b['codigo']) ?></td><td><?= e($b['nombre']) ?></td><td><?= e($b['unidad']) ?></td><td class="n"><?= e(inv_fmt($b['ing'])) ?></td><td class="n"><?= e(inv_fmt($b['con'])) ?></td><td class="n"><b><?= e(inv_fmt($b['disp'])) ?></b></td><td class="n"><?= e(inv_fmt($b['comp'])) ?></td><td class="n"><?= e(inv_fmt(max(0, $b['libre']))) ?></td><td class="n"><?= e(inv_fmt($b['minimo'])) ?></td>
    <td class="noprint"><div class="bar"><i class="<?= $clase === 'p-bad' ? 'b' : ($clase === 'p-warn' ? 'w' : '') ?>" style="width:<?= round($pct, 1) ?>%"></i></div></td><td><span class="pill <?= e($clase) ?>"><?= e($texto) ?></span></td></tr>
<?php endforeach; ?>
<?php if (!$balances[(int) $l['id']]): ?><tr><td colspan="11" class="empty">Sin productos asignados en la matriz</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endforeach; ?>
