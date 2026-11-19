<?php
declare(strict_types=1);

// Consultas y calculos de inventario: lotes, FEFO, balance por laboratorio, comprometido,
// salidas y cierre mensual. Solo lectura (las escrituras estan en acciones.php).
// Equivale al "motor de inventario" del prototipo (lotes(), fefo(), balance(), salidasLineas()),
// pero calculado en el servidor y con cantidades en milesimas enteras.

const INV_DIAS_POR_VENCER = 60;

function inv_estado_lote(int $disponible, int $dias): string
{
    if ($disponible <= 0) {
        return 'AGOTADO';
    }
    if ($dias < 0) {
        return 'VENCIDO';
    }
    if ($dias <= INV_DIAS_POR_VENCER) {
        return 'PRÓXIMO A VENCER';
    }
    return 'ACTIVO';
}

/**
 * Lotes (entradas no anuladas) con su consumo y estado.
 * Filtros: laboratorio_id, producto_id. Orden: laboratorio, producto, caducidad, id.
 * Cada fila: id, lote, laboratorio_id, lab_codigo, lab_nombre, producto_id, prod_codigo, prod_nombre,
 * unidad, fecha_ingreso, caducidad, ingresado, consumido, disp (milesimas), dias, estado.
 */
function inv_lotes(PDO $pdo, array $filtro = [], ?string $hoy = null, bool $bloquear = false): array
{
    $sql = "SELECT en.id, en.lote, en.laboratorio_id, l.codigo AS lab_codigo, l.nombre AS lab_nombre,
                en.producto_id, p.codigo AS prod_codigo, p.nombre AS prod_nombre, p.unidad,
                en.fecha_ingreso, en.caducidad, en.cantidad,
                COALESCE((SELECT SUM(vil.cantidad)
                          FROM vale_item_lotes vil
                          JOIN vale_items vi ON vi.id = vil.vale_item_id
                          JOIN vales v ON v.id = vi.vale_id
                          WHERE vil.entrada_id = en.id AND v.estado = 'Entregado'), 0) AS consumido
            FROM entradas en
            JOIN laboratorios l ON l.id = en.laboratorio_id
            JOIN productos p ON p.id = en.producto_id
            WHERE en.anulada = 0";
    $params = [];
    if (!empty($filtro['laboratorio_id'])) {
        $sql .= ' AND en.laboratorio_id = ?';
        $params[] = (int) $filtro['laboratorio_id'];
    }
    if (!empty($filtro['producto_id'])) {
        $sql .= ' AND en.producto_id = ?';
        $params[] = (int) $filtro['producto_id'];
    }
    $sql .= ' ORDER BY l.codigo, p.codigo, en.caducidad, en.id';
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $hoy = $hoy ?? inv_hoy();

    $lotes = [];
    foreach ($stmt->fetchAll() as $f) {
        $ingresado = inv_milli($f['cantidad']);
        $consumido = inv_milli($f['consumido']);
        $disp = $ingresado - $consumido;
        $dias = inv_dias_hasta((string) $f['caducidad'], $hoy);
        $lotes[] = [
            'id' => (int) $f['id'], 'lote' => $f['lote'],
            'laboratorio_id' => (int) $f['laboratorio_id'], 'lab_codigo' => $f['lab_codigo'], 'lab_nombre' => $f['lab_nombre'],
            'producto_id' => (int) $f['producto_id'], 'prod_codigo' => $f['prod_codigo'], 'prod_nombre' => $f['prod_nombre'], 'unidad' => $f['unidad'],
            'fecha_ingreso' => $f['fecha_ingreso'], 'caducidad' => $f['caducidad'],
            'ingresado' => $ingresado, 'consumido' => $consumido, 'disp' => $disp, 'dias' => $dias,
            'estado' => inv_estado_lote($disp, $dias),
        ];
    }
    return $lotes;
}

/** Existencia utilizable por producto de un laboratorio (lotes no vencidos con saldo): [producto_id => milesimas]. */
function inv_disponible_por_producto(array $lotes, int $laboratorioId): array
{
    $disp = [];
    foreach ($lotes as $l) {
        if ($l['laboratorio_id'] === $laboratorioId && $l['estado'] !== 'VENCIDO' && $l['disp'] > 0) {
            $disp[$l['producto_id']] = ($disp[$l['producto_id']] ?? 0) + $l['disp'];
        }
    }
    return $disp;
}

/**
 * Cantidad en vales en tramite (Pendiente o Autorizado) por producto: [producto_id => milesimas].
 * $excluirVale omite un vale (para recalcular al despacharlo).
 */
