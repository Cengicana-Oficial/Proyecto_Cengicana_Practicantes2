<?php
require_once __DIR__ . '/../includes/auth.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

lab_require_module_access();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(405, [
        'ok' => false,
        'message' => 'Metodo no permitido.',
    ]);
}

require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

try {
    $payload = leerJsonEntrada();
    $emails = normalizarCorreos($payload['emails'] ?? []);

    if (!$emails) {
        throw new RuntimeException('Ingrese al menos un correo valido para enviar la solicitud.');
    }

    $pdfBinario = decodificarPdf($payload['pdf_base64'] ?? '');
    $nombreArchivo = limpiarNombreArchivo($payload['file_name'] ?? 'solicitud.pdf');
    $solicitud = is_array($payload['solicitud'] ?? null) ? $payload['solicitud'] : [];
    $analisis = normalizarAnalisis($payload['analisis'] ?? []);

    enviarCorreoSolicitud($emails, $pdfBinario, $nombreArchivo, $solicitud, $analisis);

    responderJson(200, [
        'ok' => true,
        'message' => 'PDF generado, descargado y enviado por correo correctamente.',
    ]);
} catch (Throwable $e) {
    responderJson(400, [
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}

function responderJson(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function leerJsonEntrada(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        throw new RuntimeException('No se recibieron datos validos para enviar el correo.');
    }

    return $data;
}

function envCorreo(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }

    if ($value === false && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }

    if ($value === false || $value === '') {
        return $default;
    }

    return trim((string) $value);
}

function envCorreoBool(string $key, bool $default = false): bool
{
    $value = envCorreo($key);

    if ($value === null || $value === '') {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'si', 'on'], true);
}

function limpiarTextoCorreo($value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value));
}

function normalizarCorreos($emails): array
{
    if (is_string($emails)) {
        $emails = preg_split('/[,;]+/', $emails);
    }

    if (!is_array($emails)) {
        return [];
    }

    $validos = [];

    foreach ($emails as $email) {
        $email = trim((string) $email);

        if ($email === '') {
            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Correo no valido: {$email}");
        }

        $validos[strtolower($email)] = $email;
    }

    return array_values($validos);
}

function decodificarPdf(string $pdfBase64): string
{
    $pdfBase64 = trim($pdfBase64);
    $pdfBase64 = preg_replace('/^data:application\/pdf;base64,/', '', $pdfBase64);
    $binario = base64_decode($pdfBase64, true);

    if ($binario === false || $binario === '') {
        throw new RuntimeException('El PDF generado no pudo ser leido para enviarlo por correo.');
    }

    if (strlen($binario) > 10 * 1024 * 1024) {
        throw new RuntimeException('El PDF supera el tamaño maximo permitido para correo.');
    }

    if (strncmp($binario, '%PDF', 4) !== 0) {
        throw new RuntimeException('El archivo generado no tiene formato PDF valido.');
    }

    return $binario;
}

function limpiarNombreArchivo(string $nombre): string
{
    $nombre = trim($nombre);
    $nombre = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $nombre);
    $nombre = trim($nombre, '._-');

    if ($nombre === '') {
        $nombre = 'solicitud.pdf';
    }

    if (!preg_match('/\.pdf$/i', $nombre)) {
        $nombre .= '.pdf';
    }

    return $nombre;
}

function normalizarAnalisis($analisis): array
{
    if (!is_array($analisis)) {
        return [];
    }

    $items = [];

    foreach ($analisis as $item) {
        if (is_array($item)) {
            $nombre = limpiarTextoCorreo($item['nombre'] ?? '');
            $tipo = limpiarTextoCorreo($item['tipo'] ?? '');
        } else {
            $nombre = limpiarTextoCorreo($item);
            $tipo = '';
        }

        if ($nombre !== '') {
            $items[] = [
                'nombre' => $nombre,
                'tipo' => $tipo,
            ];
        }
    }

    return $items;
}

function valorSolicitud(array $solicitud, string $key, string $fallback = '-'): string
{
    $value = limpiarTextoCorreo($solicitud[$key] ?? '');

    return $value !== '' ? $value : $fallback;
}

function construirAsunto(array $solicitud): string
{
    $tipo = valorSolicitud($solicitud, 'tipo', 'Solicitud');
    $lote = valorSolicitud($solicitud, 'lote', '');

    return trim('Solicitud de analisis ' . $tipo . ($lote !== '' ? ' - lote ' . $lote : ''));
}

