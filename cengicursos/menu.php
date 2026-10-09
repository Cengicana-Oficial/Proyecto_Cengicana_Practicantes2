<?php
require_once __DIR__ . '/revisar_permisos.php';

function cengi_pagina_titulo($currentPage, $esEstudiante)
{
    $titulos = [
        'index.php' => ['Dashboard general', 'Resumen del modulo de capacitacion'],
        'ver_cursos.php' => [$esEstudiante ? 'Mis cursos' : 'Cursos', $esEstudiante ? 'Consulta tus cursos asignados y tu nota' : 'Planifica, publica y administra la oferta de capacitacion'],
        'agregar_cursos.php' => ['Nuevo curso', 'Registra un curso dentro de una categoria e ingenio'],
        'modificar_cursos.php' => ['Editar curso', 'Actualiza los datos de un curso existente'],
        'planificacion_cursos.php' => ['Planificación de cursos', 'Oferta del año: al aprobar un plan se crea el curso'],
        'calendario_cursos.php' => ['Calendario general', 'Inicios, cierres y sesiones del mes; cupos de los cursos por iniciar'],
        'programa_sesiones.php' => ['Programa de sesiones', 'Sesiones por curso, instructores, soporte técnico y conflictos'],
        'pago_instructores.php' => ['Pago a instructores', 'Liquidación mensual de sesiones impartidas para Administración'],
        'bitacora_cambios.php' => ['Bitácora de cambios', 'Historial de cambios y avisos a los inscritos'],
        'carga_masiva_planificacion.php' => ['Carga masiva', 'Planes, sesiones y tarifas desde Excel o CSV'],
        'comunicaciones.php' => ['Comunicaciones','Correos enviados, recordatorios automáticos y envíos programados'],
        'ver_categorias.php' => ['Categorias de curso', 'Clasificacion de la oferta de capacitacion'],
        'agregar_categorias.php' => ['Nueva categoria', 'Registra una categoria de curso'],
        'ver_participante_curso.php' => ['Seguimiento por curso', 'Asistencia, evaluaciones, contenido y diplomas por curso'],
        'participantes.php' => ['Participantes por curso', 'Asistencia, evaluaciones y diplomas'],
        'carga_participantes.php' => ['Carga masiva CSV', 'Resultado de la importacion de participantes'],
        'solicitudes.php' => ['Solicitudes directas a cursos', 'Solicitudes a cursos creados sin planificación'],
        'inscripciones.php' => ['Inscripciones', 'Cupo de cada convocatoria, asignación al curso y lista de espera'],
        'pagos_inscripcion.php' => ['Pagos de inscripción', 'Validación del ingenio o del comprobante y seguimiento del cobro'],
        'editar_solicitud.php' => ['Editar solicitud', 'Actualiza una solicitud pendiente'],
        'ver_ingenios.php' => ['Ingenios', 'Directorio base de ingenios afiliados'],
        'agregar_ingenios.php' => ['Nuevo ingenio', 'Registra un ingenio en el directorio'],
        'areas_tecnicas.php' => ['Áreas técnicas', 'Catálogo de temas para clasificar los cursos'],
        'ver_usuarios.php' => ['Usuarios', 'Cuentas con acceso al modulo de cursos'],
        'directorio_participantes.php' => ['Directorio de participantes', 'Vista agregada por persona: cursos, diplomas y evaluacion'],
        'dashboard_ingenio.php' => $esEstudiante || !cengi_ve_todo_por_rol_o_ingenio() ? ['Mi ingenio', 'Participantes, cursos e historial de capacitación de tu ingenio'] : ['Seguimiento por ingenio', 'Participantes, cursos, resultados e historial de capacitación por ingenio'],
        'informe_gestion.php' => ['Informe de gestión', 'Indicadores de capacitación y eventos por período para la Dirección'],
        'organizaciones.php' => ['Organizaciones', 'Ingenios, universidades e instituciones técnicas asociadas'],
        'instructores.php' => ['Instructores', 'Directorio de instructores e informe por curso/diplomado'],
        'ficha_instructor.php' => ['Ficha del instructor', 'Documentos, sesiones, evaluaciones y pagos'],
        'base_instructores.php' => ['Base de datos de instructores', 'Documentación, evaluación y sesiones de cada instructor'],
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
    $esAdmin = cengi_es_admin();
    $puedeVerEventos = cengi_puede_ver_eventos();
    // Administracion: pagos de inscripcion (inscripcion_helpers.php) y pestañas de pagos/cobros de eventos_qr.php.
    $puedeVerPagosInscripcion = function_exists('cengi_ins_puede_ver_pagos')
        ? cengi_ins_puede_ver_pagos()
        : ($esAdmin || cengi_tiene_permiso('validar_pagos_cengi') || cengi_tiene_permiso('ver_pagos_cengi') || cengi_es_administrador_ingenio());
    $puedeVerPagosEventos = $esAdmin
        || cengi_tiene_permiso('validar_pagos_cengi')
        || (cengi_tiene_permiso('ver_pagos_cengi') && $puedeVerEventos);
    $puedeVerReportes = cengi_puede_ver_reportes();
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
    $tabEventos = (string) ($_GET['tab'] ?? '');
    $enPagosEventos = $currentPage === 'eventos_qr.php' && in_array($tabEventos, ['pagos', 'cobros'], true);

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
<aside class="cengi-sidebar" id="cengiSidebar">
    <a class="cengi-brand" href="index.php" aria-label="Ir al inicio de Cengicursos">
        <img class="cengi-brand-logo" src="img/logo-comite-capacitacion.png"
            alt="Comité de Capacitación CENGICAÑA">
    </a>

    <nav class="cengi-sidebar-nav" id="cengiPrimaryNav" aria-label="Navegacion principal">
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">General</div>
            <a href="index.php" class="cengi-nav-item<?php echo $isActive(['index.php']); ?>">
                <span class="glyphicon glyphicon-th-large"></span>
                <span>Dashboard</span>
            </a>
        </div>

        <?php
        // Capacitacion: un solo grupo en el orden del ciclo del curso
        // (planificar -> programar -> inscribir -> ejecutar -> certificar), y al final el apoyo.
        // Cada entrada: pagina => [icono, texto, paginas que la marcan activa, visible, etapa].
        // Entre etapas se dibuja un separador sin titulo (solo si ambas etapas tienen opciones visibles).
        $seccionesCapacitacion = [
            'planificacion_cursos.php' => ['calendar', 'Planificación de cursos', ['planificacion_cursos.php'], $puedeGestionar, 'planificar'],
            'ver_cursos.php' => ['education', $esEstudiante ? 'Mis cursos' : 'Cursos', ['ver_cursos.php', 'agregar_cursos.php', 'modificar_cursos.php'], true, 'planificar'],
            'calendario_cursos.php' => ['th', 'Calendario general', ['calendario_cursos.php'], $puedeGestionar, 'planificar'],
            'programa_sesiones.php' => ['list', 'Programa de sesiones', ['programa_sesiones.php'], $puedeGestionar, 'planificar'],
            'inscripciones.php' => ['inbox', 'Inscripciones', ['inscripciones.php', 'solicitudes.php', 'editar_solicitud.php'], $puedeVerSolicitudes, 'ejecutar'],
            'participantes.php' => ['user', 'Participantes', ['participantes.php', 'ver_participantes.php', 'carga_participantes.php'], $puedeVerParticipantes, 'ejecutar'],
            'ver_participante_curso.php' => ['stats', 'Seguimiento por curso', ['ver_participante_curso.php'], $puedeCalificar || $puedeGestionar, 'ejecutar'],
            'diplomas.php' => ['certificate', 'Certificación', ['diplomas.php'], $puedeVerDiplomas, 'ejecutar'],
            'bitacora_cambios.php' => ['time', 'Bitácora de cambios', ['bitacora_cambios.php'], $puedeGestionar, 'apoyo'],
            'comunicaciones.php' => ['envelope', 'Comunicaciones', ['comunicaciones.php'], $puedeGestionar, 'apoyo'],
            'directorio_participantes.php' => ['list-alt', 'Directorio de participantes', ['directorio_participantes.php'], $puedeVerParticipantes, 'apoyo'],
        ];
        ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Capacitación</div>
            <?php $etapaAnterior = null; ?>
            <?php foreach ($seccionesCapacitacion as $paginaCap => [$iconoCap, $textoCap, $activasCap, $visibleCap, $etapaCap]): ?>
                <?php if (!$visibleCap) { continue; } ?>
                <?php if ($etapaAnterior !== null && $etapaAnterior !== $etapaCap): ?>
                    <div class="cengi-nav-sep" role="separator" aria-hidden="true"></div>
                <?php endif; ?>
                <?php $etapaAnterior = $etapaCap; ?>
                <a href="<?php echo $paginaCap; ?>" class="cengi-nav-item<?php echo $isActive($activasCap); ?>">
                    <span class="glyphicon glyphicon-<?php echo $iconoCap; ?>"></span>
                    <span><?php echo $textoCap; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php // Eventos: presenciales de 1 a 3 dias (inscripcion, gafetes, ingreso QR y evaluacion). ?>
        <?php if ($puedeVerEventos): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Eventos</div>
            <a href="eventos_qr.php" class="cengi-nav-item<?php echo ($currentPage === 'eventos_qr.php' && !($enPagosEventos && $puedeVerPagosEventos)) ? ' is-active' : ''; ?>">
                <span class="glyphicon glyphicon-qrcode"></span>
                <span>Control QR eventos</span>
            </a>
        </div>
        <?php endif; ?>

        <?php // Administracion: verificacion de pagos de cursos y eventos, y liquidacion a instructores. ?>
        <?php if ($puedeVerPagosInscripcion || $puedeVerPagosEventos || $esAdmin): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Administración</div>
            <?php if ($puedeVerPagosInscripcion): ?>
                <a href="pagos_inscripcion.php" class="cengi-nav-item<?php echo $isActive(['pagos_inscripcion.php', 'proforma.php']); ?>">
                    <span class="glyphicon glyphicon-credit-card"></span>
                    <span>Pagos de inscripción</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerPagosEventos): ?>
                <a href="eventos_qr.php?tab=pagos" class="cengi-nav-item<?php echo ($enPagosEventos && $tabEventos === 'pagos') ? ' is-active' : ''; ?>">
                    <span class="glyphicon glyphicon-check"></span>
                    <span>Pagos de eventos</span>
                </a>
                <a href="eventos_qr.php?tab=cobros" class="cengi-nav-item<?php echo ($enPagosEventos && $tabEventos === 'cobros') ? ' is-active' : ''; ?>">
                    <span class="glyphicon glyphicon-usd"></span>
                    <span>Cobros por evento</span>
                </a>
            <?php endif; ?>
            <?php if ($esAdmin): ?>
                <a href="pago_instructores.php" class="cengi-nav-item<?php echo $isActive(['pago_instructores.php']); ?>">
                    <span class="glyphicon glyphicon-briefcase"></span>
                    <span>Pago a instructores</span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php // Reportes: lo que consultan los ingenios, la Direccion y las instituciones de interes. ?>
        <?php if ($puedeVerDashboardIngenio || $puedeVerReportes || $esAdmin): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Reportes</div>
            <?php if ($puedeVerDashboardIngenio): ?>
                <?php // Una sola seccion: el administrador elige el ingenio; los demas ven el suyo. ?>
                <a href="dashboard_ingenio.php" class="cengi-nav-item<?php echo $isActive(['dashboard_ingenio.php', 'seguimiento_ingenio.php']); ?>">
                    <span class="glyphicon glyphicon-dashboard"></span>
                    <span><?php echo cengi_ve_todo_por_rol_o_ingenio() ? 'Seguimiento por ingenio' : 'Mi ingenio'; ?></span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerReportes): ?>
                <a href="informe_gestion.php" class="cengi-nav-item<?php echo $isActive(['informe_gestion.php']); ?>">
                    <span class="glyphicon glyphicon-stats"></span>
                    <span>Informe de gestión</span>
                </a>
            <?php endif; ?>
            <?php if ($esAdmin): ?>
                <?php // Descargas Excel con todos los ingenios: solo administrador (exportaringenios.php lo exige). ?>
                <a href="exportaringenios.php" class="cengi-nav-item">
                    <span class="glyphicon glyphicon-save-file"></span>
                    <span>Excel por ingenio</span>
                </a>
                <a href="exportarcursos.php" class="cengi-nav-item">
                    <span class="glyphicon glyphicon-save-file"></span>
                    <span>Excel por curso</span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php // Catalogos: datos base que casi no cambian. ?>
        <?php if ($puedeVerOrganizaciones || $puedeVerInstructores || $puedeVerIngenios || $puedeGestionar): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Catálogos</div>
            <?php if ($puedeVerOrganizaciones): ?>
                <a href="organizaciones.php" class="cengi-nav-item<?php echo $isActive(['organizaciones.php']); ?>">
                    <span class="glyphicon glyphicon-briefcase"></span>
                    <span>Organizaciones</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerInstructores): ?>
                <a href="instructores.php" class="cengi-nav-item<?php echo $isActive(['instructores.php', 'ficha_instructor.php', 'base_instructores.php']); ?>">
                    <span class="glyphicon glyphicon-record"></span>
                    <span>Instructores</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeVerIngenios): ?>
                <a href="ver_ingenios.php" class="cengi-nav-item<?php echo $isActive(['ver_ingenios.php', 'agregar_ingenios.php', 'modificar_ingenios.php']); ?>">
                    <span class="glyphicon glyphicon-globe"></span>
                    <span>Ingenios e instituciones</span>
                </a>
            <?php endif; ?>
            <?php if ($puedeGestionar): ?>
                <a href="areas_tecnicas.php" class="cengi-nav-item<?php echo $isActive(['areas_tecnicas.php']); ?>">
                    <span class="glyphicon glyphicon-wrench"></span>
                    <span>Áreas técnicas</span>
                </a>
                <a href="ver_categorias.php" class="cengi-nav-item<?php echo $isActive(['ver_categorias.php', 'agregar_categorias.php', 'modificar_categorias.php']); ?>">
                    <span class="glyphicon glyphicon-tags"></span>
                    <span>Categorías</span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($esAdmin || $puedeVerRoles): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Sistema</div>
            <?php if ($esAdmin): ?>
                <a href="carga_masiva_planificacion.php" class="cengi-nav-item<?php echo $isActive(['carga_masiva_planificacion.php']); ?>">
                    <span class="glyphicon glyphicon-cloud-upload"></span>
                    <span>Carga masiva</span>
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
        <a href="../login/Menu.php" class="cengi-nav-item cengi-menu-link">
            <span class="glyphicon glyphicon-home"></span>
            <span>Menu principal</span>
        </a>
        <a href="logout.php?act=logout" class="cengi-nav-item cengi-logout-link">
            <span class="glyphicon glyphicon-log-out"></span>
            <span>Salir</span>
        </a>
        <div class="cengi-sidebar-version">Cengicursos &middot; Año <?php echo date('Y'); ?></div>
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
