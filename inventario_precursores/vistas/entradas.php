<?php
declare(strict_types=1);
// ---------------------------------------------------------------- Filtros
$tab = (string) ($_GET['tab'] ?? 'Todas');
$tabs = ['Todas', 'Vigentes', 'Corregidas', 'Anuladas'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'Todas';
}
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80));
$labFiltro = (int) ($_GET['lab'] ?? 0);
$desde = inv_fecha_valida($_GET['desde'] ?? '') ?? '';
$hasta = inv_fecha_valida($_GET['hasta'] ?? '') ?? '';
$filtros = ['view' => 'entradas', 'q' => $q, 'lab' => $labFiltro ?: '', 'desde' => $desde, 'hasta' => $hasta];

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(en.lote LIKE ? OR p.codigo LIKE ? OR p.nombre LIKE ? OR en.proveedor LIKE ? OR en.factura LIKE ? OR en.lote_proveedor LIKE ? OR en.coa LIKE ?)';
    array_push($params, ...array_fill(0, 7, '%' . $q . '%'));
}
if ($labFiltro > 0) {
    $where[] = 'en.laboratorio_id = ?';
    $params[] = $labFiltro;
}
if ($desde !== '') {
    $where[] = 'en.fecha_ingreso >= ?';
    $params[] = $desde;
}
if ($hasta !== '') {
    $where[] = 'en.fecha_ingreso <= ?';
    $params[] = $hasta;
}
$sql = "SELECT en.*, l.nombre AS lab_nombre, l.codigo AS lab_codigo, p.codigo AS prod_codigo, p.nombre AS prod_nombre, p.unidad,
            COALESCE((SELECT SUM(vil.cantidad) FROM vale_item_lotes vil JOIN vale_items vi ON vi.id = vil.vale_item_id JOIN vales v ON v.id = vi.vale_id
                      WHERE vil.entrada_id = en.id AND v.estado = 'Entregado'), 0) AS consumido,
            (SELECT COUNT(*) FROM entrada_historial h WHERE h.entrada_id = en.id AND h.tipo = 'correccion') AS correcciones,
            (SELECT COUNT(*) FROM entrada_historial h WHERE h.entrada_id = en.id) AS eventos
        FROM entradas en JOIN laboratorios l ON l.id = en.laboratorio_id JOIN productos p ON p.id = en.producto_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY en.fecha_ingreso DESC, en.id DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todas = $stmt->fetchAll();

$estadoDe = static fn(array $t): string => (int) $t['anulada'] === 1 ? 'Anuladas' : ((int) $t['correcciones'] > 0 ? 'Corregidas' : 'Vigentes');
$cuentas = array_fill_keys($tabs, 0);
$cuentas['Todas'] = count($todas);
foreach ($todas as $t) {
    $cuentas[$estadoDe($t)]++;
}
// "Vigentes" = no anuladas (incluye las corregidas, que siguen sumando al inventario).
$cuentas['Vigentes'] += $cuentas['Corregidas'];
$filas = array_values(array_filter($todas, static function (array $t) use ($tab, $estadoDe): bool {
    $estado = $estadoDe($t);
    return $tab === 'Todas' || $estado === $tab || ($tab === 'Vigentes' && $estado === 'Corregidas');
}));

