<?php

require_once __DIR__ . '/includes/user_module_helper.php';

lab_require_permission('laboratorio.usuarios.gestionar');

$conn = lab_users_connection();
$module = lab_laboratory_module($conn);
$roles = lab_fetch_roles_for_user_module($conn);
$ingenios = lab_fetch_ingenios_for_user_module($conn);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    lab_forbidden('El usuario solicitado no es válido.');
}

$usuario = lab_fetch_laboratory_user($conn, (int) $module['id'], $id);
if ($usuario === null) {
    lab_forbidden('El usuario solicitado no pertenece al módulo Laboratorio.');
}

$errors = [];
$values = [
    'nombre' => (string) $usuario['nombre'],
    'correo' => (string) $usuario['correo'],
    'rol_id' => (string) $usuario['rol_id'],
    'ingenio_id' => $usuario['ingenio_id'] === null ? '' : (string) $usuario['ingenio_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['nombre'] = trim((string) ($_POST['nombre'] ?? ''));
    $values['correo'] = trim((string) ($_POST['correo'] ?? ''));
    $values['rol_id'] = trim((string) ($_POST['rol_id'] ?? ''));
    $values['ingenio_id'] = trim((string) ($_POST['ingenio_id'] ?? ''));
    $password = trim((string) ($_POST['contrasena'] ?? ''));

    if ($values['nombre'] === '') {
        $errors[] = 'El nombre es obligatorio.';
    }

    if ($values['correo'] === '') {
        $errors[] = 'El correo es obligatorio.';
    } elseif (!filter_var($values['correo'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo no tiene un formato válido.';
    }

    if ($values['rol_id'] === '' || (int) $values['rol_id'] === 1) {
        $errors[] = 'Debe seleccionar un rol válido para el módulo Laboratorio.';
    }

    $stmtCheck = $conn->prepare(
        'SELECT id
        FROM ' . lab_users_table('usuarios') . '
        WHERE correo = ? AND id <> ?
        LIMIT 1'
    );
    $stmtCheck->execute([$values['correo'], $id]);
    if ($stmtCheck->fetch()) {
        $errors[] = 'Ya existe otro usuario registrado con ese correo.';
    }

    if (empty($errors)) {
        $ingenioId = $values['ingenio_id'] === '' ? null : (int) $values['ingenio_id'];

        try {
            $conn->beginTransaction();

            if ($password !== '') {
                $stmt = $conn->prepare(
                    'UPDATE ' . lab_users_table('usuarios') . '
                    SET nombre = ?, correo = ?, contrasena = ?, rol_id = ?, ingenio_id = ?, es_superadmin = 0
                    WHERE id = ?'
                );
                $stmt->execute([
                    $values['nombre'],
                    $values['correo'],
                    password_hash($password, PASSWORD_DEFAULT),
                    (int) $values['rol_id'],
                    $ingenioId,
                    $id,
                ]);
            } else {
                $stmt = $conn->prepare(
                    'UPDATE ' . lab_users_table('usuarios') . '
                    SET nombre = ?, correo = ?, rol_id = ?, ingenio_id = ?, es_superadmin = 0
                    WHERE id = ?'
                );
                $stmt->execute([
                    $values['nombre'],
                    $values['correo'],
                    (int) $values['rol_id'],
                    $ingenioId,
                    $id,
                ]);
            }

            $stmtModule = $conn->prepare(
                'INSERT INTO ' . lab_users_table('usuario_modulo') . ' (usuario_id, modulo_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE modulo_id = VALUES(modulo_id)'
            );
            $stmtModule->execute([$id, (int) $module['id']]);

            $conn->commit();
            lab_user_module_redirect_to_list();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $errors[] = 'No fue posible actualizar el usuario. Intente nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar usuario de Laboratorio</title>
    <link rel="stylesheet" href="../login/assets/usuarios.css?v=<?= filemtime(__DIR__ . '/../login/assets/usuarios.css') ?>">
    <link rel="stylesheet" href="styles/button.css">
    <style>
        .page-shell {
            max-width: 760px;
            margin: 0 auto;
        }

        .page-note {
            margin: 12px auto 0;
            max-width: 400px;
            color: #5f6b5f;
            text-align: center;
        }

        .module-pill {
            display: inline-block;
            margin-top: 12px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eef8df;
            color: #466b24;
            font-weight: 600;
        }

        .error-box {
            max-width: 400px;
            margin: 12px auto 0;
            padding: 12px 14px;
            border-radius: 10px;
            background: #fdecec;
            color: #9b2c2c;
            border: 1px solid #f5c2c2;
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <a href="<?= lab_users_e(lab_user_module_list_url()) ?>" class="btn-volver">← Volver</a>

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <?php foreach ($errors as $error): ?>
                    <div><?= lab_users_e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <h2>Editar Usuario</h2>
            <p class="page-note">Este usuario seguirá asignado al módulo <?= lab_users_e($module['nombre']) ?>.</p>
            <div class="module-pill"><?= lab_users_e($module['nombre']) ?></div>

            <input type="text" name="nombre" placeholder="Nombre" value="<?= lab_users_e($values['nombre']) ?>" required>
            <input type="email" name="correo" placeholder="Correo" value="<?= lab_users_e($values['correo']) ?>" required>
            <input type="password" name="contrasena" placeholder="Nueva contraseña (opcional)">

            <label>Rol</label>
            <select name="rol_id" required>
                <?php foreach ($roles as $rol): ?>
                    <option value="<?= (int) $rol['id'] ?>" <?= (string) $rol['id'] === $values['rol_id'] ? 'selected' : '' ?>>
                        <?= lab_users_e($rol['nombre_rol']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Ingenio</label>
            <select name="ingenio_id">
                <option value="">Sin ingenio</option>
                <?php foreach ($ingenios as $ingenio): ?>
                    <option value="<?= (int) $ingenio['id'] ?>" <?= (string) $ingenio['id'] === $values['ingenio_id'] ? 'selected' : '' ?>>
                        <?= lab_users_e($ingenio['nombre_ingenio']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Guardar</button>
        </form>
    </div>
</body>
</html>
