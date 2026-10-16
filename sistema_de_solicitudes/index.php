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

        if ($action === 'marcar_notificaciones') {
            mark_notifications_read($pdo, $user);

            // Volver a la misma vista; solo se aceptan rutas internas de este modulo.
            $return = (string) ($_POST['return'] ?? '');
            if (!preg_match('/^index\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $return)) {
                $return = 'index.php';
            }
            header('Location: ' . $return);
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

            apply_posted_platform_role($menuPdo, $userId, $programId, find_program_id_by_name($programs, DIRECTOR_PROGRAM_NAME));
            save_user_program($pdo, $userId, $programId);

            header('Location: index.php?view=usuarios&message=' . urlencode('Programa y rol actualizados correctamente.'));
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
                throw new RuntimeException('Selecciona un usuario de la lista de coincidencias antes de agregarlo.');
            }

            $existsStmt = $menuPdo->prepare('SELECT id FROM usuarios WHERE id = ?');
            $existsStmt->execute([$userId]);
            if (!$existsStmt->fetchColumn()) {
                throw new RuntimeException('El usuario seleccionado no existe.');
            }

            apply_posted_platform_role($menuPdo, $userId, $programId, find_program_id_by_name($programs, DIRECTOR_PROGRAM_NAME));

            $insertModule = $menuPdo->prepare('INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id) VALUES (?, ?)');
            foreach ($moduleIds as $moduleIdToAssign) {
                $insertModule->execute([$userId, $moduleIdToAssign]);
            }

            if ($programId > 0) {
                save_user_program($pdo, $userId, $programId);
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

            // Se validan los adjuntos antes de registrar la solicitud.
            $adjuntosValidos = validar_adjuntos($_FILES['adjuntos'] ?? null);

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

            guardar_adjuntos($pdo, $requestId, $code, $adjuntosValidos);

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

            $adjuntosValidos = validar_adjuntos($_FILES['seguimiento_adjuntos'] ?? null);

            $pdo->prepare('UPDATE solicitudes SET estado = ? WHERE id = ?')->execute([$status, $requestId]);
            $pdo->prepare(
                'INSERT INTO seguimientos (solicitud_id, usuario_id, estado, observacion) VALUES (?, ?, ?, ?)'
            )->execute([$requestId, (int) $user['id'], $status, trim($_POST['observacion'] ?? '') ?: null]);

            guardar_adjuntos($pdo, $requestId, $request['codigo'] . '_seguimiento', $adjuntosValidos);

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
        SUM(estado = 'completado') AS completadas,
        SUM(estado = 'rechazado') AS rechazadas
     FROM solicitudes s
     WHERE {$visibleWhere}"
);
$statsStmt->execute($visibleParams);
$stats = $statsStmt->fetch() ?: ['total' => 0, 'recibidas' => 0, 'proceso' => 0, 'completadas' => 0, 'rechazadas' => 0];

$recentRequests = [];
$urgentRequests = [];
if ($view === 'panel') {
    $recentStmt = $pdo->prepare(
        "SELECT s.id, s.codigo, s.titulo, s.tipo, s.estado, s.creado_en,
                po.nombre AS programa_origen, pd.nombre AS programa_destino
         FROM solicitudes s
         INNER JOIN programas po ON po.id = s.programa_origen_id
         LEFT JOIN programas pd ON pd.id = s.programa_destino_id
         WHERE {$visibleWhere}
         ORDER BY s.creado_en DESC
         LIMIT 6"
    );
    $recentStmt->execute($visibleParams);
    $recentRequests = $recentStmt->fetchAll();

    $urgentStmt = $pdo->prepare(
        "SELECT s.id, s.codigo, s.titulo, s.estado, s.fecha_requerida, pd.nombre AS programa_destino
         FROM solicitudes s
         LEFT JOIN programas pd ON pd.id = s.programa_destino_id
         WHERE {$visibleWhere}
           AND s.prioridad = 'alta'
           AND s.estado IN ('recibido', 'proceso')
         ORDER BY s.fecha_requerida IS NULL, s.fecha_requerida, s.creado_en
         LIMIT 5"
    );
    $urgentStmt->execute($visibleParams);
    $urgentRequests = $urgentStmt->fetchAll();
}

$programUsage = [];
if ($view === 'programas' && can_view_programs($user)) {
    $programUsageRows = $pdo->query(
        "SELECT p.id,
                (SELECT COUNT(*) FROM usuario_programa up WHERE up.programa_id = p.id) AS usuarios,
                (SELECT COUNT(*) FROM solicitudes s WHERE s.programa_origen_id = p.id) AS enviadas,
                (SELECT COUNT(*) FROM solicitudes s WHERE s.programa_destino_id = p.id) AS recibidas
         FROM programas p
         WHERE p.activo = 1"
    )->fetchAll();
    foreach ($programUsageRows as $row) {
        $programUsage[(int) $row['id']] = $row;
    }
}

$programCardsSql = "
    SELECT p.id, p.nombre, COUNT(s.id) AS total
    FROM programas p
    LEFT JOIN solicitudes s ON s.programa_destino_id = p.id
";
$programCardsWhere = [];
$programCardsParams = [];

if (!can_view_all_requests($user) && !empty($user['programa_id'])) {
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
$assignableRoles = $view === 'usuarios' && can_manage_users($user) ? assignable_module_roles($menuPdo) : [];

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
        // Abrir la solicitud marca como leidas sus notificaciones.
        mark_notifications_read($pdo, $user, (int) $detail['id']);

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

$notifications = fetch_notifications($pdo, $menuDbName, $user);
$notificationsReturn = 'index.php' . ($_GET ? '?' . http_build_query(array_diff_key($_GET, ['message' => 1])) : '');

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
    <link rel="stylesheet" href="assets/css/styles.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/styles.css') ?>">
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
            <div class="notif" id="notif">
                <button type="button" class="notif-toggle" id="notifToggle" aria-haspopup="true" aria-expanded="false" aria-controls="notifPanel"
                        aria-label="Notificaciones<?= $notifications['unread'] ? ': ' . (int) $notifications['unread'] . ' sin leer' : '' ?>">
                    <i class="ti ti-bell"></i>
                    <?php if ($notifications['unread'] > 0): ?>
                        <span class="notif-badge"><?= $notifications['unread'] > 9 ? '9+' : (int) $notifications['unread'] ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-panel" id="notifPanel" hidden>
                    <div class="notif-head">
                        <div>
                            <strong>Notificaciones</strong>
                            <span><?= $notifications['unread'] ? (int) $notifications['unread'] . ' sin leer' : 'Estas al dia' ?></span>
                        </div>
                        <?php if ($notifications['unread'] > 0): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="marcar_notificaciones">
                                <input type="hidden" name="return" value="<?= e($notificationsReturn) ?>">
                                <button type="submit" class="notif-mark"><i class="ti ti-checks"></i>Marcar todas como leidas</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!$notifications['items']): ?>
                        <div class="notif-empty">
                            <i class="ti ti-bell-off"></i>
                            <span>No tienes notificaciones de los ultimos <?= NOTIFICATION_DAYS ?> dias.</span>
                        </div>
                    <?php else: ?>
                        <ul class="notif-list">
                            <?php foreach ($notifications['items'] as $notification): ?>
                                <?php $notificationView = notification_view($notification, $user); ?>
                                <li>
                                    <a class="notif-item<?= (int) $notification['leida'] === 0 ? ' is-unread' : '' ?>" href="index.php?view=detalle&id=<?= (int) $notification['solicitud_id'] ?>">
                                        <span class="notif-icon tone-<?= e($notificationView['tone']) ?>"><i class="ti <?= e($notificationView['icon']) ?>"></i></span>
                                        <span class="notif-body">
                                            <span class="notif-text"><?= e($notificationView['text']) ?></span>
                                            <?php if (!empty($notification['observacion']) && (int) $notification['es_nueva'] === 0): ?>
                                                <span class="notif-obs">"<?= e(mb_strimwidth((string) $notification['observacion'], 0, 90, '…', 'UTF-8')) ?>"</span>
                                            <?php endif; ?>
                                            <span class="notif-time"><?= e(time_ago((string) $notification['creado_en'])) ?></span>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <span class="topbar-pill"><?= e(module_role_label(module_role($user))) ?><?= !empty($user['programa']) ? ' · ' . e($user['programa']) : '' ?></span>
            <div class="topbar-userbox">
                <div class="topbar-avatar"><?= e($userInitials) ?></div>
                <div class="topbar-userbox-copy">
                    <div class="topbar-userbox-name"><?= e($user['nombre']) ?></div>
                    <div class="topbar-userbox-role"><?= e(module_role_label(module_role($user))) ?></div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        <?php if ($message): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

        <?php if ($view === 'panel'): ?>
            <?php
            $statTotal = (int) $stats['total'];
            $statSegments = [
                ['key' => 'recibido', 'label' => 'Recibidas', 'icon' => 'ti-mail-opened', 'value' => (int) $stats['recibidas']],
                ['key' => 'proceso', 'label' => 'En proceso', 'icon' => 'ti-loader-2', 'value' => (int) $stats['proceso']],
                ['key' => 'completado', 'label' => 'Completadas', 'icon' => 'ti-circle-check', 'value' => (int) $stats['completadas']],
                ['key' => 'rechazado', 'label' => 'Rechazadas', 'icon' => 'ti-circle-x', 'value' => (int) $stats['rechazadas']],
            ];
            $pct = static fn(int $value): float => $statTotal > 0 ? round($value * 100 / $statTotal, 1) : 0.0;
            $firstName = preg_split('/\s+/', trim((string) $user['nombre']))[0] ?? '';
            $maxProgramTotal = max(1, ...array_map(static fn($p) => (int) $p['total'], $programCounts ?: [['total' => 0]]));
            $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            $todayLabel = date('j') . ' de ' . $months[(int) date('n') - 1] . ' de ' . date('Y');
            $today = date('Y-m-d');
            ?>
            <section class="panel-hero">
                <div>
                    <div class="panel-hero-date"><i class="ti ti-calendar"></i><?= e($todayLabel) ?></div>
                    <h1 class="panel-hero-title">Hola, <?= e($firstName) ?></h1>
                    <p class="panel-hero-sub">
                        <?php if ($statTotal === 0): ?>
                            Aun no hay solicitudes visibles para tu usuario.
                        <?php else: ?>
                            Tienes <strong><?= (int) $stats['recibidas'] + (int) $stats['proceso'] ?></strong> solicitudes abiertas
                            y <strong><?= count($urgentRequests) ?></strong> de prioridad alta pendientes.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="panel-hero-actions">
                    <?php if (can_view_received_requests($user)): ?>
                        <a class="btn-outline" href="index.php?view=recibidas"><i class="ti ti-inbox"></i>Ver recibidas</a>
                    <?php endif; ?>
                    <?php if (can_create_requests($user)): ?>
                        <a class="btn-primary" href="index.php?view=nueva"><i class="ti ti-plus"></i>Nueva solicitud</a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="kpi-row">
                <div class="kpi-card kpi-total">
                    <div class="kpi-icon"><i class="ti ti-files"></i></div>
                    <div class="kpi-num"><?= $statTotal ?></div>
                    <div class="kpi-label">Total solicitudes</div>
                </div>
                <?php foreach ($statSegments as $segment): ?>
                    <div class="kpi-card kpi-<?= e($segment['key']) ?>">
                        <div class="kpi-icon"><i class="ti <?= e($segment['icon']) ?>"></i></div>
                        <div class="kpi-num"><?= $segment['value'] ?></div>
                        <div class="kpi-label"><?= e($segment['label']) ?> <span class="kpi-pct"><?= $pct($segment['value']) ?>%</span></div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="panel-card">
                <div class="panel-card-head">
                    <div>
                        <div class="panel-card-title">Distribucion por estado</div>
                        <div class="panel-card-sub"><?= $pct((int) $stats['completadas']) ?>% de las solicitudes ya fueron completadas</div>
                    </div>
                </div>
                <div class="status-bar" role="img" aria-label="Distribucion de solicitudes por estado">
                    <?php if ($statTotal === 0): ?>
                        <span class="status-bar-empty"></span>
                    <?php endif; ?>
                    <?php foreach ($statSegments as $segment): ?>
                        <?php if ($segment['value'] > 0): ?>
                            <span class="status-bar-seg seg-<?= e($segment['key']) ?>" style="width: <?= $pct($segment['value']) ?>%" title="<?= e($segment['label']) ?>: <?= $segment['value'] ?>"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="status-legend">
                    <?php foreach ($statSegments as $segment): ?>
                        <span><i class="legend-dot seg-<?= e($segment['key']) ?>"></i><?= e($segment['label']) ?> <strong><?= $segment['value'] ?></strong></span>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="panel-columns">
                <section class="panel-card">
                    <div class="panel-card-head">
                        <div>
                            <div class="panel-card-title">Actividad reciente</div>
                            <div class="panel-card-sub">Ultimas solicitudes visibles para tu usuario</div>
                        </div>
                        <a class="panel-card-link" href="index.php?view=mis">Ver enviadas <i class="ti ti-arrow-right"></i></a>
                    </div>
                    <?php if (!$recentRequests): ?>
                        <div class="panel-empty"><i class="ti ti-inbox-off"></i>No hay solicitudes registradas.</div>
                    <?php else: ?>
                        <ul class="recent-list">
                            <?php foreach ($recentRequests as $request): ?>
                                <li>
                                    <a class="recent-item" href="index.php?view=detalle&id=<?= (int) $request['id'] ?>">
                                        <div class="recent-main">
                                            <div class="recent-title"><span class="recent-code"><?= e($request['codigo']) ?></span><?= e($request['titulo']) ?></div>
                                            <div class="recent-meta">
                                                <?= e($request['programa_origen']) ?>
                                                <i class="ti ti-arrow-narrow-right"></i>
                                                <?= e($request['programa_destino'] ?: 'No aplica') ?>
                                                · <?= e(date('d/m/Y', strtotime($request['creado_en']))) ?>
                                            </div>
                                        </div>
                                        <?= badge_estado($request['estado']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="panel-card panel-card-urgent">
                    <div class="panel-card-head">
                        <div>
                            <div class="panel-card-title"><i class="ti ti-alert-triangle"></i>Prioridad alta</div>
                            <div class="panel-card-sub">Pendientes o en proceso</div>
                        </div>
                    </div>
                    <?php if (!$urgentRequests): ?>
                        <div class="panel-empty"><i class="ti ti-mood-smile"></i>No hay solicitudes urgentes pendientes.</div>
                    <?php else: ?>
                        <ul class="urgent-list">
                            <?php foreach ($urgentRequests as $request): ?>
                                <?php $overdue = !empty($request['fecha_requerida']) && $request['fecha_requerida'] < $today; ?>
                                <li>
                                    <a class="urgent-item" href="index.php?view=detalle&id=<?= (int) $request['id'] ?>">
                                        <div class="recent-title"><span class="recent-code"><?= e($request['codigo']) ?></span><?= e($request['titulo']) ?></div>
                                        <div class="recent-meta">
                                            <?= e($request['programa_destino'] ?: 'No aplica') ?> · <?= e(estado_label($request['estado'])) ?>
                                        </div>
                                        <?php if (!empty($request['fecha_requerida'])): ?>
                                            <div class="urgent-date<?= $overdue ? ' is-overdue' : '' ?>">
                                                <i class="ti ti-clock"></i>
                                                <?= $overdue ? 'Vencida el ' : 'Requerida para el ' ?><?= e(date('d/m/Y', strtotime($request['fecha_requerida']))) ?>
                                            </div>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>

            <?php if (can_view_received_requests($user)): ?>
            <div class="section-header panel-section-header">
                <div>
                    <div class="section-title">Solicitudes por programa destino</div>
                    <div class="section-sub">El jefe de programa ve las solicitudes que llegan a su programa. El superadmin ve todas.</div>
                </div>
            </div>
            <section class="programs-grid">
                <?php foreach ($programCounts as $program): ?>
                    <?php $programTotal = (int) $program['total']; ?>
                    <a class="program-card card<?= $programTotal === 0 ? ' is-empty' : '' ?>" href="index.php?view=recibidas&programa_destino=<?= (int) $program['id'] ?>">
                        <div class="program-card-top">
                            <div class="program-card-icon"><i class="ti ti-building"></i></div>
                            <div class="program-card-num"><?= $programTotal ?></div>
                        </div>
                        <div class="program-card-name"><?= e($program['nombre']) ?></div>
                        <div class="program-card-bar"><span style="width: <?= round($programTotal * 100 / $maxProgramTotal) ?>%"></span></div>
                        <div class="program-card-count"><?= $programTotal === 1 ? '1 solicitud' : $programTotal . ' solicitudes' ?></div>
                    </a>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
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

        <?php if ($view === 'recibidas' && !can_view_received_requests($user)): ?>
            <div class="alert error">Solo el jefe de programa puede ver las solicitudes recibidas por su programa.</div>
        <?php elseif ($view === 'recibidas'): ?>
            <div class="section-header">
                <div>
                    <div class="section-title">Solicitudes recibidas<?= $selectedDestination ? ' · ' . e($selectedDestination['nombre']) : '' ?></div>
                    <div class="section-sub">
                        <?php if ($selectedDestination): ?>
                            Mostrando solicitudes recibidas por este programa.
                        <?php elseif (can_view_all_requests($user)): ?>
                            <?= module_role($user) === 'director' ? 'Como director ves todas las solicitudes entre programas en solo lectura; solo gestionas las dirigidas a ' . e(DIRECTOR_PROGRAM_NAME) . '.' : 'Vista general de solicitudes recibidas por los programas.' ?>
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
                        <div class="section-sub">Asigna a cada usuario su programa y su rol para Solicitudes internas.</div>
                    </div>
                </div>

                <section class="roles-legend" aria-label="Roles del modulo">
                    <div class="role-card role-card-superadmin">
                        <span class="role-badge role-superadmin"><i class="ti ti-shield-star"></i>Superadmin</span>
                        <p>Ve y gestiona todas las solicitudes, usuarios y programas.</p>
                    </div>
                    <div class="role-card role-card-jefe">
                        <span class="role-badge role-jefe"><i class="ti ti-user-star"></i>Jefe de programa</span>
                        <p>Gestiona las solicitudes que otros programas envian a <strong>su programa</strong> y ve las que el mismo envia. No ve nada mas.</p>
                    </div>
                    <div class="role-card role-card-director">
                        <span class="role-badge role-director"><i class="ti ti-building-community"></i>Director</span>
                        <p>Ve todas las solicitudes entre programas en <strong>solo lectura</strong>; solo cambia el estado de las dirigidas a Direccion.</p>
                    </div>
                    <div class="role-card role-card-colaborador">
                        <span class="role-badge role-colaborador"><i class="ti ti-user"></i>Colaborador de programa</span>
                        <p>Crea solicitudes desde su programa y solo ve el seguimiento de las suyas.</p>
                    </div>
                </section>
                <p class="roles-note">
                    <i class="ti ti-info-circle"></i>
                    Los roles son de la plataforma: el rol asignado aplica en todos los modulos del usuario.
                    <?php if (is_superadmin($user)): ?>
                        Puedes ajustar sus permisos o crear otros en <a href="../login/roles/roles.php">Gestion de roles</a>.
                    <?php endif; ?>
                </p>

                <?php if (can_manage_users($user) && !$availableUsers): ?>
                    <div class="users-empty-add">
                        <span class="users-empty-add-icon"><i class="ti ti-user-check"></i></span>
                        <div class="users-empty-add-copy">
                            <strong>Todos los usuarios de la plataforma ya estan en este modulo</strong>
                            <span>Para agregar a otra persona, primero creala en la plataforma; despues aparecera aqui para asignarle el modulo y su programa.</span>
                        </div>
                        <a class="btn-primary" href="../login/usuarios/crear_usuario.php?scope=solicitudes"><i class="ti ti-user-plus"></i>Crear usuario nuevo</a>
                    </div>
                <?php elseif (can_manage_users($user)): ?>
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
                                >
                                <input type="hidden" id="agregar_usuario_id" name="usuario_id" required>
                                <div class="user-search-results" id="agregar_usuario_resultados" hidden></div>
                                <div class="form-hint" id="agregar_usuario_hint">Escribe y selecciona un usuario de la lista (flechas + Enter tambien funcionan).</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="agregar_usuario_programa">Programa asignado</label>
                                <select class="form-control" id="agregar_usuario_programa" name="programa_id">
                                    <option value="">Sin programa</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= (int) $program['id'] ?>"><?= e($program['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">Necesario para crear solicitudes y para ser jefe de programa.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="agregar_usuario_rol">Rol</label>
                                <select class="form-control" id="agregar_usuario_rol" name="rol_id">
                                    <option value="">Mantener su rol actual</option>
                                    <?php foreach ($assignableRoles as $roleId => $roleInfo): ?>
                                        <option value="<?= (int) $roleId ?>"><?= e($roleInfo['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">El jefe de programa gestiona las solicitudes que recibe su programa.</div>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-primary" type="submit" id="agregar_usuario_submit"><i class="ti ti-user-plus"></i>Agregar al modulo</button>
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
                                <th>Ingenio</th>
                                <th>Programa</th>
                                <th>Rol</th>
                                <?php if (can_manage_users($user)): ?><th>Acciones</th><?php endif; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$users): ?>
                                <tr><td colspan="<?= can_manage_users($user) ? 6 : 5 ?>">No hay usuarios para mostrar.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($users as $userItem): ?>
                                <?php
                                $formId = 'usuario-programa-form-' . (int) $userItem['id'];
                                $itemRole = module_role($userItem);
                                ?>
                                <tr>
                                    <td>
                                        <?php if (can_manage_users($user)): ?><form id="<?= e($formId) ?>" method="post"></form><?php endif; ?>
                                        <?= e($userItem['nombre']) ?>
                                    </td>
                                    <td><?= e($userItem['correo']) ?></td>
                                    <td><?= e($userItem['ingenio'] ?: 'Sin ingenio') ?></td>
                                    <td>
                                        <?php if (can_manage_users($user)): ?>
                                            <input form="<?= e($formId) ?>" type="hidden" name="action" value="actualizar_programa_usuario">
                                            <input form="<?= e($formId) ?>" type="hidden" name="usuario_id" value="<?= (int) $userItem['id'] ?>">
                                            <select form="<?= e($formId) ?>" class="form-control table-input user-program-select" name="programa_id" data-original="<?= (int) ($userItem['programa_id'] ?? 0) ?: '' ?>">
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
                                        <?php if ($itemRole === 'superadmin' || !can_manage_users($user)): ?>
                                            <span class="role-badge role-<?= e($itemRole) ?>"><?= e($itemRole === 'superadmin' ? 'Superadmin' : $userItem['nombre_rol']) ?></span>
                                        <?php else: ?>
                                            <?php $currentRoleId = (int) $userItem['rol_id']; ?>
                                            <select form="<?= e($formId) ?>" class="form-control table-input user-program-select user-role-select" name="rol_id" data-original="<?= isset($assignableRoles[$currentRoleId]) ? $currentRoleId : '' ?>">
                                                <?php if (!isset($assignableRoles[$currentRoleId])): ?>
                                                    <option value="" selected><?= e($userItem['nombre_rol']) ?> (sin permisos aqui)</option>
                                                <?php endif; ?>
                                                <?php foreach ($assignableRoles as $roleId => $roleInfo): ?>
                                                    <option value="<?= (int) $roleId ?>" <?= $roleId === $currentRoleId ? 'selected' : '' ?>><?= e($roleInfo['nombre']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($itemRole === 'ninguno'): ?>
                                                <div class="role-warning"><i class="ti ti-alert-triangle"></i>No puede crear ni ver solicitudes</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (can_manage_users($user)): ?>
                                        <td>
                                            <div class="row-actions">
                                                <button type="button" class="row-actions-toggle" aria-haspopup="menu" aria-expanded="false">
                                                    Acciones <i class="ti ti-chevron-down"></i>
                                                </button>
                                                <div class="row-actions-menu" role="menu" hidden>
                                                    <button form="<?= e($formId) ?>" class="row-actions-item row-actions-save" type="submit" role="menuitem">
                                                        <i class="ti ti-device-floppy"></i>Guardar programa y rol
                                                    </button>
                                                    <a class="row-actions-item" role="menuitem" href="../login/usuarios/editar_usuario.php?id=<?= (int) $userItem['id'] ?>&scope=solicitudes">
                                                        <i class="ti ti-pencil"></i>Editar usuario
                                                    </a>
                                                    <?php if ($itemRole !== 'superadmin' && (int) $userItem['id'] !== (int) $user['id']): ?>
                                                        <div class="row-actions-sep" role="separator"></div>
                                                        <a class="row-actions-item is-danger" role="menuitem" href="../login/usuarios/eliminar_usuario.php?id=<?= (int) $userItem['id'] ?>&scope=solicitudes" onclick="return confirm('Se eliminara este usuario. Continuar?');">
                                                            <i class="ti ti-trash"></i>Eliminar usuario
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
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
                <?php
                $canManagePrograms = can_manage_programs($user);
                $usageTotals = ['usuarios' => 0, 'solicitudes' => 0];
                foreach ($programUsage as $usage) {
                    $usageTotals['usuarios'] += (int) $usage['usuarios'];
                    $usageTotals['solicitudes'] += (int) $usage['recibidas'];
                }
                ?>
                <div class="programs-head">
                    <div>
                        <div class="section-title">Programas y direcciones</div>
                        <div class="programs-summary">
                            <span><i class="ti ti-building"></i><strong><?= count($programs) ?></strong> programas</span>
                            <span><i class="ti ti-users"></i><strong><?= $usageTotals['usuarios'] ?></strong> usuarios asignados</span>
                            <span><i class="ti ti-files"></i><strong><?= $usageTotals['solicitudes'] ?></strong> solicitudes recibidas</span>
                        </div>
                    </div>
                </div>

                <div class="programs-toolbar">
                    <label class="programs-search">
                        <i class="ti ti-search"></i>
                        <input type="search" id="buscar-programas" placeholder="Buscar programa..." oninput="filterPrograms('buscar-programas', 'lista-programas')">
                    </label>
                    <?php if ($canManagePrograms): ?>
                        <form class="programs-create" method="post">
                            <input type="hidden" name="action" value="crear_programa">
                            <input class="form-control" name="nombre" placeholder="Nombre del nuevo programa" aria-label="Nombre del nuevo programa" required>
                            <button class="btn-primary" type="submit"><i class="ti ti-plus"></i>Crear</button>
                        </form>
                    <?php endif; ?>
                </div>

                <section class="program-list<?= $canManagePrograms ? ' can-manage' : '' ?>" id="lista-programas">
                    <div class="program-list-head" aria-hidden="true">
                        <span>Programa</span>
                        <span>Usuarios</span>
                        <span>Enviadas</span>
                        <span>Recibidas</span>
                        <?php if ($canManagePrograms): ?><span></span><?php endif; ?>
                    </div>
                    <?php foreach ($programs as $program): ?>
                        <?php
                        $programId = (int) $program['id'];
                        $programFormId = 'programa-form-' . $programId;
                        $usage = $programUsage[$programId] ?? ['usuarios' => 0, 'enviadas' => 0, 'recibidas' => 0];
                        $inUse = ((int) $usage['usuarios'] + (int) $usage['enviadas'] + (int) $usage['recibidas']) > 0;
                        ?>
                        <article class="program-admin-card program-row" data-name="<?= e(mb_strtolower($program['nombre'], 'UTF-8')) ?>">
                            <div class="program-row-name">
                                <span class="program-admin-icon"><i class="ti ti-building"></i></span>
                                <?php if ($canManagePrograms): ?>
                                    <form id="<?= e($programFormId) ?>" class="program-admin-form" method="post">
                                        <input type="hidden" name="action" value="actualizar_programa">
                                        <input type="hidden" name="programa_id" value="<?= $programId ?>">
                                        <input class="program-admin-name" name="nombre" value="<?= e($program['nombre']) ?>" data-original="<?= e($program['nombre']) ?>" aria-label="Nombre del programa" title="Haz clic para editar el nombre" required>
                                    </form>
                                <?php else: ?>
                                    <div class="program-admin-title"><?= e($program['nombre']) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="program-row-stat<?= (int) $usage['usuarios'] === 0 ? ' is-zero' : '' ?>" data-label="Usuarios"><i class="ti ti-users"></i><?= (int) $usage['usuarios'] ?></span>
                            <span class="program-row-stat<?= (int) $usage['enviadas'] === 0 ? ' is-zero' : '' ?>" data-label="Enviadas"><i class="ti ti-send"></i><?= (int) $usage['enviadas'] ?></span>
                            <a class="program-row-stat program-row-link<?= (int) $usage['recibidas'] === 0 ? ' is-zero' : '' ?>" data-label="Recibidas" href="index.php?view=recibidas&programa_destino=<?= $programId ?>" title="Ver solicitudes recibidas"><i class="ti ti-inbox"></i><?= (int) $usage['recibidas'] ?></a>
                            <?php if ($canManagePrograms): ?>
                                <div class="program-admin-actions">
                                    <button form="<?= e($programFormId) ?>" class="icon-btn icon-btn-save" type="submit" title="Guardar cambios" aria-label="Guardar cambios"><i class="ti ti-check"></i></button>
                                    <form method="post" onsubmit="return confirm('Se eliminara este programa. Continuar?');">
                                        <input type="hidden" name="action" value="eliminar_programa">
                                        <input type="hidden" name="programa_id" value="<?= $programId ?>">
                                        <button class="icon-btn icon-btn-danger" type="submit"
                                            <?= $inUse ? 'disabled title="No se puede eliminar: tiene usuarios o solicitudes relacionadas"' : 'title="Eliminar programa"' ?>
                                            aria-label="Eliminar programa"><i class="ti ti-trash"></i></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
                <div class="panel-empty hidden" id="lista-programas-vacio"><i class="ti ti-search-off"></i>Ningun programa coincide con la busqueda.</div>
                <?php if (!$programs): ?>
                    <div class="panel-empty"><i class="ti ti-building-off"></i>No hay programas registrados.</div>
                <?php endif; ?>
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
                    <a class="btn-outline" href="index.php?view=<?= (int) $detail['solicitante_id'] === (int) $user['id'] ? 'mis' : 'recibidas' ?>">Volver</a>
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

                    <?php if (!can_manage_request($user, $detail) && module_role($user) === 'director'): ?>
                        <div class="readonly-note"><i class="ti ti-eye"></i>Solo lectura: como director puedes consultar esta solicitud, pero solo cambias el estado de las dirigidas a <?= e(DIRECTOR_PROGRAM_NAME) ?>.</div>
                    <?php endif; ?>

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
<script src="assets/js/app.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
