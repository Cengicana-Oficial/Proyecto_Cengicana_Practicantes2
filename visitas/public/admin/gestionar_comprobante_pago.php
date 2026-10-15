<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/VisitStorage.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function receipt_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!can_access('gestionar_pagos')) {
    receipt_response(['success' => false, 'error' => 'No tienes permiso para revisar pagos.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    receipt_response(['success' => false, 'error' => 'Método no permitido.'], 405);
}
if (!hash_equals((string) ($_SESSION['visits_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
    receipt_response(['success' => false, 'error' => 'La sesión venció. Actualiza la página.'], 419);
}

$requestId = (int) ($_POST['id_solicitud'] ?? 0);
$file = $_FILES['comprobante'] ?? null;
if ($requestId <= 0 || !is_array($file)) {
    receipt_response(['success' => false, 'error' => 'Selecciona una solicitud y un comprobante.'], 422);
}

try {
    $conn = Conexion::conectar();
    $stmt = $conn->prepare(
        "SELECT sm.id_solicitud, sm.total, sm.id_estado_pago, sm.ruta_comprobante_pago,
                sm.nombre_comprobante_pago, upper(trim(e.nombre_estado)) AS estado_solicitud
         FROM solicitud_museo sm
         INNER JOIN solicitudes s ON s.id_solicitud = sm.id_solicitud
         INNER JOIN estados e ON e.id_estado = s.id_estado
         WHERE sm.id_solicitud = ?
         FOR UPDATE"
    );
    $stmt->execute([$requestId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || (float) $payment['total'] <= 0) {
        throw new RuntimeException('Esta solicitud no tiene un monto de pago asociado.');
    }
    if (!in_array((string) $payment['estado_solicitud'], ['APROBADO', 'NOTIFICADO'], true)) {
        throw new RuntimeException('El comprobante se puede revisar después de aprobar y notificar la visita.');
    }

    [$storedPath, $storedName] = VisitStorage::storeUploadedFile(
        $file,
        'comprobantes',
        ['pdf', 'jpg', 'jpeg', 'png', 'webp']
    );

    $conn->beginTransaction();
    $update = $conn->prepare(
        "UPDATE solicitud_museo
         SET ruta_comprobante_pago = ?, nombre_comprobante_pago = ?,
             fecha_comprobante_pago = CURRENT_TIMESTAMP, id_estado_pago = 2
         WHERE id_solicitud = ?"
    );
    $update->execute([$storedPath, $storedName, $requestId]);

    // El evento queda disponible en el seguimiento de la visita.
    $event = $conn->prepare(
        "INSERT INTO solicitud_eventos_visita (id_solicitud, tipo, descripcion, actor)
         VALUES (?, 'pago', ?, ?)"
    );
    $event->execute([$requestId, 'Comprobante de pago cargado y pago marcado como pagado por el revisor.', 'Revisor de pagos']);
    $conn->commit();

    $oldPath = (string) ($payment['ruta_comprobante_pago'] ?? '');
    if ($oldPath !== '' && $oldPath !== $storedPath && Conexion::driver() === 'pgsql') {
        try { VisitStorage::deleteObject($oldPath); } catch (Throwable $ignored) { /* no bloquear el comprobante nuevo */ }
    }
    receipt_response([
        'success' => true,
        'estado' => 'pagado',
        'name' => $storedName,
        'date' => date('c'),
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    receipt_response(['success' => false, 'error' => $error->getMessage()], 422);
}
