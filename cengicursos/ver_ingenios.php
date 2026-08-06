<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';

cengi_require_ver_ingenios();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_ingenios();
$busqueda = trim((string) ($_GET['q'] ?? ''));
$confirmacion = trim((string) ($_GET['confirmacion'] ?? ''));
$nombreConfirmacion = trim((string) ($_GET['nombre'] ?? ''));
$confirmacionesValidas = ['creado', 'duplicado', 'datos_invalidos', 'error'];
$mostrarConfirmacion = in_array($confirmacion, $confirmacionesValidas, true);
$confirmacionExitosa = $confirmacion === 'creado';

if ($confirmacion === 'creado') {
    $confirmacionTitulo = 'Institución agregada';
    $confirmacionTexto = 'La institución se agregó correctamente y ya está disponible en el sistema.';
} elseif ($confirmacion === 'duplicado') {
    $confirmacionTitulo = 'La institución ya existe';
    $confirmacionTexto = 'Utiliza un nombre diferente o edita el registro existente.';
} elseif ($confirmacion === 'datos_invalidos') {
    $confirmacionTitulo = 'Revisa el nombre';
    $confirmacionTexto = 'Escribe un nombre válido de hasta 255 caracteres.';
} else {
    $confirmacionTitulo = 'No se pudo guardar';
    $confirmacionTexto = 'Ocurrió un problema al agregar la institución. Intenta nuevamente.';
}

$sql = "
    SELECT
        i.id,
        i.nombre_ingenios,
        (SELECT COUNT(*) FROM cursos c WHERE c.ingenio_id = i.id) AS cursos_total,
        (SELECT COUNT(*) FROM participantes p WHERE p.ingenio_id = i.id) AS participantes_total
    FROM ingenios i
";
$params = [];

if ($busqueda !== '') {
    $sql .= ' WHERE i.nombre_ingenios LIKE ?';
    $params[] = '%' . $busqueda . '%';
}

$sql .= ' ORDER BY i.nombre_ingenios';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$ingenios = $stmt->fetchAll(PDO::FETCH_ASSOC);

function cengi_ingenio_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingenios e instituciones</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page">
    <section class="cengi-courses-filter-card">
        <form class="cengi-courses-filters" method="get">
            <div class="cengi-course-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" name="q" value="<?php echo cengi_ingenio_html($busqueda); ?>" placeholder="Buscar ingenio o institución...">
            </div>
            <button type="submit" class="btn btn-default">Buscar</button>
            <?php if ($busqueda !== ''): ?>
                <a href="ver_ingenios.php" class="btn btn-default">Limpiar</a>
            <?php endif; ?>
            <?php if ($puedeGestionar): ?>
                <button type="button" class="btn btn-primary cengi-course-create" data-toggle="modal" data-target="#ingenio-form-modal" data-ingenio-mode="create">
                    <span class="glyphicon glyphicon-plus"></span> Nueva institución
                </button>
            <?php endif; ?>
        </form>
    </section>

    <section class="cengi-courses-section">
        <div class="cengi-courses-table-wrap">
            <table class="cengi-org-table">
                <thead>
                    <tr>
                        <th>Ingenio o institución</th>
                        <th>Cursos asociados</th>
                        <th>Participantes</th>
                        <?php if ($puedeGestionar): ?>
                            <th class="is-actions"><span class="sr-only">Acciones</span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$ingenios): ?>
                        <tr>
                            <td colspan="<?php echo $puedeGestionar ? 4 : 3; ?>" class="cengi-courses-empty">
                                <span class="glyphicon glyphicon-globe"></span>
                                <strong>No hay instituciones registradas</strong>
                                <small>Registra la primera institución para asignarla a cursos y participantes.</small>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($ingenios as $ingenio): ?>
                        <tr>
                            <td class="cengi-org-name">
                                <strong><?php echo cengi_ingenio_html($ingenio['nombre_ingenios']); ?></strong>
                            </td>
                            <td><?php echo (int) $ingenio['cursos_total']; ?></td>
                            <td><?php echo (int) $ingenio['participantes_total']; ?></td>
                            <?php if ($puedeGestionar): ?>
                                <td class="is-actions">
                                    <div class="cengi-row-actions">
                                        <button
                                            type="button"
                                            class="cengi-action-btn is-edit"
                                            data-toggle="modal"
                                            data-target="#ingenio-form-modal"
                                            data-ingenio-mode="edit"
                                            data-id="<?php echo (int) $ingenio['id']; ?>"
                                            data-name="<?php echo cengi_ingenio_html($ingenio['nombre_ingenios']); ?>"
                                            data-tooltip="Editar"
                                            aria-label="Editar"
                                        >
                                            <span class="glyphicon glyphicon-pencil"></span>
                                        </button>
                                        <button
                                            type="button"
                                            class="cengi-action-btn is-delete"
                                            data-toggle="modal"
                                            data-target="#confirm-delete"
                                            data-href="eliminar_ingenios.php?id=<?php echo (int) $ingenio['id']; ?>"
                                            data-name="<?php echo cengi_ingenio_html($ingenio['nombre_ingenios']); ?>"
                                            data-tooltip="Eliminar"
                                            aria-label="Eliminar"
                                        >
                                            <span class="glyphicon glyphicon-trash"></span>
                                        </button>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php if ($puedeGestionar): ?>
