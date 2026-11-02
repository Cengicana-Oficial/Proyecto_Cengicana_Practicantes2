<?php
/**
 * Autorizacion de la administracion de usuarios (usuarios.php, crear_usuario.php,
 * editar_usuario.php, eliminar_usuario.php).
 *
 * Sin scope solo entra el superadmin. Con scope (cursos, laboratorio, visitas, solicitudes)
 * el usuario debe tener, en ese modulo, un rol con el permiso de gestionar usuarios del
 * modulo (se calcula desde la BD con roles_modulo.php, no desde la sesion, que guarda el rol
 * del ultimo modulo visitado).
 *
 * Sobre el usuario objetivo, quien no es superadmin:
 *   - nunca toca a un superadmin;
 *   - solo toca usuarios que tienen acceso a alguno de los modulos que gestiona;
 *   - solo cambia nombre, correo, contrasena e ingenio (y solo borra la cuenta) si gestiona
 *     TODOS los modulos del usuario y el rol de plataforma del usuario no tiene permisos que
 *     el no tenga. Si no, solo puede cambiar o quitar su acceso a los modulos que gestiona.
 */
require_once __DIR__ . '/../config/roles_modulo.php';

const USR_SCOPE_AUTORIZACION = [
    'cursos' => [
        'module_names' => ['cursos', 'cengicursos'],
        'permisos' => ['gestionar_usuarios_cengi', 'gestionar_usuarios'],
        // cengicursos/revisar_permisos.php::cengi_es_admin() da admin por nombre de rol
        'roles_admin' => ['admin', 'administrador'],
    ],
    'visitas' => [
        'module_names' => ['solicitud de visitas'],
        'permisos' => ['gestionar_usuarios'],
        'roles_admin' => [],
    ],
    'laboratorio' => [
        'module_names' => ['laboratorio'],
        'permisos' => ['laboratorio.usuarios.gestionar'],
        'roles_admin' => [],
    ],
    'solicitudes' => [
        'module_names' => ['solicitudes internas'],
        'permisos' => ['solicitudes_internas.usuarios.gestionar'],
        'roles_admin' => [],
    ],
];

