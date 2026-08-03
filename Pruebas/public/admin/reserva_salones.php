<?php
require_once("../../config/auth.php");
require_login();

if (!can_access('gestionar_reserva_salones')) {
    die('No tienes permiso para gestionar reservacion de salones.');
}

$conn = conexion::conectar();
$idSolicitud = (int) ($_GET['id_solicitud'] ?? 0);
$formsUrl = 'https://forms.office.com/Pages/ResponsePage.aspx?id=N-PDcxejJEa7AwR2Y8TZ7ekuxauXBD1PptY_G3nKWn5UNzBUMjY1QzA5RFY1QzNLRTdZU1BEWFhDUi4u';

if ($idSolicitud <= 0) {
    die('Solicitud no valida.');
}

$stmt = $conn->prepare("
    SELECT
        s.id_solicitud,
        s.fecha_registro,
        s.fecha_visita,
        s.hora_visita,
        s.cantidad_visitantes,
        so.nombre_solicitante,
        so.nombre_institucion,
        so.correo,
        so.telefono,
        n.nombre_nivel,
        GROUP_CONCAT(DISTINCT ai.nombre_area ORDER BY ai.nombre_area SEPARATOR ', ') AS areas
    FROM solicitudes s
    INNER JOIN solicitantes so ON so.id_solicitante = s.id_solicitante
    INNER JOIN niveles_academicos n ON n.id_nivel = s.id_nivel
    LEFT JOIN solicitud_areas sa ON sa.id_solicitud = s.id_solicitud
    LEFT JOIN areas_interes ai ON ai.id_area = sa.id_area
    WHERE s.id_solicitud = ?
    GROUP BY
        s.id_solicitud,
        s.fecha_registro,
        s.fecha_visita,
        s.hora_visita,
        s.cantidad_visitantes,
        so.nombre_solicitante,
        so.nombre_institucion,
        so.correo,
        so.telefono,
        n.nombre_nivel
");
$stmt->execute([$idSolicitud]);
$fila = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$fila) {
    die('No se encontro la solicitud.');
}

$fechaActividad = !empty($fila['fecha_visita'])
    ? date('Y-m-d', strtotime($fila['fecha_visita']))
    : '';

$horaInicio = !empty($fila['hora_visita'])
    ? date('H:i', strtotime($fila['hora_visita']))
    : '';

$horaFinal = $horaInicio;
$observaciones = implode(' | ', array_filter([
    'Solicitud #' . $fila['id_solicitud'],
    'Nivel: ' . $fila['nombre_nivel'],
    'Areas: ' . ($fila['areas'] ?: 'Sin areas registradas'),
]));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Formulario de Reserva de Salones</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
<style>
body{
    background:#f5f5f5;
    padding:30px;
    font-family: Arial, sans-serif;
}

.panel{
    box-shadow:0 2px 10px rgba(0,0,0,0.1);
}

.panel-heading{
    background:#337ab7 !important;
    color:white !important;
}

.form-group{
    margin-bottom:20px;
}

textarea{
    resize:vertical;
}

.form-hint {
    color:#667085;
    font-size:12px;
    margin-top:6px;
}
</style>
</head>
<body>

<div class="container">
    <div class="panel panel-primary">
        <div class="panel-heading">
            <h3 class="panel-title">Formulario de Reserva de Salones</h3>
        </div>

        <div class="panel-body">
            <form id="reservaForm" action="enviar_reserva_salones.php" method="POST">
                <input type="hidden" name="id_solicitud" value="<?= (int) $fila['id_solicitud'] ?>">

                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>ID</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $fila['id_solicitud']) ?>" readonly>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Fecha Solicitado</label>
                            <input type="date" class="form-control" value="<?= htmlspecialchars($fechaSolicitado) ?>" readonly>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Fecha de la actividad</label>
                            <input type="date" class="form-control" name="rab4170e84bcd4827bd88ada6d4f7ee09" value="<?= htmlspecialchars($fechaActividad) ?>">
        
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Organizacion que solicita</label>
                    <input type="text" class="form-control" name="rfd0749f80ff0409a82c14968b7997610" value="<?= htmlspecialchars($fila['nombre_institucion']) ?>">
                </div>

                <div class="form-group">
                    <label>Descripcion de la actividad a realizar</label>
                    <textarea class="form-control" rows="4" name="rca7162a3d5764f5781730517d2dff796"><?= htmlspecialchars($observaciones) ?></textarea>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Numero de participantes</label>
                            <input type="number" class="form-control" name="r857e31b356db48e29a1f5b48a2446fac" value="<?= htmlspecialchars((string) $fila['cantidad_visitantes']) ?>">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Hora de inicio de la actividad</label>
                            <input type="time" class="form-control" name="re115e3255dd7463fbdd2c79eb9873a77" value="<?= htmlspecialchars($horaInicio) ?>">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Hora final de la actividad</label>
                            <input type="time" class="form-control" name="r7359bf7da9aa4917b5e426ba515f11da" value="<?= htmlspecialchars($horaFinal) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Responsable de la Actividad</label>
                    <input type="text" class="form-control" name="r3370bfe0775f46a0b3be69f7568f15a4" value="<?= htmlspecialchars($fila['nombre_solicitante']) ?>">
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Correo electronico del responsable</label>
                            <input type="email" class="form-control" name="r507443a331d24efcb6bdda063311b81b" value="<?= htmlspecialchars($fila['correo']) ?>">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Numero de telefono del responsable</label>
                            <input type="text" class="form-control" name="r45b9886cc1aa4042b0b3bb53d0792b68" value="<?= htmlspecialchars($fila['telefono']) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Nivel academico</label>
                    <input type="text" class="form-control" name="re212c8046ad24e53b120c0691b368572" value="<?= htmlspecialchars($fila['nombre_nivel']) ?>">
                    <p class="form-hint">Si este campo en Microsoft Forms representa otra pregunta, solo hay que ajustar el identificador tecnico.</p>
                </div>

                <div class="alert alert-info" style="margin-top:20px;">
                    Este envio sale desde el sistema. Si Microsoft Forms solicita una confirmacion adicional de su lado, te la mostrara automaticamente.
                </div>

                <button type="submit" class="btn btn-primary">Enviar solicitud</button>
                <a href="dashboard_unificado.php?modulo=solicitudes" class="btn btn-default">Regresar</a>
            </form>
        </div>
    </div>
</div>

<script>

</script>

</body>
</html>