<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="ingenio-form-modal" tabindex="-1" role="dialog" aria-labelledby="ingenio-form-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="post" id="ingenio-form" action="guardar_ingenios.php" autocomplete="off">
            <div class="modal-header">
                <h4 class="modal-title" id="ingenio-form-title">Nueva institución</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="ingenio-form-id" value="">
                <div class="form-group">
                    <label for="ingenio-form-name">Nombre</label>
                    <input type="text" name="nombre" id="ingenio-form-name" class="form-control" maxlength="255" placeholder="Ej. Ingenio Santa Ana" required>
                    <p class="help-block">Esta institución estará disponible en cursos, participantes e inscripciones.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div></div>
</div>

<?php if ($mostrarConfirmacion): ?>
<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="ingenio-feedback-modal" tabindex="-1" role="dialog" aria-labelledby="ingenio-feedback-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <div class="modal-header">
            <h4 class="modal-title" id="ingenio-feedback-title"><?php echo cengi_ingenio_html($confirmacionTitulo); ?></h4>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
        </div>
        <div class="modal-body">
            <div class="cengi-feedback<?php echo $confirmacionExitosa ? '' : ' is-error'; ?>">
                <div class="cengi-feedback-icon">
                    <span class="glyphicon glyphicon-<?php echo $confirmacionExitosa ? 'ok' : 'remove'; ?>"></span>
                </div>
                <div>
                    <?php if ($nombreConfirmacion !== ''): ?>
                        <strong><?php echo cengi_ingenio_html($nombreConfirmacion); ?></strong>
                    <?php endif; ?>
                    <p><?php echo cengi_ingenio_html($confirmacionTexto); ?></p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-dismiss="modal">Aceptar</button>
        </div>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="delete-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <div class="modal-header">
            <h4 class="modal-title" id="delete-title">Eliminar institución</h4>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
        </div>
        <div class="modal-body">
            <p>¿Deseas eliminar <strong id="delete-ingenio-name">esta institución</strong>?</p>
            <p class="help-block">No podrá eliminarse si tiene cursos, participantes o usuarios asociados.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
            <a class="btn btn-danger btn-ok" href="#">Eliminar</a>
        </div>
    </div></div>
</div>

<script>
(function () {
    'use strict';

    <?php if ($mostrarConfirmacion): ?>
    $('#ingenio-feedback-modal').modal('show');
    <?php endif; ?>

    $('#ingenio-form-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var editando = trigger && trigger.getAttribute('data-ingenio-mode') === 'edit';
        var form = document.getElementById('ingenio-form');

        form.reset();
        form.action = editando ? 'actualizar_ingenios.php' : 'guardar_ingenios.php';
        document.getElementById('ingenio-form-id').value = editando ? (trigger.getAttribute('data-id') || '') : '';
        document.getElementById('ingenio-form-name').value = editando ? (trigger.getAttribute('data-name') || '') : '';
        document.getElementById('ingenio-form-title').textContent = editando ? 'Editar institución' : 'Nueva institución';
    });

    $('#confirm-delete').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        $(this).find('.btn-ok').attr('href', trigger ? trigger.getAttribute('data-href') : '#');
        document.getElementById('delete-ingenio-name').textContent = trigger ? (trigger.getAttribute('data-name') || 'esta institución') : 'esta institución';
    });
})();
</script>
<?php endif; ?>
</body>
</html>
