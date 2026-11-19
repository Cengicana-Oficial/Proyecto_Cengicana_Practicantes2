<?php
declare(strict_types=1);
// Prueba del dominio de Inventario de precursores (solo CLI). Necesita una base DEDICADA a la prueba
// y el usuario de migraciones (crea el esquema). No toca la base real:
//   DB_INVENTARIO_NAME=sistema_inventario_prueba MIGRADOR_PASS=... php inventario_precursores/tests/dominio.php
// Los datos del menu central (modulo, permisos, roles) se crean dentro de una transaccion que se revierte.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
const ESQUEMA_MIGRANDO = true;
putenv('MIGRADOR_USER=' . (getenv('MIGRADOR_USER') ?: 'modulos_migrador'));
$nombreBase = (string) getenv('DB_INVENTARIO_NAME');
if (!preg_match('/prueba|test/i', $nombreBase)) {
    fwrite(STDERR, "Defina DB_INVENTARIO_NAME con una base de prueba (debe contener 'prueba' o 'test').\n");
    exit(2);
}
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/dominio.php';
require dirname(__DIR__) . '/includes/acciones.php';

function verificar(bool $ok, string $m): void
{
    if (!$ok) {
        throw new RuntimeException("FALLO: {$m}");
    }
    echo "OK: {$m}\n";
}
function rechaza(callable $fn, string $m, string $contiene = ''): void
{
    try {
        $fn();
    } catch (InvError $e) {
        verificar($contiene === '' || stripos($e->getMessage(), $contiene) !== false, $m . ' (' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException("FALLO: no se rechazo: {$m}");
}

$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$firma = static fn(string $p, array $extra = []): array => [$p . '_img' => $png, $p . '_ok' => '1'] + $extra;
$todos = array_keys(permisos_inventario_todos());
$admin = ['id' => 900001, 'nombre' => 'Administrador prueba', 'es_superadmin' => 1, 'permisos' => $todos, 'lab_id' => 0];
$labs = [];

// --- Cantidades
verificar(inv_parse_cantidad('12,5') === 12500 && inv_parse_cantidad('0') === null && inv_parse_cantidad('1.2345') === null && inv_parse_cantidad('-3') === null, 'Cantidades: coma decimal, cero, 4 decimales y negativos');
verificar(inv_fmt(12500) === '12.5' && inv_dec(1500) === '1.500' && inv_fmt(1234567) === '1,234.567', 'Formato de cantidades');
verificar(inv_estado_lote(0, 100) === 'AGOTADO' && inv_estado_lote(5, -1) === 'VENCIDO' && inv_estado_lote(5, 60) === 'PRÓXIMO A VENCER' && inv_estado_lote(5, 61) === 'ACTIVO', 'Estados de lote');
verificar(strpos(inv_csv_celda('=1+1'), "'=") !== false, 'CSV neutraliza formulas');

// --- Esquema y menu (transaccion revertida)
inv_esquema($pdo);
inv_esquema($pdo);
inv_sembrar_catalogo($pdo);
verificar((int) $pdo->query('SELECT COUNT(*) FROM laboratorios')->fetchColumn() === 6 && (int) $pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 14, 'Esquema idempotente y catalogo inicial (6 laboratorios, 14 productos)');
$menuPdo->beginTransaction();
try {
    inv_asegurar_menu($menuPdo);
    inv_asegurar_menu($menuPdo);
    $perm = (int) $menuPdo->query("SELECT COUNT(*) FROM permisos WHERE nombre_permiso LIKE 'inventario.%'")->fetchColumn();
    verificar($perm === count($todos), 'Permisos sembrados sin duplicar (' . $perm . ')');
    verificar((int) $menuPdo->query("SELECT COUNT(*) FROM modulos WHERE nombre = 'Inventario de precursores'")->fetchColumn() === 1, 'Modulo registrado una sola vez');
    verificar((int) $menuPdo->query("SELECT COUNT(*) FROM roles WHERE nombre_rol = 'Encargado de bodega'")->fetchColumn() === 1, 'Roles del modulo creados');
} finally {
    $menuPdo->rollBack();
}

$agr = (int) $pdo->query("SELECT id FROM laboratorios WHERE codigo = 'AGR'")->fetchColumn();
$bio = (int) $pdo->query("SELECT id FROM laboratorios WHERE codigo = 'BIO'")->fetchColumn();
$acetico = (int) $pdo->query("SELECT id FROM productos WHERE codigo = 'REC-002'")->fetchColumn();
$acetona = (int) $pdo->query("SELECT id FROM productos WHERE codigo = 'REC-001'")->fetchColumn();

// --- Entradas
$base = ['fecha_ingreso' => '2026-01-10', 'laboratorio_id' => $agr, 'producto_id' => $acetico, 'cantidad' => '10', 'caducidad' => '2027-12-31', 'proveedor' => 'Prov'];
$r = inv_entrada_registrar($pdo, $admin, $base);
inv_entrada_registrar($pdo, $admin, ['fecha_ingreso' => '2026-02-01', 'caducidad' => '2027-06-30', 'cantidad' => '5'] + $base);
$lotes = inv_lotes($pdo, ['laboratorio_id' => $agr]);
$nombres = array_column($lotes, 'lote');
sort($nombres);
verificar($nombres === ['LOT-AGR-001', 'LOT-AGR-002'], 'Lotes correlativos por laboratorio');
rechaza(fn() => inv_entrada_registrar($pdo, $admin, ['producto_id' => $acetona] + $base), 'Producto fuera de la matriz del laboratorio', 'matriz');
rechaza(fn() => inv_entrada_registrar($pdo, $admin, ['caducidad' => '2025-12-31'] + $base), 'Caducidad anterior al ingreso');
rechaza(fn() => inv_entrada_registrar($pdo, $admin, ['cantidad' => '0'] + $base), 'Cantidad cero');
rechaza(fn() => inv_entrada_registrar($pdo, ['permisos' => [], 'es_superadmin' => 0, 'id' => 1, 'nombre' => 'x', 'lab_id' => 0] , $base), 'Sin permiso no registra', 'permiso');

// --- Vales: crear, autorizar, despachar
$analista = ['id' => 900002, 'nombre' => 'Analista prueba', 'es_superadmin' => 0, 'lab_id' => $agr, 'permisos' => ['inventario.vales.crear']];
$jefe = ['id' => 900003, 'nombre' => 'Jefe prueba', 'es_superadmin' => 0, 'lab_id' => $agr, 'permisos' => ['inventario.vales.autorizar', 'inventario.vales.crear']];
$bodega = ['id' => 900004, 'nombre' => 'Bodega prueba', 'es_superadmin' => 0, 'lab_id' => 0, 'permisos' => ['inventario.vales.despachar', 'inventario.vales.ver_todos', 'inventario.vales.anular', 'inventario.entradas.corregir', 'inventario.entradas.anular', 'inventario.cierre.elaborar', 'inventario.cierre.revisar']];
$pedido = static fn(string $cant) => ['proyecto' => 'Titulacion', 'items' => [['producto_id' => $acetico, 'cantidad' => $cant, 'obs' => 'uso']]] + $firma('sol');
rechaza(fn() => inv_vale_crear($pdo, $menuPdo, $analista, $pedido('16')), 'Vale que excede la existencia libre', 'excede');
rechaza(fn() => inv_vale_crear($pdo, $menuPdo, $analista, ['items' => $pedido('1')['items']] + $firma('sol')), 'Vale sin proyecto');
rechaza(fn() => inv_vale_crear($pdo, $menuPdo, $analista, array_diff_key($pedido('1'), ['sol_ok' => 1])), 'Vale sin declaracion de firma', 'declaración');
rechaza(fn() => inv_vale_crear($pdo, $menuPdo, $analista, ['sol_img' => 'data:image/png;base64,AAAA'] + $pedido('1')), 'Firma que no es PNG valido', 'firma');
$v = inv_vale_crear($pdo, $menuPdo, $analista, $pedido('12'));
parse_str($v['ir'], $q);
$valeId = (int) $q['id'];
verificar(preg_match('/^VAL-\d{4}-0001$/', (string) $pdo->query("SELECT numero FROM vales WHERE id = {$valeId}")->fetchColumn()) === 1, 'Numero de vale correlativo');
rechaza(fn() => inv_vale_crear($pdo, $menuPdo, $analista, $pedido('4')), 'Lo comprometido reduce lo libre (15 - 12 = 3)', 'excede');
rechaza(fn() => inv_vale_autorizar($pdo, $analista, ['id' => $valeId] + $firma('aut')), 'Quien no tiene el permiso no autoriza');
$propio = inv_vale_crear($pdo, $menuPdo, $jefe, ['proyecto' => 'Propio', 'items' => [['producto_id' => $acetico, 'cantidad' => '1']]] + $firma('sol'));
parse_str($propio['ir'], $qp);
rechaza(fn() => inv_vale_autorizar($pdo, $jefe, ['id' => (int) $qp['id']] + $firma('aut')), 'Nadie autoriza su propio vale', 'propio');
inv_vale_autorizar($pdo, $jefe, ['id' => $valeId] + $firma('aut'));
verificar($pdo->query("SELECT estado FROM vales WHERE id = {$valeId}")->fetchColumn() === 'Autorizado', 'Vale autorizado');
rechaza(fn() => inv_vale_despachar($pdo, $analista, ['id' => $valeId, 'recibe' => 'X', 'entregar' => []] + $firma('ent') + $firma('rec')), 'Sin permiso de despacho', 'permiso');
$itemId = (int) $pdo->query("SELECT id FROM vale_items WHERE vale_id = {$valeId}")->fetchColumn();
rechaza(fn() => inv_vale_despachar($pdo, $bodega, ['id' => $valeId, 'recibe' => 'Mario', 'entregar' => [$itemId => '13']] + $firma('ent') + $firma('rec')), 'No entrega mas de lo solicitado', 'más de lo solicitado');
inv_vale_despachar($pdo, $bodega, ['id' => $valeId, 'recibe' => 'Mario', 'entregar' => [$itemId => '12']] + $firma('ent') + $firma('rec'));
$asign = $pdo->query("SELECT lote, cantidad FROM vale_item_lotes WHERE vale_item_id = {$itemId} ORDER BY id")->fetchAll();
verificar(count($asign) === 2 && $asign[0]['lote'] === 'LOT-AGR-002' && inv_milli($asign[0]['cantidad']) === 5000 && $asign[1]['lote'] === 'LOT-AGR-001' && inv_milli($asign[1]['cantidad']) === 7000, 'FEFO: primero el lote que vence antes (5 + 7)');
rechaza(fn() => inv_vale_despachar($pdo, $bodega, ['id' => $valeId, 'recibe' => 'Mario', 'entregar' => [$itemId => '1']] + $firma('ent') + $firma('rec')), 'Un vale entregado no se despacha dos veces', 'no está autorizado');
$bal = inv_balance($pdo, $agr)[array_search('REC-002', array_column(inv_balance($pdo, $agr), 'codigo'), true)];
verificar($bal['ing'] === 15000 && $bal['con'] === 12000 && $bal['disp'] === 3000 && $bal['comp'] === 1000 && $bal['libre'] === 2000, 'Balance: ingresado 15, consumido 12, disponible 3, comprometido 1, libre 2');
verificar(count(inv_salidas($pdo, ['laboratorio_id' => $agr])) === 2, 'Informe de salidas: una linea por lote');
rechaza(fn() => inv_vale_cerrar($pdo, $bodega, ['id' => $valeId, 'motivo' => 'ya entregado'], true), 'No se anula un vale entregado');

// --- Despacho parcial y rechazo / anulacion
$v2 = inv_vale_crear($pdo, $menuPdo, $analista, $pedido('2'));
parse_str($v2['ir'], $q2);
$id2 = (int) $q2['id'];
rechaza(fn() => inv_vale_cerrar($pdo, $jefe, ['id' => $id2, 'motivo' => 'x'], false), 'Motivo muy corto');
inv_vale_cerrar($pdo, $jefe, ['id' => $id2, 'motivo' => 'No corresponde al proyecto'], false);
verificar($pdo->query("SELECT estado FROM vales WHERE id = {$id2}")->fetchColumn() === 'Rechazado', 'Vale rechazado con motivo');

// --- Correccion y anulacion de entradas
$lote2 = (int) $pdo->query("SELECT id FROM entradas WHERE lote = 'LOT-AGR-002'")->fetchColumn();
$campos = ['fecha_ingreso' => '2026-02-01', 'laboratorio_id' => $agr, 'producto_id' => $acetico, 'cantidad' => '4', 'caducidad' => '2027-06-30', 'error_tipo' => 'Cantidad mal digitada', 'motivo' => 'Factura indica 4 L'] + $firma('cor');
rechaza(fn() => inv_entrada_corregir($pdo, $bodega, ['id' => $lote2] + $campos), 'No baja la cantidad por debajo de lo consumido', 'consumido');
rechaza(fn() => inv_entrada_corregir($pdo, $bodega, ['id' => $lote2, 'cantidad' => '5', 'laboratorio_id' => $bio] + $campos), 'Lote con consumo no cambia de laboratorio');
$lote1 = (int) $pdo->query("SELECT id FROM entradas WHERE lote = 'LOT-AGR-001'")->fetchColumn();
$sinConsumo = inv_entrada_registrar($pdo, $admin, ['cantidad' => '3', 'caducidad' => '2028-01-01'] + $base);
$idNueva = (int) $pdo->query('SELECT MAX(id) FROM entradas')->fetchColumn();
rechaza(fn() => inv_entrada_corregir($pdo, $bodega, ['id' => $idNueva, 'cantidad' => '3', 'laboratorio_id' => $agr, 'producto_id' => $acetico, 'caducidad' => '2028-01-01', 'fecha_ingreso' => '2026-01-10', 'proveedor' => 'Prov', 'error_tipo' => 'Otro', 'motivo' => 'sin cambios reales'] + $firma('cor')), 'Correccion sin cambios', 'cambios');
inv_entrada_corregir($pdo, $bodega, ['id' => $idNueva, 'cantidad' => '2.5', 'laboratorio_id' => $bio, 'producto_id' => $acetico, 'caducidad' => '2028-01-01', 'fecha_ingreso' => '2026-01-10', 'proveedor' => 'Prov', 'error_tipo' => 'Laboratorio propietario equivocado', 'motivo' => 'Era para Biotecnologia'] + $firma('cor'));
$e = $pdo->query("SELECT lote, laboratorio_id, cantidad FROM entradas WHERE id = {$idNueva}")->fetch();
verificar($e['lote'] === 'LOT-BIO-001' && (int) $e['laboratorio_id'] === $bio && inv_milli($e['cantidad']) === 2500, 'Cambio de laboratorio genera nuevo lote y conserva la cantidad corregida');
verificar(count(inv_entrada_historial($pdo, $idNueva)) === 1 && inv_entrada_historial($pdo, $idNueva)[0]['cambios'] !== [], 'Historial de la correccion con antes y despues');
rechaza(fn() => inv_entrada_anular($pdo, $bodega, ['id' => $lote1, 'error_tipo' => 'Otro', 'motivo' => 'intento de anular con consumo'] + $firma('anu')), 'Entrada con consumo no se anula', 'consumo');
inv_entrada_anular($pdo, $admin, ['id' => $idNueva, 'error_tipo' => 'Ingreso duplicado', 'motivo' => 'Duplicado de la factura'] + $firma('anu'));
verificar(count(array_filter(inv_lotes($pdo, ['laboratorio_id' => $bio]), static fn($l) => $l['id'] === $idNueva)) === 0, 'Entrada anulada deja de sumar al inventario');

// --- Cierre mensual
$cierre = inv_cierre_datos($pdo, 2026, 2, 0, true);
$fila = $cierre['filas'][array_search('REC-002', array_column($cierre['filas'], 'codigo'), true)];
verificar($fila['inicial'] === 10000 && $fila['ingresos'] === 5000 && $fila['egresos'] === 0 && $fila['final'] === 15000, 'Cierre febrero: inicial 10, ingresos 5, final 15');
$p = ['anio' => 2026, 'mes' => 2, 'laboratorio_id' => 0, 'solo_fiscalizados' => '1'];
rechaza(fn() => inv_cierre_firmar($pdo, $bodega, ['tipo' => 'rev'] + $p + $firma('cie')), 'Revisar antes de elaborar', 'Primero');
inv_cierre_firmar($pdo, $bodega, ['tipo' => 'elab'] + $p + $firma('cie'));
rechaza(fn() => inv_cierre_firmar($pdo, $bodega, ['tipo' => 'rev'] + $p + $firma('cie')), 'La misma persona no firma dos etapas', 'Separación');
$c = inv_cierre_obtener($pdo, 2026, 2, 0, true);
verificar($c !== null && isset($c['firmas']['elab']) && is_array($c['datos']) && count($c['datos']['filas']) === count($cierre['filas']), 'Cierre elaborado guarda foto de las cifras');
rechaza(fn() => inv_cierre_firmar($pdo, $analista, ['tipo' => 'apr'] + $p + $firma('cie')), 'Aprobar sin permiso', 'permiso');

// --- Laboratorios
rechaza(fn() => inv_lab_baja($pdo, $admin, ['id' => $agr, 'motivo' => 'prueba']), 'No se desactiva un laboratorio con existencia', 'No se puede desactivar');
inv_lab_guardar($pdo, $menuPdo, $admin, ['codigo' => 'ZZ', 'nombre' => 'Temporal']);
$zz = (int) $pdo->query("SELECT id FROM laboratorios WHERE codigo = 'ZZ'")->fetchColumn();
rechaza(fn() => inv_lab_eliminar($pdo, $admin, ['id' => $zz, 'confirmar_codigo' => 'XX']), 'Eliminar exige escribir el codigo', 'código');
inv_lab_eliminar($pdo, $admin, ['id' => $zz, 'confirmar_codigo' => 'zz']);
verificar((int) $pdo->query("SELECT COUNT(*) FROM laboratorios WHERE codigo = 'ZZ'")->fetchColumn() === 0, 'Laboratorio sin movimientos se elimina');
rechaza(fn() => inv_lab_guardar($pdo, $menuPdo, $admin, ['codigo' => 'AGR', 'nombre' => 'Duplicado']), 'Codigo de laboratorio duplicado', 'existe');
rechaza(fn() => inv_producto_guardar($pdo, $admin, ['tipo' => 'Reactivo', 'unidad' => 'L', 'nombre' => 'X', 'codigo' => 'REC-001']), 'Codigo de producto duplicado', 'existe');

echo "\nTodas las pruebas del dominio pasaron.\n";
