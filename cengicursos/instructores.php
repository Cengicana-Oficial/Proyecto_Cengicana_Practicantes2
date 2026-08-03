<?php
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_instructores();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_instructores();
$mensaje = '';
$mensajeTipo = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $especialidad = trim((string) ($_POST['especialidad'] ?? ''));
    $correo = trim((string) ($_POST['correo'] ?? ''));
    $telefono = trim((string) ($_POST['telefono'] ?? ''));

    $cvPath = null;
    if (isset($_FILES['cv']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
        $extension = strtolower(pathinfo($_FILES['cv']['name'], PATHINFO_EXTENSION));
        if (in_array($extension, ['pdf', 'doc', 'docx'], true)) {
            $nombreArchivo = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $_FILES['cv']['name']);
            $ruta = '../uploads/instructores/' . $nombreArchivo;
            if (move_uploaded_file($_FILES['cv']['tmp_name'], $ruta)) {
                $cvPath = $ruta;
            }
        }
    }

    try {
        if ($accion === 'crear' && $nombre !== '') {
            $stmt = $db->prepare("
                INSERT INTO instructores (nombre, especialidad, correo, telefono, cv_path, estado)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$nombre, $especialidad, $correo, $telefono, $cvPath]);
            $mensaje = 'Instructor registrado correctamente.';
        } elseif ($accion === 'actualizar') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0 && $nombre !== '') {
                if ($cvPath !== null) {
                    $stmt = $db->prepare("
                        UPDATE instructores SET nombre = ?, especialidad = ?, correo = ?, telefono = ?, cv_path = ? WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $especialidad, $correo, $telefono, $cvPath, $id]);
                } else {
                    $stmt = $db->prepare("
                        UPDATE instructores SET nombre = ?, especialidad = ?, correo = ?, telefono = ? WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $especialidad, $correo, $telefono, $id]);
                }
                $mensaje = 'Instructor actualizado correctamente.';
            }
        } elseif ($accion === 'toggle_estado') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE instructores SET estado = 1 - estado WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = 'Estado actualizado.';
            }
        }
    } catch (PDOException $e) {
        $mensaje = 'No fue posible guardar: ' . $e->getMessage();
        $mensajeTipo = 'error';
    }
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$vista = ($_GET['vista'] ?? 'directorio') === 'informe' ? 'informe' : 'directorio';

$condiciones = ['1=1'];
$params = [];
if ($busqueda !== '') {
    $condiciones[] = '(i.nombre LIKE ? OR i.especialidad LIKE ?)';
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
}

$stmt = $db->prepare("
    SELECT
        i.*,
        (SELECT COUNT(*) FROM cursos c WHERE c.instructor_id = i.id) AS total_cursos,
        (
            SELECT AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)))
            FROM cursos c
            INNER JOIN asignaciones a ON a.cursos_id = c.id
            INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
            WHERE c.instructor_id = i.id AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'
        ) AS evaluacion_promedio
    FROM instructores i
    WHERE " . implode(' AND ', $condiciones) . "
    ORDER BY i.nombre
");
$stmt->execute($params);
$instructores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Informe general por curso y diplomado: agrupa cursos con su instructor asignado.
$informe = $db->query("
    SELECT
        ca.descripcion_categorias_cursos AS categoria,
        c.id AS curso_id,
        c.nombre_cursos,
        c.inicio,
        c.fin,
        i.nombre AS instructor_nombre,
        (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id) AS total_inscritos,
        (
            SELECT AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)))
            FROM asignaciones a
            INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
            WHERE a.cursos_id = c.id AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'
        ) AS evaluacion_promedio
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    LEFT JOIN instructores i ON i.id = c.instructor_id
    ORDER BY ca.descripcion_categorias_cursos, c.nombre_cursos
")->fetchAll(PDO::FETCH_ASSOC);

$informeAgrupado = [];
foreach ($informe as $fila) {
    $informeAgrupado[$fila['categoria']][] = $fila;
}

