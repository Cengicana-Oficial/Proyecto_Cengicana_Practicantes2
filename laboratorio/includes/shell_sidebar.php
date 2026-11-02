<?php
/**
 * shell_sidebar.php
 *
 * Shell de navegacion compartido (sidebar + topbar) para TODO el modulo
 * Laboratorio, con la misma convencion visual/estructural de
 * cengicursos/menu.php (clases .cengi-sidebar / .cengi-nav-group /
 * .cengi-nav-item / .cengi-sidebar-foot / .cengi-topbar) y los tokens de
 * cengicursos/css/proyecto.css, en vez del look propio del mockup de
 * referencia. Requiere que includes/auth.php ya haya sido incluido y que
 * lab_require_module_access() (o similar) ya se haya ejecutado.
 *
 * Uso tipico dentro de una vista:
 *
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_once __DIR__ . '/../includes/shell_sidebar.php';
 *   lab_require_module_access();
 *   ...
 *   lab_shell_head('Titulo de pagina', 'Subtitulo', ['../css/mi_vista.css?v=20261006']);
 *   lab_shell_open('view/mi_vista.php');
 *   ... contenido ...
 *   lab_shell_close();
 */

if (!function_exists('lab_shell_base_prefix')) {
    /**
     * Calcula el prefijo relativo hacia la raiz de /laboratorio segun la
     * profundidad del script actual (root = '', view/x.php = '../',
     * view/Suelos/x.php = '../../').
     */
    function lab_shell_base_prefix(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
        $labRoot = str_replace('\\', '/', dirname(__DIR__));

        $pos = strpos($script, $labRoot);
        if ($pos === false) {
            return '';
        }

        $relative = ltrim(substr($script, $pos + strlen($labRoot)), '/');
        $depth = substr_count($relative, '/');

        return str_repeat('../', $depth);
    }
}

