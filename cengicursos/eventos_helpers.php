<?php

/**
 * Funciones compartidas por la administracion y el formulario publico de eventos.
 */

// Numero de contacto para solicitar el numero de cuenta / realizar el pago de un
// evento de modalidad_pago = 'Pagado', mostrado en el panel lateral del formulario
// publico de inscripcion (cengicursos/inscripcion_evento.php). Placeholder: actualizar
// con el numero real cuando el comite lo confirme.
if (!defined('CENGI_EVENTO_TELEFONO_PAGO')) {
    define('CENGI_EVENTO_TELEFONO_PAGO', 'XXXXXXXX');
}

// Soporte de pagos mostrado en el recuadro "¿A qué cuenta deposito?" del formulario
// publico de inscripcion a eventos pagados (cengicursos/inscripcion_evento.php).
if (!defined('CENGI_EVENTO_SOPORTE_PAGO_NOMBRE')) {
    define('CENGI_EVENTO_SOPORTE_PAGO_NOMBRE', 'Soporte de pagos — Administración CENGICAÑA');
}
if (!defined('CENGI_EVENTO_SOPORTE_PAGO_HORARIO')) {
    define('CENGI_EVENTO_SOPORTE_PAGO_HORARIO', 'Lunes a viernes, 8:00 a 16:30');
}
if (!defined('CENGI_EVENTO_CORREO_PAGO')) {
    define('CENGI_EVENTO_CORREO_PAGO', 'aadmon@cengicana.org');
}