// ---------------------------------------------------------------- Exportar
$puedeExportar = inv_puede($user, 'inventario.informes.exportar');
if (in_array($_GET['export'] ?? '', ['excel', 'pdf'], true)) {
    $labNombre = 'Todos';
    foreach (inv_laboratorios($pdo, false) as $l) {
        $labNombre = (int) $l['id'] === $labFiltro ? $l['nombre'] : $labNombre;
    }
    inv_exportar_si_pide([
        'archivo' => 'entradas_' . inv_hoy(),
        'hoja' => 'Entradas',
        'titulo' => 'REGISTRO DE ENTRADAS A BODEGA CENTRAL',
        'subtitulo' => 'Ingresos de reactivos precursores por compra; cada ingreso genera un lote',
        'emisor' => $user['nombre'],
        'compacto' => true,
        'meta' => [['Vista', $tab], ['Laboratorio', $labNombre], ['Búsqueda', $q !== '' ? $q : '—'],
            ['Desde', $desde !== '' ? inv_fecha($desde) : '—'], ['Hasta', $hasta !== '' ? inv_fecha($hasta) : '—'], ['Ingresos', (string) count($filas)]],
        'bloques' => [[
            'titulo' => null, 'vacio' => 'No hay entradas con estos filtros',
            'columnas' => [['Ingreso', 't'], ['Fecha ingreso', 'f'], ['Laboratorio', 't'], ['ID lote', 't'], ['Código', 't', true], ['Producto', 't'],
                ['Cantidad', 'n', true], ['Unidad', 't', true], ['Cantidad', 'nu', 'pdf'], ['Consumido', 'n'], ['Disponible', 'n', true], ['Caducidad', 'f'],
                ['Proveedor', 't'], ['Factura / remisión', 't'], ['Lote proveedor', 't', true], ['Certificado (CoA)', 't', true], ['Recibido por', 't', true], ['Estado', 't']],
            'anchos_pdf' => [7, 8, 10, 10, 18, 8, 8, 8, 10, 7, 6],
            'filas' => array_map(static function (array $f) use ($estadoDe): array {
                $cant = inv_milli($f['cantidad']);
                $cons = inv_milli($f['consumido']);
                $estado = ['Anuladas' => 'Anulada', 'Corregidas' => 'Corregida', 'Vigentes' => 'Vigente'][$estadoDe($f)];
                return [sprintf('ING-%03d', (int) $f['id']), $f['fecha_ingreso'], $f['lab_nombre'], $f['lote'], $f['prod_codigo'], $f['prod_nombre'],
                    $cant, $f['unidad'], [$cant, $f['unidad']], $cons, (int) $f['anulada'] === 1 ? 0 : $cant - $cons, $f['caducidad'],
                    (string) $f['proveedor'], (string) $f['factura'], (string) $f['lote_proveedor'], (string) $f['coa'], (string) $f['recibido_por'], $estado];
            }, $filas),
        ]],
    ], $puedeExportar);
}

// ---------------------------------------------------------------- Datos de formularios
$laboratorios = inv_laboratorios($pdo, true);
$todosLabs = inv_laboratorios($pdo, false);
$matrizPorLab = [];
foreach ($pdo->query('SELECT pl.laboratorio_id, p.id, p.codigo, p.nombre, p.unidad, p.activo FROM producto_laboratorio pl JOIN productos p ON p.id = pl.producto_id ORDER BY p.codigo')->fetchAll() as $r) {
    $matrizPorLab[(int) $r['laboratorio_id']][] = ['id' => (int) $r['id'], 'texto' => $r['codigo'] . ' · ' . $r['nombre'] . ' (' . $r['unidad'] . ')', 'unidad' => $r['unidad'], 'activo' => (int) $r['activo']];
}
// Proximo ID de lote por laboratorio (vista previa; el definitivo se asigna al guardar).
$siguienteLote = [];
$ultimos = [];
foreach ($pdo->query("SELECT clave, ultimo FROM secuencias WHERE clave LIKE 'lote-%'")->fetchAll() as $s) {
    $ultimos[$s['clave']] = (int) $s['ultimo'];
}
foreach ($laboratorios as $l) {
    $siguienteLote[(int) $l['id']] = sprintf('LOT-%s-%03d', $l['codigo'], ($ultimos['lote-' . $l['id']] ?? 0) + 1);
}
$borrador = $_SESSION['inventario_entrada_borrador'] ?? null;
unset($_SESSION['inventario_entrada_borrador']);
$bv = static fn(string $k, string $def = ''): string => (string) ($borrador[$k] ?? $def);

