<?php
/**
 * Lista de permisos del formulario de Crear rol / Editar rol.
 * Espera: $gruposPermisos (agrupar_permisos()) y $permisosRol (permisos ya asignados; [] al crear).
 *
 * Cengilabs se muestra por etapa del flujo actual (permisos_laboratorio_catalogo()), con
 * plantillas de los roles del laboratorio; los permisos obsoletos no se ofrecen y, si el rol ya
 * los tenía, se conservan sin mostrarse.
 */
// Solo se usa incluido desde crear_rol.php / editar_rol.php (protegidos por _guard.php).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
$permisosRol = $permisosRol ?? [];
$plantillasLab = [];
$rolesLab = __DIR__ . '/../../laboratorio/config/roles_laboratorio.php';
if (is_file($rolesLab)) {
    require_once $rolesLab;
    foreach (lab_roles_definicion(array_keys(permisos_laboratorio_vigentes())) as $nombreRol => $def) {
        $plantillasLab[$nombreRol] = array_values($def['permisos']);
    }
}
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<?php foreach ($gruposPermisos as $grupo => $items): ?>
    <?php if ($grupo === 'Permisos de laboratorio'):
        $porNombre = array_column($items, null, 'nombre_permiso');
        $vigentes = permisos_laboratorio_vigentes(); ?>
        <section class="permiso-section" id="permisos-cengilabs">
            <div class="permiso-section-head">
                <div>
                    <span class="permiso-kicker"><?= $e(titulo_corto_grupo_permiso($grupo)) ?></span>
                    <h4><?= $e($grupo) ?></h4>
                </div>
                <span class="permiso-count"><?= count($vigentes) ?> permisos</span>
            </div>
            <p class="permiso-section-copy"><?= $e(descripcion_grupo_permiso($grupo)) ?></p>
            <?php if ($plantillasLab): ?>
                <div class="permiso-plantilla">
                    <label for="plantilla-cengilabs">Plantilla de rol Cengilabs</label>
                    <select id="plantilla-cengilabs">
                        <option value="">Elegir plantilla…</option>
                        <?php foreach ($plantillasLab as $nombreRol => $lista): ?>
                            <option value="<?= $e(json_encode($lista)) ?>"><?= $e($nombreRol) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Marca solo los permisos de Cengilabs del rol elegido; los de otros módulos no cambian.</small>
                </div>
            <?php endif; ?>
            <?php foreach (permisos_laboratorio_catalogo() as $clave => $seccion): ?>
                <div class="permiso-etapa" data-etapa="<?= $e($clave) ?>">
                    <div class="permiso-etapa-head">
                        <h5><?= $e($seccion['titulo']) ?></h5>
                        <button type="button" class="permiso-etapa-toggle" data-etapa-toggle>Marcar etapa</button>
                    </div>
                    <div class="permisos-grid">
                        <?php foreach ($seccion['permisos'] as $nombre => [$etiqueta, $descripcion]): if (!isset($porNombre[$nombre])) continue; ?>
                            <label class="permiso-item">
                                <input type="checkbox" name="permisos[]" value="<?= $e($nombre) ?>" data-cengilabs
                                    <?= in_array($nombre, $permisosRol, true) ? 'checked' : '' ?>>
                                <span>
                                    <strong><?= $e($etiqueta) ?></strong>
                                    <small><?= $e($descripcion) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php foreach (array_intersect(permisos_laboratorio_obsoletos(), $permisosRol) as $obsoleto): ?>
                <input type="hidden" name="permisos[]" value="<?= $e($obsoleto) ?>">
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <section class="permiso-section">
            <div class="permiso-section-head">
                <div>
                    <span class="permiso-kicker"><?= $e(titulo_corto_grupo_permiso($grupo)) ?></span>
                    <h4><?= $e($grupo) ?></h4>
                </div>
                <span class="permiso-count"><?= count($items) ?> permisos</span>
            </div>
            <p class="permiso-section-copy"><?= $e(descripcion_grupo_permiso($grupo)) ?></p>
            <div class="permisos-grid">
                <?php foreach ($items as $permiso): ?>
                    <label class="permiso-item">
                        <input type="checkbox" name="permisos[]" value="<?= $e($permiso['nombre_permiso']) ?>"
                            <?= in_array($permiso['nombre_permiso'], $permisosRol, true) ? 'checked' : '' ?>>
                        <span>
                            <strong><?= $e(etiqueta_permiso($permiso['nombre_permiso'])) ?></strong>
                            <small><?= $e(descripcion_permiso($permiso['nombre_permiso'], $permiso['descripcion'])) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endforeach; ?>
<style>
.permiso-plantilla { display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin:0 0 14px; padding:10px 12px; border-radius:10px; background:#f5f9f2; font-size:13px; }
.permiso-plantilla label { font-weight:600; }
.permiso-plantilla select { padding:6px 10px; border:1px solid #d5e2d3; border-radius:8px; font:inherit; background:#fff; }
.permiso-plantilla small { flex-basis:100%; color:#66725e; }
.permiso-etapa { margin-top:14px; }
.permiso-etapa-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; }
.permiso-etapa-head h5 { margin:0; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#4b5a4e; }
.permiso-etapa-toggle { border:0; background:none; color:#3f7a12; font:inherit; font-size:12px; cursor:pointer; }
</style>
<script>
(function () {
  var sel = document.getElementById('plantilla-cengilabs');
  var cajas = function (raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll('input[data-cengilabs]')); };
  if (sel) sel.addEventListener('change', function () {
    if (!sel.value) return;
    var lista = JSON.parse(sel.value);
    cajas().forEach(function (c) { c.checked = lista.indexOf(c.value) !== -1; });
  });
  document.querySelectorAll('[data-etapa-toggle]').forEach(function (btn) {
    var etapa = btn.closest('.permiso-etapa');
    var pintar = function () { btn.textContent = cajas(etapa).every(function (c) { return c.checked; }) ? 'Quitar etapa' : 'Marcar etapa'; };
    btn.addEventListener('click', function () {
      var marcar = !cajas(etapa).every(function (c) { return c.checked; });
      cajas(etapa).forEach(function (c) { c.checked = marcar; });
      pintar();
    });
    etapa.addEventListener('change', pintar);
    document.addEventListener('permisos:cambio', pintar);
    pintar();
  });
  if (sel) sel.addEventListener('change', function () { document.dispatchEvent(new Event('permisos:cambio')); });
})();
</script>
