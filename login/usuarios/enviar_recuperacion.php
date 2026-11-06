<?php
/**
 * Envía a un usuario el enlace para restablecer su contraseña, a pedido de quien lo administra
 * (botón "llave" en usuarios.php). Es el mismo mecanismo de login/forgot_password.php:
 * token aleatorio de un solo uso, guardado como hash en password_resets, 60 minutos de validez,
 * enlace armado con LOGIN_URL (nunca con la cabecera Host) y correo vía SMTP2GO.
 *
 * Permiso: el mismo que cambiar la contraseña en editar_usuario.php (alcance 'total' sobre la
 * cuenta). Por eso, si el correo no sale, se devuelve el enlace para compartirlo por otro medio:
 * quien lo recibe ya podía fijar la contraseña directamente.
 *
 * POST: id, csrf, scope (opcional). Responde JSON.
 */
session_start();
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/correo.php';
require_once __DIR__ . '/_autorizacion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rec_responder($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['id_usuario'])) {
    rec_responder(401, ['ok' => false, 'error' => 'Tu sesión expiró. Vuelve a iniciar sesión.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !usr_csrf_valido($_POST['csrf'] ?? null)) {
    rec_responder(400, ['ok' => false, 'error' => 'Solicitud no válida. Recarga la página e inténtalo de nuevo.']);
}

$scopes = [
    'cursos' => ['module_names' => ['cursos', 'cengicursos']],
    'visitas' => ['module_names' => ['solicitud de visitas']],
    'laboratorio' => ['module_names' => ['laboratorio']],
    'solicitudes' => ['module_names' => ['solicitudes internas']],
];
$scope = strtolower(trim((string) ($_POST['scope'] ?? '')));
$scopeConfig = $scopes[$scope] ?? null;

$conn = Conexion::conectar();
$actorId = (int) $_SESSION['id_usuario'];
$esSuperadmin = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
$id = (int) ($_POST['id'] ?? 0);

$modulosGestionados = usr_exigir_acceso_scope($conn, $actorId, $esSuperadmin, $scope, $scopeConfig);

$stmt = $conn->prepare('SELECT id, nombre, correo, rol_id, es_superadmin FROM usuarios WHERE id = ?');
$stmt->execute([$id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usuario) {
    rec_responder(404, ['ok' => false, 'error' => 'El usuario ya no existe.']);
}
if (usr_alcance_sobre($conn, $actorId, $esSuperadmin, $usuario, $modulosGestionados) !== 'total') {
    rec_responder(403, ['ok' => false, 'error' => 'No puedes restablecer la contraseña de este usuario.']);
}
if (!filter_var($usuario['correo'], FILTER_VALIDATE_EMAIL)) {
    rec_responder(422, ['ok' => false, 'error' => 'El usuario no tiene un correo válido. Corrígelo en Editar.']);
}

cengi_login_cargar_env();
$base = rtrim(trim((string) ($_ENV['LOGIN_URL'] ?? '')), '/');
if ($base === '' || !preg_match('#^https?://[^/\s]+#i', $base)) {
    error_log('usuarios/enviar_recuperacion: LOGIN_URL no configurada o invalida en login/.env');
    rec_responder(500, ['ok' => false, 'error' => 'Falta configurar LOGIN_URL en login/.env; no se puede armar el enlace.']);
}

$token = bin2hex(random_bytes(32));
$conn->beginTransaction();
try {
    // Solo el último enlace sirve, igual que en forgot_password.php.
    $conn->prepare('DELETE FROM password_resets WHERE usuario_id = ?')->execute([$usuario['id']]);
    $conn->prepare('INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip_solicitud) VALUES (?, ?, ?, ?)')
        ->execute([$usuario['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'desconocida')]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    error_log('usuarios/enviar_recuperacion: ' . $e->getMessage());
    rec_responder(500, ['ok' => false, 'error' => 'No se pudo generar el enlace. Inténtalo de nuevo.']);
}

$enlace = $base . '/reset_password.php?token=' . urlencode($token);
$enviado = cengi_login_enviar_correo_reset($usuario['correo'], $usuario['nombre'], $enlace);

rec_responder(200, [
    'ok' => true,
    'enviado' => (bool) $enviado,
    'correo' => $usuario['correo'],
    'nombre' => $usuario['nombre'],
    'enlace' => $enlace,
]);
