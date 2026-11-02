<?php
/**
 * Modulos en los que se puede asignar un rol (tabla rol_modulo, ver login/config/roles_modulo.php).
 * Sin modulos marcados el rol es "general" y sirve en todos (Superadmin, Administrador).
 */
require_once __DIR__ . '/../config/roles_modulo.php';

function rol_mod_listar_modulos(PDO $conn)
{
    return $conn->query('SELECT id, nombre FROM modulos ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
}

/** Modulos elegidos en el POST, solo ids validos. */
function rol_mod_leer_post(array $post, array $modulos)
{
    $validos = array_map(static fn($m) => (int) $m['id'], $modulos);
    return array_values(array_intersect($validos, array_map('intval', (array) ($post['rol_modulos'] ?? []))));
}

/**
 * Modulos que no se pueden quitar porque hay usuarios con este rol en ellos.
 * Devuelve [modulo_id => total_usuarios]. Si el rol queda general no hay conflicto.
 */
function rol_mod_conflictos(PDO $conn, $rolId, array $nuevos)
{
    if (!$nuevos) {
        return [];
    }
    $stmt = $conn->prepare('SELECT modulo_id, COUNT(*) FROM usuario_modulo WHERE rol_id = ? GROUP BY modulo_id');
    $stmt->execute([(int) $rolId]);
    $usados = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    return array_diff_key($usados, array_flip($nuevos));
}

function rol_mod_guardar(PDO $conn, $rolId, array $moduloIds)
{
    $conn->prepare('DELETE FROM rol_modulo WHERE rol_id = ?')->execute([(int) $rolId]);
    $ins = $conn->prepare('INSERT INTO rol_modulo (rol_id, modulo_id) VALUES (?, ?)');
    foreach (array_unique($moduloIds) as $moduloId) {
        $ins->execute([(int) $rolId, (int) $moduloId]);
    }
}

function rol_mod_render(array $modulos, array $marcados)
{
    ?>
    <div class="form-field">
        <label>Módulos donde se usa este rol</label>
        <div class="module-picker">
            <?php foreach ($modulos as $m): $on = in_array((int) $m['id'], $marcados, true); ?>
                <label class="module-chip<?= $on ? ' is-checked' : '' ?>">
                    <input type="checkbox" name="rol_modulos[]" value="<?= (int) $m['id'] ?>" <?= $on ? 'checked' : '' ?>>
                    <span class="material-symbols-outlined">widgets</span>
                    <?= htmlspecialchars($m['nombre']) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="form-help">El rol solo se ofrecerá al asignar usuarios en los módulos marcados (por ejemplo, "Gestor" solo en Cursos). Si no marcas ninguno, el rol es <strong>general</strong> y se puede usar en todos.</p>
    </div>
    <script>
    document.querySelectorAll('input[name="rol_modulos[]"]').forEach(function (cb) {
        cb.addEventListener('change', function () { cb.closest('.module-chip').classList.toggle('is-checked', cb.checked); });
    });
    </script>
    <?php
}
