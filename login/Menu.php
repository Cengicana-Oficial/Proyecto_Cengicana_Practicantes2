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

// En el menu central rige el rol de PLATAFORMA (usuarios.rol_id); cada modulo carga despues
// su propio rol (login/config/roles_modulo.php).
$_SESSION['es_superadmin'] = $user['es_superadmin'];
$_SESSION['rol_id'] = $user['rol_id'];
$stmtRolPlataforma = $conn->prepare('SELECT nombre_rol FROM roles WHERE id = ?');
$stmtRolPlataforma->execute([(int) $user['rol_id']]);
$_SESSION['rol'] = $_SESSION['nombre_rol'] = (string) $stmtRolPlataforma->fetchColumn();
$_SESSION['modulo_activo'] = '';

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
    $nombreVisible = null;
    $logo = null;

    if ($modulo === 'cursos') {
        $ruta = '../cengicursos/index.php';
        $icono = 'school';
        $nombreVisible = 'CengiCursos';
        $logo = 'assets/img/logo-cengicursos.svg';
    } elseif (in_array($modulo, ['laboratorio', 'laboratorios'], true)) {
        $ruta = '../laboratorio/index.php';
        $icono = 'science';
        $nombreVisible = 'CengiLabs';
        $logo = 'assets/img/logo-cengilabs.svg';
    } elseif ($modulo === 'comites, transferencia y comunicacion') {
        $ruta = '../comites/index.php';
        $icono = 'diversity_3';
        // Marca visible del modulo; el nombre en BD no cambia (se usa para enlazarlo).
        $nombreVisible = 'CengiConecta';
        $logo = 'assets/img/logo-cengiconecta.svg';
    } elseif ($modulo === 'solicitudes internas') {
        $ruta = '../sistema_de_solicitudes/index.php';
        $icono = 'assignment';
        $nombreVisible = 'CengiColabora';
        $logo = 'assets/img/logo-cengicolabora.svg';
    } elseif (in_array($modulo, ['sigec', 'experimentos'], true)) {
        // Experimentos (SIGEC) corre en su propio contenedor/dominio (no comparte sesion
        // PHP nativa), por eso pasa por el puente de SSO en vez de saltar
        // directo a una carpeta hermana como los demas modulos.
        $ruta = 'sso_sigec.php';
        $icono = 'biotech';
        $nombreVisible = 'CengiInvestiga';
        $logo = 'assets/img/logo-cengiinvestiga.svg';
    } elseif ($modulo === 'solicitud de visitas') {
        $ruta = '../Pruebas/public/admin/dashboard_unificado.php?modulo=solicitudes';
        $icono = 'calendar_today';
        $nombreVisible = 'CengiVisitas';
        $logo = 'assets/img/logo-cengivisitas.svg';

        if (!$esSuperadmin && $rolId === 2) {
            $ruta .= '&estado=aprobado';
        }
    } elseif ($modulo === 'servicio tecnico' && $esSuperadmin) {
        // No existe dashboard_servicio.php en el repo; se reutiliza el
        // dashboard unificado del modulo Pruebas (mismo que "Solicitud de
        // visitas") hasta que exista una vista propia de servicio tecnico.
        $ruta = '../Pruebas/public/admin/dashboard_unificado.php';
        $icono = 'build';
    } elseif ($modulo === 'ensayos' && $esSuperadmin) {
        $ruta = '../Pruebas/public/admin/dashboard_ensayos.php';
        $icono = 'biotech';
    } elseif ($modulo === 'pago' && ($esSuperadmin || $rolId === 9)) {
        $ruta = '../Pruebas/public/admin/dashboard_pago.php';
        $icono = 'payments';
    }

    return [
        'ruta' => $ruta,
        'icono' => $icono,
        'descripcion' => menu_modulo_descripcion($modulo),
        'nombre' => $nombreVisible ?? $nombre,
        'logo' => $logo,
    ];
}

