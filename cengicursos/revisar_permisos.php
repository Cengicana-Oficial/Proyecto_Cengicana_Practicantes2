<?php
require_once __DIR__ . '/conexion.php';
// cengi_texto_normalizado() y cengi_usuario_actual_id(): tambien las usan paginas publicas.
require_once __DIR__ . '/cengi_texto.php';
require_once __DIR__ . '/../login/config/session.php';
require_once __DIR__ . '/../login/config/roles_modulo.php';

if (session_status() === PHP_SESSION_NONE) {
    cengi_session_start();
}

/**
 * Proteccion CSRF de todas las paginas que incluyen este archivo (las del panel; las
 * publicas como inscripcion.php no lo incluyen). Con sesion iniciada, todo POST debe traer
 * el token de la sesion en el campo _csrf o en la cabecera X-CSRF-Token. El token se agrega
 * solo: en el HTML de salida a cada <form method="post"> y, con un script en <head>, a los
 * formularios creados por JS y a fetch / XMLHttpRequest (jQuery) del mismo origen.
 */
function cengi_csrf_token()
{
    if (empty($_SESSION['cengi_csrf'])) {
        $_SESSION['cengi_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cengi_csrf'];
}

function cengi_csrf_inyectar($html)
{
    foreach (headers_list() as $cabecera) {
        if (stripos($cabecera, 'content-type:') === 0 && stripos($cabecera, 'text/html') === false) {
            return $html;
        }
    }
    if (stripos($html, '<form') === false && stripos($html, '<head') === false) {
        return $html;
    }
    $token = htmlspecialchars(cengi_csrf_token(), ENT_QUOTES, 'UTF-8');
    $html = preg_replace(
        '~<form\b[^>]*\bmethod\s*=\s*(["\']?)post\1[^>]*>~i',
        '$0<input type="hidden" name="_csrf" value="' . $token . '">',
        $html
    );
    $script = '<meta name="csrf-token" content="' . $token . '"><script>(function(){if(window.__cengiCsrf)return;window.__cengiCsrf=1;'
        . 'var t=document.querySelector(\'meta[name="csrf-token"]\').content;'
        . 'function mismo(u){try{return new URL(u,location.href).origin===location.origin}catch(e){return true}}'
        . 'function lectura(m){m=String(m||"GET").toUpperCase();return m==="GET"||m==="HEAD"||m==="OPTIONS"}'
        . 'function campo(f){if(f&&String(f.method).toLowerCase()==="post"&&!f.querySelector(\'input[name="_csrf"]\')){var i=document.createElement("input");i.type="hidden";i.name="_csrf";i.value=t;f.appendChild(i)}}'
        . 'if(window.fetch){var of=window.fetch;window.fetch=function(r,o){o=o||{};var u=typeof r==="string"?r:(r&&r.url),m=o.method||(r&&r.method);'
        . 'if(!lectura(m)&&mismo(u)){var h=new Headers(o.headers||(r instanceof Request?r.headers:undefined));h.set("X-CSRF-Token",t);o.headers=h}return of.call(this,r,o)}}'
        . 'var op=XMLHttpRequest.prototype.open,sd=XMLHttpRequest.prototype.send;'
        . 'XMLHttpRequest.prototype.open=function(m,u){this.__m=m;this.__u=u;return op.apply(this,arguments)};'
        . 'XMLHttpRequest.prototype.send=function(){if(!lectura(this.__m)&&mismo(this.__u)){try{this.setRequestHeader("X-CSRF-Token",t)}catch(e){}}return sd.apply(this,arguments)};'
        . 'document.addEventListener("submit",function(e){campo(e.target)},true);'
        . 'var os=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){campo(this);return os.apply(this,arguments)};'
        . '})();</script>';
    // Al inicio de <head> (antes que los scripts de la pagina); sin <head>, al inicio de <body>.
    $conScript = preg_replace('~<head\b[^>]*>~i', '$0' . $script, $html, 1, $cambios);
    if (!$cambios) {
        $conScript = preg_replace('~<body\b[^>]*>~i', '$0' . $script, $html, 1, $cambios);
    }
    return $cambios ? $conScript : $html;
}

function cengi_csrf_proteger()
{
    if (PHP_SAPI === 'cli' || defined('CENGI_CSRF_ACTIVO')) {
        return;
    }
    define('CENGI_CSRF_ACTIVO', true);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_SESSION['id_usuario'])) {
        $enviado = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($enviado) || !hash_equals(cengi_csrf_token(), $enviado)) {
            $mensaje = 'La sesión del formulario expiró o la solicitud no es válida. Recarga la página e inténtalo de nuevo.';
            http_response_code(403);
            $esperaJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false
                || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                || stripos($_SERVER['SCRIPT_NAME'] ?? '', '_api') !== false;
            if ($esperaJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
            } else {
                echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Solicitud no válida</title></head><body>'
                    . '<p>' . htmlspecialchars($mensaje) . '</p><p><a href="javascript:history.back()">Volver</a></p></body></html>';
            }
            exit;
        }
    }

    cengi_csrf_token();
    ob_start('cengi_csrf_inyectar');
}

