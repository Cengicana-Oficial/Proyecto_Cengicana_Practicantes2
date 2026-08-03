<?php
require_once "conexion.php";
require_once "menu.php";
require_once __DIR__ . '/../login/config/permisos_roles.php';

cengi_require_ver_roles();

$puedeGestionar = cengi_puede_gestionar_roles();
$pdo = conectar_usuarios_menu_pdo();
asegurar_tablas_permisos($pdo);

$mensaje = '';
$mensajeTipo = 'success';

// ---------------------------------------------------------------
// Definicion de los 10 modulos gestionados desde esta matriz.
// 'read'/'write' son los nombres de permiso reales en usuarios_menu.permisos
// (definidos y sembrados en login/config/permisos_roles.php).
// ---------------------------------------------------------------
$modulos = [
    'dashboard' => ['label' => 'Dashboard / indicadores', 'read' => null, 'write' => null, 'fijo' => true],
    'cursos' => ['label' => 'Cursos y diplomados', 'read' => 'ver_cursos_cengi', 'write' => 'gestionar_cursos_cengi'],
    'participantes' => ['label' => 'Participantes', 'read' => 'ver_participantes_cengi', 'write' => 'gestionar_participantes_cengi'],
    'instructores' => ['label' => 'Instructores', 'read' => 'ver_instructores_cengi', 'write' => 'gestionar_instructores_cengi'],
    'organizaciones' => ['label' => 'Organizaciones', 'read' => 'ver_organizaciones_cengi', 'write' => 'gestionar_organizaciones_cengi'],
    'eventos' => ['label' => 'Eventos', 'read' => 'ver_eventos_cengi', 'write' => 'gestionar_eventos_cengi'],
    'diplomas' => ['label' => 'Diplomas y certificacion', 'read' => 'ver_diplomas_cengi', 'write' => 'gestionar_diplomas_cengi'],
    'carga_masiva' => ['label' => 'Carga masiva', 'read' => 'ver_participantes_cengi', 'write' => 'cargar_participantes_cengi'],
    'reportes' => ['label' => 'Reportes por ingenio', 'read' => 'ver_reportes_cengi', 'write' => 'ver_reportes_cengi'],
    'roles' => ['label' => 'Roles y permisos', 'read' => 'ver_roles_cengi', 'write' => 'roles_gestionar_cengi'],
];

$nombresNivel = [1 => 'Administrador general', 2 => 'Encargado de capacitacion', 3 => 'Administrador de ingenio'];
$descripcionNivel = [
    1 => 'Gestion total del sistema: cursos, participantes, organizaciones, eventos, diplomas y roles.',
    2 => 'Coordina la operacion diaria de capacitacion: cursos, inscripciones, participantes y reportes.',
    3 => 'Ve unicamente los datos de su propio ingenio (colaboradores, cursos, asistencia y resultados).',
];

// Todos los roles reales de usuarios_menu, excluyendo Instructor/Estudiante/Participante
// (esos acceden via Moodle y no se gestionan en esta pantalla).
$rolesRaw = $pdo->query("SELECT id, nombre_rol FROM roles ORDER BY nombre_rol")->fetchAll(PDO::FETCH_ASSOC);
$rolesPorNivel = [1 => [], 2 => [], 3 => []];

foreach ($rolesRaw as $r) {
    $norm = cengi_texto_normalizado($r['nombre_rol']);
    if (strpos($norm, 'instructor') !== false || strpos($norm, 'estudiante') !== false || strpos($norm, 'alumno') !== false || strpos($norm, 'participante') !== false) {
        continue;
    }
    $nivel = cengi_clasificar_rol_nivel($r['nombre_rol']);
    $rolesPorNivel[$nivel][] = $r;
}

// Rol "editable" representativo de cada nivel (evita superadmin cuando hay alternativa,
// porque superadmin ya sobrepasa cualquier permiso via es_superadmin=1).
$rolEditablePorNivel = [];
foreach ($rolesPorNivel as $nivel => $lista) {
    $elegido = null;
    foreach ($lista as $r) {
        if (cengi_texto_normalizado($r['nombre_rol']) !== 'superadmin') {
            $elegido = $r;
            break;
        }
    }
    $rolEditablePorNivel[$nivel] = $elegido ?: ($lista[0] ?? null);
}

