<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$estados = ['Todos', 'Pendiente', 'Autorizado', 'Entregado', 'Rechazado', 'Anulado'];
$tab = (string) ($_GET['estado'] ?? 'Todos');
if (!in_array($tab, $estados, true)) {
    $tab = 'Todos';
}
$cuentas = array_fill_keys($estados, 0);
$vales = [];
if ($alcance !== -1) {
    $sql = 'SELECT estado, COUNT(*) AS n FROM vales' . ($alcance > 0 ? ' WHERE laboratorio_id = ' . (int) $alcance : '') . ' GROUP BY estado';
    foreach ($pdo->query($sql)->fetchAll() as $f) {
        $cuentas[$f['estado']] = (int) $f['n'];
        $cuentas['Todos'] += (int) $f['n'];
    }
    $cond = [];
    $params = [];
    if ($alcance > 0) {
        $cond[] = 'v.laboratorio_id = ?';
        $params[] = $alcance;
    }
    if ($tab !== 'Todos') {
        $cond[] = 'v.estado = ?';
        $params[] = $tab;
    }
    $stmt = $pdo->prepare(
        'SELECT v.id, v.numero, v.fecha, v.solicitante, v.proyecto, v.estado, l.nombre AS lab_nombre,
                (SELECT COUNT(*) FROM vale_items vi WHERE vi.vale_id = v.id) AS items
         FROM vales v JOIN laboratorios l ON l.id = v.laboratorio_id'
        . ($cond ? ' WHERE ' . implode(' AND ', $cond) : '') . ' ORDER BY v.id DESC LIMIT 300'
    );
    $stmt->execute($params);
    $vales = $stmt->fetchAll();
}
?>
<div class="row"><div class="grow"><h1 class="pt">Vales de salida</h1><p class="sub">Solicitud → visto bueno de jefatura → despacho en bodega (descuenta inventario) → recibido conforme.</p></div>
  <?php if (inv_puede($user, 'inventario.vales.crear')): ?><a class="btn p" href="<?= e(inv_url(['view' => 'nuevo'])) ?>">＋ Nuevo vale</a><?php endif; ?></div>
<div class="card">
  <div class="tabs">
    <?php foreach ($estados as $est): ?>
      <a class="tab <?= $tab === $est ? 'on' : '' ?>" href="<?= e(inv_url(['view' => 'vales', 'estado' => $est])) ?>"><?= e($est) ?> (<?= $cuentas[$est] ?>)</a>
    <?php endforeach; ?>
  </div>
  <div class="tw"><table><thead><tr><th>No. vale</th><th>Fecha</th><th>Laboratorio</th><th>Solicitante</th><th>Proyecto / ensayo</th><th class="n">Ítems</th><th>Estado</th><th></th></tr></thead><tbody>
  <?php foreach ($vales as $v): $url = e(inv_url(['view' => 'vale', 'id' => (int) $v['id']])); ?>
    <tr><td class="mono"><a class="linkish" href="<?= $url ?>"><?= e($v['numero']) ?></a></td><td><?= e(inv_fecha($v['fecha'])) ?></td><td><?= e($v['lab_nombre']) ?></td><td><?= e($v['solicitante']) ?></td><td><?= e($v['proyecto']) ?></td><td class="n"><?= (int) $v['items'] ?></td><td><?= inv_pill_vale($v['estado']) ?></td><td><a class="btn sm" href="<?= $url ?>">Abrir</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$vales): ?><tr><td colspan="8" class="empty">No hay vales en este estado</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if (count($vales) === 300): ?><p class="hint">Se muestran los 300 más recientes.</p><?php endif; ?>
</div>