function construirHtmlCorreo(array $solicitud, array $analisis): string
{
    $filas = [
        'Tipo de muestra' => valorSolicitud($solicitud, 'tipo'),
        'Numero de lote' => valorSolicitud($solicitud, 'lote'),
        'Fecha de muestreo' => valorSolicitud($solicitud, 'fecha_muestreo'),
        'Numero de muestras' => valorSolicitud($solicitud, 'numero_muestras'),
        'Laboratorio inicio' => valorSolicitud($solicitud, 'laboratorio_inicio'),
        'Laboratorio fin' => valorSolicitud($solicitud, 'laboratorio_fin'),
        'Fecha estimada' => valorSolicitud($solicitud, 'fecha_estimada'),
        'Ingresado por' => valorSolicitud($solicitud, 'ingresado_por'),
        'Correo ingresado por' => valorSolicitud($solicitud, 'correo_ingresado_por'),
        'Recibido por' => valorSolicitud($solicitud, 'recibido_por'),
        'Correo recibido por' => valorSolicitud($solicitud, 'correo_recibido_por'),
    ];

    $html = '<p>Se adjunta el PDF de la boleta de solicitud generada.</p>';
    $html .= '<h3>Descripcion de la solicitud</h3>';
    $html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#c8dba8">';

    foreach ($filas as $label => $value) {
        $html .= '<tr>';
        $html .= '<th align="left" style="background:#eaf3de;color:#27500a">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '<td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';
    $html .= '<h3>Analisis solicitados</h3>';

    if ($analisis) {
        $html .= '<ul>';
        foreach ($analisis as $item) {
            $texto = $item['nombre'] . ($item['tipo'] !== '' ? ' (' . $item['tipo'] . ')' : '');
            $html .= '<li>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p>No se seleccionaron analisis.</p>';
    }

    $observaciones = valorSolicitud($solicitud, 'observaciones', '');
    if ($observaciones !== '') {
        $html .= '<h3>Observaciones</h3>';
        $html .= '<p>' . nl2br(htmlspecialchars($observaciones, ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    return $html;
}

function construirTextoCorreo(array $solicitud, array $analisis): string
{
    $lineas = [
        'Se adjunta el PDF de la boleta de solicitud generada.',
        '',
        'Descripcion de la solicitud:',
        'Tipo de muestra: ' . valorSolicitud($solicitud, 'tipo'),
        'Numero de lote: ' . valorSolicitud($solicitud, 'lote'),
        'Fecha de muestreo: ' . valorSolicitud($solicitud, 'fecha_muestreo'),
        'Numero de muestras: ' . valorSolicitud($solicitud, 'numero_muestras'),
        'Laboratorio inicio: ' . valorSolicitud($solicitud, 'laboratorio_inicio'),
        'Laboratorio fin: ' . valorSolicitud($solicitud, 'laboratorio_fin'),
        '',
        'Analisis solicitados:',
    ];

    if ($analisis) {
        foreach ($analisis as $item) {
            $lineas[] = '- ' . $item['nombre'] . ($item['tipo'] !== '' ? ' (' . $item['tipo'] . ')' : '');
        }
    } else {
        $lineas[] = '- No se seleccionaron analisis.';
    }

    return implode("\n", $lineas);
}

function enviarCorreoSolicitud(array $emails, string $pdfBinario, string $nombreArchivo, array $solicitud, array $analisis): void
{
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->setLanguage('es', __DIR__ . '/../vendor/phpmailer/phpmailer/language/');

    $mailer = strtolower((string) envCorreo('MAIL_MAILER', 'smtp'));
    $host = envCorreo('MAIL_HOST');

    if ($mailer === 'smtp') {
        if (!$host) {
            throw new RuntimeException('SMTP no configurado: falta MAIL_HOST en Laboratorio/.env.');
        }

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int) envCorreo('MAIL_PORT', '587');
        $mail->SMTPAuth = envCorreoBool('MAIL_SMTP_AUTH', true);
        $mail->Username = envCorreo('MAIL_USERNAME', '') ?? '';
        // Gmail app passwords often come copied with display spaces; strip them before auth.
        $mail->Password = preg_replace('/\s+/', '', envCorreo('MAIL_PASSWORD', '') ?? '');

        if ($mail->SMTPAuth && ($mail->Username === '' || $mail->Password === '')) {
            throw new RuntimeException('SMTP no configurado: faltan MAIL_USERNAME y/o MAIL_PASSWORD en Laboratorio/.env.');
        }

        $encryption = strtolower((string) envCorreo('MAIL_ENCRYPTION', 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === '' || $encryption === 'none' || $encryption === 'false') {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            throw new RuntimeException('SMTP no configurado: MAIL_ENCRYPTION debe ser tls, ssl o none.');
        }

        if (envCorreoBool('MAIL_ALLOW_SELF_SIGNED', false)) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        if (envCorreoBool('MAIL_DEBUG', false)) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function ($message, $level): void {
                error_log('[SMTP nivel ' . $level . '] ' . trim((string) $message));
            };
        }
    } elseif ($mailer === 'mail') {
        $mail->isMail();
    } else {
        throw new RuntimeException('MAIL_MAILER debe ser smtp o mail.');
    }

    $defaultFrom = filter_var($mail->Username, FILTER_VALIDATE_EMAIL) ? $mail->Username : '';
    $fromAddress = envCorreo('MAIL_FROM_ADDRESS', $defaultFrom);
    $fromName = envCorreo('MAIL_FROM_NAME', 'Laboratorios AgroLab');

    if (!$fromAddress || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP no configurado: MAIL_FROM_ADDRESS debe ser un correo valido.');
    }

    $mail->setFrom($fromAddress, $fromName);

    foreach ($emails as $email) {
        $mail->addAddress($email);
    }

    $usuario = function_exists('lab_current_user') ? lab_current_user() : [];
    if (!empty($usuario['correo']) && filter_var($usuario['correo'], FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($usuario['correo'], $usuario['nombre'] ?? '');
    }

    $mail->Subject = construirAsunto($solicitud);
    $mail->isHTML(true);
    $mail->Body = construirHtmlCorreo($solicitud, $analisis);
    $mail->AltBody = construirTextoCorreo($solicitud, $analisis);
    $mail->addStringAttachment($pdfBinario, $nombreArchivo, 'base64', 'application/pdf');

    try {
        $mail->send();
    } catch (PHPMailerException $e) {
        $ayuda = ' Revise MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION, MAIL_FROM_ADDRESS y MAIL_FROM_NAME en Laboratorio/.env.';
        throw new RuntimeException('No se pudo enviar el correo: ' . $mail->ErrorInfo . $ayuda);
    }
}
