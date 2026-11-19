<?php
declare(strict_types=1);

// Operaciones que modifican datos: vales, entradas, catalogo, laboratorios, usuarios y cierre.
// Cada funcion valida permisos y datos en el servidor, trabaja dentro de una transaccion y
// devuelve ['mensaje' => ..., 'ir' => 'view=...'] o lanza InvError con un mensaje para el usuario.

const INV_ERRORES_ENTRADA = [
    'Cantidad mal digitada', 'Producto / código equivocado', 'Laboratorio propietario equivocado', 'Fecha de ingreso incorrecta',
    'Fecha de caducidad incorrecta', 'Datos de proveedor / factura / CoA', 'Lote del proveedor incorrecto', 'Otro',
];
const INV_ERRORES_ANULACION = ['Ingreso duplicado', 'Ingreso registrado por error', 'Devolución al proveedor', 'Otro'];
const INV_TIPOS_PRODUCTO = ['Precursor fiscalizado', 'Reactivo', 'Material de laboratorio', 'Consumible'];
const INV_UNIDADES = ['L', 'mL', 'Kg', 'g', 'Unidad', 'Caja', 'Frasco'];
const INV_PREFIJO_TIPO = ['Precursor fiscalizado' => 'REC', 'Reactivo' => 'REA', 'Material de laboratorio' => 'MAT', 'Consumible' => 'CON'];

/**
 * Ejecuta $fn dentro de una transaccion. Con $leerConfirmado la transaccion usa READ COMMITTED:
 * despues de bloquear filas (FOR UPDATE) las lecturas ven lo que otros ya confirmaron, para no
 * descontar dos veces el mismo lote.
 */
function inv_tx(PDO $pdo, callable $fn, bool $leerConfirmado = false)
{
    if ($leerConfirmado) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    $pdo->beginTransaction();
    try {
        $resultado = $fn();
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ---------------------------------------------------------------- Firmas

/**
 * Lee la firma enviada por el formulario con prefijo $prefijo: <p>_img (PNG dibujado, data URL),
 * <p>_ok (declaracion), <p>_nombre (solo cuando firma alguien que no es el usuario de la sesion)
 * y <p>_guardar (conservarla en el perfil). Devuelve ['nombre','imagen','guardar'].
 */
function inv_firma_leer(array $post, string $prefijo, string $nombreFijo = ''): array
{
    if (empty($post[$prefijo . '_ok'])) {
        throw new InvError('Confirme la declaración de la firma electrónica.');
    }
    $imagen = trim((string) ($post[$prefijo . '_img'] ?? ''));
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$#', $imagen, $m) || strlen($imagen) > 200000) {
        throw new InvError('Dibuje su firma en el recuadro antes de firmar.');
    }
    $binario = base64_decode($m[1], true);
    $info = $binario === false ? false : @getimagesizefromstring($binario);
    if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] > 2000 || $info[1] > 1000) {
        throw new InvError('La firma enviada no es una imagen válida.');
    }
    $nombre = $nombreFijo !== '' ? $nombreFijo : inv_texto($post[$prefijo . '_nombre'] ?? '', 160, true, 'El nombre del firmante');

    return ['nombre' => $nombre, 'imagen' => $imagen, 'guardar' => !empty($post[$prefijo . '_guardar'])];
}

/** Guarda la firma estampada y devuelve su id. $usuarioId = 0 cuando firma alguien sin cuenta (quien retira). */
function inv_firma_guardar(PDO $pdo, array $firma, int $usuarioId): int
{
    $pdo->prepare('INSERT INTO firmas (usuario_id, nombre, imagen) VALUES (?, ?, ?)')
        ->execute([$usuarioId > 0 ? $usuarioId : null, $firma['nombre'], $firma['imagen']]);
    $id = (int) $pdo->lastInsertId();
    if ($usuarioId > 0 && !empty($firma['guardar'])) {
        $pdo->prepare('INSERT INTO usuario_firma (usuario_id, imagen) VALUES (?, ?) ON DUPLICATE KEY UPDATE imagen = VALUES(imagen)')
            ->execute([$usuarioId, $firma['imagen']]);
    }
    return $id;
}

/** Firmas de un vale: [tipo => ['nombre','imagen','firmado_en']]. */
function inv_vale_firmas(PDO $pdo, int $valeId): array
{
    $stmt = $pdo->prepare('SELECT vf.tipo, f.nombre, f.imagen, f.firmado_en FROM vale_firmas vf JOIN firmas f ON f.id = vf.firma_id WHERE vf.vale_id = ?');
    $stmt->execute([$valeId]);
    $out = [];
    foreach ($stmt->fetchAll() as $f) {
        $out[$f['tipo']] = $f;
    }
    return $out;
}

function inv_bitacora(PDO $pdo, int $valeId, array $usuario, string $accion): void
{
    $pdo->prepare('INSERT INTO vale_bitacora (vale_id, usuario_id, usuario_nombre, accion) VALUES (?, ?, ?, ?)')
        ->execute([$valeId, $usuario['id'] ?: null, $usuario['nombre'], mb_substr($accion, 0, 500, 'UTF-8')]);
}

function inv_auditar(PDO $pdo, array $usuario, string $accion): void
{
    $pdo->prepare('INSERT INTO auditoria (usuario_id, usuario_nombre, accion) VALUES (?, ?, ?)')
        ->execute([$usuario['id'] ?: null, $usuario['nombre'], mb_substr($accion, 0, 500, 'UTF-8')]);
}

