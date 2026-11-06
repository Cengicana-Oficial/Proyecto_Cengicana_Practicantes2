<?php
/**
 * Datos de muestras de Experimentos por el ID leido en su etiqueta QR (lectura directa de la
 * base de Experimentos, sin API). Lo usa "Registrar etiquetas" para confirmar al escanear;
 * los datos se guardan en la ficha al guardar el lote (identificacion_lote_helper).
 * POST JSON: { ids: ["SIGEC-..."], csrf_token }
 */
require_once __DIR__ . '/../includes/auth.php';
lab_require_permission('laboratorio.lotes.ver');
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/experimentos_helper.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST'); echo json_encode(['error'=>'Método no permitido.']); exit;
}
try {
    $data = json_decode((string) file_get_contents('php://input', false, null, 0, 200001), true, 16, JSON_THROW_ON_ERROR);
    $token = (string) ($_SESSION['muestra_identificacion_csrf'] ?? '');
    if ($token === '' || !hash_equals($token, (string) ($data['csrf_token'] ?? ''))) {
        http_response_code(403); echo json_encode(['error'=>'La sesión venció. Recargue la página.']); exit;
    }
    $ids = array_slice(array_filter(array_map(static fn($id) => labExpIdDeLectura(is_scalar($id) ? (string) $id : ''), (array) ($data['ids'] ?? []))), 0, 200);
    $pdo = Conexion::conectar();
    if (!labExpDisponible($pdo)) {
        echo json_encode(['disponible'=>false,'muestras'=>(object) [],'no_encontradas'=>[]]); exit;
    }
    $muestras = labExpMuestras($pdo, $ids);
    foreach ($muestras as &$m) $m['resumen'] = labExpResumen($m);
    unset($m);
    echo json_encode([
        'disponible'=>true,
        'muestras'=>(object) $muestras,
        'no_encontradas'=>array_values(array_diff(array_unique($ids), array_keys($muestras))),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(422); echo json_encode(['error'=>'Solicitud inválida.']);
} catch (Throwable $e) {
    error_log('[laboratorio][experimentos-muestras] '.$e->getMessage());
    http_response_code(500); echo json_encode(['error'=>'No se pudo consultar Experimentos.']);
}
