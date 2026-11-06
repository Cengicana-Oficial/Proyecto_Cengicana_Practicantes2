<?php
// Cifras institucionales. Datos en $cifras (cifras_datos()) y $alianzasTodas (cifras_alianzas()).
$anioActual = (int) date('Y');
$exportar = static fn(string $f) => 'index.php?view=cifras&anio=' . $anioCifras . '&exportar=' . $f;
?>
<link rel="stylesheet" href="assets/css/cifras.css?v=<?= @filemtime(__DIR__ . '/../assets/css/cifras.css') ?>">

<div class="cif-page">
    <div class="cif-toolbar">
        <form method="get" class="cif-anio">
            <input type="hidden" name="view" value="cifras">
            <label><span>Año</span>
                <select class="form-control" name="anio" onchange="this.form.submit()">
                    <?php for ($a = $anioActual; $a >= 2020; $a--): ?><option value="<?= $a ?>" <?= $a === $anioCifras ? 'selected' : '' ?>><?= $a ?></option><?php endfor; ?>
                </select></label>
        </form>
        <p class="cif-ayuda">Cifras reunidas de Cengicursos, Comités, Laboratorio y Experimentos, listas para presentar a empresas e ingenios.</p>
        <div class="cif-acciones">
            <a class="btn-outline" href="<?= e($exportar('html')) ?>" target="_blank" rel="noopener"><i class="ti ti-printer"></i> Ver para imprimir</a>
            <a class="btn-outline" href="<?= e($exportar('pdf')) ?>"><i class="ti ti-file-type-pdf"></i> PDF</a>
            <a class="btn-primary" href="<?= e($exportar('pptx')) ?>"><i class="ti ti-presentation"></i> Descargar PowerPoint</a>
        </div>
    </div>

    <div class="cif-hero">
        <div><h2>CENGICAÑA en cifras <?= (int) $cifras['anio'] ?></h2><p>Impacto, capacitación, transferencia de tecnología, conocimiento, comunicación y alianzas.</p></div>
        <div class="cif-franja" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
    </div>

    <?php foreach ($cifras['secciones'] as $clave => $s): ?>
        <section class="cif-seccion" id="<?= e($clave) ?>" style="--cif-color: <?= e($s['color']) ?>">
            <h3><i class="ti <?= e($s['icono']) ?>"></i> <?= e($s['titulo']) ?></h3>
            <div class="cif-kpis">
                <?php foreach ($s['kpis'] as $k): ?>
                    <div class="cif-kpi">
                        <b><?= e(cifras_num($k['valor'])) ?></b>
                        <span><?= e($k['etiqueta']) ?></span>
                        <?php if ($k['detalle'] !== ''): ?><small><?= e($k['detalle']) ?></small><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($s['barras']['items'])): $b = $s['barras']; $max = max(1, ...array_map(static fn($i) => (int) $i[$b['valor']], $b['items'])); ?>
                <div class="cif-barras">
                    <h4><?= e($b['titulo']) ?></h4>
                    <?php foreach ($b['items'] as $i): ?>
                        <div class="cif-barra"><span title="<?= e((string) $i[$b['etiqueta']]) ?>"><?= e((string) $i[$b['etiqueta']]) ?></span><div><i style="width: <?= max(2, round((int) $i[$b['valor']] / $max * 100)) ?>%"></i></div><b><?= e(cifras_num($i[$b['valor']])) ?></b></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="cif-seccion" id="alianzas-registro" style="--cif-color: #A3D300">
        <div class="cif-ali-head">
            <h3><i class="ti ti-list-details"></i> Registro de alianzas y convenios</h3>
            <?php if ($puedeGestionarSeccionActual): ?><button type="button" class="btn-primary" data-cif-nueva><i class="ti ti-plus"></i> Nueva alianza</button><?php endif; ?>
        </div>
        <p class="cif-ayuda">Ingenios del extranjero, empresas tecnológicas (riego, drones…), universidades y organismos con los que CENGICAÑA colabora. Con el país se calculan los ingenios de Centroamérica y los convenios en el extranjero.</p>
        <?php if (!$alianzasTodas): ?>
            <div class="empty-state"><i class="ti ti-heart-handshake"></i><p>Aún no hay alianzas registradas.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="cif-tabla">
                <thead><tr><th></th><th>Institución</th><th>Tipo</th><th>País</th><th>Estado</th><th>Vigencia</th><th>Colaboración</th><?php if ($puedeGestionarSeccionActual): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($alianzasTodas as $a): ?>
                    <tr>
                        <td class="cif-logo"><?php if ($a['logo']): ?><img src="<?= e($a['logo']) ?>" alt=""><?php else: ?><span><?= e(mb_strtoupper(mb_substr($a['nombre'], 0, 2))) ?></span><?php endif; ?></td>
                        <td><b><?= e($a['nombre']) ?></b><?php if ($a['sitio_web']): ?><small><a href="<?= e($a['sitio_web']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('~^https?://(www\.)?~i', '', $a['sitio_web'])) ?></a></small><?php endif; ?><?php if ($a['contacto']): ?><small><?= e($a['contacto']) ?></small><?php endif; ?></td>
                        <td><?= e(CIFRAS_TIPOS[$a['tipo']] ?? $a['tipo']) ?></td>
                        <td><?= e($a['pais']) ?><?php if (cifras_es_centroamerica($a['pais'])): ?><small>Centroamérica</small><?php endif; ?></td>
                        <td><span class="cif-estado <?= e($a['estado']) ?>"><?= e(CIFRAS_ESTADOS[$a['estado']] ?? $a['estado']) ?></span></td>
                        <td><?= e(trim(($a['fecha_inicio'] ?: '') . ($a['fecha_fin'] ? ' → ' . $a['fecha_fin'] : ''), ' →') ?: '—') ?></td>
                        <td class="cif-objeto" title="<?= e((string) $a['objeto']) ?>"><?= e((string) $a['objeto']) ?></td>
                        <?php if ($puedeGestionarSeccionActual): ?>
                            <td class="cif-acc">
                                <button type="button" class="icon-btn" title="Editar" aria-label="Editar <?= e($a['nombre']) ?>" data-cif-editar="<?= e(json_encode(array_diff_key($a, ['logo' => 1]) + ['tiene_logo' => (bool) $a['logo']], JSON_UNESCAPED_UNICODE)) ?>"><i class="ti ti-pencil"></i></button>
                                <form method="post" onsubmit="return confirm('¿Eliminar la alianza con <?= e(addslashes($a['nombre'])) ?>?')">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['cifras_csrf']) ?>"><input type="hidden" name="accion" value="alianza_eliminar"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="icon-btn danger" title="Eliminar" aria-label="Eliminar <?= e($a['nombre']) ?>"><i class="ti ti-trash"></i></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <p class="cif-fuentes">Fuentes: Cengicursos (cursos, diplomas, eventos y participantes por ingenio), Comités (comités, eventos de transferencia, directorio, biblioteca, memorias, redes y sitio web), Laboratorio (informes firmados y muestras) y Experimentos (ensayos y proyectos). Un “—” indica que el módulo no tiene datos disponibles.</p>