/** Siguiente valor de un correlativo, atomico y sin huecos si la transaccion se revierte. */
function inv_siguiente(PDO $pdo, string $clave): int
{
    $pdo->prepare('INSERT INTO secuencias (clave, ultimo) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)')
        ->execute([$clave]);
    return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

function inv_nuevo_lote(PDO $pdo, array $laboratorio): string
{
    return sprintf('LOT-%s-%03d', $laboratorio['codigo'], inv_siguiente($pdo, 'lote-' . $laboratorio['id']));
}

// ---------------------------------------------------------------- Vales de salida

/** Crea el vale, lo firma el solicitante y queda Pendiente de visto bueno. Devuelve el id del vale. */
function inv_vale_crear(PDO $pdo, PDO $menuPdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.vales.crear')) {
        throw new InvError('No tiene permiso para crear vales.');
    }
    $alcance = inv_alcance_lab($u);
    if ($alcance === -1) {
        throw new InvError('Su usuario no tiene un laboratorio asignado. Solicítelo al administrador.');
    }
    $labId = $alcance > 0 ? $alcance : (int) ($post['laboratorio_id'] ?? 0);
    $lab = inv_laboratorio($pdo, $labId);
    if (!$lab || (int) $lab['activo'] !== 1) {
        throw new InvError('Seleccione un laboratorio activo.');
    }
    $proyecto = inv_texto($post['proyecto'] ?? '', 200, true, 'El proyecto o ensayo');

    $matriz = [];
    foreach (inv_productos_de_lab($pdo, $labId) as $p) {
        $matriz[(int) $p['id']] = $p;
    }
    $items = [];
    foreach ((array) ($post['items'] ?? []) as $fila) {
        $productoId = (int) ($fila['producto_id'] ?? 0);
        $textoCant = trim((string) ($fila['cantidad'] ?? ''));
        if ($productoId === 0 && $textoCant === '') {
            continue;
        }
        if (!isset($matriz[$productoId])) {
            throw new InvError('Un producto del vale no está autorizado para el laboratorio ' . $lab['nombre'] . '.');
        }
        if (isset($items[$productoId])) {
            throw new InvError('El producto ' . $matriz[$productoId]['nombre'] . ' está repetido; agregue una sola línea por producto.');
        }
        $cantidad = inv_parse_cantidad($textoCant);
        if ($cantidad === null) {
            throw new InvError('Cantidad inválida para ' . $matriz[$productoId]['nombre'] . ' (use números positivos, hasta 3 decimales).');
        }
        $items[$productoId] = ['cantidad' => $cantidad, 'obs' => inv_texto($fila['obs'] ?? '', 255, false, 'La observación')];
    }
    if (!$items) {
        throw new InvError('Agregue al menos un reactivo con cantidad.');
    }
    $firma = inv_firma_leer($post, 'sol', $u['nombre']);

    return inv_tx($pdo, static function () use ($pdo, $menuPdo, $u, $lab, $labId, $proyecto, $items, $matriz, $firma): array {
        // Serializa los vales nuevos de un mismo laboratorio para que no se comprometa dos veces la misma existencia.
        $pdo->prepare('SELECT id FROM laboratorios WHERE id = ? FOR UPDATE')->execute([$labId]);
        $lotes = inv_lotes($pdo, ['laboratorio_id' => $labId]);
        $disp = inv_disponible_por_producto($lotes, $labId);
        $comp = inv_comprometido($pdo, $labId);
        foreach ($items as $productoId => $it) {
            $libre = ($disp[$productoId] ?? 0) - ($comp[$productoId] ?? 0);
            if ($it['cantidad'] > $libre) {
                throw new InvError($matriz[$productoId]['nombre'] . ': la cantidad excede la existencia libre (' . inv_fmt(max(0, $libre)) . ' ' . $matriz[$productoId]['unidad'] . ').');
            }
        }
        $anio = (int) date('Y');
        $correlativo = inv_siguiente($pdo, 'vale-' . $anio);
        $numero = sprintf('VAL-%d-%04d', $anio, $correlativo);
        $jefe = inv_nombre_usuario($menuPdo, (int) $lab['jefe_usuario_id']);
        $pdo->prepare('INSERT INTO vales (numero, anio, correlativo, fecha, laboratorio_id, solicitante_id, solicitante, proyecto, jefe, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$numero, $anio, $correlativo, inv_hoy(), $labId, $u['id'], $u['nombre'], $proyecto, $jefe, 'Pendiente']);
        $valeId = (int) $pdo->lastInsertId();
        $insertar = $pdo->prepare('INSERT INTO vale_items (vale_id, producto_id, cantidad, observaciones) VALUES (?, ?, ?, ?)');
        foreach ($items as $productoId => $it) {
            $insertar->execute([$valeId, $productoId, inv_dec($it['cantidad']), $it['obs'] !== '' ? $it['obs'] : null]);
        }
        $firmaId = inv_firma_guardar($pdo, $firma, $u['id']);
        $pdo->prepare('INSERT INTO vale_firmas (vale_id, tipo, firma_id) VALUES (?, ?, ?)')->execute([$valeId, 'sol', $firmaId]);
        inv_bitacora($pdo, $valeId, $u, 'Vale creado y firmado por solicitante');
        return ['mensaje' => "Vale {$numero} enviado a visto bueno.", 'ir' => 'view=vale&id=' . $valeId];
    });
}

/** Vale bloqueado para modificar (FOR UPDATE). */
function inv_vale_bloquear(PDO $pdo, int $valeId): array
{
    $stmt = $pdo->prepare('SELECT * FROM vales WHERE id = ? FOR UPDATE');
    $stmt->execute([$valeId]);
    $vale = $stmt->fetch();
    if (!$vale) {
        throw new InvError('El vale no existe.');
    }
    $vale['laboratorio_id'] = (int) $vale['laboratorio_id'];
    return $vale;
}

function inv_vale_autorizar(PDO $pdo, array $u, array $post): array
{
    $valeId = (int) ($post['id'] ?? 0);
    $firma = inv_firma_leer($post, 'aut', $u['nombre']);
    return inv_tx($pdo, static function () use ($pdo, $u, $valeId, $firma): array {
        $vale = inv_vale_bloquear($pdo, $valeId);
        if ($vale['estado'] !== 'Pendiente') {
            throw new InvError('El vale ya no está pendiente de visto bueno.');
        }
        if (!inv_puede_autorizar_vale($u, $vale)) {
            throw new InvError('No puede dar el visto bueno a este vale (permiso, laboratorio o vale propio).');
        }
        $firmaId = inv_firma_guardar($pdo, $firma, $u['id']);
        $pdo->prepare('INSERT INTO vale_firmas (vale_id, tipo, firma_id) VALUES (?, ?, ?)')->execute([$valeId, 'aut', $firmaId]);
        $pdo->prepare("UPDATE vales SET estado = 'Autorizado' WHERE id = ?")->execute([$valeId]);
        inv_bitacora($pdo, $valeId, $u, 'Visto bueno / autorizado');
        return ['mensaje' => 'Vale autorizado; queda listo para despacho en Bodega Central.', 'ir' => 'view=vale&id=' . $valeId];
    });
}

/** Rechaza (Pendiente, jefe) o anula (Autorizado, bodega) un vale con motivo. */
function inv_vale_cerrar(PDO $pdo, array $u, array $post, bool $anular): array
{
    $valeId = (int) ($post['id'] ?? 0);
    $motivo = inv_texto($post['motivo'] ?? '', 500, true, 'El motivo');
    if (mb_strlen($motivo, 'UTF-8') < 5) {
        throw new InvError('Describa el motivo (mínimo 5 caracteres).');
    }
    return inv_tx($pdo, static function () use ($pdo, $u, $valeId, $motivo, $anular): array {
        $vale = inv_vale_bloquear($pdo, $valeId);
        if ($anular) {
            if (!inv_puede($u, 'inventario.vales.anular') || $vale['estado'] !== 'Autorizado') {
                throw new InvError('Solo se puede anular un vale autorizado, y con el permiso correspondiente.');
            }
        } elseif (!inv_puede_autorizar_vale($u, $vale)) {
            throw new InvError('No puede rechazar este vale (permiso, laboratorio, vale propio o ya no está pendiente).');
        }
        $estado = $anular ? 'Anulado' : 'Rechazado';
        $pdo->prepare('UPDATE vales SET estado = ?, motivo = ? WHERE id = ?')->execute([$estado, $motivo, $valeId]);
        inv_bitacora($pdo, $valeId, $u, ($anular ? 'Anulado: ' : 'Rechazado: ') . $motivo);
        return ['mensaje' => 'Vale ' . ($anular ? 'anulado' : 'rechazado') . '.', 'ir' => 'view=vale&id=' . $valeId];
    });
}

/**
 * Despacha un vale autorizado: asigna lotes por FEFO contra el inventario del laboratorio,
 * registra las firmas "Entregado por" y "Recibido conforme" y descuenta el inventario.
 * $post['entregar'][item_id] = cantidad a entregar (0 hasta la solicitada; menos = entrega parcial).
 */
function inv_vale_despachar(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.vales.despachar')) {
        throw new InvError('No tiene permiso para despachar vales.');
    }
    $valeId = (int) ($post['id'] ?? 0);
    $recibe = inv_texto($post['recibe'] ?? '', 160, true, 'La persona que retira');
    $firmaEnt = inv_firma_leer($post, 'ent', $u['nombre']);
    $firmaRec = inv_firma_leer($post, 'rec', $recibe);
    $entregar = (array) ($post['entregar'] ?? []);

    return inv_tx($pdo, static function () use ($pdo, $u, $valeId, $recibe, $firmaEnt, $firmaRec, $entregar): array {
        $vale = inv_vale_bloquear($pdo, $valeId);
        if ($vale['estado'] !== 'Autorizado') {
            throw new InvError('El vale no está autorizado o ya fue despachado.');
        }
        $labId = $vale['laboratorio_id'];
        $stmt = $pdo->prepare(
            'SELECT vi.id, vi.producto_id, vi.cantidad, p.codigo, p.nombre, p.unidad
             FROM vale_items vi JOIN productos p ON p.id = vi.producto_id WHERE vi.vale_id = ? ORDER BY vi.id'
        );
        $stmt->execute([$valeId]);
        $items = $stmt->fetchAll();

        // Bloquea los lotes del laboratorio para estos productos y recalcula con lo ya confirmado.
        $productos = array_map(static fn($i) => (int) $i['producto_id'], $items);
        $marcas = implode(',', array_fill(0, count($productos), '?'));
        $bloqueo = $pdo->prepare("SELECT id FROM entradas WHERE laboratorio_id = ? AND producto_id IN ($marcas) ORDER BY id FOR UPDATE");
        $bloqueo->execute(array_merge([$labId], $productos));
        $lotes = inv_lotes($pdo, ['laboratorio_id' => $labId]);

        $plan = [];
        $total = 0;
        foreach ($items as $it) {
            $solicitado = inv_milli($it['cantidad']);
            $texto = $entregar[$it['id']] ?? '';
            $cantidad = inv_parse_cantidad_cero($texto);
            if ($cantidad === null) {
                throw new InvError('Cantidad a entregar inválida para ' . $it['nombre'] . '.');
            }
            if ($cantidad > $solicitado) {
                throw new InvError($it['nombre'] . ': no puede entregar más de lo solicitado (' . inv_fmt($solicitado) . ' ' . $it['unidad'] . ').');
            }
            $fefo = inv_fefo($lotes, $labId, (int) $it['producto_id'], $cantidad);
            if ($fefo['falta'] > 0) {
                throw new InvError($it['nombre'] . ': la existencia disponible no alcanza para entregar ' . inv_fmt($cantidad) . ' ' . $it['unidad'] . '. Reduzca la cantidad.');
            }
            $plan[] = ['item' => $it, 'cantidad' => $cantidad, 'asign' => $fefo['asign']];
            $total += $cantidad;
        }
        if ($total <= 0) {
            throw new InvError('No hay cantidades a entregar.');
        }

        $actualizar = $pdo->prepare('UPDATE vale_items SET entregado = ? WHERE id = ?');
        $asignar = $pdo->prepare('INSERT INTO vale_item_lotes (vale_item_id, entrada_id, lote, cantidad) VALUES (?, ?, ?, ?)');
        $parcial = false;
        $lotesUsados = [];
        foreach ($plan as $p) {
            $actualizar->execute([inv_dec($p['cantidad']), $p['item']['id']]);
            if ($p['cantidad'] < inv_milli($p['item']['cantidad'])) {
                $parcial = true;
            }
            foreach ($p['asign'] as $a) {
                $asignar->execute([$p['item']['id'], $a['entrada_id'], $a['lote'], inv_dec($a['cantidad'])]);
                $lotesUsados[$a['lote']] = true;
            }
        }
        $idEnt = inv_firma_guardar($pdo, $firmaEnt, $u['id']);
        $idRec = inv_firma_guardar($pdo, $firmaRec, 0);
        $firmar = $pdo->prepare('INSERT INTO vale_firmas (vale_id, tipo, firma_id) VALUES (?, ?, ?)');
        $firmar->execute([$valeId, 'ent', $idEnt]);
        $firmar->execute([$valeId, 'rec', $idRec]);
        $pdo->prepare("UPDATE vales SET estado = 'Entregado', fecha_entrega = ?, recibido_por = ? WHERE id = ?")
            ->execute([inv_hoy(), $recibe, $valeId]);
        inv_bitacora($pdo, $valeId, $u, 'Despachado' . ($parcial ? ' (entrega parcial)' : '') . '; inventario descontado de ' . implode(', ', array_keys($lotesUsados)));
        inv_bitacora($pdo, $valeId, ['id' => 0, 'nombre' => $recibe], 'Recibido conforme');
        return ['mensaje' => 'Despacho registrado. Inventario actualizado.', 'ir' => 'view=vale&id=' . $valeId];
    }, true);
}