// ---------------------------------------------------------------
// Guardar matriz de permisos (preserva permisos de otros modulos que
// no gestiona esta matriz, ej. visitas/laboratorio/solicitudes internas).
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    if ($accion === 'guardar_matriz') {
        $rolId = (int) ($_POST['rol_id'] ?? 0);
        $selecciones = $_POST['nivel'] ?? [];

        if ($rolId > 0) {
            $permisosManejados = [];
            foreach ($modulos as $clave => $def) {
                if (!empty($def['fijo'])) {
                    continue;
                }
                if ($def['read']) {
                    $permisosManejados[] = $def['read'];
                }
                if ($def['write']) {
                    $permisosManejados[] = $def['write'];
                }
            }
            $permisosManejados = array_unique($permisosManejados);

            $actuales = obtener_permisos_rol($pdo, $rolId);
            $conservados = array_values(array_diff($actuales, $permisosManejados));

            $nuevos = [];
            foreach ($modulos as $clave => $def) {
                if (!empty($def['fijo'])) {
                    continue;
                }
                $nivelSeleccionado = $selecciones[$clave] ?? 'ninguno';

                if ($nivelSeleccionado === 'gestion') {
                    if ($def['write']) {
                        $nuevos[] = $def['write'];
                    }
                    if ($def['read']) {
                        $nuevos[] = $def['read'];
                    }
                } elseif ($nivelSeleccionado === 'lectura') {
                    if ($def['read']) {
                        $nuevos[] = $def['read'];
                    }
                }
            }

            $final = array_values(array_unique(array_merge($conservados, $nuevos)));
            guardar_permisos_rol($pdo, $rolId, $final);
            $mensaje = 'Matriz de permisos actualizada correctamente.';
        }
    }
}

// ---------------------------------------------------------------
// Estado actual de la matriz para pintar la tabla (usa el rol editable de cada nivel)
// ---------------------------------------------------------------
$estadoMatriz = [];
foreach ([1, 2, 3] as $nivel) {
    $rol = $rolEditablePorNivel[$nivel];
    $permisosRol = $rol ? obtener_permisos_rol($pdo, $rol['id']) : [];
    foreach ($modulos as $clave => $def) {
        if (!empty($def['fijo'])) {
            $estadoMatriz[$nivel][$clave] = 'lectura';
            continue;
        }
        $tieneWrite = $def['write'] && in_array($def['write'], $permisosRol, true);
        $tieneRead = $def['read'] && in_array($def['read'], $permisosRol, true);
        $estadoMatriz[$nivel][$clave] = $tieneWrite ? 'gestion' : ($tieneRead ? 'lectura' : 'ninguno');
    }
}