function inv_comprometido(PDO $pdo, int $laboratorioId = 0, int $excluirVale = 0): array
{
    $sql = "SELECT vi.producto_id, SUM(vi.cantidad) AS total
            FROM vale_items vi JOIN vales v ON v.id = vi.vale_id
            WHERE v.estado IN ('Pendiente','Autorizado') AND v.id <> ?";
    $params = [$excluirVale];
    if ($laboratorioId > 0) {
        $sql .= ' AND v.laboratorio_id = ?';
        $params[] = $laboratorioId;
    }
    $stmt = $pdo->prepare($sql . ' GROUP BY vi.producto_id');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $f) {
        $out[(int) $f['producto_id']] = inv_milli($f['total']);
    }
    return $out;
}

/**
 * Asigna una cantidad a lotes por FEFO (primero en vencer, primero en salir).
 * $lotes: salida de inv_lotes() (se filtra por laboratorio y producto aqui).
 * Devuelve ['asign' => [[entrada_id, lote, cantidad, caducidad]...], 'falta' => milesimas].
 */
function inv_fefo(array $lotes, int $laboratorioId, int $productoId, int $cantidad): array
{
    $candidatos = array_values(array_filter($lotes, static function ($l) use ($laboratorioId, $productoId) {
        return $l['laboratorio_id'] === $laboratorioId && $l['producto_id'] === $productoId && $l['estado'] !== 'VENCIDO' && $l['disp'] > 0;
    }));
    usort($candidatos, static function ($a, $b) {
        return [$a['caducidad'], $a['id']] <=> [$b['caducidad'], $b['id']];
    });
    $asign = [];
    $resta = $cantidad;
    foreach ($candidatos as $l) {
        if ($resta <= 0) {
            break;
        }
        $toma = min($resta, $l['disp']);
        $asign[] = ['entrada_id' => $l['id'], 'lote' => $l['lote'], 'cantidad' => $toma, 'caducidad' => $l['caducidad']];
        $resta -= $toma;
    }
    return ['asign' => $asign, 'falta' => max(0, $resta)];
}

/**
 * Balance de un laboratorio por cada producto de su matriz: ingresado, consumido, disponible,
 * comprometido en vales en tramite, vencido y libre para solicitar.
 */
function inv_balance(PDO $pdo, int $laboratorioId, ?array $lotes = null): array
{
    $lotes = $lotes ?? inv_lotes($pdo, ['laboratorio_id' => $laboratorioId]);
    $stmt = $pdo->prepare(
        'SELECT p.id, p.codigo, p.nombre, p.tipo, p.cas, p.unidad, p.minimo, p.concentracion, p.activo
         FROM productos p JOIN producto_laboratorio pl ON pl.producto_id = p.id
         WHERE pl.laboratorio_id = ? ORDER BY p.codigo'
    );
    $stmt->execute([$laboratorioId]);
    $comprometido = inv_comprometido($pdo, $laboratorioId);

    $filas = [];
    foreach ($stmt->fetchAll() as $p) {
        $id = (int) $p['id'];
        $ing = $con = $venc = 0;
        foreach ($lotes as $l) {
            if ($l['laboratorio_id'] === $laboratorioId && $l['producto_id'] === $id) {
                $ing += $l['ingresado'];
                $con += $l['consumido'];
                if ($l['estado'] === 'VENCIDO') {
                    $venc += $l['disp'];
                }
            }
        }
        $disp = $ing - $con;
        $comp = $comprometido[$id] ?? 0;
        $minimo = inv_milli($p['minimo']);
        $filas[] = [
            'id' => $id, 'codigo' => $p['codigo'], 'nombre' => $p['nombre'], 'unidad' => $p['unidad'], 'tipo' => $p['tipo'],
            'ing' => $ing, 'con' => $con, 'disp' => $disp, 'comp' => $comp, 'venc' => $venc, 'libre' => $disp - $venc - $comp,
            'minimo' => $minimo, 'estado' => inv_estado_stock($disp, $minimo),
        ];
    }
    return $filas;
}

/** [texto, clase] del estado de existencia de un producto. */
function inv_estado_stock(int $disp, int $minimo): array
{
    if ($disp <= 0) {
        return ['AGOTADO', 'p-bad'];
    }
    if ($disp < $minimo) {
        return ['BAJO MÍNIMO', 'p-warn'];
    }
    return ['DISPONIBLE', 'p-ok'];
}

/**
 * Lineas de salida (una por lote asignado en vales entregados), mas recientes primero.
 * Filtros: laboratorio_id, producto_id, desde, hasta (fecha de entrega, inclusive).
 */
