<?php
/**
 * kanban_controller.php
 * Endpoint AJAX (POST JSON) para mover una tarjeta del tablero de
 * planificacion (drag & drop) a una nueva columna/estado.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/kanban_model.php';

header('Content-Type: application/json; charset=utf-8');

lab_require_permission('laboratorio.kanban.gestionar');

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
$nuevoEstado = trim((string) ($payload['estado'] ?? ''));

if ($idFormulario <= 0 || $nuevoEstado === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos.']);
    exit;
}

$usuario = lab_current_user();

try {
    $pdo = Conexion::conectar();
    $resultado = lab_kanban_mover_tarjeta(
        $pdo,
        $idFormulario,
        $nuevoEstado,
        (string) ($usuario['nombre'] ?? ''),
        !empty($usuario['id']) ? (int) $usuario['id'] : null
    );

    echo json_encode(['ok' => true, 'data' => $resultado]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo mover el analisis.']);
}
