<?php
declare(strict_types=1);
$valeId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT v.*, l.nombre AS lab_nombre FROM vales v JOIN laboratorios l ON l.id = v.laboratorio_id WHERE v.id = ?');
$stmt->execute([$valeId]);
$vale = $stmt->fetch();
$alcance = inv_alcance_lab($user);
if (!$vale || ($alcance !== 0 && (int) $vale['laboratorio_id'] !== $alcance)) {
    echo '<div class="card empty">Vale no encontrado</div>';
    return;
}
$vale['laboratorio_id'] = (int) $vale['laboratorio_id'];
$itemsStmt = $pdo->prepare(
    'SELECT vi.id, vi.producto_id, vi.cantidad, vi.entregado, vi.observaciones, p.codigo, p.nombre, p.concentracion, p.unidad
     FROM vale_items vi JOIN productos p ON p.id = vi.producto_id WHERE vi.vale_id = ? ORDER BY vi.id'
);
$itemsStmt->execute([$valeId]);
$items = $itemsStmt->fetchAll();
$asign = [];
$asStmt = $pdo->prepare('SELECT vil.vale_item_id, vil.lote, vil.cantidad FROM vale_item_lotes vil JOIN vale_items vi ON vi.id = vil.vale_item_id WHERE vi.vale_id = ? ORDER BY vil.id');
$asStmt->execute([$valeId]);
foreach ($asStmt->fetchAll() as $a) {
    $asign[(int) $a['vale_item_id']][] = $a;
}
$firmas = inv_vale_firmas($pdo, $valeId);
$bitacora = $pdo->prepare('SELECT usuario_nombre, accion, creado_en FROM vale_bitacora WHERE vale_id = ? ORDER BY id');
$bitacora->execute([$valeId]);
$bitacora = $bitacora->fetchAll();
$estado = $vale['estado'];
$entregado = $estado === 'Entregado';
$puedeExportar = inv_puede($user, 'inventario.informes.exportar');

if (($_GET['export'] ?? '') === 'excel' && $puedeExportar) {
    inv_enviar_excel([
        'archivo' => 'vale_' . $vale['numero'],
        'hoja' => 'Vale',
        'titulo' => 'Vale ' . $vale['numero'],
        'subtitulo' => 'Solicitud y descarga de reactivos',
        'emisor' => $user['nombre'],
        'meta' => [
            ['Laboratorio', $vale['lab_nombre']], ['Estado', $estado], ['Fecha', inv_fecha($vale['fecha'])],
            ['Solicitante', $vale['solicitante']], ['Supervisor', $vale['jefe']], ['Proyecto', $vale['proyecto']],
        ],
        'bloques' => [[
            'titulo' => 'Reactivos', 'vacio' => 'Sin reactivos registrados',
            'columnas' => [['Código', 't'], ['Reactivo', 't'], ['Concentración', 't'], ['Unidad', 't'],
                ['Solicitado', 'n'], ['Entregado', 'n'], ['Lotes', 't'], ['Observaciones', 't']],
            'filas' => array_map(static function (array $item) use ($asign, $entregado): array {
                $lotes = array_map(static fn(array $a): string => $a['lote'] . ' (' . inv_fmt(inv_milli($a['cantidad'])) . ')',
                    $asign[(int) $item['id']] ?? []);
                return [$item['codigo'], $item['nombre'], $item['concentracion'], $item['unidad'],
                    inv_milli($item['cantidad']), $entregado ? inv_milli($item['entregado']) : null,
                    implode(', ', $lotes), $item['observaciones']];
            }, $items),
        ], [
            'titulo' => 'Historial', 'vacio' => 'Sin movimientos',
            'columnas' => [['Fecha', 't'], ['Usuario', 't'], ['Acción', 't']],
            'filas' => array_map(static fn(array $b): array => [inv_fecha($b['creado_en']), $b['usuario_nombre'], $b['accion']], $bitacora),
        ]],
    ]);
}

if (($_GET['export'] ?? '') === 'pdf') {
    inv_vale_pdf($vale, $items, $asign, $firmas, $bitacora, $user);
}

$puedeAutorizar = inv_puede_autorizar_vale($user, $vale);
$puedeAnular = $estado === 'Autorizado' && inv_puede($user, 'inventario.vales.anular');
$puedeDespachar = $estado === 'Autorizado' && inv_puede($user, 'inventario.vales.despachar');