$puedeCorregir = inv_puede($user, 'inventario.entradas.corregir');
$puedeAnular = inv_puede($user, 'inventario.entradas.anular');
$historiales = [];
foreach ($filas as $f) {
    if ((int) $f['eventos'] > 0) {
        $historiales[(int) $f['id']] = inv_entrada_historial($pdo, (int) $f['id']);
    }
}
$campoFecha = static function (string $v): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? inv_fecha($v) : ($v === '' ? '—' : $v);
};
$hayFiltros = $q !== '' || $labFiltro > 0 || $desde !== '' || $hasta !== '';
$icono = static fn(string $n): string => '<span class="material-symbols-outlined" aria-hidden="true">' . $n . '</span>';
?>
<div class="inv-vale-head"><div style="flex:1;min-width:260px"><h1 class="pt">Registro de entradas / compras</h1>
  <p class="sub" style="margin:2px 0 0">Cada ingreso genera un lote. Los errores se corrigen con justificación y firma; el historial conserva el valor original.</p></div>
  <?= inv_menu_documentos($filtros + ['tab' => $tab], $puedeExportar) ?>
  <button class="btn p" type="button" data-abrir="dlgEntrada">＋ Registrar ingreso</button></div>

<div class="card" id="entradasDatos" data-matriz="<?= e(json_encode($matrizPorLab, JSON_UNESCAPED_UNICODE)) ?>">
  <form method="get" class="inv-filtros" data-autosubmit>
    <input type="hidden" name="view" value="entradas"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label class="f inv-buscar">Buscar<input type="search" name="q" value="<?= e($q) ?>" placeholder="Lote, producto, proveedor, factura, CoA…"></label>
    <label class="f">Laboratorio<select name="lab"><option value="">Todos</option><?php foreach ($todosLabs as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $labFiltro === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option><?php endforeach; ?></select></label>
    <label class="f">Desde<input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label class="f">Hasta<input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <?php if ($hayFiltros): ?><a class="btn sm" href="<?= e(inv_url(['view' => 'entradas', 'tab' => $tab])) ?>">Quitar filtros</a><?php endif; ?>
    <noscript><button class="btn">Filtrar</button></noscript>
  </form>
  <div class="tabs"><?php foreach ($tabs as $t): ?><a class="tab <?= $tab === $t ? 'on' : '' ?>" href="<?= e(inv_url($filtros + ['tab' => $t])) ?>"><?= e($t) ?> (<?= $cuentas[$t] ?>)</a><?php endforeach; ?></div>
  <div class="tw"><table class="inv-entradas"><thead><tr><th>Lote / ingreso</th><th>Producto</th><th>Proveedor y documentos</th><th>Caducidad</th><th class="n">Existencia</th><th>Estado</th><th class="inv-acc-col">Acciones</th></tr></thead><tbody>
  <?php foreach ($filas as $f):
      $anulada = (int) $f['anulada'] === 1;
      $cantidad = inv_milli($f['cantidad']);
      $consumido = inv_milli($f['consumido']);
      $disponible = $anulada ? 0 : $cantidad - $consumido;
      $dias = inv_dias_hasta((string) $f['caducidad']);
      $datos = [
          'id' => (int) $f['id'], 'codigo' => sprintf('ING-%03d', (int) $f['id']), 'lote' => $f['lote'], 'fecha_ingreso' => $f['fecha_ingreso'],
          'laboratorio_id' => (int) $f['laboratorio_id'], 'producto_id' => (int) $f['producto_id'], 'cantidad' => inv_input_cantidad($cantidad),
          'caducidad' => $f['caducidad'], 'proveedor' => (string) $f['proveedor'], 'factura' => (string) $f['factura'],
          'lote_proveedor' => (string) $f['lote_proveedor'], 'coa' => (string) $f['coa'], 'consumido' => inv_fmt($consumido),
          'consumido_milli' => $consumido, 'unidad' => $f['unidad'], 'producto' => $f['prod_nombre'], 'bloqueado' => $consumido > 0,
      ];
      $docs = array_filter(['Factura' => (string) $f['factura'], 'Lote prov.' => (string) $f['lote_proveedor'], 'CoA' => (string) $f['coa']], static fn($v) => $v !== '');
      $pct = $cantidad > 0 ? max(0, min(100, $disponible / $cantidad * 100)) : 0; ?>
    <tr class="<?= $anulada ? 'anul' : '' ?>">
      <td><b class="mono"><?= e($f['lote']) ?></b><div class="hint"><?= e($datos['codigo']) ?> · <?= e(inv_fecha($f['fecha_ingreso'])) ?></div><div class="hint">Recibió: <?= e($f['recibido_por']) ?></div></td>
      <td><span class="inv-cod"><?= e($f['prod_codigo']) ?></span> <?= e($f['prod_nombre']) ?><div class="hint">Lab. <?= e($f['lab_nombre']) ?></div></td>
      <td><?= $f['proveedor'] !== null && $f['proveedor'] !== '' ? e($f['proveedor']) : '<span class="hint">Sin proveedor</span>' ?>
        <?php if ($docs): ?><div class="inv-docs"><?php foreach ($docs as $k => $v): ?><span><?= e($k) ?>: <b><?= e($v) ?></b></span><?php endforeach; ?></div><?php endif; ?></td>
      <td><?= e(inv_fecha($f['caducidad'])) ?>
        <?php if (!$anulada && $disponible > 0): ?><div><?= $dias < 0 ? '<span class="pill p-bad">Vencido</span>' : ($dias <= INV_DIAS_POR_VENCER ? '<span class="pill p-warn">Vence en ' . $dias . ' d</span>' : '<span class="hint">' . $dias . ' días</span>') ?></div><?php endif; ?></td>
      <td class="n"><b><?= e(inv_fmt($disponible)) ?> <?= e($f['unidad']) ?></b><div class="hint">de <?= e(inv_fmt($cantidad)) ?> · consumido <?= e(inv_fmt($consumido)) ?></div>
        <?php if (!$anulada): ?><div class="bar inv-bar-mini"><i style="width:<?= round($pct, 1) ?>%"></i></div><?php endif; ?></td>
      <td><?= $anulada ? '<span class="pill p-bad">Anulada</span>' : ((int) $f['correcciones'] > 0 ? '<span class="pill p-warn">Corregida (' . (int) $f['correcciones'] . ')</span>' : '<span class="pill p-ok">Vigente</span>') ?></td>
      <td class="inv-acc">
        <?php if ((!$anulada && ($puedeCorregir || $puedeAnular)) || (int) $f['eventos'] > 0): ?>
        <button type="button" class="btn sm inv-acc-toggle" popovertarget="accionesEntrada<?= (int) $f['id'] ?>" aria-label="Acciones de <?= e($datos['codigo']) ?>">Acciones <?= $icono('expand_more') ?></button>
        <div class="inv-acc-grupo" id="accionesEntrada<?= (int) $f['id'] ?>" popover>
        <?php if (!$anulada && $puedeCorregir): ?><button type="button" class="btn sm" data-corregir="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>" title="Corregir un error del ingreso"><?= $icono('edit') ?>Corregir</button><?php endif; ?>
        <?php if (!$anulada && $puedeAnular): ?><button type="button" class="btn sm d" data-anular="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>" <?= $consumido > 0 ? 'disabled title="El lote ya tiene consumo; no puede anularse"' : 'title="Anular el ingreso"' ?>><?= $icono('block') ?>Anular</button><?php endif; ?>
        <?php if ((int) $f['eventos'] > 0): ?><button type="button" class="btn sm" data-abrir="dlgHist<?= (int) $f['id'] ?>" title="Ver correcciones y anulación"><?= $icono('history') ?>Historial</button><?php endif; ?>
        </div>
        <?php else: ?>
        <span class="hint">&mdash;</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$filas): ?><tr><td colspan="7" class="empty"><?= $hayFiltros ? 'No hay entradas con estos filtros' : 'Aún no hay entradas registradas' ?></td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="hint" style="margin-bottom:0">Corregir requiere el permiso «Corregir entradas». Anular solo es posible si el lote no tiene consumo.<?= count($todas) === 500 ? ' Se muestran las 500 más recientes; use los filtros para acotar.' : '' ?></p>