// ---------------------------------------------------------------- Entradas

function inv_entrada_campos(PDO $pdo, array $post, bool $conFecha = true): array
{
    $fecha = inv_fecha_valida($post['fecha_ingreso'] ?? '');
    if ($fecha === null) {
        throw new InvError('Indique una fecha de ingreso válida.');
    }
    if ($fecha > inv_hoy()) {
        throw new InvError('La fecha de ingreso no puede ser futura.');
    }
    $cad = inv_fecha_valida($post['caducidad'] ?? '');
    if ($cad === null) {
        throw new InvError('Indique la fecha de caducidad.');
    }
    if ($cad < $fecha) {
        throw new InvError('La caducidad no puede ser anterior al ingreso.');
    }
    $cantidad = inv_parse_cantidad($post['cantidad'] ?? '');
    if ($cantidad === null) {
        throw new InvError('Cantidad inválida (use un número mayor que cero, hasta 3 decimales).');
    }
    return [
        'fecha_ingreso' => $fecha,
        'caducidad' => $cad,
        'cantidad' => $cantidad,
        'proveedor' => inv_texto($post['proveedor'] ?? '', 160, false, 'El proveedor'),
        'factura' => inv_texto($post['factura'] ?? '', 80, false, 'La factura / remisión'),
        'lote_proveedor' => inv_texto($post['lote_proveedor'] ?? '', 80, false, 'El lote del proveedor'),
        'coa' => inv_texto($post['coa'] ?? '', 80, false, 'El certificado (CoA)'),
    ];
}

