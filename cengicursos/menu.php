<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/notificaciones_helpers.php';

function cengi_pagina_titulo($currentPage, $esEstudiante)
{
    $titulos = [
        'index.php' => ['Dashboard general', 'Resumen del modulo de capacitacion'],
        'cursos_proximos.php' => ['Próximos cursos', 'Oferta aprobada por iniciar para tu ingenio'],
        'ver_cursos.php' => [$esEstudiante ? 'Mis cursos' : 'Cursos', $esEstudiante ? 'Consulta tus cursos asignados y tu nota' : 'Planifica, publica y administra la oferta de capacitacion'],
        'agregar_cursos.php' => ['Nuevo curso', 'Registra un curso dentro de una categoria e ingenio'],
        'modificar_cursos.php' => ['Editar curso', 'Actualiza los datos de un curso existente'],
        'planificacion_cursos.php' => ['Planificación de cursos', 'Oferta del año: al aprobar un plan se crea el curso'],
        'calendario_cursos.php' => ['Calendario general', 'Inicios, cierres y sesiones del mes; cupos de los cursos por iniciar'],
        'programa_sesiones.php' => ['Programa de sesiones', 'Sesiones por curso, instructores, soporte técnico y conflictos'],
        'pago_instructores.php' => ['Pago a instructores', 'Liquidación mensual de sesiones impartidas para Administración'],
        'bitacora_cambios.php' => ['Bitácora de cambios', 'Qué se cambió, quién y cuándo: por curso y del sistema'],
        'carga_masiva_planificacion.php' => ['Carga masiva', 'Planes, sesiones y tarifas desde Excel o CSV'],
        'comunicaciones.php' => ['Comunicaciones','Correos enviados, recordatorios automáticos y envíos programados'],
        'ver_categorias.php' => ['Categorias de curso', 'Clasificacion de la oferta de capacitacion'],
        'agregar_categorias.php' => ['Nueva categoria', 'Registra una categoria de curso'],
        'ver_participante_curso.php' => ['Seguimiento por curso', 'Asistencia, evaluaciones, contenido y diplomas por curso'],
        'constancia_notas_config.php' => ['Constancia de notas', 'Firmantes, imágenes de firma, lugar y pie de la constancia'],
        'participantes.php' => ['Seguimiento por curso', 'Participantes del curso: consulta y retirados'],
        'carga_participantes.php' => ['Carga masiva CSV', 'Resultado de la importacion de participantes'],
        'solicitudes.php' => ['Solicitudes directas a cursos', 'Solicitudes a cursos creados sin planificación'],
        'inscripciones.php' => ['Inscripciones y pagos', 'Registro por curso, validación, asignación y seguimiento del cobro'],
        'proforma.php' => ['Proforma', 'Cotización de inscripción para el ingenio o la institución'],
        'pagos_inscripcion.php' => ['Inscripciones y pagos', 'Validación del ingenio o del comprobante y seguimiento del cobro'],
        'editar_solicitud.php' => ['Editar solicitud', 'Actualiza una solicitud pendiente'],
        'ver_ingenios.php' => ['Ingenios e instituciones', 'Catálogo de ingenios e instituciones: participación y contacto'],
        'agregar_ingenios.php' => ['Nuevo ingenio', 'Registra un ingenio en el directorio'],
        'areas_tecnicas.php' => ['Áreas técnicas', 'Catálogo de temas para clasificar los cursos'],
        'ver_usuarios.php' => ['Usuarios', 'Quién entra a Cursos, con qué rol y de qué ingenio'],
        'directorio_participantes.php' => ['Directorio de participantes', 'Vista agregada por persona: cursos, diplomas y evaluacion'],
        'dashboard_ingenio.php' => $esEstudiante || !cengi_ve_todo_por_rol_o_ingenio() ? ['Mi ingenio', 'Participantes, cursos e historial de capacitación de tu ingenio'] : ['Seguimiento por ingenio', 'Participantes, cursos, resultados e historial de capacitación por ingenio'],
        'informe_gestion.php' => ['Informe de gestión', 'Indicadores de capacitación y eventos por período para la Dirección'],
        'organizaciones.php' => ['Organizaciones', 'Ingenios, universidades e instituciones técnicas asociadas'],
        'instructores.php' => ['Instructores', 'Directorio de instructores e informe por curso/diplomado'],
        'ficha_instructor.php' => ['Ficha del instructor', 'Documentos, sesiones, evaluaciones y pagos'],
        'base_instructores.php' => ['Base de datos de instructores', 'Documentación, evaluación y sesiones de cada instructor'],
        'necesidades_capacitacion.php' => ['Necesidades de capacitación', 'Lo que piden los participantes en la evaluación del instructor, para planificar el próximo año'],
        'informe_instructores_resumen.php' => ['Informe consolidado por instructor', 'Asignaciones, evaluación del instructor y satisfacción de los participantes'],
        'carga_cv_instructores.php' => ['Carga masiva de CV digital', 'Sube el Excel, revisa los datos y confirma los CV que deseas registrar'],
        'mi_perfil.php' => ['Mi perfil', 'Tu información, tu foto y tu historial de capacitación'],
        'eventos_qr.php' => ['Eventos y pagos', 'Inscripción, pagos, participación e ingreso QR'],
        'eventos_evaluacion.php' => ['Evaluación de eventos', 'Percepción de participantes, meta de respuesta, colaboración de empresas aliadas y temas de mejora'],
        'diplomas.php' => ['Certificacion', 'Generacion y carga de diplomas de curso y de evento'],
        'roles.php' => ['Roles y permisos', 'Qué puede hacer cada rol dentro de Cursos'],
        'mi_aula.php' => ['Mi aula', 'Cursos, sesiones, materiales y resultados'],
        'aula_gestion.php' => ['Gestión del aula', 'Evaluaciones, asistencia y recursos para estudiantes'],
        'aulas.php' => ['Aulas y estudiantes', 'Control general y vistas del estudiante'],
    ];

    return $titulos[$currentPage] ?? ['Cengicursos', 'Formacion y conocimiento'];
}

