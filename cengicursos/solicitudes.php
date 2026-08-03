<?php
require_once "conexion.php";
require_once "menu.php";

$conexion = conectar();
$puedeEditarSolicitudes = cengi_puede_editar_solicitudes();
$puedeAprobarSolicitudes = cengi_puede_aprobar_solicitudes();
$puedeRechazarSolicitudes = cengi_puede_rechazar_solicitudes();

cengi_require_gestor_solicitudes('ver_cursos.php');

$campo = trim($_POST['campo'] ?? '');
$condiciones = [];
$params = [];

if ($campo !== '') {
    $condiciones[] = "(
        s.nombre_participante LIKE ?
        OR c.nombre_cursos LIKE ?
        OR i.nombre_ingenios LIKE ?
        OR s.correo LIKE ?
        OR s.telefono LIKE ?
        OR s.tipo_pago LIKE ?
    )";

    $like = '%' . $campo . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = cengi_sql_texto_normalizado('i.nombre_ingenios') . " = ?";
    $params[] = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
}

$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$sql = "
    SELECT
        s.*,
        i.nombre_ingenios,
        c.nombre_cursos
    FROM solicitudes_inscripcion s
    LEFT JOIN ingenios i ON s.ingenio_id = i.id
    INNER JOIN cursos c ON s.curso_id = c.id
    {$where}
    ORDER BY
        CASE s.estado
            WHEN 'Pendiente' THEN 1
            WHEN 'Aprobado' THEN 2
            WHEN 'Rechazado' THEN 3
            ELSE 4
        END,
        s.id_solicitud DESC
";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resumen = [
    'total' => count($solicitudes),
    'pendientes' => 0,
    'aprobadas' => 0,
    'rechazadas' => 0,
];

foreach ($solicitudes as $solicitud) {
    $estadoResumen = strtolower(trim((string) ($solicitud['estado'] ?? '')));

    if ($estadoResumen === 'pendiente') {
        $resumen['pendientes']++;
    } elseif ($estadoResumen === 'aprobado') {
        $resumen['aprobadas']++;
    } elseif ($estadoResumen === 'rechazado') {
        $resumen['rechazadas']++;
    }
}

