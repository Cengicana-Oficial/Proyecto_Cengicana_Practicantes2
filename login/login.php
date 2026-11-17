<?php
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/login_throttle.php";
cengi_session_start();
require_once("config/conexion.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $segundosRestantes = cengi_login_throttle_bloqueado();

    if ($segundosRestantes > 0) {
        $error = "Demasiados intentos fallidos. Intenta de nuevo en " . ceil($segundosRestantes / 60) . " minuto(s).";
    } else {

    $correo = $_POST['correo'];
    $password = $_POST['password'];

    $conn = Conexion::conectar();

    $stmt = $conn->prepare("
        SELECT u.*, r.nombre_rol, i.nombre_ingenio
        FROM usuarios u
        INNER JOIN roles r ON u.rol_id = r.id
        LEFT JOIN ingenios i ON i.id = u.ingenio_id
        WHERE u.correo = ?
    ");

    $stmt->execute([$correo]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        if (password_verify($password, $user['contrasena'])) {
            $loginCorrecto = true;
        } elseif ($password === $user['contrasena']) {
            $loginCorrecto = true;
            $nuevoHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE usuarios SET contrasena = ? WHERE id = ?");
            $update->execute([$nuevoHash, $user['id']]);
        } else {
            $loginCorrecto = false;
        }

        if ($loginCorrecto) {
            cengi_login_throttle_limpiar();
            session_regenerate_id(true);

            $stmtMod = $conn->prepare("
                SELECT m.id, m.nombre
                FROM usuario_modulo um
                INNER JOIN modulos m ON um.modulo_id = m.id
                WHERE um.usuario_id = ?
            ");
            $stmtMod->execute([$user['id']]);
            $modulos = $stmtMod->fetchAll(PDO::FETCH_ASSOC);

            try {
                $stmtPerm = $conn->prepare("
                    SELECT p.nombre_permiso
                    FROM rol_permiso rp
                    INNER JOIN permisos p ON p.id = rp.permiso_id
                    WHERE rp.rol_id = ?
                ");
                $stmtPerm->execute([$user['rol_id']]);
                $permisos = $stmtPerm->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                $permisos = [];
            }

            $_SESSION['usuario'] = $user['nombre'];
            $_SESSION['correo'] = $user['correo'];
            $_SESSION['rol'] = $user['nombre_rol'];
            $_SESSION['rol_id'] = $user['rol_id'];
            $_SESSION['id_usuario'] = $user['id'];
            $_SESSION['es_superadmin'] = $user['es_superadmin'];
            $_SESSION['ingenio_id'] = $user['ingenio_id'];
            $_SESSION['ingenio_nombre'] = $user['nombre_ingenio'];
            $_SESSION['modulos'] = $modulos;
            $_SESSION['user_permissions'] = $permisos;

            header("Location: Menu.php");
            exit;
        }
    }

        cengi_login_throttle_registrar_fallo();
        $error = "Credenciales incorrectas";
    }
}

$resetExitoso = isset($_GET['reset']) && $_GET['reset'] === 'ok';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#073d17">
    <meta name="description" content="Cengitech, plataforma integral de Cengicaña para capacitación, investigación y gestión de procesos.">
    <title>Iniciar sesión | Cengitech</title>
    <link rel="stylesheet" href="assets/portada.css">
</head>
<body class="ct-login">
<div class="ct-page">
    <header class="ct-masthead">
        <img class="ct-official-logo" src="assets/img/logo-cengicana.png" alt="Cengicaña">
    </header>

    <main class="ct-main">
        <section aria-label="Acerca de Cengitech">
            <div class="ct-brand-header">
                <img class="ct-tech-symbol" src="assets/img/logo-cengitech.svg" alt="Símbolo Cengitech: lupa, caña y tecnología">
                <h1 class="ct-brand-name">Cengi<span class="tech">tech</span></h1>
            </div>
            <p class="ct-tagline">Conocimiento, innovación y gestión para la agroindustria cañera</p>
            <div class="ct-accent" aria-hidden="true"></div>
            <p class="ct-intro">El ecosistema digital de Cengicaña que integra diversos sistemas y módulos en una sola plataforma, facilitando la gestión de información, la innovación y la transferencia de conocimiento.</p>
            <div class="ct-modules" aria-label="Módulos de la plataforma">
                <div class="ct-module"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="m4 17 20-9 20 9-20 9zM11 21v11c8 7 18 7 26 0V21M43 18v15"/><circle cx="43" cy="36" r="2"/></svg><span>CengiCursos</span></div>
                <div class="ct-module"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M19 5h10M21 5v15L9 40c-2 3 0 5 4 5h22c4 0 6-2 4-5L27 20V5M15 32h18"/><circle cx="22" cy="37" r="2"/><path d="M29 37h2"/></svg><span>CengiLabs</span></div>
                <div class="ct-module"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 43V25M24 27C13 27 8 20 8 10c10 0 16 6 16 17ZM24 22C35 22 40 15 40 5c-10 1-16 6-16 17ZM6 42c12-7 24-7 36 0M12 37c8-5 16-5 24 0"/></svg><span>CengiInvestiga</span></div>
                <div class="ct-module"><svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="15" r="7"/><path d="M11 41v-6c0-16 26-16 26 0v6M8 21a6 6 0 1 1 0-12M7 29c-5 0-6 5-6 11M40 21a6 6 0 1 0 0-12M41 29c5 0 6 5 6 11"/></svg><span>CengiConecta</span></div>
                <div class="ct-module"><svg viewBox="0 0 48 48" aria-hidden="true"><rect x="6" y="6" width="14" height="14" rx="3"/><rect x="28" y="6" width="14" height="14" rx="3"/><rect x="6" y="28" width="14" height="14" rx="3"/><rect x="28" y="28" width="14" height="14" rx="3"/></svg><span>Más módulos</span></div>
            </div>
        </section>

        <section class="ct-card" aria-labelledby="login-title">
            <h2 id="login-title">Bienvenido a Cengitech</h2>
            <p class="ct-subtitle">Accede a los módulos del ecosistema digital de Cengicaña y gestiona tus actividades de forma integral.</p>

            <?php if (isset($error)): ?>
                <div class="ct-alert ct-alert--error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($resetExitoso): ?>
                <div class="ct-alert ct-alert--ok" role="status">Tu contraseña fue restablecida correctamente. Ya puedes iniciar sesión.</div>
            <?php endif; ?>

            <form id="login-form" method="POST" autocomplete="on">
                <div class="ct-field">
                    <label for="correo">Correo electrónico</label>
                    <input id="correo" name="correo" type="email" autocomplete="username" inputmode="email" required
                           value="<?= htmlspecialchars($_POST['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="ct-field">
                    <label for="password">Contraseña</label>
                    <div class="ct-input-wrap">
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                        <button class="ct-toggle" type="button" id="toggle-password" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">
                            <svg class="ct-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.7-6 10-6 10 6 10 6-3.7 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="ct-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A10 10 0 0 1 12 6c6.3 0 10 6 10 6a17 17 0 0 1-3.2 3.8M6.6 6.6C3.8 8.3 2 12 2 12s3.7 6 10 6c1.8 0 3.4-.5 4.8-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                    </div>
                </div>
                <button class="ct-submit" type="submit">Ingresar &nbsp;→</button>
            </form>

            <a class="ct-recovery" href="forgot_password.php">¿Olvidaste tu contraseña?</a>
        </section>
    </main>

    <footer class="ct-footer">Conectando conocimiento, tecnología y aprendizaje para el desarrollo de la Agroindustria</footer>
</div>
<script>
    (function () {
        var toggle = document.getElementById('toggle-password');
        var password = document.getElementById('password');
        toggle.addEventListener('click', function () {
            var visible = password.type === 'password';
            password.type = visible ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(visible));
            toggle.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
            toggle.title = visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
        });
        document.getElementById('login-form').addEventListener('submit', function (e) {
            var btn = e.target.querySelector('.ct-submit');
            btn.disabled = true;
            btn.textContent = 'Ingresando…';
        });
    })();
</script>
</body>
</html>
