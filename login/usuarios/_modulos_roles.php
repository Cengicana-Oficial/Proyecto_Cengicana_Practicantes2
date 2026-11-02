<?php
/**
 * Selector "modulo -> rol" de crear_usuario.php y editar_usuario.php (rol por modulo, ver
 * login/config/roles_modulo.php). Cada modulo se activa por separado y se le elige un rol
 * de los que sirven en ese modulo (los propios del modulo y los genericos), para que el rol
 * de un modulo no aparezca ni aplique en otro.
 */
require_once __DIR__ . '/../config/roles_modulo.php';

/** Roles asignables por modulo: [modulo_id => [rol_id => nombre_rol]]. */
function usr_roles_por_modulo(PDO $conn, array $modulos, $incluirSuperadmin)
{
    $out = [];
    foreach ($modulos as $m) {
        $out[(int) $m['id']] = [];
        foreach (cengi_rm_roles_asignables($conn, (int) $m['id'], $incluirSuperadmin) as $r) {
            $out[(int) $m['id']][(int) $r['id']] = $r['nombre_rol'] . ($r['del_modulo'] ? '' : ' (general)');
        }
    }
    return $out;
}

/**
 * Lee del POST los modulos activados y su rol. Valida que el rol sirva en ese modulo.
 * Devuelve [modulo_id => rol_id] o lanza InvalidArgumentException.
 */
function usr_leer_modulos_roles(array $post, array $modulosPermitidos, array $rolesPorModulo, array $nombres)
{
    $seleccion = [];
    foreach ((array) ($post['modulos'] ?? []) as $moduloId => $datos) {
        $moduloId = (int) $moduloId;
        if (empty($datos['acceso']) || !in_array($moduloId, $modulosPermitidos, true)) {
            continue;
        }
        $rolId = (int) ($datos['rol_id'] ?? 0);
        if (!isset($rolesPorModulo[$moduloId][$rolId])) {
            throw new InvalidArgumentException('Elige el rol del usuario en ' . ($nombres[$moduloId] ?? 'el módulo') . '.');
        }
        $seleccion[$moduloId] = $rolId;
    }
    return $seleccion;
}

/** Guarda los accesos: agrega/actualiza los elegidos y quita los permitidos que no se eligieron. */
function usr_guardar_modulos_roles(PDO $conn, $usuarioId, array $seleccion, array $modulosPermitidos)
{
    foreach ($seleccion as $moduloId => $rolId) {
        cengi_rm_asignar($conn, $usuarioId, $moduloId, $rolId);
    }
    $quitar = array_diff($modulosPermitidos, array_keys($seleccion));
    if ($quitar) {
        $conn->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id IN (' . implode(',', array_fill(0, count($quitar), '?')) . ')')
            ->execute(array_merge([(int) $usuarioId], array_values($quitar)));
    }
}

/**
 * Pinta la tabla de modulos. $actuales: [modulo_id => rol_id|null] del usuario (o del POST).
 * $fijos: modulos que no se pueden desmarcar (alta desde la pantalla de un modulo).
 */
function usr_render_modulos_roles(array $modulos, array $rolesPorModulo, array $actuales, array $fijos = [])
{
    ?>
    <div class="form-field" id="moduloContainer">
        <label>Módulos y rol en cada uno</label>
        <table class="module-role-table">
            <thead><tr><th>Acceso</th><th>Módulo</th><th>Rol en el módulo</th></tr></thead>
            <tbody>
            <?php foreach ($modulos as $m):
                $id = (int) $m['id'];
                $activo = array_key_exists($id, $actuales) || in_array($id, $fijos, true);
                $fijo = in_array($id, $fijos, true);
                $rolActual = $actuales[$id] ?? null;
            ?>
                <tr class="<?= $activo ? 'is-active' : '' ?>">
                    <td>
                        <?php if ($fijo): ?><input type="hidden" name="modulos[<?= $id ?>][acceso]" value="1"><span class="material-symbols-outlined" title="Acceso de este módulo">check_circle</span>
                        <?php else: ?><input type="checkbox" name="modulos[<?= $id ?>][acceso]" value="1" <?= $activo ? 'checked' : '' ?> aria-label="Acceso a <?= htmlspecialchars($m['nombre']) ?>" data-modulo-acceso><?php endif; ?>
                    </td>
                    <td><strong><?= htmlspecialchars($m['nombre']) ?></strong></td>
                    <td>
                        <select name="modulos[<?= $id ?>][rol_id]" <?= $activo ? '' : 'disabled' ?> aria-label="Rol en <?= htmlspecialchars($m['nombre']) ?>">
                            <option value="">Elige el rol…</option>
                            <?php foreach ($rolesPorModulo[$id] ?? [] as $rolId => $nombreRol): ?>
                                <option value="<?= (int) $rolId ?>" <?= (int) $rolId === (int) $rolActual ? 'selected' : '' ?>><?= htmlspecialchars($nombreRol) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($rolActual && !isset($rolesPorModulo[$id][(int) $rolActual])): ?><span class="form-help" style="color:#B23223;">El rol actual no corresponde a este módulo: elige uno.</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="form-help">Cada módulo tiene su propio rol: el rol de un módulo no da permisos en los demás. Solo se ofrecen los roles de cada módulo y los roles generales (marcados “general”).</p>
    </div>
    <style>
        .module-role-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .module-role-table th { padding: 6px 8px; border-bottom: 1px solid #dfe7d8; color: #5b6459; font-size: 12px; text-align: left; }
        .module-role-table td { padding: 8px; border-bottom: 1px solid #eef2eb; vertical-align: middle; }
        .module-role-table tr:not(.is-active) td:nth-child(2) { color: #8a9187; }
        .module-role-table tr.is-active { background: #f6fbef; }
        .module-role-table select { width: 100%; min-width: 180px; }
        .module-role-table .material-symbols-outlined { color: #2f6b12; }
    </style>
    <script>
    document.querySelectorAll('[data-modulo-acceso]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var fila = cb.closest('tr'), sel = fila.querySelector('select');
            fila.classList.toggle('is-active', cb.checked);
            sel.disabled = !cb.checked;
            if (cb.checked && !sel.value) { sel.focus(); }
        });
    });
    </script>
    <?php
}
