<?php
declare(strict_types=1);
require_once __DIR__ . '/config/session.php';
cengi_session_start();
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/visitas_access.php';
require_once __DIR__ . '/config/cengisalas_supabase.php';
require_once dirname(__DIR__) . '/visitas/config/presentation.php';
require_once dirname(__DIR__) . '/visitas/config/user_management.php';
header('Cache-Control: no-store, private');
if (empty($_SESSION['id_usuario'])) { header('Location: login.php'); exit; }
$db = Conexion::conectar();
$actorId = (int) $_SESSION['id_usuario'];
$actor = visitas_actor($db, $actorId);
if (!$actor || !in_array('gestionar_roles_visitas', visitas_permissions($actor['role_code']), true)) {
    http_response_code(403);
    exit('Tu rol no permite administrar usuarios y roles de Visitas.');
}
$_SESSION['visitas_roles_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$creating = ($_POST['action'] ?? '') === 'create';
$roles = cengisalas_roles()['visitas'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['visitas_roles_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new DomainException('La sesión de edición expiró. Actualiza la página.', 403);
        }
        if ($creating) {
            visitas_create_user($db, $_POST);
            header('Location: visitas_roles.php?created=1', true, 303);
            exit;
        }
        if (($_POST['action'] ?? 'assign') !== 'assign') throw new InvalidArgumentException('Acción no válida.');
        $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = (string) ($_POST['role'] ?? '');
        if (!$targetId || ($role !== '' && !isset($roles[$role])) || $targetId === $actorId) {
            throw new InvalidArgumentException('Selecciona un usuario y un rol válido. No puedes cambiar tu propio acceso.');
        }
        $db->beginTransaction();
        $query = $db->prepare('SELECT u.id,u.es_superadmin,r.nombre_rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=? FOR UPDATE');
        $query->execute([$targetId]);
        $target = $query->fetch(PDO::FETCH_ASSOC);
        if (!$target || (int) $target['es_superadmin'] === 1) throw new InvalidArgumentException('No se puede modificar el acceso de un superadministrador.');
        $moduleId = (int) $db->query("SELECT id FROM modulos WHERE LOWER(nombre)='visitas' LIMIT 1")->fetchColumn();
        if (!$moduleId) throw new RuntimeException('Falta registrar el módulo Visitas.');
        if ($role === '') {
            $db->prepare("DELETE FROM cengisalas_module_access WHERE usuario_id=? AND module_code='visitas'")->execute([$targetId]);
            $db->prepare('DELETE FROM usuario_modulo WHERE usuario_id=? AND modulo_id=?')->execute([$targetId, $moduleId]);
        } else {
            $db->prepare("INSERT INTO cengisalas_module_access(usuario_id,module_code,role_code) VALUES (?,'visitas',?) ON DUPLICATE KEY UPDATE role_code=VALUES(role_code)")->execute([$targetId, $role]);
            $db->prepare('INSERT INTO usuario_modulo(usuario_id,modulo_id) VALUES (?,?) ON DUPLICATE KEY UPDATE modulo_id=VALUES(modulo_id)')->execute([$targetId, $moduleId]);
            // Solo los roles base creados desde Visitas se actualizan en el portal.
            if (in_array($target['nombre_rol'], array_map(fn($label) => 'Visitas - ' . $label, $roles), true)) {
                $db->prepare('UPDATE usuarios SET rol_id=? WHERE id=?')->execute([visitas_portal_role_id($db, $role), $targetId]);
            }
        }
        $db->commit();
        header('Location: visitas_roles.php?saved=1', true, 303);
        exit;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        $expected = $exception instanceof InvalidArgumentException || $exception instanceof DomainException;
        http_response_code($expected ? (in_array($exception->getCode(), [403,409], true) ? $exception->getCode() : 422) : 503);
        if (!$expected) error_log('Usuarios Visitas: ' . $exception->getMessage());
        $error = $expected ? $exception->getMessage() : 'No se pudo guardar el cambio. Inténtalo de nuevo.';
    }
}
$organizations = $departments = [];
$catalogError = '';
try {
    $pg = cengisalas_pg();
    $organizations = $pg->query('SELECT id,name FROM public.organizations WHERE is_active ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pg->query('SELECT id,organization_id,name FROM public.departments WHERE is_active ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) { $catalogError = 'No fue posible cargar las instituciones. Actualiza la página para crear un usuario.'; }
$users = $db->query("SELECT u.id,u.nombre,u.correo,u.es_superadmin,a.role_code FROM usuarios u
    LEFT JOIN cengisalas_module_access a ON a.usuario_id=u.id AND a.module_code='visitas' ORDER BY u.nombre,u.id")->fetchAll(PDO::FETCH_ASSOC);
function visitas_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Usuarios, roles y permisos de Visitas | CENGICAÑA</title>
<link rel="stylesheet" href="../visitas/assets/roles.css?v=20261008-roles">
<?php visitas_ui_head(); ?></head>
<body class="visits-admin">
<?php visitas_ui_shell('Roles y permisos', 'Usuarios y acceso al módulo de Visitas', 'roles'); ?>
<main class="roles-shell" id="visits-content" tabindex="-1">
<header><p class="eyebrow">CENGICAÑA · VISITAS</p><h1>Usuarios, roles y permisos</h1><p>Crea usuarios del portal de Módulos y administra su acceso a Visitas.</p></header>
<section class="role-guide visits-role-guide" aria-label="Permisos de cada rol">
<?php foreach ($roles as $code => $label): ?><article><h2><?= visitas_h($label) ?></h2><p><?= $code === 'gestor_pagos' ? 'Acceso únicamente a Reportes y Pagos.' : 'Acceso completo a Visitas, incluidos usuarios y roles.' ?></p></article><?php endforeach; ?>
</section>
<?php if ($error !== ''): ?><p class="notice error" role="alert"><?= visitas_h($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="notice success" role="status">Acceso actualizado. El cambio se aplica en la siguiente petición del usuario.</p><?php endif; ?>
<?php if (isset($_GET['created'])): ?><p class="notice success" role="status">Usuario creado en Módulos con acceso a Visitas. Ya puede iniciar sesión con su correo y contraseña.</p><?php endif; ?>
<details class="users-panel visits-create-panel" <?= $creating && $error !== '' ? 'open' : '' ?>>
<summary>Crear usuario <span>Registrar una nueva cuenta en Módulos</span></summary>
<?php if ($catalogError !== ''): ?><p class="notice error"><?= visitas_h($catalogError) ?></p><?php else: ?>
<form method="post" class="visits-create-form" id="visitsCreateUser">
<input type="hidden" name="action" value="create"><input type="hidden" name="csrf" value="<?= visitas_h($_SESSION['visitas_roles_csrf']) ?>">
<label>Nombre completo<input name="name" required minlength="3" maxlength="120" autocomplete="name" value="<?= visitas_h($creating ? ($_POST['name'] ?? '') : '') ?>"></label>
<label>Correo electrónico<input type="email" name="email" required maxlength="120" autocomplete="email" value="<?= visitas_h($creating ? ($_POST['email'] ?? '') : '') ?>"></label>
<label>Contraseña<input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"><small>Entre 8 y 72 caracteres.</small></label>
<label>Confirmar contraseña<input type="password" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password"></label>
<label>Institución<select name="organization_id" id="visitsOrganization" required><option value="">Selecciona una institución</option><?php foreach ($organizations as $org): ?><option value="<?= (int) $org['id'] ?>" <?= $creating && (string) ($_POST['organization_id'] ?? '') === (string) $org['id'] ? 'selected' : '' ?>><?= visitas_h($org['name']) ?></option><?php endforeach; ?></select></label>
<label>Departamento (opcional)<select name="department_id" id="visitsDepartment"><option value="">Sin departamento</option><?php foreach ($departments as $dept): ?><option value="<?= (int) $dept['id'] ?>" data-organization="<?= (int) $dept['organization_id'] ?>" <?= $creating && (string) ($_POST['department_id'] ?? '') === (string) $dept['id'] ? 'selected' : '' ?>><?= visitas_h($dept['name']) ?></option><?php endforeach; ?></select></label>
<label>Rol de Visitas<select name="role" required><?php foreach ($roles as $code => $label): ?><option value="<?= visitas_h($code) ?>" <?= $creating && ($_POST['role'] ?? '') === $code ? 'selected' : '' ?>><?= visitas_h($label) ?></option><?php endforeach; ?></select></label>
<div class="visits-create-actions"><button type="submit">Crear usuario</button></div>
</form><?php endif; ?></details>
<section class="users-panel"><div class="toolbar"><h2>Usuarios del portal</h2><label>Buscar usuario<input id="userSearch" type="search" placeholder="Nombre o correo" autocomplete="off"></label></div>
<div class="table-scroll"><table><thead><tr><th>Usuario</th><th>Acceso a Visitas</th><th>Administrar acceso</th></tr></thead><tbody>
<?php foreach ($users as $user):
    $superadmin = (int) $user['es_superadmin'] === 1;
    $locked = $superadmin || (int) $user['id'] === $actorId;
    $role = visitas_role_code((string) ($user['role_code'] ?? ''));
?>
<tr class="user-row"><td><strong><?= visitas_h($user['nombre']) ?></strong><span class="email"><?= visitas_h($user['correo']) ?></span></td>
<td><span class="badge"><?= visitas_h($superadmin ? 'Superadministrador' : ($roles[$role] ?? 'Sin acceso')) ?></span></td>
<td><?php if ($locked): ?><span class="muted"><?= $superadmin ? 'Acceso global del portal' : 'Tu cuenta actual' ?></span>
<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= visitas_h($_SESSION['visitas_roles_csrf']) ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
<select name="role" aria-label="Rol de <?= visitas_h($user['nombre']) ?>"><option value="" <?= $role === '' ? 'selected' : '' ?>>Sin acceso</option>
<?php foreach ($roles as $code => $label): ?><option value="<?= visitas_h($code) ?>" <?= $role === $code ? 'selected' : '' ?>><?= visitas_h($label) ?></option><?php endforeach; ?>
</select><button type="submit">Guardar</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><p id="emptySearch" hidden>No se encontraron usuarios.</p></section>
<p class="muted">Los usuarios creados aquí aparecen en Módulos. Sus permisos de Visitas son independientes de los de otros módulos.</p>
</main><script>
document.getElementById('userSearch').addEventListener('input', function () {
    const query = this.value.trim().toLocaleLowerCase('es'); let matches = 0;
    document.querySelectorAll('.user-row').forEach(row => { row.hidden = !row.cells[0].textContent.toLocaleLowerCase('es').includes(query); if (!row.hidden) matches++; });
    document.getElementById('emptySearch').hidden = matches > 0;
});
const organization = document.getElementById('visitsOrganization'), department = document.getElementById('visitsDepartment');
function filterDepartments() {
    if (!department) return;
    [...department.options].forEach(option => { if (!option.value) return; option.hidden = option.dataset.organization !== organization.value; option.disabled = option.hidden; });
    if (department.selectedOptions[0]?.disabled) department.value = '';
}
organization?.addEventListener('change', filterDepartments); filterDepartments();
document.getElementById('visitsCreateUser')?.addEventListener('submit', event => {
    const password = event.currentTarget.elements.password, confirmation = event.currentTarget.elements.password_confirmation;
    confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Las contraseñas deben coincidir.');
    if (!event.currentTarget.reportValidity()) event.preventDefault();
});
document.querySelectorAll('[name="password"], [name="password_confirmation"]').forEach(input => input.addEventListener('input', () => document.querySelector('[name="password_confirmation"]').setCustomValidity('')));
</script></body></html>
