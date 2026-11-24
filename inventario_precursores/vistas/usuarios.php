<?php
declare(strict_types=1);
$usuarios = inv_usuarios_modulo($menuPdo);
$rolesInventario = $user['es_superadmin'] === 1 ? inv_roles_asignables_modulo($menuPdo) : [];
$labs = inv_laboratorios($pdo, true);
$asignados = [];
foreach ($pdo->query('SELECT ul.usuario_id, l.nombre FROM usuario_laboratorio ul JOIN laboratorios l ON l.id = ul.laboratorio_id')->fetchAll() as $f) {
    $asignados[(int) $f['usuario_id']] = $f['nombre'];
}
$conFirma = array_map('intval', $pdo->query('SELECT usuario_id FROM usuario_firma')->fetchAll(PDO::FETCH_COLUMN));
$idsLab = [];
foreach ($pdo->query('SELECT usuario_id, laboratorio_id FROM usuario_laboratorio')->fetchAll() as $f) {
    $idsLab[(int) $f['usuario_id']] = (int) $f['laboratorio_id'];
}
?>
<div class="row"><div class="grow"><h1 class="pt">Usuarios del módulo</h1><p class="sub">Consulte los usuarios de Inventario y su laboratorio. El superadministrador puede cambiar aquí el rol de cada usuario en este módulo.</p></div>
  <?php if ($user['es_superadmin'] === 1): ?><a class="btn" href="../login/roles/roles.php">🔑 Roles y permisos</a><?php endif; ?></div>
<div class="card"><div class="tw"><table><thead><tr><th>Usuario</th><th>Correo</th><th>Rol en el módulo</th><th>Laboratorio</th><th>Firma guardada</th></tr></thead><tbody>
<?php foreach ($usuarios as $u): ?>
  <tr><td><div class="row" style="gap:8px;flex-wrap:nowrap"><div class="avatar" style="width:30px;height:30px;font-size:11px"><?= e(inv_iniciales($u['nombre'])) ?></div><b><?= e($u['nombre']) ?></b><?= $u['id'] === $user['id'] ? '<span class="pill p-info">Sesión</span>' : '' ?></div></td>
    <td><?= e($u['correo']) ?></td>
    <td>
      <?php if ($rolesInventario && $u['es_superadmin'] !== 1): ?>
        <form method="post" action="<?= e(inv_url(['view' => 'usuarios'])) ?>" class="row" style="gap:6px">
          <?= inv_form_campos('usuario_rol_guardar') ?><input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
          <select class="in" name="rol_id" required aria-label="Rol de Inventario de <?= e($u['nombre']) ?>">
            <?php if (!isset($rolesInventario[(int) $u['rol_id']])): ?><option value="" selected disabled><?= e($u['nombre_rol'] ?: 'Seleccionar rol de Inventario') ?></option><?php endif; ?>
            <?php foreach ($rolesInventario as $id => $rol): ?>
              <option value="<?= (int) $id ?>" <?= (int) $u['rol_id'] === (int) $id ? 'selected' : '' ?>><?= e($rol['nombre_rol']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn sm" type="submit">Guardar rol</button>
        </form>
      <?php else: ?>
        <span class="pill <?= $u['es_superadmin'] === 1 ? 'p-bad' : 'p-ok' ?>"><?= e($u['es_superadmin'] === 1 ? 'Superadmin' : ($u['nombre_rol'] ?: 'Sin rol asignado')) ?></span>
      <?php endif; ?>
    </td>
    <td><form method="post" action="<?= e(inv_url(['view' => 'usuarios'])) ?>" class="row" style="gap:6px" data-autosubmit-post>
      <?= inv_form_campos('usuario_lab_guardar') ?><input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
      <select class="in" name="laboratorio_id" aria-label="Laboratorio de <?= e($u['nombre']) ?>"><option value="0">— Sin laboratorio (Bodega) —</option>
        <?php foreach ($labs as $l): ?><option value="<?= (int) $l['id'] ?>" <?= ($idsLab[$u['id']] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option><?php endforeach; ?></select>
      <noscript><button class="btn sm">Guardar</button></noscript></form></td>
    <td><?= in_array($u['id'], $conFirma, true) ? 'Sí' : '<span class="hint">—</span>' ?></td></tr>
<?php endforeach; ?>
<?php if (!$usuarios): ?><tr><td colspan="5" class="empty">Ningún usuario tiene acceso al módulo todavía</td></tr><?php endif; ?>
</tbody></table></div></div>
