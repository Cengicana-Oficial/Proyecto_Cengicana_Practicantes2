<?php
require_once __DIR__ . '/../includes/auth.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

header('Content-Type: application/json; charset=UTF-8');

if (!lab_is_authenticated()) {
    responderJson(401, [
        'ok' => false,
        'message' => 'La sesion vencio. Inicie sesion nuevamente antes de enviar la solicitud.',
    ]);
}

if (!lab_has_module_access()) {
    responderJson(403, [
        'ok' => false,
        'message' => 'Su usuario no tiene acceso al modulo Laboratorio.',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(405, [
        'ok' => false,
        'message' => 'Metodo no permitido.',
    ]);
}

try {
    cargarPhpMailer();
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

function cargarPhpMailer(): void
{
    if (class_exists(PHPMailer::class)) {
        return;
    }

    $autoloaders = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../cengicursos/vendor/autoload.php',
    ];

    foreach ($autoloaders as $autoloader) {
        if (is_file($autoloader)) {
            require_once $autoloader;

            if (class_exists(PHPMailer::class)) {
                return;
            }
        }
    }

    throw new RuntimeException('No se encontro la dependencia PHPMailer necesaria para enviar el correo.');
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
        $configuracionCompartida = configuracionCorreoCompartida();
        $value = $configuracionCompartida[$key] ?? null;
    }

    if ($value === null || $value === false || $value === '') {
        return $default;
    }

    return trim((string) $value);
}

function configuracionCorreoCompartida(): array
{
    static $configuracion = null;

    if (is_array($configuracion)) {
        return $configuracion;
    }

    $configuracion = [];
    $archivo = __DIR__ . '/../../cengicursos/.env';

    if (!is_file($archivo) || !is_readable($archivo)) {
        return $configuracion;
    }

    $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) {
        return $configuracion;
    }

    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
            continue;
        }

        [$nombre, $valor] = explode('=', $linea, 2);
        $nombre = trim($nombre);

        if (strpos($nombre, 'MAIL_') !== 0) {
            continue;
        }

        $configuracion[$nombre] = trim(trim($valor), "'\"");
    }

    return $configuracion;
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

    return trim('CENGICAÑA | Solicitud de analisis ' . $tipo . ($lote !== '' ? ' - lote ' . $lote : ''));
}