cengi_csrf_proteger();

/**
 * Equivalente en SQL (MySQL) de cengi_texto_normalizado(), para comparar
 * columnas dentro de una consulta. MySQL no tiene TRANSLATE()/REGEXP_REPLACE(...,'g')
 * como PostgreSQL, asi que se encadenan REPLACE() para cada vocal acentuada y la ñ.
 */
function cengi_sql_texto_normalizado($columnaSql)
{
    $expr = "LOWER(TRIM({$columnaSql}))";

    foreach (['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'] as $con => $sin) {
        $expr = "REPLACE({$expr}, '{$con}', '{$sin}')";
    }

    return "REPLACE({$expr}, ' ', '')";
}

function usuario_tiene_modulo_cursos()
{
    if (isset($_SESSION['es_superadmin']) && (int) $_SESSION['es_superadmin'] === 1) {
        return true;
    }

    foreach ($_SESSION['modulos'] ?? [] as $modulo) {
        $nombre = cengi_texto_normalizado($modulo['nombre'] ?? '');
        if ($nombre === 'cursos' || $nombre === 'cengicursos') {
            return true;
        }
    }

    return false;
}

function cengi_cargar_usuario_actual()
{
    if (empty($_SESSION['id_usuario'])) {
        return null;
    }

    static $usuario = null;
    if ($usuario !== null) {
        return $usuario;
    }

    // La sesion es compartida con los demas modulos: hasta cargar el rol de Cursos no debe
    // quedar el rol ni los permisos del ultimo modulo visitado.
    $_SESSION['rol'] = $_SESSION['nombre_rol'] = '';
    $_SESSION['rol_id'] = 0;
    $_SESSION['user_permissions'] = [];

    try {
        $menu = conectar_usuarios_menu_pdo();
        $stmt = $menu->prepare("
            SELECT u.id, u.nombre, u.correo, u.ingenio_id, u.es_superadmin, i.nombre_ingenio
            FROM usuarios u
            LEFT JOIN ingenios i ON i.id = u.ingenio_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $_SESSION['id_usuario']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($usuario) {
            // Rol y permisos del usuario EN CURSOS (login/config/roles_modulo.php): su rol en
            // Laboratorio, Comites, etc. no aplica aqui.
            $rolCursos = cengi_rm_activar_modulo($menu, 'cursos');
            $usuario['rol_id'] = $rolCursos['rol_id'] ?? 0;
            $usuario['nombre_rol'] = $rolCursos['nombre_rol'] ?? '';
            $_SESSION['usuario'] = $usuario['nombre'];
            $_SESSION['correo'] = $usuario['correo'];
            $_SESSION['ingenio_id'] = $usuario['ingenio_id'];
            $_SESSION['ingenio_nombre'] = $usuario['nombre_ingenio'];
            $_SESSION['es_superadmin'] = $usuario['es_superadmin'];
        }
    } catch (Throwable $e) {
        error_log('Error cargando usuario de cengicursos: ' . $e->getMessage());
        $usuario = null;
        // Si la activacion fallo a medias, no se usa ningun rol ni permiso.
        $_SESSION['rol'] = $_SESSION['nombre_rol'] = '';
        $_SESSION['rol_id'] = 0;
        $_SESSION['user_permissions'] = [];
    }

    return $usuario;
}

function cengi_rol_actual()
{
    cengi_cargar_usuario_actual();
    return cengi_texto_normalizado($_SESSION['rol'] ?? '');
}

function cengi_tiene_permiso($permiso)
{
    if (cengi_es_superadmin()) {
        return true;
    }

    return in_array((string) $permiso, $_SESSION['user_permissions'] ?? [], true);
}

function cengi_es_superadmin()
{
    cengi_cargar_usuario_actual();
    return isset($_SESSION['es_superadmin']) && (int) $_SESSION['es_superadmin'] === 1;
}

function cengi_es_admin()
{
    $rol = cengi_rol_actual();
    if (cengi_es_superadmin()) return true;
    // El gestor conserva siempre su alcance institucional, incluso con permisos heredados.
    if (strpos($rol, 'gestor') !== false && strpos($rol, 'ingenio') !== false) return false;
    if (in_array($rol, ['coordinadordecapacitacion', 'jefedetransferenciadetecnologia'], true)) return true;
    // El Coordinador de capacitación administra todo Cursos por su permiso (no por el nombre del rol).
    return cengi_es_superadmin() || in_array($rol, ['admin', 'administrador', 'superadmin'], true)
        || cengi_tiene_permiso('administrar_cursos_cengi');
}

function cengi_es_instructor()
{
    $rol = cengi_rol_actual();
    return strpos($rol, 'instructor') !== false || strpos($rol, 'docente') !== false;
}

function cengi_es_maestro()
{
    return strpos(cengi_rol_actual(), 'maestro') !== false;
}

function cengi_es_formador()
{
    return cengi_es_instructor() || cengi_es_maestro();
}

function cengi_es_gestor()
{
    return strpos(cengi_rol_actual(), 'gestor') !== false;
}

function cengi_es_gestor_ingenio()
{
    return cengi_es_gestor() && cengi_es_ingenio() && !cengi_es_superadmin();
}

function cengi_es_estudiante()
{
    return in_array(
        cengi_rol_actual(),
        ['estudiante', 'alumno', 'participante'],
        true
    );
}

function cengi_es_ingenio()
{
    return strpos(cengi_rol_actual(), 'ingenio') !== false;
}

/**
 * Rol "Administrador de ingenio" (nivel 3 en cengi_clasificar_rol_nivel()):
 * cualquier rol cuyo nombre contenga "ingenio" y que NO sea admin/superadmin.
 * Se usa para aplicar scoping/ocultamientos adicionales en instructores.php
 * (visibilidad de instructores por participantes de su ingenio, encuestas de
 * satisfaccion e informes PDF de instructor), sin tocar el acceso base ya
 * otorgado via ver_instructores_cengi/gestionar_instructores_cengi.
 */
function cengi_es_administrador_ingenio()
{
    return !cengi_es_admin() && cengi_es_ingenio();
}

function cengi_puede_gestionar()
{
    if (cengi_es_gestor_ingenio()) return false;
    return cengi_es_admin() || cengi_tiene_permiso('gestionar_cursos_cengi');
}

function cengi_puede_gestionar_solicitudes()
{
    return cengi_puede_aprobar_solicitudes()
        || cengi_puede_rechazar_solicitudes()
        || cengi_puede_editar_solicitudes();
}

function cengi_puede_calificar()
{
    return cengi_es_admin()
        || cengi_es_formador()
        || cengi_es_gestor()
        || cengi_tiene_permiso('gestionar_notas_cengi');
}

function cengi_puede_subir_diploma()
{
    return cengi_es_admin()
        || cengi_es_formador()
        || cengi_es_gestor()
        || cengi_tiene_permiso('subir_diplomas_cengi')
        || cengi_tiene_permiso('gestionar_notas_cengi');
}

function cengi_require_subir_diploma($redirect = 'ver_cursos.php')
{
    if (!cengi_puede_subir_diploma()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_puede_ver_usuarios()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('ver_usuarios_cengi')
        || cengi_tiene_permiso('gestionar_usuarios_cengi');
}

function cengi_puede_gestionar_usuarios()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('gestionar_usuarios_cengi')
        || cengi_tiene_permiso('gestionar_usuarios');
}

function cengi_puede_ver_ingenios()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('ver_ingenios_cengi')
        || cengi_tiene_permiso('gestionar_ingenios_cengi')
        || cengi_tiene_permiso('gestionar_ingenios');
}

