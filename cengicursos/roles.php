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
    'diplomas' => ['label' => 'Diplomas y certificación', 'read' => 'ver_diplomas_cengi', 'write' => 'gestionar_diplomas_cengi'],
    'carga_masiva' => ['label' => 'Carga masiva', 'read' => 'ver_participantes_cengi', 'write' => 'cargar_participantes_cengi'],
    'reportes' => ['label' => 'Reportes por ingenio', 'read' => 'ver_reportes_cengi', 'write' => 'ver_reportes_cengi'],
    'roles' => ['label' => 'Roles y permisos', 'read' => 'ver_roles_cengi', 'write' => 'roles_gestionar_cengi'],
];

$nombresNivel = [1 => 'Administrador general', 2 => 'Encargado de capacitación', 3 => 'Administrador de ingenio'];
$descripcionNivel = [
    1 => 'Acceso completo al sistema, configuración e integraciones.',
    2 => 'Gestiona cursos, participantes, evaluaciones y diplomas.',
    3 => 'Consulta únicamente a sus propios colaboradores.',
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
$usuarios = array_values(array_filter($usuarios, static function ($usuario) {
    $rolNormalizado = cengi_texto_normalizado($usuario['nombre_rol']);
    return strpos($rolNormalizado, 'instructor') === false
        && strpos($rolNormalizado, 'estudiante') === false
        && strpos($rolNormalizado, 'alumno') === false
        && strpos($rolNormalizado, 'participante') === false;
}));
$conteoUsuariosPorNivel = [1 => 0, 2 => 0, 3 => 0];
foreach ($usuarios as $usuario) {
    $nivelUsuario = cengi_clasificar_rol_nivel($usuario['nombre_rol']);
    if (isset($conteoUsuariosPorNivel[$nivelUsuario])) {
        $conteoUsuariosPorNivel[$nivelUsuario]++;
    }
}

function cengi_rol_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_iniciales_usuario($nombre)
{
    $iniciales = '';
    foreach (preg_split('/\s+/', trim((string) $nombre), -1, PREG_SPLIT_NO_EMPTY) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($iniciales, 'UTF-8') >= 2) {
            break;
        }
    }
    return $iniciales !== '' ? $iniciales : 'U';
}
?>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas cengi-roles-canvas">
<?php menu_render(); ?>
<main class="container cengi-roles-page">

    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-ok"></span></div>
            <div><p><?php echo cengi_rol_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <div class="cengi-role-card-grid">
        <?php foreach ([1, 2, 3] as $nivel): ?>
            <?php
                $cantidadGestion = 0;
                $cantidadLectura = 0;
                foreach ($estadoMatriz[$nivel] as $permisoNivel) {
                    if ($permisoNivel === 'gestion') { $cantidadGestion++; }
                    if ($permisoNivel === 'lectura') { $cantidadLectura++; }
                }
                $rolEditable = $rolEditablePorNivel[$nivel];
            ?>
            <section class="cengi-ref-section cengi-role-card">
                <div class="cengi-ref-section-body">
                    <div class="cengi-role-card-top">
                        <span class="cengi-role-level is-level-<?php echo $nivel; ?>">Nivel <?php echo $nivel; ?></span>
                        <span class="cengi-role-user-count"><?php echo (int) $conteoUsuariosPorNivel[$nivel]; ?> usuarios</span>
                    </div>
                    <h3><?php echo cengi_rol_html($nombresNivel[$nivel]); ?></h3>
                    <p><?php echo cengi_rol_html($descripcionNivel[$nivel]); ?></p>
                    <?php if ($nivel === 3): ?>
                        <div class="cengi-role-warning"><span aria-hidden="true">&#9888;</span> Cada usuario con este rol debe tener un ingenio/institución asignado abajo, en <strong>Usuarios del sistema</strong> &mdash; así solo ve sus propios colaboradores.</div>
                    <?php endif; ?>
                    <div class="cengi-role-summary">
                        <span class="cengi-ref-chip cengi-perm-gestion"><?php echo $cantidadGestion; ?> con gestión</span>
                        <span class="cengi-ref-chip cengi-perm-lectura"><?php echo $cantidadLectura; ?> solo lectura</span>
                    </div>
                    <?php if ($puedeGestionar && $rolEditable): ?>
                        <button type="button" class="cengi-ref-button cengi-ref-button-outline cengi-role-edit" onclick="cengiAbrirPermisos(<?php echo $nivel; ?>, <?php echo (int) $rolEditable['id']; ?>)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                            Editar permisos
                        </button>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="cengi-ref-notice">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
        <span>Los roles de <strong>Instructor</strong> y <strong>Participante</strong> no se gestionan en SIGEC: instructores y participantes acceden al contenido, tareas y evaluaciones a través de la plataforma <strong>Moodle</strong>, que se sincroniza con SIGEC mediante la integración señalada abajo.</span>
    </div>

    <section class="cengi-ref-section cengi-permissions-section">
        <div class="cengi-ref-section-head">
            <div>
                <h3>Matriz de permisos por módulo</h3>
                <div class="hint">Vista general &mdash; usa "Editar permisos" en cada rol para modificarla</div>
            </div>
        </div>
        <div class="cengi-ref-table-scroll">
            <table class="cengi-ref-table">
                <thead>
                    <tr>
                        <th>Módulo del sistema</th>
                        <?php foreach ([1, 2, 3] as $nivel): ?>
                            <th style="text-align:center;"><?php echo cengi_rol_html($nombresNivel[$nivel]); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modulos as $clave => $def): ?>
                        <tr>
                            <td><strong><?php echo cengi_rol_html($def['label']); ?></strong></td>
                            <?php foreach ([1, 2, 3] as $nivel):
                                $valor = $estadoMatriz[$nivel][$clave] ?? 'ninguno';
                                $claseChip = $valor === 'gestion' ? 'cengi-perm-gestion' : ($valor === 'lectura' ? 'cengi-perm-lectura' : 'cengi-perm-ninguno');
                                $etiqueta = $valor === 'gestion' ? 'Gestión completa' : ($valor === 'lectura' ? 'Solo lectura' : 'Sin acceso');
                            ?>
                                <td style="text-align:center;"><span class="cengi-tag <?php echo $claseChip; ?>"><?php echo $etiqueta; ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="cengi-permissions-legend">
            <span><span class="cengi-ref-chip cengi-perm-gestion">Gestión completa</span> puede crear, editar y eliminar</span>
            <span><span class="cengi-ref-chip cengi-perm-lectura">Solo lectura</span> únicamente puede consultar</span>
            <span><span class="cengi-ref-chip cengi-perm-ninguno">Sin acceso</span> el módulo no aparece en su menu</span>
        </div>
    </section>

    <section class="cengi-ref-section cengi-users-section">
        <div class="cengi-ref-section-head">
            <div>
                <h3>Usuarios del sistema</h3>
                <div class="hint">Asigna el rol y, si aplica, el ingenio/institución al que queda limitado cada usuario</div>
            </div>
            <?php if ($puedeGestionar): ?>
                <a href="../login/usuarios/crear_usuario.php?scope=cursos" class="cengi-ref-button cengi-ref-button-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Nuevo usuario
                </a>
            <?php endif; ?>
        </div>
        <div class="cengi-ref-table-scroll">
            <table class="cengi-ref-table">
                <thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Ingenio / institucion asignado</th><th>Estado</th><?php if ($puedeGestionar): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td>
                                <div class="cengi-user-name-cell">
                                    <span class="cengi-user-avatar"><?php echo cengi_rol_html(cengi_iniciales_usuario($u['nombre'])); ?></span>
                                    <strong><?php echo cengi_rol_html($u['nombre']); ?></strong>
                                </div>
                            </td>
                            <td class="cengi-user-email"><?php echo cengi_rol_html($u['correo']); ?></td>
                            <td><?php echo cengi_rol_html($u['nombre_rol']); ?></td>
                            <td>
                                <?php if ($u['nombre_ingenio'] !== 'Sin ingenio'): ?>
                                    <span class="cengi-ref-chip"><?php echo cengi_rol_html($u['nombre_ingenio']); ?></span>
                                <?php else: ?>
                                    <span class="cengi-user-no-org">Todos / no aplica</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="cengi-user-status"><i></i>Activo</span></td>
                            <?php if ($puedeGestionar): ?>
                                <td>
                                    <a class="cengi-user-edit" href="../login/usuarios/editar_usuario.php?id=<?php echo (int) $u['id']; ?>&scope=cursos" title="Editar" aria-label="Editar a <?php echo cengi_rol_html($u['nombre']); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                                    </a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="cengi-ref-section cengi-integrations-section">
        <div class="cengi-ref-section-head">
            <div><h3>Integraciones futuras</h3><div class="hint">Preparado para conectarse con:</div></div>
        </div>
        <div class="cengi-ref-section-body cengi-integrations-list">
            <span class="cengi-ref-chip">Moodle</span>
            <span class="cengi-ref-chip">Power BI</span>
            <span class="cengi-ref-chip">Microsoft Teams</span>
            <span class="cengi-ref-chip">WhatsApp Business API</span>
            <span class="cengi-ref-chip">Correo electrónico</span>
            <span class="cengi-ref-chip">Sistemas contables</span>
        </div>
    </section>
