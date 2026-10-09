<?php
/**
 * Roles por modulo (usuarios_menu): cada usuario tiene un rol en cada modulo al que tiene
 * acceso (usuario_modulo.rol_id), para que los roles y permisos de un modulo no se crucen
 * con los de otro. Migracion: login/config/migracion_roles_por_modulo.sql.
 *
 *   rol_modulo        modulos a los que pertenece cada rol. Un rol puede pertenecer a varios
 *                     (Director: Comites y Solicitudes internas). Sin filas = rol generico
 *                     (Superadmin, Administrador): se puede asignar en cualquier modulo, pero
 *                     solo vale en el modulo donde se asigna.
 *   usuario_modulo.rol_id  rol del usuario en ese modulo.
 *   usuarios.rol_id   rol en la plataforma (menu central y administracion de usuarios).
 *
 * Todos los modulos comparten una sola sesion PHP: cada uno llama a
 * cengi_rm_activar_modulo() al inicio de cada peticion para cargar SU rol y SUS permisos
 * en $_SESSION['rol'], ['rol_id'] y ['user_permissions'] (el codigo existente de cada
 * modulo sigue leyendo esas mismas claves).
 */

const CENGI_RM_MODULOS = [
    'cursos' => 'Cursos',
    'laboratorio' => 'Laboratorio',
    'comites' => 'Comites, Transferencia y Comunicacion',
    'solicitudes' => 'Solicitudes internas',
    'sigec' => 'SIGEC',
    'visitas' => 'Solicitud de visitas',
    'servicio' => 'Servicio tecnico',
];

function cengi_rm_norm($texto)
{
    $texto = mb_strtolower(trim((string) $texto), 'UTF-8');
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
}

/** id del modulo por clave (cursos, laboratorio...) o por su nombre; 0 si no existe. */
function cengi_rm_modulo_id(PDO $db, $modulo)
{
    static $cache = [];
    $nombre = CENGI_RM_MODULOS[cengi_rm_norm($modulo)] ?? (string) $modulo;
    $clave = cengi_rm_norm($nombre);
    if (!isset($cache[$clave])) {
        $cache[$clave] = 0;
        foreach ($db->query('SELECT id, nombre FROM modulos')->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if (cengi_rm_norm($m['nombre']) === $clave) {
                $cache[$clave] = (int) $m['id'];
            }
        }
    }
    return $cache[$clave];
}

