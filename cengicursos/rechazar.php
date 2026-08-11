<?php
require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "correo.php";

cengi_require_rechazar_solicitudes('solicitudes.php');

$mysqli = conectar();
$id = (int) ($_GET['id'] ?? 0);
$resultado = 'error';
$mensaje = 'No se pudo localizar la solicitud.';

if ($id > 0) {
    $stmtSolicitud = $mysqli->prepare("
        SELECT
            s.id_solicitud,
            s.estado,
            s.correo,
            s.nombre_participante,
            i.nombre_ingenios,
            c.nombre_cursos
        FROM solicitudes_inscripcion s
        LEFT JOIN ingenios i ON i.id = s.ingenio_id
        LEFT JOIN cursos c ON c.id = s.curso_id
        WHERE s.id_solicitud = ?
        LIMIT 1
    ");
    $stmtSolicitud->execute([$id]);
    $solicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);

    if (
        $solicitud &&
        strtolower(trim((string) ($solicitud['estado'] ?? ''))) === 'pendiente' &&
        (
            cengi_ve_todo_por_rol_o_ingenio() ||
            cengi_texto_normalizado($solicitud['nombre_ingenios'] ?? '') === cengi_texto_normalizado(cengi_ingenio_nombre_actual())
        )
    ) {
        $sql = "
            UPDATE solicitudes_inscripcion
            SET estado = 'Rechazado'
            WHERE id_solicitud = ?
        ";

        $stmt = $mysqli->prepare($sql);
        $stmt->execute([$id]);
        $resultado = 'rechazada';
        $mensaje = '';

        try {

            require __DIR__ . '/correos/plantilla_rechazado.php';

            $correoEnviado = cengi_enviar_correo(
                $solicitud['correo'],
                $solicitud['nombre_participante'],
                'Actualización de tu solicitud de inscripción',
                $html
            );

            if (!$correoEnviado) {
                error_log(
                    "No se pudo enviar correo de rechazo " .
                    "para solicitud {$id}"
                );
            }

        } catch (Throwable $mailError) {

            error_log(
                "Error enviando correo de rechazo: " .
                $mailError->getMessage()
            );
        }
    } elseif ($solicitud && strtolower(trim((string) ($solicitud['estado'] ?? ''))) !== 'pendiente') {
        $mensaje = 'La solicitud ya fue gestionada.';
    } elseif ($solicitud) {
        $mensaje = 'No tienes permiso para gestionar solicitudes de otro ingenio.';
    }
}

$destino = "solicitudes.php?resultado={$resultado}&id={$id}";
if ($mensaje !== '') {
    $destino .= '&mensaje=' . rawurlencode($mensaje);
}

header("Location: {$destino}");
exit();
?>
