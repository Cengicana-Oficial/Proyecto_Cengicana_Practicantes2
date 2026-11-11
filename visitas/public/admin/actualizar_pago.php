<?php
require_once("../../config/auth.php");
require_login();
require_once __DIR__ . '/../../config/payment_details.php';

$isJson = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');

function payment_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function payment_flash(string $type, string $message): void
{
    $_SESSION['visits_payment_flash'] = ['type' => $type, 'message' => $message];
}

/** Responde el error según el origen: JSON para fetch, mensaje y redirección para formularios. */
function payment_fail(bool $isJson, string $message, int $status): void
{
    if ($isJson) {
        payment_respond(['success' => false, 'error' => $message], $status);
    }
    payment_flash('error', $message);
    header("Location: dashboard_pago.php");
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
    payment_fail($isJson, 'Solicitud inválida.', 400);
}

if (empty($_SESSION['visits_csrf']) || !hash_equals(
    (string) ($_SESSION['visits_csrf'] ?? ''),
    (string) ($payload['csrf'] ?? '')
)) {
    payment_fail($isJson, 'La sesión venció. Actualiza la página e inténtalo de nuevo.', 403);
}

$id_solicitud = isset($payload['id_solicitud']) ? (int) $payload['id_solicitud'] : 0;

// accion: pagar | pendiente | nota | editar. Se conserva compatibilidad con
// los clientes anteriores que envían id_estado_pago o estado.
$action = strtolower(trim((string) ($payload['accion'] ?? '')));
if ($action === '') {
    $requestedState = strtolower(trim((string) ($payload['estado'] ?? '')));
    $legacyState = isset($payload['id_estado_pago'])
        ? (int) $payload['id_estado_pago']
        : ($requestedState === 'pagado' ? 2 : ($requestedState === 'pendiente' ? 1 : 0));
    $action = $legacyState === 2 ? 'pagar' : ($legacyState === 1 ? 'pendiente' : '');
}

if (!$id_solicitud || !in_array($action, ['pagar', 'pendiente', 'nota', 'editar'], true)) {
    payment_fail($isJson, 'Indica una acción de pago válida.', 422);
}

$hasAmount = array_key_exists('monto_pagado', $payload) && trim((string) $payload['monto_pagado']) !== '';
$amount = null;
if ($hasAmount) {
    $rawAmount = str_replace([',', 'Q', 'q', ' '], ['', '', '', ''], (string) $payload['monto_pagado']);
    if (!is_numeric($rawAmount) || (float) $rawAmount < 0 || (float) $rawAmount > 99999999) {
        payment_fail($isJson, 'El monto pagado debe ser un número mayor o igual a 0.', 422);
    }
    $amount = round((float) $rawAmount, 2);
}
$hasNote = array_key_exists('observacion_pago', $payload);
$note = trim(preg_replace('/\s+/u', ' ', (string) ($payload['observacion_pago'] ?? '')));
if (mb_strlen($note) > 1000) {
    payment_fail($isJson, 'La observación no puede superar 1000 caracteres.', 422);
}

try {
    $conn = Conexion::conectar();
    $hasDetails = visitas_payment_details_available($conn);
    if (!$hasDetails && in_array($action, ['nota', 'editar'], true)) {
        throw new RuntimeException('Falta aplicar la migración 40_visit_payment_details.sql para guardar montos y observaciones.');
    }

    $conn->beginTransaction();
    $stmt = $conn->prepare(
        "SELECT sm.id_estado_pago, sm.total, upper(trim(e.nombre_estado)) AS estado_solicitud
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
    $isPaid = (int) $payment['id_estado_pago'] === 2;
    if ($action === 'editar' && !$isPaid) {
        throw new RuntimeException('Solo se puede editar el monto de un pago registrado. Usa "Marcar pagado".');
    }

    $actor = mb_substr((string) ($_SESSION['nombre'] ?? 'Usuario'), 0, 150);
    $sets = [];
    $params = [];
    if ($action === 'pagar') {
        $sets[] = 'id_estado_pago = 2';
        if ($hasDetails) {
            $sets[] = 'monto_pagado = ?';
            $params[] = $hasAmount ? $amount : round((float) $payment['total'], 2);
            $sets[] = 'fecha_pago = CURRENT_TIMESTAMP';
        }
    } elseif ($action === 'pendiente') {
        $sets[] = 'id_estado_pago = 1';
        if ($hasDetails) {
            $sets[] = 'monto_pagado = NULL';
            $sets[] = 'fecha_pago = NULL';
        }
    } elseif ($action === 'editar' && $hasAmount) {
        $sets[] = 'monto_pagado = ?';
        $params[] = $amount;
    }
    if ($hasDetails) {
        if ($hasNote) {
            $sets[] = 'observacion_pago = ?';
            $params[] = $note === '' ? null : $note;
        }
        $sets[] = 'pago_actualizado_por = ?';
        $params[] = $actor;
        $sets[] = 'pago_actualizado_en = CURRENT_TIMESTAMP';
    }

    $params[] = $id_solicitud;
    $stmt = $conn->prepare('UPDATE solicitud_museo SET ' . implode(', ', $sets) . ' WHERE id_solicitud = ?');
    $stmt->execute($params);
    $conn->commit();

    $finalPaid = $action === 'pagar' || ($action !== 'pendiente' && $isPaid);
    if ($isJson) {
        payment_respond([
            'success' => true,
            'estado' => $finalPaid ? 'pagado' : 'pendiente',
        ]);
    }
    $messages = [
        'pagar' => "La solicitud #{$id_solicitud} quedó marcada como pagada.",
        'pendiente' => "El pago de la solicitud #{$id_solicitud} volvió a pendiente.",
        'nota' => "Se guardó la observación de la solicitud #{$id_solicitud}.",
        'editar' => "Se actualizó el pago de la solicitud #{$id_solicitud}.",
    ];
    payment_flash('ok', $messages[$action]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    payment_fail($isJson, $error instanceof RuntimeException
        ? $error->getMessage()
        : 'No se pudo actualizar el pago. Inténtalo de nuevo.', 422);
}

header("Location: dashboard_pago.php");
exit;