/**
 * Banner estándar de página (franja verde con la línea de colores de la marca).
 * Sin argumentos usa el título y subtítulo de cengi_pagina_titulo() y, como antetítulo,
 * el grupo del menú al que pertenece la página. $acciones es HTML ya escapado.
 */
function cengi_banner($titulo = null, $subtitulo = null, $antetitulo = null, $acciones = '')
{
    $paginaActual = cengi_nav_pagina_actual();
    $esEstudiante = cengi_es_estudiante();
    [$tituloDefecto, $subtituloDefecto] = cengi_pagina_titulo(strtok($paginaActual, '?'), $esEstudiante);
    if ($antetitulo === null) {
        $ubicacion = $esEstudiante ? null : cengi_nav_ubicar($paginaActual);
        $antetitulo = $ubicacion ? $ubicacion[1] : 'Cengicursos';
    }
    $h = static function ($texto) {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    };
    ?>
    <header class="cengi-banner">
        <div class="cengi-banner-text">
            <?php if ($antetitulo !== ''): ?><small class="cengi-banner-k"><?php echo $h($antetitulo); ?></small><?php endif; ?>
            <h1><?php echo $h($titulo ?? $tituloDefecto); ?></h1>
            <?php if (($subtitulo ?? $subtituloDefecto) !== ''): ?><p><?php echo $h($subtitulo ?? $subtituloDefecto); ?></p><?php endif; ?>
        </div>
        <?php if ($acciones !== ''): ?><div class="cengi-banner-acciones"><?php echo $acciones; ?></div><?php endif; ?>
    </header>
    <?php
}

/**
 * Mapa único de navegación del módulo. Lo usan el menú lateral, las barras de sección
 * (cengi_nav_barra) y la ruta del encabezado, para que los tres digan siempre lo mismo.
 *
 * Reglas de navegación:
 *  - Cada página pertenece a UN grupo del menú lateral (una sola ubicación).
 *  - La barra horizontal de una sección solo muestra páginas de ese mismo grupo:
 *    pasar de una pestaña a otra nunca cambia el grupo marcado en el menú.
 *  - Ir a otro grupo se hace desde el menú lateral o con un enlace de contexto dentro del
 *    contenido (p. ej. "Ir a Pagos de inscripción"); al llegar, el encabezado ofrece
 *    "Volver a …" para regresar a la página de origen.
 *
 * Cada opción: [página, icono, texto, páginas que la marcan activa, visible, en menú lateral].
 */