/** Verifica que el producto exista, este activo y este autorizado para el laboratorio (matriz). */
function inv_validar_producto_en_lab(PDO $pdo, int $productoId, int $labId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.codigo, p.nombre, p.unidad FROM productos p JOIN producto_laboratorio pl ON pl.producto_id = p.id AND pl.laboratorio_id = ?
         WHERE p.id = ? AND p.activo = 1'
    );
    $stmt->execute([$labId, $productoId]);
    $p = $stmt->fetch();
    if (!$p) {
        throw new InvError('El producto no está activo o no está autorizado para ese laboratorio en la matriz.');
    }
    return $p;
}

function inv_entrada_registrar(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.entradas.registrar')) {
        throw new InvError('No tiene permiso para registrar entradas.');
    }
    $lab = inv_laboratorio($pdo, (int) ($post['laboratorio_id'] ?? 0));
    if (!$lab || (int) $lab['activo'] !== 1) {
        throw new InvError('Seleccione un laboratorio activo.');
    }
    $producto = inv_validar_producto_en_lab($pdo, (int) ($post['producto_id'] ?? 0), (int) $lab['id']);
    $campos = inv_entrada_campos($pdo, $post);

    return inv_tx($pdo, static function () use ($pdo, $u, $lab, $producto, $campos): array {
        $lote = inv_nuevo_lote($pdo, $lab);
        $pdo->prepare(
            'INSERT INTO entradas (fecha_ingreso, laboratorio_id, producto_id, lote, proveedor, factura, lote_proveedor, coa, caducidad, cantidad, recibido_por_id, recibido_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $campos['fecha_ingreso'], $lab['id'], $producto['id'], $lote, $campos['proveedor'] ?: null, $campos['factura'] ?: null,
            $campos['lote_proveedor'] ?: null, $campos['coa'] ?: null, $campos['caducidad'], inv_dec($campos['cantidad']), $u['id'], $u['nombre'],
        ]);
        inv_auditar($pdo, $u, "Registró la entrada ING-" . sprintf('%03d', (int) $pdo->lastInsertId()) . " ({$lote}: " . inv_fmt($campos['cantidad']) . " {$producto['unidad']} de {$producto['nombre']})");
        return ['mensaje' => "Ingreso registrado · {$lote}", 'ir' => 'view=entradas'];
    });
}

/** Entrada con consumo, bloqueada para modificar. */
function inv_entrada_bloquear(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM entradas WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $e = $stmt->fetch();
    if (!$e) {
        throw new InvError('La entrada no existe.');
    }
    $consumo = $pdo->prepare(
        "SELECT COALESCE(SUM(vil.cantidad), 0) FROM vale_item_lotes vil JOIN vale_items vi ON vi.id = vil.vale_item_id JOIN vales v ON v.id = vi.vale_id
         WHERE vil.entrada_id = ? AND v.estado = 'Entregado'"
    );
    $consumo->execute([$id]);
    $e['consumido'] = inv_milli($consumo->fetchColumn());
    return $e;
}

