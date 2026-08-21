<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/conexion.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envia un correo via SMTP/PHPMailer con el layout HTML ya armado por quien
 * llama (ver cengicursos/correos/*.php).
 *
 * $adjuntos es opcional (default []) para no romper a los llamadores existentes
 * (aprobar.php, rechazar.php, cron_recordatorio_cupo.php, etc.) que no adjuntan
 * nada. Cada elemento es un arreglo asociativo:
 *   ['contenido' => <bytes crudos del archivo>, 'nombre' => 'archivo.pdf', 'tipo' => 'application/pdf']
 * Se agrega con PHPMailer::addStringAttachment() (no hace falta guardar el
 * archivo en disco primero).
 */
function cengi_enviar_correo(
    string $destinatario,
    string $nombre,
    string $asunto,
    string $contenidoHtml,
    array $adjuntos = []
): bool {

    $env = cengicursos_env();

    $smtpHost = $env['MAIL_HOST'] ?? 'mail.smtp2go.com';
    $smtpPort = (int) ($env['MAIL_PORT'] ?? 2525);
    $smtpUser = $env['MAIL_USERNAME'] ?? '';
    $smtpPassword = $env['MAIL_PASSWORD'] ?? '';

    $fromEmail = $env['MAIL_FROM_ADDRESS'] ?? '';
    $fromName = $env['MAIL_FROM_NAME'] ?? 'CENGICANA';

    if (
        $smtpUser === '' ||
        $smtpPassword === '' ||
        $fromEmail === ''
    ) {
        throw new RuntimeException(
            'La configuración SMTP2GO está incompleta.'
        );
    }

    // Reintento como red de seguridad general ante fallas transitorias de red
    // (no relacionado con el problema de hostname/certificado de mas arriba,
    // que ya se resuelve conectando al host correcto).
    $intentosMaximos = 3;
    $ultimoError = '';

    for ($intento = 1; $intento <= $intentosMaximos; $intento++) {

        $mail = new PHPMailer(true);

        try {

            $mail->isSMTP();

            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;

            $mail->Username = $smtpUser;
            $mail->Password = $smtpPassword;

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

            foreach ($adjuntos as $adjunto) {
                $contenidoAdjunto = (string) ($adjunto['contenido'] ?? '');
                if ($contenidoAdjunto === '') {
                    continue;
                }
                $mail->addStringAttachment(
                    $contenidoAdjunto,
                    (string) ($adjunto['nombre'] ?? 'adjunto.pdf'),
                    PHPMailer::ENCODING_BASE64,
                    (string) ($adjunto['tipo'] ?? 'application/octet-stream')
                );
            }

            $mail->send();

            return true;

        } catch (Exception $e) {

            $ultimoError = $mail->ErrorInfo;

            if ($intento < $intentosMaximos) {
                usleep(300000);
            }
        }
    }

    error_log(
        "Error enviando correo con SMTP2GO (tras {$intentosMaximos} intentos): " .
        $ultimoError
    );

    return false;
}
