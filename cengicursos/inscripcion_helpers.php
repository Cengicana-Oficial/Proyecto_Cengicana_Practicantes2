<?php
/**
 * Inscripciones por convocatoria y pagos de inscripcion (inscripcion.php,
 * guardar_inscripcion.php, inscripciones.php, pagos_inscripcion.php, aprobar.php).
 * Esquema: cengicursos/migrations/20261008_inscripciones_convocatoria.sql.
 *
 * Una solicitud ligada a una planificacion (convocatoria) pasa por:
 *   1. Validacion: tipo_pago 'Ingenio' -> la valida el usuario de ese ingenio
 *      (validacion_ingenio = 'validada'); 'Propio' -> Administracion valida el
 *      comprobante (pago_estado 'validado'); 'Exonerado' (curso sin costo) no requiere.
 *   2. Cupo: cengi_ins_procesar_plan() crea el curso cuando las validadas llegan al cupo
 *      minimo y asigna; con el curso ya creado asigna directo o manda a lista de espera
 *      ('Espera') si se lleno el cupo maximo.
 *   3. Seguimiento del cobro (independiente de la asignacion): Administracion marca
 *      como pagado el pago propio o la orden grupal del ingenio (pagos_grupales).
 */

require_once __DIR__ . '/planificacion_helpers.php';

const CENGI_INS_CUOTAS = [
    'asociado' => ['Asociado', 'colaborador de un ingenio asociado'],
    'externo' => ['Externo', 'otra institución o particular'],
    'extranjero' => ['Extranjero', 'participante de otro país'],
];
const CENGI_INS_TIPO_CAMBIO = 7.75; // Q por US$1, para totales en quetzales
const CENGI_INS_MAX_COMPROBANTE = 5 * 1024 * 1024;
const CENGI_INS_ESTADOS_PAGO = [
    'pendiente' => ['Pendiente de pago', 'is-upcoming'],
    'en revisión' => ['Comprobante por validar', 'is-pending'],
    'validado' => ['Validado · falta marcar pagado', 'is-pending'],
    'pagado' => ['Pagado', 'is-active'],
    'rechazado' => ['Comprobante rechazado', 'is-rejected'],
    'reembolsado' => ['Reembolsado', 'is-neutral'],
    'exonerado' => ['Sin costo', 'is-neutral'],
];
const CENGI_INS_ETAPAS = [
    'pend-ingenio' => ['Pendiente de validación del ingenio', 'is-upcoming'],
    'no-ingenio' => ['No validado por el ingenio', 'is-rejected'],
    'sin-comprobante' => ['Esperando comprobante', 'is-upcoming'],
    'pago-revision' => ['Comprobante por validar', 'is-pending'],
    'pago-rechazado' => ['Comprobante rechazado', 'is-rejected'],
    'reembolsado' => ['Reembolsado', 'is-neutral'],
    'validado' => ['Validado · esperando cupo', 'is-pending'],
    'asignado' => ['Asignado al curso', 'is-active'],
    'espera' => ['Lista de espera', 'is-pending'],
    'rechazado' => ['Inscripción rechazada', 'is-neutral'],
];

// ---------------------------------------------------------------- permisos

function cengi_ins_puede_validar_pagos()
{
    return !cengi_es_gestor_ingenio() && (cengi_es_admin() || cengi_tiene_permiso('validar_pagos_cengi'));
}

function cengi_ins_delega_cengicana() {
    return !cengi_es_gestor_ingenio() && cengi_es_ingenio_cengicana() && (cengi_puede_gestionar() || cengi_es_gestor() || cengi_puede_aprobar_solicitudes());
}

function cengi_ins_puede_ver_pagos()
{
    return cengi_ins_puede_validar_pagos() || cengi_tiene_permiso('ver_pagos_cengi') || cengi_es_administrador_ingenio() || cengi_puede_gestionar() || cengi_ins_delega_cengicana();
}

/** El usuario de un ingenio valida solo a los inscritos de su ingenio; el administrador, a todos. */
function cengi_ins_puede_validar_ingenio($nombreIngenio)
{
    return cengi_es_admin()
        || cengi_ins_delega_cengicana()
        || (cengi_puede_gestionar() && (cengi_ve_todo_por_rol_o_ingenio() || cengi_texto_normalizado($nombreIngenio) === cengi_texto_normalizado(cengi_ingenio_nombre_actual())))
        || (cengi_es_ingenio() && cengi_texto_normalizado($nombreIngenio) === cengi_texto_normalizado(cengi_ingenio_nombre_actual()));
}

