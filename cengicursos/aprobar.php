<?php
/**
 * Aprueba una solicitud directa a un curso (flujo de solicitudes.php): crea o actualiza el
 * participante, su usuario de estudiante y la asignacion (ver cengi_ins_asignar()).
 * Las solicitudes de una convocatoria (planificacion_id) no pasan por aqui: se asignan
 * solas al validarse el pago en pagos_inscripcion.php (ver inscripcion_helpers.php).
 */

require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "correo.php";
require_once "inscripcion_helpers.php";

cengi_require_aprobar_solicitudes('solicitudes.php');

$db = conectar();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: solicitudes.php");
    exit();
}

$db->beginTransaction();

try {

    $sqlSolicitud = "
        SELECT
            s.*,
            i.nombre_ingenios,
            c.nombre_cursos
        FROM solicitudes_inscripcion s
        LEFT JOIN ingenios i
            ON i.id = s.ingenio_id
        LEFT JOIN cursos c
            ON c.id = s.curso_id
        WHERE s.id_solicitud = ?
        LIMIT 1
    ";

    $stmtSolicitud = $db->prepare($sqlSolicitud);
    $stmtSolicitud->execute([$id]);

    $solicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        throw new RuntimeException('Solicitud no encontrada.');
    }

    if (!empty($solicitud['planificacion_id'])) {
        throw new RuntimeException('Esta inscripción es de una convocatoria: se asigna al validar su pago en Pagos de inscripción.');
    }

    if (strtolower(trim((string) ($solicitud['estado'] ?? ''))) !== 'pendiente') {
        throw new RuntimeException('La solicitud ya fue gestionada.');
    }

    if (
        !cengi_ve_todo_por_rol_o_ingenio() &&
        cengi_texto_normalizado($solicitud['nombre_ingenios'] ?? '') !== cengi_texto_normalizado(cengi_ingenio_nombre_actual())
    ) {
        throw new RuntimeException('No puedes aprobar solicitudes de otro ingenio.');
    }

    cengi_ins_asignar($db, $solicitud, trim((string) ($_SESSION['usuario'] ?? '')) ?: 'Gestión de Capacitación');

    $db->commit();

    // ==========================================
// ENVIAR CORREO DE APROBACIÓN
// ==========================================

try {

    require __DIR__ . '/correos/plantilla_aprobado.php';

    $correoEnviado = cengi_enviar_correo(
        $solicitud['correo'],
        $solicitud['nombre_participante'],
        'Solicitud de inscripción aprobada',
        $html
    );

    if (!$correoEnviado) {
        error_log(
            "No se pudo enviar correo de aprobación " .
            "para solicitud {$id}"
        );
    }

} catch (Throwable $mailError) {

    error_log(
        "Error enviando correo de aprobación: " .
        $mailError->getMessage()
    );
}

} catch (Throwable $e) {

    $db->rollBack();
    cengi_ins_deshacer_cuentas_creadas($db);

    header(
        "Location: solicitudes.php?resultado=error&id={$id}&mensaje=" .
        rawurlencode($e->getMessage())
    );
    exit();
}

header("Location: solicitudes.php?resultado=aprobada&id={$id}");
exit();