// Plan de despacho: existencia actual del laboratorio por producto y lotes en orden FEFO.
$existencias = [];
$lotesFefo = [];
if ($puedeDespachar) {
    $lotes = inv_lotes($pdo, ['laboratorio_id' => $vale['laboratorio_id']]);
    $existencias = inv_disponible_por_producto($lotes, $vale['laboratorio_id']);
    foreach ($lotes as $l) {
        if ($l['estado'] !== 'VENCIDO' && $l['disp'] > 0) {
            $lotesFefo[$l['producto_id']][] = $l;
        }
    }
    foreach ($lotesFefo as &$grupo) {
        usort($grupo, static fn($a, $b) => [$a['caducidad'], $a['id']] <=> [$b['caducidad'], $b['id']]);
    }
    unset($grupo);
}

$firmaCaja = static function (string $clave, string $rol, string $cargo) use ($firmas): string {
    $f = $firmas[$clave] ?? null;
    $html = '<div class="sig ' . ($f ? '' : 'pend') . '"><div class="rl">' . e($rol) . '</div><div class="im">';
    $html .= $f ? '<img src="' . e($f['imagen']) . '" alt="Firma de ' . e($f['nombre']) . '">' : 'Pendiente de firma';
    $html .= '</div><div class="ln"><b>' . ($f ? e($f['nombre']) : '&nbsp;') . '</b><br><span class="hint">' . e($cargo) . ($f ? ' · ' . e(inv_fecha($f['firmado_en'])) : '') . '</span></div></div>';
    return $html;
};
$pasos = [['Solicitado', 'Analista', 'sol'], ['Visto bueno', 'Jefe de laboratorio', 'aut'], ['Despachado', 'Bodega Central', 'ent'], ['Recibido conforme', 'Persona que retira', 'rec']];
$rechazado = in_array($estado, ['Rechazado', 'Anulado'], true);
$primerPendiente = null;
foreach ($pasos as $i => $p) {
    if (!isset($firmas[$p[2]])) {
        $primerPendiente = $i;
        break;
    }
}
?>
<div class="inv-vale-head noprint"><a class="btn" href="<?= e(inv_url(['view' => 'vales'])) ?>">← Vales</a><h1 class="pt">Vale <?= e($vale['numero']) ?></h1><?= inv_pill_vale($estado) ?><?= inv_menu_documentos(['view' => 'vale', 'id' => $valeId], $puedeExportar, true) ?></div>

