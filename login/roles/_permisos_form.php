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
$catalogosMenus = permisos_menus_catalogo();
// Permisos que ningún módulo consulta: no se ofrecen; si el rol ya los tiene se conservan.
$sinUso = permisos_sin_uso();
foreach ($gruposPermisos as $grupoSinUso => $itemsSinUso) {
    $gruposPermisos[$grupoSinUso] = array_values(array_filter($itemsSinUso, static fn($p) => !isset($sinUso[$p['nombre_permiso']])));
}
$gruposPermisos = array_filter($gruposPermisos);
foreach (array_intersect(array_keys($sinUso), $permisosRol) as $permisoSinUso): ?>
    <input type="hidden" name="permisos[]" value="<?= $e($permisoSinUso) ?>">
<?php endforeach; ?>
<?php if ($gruposPermisos): ?>
<!-- Filtro por módulo y buscador: las pestañas se arman en el navegador a partir de data-grupo. -->
<div class="permisos-filtro" id="permisos-filtro">
    <div class="permisos-filtro-tabs" role="tablist" aria-label="Filtrar permisos por módulo" data-filtro-tabs></div>
    <label class="permisos-filtro-buscar">
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
        <input type="search" placeholder="Buscar permiso…" aria-label="Buscar permiso" data-filtro-buscar>
    </label>