function cengi_nav_estructura()
{
    static $estructura = null;
    if ($estructura !== null) {
        return $estructura;
    }
    $puedeGestionar = cengi_puede_gestionar();
    $esAdmin = cengi_es_admin();
    $puedeCalificar = cengi_puede_calificar();
    $puedeVerEventos = cengi_puede_ver_eventos();
    $puedeVerPagosInscripcion = function_exists('cengi_ins_puede_ver_pagos')
        ? cengi_ins_puede_ver_pagos()
        : ($esAdmin || cengi_tiene_permiso('validar_pagos_cengi') || cengi_tiene_permiso('ver_pagos_cengi') || cengi_es_administrador_ingenio());
    $puedeVerPagosEventos = $esAdmin || cengi_tiene_permiso('validar_pagos_cengi') || (cengi_tiene_permiso('ver_pagos_cengi') && $puedeVerEventos);
    // Jefe de Transferencia de Tecnologia: valida y firma las liquidaciones de pago a instructores.
    $puedeFirmarLiquidaciones = $esAdmin || cengi_tiene_permiso('firmar_liquidaciones_cengi');
    $puedeVerParticipantes = cengi_puede_ver_participantes();
    $verTodo = cengi_ve_todo_por_rol_o_ingenio();
    $puedeVerInstructores = cengi_puede_ver_instructores();
    $puedeGestionarInstructores = cengi_puede_gestionar_instructores();
    $esAdminIngenio = cengi_es_administrador_ingenio();

    $estructura = [
        'general' => ['General', [
            ['index.php', 'th-large', 'Dashboard', ['index.php'], true, true],
        ]],
        // Ciclo del curso: planificar y programar (con su bitácora y avisos).
        'planificacion' => ['Planificación', [
            ['planificacion_cursos.php', 'calendar', 'Planificación de cursos', ['planificacion_cursos.php'], $puedeGestionar, true],
            ['calendario_cursos.php', 'th', 'Calendario general', ['calendario_cursos.php'], $puedeGestionar, true],
            ['programa_sesiones.php', 'list', 'Programa de sesiones', ['programa_sesiones.php'], $puedeGestionar, true],
            ['bitacora_cambios.php', 'time', 'Bitácora de cambios', ['bitacora_cambios.php'], $puedeGestionar, true],
            ['comunicaciones.php', 'envelope', 'Comunicaciones', ['comunicaciones.php'], $puedeGestionar, true],
            ['carga_masiva_planificacion.php', 'cloud-upload', 'Carga masiva', ['carga_masiva_planificacion.php'], $esAdmin, true],
        ]],
        // Ejecutar el curso: inscribir, dar seguimiento, aula y certificar.
        'cursos' => ['Cursos y participantes', [
            ['cursos_proximos.php', 'calendar', 'Próximos cursos', ['cursos_proximos.php'], cengi_puede_ver_solicitudes(), true],
            ['ver_cursos.php', 'education', 'Cursos', ['ver_cursos.php', 'agregar_cursos.php', 'modificar_cursos.php'], true, true],
            ['inscripciones.php', 'inbox', 'Inscripciones y pagos', ['inscripciones.php', 'pagos_inscripcion.php', 'proforma.php', 'inscripcion_detalle.php', 'inscripcion_preparar_cobro.php', 'solicitudes.php', 'editar_solicitud.php'], (cengi_puede_ver_solicitudes() || $puedeVerPagosInscripcion), true],
            ['solicitudes.php', 'list-alt', 'Solicitudes directas', ['solicitudes.php', 'editar_solicitud.php'], cengi_puede_ver_solicitudes(), false],
            // Seguimiento por curso reúne participantes, notas, diplomas, temario y materiales.
            // Quien solo consulta participantes (sin calificar) entra a la vista de consulta.
            [$puedeCalificar ? 'ver_participante_curso.php' : 'participantes.php', 'stats', 'Seguimiento por curso', ['ver_participante_curso.php', 'participantes.php', 'ver_participantes.php', 'carga_participantes.php', 'constancia_notas_config.php'], $puedeCalificar || $puedeVerParticipantes, true],
            ['aulas.php', 'blackboard', 'Aula virtual', ['aulas.php', 'aula_gestion.php'], $puedeGestionar || $puedeCalificar, true],
            ['diplomas.php', 'certificate', 'Certificación', ['diplomas.php'], cengi_puede_ver_diplomas(), true],
            ['directorio_participantes.php', 'list-alt', 'Directorio de participantes', ['directorio_participantes.php'], $puedeVerParticipantes, true],
        ]],
        // Eventos presenciales de 1 a 3 dias (inscripcion, gafetes, ingreso QR y evaluacion).
        'eventos' => ['Eventos', [
            ['eventos_qr.php', 'qrcode', 'Eventos y pagos', ['eventos_qr.php', 'eventos_qr.php?tab=pagos', 'eventos_qr.php?tab=cobros'], $puedeVerEventos || $puedeVerPagosEventos, true],
            ['eventos_evaluacion.php', 'star', 'Evaluación de eventos', ['eventos_evaluacion.php'], $puedeVerEventos, true],
        ]],
        // Verificacion de pagos de cursos y eventos, y liquidacion a instructores.
        'administracion' => ['Administración', [
            ['pagos_inscripcion.php', 'credit-card', 'Pagos de inscripción', ['pagos_inscripcion.php', 'proforma.php'], $puedeVerPagosInscripcion, false],
            ['eventos_qr.php?tab=pagos', 'check', 'Pagos de eventos', ['eventos_qr.php?tab=pagos'], $puedeVerPagosEventos, false],
            ['eventos_qr.php?tab=cobros', 'usd', 'Cobros por evento', ['eventos_qr.php?tab=cobros'], $puedeVerPagosEventos, false],
            ['pago_instructores.php', 'briefcase', 'Pago a instructores', ['pago_instructores.php'], $puedeFirmarLiquidaciones, true],
        ]],
        // Lo que consultan los ingenios, la Direccion y las instituciones de interes.
        'reportes' => ['Reportes', [
            ['dashboard_ingenio.php', 'dashboard', $verTodo ? 'Seguimiento por ingenio' : 'Mi ingenio', ['dashboard_ingenio.php', 'seguimiento_ingenio.php'], cengi_puede_ver_dashboard_ingenio(), true],
            ['informe_gestion.php', 'stats', 'Informe de gestión', ['informe_gestion.php'], cengi_puede_ver_reportes(), true],
            // Descargas Excel con todos los ingenios: solo administrador (exportaringenios.php lo exige).
            ['exportaringenios.php', 'save-file', 'Excel por ingenio', [], $esAdmin, true],
            ['exportarcursos.php', 'save-file', 'Excel por curso', [], $esAdmin, true],
        ]],
        // Instructores: directorio, expediente, evaluación y necesidades (módulo de trabajo, no catálogo).
        // El pago a instructores sigue en Administración.
        'instructores' => ['Instructores', [
            ['instructores.php', 'record', 'Directorio de instructores', ['instructores.php', 'ficha_instructor.php'], $puedeVerInstructores, true],
            ['base_instructores.php', 'folder-open', 'Base de datos y documentos', ['base_instructores.php'], $puedeVerInstructores, true],
            ['informe_instructores_resumen.php', 'stats', 'Evaluación de instructores', ['informe_instructores_resumen.php'], $puedeVerInstructores && !$esAdminIngenio, true],
            ['necesidades_capacitacion.php', 'comment', 'Necesidades de capacitación', ['necesidades_capacitacion.php'], $puedeGestionarInstructores && !$esAdminIngenio, true],
            ['carga_cv_instructores.php', 'upload', 'Carga masiva de CV', ['carga_cv_instructores.php'], $puedeGestionarInstructores, true],
        ]],
        // Datos base que casi no cambian.
        'catalogos' => ['Catálogos', [
            ['ver_ingenios.php', 'globe', 'Ingenios e instituciones', ['ver_ingenios.php', 'agregar_ingenios.php', 'modificar_ingenios.php', 'organizaciones.php'], cengi_puede_ver_ingenios(), true],
            ['areas_tecnicas.php', 'wrench', 'Áreas técnicas', ['areas_tecnicas.php'], $puedeGestionar, true],
            ['ver_categorias.php', 'tags', 'Categorías', ['ver_categorias.php', 'agregar_categorias.php', 'modificar_categorias.php'], $puedeGestionar, true],
        ]],
        'sistema' => ['Sistema', [
            ['ver_usuarios.php', 'user', 'Usuarios', ['ver_usuarios.php'], cengi_puede_ver_usuarios(), true],
            ['roles.php', 'lock', 'Roles y permisos', ['roles.php'], cengi_puede_ver_roles(), true],
        ]],
    ];
    return $estructura;
}

