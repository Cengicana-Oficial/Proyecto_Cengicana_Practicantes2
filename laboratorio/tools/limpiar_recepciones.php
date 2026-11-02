<?php
/**
 * Limpieza de recepciones de prueba (solo línea de comandos).
 *
 *   php laboratorio/tools/limpiar_recepciones.php                     Lista las recepciones
 *   php laboratorio/tools/limpiar_recepciones.php --ids=1,2,5         Muestra qué se borraría (no borra)
 *   php laboratorio/tools/limpiar_recepciones.php --hasta=2026-06-30  Recepciones ingresadas hasta esa fecha
 *   ... --confirmar                                                    Borra de verdad, en una transacción
 *
 * Borra cada recepción con todo lo que depende de ella: muestras, análisis
 * solicitados, lote, rangos, formularios y sus resultados (tablas por análisis,
 * curvas, hojas de trabajo), identificación, planificación, historial y
 * trazabilidad. Las tablas se descubren por sus columnas de enlace
 * (id_solicitud, id_muestra, id_lote, id_rango, id_formulario), así se incluyen
 * también las tablas que crea la aplicación. Haga un respaldo antes de confirmar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../conexion.php';

$opciones = getopt('', ['ids:', 'hasta:', 'confirmar']);
$pdo = Conexion::conectar();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function lr_lista(PDO $pdo, string $where = '1=1', array $params = []): array
{
    $stmt = $pdo->prepare("
        SELECT s.id_solicitud, l.id_lote, l.codigo_lote, tm.nombre AS tipo, s.institucion, s.numero_muestras, s.fecha_ingreso,
               (SELECT COUNT(*) FROM formulario f JOIN lote_rango lr ON lr.id_rango = f.id_rango WHERE lr.id_lote = s.id_lote) AS formularios
        FROM solicitud s
        LEFT JOIN lote l ON l.id_lote = s.id_lote
        LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
        WHERE {$where}
        ORDER BY s.id_solicitud
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function lr_imprimir(array $filas): void
{
    printf("%-6s %-12s %-10s %-30s %8s %-11s %s\n", 'ID', 'Lote', 'Tipo', 'Cliente', 'Muestras', 'Ingreso', 'Formularios');
    foreach ($filas as $f) {
        printf("%-6d %-12s %-10s %-30s %8d %-11s %d\n", $f['id_solicitud'], (string) $f['codigo_lote'], mb_substr((string) $f['tipo'], 0, 10),
            mb_substr((string) $f['institucion'], 0, 30), $f['numero_muestras'], (string) $f['fecha_ingreso'], $f['formularios']);
    }
}

function lr_ids(PDO $pdo, string $sql, array $params): array
{
    if (!$params) return [];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function lr_in(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

if (!isset($opciones['ids']) && !isset($opciones['hasta'])) {
    echo "Recepciones registradas (use --ids=... o --hasta=AAAA-MM-DD para elegir cuáles borrar):\n\n";
    lr_imprimir(lr_lista($pdo));
    exit(0);
}

if (isset($opciones['ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $opciones['ids'])))));
    $seleccion = $ids ? lr_lista($pdo, 's.id_solicitud IN (' . lr_in($ids) . ')', $ids) : [];
} else {
    $hasta = (string) $opciones['hasta'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        fwrite(STDERR, "Fecha inválida en --hasta (use AAAA-MM-DD).\n");
        exit(1);
    }
    $seleccion = lr_lista($pdo, 's.fecha_ingreso <= ?', [$hasta]);
}
if (!$seleccion) {
    echo "No hay recepciones que coincidan.\n";
    exit(0);
}

$solicitudes = array_map('intval', array_column($seleccion, 'id_solicitud'));
$lotes = array_values(array_unique(array_filter(array_map('intval', array_column($seleccion, 'id_lote')))));

// Un lote compartido con una recepción que no se borra dejaría muestras y rangos huérfanos.
$compartidas = $lotes ? lr_lista($pdo, 's.id_lote IN (' . lr_in($lotes) . ') AND s.id_solicitud NOT IN (' . lr_in($solicitudes) . ')', array_merge($lotes, $solicitudes)) : [];
if ($compartidas) {
    echo "Estas recepciones comparten lote con las seleccionadas y también deben incluirse (o quitarse de la selección):\n\n";
    lr_imprimir($compartidas);
    exit(1);
}

$muestras = lr_ids($pdo, 'SELECT id_muestra FROM muestra WHERE id_solicitud IN (' . lr_in($solicitudes) . ')', $solicitudes);
$rangos = lr_ids($pdo, 'SELECT id_rango FROM lote_rango WHERE id_lote IN (' . lr_in($lotes) . ')', $lotes);
$formularios = lr_ids($pdo, 'SELECT id_formulario FROM formulario WHERE id_rango IN (' . lr_in($rangos) . ')', $rangos);
$claves = ['id_formulario' => $formularios, 'id_rango' => $rangos, 'id_muestra' => $muestras, 'id_solicitud' => $solicitudes, 'id_lote' => $lotes];

$columnas = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('id_formulario','id_rango','id_muestra','id_solicitud','id_lote')
")->fetchAll(PDO::FETCH_ASSOC);
$porTabla = [];
foreach ($columnas as $c) {
    $porTabla[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
}
ksort($porTabla);

$plan = [];
foreach ($porTabla as $tabla => $cols) {
    $condiciones = [];
    $params = [];
    foreach ($cols as $col) {
        if ($claves[$col]) {
            $condiciones[] = "`{$col}` IN (" . lr_in($claves[$col]) . ')';
            $params = array_merge($params, $claves[$col]);
        }
    }
    if (!$condiciones) continue;
    $where = implode(' OR ', $condiciones);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$tabla}` WHERE {$where}");
    $stmt->execute($params);
    $n = (int) $stmt->fetchColumn();
    if ($n > 0) $plan[] = [$tabla, $where, $params, $n];
}

echo "Recepciones seleccionadas:\n\n";
lr_imprimir($seleccion);
echo "\nFilas que se borrarían:\n";
foreach ($plan as [$tabla, , , $n]) {
    printf("  %-32s %d\n", $tabla, $n);
}

if (!isset($opciones['confirmar'])) {
    echo "\nNo se borró nada. Revise la lista, haga un respaldo de la base de datos y repita con --confirmar.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    // Se borra en un solo paso con las llaves foráneas suspendidas: el orden de dependencias
    // entre tablas de resultados y curvas varía, pero el conjunto borrado es cerrado.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($plan as [$tabla, $where, $params]) {
        $pdo->prepare("DELETE FROM `{$tabla}` WHERE {$where}")->execute($params);
    }
    // Hojas de trabajo en borrador: se quitan las muestras borradas para que no queden huérfanas.
    $existeBorrador = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_hoja_borrador'")->fetchColumn();
    if ($existeBorrador && $muestras) {
        $borradas = array_flip($muestras);
        $actualizar = $pdo->prepare('UPDATE lab_hoja_borrador SET datos = ? WHERE usuario_key = ? AND hoja = ?');
        foreach ($pdo->query('SELECT usuario_key, hoja, datos FROM lab_hoja_borrador')->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $cap = json_decode((string) $b['datos'], true);
            if (!is_array($cap) || empty($cap['seleccion'])) continue;
            $quedan = array_values(array_filter($cap['seleccion'], static fn($id) => !isset($borradas[(int) $id])));
            if (count($quedan) === count($cap['seleccion'])) continue;
            $cap['seleccion'] = $quedan;
            $cap['filas'] = (object) array_diff_key($cap['filas'] ?? [], $borradas);
            $actualizar->execute([json_encode($cap, JSON_UNESCAPED_UNICODE), $b['usuario_key'], $b['hoja']]);
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, 'No se borró nada: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "\nListo: " . count($solicitudes) . " recepción(es) borradas con todos sus datos asociados.\n";
