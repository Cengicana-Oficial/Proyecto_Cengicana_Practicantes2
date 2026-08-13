<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/conexion.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envia un correo via Brevo/PHPMailer con el layout HTML ya armado por quien
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

    // El default es "smtp-relay.sendinblue.com" (alias legado, todavia vigente,
    // del mismo servicio) y no "smtp-relay.brevo.com": desde la red de este
    // proyecto, ese segundo hostname resuelve consistentemente a un backend de
    // Brevo cuyo certificado TLS (valido, emitido por Let's Encrypt) solo
    // cubre los nombres "*.sendinblue.com" en su Subject Alternative Name, no
    // "brevo.com" -> PHP rechaza el handshake por hostname distinto
    // (verify_peer_name). Conectando directo al hostname que si esta en el SAN
    // del certificado se valida con total seguridad, sin bajar ninguna
    // verificacion. Ver deploy/env/cengicursos.env.example.
    $smtpHost = $env['BREVO_SMTP_HOST'] ?? 'smtp-relay.sendinblue.com';
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
        "Error enviando correo con Brevo (tras {$intentosMaximos} intentos): " .
        $ultimoError
    );

    return false;
}