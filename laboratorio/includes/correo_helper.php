<?php
/**
 * correo_helper.php
 *
 * Configuración compartida de PHPMailer (Laboratorio/.env o cengicursos/.env) para los
 * envíos del módulo: boleta de recepción (enviar_solicitud_pdf.php) y reportes
 * (enviar_reporte_correo.php).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

if (!function_exists('cargarPhpMailer')) {
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
}

if (!function_exists('envCorreo')) {
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
}

if (!function_exists('normalizarCorreos')) {
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
}

if (!function_exists('labCorreoNuevoMailer')) {
    /** PHPMailer listo para enviar: transporte, remitente y responder-a (usuario actual). */
    function labCorreoNuevoMailer(): PHPMailer
    {
        cargarPhpMailer();
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


        $usuario = function_exists('lab_current_user') ? lab_current_user() : [];
        if (!empty($usuario['correo']) && filter_var($usuario['correo'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($usuario['correo'], $usuario['nombre'] ?? '');
        }

        return $mail;
    }
}
