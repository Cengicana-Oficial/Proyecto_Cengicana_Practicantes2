<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/VisitStorage.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_login();
require_once __DIR__ . '/../../config/public_urls.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

function payment_form_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!can_access('gestionar_pagos')) {
    payment_form_response(['success' => false, 'error' => 'No tienes permiso para revisar pagos.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    payment_form_response(['success' => false, 'error' => 'Método no permitido.'], 405);
}
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    payment_form_response(['success' => false, 'error' => 'Solicitud inválida.'], 400);
}
if (!hash_equals((string) ($_SESSION['visits_csrf'] ?? ''), (string) ($payload['csrf'] ?? ''))) {
    payment_form_response(['success' => false, 'error' => 'La sesión venció. Actualiza la página.'], 419);
}
$requestId = (int) ($payload['id_solicitud'] ?? 0);
$action = strtolower(trim((string) ($payload['action'] ?? 'prepare')));
if ($requestId <= 0 || !in_array($action, ['prepare', 'send_email'], true)) {
    payment_form_response(['success' => false, 'error' => 'Datos de pago inválidos.'], 422);
}

function payment_public_url(string $token): string
{
    return visitas_public_url('formulario_pago.php') . '?token=' . rawurlencode($token);
}

function payment_email(string $to, array $visit, string $url): bool
{
    $mailHost = (string) (Conexion::setting('MAIL_HOST', '') ?: '');
    $mailPort = (string) (Conexion::setting('MAIL_PORT', '') ?: '');
    $mailUser = (string) (Conexion::setting('MAIL_USER', '') ?: '');
    $mailPass = (string) (Conexion::setting('MAIL_PASS', '') ?: '');
    $mailName = (string) (Conexion::setting('MAIL_NAME', 'CENGICAÑA') ?: 'CENGICAÑA');
    if ($mailHost === '' || $mailPort === '' || $mailUser === '' || $mailPass === '') {
        throw new RuntimeException('La configuración de correo no está completa.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $mailHost;
    $mail->SMTPAuth = true;
    $mail->Username = $mailUser;
    $mail->Password = $mailPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) $mailPort;
    $mail->setFrom($mailUser, $mailName);
    $mail->addAddress($to, (string) $visit['nombre_solicitante']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Formulario de comprobante de pago · Solicitud #' . $visit['id_solicitud'];
    $safeName = htmlspecialchars((string) $visit['nombre_solicitante'], ENT_QUOTES, 'UTF-8');
    $safeInstitution = htmlspecialchars((string) $visit['nombre_institucion'], ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars((string) $visit['fecha_visita'], ENT_QUOTES, 'UTF-8');
    $safeTime = htmlspecialchars(substr((string) $visit['hora_visita'], 0, 5), ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $total = number_format((float) $visit['total'], 2);
    $mail->Body = <<<HTML
<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;color:#1b3d2f;line-height:1.6">
<h2 style="color:#2e7d32">Comprobante de pago · CENGICAÑA</h2>
<p>Hola <strong>{$safeName}</strong>,</p>
<p>Para completar la solicitud de visita <strong>#{$visit['id_solicitud']}</strong>, utiliza el siguiente formulario para adjuntar tu comprobante de pago.</p>
<table cellpadding="6" style="background:#f4faf5;border:1px solid #d6ead9;border-radius:8px"><tr><td><strong>Institución</strong></td><td>{$safeInstitution}</td></tr><tr><td><strong>Fecha y hora</strong></td><td>{$safeDate} · {$safeTime}</td></tr><tr><td><strong>Total</strong></td><td>Q {$total}</td></tr></table>
<p><a href="{$safeUrl}" style="display:inline-block;padding:12px 18px;background:#2e7d32;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Adjuntar comprobante</a></p>
<p style="color:#68776e;font-size:12px">Si el botón no funciona, copia este enlace:<br>{$safeUrl}</p></body></html>
HTML;
    return $mail->send();
}

try {
    $conn = Conexion::conectar();
    $areasAggregate = Conexion::groupConcat('ai.nombre_area', true);
    $sql = "SELECT s.id_solicitud,s.fecha_visita,s.hora_visita,s.tipo_visita,
                   so.nombre_solicitante,so.nombre_institucion,so.correo,
                   e.nombre_estado,COALESCE(m.total,0) AS total,
                   m.id_estado_pago,m.pago_token,m.ruta_comprobante_pago,
                   m.nombre_comprobante_pago,m.fecha_comprobante_pago,
                   m.correo_formulario_pago_enviado,{$areasAggregate} AS areas
            FROM solicitudes s
            JOIN solicitantes so ON so.id_solicitante=s.id_solicitante
            JOIN estados e ON e.id_estado=s.id_estado
            LEFT JOIN solicitud_museo m ON m.id_solicitud=s.id_solicitud
            LEFT JOIN solicitud_areas sa ON sa.id_solicitud=s.id_solicitud
            LEFT JOIN areas_interes ai ON ai.id_area=sa.id_area
            WHERE s.id_solicitud=?
            GROUP BY s.id_solicitud,s.fecha_visita,s.hora_visita,s.tipo_visita,
                     so.nombre_solicitante,so.nombre_institucion,so.correo,
                     e.nombre_estado,m.total,m.id_estado_pago,m.pago_token,
                     m.ruta_comprobante_pago,m.nombre_comprobante_pago,
                     m.fecha_comprobante_pago,m.correo_formulario_pago_enviado";
    $statement = $conn->prepare($sql);
    $statement->execute([$requestId]);
    $visit = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$visit || (float) $visit['total'] <= 0) {
        throw new RuntimeException('Esta solicitud no tiene un monto de pago asociado.');
    }
    $state = strtoupper(trim((string) $visit['nombre_estado']));
    if (!in_array($state, ['APROBADO', 'NOTIFICADO'], true)) {
        throw new RuntimeException('El formulario de pago estará disponible después de aprobar y notificar la visita.');
    }
    if (trim((string) ($visit['pago_token'] ?? '')) === '') {
        $token = bin2hex(random_bytes(20));
        $update = $conn->prepare('UPDATE solicitud_museo SET pago_token=?, fecha_envio_formulario_pago=COALESCE(fecha_envio_formulario_pago,CURRENT_TIMESTAMP) WHERE id_solicitud=?');
        $update->execute([$token, $requestId]);
        $visit['pago_token'] = $token;
    }
    $url = payment_public_url((string) $visit['pago_token']);
    if ($action === 'send_email') {
        if (trim((string) $visit['correo']) === '') throw new RuntimeException('La solicitud no tiene correo electrónico.');
        payment_email((string) $visit['correo'], $visit, $url);
        $conn->prepare('UPDATE solicitud_museo SET correo_formulario_pago_enviado=1,fecha_envio_formulario_pago=CURRENT_TIMESTAMP WHERE id_solicitud=?')->execute([$requestId]);
    }
    payment_form_response([
        'success' => true,
        'action' => $action,
        'payment_url' => $url,
        'email' => (string) $visit['correo'],
        'receipt' => !empty($visit['ruta_comprobante_pago']) ? [
            'name' => (string) ($visit['nombre_comprobante_pago'] ?? ''),
            'date' => (string) ($visit['fecha_comprobante_pago'] ?? ''),
        ] : null,
    ]);
} catch (Throwable $error) {
    payment_form_response(['success' => false, 'error' => $error->getMessage()], 422);
}
