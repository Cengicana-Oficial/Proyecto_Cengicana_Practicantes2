<?php
declare(strict_types=1);

/*
 * Avisos por correo de Solicitudes internas
 * -----------------------------------------
 * Complementan la campana del sistema para quien no esta dentro de la plataforma:
 *  - solicitud nueva: a los jefes que gestionan el programa destino (si no hay
 *    ninguno, a los superadmin del modulo, para que nadie la pierda), con copia
 *    opcional a Direccion y a correos adicionales;
 *  - cambio de estado/seguimiento: al solicitante, en los estados elegidos.
 * Nunca se avisa al usuario que hizo la accion.
 *
 * Que se envia se edita en la vista "Correos" (tabla `configuracion_correo`); cada
 * envio queda en `correo_registro`. Usa el SMTP2GO de la plataforma (MAIL_* de
 * login/.env, leido por app_env_value()) y el PHPMailer instalado en login/vendor.
 * SOLICITUDES_CORREO_ACTIVO=0 en el .env apaga todo sin importar la pantalla.
 * Un fallo de correo nunca interrumpe la solicitud.
 */

use PHPMailer\PHPMailer\PHPMailer;

const MAIL_STATUSES = ['recibido', 'proceso', 'completado', 'rechazado'];

const MAIL_SETTINGS_DEFAULTS = [
    'activo' => '1',
    'nueva_destino' => '1',
    'nueva_direccion' => '0',
    'correos_copia' => '',
    'avance_estados' => 'recibido,proceso,completado,rechazado',
];

/** Vista "Correos": superadmin y administradores de usuarios del modulo. */
function can_manage_mail_settings(array $user): bool
{
    return is_superadmin($user) || can_manage_users($user);
}

function mail_env_enabled(): bool
{
    return !in_array(strtolower(app_env_value('SOLICITUDES_CORREO_ACTIVO', '1')), ['0', 'false', 'no', 'off'], true);
}

/** Remitente configurado, o '' si falta algun dato SMTP o sigue con valores de ejemplo (change-me). */
function mail_smtp_sender(): string
{
    $user = app_env_value('MAIL_USERNAME');
    $password = app_env_value('MAIL_PASSWORD');
    $from = app_env_value('MAIL_FROM_ADDRESS');

    if ($user === '' || $password === '' || $user === 'change-me' || $password === 'change-me' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return $from;
}

function mail_settings(PDO $pdo, bool $reload = false): array
{
    static $settings = null;

    if ($settings === null || $reload) {
        $settings = MAIL_SETTINGS_DEFAULTS;
        foreach ($pdo->query('SELECT clave, valor FROM configuracion_correo')->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string) $value;
            }
        }
    }

    return $settings;
}

function save_mail_settings(PDO $pdo, array $values): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO configuracion_correo (clave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    );
    foreach (array_intersect_key($values, MAIL_SETTINGS_DEFAULTS) as $key => $value) {
        $stmt->execute([$key, (string) $value]);
    }
    mail_settings($pdo, true);
}

/** Separa y valida una lista de correos escrita a mano (comas, punto y coma o saltos de linea). */
function parse_mail_list(string $value, array &$invalid = []): array
{
    $emails = [];
    foreach (preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($email)] = $email;
        } else {
            $invalid[] = $email;
        }
    }

    return array_values($emails);
}

function mail_enabled(PDO $pdo): bool
{
    return mail_env_enabled() && mail_settings($pdo)['activo'] === '1';
}

/** Enlace absoluto al detalle de una solicitud (SOLICITUDES_URL permite fijar la base publica). */
function mail_request_link(int $requestId): string
{
    $base = rtrim(app_env_value('SOLICITUDES_URL'), '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/sistema_de_solicitudes/index.php')), '/');
        $base = "{$scheme}://{$host}{$dir}";
    }

    return "{$base}/index.php?view=detalle&id={$requestId}";
}

/**
 * Envia un correo y lo deja en `correo_registro`. Devuelve '' si salio bien o el
 * motivo del fallo.
 */