</main>

<?php if ($puedeGestionar): ?>
<div class="cengi-permissions-modal" id="cengiPermissionsModal" aria-hidden="true">
    <form method="POST" class="cengi-permissions-dialog" id="cengiPermissionsForm">
        <input type="hidden" name="accion" value="guardar_matriz">
        <input type="hidden" name="rol_id" id="cengiPermRolId" value="">
        <div class="cengi-permissions-modal-head">
            <div>
                <h3 id="cengiPermModalTitle">Editar permisos</h3>
                <div class="hint">Define el nivel de acceso de este rol en cada módulo del sistema</div>
            </div>
            <button type="button" class="cengi-user-edit" onclick="cengiCerrarPermisos()" aria-label="Cerrar">&times;</button>
        </div>
        <div class="cengi-permissions-modal-body">
            <?php foreach ($modulos as $clave => $def): ?>
                <div class="cengi-permission-row" data-modulo="<?php echo cengi_rol_html($clave); ?>">
                    <div class="cengi-permission-name"><?php echo cengi_rol_html($def['label']); ?></div>
                    <div class="cengi-permission-options">
                        <?php foreach (['gestion' => 'Gestión', 'lectura' => 'Lectura', 'ninguno' => 'Sin acceso'] as $opcion => $texto): ?>
                            <button type="button" class="cengi-permission-option" data-value="<?php echo $opcion; ?>"<?php echo !empty($def['fijo']) ? ' disabled' : ''; ?>><?php echo $texto; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php if (empty($def['fijo'])): ?>
                        <input type="hidden" name="nivel[<?php echo cengi_rol_html($clave); ?>]" value="ninguno">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="cengi-permissions-modal-foot">
            <button type="button" class="cengi-ref-button cengi-ref-button-outline" onclick="cengiCerrarPermisos()">Cancelar</button>
            <button type="submit" class="cengi-ref-button cengi-ref-button-primary">Guardar permisos</button>
        </div>
    </form>
