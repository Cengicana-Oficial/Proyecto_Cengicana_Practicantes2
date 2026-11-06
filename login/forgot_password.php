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

            // La base del enlace sale de LOGIN_URL (login/.env), nunca de la
            // cabecera Host: con Host falso, un atacante podria pedir el reseteo
            // de otra persona y hacer que el correo legitimo apunte a su dominio,
            // capturando el token cuando la victima hace clic.
            cengi_login_cargar_env();
            $baseLogin = rtrim(trim((string) ($_ENV['LOGIN_URL'] ?? '')), '/');
            if ($baseLogin !== '' && !preg_match('#^https?://[^/\s]+#i', $baseLogin)) {
                $baseLogin = '';
            }

            if ($user && $baseLogin === '') {
                error_log('login: LOGIN_URL no configurada o invalida en login/.env; no se envio el correo de reseteo.');
            } elseif ($user) {
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

                $enlace = "{$baseLogin}/reset_password.php?token=" . urlencode($token);

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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#073d17">
    <title>Recuperar contraseña | Cengitech</title>
    <link rel="stylesheet" href="assets/portada.css">
</head>
<body class="ct-login">
<div class="ct-page">
    <header class="ct-masthead">
        <img class="ct-official-logo" src="assets/img/logo-cengicana.png" alt="Cengicaña">
    </header>

    <main class="ct-main ct-main--solo">
        <section class="ct-card" aria-labelledby="recuperar-title">
            <div class="ct-card-brand">
                <img src="assets/img/logo-cengitech.svg" alt="" aria-hidden="true">
                <span>Cengi<b>tech</b></span>
            </div>
            <h2 id="recuperar-title">¿Olvidaste tu contraseña?</h2>
            <p class="ct-subtitle">Ingresa el correo con el que accedes a Cengitech y te enviaremos un enlace para restablecerla.</p>

            <?php if ($mensaje): ?>
                <div class="ct-alert ct-alert--ok" role="status"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form id="recuperar-form" method="POST" autocomplete="on">
                <div class="ct-field">
                    <label for="correo">Correo electrónico</label>
                    <input id="correo" name="correo" type="email" autocomplete="username" inputmode="email" required>
                </div>
                <button class="ct-submit" type="submit">Enviar enlace de recuperación</button>
            </form>

            <a class="ct-recovery" href="login.php">← Volver a iniciar sesión</a>
        </section>
    </main>

    <footer class="ct-footer">Conectando conocimiento, tecnología y aprendizaje para el desarrollo de la Agroindustria</footer>
</div>
<script>
    document.getElementById('recuperar-form').addEventListener('submit', function (e) {
        var btn = e.target.querySelector('.ct-submit');
        btn.disabled = true;
        btn.textContent = 'Enviando…';
    });
</script>
</body>
</html>
