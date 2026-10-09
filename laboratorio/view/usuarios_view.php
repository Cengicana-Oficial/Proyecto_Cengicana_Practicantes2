<?php
/**
 * Usuarios del laboratorio (Sistema › Usuarios): quién tiene acceso al módulo, con qué rol, y
 * acciones para dar acceso, cambiar el rol o quitar el acceso. Los usuarios nuevos se crean en el
 * formulario central (login/usuarios/crear_usuario.php?scope=laboratorio), que regresa aquí.
 * Reglas de asignación: config/roles_laboratorio.php::lab_usuario_asignar().
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../config/roles_laboratorio.php';

lab_require_module_access();

$usuario = lab_current_user();
$esSuperadmin = (bool) $usuario['es_superadmin'];
if (!$esSuperadmin && !lab_can('laboratorio.usuarios.gestionar')) {
    lab_forbidden('No tiene permiso para gestionar los usuarios del laboratorio.');
}
$_SESSION['roles_csrf'] ??= bin2hex(random_bytes(32));
$miId = (int) ($usuario['id'] ?? 0);

function usr_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function usr_iniciales(string $nombre): string
{
    $ini = '';
    foreach (preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) as $parte) {
        $ini .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($ini, 'UTF-8') >= 2) break;
    }
    return $ini !== '' ? $ini : '?';
}

/** Clase de color por rol (paleta oficial). */
function usr_clase_rol(string $rol, bool $esSuper): string
{
    if ($esSuper) return 'is-super';
    $r = strtr(mb_strtolower($rol, 'UTF-8'), ['é' => 'e', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    return match (true) {
        str_contains($r, 'superadmin') => 'is-super',
        str_contains($r, 'jefe') || str_contains($r, 'jefa') => 'is-jefe',
        str_contains($r, 'tecnico') => 'is-tecnico',
        str_contains($r, 'analista') => 'is-analista',
        str_contains($r, 'recepcion') => 'is-recepcion',
        default => 'is-otro',
    };
}

$mensaje = $_SESSION['roles_flash'] ?? null;
$errorAsignacion = $_SESSION['roles_flash_error'] ?? '';
unset($_SESSION['roles_flash'], $_SESSION['roles_flash_error']);
$error = '';
$datos = ['con_acceso' => [], 'sin_acceso' => [], 'modulo' => 0];
$rolesAsignables = [];
$creado = (int) ($_GET['creado'] ?? 0);

try {
    $central = lab_roles_db_central();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals($_SESSION['roles_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            lab_forbidden('La sesión del formulario expiró. Recargue la página.');
        }
        try {
            $resultado = lab_usuario_asignar($central, $miId, $esSuperadmin, (int) ($_POST['usuario_id'] ?? 0),
                (string) ($_POST['accion_usuario'] ?? ''), (int) ($_POST['rol_id'] ?? 0));
            $_SESSION['roles_flash'] = $resultado;
            try {
                require_once __DIR__ . '/../conexion.php';
                require_once __DIR__ . '/../includes/catalogo_control_helper.php';
                labCatalogoControlEsquema($conexion);
                labCatalogoAuditar($conexion, (string) ($usuario['nombre'] ?? 'Usuario'), 'Usuarios y roles', 'Usuario #' . (int) $_POST['usuario_id'], $resultado);
            } catch (Throwable $e) {
                error_log('[laboratorio][usuarios] auditoría: ' . $e->getMessage());
            }
        } catch (InvalidArgumentException $e) {
            $_SESSION['roles_flash_error'] = $e->getMessage();
        }
        header('Location: usuarios_view.php' . ((int) ($_POST['usuario_id'] ?? 0) ? '#usuario-' . (int) $_POST['usuario_id'] : ''));
        exit;
    }

    // El módulo "Laboratorio" debe existir para que el formulario central asigne el acceso.
    lab_modulo_laboratorio_id($central, true);
    $datos = lab_usuarios_laboratorio($central);
    $rolesAsignables = lab_roles_asignables($central, $esSuperadmin);
} catch (Throwable $e) {
    error_log('[laboratorio][usuarios] ' . $e->getMessage());
    $error = 'No se pudo consultar la base central de usuarios: ' . $e->getMessage();
}

// Resumen por rol
$porRol = [];
foreach ($datos['con_acceso'] as $u) {
    $clave = $u['es_superadmin'] ? 'Superadmin' : ($u['rol'] ?: 'Sin rol');
    $porRol[$clave] ??= ['total' => 0, 'clase' => usr_clase_rol($clave, $u['es_superadmin'])];
    $porRol[$clave]['total']++;
}
$ordenRoles = array_flip(['Superadmin', 'Jefe de laboratorio', 'Técnico', 'Analista', 'Recepcionista']);
uksort($porRol, static fn($a, $b) => ($ordenRoles[$a] ?? 9) <=> ($ordenRoles[$b] ?? 9) ?: strcmp($a, $b));

lab_shell_head('Usuarios', 'Personas con acceso al sistema de laboratorio y su rol', [
    '../css/lab_shell.css?v=20261006',
    '../styles/usuarios_lab.css?v=' . filemtime(__DIR__ . '/../styles/usuarios_lab.css'),
]);
lab_shell_open('usuarios_view.php');
?>
<section class="usr-page">
    <?php if ($mensaje): ?><div class="usr-alert is-ok" role="status"><?= usr_e($mensaje) ?></div><?php endif; ?>
    <?php if ($errorAsignacion !== ''): ?><div class="usr-alert is-error" role="alert"><?= usr_e($errorAsignacion) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="usr-alert is-error" role="alert"><?= usr_e($error) ?></div><?php endif; ?>
    <?php if (!$error && !$rolesAsignables): ?>
        <div class="usr-alert is-warn">Los roles del laboratorio aún no están creados. Un superadmin puede crearlos en <a href="roles_view.php">Roles y permisos</a>.</div>
    <?php endif; ?>

    <header class="usr-head">
        <div>
            <h2>Personas con acceso al laboratorio</h2>
            <p>El rol define qué pantallas ve cada persona, qué puede hacer y qué le avisa la campanita. Los cambios se aplican cuando la persona vuelve a iniciar sesión.</p>
        </div>
        <div class="usr-head-actions">
            <a class="usr-btn usr-btn-primary" href="../../login/usuarios/crear_usuario.php?scope=laboratorio"><span class="material-symbols-outlined" aria-hidden="true">person_add</span>Crear usuario</a>
            <a class="usr-btn usr-btn-light" href="roles_view.php"><span class="material-symbols-outlined" aria-hidden="true">admin_panel_settings</span>Ver roles y permisos</a>
        </div>
    </header>

    <?php if (!$error): ?>
        <section class="usr-summary" aria-label="Personas por rol">
            <button type="button" class="usr-sum is-on" data-filtro-rol="">
                <strong><?= count($datos['con_acceso']) ?></strong><span>Con acceso</span>
            </button>
            <?php foreach ($porRol as $nombreRol => $info): ?>
                <button type="button" class="usr-sum <?= usr_e($info['clase']) ?>" data-filtro-rol="<?= usr_e($nombreRol) ?>">
                    <strong><?= (int) $info['total'] ?></strong><span><?= usr_e($nombreRol) ?></span>
                </button>
            <?php endforeach; ?>
        </section>

        <?php if ($rolesAsignables && $datos['sin_acceso']): ?>
            <form method="post" class="usr-add">
                <input type="hidden" name="csrf" value="<?= usr_e($_SESSION['roles_csrf']) ?>">
                <input type="hidden" name="accion_usuario" value="agregar">
                <span class="material-symbols-outlined usr-add-icon" aria-hidden="true">group_add</span>
                <label><span>Dar acceso a una persona ya registrada en el sistema</span>
                    <select name="usuario_id" required>
                        <option value="">Seleccionar persona…</option>
                        <?php foreach ($datos['sin_acceso'] as $u): if ($u['id'] === $miId) continue; ?>
                            <option value="<?= (int) $u['id'] ?>"><?= usr_e($u['nombre'] . ' — ' . $u['correo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>Rol en el laboratorio</span>
                    <select name="rol_id" required>
                        <?php foreach ($rolesAsignables as $idRol => $nombreRol): ?><option value="<?= (int) $idRol ?>"<?= $nombreRol === 'Analista' ? ' selected' : '' ?>><?= usr_e($nombreRol) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="usr-btn usr-btn-primary">Dar acceso</button>
            </form>
        <?php endif; ?>

        <div class="usr-tools">
            <label class="usr-search"><span class="material-symbols-outlined" aria-hidden="true">search</span>
                <span class="sr-only">Buscar persona</span>
                <input type="search" id="usrBuscar" placeholder="Buscar por nombre, correo o rol" autocomplete="off"></label>
            <span class="usr-count" id="usrConteo" aria-live="polite"></span>
        </div>

        <div class="usr-grid" id="usrGrid" role="list" aria-label="Personas con acceso al laboratorio">
            <div class="usr-list-head" aria-hidden="true"><span>Persona</span><span>Rol</span><span>Asignar rol</span></div>
            <?php foreach ($datos['con_acceso'] as $u):
                $esYo = $u['id'] === $miId;
                $esSuperU = $u['es_superadmin'] || mb_strtolower((string) $u['rol'], 'UTF-8') === 'superadmin';
                $bloqueado = $esYo || ($esSuperU && !$esSuperadmin);
                $rolVisible = $u['es_superadmin'] ? 'Superadmin' : ($u['rol'] ?: 'Sin rol');
                $clase = usr_clase_rol($rolVisible, $u['es_superadmin']);
                $esRolLab = isset($rolesAsignables[$u['rol_id']]) || $u['es_superadmin'] || in_array($rolVisible, ['Superadmin', 'Jefe de laboratorio', 'Técnico', 'Analista', 'Recepcionista'], true); ?>
                <article role="listitem" class="usr-card <?= usr_e($clase) ?><?= $creado === $u['id'] ? ' is-new' : '' ?>" id="usuario-<?= (int) $u['id'] ?>"
                         data-rol="<?= usr_e($rolVisible) ?>" data-buscar="<?= usr_e(mb_strtolower($u['nombre'] . ' ' . $u['correo'] . ' ' . $rolVisible, 'UTF-8')) ?>">
                    <div class="usr-card-top">
                        <span class="usr-avatar" aria-hidden="true"><?= usr_e(usr_iniciales($u['nombre'])) ?></span>
                        <div class="usr-card-id">
                            <h3><?= usr_e($u['nombre']) ?><?= $esYo ? ' <small>(usted)</small>' : '' ?></h3>
                            <a href="mailto:<?= usr_e($u['correo']) ?>"><?= usr_e($u['correo']) ?></a>
                        </div>
                        <?php if ($creado === $u['id']): ?><span class="usr-new">Nuevo</span><?php endif; ?>
                    </div>
                    <div class="usr-role">
                        <span class="usr-role-chip"><?= usr_e($rolVisible) ?></span>
                        <?php if (!$esRolLab): ?><small class="usr-role-warn">Rol de otro módulo: asígnele un rol del laboratorio.</small><?php endif; ?>
                    </div>
                    <div class="usr-card-actions">
                        <?php if ($bloqueado): ?>
                            <small class="usr-lock"><span class="material-symbols-outlined" aria-hidden="true">lock</span><?= $esYo ? 'Su propio usuario: otro administrador debe cambiarlo.' : 'Solo un superadmin puede modificarlo.' ?></small>
                        <?php elseif ($rolesAsignables): ?>
                            <form method="post" class="usr-inline">
                                <input type="hidden" name="csrf" value="<?= usr_e($_SESSION['roles_csrf']) ?>">
                                <input type="hidden" name="accion_usuario" value="rol">
                                <input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
                                <select name="rol_id" aria-label="Rol de <?= usr_e($u['nombre']) ?>">
                                    <?php if (!isset($rolesAsignables[$u['rol_id']])): ?><option value="" selected disabled><?= usr_e($rolVisible) ?> (actual)</option><?php endif; ?>
                                    <?php foreach ($rolesAsignables as $idRol => $nombreRol): ?><option value="<?= (int) $idRol ?>"<?= (int) $idRol === $u['rol_id'] ? ' selected' : '' ?>><?= usr_e($nombreRol) ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="usr-btn usr-btn-light usr-btn-sm">Asignar rol</button>
                            </form>
                            <?php if (!$u['es_superadmin']): ?>
                                <form method="post" class="usr-remove-form" data-confirm="¿Quitar el acceso al laboratorio a <?= usr_e($u['nombre']) ?>? La persona no se elimina del sistema.">
                                    <input type="hidden" name="csrf" value="<?= usr_e($_SESSION['roles_csrf']) ?>">
                                    <input type="hidden" name="accion_usuario" value="quitar">
                                    <input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="usr-remove">Quitar acceso</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="usr-empty" id="usrVacio"<?= $datos['con_acceso'] ? ' hidden' : '' ?>>No hay personas que coincidan.</p>
    <?php endif; ?>
</section>
<script>
(function () {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.usr-card'));
    var buscar = document.getElementById('usrBuscar');
    var conteo = document.getElementById('usrConteo');
    var vacio = document.getElementById('usrVacio');
    var chips = Array.prototype.slice.call(document.querySelectorAll('[data-filtro-rol]'));
    var rol = '';
    function aplicar() {
        var q = buscar ? buscar.value.trim().toLowerCase() : '';
        var n = 0;
        cards.forEach(function (c) {
            var ok = (!rol || c.dataset.rol === rol) && (!q || c.dataset.buscar.indexOf(q) !== -1);
            c.hidden = !ok;
            if (ok) n++;
        });
        if (conteo) conteo.textContent = n + ' de ' + cards.length + ' persona(s)';
        if (vacio) vacio.hidden = n > 0;
    }
    if (buscar) buscar.addEventListener('input', aplicar);
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            rol = chip.dataset.filtroRol || '';
            chips.forEach(function (x) { x.classList.toggle('is-on', x === chip); x.setAttribute('aria-pressed', x === chip ? 'true' : 'false'); });
            aplicar();
        });
    });
    document.querySelectorAll('.usr-remove-form').forEach(function (f) {
        f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
    });
    var nuevo = document.querySelector('.usr-card.is-new');
    if (nuevo) nuevo.scrollIntoView({ block: 'center' });
    aplicar();
})();
</script>
<?php lab_shell_close(); ?>
