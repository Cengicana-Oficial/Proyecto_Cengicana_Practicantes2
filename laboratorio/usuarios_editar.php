<?php

require_once __DIR__ . '/includes/user_module_helper.php';

lab_require_permission('laboratorio.usuarios.gestionar');

$conn = lab_users_connection();
$module = lab_laboratory_module($conn);
$roles = lab_fetch_roles_for_user_module($conn);
$ingenios = lab_fetch_ingenios_for_user_module($conn);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    lab_forbidden("El usuario solicitado no es v\u{00E1}lido.");
}

$usuario = lab_fetch_laboratory_user($conn, (int) $module['id'], $id);
if ($usuario === null) {
    lab_forbidden("El usuario solicitado no pertenece al m\u{00F3}dulo Laboratorio.");
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
        $errors[] = "El correo no tiene un formato v\u{00E1}lido.";
    }

    if ($values['rol_id'] === '' || (int) $values['rol_id'] === 1) {
        $errors[] = "Debe seleccionar un rol v\u{00E1}lido para el m\u{00F3}dulo Laboratorio.";
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles/base.css?v=<?= filemtime(__DIR__ . '/styles/base.css') ?>">
    <style>
        :root {
            --white: #ffffff;
            --page-bg: #ffffff;
            --surface: #ffffff;
            --surface-soft: #f7f8f8;
            --border: #ced2d5;
            --border-strong: #ced2d5;
            --panel-border: var(--border);
            --text-main: #232523;
            --text-soft: #4a4d49;
            --text-mute: #4a4d49;
            --text-secondary: #4a4d49;
            --brand: #73bc25;
            --brand-soft: rgba(115, 188, 37, 0.12);
            --brand-accent: #73bc25;
            --brand-secondary: #a3d300;
            --chip-bg: rgba(163, 211, 0, 0.14);
            --danger: #ff6b00;
            --danger-soft: rgba(255, 107, 0, 0.08);
            --warning: #ffcc00;
            --warning-soft: rgba(255, 204, 0, 0.14);
            --green-50: #eef7e2;
            --green-100: #dceebf;
            --green-200: #c8e58d;
            --green-300: #b3da5a;
            --green-400: #9ed129;
            --green-500: #88c818;
            --green-600: #73bc25;
            --green-700: #5f9d1f;
            --green-800: #4a7d19;
            --green-900: #335512;
            --teal-50: #f4fbe3;
            --teal-100: #e5f3b7;
            --teal-200: #d7eb86;
            --teal-300: #c8e356;
            --teal-400: #b7da2f;
            --teal-500: #a3d300;
            --teal-600: #8db600;
            --teal-700: #789700;
            --teal-800: #627800;
            --tertiary-50: #fff1e3;
            --tertiary-100: #ffd9b8;
            --tertiary-200: #ffbf86;
            --tertiary-300: #ffa255;
            --tertiary-400: #ff8a33;
            --tertiary-500: #ff6b00;
            --tertiary-600: #d95800;
            --tertiary-700: #b34700;
            --tertiary-800: #8c3600;
            --neutral-50: #f7f8f8;
            --neutral-100: #eceeed;
            --neutral-200: #dde1e3;
            --neutral-300: #ced2d5;
            --neutral-400: #b9bec2;
            --neutral-500: #a4aaaf;
            --neutral-600: #8d9398;
            --neutral-700: #6f7579;
            --neutral-800: #555b5f;
            --neutral-900: #232523;
            --color-info-50: #f4fbe3;
            --color-info-100: #e5f3b7;
            --color-info-200: #d7eb86;
            --color-info-300: #c8e356;
            --color-info-400: #b7da2f;
            --color-info-500: #a3d300;
            --color-info-600: #8db600;
            --color-info-700: #789700;
            --color-warning-50: #fff8db;
            --color-warning-100: #ffef9f;
            --color-warning-200: #ffe866;
            --color-warning-300: #ffdb33;
            --color-warning-400: #ffcf0f;
            --color-warning-500: #ffcc00;
            --color-warning-600: #d9ad00;
            --color-warning-700: #b38f00;
            --color-danger-50: #fff0e3;
            --color-danger-100: #ffd5b8;
            --color-danger-200: #ffb486;
            --color-danger-300: #ff9155;
            --color-danger-400: #ff7833;
            --color-danger-500: #ff6b00;
            --color-danger-600: #d95a00;
            --color-danger-700: #b34a00;
            --lab-body-background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(255, 255, 255, 0.97)),
                radial-gradient(circle at top right, rgba(163, 211, 0, 0.10), transparent 30%),
                linear-gradient(to right, rgba(206, 210, 213, 0.34) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(206, 210, 213, 0.34) 1px, transparent 1px);
            --lab-body-background-size: auto, auto, 24px 24px, 24px 24px;
            --lab-body-background-position: center, center, center, center;
            --lab-body-surface: rgba(255, 255, 255, 0.96);
            --lab-body-surface-strong: rgba(255, 255, 255, 0.99);
            --lab-body-border-soft: rgba(206, 210, 213, 0.82);
            --lab-body-border-strong: rgba(206, 210, 213, 0.95);
            --surface-card-bg: var(--white);
            --surface-panel-bg: var(--white);
            --surface-hero-bg: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(255, 255, 255, 0.96));
            --surface-border: 1px solid var(--border);
            --surface-border-soft: 1px solid var(--border);
            --surface-border-strong: 1px solid var(--border);
            --surface-radius-card: 28px;
            --surface-radius-panel: 28px;
            --surface-radius-hero: 28px;
            --surface-shadow-card: 0 18px 48px rgba(12, 39, 27, 0.08);
            --surface-shadow-panel: 0 18px 48px rgba(12, 39, 27, 0.08);
            --surface-shadow-hero: 0 18px 48px rgba(12, 39, 27, 0.08);
            --state-success-bg: var(--green-50);
            --state-success-text: var(--green-800);
            --state-success-border: var(--green-200);
            --state-info-bg: var(--teal-50);
            --state-info-text: var(--teal-700);
            --state-info-border: var(--teal-200);
            --state-warning-bg: var(--color-warning-50);
            --state-warning-text: var(--color-warning-700);
            --state-warning-border: var(--color-warning-200);
            --state-danger-bg: var(--color-danger-50);
            --state-danger-text: var(--color-danger-700);
            --state-danger-border: var(--color-danger-200);
        }

        body {
            padding: 32px 18px 40px;
            background: var(--lab-body-background);
            background-size: var(--lab-body-background-size);
            background-position: var(--lab-body-background-position);
            color: var(--text-main);
        }

        a {
            text-decoration: none;
        }

        button,
        input,
        select {
            font: inherit;
        }

        .page-shell {
            max-width: 980px;
            margin: 0 auto;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding: 10px 14px;
            border: 1px solid rgba(206, 210, 213, 0.84);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            color: #73bc25;
            font-weight: 700;
            box-shadow: 0 10px 22px rgba(12, 39, 27, 0.05);
            backdrop-filter: blur(10px);
            width: fit-content;
            text-decoration: none;
            transition:
                transform 0.18s ease,
                box-shadow 0.18s ease,
                border-color 0.18s ease,
                background 0.18s ease;
        }

        .back-link:hover,
        .back-link:focus-visible {
            transform: translateY(-1px);
            border-color: rgba(115, 188, 37, 0.24);
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 14px 26px rgba(12, 39, 27, 0.07);
            outline: none;
        }

        .admin-card {
            border: 1px solid rgba(206, 210, 213, 0.72);
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 251, 247, 0.94));
            box-shadow: 0 18px 48px rgba(12, 39, 27, 0.08);
            overflow: hidden;
        }

        .admin-hero {
            display: flex;
            flex-direction: column;
            gap: 0;
            padding: 30px 30px 22px;
            border-bottom: 1px solid rgba(206, 210, 213, 0.72);
        }

        .hero-copy {
            display: flex;
            flex-direction: column;
            gap: 0;
            max-width: 760px;
        }

        .hero-copy .back-link {
            margin-bottom: 12px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(115, 188, 37, 0.14), rgba(255, 255, 255, 0.96));
            color: #73bc25;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .hero-copy h1 {
            margin: 0;
            font-size: clamp(32px, 4vw, 42px);
            line-height: 1.02;
            letter-spacing: -0.04em;
            color: #73bc25;
        }

        .hero-copy p {
            max-width: 680px;
            margin: 12px 0 0;
            color: #4a4d49;
            font-size: 15px;
            line-height: 1.6;
        }

        .module-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            margin-top: 16px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(163, 211, 0, 0.14);
            color: #73bc25;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .error-box {
            margin: 0 30px 0;
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(255, 107, 0, 0.08);
            color: #8c3600;
            border: 1px solid rgba(255, 107, 0, 0.18);
        }

        .admin-form-wrap {
            padding: 24px 30px 30px;
        }

        form {
            display: grid;
            gap: 14px;
            max-width: none;
            margin: 0;
            padding: 0;
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        form label {
            margin-top: 2px;
            color: #4a4d49;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        form input,
        form select {
            width: 100%;
            min-height: 50px;
            padding: 0 16px;
            border: 1px solid rgba(206, 210, 213, 0.92);
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff, #fafcf9);
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.85);
            color: #232523;
            font: inherit;
            font-size: 14px;
            transition:
                border-color 0.18s ease,
                box-shadow 0.18s ease,
                background 0.18s ease,
                transform 0.18s ease;
        }

        form input:hover,
        form select:hover {
            border-color: rgba(115, 188, 37, 0.22);
            background: linear-gradient(180deg, #ffffff, #f6fbf6);
            box-shadow: 0 8px 20px rgba(12, 39, 27, 0.06);
            transform: translateY(-1px);
        }

        form input:focus,
        form select:focus {
            outline: none;
            border-color: rgba(115, 188, 37, 0.34);
            box-shadow:
                0 0 0 4px rgba(115, 188, 37, 0.12),
                0 8px 20px rgba(12, 39, 27, 0.06);
        }

        form select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            cursor: pointer;
        }

        form button[type="submit"] {
            justify-self: start;
            margin-top: 10px;
            --btn-bg: linear-gradient(180deg, var(--green-600), var(--green-800));
            --btn-border: var(--green-600);
            --btn-fg: var(--white);
            --btn-shadow: 0 10px 18px rgba(23, 52, 4, 0.18);
        }

        .page-note {
            margin: 0;
            color: #4a4d49;
        }

        @media (max-width: 900px) {
            .admin-hero,
            .admin-form-wrap,
            .error-box {
                padding-left: 22px;
                padding-right: 22px;
            }

            .admin-form-wrap {
                padding-top: 22px;
            }
        }

        @media (max-width: 760px) {
            body {
                padding: 22px 12px 28px;
            }

            .page-shell {
                max-width: 100%;
            }

            .admin-hero,
            .admin-form-wrap,
            .error-box {
                padding-left: 18px;
                padding-right: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <section class="admin-card">
            <div class="admin-hero">
                <a href="<?= lab_users_e(lab_user_module_list_url()) ?>" class="back-link">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Volver</span>
                </a>

                <div class="hero-copy">
                    <span class="eyebrow">
                        <i class="fa-solid fa-flask-vial"></i>
                        <span>Gesti&oacute;n de usuarios del m&oacute;dulo</span>
                    </span>
                    <h1>Editar Usuario</h1>
                    <p class="page-note">Actualiza los datos del usuario sin salir del m&oacute;dulo <?= lab_users_e($module['nombre']) ?>.</p>
                    <span class="module-pill"><?= lab_users_e($module['nombre']) ?></span>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <?php foreach ($errors as $error): ?>
                        <div><?= lab_users_e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="admin-form-wrap">
                <form method="POST">
                    <input type="text" name="nombre" placeholder="Nombre" value="<?= lab_users_e($values['nombre']) ?>" required>
                    <input type="email" name="correo" placeholder="Correo" value="<?= lab_users_e($values['correo']) ?>" required>
                    <input type="password" name="contrasena" placeholder="Nueva contrase&ntilde;a (opcional)">

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

                    <button type="submit">Guardar cambios</button>
                </form>
            </div>
        </section>
    </div>
</body>
</html>
