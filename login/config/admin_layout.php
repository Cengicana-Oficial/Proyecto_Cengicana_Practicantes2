<?php
/**
 * Encabezado y pie Cengitech para las páginas de administración de login/
 * (usuarios/, roles/, modulos/, ingenios/). Solo presentación: el acceso lo
 * siguen controlando los _guard.php / _autorizacion.php de cada carpeta.
 *
 * Uso (las páginas viven un nivel debajo de login/):
 *   <head> ... <link rel="stylesheet" href="../assets/menu-cengitech.css"> + admin-cengitech.css
 *   <body class="cx cx-admin"><?php cengi_admin_header('usuarios'); ?> ... <?php cengi_admin_footer(); ?></body>
 */

if (!function_exists('cengi_admin_header')) {

    function cengi_admin_puede($permiso)
    {
        if ((int) ($_SESSION['es_superadmin'] ?? 0) === 1) {
            return true;
        }

        return in_array($permiso, $_SESSION['user_permissions'] ?? [], true);
    }

    function cengi_admin_header($activo = '')
    {
        $esSuper = (int) ($_SESSION['es_superadmin'] ?? 0) === 1;
        $nombre = trim((string) ($_SESSION['usuario'] ?? ''));
        $correo = (string) ($_SESSION['correo'] ?? '');
        $inicial = $nombre !== '' ? mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') : '?';
        $h = static function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };

        // Mismas reglas que los guards de cada carpeta.
        $links = [];
        if ($esSuper) {
            $links['usuarios'] = ['../usuarios/usuarios.php', 'group', 'Usuarios'];
            $links['roles'] = ['../roles/roles.php', 'admin_panel_settings', 'Roles y permisos'];
        }
        if (cengi_admin_puede('gestionar_modulos')) {
            $links['modulos'] = ['../modulos/modulos.php', 'widgets', 'Módulos'];
        }
        if (cengi_admin_puede('gestionar_ingenios')) {
            $links['ingenios'] = ['../ingenios/ingenios.php', 'factory', 'Ingenios'];
        }
        ?>
    <a class="skip-link" href="#cx-contenido">Saltar al contenido</a>
    <header class="app-header" id="appHeader">
        <div class="nav-shell">
            <a class="brand" href="../Menu.php" aria-label="Cengitech, ir al panel general">
                <img class="brand-symbol" src="../assets/img/logo-cengitech.svg" alt="" aria-hidden="true">
                <span class="brand-name">Cengi<b>tech</b></span>
            </a>

            <button class="menu-toggle" id="menuBtn" type="button"
                    aria-label="Abrir menú" aria-controls="mainNav" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>

            <nav class="main-nav" id="mainNav" aria-label="Navegación principal">
                <a class="nav-link" href="../Menu.php">
                    <span class="material-symbols-outlined">dashboard</span>
                    Panel general
                </a>
                <?php foreach ($links as $clave => [$ruta, $icono, $texto]): ?>
                    <a class="nav-link<?= $clave === $activo ? ' is-active' : '' ?>" href="<?= $h($ruta) ?>"<?= $clave === $activo ? ' aria-current="page"' : '' ?>>
                        <span class="material-symbols-outlined"><?= $h($icono) ?></span>
                        <?= $h($texto) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <details class="user-menu">
                <summary>
                    <span class="user-avatar"><?= $h($inicial) ?></span>
                    <span class="user-copy">
                        <strong><?= $h($nombre) ?></strong>
                        <small><?= $esSuper ? 'Administrador' : 'Usuario activo' ?></small>
                    </span>
                    <span class="material-symbols-outlined">expand_more</span>
                </summary>
                <div class="user-panel">
                    <div class="user-panel-heading">
                        <span class="user-avatar is-large"><?= $h($inicial) ?></span>
                        <div>
                            <strong><?= $h($nombre) ?></strong>
                            <small><?= $h($correo) ?></small>
                        </div>
                    </div>
                    <span class="session-status"><i></i> Sesión activa</span>
                    <a href="../../Pruebas/public/admin/logout.php" class="logout-link">
                        <span class="material-symbols-outlined">logout</span>
                        Cerrar sesión
                    </a>
                </div>
            </details>
        </div>
    </header>
    <div class="cx-admin-band" aria-hidden="true"></div>
    <span id="cx-contenido" tabindex="-1"></span>
        <?php
    }

    function cengi_admin_footer()
    {
        ?>
    <footer class="app-footer">
        <p class="footer-band">Conectando conocimiento, tecnología y aprendizaje para el desarrollo de la Agroindustria</p>
        <div class="footer-bottom">
            <span><strong>Cengi<b>tech</b></strong> · © <?= date('Y') ?> Cengicaña</span>
            <img src="../assets/img/logo-cengicana.png" alt="Cengicaña">
        </div>
    </footer>
    <script src="../config/menu.js?v=<?= (int) @filemtime(__DIR__ . '/menu.js') ?>"></script>
        <?php
    }
}
