<?php
require_once __DIR__ . "/config/session.php";
cengi_session_start();
require_once("config/conexion.php");

/**
 * Valida contra la fuerza minima requerida: al menos 8 caracteres, con al
 * menos una letra y un numero. No exige simbolos para no ser demasiado
 * restrictivo, pero evita contrasenas triviales como "12345678" o "password".
 */
function cengi_password_es_fuerte(string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }

    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return false;
    }

    return true;
}

$conn = Conexion::conectar();

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;
$exito = false;
$tokenValido = false;
$resetRow = null;

if ($token === '' || !ctype_xdigit($token) || strlen($token) !== 64) {
    $error = "El enlace de recuperación no es válido. Solicita uno nuevo.";
} else {

    $tokenHash = hash('sha256', $token);

    $stmt = $conn->prepare("
        SELECT pr.id, pr.usuario_id, pr.expira_en, pr.usado_en, u.correo, u.nombre
        FROM password_resets pr
        INNER JOIN usuarios u ON u.id = pr.usuario_id
        WHERE pr.token_hash = ?
    ");
    $stmt->execute([$tokenHash]);
    $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRow) {
        $error = "El enlace de recuperación no es válido. Solicita uno nuevo.";
    } elseif ($resetRow['usado_en'] !== null) {
        $error = "Este enlace ya fue utilizado. Solicita uno nuevo si necesitas restablecer tu contraseña de nuevo.";
    } elseif (strtotime($resetRow['expira_en']) < time()) {
        $error = "Este enlace expiró. Solicita uno nuevo.";
    } else {
        $tokenValido = true;
    }
}

if ($tokenValido && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $password = (string) ($_POST['password'] ?? '');
    $confirmacion = (string) ($_POST['password_confirmacion'] ?? '');

    if ($password !== $confirmacion) {
        $error = "Las contraseñas no coinciden.";
    } elseif (!cengi_password_es_fuerte($password)) {
        $error = "La contraseña debe tener al menos 8 caracteres, incluyendo letras y números.";
    } else {

        $nuevoHash = password_hash($password, PASSWORD_DEFAULT);

        $conn->beginTransaction();
        try {
            $update = $conn->prepare("UPDATE usuarios SET contrasena = ? WHERE id = ?");
            $update->execute([$nuevoHash, $resetRow['usuario_id']]);

            $marcarUsado = $conn->prepare("UPDATE password_resets SET usado_en = NOW() WHERE id = ?");
            $marcarUsado->execute([$resetRow['id']]);

            $conn->commit();
            $exito = true;
            $tokenValido = false; // el token ya no debe volver a mostrarse como usable
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("reset_password: error actualizando contrasena: " . $e->getMessage());
            $error = "Ocurrió un error al actualizar tu contraseña. Intenta de nuevo.";
            $tokenValido = true;
        }
    }
}

if ($exito) {
    header("Location: login.php?reset=ok");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#073d17">
    <meta name="referrer" content="no-referrer">
    <title>Restablecer contraseña | Cengitech</title>
    <link rel="stylesheet" href="assets/portada.css">
</head>
<body class="ct-login">
<div class="ct-page">
    <header class="ct-masthead">
        <img class="ct-official-logo" src="assets/img/logo-cengicana.png" alt="Cengicaña">
    </header>

    <main class="ct-main ct-main--solo">
        <section class="ct-card" aria-labelledby="reset-title">
            <div class="ct-card-brand">
                <img src="assets/img/logo-cengitech.svg" alt="" aria-hidden="true">
                <span>Cengi<b>tech</b></span>
            </div>
            <h2 id="reset-title">Restablecer contraseña</h2>

            <?php if ($tokenValido): ?>
                <p class="ct-subtitle">
                    <?php if (!empty($resetRow['nombre'])): ?>Hola, <?= htmlspecialchars($resetRow['nombre'], ENT_QUOTES, 'UTF-8') ?>. <?php endif; ?>
                    Escribe tu nueva contraseña: al menos 8 caracteres, con letras y números.
                </p>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="ct-alert ct-alert--error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($tokenValido): ?>
                <form id="reset-form" method="POST" autocomplete="off">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (!empty($resetRow['correo'])): ?>
                        <input type="hidden" name="username" autocomplete="username" value="<?= htmlspecialchars($resetRow['correo'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>

                    <div class="ct-field">
                        <label for="password">Nueva contraseña</label>
                        <div class="ct-input-wrap">
                            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                            <button class="ct-toggle" type="button" data-toggle="password" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">
                                <svg class="ct-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.7-6 10-6 10 6 10 6-3.7 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="ct-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A10 10 0 0 1 12 6c6.3 0 10 6 10 6a17 17 0 0 1-3.2 3.8M6.6 6.6C3.8 8.3 2 12 2 12s3.7 6 10 6c1.8 0 3.4-.5 4.8-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="ct-field">
                        <label for="password_confirmacion">Confirmar contraseña</label>
                        <div class="ct-input-wrap">
                            <input id="password_confirmacion" name="password_confirmacion" type="password" autocomplete="new-password" minlength="8" required>
                            <button class="ct-toggle" type="button" data-toggle="password_confirmacion" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">
                                <svg class="ct-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.7-6 10-6 10 6 10 6-3.7 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="ct-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A10 10 0 0 1 12 6c6.3 0 10 6 10 6a17 17 0 0 1-3.2 3.8M6.6 6.6C3.8 8.3 2 12 2 12s3.7 6 10 6c1.8 0 3.4-.5 4.8-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                            </button>
                        </div>
                    </div>

                    <button class="ct-submit" type="submit">Restablecer contraseña</button>
                </form>
            <?php else: ?>
                <a class="ct-submit ct-submit--link" href="forgot_password.php">Solicitar un nuevo enlace</a>
            <?php endif; ?>

            <a class="ct-recovery" href="login.php">← Volver a iniciar sesión</a>
        </section>
    </main>

    <footer class="ct-footer">Conectando conocimiento, tecnología y aprendizaje para el desarrollo de la Agroindustria</footer>
</div>
<script>
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle'));
            var visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            btn.setAttribute('aria-pressed', String(visible));
            btn.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
            btn.title = visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
        });
    });
    var form = document.getElementById('reset-form');
    if (form) {
        var conf = document.getElementById('password_confirmacion');
        var pass = document.getElementById('password');
        var validar = function () {
            conf.setCustomValidity(conf.value && conf.value !== pass.value ? 'Las contraseñas no coinciden.' : '');
        };
        pass.addEventListener('input', validar);
        conf.addEventListener('input', validar);
        form.addEventListener('submit', function (e) {
            validar();
            if (!form.checkValidity()) { return; }
            var btn = form.querySelector('.ct-submit');
            btn.disabled = true;
            btn.textContent = 'Guardando…';
        });
    }
</script>
</body>
</html>