$resultado = trim($_GET['resultado'] ?? '');
$resultadoId = (int) ($_GET['id'] ?? 0);
$mensajeResultado = trim($_GET['mensaje'] ?? '');
$proyectoCssVersion = (int) (@filemtime(__DIR__ . '/css/proyecto.css') ?: 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitudes | CENGICURSOS</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/proyecto.css?v=<?php echo $proyectoCssVersion; ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>

<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-requests-page">
    <div class="cengi-hero cengi-requests-hero">
        <span class="cengi-chip">Solicitudes</span>
        <h2>Gestión de inscripciones</h2>
        <p>Revisa los datos de cada participante y gestiona su inscripción al curso correspondiente.</p>
    </div>

    <?php if ($resultado !== ''): ?>
        <?php
        $esAprobada = $resultado === 'aprobada';
        $esRechazada = $resultado === 'rechazada';
        $esError = $resultado === 'error';
        ?>
        <div class="cengi-feedback <?php echo $esError ? 'is-error' : ($esRechazada ? 'is-warning' : 'is-success'); ?>" role="status" aria-live="polite">
            <span class="cengi-feedback-icon glyphicon <?php echo $esError ? 'glyphicon-warning-sign' : ($esRechazada ? 'glyphicon-remove' : 'glyphicon-ok'); ?>"></span>
            <div>
                <strong>
                    <?php echo $esError ? 'No se pudo completar la acción' : ($esRechazada ? 'Solicitud rechazada' : 'Solicitud aprobada correctamente'); ?>
                </strong>
                <p>
                    <?php if ($esAprobada): ?>
                        La solicitud #<?php echo $resultadoId; ?> fue aprobada y el participante quedó asociado al curso.
                    <?php elseif ($esRechazada): ?>
                        La solicitud #<?php echo $resultadoId; ?> fue marcada como rechazada.
                    <?php else: ?>
                        <?php echo htmlspecialchars($mensajeResultado ?: 'Intenta nuevamente o verifica los datos de la solicitud.'); ?>
                    <?php endif; ?>
                </p>
            </div>
            <button type="button" class="cengi-feedback-close" aria-label="Cerrar notificación">&times;</button>
        </div>
    <?php endif; ?>

    <section class="cengi-request-stats" aria-label="Resumen de solicitudes">
        <article class="cengi-request-stat is-total">
            <span class="glyphicon glyphicon-inbox"></span>
            <div><strong><?php echo $resumen['total']; ?></strong><small>Total</small></div>
        </article>
        <article class="cengi-request-stat is-pending">
            <span class="glyphicon glyphicon-time"></span>
            <div><strong><?php echo $resumen['pendientes']; ?></strong><small>Pendientes</small></div>
        </article>
        <article class="cengi-request-stat is-approved">
            <span class="glyphicon glyphicon-ok"></span>
            <div><strong><?php echo $resumen['aprobadas']; ?></strong><small>Aprobadas</small></div>
        </article>
        <article class="cengi-request-stat is-rejected">
            <span class="glyphicon glyphicon-remove"></span>
            <div><strong><?php echo $resumen['rechazadas']; ?></strong><small>Rechazadas</small></div>
        </article>
    </section>

    <section class="cengi-request-panel">
        <div class="cengi-request-toolbar">
            <div>
                <span class="cengi-section-kicker">Bandeja de solicitudes</span>
                <h3>Inscripciones recibidas</h3>
                <p>Las solicitudes pendientes se muestran primero.</p>
            </div>

            <form method="POST" class="cengi-request-search" role="search">
                <div class="cengi-search-control">
                    <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                    <input
                        type="text"
                        name="campo"
                        class="form-control"
                        placeholder="Participante, curso, correo o ingenio"
                        value="<?php echo htmlspecialchars($campo); ?>"
                        aria-label="Buscar solicitudes"
                    >
                </div>
                <button type="submit" class="btn btn-success">Buscar</button>
            </form>
        </div>

        <?php if (!$solicitudes): ?>
            <div class="cengi-request-empty">
                <span class="glyphicon glyphicon-search"></span>
                <h4>No encontramos solicitudes</h4>
                <p><?php echo $campo !== '' ? 'Prueba con otro término o borra la búsqueda para ver el listado completo.' : 'Las nuevas solicitudes aparecerán aquí.'; ?></p>
            </div>
        <?php else: ?>
            <div class="cengi-request-table-wrap">
                <table class="cengi-request-table">
                    <thead>
                        <tr>
                            <th>Participante</th>
                            <th>Contacto</th>
                            <th>Curso solicitado</th>
                            <th>Ingenio</th>
                            <th>Estado</th>
                            <?php if ($puedeEditarSolicitudes || $puedeAprobarSolicitudes || $puedeRechazarSolicitudes): ?>
                                <th class="is-actions">Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($solicitudes as $fila): ?>
                        <?php
                        $estado = trim((string) ($fila['estado'] ?? 'Pendiente'));
                        $estadoNormalizado = strtolower($estado);
                        $estadoClase = $estadoNormalizado === 'aprobado'
                            ? 'is-approved'
                            : ($estadoNormalizado === 'rechazado' ? 'is-rejected' : 'is-pending');
                        $esPendiente = $estadoNormalizado === 'pendiente';
                        ?>
                        <tr>
                            <td>
                                <div class="cengi-person-cell">
                                    <span class="cengi-person-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($fila['nombre_participante'], 0, 1, 'UTF-8'), 'UTF-8')); ?></span>
                                    <div>
                                        <strong><?php echo htmlspecialchars($fila['nombre_participante']); ?></strong>
                                        <small>Solicitud #<?php echo (int) $fila['id_solicitud']; ?> · CUI <?php echo htmlspecialchars($fila['cui_participante']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="cengi-stacked-cell">
                                    <span><span class="glyphicon glyphicon-envelope"></span> <?php echo htmlspecialchars($fila['correo']); ?></span>
                                    <small><span class="glyphicon glyphicon-earphone"></span> <?php echo htmlspecialchars($fila['telefono']); ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="cengi-stacked-cell">
                                    <strong><?php echo htmlspecialchars($fila['nombre_cursos']); ?></strong>
                                    <small><?php echo htmlspecialchars($fila['tipo_pago']); ?></small>
                                </div>
                            </td>
                            <td><span class="cengi-ingenio-chip"><span class="glyphicon glyphicon-map-marker"></span> <?php echo htmlspecialchars($fila['nombre_ingenios'] ?? 'Sin ingenio'); ?></span></td>
                            <td><span class="cengi-status-badge <?php echo $estadoClase; ?>"><i></i><?php echo htmlspecialchars($estado); ?></span></td>

                            <?php if ($puedeEditarSolicitudes || $puedeAprobarSolicitudes || $puedeRechazarSolicitudes): ?>
                                <td class="is-actions">
                                    <?php if ($esPendiente): ?>
                                        <div class="acciones-solicitud">
                                            <?php if ($puedeEditarSolicitudes): ?>
                                                <a class="accion-solicitud editar" href="editar_solicitud.php?id=<?php echo (int) $fila['id_solicitud']; ?>" data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span><span class="sr-only">Editar</span></a>
                                            <?php endif; ?>
                                            <?php if ($puedeAprobarSolicitudes): ?>
                                                <button
                                                    type="button"
                                                    class="accion-solicitud aprobar"
                                                    data-toggle="modal"
                                                    data-target="#confirm-request-action"
                                                    data-action="aprobar.php"
                                                    data-kind="aprobar"
                                                    data-id="<?php echo (int) $fila['id_solicitud']; ?>"
                                                    data-name="<?php echo htmlspecialchars($fila['nombre_participante'], ENT_QUOTES); ?>"
                                                    data-course="<?php echo htmlspecialchars($fila['nombre_cursos'], ENT_QUOTES); ?>"
                                                    data-tooltip="Aprobar"
                                                    aria-label="Aprobar"
                                                ><span class="glyphicon glyphicon-ok"></span><span class="sr-only">Aprobar</span></button>
                                            <?php endif; ?>
                                            <?php if ($puedeRechazarSolicitudes): ?>
                                                <button
                                                    type="button"
                                                    class="accion-solicitud rechazar"
                                                    data-toggle="modal"
                                                    data-target="#confirm-request-action"
                                                    data-action="rechazar.php"
                                                    data-kind="rechazar"
                                                    data-id="<?php echo (int) $fila['id_solicitud']; ?>"
                                                    data-name="<?php echo htmlspecialchars($fila['nombre_participante'], ENT_QUOTES); ?>"
                                                    data-course="<?php echo htmlspecialchars($fila['nombre_cursos'], ENT_QUOTES); ?>"
                                                    data-tooltip="Rechazar"
                                                    aria-label="Rechazar"
                                                ><span class="glyphicon glyphicon-remove"></span><span class="sr-only">Rechazar</span></button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="cengi-managed-label"><span class="glyphicon glyphicon-lock"></span> Gestionada</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade cengi-confirm-modal" id="confirm-request-action" tabindex="-1" role="dialog" aria-labelledby="confirm-request-title" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-body">
                <span class="cengi-confirm-icon glyphicon glyphicon-ok" id="confirm-request-icon"></span>
                <span class="cengi-confirm-kicker" id="confirm-request-kicker">Confirmar aprobación</span>
                <h4 id="confirm-request-title">¿Aprobar esta solicitud?</h4>
                <p id="confirm-request-copy">Esta acción inscribirá al participante en el curso seleccionado.</p>
                <div class="cengi-confirm-context">
                    <strong id="confirm-request-name"></strong>
                    <span id="confirm-request-course"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <form method="GET" id="confirm-request-form">
                    <input type="hidden" name="id" id="confirm-request-id">
                    <button type="submit" class="btn btn-success" id="confirm-request-submit">
                        <span class="glyphicon glyphicon-ok"></span> Sí, aprobar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    $('#confirm-request-action').on('show.bs.modal', function (event) {
        var trigger = $(event.relatedTarget);
        var kind = trigger.data('kind');
        var isReject = kind === 'rechazar';
        var modal = $(this);

        modal.toggleClass('is-reject', isReject);
        modal.find('#confirm-request-form').attr('action', trigger.data('action'));
        modal.find('#confirm-request-id').val(trigger.data('id'));
        modal.find('#confirm-request-name').text(trigger.data('name'));
        modal.find('#confirm-request-course').text(trigger.data('course'));
        modal.find('#confirm-request-kicker').text(isReject ? 'Confirmar rechazo' : 'Confirmar aprobación');
        modal.find('#confirm-request-title').text(isReject ? '¿Rechazar esta solicitud?' : '¿Aprobar esta solicitud?');
        modal.find('#confirm-request-copy').text(
            isReject
                ? 'La solicitud dejará de estar pendiente y quedará registrada como rechazada.'
                : 'El participante será creado o actualizado y quedará asociado al curso seleccionado.'
        );
        modal.find('#confirm-request-icon')
            .toggleClass('glyphicon-ok', !isReject)
            .toggleClass('glyphicon-remove', isReject);
        modal.find('#confirm-request-submit')
            .toggleClass('btn-success', !isReject)
            .toggleClass('btn-danger', isReject)
            .html(isReject
                ? '<span class="glyphicon glyphicon-remove"></span> Sí, rechazar'
                : '<span class="glyphicon glyphicon-ok"></span> Sí, aprobar'
            );
    });

    $('#confirm-request-form').on('submit', function () {
        var button = $('#confirm-request-submit');
        button.prop('disabled', true).html(
            '<span class="glyphicon glyphicon-refresh cengi-is-spinning"></span> Procesando...'
        );
    });

    $('.cengi-feedback-close').on('click', function () {
        $(this).closest('.cengi-feedback').slideUp(160);
    });
})();
</script>
</body>
</html>