function cengi_inst_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
<?php menu_render(); ?>
<div class="container">

    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'remove' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_inst_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <div class="cengi-tabs">
        <a href="instructores.php?vista=directorio" class="cengi-tab<?php echo $vista === 'directorio' ? ' is-active' : ''; ?>">Directorio de instructores</a>
        <a href="instructores.php?vista=informe" class="cengi-tab<?php echo $vista === 'informe' ? ' is-active' : ''; ?>">Informe general por curso y diplomado</a>
    </div>

    <?php if ($vista === 'directorio'): ?>
        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">Directorio de instructores</h3>
            </div>
            <div class="panel-body">
                <div class="cengi-toolbar">
                    <form class="cengi-toolbar-filters" method="GET">
                        <input type="hidden" name="vista" value="directorio">
                        <input type="text" name="q" class="form-control" placeholder="Buscar instructor o especialidad..." value="<?php echo cengi_inst_html($busqueda); ?>" style="min-width:240px;">
                        <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-search"></span> Buscar</button>
                    </form>
                    <?php if ($puedeGestionar): ?>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#instModal" onclick="cengiInstNuevo()">
                            <span class="glyphicon glyphicon-plus"></span> Nuevo instructor
                        </button>
                    <?php endif; ?>
                </div>

                <div class="cengi-table-wrap">
                    <table class="table table-striped table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Instructor</th>
                                <th>Especialidad</th>
                                <th>Contacto</th>
                                <th>Cursos impartidos</th>
                                <th>Evaluacion prom.</th>
                                <th>CV</th>
                                <th>Estado</th>
                                <?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$instructores): ?>
                                <tr><td colspan="8" class="text-center">No hay instructores registrados todavia.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($instructores as $inst): ?>
                                <tr>
                                    <td>
                                        <div class="cengi-person-cell">
                                            <div class="cengi-person-avatar"><?php echo cengi_inst_html(mb_strtoupper(mb_substr($inst['nombre'], 0, 1, 'UTF-8'), 'UTF-8')); ?></div>
                                            <strong><?php echo cengi_inst_html($inst['nombre']); ?></strong>
                                        </div>
                                    </td>
                                    <td><?php echo cengi_inst_html($inst['especialidad'] ?: '—'); ?></td>
                                    <td>
                                        <?php echo cengi_inst_html($inst['correo'] ?: '—'); ?><br>
                                        <small class="text-muted"><?php echo cengi_inst_html($inst['telefono']); ?></small>
                                    </td>
                                    <td><?php echo (int) $inst['total_cursos']; ?></td>
                                    <td><?php echo $inst['evaluacion_promedio'] !== null ? number_format((float) $inst['evaluacion_promedio'], 1) . ' pts' : '—'; ?></td>
                                    <td>
                                        <?php if ($inst['cv_path']): ?>
                                            <a href="<?php echo cengi_inst_html($inst['cv_path']); ?>" target="_blank" class="btn btn-info btn-xs">Ver CV</a>
                                        <?php else: ?>
                                            <span class="text-muted">Sin CV</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="cengi-status-badge <?php echo ((int) $inst['estado'] === 1) ? 'is-active' : 'is-neutral'; ?>">
                                            <i></i><?php echo ((int) $inst['estado'] === 1) ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <?php if ($puedeGestionar): ?>
                                        <td>
                                            <div class="cengi-row-actions">
                                                <button type="button" class="cengi-action-btn is-edit" style="border:0;" data-toggle="modal" data-target="#instModal"
                                                    onclick='cengiInstEditar(<?php echo json_encode($inst, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                    data-tooltip="Editar"><span class="glyphicon glyphicon-pencil"></span></button>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="accion" value="toggle_estado">
                                                    <input type="hidden" name="id" value="<?php echo (int) $inst['id']; ?>">
                                                    <button type="submit" class="cengi-action-btn <?php echo ((int) $inst['estado'] === 1) ? 'is-toggle-on' : 'is-toggle-off'; ?>" style="border:0;" data-tooltip="Cambiar estado">
                                                        <span class="glyphicon <?php echo ((int) $inst['estado'] === 1) ? 'glyphicon-eye-close' : 'glyphicon-eye-open'; ?>"></span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">Informe de instructores por curso y diplomado</h3>
                <small>Agrupado por categoria de curso; incluye inscritos y evaluacion final promedio.</small>
            </div>
            <div class="panel-body" style="padding:0;">
                <?php if (!$informeAgrupado): ?>
                    <div class="cengi-empty" style="margin:18px;">No hay cursos registrados todavia.</div>
                <?php endif; ?>
                <?php foreach ($informeAgrupado as $categoria => $filasCategoria): ?>
                    <div style="padding:13px 18px;background:#FAFBF7;border-bottom:1px solid var(--cengi-border);border-top:1px solid var(--cengi-border);font-weight:700;font-size:12.5px;">
                        <?php echo cengi_inst_html($categoria); ?>
                    </div>
                    <div class="cengi-table-wrap" style="border:0;border-radius:0;">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Curso</th>
                                    <th>Instructor</th>
                                    <th>Inicio</th>
                                    <th>Fin</th>
                                    <th>Inscritos</th>
                                    <th>Evaluacion prom.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filasCategoria as $fila): ?>
                                    <tr>
                                        <td><?php echo cengi_inst_html($fila['nombre_cursos']); ?></td>
                                        <td><?php echo cengi_inst_html($fila['instructor_nombre'] ?: 'Sin asignar'); ?></td>
                                        <td><?php echo cengi_inst_html($fila['inicio']); ?></td>
                                        <td><?php echo cengi_inst_html($fila['fin']); ?></td>
                                        <td><?php echo (int) $fila['total_inscritos']; ?></td>
                                        <td><?php echo $fila['evaluacion_promedio'] !== null ? number_format((float) $fila['evaluacion_promedio'], 1) . ' pts' : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="cengi-notice" style="margin-top:16px;">
        <span class="glyphicon glyphicon-info-sign"></span>
        <span>La "Carga masiva de evaluaciones" para instructores todavia no procesa CSV en esta version: usa el formulario de "Seguimiento por curso" para registrar notas manualmente por participante.</span>
    </div>