</div>

<script>
var cengiEstadoMatriz = <?php echo json_encode($estadoMatriz, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var cengiNombresNivel = <?php echo json_encode($nombresNivel, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function cengiSeleccionarOpcion(row, valor) {
    row.querySelectorAll('.cengi-permission-option').forEach(function (button) {
        var seleccionado = button.getAttribute('data-value') === valor;
        button.classList.toggle('is-' + button.getAttribute('data-value'), seleccionado);
        button.setAttribute('aria-pressed', seleccionado ? 'true' : 'false');
    });
    var input = row.querySelector('input[type="hidden"]');
    if (input) { input.value = valor; }
}

function cengiAbrirPermisos(nivel, rolId) {
    var modal = document.getElementById('cengiPermissionsModal');
    var estado = cengiEstadoMatriz[nivel] || {};
    document.getElementById('cengiPermRolId').value = rolId;
    document.getElementById('cengiPermModalTitle').textContent = 'Editar permisos — ' + cengiNombresNivel[nivel];
    modal.querySelectorAll('.cengi-permission-row').forEach(function (row) {
        var modulo = row.getAttribute('data-modulo');
        cengiSeleccionarOpcion(row, estado[modulo] || 'ninguno');
    });
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('cengi-modal-open');
}

function cengiCerrarPermisos() {
    var modal = document.getElementById('cengiPermissionsModal');
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('cengi-modal-open');
}

document.querySelectorAll('.cengi-permission-option:not([disabled])').forEach(function (button) {
    button.addEventListener('click', function () {
        cengiSeleccionarOpcion(button.closest('.cengi-permission-row'), button.getAttribute('data-value'));
    });
});

document.getElementById('cengiPermissionsModal').addEventListener('click', function (event) {
    if (event.target === this) { cengiCerrarPermisos(); }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { cengiCerrarPermisos(); }
});
</script>
<?php endif; ?>
</body>
</html>