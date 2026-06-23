<?php
require_once __DIR__ . '/conexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
    $columnaNormalizada = "regexp_replace(lower(translate({$referenciaColumna}, 'áéíóúÁÉÍÓÚñÑ', 'aeiouAEIOUnN')), '\s+', '', 'g')";

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
