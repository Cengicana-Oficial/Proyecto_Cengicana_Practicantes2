<?php
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_organizaciones();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_organizaciones();

$tiposValidos = ['Ingenio azucarero', 'Universidad', 'Empresa proveedora', 'Institucion tecnica', 'Otra'];
$mensaje = '';
$mensajeTipo = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $tipo = trim((string) ($_POST['tipo'] ?? ''));
    $contactoNombre = trim((string) ($_POST['contacto_nombre'] ?? ''));
    $contactoCorreo = trim((string) ($_POST['contacto_correo'] ?? ''));
    $contactoTelefono = trim((string) ($_POST['contacto_telefono'] ?? ''));
    $ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
    $ingenioId = $ingenioId > 0 ? $ingenioId : null;
    $estado = (int) ($_POST['estado'] ?? 1) === 0 ? 0 : 1;

    if (!in_array($tipo, $tiposValidos, true)) {
        $tipo = 'Otra';
    }

    try {
        if ($accion === 'crear' && $nombre !== '') {
            $stmt = $db->prepare("
                INSERT INTO organizaciones (nombre, tipo, contacto_nombre, contacto_correo, contacto_telefono, ingenio_id, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $tipo, $contactoNombre, $contactoCorreo, $contactoTelefono, $ingenioId, $estado]);
            $mensaje = 'Organizacion registrada correctamente.';
        } elseif ($accion === 'actualizar') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0 && $nombre !== '') {
                $stmt = $db->prepare("
                    UPDATE organizaciones
                    SET nombre = ?, tipo = ?, contacto_nombre = ?, contacto_correo = ?, contacto_telefono = ?, ingenio_id = ?, estado = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $tipo, $contactoNombre, $contactoCorreo, $contactoTelefono, $ingenioId, $estado, $id]);
                $mensaje = 'Organizacion actualizada correctamente.';
            }
        } elseif ($accion === 'toggle_estado') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE organizaciones SET estado = 1 - estado WHERE id = ?");
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
$filtroTipo = trim((string) ($_GET['tipo'] ?? ''));

$condiciones = [];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = 'o.nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}

if ($filtroTipo !== '' && in_array($filtroTipo, $tiposValidos, true)) {
    $condiciones[] = 'o.tipo = ?';
    $params[] = $filtroTipo;
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = '(o.ingenio_id = ? OR o.ingenio_id IS NULL)';
    $params[] = cengi_ingenio_id_actual();
}

$where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

