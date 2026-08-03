<?php
require_once "revisar_permisos.php";
require_once "conexion.php";

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
            i.nombre_ingenios
        FROM solicitudes_inscripcion s
        LEFT JOIN ingenios i ON i.id = s.ingenio_id
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