/** Nombre del ingenio al que se limita la vista (usuarios de ingenio sin permisos centrales), o null. */
function cengi_ins_ingenio_limitado()
{
    if (cengi_es_gestor_ingenio()) return cengi_ingenio_nombre_actual();
    if (cengi_ins_puede_validar_pagos() || cengi_es_admin() || cengi_ve_todo_por_rol_o_ingenio() || cengi_ins_delega_cengicana()) {
        return null;
    }
    return cengi_ingenio_nombre_actual();
}

// ---------------------------------------------------------------- cuotas y estado

/** [monto, moneda] de una cuota segun los costos del plan. */
function cengi_ins_monto(array $plan, $cuota)
{
    if ($cuota === 'extranjero') {
        return [(float) $plan['costo_extranjero'], 'USD'];
    }
    return [(float) ($cuota === 'externo' ? $plan['costo_externos'] : $plan['costo_asociados']), 'GTQ'];
}

function cengi_ins_dinero($monto, $moneda)
{
    return ($moneda === 'USD' ? 'US$' : 'Q') . number_format((float) $monto, 2);
}

function cengi_ins_en_quetzales($monto, $moneda)
{
    return (float) $monto * ($moneda === 'USD' ? CENGI_INS_TIPO_CAMBIO : 1);
}

/** Condicion SQL (alias s) de "inscripcion validada" (cuenta para el cupo y se puede asignar). */
function cengi_ins_sql_validada($alias = 's')
{
    return "($alias.tipo_pago = 'Exonerado' OR ($alias.tipo_pago='Propio' AND $alias.pago_estado='pagado') OR ($alias.tipo_pago='Ingenio' AND $alias.validacion_ingenio='validada'))";

}

/** ['ok' => bool, 'texto' => ..., 'accion' => 'ingenio'|'pago'|null] */
function cengi_ins_requisito(array $s)
{
    if ($s['tipo_pago'] === 'Exonerado') {
        return ['ok' => true, 'texto' => 'Sin costo', 'accion' => null];
    }
    if ($s['tipo_pago'] === 'Ingenio') {
        if ($s['validacion_ingenio'] === 'validada') {
            return ['ok' => true, 'texto' => 'Validado por el ingenio · ' . $s['validacion_ingenio_por'], 'accion' => null];
        }
        if ($s['validacion_ingenio'] === 'rechazada') {
            return ['ok' => false, 'texto' => 'No validado por el ingenio', 'accion' => 'ingenio'];
        }
        return ['ok' => false, 'texto' => 'Pendiente de validación del ingenio', 'accion' => 'ingenio'];
    }
    switch ($s['pago_estado']) {
        case 'validado':
        case 'pagado':
            return ['ok' => true, 'texto' => 'Validado por Administración · ' . $s['pago_verificado_por'], 'accion' => null];
        case 'en revisión':
            return ['ok' => false, 'texto' => 'Comprobante por validar (Administración)', 'accion' => 'pago'];
        case 'rechazado':
            return ['ok' => false, 'texto' => 'Comprobante rechazado', 'accion' => 'pago'];
        case 'reembolsado':
            return ['ok' => false, 'texto' => 'Reembolsado', 'accion' => null];
        default:
            return ['ok' => false, 'texto' => 'Sin comprobante de pago', 'accion' => 'pago'];
    }
}

function cengi_ins_etapa(array $s)
{
    if ($s['estado'] === 'Rechazado') {
        return 'rechazado';
    }
    if ($s['estado'] === 'Aprobado') {
        return 'asignado';
    }
    if ($s['estado'] === 'Espera') {
        return 'espera';
    }
    if (cengi_ins_requisito($s)['ok']) {
        return 'validado';
    }
    if ($s['tipo_pago'] === 'Ingenio') {
        return $s['validacion_ingenio'] === 'rechazada' ? 'no-ingenio' : 'pend-ingenio';
    }
    return ['en revisión' => 'pago-revision', 'rechazado' => 'pago-rechazado', 'reembolsado' => 'reembolsado'][$s['pago_estado']] ?? 'sin-comprobante';
}

/** Estado del cobro: el del pago propio, el de la orden grupal del ingenio o "sin costo". */
function cengi_ins_estado_cobro(array $s)
{
    if ($s['tipo_pago'] === 'Exonerado') {
        return 'exonerado';
    }
    if ($s['tipo_pago'] === 'Ingenio') {
        return $s['grupo_estado'] ?? ($s['pago_estado'] ?: 'pendiente');
    }
    return $s['pago_estado'] ?: 'pendiente';
}

