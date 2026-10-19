<?php
/**
 * Usuarios y roles de Cursos (ver_usuarios.php y roles.php).
 *
 * Los datos viven en la base central usuarios_menu (administración en login/). Desde Cursos solo
 * se cambia lo propio del módulo: quién entra a Cursos, con qué rol y de qué ingenio, y qué puede
 * hacer cada rol dentro de Cursos. Lo profundo (crear, renombrar o eliminar roles, contraseñas,
 * superadministradores y los demás módulos) se hace en la administración central.
 */
require_once __DIR__ . '/../login/config/permisos_roles.php';
require_once __DIR__ . '/../login/config/roles_modulo.php';

/** Permisos que solo el superadministrador concede (darían control total de Cursos o de sus roles). */
const CENGI_ACC_PROTEGIDOS = ['administrar_cursos_cengi', 'roles_gestionar_cengi'];

/**
 * Áreas de Cursos y sus permisos reales. 'ver' vacío = el área solo se activa o no ("Sí");
 * 'gestionar' vacío = el área solo se consulta.
 */
function cengi_acc_areas()
{
    return [
        'administracion' => ['Administración completa', 'Todo el módulo y todos los ingenios (Coordinación de capacitación).', [], ['administrar_cursos_cengi']],
        'cursos' => ['Cursos y planificación', 'Cursos, planificación, sesiones y temarios.', ['ver_cursos_cengi'], ['gestionar_cursos_cengi']],
        'inscripciones' => ['Inscripciones', 'Revisar, aprobar y rechazar inscripciones.', ['ver_solicitudes_cengi'], ['gestionar_solicitudes_cengi', 'editar_solicitudes_cengi', 'aprobar_solicitudes_cengi', 'rechazar_solicitudes_cengi']],
        'participantes' => ['Participantes', 'Directorio, datos, carga masiva y desactivación.', ['ver_participantes_cengi'], ['gestionar_participantes_cengi', 'editar_participantes_cengi', 'cargar_participantes_cengi', 'eliminar_participantes_cengi']],
        'notas' => ['Asistencia, notas y diplomas', 'Registrar asistencia y notas y adjuntar diplomas en cada curso.', [], ['gestionar_notas_cengi', 'subir_diplomas_cengi']],
        'diplomas' => ['Certificación', 'Generar diplomas y constancias.', ['ver_diplomas_cengi'], ['gestionar_diplomas_cengi']],
        'instructores' => ['Instructores', 'Directorio, documentos y evaluaciones de instructores.', ['ver_instructores_cengi'], ['gestionar_instructores_cengi']],
        'organizaciones' => ['Organizaciones', 'Instituciones aliadas.', ['ver_organizaciones_cengi'], ['gestionar_organizaciones_cengi']],
        'ingenios' => ['Ingenios e instituciones', 'Catálogo de ingenios.', ['ver_ingenios_cengi'], ['gestionar_ingenios_cengi']],
        'eventos' => ['Eventos', 'Eventos con control QR.', ['ver_eventos_cengi'], ['gestionar_eventos_cengi']],
        'pagos' => ['Pagos de inscripción y eventos', 'Ver pagos o validarlos (Administración).', ['ver_pagos_cengi'], ['validar_pagos_cengi']],
        'liquidaciones' => ['Firma de liquidaciones', 'Aprobar y firmar liquidaciones y validar recibos (Jefe de transferencia).', [], ['firmar_liquidaciones_cengi']],
        'reportes' => ['Reportes e informe de gestión', 'Indicadores para la Dirección.', ['ver_reportes_cengi'], []],
        'usuarios' => ['Usuarios de Cursos', 'Ver usuarios o cambiar su rol e ingenio en Cursos.', ['ver_usuarios_cengi'], ['gestionar_usuarios_cengi']],
        'roles' => ['Roles y permisos de Cursos', 'Ver esta matriz o editarla.', ['ver_roles_cengi'], ['roles_gestionar_cengi']],
    ];
}

/**
 * Nivel de un área para una lista de permisos: 'gestionar' (todos los de gestión), 'parcial'
 * (solo algunos: se asignaron a mano en la administración central), 'ver' o ''.
 */
function cengi_acc_nivel(array $area, array $permisos)
{
    [, , $ver, $gestionar] = $area;
    if ($gestionar && !array_diff($gestionar, $permisos)) {
        return 'gestionar';
    }
    if ($gestionar && array_intersect($gestionar, $permisos)) {
        return 'parcial';
    }
    return $ver && array_intersect($ver, $permisos) ? 'ver' : '';
}

