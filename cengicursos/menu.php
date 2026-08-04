<?php
require_once __DIR__ . '/revisar_permisos.php';

function cengi_pagina_titulo($currentPage, $esEstudiante)
{
    $titulos = [
        'index.php' => ['Dashboard general', 'Resumen del modulo de capacitacion'],
        'ver_cursos.php' => [$esEstudiante ? 'Mis cursos' : 'Cursos', $esEstudiante ? 'Consulta tus cursos asignados y tu nota' : 'Planifica, publica y administra la oferta de capacitacion'],
        'agregar_cursos.php' => ['Nuevo curso', 'Registra un curso dentro de una categoria e ingenio'],
        'modificar_cursos.php' => ['Editar curso', 'Actualiza los datos de un curso existente'],
        'ver_categorias.php' => ['Categorias de curso', 'Clasificacion de la oferta de capacitacion'],
        'agregar_categorias.php' => ['Nueva categoria', 'Registra una categoria de curso'],
        'ver_participante_curso.php' => ['Seguimiento por curso', 'Asistencia, evaluaciones, contenido y diplomas por curso'],
        'participantes.php' => ['Participantes por curso', 'Asistencia, evaluaciones y diplomas'],
        'carga_participantes.php' => ['Carga masiva CSV', 'Resultado de la importacion de participantes'],
        'solicitudes.php' => ['Inscripciones', 'Solicitudes pendientes de validacion'],
        'editar_solicitud.php' => ['Editar solicitud', 'Actualiza una solicitud pendiente'],
        'ver_ingenios.php' => ['Ingenios', 'Directorio base de ingenios afiliados'],
        'agregar_ingenios.php' => ['Nuevo ingenio', 'Registra un ingenio en el directorio'],
        'ver_usuarios.php' => ['Usuarios', 'Cuentas con acceso al modulo de cursos'],
        'directorio_participantes.php' => ['Directorio de participantes', 'Vista agregada por persona: cursos, diplomas y evaluacion'],
        'seguimiento_ingenio.php' => ['Seguimiento por ingenio', 'Ficha institucional de capacitacion por ingenio'],
        'dashboard_ingenio.php' => ['Mi ingenio', 'Participantes, cursos e historial de capacitacion de tu ingenio'],
        'organizaciones.php' => ['Organizaciones', 'Ingenios, universidades e instituciones técnicas asociadas'],
        'instructores.php' => ['Instructores', 'Directorio de instructores e informe por curso/diplomado'],
        'eventos_qr.php' => ['Control QR eventos', 'Eventos con registro de ingreso por codigo QR'],
        'diplomas.php' => ['Certificacion', 'Generacion y carga de diplomas de curso y de evento'],
        'roles.php' => ['Roles y permisos', 'Perfiles de acceso e integraciones futuras'],
    ];

    return $titulos[$currentPage] ?? ['Cengicursos', 'Formacion y conocimiento'];
}

function menu_render()
{
    $puedeGestionar = cengi_puede_gestionar();
    $puedeVerIngenios = cengi_puede_ver_ingenios();
    $puedeVerParticipantes = cengi_puede_ver_participantes();
    $puedeVerSolicitudes = cengi_puede_ver_solicitudes();
    $puedeVerOrganizaciones = cengi_puede_ver_organizaciones();
    $puedeVerInstructores = cengi_puede_ver_instructores();
    $puedeVerEventos = cengi_puede_ver_eventos();
    $puedeVerDiplomas = cengi_puede_ver_diplomas();
    $puedeVerRoles = cengi_puede_ver_roles();
    $puedeCalificar = cengi_puede_calificar();
    $puedeVerDashboardIngenio = cengi_puede_ver_dashboard_ingenio();
    $esEstudiante = cengi_es_estudiante();

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $currentPage = basename($requestPath ?: 'index.php');
    $isActive = static function (array $pages) use ($currentPage) {
        return in_array($currentPage, $pages, true) ? ' is-active' : '';
    };

    [$tituloPagina, $subtituloPagina] = cengi_pagina_titulo($currentPage, $esEstudiante);

    $nombreUsuario = trim((string) ($_SESSION['usuario'] ?? 'Usuario'));
    $rolUsuario = trim((string) ($_SESSION['rol'] ?? ''));
    $ingenioUsuario = trim((string) ($_SESSION['ingenio_nombre'] ?? ''));
    $iniciales = '';
    foreach (preg_split('/\s+/', $nombreUsuario, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($iniciales, 'UTF-8') >= 2) {
            break;
        }
    }
    $iniciales = $iniciales !== '' ? $iniciales : 'U';
    ?>
<style>
    #cengiPrimaryNav a[href='ver_categorias.php'],
    #cengiPrimaryNav a[href='exportaringenios.php'],
    #cengiPrimaryNav a[href='exportarcursos.php'],
    #cengiPrimaryNav a[href='ver_ingenios.php'] {
        display: none;
    }