</div>

<?php if ($puedeGestionarSeccionActual): ?>
<dialog class="cif-dialog" id="cif-dialog" aria-labelledby="cif-dialog-titulo">
    <form method="post" enctype="multipart/form-data">
        <header><h3 id="cif-dialog-titulo">Nueva alianza</h3><button type="button" class="icon-btn" data-cif-cerrar aria-label="Cerrar"><i class="ti ti-x"></i></button></header>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['cifras_csrf']) ?>"><input type="hidden" name="accion" value="alianza_guardar"><input type="hidden" name="id" value="">
        <div class="cif-campos">
            <label class="cif-ancho">Institución <span class="req">*</span><input class="form-control" name="nombre" maxlength="200" required placeholder="Ej. Latitud, Ingenio San Antonio (Nicaragua)"></label>
            <label>Tipo <span class="req">*</span><select class="form-control" name="tipo" required><?php foreach (CIFRAS_TIPOS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></label>
            <label>País <span class="req">*</span><input class="form-control" name="pais" list="cif-paises" maxlength="80" required value="Guatemala"><datalist id="cif-paises"><?php foreach (CIFRAS_PAISES as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></label>
            <label>Estado<select class="form-control" name="estado"><?php foreach (CIFRAS_ESTADOS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></label>
            <label>Inicio<input class="form-control" type="date" name="fecha_inicio"></label>
            <label>Fin<input class="form-control" type="date" name="fecha_fin"></label>
            <label class="cif-ancho">Objeto de la colaboración<textarea class="form-control" name="objeto" rows="2" placeholder="Ej. Riego por goteo en ensayos de variedades; vuelos con drones para monitoreo."></textarea></label>
            <label>Contacto<input class="form-control" name="contacto" maxlength="200" placeholder="Nombre · correo"></label>
            <label>Sitio web<input class="form-control" name="sitio_web" maxlength="255" placeholder="empresa.com"></label>
            <label class="cif-ancho">Logo (PNG o JPG, hasta 1 MB)<input class="form-control" type="file" name="logo" accept="image/png,image/jpeg"><span class="cif-quitar" hidden><input type="checkbox" name="quitar_logo" value="1"> Quitar el logo actual</span></label>
        </div>
        <footer><button type="button" class="btn-outline" data-cif-cerrar>Cancelar</button><button type="submit" class="btn-primary">Guardar</button></footer>
    </form>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('cif-dialog'), form = dialog.querySelector('form');
    const abrir = datos => {
        form.reset();
        form.elements.id.value = datos?.id || '';
        dialog.querySelector('#cif-dialog-titulo').textContent = datos ? 'Editar alianza' : 'Nueva alianza';
        ['nombre', 'tipo', 'pais', 'estado', 'fecha_inicio', 'fecha_fin', 'objeto', 'contacto', 'sitio_web'].forEach(k => { if (datos && datos[k] != null) form.elements[k].value = datos[k]; });
        dialog.querySelector('.cif-quitar').hidden = !datos?.tiene_logo;
        dialog.showModal();
    };
    document.querySelector('[data-cif-nueva]')?.addEventListener('click', () => abrir(null));
    document.querySelectorAll('[data-cif-editar]').forEach(b => b.addEventListener('click', () => abrir(JSON.parse(b.dataset.cifEditar))));
    dialog.querySelectorAll('[data-cif-cerrar]').forEach(b => b.addEventListener('click', () => dialog.close()));
})();
</script>
<?php endif; ?>