function send_request_mail(PDO $pdo, string $type, string $code, string $to, string $name, string $subject, string $html): string
{
    $error = '';
    $autoload = __DIR__ . '/../../login/vendor/autoload.php';

    if (!is_file($autoload)) {
        $error = 'Falta login/vendor (PHPMailer).';
    } elseif (($from = mail_smtp_sender()) === '') {
        $error = 'Configuracion SMTP incompleta (MAIL_USERNAME, MAIL_PASSWORD o MAIL_FROM_ADDRESS).';
    } else {
        require_once $autoload;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = app_env_value('MAIL_HOST', 'mail.smtp2go.com');
                $mail->Port = (int) app_env_value('MAIL_PORT', '2525');
                $mail->SMTPAuth = true;
                $mail->Username = app_env_value('MAIL_USERNAME');
                $mail->Password = app_env_value('MAIL_PASSWORD');
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->CharSet = 'UTF-8';
                $mail->setFrom($from, app_env_value('MAIL_FROM_NAME', 'CENGICANA'));
                $mail->addAddress($to, $name);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $html;
                $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
                $mail->send();
                $error = '';
                break;
            } catch (Throwable $exception) {
                $error = $mail->ErrorInfo ?: $exception->getMessage();
                if ($attempt < 3) {
                    usleep(300000);
                }
            }
        }
    }

    if ($error !== '') {
        error_log("Solicitudes internas: no se pudo enviar el correo a {$to}: {$error}");
    }

    try {
        $pdo->prepare(
            'INSERT INTO correo_registro (tipo, solicitud_codigo, destinatario, asunto, estado, error) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $type,
            $code !== '' ? $code : null,
            mb_substr($to, 0, 190, 'UTF-8'),
            mb_substr($subject, 0, 255, 'UTF-8'),
            $error === '' ? 'enviado' : 'fallido',
            $error !== '' ? mb_substr($error, 0, 255, 'UTF-8') : null,
        ]);
    } catch (Throwable $exception) {
        error_log('Solicitudes internas: no se pudo registrar el correo: ' . $exception->getMessage());
    }

    return $error;
}

/** Layout comun de los avisos. $rows: [etiqueta => valor] ya en texto plano. */
function mail_layout(string $name, string $intro, array $rows, string $link, string $note = ''): string
{
    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $rowsHtml .= '<tr><td style="padding:6px 12px 6px 0;color:#5b6b4f;white-space:nowrap;vertical-align:top;">' . e($label)
            . '</td><td style="padding:6px 0;color:#1a2e0a;">' . nl2br(e((string) $value)) . '</td></tr>';
    }
    $noteHtml = $note !== ''
        ? '<p style="margin:16px 0 0;padding:12px 14px;background:#f3f8ec;border-left:3px solid #73BC25;border-radius:4px;">' . nl2br(e($note)) . '</p>'
        : '';

    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#1a2e0a;">'
        . '<h2 style="color:#73BC25;margin:0 0 16px;">CENGICAÑA · Solicitudes internas</h2>'
        . '<p>Hola ' . e($name) . ',</p>'
        . '<p>' . e($intro) . '</p>'
        . '<table style="border-collapse:collapse;font-size:14px;margin-top:8px;">' . $rowsHtml . '</table>'
        . $noteHtml
        . '<p style="text-align:center;margin:24px 0;"><a href="' . e($link) . '" style="background:#73BC25;color:#ffffff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;">Ver solicitud</a></p>'
        . '<p style="font-size:12px;color:#7a8a6e;">Este es un aviso automático; no respondas a este correo.</p>'
        . '</div>';
}

/** [asunto, html] del aviso de solicitud nueva. */
function mail_new_request(array $request, string $recipientName): array
{
    return [
        "Nueva solicitud {$request['codigo']}: {$request['titulo']}",
        mail_layout(
            $recipientName,
            "{$request['solicitante_nombre']} registró una solicitud dirigida a {$request['programa_destino']} que espera seguimiento.",
            [
                'Código' => $request['codigo'],
                'Título' => $request['titulo'],
                'Tipo' => ucfirst((string) $request['tipo']),
                'Prioridad' => ucfirst((string) $request['prioridad']),
                'Programa origen' => $request['programa_origen'],
                'Fecha requerida' => !empty($request['fecha_requerida']) ? date('d/m/Y', strtotime((string) $request['fecha_requerida'])) : '',
                'Descripción' => mb_strimwidth((string) $request['descripcion'], 0, 600, '…', 'UTF-8'),
            ],
            mail_request_link((int) $request['id'])
        ),
    ];
}

/** [asunto, html] del aviso de avance al solicitante. */
function mail_request_progress(array $request, string $actorName, string $status, string $observation): array
{
    $label = estado_label($status);

    return [
        "Solicitud {$request['codigo']}: {$label}",
        mail_layout(
            (string) $request['solicitante_nombre'],
            "Tu solicitud {$request['codigo']} tiene un nuevo avance registrado por {$actorName} ({$request['programa_destino']}).",
            [
                'Código' => $request['codigo'],
                'Título' => $request['titulo'],
                'Estado' => $label,
            ],
            mail_request_link((int) $request['id']),
            $observation !== '' ? "Observación: {$observation}" : ''
        ),
    ];
}