/** Modulos de un rol (vacio = generico). */
function cengi_rm_modulos_de_rol(PDO $db, $rolId)
{
    $stmt = $db->prepare('SELECT modulo_id FROM rol_modulo WHERE rol_id = ?');
    $stmt->execute([(int) $rolId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Un rol sirve en el modulo si es generico o pertenece a ese modulo. */
function cengi_rm_rol_compatible(PDO $db, $rolId, $moduloId)
{
    if (!$rolId) {
        return false;
    }
    $modulos = cengi_rm_modulos_de_rol($db, $rolId);
    return !$modulos || in_array((int) $moduloId, $modulos, true);
}

/**
 * Rol del usuario en el modulo: ['rol_id', 'nombre_rol'] o null si no tiene acceso o no
 * tiene rol ahi. El superadmin conserva su rol en todos los modulos. Si la fila de
 * usuario_modulo aun no tiene rol, se usa el de la plataforma solo si es compatible con el
 * modulo (nunca el rol propio de otro modulo).
 */
function cengi_rm_rol_en_modulo(PDO $db, $usuarioId, $modulo)
{
    $moduloId = cengi_rm_modulo_id($db, $modulo);
    $stmt = $db->prepare('SELECT u.es_superadmin, um.usuario_id AS tiene_modulo, r.id AS rol_id, r.nombre_rol,
            rp.id AS rp_id, rp.nombre_rol AS rp_nombre
        FROM usuarios u
        LEFT JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id = ?
        LEFT JOIN roles r ON r.id = um.rol_id
        LEFT JOIN roles rp ON rp.id = u.rol_id
        WHERE u.id = ?');
    $stmt->execute([$moduloId, (int) $usuarioId]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        return null;
    }
    $plataforma = $f['rp_id'] !== null ? ['rol_id' => (int) $f['rp_id'], 'nombre_rol' => (string) $f['rp_nombre']] : null;
    if ((int) $f['es_superadmin'] === 1) {
        return $plataforma;
    }
    if (!$f['tiene_modulo']) {
        return null;
    }
    if ($f['rol_id'] !== null) {
        return ['rol_id' => (int) $f['rol_id'], 'nombre_rol' => (string) $f['nombre_rol']];
    }
    return $plataforma && cengi_rm_rol_compatible($db, $plataforma['rol_id'], $moduloId) ? $plataforma : null;
}

function cengi_rm_permisos_rol(PDO $db, $rolId)
{
    if (!$rolId) {
        return [];
    }
    $stmt = $db->prepare('SELECT p.nombre_permiso FROM rol_permiso rp INNER JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ?');
    $stmt->execute([(int) $rolId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Carga en la sesion el rol y los permisos del usuario EN ESTE MODULO. Llamar al inicio de
 * cada peticion del modulo (despues de comprobar que hay sesion). Devuelve el rol o null.
 */
function cengi_rm_activar_modulo(PDO $db, $modulo)
{
    if (empty($_SESSION['id_usuario'])) {
        return null;
    }
    $rol = cengi_rm_rol_en_modulo($db, (int) $_SESSION['id_usuario'], $modulo);
    $_SESSION['modulo_activo'] = CENGI_RM_MODULOS[cengi_rm_norm($modulo)] ?? (string) $modulo;
    $_SESSION['rol'] = $rol['nombre_rol'] ?? '';
    $_SESSION['nombre_rol'] = $rol['nombre_rol'] ?? '';
    $_SESSION['rol_id'] = $rol['rol_id'] ?? 0;
    $_SESSION['user_permissions'] = $rol ? cengi_rm_permisos_rol($db, $rol['rol_id']) : [];
    return $rol;
}

/**
 * Roles que se pueden asignar en un modulo: los del modulo primero y despues los genericos.
 * Superadmin solo si quien asigna es superadmin.
 */
function cengi_rm_roles_asignables(PDO $db, $moduloId, $incluirSuperadmin = false)
{
    $stmt = $db->prepare('SELECT r.id, r.nombre_rol,
            EXISTS (SELECT 1 FROM rol_modulo rm WHERE rm.rol_id = r.id AND rm.modulo_id = ?) AS del_modulo,
            NOT EXISTS (SELECT 1 FROM rol_modulo rm WHERE rm.rol_id = r.id) AS generico
        FROM roles r ORDER BY r.nombre_rol');
    $stmt->execute([(int) $moduloId]);
    $roles = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static function ($r) use ($incluirSuperadmin) {
        return ($r['del_modulo'] || $r['generico']) && ($incluirSuperadmin || cengi_rm_norm($r['nombre_rol']) !== 'superadmin');
    }));
    usort($roles, static function ($a, $b) {
        return [(int) $b['del_modulo'], $a['nombre_rol']] <=> [(int) $a['del_modulo'], $b['nombre_rol']];
    });
    return $roles;
}

/** Da acceso al modulo con ese rol (o actualiza el rol si ya tenia acceso). */
function cengi_rm_asignar(PDO $db, $usuarioId, $moduloId, $rolId)
{
    $db->prepare('INSERT INTO usuario_modulo (usuario_id, modulo_id, rol_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rol_id = VALUES(rol_id)')
        ->execute([(int) $usuarioId, (int) $moduloId, $rolId ? (int) $rolId : null]);
}

/** Usuarios con acceso al modulo y su rol ahi: [usuario_id => ['rol_id', 'nombre_rol']]. */
function cengi_rm_roles_de_usuarios(PDO $db, $moduloId)
{
    $stmt = $db->prepare('SELECT um.usuario_id, r.id AS rol_id, r.nombre_rol FROM usuario_modulo um LEFT JOIN roles r ON r.id = um.rol_id WHERE um.modulo_id = ?');
    $stmt->execute([(int) $moduloId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $out[(int) $f['usuario_id']] = ['rol_id' => $f['rol_id'] !== null ? (int) $f['rol_id'] : null, 'nombre_rol' => (string) $f['nombre_rol']];
    }
    return $out;
}