function cengi_puede_gestionar_ingenios()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('gestionar_ingenios_cengi')
        || cengi_tiene_permiso('gestionar_ingenios');
}

function cengi_puede_ver_participantes()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_es_formador()
        || cengi_tiene_permiso('ver_participantes_cengi')
        || cengi_tiene_permiso('gestionar_participantes_cengi');
}

function cengi_puede_cargar_participantes()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_es_formador()
        || cengi_tiene_permiso('cargar_participantes_cengi')
        || cengi_tiene_permiso('gestionar_participantes_cengi');
}

function cengi_puede_editar_participantes()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('editar_participantes_cengi')
        || cengi_tiene_permiso('gestionar_participantes_cengi');
}

function cengi_puede_eliminar_participantes()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('eliminar_participantes_cengi')
        || cengi_tiene_permiso('gestionar_participantes_cengi');
}

function cengi_puede_ver_solicitudes()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_es_formador()
        || cengi_tiene_permiso('ver_solicitudes_cengi')
        || cengi_tiene_permiso('gestionar_solicitudes_cengi')
        || cengi_tiene_permiso('ver_solicitudes')
        || cengi_tiene_permiso('gestionar_solicitudes');
}

function cengi_puede_editar_solicitudes()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_tiene_permiso('editar_solicitudes_cengi')
        || cengi_tiene_permiso('gestionar_solicitudes_cengi')
        || cengi_tiene_permiso('gestionar_solicitudes');
}