/** Solicitud con sus programas y su solicitante; sin id, la mas reciente (para pruebas). */
function mail_fetch_request(PDO $pdo, string $menuDbName, ?int $requestId = null): ?array
{
    $stmt = $pdo->prepare(
        "SELECT s.*, po.nombre AS programa_origen, pd.nombre AS programa_destino,
                u.nombre AS solicitante_nombre, u.correo AS solicitante_correo
         FROM solicitudes s
         INNER JOIN programas po ON po.id = s.programa_origen_id
         LEFT JOIN programas pd ON pd.id = s.programa_destino_id
         LEFT JOIN {$menuDbName}.usuarios u ON u.id = s.solicitante_id
         " . ($requestId !== null ? 'WHERE s.id = ?' : 'ORDER BY s.id DESC LIMIT 1')
    );
    $stmt->execute($requestId !== null ? [$requestId] : []);

    return $stmt->fetch() ?: null;
}

/** Solicitud de ejemplo para la vista previa y las pruebas: la ultima registrada o una ficticia. */
function mail_sample_request(PDO $pdo, string $menuDbName): array
{
    return mail_fetch_request($pdo, $menuDbName) ?? [
        'id' => 0,
        'codigo' => 'SOL-0000-000',
        'titulo' => 'Solicitud de ejemplo',
        'tipo' => 'apoyo',
        'prioridad' => 'media',
        'programa_origen' => 'Programa origen',
        'programa_destino' => 'Programa destino',
        'fecha_requerida' => null,
        'descripcion' => 'Texto de ejemplo de la descripcion de la solicitud.',
        'solicitante_nombre' => 'Persona solicitante',
        'solicitante_correo' => '',
    ];
}

/** Destinatarios de una solicitud nueva segun la configuracion: [correo_minusculas => nombre]. */
function new_request_recipients(PDO $pdo, PDO $menuPdo, array $actor, array $request): array
{
    $settings = mail_settings($pdo);
    $users = array_filter(
        fetch_module_users($menuPdo, $pdo, $actor),
        static fn(array $u): bool => (int) $u['id'] !== (int) $actor['id'] && filter_var((string) $u['correo'], FILTER_VALIDATE_EMAIL)
    );
    $chosen = [];

    if ($settings['nueva_destino'] === '1') {
        $chosen = array_filter($users, static fn(array $u): bool => !is_superadmin($u) && can_manage_request($u, $request));
        if (!$chosen) {
            $chosen = array_filter($users, 'is_superadmin');
        }
    }
    if ($settings['nueva_direccion'] === '1') {
        $chosen = array_merge($chosen, array_filter($users, static fn(array $u): bool => in_array(module_role($u), ['director', 'superadmin'], true)));
    }

    $recipients = [];
    foreach ($chosen as $u) {
        $recipients[strtolower((string) $u['correo'])] = (string) $u['nombre'];
    }
    foreach (parse_mail_list($settings['correos_copia']) as $email) {
        $recipients[strtolower($email)] ??= $email;
    }
    unset($recipients[strtolower((string) ($actor['correo'] ?? ''))]);

    return $recipients;
}

function notify_new_request(PDO $pdo, PDO $menuPdo, string $menuDbName, array $actor, int $requestId): void
{
    try {
        if (!mail_enabled($pdo) || !($request = mail_fetch_request($pdo, $menuDbName, $requestId))) {
            return;
        }
        foreach (new_request_recipients($pdo, $menuPdo, $actor, $request) as $email => $name) {
            [$subject, $html] = mail_new_request($request, $name);
            send_request_mail($pdo, 'nueva', (string) $request['codigo'], $email, $name, $subject, $html);
        }
    } catch (Throwable $exception) {
        error_log('Solicitudes internas: aviso de solicitud nueva fallido: ' . $exception->getMessage());
    }
}

function notify_request_progress(PDO $pdo, string $menuDbName, array $actor, int $requestId, string $status, string $observation): void
{
    try {
        if (!mail_enabled($pdo) || !in_array($status, explode(',', mail_settings($pdo)['avance_estados']), true)) {
            return;
        }
        $request = mail_fetch_request($pdo, $menuDbName, $requestId);
        if (!$request || (int) $request['solicitante_id'] === (int) $actor['id'] || !filter_var((string) $request['solicitante_correo'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        [$subject, $html] = mail_request_progress($request, (string) $actor['nombre'], $status, $observation);
        send_request_mail($pdo, 'avance', (string) $request['codigo'], (string) $request['solicitante_correo'], (string) $request['solicitante_nombre'], $subject, $html);
    } catch (Throwable $exception) {
        error_log('Solicitudes internas: aviso de avance fallido: ' . $exception->getMessage());
    }
}
