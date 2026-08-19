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
 *   lab_shell_head('Titulo de pagina', 'Subtitulo', ['../css/mi_vista.css']);
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
     *     Control de calidad, Informes, Firma electronica, Documentos (PDF).
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
        $canFirma = lab_can('laboratorio.firma.gestionar');
        $canDocumentos = lab_can('laboratorio.documentos.ver');
        $canCatalogoAnalisis = lab_can('laboratorio.catalogo_analisis.ver');
        $canUsuarios = lab_can('laboratorio.usuarios.gestionar') && is_file(__DIR__ . '/../usuarios.php');

        $badges = lab_shell_nav_badges();

        $groups = [];

        // ---- Flujo operativo ----------------------------------------
        $flujo = [];
        if ($canDashboard) {
            $flujo[] = ['href' => 'view/dashboard.php', 'icon' => 'monitoring', 'label' => 'Dashboard', 'match' => ['dashboard.php']];
        }
        if ($canRecepcion) {
            $flujo[] = ['href' => 'index.php', 'icon' => 'inbox', 'label' => 'Recepcion', 'match' => ['index.php']];
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
                'match' => ['solicitudes_pendientes_tecnico.php'], 'badge' => $badges['bandeja'] ?? null,
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
            $calidad[] = ['href' => 'view/informes_view.php', 'icon' => 'summarize', 'label' => 'Informes', 'match' => ['informes_view.php']];
        }
        if ($canFirma && is_file(__DIR__ . '/../view/firma_electronica_view.php')) {
            $calidad[] = ['href' => 'view/firma_electronica_view.php', 'icon' => 'draw', 'label' => 'Firma electronica', 'match' => ['firma_electronica_view.php']];
        }
        if ($canDocumentos && is_file(__DIR__ . '/../view/documentos_view.php')) {
            $calidad[] = ['href' => 'view/documentos_view.php', 'icon' => 'description', 'label' => 'Documentos (PDF)', 'match' => ['documentos_view.php']];
        }
        if ($calidad) {
            $groups['Calidad y entrega'] = $calidad;
        }

        // ---- Sistema -----------------------------------------------------
        $sistema = [];
        if ($canCatalogoAnalisis) {
            $sistema[] = ['href' => 'catalogo_analisis.php', 'icon' => 'table_view', 'label' => 'Catalogo de analisis', 'match' => ['catalogo_analisis.php', 'catalogo_muestras.php']];
        }
        if ($canUsuarios) {
            $sistema[] = ['href' => 'usuarios.php', 'icon' => 'group', 'label' => 'Administracion', 'match' => ['usuarios.php', 'usuarios_crear.php', 'usuarios_editar.php']];
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
     *                         correcto por el llamador, ej. '../css/dashboard.css').
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
<link rel="stylesheet" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>css/lab_shell.css?v=1">
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
            <strong>SIGELAB</strong>
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
     * traen su propio cierre de documento, ej. usuarios.php, catalogo_*.php).
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
</script>
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
