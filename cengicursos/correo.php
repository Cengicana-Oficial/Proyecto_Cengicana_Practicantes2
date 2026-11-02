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
 *
 * $ical (opcional): contenido iCalendar con METHOD:REQUEST o CANCEL. Se envía como parte
 * text/calendar para que Outlook lo muestre como invitación (ver calendario_ics_helpers.php).
 */
function cengi_enviar_correo(
    string $destinatario,
    string $nombre,
    string $asunto,
    string $contenidoHtml,
    array $adjuntos = [],
    string $ical = '',
    string $replyTo = ''
): bool {
    try {
        $ok = cengi_enviar_correo_smtp($destinatario, $nombre, $asunto, $contenidoHtml, $adjuntos, $ical, $replyTo);
    } catch (Throwable $e) {
        cengi_correo_registrar($destinatario, $nombre, $asunto, false, $e->getMessage());
        throw $e;
    }
    cengi_correo_registrar($destinatario, $nombre, $asunto, $ok, $ok ? '' : 'El servidor de correo rechazó el envío.');
    return $ok;
}

/**
 * Deja constancia de cada correo enviado por el modulo (aprobaciones, gafetes,
 * recordatorios de cupo, etc.) en "comunicaciones", para verlos en
 * comunicaciones.php. Las comunicaciones de planificacion_helpers.php ya se registran
 * solas y desactivan esto con $GLOBALS['cengi_correo_sin_registro'].
 * $GLOBALS['cengi_correo_categoria'] permite a un llamador indicar la categoria.
 * Nunca interrumpe el envio: si la tabla no existe (migracion pendiente) se ignora.
 */
function cengi_correo_registrar(string $destinatario, string $nombre, string $asunto, bool $ok, string $error): void
{
    if (!empty($GLOBALS['cengi_correo_sin_registro'])) {
        return;
    }
    try {
        $db = conectar();
        $db->prepare("INSERT INTO comunicaciones (categoria, origen, asunto, mensaje, estado, enviados, fallidos, creado_por_nombre, enviado_en)
            VALUES (?, 'sistema', ?, '', ?, ?, ?, ?, NOW())")->execute([
            (string) ($GLOBALS['cengi_correo_categoria'] ?? 'Correo del sistema'),
            mb_substr($asunto, 0, 255, 'UTF-8'),
            $ok ? 'enviada' : 'fallida',
            $ok ? 1 : 0,
            $ok ? 0 : 1,
            trim((string) ($_SESSION['usuario'] ?? '')) ?: 'Sistema',
        ]);
        $db->prepare('INSERT INTO comunicacion_destinatarios (comunicacion_id, nombre, correo, estado, error) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int) $db->lastInsertId(), $nombre, $destinatario, $ok ? 'enviado' : 'fallido', mb_substr($error, 0, 255, 'UTF-8')]);
    } catch (Throwable $e) {
        error_log('No fue posible registrar el correo enviado: ' . $e->getMessage());
    }
}

function cengi_enviar_correo_smtp(
    string $destinatario,
    string $nombre,
    string $asunto,
    string $contenidoHtml,
    array $adjuntos = [],
    string $ical = '',
    string $replyTo = ''
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

            if ($replyTo !== '') $mail->addReplyTo($replyTo);
            $mail->addAddress(
                $destinatario,
                $nombre
            );

            $logoPath = __DIR__ . '/css/images/cengi.png';

            // PHPMailer solo incluye la parte text/calendar en correos sin imágenes embebidas.
            if ($ical === '' && file_exists($logoPath)) {
                $mail->addEmbeddedImage($logoPath, 'logo_cengicana');
            }

            if ($ical !== '') {
                $mail->Ical = $ical;
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