/** Qué hace cada rol conocido (texto para las listas). */
function cengi_acc_descripcion_rol($nombreRol)
{
    $n = cengi_rm_norm($nombreRol);
    $textos = [
        'superadmin' => 'Acceso total a todos los módulos. Se administra en la administración central.',
        'administrador' => 'Administra Cursos y los módulos donde se le asigne.',
        'coordinador de capacitacion' => 'Coordinación de Capacitación: administra todo Cursos y ve todos los ingenios. Incluye gestión y firma de liquidaciones.',
        'gestor de capacitacion de ingenio' => 'Gestiona la capacitación de su ingenio: participantes, inscripciones, notas y diplomas, solo de su gente.',
        'gestor' => 'Gestiona capacitación: califica, carga participantes y revisa inscripciones.',
        'jefe de transferencia de tecnologia' => 'Administra todo Cursos y todos los ingenios, incluidos pagos, liquidaciones y firma.',
        'instructor' => 'Imparte cursos: califica y adjunta diplomas de sus cursos.',
        'estudiante' => 'Participante: entra al aula virtual y ve sus cursos. No usa el panel de gestión.',
    ];
    return $textos[$n] ?? '';
}

/**
 * Accesos que Cursos da por el TIPO de rol (su nombre), además de la matriz: los roles de tipo
 * Gestor e Instructor califican y cargan participantes; los de ingenio solo ven su ingenio.
 */
function cengi_acc_implicitos($nombreRol)
{
    $n = cengi_rm_norm($nombreRol);
    $partes = [];
    if (strpos($n, 'gestor') !== false) {
        $partes[] = 'por ser de tipo Gestor también califica, carga participantes y revisa inscripciones';
    }
    if (strpos($n, 'instructor') !== false || strpos($n, 'docente') !== false || strpos($n, 'maestro') !== false) {
        $partes[] = 'por ser de tipo Instructor también califica y adjunta diplomas';
    }
    if (strpos($n, 'ingenio') !== false) {
        $partes[] = 'solo ve la información de su ingenio';
    }
    return $partes ? ucfirst(implode('; ', $partes)) . '.' : '';
}

function cengi_acc_es_estudiante($nombreRol)
{
    return in_array(cengi_rm_norm($nombreRol), ['estudiante', 'alumno', 'participante'], true);
}

/**
 * Roles que sirven en Cursos (propios del módulo o generales), con su cantidad de usuarios en
 * Cursos: [id, nombre_rol, del_modulo, generico, usuarios].
 */
function cengi_acc_roles_cursos(PDO $m)
{
    $moduloId = cengi_rm_modulo_id($m, 'cursos');
    $roles = cengi_rm_roles_asignables($m, $moduloId, true);
    $stmt = $m->prepare('SELECT CASE WHEN u.es_superadmin = 1 THEN u.rol_id ELSE um.rol_id END AS rol_id, COUNT(*) AS n
        FROM usuario_modulo um INNER JOIN usuarios u ON u.id = um.usuario_id WHERE um.modulo_id = ? GROUP BY 1');
    $stmt->execute([$moduloId]);
    $conteo = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach ($roles as &$r) {
        $r['id'] = (int) $r['id'];
        $r['usuarios'] = $conteo[$r['id']] ?? 0;
        $r['del_modulo'] = (int) $r['del_modulo'];
        $r['generico'] = (int) $r['generico'];
    }
    unset($r);
    return $roles;
}

/** ¿Puede el usuario actual cambiar los permisos de este rol desde Cursos? */
function cengi_acc_puede_editar_rol(array $rol)
{
    if (cengi_rm_norm($rol['nombre_rol']) === 'superadmin') {
        return false; // ya tiene todo
    }
    if (cengi_es_superadmin()) {
        return true;
    }
    // Los roles generales (Administrador) sirven en varios módulos: se cambian en la administración central.
    return !$rol['generico'] && cengi_puede_gestionar_roles();
}

/** ¿Puede asignar este rol a un usuario desde Cursos? (nunca Superadmin; generales solo el superadmin). */
function cengi_acc_puede_asignar_rol(array $rol)
{
    if (cengi_es_gestor_ingenio()) return cengi_acc_es_estudiante($rol['nombre_rol']);
    if (cengi_rm_norm($rol['nombre_rol']) === 'superadmin') {
        return false;
    }
    return cengi_es_superadmin() || !$rol['generico'];
}

function cengi_acc_iniciales($nombre)
{
    $ini = '';
    foreach (preg_split('/\s+/', trim((string) $nombre), -1, PREG_SPLIT_NO_EMPTY) as $parte) {
        $ini .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($ini, 'UTF-8') >= 2) {
            break;
        }
    }
    return $ini !== '' ? $ini : 'U';
}