function cengi_ins_exigir_pago_propio_validado(array $s) {
    if($s['tipo_pago']!=='Propio'||$s['pago_estado']!=='validado') throw new InvalidArgumentException('Administración debe revisar y validar el comprobante antes de registrar el ingreso.');
}

/** Solicitud con datos de plan, ingenio y grupo (para paginas y correos). */
function cengi_ins_solicitud(PDO $db, $id, $bloquear = false)
{
    $stmt = $db->prepare("SELECT s.*, i.nombre_ingenios, COALESCE(p.nombre, c.nombre_cursos) AS plan_nombre, p.anio, g.estado AS grupo_estado, g.orden AS grupo_orden
        FROM solicitudes_inscripcion s
        LEFT JOIN ingenios i ON i.id = s.ingenio_id
        LEFT JOIN planificacion_cursos p ON p.id = s.planificacion_id
        LEFT JOIN cursos c ON c.id = s.curso_id
        LEFT JOIN pagos_grupales g ON g.id = s.grupo_pago_id
        WHERE s.id_solicitud = ?" . ($bloquear ? ' FOR UPDATE' : ''));
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------------------------------------------------------------- comprobantes

/**
 * Guarda un comprobante (PDF, JPG o PNG, hasta 5 MB) con nombre aleatorio y devuelve
 * [ruta, nombre original]. Lanza InvalidArgumentException con un mensaje para el usuario.
 */
function cengi_ins_guardar_comprobante(array $archivo, $prefijo)
{
    if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        throw new InvalidArgumentException(in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'El comprobante supera el tamaño permitido.' : 'No se pudo recibir el comprobante.');
    }
    if ($archivo['size'] > CENGI_INS_MAX_COMPROBANTE) {
        throw new InvalidArgumentException('El comprobante supera los 5 MB.');
    }
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if (!isset($mimes[$extension]) || $mimes[$extension] !== $mime) {
        throw new InvalidArgumentException('El comprobante debe ser un PDF, JPG o PNG.');
    }
    $ruta = cengi_guardar_archivo_subido($archivo['tmp_name'], 'comprobantes_inscripcion', $prefijo . '_' . bin2hex(random_bytes(12)) . '.' . $extension);
    if ($ruta === null) {
        throw new InvalidArgumentException('No fue posible guardar el comprobante. Intenta más tarde.');
    }
    return [$ruta, mb_substr(basename($archivo['name']), 0, 255, 'UTF-8')];
}

// ---------------------------------------------------------------- pagos grupales del ingenio

/**
 * Orden grupal abierta (no pagada) del ingenio para el curso; la crea si no existe.
 * No usa las ordenes de proformas (proforma_id): a esas se amarra con cengi_pf_amarrar().
 */
function cengi_ins_grupo_abierto(PDO $db, $planId, $ingenioId)
{
    $stmt = $db->prepare("SELECT id FROM pagos_grupales WHERE planificacion_id = ? AND ingenio_id = ? AND estado <> 'pagado' AND proforma_id IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int) $planId, (int) $ingenioId]);
    $id = (int) $stmt->fetchColumn();
    if (!$id) {
        $db->prepare('INSERT INTO pagos_grupales (planificacion_id, ingenio_id) VALUES (?, ?)')->execute([(int) $planId, (int) $ingenioId]);
        $id = (int) $db->lastInsertId();
    }
    return $id;
}

/** Monto de la orden = suma de las cuotas de sus participantes (las de proforma tienen monto fijo). */
function cengi_ins_recalcular_grupo(PDO $db, $grupoId)
{
    if (!$grupoId) {
        return;
    }
    $db->prepare("UPDATE pagos_grupales SET monto = (SELECT COALESCE(SUM(monto), 0) FROM solicitudes_inscripcion
        WHERE grupo_pago_id = ? AND estado <> 'Rechazado') WHERE id = ? AND proforma_id IS NULL")->execute([(int) $grupoId, (int) $grupoId]);
}

// ---------------------------------------------------------------- asignacion al curso

function cengi_buscar_rol_estudiante(mysqli $conexionUsuarios)
{
    $stmtRol = $conexionUsuarios->prepare("
        SELECT id, nombre_rol
        FROM roles
        WHERE LOWER(nombre_rol) IN ('estudiante', 'alumno', 'participante')
        ORDER BY
            CASE LOWER(nombre_rol)
                WHEN 'estudiante' THEN 1
                WHEN 'alumno' THEN 2
                ELSE 3
            END
        LIMIT 1
    ");
    $stmtRol->execute();
    $resultado = $stmtRol->get_result();

    return $resultado ? $resultado->fetch_assoc() : null;
}

function cengi_buscar_modulo_cursos(mysqli $conexionUsuarios)
{
    $stmtModulo = $conexionUsuarios->prepare("
        SELECT id
        FROM modulos
        WHERE LOWER(nombre) IN ('cursos', 'cengicursos')
        ORDER BY id
        LIMIT 1
    ");
    $stmtModulo->execute();
    $resultado = $stmtModulo->get_result();
    $fila = $resultado ? $resultado->fetch_assoc() : null;

    return (int) ($fila['id'] ?? 0);
}

/**
 * Contrasena inicial de la cuenta de estudiante: aleatoria y nadie la conoce. El estudiante
 * crea la suya con el enlace que se le envia al ser asignado (cengi_ins_enlace_cuenta()).
 * Antes era su CUI: con el DPI y el correo cualquiera podia entrar a su cuenta.
 */
function cengi_password_temporal_estudiante(array $solicitud)
{
    return bin2hex(random_bytes(24));
}

/** Vigencia del enlace para crear la contrasena de una cuenta nueva de estudiante. */
const CENGI_INS_ENLACE_CUENTA_TTL = 7 * 24 * 3600;

/**
 * Genera el enlace de login/reset_password.php para que el estudiante nuevo cree su
 * contrasena (misma tabla password_resets que "Olvide mi contrasena") y lo guarda para los
 * correos de esta peticion.
 */
function cengi_ins_generar_enlace_cuenta(mysqli $conexionUsuarios, $usuarioId, $correo)
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expira = date('Y-m-d H:i:s', time() + CENGI_INS_ENLACE_CUENTA_TTL);
    $stmt = $conexionUsuarios->prepare('INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip_solicitud) VALUES (?, ?, ?, NULL)');
    $stmt->bind_param('iss', $usuarioId, $tokenHash, $expira);
    $stmt->execute();

    $env = function_exists('cengicursos_env') ? cengicursos_env() : [];
    if (!empty($env['APP_URL'])) {
        $base = rtrim((string) $env['APP_URL'], '/');
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $GLOBALS['cengi_ins_enlaces_cuenta'][mb_strtolower(trim((string) $correo), 'UTF-8')] = $base . '/login/reset_password.php?token=' . $token;
}

/** Enlace para crear la contrasena si en esta peticion se creo la cuenta de ese correo; '' si no. */
function cengi_ins_enlace_cuenta($correo)
{
    return $GLOBALS['cengi_ins_enlaces_cuenta'][mb_strtolower(trim((string) $correo), 'UTF-8')] ?? '';
}

function cengi_asegurar_ingenio_en_menu(mysqli $conexionUsuarios, $ingenioId, $nombreIngenio = '')
{
    $ingenioId = (int) $ingenioId;

    if ($ingenioId > 0) {
        $stmtExiste = $conexionUsuarios->prepare("SELECT id FROM ingenios WHERE id = ? LIMIT 1");
        $stmtExiste->bind_param('i', $ingenioId);
        $stmtExiste->execute();
        $resultado = $stmtExiste->get_result();
        $fila = $resultado ? $resultado->fetch_assoc() : null;

        if ($fila) {
            return (int) $fila['id'];
        }
    }

    $nombre = trim((string) $nombreIngenio);
    if ($nombre === '') {
        $nombre = 'Ingenio sin nombre';
    }

    $stmtPorNombre = $conexionUsuarios->prepare(
        "SELECT id FROM ingenios WHERE LOWER(nombre_ingenio) = LOWER(?) LIMIT 1"
    );
    $stmtPorNombre->bind_param('s', $nombre);
    $stmtPorNombre->execute();
    $resultadoNombre = $stmtPorNombre->get_result();
    $filaNombre = $resultadoNombre ? $resultadoNombre->fetch_assoc() : null;

    if ($filaNombre) {
        return (int) $filaNombre['id'];
    }

    $stmtInsert = $conexionUsuarios->prepare(
        "INSERT INTO ingenios (nombre_ingenio, estado) VALUES (?, 1)"
    );
    $stmtInsert->bind_param('s', $nombre);
    $stmtInsert->execute();

    return (int) $conexionUsuarios->insert_id;
}

function cengi_crear_o_asociar_usuario_estudiante(array $solicitud)
{
    $correo = trim((string) ($solicitud['correo'] ?? ''));

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $conexionUsuarios = conectar_usuarios_menu();
    $rolEstudiante = cengi_buscar_rol_estudiante($conexionUsuarios);

    if (!$rolEstudiante) {
        return 0;
    }

    $stmtUsuario = $conexionUsuarios->prepare("
        SELECT id
        FROM usuarios
        WHERE correo = ?
        LIMIT 1
    ");
    $stmtUsuario->bind_param('s', $correo);
    $stmtUsuario->execute();
    $resultadoUsuario = $stmtUsuario->get_result();
    $usuario = $resultadoUsuario ? $resultadoUsuario->fetch_assoc() : null;

    if ($usuario) {
        $usuarioId = (int) $usuario['id'];
    } else {
        $nombre = trim((string) ($solicitud['nombre_participante'] ?? ''));
        $contrasenaHash = password_hash(cengi_password_temporal_estudiante($solicitud), PASSWORD_DEFAULT);
        $rolId = (int) $rolEstudiante['id'];
        $ingenioId = cengi_asegurar_ingenio_en_menu(
            $conexionUsuarios,
            $solicitud['ingenio_id'] ?? null,
            $solicitud['nombre_ingenios'] ?? ($solicitud['nombre_ingenio'] ?? '')
        );
        $esSuperadmin = 0;

        $stmtInsertUsuario = $conexionUsuarios->prepare("
            INSERT INTO usuarios (nombre, correo, contrasena, rol_id, ingenio_id, es_superadmin)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtInsertUsuario->bind_param('sssiii', $nombre, $correo, $contrasenaHash, $rolId, $ingenioId, $esSuperadmin);
        $stmtInsertUsuario->execute();
        $usuarioId = (int) $conexionUsuarios->insert_id;
        $GLOBALS['cengi_ins_cuentas_creadas'][] = ['usuario_id' => $usuarioId, 'modulo_id' => 0, 'cuenta_nueva' => true];
        cengi_ins_generar_enlace_cuenta($conexionUsuarios, $usuarioId, $correo);
    }

    // Acceso a Cursos con rol Estudiante (rol por modulo). Si ya tenia rol en Cursos
    // (p. ej. Instructor) se respeta; sus roles en otros modulos no cambian.
    $moduloCursosId = cengi_buscar_modulo_cursos($conexionUsuarios);
    if ($moduloCursosId > 0) {
        $rolEstudianteId = (int) $rolEstudiante['id'];
        $stmtVinculo = $conexionUsuarios->prepare("
            INSERT INTO usuario_modulo (usuario_id, modulo_id, rol_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE rol_id = COALESCE(rol_id, VALUES(rol_id))
        ");
        $stmtVinculo->bind_param('iii', $usuarioId, $moduloCursosId, $rolEstudianteId);
        $stmtVinculo->execute();
        if ($stmtVinculo->affected_rows === 1) {
            // Fila nueva (con ON DUPLICATE KEY, 2 = actualizada): el acceso a Cursos se dio aqui.
            $GLOBALS['cengi_ins_cuentas_creadas'][] = ['usuario_id' => $usuarioId, 'modulo_id' => $moduloCursosId, 'cuenta_nueva' => false];
        }
    }

    return $usuarioId;
}

/**
 * La cuenta de estudiante se crea en usuarios_menu (otra base, con mysqli), fuera de la
 * transaccion de cengi_cursos que asigna la inscripcion. Llamar despues de cada rollBack()
 * de una transaccion que pudo asignar inscripciones: deshace las cuentas y accesos a Cursos
 * creados en esta peticion que ya no usa ningun participante ni asignacion confirmados (si
 * otra transaccion de la misma peticion ya los confirmo, se conservan).
 */
function cengi_ins_deshacer_cuentas_creadas(PDO $db)
{
    $creadas = $GLOBALS['cengi_ins_cuentas_creadas'] ?? [];
    $GLOBALS['cengi_ins_cuentas_creadas'] = [];
    // Los enlaces de cuentas que se deshacen ya no sirven (su token se borra con el usuario).
    $GLOBALS['cengi_ins_enlaces_cuenta'] = [];
    if (!$creadas) {
        return;
    }
    try {
        $enUso = $db->prepare('SELECT (SELECT COUNT(*) FROM participantes WHERE usuarios_id = ?) + (SELECT COUNT(*) FROM asignaciones WHERE usuarios_id = ?)');
        $menu = conectar_usuarios_menu();
        foreach (array_reverse($creadas) as $c) {
            $usuarioId = (int) $c['usuario_id'];
            $enUso->execute([$usuarioId, $usuarioId]);
            if ((int) $enUso->fetchColumn() > 0) {
                continue;
            }
            if ($c['cuenta_nueva']) {
                $stmt = $menu->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ?');
                $stmt->bind_param('i', $usuarioId);
                $stmt->execute();
                $stmt = $menu->prepare('DELETE FROM usuarios WHERE id = ? AND es_superadmin = 0');
                $stmt->bind_param('i', $usuarioId);
                $stmt->execute();
            } else {
                $moduloId = (int) $c['modulo_id'];
                $stmt = $menu->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?');
                $stmt->bind_param('ii', $usuarioId, $moduloId);
                $stmt->execute();
            }
        }
    } catch (Throwable $e) {
        error_log('No se pudieron deshacer las cuentas de estudiante creadas: ' . $e->getMessage());
    }
}

/**
 * Asigna la solicitud a su curso: crea o actualiza el participante (por CUI), su usuario
 * de estudiante y la asignacion, y marca la solicitud como 'Aprobado'. Debe llamarse
 * dentro de una transaccion; no envia correos.
 */
function cengi_ins_asignar(PDO $db, array $solicitud, $por)
{
    if(!cengi_ins_admitida($solicitud))throw new InvalidArgumentException('Primero debe aceptar el ingenio o Administración debe aceptar el pago propio.');
    if (!cengi_ins_requisito($solicitud)['ok']) throw new InvalidArgumentException('Primero valida el patrocinio del ingenio o el pago propio.');
    if ($solicitud['tipo_pago'] === 'Propio' && $solicitud['monto'] === null) throw new InvalidArgumentException('Primero define la cuota de esta inscripción.');
    $cursoId = (int) $solicitud['curso_id'];
    if ($cursoId <= 0) {
        throw new RuntimeException('La solicitud no tiene un curso asignado.');
    }
    $stmt = $db->prepare('SELECT id FROM participantes WHERE cui_participantes = ? LIMIT 1');
    $stmt->execute([$solicitud['cui_participante']]);
    $participanteId = (int) $stmt->fetchColumn();
    $ingenioId = (int) $solicitud['ingenio_id'] ?: cengi_curso_ingenio_por_defecto($db);
    $datos = [$ingenioId, $solicitud['nombre_participante'], $solicitud['puesto_participante'], $solicitud['area_participante'],
        $solicitud['correo'], $solicitud['grado_academico'], $solicitud['telefono']];
    if ($participanteId) {
        $db->prepare('UPDATE participantes SET ingenio_id = ?, nombre_participantes = ?, puesto_participantes = ?, area_participantes = ?,
            correo_participantes = ?, grado_academico_participantes = ?, telefono_participantes = ?, estado_participantes = 1, actualizado = NOW() WHERE id = ?')
            ->execute(array_merge($datos, [$participanteId]));
    } else {
        $db->prepare('INSERT INTO participantes (ingenio_id, usuarios_id, cui_participantes, nombre_participantes, puesto_participantes, area_participantes,
            correo_participantes, grado_academico_participantes, telefono_participantes, estado_participantes, creado) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 1, NOW())')
            ->execute([$ingenioId, $solicitud['cui_participante'], $solicitud['nombre_participante'], $solicitud['puesto_participante'],
                $solicitud['area_participante'], $solicitud['correo'], $solicitud['grado_academico'], $solicitud['telefono']]);
        $participanteId = (int) $db->lastInsertId();
    }

    $usuarioId = 0;
    try {
        $usuarioId = cengi_crear_o_asociar_usuario_estudiante($solicitud);
    } catch (Throwable $e) {
        // La cuenta de estudiante es un extra: si usuarios_menu falla, la asignacion sigue.
        error_log('No se pudo crear el usuario de estudiante para la solicitud ' . $solicitud['id_solicitud'] . ': ' . $e->getMessage());
    }
    if ($usuarioId > 0) {
        $db->prepare('UPDATE participantes SET usuarios_id = ?, actualizado = NOW() WHERE id = ?')->execute([$usuarioId, $participanteId]);
    }

    $stmt = $db->prepare('SELECT id FROM asignaciones WHERE participantes_id = ? AND cursos_id = ? LIMIT 1');
    $stmt->execute([$participanteId, $cursoId]);
    $asignacionId = (int) $stmt->fetchColumn();
    if ($asignacionId) {
        $db->prepare('UPDATE asignaciones SET usuarios_id = ?, estado_asignaciones = 1, actualizado = NOW() WHERE id = ?')
            ->execute([$usuarioId ?: null, $asignacionId]);
    } else {
        $db->prepare('INSERT INTO asignaciones (participantes_id, usuarios_id, cursos_id, estado_asignaciones, creado) VALUES (?, ?, ?, 1, NOW())')
            ->execute([$participanteId, $usuarioId ?: null, $cursoId]);
    }
    $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Aprobado', asignado_por = ?, asignado_en = NOW() WHERE id_solicitud = ?")
        ->execute([mb_substr((string) $por, 0, 255, 'UTF-8'), (int) $solicitud['id_solicitud']]);
    return $participanteId;
}

function cengi_ins_admitida(array $s) {
    if($s['estado']==='Rechazado')return false;
    if($s['tipo_pago']==='Exonerado')return true;
    if($s['tipo_pago']==='Ingenio')return ($s['validacion_ingenio']??'')==='validada';
    return $s['tipo_pago']==='Propio' && cengi_ins_estado_cobro($s)==='pagado';
}

/** Reconoce también inscritos históricos cuyo pago propio todavía debe conciliarse. */
function cengi_ins_inscrito(array $s) {
    return cengi_ins_admitida($s);
}

/** Quita la asignacion (desactiva) y devuelve la solicitud a "validada, por asignar". */
function cengi_ins_quitar_asignacion(PDO $db, array $solicitud)
{
    $db->prepare('UPDATE asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id
        SET a.estado_asignaciones = 0, a.actualizado = NOW() WHERE a.cursos_id = ? AND p.cui_participantes = ?')
        ->execute([(int) $solicitud['curso_id'], $solicitud['cui_participante']]);
    $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Pendiente', asignado_por = '', asignado_en = NULL WHERE id_solicitud = ?")
        ->execute([(int) $solicitud['id_solicitud']]);
}

// ---------------------------------------------------------------- cupo: paso a Cursos y asignacion automatica

/**
 * Revisa la convocatoria despues de cada validacion o cambio:
 *   - sin curso: si las validadas llegan al cupo minimo, crea el curso (pasa a Cursos);
 *   - con curso: asigna las validadas mientras haya cupo (cupo maximo) y manda el resto a
 *     lista de espera; si se libera un lugar, entra la primera de la lista de espera.
 * Encola los correos (asignacion / lista de espera); cengi_ins_enviar_cola() los envia
 * despues del commit. Devuelve ['curso_creado' => codigo|null, 'asignadas' => n, 'espera' => n].
 */
function cengi_ins_procesar_plan(PDO $db, $planId, $por = 'Sistema (cupo de la convocatoria)')
{
    $resultado = ['curso_creado' => null, 'asignadas' => 0, 'espera' => 0];
    $plan = cengi_pp_plan($db, $planId);
    if (!$plan || !cengi_pp_convocado($plan)) {
        return $resultado;
    }
    $stmt = $db->prepare("SELECT s.id_solicitud FROM solicitudes_inscripcion s
        WHERE s.planificacion_id = ? AND s.estado IN ('Pendiente', 'Espera') AND " . cengi_ins_sql_validada('s') . "
        ORDER BY s.estado = 'Pendiente', COALESCE(s.validacion_ingenio_en, s.pago_verificado_en, s.creado), s.id_solicitud");
    $stmt->execute([(int) $plan['id']]);
    $validadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($plan['curso_id'])) {
        if (count($validadas) < max(1, (int) $plan['cupo_minimo'])) {
            return $resultado;
        }
        $resultado['curso_creado'] = cengi_pp_crear_curso($db, $plan, 'se alcanzó el cupo mínimo de ' . (int) $plan['cupo_minimo'] . ' inscripciones validadas');
        $plan = cengi_pp_plan($db, $planId);
        $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'curso', 'plan' => $plan];
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM asignaciones WHERE cursos_id = ? AND estado_asignaciones = 1');
    $stmt->execute([(int) $plan['curso_id']]);
    $asignados = (int) $stmt->fetchColumn();
    $maximo = $plan['cupo_maximo'] ? (int) $plan['cupo_maximo'] : PHP_INT_MAX;
    foreach ($validadas as $id) {
        $s = cengi_ins_solicitud($db, $id, true);
        $s['curso_id'] = $plan['curso_id'];
        if ($asignados < $maximo) {
            cengi_ins_asignar($db, $s, $por);
            $asignados++;
            $resultado['asignadas']++;
            $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'asignado', 'plan' => $plan, 'solicitud' => $s];
        } elseif ($s['estado'] === 'Pendiente') {
            $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Espera' WHERE id_solicitud = ?")->execute([(int) $id]);
            $resultado['espera']++;
            $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'espera', 'plan' => $plan, 'solicitud' => $s];
        }
    }
    return $resultado;
}

/** Envia los correos encolados por cengi_ins_procesar_plan() (llamar despues del commit). */
function cengi_ins_enviar_cola(PDO $db)
{
    $cola = $GLOBALS['cengi_ins_cola'] ?? [];
    $GLOBALS['cengi_ins_cola'] = [];
    foreach ($cola as $item) {
        if ($item['tipo'] === 'curso') {
            continue;
        }
        $plan = cengi_pp_plan($db, $item['plan']['id']);
        $s = $item['solicitud'];
        $asignado = $item['tipo'] === 'asignado';
        $enlaceCuenta = $asignado ? cengi_ins_enlace_cuenta($s['correo']) : '';
        $textoCuenta = $enlaceCuenta !== ''
            ? "\n\nSe creó tu cuenta en la plataforma de cursos con este correo. Crea tu contraseña en el siguiente enlace (vence en 7 días; después puedes usar «¿Olvidaste tu contraseña?» en el inicio de sesión):\n" . $enlaceCuenta
            : '';
        try {
            cengi_pp_enviar_comunicacion($db, [
                'categoria' => $asignado ? 'Asignación al curso' : 'Lista de espera', 'origen' => 'sistema', 'planificacion_id' => (int) $plan['id'],
                'asunto' => ($asignado ? 'Asignado al curso: ' : 'Lista de espera: ') . $plan['nombre'],
                'mensaje' => "Hola {nombre}:\n\n" . ($asignado
                    ? 'Tu inscripción fue validada y ya estás asignado a ' . $plan['nombre'] . ($plan['codigo_curso'] ? ' (' . $plan['codigo_curso'] . ')' : '') . ".\n\nProgramación vigente:\n" . cengi_pp_programacion_vigente($db, $plan)
                        . "\n\nRecibirás un aviso si cambia la fecha, el horario, el lugar o el instructor." . $textoCuenta
                    :'Tu inscripción a ' . $plan['nombre'] . " fue validada, pero el curso ya completó su cupo. Quedaste en la lista de espera: te avisaremos si se libera un lugar.")
                    . "\n\nCoordinación de Capacitación — CENGICAÑA",
            ], [['nombre' => $s['nombre_participante'], 'correo' => $s['correo']]]);
        } catch (Throwable $e) {
            error_log('No se pudo enviar el aviso de inscripción ' . $s['id_solicitud'] . ': ' . $e->getMessage());
        }
    }
}

/** Usuarios del menu con rol de ingenio para un ingenio (para pedirles la validacion). */
function cengi_ins_usuarios_ingenio($nombreIngenio)
{
    try {
        // Rol de ingenio EN CURSOS (usuario_modulo.rol_id), no su rol en otros modulos.
        $stmt = conectar_usuarios_menu_pdo()->query("SELECT u.nombre, u.correo, i.nombre_ingenio FROM usuarios u
            INNER JOIN usuario_modulo um ON um.usuario_id = u.id
            INNER JOIN modulos m ON m.id = um.modulo_id AND LOWER(m.nombre) IN ('cursos', 'cengicursos')
            INNER JOIN roles r ON r.id = um.rol_id INNER JOIN ingenios i ON i.id = u.ingenio_id
            WHERE LOWER(r.nombre_rol) LIKE '%ingenio%' AND u.correo <> ''");
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static function ($u) use ($nombreIngenio) {
            return cengi_texto_normalizado($u['nombre_ingenio']) === cengi_texto_normalizado($nombreIngenio);
        }));
    } catch (Throwable $e) {
        error_log('No se pudieron consultar los usuarios del ingenio: ' . $e->getMessage());
        return [];
    }
}
