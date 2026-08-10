<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../login/config/session.php';

if (session_status() === PHP_SESSION_NONE) {
    cengi_session_start();
}

function cengi_texto_normalizado($valor)
{
    $valor = trim((string) $valor);
    $valor = mb_strtolower($valor, 'UTF-8');
    $valor = strtr($valor, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ñ' => 'n',
        'Á' => 'a',
        'É' => 'e',
        'Í' => 'i',
        'Ó' => 'o',
        'Ú' => 'u',
        'Ñ' => 'n',
    ]);

    return preg_replace('/\s+/', '', $valor);
}

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

    try {
        $conn = conectar_usuarios_menu();
        $stmt = $conn->prepare("
            SELECT
                u.id,
                u.nombre,
                u.correo,
                u.rol_id,
                u.ingenio_id,
                u.es_superadmin,
                r.nombre_rol,
                i.nombre_ingenio
            FROM usuarios u
            INNER JOIN roles r ON r.id = u.rol_id
            LEFT JOIN ingenios i ON i.id = u.ingenio_id
            WHERE u.id = ?
            LIMIT 1
        ");

        $idUsuario = (int) $_SESSION['id_usuario'];
        $stmt->bind_param('i', $idUsuario);
        $stmt->execute();
        $usuario = $stmt->get_result()->fetch_assoc();

        if ($usuario) {
            $_SESSION['usuario'] = $usuario['nombre'];
            $_SESSION['correo'] = $usuario['correo'];
            $_SESSION['rol'] = $usuario['nombre_rol'];
            $_SESSION['rol_id'] = $usuario['rol_id'];
            $_SESSION['ingenio_id'] = $usuario['ingenio_id'];
            $_SESSION['ingenio_nombre'] = $usuario['nombre_ingenio'];
            $_SESSION['es_superadmin'] = $usuario['es_superadmin'];
        }
    } catch (Exception $e) {
        error_log('Error cargando usuario de cengicursos: ' . $e->getMessage());
        $usuario = null;
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
    return cengi_es_superadmin() || in_array($rol, ['admin', 'administrador', 'superadmin'], true);
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

function cengi_puede_gestionar()
{
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
function cengi_puede_ver_dashboard_ingenio()
{
    return cengi_es_admin() || (cengi_es_ingenio() && cengi_ingenio_id_actual() > 0);
}

function cengi_puede_ver_roles()
{
    return cengi_es_admin()
        || cengi_tiene_permiso('ver_roles_cengi')
        || cengi_tiene_permiso('roles_gestionar_cengi');
}

function cengi_puede_gestionar_roles()
{
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

function cengi_usuario_actual_id()
{
    return isset($_SESSION['id_usuario']) ? (int) $_SESSION['id_usuario'] : 0;
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
