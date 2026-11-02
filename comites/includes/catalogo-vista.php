<?php
$catalogo = $pdo->query("SELECT c.*, l.nombre AS lider_nombre,
    (SELECT COUNT(*) FROM contacto_comite cc JOIN contactos ct ON ct.id=cc.contacto_id WHERE cc.comite_id=c.id AND ct.estado='Activo') AS integrantes,
    (SELECT COUNT(*) FROM reuniones r WHERE r.comite_id=c.id) AS reuniones,
    (SELECT MAX(fecha) FROM reuniones r WHERE r.comite_id=c.id AND realizada=1) AS ultima,
    (SELECT MIN(fecha) FROM reuniones r WHERE r.comite_id=c.id AND realizada=0 AND fecha>=CURRENT_DATE) AS proxima,
    (SELECT COUNT(*) FROM acuerdos a WHERE a.comite_id=c.id AND estado<>'Completado') AS abiertos,
    (SELECT COUNT(*) FROM acuerdos a WHERE a.comite_id=c.id AND estado<>'Completado' AND plazo<CURRENT_DATE) AS vencidos
    FROM comites c LEFT JOIN contactos l ON l.id=c.lider_id ORDER BY c.nombre")->fetchAll();
$directorio = $pdo->query('SELECT * FROM contactos ORDER BY nombre')->fetchAll();
$relaciones = $pdo->query('SELECT contacto_id,comite_id FROM contacto_comite')->fetchAll();
$miembros = [];
foreach ($relaciones as $relacion) { $miembros[$relacion['comite_id']][] = (int) $relacion['contacto_id']; }
$gestionar = can_manage_resource($user, 'configuracion');
$busqueda = trim((string) ($_GET['q'] ?? ''));
$estadoFiltro = (string) ($_GET['estado'] ?? 'Activo');
$resultados = array_filter($catalogo, static fn(array $c): bool => ($estadoFiltro === 'todos' || (int) $c['activo'] === ($estadoFiltro === 'Inactivo' ? 0 : 1)) && ($busqueda === '' || mb_stripos($c['nombre'] . ' ' . ($c['descripcion'] ?? ''), $busqueda) !== false));
$seleccion = null;
$modalMiembros = isset($_GET['integrantes']);
$seleccionId = (string) ($_GET['integrantes'] ?? $_GET['editar'] ?? '');
foreach ($catalogo as $c) { if ($c['id'] === $seleccionId) { $seleccion = $c; } }
if (isset($_GET['nuevo'])) { $seleccion = ['id'=>'','nombre'=>'','descripcion'=>'','lider_id'=>'','periodicidad'=>'Mensual','activo'=>1,'logo'=>null]; }
if ($flashError !== '' && ($_POST['accion'] ?? '') === 'grupo_guardar') {
    $seleccion = array_merge($seleccion ?? [], $_POST, ['activo' => ($_POST['estado'] ?? '') === 'Inactivo' ? 0 : 1]);
}
$csrf = '<input type="hidden" name="csrf" value="' . e($_SESSION['catalogo_csrf']) . '">';
?>
<link rel="stylesheet" href="assets/css/catalogo.css?v=<?= @filemtime(__DIR__ . '/../assets/css/catalogo.css') ?>">
<section class="catalogo" aria-label="Catálogo de comités">
    <?php if ($gestionar): ?><div class="catalogo-heading"><a class="btn btn-primary" href="index.php?view=comites&nuevo=1"><i class="ti ti-plus"></i> Nuevo grupo</a></div><?php endif; ?>
    <form class="catalogo-filtros" method="get">
        <input type="hidden" name="view" value="comites">
        <label class="catalogo-buscar"><i class="ti ti-search"></i><input class="form-control" name="q" value="<?= e($busqueda) ?>" placeholder="Buscar comité..." aria-label="Buscar comité"></label>
        <select class="form-control" name="estado" aria-label="Estado de los comités" onchange="this.form.submit()">
            <?php foreach (['Activo'=>'Activos','Inactivo'=>'Inactivos','todos'=>'Todos'] as $valor=>$label): ?><option value="<?= e($valor) ?>" <?= $estadoFiltro===$valor?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline" type="submit">Buscar</button><span class="catalogo-badge"><?= count($resultados) ?> comité(s)</span>
    </form>
    <?php if ($resultados): ?>
    <div class="catalogo-lista">
        <table>
            <thead><tr><th>Comité</th><th class="num">Integrantes</th><th class="num">Reuniones</th><th class="num">Pasos abiertos</th><th>Última / próxima reunión</th><th>Estado</th><?php if ($gestionar): ?><th class="acc">Acciones</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($resultados as $c): $urlId=urlencode($c['id']); ?>
                <tr class="<?= !(int)$c['activo']?'catalogo-inactivo':'' ?>">
                    <td>
                        <div class="catalogo-nombre">
                            <?php if ($c['logo']): ?><img class="catalogo-logo" src="<?= e($c['logo']) ?>" alt="Logo de <?= e($c['nombre']) ?>"><?php else: ?><span class="catalogo-logo catalogo-icon"><i class="ti ti-affiliate"></i></span><?php endif; ?>
                            <div class="catalogo-title">
                                <strong><?= e($c['nombre']) ?></strong>
                                <small><?= e($c['periodicidad']) ?> · Líder: <span class="<?= !$c['lider_nombre']?'catalogo-alerta':'' ?>"><?= e($c['lider_nombre'] ?: 'sin asignar') ?></span></small>
                                <?php if ($c['descripcion']): ?><small class="catalogo-descripcion" title="<?= e($c['descripcion']) ?>"><?= e($c['descripcion']) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="num"><a class="catalogo-num" href="index.php?view=contactos&comite=<?= $urlId ?>" title="Ver integrantes"><?= (int)$c['integrantes'] ?></a></td>
                    <td class="num"><a class="catalogo-num" href="index.php?view=reuniones&comite=<?= $urlId ?>" title="Ver reuniones"><?= (int)$c['reuniones'] ?></a></td>
                    <td class="num"><a class="catalogo-num <?= $c['vencidos']?'is-danger':'' ?>" href="index.php?view=acuerdos&comite=<?= $urlId ?>" title="Ver próximos pasos"><?= (int)$c['abiertos'] ?></a><?php if ($c['vencidos']): ?><small class="catalogo-peligro"><?= (int)$c['vencidos'] ?> vencido(s)</small><?php endif; ?></td>
                    <td class="catalogo-fechas">
                        <span><i class="ti ti-calendar-check"></i> <?= $c['ultima'] ? date('d/m/Y',strtotime($c['ultima'])) : '—' ?></span>
                        <span class="<?= !$c['proxima']?'catalogo-alerta':'' ?>"><i class="ti ti-calendar-event"></i> <?= $c['proxima'] ? date('d/m/Y',strtotime($c['proxima'])) : 'Sin programar' ?></span>
                    </td>
                    <td><span class="catalogo-badge <?= (int)$c['activo']?'catalogo-verde':'' ?>"><?= (int)$c['activo']?'Activo':'Inactivo' ?></span></td>
                    <?php if ($gestionar): ?>
                    <td class="acc">
                        <details class="catalogo-menu">
                            <summary><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary>
                            <div class="catalogo-menu-list" role="menu">
                                <a href="index.php?view=comites&integrantes=<?= $urlId ?>"><i class="ti ti-user-plus"></i> Agregar integrantes</a>
                                <a href="index.php?view=comites&editar=<?= $urlId ?>"><i class="ti ti-pencil"></i> Editar</a>
                                <form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button name="accion" value="grupo_estado"><i class="ti <?= (int)$c['activo']?'ti-player-pause':'ti-player-play' ?>"></i> <?= (int)$c['activo']?'Inactivar':'Activar' ?></button></form>
                                <form method="post" onsubmit="return confirm('¿Eliminar este comité? Esta acción no se puede deshacer.')"><?= $csrf ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="is-danger" name="accion" value="grupo_eliminar"><i class="ti ti-trash"></i> Eliminar</button></form>
                            </div>
                        </details>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if (!$resultados): ?><div class="catalogo-vacio">No hay comités con esos filtros.</div><?php endif; ?>

    <?php if ($seleccion !== null && $gestionar):
        $id = (string) ($seleccion['id'] ?? '');
        $actuales = $miembros[$id] ?? [];
        if ($flashError !== '' && ($_POST['accion'] ?? '') === 'grupo_guardar') { $actuales = array_map('intval',(array)($_POST['integrantes'] ?? [])); }
    ?>
    <div class="catalogo-overlay">
        <section class="catalogo-dialog" role="dialog" aria-modal="true" aria-labelledby="catalogo-modal-title">
            <header><h2 id="catalogo-modal-title"><?= $modalMiembros ? 'Agregar integrantes · ' . e($seleccion['nombre']) : ($id ? 'Editar grupo' : 'Nuevo grupo') ?></h2><a href="index.php?view=comites" aria-label="Cerrar"><i class="ti ti-x"></i></a></header>
            <?php if ($flashError): ?><div class="alert error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
            <?php if (!$modalMiembros): ?>
            <form method="post" enctype="multipart/form-data" class="catalogo-form">
                <?= $csrf ?><input type="hidden" name="accion" value="grupo_guardar"><input type="hidden" name="id" value="<?= e($id) ?>">
                <div class="catalogo-fields">
                    <label>Nombre del comité o grupo<input class="form-control" name="nombre" maxlength="160" required value="<?= e($seleccion['nombre']) ?>" placeholder="Ej. Comité de Riego"></label>
                    <label>Periodicidad de reuniones<select class="form-control" name="periodicidad"><?php foreach (['Mensual','Bimestral','Trimestral','Semestral','Según necesidad'] as $op): ?><option <?= ($seleccion['periodicidad'] ?? 'Mensual')===$op?'selected':'' ?>><?= e($op) ?></option><?php endforeach; ?></select></label>
                    <label>Líder del comité<select class="form-control" name="lider_id" id="catalogo-lider"><option value="">Sin asignar</option><?php foreach ($directorio as $contacto): if ($contacto['estado'] !== 'Activo') continue; ?><option value="<?= (int)$contacto['id'] ?>" <?= (int)($seleccion['lider_id'] ?? 0)===(int)$contacto['id']?'selected':'' ?>><?= e($contacto['nombre'].' · '.$contacto['empresa']) ?></option><?php endforeach; ?></select><small>Se agrega automáticamente como integrante.</small></label>
                    <label>Estado<select class="form-control" name="estado"><option <?= (int)$seleccion['activo']?'selected':'' ?>>Activo</option><option <?= !(int)$seleccion['activo']?'selected':'' ?>>Inactivo</option></select></label>
                    <div class="catalogo-wide"><label>Logo <small>PNG, JPG, SVG o WebP, hasta 2 MB.</small><input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp" id="catalogo-logo-input"></label><img id="catalogo-logo-preview" class="catalogo-preview" src="<?= e($seleccion['logo'] ?? '') ?>" alt="Vista previa del logo" <?= empty($seleccion['logo'])?'hidden':'' ?>><label><input type="checkbox" name="quitar_logo" value="1"> Quitar logo actual</label></div>
                    <label class="catalogo-wide">Objetivo o descripción<textarea class="form-control" rows="2" name="descripcion"><?= e($seleccion['descripcion'] ?? '') ?></textarea></label>
                </div>
                <label>Integrantes (<span id="catalogo-total">0</span>)</label>
                <input class="form-control" type="search" data-catalogo-search="catalogo-seleccion" placeholder="Buscar contacto..." aria-label="Buscar integrante">
                <div class="catalogo-contactos" id="catalogo-seleccion">
                    <?php foreach ($directorio as $contacto): if ($contacto['estado'] !== 'Activo') continue; ?><label class="catalogo-contacto" data-catalogo-row><input type="checkbox" name="integrantes[]" value="<?= (int)$contacto['id'] ?>" <?= in_array((int)$contacto['id'],$actuales,true)?'checked':'' ?>><span><?= e($contacto['nombre']) ?><small><?= e($contacto['cargo'].' · '.$contacto['empresa']) ?></small></span><span data-lider-tag hidden class="catalogo-badge catalogo-verde">Líder</span></label><?php endforeach; ?>
                    <?php if (!$directorio): ?><p class="catalogo-vacio">Sin contactos. Agréguelos primero en el Directorio.</p><?php endif; ?>
                </div>
                <div class="catalogo-actions"><a class="btn btn-outline" href="index.php?view=comites">Cancelar</a><button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy"></i> Guardar grupo</button></div>
            </form>
            <?php else: ?>
            <nav class="catalogo-tabs" aria-label="Agregar integrantes"><button type="button" data-catalogo-tab="directorio" class="active">Del directorio</button><button type="button" data-catalogo-tab="nuevo">Registrar nuevo contacto</button><button type="button" data-catalogo-tab="cargar">Cargar lista (Excel)</button></nav>
            <div data-catalogo-panel="directorio">
                <input class="form-control" type="search" data-catalogo-search="catalogo-disponibles" placeholder="Buscar en el directorio por nombre, cargo, empresa o correo..." aria-label="Buscar en el directorio">
                <div class="catalogo-contactos" id="catalogo-disponibles">
                    <?php $disponibles=0; foreach ($directorio as $contacto): if ($contacto['estado'] !== 'Activo' || in_array((int)$contacto['id'],$actuales,true)) continue; $disponibles++; ?>
                    <form method="post" class="catalogo-contacto" data-catalogo-row><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="contacto_id" value="<?= (int)$contacto['id'] ?>"><span><?= e($contacto['nombre']) ?><small><?= e($contacto['cargo'].' · '.$contacto['empresa'].' · '.$contacto['email']) ?></small></span><button class="btn btn-outline" name="accion" value="grupo_integrante_agregar">+ Agregar</button></form>
                    <?php endforeach; if (!$disponibles): ?><p class="catalogo-vacio">Sin coincidencias. Use “Registrar nuevo contacto”.</p><?php endif; ?>
                </div>
            </div>
            <form method="post" data-catalogo-panel="nuevo" hidden><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="accion" value="grupo_integrante_nuevo">
                <div class="catalogo-fields"><?php foreach (['nombre'=>'Nombre completo','email'=>'Correo','cargo'=>'Cargo','empresa'=>'Empresa / ingenio','area'=>'Área','telefono'=>'Teléfono'] as $key=>$label): ?><label><?= $label ?><input class="form-control" name="<?= $key ?>" type="<?= $key==='email'?'email':'text' ?>" maxlength="<?= $key==='telefono'?'40':'180' ?>" <?= $key==='nombre'?'required':'' ?> value="<?= ($flashError && ($_POST['accion'] ?? '')==='grupo_integrante_nuevo')?e((string)($_POST[$key] ?? '')):'' ?>"></label><?php endforeach; ?></div>
                <div class="catalogo-actions"><button class="btn btn-primary">Registrar y agregar al comité</button></div>
            </form>
            <form method="post" enctype="multipart/form-data" data-catalogo-panel="cargar" hidden><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="accion" value="grupo_integrante_importar">
                <p>Selecciona una lista Excel (.xlsx) o CSV. La primera fila debe incluir: Nombre, Correo, Cargo, Empresa, Área y Teléfono. Solo Nombre es obligatorio. Los correos existentes se vinculan al comité sin duplicar el contacto.</p>
                <label class="catalogo-upload">Cargar lista<input class="form-control" type="file" name="lista" accept=".xlsx,.csv" required></label>
                <div class="catalogo-actions"><button class="btn btn-primary">Cargar y agregar integrantes</button></div>
            </form>
            <div class="catalogo-miembros"><h3>Integrantes actuales (<?= count($actuales) ?>)</h3><div class="catalogo-chips">
                <?php foreach ($directorio as $contacto): if (!in_array((int)$contacto['id'],$actuales,true)) continue; ?><form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="contacto_id" value="<?= (int)$contacto['id'] ?>"><span><?= e($contacto['nombre']) ?></span><button name="accion" value="grupo_integrante_quitar" aria-label="Quitar <?= e($contacto['nombre']) ?>">×</button></form><?php endforeach; ?>
                <?php if (!$actuales): ?><span>Sin integrantes.</span><?php endif; ?>
            </div></div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</section>
<script src="assets/js/catalogo.js?v=<?= @filemtime(__DIR__ . '/../assets/js/catalogo.js') ?>" defer></script>