/**
 * Barras de sección: grupos de páginas hermanas que se recorren con pestañas.
 * Solo contienen páginas de un mismo grupo del menú lateral.
 */
const CENGI_NAV_BARRAS = [
    'planificacion' => ['planificacion_cursos.php', 'calendario_cursos.php', 'programa_sesiones.php', 'bitacora_cambios.php', 'comunicaciones.php', 'carga_masiva_planificacion.php'],
    'inscripciones' => ['inscripciones.php', 'solicitudes.php'],
    'pagos' => ['pago_instructores.php'],
    'eventos' => ['eventos_qr.php', 'eventos_evaluacion.php'],
    'instructores' => ['instructores.php', 'base_instructores.php', 'informe_instructores_resumen.php', 'necesidades_capacitacion.php', 'carga_cv_instructores.php'],
];

/** Clave de la página actual (eventos_qr.php con pestaña de pagos/cobros cuenta como página aparte). */
function cengi_nav_pagina_actual()
{
    $pagina = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php');
    $tab = (string) ($_GET['tab'] ?? '');
    return $pagina === 'eventos_qr.php' && in_array($tab, ['pagos', 'cobros'], true) ? $pagina . '?tab=' . $tab : $pagina;
}

/** Grupo y opción del menú a los que pertenece una página: [claveGrupo, nombreGrupo, opción] o null. */
function cengi_nav_ubicar($pagina)
{
    foreach (cengi_nav_estructura() as $clave => [$nombre, $opciones]) {
        foreach ($opciones as $op) {
            if ($op[5] && in_array($pagina, $op[3], true)) {
                return [$clave, $nombre, $op];
            }
        }
    }
    return null;
}

