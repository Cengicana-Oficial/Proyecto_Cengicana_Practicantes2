<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$labSel = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? 0);
$rutaDisponibilidad = ['view' => 'disponibilidad'];
$laboratorios = $alcance === -1 ? [] : inv_laboratorios($pdo);
$labs = $labSel === 0 ? $laboratorios : array_values(array_filter($laboratorios, static fn(array $l): bool => (int) $l['id'] === $labSel));
$lotes = $alcance === -1 ? [] : inv_lotes($pdo, $labSel > 0 ? ['laboratorio_id' => $labSel] : []);
$balances = [];
foreach ($labs as $l) {
    $balances[(int) $l['id']] = inv_balance($pdo, (int) $l['id'], $lotes);
}
$puedeExportar = inv_puede($user, 'inventario.informes.exportar');
if (in_array($_GET['export'] ?? '', ['excel', 'pdf'], true)) {
    $bloques = [];
    foreach ($labs as $l) {
        $bloques[] = [
            'titulo' => 'Laboratorio: ' . $l['nombre'] . ((int) $l['activo'] === 1 ? '' : ' (inactivo)'),
            'vacio' => 'Sin productos asignados en la matriz',
            'columnas' => [['Código', 't'], ['Producto', 't'], ['Unidad', 't'], ['Total ingresado', 'n'], ['Total consumido', 'n'], ['Stock disponible', 'n'], ['Comprometido', 'n'], ['Libre p/ solicitar', 'n'], ['Mínimo', 'n'], ['Estado', 't']],
            'filas' => array_map(static fn(array $b): array => [$b['codigo'], $b['nombre'], $b['unidad'], (int) $b['ing'], (int) $b['con'], (int) $b['disp'], (int) $b['comp'], max(0, (int) $b['libre']), (int) $b['minimo'], $b['estado'][0]], $balances[(int) $l['id']]),
        ];
    }
    inv_exportar_si_pide([
        'archivo' => 'disponibilidad_' . inv_hoy(),
        'hoja' => 'Disponibilidad',
        'titulo' => 'DISPONIBILIDAD DE REACTIVOS POR LABORATORIO',
        'subtitulo' => 'Balance consolidado: total ingresado, total consumido, comprometido en vales en trámite y existencia libre',
        'emisor' => $user['nombre'],
        'meta' => [['Alcance', $labSel > 0 && $labs ? 'Laboratorio ' . $labs[0]['nombre'] : 'Todos los laboratorios'], ['Fecha de corte', inv_fecha(inv_hoy())]],
        'bloques' => $bloques ?: [['titulo' => null, 'vacio' => 'Su usuario no tiene un laboratorio asignado.', 'columnas' => [['Laboratorio', 't']], 'filas' => []]],
    ], $puedeExportar);
}
?>
<div class="row noprint"><div class="grow"><h1 class="pt">Disponibilidad de reactivos por laboratorio</h1><p class="sub">Balance consolidado: total ingresado, total consumido, comprometido en vales en trámite y existencia libre.</p></div>
  <?= inv_menu_documentos($rutaDisponibilidad + ['lab' => $labSel], $puedeExportar) ?></div>
<?php if ($alcance === 0 && $laboratorios): ?>
<nav class="tabs noprint" aria-label="Disponibilidad por laboratorio">
  <a class="tab<?= $labSel === 0 ? ' on' : '' ?>" href="<?= e(inv_url($rutaDisponibilidad)) ?>"<?= $labSel === 0 ? ' aria-current="page"' : '' ?>>Todos</a>
  <?php foreach ($laboratorios as $laboratorio): ?>
    <?php $id = (int) $laboratorio['id']; $activo = $labSel === $id; ?>
    <a class="tab<?= $activo ? ' on' : '' ?>" href="<?= e(inv_url($rutaDisponibilidad + ['lab' => $id])) ?>"<?= $activo ? ' aria-current="page"' : '' ?>><?= e($laboratorio['nombre']) ?><?= (int) $laboratorio['activo'] === 1 ? '' : ' (inactivo)' ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($alcance === -1): ?><div class="alert warn">Su usuario no tiene un laboratorio asignado.</div><?php endif; ?>
<?php if ($alcance !== -1 && !$labs): ?><div class="card empty">No hay laboratorios para mostrar.</div><?php endif; ?>
<?php foreach ($labs as $l): ?>
<div class="card"><h3>LABORATORIO: <?= e(mb_strtoupper($l['nombre'], 'UTF-8')) ?><?= (int) $l['activo'] === 1 ? '' : ' (inactivo)' ?></h3><div class="tw"><table><thead><tr><th>Código</th><th>Producto</th><th>Unidad</th><th class="n">Total ingresado</th><th class="n">Total consumido</th><th class="n">Stock disponible</th><th class="n">Comprometido</th><th class="n">Libre p/ solicitar</th><th class="n">Mínimo</th><th class="noprint" style="width:120px">Existencia</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($balances[(int) $l['id']] as $b): [$texto, $clase] = $b['estado']; $pct = $b['ing'] > 0 ? max(0, $b['disp']) / $b['ing'] * 100 : 0; ?>
  <tr><td class="mono"><?= e($b['codigo']) ?></td><td><?= e($b['nombre']) ?></td><td><?= e($b['unidad']) ?></td><td class="n"><?= e(inv_fmt($b['ing'])) ?></td><td class="n"><?= e(inv_fmt($b['con'])) ?></td><td class="n"><b><?= e(inv_fmt($b['disp'])) ?></b></td><td class="n"><?= e(inv_fmt($b['comp'])) ?></td><td class="n"><?= e(inv_fmt(max(0, $b['libre']))) ?></td><td class="n"><?= e(inv_fmt($b['minimo'])) ?></td>
    <td class="noprint"><div class="bar"><i class="<?= $clase === 'p-bad' ? 'b' : ($clase === 'p-warn' ? 'w' : '') ?>" style="width:<?= round($pct, 1) ?>%"></i></div></td><td><span class="pill <?= e($clase) ?>"><?= e($texto) ?></span></td></tr>
<?php endforeach; ?>
<?php if (!$balances[(int) $l['id']]): ?><tr><td colspan="11" class="empty">Sin productos asignados en la matriz</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endforeach; ?>