function construirHtmlCorreo(array $solicitud, array $analisis): string
{
    $filas = [
        'Tipo de muestra' => valorSolicitud($solicitud, 'tipo'),
        'Cliente / institucion' => valorSolicitud($solicitud, 'institucion'),
        'Responsable del envio' => valorSolicitud($solicitud, 'responsable_envio'),
        'Numero de lote' => valorSolicitud($solicitud, 'lote'),
        'Codigo de muestreo' => valorSolicitud($solicitud, 'codigo_muestreo'),
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

    $esc = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    $tipo = valorSolicitud($solicitud, 'tipo');
    $lote = valorSolicitud($solicitud, 'lote');
    $numeroMuestras = valorSolicitud($solicitud, 'numero_muestras');
    $laboratorioInicio = valorSolicitud($solicitud, 'laboratorio_inicio');
    $laboratorioFin = valorSolicitud($solicitud, 'laboratorio_fin');
    $rangoLaboratorio = $laboratorioInicio === $laboratorioFin
        ? $laboratorioInicio
        : $laboratorioInicio . ' a ' . $laboratorioFin;

    $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    $html .= '<body style="margin:0;padding:0;background:#f3f6f1;color:#1f2923;font-family:Arial,Helvetica,sans-serif">';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f3f6f1">';
    $html .= '<tr><td align="center" style="padding:28px 12px">';
    $html .= '<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:680px;background:#ffffff;border-collapse:collapse;border-top:8px solid #73bf3f">';

    $html .= '<tr><td style="padding:26px 30px 22px;border-bottom:1px solid #d7e2d2">';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
    $html .= '<td valign="middle"><div style="font-size:24px;line-height:28px;font-weight:800;letter-spacing:1px;color:#1f542d">CENGICAÑA</div>';
    $html .= '<div style="margin-top:4px;font-size:13px;line-height:18px;color:#657168">Laboratorio Agroindustrial</div></td>';
    $html .= '<td valign="middle" align="right"><span style="display:inline-block;padding:7px 12px;background:#edf6e8;color:#285b32;font-size:11px;line-height:14px;font-weight:700;letter-spacing:.5px">NUEVA SOLICITUD</span></td>';
    $html .= '</tr></table></td></tr>';

    $html .= '<tr><td style="padding:26px 30px 8px">';
    $html .= '<div style="font-size:20px;line-height:27px;font-weight:700;color:#1f2923">Boleta de solicitud de analisis</div>';
    $html .= '<div style="margin-top:8px;font-size:14px;line-height:21px;color:#657168">Se adjunta el PDF oficial con el detalle completo de la solicitud y las firmas registradas.</div>';
    $html .= '</td></tr>';

    $html .= '<tr><td style="padding:18px 30px 24px">';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#1f542d;border-left:7px solid #a8d52f">';
    $html .= '<tr><td style="padding:20px 22px">';
    $html .= '<div style="font-size:10px;line-height:14px;font-weight:700;letter-spacing:.8px;color:#dcebd5">LOTE</div>';
    $html .= '<div style="margin-top:4px;font-size:27px;line-height:32px;font-weight:800;color:#ffffff">' . $esc($lote) . '</div>';
    $html .= '<div style="margin-top:7px;font-size:12px;line-height:18px;color:#dcebd5">' . $esc($tipo) . '</div>';
    $html .= '</td><td width="45%" valign="middle" style="padding:20px 22px;border-left:1px solid #477552">';
    $html .= '<div style="font-size:10px;line-height:14px;font-weight:700;color:#dcebd5">MUESTRAS</div>';
    $html .= '<div style="margin-top:3px;font-size:17px;line-height:22px;font-weight:700;color:#ffffff">' . $esc($numeroMuestras) . '</div>';
    $html .= '<div style="margin-top:10px;font-size:10px;line-height:14px;font-weight:700;color:#dcebd5">RANGO DE LABORATORIO</div>';
    $html .= '<div style="margin-top:3px;font-size:13px;line-height:18px;font-weight:700;color:#ffffff">' . $esc($rangoLaboratorio) . '</div>';
    $html .= '</td></tr></table></td></tr>';

    $html .= '<tr><td style="padding:0 30px 10px">';
    $html .= '<div style="padding-left:10px;border-left:4px solid #73bf3f;font-size:13px;line-height:18px;font-weight:800;letter-spacing:.4px;color:#1f542d">DATOS DE LA SOLICITUD</div>';
    $html .= '</td></tr>';
    $html .= '<tr><td style="padding:8px 30px 26px">';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;border:1px solid #d7e2d2">';

    $rowIndex = 0;
    foreach ($filas as $label => $value) {
        $background = $rowIndex % 2 === 0 ? '#ffffff' : '#f8faf7';
        $html .= '<tr style="background:' . $background . '">';
        $html .= '<td width="38%" valign="top" style="padding:10px 12px;border-bottom:1px solid #e2e9df;font-size:11px;line-height:16px;font-weight:700;color:#657168">' . $esc($label) . '</td>';
        $html .= '<td valign="top" style="padding:10px 12px;border-bottom:1px solid #e2e9df;font-size:12px;line-height:17px;color:#1f2923">' . $esc($value) . '</td>';
        $html .= '</tr>';
        $rowIndex++;
    }

    $html .= '</table></td></tr>';
    $html .= '<tr><td style="padding:0 30px 10px">';
    $html .= '<div style="padding-left:10px;border-left:4px solid #73bf3f;font-size:13px;line-height:18px;font-weight:800;letter-spacing:.4px;color:#1f542d">ANALISIS SOLICITADOS</div>';
    $html .= '</td></tr>';
    $html .= '<tr><td style="padding:8px 30px 26px">';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;border:1px solid #d7e2d2">';
    $html .= '<tr style="background:#1f542d"><td style="padding:10px 12px;font-size:10px;line-height:14px;font-weight:700;color:#ffffff">ANALISIS</td>';
    $html .= '<td width="28%" style="padding:10px 12px;font-size:10px;line-height:14px;font-weight:700;color:#ffffff">CATEGORIA</td></tr>';

    if ($analisis) {
        foreach ($analisis as $index => $item) {
            $background = $index % 2 === 0 ? '#ffffff' : '#f8faf7';
            $html .= '<tr style="background:' . $background . '">';
            $html .= '<td valign="top" style="padding:10px 12px;border-bottom:1px solid #e2e9df;font-size:12px;line-height:17px;color:#1f2923">' . $esc($item['nombre']) . '</td>';
            $html .= '<td valign="top" style="padding:10px 12px;border-bottom:1px solid #e2e9df;font-size:11px;line-height:17px;color:#657168">' . $esc($item['tipo'] !== '' ? $item['tipo'] : '-') . '</td>';
            $html .= '</tr>';
        }
    } else {
        $html .= '<tr><td colspan="2" style="padding:14px 12px;font-size:12px;line-height:17px;color:#657168">No se seleccionaron analisis.</td></tr>';
    }

    $html .= '</table></td></tr>';
    $observaciones = valorSolicitud($solicitud, 'observaciones', '');
    if ($observaciones !== '') {
        $html .= '<tr><td style="padding:0 30px 10px">';
        $html .= '<div style="padding-left:10px;border-left:4px solid #73bf3f;font-size:13px;line-height:18px;font-weight:800;letter-spacing:.4px;color:#1f542d">OBSERVACIONES</div>';
        $html .= '</td></tr>';
        $html .= '<tr><td style="padding:8px 30px 28px">';
        $html .= '<div style="padding:15px 16px;background:#edf6e8;border:1px solid #d7e2d2;font-size:12px;line-height:19px;color:#1f2923">' . nl2br($esc($observaciones)) . '</div>';
        $html .= '</td></tr>';
    }

    $html .= '<tr><td style="padding:20px 30px;background:#1f542d">';
    $html .= '<div style="font-size:12px;line-height:18px;font-weight:700;color:#ffffff">Laboratorio Agroindustrial CENGICAÑA</div>';
    $html .= '<div style="margin-top:4px;font-size:10px;line-height:16px;color:#dcebd5">Km 92.5 Carretera a Santa Lucia Cotzumalguapa, Escuintla, Guatemala</div>';
    $html .= '<div style="margin-top:8px;font-size:10px;line-height:16px;color:#bcd4b8">Este es un mensaje automatico. El PDF adjunto constituye la boleta oficial de la solicitud.</div>';
    $html .= '</td></tr>';
    $html .= '</table></td></tr></table></body></html>';

    return $html;
}

function construirTextoCorreo(array $solicitud, array $analisis): string
{
    $lineas = [
        'CENGICAÑA - Laboratorio Agroindustrial',
        '',
        'Se adjunta el PDF de la boleta de solicitud generada.',
        '',
        'Descripcion de la solicitud:',
        'Tipo de muestra: ' . valorSolicitud($solicitud, 'tipo'),
        'Cliente / institucion: ' . valorSolicitud($solicitud, 'institucion'),
        'Responsable del envio: ' . valorSolicitud($solicitud, 'responsable_envio'),
        'Numero de lote: ' . valorSolicitud($solicitud, 'lote'),
        'Codigo de muestreo: ' . valorSolicitud($solicitud, 'codigo_muestreo'),
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

    $observaciones = valorSolicitud($solicitud, 'observaciones', '');
    if ($observaciones !== '') {
        $lineas[] = '';
        $lineas[] = 'Observaciones:';
        $lineas[] = $observaciones;
    }

    $lineas[] = '';
    $lineas[] = 'Laboratorio Agroindustrial CENGICAÑA';

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
            throw new RuntimeException('SMTP no configurado: falta MAIL_HOST en Laboratorio/.env o cengicursos/.env.');
        }

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int) envCorreo('MAIL_PORT', '587');
        $mail->SMTPAuth = envCorreoBool('MAIL_SMTP_AUTH', true);
        $mail->Username = envCorreo('MAIL_USERNAME', '') ?? '';
        // Gmail app passwords often come copied with display spaces; strip them before auth.
        $mail->Password = preg_replace('/\s+/', '', envCorreo('MAIL_PASSWORD', '') ?? '');

        if ($mail->SMTPAuth && ($mail->Username === '' || $mail->Password === '')) {
            throw new RuntimeException('SMTP no configurado: faltan MAIL_USERNAME y/o MAIL_PASSWORD en Laboratorio/.env o cengicursos/.env.');
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
        $ayuda = ' Revise MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION, MAIL_FROM_ADDRESS y MAIL_FROM_NAME en Laboratorio/.env o cengicursos/.env.';
        throw new RuntimeException('No se pudo enviar el correo: ' . $mail->ErrorInfo . $ayuda);
    }
}