$stmt = $db->prepare("
    SELECT
        o.id, o.nombre, o.tipo, o.contacto_nombre, o.contacto_correo, o.contacto_telefono,
        o.ingenio_id, o.estado,
        (SELECT COUNT(DISTINCT p.id) FROM participantes p WHERE p.ingenio_id = o.ingenio_id) AS colaboradores_registrados,
        (SELECT COUNT(DISTINCT p.id) FROM participantes p WHERE p.ingenio_id = o.ingenio_id AND p.estado_participantes = 1) AS colaboradores_activos,
        (SELECT COUNT(DISTINCT c.id) FROM cursos c WHERE c.ingenio_id = o.ingenio_id) AS cursos_inscritos
    FROM organizaciones o
    {$where}
    ORDER BY o.nombre
");
$stmt->execute($params);
$organizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ingenios = $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios")->fetchAll(PDO::FETCH_ASSOC);

// Cursos activos y satisfaccion aproximada (post-evaluacion) por ingenio, para la ficha de detalle.
// No existe ninguna tabla de encuestas de satisfaccion en el esquema: se muestra "No disponible"
// cuando no hay datos numericos de post-evaluacion registrados para esa organizacion.
$ingenioIds = array_values(array_unique(array_filter(array_map(
    static fn($org) => (int) ($org['ingenio_id'] ?? 0),
    $organizaciones
))));

$cursosActivosPorIngenio = [];
$promedioPostEvalPorIngenio = [];

if ($ingenioIds) {
    $marcadores = implode(',', array_fill(0, count($ingenioIds), '?'));

    $stmtCursosOrg = $db->prepare("
        SELECT
            c.ingenio_id, c.id, c.nombre_cursos, c.fin,
            (SELECT COUNT(*) FROM asignaciones ax WHERE ax.cursos_id = c.id AND ax.estado_asignaciones = 1) AS inscritos
        FROM cursos c
        WHERE c.ingenio_id IN ($marcadores)
        ORDER BY c.nombre_cursos
    ");
    $stmtCursosOrg->execute($ingenioIds);
    foreach ($stmtCursosOrg->fetchAll(PDO::FETCH_ASSOC) as $cursoOrg) {
        $fin = (string) ($cursoOrg['fin'] ?? '');
        $sigueActivo = $fin === '' || $fin === '0000-00-00' || $fin >= date('Y-m-d');
        if ($sigueActivo) {
            $cursosActivosPorIngenio[(int) $cursoOrg['ingenio_id']][] = $cursoOrg;
        }
    }

    $stmtPromedio = $db->prepare("
        SELECT p.ingenio_id, AVG(CAST(cc.posevaluacion AS DECIMAL(10,2))) AS promedio
        FROM control_cursos cc
        INNER JOIN asignaciones a ON a.id = cc.asignacion_id
        INNER JOIN participantes p ON p.id = a.participantes_id
        WHERE p.ingenio_id IN ($marcadores)
          AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'
        GROUP BY p.ingenio_id
    ");
    $stmtPromedio->execute($ingenioIds);
    foreach ($stmtPromedio->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $promedioPostEvalPorIngenio[(int) $fila['ingenio_id']] = (float) $fila['promedio'];
    }
}

function cengi_org_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_org_tipo_label($tipo)
{
    return $tipo === 'Institucion tecnica' ? 'Institución técnica' : $tipo;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Organizaciones</title>
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
            <div><p><?php echo cengi_org_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <section class="cengi-courses-filter-card">
        <form class="cengi-courses-filters" method="get">
            <div class="cengi-course-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" name="q" value="<?php echo cengi_org_html($busqueda); ?>" placeholder="Buscar organización...">
            </div>
            <select name="tipo" aria-label="Filtrar por tipo">
                <option value="">Todos los tipos</option>
                <?php foreach ($tiposValidos as $t): ?>
                    <option value="<?php echo cengi_org_html($t); ?>" <?php echo $filtroTipo === $t ? 'selected' : ''; ?>><?php echo cengi_org_html(cengi_org_tipo_label($t)); ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($puedeGestionar): ?>
                <button type="button" class="btn btn-primary cengi-course-create" data-toggle="modal" data-target="#org-form-modal" data-org-modal="create">
                    <span class="glyphicon glyphicon-plus"></span> Nueva organización
                </button>
            <?php endif; ?>
        </form>
    </section>

    <section class="cengi-courses-section">
        <div class="cengi-courses-table-wrap">
            <table class="cengi-org-table">
                <thead>
                    <tr>
                        <th>Organización</th>
                        <th>Tipo</th>
                        <th>Contacto</th>
                        <th>Colaboradores activos</th>
                        <th>Cursos inscritos</th>
                        <th>Estado</th>
                        <th class="is-actions"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$organizaciones): ?>
                        <tr><td colspan="7" class="cengi-courses-empty"><span class="glyphicon glyphicon-briefcase"></span><strong>No hay organizaciones registradas</strong><small>Prueba con otros filtros o registra una organizacion nueva.</small></td></tr>
                    <?php endif; ?>
                    <?php foreach ($organizaciones as $org):
                        $ingenioIdOrg = (int) ($org['ingenio_id'] ?? 0);
                        $cursosActivosOrg = $ingenioIdOrg ? ($cursosActivosPorIngenio[$ingenioIdOrg] ?? []) : [];
                        $cursosConInscritos = count(array_filter($cursosActivosOrg, static fn($c) => (int) $c['inscritos'] > 0));
                        $promedioPostEval = $ingenioIdOrg && isset($promedioPostEvalPorIngenio[$ingenioIdOrg])
                            ? number_format($promedioPostEvalPorIngenio[$ingenioIdOrg], 1)
                            : '';
                        $cursosJson = json_encode(array_map(static fn($c) => [
                            'nombre' => $c['nombre_cursos'],
                            'inscritos' => (int) $c['inscritos'],
                        ], $cursosActivosOrg), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    ?>
                        <tr>
                            <td class="cengi-org-name"><strong><?php echo cengi_org_html($org['nombre']); ?></strong></td>
                            <td><?php echo cengi_org_html(cengi_org_tipo_label($org['tipo'])); ?></td>
                            <td class="cengi-org-contact">
                                <?php echo cengi_org_html($org['contacto_nombre'] ?: '—'); ?><?php if ($org['contacto_correo']): ?><span> · <?php echo cengi_org_html($org['contacto_correo']); ?></span><?php endif; ?>
                            </td>
                            <td><?php echo $ingenioIdOrg ? (int) $org['colaboradores_activos'] : '—'; ?></td>
                            <td><?php echo $ingenioIdOrg ? (int) $org['cursos_inscritos'] : '—'; ?></td>
                            <td>
                                <span class="cengi-status-badge <?php echo ((int) $org['estado'] === 1) ? 'is-active' : 'is-neutral'; ?>">
                                    <i></i><?php echo ((int) $org['estado'] === 1) ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td class="is-actions">
                                <div class="cengi-row-actions">
                                    <button type="button" class="cengi-action-btn is-view org-detail-trigger" data-toggle="modal" data-target="#org-detail-modal"
                                        data-name="<?php echo cengi_org_html($org['nombre']); ?>"
                                        data-type="<?php echo cengi_org_html(cengi_org_tipo_label($org['tipo'])); ?>"
                                        data-contact="<?php echo cengi_org_html(trim(($org['contacto_nombre'] ?: '') . (($org['contacto_nombre'] && $org['contacto_correo']) ? ' · ' : '') . ($org['contacto_correo'] ?: ''))); ?>"
                                        data-collab="<?php echo (int) $org['colaboradores_registrados']; ?>"
                                        data-courses-count="<?php echo $cursosConInscritos; ?>"
                                        data-satisfaction="<?php echo cengi_org_html($promedioPostEval); ?>"
                                        data-ingenio="<?php echo (int) $ingenioIdOrg; ?>"
                                        data-courses="<?php echo $cursosJson; ?>"
                                        data-tooltip="Ver ficha" aria-label="Ver ficha">
                                        <span class="glyphicon glyphicon-eye-open"></span>
                                    </button>
                                    <?php if ($puedeGestionar): ?>
                                        <button type="button" class="cengi-action-btn is-edit org-edit-trigger" data-toggle="modal" data-target="#org-form-modal" data-org-modal="edit"
                                            data-id="<?php echo (int) $org['id']; ?>"
                                            data-name="<?php echo cengi_org_html($org['nombre']); ?>"
                                            data-type="<?php echo cengi_org_html(cengi_org_tipo_label($org['tipo'])); ?>"
                                            data-ingenio="<?php echo (int) $ingenioIdOrg; ?>"
                                            data-estado="<?php echo (int) $org['estado']; ?>"
                                            data-contact-name="<?php echo cengi_org_html($org['contacto_nombre']); ?>"
                                            data-contact-email="<?php echo cengi_org_html($org['contacto_correo']); ?>"
                                            data-contact-phone="<?php echo cengi_org_html($org['contacto_telefono']); ?>"
                                            data-tooltip="Editar" aria-label="Editar">
                                            <span class="glyphicon glyphicon-pencil"></span>
                                        </button>

                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="cengi-org-user-view">
        <div class="cengi-org-user-view-head">
            <h3>Vista de usuario de ingenio</h3>
            <p>Permisos: solo visualiza sus propios colaboradores, cursos, asistencia y resultados</p>
        </div>
        <div class="cengi-org-user-view-body">
            <span class="cengi-tag">✓ Colaboradores propios</span>
            <span class="cengi-tag">✓ Cursos inscritos</span>
            <span class="cengi-tag">✓ Asistencia</span>
            <span class="cengi-tag">✓ Resultados</span>
            <span class="cengi-tag is-denied">✕ Datos de otras instituciones</span>
        </div>
    </section>
</main>

<?php if ($puedeGestionar): ?>
<div class="modal fade cengi-participant-modal cengi-org-form-modal" id="org-form-modal" tabindex="-1" role="dialog" aria-labelledby="org-form-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form method="POST" id="org-form">
            <div class="modal-header">
                <h4 class="modal-title" id="org-form-title">Nueva organización</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" id="org-form-accion" value="crear">
                <input type="hidden" name="id" id="org-form-id" value="">
                <div class="cengi-participant-modal-grid">
                    <div class="form-group is-full"><label for="org-form-name">Nombre</label><input type="text" name="nombre" id="org-form-name" class="form-control" placeholder="Ej. Ingenio Santa Ana" required></div>
                    <div class="form-group"><label for="org-form-type">Tipo</label>
                        <select name="tipo" id="org-form-type" class="form-control">
                            <?php foreach ($tiposValidos as $t): ?>
                                <option value="<?php echo cengi_org_html($t); ?>"><?php echo cengi_org_html(cengi_org_tipo_label($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label for="org-form-status">Estado</label>
                        <select name="estado" id="org-form-status" class="form-control">
                            <option value="1">Activa</option>
                            <option value="0">Inactiva</option>
                        </select>
                    </div>
                    <div class="form-group is-full"><label for="org-form-ingenio">Ingenio vinculado <span class="text-muted">(opcional)</span></label>
                        <select name="ingenio_id" id="org-form-ingenio" class="form-control">
                            <option value="0">Ninguno</option>
                            <?php foreach ($ingenios as $ing): ?>
                                <option value="<?php echo (int) $ing['id']; ?>"><?php echo cengi_org_html($ing['nombre_ingenios']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group is-full"><label for="org-form-contact-name">Contacto</label><input type="text" name="contacto_nombre" id="org-form-contact-name" class="form-control" placeholder="Nombre del contacto"></div>
                    <div class="form-group"><label for="org-form-contact-email">Correo de contacto</label><input type="email" name="contacto_correo" id="org-form-contact-email" class="form-control"></div>
                    <div class="form-group"><label for="org-form-contact-phone">Telefono de contacto</label><input type="text" name="contacto_telefono" id="org-form-contact-phone" class="form-control"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="org-form-submit">Guardar</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-org-detail-modal" id="org-detail-modal" tabindex="-1" role="dialog" aria-labelledby="org-detail-name">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="cengi-org-detail-head">
            <span class="cengi-org-detail-avatar" id="org-detail-avatar">OR</span>
            <div class="cengi-org-detail-heading">
                <h4 id="org-detail-name">Organización</h4>
                <p id="org-detail-sub"></p>
            </div>
            <button type="button" class="cengi-org-modal-close" data-dismiss="modal" aria-label="Cerrar">&times;</button>
        </div>
        <div class="cengi-org-detail-stats">
            <div><strong id="org-detail-collab">0</strong><span>Colaboradores registrados</span></div>
            <div><strong id="org-detail-courses">0</strong><span>Cursos con inscritos</span></div>
            <div><strong id="org-detail-satisfaction">—</strong><span>Satisfacción promedio</span></div>
        </div>
        <div class="cengi-org-detail-body">
            <h5>Cursos activos de esta organización</h5>
            <div id="org-detail-course-list"></div>
            <div class="cengi-notice cengi-org-detail-notice">
                <span class="glyphicon glyphicon-info-sign"></span>
                <span>El usuario de esta organización solo puede ver esta misma información: sus colaboradores, cursos, asistencia y resultados.</span>
            </div>
        </div>
        <div class="cengi-org-detail-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            <a href="seguimiento_ingenio.php" class="btn btn-primary" id="org-detail-followup">Ver seguimiento completo</a>
        </div>
    </div></div>
</div>
<script>
(function () {
    'use strict';

    document.querySelectorAll('.cengi-courses-filters select').forEach(function (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    });

    function initials(name) {
        return (name || 'OR').trim().split(/\s+/).slice(0, 2).map(function (word) { return word.charAt(0).toUpperCase(); }).join('');
    }
    function escapeHtml(value) {
        var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML;
    }

    <?php if ($puedeGestionar): ?>
    $('#org-form-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var mode = trigger && trigger.getAttribute('data-org-modal') === 'edit' ? 'edit' : 'create';
        var form = document.getElementById('org-form');
        form.reset();
        document.getElementById('org-form-accion').value = mode === 'edit' ? 'actualizar' : 'crear';
        document.getElementById('org-form-id').value = '';
        document.getElementById('org-form-title').textContent = mode === 'edit' ? 'Editar organización' : 'Nueva organización';
        document.getElementById('org-form-status').value = '1';
        if (mode === 'edit' && trigger) {
            document.getElementById('org-form-id').value = trigger.getAttribute('data-id') || '';
            document.getElementById('org-form-name').value = trigger.getAttribute('data-name') || '';
            document.getElementById('org-form-type').value = trigger.getAttribute('data-type') || 'Otra';
            document.getElementById('org-form-ingenio').value = trigger.getAttribute('data-ingenio') || '0';
            document.getElementById('org-form-status').value = trigger.getAttribute('data-estado') || '1';
            document.getElementById('org-form-contact-name').value = trigger.getAttribute('data-contact-name') || '';
            document.getElementById('org-form-contact-email').value = trigger.getAttribute('data-contact-email') || '';
            document.getElementById('org-form-contact-phone').value = trigger.getAttribute('data-contact-phone') || '';
        }
    });
    <?php endif; ?>

    $('#org-detail-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        var name = trigger.getAttribute('data-name') || '';
        var type = trigger.getAttribute('data-type') || '';
        var contact = trigger.getAttribute('data-contact') || 'Sin contacto registrado';
        document.getElementById('org-detail-name').textContent = name;
        document.getElementById('org-detail-avatar').textContent = initials(name);
        document.getElementById('org-detail-sub').textContent = type + (contact ? ' · ' + contact : '');
        document.getElementById('org-detail-collab').textContent = trigger.getAttribute('data-collab') || '0';
        document.getElementById('org-detail-courses').textContent = trigger.getAttribute('data-courses-count') || '0';
        var ingenioId = trigger.getAttribute('data-ingenio') || '';
        document.getElementById('org-detail-followup').href = 'seguimiento_ingenio.php' + (ingenioId ? '?ingenio_id=' + encodeURIComponent(ingenioId) : '');
        var satisfaction = trigger.getAttribute('data-satisfaction') || '';
        document.getElementById('org-detail-satisfaction').textContent = satisfaction !== '' ? satisfaction : 'No disponible';

        var courses = [];
        try { courses = JSON.parse(trigger.getAttribute('data-courses') || '[]'); } catch (ignore) {}
        document.getElementById('org-detail-course-list').innerHTML = courses.length ? courses.map(function (course) {
            return '<div class="cengi-org-detail-course-row"><strong>' + escapeHtml(course.nombre) + '</strong><span>' + escapeHtml(course.inscritos) + ' colaboradores inscritos</span></div>';
        }).join('') : '<div class="cengi-org-detail-empty">No hay cursos activos registrados para esta organización.</div>';
    });
})();
</script>
</body>
</html>
