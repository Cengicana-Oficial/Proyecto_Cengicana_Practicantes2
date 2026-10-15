<?php
require_once("../../config/auth.php");
require_login();

$isJson = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');

function payment_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!can_access('gestionar_pagos')) {
    if ($isJson) {
        payment_respond(['success' => false, 'error' => 'No tienes permiso para gestionar pagos.'], 403);
    }
    header("Location: dashboard_unificado.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isJson) {
        payment_respond(['success' => false, 'error' => 'Metodo no permitido.'], 405);
    }
    header("Location: dashboard_pago.php");
    exit;
}

$payload = $isJson
    ? json_decode((string) file_get_contents('php://input'), true)
    : $_POST;
if (!is_array($payload)) {
    payment_respond(['success' => false, 'error' => 'Solicitud invalida.'], 400);
}

if (empty($_SESSION['visits_csrf']) || !hash_equals(
    (string) ($_SESSION['visits_csrf'] ?? ''),
    (string) ($payload['csrf'] ?? '')
)) {
    payment_respond(['success' => false, 'error' => 'La sesion vencio. Actualiza la pagina.'], 403);
}

$id_solicitud = isset($payload['id_solicitud']) ? (int) $payload['id_solicitud'] : 0;
$requestedState = strtolower(trim((string) ($payload['estado'] ?? '')));
$id_estado_pago = isset($payload['id_estado_pago'])
    ? (int) $payload['id_estado_pago']
    : ($requestedState === 'pagado' ? 2 : ($requestedState === 'pendiente' ? 1 : 0));

if (!$id_solicitud || !in_array($id_estado_pago, [1, 2], true)) {
    if ($isJson) {
        payment_respond(['success' => false, 'error' => 'Indica un estado de pago valido.'], 422);
    }
    header("Location: dashboard_pago.php");
    exit;
}

try {
    $conn = Conexion::conectar();
    $conn->beginTransaction();
    $stmt = $conn->prepare(
        "SELECT sm.id_estado_pago, upper(trim(e.nombre_estado)) AS estado_solicitud
         FROM solicitud_museo sm
         INNER JOIN solicitudes s ON s.id_solicitud = sm.id_solicitud
         INNER JOIN estados e ON e.id_estado = s.id_estado
         WHERE sm.id_solicitud = ? FOR UPDATE"
    );
    $stmt->execute([$id_solicitud]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new RuntimeException('Esta solicitud no tiene un pago asociado.');
    }
    if (!in_array((string) $payment['estado_solicitud'], ['APROBADO', 'NOTIFICADO'], true)) {
        throw new RuntimeException('El pago se gestiona despues de aprobar la solicitud y notificar al visitante.');
    }

    $stmt = $conn->prepare("UPDATE solicitud_museo SET id_estado_pago = ? WHERE id_solicitud = ?");
    $stmt->execute([$id_estado_pago, $id_solicitud]);
    $conn->commit();

    if ($isJson) {
        payment_respond([
            'success' => true,
            'estado' => $id_estado_pago === 2 ? 'pagado' : 'pendiente',
        ]);
    }
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($isJson) {
        payment_respond(['success' => false, 'error' => $error->getMessage()], 422);
    }
}

header("Location: dashboard_pago.php");
exit;
