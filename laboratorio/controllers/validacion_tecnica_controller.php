<?php
/**
 * validacion_tecnica_controller.php
 * Endpoint AJAX (POST JSON) para aprobar/rechazar un analisis desde la
 * matriz de validacion tecnica (view/validacion_tecnica_view.php).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/validacion_tecnica_model.php';

header('Content-Type: application/json; charset=utf-8');

lab_require_permission('laboratorio.validacion_tecnica.aprobar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$idFormulario = (int) ($payload['id_formulario'] ?? 0);
$accion = trim((string) ($payload['accion'] ?? ''));
$comentario = trim((string) ($payload['comentario'] ?? ''));

if ($idFormulario <= 0 || !in_array($accion, ['aprobar', 'rechazar'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos.']);
    exit;
}

$usuario = lab_current_user();

try {
    $pdo = Conexion::conectar();
    $resultado = lab_validacion_resolver(
        $pdo,
        $idFormulario,
        $accion,
        (string) ($usuario['nombre'] ?? ''),
        !empty($usuario['id']) ? (int) $usuario['id'] : null,
        $comentario
    );

    echo json_encode(['ok' => true, 'data' => $resultado]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar la decision.']);
}
