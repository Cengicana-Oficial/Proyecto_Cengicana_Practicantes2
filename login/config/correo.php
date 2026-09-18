<?php

/**
 * Envio de correo via SMTP2GO/PHPMailer para el modulo login/ (flujo
 * "olvide mi contrasena": login/forgot_password.php -> login/reset_password.php).
 * Mismo patron que cengicursos/correo.php (host/puerto/STARTTLS, 3 reintentos,
 * log del ultimo error), adaptado a como login/ carga su .env (vlucas/phpdotenv
 * via Conexion, ver login/config/conexion.php) en vez del lector simple de
 * cengicursos/conexion.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function cengi_login_cargar_env()
{
    static $cargado = false;

    if ($cargado) {
        return;
    }

    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
    $cargado = true;
}

/**
 * Envia el correo con el enlace de reseteo de contrasena.
 * Devuelve true si el correo se envio correctamente, false si fallo (el
 * error de detalle queda en error_log; el llamador NO debe mostrar ese
 * detalle al usuario ni revelar si el correo existe en la BD).
 */
function cengi_login_enviar_correo_reset(string $destinatario, string $nombre, string $enlace): bool
{
    cengi_login_cargar_env();

    $smtpHost = $_ENV['MAIL_HOST'] ?? 'mail.smtp2go.com';
    $smtpPort = (int) ($_ENV['MAIL_PORT'] ?? 2525);
    $smtpUser = $_ENV['MAIL_USERNAME'] ?? '';
    $smtpPassword = $_ENV['MAIL_PASSWORD'] ?? '';

    $fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? '';
    $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CENGICANA';

    if ($smtpUser === '' || $smtpPassword === '' || $fromEmail === '') {
        error_log('login: configuracion SMTP2GO incompleta (MAIL_USERNAME/MAIL_PASSWORD/MAIL_FROM_ADDRESS); no se pudo enviar el correo de reseteo.');
        return false;
    }

    $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $enlaceSeguro = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');

    $contenidoHtml = "
        <div style=\"font-family: 'DM Sans', Arial, sans-serif; max-width: 480px; margin: 0 auto; color: #1a2e0a;\">
            <h2 style=\"color: #73BC25;\">CENGICAÑA</h2>
            <p>Hola {$nombreSeguro},</p>
            <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta. Si fuiste tú, haz clic en el siguiente enlace para continuar:</p>
            <p style=\"text-align: center; margin: 24px 0;\">
                <a href=\"{$enlaceSeguro}\" style=\"background: #73BC25; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;\">Restablecer contraseña</a>
            </p>
            <p>O copia y pega este enlace en tu navegador:<br><a href=\"{$enlaceSeguro}\">{$enlaceSeguro}</a></p>
            <p>Este enlace expira en 60 minutos y solo puede usarse una vez.</p>
            <p>Si tú no solicitaste este cambio, puedes ignorar este correo; tu contraseña seguirá siendo la misma.</p>
        </div>
    ";

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

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($destinatario, $nombre);

            $mail->isHTML(true);
            $mail->Subject = 'Restablecer tu contraseña - CENGICAÑA';
            $mail->Body = $contenidoHtml;
            $mail->AltBody = "Hola {$nombre}, para restablecer tu contraseña visita: {$enlace} (expira en 60 minutos, un solo uso).";

            $mail->send();

            return true;

        } catch (Exception $e) {

            $ultimoError = $mail->ErrorInfo;

            if ($intento < $intentosMaximos) {
                usleep(300000);
            }
        }
    }

    error_log("login: error enviando correo de reseteo con SMTP2GO (tras {$intentosMaximos} intentos): {$ultimoError}");

    return false;
}