function inv_entrada_corregir(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.entradas.corregir')) {
        throw new InvError('No tiene permiso para corregir entradas.');
    }
    $id = (int) ($post['id'] ?? 0);
    $tipoError = (string) ($post['error_tipo'] ?? '');
    if (!in_array($tipoError, INV_ERRORES_ENTRADA, true)) {
        throw new InvError('Seleccione el tipo de error.');
    }
    $motivo = inv_texto($post['motivo'] ?? '', 1000, true, 'La justificación');
    if (mb_strlen($motivo, 'UTF-8') < 10) {
        throw new InvError('Describa el error y la justificación (mínimo 10 caracteres).');
    }
    $documento = inv_texto($post['documento'] ?? '', 160, false, 'El documento de soporte');
    $campos = inv_entrada_campos($pdo, $post);
    $nuevoLab = (int) ($post['laboratorio_id'] ?? 0);
    $nuevoProducto = (int) ($post['producto_id'] ?? 0);
    $firma = inv_firma_leer($post, 'cor', $u['nombre']);

    return inv_tx($pdo, static function () use ($pdo, $u, $id, $tipoError, $motivo, $documento, $campos, $nuevoLab, $nuevoProducto, $firma): array {
        $e = inv_entrada_bloquear($pdo, $id);
        if ((int) $e['anulada'] === 1) {
            throw new InvError('La entrada está anulada y no se puede corregir.');
        }
        $consumido = $e['consumido'];
        $cambiaLabProducto = $nuevoLab !== (int) $e['laboratorio_id'] || $nuevoProducto !== (int) $e['producto_id'];
        if ($consumido > 0 && $cambiaLabProducto) {
            throw new InvError('El lote ya tiene consumo: no se puede cambiar el laboratorio ni el producto.');
        }
        if ($campos['cantidad'] < $consumido) {
            throw new InvError('La cantidad no puede ser menor a lo consumido (' . inv_fmt($consumido) . ').');
        }
        $lab = inv_laboratorio($pdo, $nuevoLab);
        if (!$lab || ((int) $lab['activo'] !== 1 && $nuevoLab !== (int) $e['laboratorio_id'])) {
            throw new InvError('Seleccione un laboratorio activo.');
        }
        if ($cambiaLabProducto) {
            $producto = inv_validar_producto_en_lab($pdo, $nuevoProducto, $nuevoLab);
        } else {
            $stmt = $pdo->prepare('SELECT id, codigo, nombre, unidad FROM productos WHERE id = ?');
            $stmt->execute([$nuevoProducto]);
            $producto = $stmt->fetch();
        }
        $anterior = $pdo->prepare('SELECT l.nombre AS lab, CONCAT(p.codigo, \' · \', p.nombre) AS producto FROM laboratorios l, productos p WHERE l.id = ? AND p.id = ?');
        $anterior->execute([$e['laboratorio_id'], $e['producto_id']]);
        $antes = $anterior->fetch();

        $cambios = [];
        $comparar = static function (string $campo, string $antes, string $despues) use (&$cambios): void {
            if ($antes !== $despues) {
                $cambios[] = ['campo' => $campo, 'antes' => $antes, 'despues' => $despues];
            }
        };
        $comparar('Fecha de ingreso', (string) $e['fecha_ingreso'], $campos['fecha_ingreso']);
        $comparar('Laboratorio', (string) $antes['lab'], (string) $lab['nombre']);
        $comparar('Producto', (string) $antes['producto'], $producto['codigo'] . ' · ' . $producto['nombre']);
        $comparar('Cantidad', inv_fmt(inv_milli($e['cantidad'])), inv_fmt($campos['cantidad']));
        $comparar('Caducidad', (string) $e['caducidad'], $campos['caducidad']);
        $comparar('Proveedor', (string) $e['proveedor'], $campos['proveedor']);
        $comparar('Factura / remisión', (string) $e['factura'], $campos['factura']);
        $comparar('Lote proveedor', (string) $e['lote_proveedor'], $campos['lote_proveedor']);
        $comparar('Certificado (CoA)', (string) $e['coa'], $campos['coa']);
        if (!$cambios) {
            throw new InvError('No hay cambios que guardar.');
        }
        $lote = (string) $e['lote'];
        if ($nuevoLab !== (int) $e['laboratorio_id']) {
            $lote = inv_nuevo_lote($pdo, $lab);
            $cambios[] = ['campo' => 'ID lote', 'antes' => (string) $e['lote'], 'despues' => $lote];
        }
        $pdo->prepare(
            'UPDATE entradas SET fecha_ingreso = ?, laboratorio_id = ?, producto_id = ?, lote = ?, proveedor = ?, factura = ?, lote_proveedor = ?, coa = ?, caducidad = ?, cantidad = ? WHERE id = ?'
        )->execute([
            $campos['fecha_ingreso'], $nuevoLab, $nuevoProducto, $lote, $campos['proveedor'] ?: null, $campos['factura'] ?: null,
            $campos['lote_proveedor'] ?: null, $campos['coa'] ?: null, $campos['caducidad'], inv_dec($campos['cantidad']), $id,
        ]);
        if ($lote !== (string) $e['lote']) {
            // El lote cambio de laboratorio (no tiene consumo): los vales no lo referencian todavia.
            $pdo->prepare('UPDATE vale_item_lotes SET lote = ? WHERE entrada_id = ?')->execute([$lote, $id]);
        }
        $firmaId = inv_firma_guardar($pdo, $firma, $u['id']);
        $pdo->prepare(
            "INSERT INTO entrada_historial (entrada_id, tipo, error_tipo, motivo, documento, cambios, usuario_id, usuario_nombre, firma_id) VALUES (?, 'correccion', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$id, $tipoError, $motivo, $documento ?: null, json_encode($cambios, JSON_UNESCAPED_UNICODE), $u['id'], $u['nombre'], $firmaId]);
        inv_auditar($pdo, $u, sprintf('Corrigió la entrada ING-%03d (%s): %s', $id, $tipoError, implode(', ', array_column($cambios, 'campo'))));
        return ['mensaje' => sprintf('Entrada ING-%03d corregida.', $id), 'ir' => 'view=entradas'];
    }, true);
}

function inv_entrada_anular(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.entradas.anular')) {
        throw new InvError('No tiene permiso para anular entradas.');
    }
    $id = (int) ($post['id'] ?? 0);
    $tipoError = (string) ($post['error_tipo'] ?? '');
    if (!in_array($tipoError, INV_ERRORES_ANULACION, true)) {
        throw new InvError('Seleccione el tipo de error.');
    }
    $motivo = inv_texto($post['motivo'] ?? '', 1000, true, 'La justificación');
    if (mb_strlen($motivo, 'UTF-8') < 10) {
        throw new InvError('Escriba la justificación (mínimo 10 caracteres).');
    }
    $firma = inv_firma_leer($post, 'anu', $u['nombre']);

    return inv_tx($pdo, static function () use ($pdo, $u, $id, $tipoError, $motivo, $firma): array {
        $e = inv_entrada_bloquear($pdo, $id);
        if ((int) $e['anulada'] === 1) {
            throw new InvError('La entrada ya está anulada.');
        }
        if ($e['consumido'] > 0) {
            throw new InvError('El lote tiene consumo; use «Corregir» en lugar de anular.');
        }
        $pdo->prepare('UPDATE entradas SET anulada = 1 WHERE id = ?')->execute([$id]);
        $firmaId = inv_firma_guardar($pdo, $firma, $u['id']);
        $pdo->prepare(
            "INSERT INTO entrada_historial (entrada_id, tipo, error_tipo, motivo, cambios, usuario_id, usuario_nombre, firma_id) VALUES (?, 'anulacion', ?, ?, '[]', ?, ?, ?)"
        )->execute([$id, $tipoError, $motivo, $u['id'], $u['nombre'], $firmaId]);
        inv_auditar($pdo, $u, sprintf('Anuló la entrada ING-%03d (%s)', $id, $tipoError));
        return ['mensaje' => sprintf('Entrada ING-%03d anulada.', $id), 'ir' => 'view=entradas'];
    }, true);
}

function inv_entrada_historial(PDO $pdo, int $entradaId): array
{
    $stmt = $pdo->prepare(
        'SELECT h.*, f.imagen AS firma_imagen FROM entrada_historial h LEFT JOIN firmas f ON f.id = h.firma_id WHERE h.entrada_id = ? ORDER BY h.id DESC'
    );
    $stmt->execute([$entradaId]);
    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['cambios'] = json_decode((string) $f['cambios'], true) ?: [];
    }
    return $filas;
}

// ---------------------------------------------------------------- Catalogo y matriz

