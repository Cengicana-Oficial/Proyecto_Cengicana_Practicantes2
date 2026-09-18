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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña - CENGICAÑA</title>
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
            <p>Restablecer contraseña</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($tokenValido): ?>
            <form method="POST" class="login-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                <label>Nueva contraseña</label>
                <input
                    type="password"
                    name="password"
                    placeholder="Mínimo 8 caracteres, letras y números"
                    minlength="8"
                    required
                >

                <label>Confirmar contraseña</label>
                <input
                    type="password"
                    name="password_confirmacion"
                    placeholder="Repite tu nueva contraseña"
                    minlength="8"
                    required
                >

                <button type="submit">
                    Restablecer contraseña
                </button>
            </form>
        <?php else: ?>
            <p style="text-align:center; margin-top:8px;">
                <a href="forgot_password.php" style="color: var(--verde-oscuro); text-decoration: none; font-size: 14px;">Solicitar un nuevo enlace</a>
            </p>
        <?php endif; ?>

        <p style="text-align:center; margin-top:16px;">
            <a href="login.php" style="color: var(--verde-oscuro); text-decoration: none; font-size: 14px;">Volver a iniciar sesión</a>
        </p>
    </div>

</div>

</body>
</html>