function usr_csrf_token()
{
    if (empty($_SESSION['usuarios_csrf'])) {
        $_SESSION['usuarios_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['usuarios_csrf'];
}

function usr_csrf_valido($token)
{
    return is_string($token) && !empty($_SESSION['usuarios_csrf']) && hash_equals($_SESSION['usuarios_csrf'], $token);
}

/** Responde 403 con la pagina de aviso del modulo (si la pantalla la define) y termina. */
function usr_denegar($mensaje, $volverA = 'usuarios.php')
{
    http_response_code(403);
    if (function_exists('usuarios_mostrar_aviso')) {
        usuarios_mostrar_aviso('Acción no permitida', $mensaje, $volverA);
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acción no permitida</title></head><body>'
        . '<p>' . htmlspecialchars($mensaje) . '</p><p><a href="' . htmlspecialchars($volverA) . '">Volver</a></p></body></html>';
    exit;
}

function usr_permisos_lower(PDO $conn, $rolId)
{
    return array_map('strtolower', cengi_rm_permisos_rol($conn, $rolId));
}

/** El usuario puede gestionar usuarios en ese modulo (fila de `modulos`) segun la regla del scope. */
function usr_gestiona_modulo(PDO $conn, $actorId, array $modulo, array $regla)
{
    $rol = cengi_rm_rol_en_modulo($conn, (int) $actorId, (string) $modulo['nombre']);
    if (!$rol) {
        return false;
    }
    if (in_array(cengi_rm_norm($rol['nombre_rol']), $regla['roles_admin'], true)) {
        return true;
    }
    return (bool) array_intersect($regla['permisos'], usr_permisos_lower($conn, $rol['rol_id']));
}

/** Modulos (filas id, nombre) que pertenecen a un scope. */
function usr_modulos_de_scope(PDO $conn, $scope)
{
    $regla = USR_SCOPE_AUTORIZACION[$scope] ?? null;
    if (!$regla) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($regla['module_names']), '?'));
    $stmt = $conn->prepare("SELECT id, nombre, ? AS scope FROM modulos WHERE LOWER(nombre) IN ($placeholders) ORDER BY nombre");
    $stmt->execute(array_merge([$scope], $regla['module_names']));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Quien no es superadmin solo asigna en un modulo roles que no tengan mas permisos que su
 * propio rol ahi; los roles de administrador por nombre (Cursos) solo los asigna otro admin.
 */
function usr_rol_asignable(PDO $conn, $actorId, $esSuperadmin, array $modulo, $rolId)
{
    if ($esSuperadmin) {
        return true;
    }
    $regla = USR_SCOPE_AUTORIZACION[$modulo['scope'] ?? ''] ?? null;
    $rolActor = cengi_rm_rol_en_modulo($conn, (int) $actorId, (string) $modulo['nombre']);
    if (!$regla || !$rolActor) {
        return false;
    }
    if ((int) $rolActor['rol_id'] === (int) $rolId) {
        return true;
    }
    $stmt = $conn->prepare('SELECT nombre_rol FROM roles WHERE id = ?');
    $stmt->execute([(int) $rolId]);
    $nombreRol = cengi_rm_norm((string) $stmt->fetchColumn());
    if ($nombreRol === 'superadmin') {
        return false;
    }
    $actorEsAdmin = in_array(cengi_rm_norm($rolActor['nombre_rol']), $regla['roles_admin'], true);
    if ($actorEsAdmin) {
        // En el modulo el admin por nombre ya tiene todos los permisos (cengi_es_admin()).
        return true;
    }
    if (in_array($nombreRol, $regla['roles_admin'], true)) {
        return false;
    }
    return !array_diff(usr_permisos_lower($conn, $rolId), usr_permisos_lower($conn, $rolActor['rol_id']));
}

/** Quita de [modulo_id => [rol_id => nombre]] los roles que el actor no puede asignar. */
function usr_filtrar_roles_asignables(PDO $conn, $actorId, $esSuperadmin, array $rolesPorModulo, array $modulos)
{
    if ($esSuperadmin) {
        return $rolesPorModulo;
    }
    foreach ($modulos as $m) {
        $id = (int) $m['id'];
        foreach (array_keys($rolesPorModulo[$id] ?? []) as $rolId) {
            if (!usr_rol_asignable($conn, $actorId, false, $m, $rolId)) {
                unset($rolesPorModulo[$id][$rolId]);
            }
        }
    }
    return $rolesPorModulo;
}

/** Modulos del scope que el usuario gestiona (todos si es superadmin). */
function usr_modulos_gestionados(PDO $conn, $actorId, $esSuperadmin, $scope)
{
    $scope = strtolower(trim((string) $scope));
    $modulos = usr_modulos_de_scope($conn, $scope);
    if ($esSuperadmin) {
        return $modulos;
    }
    $regla = USR_SCOPE_AUTORIZACION[$scope] ?? null;
    if (!$regla) {
        return [];
    }
    return array_values(array_filter($modulos, static function ($m) use ($conn, $actorId, $regla) {
        return usr_gestiona_modulo($conn, $actorId, $m, $regla);
    }));
}

/** ids de todos los modulos (de cualquier scope) en los que el usuario gestiona usuarios. */
function usr_ids_gestionados_global(PDO $conn, $actorId)
{
    $ids = [];
    foreach (array_keys(USR_SCOPE_AUTORIZACION) as $scope) {
        foreach (usr_modulos_gestionados($conn, $actorId, false, $scope) as $m) {
            $ids[] = (int) $m['id'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Corta la peticion si el usuario no puede administrar usuarios en este scope.
 * Devuelve los modulos del scope que gestiona (para superadmin sin scope: []).
 */
function usr_exigir_acceso_scope(PDO $conn, $actorId, $esSuperadmin, $scope, $scopeConfig)
{
    if (!$scopeConfig) {
        if ($esSuperadmin) {
            return [];
        }
        header('Location: ../Menu.php');
        exit;
    }
    $gestionados = usr_modulos_gestionados($conn, $actorId, $esSuperadmin, $scope);
    if (!$gestionados) {
        usr_denegar('No tienes permiso para administrar los usuarios de este módulo.', $scopeConfig['return_url'] ?? '../Menu.php');
    }
    return $gestionados;
}

/**
 * Que puede hacer el actor sobre el usuario objetivo:
 *   'ninguno'  no puede tocarlo;
 *   'modulos'  solo su acceso/rol en los modulos que gestiona;
 *   'total'    tambien sus datos de cuenta (nombre, correo, contrasena, ingenio) y borrarlo.
 */
function usr_alcance_sobre(PDO $conn, $actorId, $esSuperadmin, array $objetivo, array $modulosGestionados)
{
    if ($esSuperadmin) {
        return 'total';
    }
    if ((int) $objetivo['es_superadmin'] === 1) {
        return 'ninguno';
    }

    $idsModulosGestionados = array_map(static fn($m) => (int) $m['id'], $modulosGestionados);
    $stmt = $conn->prepare('SELECT modulo_id, rol_id FROM usuario_modulo WHERE usuario_id = ?');
    $stmt->execute([(int) $objetivo['id']]);
    $rolesObjetivo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $rolesObjetivo[(int) $fila['modulo_id']] = $fila['rol_id'] !== null ? (int) $fila['rol_id'] : null;
    }
    $modulosObjetivo = array_keys($rolesObjetivo);

    if (!array_intersect($modulosObjetivo, $idsModulosGestionados)) {
        return 'ninguno';
    }

    // No se toca a quien tiene, en un modulo que gestiona el actor, un rol que el actor no podria asignar.
    foreach ($modulosGestionados as $m) {
        $rolObjetivo = $rolesObjetivo[(int) $m['id']] ?? null;
        if ($rolObjetivo && !usr_rol_asignable($conn, $actorId, false, $m, $rolObjetivo)) {
            return 'ninguno';
        }
    }

    if (array_diff($modulosObjetivo, usr_ids_gestionados_global($conn, $actorId))) {
        return 'modulos';
    }

    // El rol de plataforma (menu central) del objetivo no puede tener permisos que el actor no
    // tenga ni en la plataforma ni en los modulos que gestiona.
    $permisosObjetivo = usr_permisos_lower($conn, (int) $objetivo['rol_id']);
    if ($permisosObjetivo) {
        $stmtActor = $conn->prepare('SELECT rol_id FROM usuarios WHERE id = ?');
        $stmtActor->execute([(int) $actorId]);
        $permisosActor = usr_permisos_lower($conn, (int) $stmtActor->fetchColumn());
        foreach ($idsModulosGestionados as $moduloId) {
            $stmtNombre = $conn->prepare('SELECT nombre FROM modulos WHERE id = ?');
            $stmtNombre->execute([$moduloId]);
            $rol = cengi_rm_rol_en_modulo($conn, (int) $actorId, (string) $stmtNombre->fetchColumn());
            if ($rol) {
                $permisosActor = array_merge($permisosActor, usr_permisos_lower($conn, $rol['rol_id']));
            }
        }
        if (array_diff($permisosObjetivo, $permisosActor)) {
            return 'modulos';
        }
    }

    return 'total';
}