if (!function_exists('lab_shell_nav_items')) {
    /**
     * Estructura de navegacion agrupada. Los 3 grupos y el orden de items
     * dentro de cada uno replican 1:1 el sidebar del mockup de referencia
     * (laboratorio-workflow-v2), pero cada item apunta a una pagina real
     * del modulo (nunca a un href inventado):
     *
     *   Flujo operativo:
     *     Dashboard, Recepcion, Identificacion de muestra,
     *     Planificacion del trabajo, Bandeja del analista,
     *     Validacion tecnica, Historial de versiones.
     *   Calidad y entrega:
     *     Control de calidad, Informes, Firma electronica, Reportes.
     *   Sistema:
     *     Catalogo de analisis, Administracion.
     *
     * Los badges numericos (Planificacion/Bandeja/Validacion) usan
     * conteos reales calculados en includes/nav_badges_helper.php; si la
     * base de datos no esta disponible el badge simplemente no se pinta.
     */
    function lab_shell_nav_items(): array
    {
        require_once __DIR__ . '/nav_badges_helper.php';

        $canDashboard = lab_has_module_access();
        $canRecepcion = lab_can('laboratorio.solicitudes.crear') || lab_has_module_access();
        $canLotes = lab_can('laboratorio.lotes.ver');
        $canKanban = lab_can('laboratorio.kanban.ver');
        $canBandeja = lab_can('laboratorio.formularios_pendientes.ver') || lab_is_technician();
        $canValidacion = lab_can('laboratorio.validacion_tecnica.ver') && is_file(__DIR__ . '/../view/validacion_tecnica_view.php');
        $canTrazabilidad = lab_can('laboratorio.trazabilidad.ver');
        $canConsolidacion = lab_can('laboratorio.consolidacion.ver');
        $canInformes = lab_can('laboratorio.informes.ver');
        $canDocumentos = lab_can('laboratorio.documentos.ver');
        $canCatalogoAnalisis = lab_can('laboratorio.catalogo_analisis.ver');
        $canUsuarios = lab_can('laboratorio.usuarios.gestionar');

        $badges = lab_shell_nav_badges();

        $groups = [];

        // ---- Flujo operativo ----------------------------------------
        $flujo = [];
        if ($canDashboard) {
            $flujo[] = ['href' => 'index.php', 'icon' => 'home', 'label' => 'Inicio', 'match' => ['index.php']];
            $flujo[] = ['href' => 'view/dashboard.php', 'icon' => 'monitoring', 'label' => 'Dashboard', 'match' => ['dashboard.php']];
        }
        if ($canRecepcion) {
            $flujo[] = ['href' => 'view/solicitud_formulario.php', 'icon' => 'inbox', 'label' => 'Recepcion', 'match' => ['solicitud_formulario.php']];
        }
        if ($canLotes) {
            $flujo[] = ['href' => 'view/listar_lotes.php', 'icon' => 'search', 'label' => 'Identificacion de muestra', 'match' => ['listar_lotes.php']];
        }
        if ($canKanban) {
            $flujo[] = [
                'href' => 'view/kanban_view.php', 'icon' => 'view_kanban', 'label' => 'Planificacion del trabajo',
                'match' => ['kanban_view.php'], 'badge' => $badges['planificacion'] ?? null,
            ];
        }
        if ($canBandeja) {
            $flujo[] = [
                'href' => 'view/solicitudes_pendientes_tecnico.php', 'icon' => 'checklist', 'label' => 'Bandeja del analista',
                'match' => ['solicitudes_pendientes_tecnico.php', 'captura_variables.php'], 'badge' => $badges['bandeja'] ?? null,
            ];
        }
        if ($canValidacion) {
            $flujo[] = [
                'href' => 'view/validacion_tecnica_view.php', 'icon' => 'fact_check', 'label' => 'Validacion tecnica',
                'match' => ['validacion_tecnica_view.php'], 'badge' => $badges['validacion'] ?? null,
            ];
        }
        if ($canTrazabilidad) {
            $flujo[] = ['href' => 'view/trazabilidad_view.php', 'icon' => 'timeline', 'label' => 'Historial de versiones', 'match' => ['trazabilidad_view.php']];
        }
        if ($flujo) {
            $groups['Flujo operativo'] = $flujo;
        }

        // ---- Calidad y entrega -----------------------------------------
        $calidad = [];
        if ($canConsolidacion) {
            $calidad[] = ['href' => 'controllers/consolidacion_controller.php', 'icon' => 'verified', 'label' => 'Control de calidad', 'match' => ['consolidacion_controller.php']];
        }
        if ($canInformes && is_file(__DIR__ . '/../view/informes_view.php')) {
            $calidad[] = ['href' => 'view/informes_view.php', 'icon' => 'summarize', 'label' => 'Informes', 'match' => ['informes_view.php', 'firma_electronica_view.php']];
        }
        if ($canDocumentos && is_file(__DIR__ . '/../view/documentos_view.php')) {
            $calidad[] = ['href' => 'view/documentos_view.php', 'icon' => 'description', 'label' => 'Reportes', 'match' => ['documentos_view.php']];
        }
        if ($calidad) {
            $groups['Calidad y entrega'] = $calidad;
        }

        // ---- Sistema -----------------------------------------------------
        $sistema = [];
        if ($canCatalogoAnalisis) {
            $sistema[] = ['href' => 'catalogo_analisis.php', 'icon' => 'table_view', 'label' => 'Catalogo de analisis', 'match' => ['catalogo_analisis.php', 'catalogo_muestras.php']];
        }
        if ($canDashboard) {
            $sistema[] = ['href' => 'view/roles_view.php', 'icon' => 'admin_panel_settings', 'label' => 'Roles y permisos', 'match' => ['roles_view.php']];
        }
        if ($canUsuarios) {
            // Personas con acceso al laboratorio y su rol (view/usuarios_view.php). Los usuarios
            // nuevos se crean en el formulario central (login/usuarios, scope "laboratorio"),
            // que regresa a esta pantalla.
            $sistema[] = ['href' => 'view/usuarios_view.php', 'icon' => 'group', 'label' => 'Usuarios', 'match' => ['usuarios_view.php']];
        }
        if ($sistema) {
            $groups['Sistema'] = $sistema;
        }

        return $groups;
    }
}