function cengi_puede_aprobar_solicitudes()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('aprobar_solicitudes_cengi')
        || cengi_tiene_permiso('gestionar_solicitudes_cengi')
        || cengi_tiene_permiso('gestionar_solicitudes');
}

function cengi_puede_rechazar_solicitudes()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_tiene_permiso('rechazar_solicitudes_cengi')
        || cengi_tiene_permiso('gestionar_solicitudes_cengi')
        || cengi_tiene_permiso('gestionar_solicitudes');
}

function cengi_puede_ver_organizaciones()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_tiene_permiso('ver_organizaciones_cengi')
        || cengi_tiene_permiso('gestionar_organizaciones_cengi');
}

function cengi_puede_gestionar_organizaciones()
{
    return cengi_es_admin() || cengi_tiene_permiso('gestionar_organizaciones_cengi');
}

function cengi_puede_ver_instructores()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_es_formador()
        || cengi_tiene_permiso('ver_instructores_cengi')
        || cengi_tiene_permiso('gestionar_instructores_cengi');
}

function cengi_puede_gestionar_instructores()
{
    return cengi_es_admin() || cengi_tiene_permiso('gestionar_instructores_cengi');
}

/**
 * Genera informes/reportes PDF de instructor (informe_instructor_pdf.php,
 * informe_instructor_curso_pdf.php, informe_instructor_modulo_pdf.php).
 * El rol "Administrador de ingenio" SI puede generar estos informes, pero
 * cada reporte se limita a su propio ingenio dentro del archivo (courses/
 * modulos sin participantes de su ingenio devuelven 403, y las secciones de
 * encuesta de satisfaccion quedan vacias): el scoping real vive dentro de
 * cada uno de los 3 archivos de reporte, no en este gate generico de acceso.
 * Ver cengi_es_administrador_ingenio() y los helpers de scoping en
 * conexion.php (cengi_frag_curso_participante_ingenio() y companeros).
 */
function cengi_puede_generar_informe_instructor()
{
    return cengi_puede_ver_instructores();
}

function cengi_puede_ver_eventos()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_es_formador()
        || cengi_tiene_permiso('ver_eventos_cengi')
        || cengi_tiene_permiso('gestionar_eventos_cengi');
}

function cengi_puede_gestionar_eventos()
{
    return cengi_es_admin() || cengi_tiene_permiso('gestionar_eventos_cengi');
}

