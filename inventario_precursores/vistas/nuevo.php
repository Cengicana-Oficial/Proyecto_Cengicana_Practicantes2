<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$borrador = $_SESSION['inventario_borrador'] ?? [];
unset($_SESSION['inventario_borrador']);
$labId = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? ($borrador['laboratorio_id'] ?? 0));
$laboratorios = inv_laboratorios($pdo, true);
if ($alcance === 0 && $labId === 0 && $laboratorios) {
    $labId = (int) $laboratorios[0]['id'];
}
$lab = $alcance === -1 ? null : inv_laboratorio($pdo, $labId);
$lista = $lab ? inv_productos_de_lab($pdo, (int) $lab['id']) : [];
$libres = [];
if ($lab) {
    $disp = inv_disponible_por_producto(inv_lotes($pdo, ['laboratorio_id' => (int) $lab['id']]), (int) $lab['id']);
    $comp = inv_comprometido($pdo, (int) $lab['id']);
    foreach ($lista as $p) {
        $libres[(int) $p['id']] = max(0, ($disp[(int) $p['id']] ?? 0) - ($comp[(int) $p['id']] ?? 0));
    }
}
$conExistencia = count(array_filter($libres, static fn(int $l): bool => $l > 0));

// Jefe / supervisor: solo quienes pueden dar el visto bueno a este laboratorio.
$candidatos = $lab ? inv_jefes_candidatos($menuPdo, $pdo, (int) $lab['id'], $user) : [];
$jefeSel = (int) ($borrador['jefe_id'] ?? 0);
if (!isset($candidatos[$jefeSel])) {
    $jefeLab = $lab ? (int) $lab['jefe_usuario_id'] : 0;
    $jefeSel = isset($candidatos[$jefeLab]) ? $jefeLab : (count($candidatos) === 1 ? (int) array_key_first($candidatos) : 0);
}

$filas = $borrador['items'] ?? [['producto_id' => '', 'cantidad' => '', 'obs' => '']];
$proyecto = (string) ($borrador['proyecto'] ?? ($_GET['proyecto'] ?? ''));
$siguiente = sprintf('VAL-%d-%04d', (int) date('Y'), (int) $pdo->query('SELECT COALESCE(MAX(correlativo),0)+1 FROM vales WHERE anio = ' . (int) date('Y'))->fetchColumn());
$opcionesDe = static function (int $elegido) use ($lista, $libres): string {
    $html = '<option value="">— Seleccione reactivo / material —</option>';
    foreach ($lista as $p) {
        $id = (int) $p['id'];
        $libre = $libres[$id] ?? 0;
        $html .= '<option value="' . $id . '" data-unidad="' . e($p['unidad']) . '"'
            . ($id === $elegido ? ' selected' : '') . ($libre <= 0 ? ' data-agotado' . ($id !== $elegido ? ' disabled' : '') : '') . '>'
            . e($p['codigo'] . ' · ' . $p['nombre'] . ($libre > 0 ? '' : ' — sin existencia')) . '</option>';
    }
    return $html;
};
$filaHtml = static function (int $i, array $f) use ($opcionesDe): string {
    $n = $i < 0 ? '__' : (string) $i;
    return '<tr class="linea"><td class="num">' . ($i < 0 ? '' : $i + 1) . '</td>'
        . '<td><select class="in prod" name="items[' . $n . '][producto_id]" aria-label="Código o reactivo">'
        . $opcionesDe((int) ($f['producto_id'] ?? 0)) . '</select><span class="libre hint" aria-live="polite"></span></td>'
        . '<td><span class="inv-con-unidad"><input class="in cant" name="items[' . $n . '][cantidad]" value="'
        . e((string) ($f['cantidad'] ?? '')) . '" inputmode="decimal" autocomplete="off" placeholder="0" aria-label="Cantidad solicitada"><em class="unidad"></em></span>'
        . '<span class="hint exceso" hidden></span></td>'
        . '<td><input class="in obs" name="items[' . $n . '][obs]" value="'
        . e((string) ($f['obs'] ?? '')) . '" maxlength="255" placeholder="Uso previsto" aria-label="Observaciones opcionales"></td>'
        . '<td><button type="button" class="btn sm quitar">Quitar</button></td></tr>';
};
$seccion = static fn(int $n, string $t): string => '<h3 class="inv-sec"><span class="inv-sec-num">' . $n . '</span>' . e($t) . '</h3>';
?>
<div class="inv-vale-head"><h1 class="pt">Nuevo vale de solicitud y descarga</h1></div>
<p class="sub" style="margin-top:-8px">Solicite reactivos de la matriz autorizada del laboratorio. La existencia libre ya descuenta lo comprometido en otros vales en trámite.</p>
<?php if ($alcance === -1): ?>
  <div class="alert warn">Su usuario no tiene un laboratorio asignado. Solicítelo al administrador del módulo.</div>
<?php elseif (!$lab): ?>
  <div class="alert warn">No hay laboratorios activos.</div>