function inv_salidas(PDO $pdo, array $filtro = []): array
{
    $sql = "SELECT v.id AS vale_id, v.numero, v.fecha_entrega, v.laboratorio_id, l.nombre AS lab_nombre, vil.lote,
                p.id AS producto_id, p.codigo AS prod_codigo, p.nombre AS prod_nombre, p.unidad, vil.cantidad,
                v.solicitante, v.proyecto, fe.nombre AS despacho, fr.nombre AS recibe
            FROM vale_item_lotes vil
            JOIN vale_items vi ON vi.id = vil.vale_item_id
            JOIN vales v ON v.id = vi.vale_id
            JOIN productos p ON p.id = vi.producto_id
            JOIN laboratorios l ON l.id = v.laboratorio_id
            LEFT JOIN vale_firmas vfe ON vfe.vale_id = v.id AND vfe.tipo = 'ent'
            LEFT JOIN firmas fe ON fe.id = vfe.firma_id
            LEFT JOIN vale_firmas vfr ON vfr.vale_id = v.id AND vfr.tipo = 'rec'
            LEFT JOIN firmas fr ON fr.id = vfr.firma_id
            WHERE v.estado = 'Entregado'";
    $params = [];
    foreach (['laboratorio_id' => 'v.laboratorio_id = ?', 'producto_id' => 'p.id = ?'] as $clave => $condicion) {
        if (!empty($filtro[$clave])) {
            $sql .= ' AND ' . $condicion;
            $params[] = (int) $filtro[$clave];
        }
    }
    if (!empty($filtro['desde'])) {
        $sql .= ' AND v.fecha_entrega >= ?';
        $params[] = $filtro['desde'];
    }
    if (!empty($filtro['hasta'])) {
        $sql .= ' AND v.fecha_entrega <= ?';
        $params[] = $filtro['hasta'];
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY v.fecha_entrega DESC, v.numero DESC, vil.id');
    $stmt->execute($params);
    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['cantidad'] = inv_milli($f['cantidad']);
    }
    return $filas;
}

/**
 * Cifras del informe mensual por producto (precursores fiscalizados o todos), de un laboratorio
 * o consolidado. inicial = saldo antes del mes; final = inicial + ingresos - egresos.
 * Devuelve ['inicio','fin','filas'].
 */
function inv_cierre_datos(PDO $pdo, int $anio, int $mes, int $laboratorioId, bool $soloFiscalizados): array
{
    $inicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fin = date('Y-m-t', strtotime($inicio));

    $sql = 'SELECT p.id, p.codigo, p.nombre, p.cas, p.unidad FROM productos p';
    $params = [];
    $cond = [];
    if ($laboratorioId > 0) {
        $sql .= ' JOIN producto_laboratorio pl ON pl.producto_id = p.id AND pl.laboratorio_id = ?';
        $params[] = $laboratorioId;
    }
    if ($soloFiscalizados) {
        $cond[] = "p.tipo = 'Precursor fiscalizado'";
    }
    $stmt = $pdo->prepare($sql . ($cond ? ' WHERE ' . implode(' AND ', $cond) : '') . ' ORDER BY p.codigo');
    $stmt->execute($params);
    $productos = $stmt->fetchAll();

    $labEn = $laboratorioId > 0 ? ' AND en.laboratorio_id = ' . (int) $laboratorioId : '';
    $ent = $pdo->prepare(
        "SELECT en.producto_id,
                SUM(CASE WHEN en.fecha_ingreso < :i1 THEN en.cantidad ELSE 0 END) AS antes,
                SUM(CASE WHEN en.fecha_ingreso BETWEEN :i2 AND :f1 THEN en.cantidad ELSE 0 END) AS ingresos
         FROM entradas en WHERE en.anulada = 0 AND en.fecha_ingreso <= :f2{$labEn} GROUP BY en.producto_id"
    );
    $ent->execute(['i1' => $inicio, 'i2' => $inicio, 'f1' => $fin, 'f2' => $fin]);
    $entradas = [];
    foreach ($ent->fetchAll() as $f) {
        $entradas[(int) $f['producto_id']] = $f;
    }

    $labV = $laboratorioId > 0 ? ' AND v.laboratorio_id = ' . (int) $laboratorioId : '';
    $sal = $pdo->prepare(
        "SELECT vi.producto_id,
                SUM(CASE WHEN v.fecha_entrega < :i1 THEN vil.cantidad ELSE 0 END) AS antes,
                SUM(CASE WHEN v.fecha_entrega BETWEEN :i2 AND :f1 THEN vil.cantidad ELSE 0 END) AS egresos
         FROM vale_item_lotes vil
         JOIN vale_items vi ON vi.id = vil.vale_item_id
         JOIN vales v ON v.id = vi.vale_id
         WHERE v.estado = 'Entregado' AND v.fecha_entrega <= :f2{$labV} GROUP BY vi.producto_id"
    );
    $sal->execute(['i1' => $inicio, 'i2' => $inicio, 'f1' => $fin, 'f2' => $fin]);
    $salidas = [];
    foreach ($sal->fetchAll() as $f) {
        $salidas[(int) $f['producto_id']] = $f;
    }

    $filas = [];
    foreach ($productos as $p) {
        $id = (int) $p['id'];
        $inicial = inv_milli($entradas[$id]['antes'] ?? 0) - inv_milli($salidas[$id]['antes'] ?? 0);
        $ing = inv_milli($entradas[$id]['ingresos'] ?? 0);
        $egr = inv_milli($salidas[$id]['egresos'] ?? 0);
        $filas[] = [
            'codigo' => $p['codigo'], 'nombre' => $p['nombre'], 'cas' => (string) $p['cas'], 'unidad' => $p['unidad'],
            'inicial' => $inicial, 'ingresos' => $ing, 'egresos' => $egr, 'final' => $inicial + $ing - $egr,
        ];
    }
    return ['inicio' => $inicio, 'fin' => $fin, 'filas' => $filas];
}

