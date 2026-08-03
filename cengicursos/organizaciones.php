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

    if (!in_array($tipo, $tiposValidos, true)) {
        $tipo = 'Otra';
    }

    try {
        if ($accion === 'crear' && $nombre !== '') {
            $stmt = $db->prepare("
                INSERT INTO organizaciones (nombre, tipo, contacto_nombre, contacto_correo, contacto_telefono, ingenio_id, estado)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$nombre, $tipo, $contactoNombre, $contactoCorreo, $contactoTelefono, $ingenioId]);
            $mensaje = 'Organizacion registrada correctamente.';
        } elseif ($accion === 'actualizar') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0 && $nombre !== '') {
                $stmt = $db->prepare("
                    UPDATE organizaciones
                    SET nombre = ?, tipo = ?, contacto_nombre = ?, contacto_correo = ?, contacto_telefono = ?, ingenio_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $tipo, $contactoNombre, $contactoCorreo, $contactoTelefono, $ingenioId, $id]);
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
        (SELECT COUNT(DISTINCT p.id) FROM participantes p WHERE p.ingenio_id = o.ingenio_id AND p.estado_participantes = 1) AS colaboradores_activos,
        (SELECT COUNT(DISTINCT c.id) FROM cursos c WHERE c.ingenio_id = o.ingenio_id) AS cursos_inscritos
    FROM organizaciones o
    {$where}
    ORDER BY o.nombre
");
$stmt->execute($params);
$organizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ingenios = $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios")->fetchAll(PDO::FETCH_ASSOC);