</div>

<?php if ($puedeGestionar): ?>
<div class="modal fade" id="instModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="instForm">
                <div class="model-header">
                    <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h4 class="modal-title" id="instModalTitulo">Nuevo instructor</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="instAccion" value="crear">
                    <input type="hidden" name="id" id="instId" value="">
                    <div class="cengi-form-grid">
                        <div class="form-group">
                            <label class="control-label">Nombre</label>
                            <input type="text" name="nombre" id="instNombre" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Especialidad</label>
                            <input type="text" name="especialidad" id="instEspecialidad" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="control-label">Correo</label>
                            <input type="email" name="correo" id="instCorreo" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="control-label">Telefono</label>
                            <input type="text" name="telefono" id="instTelefono" class="form-control">
                        </div>
                        <div class="form-group cengi-form-full">
                            <label class="control-label">Hoja de vida (PDF/DOC)</label>
                            <input type="file" name="cv" class="form-control" accept=".pdf,.doc,.docx">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function cengiInstNuevo() {
    document.getElementById('instModalTitulo').textContent = 'Nuevo instructor';
    document.getElementById('instAccion').value = 'crear';
    document.getElementById('instId').value = '';
    document.getElementById('instForm').reset();
}
function cengiInstEditar(inst) {
    document.getElementById('instModalTitulo').textContent = 'Editar instructor';
    document.getElementById('instAccion').value = 'actualizar';
    document.getElementById('instId').value = inst.id;
    document.getElementById('instNombre').value = inst.nombre || '';
    document.getElementById('instEspecialidad').value = inst.especialidad || '';
    document.getElementById('instCorreo').value = inst.correo || '';
    document.getElementById('instTelefono').value = inst.telefono || '';
}
</script>
<?php endif; ?>
</body>
</html>