</style>
<aside class="cengi-sidebar" id="cengiSidebar">
    <a class="cengi-brand" href="index.php" aria-label="Ir al inicio de Cengicursos">
        <span class="cengi-brand-mark" aria-hidden="true">
            <span class="glyphicon glyphicon-leaf"></span>
        </span>
        <span class="cengi-brand-copy">
            <strong>SIGEC</strong>
            <small>CENGICAÑA</small>
        </span>
    </a>

    <nav class="cengi-sidebar-nav" id="cengiPrimaryNav" aria-label="Navegacion principal">
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">General</div>
            <a href="index.php" class="cengi-nav-item<?php echo $isActive(['index.php']); ?>">
                <span class="glyphicon glyphicon-th-large"></span>
                <span>Dashboard</span>
            </a>
        </div>

        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Capacitacion</div>
            <a href="ver_cursos.php" class="cengi-nav-item<?php echo $isActive(['ver_cursos.php', 'agregar_cursos.php', 'modificar_cursos.php']); ?>">
                <span class="glyphicon glyphicon-education"></span>
                <span><?php echo $esEstudiante ? 'Mis cursos' : 'Cursos'; ?></span>
            </a>
            <?php if ($puedeGestionar): ?>
                <a href="ver_categorias.php" class="cengi-nav-item<?php echo $isActive(['ver_categorias.php', 'agregar_categorias.php']); ?>">
                    <span class="glyphicon glyphicon-tags"></span>
                    <span>Categorias</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerSolicitudes): ?>
                <a href="solicitudes.php" class="cengi-nav-item<?php echo $isActive(['solicitudes.php', 'editar_solicitud.php']); ?>">
                    <span class="glyphicon glyphicon-inbox"></span>
                    <span>Inscripciones</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerParticipantes): ?>
                <a href="participantes.php" class="cengi-nav-item<?php echo $isActive(['participantes.php', 'ver_participantes.php']); ?>">
                    <span class="glyphicon glyphicon-user"></span>
                    <span>Participantes</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeCalificar || $puedeGestionar): ?>
                <a href="ver_participante_curso.php" class="cengi-nav-item<?php echo $isActive(['ver_participante_curso.php']); ?>">
                    <span class="glyphicon glyphicon-stats"></span>
                    <span>Seguimiento por curso</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerParticipantes): ?>
                <a href="directorio_participantes.php" class="cengi-nav-item<?php echo $isActive(['directorio_participantes.php']); ?>">
                    <span class="glyphicon glyphicon-list-alt"></span>
                    <span>Directorio de participantes</span>
                </a>
            <?php endif; ?>
        </div>

        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Reportes</div>
            <a href="seguimiento_ingenio.php" class="cengi-nav-item<?php echo $isActive(['seguimiento_ingenio.php']); ?>">
                <span class="glyphicon glyphicon-tree-deciduous"></span>
                <span>Seguimiento por ingenio</span>
            </a>
            <?php if ($puedeVerDashboardIngenio): ?>
                <a href="dashboard_ingenio.php" class="cengi-nav-item<?php echo $isActive(['dashboard_ingenio.php']); ?>">
                    <span class="glyphicon glyphicon-dashboard"></span>
                    <span>Mi ingenio</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeGestionar): ?>
                <a href="exportaringenios.php" class="cengi-nav-item">
                    <span class="glyphicon glyphicon-save-file"></span>
                    <span>Reporte por ingenio</span>
                </a>
                <a href="exportarcursos.php" class="cengi-nav-item">
                    <span class="glyphicon glyphicon-save-file"></span>
                    <span>Reporte por curso</span>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($puedeVerOrganizaciones || $puedeVerInstructores || $puedeVerIngenios): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Comunidad</div>
            <?php if ($puedeVerOrganizaciones): ?>
                <a href="organizaciones.php" class="cengi-nav-item<?php echo $isActive(['organizaciones.php']); ?>">
                    <span class="glyphicon glyphicon-briefcase"></span>
                    <span>Organizaciones</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerInstructores): ?>
                <a href="instructores.php" class="cengi-nav-item<?php echo $isActive(['instructores.php']); ?>">
                    <span class="glyphicon glyphicon-record"></span>
                    <span>Instructores</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerIngenios): ?>
                <a href="ver_ingenios.php" class="cengi-nav-item<?php echo $isActive(['ver_ingenios.php', 'agregar_ingenios.php', 'modificar_ingenios.php']); ?>">
                    <span class="glyphicon glyphicon-globe"></span>
                    <span>Ingenios</span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($puedeVerEventos || $puedeVerDiplomas): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Eventos y certificacion</div>
            <?php if ($puedeVerEventos): ?>
                <a href="eventos_qr.php" class="cengi-nav-item<?php echo $isActive(['eventos_qr.php']); ?>">
                    <span class="glyphicon glyphicon-qrcode"></span>
                    <span>Control QR eventos</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerDiplomas): ?>
                <a href="diplomas.php" class="cengi-nav-item<?php echo $isActive(['diplomas.php']); ?>">
                    <span class="glyphicon glyphicon-certificate"></span>
                    <span>Certificacion</span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($puedeGestionar || $puedeVerRoles): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Sistema</div>
            <?php if ($puedeGestionar): ?>
                <a href="participantes.php" class="cengi-nav-item">
                    <span class="glyphicon glyphicon-cloud-upload"></span>
                    <span>Carga masiva CSV</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerRoles): ?>
                <a href="roles.php" class="cengi-nav-item<?php echo $isActive(['roles.php']); ?>">
                    <span class="glyphicon glyphicon-lock"></span>
                    <span>Roles y permisos</span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </nav>

    <div class="cengi-sidebar-foot">
        <a href="logout.php?act=logout" class="cengi-nav-item cengi-logout-link">
            <span class="glyphicon glyphicon-log-out"></span>
            <span>Salir</span>
        </a>
        <div class="cengi-sidebar-version">SIGEC &middot; Cengicursos &middot; Zafra 2025&ndash;2026</div>
    </div>
</aside>

<header class="cengi-topbar">
    <button type="button" class="cengi-menu-toggle" id="cengiMenuToggle"
            aria-label="Abrir menu" aria-expanded="false" aria-controls="cengiSidebar">
        <span></span><span></span><span></span>
    </button>
    <div class="cengi-topbar-titles">
        <div class="cengi-topbar-title"><?php echo htmlspecialchars($tituloPagina, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="cengi-topbar-sub"><?php echo htmlspecialchars($subtituloPagina, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <div class="cengi-topbar-right">
        <?php if ($rolUsuario !== ''): ?>
            <span class="cengi-pill"><?php echo htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <div class="cengi-userbox">
            <div class="cengi-avatar"><?php echo htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8'); ?></div>
            <div>
                <div class="cengi-userbox-u1"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="cengi-userbox-u2"><?php echo htmlspecialchars($ingenioUsuario !== '' ? $ingenioUsuario : $rolUsuario, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </div>
</header>

<script src="js/cengi-navigation.js"></script>
<script>
(function () {
    var sidebar = document.getElementById('cengiSidebar');
    var toggle = document.getElementById('cengiMenuToggle');
    var nav = document.getElementById('cengiPrimaryNav');

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