</div>

<dialog id="dlgEntrada" class="md lg" <?= $borrador ? 'data-abrir-al-cargar' : '' ?>><form method="post" action="<?= e(inv_url(['view' => 'entradas'])) ?>" id="formEntrada">
  <?= inv_form_campos('entrada_registrar') ?>
  <header><h3>Registrar ingreso a Bodega Central</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd">
    <div class="inv-lote-previo" id="entLotePrevio" data-siguientes="<?= e(json_encode($siguienteLote)) ?>"><span>Lote que se generará</span><b></b><small>El número definitivo se asigna al guardar.</small></div>
    <div class="sh">1. DOCUMENTO DE COMPRA</div>
    <div class="fg">
      <label class="f">Fecha de ingreso<input type="date" name="fecha_ingreso" id="entFecha" value="<?= e($bv('fecha_ingreso', inv_hoy())) ?>" max="<?= e(inv_hoy()) ?>" required></label>
      <label class="f">Laboratorio comprador / propietario<select name="laboratorio_id" id="entLab" data-matriz-lab="#entProducto" required>
        <?php foreach ($laboratorios as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $bv('laboratorio_id') === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option><?php endforeach; ?></select></label>
      <label class="f">Proveedor<input name="proveedor" value="<?= e($bv('proveedor')) ?>" maxlength="160" list="entProveedores"></label>
      <label class="f">No. factura / remisión<input name="factura" value="<?= e($bv('factura')) ?>" maxlength="80"></label>
    </div>
    <datalist id="entProveedores"><?php foreach ($pdo->query("SELECT DISTINCT proveedor FROM entradas WHERE proveedor IS NOT NULL AND proveedor <> '' ORDER BY proveedor LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) as $prov): ?><option value="<?= e($prov) ?>"><?php endforeach; ?></datalist>
    <div class="sh" style="margin-top:14px">2. PRODUCTO Y LOTE</div>
    <div class="fg">
      <label class="f" style="grid-column:span 2">Producto (según matriz del laboratorio)<select name="producto_id" id="entProducto" data-elegido="<?= e($bv('producto_id')) ?>" required></select></label>
      <label class="f">Cantidad ingresada<span class="inv-con-unidad"><input name="cantidad" id="entCantidad" value="<?= e($bv('cantidad')) ?>" inputmode="decimal" autocomplete="off" required><em id="entUnidad"></em></span></label>
      <label class="f">Fecha de caducidad<input type="date" name="caducidad" id="entCaducidad" value="<?= e($bv('caducidad')) ?>" required><span class="hint" id="entCadAviso"></span></label>
      <label class="f">No. lote proveedor<input name="lote_proveedor" value="<?= e($bv('lote_proveedor')) ?>" maxlength="80"></label>
      <label class="f">Certificado (CoA)<input name="coa" value="<?= e($bv('coa')) ?>" maxlength="80"></label>
    </div>
    <label class="inv-check"><input type="checkbox" name="otro" value="1" <?= $bv('otro') !== '' ? 'checked' : '' ?>> Después de guardar, registrar otro producto de la misma factura</label>
  </div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn p" type="submit">Guardar ingreso</button></footer></form></dialog>

<?php if ($puedeCorregir): ?>
<dialog id="dlgCorregir" class="md lg"><form method="post" action="<?= e(inv_url(['view' => 'entradas'])) ?>" id="formCorregir" <?= inv_firma_attr([['p' => 'cor', 'titulo' => 'Corrección de entrada', 'nombre' => $user['nombre'], 'fijo' => true]]) ?>>
  <?= inv_form_campos('entrada_corregir') ?><input type="hidden" name="id">
  <header><h3 id="corTitulo">✎ Corregir entrada</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd">
    <div class="alert warn" id="corBloqueo" hidden style="margin-top:0"></div>
    <div class="sh">1. MOTIVO DE LA CORRECCIÓN</div>
    <div class="fg"><label class="f">Tipo de error<select name="error_tipo" required><option value="">— Seleccione —</option><?php foreach (INV_ERRORES_ENTRADA as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select></label>
      <label class="f" style="grid-column:span 2">Descripción del error y justificación<textarea name="motivo" rows="2" required minlength="10" maxlength="1000" placeholder="Ej. Se digitó 0 L en lugar de 20 L según factura FAC-10029"></textarea></label>
      <label class="f">Documento de soporte (opcional)<input name="documento" maxlength="160"></label></div>
    <div class="sh" style="margin-top:14px">2. DATOS CORREGIDOS</div>
    <div class="fg">
      <label class="f">Fecha de ingreso<input type="date" name="fecha_ingreso" max="<?= e(inv_hoy()) ?>" required></label>
      <label class="f">Laboratorio propietario<select name="laboratorio_id" data-matriz-lab="#corProducto" data-incluir-inactivos="1" required><?php foreach ($todosLabs as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['nombre']) ?></option><?php endforeach; ?></select></label>
      <label class="f" style="grid-column:span 2">Producto<select name="producto_id" id="corProducto" required></select></label>
      <label class="f">Cantidad ingresada<input name="cantidad" inputmode="decimal" required></label>
      <label class="f">Fecha de caducidad<input type="date" name="caducidad" required></label>
      <label class="f">Proveedor<input name="proveedor" maxlength="160"></label><label class="f">Factura / remisión<input name="factura" maxlength="80"></label>
      <label class="f">Lote proveedor<input name="lote_proveedor" maxlength="80"></label><label class="f">Certificado (CoA)<input name="coa" maxlength="80"></label></div>
  </div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn p" type="submit">✍ Firmar y guardar corrección</button></footer></form></dialog>
<?php endif; ?>

<?php if ($puedeAnular): ?>
<dialog id="dlgAnularEntrada" class="md"><form method="post" action="<?= e(inv_url(['view' => 'entradas'])) ?>" id="formAnularEntrada" <?= inv_firma_attr([['p' => 'anu', 'titulo' => 'Anulación de entrada', 'nombre' => $user['nombre'], 'fijo' => true]]) ?>>
  <?= inv_form_campos('entrada_anular') ?><input type="hidden" name="id">
  <header><h3 id="anuTitulo">⊘ Anular entrada</h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd"><div class="alert bad" id="anuResumen" style="margin-top:0"></div>
    <div class="fg"><label class="f">Tipo de error<select name="error_tipo" required><?php foreach (INV_ERRORES_ANULACION as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select></label>
      <label class="f" style="grid-column:span 2">Justificación<textarea name="motivo" rows="2" required minlength="10" maxlength="1000"></textarea></label></div></div>
  <footer><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn d" type="submit">✍ Firmar y anular</button></footer></form></dialog>
<?php endif; ?>

<?php foreach ($historiales as $entradaId => $hist): ?>
<dialog id="dlgHist<?= $entradaId ?>" class="md inv-historial" aria-labelledby="histTitulo<?= $entradaId ?>">
  <header><h3 id="histTitulo<?= $entradaId ?>">Historial de <?= e(sprintf('ING-%03d', $entradaId)) ?></h3><button type="button" class="x" data-cerrar aria-label="Cerrar">×</button></header>
  <div class="bd">
    <?php foreach ($hist as $h): ?>
    <details class="inv-historial-evento" name="historial-<?= $entradaId ?>">
      <summary>
        <b><?= $h['tipo'] === 'anulacion' ? 'Anulación' : 'Corrección' ?>: <?= e($h['error_tipo']) ?></b>
        <span class="hint"><?= e(inv_fecha($h['creado_en'])) ?> · <?= e($h['usuario_nombre']) ?></span>
      </summary>
      <div class="inv-historial-detalle">
        <p><?= e($h['motivo']) ?><?= $h['documento'] ? ' <span class="hint">· Soporte: ' . e($h['documento']) . '</span>' : '' ?></p>
        <?php if ($h['cambios']): ?>
        <table class="diff"><thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead><tbody>
          <?php foreach ($h['cambios'] as $c): ?><tr><td><?= e($c['campo']) ?></td><td class="old"><?= e($campoFecha((string) $c['antes'])) ?></td><td class="new"><?= e($campoFecha((string) $c['despues'])) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <div class="inv-historial-firma hint">Firma: <?php if ($h['firma_imagen']): ?><img src="<?= e($h['firma_imagen']) ?>" alt=""><?php endif; ?><b><?= e($h['usuario_nombre']) ?></b></div>
      </div>
    </details>
    <?php endforeach; ?>
  </div>
</dialog>
<?php endforeach; ?>