/** Opción del menú por su página exacta (incluye las que no se muestran en el menú lateral). */
function cengi_nav_opcion($pagina)
{
    foreach (cengi_nav_estructura() as [, $opciones]) {
        foreach ($opciones as $op) {
            if ($op[0] === $pagina) {
                return $op;
            }
        }
    }
    return null;
}

/**
 * Barra horizontal de la sección de $pagina (pestañas entre páginas hermanas del mismo grupo).
 * $insignias: [página => número] para mostrar un contador en la pestaña.
 * $enlaces: [página => href] para conservar filtros al cambiar de pestaña.
 */
function cengi_nav_barra($pagina, array $insignias = [], array $enlaces = [])
{
    $actual = cengi_nav_pagina_actual();
    foreach (CENGI_NAV_BARRAS as $clave => $paginas) {
        $opciones = [];
        $contiene = false;
        foreach ($paginas as $p) {
            $op = cengi_nav_opcion($p);
            if (!$op || !$op[4]) {
                continue;
            }
            $opciones[] = $op;
            $contiene = $contiene || in_array($pagina, $op[3], true) || $p === $pagina;
        }
        if (!$contiene || count($opciones) < 2) {
            continue;
        }
        // Activa: la de la página actual si está en la barra; si no, la indicada por la página.
        $activa = null;
        foreach ([$actual, $pagina] as $buscar) {
            foreach ($opciones as $op) {
                if ($op[0] === $buscar) {
                    $activa = $op[0];
                    break 2;
                }
            }
        }
        if ($activa === null) {
            foreach ($opciones as $op) {
                if (in_array($pagina, $op[3], true)) {
                    $activa = $op[0];
                    break;
                }
            }
        }
        $grupo = cengi_nav_ubicar($opciones[0][0]);
        echo '<nav class="pp-subnav cengi-nav-barra" aria-label="' . htmlspecialchars($grupo[1] ?? 'Sección', ENT_QUOTES, 'UTF-8') . '">';
        foreach ($opciones as [$href, $icono, $texto]) {
            $n = (int) ($insignias[$href] ?? 0);
            echo '<a href="' . htmlspecialchars($enlaces[$href] ?? $href, ENT_QUOTES, 'UTF-8') . '"' . ($href === $activa ? ' class="is-active" aria-current="page"' : '') . '>'
                . '<span class="glyphicon glyphicon-' . $icono . '"></span>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8')
                . ($n > 0 ? ' <b>' . $n . '</b>' : '') . '</a>';
        }
        echo '</nav>';
        return;
    }
}

