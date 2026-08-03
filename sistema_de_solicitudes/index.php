<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../login/login.php');
    exit;
}

$user = current_user($menuPdo, $pdo);
if (!$user) {
    header('Location: ../login/login.php');
    exit;
}

if (!user_has_module_access($user, $menuPdo)) {
    header('Location: ../login/Menu.php');
    exit;
}

$view = $_GET['view'] ?? 'panel';
$message = $_GET['message'] ?? '';
$error = '';
$moduleIds = module_ids($menuPdo);
$moduleId = $moduleIds[0] ?? null;

$programs = $pdo->query('SELECT * FROM programas WHERE activo = 1 ORDER BY nombre')->fetchAll();
$dbNameStmt = $pdo->query('SELECT DATABASE()');
$solicitudesDbName = (string) $dbNameStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'crear_programa') {
            if (!can_manage_programs($user)) {
                throw new RuntimeException('No tienes permiso para crear programas.');
            }

            $name = trim($_POST['nombre'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Escribe el nombre del programa.');
            }

            $stmt = $pdo->prepare('INSERT INTO programas (nombre) VALUES (?)');
            $stmt->execute([$name]);

            header('Location: index.php?view=programas&message=' . urlencode('Programa creado correctamente.'));
            exit;
        }

        if ($action === 'actualizar_programa') {
            if (!can_manage_programs($user)) {
                throw new RuntimeException('No tienes permiso para modificar programas.');
            }

            $programId = (int) ($_POST['programa_id'] ?? 0);
            $name = trim($_POST['nombre'] ?? '');

            if ($programId <= 0 || $name === '') {
                throw new RuntimeException('Completa los datos del programa.');
            }

            $stmt = $pdo->prepare('UPDATE programas SET nombre = ? WHERE id = ?');
            $stmt->execute([$name, $programId]);

            header('Location: index.php?view=programas&message=' . urlencode('Programa actualizado correctamente.'));
            exit;
        }

        if ($action === 'eliminar_programa') {
            if (!can_manage_programs($user)) {
                throw new RuntimeException('No tienes permiso para eliminar programas.');
            }

            $programId = (int) ($_POST['programa_id'] ?? 0);
            if ($programId <= 0) {
                throw new RuntimeException('Programa no valido.');
            }

            $usageStmt = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM usuario_programa WHERE programa_id = ?) +
                    (SELECT COUNT(*) FROM solicitudes WHERE programa_origen_id = ? OR programa_destino_id = ?)'
            );
            $usageStmt->execute([$programId, $programId, $programId]);
            if ((int) $usageStmt->fetchColumn() > 0) {
                throw new RuntimeException('No se puede eliminar el programa porque ya tiene usuarios o solicitudes relacionadas.');
            }

            $stmt = $pdo->prepare('DELETE FROM programas WHERE id = ?');
            $stmt->execute([$programId]);

            header('Location: index.php?view=programas&message=' . urlencode('Programa eliminado correctamente.'));
            exit;
        }

        if ($action === 'crear_usuario') {
            if (!can_manage_users($user)) {
                throw new RuntimeException('No tienes permiso para crear usuarios.');
            }

            if ($moduleId === null) {
                throw new RuntimeException('El modulo Sistema de solicitudes no esta registrado en la base principal.');
            }

            $name = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['correo'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $roleId = (int) ($_POST['rol_id'] ?? 0);
            $ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
            $programId = (int) ($_POST['programa_id'] ?? 0);

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || $roleId <= 0 || $ingenioId <= 0) {
                throw new RuntimeException('Completa nombre, correo, contrasena, rol e ingenio.');
            }

            $roleStmt = $menuPdo->prepare('SELECT id, nombre_rol FROM roles WHERE id = ?');
            $roleStmt->execute([$roleId]);
            $role = $roleStmt->fetch();
            if (!$role) {
                throw new RuntimeException('El rol seleccionado no existe.');
            }

            $newIsSuperadmin = role_is_superadmin((string) $role['nombre_rol']);
            if ($newIsSuperadmin && !is_superadmin($user)) {
                throw new RuntimeException('Solo el superadmin puede crear otro superadmin.');
            }

            if (!$newIsSuperadmin && $programId <= 0) {
                throw new RuntimeException('Selecciona el programa para el usuario.');
            }

            $checkStmt = $menuPdo->prepare('SELECT id FROM usuarios WHERE correo = ?');
            $checkStmt->execute([$email]);
            if ($checkStmt->fetchColumn()) {
                throw new RuntimeException('Ya existe un usuario con ese correo.');
            }

            $menuPdo->beginTransaction();
            $pdo->beginTransaction();

            $insertUser = $menuPdo->prepare(
                'INSERT INTO usuarios (nombre, correo, contrasena, rol_id, ingenio_id, es_superadmin)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertUser->execute([
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $roleId,
                $ingenioId,
                $newIsSuperadmin ? 1 : 0,
            ]);

            $newUserId = (int) $menuPdo->lastInsertId();

            $insertModule = $menuPdo->prepare(
                'INSERT INTO usuario_modulo (usuario_id, modulo_id) VALUES (?, ?)'
            );
            $insertModule->execute([$newUserId, $moduleId]);

            if ($programId > 0) {
                $insertProgram = $pdo->prepare(
                    'INSERT INTO usuario_programa (usuario_id, programa_id) VALUES (?, ?)'
                );
                $insertProgram->execute([$newUserId, $programId]);
            }

            $pdo->commit();
            $menuPdo->commit();

            header('Location: index.php?view=usuarios&message=' . urlencode('Usuario creado correctamente.'));
            exit;
        }

        if ($action === 'actualizar_usuario') {
            if (!can_manage_users($user)) {
                throw new RuntimeException('No tienes permiso para modificar usuarios.');
            }

            if ($moduleId === null) {
                throw new RuntimeException('El modulo Sistema de solicitudes no esta registrado en la base principal.');
            }

            $userId = (int) ($_POST['usuario_id'] ?? 0);
            $name = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['correo'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $roleId = (int) ($_POST['rol_id'] ?? 0);
            $ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
            $programId = (int) ($_POST['programa_id'] ?? 0);
            $assignModule = isset($_POST['asignado_modulo']) ? 1 : 0;

            if ($userId <= 0 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $roleId <= 0 || $ingenioId <= 0) {
                throw new RuntimeException('Completa los datos obligatorios del usuario.');
            }

            $targetStmt = $menuPdo->prepare(
                'SELECT u.id, u.es_superadmin, r.nombre_rol
                 FROM usuarios u
                 INNER JOIN roles r ON r.id = u.rol_id
                 WHERE u.id = ?'
            );
            $targetStmt->execute([$userId]);
            $target = $targetStmt->fetch();
            if (!$target) {
                throw new RuntimeException('Usuario no encontrado.');
            }

            if ((int) $user['id'] === $userId && !$assignModule && !is_superadmin($user)) {
                throw new RuntimeException('No puedes quitarte el acceso al modulo actual.');
            }

            if ((int) $target['es_superadmin'] === 1 && !is_superadmin($user)) {
                throw new RuntimeException('Solo el superadmin puede editar otro superadmin.');
            }

            $roleStmt = $menuPdo->prepare('SELECT id, nombre_rol FROM roles WHERE id = ?');
            $roleStmt->execute([$roleId]);
            $role = $roleStmt->fetch();
            if (!$role) {
                throw new RuntimeException('El rol seleccionado no existe.');
            }

            $newIsSuperadmin = role_is_superadmin((string) $role['nombre_rol']);
            if ($newIsSuperadmin && !is_superadmin($user)) {
                throw new RuntimeException('Solo el superadmin puede asignar el rol superadmin.');
            }

            if ($assignModule && !$newIsSuperadmin && $programId <= 0) {
                throw new RuntimeException('Selecciona el programa para el usuario.');
            }

            $checkStmt = $menuPdo->prepare('SELECT id FROM usuarios WHERE correo = ? AND id <> ?');
            $checkStmt->execute([$email, $userId]);
            if ($checkStmt->fetchColumn()) {
                throw new RuntimeException('Ya existe otro usuario con ese correo.');
            }

            $menuPdo->beginTransaction();
            $pdo->beginTransaction();

            $sql = 'UPDATE usuarios SET nombre = ?, correo = ?, rol_id = ?, ingenio_id = ?, es_superadmin = ?';
            $params = [$name, $email, $roleId, $ingenioId, $newIsSuperadmin ? 1 : 0];
            if ($password !== '') {
                $sql .= ', contrasena = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $params[] = $userId;

            $menuPdo->prepare($sql)->execute($params);

            $moduleCheckStmt = $menuPdo->prepare(
                'SELECT COUNT(*) FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?'
            );
            $moduleCheckStmt->execute([$userId, $moduleId]);
            $hasModule = (int) $moduleCheckStmt->fetchColumn() > 0;

            if ($assignModule && !$hasModule) {
                $menuPdo->prepare('INSERT INTO usuario_modulo (usuario_id, modulo_id) VALUES (?, ?)')
                    ->execute([$userId, $moduleId]);
            }

            if (!$assignModule && $hasModule) {
                $menuPdo->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?')
                    ->execute([$userId, $moduleId]);
            }

            if ($assignModule && $programId > 0) {
                $pdo->prepare(
                    'INSERT INTO usuario_programa (usuario_id, programa_id)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE programa_id = VALUES(programa_id)'
                )->execute([$userId, $programId]);
            } else {
                $pdo->prepare('DELETE FROM usuario_programa WHERE usuario_id = ?')->execute([$userId]);
            }

            $pdo->commit();
            $menuPdo->commit();

            header('Location: index.php?view=usuarios&message=' . urlencode('Usuario actualizado correctamente.'));
            exit;
        }

        if ($action === 'eliminar_usuario') {
            if (!can_manage_users($user)) {
                throw new RuntimeException('No tienes permiso para eliminar usuarios.');
            }

            $userId = (int) ($_POST['usuario_id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Usuario no valido.');
            }

            if ((int) $user['id'] === $userId) {
                throw new RuntimeException('No puedes eliminar tu propio usuario.');
            }

            $targetStmt = $menuPdo->prepare('SELECT es_superadmin FROM usuarios WHERE id = ?');
            $targetStmt->execute([$userId]);
            $target = $targetStmt->fetch();
            if (!$target) {
                throw new RuntimeException('Usuario no encontrado.');
            }

            if ((int) $target['es_superadmin'] === 1) {
                throw new RuntimeException('No se puede eliminar un superadmin desde este modulo.');
            }

            $menuPdo->beginTransaction();
            $pdo->beginTransaction();

            $menuPdo->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id IN (' . implode(',', array_fill(0, count($moduleIds), '?')) . ')')
                ->execute(array_merge([$userId], $moduleIds));
            $pdo->prepare('DELETE FROM usuario_programa WHERE usuario_id = ?')->execute([$userId]);
            $menuPdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$userId]);

            $pdo->commit();
            $menuPdo->commit();

            header('Location: index.php?view=usuarios&message=' . urlencode('Usuario eliminado correctamente.'));
            exit;
        }

        if ($action === 'crear_solicitud') {
            if (!can_create_requests($user)) {
                throw new RuntimeException('Tu usuario necesita un programa asignado para crear solicitudes.');
            }

            $type = $_POST['tipo'] ?? '';
            $title = trim($_POST['titulo'] ?? '');
            $description = trim($_POST['descripcion'] ?? '');
            $priority = $_POST['prioridad'] ?? 'media';
            $programOriginId = is_superadmin($user)
                ? (int) ($_POST['programa_origen_id'] ?? 0)
                : (int) ($user['programa_id'] ?? 0);
            $programDestinationId = $type === 'apoyo'
                ? (int) ($_POST['programa_destino_id'] ?? 0)
                : 0;

            $administrationId = find_program_id_by_name($programs, 'Administracion');

            if (!in_array($type, ['compra', 'ti', 'apoyo'], true) || $title === '' || $description === '') {
                throw new RuntimeException('Completa los campos obligatorios antes de guardar la solicitud.');
            }

            if ($type === 'apoyo') {
                if ($programOriginId <= 0 || $programDestinationId <= 0) {
                    throw new RuntimeException('Selecciona el programa origen y el programa destino.');
                }
                if ($programOriginId === $programDestinationId) {
                    throw new RuntimeException('El programa destino debe ser distinto al programa origen.');
                }
            } else {
                if ($administrationId === null) {
                    throw new RuntimeException('No se encontro el programa Administracion.');
                }

                $programDestinationId = $administrationId;
                if ($programOriginId <= 0) {
                    $programOriginId = $administrationId;
                }
            }

            $code = next_codigo($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO solicitudes
                (codigo, solicitante_id, programa_origen_id, programa_destino_id, tipo, prioridad, titulo, descripcion, fecha_requerida, proveedor_sugerido, monto_estimado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $code,
                (int) $user['id'],
                $programOriginId,
                $programDestinationId > 0 ? $programDestinationId : null,
                $type,
                $priority,
                $title,
                $description,
                $_POST['fecha_requerida'] ?: null,
                trim($_POST['proveedor_sugerido'] ?? '') ?: null,
                $_POST['monto_estimado'] !== '' ? (float) $_POST['monto_estimado'] : null,
            ]);

            $requestId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO seguimientos (solicitud_id, usuario_id, estado, observacion) VALUES (?, ?, ?, ?)'
            )->execute([$requestId, (int) $user['id'], 'recibido', 'Solicitud registrada en el sistema.']);

            if (!empty($_FILES['adjuntos']['name'][0])) {
                $uploadDir = __DIR__ . '/uploads';
                foreach ($_FILES['adjuntos']['name'] as $index => $name) {
                    if ($_FILES['adjuntos']['error'][$index] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));
                    $targetName = $code . '_' . time() . '_' . $safeName;
                    $targetPath = $uploadDir . '/' . $targetName;

                    if (move_uploaded_file($_FILES['adjuntos']['tmp_name'][$index], $targetPath)) {
                        $pdo->prepare(
                            'INSERT INTO adjuntos (solicitud_id, nombre_original, ruta, mime_type, tamano_bytes)
                             VALUES (?, ?, ?, ?, ?)'
                        )->execute([
                            $requestId,
                            $name,
                            'uploads/' . $targetName,
                            $_FILES['adjuntos']['type'][$index],
                            (int) $_FILES['adjuntos']['size'][$index],
                        ]);
                    }
                }
            }

            header('Location: index.php?view=mis&message=' . urlencode("Solicitud {$code} creada correctamente."));
            exit;
        }

        if ($action === 'actualizar_estado') {
            $requestId = (int) ($_POST['solicitud_id'] ?? 0);
            $status = $_POST['estado'] ?? '';

            $stmt = $pdo->prepare('SELECT * FROM solicitudes WHERE id = ?');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();

            if (!$request || !can_manage_request($user, $request) || !in_array($status, ['recibido', 'proceso', 'completado', 'rechazado'], true)) {
                throw new RuntimeException('No puedes actualizar esta solicitud.');
            }

            if ($request['estado'] === 'completado') {
                throw new RuntimeException('Las solicitudes completadas ya no se pueden modificar.');
            }

            $pdo->prepare('UPDATE solicitudes SET estado = ? WHERE id = ?')->execute([$status, $requestId]);
            $pdo->prepare(
                'INSERT INTO seguimientos (solicitud_id, usuario_id, estado, observacion) VALUES (?, ?, ?, ?)'
            )->execute([$requestId, (int) $user['id'], $status, trim($_POST['observacion'] ?? '') ?: null]);

            if (!empty($_FILES['seguimiento_adjuntos']['name'][0])) {
                $uploadDir = __DIR__ . '/uploads';
                foreach ($_FILES['seguimiento_adjuntos']['name'] as $index => $name) {
                    if ($_FILES['seguimiento_adjuntos']['error'][$index] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));
                    $targetName = $request['codigo'] . '_seguimiento_' . time() . '_' . $safeName;
                    $targetPath = $uploadDir . '/' . $targetName;

                    if (move_uploaded_file($_FILES['seguimiento_adjuntos']['tmp_name'][$index], $targetPath)) {
                        $pdo->prepare(
                            'INSERT INTO adjuntos (solicitud_id, nombre_original, ruta, mime_type, tamano_bytes)
                             VALUES (?, ?, ?, ?, ?)'
                        )->execute([
                            $requestId,
                            $name,
                            'uploads/' . $targetName,
                            $_FILES['seguimiento_adjuntos']['type'][$index],
                            (int) $_FILES['seguimiento_adjuntos']['size'][$index],
                        ]);
                    }
                }
            }

            header('Location: index.php?view=detalle&id=' . $requestId . '&message=' . urlencode('Estado actualizado correctamente.'));
            exit;
        }
    } catch (Throwable $exception) {
        if ($menuPdo->inTransaction()) {
            $menuPdo->rollBack();
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

[$visibleWhere, $visibleParams] = request_scope_sql($user);
[$sentWhere, $sentParams] = sent_scope_sql($user);
[$receivedWhere, $receivedParams] = received_scope_sql($user);
[$managementWhere, $managementParams] = management_scope_sql($user);

$statsStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(estado = 'recibido') AS recibidas,
        SUM(estado = 'proceso') AS proceso,
        SUM(estado = 'completado') AS completadas
     FROM solicitudes s
     WHERE {$visibleWhere}"
);
$statsStmt->execute($visibleParams);
$stats = $statsStmt->fetch() ?: ['total' => 0, 'recibidas' => 0, 'proceso' => 0, 'completadas' => 0];

$programCardsSql = "
    SELECT p.id, p.nombre, COUNT(s.id) AS total
    FROM programas p
    LEFT JOIN solicitudes s ON s.programa_destino_id = p.id
";
$programCardsWhere = [];
$programCardsParams = [];

if (!is_superadmin($user) && !empty($user['programa_id'])) {
    $programCardsWhere[] = 'p.id = ?';
    $programCardsParams[] = (int) $user['programa_id'];
}

$programCardsWhere[] = 'p.activo = 1';
$programCardsSql .= ' WHERE ' . implode(' AND ', $programCardsWhere);
$programCardsSql .= ' GROUP BY p.id, p.nombre ORDER BY p.nombre';
$programCardsStmt = $pdo->prepare($programCardsSql);
$programCardsStmt->execute($programCardsParams);
$programCounts = $programCardsStmt->fetchAll();

$myStmt = $pdo->prepare(
    "SELECT s.*, po.nombre AS programa_origen, pd.nombre AS programa_destino
     FROM solicitudes s
     INNER JOIN programas po ON po.id = s.programa_origen_id
     LEFT JOIN programas pd ON pd.id = s.programa_destino_id
     WHERE {$sentWhere}
     ORDER BY s.creado_en DESC"
);
$myStmt->execute($sentParams);
$visibleRequests = $myStmt->fetchAll();

$receivedListSql = "SELECT s.*, u.nombre AS solicitante, po.nombre AS programa_origen, pd.nombre AS programa_destino
     FROM solicitudes s
     INNER JOIN {$menuDbName}.usuarios u ON u.id = s.solicitante_id
     INNER JOIN programas po ON po.id = s.programa_origen_id
     LEFT JOIN programas pd ON pd.id = s.programa_destino_id
     WHERE {$receivedWhere}";
$receivedListParams = $receivedParams;
$destinationFilter = isset($_GET['programa_destino']) ? (int) $_GET['programa_destino'] : 0;
if ($destinationFilter > 0) {
    $receivedListSql .= ' AND s.programa_destino_id = ?';
    $receivedListParams[] = $destinationFilter;
}
$receivedListSql .= ' ORDER BY s.creado_en DESC';
$receivedListStmt = $pdo->prepare($receivedListSql);
$receivedListStmt->execute($receivedListParams);
$receivedRequests = $receivedListStmt->fetchAll();

$selectedDestination = null;
foreach ($programs as $program) {
    if ((int) $program['id'] === $destinationFilter) {
        $selectedDestination = $program;
        break;
    }
}

$managementSql = "
    SELECT s.*, u.nombre AS solicitante, po.nombre AS programa_origen, pd.nombre AS programa_destino
    FROM solicitudes s
    INNER JOIN {$solicitudesDbName}.programas po ON po.id = s.programa_origen_id
    LEFT JOIN {$solicitudesDbName}.programas pd ON pd.id = s.programa_destino_id
    INNER JOIN {$menuDbName}.usuarios u ON u.id = s.solicitante_id
    WHERE {$managementWhere}
";
$managementQueryParams = $managementParams;

if ($destinationFilter > 0) {
    $managementSql .= ' AND s.programa_destino_id = ?';
    $managementQueryParams[] = $destinationFilter;
}

$managementSql .= ' ORDER BY s.creado_en DESC';
$managementStmt = $pdo->prepare($managementSql);
$managementStmt->execute($managementQueryParams);
$managementRequests = $managementStmt->fetchAll();

$users = can_view_users($user) ? fetch_module_users($menuPdo, $pdo, $user) : [];
$rolesStmt = is_superadmin($user)
    ? $menuPdo->query('SELECT id, nombre_rol FROM roles ORDER BY nombre_rol')
    : $menuPdo->query("SELECT id, nombre_rol FROM roles WHERE LOWER(nombre_rol) <> 'superadmin' ORDER BY nombre_rol");
$roles = $rolesStmt->fetchAll();
$ingenios = $menuPdo->query('SELECT id, nombre_ingenio FROM ingenios ORDER BY nombre_ingenio')->fetchAll();

$detail = null;
$followUps = [];
$attachments = [];
if ($view === 'detalle' && isset($_GET['id'])) {
    $detailStmt = $pdo->prepare(
        "SELECT s.*, u.nombre AS solicitante, po.nombre AS programa_origen, pd.nombre AS programa_destino
         FROM solicitudes s
         INNER JOIN {$menuDbName}.usuarios u ON u.id = s.solicitante_id
         INNER JOIN programas po ON po.id = s.programa_origen_id
         LEFT JOIN programas pd ON pd.id = s.programa_destino_id
         WHERE s.id = ?"
    );
    $detailStmt->execute([(int) $_GET['id']]);
    $detail = $detailStmt->fetch();

    if ($detail && !can_view_request($user, $detail)) {
        $detail = null;
        $error = 'No tienes permiso para ver esta solicitud.';
    }

    if ($detail) {
        $followStmt = $pdo->prepare(
            "SELECT sg.*, u.nombre
             FROM seguimientos sg
             INNER JOIN {$menuDbName}.usuarios u ON u.id = sg.usuario_id
             WHERE sg.solicitud_id = ?
             ORDER BY sg.creado_en DESC"
        );
        $followStmt->execute([(int) $_GET['id']]);
        $followUps = $followStmt->fetchAll();

        $attachmentStmt = $pdo->prepare('SELECT * FROM adjuntos WHERE solicitud_id = ? ORDER BY subido_en DESC');
        $attachmentStmt->execute([(int) $_GET['id']]);
        $attachments = $attachmentStmt->fetchAll();
    }
}

$active = static fn(string $name): string => $view === $name ? 'active' : '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistema de solicitudes CENGICANA</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div class="app" data-manual-permission-roles="[]">
    <nav class="nav">
        <a class="nav-logo" href="index.php?view=panel">
            <span class="nav-logo-icon"><i class="ti ti-hexagon-letter-c"></i></span>
            <span>CENGICANA · Solicitudes</span>
        </a>
        <div class="nav-tabs">
            <a class="nav-tab <?= e($active('panel')) ?>" href="index.php?view=panel">Panel</a>
            <a class="nav-tab <?= e($active('mis')) ?>" href="index.php?view=mis">Enviadas</a>
            <?php if (can_view_received_requests($user)): ?>
                <a class="nav-tab <?= e($active('recibidas')) ?>" href="index.php?view=recibidas">Recibidas</a>
            <?php endif; ?>
            <?php if (can_manage_requests($user)): ?>
                <a class="nav-tab <?= e($active('gestion')) ?>" href="index.php?view=gestion">Gestion</a>
            <?php endif; ?>
            <?php if (can_create_requests($user)): ?>
                <a class="nav-tab <?= e($active('nueva')) ?>" href="index.php?view=nueva">Nueva solicitud</a>
            <?php endif; ?>
            <?php if (can_view_users($user)): ?>
                <a class="nav-tab <?= e($active('usuarios')) ?>" href="index.php?view=usuarios">Usuarios</a>
            <?php endif; ?>
            <?php if (can_view_programs($user)): ?>
                <a class="nav-tab <?= e($active('programas')) ?>" href="index.php?view=programas">Programas</a>
            <?php endif; ?>
        </div>
        <div class="nav-user"><i class="ti ti-user-circle"></i><?= e($user['nombre']) ?> · <?= e($user['nombre_rol']) ?></div>
        <a class="nav-logout" href="index.php?logout=1"><i class="ti ti-logout-2"></i>Cerrar sesion</a>
    </nav>

    <main class="container">
        <?php if ($message): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

        <?php if ($view === 'panel'): ?>
            <div class="section-header">
                <div>
                    <div class="section-title">Panel general</div>
                    <div class="section-sub">Resumen de solicitudes visibles para tu usuario.</div>
                </div>
                <?php if (can_create_requests($user)): ?>
                    <a class="btn-primary" href="index.php?view=nueva"><i class="ti ti-plus"></i>Nueva solicitud</a>
                <?php endif; ?>
            </div>

            <section class="stats-row">
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-inbox"></i></div><div><div class="stat-num"><?= (int) $stats['total'] ?></div><div class="stat-label">Total solicitudes</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-mail-opened"></i></div><div><div class="stat-num"><?= (int) $stats['recibidas'] ?></div><div class="stat-label">Recibidas</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-loader"></i></div><div><div class="stat-num"><?= (int) $stats['proceso'] ?></div><div class="stat-label">En proceso</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="ti ti-circle-check"></i></div><div><div class="stat-num"><?= (int) $stats['completadas'] ?></div><div class="stat-label">Completadas</div></div></div>
            </section>

            <div class="section-title">Solicitudes por programa destino</div>
            <div class="section-sub">Cada usuario ve las solicitudes que llegan a su programa. El superadmin ve todas.</div>
            <section class="programs-grid">
                <?php foreach ($programCounts as $program): ?>
                    <a class="program-card card" href="index.php?view=<?= can_manage_requests($user) ? 'gestion' : 'recibidas' ?>&programa_destino=<?= (int) $program['id'] ?>">
                        <div class="program-card-icon"><i class="ti ti-building"></i></div>
                        <div class="program-card-name"><?= e($program['nombre']) ?></div>
                        <div class="program-card-count"><?= (int) $program['total'] ?> solicitudes</div>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($view === 'mis'): ?>
            <div class="section-header">
                <div>
                    <div class="section-title">Solicitudes enviadas</div>
                    <div class="section-sub">
                        <?= is_superadmin($user)
                            ? 'Como superadmin ves el historial completo.'
                            : 'Aqui solo ves las solicitudes que tu usuario ha enviado.' ?>
                    </div>
                </div>
                <?php if (can_create_requests($user)): ?>
                    <a class="btn-primary" href="index.php?view=nueva"><i class="ti ti-plus"></i>Nueva solicitud</a>
                <?php endif; ?>
            </div>
            <div class="filters">
                <div class="search-box"><input class="form-control" id="buscar-mis" oninput="filterRows('buscar-mis','tabla-mis')" placeholder="Buscar solicitud..."></div>
                <button class="filter-chip active" onclick="setStatusFilter(this,'tabla-mis','todas')">Todas</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-mis','recibido')">Recibido</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-mis','proceso')">En proceso</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-mis','completado')">Completado</button>
            </div>
            <?php render_table($visibleRequests, 'tabla-mis', $user); ?>
        <?php endif; ?>

        <?php if ($view === 'recibidas'): ?>
            <div class="section-header">
                <div>
                    <div class="section-title">Solicitudes recibidas</div>
                    <div class="section-sub">
                        <?= is_superadmin($user)
                            ? 'Vista general de solicitudes recibidas por los programas.'
                            : 'Aqui aparecen las solicitudes que llegaron a tu programa asignado.' ?>
                    </div>
                </div>
                <?php if ($selectedDestination): ?>
                    <a class="btn-outline" href="index.php?view=panel">Volver a programas</a>
                <?php endif; ?>
            </div>
            <div class="filters">
                <div class="search-box"><input class="form-control" id="buscar-recibidas" oninput="filterRows('buscar-recibidas','tabla-recibidas')" placeholder="Buscar solicitud..."></div>
                <button class="filter-chip active" onclick="setStatusFilter(this,'tabla-recibidas','todas')">Todas</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-recibidas','recibido')">Recibido</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-recibidas','proceso')">En proceso</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-recibidas','completado')">Completado</button>
            </div>
            <?php render_table($receivedRequests, 'tabla-recibidas', $user, true); ?>
        <?php endif; ?>

        <?php if ($view === 'gestion'): ?>
            <div class="section-header">
                <div>
                    <div class="section-title">Gestion de solicitudes<?= $selectedDestination ? ' · ' . e($selectedDestination['nombre']) : '' ?></div>
                    <div class="section-sub">
                        <?= $selectedDestination ? 'Mostrando solicitudes recibidas por este programa.' : 'Aqui solo se gestionan solicitudes que llegaron a tu programa.' ?>
                    </div>
                </div>
                <?php if ($selectedDestination): ?>
                    <a class="btn-outline" href="index.php?view=panel">Volver a programas</a>
                <?php endif; ?>
            </div>
            <div class="filters">
                <div class="search-box"><input class="form-control" id="buscar-gestion" oninput="filterRows('buscar-gestion','tabla-gestion')" placeholder="Buscar solicitud..."></div>
                <button class="filter-chip active" onclick="setStatusFilter(this,'tabla-gestion','todas')">Todas</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-gestion','recibido')">Recibido</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-gestion','proceso')">En proceso</button>
                <button class="filter-chip" onclick="setStatusFilter(this,'tabla-gestion','completado')">Completado</button>
            </div>
            <?php render_table($managementRequests, 'tabla-gestion', $user, true); ?>
        <?php endif; ?>

        <?php if ($view === 'usuarios'): ?>
            <?php if (!can_view_users($user)): ?>
                <div class="alert error">No tienes permiso para ver usuarios.</div>
            <?php else: ?>
                <div class="section-header">
                    <div>
                        <div class="section-title">Usuarios del modulo</div>
                        <div class="section-sub">El superadmin puede ver todos los usuarios; los demas solo trabajan con usuarios del modulo.</div>
                    </div>
                </div>

                <?php if (can_manage_users($user)): ?>
                    <form class="form-card" method="post">
                        <input type="hidden" name="action" value="crear_usuario">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="usuario_nombre">Nombre <span class="req">*</span></label>
                                <input class="form-control" id="usuario_nombre" name="nombre" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="usuario_correo">Correo <span class="req">*</span></label>
                                <input class="form-control" id="usuario_correo" type="email" name="correo" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="usuario_password">Contrasena <span class="req">*</span></label>
                                <input class="form-control" id="usuario_password" type="password" name="password" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="usuario_rol">Rol <span class="req">*</span></label>
                                <select class="form-control" id="usuario_rol" name="rol_id" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>"><?= e(role_label((string) $role['nombre_rol'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="usuario_ingenio">Ingenio <span class="req">*</span></label>
                                <select class="form-control" id="usuario_ingenio" name="ingenio_id" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($ingenios as $ingenio): ?>
                                        <option value="<?= (int) $ingenio['id'] ?>"><?= e($ingenio['nombre_ingenio']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group form-full">
                                <label class="form-label" for="usuario_programa">Programa asignado</label>
                                <select class="form-control" id="usuario_programa" name="programa_id">
                                    <option value="">Seleccionar programa...</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= (int) $program['id'] ?>"><?= e($program['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">El programa se usa para decidir que solicitudes puede ver y gestionar el usuario.</div>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-primary" type="submit"><i class="ti ti-user-plus"></i>Crear usuario</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Ingenio</th>
                                <th>Programa</th>
                                <th>Modulo</th>
                                <th>Contrasena</th>
                                <?php if (can_manage_users($user)): ?><th>Acciones</th><?php endif; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $userItem): ?>
                                <?php $formId = 'usuario-form-' . (int) $userItem['id']; ?>
                                <tr>
                                    <td>
                                        <?php if (can_manage_users($user)): ?><form id="<?= e($formId) ?>" method="post"></form><?php endif; ?>
                                        <?php if (can_manage_users($user)): ?>
                                            <input form="<?= e($formId) ?>" type="hidden" name="action" value="actualizar_usuario">
                                            <input form="<?= e($formId) ?>" type="hidden" name="usuario_id" value="<?= (int) $userItem['id'] ?>">
                                            <input form="<?= e($formId) ?>" class="form-control table-input" name="nombre" value="<?= e($userItem['nombre']) ?>">
                                        <?php else: ?>
                                            <?= e($userItem['nombre']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <input form="<?= e($formId) ?>" class="form-control table-input" type="email" name="correo" value="<?= e($userItem['correo']) ?>">
                                        <?php else: ?>
                                            <?= e($userItem['correo']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <select form="<?= e($formId) ?>" class="form-control table-input" name="rol_id">
                                                <?php foreach ($roles as $role): ?>
                                                    <option value="<?= (int) $role['id'] ?>" <?= (int) $userItem['rol_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                                        <?= e(role_label((string) $role['nombre_rol'])) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <?= e(role_label((string) $userItem['nombre_rol'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <select form="<?= e($formId) ?>" class="form-control table-input" name="ingenio_id">
                                                <option value="">Seleccionar...</option>
                                                <?php foreach ($ingenios as $ingenio): ?>
                                                    <option value="<?= (int) $ingenio['id'] ?>" <?= (int) ($userItem['ingenio_id'] ?? 0) === (int) $ingenio['id'] ? 'selected' : '' ?>>
                                                        <?= e($ingenio['nombre_ingenio']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <?= e($userItem['ingenio'] ?: 'Sin ingenio') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <select form="<?= e($formId) ?>" class="form-control table-input" name="programa_id">
                                                <option value="">Sin programa</option>
                                                <?php foreach ($programs as $program): ?>
                                                    <option value="<?= (int) $program['id'] ?>" <?= (int) ($userItem['programa_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
                                                        <?= e($program['nombre']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <?= e($userItem['programa'] ?: 'Sin programa') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <label class="permission-option">
                                                <input form="<?= e($formId) ?>" type="checkbox" name="asignado_modulo" <?= (int) $userItem['tiene_modulo'] === 1 ? 'checked' : '' ?>>
                                                <span>Asignado</span>
                                            </label>
                                        <?php else: ?>
                                            <?= (int) $userItem['tiene_modulo'] === 1 ? 'Asignado' : 'No asignado' ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <input form="<?= e($formId) ?>" class="form-control table-input" type="password" name="password" placeholder="Sin cambio">
                                        <?php else: ?>
                                            Protegida
                                        <?php endif; ?>
                                    </td>
                                    <?php if (can_manage_users($user)): ?>
                                        <td>
                                            <div class="actions" style="justify-content:flex-start">
                                                <button form="<?= e($formId) ?>" class="btn-primary btn-sm" type="submit"><i class="ti ti-device-floppy"></i>Guardar</button>
                                                <form method="post" onsubmit="return confirm('Se eliminara este usuario. Continuar?');">
                                                    <input type="hidden" name="action" value="eliminar_usuario">
                                                    <input type="hidden" name="usuario_id" value="<?= (int) $userItem['id'] ?>">
                                                    <button class="btn-outline btn-sm" type="submit">Eliminar</button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'programas'): ?>
            <?php if (!can_view_programs($user)): ?>
                <div class="alert error">No tienes permiso para ver programas.</div>
            <?php else: ?>
                <div class="section-header">
                    <div>
                        <div class="section-title">Programas y direcciones</div>
                        <div class="section-sub">Aqui puedes consultar, crear, editar o eliminar los programas usados por este modulo.</div>
                    </div>
                </div>

                <?php if (can_manage_programs($user)): ?>
                    <form class="form-card" method="post">
                        <input type="hidden" name="action" value="crear_programa">
                        <div class="form-grid">
                            <div class="form-group form-full">
                                <label class="form-label" for="programa_nombre">Nombre del programa <span class="req">*</span></label>
                                <input class="form-control" id="programa_nombre" name="nombre" required>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-primary" type="submit"><i class="ti ti-plus"></i>Crear programa</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Programa</th>
                                <th>Acciones</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($programs as $program): ?>
                                <?php $programFormId = 'programa-form-' . (int) $program['id']; ?>
                                <tr>
                                    <td>
                                        <?php if (can_manage_programs($user)): ?><form id="<?= e($programFormId) ?>" method="post"></form><?php endif; ?>
                                        <?php if (can_manage_programs($user)): ?>
                                            <input form="<?= e($programFormId) ?>" type="hidden" name="action" value="actualizar_programa">
                                            <input form="<?= e($programFormId) ?>" type="hidden" name="programa_id" value="<?= (int) $program['id'] ?>">
                                            <input form="<?= e($programFormId) ?>" class="form-control table-input" name="nombre" value="<?= e($program['nombre']) ?>">
                                        <?php else: ?>
                                            <?= e($program['nombre']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (can_manage_programs($user)): ?>
                                            <div class="actions" style="justify-content:flex-start">
                                                <button form="<?= e($programFormId) ?>" class="btn-primary btn-sm" type="submit"><i class="ti ti-device-floppy"></i>Guardar</button>
                                                <form method="post" onsubmit="return confirm('Se eliminara este programa. Continuar?');">
                                                    <input type="hidden" name="action" value="eliminar_programa">
                                                    <input type="hidden" name="programa_id" value="<?= (int) $program['id'] ?>">
                                                    <button class="btn-outline btn-sm" type="submit">Eliminar</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            Solo lectura
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'nueva'): ?>
            <?php if (!can_create_requests($user)): ?>
                <div class="alert error">Tu usuario necesita un programa asignado para crear solicitudes.</div>
            <?php else: ?>
                <div class="section-header">
                    <div>
                        <div class="section-title">Nueva solicitud</div>
                        <div class="section-sub">Registra compras, soporte TI o apoyo entre programas.</div>
                    </div>
                </div>
                <form class="form-card" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="crear_solicitud">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="tipo">Tipo de solicitud <span class="req">*</span></label>
                            <select class="form-control" id="tipo" name="tipo" required>
                                <option value="">Seleccionar...</option>
                                <option value="compra">Requerimiento de compra</option>
                                <option value="ti">Soporte TI</option>
                                <option value="apoyo">Apoyo entre areas</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="prioridad">Prioridad <span class="req">*</span></label>
                            <select class="form-control" id="prioridad" name="prioridad" required>
                                <option value="media">Media</option>
                                <option value="alta">Alta</option>
                                <option value="baja">Baja</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" id="programa_origen_label" for="programa_origen_id">Programa origen <span class="req">*</span></label>
                            <select class="form-control" id="programa_origen_id" name="programa_origen_id" data-user-program-id="<?= (int) ($user['programa_id'] ?? 0) ?>" <?= is_superadmin($user) ? '' : 'disabled' ?> required>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>" data-program-name="<?= e(strtolower($program['nombre'])) ?>" <?= (int) $program['id'] === (int) ($user['programa_id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= e($program['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!is_superadmin($user)): ?>
                                <input type="hidden" name="programa_origen_id" value="<?= (int) ($user['programa_id'] ?? 0) ?>">
                            <?php endif; ?>
                            <div class="form-hint" id="programa_origen_hint">
                                <?= is_superadmin($user) ? 'El superadmin puede elegir el programa origen.' : 'Tu solicitud saldra desde el programa asignado a tu usuario.' ?>
                            </div>
                        </div>
                        <div class="form-group hidden" id="campo-programa-destino">
                            <label class="form-label" for="programa_destino_id">Programa destino <span class="req">*</span></label>
                            <select class="form-control" id="programa_destino_id" name="programa_destino_id">
                                <option value="">Seleccionar...</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>"><?= e($program['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="fecha_requerida">Fecha requerida</label>
                            <input class="form-control" type="date" id="fecha_requerida" name="fecha_requerida">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="titulo">Titulo <span class="req">*</span></label>
                            <input class="form-control" type="text" id="titulo" name="titulo" maxlength="180" required>
                        </div>
                        <div class="form-full hidden" id="campos-compra">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="proveedor_sugerido">Proveedor sugerido</label>
                                    <input class="form-control" type="text" id="proveedor_sugerido" name="proveedor_sugerido">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="monto_estimado">Monto estimado (Q)</label>
                                    <input class="form-control" type="number" step="0.01" min="0" id="monto_estimado" name="monto_estimado">
                                </div>
                            </div>
                        </div>
                        <div class="form-group form-full">
                            <label class="form-label" for="descripcion">Descripcion detallada <span class="req">*</span></label>
                            <textarea class="form-control" id="descripcion" name="descripcion" required placeholder="Describe el requerimiento, contexto y justificacion."></textarea>
                        </div>
                        <div class="form-group form-full">
                            <label class="form-label" for="adjuntos">Adjuntos</label>
                            <input class="form-control" type="file" id="adjuntos" name="adjuntos[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                        </div>
                    </div>
                    <div class="actions">
                        <a class="btn-outline" href="index.php?view=mis">Cancelar</a>
                        <button class="btn-primary" type="submit"><i class="ti ti-send"></i>Enviar solicitud</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'detalle'): ?>
            <?php if (!$detail): ?>
                <div class="alert error">No se encontro la solicitud.</div>
            <?php else: ?>
                <div class="section-header">
                    <div>
                        <div class="section-title"><?= e($detail['codigo']) ?> · <?= e($detail['titulo']) ?></div>
                        <div class="section-sub"><?= badge_tipo($detail['tipo']) ?> <?= badge_estado($detail['estado']) ?> <?= badge_prioridad($detail['prioridad']) ?></div>
                    </div>
                    <a class="btn-outline" href="index.php?view=<?= can_manage_request($user, $detail) ? 'gestion' : 'mis' ?>">Volver</a>
                </div>
                <section class="form-card">
                    <div class="detail-grid">
                        <div><div class="detail-label">Solicitante</div><div class="detail-value"><?= e($detail['solicitante']) ?></div></div>
                        <div><div class="detail-label">Programa origen</div><div class="detail-value"><?= e($detail['programa_origen']) ?></div></div>
                        <div><div class="detail-label">Programa destino</div><div class="detail-value"><?= e($detail['programa_destino'] ?: 'No aplica') ?></div></div>
                        <div><div class="detail-label">Fecha requerida</div><div class="detail-value"><?= e($detail['fecha_requerida'] ?: 'Sin fecha') ?></div></div>
                        <div class="form-full"><div class="detail-label">Descripcion</div><div class="detail-value"><?= nl2br(e($detail['descripcion'])) ?></div></div>
                    </div>

                    <?php if ($attachments): ?>
                        <div class="section-sub">Adjuntos</div>
                        <div class="filters">
                            <?php foreach ($attachments as $attachment): ?>
                                <a class="btn-outline btn-sm" href="<?= e($attachment['ruta']) ?>" target="_blank"><i class="ti ti-paperclip"></i><?= e($attachment['nombre_original']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="section-sub">Historial de seguimiento</div>
                    <div class="timeline">
                        <?php foreach ($followUps as $followUp): ?>
                            <div class="timeline-item">
                                <div class="timeline-title"><?= e(estado_label($followUp['estado'])) ?> · <?= e($followUp['nombre']) ?></div>
                                <div class="timeline-meta"><?= e(date('d/m/Y H:i', strtotime($followUp['creado_en']))) ?></div>
                                <?php if ($followUp['observacion']): ?><div class="timeline-obs"><?= nl2br(e($followUp['observacion'])) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (can_manage_request($user, $detail) && $detail['estado'] !== 'completado'): ?>
                        <form method="post" enctype="multipart/form-data" class="form-card" style="margin-top:18px">
                            <input type="hidden" name="action" value="actualizar_estado">
                            <input type="hidden" name="solicitud_id" value="<?= (int) $detail['id'] ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="estado">Cambiar estado</label>
                                    <select class="form-control" id="estado" name="estado">
                                        <option value="recibido" <?= $detail['estado'] === 'recibido' ? 'selected' : '' ?>>Recibido</option>
                                        <option value="proceso" <?= $detail['estado'] === 'proceso' ? 'selected' : '' ?>>En proceso</option>
                                        <option value="completado" <?= $detail['estado'] === 'completado' ? 'selected' : '' ?>>Completado</option>
                                        <option value="rechazado" <?= $detail['estado'] === 'rechazado' ? 'selected' : '' ?>>Rechazado</option>
                                    </select>
                                </div>
                                <div class="form-group form-full">
                                    <label class="form-label" for="observacion">Observacion</label>
                                    <textarea class="form-control" id="observacion" name="observacion" placeholder="Comentario visible para el solicitante."></textarea>
                                </div>
                                <div class="form-group form-full">
                                    <label class="form-label" for="seguimiento_adjuntos">Documentos de respaldo</label>
                                    <input class="form-control" type="file" id="seguimiento_adjuntos" name="seguimiento_adjuntos[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                                </div>
                            </div>
                            <div class="actions">
                                <button class="btn-primary" type="submit"><i class="ti ti-device-floppy"></i>Guardar seguimiento</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