// ---------------------------------------------------------------
// Usuarios del sistema vinculados al modulo de cursos
// ---------------------------------------------------------------
$usuarios = $pdo->query("
    SELECT DISTINCT
        u.id, u.nombre, u.correo, u.es_superadmin,
        r.nombre_rol,
        COALESCE(i.nombre_ingenio, 'Sin ingenio') AS nombre_ingenio
    FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id
    LEFT JOIN ingenios i ON i.id = u.ingenio_id
    INNER JOIN usuario_modulo um ON um.usuario_id = u.id
    INNER JOIN modulos m ON m.id = um.modulo_id
    WHERE LOWER(m.nombre) IN ('cursos', 'cengicursos')
    ORDER BY u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

function cengi_rol_html($valor)
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
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-ok"></span></div>
            <div><p><?php echo cengi_rol_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <div class="cengi-role-card-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
        <?php foreach ([1, 2, 3] as $nivel): ?>
            <div class="cengi-role-card">
                <span class="rc-level">Nivel <?php echo $nivel; ?></span>
                <h3><?php echo cengi_rol_html($nombresNivel[$nivel]); ?></h3>
                <p><?php echo cengi_rol_html($descripcionNivel[$nivel]); ?></p>
                <div style="font-size:11px;color:var(--cengi-muted);">
                    Roles en usuarios_menu:
                    <?php echo $rolesPorNivel[$nivel] ? cengi_rol_html(implode(', ', array_map(static function ($r) { return $r['nombre_rol']; }, $rolesPorNivel[$nivel]))) : 'ninguno registrado todavia'; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="cengi-notice" style="margin-top:16px;">
        <span class="glyphicon glyphicon-info-sign"></span>
        <span>Los roles de <strong>Instructor</strong> y <strong>Participante</strong> no se gestionan en SIGEC: instructores y participantes acceden al contenido, tareas y evaluaciones a traves de la plataforma <strong>Moodle</strong>, que se sincroniza con SIGEC mediante la integracion señalada abajo. (Este es un aviso informativo; no existe integracion real con Moodle en este monorepo.)</span>
    </div>

    <div class="panel panel-success" style="margin-top:16px;">
        <div class="panel-heading">
            <h3 class="panel-title">Matriz de permisos por modulo</h3>
            <small>Persiste directamente contra <code>usuarios_menu.rol_permiso</code>. Superadmin siempre tiene acceso total sin importar esta matriz (bypass por <code>es_superadmin=1</code>).</small>
        </div>
        <div class="panel-body" style="padding:0;">
            <div class="cengi-table-wrap" style="border:0;border-radius:0;">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Modulo del sistema</th>
                        <?php foreach ([1, 2, 3] as $nivel): ?>
                            <th style="text-align:center;"><?php echo cengi_rol_html($nombresNivel[$nivel]); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modulos as $clave => $def): ?>
                        <tr>
                            <td><?php echo cengi_rol_html($def['label']); ?></td>
                            <?php foreach ([1, 2, 3] as $nivel):
                                $valor = $estadoMatriz[$nivel][$clave] ?? 'ninguno';
                                $claseChip = $valor === 'gestion' ? 'cengi-perm-gestion' : ($valor === 'lectura' ? 'cengi-perm-lectura' : 'cengi-perm-ninguno');
                                $etiqueta = $valor === 'gestion' ? 'Gestion completa' : ($valor === 'lectura' ? 'Solo lectura' : 'Sin acceso');
                            ?>
                                <td style="text-align:center;"><span class="cengi-tag <?php echo $claseChip; ?>"><?php echo $etiqueta; ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <div class="panel-body" style="display:flex;gap:14px;flex-wrap:wrap;border-top:1px solid var(--cengi-border);">
            <span style="font-size:11px;color:var(--cengi-muted);"><span class="cengi-tag cengi-perm-gestion">Gestion completa</span> puede crear, editar y eliminar</span>
            <span style="font-size:11px;color:var(--cengi-muted);"><span class="cengi-tag cengi-perm-lectura">Solo lectura</span> unicamente puede consultar</span>
            <span style="font-size:11px;color:var(--cengi-muted);"><span class="cengi-tag cengi-perm-ninguno">Sin acceso</span> el modulo no aparece en su menu</span>
        </div>
    </div>

    <?php if ($puedeGestionar): ?>
    <div class="panel panel-success">
        <div class="panel-heading"><h3 class="panel-title">Editar permisos de un rol</h3></div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_matriz">
                <div class="form-group">
                    <label class="control-label">Rol a editar</label>
                    <select name="rol_id" class="form-control" id="rolSelector" onchange="cengiCargarNivelesRol()">
                        <?php foreach ([1, 2, 3] as $nivel): ?>
                            <?php $rol = $rolEditablePorNivel[$nivel]; ?>
                            <?php if ($rol): ?>
                                <option value="<?php echo (int) $rol['id']; ?>" data-nivel="<?php echo $nivel; ?>">
                                    <?php echo cengi_rol_html($nombresNivel[$nivel] . ' (' . $rol['nombre_rol'] . ')'); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cengi-table-wrap">
                    <table class="table table-bordered">
                        <thead><tr><th>Modulo</th><th>Gestion completa</th><th>Solo lectura</th><th>Sin acceso</th></tr></thead>
                        <tbody>
                            <?php foreach ($modulos as $clave => $def): ?>
                                <?php if (!empty($def['fijo'])) { continue; } ?>
                                <tr>
                                    <td><?php echo cengi_rol_html($def['label']); ?></td>
                                    <?php foreach (['gestion', 'lectura', 'ninguno'] as $opcion): ?>
                                        <td style="text-align:center;">
                                            <input type="radio" name="nivel[<?php echo cengi_rol_html($clave); ?>]" value="<?php echo $opcion; ?>" class="cengi-matriz-radio" data-modulo="<?php echo cengi_rol_html($clave); ?>" data-valor="<?php echo $opcion; ?>">
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-success"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar permisos de este rol</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel panel-success">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 class="panel-title">Usuarios del sistema</h3>
                <small>Asigna el rol y, si aplica, el ingenio/institucion al que queda limitado cada usuario</small>
            </div>
            <?php if ($puedeGestionar): ?>
                <a href="../login/usuarios/crear_usuario.php?scope=cursos" class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-plus"></span> Nuevo usuario</a>
            <?php endif; ?>
        </div>
        <div class="cengi-table-wrap">
            <table class="table table-striped table-bordered table-hover">
                <thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Ingenio / institucion asignado</th><th>Estado</th><?php if ($puedeGestionar): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><strong><?php echo cengi_rol_html($u['nombre']); ?></strong></td>
                            <td><?php echo cengi_rol_html($u['correo']); ?></td>
                            <td><?php echo cengi_rol_html($u['nombre_rol']); ?></td>
                            <td><?php echo cengi_rol_html($u['nombre_ingenio']); ?></td>
                            <td>
                                <?php if ((int) $u['es_superadmin'] === 1): ?>
                                    <span class="cengi-status-badge is-active"><i></i>Superadmin</span>
                                <?php else: ?>
                                    <span class="cengi-status-badge is-neutral"><i></i>Activo</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($puedeGestionar): ?>
                                <td>
                                    <a class="cengi-action-btn is-edit" href="../login/usuarios/editar_usuario.php?id=<?php echo (int) $u['id']; ?>&scope=cursos" data-tooltip="Editar"><span class="glyphicon glyphicon-pencil"></span></a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel panel-success">
        <div class="panel-heading"><h3 class="panel-title">Integraciones futuras</h3><small>Preparado para conectarse con:</small></div>
        <div class="panel-body" style="display:flex;gap:8px;flex-wrap:wrap;">
            <span class="cengi-tag">Moodle</span>
            <span class="cengi-tag">Power BI</span>
            <span class="cengi-tag">Microsoft Teams</span>
            <span class="cengi-tag">WhatsApp Business API</span>
            <span class="cengi-tag">Correo electronico</span>
            <span class="cengi-tag">Sistemas contables</span>
        </div>
        <div class="panel-body" style="padding-top:0;">
            <p class="text-muted" style="font-size:11.5px;">Ninguna de estas integraciones esta conectada todavia: son placeholders informativos, igual que en el mockup de referencia.</p>
        </div>
    </div>
</div>

<?php if ($puedeGestionar): ?>
<script>
var cengiEstadoMatriz = <?php echo json_encode($estadoMatriz, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var cengiNivelPorRolId = {};
<?php foreach ([1, 2, 3] as $nivel): ?>
<?php if ($rolEditablePorNivel[$nivel]): ?>
cengiNivelPorRolId[<?php echo (int) $rolEditablePorNivel[$nivel]['id']; ?>] = <?php echo $nivel; ?>;
<?php endif; ?>
<?php endforeach; ?>

function cengiCargarNivelesRol() {
    var select = document.getElementById('rolSelector');
    if (!select) { return; }
    var rolId = select.value;
    var nivel = cengiNivelPorRolId[rolId];
    var estado = cengiEstadoMatriz[nivel] || {};

    document.querySelectorAll('.cengi-matriz-radio').forEach(function (radio) {
        var modulo = radio.getAttribute('data-modulo');
        var valor = radio.getAttribute('data-valor');
        radio.checked = (estado[modulo] === valor);
    });
}
document.addEventListener('DOMContentLoaded', cengiCargarNivelesRol);
</script>
<?php endif; ?>
</body>
</html>