if (!function_exists('lab_shell_head')) {
    /**
     * Imprime la apertura de <html><head> con las fuentes/CSS base del shell
     * mas los CSS propios de la vista que se le indiquen.
     *
     * @param string $extraCss Rutas de CSS adicionales (ya resueltas con el prefijo
     *                         correcto por el llamador, ej. '../css/dashboard.css?v=20261006').
     */
    function lab_shell_head(string $titulo, string $subtitulo = '', array $extraCss = []): void
    {
        $base = lab_shell_base_prefix();
        $GLOBALS['__lab_shell_title'] = $titulo;
        $GLOBALS['__lab_shell_sub'] = $subtitulo;
        $GLOBALS['__lab_shell_base'] = $base;
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titulo !== '' ? $titulo . ' · Laboratorio' : 'Laboratorio', ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>css/lab_shell.css?v=20261006">
<?php foreach ($extraCss as $cssHref): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
</head>
<body class="cengi-canvas">
        <?php
    }
}

if (!function_exists('lab_shell_open')) {
    /**
     * Imprime el sidebar + topbar y abre <main class="cengi-lab-content">.
     * $currentScript se usa para resaltar el item activo (ej. 'dashboard.php').
     */
    function lab_shell_open(string $currentScript, string $titulo = '', string $subtitulo = ''): void
    {
        $base = $GLOBALS['__lab_shell_base'] ?? lab_shell_base_prefix();
        $titulo = $titulo !== '' ? $titulo : ($GLOBALS['__lab_shell_title'] ?? 'Laboratorio');
        $subtitulo = $subtitulo !== '' ? $subtitulo : ($GLOBALS['__lab_shell_sub'] ?? '');

        $user = function_exists('lab_current_user') ? lab_current_user() : [];
        $nombreUsuario = trim((string) ($user['nombre'] ?? 'Usuario'));
        if ($nombreUsuario === '') {
            $nombreUsuario = 'Usuario';
        }
        $rolUsuario = trim((string) ($user['rol'] ?? ''));

        $iniciales = '';
        foreach (preg_split('/\s+/', $nombreUsuario, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
            $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($iniciales, 'UTF-8') >= 2) {
                break;
            }
        }
        $iniciales = $iniciales !== '' ? $iniciales : 'U';

        $groups = lab_shell_nav_items();
        $currentScript = basename($currentScript);
        ?>
<div class="cengi-sidebar-wrap">
<aside class="cengi-sidebar" id="cengiLabSidebar">
    <a class="cengi-brand" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>index.php">
        <div class="cengi-brand-mark"><span class="material-symbols-outlined">experiment</span></div>
        <div class="cengi-brand-copy">
            <strong>CENGILABS</strong>
            <small>Lab. Agroindustrial</small>
        </div>
    </a>

    <nav class="cengi-sidebar-nav" id="cengiLabNav" aria-label="Navegacion de Laboratorio">
        <?php foreach ($groups as $label => $items): ?>
            <div class="cengi-nav-group">
                <div class="cengi-nav-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                <?php foreach ($items as $item): ?>
                    <?php $isActive = in_array($currentScript, $item['match'], true); ?>
                    <a href="<?= htmlspecialchars($base . $item['href'], ENT_QUOTES, 'UTF-8') ?>"
                       class="cengi-nav-item<?= $isActive ? ' is-active' : '' ?>"
                       <?= $isActive ? 'aria-current="page"' : '' ?>>
                        <span class="material-symbols-outlined"><?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($item['badge']) && (int) $item['badge'] > 0): ?>
                            <span class="cengi-nav-badge"><?= (int) $item['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="cengi-sidebar-foot">
        <a href="<?= htmlspecialchars($base . '../login/Menu.php', ENT_QUOTES, 'UTF-8') ?>" class="cengi-nav-item">
            <span class="material-symbols-outlined">home</span>
            <span>Menú principal</span>
        </a>
        <a href="<?= htmlspecialchars(lab_logout_url(), ENT_QUOTES, 'UTF-8') ?>" class="cengi-nav-item cengi-logout-link">
            <span class="material-symbols-outlined">logout</span>
            <span>Cerrar sesion</span>
        </a>
        <div class="cengi-sidebar-version">Laboratorio &middot; Zafra 2025&ndash;2026</div>
    </div>
</aside>

<header class="cengi-topbar">
    <button type="button" class="cengi-menu-toggle" id="cengiLabMenuToggle"
            aria-label="Abrir menu" aria-expanded="false" aria-controls="cengiLabSidebar">
        <span></span><span></span><span></span>
    </button>
    <div class="cengi-topbar-titles">
        <div class="cengi-topbar-title"><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($subtitulo !== ''): ?>
            <div class="cengi-topbar-sub"><?= htmlspecialchars($subtitulo, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>
    <div class="cengi-topbar-right">
        <div class="cengi-notif" id="cengiNotif" data-endpoint="<?= htmlspecialchars($base . 'controllers/notificaciones.php', ENT_QUOTES, 'UTF-8') ?>" data-base="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="cengi-notif-btn" id="cengiNotifBtn" aria-label="Notificaciones" aria-haspopup="true" aria-expanded="false" aria-controls="cengiNotifPanel">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                <span class="cengi-notif-count" id="cengiNotifCount" hidden></span>
            </button>
            <div class="cengi-notif-panel" id="cengiNotifPanel" role="dialog" aria-label="Notificaciones" hidden>
                <div class="cengi-notif-head"><b>Lo que le toca atender</b><button type="button" class="cengi-notif-refresh" id="cengiNotifRefresh" aria-label="Actualizar notificaciones">Actualizar</button></div>
                <div class="cengi-notif-list" id="cengiNotifList"><p class="cengi-notif-empty">Cargando…</p></div>
            </div>
        </div>
        <?php if ($rolUsuario !== ''): ?>
            <span class="cengi-pill"><?= htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <div class="cengi-userbox">
            <div class="cengi-avatar"><?= htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8') ?></div>
            <div>
                <div class="cengi-userbox-u1"><?= htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($rolUsuario !== ''): ?><div class="cengi-userbox-u2"><?= htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
        </div>
    </div>
</header>
</div>

<main class="cengi-lab-content">
        <?php
    }
}

if (!function_exists('lab_shell_content_close')) {
    /**
     * Cierra <main class="cengi-lab-content"> e imprime el script del
     * toggle del sidebar, sin cerrar <body></html> (para paginas que ya
     * traen su propio cierre de documento, ej. catalogo_*.php).
     */
    function lab_shell_content_close(): void
    {
        ?>
</main>
<script>
(function () {
    var sidebar = document.getElementById('cengiLabSidebar');
    var toggle = document.getElementById('cengiLabMenuToggle');
    var nav = document.getElementById('cengiLabNav');

    function closeMenu() {
        if (!sidebar || !toggle) return;
        sidebar.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            var willOpen = !sidebar.classList.contains('is-open');
            sidebar.classList.toggle('is-open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    }

    document.addEventListener('click', function (event) {
        if (sidebar && sidebar.classList.contains('is-open')
            && !sidebar.contains(event.target)
            && event.target !== toggle
            && !toggle.contains(event.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMenu();
    });

    if (nav) {
        nav.addEventListener('click', function (event) {
            if (event.target.closest('a')) closeMenu();
        });
    }
})();

/* Campanita: pide al servidor lo que le toca atender al usuario (según su rol) y lo
   refresca cada 2 minutos y al volver a la pestaña. */
(function () {
    var root = document.getElementById('cengiNotif');
    if (!root || !window.fetch) return;
    var btn = document.getElementById('cengiNotifBtn');
    var panel = document.getElementById('cengiNotifPanel');
    var list = document.getElementById('cengiNotifList');
    var count = document.getElementById('cengiNotifCount');
    var base = root.dataset.base || '';
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
    function pintar(datos) {
        var total = datos.total || 0;
        count.hidden = total <= 0;
        count.textContent = total > 99 ? '99+' : String(total);
        btn.setAttribute('aria-label', total ? 'Notificaciones: ' + total + ' pendiente(s)' : 'Notificaciones: nada pendiente');
        list.innerHTML = (datos.items || []).length
            ? datos.items.map(function (n) {
                return '<a class="cengi-notif-item is-' + esc(n.tono) + '" href="' + esc(base + n.href) + '">'
                    + '<span class="cengi-notif-num">' + esc(n.cantidad) + '</span>'
                    + '<span><b>' + esc(n.titulo) + '</b><small>' + esc(n.detalle) + '</small></span></a>';
            }).join('')
            : '<p class="cengi-notif-empty">Todo al día: no tiene pendientes por atender.</p>';
    }
    function cargar(fresco) {
        return fetch(root.dataset.endpoint + (fresco ? '?fresco=1' : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (datos) { if (datos) pintar(datos); })
            .catch(function () {});
    }
    function abrir(abierto) {
        panel.hidden = !abierto;
        btn.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    }
    btn.addEventListener('click', function (e) { e.stopPropagation(); abrir(panel.hidden); if (!panel.hidden) cargar(); });
    document.getElementById('cengiNotifRefresh').addEventListener('click', function () { cargar(true); });
    document.addEventListener('click', function (e) { if (!root.contains(e.target)) abrir(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { abrir(false); btn.focus(); } });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) cargar(); });
    setInterval(function () { if (!document.hidden) cargar(); }, 120000);
    cargar();
})();
</script>
<style>
.cengi-notif { position:relative; }
.cengi-notif-btn { position:relative; display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border:1px solid var(--cengi-border, #E3F1D3); border-radius:50%; background:#fff; color:var(--cengi-ink, #1F2326); cursor:pointer; }
.cengi-notif-btn:hover, .cengi-notif-btn[aria-expanded="true"] { border-color:var(--cengi-primary, #73bc25); }
.cengi-notif-count { position:absolute; top:-4px; right:-4px; min-width:18px; height:18px; padding:0 5px; border-radius:9px; background:#B34B00; color:#fff; font-size:11px; font-weight:700; line-height:18px; text-align:center; }
.cengi-notif-count[hidden], .cengi-notif-panel[hidden] { display:none; }
.cengi-notif-panel { position:absolute; right:0; top:calc(100% + 8px); z-index:60; width:min(340px, calc(100vw - 32px)); background:#fff; border:1px solid var(--cengi-border, #E3F1D3); border-radius:12px; box-shadow:0 12px 32px rgba(31, 35, 38,.16); overflow:hidden; }
.cengi-notif-head { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:12px 14px; border-bottom:1px solid var(--cengi-border, #E3F1D3); font-size:13px; }
.cengi-notif-refresh { border:0; background:none; color:var(--cengi-primary-deep, #4E8418); font:inherit; font-size:12px; cursor:pointer; }
.cengi-notif-list { max-height:min(60vh, 420px); overflow-y:auto; }
.cengi-notif-item { display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border-bottom:1px solid #E3F1D3; color:var(--cengi-ink, #1F2326); text-decoration:none; font-size:13px; }
.cengi-notif-item:hover { background:#F7F8F8; }
.cengi-notif-item b { display:block; font-weight:600; }
.cengi-notif-item small { display:block; color:var(--cengi-muted, #636A6F); font-size:12px; margin-top:2px; }
.cengi-notif-num { flex:none; min-width:30px; height:30px; padding:0 6px; border-radius:8px; background:#E3F1D3; color:#30353A; font-weight:700; line-height:30px; text-align:center; }
.cengi-notif-item.is-alerta .cengi-notif-num { background:#FFD9BF; color:#B34B00; }
.cengi-notif-empty { margin:0; padding:18px 14px; color:var(--cengi-muted, #636A6F); font-size:13px; text-align:center; }
</style>
        <?php
    }
}

if (!function_exists('lab_shell_close')) {
    /**
     * lab_shell_content_close() + cierre de <body></html>. Usar solo en
     * paginas que abrieron el documento con lab_shell_head().
     */
    function lab_shell_close(): void
    {
        lab_shell_content_close();
        ?>
</body>
</html>
        <?php
    }
}
