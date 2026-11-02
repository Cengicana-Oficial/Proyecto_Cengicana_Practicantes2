<?php
require_once __DIR__ . '/../includes/auth.php';
lab_require_permission('laboratorio.lotes.ver');
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/identificacion_lote_helper.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST'); echo json_encode(['error'=>'Método no permitido.']); exit;
}
try {
    $raw = file_get_contents('php://input', false, null, 0, 5000001);
    if (strlen($raw) > 5000000) throw new InvalidArgumentException('El lote supera el tamaño máximo de la solicitud.');
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new InvalidArgumentException('Solicitud inválida.');
    $token = (string) ($_SESSION['muestra_identificacion_csrf'] ?? '');
    if ($token === '' || !hash_equals($token, (string) ($data['csrf_token'] ?? ''))) {
        http_response_code(403); echo json_encode(['error'=>'La sesión venció. Recargue la página.']); exit;
    }
    labIdentificacionCargarCamposTipo($conexion);
    $usuario = lab_current_user();
    $lote = labIdentificacionGuardarLote($conexion, $data, (string) ($usuario['nombre'] ?: 'Usuario de laboratorio'));
    echo json_encode(['lote'=>$lote], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | JsonException $e) {
    http_response_code(422); echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code($e->getCode() === 409 ? 409 : 500);
    error_log('[laboratorio][identificacion-lote] '.$e->getMessage());
    echo json_encode(['error'=>$e->getCode() === 409 ? $e->getMessage() : 'No se pudo guardar la identificación. Su borrador se conserva.']);
}