function cengi_org_html($valor)
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
            <div><p><?php echo cengi_org_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <div class="panel panel-success">
        <div class="panel-heading">
            <h3 class="panel-title">Directorio de organizaciones</h3>
        </div>
        <div class="panel-body">
            <div class="cengi-toolbar">
                <form class="cengi-toolbar-filters" method="GET">
                    <input type="text" name="q" class="form-control" placeholder="Buscar organizacion..." value="<?php echo cengi_org_html($busqueda); ?>" style="min-width:220px;">
                    <select name="tipo" class="form-control" onchange="this.form.submit()">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($tiposValidos as $t): ?>
                            <option value="<?php echo cengi_org_html($t); ?>" <?php echo $filtroTipo === $t ? 'selected' : ''; ?>><?php echo cengi_org_html($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-search"></span> Filtrar</button>
                </form>
                <?php if ($puedeGestionar): ?>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#orgModal" onclick="cengiOrgNuevo()">
                        <span class="glyphicon glyphicon-plus"></span> Nueva organizacion
                    </button>
                <?php endif; ?>
            </div>

            <div class="cengi-table-wrap">
                <table class="table table-striped table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>Organizacion</th>
                            <th>Tipo</th>
                            <th>Contacto</th>
                            <th>Colaboradores activos</th>
                            <th>Cursos inscritos</th>
                            <th>Estado</th>
                            <?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$organizaciones): ?>
                            <tr><td colspan="7" class="text-center">No hay organizaciones registradas todavia.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($organizaciones as $org): ?>
                            <tr>
                                <td><strong><?php echo cengi_org_html($org['nombre']); ?></strong></td>
                                <td><?php echo cengi_org_html($org['tipo']); ?></td>
                                <td>
                                    <?php echo cengi_org_html($org['contacto_nombre'] ?: '—'); ?>
                                    <?php if ($org['contacto_correo']): ?><br><small class="text-muted"><?php echo cengi_org_html($org['contacto_correo']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo $org['ingenio_id'] ? (int) $org['colaboradores_activos'] : '—'; ?></td>
                                <td><?php echo $org['ingenio_id'] ? (int) $org['cursos_inscritos'] : '—'; ?></td>
                                <td>
                                    <span class="cengi-status-badge <?php echo ((int) $org['estado'] === 1) ? 'is-active' : 'is-neutral'; ?>">
                                        <i></i><?php echo ((int) $org['estado'] === 1) ? 'Activa' : 'Inactiva'; ?>
                                    </span>
                                </td>
                                <?php if ($puedeGestionar): ?>
                                <td>
                                    <div class="cengi-row-actions">
                                        <button type="button" class="cengi-action-btn is-edit" style="border:0;" data-toggle="modal" data-target="#orgModal"
                                            data-tooltip="Editar" aria-label="Editar"
                                            onclick='cengiOrgEditar(<?php echo json_encode($org, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                            <span class="glyphicon glyphicon-pencil"></span>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="accion" value="toggle_estado">
                                            <input type="hidden" name="id" value="<?php echo (int) $org['id']; ?>">
                                            <button type="submit" class="cengi-action-btn <?php echo ((int) $org['estado'] === 1) ? 'is-toggle-on' : 'is-toggle-off'; ?>" style="border:0;" data-tooltip="<?php echo ((int) $org['estado'] === 1) ? 'Desactivar' : 'Activar'; ?>">
                                                <span class="glyphicon <?php echo ((int) $org['estado'] === 1) ? 'glyphicon-eye-close' : 'glyphicon-eye-open'; ?>"></span>
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

    <div class="panel panel-success">
        <div class="panel-heading">
            <h3 class="panel-title">Vista de usuario de ingenio</h3>
            <small>Permisos reales aplicados hoy mediante <code>cengi_scope_sql()</code>: solo visualiza sus propios colaboradores, cursos, asistencia y resultados.</small>
        </div>
        <div class="panel-body" style="display:flex;gap:8px;flex-wrap:wrap;">
            <span class="cengi-tag">✓ Colaboradores propios</span>
            <span class="cengi-tag">✓ Cursos inscritos</span>
            <span class="cengi-tag">✓ Asistencia</span>
            <span class="cengi-tag">✓ Resultados</span>
            <span class="cengi-tag" style="background:#FBE3E0;color:#B23223;">✕ Datos de otras instituciones</span>
        </div>
    </div>
</div>

<?php if ($puedeGestionar): ?>
<div class="modal fade" id="orgModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="orgForm">
                <div class="model-header">
                    <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h4 class="modal-title" id="orgModalTitulo">Nueva organizacion</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="orgAccion" value="crear">
                    <input type="hidden" name="id" id="orgId" value="">
                    <div class="cengi-form-grid">
                        <div class="form-group">
                            <label class="control-label">Nombre</label>
                            <input type="text" name="nombre" id="orgNombre" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Tipo</label>
                            <select name="tipo" id="orgTipo" class="form-control">
                                <?php foreach ($tiposValidos as $t): ?>
                                    <option value="<?php echo cengi_org_html($t); ?>"><?php echo cengi_org_html($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Ingenio vinculado <span class="text-muted">(opcional)</span></label>
                            <select name="ingenio_id" id="orgIngenio" class="form-control">
                                <option value="0">Ninguno</option>
                                <?php foreach ($ingenios as $ing): ?>
                                    <option value="<?php echo (int) $ing['id']; ?>"><?php echo cengi_org_html($ing['nombre_ingenios']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Contacto</label>
                            <input type="text" name="contacto_nombre" id="orgContactoNombre" class="form-control" placeholder="Nombre del contacto">
                        </div>
                        <div class="form-group">
                            <label class="control-label">Correo de contacto</label>
                            <input type="email" name="contacto_correo" id="orgContactoCorreo" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="control-label">Telefono de contacto</label>
                            <input type="text" name="contacto_telefono" id="orgContactoTelefono" class="form-control">
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
function cengiOrgNuevo() {
    document.getElementById('orgModalTitulo').textContent = 'Nueva organizacion';
    document.getElementById('orgAccion').value = 'crear';
    document.getElementById('orgId').value = '';
    document.getElementById('orgForm').reset();
}
function cengiOrgEditar(org) {
    document.getElementById('orgModalTitulo').textContent = 'Editar organizacion';
    document.getElementById('orgAccion').value = 'actualizar';
    document.getElementById('orgId').value = org.id;
    document.getElementById('orgNombre').value = org.nombre || '';
    document.getElementById('orgTipo').value = org.tipo || 'Otra';
    document.getElementById('orgIngenio').value = org.ingenio_id || 0;
    document.getElementById('orgContactoNombre').value = org.contacto_nombre || '';
    document.getElementById('orgContactoCorreo').value = org.contacto_correo || '';
    document.getElementById('orgContactoTelefono').value = org.contacto_telefono || '';
}
</script>
<?php endif; ?>
</body>
</html>
