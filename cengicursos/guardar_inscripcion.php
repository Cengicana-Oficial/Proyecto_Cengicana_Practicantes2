<?php
/**
 * Guarda la solicitud del formulario publico (inscripcion.php).
 *   destino "p:<id>" -> convocatoria de una planificacion: calcula cuota y monto, registra
 *                       quien paga (Ingenio/Propio/Exonerado) y el comprobante, y la deja
 *                       por validar en pagos_inscripcion.php (ver inscripcion_helpers.php).
 *   destino "c:<id>" -> curso creado directamente: flujo anterior (solicitudes.php).
 */
require_once __DIR__ . "/inscripcion_helpers.php";
require_once __DIR__ . "/proformas_helpers.php";
require_once __DIR__ . "/correo.php";

function inscripcion_error($mensaje, $destino = '')
{
    $extra = strpos($destino, 'p:') === 0 ? '&plan=' . (int) substr($destino, 2) : '';
    header("Location: inscripcion.php?resultado=error&mensaje=" . rawurlencode($mensaje) . $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: inscripcion.php");
    exit;
}

$db = conectar();

$nombreParticipante = trim((string) ($_POST['nombre_participante'] ?? ''));
$cuiParticipante = trim((string) ($_POST['cui_participante'] ?? ''));
$puestoParticipante = trim((string) ($_POST['puesto_participante'] ?? ''));
$areaParticipante = trim((string) ($_POST['area_participante'] ?? ''));
$gradoAcademico = trim((string) ($_POST['grado_academico'] ?? ''));
$correo = trim((string) ($_POST['correo'] ?? ''));
$telefono = trim((string) ($_POST['telefono'] ?? ''));
$ingenioTexto = trim((string) ($_POST['ingenio_id'] ?? ''));
$institucionOtra = mb_substr(trim((string) ($_POST['institucion_otra'] ?? '')), 0, 255, 'UTF-8');
$tipoPago = trim((string) ($_POST['tipo_pago'] ?? ''));
// "curso_id" se acepta por compatibilidad con formularios abiertos antes del cambio.
$destino = trim((string) ($_POST['destino'] ?? (isset($_POST['curso_id']) ? 'c:' . (int) $_POST['curso_id'] : '')));

if ($nombreParticipante === '' || $puestoParticipante === '' || $areaParticipante === '' || $gradoAcademico === '' || $telefono === '') {
    inscripcion_error('Completa todos los campos obligatorios.', $destino);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    inscripcion_error('El correo electrónico no es válido.', $destino);
}

$ingenioId = ctype_digit($ingenioTexto) ? (int) $ingenioTexto : 0;
$nombreIngenio = '';
if ($ingenioId > 0) {
    $stmtIngenio = $db->prepare("SELECT nombre_ingenios FROM ingenios WHERE id = ?");
    $stmtIngenio->execute([$ingenioId]);
    $nombreIngenio = $stmtIngenio->fetchColumn();
    if ($nombreIngenio === false) {
        inscripcion_error('El ingenio seleccionado no es válido.', $destino);
    }
} elseif ($ingenioTexto === 'otra' && strpos($destino, 'p:') === 0) {
    if ($institucionOtra === '') {
        inscripcion_error('Escribe el nombre de tu institución.', $destino);
    }
} else {
    inscripcion_error('Selecciona un ingenio o institución.', $destino);
}

// El CUI es opcional: si el participante no lo proporciona se le asigna un
// correlativo generado con formato "NNNND" que continua a partir del ultimo
// usado (ver cengi_cui_nd_generar() en conexion.php).
if ($cuiParticipante === '') {
    $cuiParticipante = cengi_cui_nd_generar($db);
}

// ------------------------------------------------------------ convocatoria (planificacion)
if (strpos($destino, 'p:') === 0) {
    $plan = cengi_pp_plan($db, (int) substr($destino, 2));
    if (!$plan || !cengi_pp_convocado($plan) || $plan['fecha_fin'] < cengi_pp_hoy()) {
        inscripcion_error('La convocatoria de este curso ya no está abierta.');
    }
    $duplicado = $db->prepare("SELECT COUNT(*) FROM solicitudes_inscripcion WHERE planificacion_id = ? AND LOWER(correo) = LOWER(?) AND estado <> 'Rechazado'");
    $duplicado->execute([$plan['id'], $correo]);
    if ((int) $duplicado->fetchColumn() > 0) {
        inscripcion_error('Ese correo ya está inscrito en este curso.', $destino);
    }

    $cuota = array_key_exists($_POST['cuota'] ?? '', CENGI_INS_CUOTAS) ? $_POST['cuota'] : ($ingenioId ? 'asociado' : 'externo');
    [$monto, $moneda] = cengi_ins_monto($plan, $cuota);
    if ($monto <= 0) {
        $tipoPago = 'Exonerado';
    } elseif ($tipoPago === 'Ingenio' && !$ingenioId) {
        inscripcion_error('El pago por ingenio solo aplica si seleccionas tu ingenio. Elige pago propio.', $destino);
    } elseif (!in_array($tipoPago, ['Ingenio', 'Propio'], true)) {
        inscripcion_error('Indica quién paga la inscripción.', $destino);
    }

    $boleta = mb_substr(trim((string) ($_POST['boleta'] ?? '')), 0, 60, 'UTF-8');
    $pagoFecha = trim((string) ($_POST['pago_fecha'] ?? ''));
    $montoComprobante = trim((string) ($_POST['monto_comprobante'] ?? ''));
    $comprobante = [null, ''];
    $archivo = $_FILES['comprobante'] ?? null;
    if ($tipoPago === 'Propio' && $archivo && $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($boleta === '' || !cengi_pp_es_fecha($pagoFecha) || $pagoFecha > cengi_pp_hoy() || !is_numeric($montoComprobante) || (float) $montoComprobante <= 0) {
            inscripcion_error('Con el comprobante indica el número de boleta, la fecha del pago y el monto pagado.', $destino);
        }
        try {
            $comprobante = cengi_ins_guardar_comprobante($archivo, 'p' . (int) $plan['id']);
        } catch (InvalidArgumentException $e) {
            inscripcion_error($e->getMessage(), $destino);
        }
    }

    try {
        $db->beginTransaction();
        $grupoId = $tipoPago === 'Ingenio' ? cengi_ins_grupo_abierto($db, $plan['id'], $ingenioId) : null;
        $db->prepare("INSERT INTO solicitudes_inscripcion (nombre_participante, cui_participante, puesto_participante, area_participante, grado_academico,
                correo, telefono, ingenio_id, institucion_otra, curso_id, planificacion_id, tipo_pago, como_se_entero, cuota, monto, moneda,
                pago_estado, boleta, pago_fecha, monto_comprobante, comprobante_ruta, comprobante_nombre, validacion_ingenio, grupo_pago_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
            $nombreParticipante, $cuiParticipante, $puestoParticipante, $areaParticipante, $gradoAcademico, $correo, $telefono,
            $ingenioId ?: null, $institucionOtra, $plan['curso_id'] ?: null, $plan['id'], $tipoPago,
            mb_substr(trim((string) ($_POST['como_se_entero'] ?? '')), 0, 60, 'UTF-8'), $cuota, $monto, $moneda,
            $tipoPago === 'Exonerado' ? 'exonerado' : ($tipoPago === 'Propio' ? ($comprobante[0] ? 'en revisión' : 'pendiente') : null),
            $boleta, $comprobante[0] ? $pagoFecha : null, $comprobante[0] ? round((float) $montoComprobante, 2) : null,
            $comprobante[0], $comprobante[1], $tipoPago === 'Ingenio' ? 'pendiente' : null, $grupoId,
        ]);
        $solicitudId = (int) $db->lastInsertId();
        cengi_ins_recalcular_grupo($db, $grupoId);
        // Si el ingenio tiene una proforma con cupos libres para este curso, la
        // inscripcion se amarra a ella (precio de la proforma; validada si ya se pago).
        if ($tipoPago === 'Ingenio' && cengi_pf_auto_amarrar($db, $solicitudId, $plan['id'], $ingenioId)) {
            $stmtMonto = $db->prepare('SELECT monto, moneda FROM solicitudes_inscripcion WHERE id_solicitud = ?');
            $stmtMonto->execute([$solicitudId]);
            [$monto, $moneda] = array_values($stmtMonto->fetch(PDO::FETCH_ASSOC));
        }
        // Una inscripcion sin costo cuenta de inmediato para el cupo.
        cengi_ins_procesar_plan($db, $plan['id']);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        cengi_ins_deshacer_cuentas_creadas($db);
        error_log('Error al guardar inscripcion de convocatoria: ' . $e->getMessage());
        inscripcion_error('No fue posible enviar tu inscripción. Intenta más tarde.', $destino);
    }

    $dinero = cengi_ins_dinero($monto, $moneda);
    $cuotaTexto = mb_strtolower(CENGI_INS_CUOTAS[$cuota][0], 'UTF-8');
    if ($tipoPago === 'Exonerado') {
        $pagoTexto = 'Este curso no tiene costo para tu cuota: no necesitas pagar.';
    } elseif ($tipoPago === 'Ingenio') {
        $pagoTexto = "Cuota {$cuotaTexto} de {$dinero}: la cubre {$nombreIngenio} en su orden de grupo. No necesitas pagar; tu ingenio debe validar tu inscripción para que podamos asignarte al curso.";
    } elseif ($comprobante[0]) {
        $pagoTexto = "Cuota {$cuotaTexto} de {$dinero}: recibimos tu comprobante (boleta {$boleta}). Cuando Administración lo valide te asignaremos al curso.";
    } else {
        $pagoTexto = "Cuota {$cuotaTexto} de {$dinero}, pago propio. Envía tu comprobante de pago respondiendo este correo; al validarlo Administración, te asignaremos al curso.";
    }
    try {
        cengi_ins_enviar_cola($db);
        cengi_pp_enviar_comunicacion($db, [
            'categoria' => 'Inscripción', 'origen' => 'sistema', 'planificacion_id' => (int) $plan['id'],
            'asunto' => 'Confirmación de inscripción: ' . $plan['nombre'],
            'mensaje' => "Hola {nombre}:\n\nRecibimos tu inscripción a {$plan['nombre']}" . ($plan['codigo_curso'] ? " ({$plan['codigo_curso']})" : '') . ".\n\n"
                . "Programación vigente:\n" . cengi_pp_programacion_vigente($db, $plan) . "\n\nPago: {$pagoTexto}\n\n"
                . (!empty($plan['curso_id']) ? '' : 'El curso se confirma al completar ' . (int) $plan['cupo_minimo'] . " inscripciones validadas.\n\n")
                . "Te avisaremos por este medio si cambia la fecha, el horario, el lugar o el instructor.\n\nCoordinación de Capacitación — CENGICAÑA",
            'usuario' => 'Formulario de inscripción',
        ], [['nombre' => $nombreParticipante, 'correo' => $correo]]);
    } catch (Throwable $mailError) {
        error_log('Error enviando la confirmación de inscripción: ' . $mailError->getMessage());
    }

    header("Location: inscripcion.php?resultado=ok&tipo=convocatoria&plan=" . (int) $plan['id']);
    exit;
}

