<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$mensaje = '';
$mensajeTipo = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));

    try {
        if ($accion === 'crear') {
            if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 120) {
                throw new InvalidArgumentException('Escribe un nombre de hasta 120 caracteres.');
            }

            $stmt = $db->prepare('INSERT INTO areas_tecnicas (nombre, estado) VALUES (?, 1)');
            $stmt->execute([$nombre]);
            $mensaje = 'Área técnica registrada correctamente.';
        } elseif ($accion === 'actualizar') {
            if ($id <= 0 || $nombre === '' || mb_strlen($nombre, 'UTF-8') > 120) {
                throw new InvalidArgumentException('Los datos del área técnica no son válidos.');
            }

            $db->beginTransaction();
            $stmtActual = $db->prepare('SELECT nombre FROM areas_tecnicas WHERE id = ? FOR UPDATE');
            $stmtActual->execute([$id]);
            $nombreAnterior = $stmtActual->fetchColumn();
            if ($nombreAnterior === false) {
                throw new InvalidArgumentException('El área técnica ya no existe.');
            }

            $stmt = $db->prepare('UPDATE areas_tecnicas SET nombre = ? WHERE id = ?');
            $stmt->execute([$nombre, $id]);
            $stmtCursos = $db->prepare('UPDATE cursos SET area_tecnica = ? WHERE area_tecnica = ?');
            $stmtCursos->execute([$nombre, $nombreAnterior]);
            $db->commit();
            $mensaje = 'Área técnica actualizada correctamente.';
        } elseif ($accion === 'toggle_estado') {
            if ($id <= 0) {
                throw new InvalidArgumentException('El área técnica no es válida.');
            }

            $stmt = $db->prepare('UPDATE areas_tecnicas SET estado = 1 - estado WHERE id = ?');
            $stmt->execute([$id]);
            $mensaje = 'Estado del área técnica actualizado.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensajeTipo = 'error';
        if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
            $mensaje = 'Ya existe un área técnica con ese nombre.';
        } elseif ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('No fue posible guardar el área técnica: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar el área técnica.';
        }
    }
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$sql = "
    SELECT at.id, at.nombre, at.estado,
           (SELECT COUNT(*) FROM cursos c WHERE c.area_tecnica = at.nombre) AS cursos_total
    FROM areas_tecnicas at
";
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE at.nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}
$sql .= ' ORDER BY at.estado DESC, at.nombre';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

function cengi_area_html($valor)
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
    <title>Áreas técnicas</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page">
    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'remove' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_area_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <section class="cengi-courses-filter-card">
        <form class="cengi-courses-filters" method="get">
            <div class="cengi-course-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" name="q" value="<?php echo cengi_area_html($busqueda); ?>" placeholder="Buscar área técnica...">
            </div>
            <button type="submit" class="btn btn-default">Buscar</button>
            <button type="button" class="btn btn-primary cengi-course-create" data-toggle="modal" data-target="#area-form-modal" data-area-mode="create">
                <span class="glyphicon glyphicon-plus"></span> Nueva área técnica
            </button>
        </form>
    </section>

    <section class="cengi-courses-section">
        <div class="cengi-courses-table-wrap">
            <table class="cengi-org-table">
                <thead>
                    <tr>
                        <th>Área técnica</th>
                        <th>Cursos asociados</th>
                        <th>Estado</th>
                        <th class="is-actions"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$areas): ?>
                        <tr><td colspan="4" class="cengi-courses-empty"><span class="glyphicon glyphicon-wrench"></span><strong>No hay áreas técnicas registradas</strong><small>Registra la primera área para clasificar los cursos.</small></td></tr>
                    <?php endif; ?>
                    <?php foreach ($areas as $area): ?>
                        <tr>
                            <td class="cengi-org-name"><strong><?php echo cengi_area_html($area['nombre']); ?></strong></td>
                            <td><?php echo (int) $area['cursos_total']; ?></td>
                            <td>
                                <span class="cengi-status-badge <?php echo (int) $area['estado'] === 1 ? 'is-active' : 'is-neutral'; ?>">
                                    <i></i><?php echo (int) $area['estado'] === 1 ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td class="is-actions">
                                <div class="cengi-row-actions">
                                    <button type="button" class="cengi-action-btn is-edit area-edit-trigger" data-toggle="modal" data-target="#area-form-modal" data-area-mode="edit" data-id="<?php echo (int) $area['id']; ?>" data-name="<?php echo cengi_area_html($area['nombre']); ?>" data-tooltip="Editar" aria-label="Editar">
                                        <span class="glyphicon glyphicon-pencil"></span>
                                    </button>
                                    <form method="post" class="cengi-inline-form">
                                        <input type="hidden" name="accion" value="toggle_estado">
                                        <input type="hidden" name="id" value="<?php echo (int) $area['id']; ?>">
                                        <button type="submit" class="cengi-action-btn <?php echo (int) $area['estado'] === 1 ? 'is-delete' : 'is-view'; ?>" data-tooltip="<?php echo (int) $area['estado'] === 1 ? 'Desactivar' : 'Activar'; ?>" aria-label="<?php echo (int) $area['estado'] === 1 ? 'Desactivar' : 'Activar'; ?>">
                                            <span class="glyphicon glyphicon-<?php echo (int) $area['estado'] === 1 ? 'ban-circle' : 'ok'; ?>"></span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="area-form-modal" tabindex="-1" role="dialog" aria-labelledby="area-form-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="post" id="area-form" autocomplete="off">
            <div class="modal-header">
                <h4 class="modal-title" id="area-form-title">Nueva área técnica</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" id="area-form-action" value="crear">
                <input type="hidden" name="id" id="area-form-id" value="">
                <div class="form-group">
                    <label for="area-form-name">Nombre</label>
                    <input type="text" name="nombre" id="area-form-name" class="form-control" maxlength="120" placeholder="Ej. Nutrición vegetal" required>
                    <p class="help-block">Esta opción aparecerá al crear o editar cursos.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    $('#area-form-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var editando = trigger && trigger.getAttribute('data-area-mode') === 'edit';
        document.getElementById('area-form').reset();
        document.getElementById('area-form-action').value = editando ? 'actualizar' : 'crear';
        document.getElementById('area-form-id').value = editando ? (trigger.getAttribute('data-id') || '') : '';
        document.getElementById('area-form-name').value = editando ? (trigger.getAttribute('data-name') || '') : '';
        document.getElementById('area-form-title').textContent = editando ? 'Editar área técnica' : 'Nueva área técnica';
    });
})();
</script>
</body>
</html>