function cengi_evento_generar_codigo_qr(PDO $db)
{
    do {
        $codigo = 'EVT-' . date('Y') . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $db->prepare('SELECT COUNT(*) FROM evento_participantes WHERE codigo_qr = ?');
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $codigo;
}

function cengi_evento_modalidad_pago($valor)
{
    return strcasecmp(trim((string) $valor), 'Pagado') === 0 ? 'Pagado' : 'Gratuito';
}

function cengi_evento_asegurar_token_inscripcion(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT token_inscripcion FROM eventos WHERE id = ?');
    $stmt->execute([(int) $eventoId]);
    $token = $stmt->fetchColumn();

    if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
        return $token;
    }

    for ($intento = 0; $intento < 5; $intento++) {
        $token = bin2hex(random_bytes(16));
        try {
            $actualizar = $db->prepare('UPDATE eventos SET token_inscripcion = ? WHERE id = ? AND token_inscripcion IS NULL');
            $actualizar->execute([$token, (int) $eventoId]);

            $stmt->execute([(int) $eventoId]);
            $guardado = $stmt->fetchColumn();
            if (is_string($guardado) && $guardado !== '') {
                return $guardado;
            }
        } catch (PDOException $e) {
            // Una colision del indice UNIQUE es extremadamente improbable;
            // si ocurre, se genera otro token en la siguiente iteracion.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    throw new RuntimeException('No fue posible generar el enlace de inscripcion.');
}

/* ==================== Editar evento, colaboracion, difusion y encuestas ==================== */

// Preguntas de la encuesta de colaboracion (empresas aliadas), mismas del prototipo SIGEC v29.
// La clave es tambien el nombre de la columna en evento_encuesta_colab; 'na' admite "No aplica".
const CENGI_EVT_COLAB_ITEMS = [
    ['k' => 'coord', 'g' => 'Coordinación', 't' => 'Claridad en la coordinación con el Centro', 'na' => false],
    ['k' => 'cumpl', 'g' => 'Coordinación', 't' => 'Cumplimiento de lo acordado por el Centro', 'na' => false],
    ['k' => 'difus', 'g' => 'Aporte del Centro', 't' => 'Difusión e invitación a la red de contactos', 'na' => false],
    ['k' => 'organ', 'g' => 'Aporte del Centro', 't' => 'Apoyo en la organización (agenda y dinámica)', 'na' => false],
    ['k' => 'audit', 'g' => 'Aporte del Centro', 't' => 'Uso y condiciones del auditorio', 'na' => true],
    ['k' => 'valor', 'g' => 'Valor percibido', 't' => 'Valor agregado de la participación del Centro', 'na' => false],
    ['k' => 'util', 'g' => 'Valor percibido', 't' => 'Utilidad del evento para su empresa', 'na' => false],
];
const CENGI_EVT_COLAB_GRUPOS = ['Coordinación', 'Aporte del Centro', 'Valor percibido'];

const CENGI_EVT_COLAB_MODALIDADES = [
    1 => 'Invitación abierta a la red de contactos',
    2 => 'Evento organizado con colaboración de CENGICAÑA',
    3 => 'Taller o curso con apoyo integral',
];
const CENGI_EVT_COLAB_FINANCIA = [
    'empresa' => 'La empresa aliada (gratis para participantes)',
    'compartido' => 'Compartido (participantes pagan una cuota)',
    'centro' => 'CENGICAÑA (alianza estratégica)',
];

const CENGI_EVT_DIF_CANALES = [
    'redes' => 'Redes sociales',
    'whatsapp' => 'Comunidad de WhatsApp',
    'correo' => 'Correo electrónico',
    'web' => 'Sitio web / landing page',
    'impreso' => 'Material impreso',
    'aliado' => 'Canales de la empresa aliada',
    'otro' => 'Otro',
];

const CENGI_EVT_TIPOS = ['Capacitación', 'Evento técnico', 'Seminario', 'Taller', 'Feria', 'Congreso', 'Día de campo'];
const CENGI_EVT_ESTADOS = ['Planificado', 'En curso', 'Finalizado', 'Cancelado'];

/* Token aleatorio de 32 caracteres hex (enlaces publicos de encuestas). */
function cengi_evento_token()
{
    return bin2hex(random_bytes(16));
}

/* Token del enlace general / QR de la encuesta de percepcion de un evento (se crea la
   primera vez que se pide). */
function cengi_evento_asegurar_token_percepcion(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT token_percepcion FROM eventos WHERE id = ?');
    $stmt->execute([(int) $eventoId]);
    $token = $stmt->fetchColumn();
    if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
        return $token;
    }
    $token = cengi_evento_token();
    $db->prepare('UPDATE eventos SET token_percepcion = ? WHERE id = ? AND token_percepcion IS NULL')->execute([$token, (int) $eventoId]);
    $stmt->execute([(int) $eventoId]);
    return (string) $stmt->fetchColumn();
}

/* URL base del sitio para los enlaces que se envian por correo: APP_URL del .env si
   existe; si no, el esquema y host de la peticion actual (solo se usa desde acciones
   del panel de administracion). */
function cengi_evento_url_base()
{
    $env = function_exists('cengicursos_env') ? cengicursos_env() : [];
    if (!empty($env['APP_URL'])) {
        return rtrim((string) $env['APP_URL'], '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function cengi_evento_url_encuesta($token)
{
    return cengi_evento_url_base() . '/cengicursos/encuesta_evento.php?t=' . rawurlencode((string) $token);
}

/* Cuerpo HTML de los correos de encuestas (mismo layout que los correos de gafete):
   $parrafos es una lista de textos planos; $url y $boton arman el boton principal. */
function cengi_evento_correo_encuesta_html($saludo, array $parrafos, $url, $boton)
{
    $e = static function ($t) {
        return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    };
    $cuerpo = '';
    foreach ($parrafos as $p) {
        $cuerpo .= "<p style='margin:0 0 14px;font-size:15px;line-height:1.6;color:#333333;'>" . $e($p) . '</p>';
    }
    return "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
<body style='margin:0;padding:0;background-color:#f4f6f8;font-family:Arial, Helvetica, sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background-color:#f4f6f8;padding:40px 15px;'><tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='width:100%;max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);'>
<tr><td style='background:#176b3a;padding:30px;text-align:center;color:#ffffff;'>
<table cellpadding='0' cellspacing='0' style='margin:0 auto;'><tr><td style='background:#ffffff;border-radius:12px;padding:12px 22px;'><img src='cid:logo_cengicana' alt='CENGICAÑA' style='display:block;max-width:170px;height:auto;'></td></tr></table>
</td></tr>
<tr><td style='padding:32px 34px;'>
<p style='margin:0 0 16px;font-size:16px;color:#222222;'>" . $e($saludo) . "</p>
{$cuerpo}
<p style='margin:24px 0;text-align:center;'><a href='" . $e($url) . "' style='display:inline-block;background:#176b3a;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 26px;border-radius:8px;'>" . $e($boton) . "</a></p>
<p style='margin:0;font-size:12px;color:#777777;word-break:break-all;'>Si el botón no funciona, copia este enlace: " . $e($url) . "</p>
</td></tr>
<tr><td style='background:#f1f3f5;padding:18px;text-align:center;font-size:12px;color:#777777;'>CENGICAÑA · Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar</td></tr>
</table></td></tr></table></body></html>";
}