function inv_producto_guardar(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.catalogo.gestionar')) {
        throw new InvError('No tiene permiso para administrar el catálogo.');
    }
    $id = (int) ($post['id'] ?? 0);
    $tipo = (string) ($post['tipo'] ?? '');
    $unidad = (string) ($post['unidad'] ?? '');
    if (!in_array($tipo, INV_TIPOS_PRODUCTO, true)) {
        throw new InvError('Seleccione el tipo de producto.');
    }
    if (!in_array($unidad, INV_UNIDADES, true)) {
        throw new InvError('Seleccione la unidad de medida.');
    }
    $nombre = inv_texto($post['nombre'] ?? '', 160, true, 'El nombre');
    $concentracion = inv_texto($post['concentracion'] ?? '', 80, false, 'La concentración');
    $cas = inv_texto($post['cas'] ?? '', 40, false, 'El número CAS');
    $minimo = inv_parse_cantidad_cero($post['minimo'] ?? '');
    if ($minimo === null) {
        throw new InvError('Stock mínimo inválido.');
    }
    $activo = !empty($post['activo']) ? 1 : 0;
    $labs = array_values(array_unique(array_map('intval', (array) ($post['labs'] ?? []))));
    $codigoNuevo = strtoupper(trim((string) ($post['codigo'] ?? '')));

    return inv_tx($pdo, static function () use ($pdo, $u, $id, $tipo, $unidad, $nombre, $concentracion, $cas, $minimo, $activo, $labs, $codigoNuevo): array {
        if ($id > 0) {
            $existe = $pdo->prepare('SELECT codigo FROM productos WHERE id = ? FOR UPDATE');
            $existe->execute([$id]);
            $codigo = (string) $existe->fetchColumn();
            if ($codigo === '') {
                throw new InvError('El producto no existe.');
            }
            $pdo->prepare('UPDATE productos SET nombre = ?, tipo = ?, concentracion = ?, cas = ?, unidad = ?, minimo = ?, activo = ? WHERE id = ?')
                ->execute([$nombre, $tipo, $concentracion ?: null, $cas ?: null, $unidad, inv_dec($minimo), $activo, $id]);
        } else {
            $codigo = $codigoNuevo;
            if (!preg_match('/^[A-Z]{3}-\d{3,4}$/', $codigo)) {
                throw new InvError('El código debe tener el formato REC-001 (tres letras, guion y número).');
            }
            $dup = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE codigo = ?');
            $dup->execute([$codigo]);
            if ((int) $dup->fetchColumn() > 0) {
                throw new InvError('El código ya existe.');
            }
            $pdo->prepare('INSERT INTO productos (codigo, nombre, tipo, concentracion, cas, unidad, minimo, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$codigo, $nombre, $tipo, $concentracion ?: null, $cas ?: null, $unidad, inv_dec($minimo), $activo]);
            $id = (int) $pdo->lastInsertId();
        }
        // Matriz: solo laboratorios existentes. Quitar un laboratorio con existencia exige confirmarlo en el formulario de la matriz.
        $validos = array_map('intval', $pdo->query('SELECT id FROM laboratorios WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN));
        $actuales = $pdo->prepare('SELECT laboratorio_id FROM producto_laboratorio WHERE producto_id = ?');
        $actuales->execute([$id]);
        $actuales = array_map('intval', $actuales->fetchAll(PDO::FETCH_COLUMN));
        $conservar = array_values(array_unique(array_merge(array_intersect($labs, $validos), array_diff($actuales, $validos) ?: [])));
        foreach (array_diff($actuales, $conservar) as $quitar) {
            foreach (inv_lotes($pdo, ['laboratorio_id' => $quitar, 'producto_id' => $id]) as $lote) {
                if ($lote['disp'] > 0) {
                    throw new InvError('Un laboratorio que se quita tiene existencia de este producto; quítelo desde la matriz para confirmarlo.');
                }
            }
            $pdo->prepare('DELETE FROM producto_laboratorio WHERE producto_id = ? AND laboratorio_id = ?')->execute([$id, $quitar]);
        }
        foreach (array_diff($conservar, $actuales) as $agregar) {
            $pdo->prepare('INSERT IGNORE INTO producto_laboratorio (producto_id, laboratorio_id) VALUES (?, ?)')->execute([$id, $agregar]);
        }
        inv_auditar($pdo, $u, "Guardó el producto {$codigo} · {$nombre}");
        return ['mensaje' => 'Producto guardado.', 'ir' => 'view=catalogo'];
    });
}

/** Asigna o quita un producto a un laboratorio (casilla de la matriz). */
function inv_matriz_alternar(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.catalogo.gestionar')) {
        throw new InvError('No tiene permiso para administrar el catálogo.');
    }
    $productoId = (int) ($post['producto_id'] ?? 0);
    $labId = (int) ($post['laboratorio_id'] ?? 0);
    $lab = inv_laboratorio($pdo, $labId);
    $prod = $pdo->prepare('SELECT codigo, nombre FROM productos WHERE id = ?');
    $prod->execute([$productoId]);
    $producto = $prod->fetch();
    if (!$lab || !$producto) {
        throw new InvError('Producto o laboratorio no válido.');
    }
    return inv_tx($pdo, static function () use ($pdo, $u, $productoId, $labId, $lab, $producto, $post): array {
        $actual = $pdo->prepare('SELECT COUNT(*) FROM producto_laboratorio WHERE producto_id = ? AND laboratorio_id = ?');
        $actual->execute([$productoId, $labId]);
        if ((int) $actual->fetchColumn() > 0) {
            $saldo = 0;
            foreach (inv_lotes($pdo, ['laboratorio_id' => $labId, 'producto_id' => $productoId]) as $l) {
                $saldo += max(0, $l['disp']);
            }
            if ($saldo > 0 && empty($post['confirmado'])) {
                throw new InvError('El laboratorio tiene existencia de este producto; confirme para quitar la asignación.');
            }
            $pdo->prepare('DELETE FROM producto_laboratorio WHERE producto_id = ? AND laboratorio_id = ?')->execute([$productoId, $labId]);
            inv_auditar($pdo, $u, "Quitó {$producto['codigo']} de la matriz del laboratorio {$lab['nombre']}");
        } else {
            if ((int) $lab['activo'] !== 1) {
                throw new InvError('El laboratorio está inactivo.');
            }
            $pdo->prepare('INSERT IGNORE INTO producto_laboratorio (producto_id, laboratorio_id) VALUES (?, ?)')->execute([$productoId, $labId]);
            inv_auditar($pdo, $u, "Asignó {$producto['codigo']} a la matriz del laboratorio {$lab['nombre']}");
        }
        return ['mensaje' => 'Matriz actualizada.', 'ir' => 'view=catalogo'];
    });
}

// ---------------------------------------------------------------- Laboratorios y usuarios

function inv_lab_guardar(PDO $pdo, PDO $menuPdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.laboratorios.gestionar')) {
        throw new InvError('No tiene permiso para administrar laboratorios.');
    }
    $id = (int) ($post['id'] ?? 0);
    $nombre = inv_texto($post['nombre'] ?? '', 120, true, 'El nombre');
    $jefeId = (int) ($post['jefe_usuario_id'] ?? 0);
    if ($jefeId > 0 && !isset(inv_usuarios_con_permiso($menuPdo, 'inventario.vales.autorizar')[$jefeId])) {
        throw new InvError('El jefe debe ser un usuario del módulo con el permiso de visto bueno.');
    }
    return inv_tx($pdo, static function () use ($pdo, $u, $id, $nombre, $jefeId, $post): array {
        if ($id > 0) {
            $lab = inv_laboratorio($pdo, $id);
            if (!$lab) {
                throw new InvError('El laboratorio no existe.');
            }
            $pdo->prepare('UPDATE laboratorios SET nombre = ?, jefe_usuario_id = ? WHERE id = ?')->execute([$nombre, $jefeId ?: null, $id]);
            inv_auditar($pdo, $u, "Editó el laboratorio {$nombre}");
        } else {
            $codigo = strtoupper(trim((string) ($post['codigo'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{2,4}$/', $codigo)) {
                throw new InvError('El código debe tener de 2 a 4 letras o números.');
            }
            $dup = $pdo->prepare('SELECT COUNT(*) FROM laboratorios WHERE codigo = ?');
            $dup->execute([$codigo]);
            if ((int) $dup->fetchColumn() > 0) {
                throw new InvError('Ya existe un laboratorio con ese código.');
            }
            $pdo->prepare('INSERT INTO laboratorios (codigo, nombre, jefe_usuario_id) VALUES (?, ?, ?)')->execute([$codigo, $nombre, $jefeId ?: null]);
            inv_auditar($pdo, $u, "Creó el laboratorio {$nombre}");
        }
        return ['mensaje' => 'Laboratorio guardado.', 'ir' => 'view=laboratorios'];
    });
}

/** Desactiva el laboratorio (conserva su historial). Bloqueado con saldo, vales en tramite o usuarios. */
function inv_lab_baja(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.laboratorios.gestionar')) {
        throw new InvError('No tiene permiso para administrar laboratorios.');
    }
    $id = (int) ($post['id'] ?? 0);
    $motivo = inv_texto($post['motivo'] ?? '', 255, true, 'El motivo');
    return inv_tx($pdo, static function () use ($pdo, $u, $id, $motivo): array {
        $lab = inv_laboratorio($pdo, $id);
        if (!$lab) {
            throw new InvError('El laboratorio no existe.');
        }
        $dep = inv_lab_dependencias($pdo, $id);
        if ($dep['lotes_con_saldo'] > 0 || $dep['vales_en_tramite'] > 0 || $dep['usuarios'] > 0) {
            throw new InvError('No se puede desactivar: tiene lotes con existencia, vales en trámite o usuarios asignados.');
        }
        $pdo->prepare('UPDATE laboratorios SET activo = 0, baja_motivo = ?, baja_en = NOW() WHERE id = ?')->execute([$motivo, $id]);
        inv_auditar($pdo, $u, "Desactivó el laboratorio {$lab['nombre']}: {$motivo}");
        return ['mensaje' => 'Laboratorio desactivado.', 'ir' => 'view=laboratorios'];
    });
}

function inv_lab_reactivar(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.laboratorios.gestionar')) {
        throw new InvError('No tiene permiso para administrar laboratorios.');
    }
    $id = (int) ($post['id'] ?? 0);
    $lab = inv_laboratorio($pdo, $id);
    if (!$lab) {
        throw new InvError('El laboratorio no existe.');
    }
    $pdo->prepare('UPDATE laboratorios SET activo = 1, baja_motivo = NULL, baja_en = NULL WHERE id = ?')->execute([$id]);
    inv_auditar($pdo, $u, "Reactivó el laboratorio {$lab['nombre']}");
    return ['mensaje' => 'Laboratorio reactivado.', 'ir' => 'view=laboratorios'];
}

/** Elimina definitivamente un laboratorio que nunca tuvo movimientos ni usuarios. Pide escribir su codigo. */
function inv_lab_eliminar(PDO $pdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.laboratorios.gestionar')) {
        throw new InvError('No tiene permiso para administrar laboratorios.');
    }
    $id = (int) ($post['id'] ?? 0);
    return inv_tx($pdo, static function () use ($pdo, $u, $id, $post): array {
        $lab = inv_laboratorio($pdo, $id);
        if (!$lab) {
            throw new InvError('El laboratorio no existe.');
        }
        if (strtoupper(trim((string) ($post['confirmar_codigo'] ?? ''))) !== $lab['codigo']) {
            throw new InvError('Escriba el código del laboratorio para confirmar.');
        }
        $dep = inv_lab_dependencias($pdo, $id);
        if ($dep['historial'] > 0 || $dep['usuarios'] > 0) {
            throw new InvError('El laboratorio tiene movimientos o usuarios: solo puede desactivarse para conservar la trazabilidad.');
        }
        $pdo->prepare('DELETE FROM producto_laboratorio WHERE laboratorio_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM laboratorios WHERE id = ?')->execute([$id]);
        inv_auditar($pdo, $u, "Eliminó el laboratorio {$lab['nombre']}");
        return ['mensaje' => 'Laboratorio eliminado.', 'ir' => 'view=laboratorios'];
    });
}