</div>
<p class="permisos-filtro-vacio" data-filtro-vacio hidden>No hay permisos que coincidan con la búsqueda en este módulo.</p>
<?php endif; ?>
<?php foreach ($gruposPermisos as $grupo => $items): ?>
    <?php if ($grupo === 'Permisos de laboratorio'):
        $porNombre = array_column($items, null, 'nombre_permiso');
        $vigentes = permisos_laboratorio_vigentes(); ?>
        <section class="permiso-section" id="permisos-cengilabs" data-grupo="<?= $e(titulo_corto_grupo_permiso($grupo)) ?>">
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
    <?php elseif ($grupo === 'Permisos de Comités'):
        // Tabla por sección del menú del módulo (permisos_comites.php): Ver / Gestionar.
        $porNombre = array_column($items, null, 'nombre_permiso');
        $mostrados = [];
        $caja = static function ($nombre, $extra = '') use ($porNombre, $permisosRol, $e, &$mostrados) {
            if (!isset($porNombre[$nombre])) {
                return '<span class="pc-falta" title="Se crea al abrir el módulo Comités después de actualizarlo">—</span>';
            }
            $mostrados[] = $nombre;
            return '<input type="checkbox" name="permisos[]" value="' . $e($nombre) . '" data-comites ' . $extra
                . (in_array($nombre, $permisosRol, true) ? ' checked' : '') . '>';
        }; ?>
        <section class="permiso-section" id="permisos-comites" data-grupo="<?= $e(titulo_corto_grupo_permiso($grupo)) ?>">
            <div class="permiso-section-head">
                <div>
                    <span class="permiso-kicker"><?= $e(titulo_corto_grupo_permiso($grupo)) ?></span>
                    <h4><?= $e($grupo) ?></h4>
                </div>
                <span class="permiso-count" data-comites-count></span>
            </div>
            <p class="permiso-section-copy">Cada fila es una página del menú de Comités. <strong>Ver</strong> muestra la página en el menú; <strong>Gestionar</strong> permite crear, editar y eliminar (incluye Ver). Sin ninguna de las dos, la página no aparece.</p>
            <div class="permiso-plantilla">
                <label for="plantilla-comites">Plantilla de rol Comités</label>
                <select id="plantilla-comites">
                    <option value="">Elegir plantilla…</option>
                    <?php foreach (permisos_comites_plantillas() as $nombreRol => $lista): ?>
                        <option value="<?= $e(json_encode(array_values($lista))) ?>"><?= $e($nombreRol) ?></option>
                    <?php endforeach; ?>
                    <option value="[]">Sin acceso a Comités</option>
                </select>
                <small>Marca solo los permisos de Comités de la plantilla; los de otros módulos no cambian. Después puede ajustar fila por fila.</small>
            </div>
            <table class="pc-matriz">
                <thead>
                    <tr><th>Página del módulo</th><th>Ver</th><th>Gestionar</th></tr>
                </thead>
                <?php foreach (permisos_comites_catalogo() as $grupoMenu => $secciones): ?>
                    <tbody data-pc-grupo>
                        <tr class="pc-grupo">
                            <th><?= $e($grupoMenu) ?></th>
                            <td><button type="button" class="permiso-etapa-toggle" data-pc-col="ver">Todo</button></td>
                            <td><button type="button" class="permiso-etapa-toggle" data-pc-col="gestionar">Todo</button></td>
                        </tr>
                        <?php foreach ($secciones as $clave => [$etiqueta, $descripcion]): ?>
                            <tr data-pc-fila>
                                <td><strong><?= $e($etiqueta) ?></strong><small><?= $e($descripcion) ?></small></td>
                                <td><?= $caja("comites.$clave.ver", 'data-pc="ver"') ?></td>
                                <td><?= $caja("comites.$clave.gestionar", 'data-pc="gestionar"') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
            <div class="permiso-etapa">
                <div class="permiso-etapa-head"><h5>Aprobaciones y acciones especiales</h5></div>
                <div class="permisos-grid">
                    <?php foreach (permisos_comites_especiales() as $nombre => [$etiqueta, $descripcion]): if (!isset($porNombre[$nombre])) continue; ?>
                        <label class="permiso-item">
                            <?= $caja($nombre) ?>
                            <span><strong><?= $e($etiqueta) ?></strong><small><?= $e($descripcion) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php $otros = array_diff(array_keys($porNombre), $mostrados); if ($otros): ?>
                <div class="permiso-etapa">
                    <div class="permiso-etapa-head"><h5>Otros permisos registrados</h5></div>
                    <div class="permisos-grid">
                        <?php foreach ($otros as $nombre): ?>
                            <label class="permiso-item">
                                <?= $caja($nombre) ?>
                                <span><strong><?= $e(etiqueta_permiso($nombre)) ?></strong><small><?= $e(descripcion_permiso($nombre, $porNombre[$nombre]['descripcion'])) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php
        $esInventario = $grupo === 'Permisos de Inventario';
        $esSigec = $grupo === 'Permisos de Experimentos';
        // Subgrupos según el menú de cada módulo (catálogos de Inventario, Experimentos y
        // login/config/permisos_menus.php para Cursos, Visitas, Solicitudes internas y Portal).
        $catalogoGrupo = $esSigec ? permisos_sigec_catalogo()
            : ($esInventario ? permisos_inventario_catalogo() : ($catalogosMenus[$grupo] ?? null));
        $subgrupos = ['' => $items];
        if ($catalogoGrupo) {
            $pendientes = array_column($items, null, 'nombre_permiso');
            $subgrupos = [];
            foreach ($catalogoGrupo as $titulo => $permisosCatalogo) {
                foreach (array_keys($permisosCatalogo) as $nombre) {
                    if (isset($pendientes[$nombre])) {
                        $subgrupos[$titulo][] = $pendientes[$nombre];
                        unset($pendientes[$nombre]);
                    }
                }
            }
            if ($pendientes) {
                $subgrupos['Otros permisos registrados'] = $pendientes;
            }
        }
        $conConteo = (bool) $catalogoGrupo;
        $asignados = $conConteo ? count(array_intersect(array_column($items, 'nombre_permiso'), $permisosRol)) : 0;
        ?>
        <section class="permiso-section" data-grupo="<?= $e(titulo_corto_grupo_permiso($grupo)) ?>"<?= $esInventario ? ' id="permisos-inventario"' : ($esSigec ? ' id="permisos-experimentos"' : ($grupo === 'Permisos de solicitudes internas' ? ' id="permisos-solicitudes-internas"' : '')) ?>>
            <div class="permiso-section-head">
                <div>
                    <span class="permiso-kicker"><?= $e(titulo_corto_grupo_permiso($grupo)) ?></span>
                    <h4><?= $e($grupo) ?></h4>
                </div>
                <span class="permiso-count"><?= $conConteo ? $asignados . ' de ' . count($items) . ' asignados' : count($items) . ' permisos' ?></span>
            </div>
            <p class="permiso-section-copy"><?= $e(descripcion_grupo_permiso($grupo)) ?></p>
            <?php foreach ($subgrupos as $titulo => $permisosDelGrupo): ?>
            <?php if ($titulo !== ''): ?><div class="permiso-etapa"><div class="permiso-etapa-head"><h5><?= $e($titulo) ?></h5></div><?php endif; ?>
            <div class="permisos-grid">
                <?php foreach ($permisosDelGrupo as $permiso): ?>
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
            <?php if ($titulo !== ''): ?></div><?php endif; ?>
            <?php endforeach; ?>
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
.pc-matriz { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:6px; }
.pc-matriz th, .pc-matriz td { padding:8px 10px; border-bottom:1px solid #e6eee3; text-align:left; vertical-align:middle; }
.pc-matriz thead th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#66725e; }
.pc-matriz thead th:not(:first-child), .pc-matriz td:not(:first-child) { width:96px; text-align:center; }
.pc-matriz tr.pc-grupo th { padding-top:14px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#4b5a4e; }
.pc-matriz tr.pc-grupo td { padding-top:14px; }
.pc-matriz tr[data-pc-fila]:hover { background:#f5f9f2; }
.pc-matriz td strong { display:block; font-weight:600; color:#1f2d1f; }
.pc-matriz td small { display:block; color:#66725e; font-size:12px; }
.pc-matriz input[type=checkbox] { width:18px; height:18px; accent-color:#3f7a12; cursor:pointer; }
.pc-falta { color:#a3ad9c; cursor:help; }
/* Filtro por módulo: queda pegado bajo el encabezado al desplazarse. */
.permisos-filtro { position:sticky; top:var(--permisos-filtro-top, 76px); z-index:20; display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin:14px 0 0; padding:10px 12px; border:1px solid #d5e2d3; border-radius:14px; background:rgba(255,255,255,.96); backdrop-filter:blur(8px); box-shadow:0 8px 18px rgba(7,60,24,.07); }
.permisos-filtro-tabs { display:flex; flex-wrap:wrap; gap:6px; flex:1 1 auto; }
.permisos-filtro-tab { display:inline-flex; align-items:center; gap:6px; padding:7px 12px; border:1px solid #d5e2d3; border-radius:999px; background:#fff; color:#1f2d1f; font:inherit; font-size:12.5px; font-weight:600; cursor:pointer; }
.permisos-filtro-tab:hover { background:#f5f9f2; border-color:#aed58d; }
.permisos-filtro-tab[aria-selected="true"] { background:#3f7a12; border-color:#3f7a12; color:#fff; }
.permisos-filtro-tab .n { padding:1px 7px; border-radius:999px; background:#eef5e8; color:#3f7a12; font-size:11px; font-weight:700; }
.permisos-filtro-tab[aria-selected="true"] .n { background:rgba(255,255,255,.22); color:#fff; }
.permisos-filtro-tab .dot { width:7px; height:7px; border-radius:50%; background:#8bc34a; }
.permisos-filtro-buscar { display:flex; align-items:center; gap:6px; flex:0 1 240px; padding:6px 10px; border:1px solid #d5e2d3; border-radius:999px; background:#fff; }
.permisos-filtro-buscar .material-symbols-outlined { font-size:18px; color:#66725e; }
.permisos-filtro-buscar input { border:0; outline:0; width:100%; font:inherit; font-size:13px; background:transparent; }
.permisos-filtro-vacio { margin:14px 0 0; padding:14px; border:1px dashed #d5e2d3; border-radius:12px; color:#66725e; font-size:13px; text-align:center; }
@media (max-width: 720px) { .permisos-filtro-buscar { flex-basis:100%; } }
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
(function () {
  // Comités: Gestionar incluye Ver; quitar Ver quita Gestionar.
  var raiz = document.getElementById('permisos-comites');
  if (!raiz) return;
  var todas = function (sel, base) { return Array.prototype.slice.call((base || raiz).querySelectorAll(sel)); };
  var contador = raiz.querySelector('[data-comites-count]');
  var pintar = function () {
    var cajas = todas('input[data-comites]');
    contador.textContent = cajas.filter(function (c) { return c.checked; }).length + ' de ' + cajas.length + ' asignados';
    todas('[data-pc-col]').forEach(function (btn) {
      var col = todas('input[data-pc="' + btn.dataset.pcCol + '"]', btn.closest('tbody'));
      btn.textContent = col.length && col.every(function (c) { return c.checked; }) ? 'Quitar' : 'Todo';
    });
  };
  var enlazar = function (caja) {
    var fila = caja.closest('[data-pc-fila]');
    if (!fila) return;
    var ver = fila.querySelector('input[data-pc="ver"]');
    var gestionar = fila.querySelector('input[data-pc="gestionar"]');
    if (caja === gestionar && caja.checked && ver) ver.checked = true;
    if (caja === ver && !caja.checked && gestionar) gestionar.checked = false;
  };
  raiz.addEventListener('change', function (ev) {
    if (ev.target.matches('input[data-comites]')) enlazar(ev.target);
    pintar();
  });
  todas('[data-pc-col]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var col = todas('input[data-pc="' + btn.dataset.pcCol + '"]', btn.closest('tbody'));
      var marcar = !col.every(function (c) { return c.checked; });
      col.forEach(function (c) { c.checked = marcar; enlazar(c); });
      pintar();
    });
  });
  var plantilla = document.getElementById('plantilla-comites');
  plantilla.addEventListener('change', function () {
    if (!plantilla.value) return;
    var lista = JSON.parse(plantilla.value);
    todas('input[data-comites]').forEach(function (c) { c.checked = lista.indexOf(c.value) !== -1; });
    plantilla.value = '';
    pintar();
  });
  pintar();
})();
</script>
<script>
(function () {
  // Filtro de permisos por módulo + buscador. Las pestañas salen de data-grupo de cada sección;
  // "Módulos del rol" muestra los módulos marcados arriba (chips con data-grupo-permisos).
  var filtro = document.getElementById('permisos-filtro');
  if (!filtro) return;
  var form = filtro.closest('form') || document;
  var tabsBox = filtro.querySelector('[data-filtro-tabs]');
  var buscar = filtro.querySelector('[data-filtro-buscar]');
  var vacio = document.querySelector('[data-filtro-vacio]');
  var secciones = Array.prototype.slice.call(document.querySelectorAll('.permiso-section[data-grupo]'));
  var chips = Array.prototype.slice.call(document.querySelectorAll('.module-chip[data-grupo-permisos]'));
  var grupos = [];
  secciones.forEach(function (s) { if (grupos.indexOf(s.dataset.grupo) === -1) grupos.push(s.dataset.grupo); });
  var ROL = '__rol', TODOS = '__todos';
  var norm = function (t) { return (t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  var cajas = function (s) { return Array.prototype.slice.call(s.querySelectorAll('input[type=checkbox][name="permisos[]"]')); };
  var gruposDelRol = function () {
    var g = [];
    chips.forEach(function (c) {
      var input = c.querySelector('input');
      var grupo = c.dataset.grupoPermisos;
      if (input && input.checked && grupos.indexOf(grupo) !== -1 && g.indexOf(grupo) === -1) g.push(grupo);
    });
    return g;
  };
  var activo = gruposDelRol().length ? ROL : TODOS;

  // El encabezado del panel es fijo: la barra se pega justo debajo.
  var header = document.querySelector('.app-header');
  if (header) filtro.style.setProperty('--permisos-filtro-top', (header.offsetHeight + 8) + 'px');

  var visiblesDe = function (clave) {
    if (clave === TODOS) return grupos.slice();
    if (clave === ROL) return gruposDelRol();
    return [clave];
  };
  var conteo = function (lista) {
    var marcados = 0, total = 0;
    secciones.forEach(function (s) {
      if (lista.indexOf(s.dataset.grupo) === -1) return;
      cajas(s).forEach(function (c) { total++; if (c.checked) marcados++; });
    });
    return marcados + '/' + total;
  };

  var pintarTabs = function () {
    var delRol = gruposDelRol();
    if (activo === ROL && !delRol.length) activo = TODOS;
    var claves = (delRol.length ? [ROL] : []).concat(grupos, [TODOS]);
    tabsBox.innerHTML = '';
    claves.forEach(function (clave) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'permisos-filtro-tab';
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', clave === activo ? 'true' : 'false');
      var nombre = clave === ROL ? 'Módulos del rol' : (clave === TODOS ? 'Todos' : clave);
      if (clave !== ROL && clave !== TODOS && delRol.indexOf(clave) !== -1) {
        var dot = document.createElement('span');
        dot.className = 'dot';
        dot.title = 'Módulo marcado para este rol';
        b.appendChild(dot);
      }
      b.appendChild(document.createTextNode(nombre));
      var n = document.createElement('span');
      n.className = 'n';
      n.textContent = conteo(visiblesDe(clave));
      n.title = 'Permisos marcados / disponibles';
      b.appendChild(n);
      b.addEventListener('click', function () { activo = clave; aplicar(true); });
      tabsBox.appendChild(b);
    });
  };

  var mostrar = function (el, si) { el.style.display = si ? '' : 'none'; };

  var aplicar = function (desplazar) {
    var visibles = visiblesDe(activo);
    var q = norm(buscar.value.trim());
    var hayAlgo = false;
    secciones.forEach(function (s) {
      var enGrupo = visibles.indexOf(s.dataset.grupo) !== -1;
      var coincide = 0;
      s.querySelectorAll('.permiso-item, tr[data-pc-fila]').forEach(function (it) {
        var ok = !q || norm(it.textContent).indexOf(q) !== -1;
        mostrar(it, ok);
        if (ok) coincide++;
      });
      s.querySelectorAll('.permiso-etapa, tbody[data-pc-grupo]').forEach(function (bloque) {
        var items = bloque.querySelectorAll('.permiso-item, tr[data-pc-fila]');
        var alguno = Array.prototype.some.call(items, function (it) { return it.style.display !== 'none'; });
        mostrar(bloque, !q || !items.length || alguno);
      });
      var ver = enGrupo && (!q || coincide > 0);
      mostrar(s, ver);
      if (ver) hayAlgo = true;
    });
    if (vacio) vacio.hidden = hayAlgo;
    pintarTabs();
    if (desplazar) {
      var tope = filtro.getBoundingClientRect().top + window.pageYOffset - (header ? header.offsetHeight + 8 : 0);
      if (window.pageYOffset > tope) window.scrollTo({ top: tope, behavior: 'smooth' });
    }
  };

  // Al marcar un módulo arriba, la lista salta a sus permisos.
  chips.forEach(function (c) {
    var input = c.querySelector('input');
    if (!input) return;
    input.addEventListener('change', function () {
      var grupo = c.dataset.grupoPermisos;
      if (input.checked && grupos.indexOf(grupo) !== -1) activo = grupo;
      else if (!input.checked && activo === grupo) activo = gruposDelRol().length ? ROL : TODOS;
      aplicar(false);
    });
  });
  buscar.addEventListener('input', function () { aplicar(false); });
  form.addEventListener('change', function (ev) { if (ev.target.name === 'permisos[]' || ev.target.tagName === 'SELECT') pintarTabs(); });
  document.addEventListener('permisos:cambio', pintarTabs);
  aplicar(false);
})();
</script>