function cengi_puede_ver_diplomas()
{
    return cengi_es_admin()
        || cengi_es_gestor()
        || cengi_es_formador()
        || cengi_tiene_permiso('ver_diplomas_cengi')
        || cengi_tiene_permiso('gestionar_diplomas_cengi')
        || cengi_puede_subir_diploma();
}

function cengi_puede_gestionar_diplomas()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('gestionar_diplomas_cengi')
        || cengi_puede_subir_diploma();
}

/**
 * Dashboard exclusivo de ingenio: se otorga a los administradores (que ven
 * todo por cortesia) y a cualquier usuario cuyo rol contenga "ingenio" y que
 * tenga un ingenio_id asignado. No se registro un permiso granular nuevo en
 * permisos_roles.php porque el acceso depende del rol/ingenio de la sesion,
 * igual que el resto del scoping por ingenio del modulo (cengi_scope_sql()).
 */
/**
 * Seguimiento por ingenio (dashboard_ingenio.php, que reemplazo a seguimiento_ingenio.php):
 * el administrador elige cualquier ingenio; el usuario de ingenio y quien gestiona cursos
 * sin ser administrador ven solo su propio ingenio.
 */
function cengi_puede_ver_dashboard_ingenio()
{
    return cengi_es_admin()
        || ((cengi_es_ingenio() || cengi_puede_gestionar()) && cengi_ingenio_id_actual() > 0);
}

/**
 * Reportes para la Dirección e instituciones de interés (Informe de gestión): el
 * administrador y quien tenga ver_reportes_cengi (matriz "Reportes" en roles.php).
 */
function cengi_puede_ver_reportes()
{
    return cengi_es_admin() || cengi_tiene_permiso('ver_reportes_cengi');
}

function cengi_puede_ver_roles()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('ver_roles_cengi')
        || cengi_tiene_permiso('roles_gestionar_cengi');
}

function cengi_puede_gestionar_roles()
{
    if (cengi_es_gestor_ingenio()) return false;
    return cengi_es_admin() || cengi_tiene_permiso('roles_gestionar_cengi');
}

/**
 * Clasifica un nombre de rol de usuarios_menu en uno de los 3 niveles
 * administrativos que gestiona Cengicursos (ver pagina roles.php). Instructor y
 * Participante/Estudiante no se gestionan aqui (acceden via Moodle) y se
 * excluyen antes de llamar a esta funcion.
 */
function cengi_clasificar_rol_nivel($nombreRol)
{
    $normalizado = cengi_texto_normalizado($nombreRol);

    if (strpos($normalizado, 'ingenio') !== false) {
        return 3; // Administrador de ingenio
    }

    if (strpos($normalizado, 'gestor') !== false || strpos($normalizado, 'encargado') !== false || strpos($normalizado, 'capacitacion') !== false) {
        return 2; // Encargado de capacitacion
    }

    return 1; // Administrador general (admin / superadmin / cualquier otro rol administrativo)
}

function cengi_ingenio_id_actual()
{
    cengi_cargar_usuario_actual();
    return isset($_SESSION['ingenio_id']) ? (int) $_SESSION['ingenio_id'] : 0;
}

function cengi_ingenio_nombre_actual()
{
    cengi_cargar_usuario_actual();
    return trim((string) ($_SESSION['ingenio_nombre'] ?? ''));
}

function cengi_es_ingenio_cengicana()
{
    cengi_cargar_usuario_actual();
    $nombre = cengi_texto_normalizado($_SESSION['ingenio_nombre'] ?? '');
    return $nombre === 'cengicana' || strpos($nombre, 'cengicana') !== false;
}

function cengi_ve_todo_por_rol_o_ingenio()
{
    return cengi_es_admin();
}

function cengi_scope_sql($alias, $alreadyWhere = true, $column = 'ingenio_id')
{
    if (cengi_ve_todo_por_rol_o_ingenio()) {
        return '';
    }

    $ingenioId = cengi_ingenio_id_actual();
    $prefijo = $alreadyWhere ? ' AND ' : ' WHERE ';

    if ($ingenioId <= 0) {
        return $prefijo . '1 = 0';
    }

    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);

    return $prefijo . "{$alias}.{$column} = " . $ingenioId;
}

