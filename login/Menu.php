<?php
require_once __DIR__ . "/config/session.php";
cengi_session_start();
require_once __DIR__ . "/config/conexion.php";

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$titulo = "Plataforma de Gestión";
$conn = Conexion::conectar();
$idUsuario = (int) $_SESSION['id_usuario'];

$stmtEnsureModulo = $conn->prepare("
    INSERT INTO modulos (nombre)
    SELECT ?
    WHERE NOT EXISTS (
        SELECT 1
        FROM modulos
        WHERE LOWER(nombre) = LOWER(?)
    )
");
$stmtEnsureModulo->execute(['Solicitudes internas', 'Solicitudes internas']);

$stmtUser = $conn->prepare("
    SELECT id, nombre, correo, es_superadmin, rol_id
    FROM usuarios
    WHERE id = ?
");
$stmtUser->execute([$idUsuario]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    cengi_session_destroy();
    header("Location: login.php");
    exit;
}

$_SESSION['es_superadmin'] = $user['es_superadmin'];
$_SESSION['rol_id'] = $user['rol_id'];

try {
    $stmtPermisos = $conn->prepare("
        SELECT p.nombre_permiso
        FROM rol_permiso rp
        INNER JOIN permisos p ON p.id = rp.permiso_id
        WHERE rp.rol_id = ?
    ");
    $stmtPermisos->execute([$user['rol_id']]);
    $_SESSION['user_permissions'] = $stmtPermisos->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $_SESSION['user_permissions'] = $_SESSION['user_permissions'] ?? [];
}

function menu_can($permission)
{
    if (isset($_SESSION['es_superadmin']) && (int) $_SESSION['es_superadmin'] === 1) {
        return true;
    }

    return in_array($permission, $_SESSION['user_permissions'] ?? [], true);
}

function menu_normalizar_modulo($nombre)
{
    $nombre = mb_strtolower(trim((string) $nombre), 'UTF-8');

    return strtr($nombre, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ñ' => 'n',
    ]);
}

function menu_modulo_meta($nombre)
{
    $modulo = menu_normalizar_modulo($nombre);
    $esSuperadmin = !empty($_SESSION['es_superadmin']);
    $rolId = (int) ($_SESSION['rol_id'] ?? 0);
    $ruta = '#';
    $icono = 'apps';

    if ($modulo === 'cursos') {
        $ruta = '../cengicursos/index.php';
        $icono = 'school';
    } elseif (in_array($modulo, ['laboratorio', 'laboratorios'], true)) {
        $ruta = '../laboratorio/index.php';
        $icono = 'science';
    } elseif ($modulo === 'solicitudes internas') {
        $ruta = '../sistema_de_solicitudes/index.php';
        $icono = 'assignment';
    } elseif ($modulo === 'sigec') {
        // SIGEC corre en su propio contenedor/dominio (no comparte sesion
        // PHP nativa), por eso pasa por el puente de SSO en vez de saltar
        // directo a una carpeta hermana como los demas modulos.
        $ruta = 'sso_sigec.php';
        $icono = 'biotech';
    } elseif ($modulo === 'solicitud de visitas') {
        $ruta = '../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes';
        $icono = 'calendar_today';

        if (!$esSuperadmin && $rolId === 2) {
            $ruta .= '&estado=aprobado';
        }
    } elseif ($modulo === 'servicio tecnico' && $esSuperadmin) {
        $ruta = '../Pruebas/public/admin/dashboard_servicio.php';
        $icono = 'build';
    } elseif ($modulo === 'ensayos' && $esSuperadmin) {
        $ruta = '../Pruebas/public/admin/dashboard_ensayos.php';
        $icono = 'biotech';
    } elseif ($modulo === 'pago' && ($esSuperadmin || $rolId === 9)) {
        $ruta = '../Pruebas/public/admin/dashboard_pago.php';
        $icono = 'payments';
    }

    return ['ruta' => $ruta, 'icono' => $icono];
}

$mostrarAdministracion = !empty($_SESSION['es_superadmin']);

if ($mostrarAdministracion) {
    $stmt = $conn->query("
        SELECT id, nombre
        FROM modulos
        ORDER BY nombre
    ");
} else {
    $stmt = $conn->prepare("
        SELECT m.id, m.nombre
        FROM usuario_modulo um
        INNER JOIN modulos m ON m.id = um.modulo_id
        WHERE um.usuario_id = ?
        ORDER BY m.nombre
    ");
    $stmt->execute([$idUsuario]);
}

$modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($mostrarAdministracion) {
    $stmtDash = $conn->query("
        SELECT
            m.nombre AS modulo,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.nombre END) AS admin_nombre,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.correo END) AS admin_correo,
            COUNT(DISTINCT u.id) AS total_usuarios
        FROM modulos m
        LEFT JOIN usuario_modulo um ON m.id = um.modulo_id
        LEFT JOIN usuarios u ON u.id = um.usuario_id
        LEFT JOIN roles r ON u.rol_id = r.id
        GROUP BY m.id
        ORDER BY m.nombre
    ");
} else {
    $stmtDash = $conn->prepare("
        SELECT
            m.nombre AS modulo,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.nombre END) AS admin_nombre,
            MAX(CASE WHEN r.nombre_rol = 'Administrador' THEN u.correo END) AS admin_correo,
            COUNT(DISTINCT u.id) AS total_usuarios
        FROM usuario_modulo um
        INNER JOIN modulos m ON m.id = um.modulo_id
        INNER JOIN usuarios u ON u.id = um.usuario_id
        INNER JOIN roles r ON u.rol_id = r.id
        WHERE um.modulo_id IN (
            SELECT modulo_id
            FROM usuario_modulo
            WHERE usuario_id = ?
        )
        GROUP BY m.id
        ORDER BY m.nombre
    ");
    $stmtDash->execute([$idUsuario]);
}

$dashboard = $stmtDash->fetchAll(PDO::FETCH_ASSOC);
$totalUsuariosVinculados = array_sum(array_map(static function ($item) {
    return (int) ($item['total_usuarios'] ?? 0);
}, $dashboard));
$inicialUsuario = mb_strtoupper(mb_substr($user['nombre'], 0, 1, 'UTF-8'), 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($titulo); ?> | CENGICAÑA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/Menu.css">
</head>
<body>
    <header class="app-header" id="appHeader">
        <div class="brand-accent"></div>
        <div class="nav-shell">
            <a class="brand" href="Menu.php" aria-label="Ir al panel principal">
                <img class="brand-logo" src="assets/img/logo.png" alt="CENGICAÑA">
            </a>

            <button class="menu-toggle" id="menuBtn" type="button"
                    aria-label="Abrir menú" aria-controls="mainNav" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>

            <nav class="main-nav" id="mainNav" aria-label="Navegación principal">
                <a class="nav-link is-active" href="Menu.php">
                    <span class="material-symbols-outlined">dashboard</span>
                    Panel general
                </a>

                <?php if ($mostrarAdministracion): ?>
                    <details class="nav-dropdown">
                        <summary class="nav-link">
                            <span class="material-symbols-outlined">settings</span>
                            Administración
                            <span class="material-symbols-outlined nav-chevron">expand_more</span>
                        </summary>
                        <div class="dropdown-panel">
                            <span class="dropdown-title">Administración</span>
                            <?php if (menu_can('gestionar_usuarios')): ?>
                                <a href="usuarios/usuarios.php"><span class="material-symbols-outlined">group</span> Usuarios</a>
                            <?php endif; ?>
                            <?php if (menu_can('gestionar_roles')): ?>
                                <a href="roles/roles.php"><span class="material-symbols-outlined">admin_panel_settings</span> Roles y permisos</a>
                            <?php endif; ?>
                            <?php if (menu_can('gestionar_modulos')): ?>
                                <a href="modulos/modulos.php"><span class="material-symbols-outlined">widgets</span> Módulos</a>
                            <?php endif; ?>
                            <?php if (menu_can('gestionar_ingenios')): ?>
                                <a href="ingenios/ingenios.php"><span class="material-symbols-outlined">factory</span> Ingenios</a>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>

                <details class="nav-dropdown systems-dropdown">
                    <summary class="nav-link">
                        <span class="material-symbols-outlined">apps</span>
                        Sistemas
                        <span class="module-count"><?php echo count($modulos); ?></span>
                        <span class="material-symbols-outlined nav-chevron">expand_more</span>
                    </summary>
                    <div class="dropdown-panel">
                        <span class="dropdown-title">Sistemas disponibles</span>
                        <?php foreach ($modulos as $modulo): ?>
                            <?php $meta = menu_modulo_meta($modulo['nombre']); ?>
                            <a href="<?php echo htmlspecialchars($meta['ruta']); ?>"
                               <?php echo $meta['ruta'] === '#' ? 'class="is-disabled" aria-disabled="true"' : ''; ?>>
                                <span class="material-symbols-outlined"><?php echo htmlspecialchars($meta['icono']); ?></span>
                                <?php echo htmlspecialchars($modulo['nombre']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            </nav>

            <details class="user-menu">
                <summary>
                    <span class="user-avatar"><?php echo htmlspecialchars($inicialUsuario); ?></span>
                    <span class="user-copy">
                        <strong><?php echo htmlspecialchars($user['nombre']); ?></strong>
                        <small><?php echo $mostrarAdministracion ? 'Administrador' : 'Usuario activo'; ?></small>
                    </span>
                    <span class="material-symbols-outlined">expand_more</span>
                </summary>
                <div class="user-panel">
                    <div class="user-panel-heading">
                        <span class="user-avatar is-large"><?php echo htmlspecialchars($inicialUsuario); ?></span>
                        <div>
                            <strong><?php echo htmlspecialchars($user['nombre']); ?></strong>
                            <small><?php echo htmlspecialchars($user['correo']); ?></small>
                        </div>
                    </div>
                    <span class="session-status"><i></i> Sesión activa</span>
                    <a href="../Pruebas/public/admin/logout.php" class="logout-link">
                        <span class="material-symbols-outlined">logout</span>
                        Cerrar sesión
                    </a>
                </div>
            </details>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="hero-copy">
                <span class="eyebrow">CENGICAÑA Digital</span>
                <h1>Todo el trabajo,<br><span>en un mismo lugar.</span></h1>
                <p>Gestiona procesos, solicitudes y accesos desde una plataforma clara, rápida y organizada.</p>
            </div>

            <div class="hero-summary">
                <div class="summary-heading">
                    <span class="material-symbols-outlined">monitoring</span>
                    <div>
                        <strong>Resumen de la plataforma</strong>
                        <small>Información actualizada de tus sistemas</small>
                    </div>
                </div>
                <div class="summary-grid">
                    <div>
                        <strong><?php echo count($modulos); ?></strong>
                        <span>Sistemas disponibles</span>
                    </div>
                    <div>
                        <strong><?php echo $totalUsuariosVinculados; ?></strong>
                        <span>Usuarios vinculados</span>
                    </div>
                </div>
                <span class="summary-status"><i></i> Servicios operando</span>
            </div>
        </section>

        <section class="content-section">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Vista general</span>
                    <h2>Panel general del sistema</h2>
                    <p>Consulta responsables y usuarios vinculados a cada módulo.</p>
                </div>
                <span class="section-total"><?php echo count($dashboard); ?> módulos</span>
            </div>

            <div class="dashboard-grid">
                <?php foreach ($dashboard as $item): ?>
                    <?php
                    $adminNombre = $item['admin_nombre'] ?: 'No asignado';
                    $adminIniciales = mb_strtoupper(mb_substr($adminNombre, 0, 2, 'UTF-8'), 'UTF-8');
                    ?>
                    <article class="module-card">
                        <div class="module-card-top">
                            <span class="module-icon"><span class="material-symbols-outlined">dashboard</span></span>
                            <span class="active-badge"><i></i> Activo</span>
                        </div>
                        <h3><?php echo htmlspecialchars($item['modulo']); ?></h3>
                        <div class="module-admin">
                            <span class="admin-avatar"><?php echo htmlspecialchars($adminIniciales); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars($adminNombre); ?></strong>
                                <small><?php echo htmlspecialchars($item['admin_correo'] ?? 'Sin correo asignado'); ?></small>
                            </div>
                        </div>
                        <div class="module-metric">
                            <strong><?php echo (int) $item['total_usuarios']; ?></strong>
                            <span>usuarios activos</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="systems-section">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Accesos directos</span>
                    <h2>Sistemas disponibles</h2>
                    <p>Ingresa rápidamente a las herramientas habilitadas para tu cuenta.</p>
                </div>
            </div>

            <div class="systems-grid">
                <?php foreach ($modulos as $modulo): ?>
                    <?php $meta = menu_modulo_meta($modulo['nombre']); ?>
                    <a class="system-card<?php echo $meta['ruta'] === '#' ? ' is-disabled' : ''; ?>"
                       href="<?php echo htmlspecialchars($meta['ruta']); ?>"
                       <?php echo $meta['ruta'] === '#' ? 'aria-disabled="true"' : ''; ?>>
                        <span class="system-icon">
                            <span class="material-symbols-outlined"><?php echo htmlspecialchars($meta['icono']); ?></span>
                        </span>
                        <span>
                            <strong><?php echo htmlspecialchars($modulo['nombre']); ?></strong>
                            <small><?php echo $meta['ruta'] === '#' ? 'Acceso no configurado' : 'Abrir sistema'; ?></small>
                        </span>
                        <span class="material-symbols-outlined system-arrow">arrow_forward</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <footer class="app-footer">
        <div>
            <strong>CENGICAÑA</strong>
            <span>© <?php echo date('Y'); ?> · Plataforma de gestión institucional</span>
        </div>
        <span>Innovación al servicio de la agroindustria</span>
    </footer>

    <script src="config/menu.js"></script>
</body>
</html>
