<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/conexion.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function cengi_enviar_correo(
    string $destinatario,
    string $nombre,
    string $asunto,
    string $contenidoHtml
): bool {

    $env = cengicursos_env();

    $smtpHost = $env['BREVO_SMTP_HOST'] ?? 'smtp-relay.brevo.com';
    $smtpPort = (int) ($env['BREVO_SMTP_PORT'] ?? 587);
    $smtpUser = $env['BREVO_SMTP_USER'] ?? '';
    $smtpKey = $env['BREVO_SMTP_KEY'] ?? '';

    $fromEmail = $env['BREVO_FROM_EMAIL'] ?? '';
    $fromName = $env['BREVO_FROM_NAME'] ?? 'CENGICANA';

    if (
        $smtpUser === '' ||
        $smtpKey === '' ||
        $fromEmail === ''
    ) {
        throw new RuntimeException(
            'La configuración de correo de Brevo está incompleta.'
        );
    }

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();

        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;

        $mail->Username = $smtpUser;
        $mail->Password = $smtpKey;

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $fromEmail,
            $fromName
        );

        $mail->addAddress(
            $destinatario,
            $nombre
        );

        $logoPath = __DIR__ . '/css/images/cengi.png';

        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'logo_cengicana');
        }

        $mail->isHTML(true);

        $mail->Subject = $asunto;
        $mail->Body = $contenidoHtml;

        $mail->AltBody = strip_tags($contenidoHtml);

        $mail->send();

        return true;

    } catch (Exception $e) {

        error_log(
            'Error enviando correo con Brevo: ' .
            $mail->ErrorInfo
        );

        return false;
    }
}