<?php else: ?>
<form method="post" action="<?= e(inv_url(['view' => 'nuevo'])) ?>" id="formNuevoVale" <?= inv_firma_attr([['p' => 'sol', 'titulo' => 'Solicitado por: analista / solicitante', 'nombre' => $user['nombre'], 'fijo' => true]]) ?>>
  <?= inv_form_campos('vale_crear') ?>
  <input type="hidden" name="laboratorio_id" value="<?= (int) $lab['id'] ?>">

  <div class="card"><?= $seccion(1, 'Datos del laboratorio y solicitante') ?>
    <p class="hint inv-solicitud-meta"><span>Solicitante <b><?= e($user['nombre']) ?></b></span><span>Fecha <b><?= e(inv_fecha(inv_hoy())) ?></b></span><span>Folio provisional <b><?= e($siguiente) ?></b></span></p>
    <div class="inv-solicitud-campos">
      <?php if ($alcance === 0): ?>
        <label class="f">Laboratorio solicitante<select data-ir-lab="<?= e(inv_url(['view' => 'nuevo'])) ?>">
          <?php foreach ($laboratorios as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === (int) $lab['id'] ? 'selected' : '' ?>>Laboratorio <?= e($l['nombre']) ?></option><?php endforeach; ?>
        </select></label>
      <?php else: ?>
        <label class="f">Laboratorio solicitante<input value="Laboratorio <?= e($lab['nombre']) ?>" readonly></label>
      <?php endif; ?>
      <label class="f">Jefe / supervisor inmediato
        <select name="jefe_id" required <?= $candidatos ? '' : 'disabled' ?>>
          <option value=""><?= $candidatos ? '— Seleccione quién dará el visto bueno —' : 'Nadie puede autorizar este laboratorio' ?></option>
          <?php foreach ($candidatos as $id => $nombre): ?><option value="<?= (int) $id ?>" <?= $id === $jefeSel ? 'selected' : '' ?>><?= e($nombre) ?></option><?php endforeach; ?>
        </select>
        </label>
      <label class="f">Proyecto / ensayo<input name="proyecto" value="<?= e($proyecto) ?>" maxlength="200" required placeholder="Ej. Titulación de acidez en muestras de jugo"></label>
    </div>
    <?php if (!$candidatos): ?><div class="alert warn" style="margin-top:12px"><span>Ningún usuario tiene el permiso <b>Autorizar vales</b> con alcance sobre el Laboratorio <?= e($lab['nombre']) ?>. Asígnelo en <b>Usuarios del módulo</b> (rol con ese permiso y este laboratorio) para poder enviar vales.</span></div><?php endif; ?>
  </div>

  <div class="card">
    <?= $seccion(2, 'Reactivos solicitados') ?>
    <div class="tw inv-lineas-scroll" role="region" aria-label="Tabla de reactivos solicitados" tabindex="0">
      <table class="inv-lineas">
        <thead><tr><th scope="col">#</th><th scope="col">Código / reactivo</th><th scope="col">Cantidad</th><th scope="col">Observaciones <span class="hint">(opcional)</span></th><th scope="col">Acciones</th></tr></thead>
        <tbody id="lineas" data-libres="<?= e(json_encode($libres)) ?>">
          <?php foreach (array_values($filas ?: [[]]) as $i => $f): ?><?= $filaHtml($i, (array) $f) ?><?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="inv-linea-pie"><button type="button" class="btn" id="agregarLinea" <?= $conExistencia > 0 ? '' : 'disabled' ?>>＋ Agregar fila</button></div>
    <template id="plantillaLinea"><?= $filaHtml(-1, []) ?></template>
    <?php if (!$lista): ?><div class="alert warn"><span>Este laboratorio no tiene reactivos asignados en la matriz. Solicite a Administración que los asigne en <b>Catálogo y matriz</b>.</span></div>
    <?php elseif ($conExistencia === 0): ?><div class="alert warn"><span>Ningún reactivo de este laboratorio tiene existencia libre. Registre una entrada a bodega antes de solicitar.</span></div><?php endif; ?>
    <p class="hint" style="margin-bottom:0">El lote se asigna en bodega por FEFO (primero en vencer, primero en salir) al momento del despacho.</p>
  </div>

  <div class="card"><?= $seccion(3, 'Firma y envío') ?>
    <div class="inv-ruta">
      <div class="inv-ruta-paso is-yo"><b>1</b><span>Usted firma la solicitud</span></div>
      <div class="inv-ruta-paso"><b>2</b><span>Visto bueno de <em id="rutaJefe"><?= e($candidatos[$jefeSel] ?? 'el jefe inmediato') ?></em></span></div>
      <div class="inv-ruta-paso"><b>3</b><span>Despacho en Bodega Central</span></div>
      <div class="inv-ruta-paso"><b>4</b><span>Recibido conforme</span></div>
    </div>
    <div class="inv-vale-acciones"><a class="btn" href="<?= e(inv_url(['view' => 'nuevo', 'lab' => (int) $lab['id']])) ?>">Limpiar</a>
      <button class="btn p" type="submit" <?= $lista && $candidatos && $conExistencia > 0 ? '' : 'disabled' ?>>✍ Firmar y enviar a autorización</button></div>
  </div>
</form>
<?php endif; ?>