<div class="card noprint inv-vale-flujo">
  <div class="flow">
  <?php foreach ($pasos as $i => $p):
      $f = $firmas[$p[2]] ?? null;
      $clase = $f ? 'done' : ($i === $primerPendiente ? ($rechazado ? 'rej' : 'cur') : ''); ?>
    <div class="step <?= $clase ?>"><div class="dot"><?= $f ? '✓' : ($clase === 'rej' ? '✕' : $i + 1) ?></div><b><?= e($p[0]) ?></b><span><?= $f ? e($f['nombre']) . ' · ' . e(inv_fecha($f['firmado_en'])) : e($p[1]) ?></span></div>
  <?php endforeach; ?>
  </div>
  <?php if (in_array($estado, ['Pendiente', 'Autorizado'], true)): ?>
  <div class="inv-vale-acciones">
  <?php if ($estado === 'Pendiente'): ?>
    <?php if ($puedeAutorizar): ?>
      <button type="button" class="btn d" data-abrir="dlgRechazar">✕ Rechazar</button>
      <form method="post" action="<?= e(inv_url(['view' => 'vale', 'id' => $valeId])) ?>" <?= inv_firma_attr([['p' => 'aut', 'titulo' => 'Visto bueno / autorizado: jefe inmediato', 'nombre' => $user['nombre'], 'fijo' => true]]) ?>>
        <?= inv_form_campos('vale_autorizar') ?><input type="hidden" name="id" value="<?= $valeId ?>"><button class="btn p" type="submit">✍ Firmar visto bueno</button></form>
    <?php else: ?><span class="hint">Esperando visto bueno de <?= e($vale['jefe'] !== '' ? $vale['jefe'] : 'la jefatura del laboratorio') ?></span><?php endif; ?>
  <?php elseif ($estado === 'Autorizado'): ?>
    <?php if ($puedeAnular): ?><button type="button" class="btn d" data-abrir="dlgAnular">Anular</button><?php endif; ?>
    <?php if ($puedeDespachar): ?><button type="button" class="btn p" data-abrir="dlgDespacho">⬆ Despachar y descontar inventario</button>
    <?php else: ?><span class="hint">Autorizado. Presentarse a Bodega Central para el despacho.</span><?php endif; ?>
  <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="doc">
  <div class="dh"><img src="../login/assets/img/logo-cengicana.png" alt="CENGICAÑA"><div class="t"><b>BODEGA CENTRAL DE PRECURSORES</b><span>VALE DE SOLICITUD Y DESCARGA DE REACTIVOS FISCALIZADOS</span></div><div class="no">No. DE VALE<b><?= e($vale['numero']) ?></b>Fecha: <?= e(inv_fecha($vale['fecha'])) ?></div></div>
  <div class="sh">1. LABORATORIO Y SOLICITANTE</div>
  <dl class="inv-vale-datos">
    <div><dt>Laboratorio</dt><dd><?= e($vale['lab_nombre']) ?></dd></div>
    <div><dt>Analista responsable</dt><dd><?= e($vale['solicitante']) ?></dd></div>
    <div><dt>Proyecto / ensayo</dt><dd><?= e($vale['proyecto']) ?></dd></div>
    <div><dt>Jefe / supervisor</dt><dd><?= e($vale['jefe'] !== '' ? $vale['jefe'] : '—') ?></dd></div>
  </dl>
  <div class="sh">2. DETALLE DE REACTIVOS PRECURSORES SOLICITADOS</div>
  <table><thead><tr><th>Ítem</th><th>Código</th><th>Precursor químico / concentración</th><th>Unidad</th><th class="n">Cant. solicitada</th><th class="n">Cant. entregada</th><th>ID lote asignado (uso bodega)</th><th>Observaciones / uso</th></tr></thead><tbody>
  <?php foreach ($items as $k => $i): ?>
    <tr><td><?= $k + 1 ?></td><td class="mono"><?= e($i['codigo']) ?></td><td><?= e($i['nombre']) ?><?= $i['concentracion'] ? ' (' . e($i['concentracion']) . ')' : '' ?></td><td><?= e($i['unidad']) ?></td><td class="n"><?= e(inv_fmt(inv_milli($i['cantidad']))) ?></td>
      <td class="n"><?= $entregado ? e(inv_fmt(inv_milli($i['entregado']))) : '—' ?></td>
      <td class="mono"><?php if ($entregado): foreach ($asign[(int) $i['id']] ?? [] as $a): ?><?= e($a['lote']) ?> (<?= e(inv_fmt(inv_milli($a['cantidad']))) ?>)<br><?php endforeach; else: ?>—<?php endif; ?></td>
      <td><?= e($i['observaciones']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <div class="sh">3. AUTORIZACIONES Y CONFORMIDAD DE ENTREGA</div>
  <div class="sigs"><?= $firmaCaja('sol', 'Solicitado por', 'Analista / solicitante') ?><?= $firmaCaja('aut', 'Visto bueno / autorizado', 'Jefe inmediato de laboratorio') ?><?= $firmaCaja('ent', 'Entregado por', 'Encargado de Bodega Central') ?><?= $firmaCaja('rec', 'Recibido conforme', 'Persona que retira') ?></div>
  <?php if ($rechazado): ?><div class="alert bad" style="margin-top:10px"><?= $estado === 'Rechazado' ? 'Rechazado' : 'Vale anulado' ?>: <?= e($vale['motivo']) ?></div><?php endif; ?>
  <div class="note">Nota: Este documento es un comprobante oficial auditable de descarga de sustancias fiscalizadas. La bodega central no entregará ningún precursor sin las firmas de autorización requeridas.</div>
</div>

<div class="card noprint" style="margin-top:16px"><h3>Bitácora del vale</h3><table class="inv-bitacora"><tbody>
<?php foreach ($bitacora as $b): ?><tr><td style="width:150px"><?= e(inv_fecha($b['creado_en'])) ?></td><td style="width:220px"><?= e($b['usuario_nombre']) ?></td><td><?= e($b['accion']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<?php if ($estado === 'Pendiente' && $puedeAutorizar): ?>
<dialog id="dlgRechazar" class="md"><form method="post" action="<?= e(inv_url(['view' => 'vale', 'id' => $valeId])) ?>">
  <?= inv_form_campos('vale_rechazar') ?><input type="hidden" name="id" value="<?= $valeId ?>">
  <header><h3>Rechazar vale <?= e($vale['numero']) ?></h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><label class="f">Motivo<textarea name="motivo" rows="3" required minlength="5" maxlength="500"></textarea></label></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn d" type="submit">Rechazar</button></footer></form></dialog>
<?php endif; ?>
<?php if ($puedeAnular): ?>
<dialog id="dlgAnular" class="md"><form method="post" action="<?= e(inv_url(['view' => 'vale', 'id' => $valeId])) ?>">
  <?= inv_form_campos('vale_anular') ?><input type="hidden" name="id" value="<?= $valeId ?>">
  <header><h3>Anular vale <?= e($vale['numero']) ?></h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><label class="f">Motivo<textarea name="motivo" rows="3" required minlength="5" maxlength="500"></textarea></label></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn d" type="submit">Anular</button></footer></form></dialog>
<?php endif; ?>
<?php if ($puedeDespachar): ?>
<dialog id="dlgDespacho" class="md lg"><form method="post" action="<?= e(inv_url(['view' => 'vale', 'id' => $valeId])) ?>"
  <?= inv_firma_attr([['p' => 'ent', 'titulo' => 'Entregado por: encargado de Bodega Central', 'nombre' => $user['nombre'], 'fijo' => true], ['p' => 'rec', 'titulo' => 'Recibido conforme: persona que retira', 'nombre' => '', 'fijo' => false, 'campo' => 'recibe']]) ?>>
  <?= inv_form_campos('vale_despachar') ?><input type="hidden" name="id" value="<?= $valeId ?>">
  <header><h3>Despacho de vale <?= e($vale['numero']) ?> · Lab. <?= e($vale['lab_nombre']) ?></h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><p class="hint" style="margin-top:0">Los lotes se asignan automáticamente por FEFO contra el inventario propio del laboratorio al confirmar. Puede ajustar la cantidad entregada (entrega parcial).</p>
  <div class="tw"><table><thead><tr><th>#</th><th>Reactivo</th><th class="n">Solicitado</th><th class="n">Disponible</th><th class="n">Entregar</th><th>Lotes disponibles (orden FEFO)</th></tr></thead><tbody>
  <?php foreach ($items as $k => $i):
      $solicitado = inv_milli($i['cantidad']);
      $disp = $existencias[(int) $i['producto_id']] ?? 0;
      $sugerida = min($solicitado, $disp); ?>
    <tr><td><?= $k + 1 ?></td><td><b><?= e($i['codigo']) ?></b> · <?= e($i['nombre']) ?></td><td class="n"><?= e(inv_fmt($solicitado)) ?> <?= e($i['unidad']) ?></td><td class="n"><?= e(inv_fmt($disp)) ?></td>
      <td class="n"><input class="in" name="entregar[<?= (int) $i['id'] ?>]" value="<?= e(inv_input_cantidad($sugerida)) ?>" inputmode="decimal" style="width:90px;text-align:right" aria-label="Cantidad a entregar de <?= e($i['nombre']) ?>"></td>
      <td class="mono"><?php foreach ($lotesFefo[(int) $i['producto_id']] ?? [] as $l): ?><?= e($l['lote']) ?>: <?= e(inv_fmt($l['disp'])) ?> <span class="hint">(vence <?= e(inv_fecha($l['caducidad'])) ?>)</span><br><?php endforeach; ?><?php if (empty($lotesFefo[(int) $i['producto_id']])): ?><span class="hint">Sin existencia</span><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="fg" style="margin-top:14px"><label class="f">Persona que retira<input name="recibe" value="<?= e($vale['solicitante']) ?>" maxlength="160" required></label><label class="f">Fecha de despacho<input value="<?= e(inv_fecha(inv_hoy())) ?>" disabled></label></div>
  <div class="alert warn" style="margin-top:12px"><span>Al confirmar se pedirán dos firmas seguidas: <b>Entregado por</b> (bodega) y <b>Recibido conforme</b> (quien retira); luego se descuenta el inventario de los lotes asignados.</span></div></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn p" type="submit">✍ Firmar entrega</button></footer></form></dialog>
<?php endif; ?>
