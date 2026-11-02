<?php
/**
 * Roles y permisos (Sistema): detalle de los roles del flujo de Laboratorio — qué hace cada uno,
 * qué le notifica la campanita y qué pantallas/acciones tiene habilitadas — y su estado real en
 * la base central (creado, usuarios, permisos asignados). Un superadmin puede crear o actualizar
 * los roles desde aquí; la edición fina sigue en Menú principal > Roles y permisos.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../config/roles_laboratorio.php';

lab_require_module_access();

$usuario = lab_current_user();
$esSuperadmin = (bool) $usuario['es_superadmin'];
$_SESSION['roles_csrf'] ??= bin2hex(random_bytes(32));

function roles_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function roles_norm(string $v): string
{
    $v = mb_strtolower(trim($v), 'UTF-8');
    return strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

$mensaje = $_SESSION['roles_flash'] ?? null;
unset($_SESSION['roles_flash']);
$error = '';
$definicion = lab_roles_definicion(lab_roles_permisos_lab());
$estado = [];
// Acceso a la pantalla de Usuarios: superadmin o quien tenga "Asignar usuarios y roles del laboratorio".
$puedeAsignar = $esSuperadmin || lab_can('laboratorio.usuarios.gestionar');

try {
    $central = lab_roles_db_central();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!$esSuperadmin) {
            lab_forbidden('Solo un superadmin puede crear o actualizar los roles.');
        }
        if (!hash_equals($_SESSION['roles_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            lab_forbidden('La sesión del formulario expiró. Recargue la página.');
        }
        $sincronizar = ($_POST['modo'] ?? '') === 'sincronizar';
        $resultado = lab_roles_aplicar($central, $sincronizar);
        $creados = array_keys(array_filter($resultado['roles'], static fn($r) => $r['creado']));
        $nuevos = array_sum(array_column($resultado['roles'], 'nuevos'));
        $quitados = array_sum(array_column($resultado['roles'], 'quitados'));
        $_SESSION['roles_flash'] = ($creados ? 'Roles creados: ' . implode(', ', $creados) . '. ' : 'Los roles ya existían. ')
            . "Permisos asignados ahora: {$nuevos}" . ($sincronizar ? " · quitados: {$quitados}" : '') . '.'
            . ($resultado['modulo_laboratorio'] ? '' : ' Aviso: no existe el módulo "Laboratorio"; asígnelo a los usuarios desde Administración.')
            . ' Los usuarios deben cerrar e iniciar sesión para que se apliquen.';
        header('Location: roles_view.php');
        exit;
    }

    asegurar_tablas_permisos($central);
    $definicion = lab_roles_definicion(lab_roles_permisos_lab($central));
    $estado = lab_roles_estado($central);
} catch (Throwable $e) {
    error_log('[laboratorio][roles] ' . $e->getMessage());
    // El detalle (host, usuario, esquema) queda solo en el log.
    $error = 'No se pudo consultar la base central de usuarios. Inténtelo más tarde o contacte al administrador.';
}

$faltanRoles = $estado ? count(array_filter($estado, static fn($r) => $r === null)) : 0;
$rolActual = roles_norm((string) ($usuario['rol'] ?? ''));
$clasesRol = ['Superadmin' => 'is-super', 'Jefe de laboratorio' => 'is-jefe', 'Técnico' => 'is-tecnico', 'Analista' => 'is-analista', 'Recepcionista' => 'is-recepcion'];
$matriz = lab_roles_matriz();
$conPendientes = $estado ? count(array_filter($definicion, static function ($rol, $nombre) use ($estado) {
    $st = $estado[$nombre] ?? null;
    return !$st || array_diff($rol['permisos'], $st['permisos']);
}, ARRAY_FILTER_USE_BOTH)) : 0;

lab_shell_head('Roles y permisos', 'Qué hace cada rol del laboratorio', [
    '../css/lab_shell.css?v=20261006',
    '../styles/roles.css?v=' . filemtime(__DIR__ . '/../styles/roles.css'),
]);
lab_shell_open('roles_view.php');
?>
<section class="rp-page">
    <?php if ($mensaje): ?><div class="roles-alert is-ok" role="status"><?= roles_e($mensaje) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="roles-alert is-error" role="alert"><?= roles_e($error) ?></div><?php endif; ?>

    <header class="rp-head">
        <div>
            <h2>Roles del laboratorio</h2>
            <p>Cada persona tiene un rol. El rol define qué pantallas ve, qué puede hacer y qué le avisa la campanita.
                Aquí se consulta; los permisos se configuran en el panel central y las personas se asignan en Usuarios.</p>
        </div>
        <div class="rp-head-actions">
            <?php if ($puedeAsignar): ?><a class="rp-btn rp-btn-primary" href="usuarios_view.php"><span class="material-symbols-outlined" aria-hidden="true">group</span>Asignar personas</a><?php endif; ?>
            <?php if ($esSuperadmin): ?><a class="rp-btn rp-btn-light" href="../../login/roles/roles.php"><span class="material-symbols-outlined" aria-hidden="true">tune</span>Editar permisos</a><?php endif; ?>
        </div>
    </header>

    <?php if (!$error && $faltanRoles > 0 && !$esSuperadmin): ?>
        <div class="roles-alert is-warn">Algunos roles del laboratorio aún no están creados. Pida a un superadmin que los cree.</div>
    <?php endif; ?>

    <div class="rp-list" role="list">
        <?php foreach ($definicion as $nombre => $rol):
            $st = $estado[$nombre] ?? null;
            $esMio = $rolActual !== '' && roles_norm($nombre) === $rolActual;
            $puede = array_values(array_filter($matriz, static fn($f) => $nombre === 'Superadmin' || in_array($f[2], $rol['permisos'], true)));
            $porSeccion = [];
            foreach ($puede as [$sec, $accion]) $porSeccion[$sec][] = $accion; ?>
            <details class="rp-role <?= roles_e($clasesRol[$nombre] ?? '') ?><?= $esMio ? ' is-mine' : '' ?>" role="listitem"<?= $esMio ? ' open' : '' ?>>
                <summary>
                    <span class="rp-dot" aria-hidden="true"></span>
                    <span class="rp-role-main">
                        <strong><?= roles_e($nombre) ?><?php if ($esMio): ?> <span class="rp-mine">Su rol</span><?php endif; ?></strong>
                        <small><?= roles_e($rol['descripcion']) ?></small>
                    </span>
                    <span class="rp-role-meta">
                        <?php if ($st): ?><b><?= (int) $st['usuarios'] ?></b> persona(s)<?php elseif (!$error): ?><em>No creado</em><?php endif; ?>
                    </span>
                    <span class="material-symbols-outlined rp-chevron" aria-hidden="true">expand_more</span>
                </summary>
                <div class="rp-role-body">
                    <div class="rp-can">
                        <h4>Puede</h4>
                        <?php foreach ($porSeccion as $sec => $acciones): ?>
                            <div class="rp-can-group"><span><?= roles_e($sec) ?></span>
                                <ul><?php foreach ($acciones as $accion): ?><li><?= roles_e($accion) ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rp-notif">
                        <h4><span class="material-symbols-outlined" aria-hidden="true">notifications</span>La campanita le avisa</h4>
                        <p><?= roles_e($rol['notifica']) ?></p>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
    </div>

    <details class="rp-panel">
        <summary><span class="material-symbols-outlined" aria-hidden="true">table_view</span>Comparar roles<small>Tabla de lo que puede hacer cada rol</small></summary>
        <div class="roles-matrix-wrap"><table class="roles-matrix">
            <thead><tr><th scope="col">Pantalla / acción</th><?php foreach (array_keys($definicion) as $nombre): ?><th scope="col"><?= roles_e($nombre) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php $seccion = null; foreach ($matriz as [$sec, $accion, $permiso]): ?>
                <?php if ($sec !== $seccion): $seccion = $sec; ?><tr class="roles-sec"><th colspan="<?= count($definicion) + 1 ?>" scope="rowgroup"><?= roles_e($sec) ?></th></tr><?php endif; ?>
                <tr>
                    <th scope="row"><?= roles_e($accion) ?></th>
                    <?php foreach ($definicion as $nombre => $rol): $si = $nombre === 'Superadmin' || in_array($permiso, $rol['permisos'], true); ?>
                        <td class="<?= $si ? 'is-yes' : 'is-no' ?>"><span aria-hidden="true"><?= $si ? '✓' : '—' ?></span><span class="sr-only"><?= $si ? 'Sí' : 'No' ?></span></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </details>

    <?php if ($esSuperadmin && !$error): ?>
        <details class="rp-panel"<?= ($faltanRoles || $conPendientes) ? ' open' : '' ?>>
            <summary><span class="material-symbols-outlined" aria-hidden="true">build</span>Mantenimiento de roles
                <small><?= ($faltanRoles || $conPendientes) ? $conPendientes . ' rol(es) por crear o completar' : 'Todos los roles están al día' ?></small></summary>
            <div class="rp-maint">
                <ul class="rp-maint-list">
                    <?php foreach ($definicion as $nombre => $rol): $st = $estado[$nombre] ?? null; $faltan = $st ? array_values(array_diff($rol['permisos'], $st['permisos'])) : []; ?>
                        <li>
                            <span><?= roles_e($nombre) ?></span>
                            <?php if (!$st): ?><span class="roles-chip is-warn">No creado</span>
                            <?php elseif ($faltan): ?><span class="roles-chip is-warn" title="<?= roles_e('Faltan: ' . implode(', ', $faltan)) ?>">Faltan <?= count($faltan) ?> permiso(s)</span>
                            <?php else: ?><span class="roles-chip is-ok">Al día · rol #<?= (int) $st['id'] ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" class="roles-actions">
                    <input type="hidden" name="csrf" value="<?= roles_e($_SESSION['roles_csrf']) ?>">
                    <button type="submit" name="modo" value="agregar" class="cengi-btn cengi-btn-primary cengi-btn-sm">Crear o actualizar roles</button>
                    <button type="submit" name="modo" value="sincronizar" class="cengi-btn cengi-btn-ghost cengi-btn-sm"
                            onclick="return confirm('Se quitarán a estos 5 roles los permisos de Laboratorio que no estén en su definición. ¿Continuar?');">Sincronizar exactamente</button>
                </form>
                <p class="rp-maint-note">"Crear o actualizar" agrega los roles y permisos que falten; "Sincronizar" además quita los permisos de Laboratorio que sobren. Los cambios se aplican al volver a iniciar sesión.</p>
            </div>
        </details>
    <?php endif; ?>
</section>
<?php lab_shell_close(); ?>