// ---------------------------------------------------------------- Catalogos y consultas de apoyo

function inv_laboratorios(PDO $pdo, bool $soloActivos = false): array
{
    return $pdo->query('SELECT id, codigo, nombre, jefe_usuario_id, activo, baja_motivo, baja_en FROM laboratorios'
        . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY nombre')->fetchAll();
}

function inv_laboratorio(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, codigo, nombre, jefe_usuario_id, activo, baja_motivo, baja_en FROM laboratorios WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Productos con la lista de ids de laboratorio autorizados: cada fila trae 'labs' => [id...]. */
function inv_productos(PDO $pdo, bool $soloActivos = false): array
{
    $productos = $pdo->query('SELECT id, codigo, nombre, tipo, concentracion, cas, unidad, minimo, activo FROM productos'
        . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY codigo')->fetchAll();
    $matriz = [];
    foreach ($pdo->query('SELECT producto_id, laboratorio_id FROM producto_laboratorio')->fetchAll() as $f) {
        $matriz[(int) $f['producto_id']][] = (int) $f['laboratorio_id'];
    }
    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['minimo'] = inv_milli($p['minimo']);
        $p['labs'] = $matriz[$p['id']] ?? [];
    }
    return $productos;
}

/** Productos activos que el laboratorio puede solicitar. */
function inv_productos_de_lab(PDO $pdo, int $laboratorioId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.codigo, p.nombre, p.unidad FROM productos p
         JOIN producto_laboratorio pl ON pl.producto_id = p.id
         WHERE pl.laboratorio_id = ? AND p.activo = 1 ORDER BY p.codigo'
    );
    $stmt->execute([$laboratorioId]);
    return $stmt->fetchAll();
}

/** Nombre de usuario de la plataforma, o '' si no existe. */
function inv_nombre_usuario(PDO $menuPdo, int $usuarioId): string
{
    if ($usuarioId <= 0) {
        return '';
    }
    $stmt = $menuPdo->prepare('SELECT nombre FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * Usuarios con acceso al modulo y su rol ahi (superadmins incluidos):
 * [id, nombre, correo, es_superadmin, rol_id, nombre_rol].
 */
function inv_usuarios_modulo(PDO $menuPdo): array
{
    $moduloId = inv_modulo_id($menuPdo);
    $stmt = $menuPdo->prepare(
        'SELECT u.id, u.nombre, u.correo, u.es_superadmin, um.rol_id, COALESCE(r.nombre_rol, \'\') AS nombre_rol
         FROM usuarios u
         LEFT JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id = ?
         LEFT JOIN roles r ON r.id = um.rol_id
         WHERE um.usuario_id IS NOT NULL OR u.es_superadmin = 1
         ORDER BY u.nombre'
    );
    $stmt->execute([$moduloId]);
    $usuarios = $stmt->fetchAll();
    foreach ($usuarios as &$u) {
        $u['id'] = (int) $u['id'];
        $u['es_superadmin'] = (int) $u['es_superadmin'];
    }
    return $usuarios;
}

/** Usuarios del modulo cuyo rol tiene el permiso (o superadmins): [id => nombre]. */
function inv_usuarios_con_permiso(PDO $menuPdo, string $permiso): array
{
    $moduloId = inv_modulo_id($menuPdo);
    $stmt = $menuPdo->prepare(
        'SELECT DISTINCT u.id, u.nombre
         FROM usuarios u
         LEFT JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id = ?
         LEFT JOIN rol_permiso rp ON rp.rol_id = um.rol_id
         LEFT JOIN permisos p ON p.id = rp.permiso_id AND p.nombre_permiso = ?
         WHERE u.es_superadmin = 1 OR p.id IS NOT NULL
         ORDER BY u.nombre'
    );
    $stmt->execute([$moduloId, $permiso]);
    $out = [];
    foreach ($stmt->fetchAll() as $f) {
        $out[(int) $f['id']] = $f['nombre'];
    }
    return $out;
}

/** Dependencias que impiden dar de baja o eliminar un laboratorio. */
function inv_lab_dependencias(PDO $pdo, int $laboratorioId): array
{
    $conSaldo = 0;
    foreach (inv_lotes($pdo, ['laboratorio_id' => $laboratorioId]) as $l) {
        if ($l['disp'] > 0) {
            $conSaldo++;
        }
    }
    $consulta = static function (string $sql) use ($pdo, $laboratorioId): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$laboratorioId]);
        return (int) $stmt->fetchColumn();
    };
    return [
        'lotes_con_saldo' => $conSaldo,
        'vales_en_tramite' => $consulta("SELECT COUNT(*) FROM vales WHERE laboratorio_id = ? AND estado IN ('Pendiente','Autorizado')"),
        'usuarios' => $consulta('SELECT COUNT(*) FROM usuario_laboratorio WHERE laboratorio_id = ?'),
        'historial' => $consulta('SELECT COUNT(*) FROM entradas WHERE laboratorio_id = ?')
            + $consulta('SELECT COUNT(*) FROM vales WHERE laboratorio_id = ?'),
        'productos' => $consulta('SELECT COUNT(*) FROM producto_laboratorio WHERE laboratorio_id = ?'),
    ];
}

