<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/auth.php';
require_login();
require_once __DIR__ . '/../../config/VisitGuestForm.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$json = static function (bool $success, string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, $success ? 'message' : 'error' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
};

if (!can_access_any(['gestionar_solicitudes', 'enviar_correos'])) {
    $json(false, 'No tienes permiso para enviar formularios.', 403);
}

$requestId = (int) ($_POST['id_solicitud'] ?? 0);
$sendEmail = (string) ($_POST['send_email'] ?? '') === '1';
$providedToken = trim((string) ($_POST['token'] ?? ''));
if ($requestId <= 0) {
    $json(false, 'ID de solicitud no proporcionado.', 400);
}

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
    $dotenv->safeLoad();
    $conn = Conexion::conectar();
    $stmt = $conn->prepare(
        'select s.id_solicitud, s.fecha_visita, s.hora_visita, s.cantidad_visitantes,
                e.nombre_estado, so.nombre_solicitante, so.nombre_institucion, so.correo
         from solicitudes s
         join estados e on e.id_estado = s.id_estado
         join solicitantes so on so.id_solicitante = s.id_solicitante
         where s.id_solicitud = ? limit 1'
    );
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        $json(false, 'Solicitud no encontrada.', 404);
    }

    $status = strtolower(trim((string) $request['nombre_estado']));
    if (!in_array($status, ['aprobado', 'notificado', 'notificada'], true)) {
        $json(false, 'El formulario solo puede enviarse cuando la visita está aprobada.', 422);
    }
    if ($sendEmail && trim((string) $request['correo']) === '') {
        $json(false, 'La solicitud no tiene correo electrónico de contacto.', 422);
    }

    if ($sendEmail && preg_match('/^[a-f0-9]{48}$/i', $providedToken)) {
        $linkedRequest = VisitGuestForm::find($conn, $providedToken);
        if (!$linkedRequest || (int) $linkedRequest['id_solicitud'] !== $requestId) {
            $json(false, 'El enlace del formulario ya no es válido. Genera uno nuevo.', 422);
        }
        $link = VisitGuestForm::publicUrl($providedToken);
    } else {
        $link = VisitGuestForm::issue($conn, $requestId);
    }
    // Primero se genera el enlace sin depender de SMTP. Así el administrador
    // puede copiarlo y compartirlo por WhatsApp u otro medio.
    if (!$sendEmail) {
        $json(true, 'Enlace del formulario generado correctamente.', 200, [
            'request_id' => $requestId,
            'url' => $link,
            'mail_sent' => false,
        ]);
    }

    $name = htmlspecialchars((string) $request['nombre_solicitante'], ENT_QUOTES, 'UTF-8');
    $institution = htmlspecialchars((string) $request['nombre_institucion'], ENT_QUOTES, 'UTF-8');
    $date = htmlspecialchars((string) $request['fecha_visita'], ENT_QUOTES, 'UTF-8');
    $time = htmlspecialchars(substr((string) $request['hora_visita'], 0, 5), ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $host = trim((string) ($_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST') ?: ''));
    $port = (int) ($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?: 0);
    $user = trim((string) ($_ENV['MAIL_USER'] ?? getenv('MAIL_USER') ?: ''));
    $pass = (string) ($_ENV['MAIL_PASS'] ?? getenv('MAIL_PASS') ?: '');
    $mailName = (string) ($_ENV['MAIL_NAME'] ?? getenv('MAIL_NAME') ?: 'CENGICAÑA');
    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        $json(false, 'La configuración SMTP no está completa.', 500);
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $user;
    $mail->Password = $pass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $port;
    $mail->setFrom($user, $mailName);
    $mail->addAddress((string) $request['correo'], (string) $request['nombre_solicitante']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Registro de visitantes — visita a CENGICAÑA';
    $mail->Body = <<<HTML
<!doctype html><html lang="es"><head><meta charset="UTF-8"><title>Registro de visitantes</title></head>
<body style="margin:0;background:#f3f7f4;font-family:Arial,sans-serif;color:#173b29;padding:24px">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:14px;padding:30px">
<tr><td><h2 style="color:#2e7d32;margin-top:0">Registro de visitantes</h2>
<p>Hola <strong>{$name}</strong>,</p>
<p>Para completar la preparación de tu visita, registra los nombres de las personas que asistirán.</p>
<p><strong>Institución:</strong> {$institution}<br><strong>Fecha:</strong> {$date}<br><strong>Hora:</strong> {$time}<br><strong>Cantidad solicitada:</strong> {$request['cantidad_visitantes']}</p>
<p style="margin:26px 0"><a href="{$safeLink}" style="display:inline-block;background:#2e7d32;color:#fff;text-decoration:none;padding:13px 20px;border-radius:8px;font-weight:bold">Registrar visitantes</a></p>
<p style="color:#68796e;font-size:13px">El enlace está vinculado exclusivamente a esta solicitud y vencerá después de la fecha de la visita.</p>
</td></tr></table></td></tr></table></body></html>
HTML;
    $mail->send();
    $json(true, 'Formulario de visitantes enviado correctamente.', 200, ['request_id' => $requestId, 'url' => $link]);
} catch (Throwable $e) {
    error_log('Error al enviar formulario de visitantes #' . $requestId . ': ' . $e->getMessage());
    $json(false, $sendEmail
        ? 'No fue posible enviar el correo. Verifica la configuración SMTP.'
        : 'No fue posible generar el enlace. Verifica que hayas ejecutado la migración 34_visit_guest_forms.sql.', 500);
}
