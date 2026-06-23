<?php
require_once __DIR__ . "/config/session.php";
cengi_session_start();
require_once("config/conexion.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

    $error = "Credenciales incorrectas";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login CENGICAÑA</title>
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
        </div>

        <?php if(isset($error)): ?>
            <div class="error-msg">
                <?= $error ?>
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

            <label>Contraseña</label>
            <input
                type="password"
                name="password"
                placeholder="Ingrese contraseña"
                required
            >

            <button type="submit">
                Ingresar
            </button>

        </form>
    </div>

</div>

</body>
</html>
