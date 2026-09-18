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

$programs = $pdo->query('SELECT * FROM programas WHERE activo = 1 ORDER BY nombre')->fetchAll();

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

        if ($action === 'actualizar_programa_usuario') {
            if (!can_manage_users($user)) {
                throw new RuntimeException('No tienes permiso para modificar el programa de un usuario.');
            }

            $userId = (int) ($_POST['usuario_id'] ?? 0);
            $programId = (int) ($_POST['programa_id'] ?? 0);

            if ($userId <= 0) {
                throw new RuntimeException('Usuario no valido.');
            }

            if ($moduleIds) {
                $moduleCheckStmt = $menuPdo->prepare(
                    'SELECT COUNT(*) FROM usuario_modulo WHERE usuario_id = ? AND modulo_id IN (' . implode(',', array_fill(0, count($moduleIds), '?')) . ')'
                );
                $moduleCheckStmt->execute(array_merge([$userId], $moduleIds));
                if ((int) $moduleCheckStmt->fetchColumn() === 0) {
                    throw new RuntimeException('El usuario no pertenece a este modulo.');
                }
            }

            if ($programId > 0) {
                $pdo->prepare(
                    'INSERT INTO usuario_programa (usuario_id, programa_id)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE programa_id = VALUES(programa_id)'
                )->execute([$userId, $programId]);
            } else {
                $pdo->prepare('DELETE FROM usuario_programa WHERE usuario_id = ?')->execute([$userId]);
            }

            header('Location: index.php?view=usuarios&message=' . urlencode('Programa actualizado correctamente.'));
            exit;
        }

        if ($action === 'agregar_usuario_modulo') {
            if (!can_manage_users($user)) {
                throw new RuntimeException('No tienes permiso para agregar usuarios a este modulo.');
            }

            if (!$moduleIds) {
                throw new RuntimeException('El modulo Solicitudes Internas no esta registrado en la base principal.');
            }

            $userId = (int) ($_POST['usuario_id'] ?? 0);
            $programId = (int) ($_POST['programa_id'] ?? 0);

            if ($userId <= 0) {
                throw new RuntimeException('Selecciona un usuario existente.');
            }

            $existsStmt = $menuPdo->prepare('SELECT id FROM usuarios WHERE id = ?');
            $existsStmt->execute([$userId]);
            if (!$existsStmt->fetchColumn()) {
                throw new RuntimeException('El usuario seleccionado no existe.');
            }

            $insertModule = $menuPdo->prepare('INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id) VALUES (?, ?)');
            foreach ($moduleIds as $moduleIdToAssign) {
                $insertModule->execute([$userId, $moduleIdToAssign]);
            }

            if ($programId > 0) {
                $pdo->prepare(
                    'INSERT INTO usuario_programa (usuario_id, programa_id)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE programa_id = VALUES(programa_id)'
                )->execute([$userId, $programId]);
            }

            header('Location: index.php?view=usuarios&message=' . urlencode('Usuario agregado al modulo correctamente.'));
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

// La tabla usuario_programa vive en la base local de este modulo y no es
// visible para login/usuarios/eliminar_usuario.php, por lo que puede quedar
// con registros huerfanos cuando un usuario se elimina o pierde el modulo
// desde el modulo central. Se limpia antes de listar usuarios.
if ($moduleIds) {
    $moduleOrphanPlaceholders = implode(',', array_fill(0, count($moduleIds), '?'));
    $pdo->prepare(
        "DELETE up FROM usuario_programa up
         WHERE NOT EXISTS (
             SELECT 1 FROM {$menuDbName}.usuario_modulo um
             WHERE um.usuario_id = up.usuario_id
               AND um.modulo_id IN ({$moduleOrphanPlaceholders})
         )"
    )->execute($moduleIds);
}

$users = can_view_users($user) ? fetch_module_users($menuPdo, $pdo, $user) : [];

$availableUsers = [];
if (can_manage_users($user) && $moduleIds) {
    $availablePlaceholders = implode(',', array_fill(0, count($moduleIds), '?'));
    $availableStmt = $menuPdo->prepare(
        "SELECT id, nombre, correo
         FROM usuarios
         WHERE id NOT IN (
             SELECT usuario_id FROM usuario_modulo WHERE modulo_id IN ({$availablePlaceholders})
         )
         ORDER BY nombre"
    );
    $availableStmt->execute($moduleIds);
    $availableUsers = $availableStmt->fetchAll();
}

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

$active = static fn(string $name): string => $view === $name ? 'is-active' : '';

$sidebarTitles = [
    'panel' => ['Panel', 'Resumen de solicitudes visibles para tu usuario'],
    'mis' => ['Enviadas', 'Solicitudes que has enviado'],
    'recibidas' => ['Recibidas', 'Solicitudes recibidas por tu programa'],
    'usuarios' => ['Usuarios', 'Usuarios del modulo'],
    'programas' => ['Programas', 'Programas y direcciones'],
    'nueva' => ['Nueva solicitud', 'Registra una nueva solicitud'],
    'detalle' => ['Detalle de solicitud', 'Seguimiento de la solicitud'],
];
[$topbarTitle, $topbarSub] = $sidebarTitles[$view] ?? ['Sistema de solicitudes', 'CENGICANA'];

$userInitials = '';
foreach (preg_split('/\s+/', trim((string) $user['nombre']), -1, PREG_SPLIT_NO_EMPTY) as $namePart) {
    $userInitials .= mb_strtoupper(mb_substr($namePart, 0, 1, 'UTF-8'), 'UTF-8');
    if (mb_strlen($userInitials, 'UTF-8') >= 2) {
        break;
    }
}
$userInitials = $userInitials !== '' ? $userInitials : 'U';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistema de solicitudes CENGICANA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div class="app" data-manual-permission-roles="[]">
    <aside class="sidebar" id="appSidebar">
        <a class="sidebar-brand" href="index.php?view=panel">
            <span class="sidebar-brand-mark"><i class="ti ti-hexagon-letter-c"></i></span>
            <span class="sidebar-brand-copy">
                <strong>CENGICANA</strong>
                <small>Solicitudes</small>
            </span>
        </a>

        <nav class="sidebar-nav" id="sidebarNav">
            <div class="sidebar-nav-group">
                <div class="sidebar-nav-label">Solicitudes</div>
                <a href="index.php?view=panel" class="sidebar-nav-item <?= e($active('panel')) ?>">
                    <i class="ti ti-layout-dashboard"></i><span>Panel</span>
                </a>
                <a href="index.php?view=mis" class="sidebar-nav-item <?= e($active('mis')) ?>">
                    <i class="ti ti-send"></i><span>Enviadas</span>
                </a>
                <?php if (can_view_received_requests($user)): ?>
                    <a href="index.php?view=recibidas" class="sidebar-nav-item <?= e($active('recibidas')) ?>">
                        <i class="ti ti-inbox"></i><span>Recibidas</span>
                    </a>
                <?php endif; ?>
                <?php if (can_create_requests($user)): ?>
                    <a href="index.php?view=nueva" class="sidebar-nav-item <?= e($active('nueva')) ?>">
                        <i class="ti ti-plus"></i><span>Nueva solicitud</span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (can_view_users($user) || can_view_programs($user)): ?>
                <div class="sidebar-nav-group">
                    <div class="sidebar-nav-label">Administracion</div>
                    <?php if (can_view_users($user)): ?>
                        <a href="index.php?view=usuarios" class="sidebar-nav-item <?= e($active('usuarios')) ?>">
                            <i class="ti ti-users"></i><span>Usuarios</span>
                        </a>
                    <?php endif; ?>
                    <?php if (can_view_programs($user)): ?>
                        <a href="index.php?view=programas" class="sidebar-nav-item <?= e($active('programas')) ?>">
                            <i class="ti ti-building"></i><span>Programas</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-foot">
            <a href="../login/Menu.php" class="sidebar-nav-item sidebar-menu-link">
                <i class="ti ti-home"></i><span>Menu principal</span>
            </a>
            <a href="index.php?logout=1" class="sidebar-nav-item sidebar-logout-link">
                <i class="ti ti-logout-2"></i><span>Cerrar sesion</span>
            </a>
        </div>
    </aside>

    <header class="topbar">
        <button type="button" class="menu-toggle" id="sidebarToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="appSidebar">
            <span></span><span></span><span></span>
        </button>
        <div class="topbar-titles">
            <div class="topbar-title"><?= e($topbarTitle) ?></div>
            <div class="topbar-sub"><?= e($topbarSub) ?></div>
        </div>
        <div class="topbar-right">
            <span class="topbar-pill"><?= e($user['nombre_rol']) ?></span>
            <div class="topbar-userbox">
                <div class="topbar-avatar"><?= e($userInitials) ?></div>
                <div class="topbar-userbox-copy">
                    <div class="topbar-userbox-name"><?= e($user['nombre']) ?></div>
                    <div class="topbar-userbox-role"><?= e($user['nombre_rol']) ?></div>
                </div>
            </div>
        </div>
    </header>

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
                    <a class="program-card card" href="index.php?view=recibidas&programa_destino=<?= (int) $program['id'] ?>">
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
                    <div class="section-title">Solicitudes recibidas<?= $selectedDestination ? ' · ' . e($selectedDestination['nombre']) : '' ?></div>
                    <div class="section-sub">
                        <?php if ($selectedDestination): ?>
                            Mostrando solicitudes recibidas por este programa.
                        <?php elseif (is_superadmin($user)): ?>
                            Vista general de solicitudes recibidas por los programas.
                        <?php else: ?>
                            Aqui aparecen las solicitudes que llegaron a tu programa asignado.
                        <?php endif; ?>
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
                        <input type="hidden" name="action" value="agregar_usuario_modulo">
                        <div class="form-grid">
                            <div class="form-group user-search">
                                <label class="form-label" for="agregar_usuario_buscar">Usuario existente en la plataforma <span class="req">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="agregar_usuario_buscar"
                                    placeholder="Buscar por nombre o correo..."
                                    autocomplete="off"
                                    data-users="<?= e(json_encode(array_map(
                                        static fn(array $u): array => ['id' => (int) $u['id'], 'label' => $u['nombre'] . ' — ' . $u['correo']],
                                        $availableUsers
                                    ), JSON_UNESCAPED_UNICODE)) ?>"
                                    <?= !$availableUsers ? 'disabled' : '' ?>
                                >
                                <input type="hidden" id="agregar_usuario_id" name="usuario_id" required>
                                <div class="user-search-results" id="agregar_usuario_resultados" hidden></div>
                                <?php if ($availableUsers): ?>
                                    <div class="form-hint">Escribe para ver las 5 mejores coincidencias.</div>
                                <?php else: ?>
                                    <div class="form-hint">No hay usuarios de la plataforma disponibles para agregar (ya estan todos en este modulo).</div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="agregar_usuario_programa">Programa asignado</label>
                                <select class="form-control" id="agregar_usuario_programa" name="programa_id">
                                    <option value="">Sin programa</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= (int) $program['id'] ?>"><?= e($program['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">El programa se usa para decidir que solicitudes puede ver y gestionar el usuario.</div>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-primary" type="submit"><i class="ti ti-user-plus"></i>Agregar al modulo</button>
                        </div>
                        <div class="form-hint" style="margin-top:10px;">
                            ¿La persona todavia no existe en la plataforma?
                            <a href="../login/usuarios/crear_usuario.php?scope=solicitudes">Crear usuario nuevo</a>.
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
                                <?php if (can_manage_users($user)): ?><th>Acciones</th><?php endif; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$users): ?>
                                <tr><td colspan="<?= can_manage_users($user) ? 7 : 6 ?>">No hay usuarios para mostrar.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($users as $userItem): ?>
                                <?php $formId = 'usuario-programa-form-' . (int) $userItem['id']; ?>
                                <tr>
                                    <td>
                                        <?php if (can_manage_users($user)): ?><form id="<?= e($formId) ?>" method="post"></form><?php endif; ?>
                                        <?= e($userItem['nombre']) ?>
                                    </td>
                                    <td><?= e($userItem['correo']) ?></td>
                                    <td><?= e(role_label((string) $userItem['nombre_rol'])) ?></td>
                                    <td><?= e($userItem['ingenio'] ?: 'Sin ingenio') ?></td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <input form="<?= e($formId) ?>" type="hidden" name="action" value="actualizar_programa_usuario">
                                            <input form="<?= e($formId) ?>" type="hidden" name="usuario_id" value="<?= (int) $userItem['id'] ?>">
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
                                    <td><?= (int) $userItem['tiene_modulo'] === 1 ? 'Asignado' : 'No asignado' ?></td>
                                    <?php if (can_manage_users($user)): ?>
                                        <td>
                                            <div class="actions" style="justify-content:flex-start">
                                                <button form="<?= e($formId) ?>" class="btn-primary btn-sm" type="submit"><i class="ti ti-device-floppy"></i>Guardar programa</button>
                                                <a class="btn-outline btn-sm" href="../login/usuarios/editar_usuario.php?id=<?= (int) $userItem['id'] ?>&scope=solicitudes">Editar</a>
                                                <?php if (strtolower((string) $userItem['nombre_rol']) !== 'superadmin' && (int) $userItem['id'] !== (int) $user['id']): ?>
                                                    <a class="btn-outline btn-sm" href="../login/usuarios/eliminar_usuario.php?id=<?= (int) $userItem['id'] ?>&scope=solicitudes" onclick="return confirm('Se eliminara este usuario. Continuar?');">Eliminar</a>
                                                <?php endif; ?>
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
                    <a class="btn-outline" href="index.php?view=<?= can_manage_request($user, $detail) ? 'recibidas' : 'mis' ?>">Volver</a>
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