function cengi_scope_sql_por_nombre_ingenio($alias, $alreadyWhere = true, $column = 'nombre_ingenios')
{
    if (cengi_ve_todo_por_rol_o_ingenio()) {
        return '';
    }

    $ingenioNombre = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
    $prefijo = $alreadyWhere ? ' AND ' : ' WHERE ';

    if ($ingenioNombre === '') {
        return $prefijo . '1 = 0';
    }

    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);

    $referenciaColumna = $alias !== '' ? "{$alias}.{$column}" : $column;
    $columnaNormalizada = "LOWER(REPLACE({$referenciaColumna}, ' ', ''))";

    return $prefijo . $columnaNormalizada . " = '" . addslashes($ingenioNombre) . "'";
}

function cengi_require_admin($redirect = 'index.php')
{
    if (!cengi_puede_gestionar()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_gestor_solicitudes($redirect = 'solicitudes.php')
{
    if (!cengi_puede_ver_solicitudes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_calificador($redirect = 'ver_cursos.php')
{
    if (!cengi_puede_calificar()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_usuarios($redirect = 'index.php')
{
    if (!cengi_puede_ver_usuarios()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_ingenios($redirect = 'index.php')
{
    if (!cengi_puede_ver_ingenios()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_gestionar_ingenios($redirect = 'ver_ingenios.php')
{
    if (!cengi_puede_gestionar_ingenios()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_participantes($redirect = 'index.php')
{
    if (!cengi_puede_ver_participantes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_carga_participantes($redirect = 'participantes.php')
{
    if (!cengi_puede_cargar_participantes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_editar_participantes($redirect = 'participantes.php')
{
    if (!cengi_puede_editar_participantes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_eliminar_participantes($redirect = 'participantes.php')
{
    if (!cengi_puede_eliminar_participantes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_editar_solicitudes($redirect = 'solicitudes.php')
{
    if (!cengi_puede_editar_solicitudes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_aprobar_solicitudes($redirect = 'solicitudes.php')
{
    if (!cengi_puede_aprobar_solicitudes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_rechazar_solicitudes($redirect = 'solicitudes.php')
{
    if (!cengi_puede_rechazar_solicitudes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_organizaciones($redirect = 'index.php')
{
    if (!cengi_puede_ver_organizaciones()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_gestionar_organizaciones($redirect = 'organizaciones.php')
{
    if (!cengi_puede_gestionar_organizaciones()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_instructores($redirect = 'index.php')
{
    if (!cengi_puede_ver_instructores()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_gestionar_instructores($redirect = 'instructores.php')
{
    if (!cengi_puede_gestionar_instructores()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_generar_informe_instructor($redirect = 'instructores.php')
{
    if (!cengi_puede_generar_informe_instructor()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_eventos($redirect = 'index.php')
{
    if (!cengi_puede_ver_eventos()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_gestionar_eventos($redirect = 'eventos_qr.php')
{
    if (!cengi_puede_gestionar_eventos()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_diplomas($redirect = 'index.php')
{
    if (!cengi_puede_ver_diplomas()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_gestionar_diplomas($redirect = 'diplomas.php')
{
    if (!cengi_puede_gestionar_diplomas()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_dashboard_ingenio($redirect = 'index.php')
{
    if (!cengi_puede_ver_dashboard_ingenio()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_reportes($redirect = 'index.php')
{
    if (!cengi_puede_ver_reportes()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_ver_roles($redirect = 'index.php')
{
    if (!cengi_puede_ver_roles()) {
        header("Location: {$redirect}");
        exit();
    }
}

function cengi_require_gestionar_roles($redirect = 'roles.php')
{
    if (!cengi_puede_gestionar_roles()) {
        header("Location: {$redirect}");
        exit();
    }
}

if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

cengi_cargar_usuario_actual();

if (!usuario_tiene_modulo_cursos()) {
    header("Location: ../login/Menu.php");
    exit();
}

if (!isset($_SESSION['CMenus'])) {
    $_SESSION['CMenus'] = $_SESSION['rol'] ?? 'Usuario';
}