/** Asigna (o quita) el laboratorio de un usuario del modulo. */
function inv_usuario_lab_guardar(PDO $pdo, PDO $menuPdo, array $u, array $post): array
{
    if (!inv_puede($u, 'inventario.usuarios.gestionar')) {
        throw new InvError('No tiene permiso para asignar laboratorios a usuarios.');
    }
    $usuarioId = (int) ($post['usuario_id'] ?? 0);
    $labId = (int) ($post['laboratorio_id'] ?? 0);
    $nombre = inv_nombre_usuario($menuPdo, $usuarioId);
    if ($nombre === '') {
        throw new InvError('El usuario no existe.');
    }
    if ($labId > 0) {
        $lab = inv_laboratorio($pdo, $labId);
        if (!$lab || (int) $lab['activo'] !== 1) {
            throw new InvError('Seleccione un laboratorio activo.');
        }
        $pdo->prepare('INSERT INTO usuario_laboratorio (usuario_id, laboratorio_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE laboratorio_id = VALUES(laboratorio_id)')
            ->execute([$usuarioId, $labId]);
        inv_auditar($pdo, $u, "Asignó a {$nombre} al laboratorio {$lab['nombre']}");
    } else {
        $pdo->prepare('DELETE FROM usuario_laboratorio WHERE usuario_id = ?')->execute([$usuarioId]);
        inv_auditar($pdo, $u, "Quitó el laboratorio a {$nombre}");
    }
    return ['mensaje' => 'Laboratorio del usuario actualizado.', 'ir' => 'view=usuarios'];
}