/** Tareas pendientes del usuario: [[vale, texto]...]. */
function inv_tareas(PDO $pdo, array $usuario): array
{
    $alcance = inv_alcance_lab($usuario);
    if ($alcance === -1 && !inv_puede($usuario, 'inventario.vales.despachar')) {
        return [];
    }
    $stmt = $pdo->query(
        "SELECT v.id, v.numero, v.estado, v.laboratorio_id, v.solicitante_id, v.solicitante, l.nombre AS lab_nombre
         FROM vales v JOIN laboratorios l ON l.id = v.laboratorio_id
         WHERE v.estado IN ('Pendiente','Autorizado') ORDER BY v.id DESC"
    );
    $autoriza = inv_puede($usuario, 'inventario.vales.autorizar');
    $despacha = inv_puede($usuario, 'inventario.vales.despachar');
    $tareas = [];
    foreach ($stmt->fetchAll() as $v) {
        $v['laboratorio_id'] = (int) $v['laboratorio_id'];
        $propio = $alcance === 0 || $v['laboratorio_id'] === $alcance;
        if ($autoriza && $v['estado'] === 'Pendiente' && $propio && inv_puede_autorizar_vale($usuario, $v)) {
            $tareas[] = [$v, 'Requiere su visto bueno'];
        } elseif ($despacha && $v['estado'] === 'Autorizado') {
            $tareas[] = [$v, 'Listo para despacho y firma de entrega'];
        } elseif (!$autoriza && !$despacha && $propio) {
            $tareas[] = [$v, $v['estado'] === 'Pendiente' ? 'Esperando visto bueno de jefatura' : 'Autorizado; puede pasar a bodega'];
        }
    }
    return $tareas;
}

/** Puede el usuario dar visto bueno a este vale (fila de vales con laboratorio_id y solicitante_id)? */
function inv_puede_autorizar_vale(array $usuario, array $vale): bool
{
    if (!inv_puede($usuario, 'inventario.vales.autorizar') || ($vale['estado'] ?? '') !== 'Pendiente') {
        return false;
    }
    $alcance = inv_alcance_lab($usuario);
    if ($alcance !== 0 && (int) $vale['laboratorio_id'] !== $alcance) {
        return false;
    }
    // Separacion de funciones: nadie da el visto bueno a su propio vale (salvo el superadmin).
    if ((int) ($vale['solicitante_id'] ?? 0) === $usuario['id'] && $usuario['es_superadmin'] !== 1) {
        return false;
    }
    return true;
}