function menu_modulo_descripcion($moduloNormalizado)
{
    $descripciones = [
        'cursos' => 'Capacitación, inscripciones y eventos',
        'laboratorio' => 'Muestras, análisis y resultados',
        'laboratorios' => 'Muestras, análisis y resultados',
        'experimentos' => 'Programas, ensayos y evaluaciones',
        'sigec' => 'Programas, ensayos y evaluaciones',
        'comites, transferencia y comunicacion' => 'Comités, transferencia y comunicación',
        'solicitudes internas' => 'Requerimientos entre áreas',
        'solicitud de visitas' => 'Programación y aprobación de visitas',
        'servicio tecnico' => 'Atención y seguimiento técnico',
        'ensayos' => 'Gestión de ensayos',
        'pago' => 'Control de pagos',
    ];

    return $descripciones[$moduloNormalizado] ?? 'Sistema de la plataforma';
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
        LEFT JOIN roles r ON r.id = um.rol_id -- rol en ese modulo
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
        LEFT JOIN roles r ON r.id = um.rol_id -- rol en ese modulo
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
    <meta name="theme-color" content="#073d17">
    <title>Panel general | Cengitech</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/menu-cengitech.css?v=<?php echo filemtime(__DIR__ . '/assets/menu-cengitech.css'); ?>">
</head>
<body class="cx">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>

    <header class="app-header" id="appHeader">
        <div class="nav-shell">
            <a class="brand" href="Menu.php" aria-label="Cengitech, ir al panel general">
                <img class="brand-symbol" src="assets/img/logo-cengitech.svg" alt="" aria-hidden="true">
                <span class="brand-name">Cengi<b>tech</b></span>
            </a>

            <button class="menu-toggle" id="menuBtn" type="button"
                    aria-label="Abrir menú" aria-controls="mainNav" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>

            <nav class="main-nav" id="mainNav" aria-label="Navegación principal">
                <a class="nav-link is-active" href="Menu.php" aria-current="page">
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
                            <?php // Editar roles y permisos es solo de superadmin (ver roles/_guard.php). ?>
                            <?php if ((int) ($_SESSION['es_superadmin'] ?? 0) === 1): ?>
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

                <details class="nav-dropdown">
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
                                <?php if ($meta['logo']): ?>
                                    <img class="dropdown-logo" src="<?php echo htmlspecialchars($meta['logo']); ?>" alt="" aria-hidden="true">
                                <?php else: ?>
                                    <span class="material-symbols-outlined"><?php echo htmlspecialchars($meta['icono']); ?></span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($meta['nombre']); ?>
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

    <main id="contenido" tabindex="-1">
        <section class="hero">
            <div class="hero-inner">
                <div class="hero-copy">
                    <span class="eyebrow"><i></i> Hola, <?php echo htmlspecialchars(explode(' ', trim($user['nombre']))[0]); ?></span>
                    <h1>Bienvenido a Cengi<span>tech</span></h1>
                    <p class="hero-tagline">Conocimiento, innovación y gestión para la agroindustria cañera</p>
                    <div class="hero-accent" aria-hidden="true"></div>
                    <p class="hero-intro">Desde aquí accedes a los módulos del ecosistema digital de Cengitech habilitados para tu cuenta.</p>
                </div>

                <aside class="hero-summary" aria-label="Resumen de la plataforma">
                    <div class="summary-heading">
                        <span class="material-symbols-outlined">monitoring</span>
                        <div>
                            <strong>Resumen de la plataforma</strong>
                            <small>Información de tus sistemas</small>
                        </div>
                    </div>
                    <div class="summary-grid">
                        <div>
                            <strong><?php echo count($modulos); ?></strong>
                            <span>Sistemas disponibles</span>
                        </div>
                        <div>
                            <strong><?php echo $totalUsuariosVinculados; ?></strong>
                            <span>Vínculos de usuarios</span>
                        </div>
                    </div>
                    <span class="summary-status"><i></i> Accesos habilitados para tu cuenta</span>
                </aside>
            </div>
        </section>

        <section class="systems-section">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Accesos directos</span>
                    <h2>Módulos disponibles</h2>
                    <p>Accede rápidamente a los módulos habilitados para tu cuenta.</p>
                </div>
                <div class="systems-toolbar" id="systemsToolbar" hidden>
                    <label class="system-search" for="systemSearch">
                        <span class="material-symbols-outlined" aria-hidden="true">search</span>
                        <span class="sr-only">Buscar módulo por nombre</span>
                        <input id="systemSearch" type="search" placeholder="Buscar un módulo…" autocomplete="off" aria-controls="systemsGrid">
                    </label>
                    <span id="systemResults" role="status" aria-live="polite"></span>
                </div>
            </div>

            <div class="systems-grid" id="systemsGrid">
                <?php foreach (array_values($modulos) as $i => $modulo): ?>
                    <?php $meta = menu_modulo_meta($modulo['nombre']); ?>
                    <a class="system-card<?php echo $meta['ruta'] === '#' ? ' is-disabled' : ''; ?>"
                       data-buscar="<?php echo htmlspecialchars($meta['nombre'] . ' ' . $modulo['nombre'] . ' ' . $meta['descripcion']); ?>"
                       style="--i: <?php echo (int) $i; ?>"
                       href="<?php echo htmlspecialchars($meta['ruta']); ?>"
                       <?php echo $meta['ruta'] === '#' ? 'aria-disabled="true"' : ''; ?>>
                        <span class="system-icon<?php echo $meta['logo'] ? ' has-logo' : ''; ?>">
                            <?php if ($meta['logo']): ?>
                                <img src="<?php echo htmlspecialchars($meta['logo']); ?>" alt="" aria-hidden="true">
                            <?php else: ?>
                                <span class="material-symbols-outlined"><?php echo htmlspecialchars($meta['icono']); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="system-copy">
                            <strong><?php echo htmlspecialchars($meta['nombre']); ?></strong>
                            <small><?php echo htmlspecialchars($meta['descripcion']); ?></small>
                        </span>
                        <span class="system-cta">
                            <?php echo $meta['ruta'] === '#' ? 'Acceso no configurado' : 'Ingresar al módulo'; ?>
                            <span class="material-symbols-outlined system-arrow">arrow_forward</span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="systems-empty" id="systemsEmpty" <?php echo count($modulos) ? 'hidden' : ''; ?>>
                <span class="material-symbols-outlined" aria-hidden="true">search_off</span>
                <h3><?php echo count($modulos) ? 'No se encontraron módulos' : 'No tienes módulos asignados'; ?></h3>
                <p><?php echo count($modulos) ? 'Prueba con otro nombre o limpia la búsqueda.' : 'Contacta al administrador para solicitar acceso.'; ?></p>
                <?php if (count($modulos)): ?><button type="button" id="clearSystemSearch">Mostrar todos</button><?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="app-footer">
        <p class="footer-band">Conectando conocimiento, tecnología y aprendizaje para el desarrollo de la Agroindustria</p>
        <div class="footer-bottom">
            <span><strong>Cengi<b>tech</b></strong> · © <?php echo date('Y'); ?> Cengicaña</span>
            <img src="assets/img/logo-cengicana.png" alt="Cengicaña">
        </div>
    </footer>

    <script src="config/menu.js?v=<?php echo filemtime(__DIR__ . '/config/menu.js'); ?>"></script>
</body>
</html>