/** Enlace "Volver a …" cuando se llegó desde otra sección del módulo (mismo sitio). */
function cengi_nav_volver($paginaActual, $esEstudiante)
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $partes = $ref !== '' ? parse_url($ref) : false;
    if (!$partes || empty($partes['host']) || strcasecmp($partes['host'], (string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) !== 0) {
        return null;
    }
    $ruta = (string) ($partes['path'] ?? '');
    if (strpos($ruta, '/cengicursos/') === false) {
        return null;
    }
    $origen = basename($ruta);
    parse_str((string) ($partes['query'] ?? ''), $q);
    $claveOrigen = $origen === 'eventos_qr.php' && in_array($q['tab'] ?? '', ['pagos', 'cobros'], true) ? $origen . '?tab=' . $q['tab'] : $origen;
    $base = strtok($paginaActual, '?');
    if ($origen === $base) {
        return null;
    }
    $grupoOrigen = cengi_nav_ubicar($claveOrigen);
    $grupoActual = cengi_nav_ubicar($paginaActual);
    // Solo entre grupos distintos: dentro de una sección ya están la barra y el menú.
    if (!$grupoOrigen || ($grupoActual && $grupoOrigen[0] === $grupoActual[0])) {
        return null;
    }
    [$titulo] = cengi_pagina_titulo($origen, $esEstudiante);
    return ['href' => $ruta . (isset($partes['query']) ? '?' . $partes['query'] : ''), 'texto' => $titulo];
}

