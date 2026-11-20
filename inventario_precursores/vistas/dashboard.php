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


// Presentacion con el mismo esquema del inicio de CengiLabs (laboratorio/index.php + css/inicio.css).
$hora = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$primerNombre = preg_split('/\s+/', trim((string) preg_replace('/^(Ing\.|Lic\.|Dra\.|Dr\.)\s*/u', '', (string) $user['nombre'])))[0] ?? '';
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaHoy = ucfirst($dias[(int) date('w')]) . ' ' . date('j') . ' de ' . $meses[(int) date('n')] . ' de ' . date('Y');
$irA = static fn(string $v): ?string => isset($permitidas[$v]) ? inv_url(['view' => $v]) : null;
$ubicacion = $alcance > 0 ? 'Lab. ' . $user['lab_nombre'] : 'Bodega Central';

// Resumen de alertas (cada fila enlaza a la vista donde se atiende, si el usuario puede abrirla).
$resumenAlertas = [];
if ($vencidos) {
    $resumenAlertas[] = ['n' => count($vencidos), 'tono' => 'alerta', 'titulo' => 'Lotes vencidos con existencia', 'detalle' => 'Deben retirarse del despacho y darse de baja.', 'href' => $irA('lotes')];
}
if ($proximos) {
    $resumenAlertas[] = ['n' => count($proximos), 'tono' => 'aviso', 'titulo' => 'Lotes próximos a vencer', 'detalle' => 'Vencen en ' . INV_DIAS_POR_VENCER . ' días o menos: despáchelos primero (FEFO).', 'href' => $irA('lotes')];
}
if ($bajos) {
    $resumenAlertas[] = ['n' => count($bajos), 'tono' => 'alerta', 'titulo' => 'Productos bajo el stock mínimo', 'detalle' => 'Programe la compra o el traslado de existencias.', 'href' => $irA('disponibilidad')];
}
?>
<div class="ini">
  <section class="ini-hero">
    <div class="ini-hero-copy">
      <span class="ini-eyebrow"><?= e($fechaHoy) ?></span>
      <h1><?= e($saludo) ?><?= $primerNombre !== '' ? ', ' . e($primerNombre) : '' ?></h1>
      <p><?= $tareas
          ? 'Tiene <b>' . count($tareas) . '</b> vale(s) que esperan su acción.'
          : 'Está al día: no tiene vales pendientes de su acción.' ?>
        Tablero de <b><?= e($ubicacion) ?></b><?= $alcance > 0 ? ' (vista limitada a su laboratorio)' : ' · consolidado de todos los laboratorios' ?>.</p>
      <div class="cgl-hero-brand">
        <span class="cengi-brand-mark inv-brand-mark" aria-hidden="true"><span class="material-symbols-outlined">inventory_2</span></span>
        <strong>SI<b>CIR</b></strong>
        <span>· Control de Inventario de Precursores y Reactivos</span>
      </div>
    </div>
    <div class="ini-hero-actions">
      <?php if ($irA('nuevo')): ?>
        <a class="ini-btn ini-btn-primary" href="<?= e($irA('nuevo')) ?>"><span class="material-symbols-outlined" aria-hidden="true">add_circle</span>Nuevo vale</a>
      <?php endif; ?>
      <?php if ($irA('vales')): ?>
        <a class="ini-btn ini-btn-light" href="<?= e($irA('vales')) ?>"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>Vales de salida</a>
      <?php endif; ?>
      <?php if ($irA('lotes')): ?>
        <a class="ini-btn ini-btn-light" href="<?= e($irA('lotes')) ?>"><span class="material-symbols-outlined" aria-hidden="true">stacks</span>Control de lotes</a>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($sinAcceso): ?>
    <div class="alert warn">Su usuario no tiene un laboratorio asignado, por lo que no se muestran existencias. Solicite la asignación al administrador del módulo.</div>
  <?php endif; ?>

  <section class="ini-kpis" aria-label="Indicadores del inventario">
    <article class="ini-kpi">
      <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">science</span></span>
      <div><strong><?= $productos ?></strong><span>Productos en catálogo<?= $alcance > 0 ? ' asignados' : '' ?></span></div>
    </article>
    <article class="ini-kpi">
      <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">stacks</span></span>
      <div><strong><?= count($activos) ?></strong><span>Lotes con existencia</span></div>
    </article>
    <article class="ini-kpi">
      <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">pending_actions</span></span>
      <div><strong><?= $enTramite ?></strong><span>Vales en trámite</span></div>
    </article>
    <article class="ini-kpi">
      <span class="ini-kpi-icon"><span class="material-symbols-outlined" aria-hidden="true">local_shipping</span></span>
      <div><strong><?= $despachadosMes ?></strong><span>Vales despachados este mes</span></div>
    </article>
  </section>

  <div class="ini-grid">
    <section class="ini-card" aria-labelledby="inv-tareas-titulo">
      <header class="ini-card-head">
        <h2 id="inv-tareas-titulo">Lo que le toca atender</h2>
        <?php if ($tareas): ?><span class="ini-count"><?= count($tareas) ?></span><?php endif; ?>
      </header>
      <?php if ($tareas): ?>
        <ul class="ini-tasks">
          <?php foreach ($tareas as [$v, $texto]): ?>
            <li><a class="ini-task" href="<?= e(inv_url(['view' => 'vale', 'id' => (int) $v['id']])) ?>">
              <span class="ini-task-num inv-task-ico"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span></span>
              <span class="ini-task-text"><b><?= e($v['numero']) ?> · <?= e($v['lab_nombre']) ?></b><small><?= e($v['solicitante']) ?> — <?= e($texto) ?></small></span>
              <?= inv_pill_vale($v['estado']) ?>
              <span class="material-symbols-outlined ini-task-go" aria-hidden="true">chevron_right</span>
            </a></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="ini-empty">
          <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
          <p><b>Todo al día.</b> Cuando un vale requiera su visto bueno, autorización o despacho aparecerá aquí.</p>
        </div>
      <?php endif; ?>
    </section>

    <section class="ini-card" aria-labelledby="inv-alertas-titulo">
      <header class="ini-card-head">
        <h2 id="inv-alertas-titulo">Alertas de inventario</h2>
        <?php if ($resumenAlertas): ?><span class="ini-count"><?= count($vencidos) + count($proximos) + count($bajos) ?></span><?php endif; ?>
      </header>
      <?php if ($resumenAlertas): ?>
        <ul class="ini-tasks">
          <?php foreach ($resumenAlertas as $a): ?>
            <li><<?= $a['href'] ? 'a href="' . e($a['href']) . '"' : 'div' ?> class="ini-task<?= $a['tono'] === 'alerta' ? ' is-alerta' : ' is-aviso' ?>">
              <span class="ini-task-num"><?= (int) $a['n'] ?></span>
              <span class="ini-task-text"><b><?= e($a['titulo']) ?></b><small><?= e($a['detalle']) ?></small></span>
              <?php if ($a['href']): ?><span class="material-symbols-outlined ini-task-go" aria-hidden="true">chevron_right</span><?php endif; ?>
            </<?= $a['href'] ? 'a' : 'div' ?>></li>
          <?php endforeach; ?>
        </ul>
        <details class="inv-detalle">
          <summary>Ver el detalle por lote y producto</summary>
          <div class="inv-detalle-lista">
            <?php foreach ($vencidos as $l): ?>
              <div class="alert bad"><div><b><?= e($l['lote']) ?></b> · <?= e($l['prod_nombre']) ?> (<?= e($l['lab_nombre']) ?>) vencido el <?= e(inv_fecha($l['caducidad'])) ?> con <?= e(inv_fmt($l['disp'])) ?> <?= e($l['unidad']) ?></div></div>
            <?php endforeach; foreach ($proximos as $l): ?>
              <div class="alert warn"><div><b><?= e($l['lote']) ?></b> · <?= e($l['prod_nombre']) ?> (<?= e($l['lab_nombre']) ?>) vence en <?= (int) $l['dias'] ?> días (<?= e(inv_fecha($l['caducidad'])) ?>) · <?= e(inv_fmt($l['disp'])) ?> <?= e($l['unidad']) ?></div></div>
            <?php endforeach; foreach ($bajos as $b): ?>
              <div class="alert <?= $b['disp'] <= 0 ? 'bad' : 'warn' ?>"><div><b><?= e($b['nombre']) ?></b> · <?= e($b['lab']) ?>: <?= e(inv_fmt($b['disp'])) ?> <?= e($b['unidad']) ?> (mínimo <?= e(inv_fmt($b['minimo'])) ?>)</div></div>
            <?php endforeach; ?>
          </div>
        </details>
      <?php else: ?>
        <div class="ini-empty">
          <span class="material-symbols-outlined" aria-hidden="true">verified</span>
          <p><b>Sin alertas.</b> No hay lotes vencidos, próximos a vencer ni productos bajo el mínimo.</p>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($porLab): ?>
  <section class="ini-card" aria-labelledby="inv-lab-titulo">
    <header class="ini-card-head"><h2 id="inv-lab-titulo">Líneas despachadas por laboratorio · <?= e(date('Y')) ?></h2></header>
    <div class="inv-barras">
      <?php foreach ($porLab as $i => $r): ?>
        <div class="inv-barra">
          <span class="inv-barra-lab"><?= e($r['nombre']) ?></span>
          <div class="bar grow"><i class="c<?= $i % 4 ?>" style="width:<?= round((int) $r['lineas'] / $max * 100, 1) ?>%"></i></div>
          <span class="inv-barra-n"><?= (int) $r['lineas'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
