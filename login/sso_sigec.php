<?php
/**
 * Puente de SSO hacia SIGEC (app Laravel en un contenedor/dominio aparte,
 * ver docker-compose.prod.yml servicio "sigec"). SIGEC no puede leer la
 * sesion PHP nativa compartida por el resto del compendio (login/config/
 * session.php), asi que este script arma un token de corta duracion
 * firmado con HMAC-SHA256 y redirige a sigec/routes/web.php "/sso/entrar"
 * (App\Http\Controllers\Auth\SsoController), que lo valida y abre una
 * sesion Laravel normal. Ver tambien sigec/config/sso.php.
 *
 * Formato del token: base64url(json_payload) . '.' . base64url(hmac_sha256(json_payload, secret)).
 * Ventana de expiracion corta (60s) es la unica proteccion contra replay —
 * no hay nonce/lista de un solo uso; riesgo aceptado para trafico interno
 * de mismo origen de confianza.
 */

require_once __DIR__ . "/config/session.php";
cengi_session_start();
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$esSuperadmin = !empty($_SESSION['es_superadmin']);
$tienePermiso = $esSuperadmin
    || in_array('sigec.acceder', $_SESSION['user_permissions'] ?? [], true);

if (!$tienePermiso) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso denegado</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f6f8f3;color:#1f2a1f;padding:40px}';
    echo '.box{max-width:560px;margin:auto;background:#fff;border:1px solid #dfe7d8;border-radius:8px;padding:24px}';
    echo 'a{color:#2c6b2f}</style></head><body><div class="box">';
    echo '<h1>Acceso denegado</h1>';
    echo '<p>Su usuario no tiene acceso al modulo SIGEC.</p>';
    echo '<a href="Menu.php">Volver al panel principal</a>';
    echo '</div></body></html>';
    exit;
}

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$sigecUrl = $_ENV['SIGEC_URL'] ?? null;
$secret = $_ENV['SIGEC_SSO_SECRET'] ?? null;

if (!$sigecUrl || !$secret) {
    http_response_code(500);
    die('Error: SIGEC_URL / SIGEC_SSO_SECRET no configurados.');
}

function sso_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$payload = [
    'id_usuario' => (int) $_SESSION['id_usuario'],
    'correo' => $_SESSION['correo'],
    'nombre' => $_SESSION['usuario'],
    'rol_id' => (int) ($_SESSION['rol_id'] ?? 0),
    'es_superadmin' => $esSuperadmin,
    'ingenio_id' => $_SESSION['ingenio_id'] ?? null,
    'exp' => time() + 60,
];

$payloadJson = json_encode($payload);
$signature = hash_hmac('sha256', $payloadJson, $secret, true);

$token = sso_base64url_encode($payloadJson) . '.' . sso_base64url_encode($signature);

header('Location: ' . rtrim($sigecUrl, '/') . '/sso/entrar?token=' . urlencode($token));
exit;