function menu_render($vistaEstudiante = false)
{
    $esEstudiante = $vistaEstudiante || cengi_es_estudiante();
    $paginaActual = cengi_nav_pagina_actual();
    $currentPage = strtok($paginaActual, '?');
    $isActive = static function (array $pages) use ($currentPage) {
        return in_array($currentPage, $pages, true) ? ' is-active' : '';
    };

    [$tituloPagina, $subtituloPagina] = cengi_pagina_titulo($currentPage, $esEstudiante);
    $ubicacion = $esEstudiante ? null : cengi_nav_ubicar($paginaActual);
    $volver = $esEstudiante ? null : cengi_nav_volver($paginaActual, $esEstudiante);

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
    $version = static function ($archivo) {
        return (int) (@filemtime(__DIR__ . '/' . $archivo) ?: 1);
    };
    ?>
<?php /* Menú "Acciones" en las filas de tabla de todas las páginas (ver js/course_actions.js). */ ?>
<link rel="stylesheet" href="css/course_actions.css?v=<?php echo $version('css/course_actions.css'); ?>">
<script src="js/course_actions.js?v=<?php echo $version('js/course_actions.js'); ?>" defer></script>
<aside class="cengi-sidebar" id="cengiSidebar">
    <a class="cengi-brand" href="index.php" aria-label="Ir al inicio de Cengicursos">
        <img class="cengi-brand-logo" src="img/logo-comite-capacitacion.png"
            alt="Comité de Capacitación CENGICAÑA">
    </a>

    <nav class="cengi-sidebar-nav" id="cengiPrimaryNav" aria-label="Navegacion principal">
        <?php if ($esEstudiante): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label">Mi aprendizaje</div>
            <a href="mi_aula.php" class="cengi-nav-item<?php echo $isActive(['mi_aula.php','ver_cursos.php','index.php']); ?>">
                <span class="glyphicon glyphicon-education"></span><span>Mis cursos</span>
            </a>
            <a href="mi_perfil.php" class="cengi-nav-item<?php echo $isActive(['mi_perfil.php']); ?>">
                <span class="glyphicon glyphicon-user"></span><span>Mi perfil</span>
            </a>
            <a href="../login/forgot_password.php" class="cengi-nav-item">
                <span class="glyphicon glyphicon-lock"></span><span>Recuperar contraseña</span>
            </a>
        </div>
        <?php else: ?>
        <?php
        // Un solo nivel por grupo: cada página aparece en un único lugar del menú.
        foreach (cengi_nav_estructura() as $claveGrupo => [$nombreGrupo, $opciones]):
            $visibles = array_filter($opciones, static function ($op) {
                return $op[4] && $op[5];
            });
            if (!$visibles) {
                continue;
            }
            $grupoActivo = $ubicacion && $ubicacion[0] === $claveGrupo;
            if ($claveGrupo === 'general'): ?>
        <div class="cengi-nav-group">
            <div class="cengi-nav-label"><?php echo $nombreGrupo; ?></div>
            <?php else: ?>
        <details class="cengi-nav-group cengi-nav-dropdown<?php echo $grupoActivo ? ' is-current' : ''; ?>"<?php echo $grupoActivo ? ' open' : ''; ?>>
            <summary class="cengi-nav-label"><?php echo $nombreGrupo; ?></summary>
            <?php endif; ?>
            <?php foreach ($visibles as [$href, $icono, $texto, $activas]):
                $activa = $ubicacion && $ubicacion[2][0] === $href; ?>
            <a href="<?php echo $href; ?>" class="cengi-nav-item<?php echo $activa ? ' is-active' : ''; ?>"<?php echo $activa ? ' aria-current="page"' : ''; ?>>
                <span class="glyphicon glyphicon-<?php echo $icono; ?>"></span>
                <span><?php echo $texto; ?></span>
            </a>
            <?php endforeach; ?>
        <?php echo $claveGrupo === 'general' ? '</div>' : '</details>'; ?>
        <?php endforeach; ?>
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
        <?php if ($ubicacion || $volver): ?>
            <div class="cengi-topbar-ruta">
                <?php if ($volver): ?><a class="cengi-volver" href="<?php echo htmlspecialchars($volver['href'], ENT_QUOTES, 'UTF-8'); ?>"><span class="glyphicon glyphicon-arrow-left" aria-hidden="true"></span> Volver a <?php echo htmlspecialchars($volver['texto'], ENT_QUOTES, 'UTF-8'); ?></a><?php endif; ?>
                <?php if ($ubicacion): ?><span class="cengi-ruta-grupo"><?php echo htmlspecialchars($ubicacion[1], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="cengi-topbar-title"><?php echo htmlspecialchars($tituloPagina, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="cengi-topbar-sub"><?php echo htmlspecialchars($subtituloPagina, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <div class="cengi-topbar-right">
        <?php if (!$esEstudiante) cengi_notificaciones_render(); ?>
        <?php if ($rolUsuario !== ''): ?>
            <span class="cengi-pill"><?php echo htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <div class="cengi-userbox">
            <?php
            // Estudiante: su foto de perfil (si la subió) y acceso directo a Mi perfil.
            $fotoAvatar = false;
            if ($esEstudiante) {
                require_once __DIR__ . '/estudiante_perfil_helpers.php';
                try {
                    $yoAvatar = cengi_est_participante(conectar(), cengi_usuario_actual_id());
                    $fotoAvatar = $yoAvatar && !empty($yoAvatar['foto_path']) && cengi_est_foto_archivo($yoAvatar['foto_path']);
                } catch (Throwable $e) {
                    $fotoAvatar = false;
                }
            }
            ?>
            <?php if ($esEstudiante): ?><a class="cengi-avatar<?php echo $fotoAvatar ? ' has-foto' : ''; ?>" href="mi_perfil.php" title="Mi perfil"><?php if ($fotoAvatar): ?><img src="mi_perfil_foto.php?v=<?php echo htmlspecialchars(substr(md5((string) $yoAvatar['foto_path']), 0, 8), ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: echo htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8'); endif; ?></a>
            <?php else: ?><div class="cengi-avatar"><?php echo htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
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

    if (nav) {
        var activeLink = nav.querySelector('.cengi-nav-item.is-active');
        var parent = activeLink ? activeLink.parentElement : null;
        while (parent && parent !== nav) {
            if (parent.tagName === 'DETAILS') parent.open = true;
            parent = parent.parentElement;
        }
        if (activeLink && activeLink.scrollIntoView) { try { activeLink.scrollIntoView({ block: 'nearest' }); } catch (e) {} }
    }
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
        if (event.key === 'Escape') { closeMenu(); closeNotif(); }
    });

    // Campana de notificaciones.
    var notif = document.getElementById('cengiNotif');
    var notifBtn = document.getElementById('cengiNotifBtn');
    var notifPanel = document.getElementById('cengiNotifPanel');
    function closeNotif() {
        if (!notifPanel || notifPanel.hidden) return;
        notifPanel.hidden = true;
        notifBtn.setAttribute('aria-expanded', 'false');
    }
    if (notifBtn && notifPanel) {
        notifBtn.addEventListener('click', function () {
            var willOpen = notifPanel.hidden;
            notifPanel.hidden = !willOpen;
            notifBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (!notif.contains(event.target)) closeNotif();
        });
    }

    if (nav) {
        nav.addEventListener('click', function (event) {
            if (event.target.closest('a')) closeMenu();
        });
    }
})();
</script>
<?php
}