// ------------------------------------------------------------ curso directo (flujo anterior)
$cursoId = strpos($destino, 'c:') === 0 ? (int) substr($destino, 2) : 0;

if (!in_array($tipoPago, ['Ingenio', 'Propio'], true)) {
    inscripcion_error('Selecciona un tipo de pago válido.');
}

if ($ingenioId <= 0) {
    inscripcion_error('Selecciona un ingenio.');
}

if ($cursoId <= 0) {
    inscripcion_error('Selecciona un curso.');
}

$stmtCurso = $db->prepare("SELECT id, nombre_cursos FROM cursos WHERE id = ?");
$stmtCurso->execute([$cursoId]);
$cursoSeleccionado = $stmtCurso->fetch();
if (!$cursoSeleccionado) {
    inscripcion_error('El curso seleccionado no es válido.');
}
$nombreCurso = $cursoSeleccionado['nombre_cursos'] ?? '';

$sql = "
    INSERT INTO solicitudes_inscripcion (
        nombre_participante,
        cui_participante,
        puesto_participante,
        area_participante,
        grado_academico,
        correo,
        telefono,
        ingenio_id,
        curso_id,
        tipo_pago
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $nombreParticipante,
        $cuiParticipante,
        $puestoParticipante,
        $areaParticipante,
        $gradoAcademico,
        $correo,
        $telefono,
        $ingenioId,
        $cursoId,
        $tipoPago,
    ]);
} catch (PDOException $e) {
    error_log("Error al guardar solicitud de inscripcion: " . $e->getMessage());
    inscripcion_error('No fue posible enviar tu solicitud. Intenta más tarde.');
}

try {

    $solicitud = [
        'nombre_participante' => $nombreParticipante,
        'correo' => $correo,
        'nombre_cursos' => $nombreCurso,
    ];

    require __DIR__ . '/correos/plantilla_recibida.php';

    $correoEnviado = cengi_enviar_correo(
        $correo,
        $nombreParticipante,
        'Hemos recibido tu solicitud de inscripción',
        $html
    );

    if (!$correoEnviado) {
        error_log(
            "No se pudo enviar correo de confirmacion de solicitud recibida " .
            "para {$correo}"
        );
    }

} catch (Throwable $mailError) {

    error_log(
        "Error enviando correo de confirmacion de solicitud recibida: " .
        $mailError->getMessage()
    );
}

header("Location: inscripcion.php?resultado=ok");
exit;
