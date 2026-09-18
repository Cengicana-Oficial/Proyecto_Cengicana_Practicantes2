<?php
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/password_reset_throttle.php";
require_once __DIR__ . "/config/correo.php";
cengi_session_start();
require_once("config/conexion.php");

const CENGI_RESET_TOKEN_TTL_SEGUNDOS = 3600; // 60 minutos

$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $correo = trim((string) ($_POST['correo'] ?? ''));

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        // Formato invalido: no hace falta throttle ni consulta a BD, pero el
        // mensaje sigue siendo el mismo generico para no dar pistas.
        $mensaje = "Si el correo está registrado, te enviaremos un enlace para restablecer tu contraseña.";
    } else {

        $bloqueado = cengi_reset_throttle_bloqueado($correo);

        if (!$bloqueado) {
            cengi_reset_throttle_registrar($correo);

            $conn = Conexion::conectar();

            $stmt = $conn->prepare("SELECT id, nombre, correo FROM usuarios WHERE correo = ?");
            $stmt->execute([$correo]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiraEn = date('Y-m-d H:i:s', time() + CENGI_RESET_TOKEN_TTL_SEGUNDOS);
                $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'desconocida');

                // Invalida cualquier enlace previo sin usar para este usuario:
                // solo el ultimo enlace enviado debe funcionar.
                $delete = $conn->prepare("DELETE FROM password_resets WHERE usuario_id = ?");
                $delete->execute([$user['id']]);

                $insert = $conn->prepare("
                    INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip_solicitud)
                    VALUES (?, ?, ?, ?)
                ");
                $insert->execute([$user['id'], $tokenHash, $expiraEn, $ipHash]);

                $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $directorio = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/login/forgot_password.php')), '/');
                $enlace = "{$esquema}://{$host}{$directorio}/reset_password.php?token=" . urlencode($token);

                cengi_login_enviar_correo_reset($user['correo'], $user['nombre'], $enlace);
            }
        }

        // Mismo mensaje exista o no el correo, este bloqueado o no: evita
        // filtrar que correos estan registrados.
        $mensaje = "Si el correo está registrado, te enviaremos un enlace para restablecer tu contraseña.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Olvidé mi contraseña - CENGICAÑA</title>
    <link rel="stylesheet" href="assets/login.css">
    <div class="logo-top">
    <img src="assets/img/logo.png" alt="Logo">
</div>
</head>
<body>

<div class="login-container">

    <div class="login-card">

        <div class="logo-box">
            <h1>CENGICAÑA</h1>
            <p>Recupera el acceso a tu cuenta</p>
        </div>

        <?php if ($mensaje): ?>
            <div class="error-msg">
                <?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form">

            <label>Correo electrónico</label>
            <input
                type="email"
                name="correo"
                placeholder="Ingresa tu email"
                required
            >

            <button type="submit">
                Enviar enlace de recuperación
            </button>

        </form>

        <p style="text-align:center; margin-top:16px;">
            <a href="login.php" style="color: var(--verde-oscuro); text-decoration: none; font-size: 14px;">Volver a iniciar sesión</a>
        </p>
    </div>

</div>

</body>
</html>