// ---------------------------------------------------------------- Cierre mensual

const INV_CIERRE_PERMISO = ['elab' => 'inventario.cierre.elaborar', 'rev' => 'inventario.cierre.revisar', 'apr' => 'inventario.cierre.aprobar'];
const INV_CIERRE_ROL = ['elab' => 'Elaborado por', 'rev' => 'Revisado por', 'apr' => 'Aprobado por'];

/** Cierre guardado de un periodo y alcance: ['id','datos','firmas' => [tipo => fila]] o null. */
function inv_cierre_obtener(PDO $pdo, int $anio, int $mes, int $labId, bool $soloFisc): ?array
{
    $stmt = $pdo->prepare('SELECT id, datos FROM cierres WHERE anio = ? AND mes = ? AND laboratorio_id = ? AND solo_fiscalizados = ?');
    $stmt->execute([$anio, $mes, $labId, $soloFisc ? 1 : 0]);
    $c = $stmt->fetch();
    if (!$c) {
        return null;
    }
    $firmas = $pdo->prepare('SELECT cf.tipo, cf.usuario_id, f.nombre, f.imagen, f.firmado_en FROM cierre_firmas cf JOIN firmas f ON f.id = cf.firma_id WHERE cf.cierre_id = ?');
    $firmas->execute([$c['id']]);
    $out = ['id' => (int) $c['id'], 'datos' => $c['datos'] !== null ? json_decode((string) $c['datos'], true) : null, 'firmas' => []];
    foreach ($firmas->fetchAll() as $f) {
        $out['firmas'][$f['tipo']] = $f;
    }
    return $out;
}

function inv_cierre_firmar(PDO $pdo, array $u, array $post): array
{
    $tipo = (string) ($post['tipo'] ?? '');
    if (!isset(INV_CIERRE_PERMISO[$tipo]) || !inv_puede($u, INV_CIERRE_PERMISO[$tipo])) {
        throw new InvError('No tiene permiso para firmar «' . (INV_CIERRE_ROL[$tipo] ?? 'cierre') . '».');
    }
    $anio = (int) ($post['anio'] ?? 0);
    $mes = (int) ($post['mes'] ?? 0);
    $labId = (int) ($post['laboratorio_id'] ?? 0);
    $soloFisc = !empty($post['solo_fiscalizados']);
    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
        throw new InvError('Periodo no válido.');
    }
    $alcance = inv_alcance_lab($u);
    if ($alcance === -1 || ($alcance > 0 && $labId !== $alcance)) {
        throw new InvError('Solo puede firmar el cierre de su propio laboratorio.');
    }
    if ($labId > 0 && !inv_laboratorio($pdo, $labId)) {
        throw new InvError('Laboratorio no válido.');
    }
    $firma = inv_firma_leer($post, 'cie', $u['nombre']);

    return inv_tx($pdo, static function () use ($pdo, $u, $tipo, $anio, $mes, $labId, $soloFisc, $firma): array {
        $pdo->prepare('INSERT IGNORE INTO cierres (anio, mes, laboratorio_id, solo_fiscalizados) VALUES (?, ?, ?, ?)')
            ->execute([$anio, $mes, $labId, $soloFisc ? 1 : 0]);
        $stmt = $pdo->prepare('SELECT id FROM cierres WHERE anio = ? AND mes = ? AND laboratorio_id = ? AND solo_fiscalizados = ? FOR UPDATE');
        $stmt->execute([$anio, $mes, $labId, $soloFisc ? 1 : 0]);
        $cierreId = (int) $stmt->fetchColumn();
        $existentes = $pdo->prepare('SELECT tipo, usuario_id FROM cierre_firmas WHERE cierre_id = ?');
        $existentes->execute([$cierreId]);
        $firmados = [];
        foreach ($existentes->fetchAll() as $f) {
            $firmados[$f['tipo']] = (int) $f['usuario_id'];
        }
        if (isset($firmados[$tipo])) {
            throw new InvError('«' . INV_CIERRE_ROL[$tipo] . '» ya está firmado.');
        }
        if ($tipo === 'rev' && !isset($firmados['elab'])) {
            throw new InvError('Primero debe firmarse «Elaborado por».');
        }
        if ($tipo === 'apr' && !isset($firmados['rev'])) {
            throw new InvError('Primero debe firmarse «Revisado por».');
        }
        if ($u['es_superadmin'] !== 1 && in_array($u['id'], $firmados, true)) {
            throw new InvError('Separación de funciones: otra persona debe firmar esta etapa del cierre.');
        }
        if ($tipo === 'elab') {
            // Foto de las cifras al elaborar: las firmas posteriores avalan estos mismos numeros.
            $datos = inv_cierre_datos($pdo, $anio, $mes, $labId, $soloFisc);
            $pdo->prepare('UPDATE cierres SET datos = ? WHERE id = ?')->execute([json_encode($datos, JSON_UNESCAPED_UNICODE), $cierreId]);
        }
        $firmaId = inv_firma_guardar($pdo, $firma, $u['id']);
        $pdo->prepare('INSERT INTO cierre_firmas (cierre_id, tipo, firma_id, usuario_id) VALUES (?, ?, ?, ?)')->execute([$cierreId, $tipo, $firmaId, $u['id']]);
        inv_auditar($pdo, $u, sprintf('Firmó «%s» del cierre %02d/%d%s', INV_CIERRE_ROL[$tipo], $mes, $anio, $labId > 0 ? " (laboratorio {$labId})" : ' (consolidado)'));
        return [
            'mensaje' => 'Firma registrada en el cierre mensual.',
            'ir' => sprintf('view=cierre&anio=%d&mes=%d&lab=%d&fisc=%d', $anio, $mes, $labId, $soloFisc ? 1 : 0),
        ];
    });
}
