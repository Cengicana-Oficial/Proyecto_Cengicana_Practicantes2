<?php
// Cifras institucionales como presentación: portada, crecimiento, una diapositiva por sección,
// diapositivas libres (cifras, texto, gráfico) y cierre. Navegable por pestañas, flechas o teclado,
// con pantalla completa y copia de cifras en texto. Los gestores crean y editan diapositivas.
// Datos en $cifras (cifras_presentacion()) y $alianzasTodas (cifras_alianzas()).
$anioActual = (int) date('Y');
$anioC = (int) $cifras['anio'];
$gestor = (bool) $puedeGestionarSeccionActual;
$exportar = static fn(string $f) => 'index.php?view=cifras&anio=' . $anioCifras . '&exportar=' . $f;
$cortos = ['evolucion' => 'Crecimiento', 'impacto' => 'Impacto', 'capacitacion' => 'Capacitación', 'transferencia' => 'Transferencia', 'comites' => 'Comités',
    'visitas' => 'Visitas', 'conocimiento' => 'Conocimiento', 'comunicacion' => 'Comunicación', 'alianzas' => 'Alianzas'];
$corto = static fn(string $c, array $s) => $cortos[$c] ?? mb_strimwidth($s['titulo'], 0, 22, '…');
$fondo = static fn(array $foto) => "--cif-foto:url('" . $foto['url'] . "');--cif-foto-pos:center " . (int) $foto['pos'] . '%';
$secciones = $cifras['secciones'];
$portada = $cifras['portada'];
$cierre = $cifras['cierre'];
$verCierre = $gestor || !$cierre['oculta'];
$total = 1 + count($secciones) + ($verCierre ? 1 : 0);
$json = static fn(array $v) => e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$fotoEditor = static fn(array $f) => ['url' => $f['url'], 'pos' => $f['pos'], 'propia' => $f['propia']];
$botonEditar = static fn(array $datos) => $gestor
    ? '<button type="button" class="cif-foto-btn" data-cif-editar-diapo="' . $json($datos) . '"><i class="ti ti-edit"></i><span>Editar diapositiva</span></button>' : '';
$deltaHtml = static fn(?array $dl) => $dl ? '<em class="cif-delta ' . ['-1' => 'baja', '0' => 'igual', '1' => 'sube'][(string) $dl['signo']] . '">' . e($dl['texto']) . '</em>' : '';
$aviso = '<span class="cif-oculta-aviso"><i class="ti ti-eye-off"></i> Oculta: no aparece al presentar ni en PDF/PowerPoint</span>';

// Texto plano de cada diapositiva para el botón "Copiar cifras".
$texto = static function (array $s) use ($anioC): string {
    $t = $s['titulo'] . ' · CENGICAÑA en cifras ' . $anioC . "\n" . ($s['mensaje'] !== '' ? $s['mensaje'] . "\n" : '');
    foreach ($s['kpis'] as $k) $t .= '• ' . cifras_num($k['valor']) . ' ' . $k['etiqueta'] . ($k['detalle'] !== '' ? ' (' . $k['detalle'] . ')' : '') . (!empty($k['delta']) ? ' — ' . $k['delta']['texto'] : '') . (isset($k['meta']) ? ' — ' . $k['avance'] . ' % de la meta' : '') . "\n";
    foreach ($s['series'] ?? [] as $se) $t .= '• ' . $se['etiqueta'] . ': ' . implode(' → ', array_map(static fn($a, $v) => "$a: " . ($v === null ? '—' : number_format($v, 0, '.', ',')), array_keys($se['valores']), $se['valores'])) . "\n";
    foreach ($s['cuerpo'] ?? [] as $l) $t .= '• ' . $l . "\n";
    $g = !empty($s['barras']['items']) ? ['titulo' => $s['barras']['titulo'], 'items' => array_map(static fn($i) => ['nombre' => $i[$s['barras']['etiqueta']], 'valor' => $i[$s['barras']['valor']]], $s['barras']['items'])] : ($s['grafico'] ?? null);
    if (!empty($g['items'])) {
        $t .= "\n" . ($g['titulo'] !== '' ? $g['titulo'] . ":\n" : '');
        foreach ($g['items'] as $i) $t .= '  - ' . $i['nombre'] . ': ' . cifras_num($i['valor']) . "\n";
    }
    if (!empty($s['lista'])) $t .= "\nAliados: " . implode(', ', array_map(static fn($a) => $a['nombre'] . ' (' . $a['pais'] . ')', $s['lista'])) . "\n";
    return rtrim($t);
};
$textoPortada = $portada['titulo'] . ' ' . $anioC . "\n" . implode("\n", array_map(static fn($k) => '• ' . cifras_num($k['valor']) . ' ' . $k['etiqueta'], $portada['destacados']));
$textoTodo = $textoPortada . "\n\n" . implode("\n\n", array_map($texto, array_filter($secciones, static fn($s) => !$s['oculta'])));

// Datos para el editor: lista de diapositivas (posición) y cifras elegibles (portada y crecimiento).
$listaDiapos = [];
foreach ($secciones as $c => $s) $listaDiapos[] = [$c, $corto($c, $s)];
$candidatos = array_values(array_map(static fn($k) => ['id' => $k['id'], 'etiqueta' => $k['etiqueta'], 'seccion' => $k['seccion'], 'valor' => cifras_num($k['valor'])], $portada['candidatos']));

// Datos del editor para una sección.
$datosEditor = static function (string $clave, array $s) use ($fotoEditor): array {
    $d = ['clave' => $clave, 'tipo' => $s['tipo'], 'plantilla' => $s['plantilla'] ?? '', 'nombre' => $s['tipo'] === 'libre' ? $s['titulo'] : $s['titulo_original'],
        'titulo' => $s['titulo'] !== $s['titulo_original'] || $s['tipo'] === 'libre' ? $s['titulo'] : '', 'titulo_original' => $s['titulo_original'],
        'mensaje' => $s['mensaje'], 'oculta' => $s['oculta'], 'foto' => $fotoEditor($s['foto']), 'extras' => $s['extras'], 'despues' => $s['despues']];
    $visibles = array_column($s['kpis'], 'id');
    $d['kpis'] = array_map(static fn($k) => ['id' => $k['id'], 'etiqueta' => $k['etiqueta'], 'valor' => cifras_num($k['valor']), 'visible' => in_array($k['id'], $visibles, true), 'meta' => $s['metas'][$k['id']] ?? ''], $s['kpis_todos']);
    if ($s['tipo'] === 'evolucion') $d['elegidos'] = $s['evolucion_ids'];
    if ($s['tipo'] === 'libre') {
        $d['color'] = $s['color'];
        $d['cuerpo'] = implode("\n", $s['cuerpo']);
        $d['grafico'] = $s['grafico_conf'] ?? null;
    }
    return $d;
};
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
        <p class="cif-ayuda">Cifras de Cengicursos, Comités, Visitas, Laboratorio y Experimentos listas para presentar, comparadas con el año anterior. Navega con las pestañas o con las flechas ← → del teclado.<?php if ($gestor): ?> Con <b>+ Nueva</b> agregas diapositivas propias y con <b>Editar diapositiva</b> cambias textos, cifras, metas, foto y posición; el PowerPoint y el PDF salen con esos cambios.<?php endif; ?></p>
        <div class="cif-acciones">
            <button type="button" class="btn-primary" data-cif-presentar><i class="ti ti-player-play"></i> Presentar</button>
            <button type="button" class="btn-outline" data-cif-copiar="<?= e($textoTodo) ?>"><i class="ti ti-copy"></i> Copiar todo</button>
            <a class="btn-outline" href="<?= e($exportar('html')) ?>" target="_blank" rel="noopener"><i class="ti ti-printer"></i> Imprimir</a>
            <a class="btn-outline" href="<?= e($exportar('pdf')) ?>"><i class="ti ti-file-type-pdf"></i> PDF</a>
            <a class="btn-outline" href="<?= e($exportar('pptx')) ?>"><i class="ti ti-presentation"></i> PowerPoint</a>
        </div>
    </div>

    <div class="cif-pres" id="cif-pres">
        <nav class="cif-tabs" role="tablist" aria-label="Diapositivas de la presentación">
            <button type="button" role="tab" id="cif-tab-portada" aria-controls="cif-portada" data-cif-tab="portada" style="--cif-color:#1F5632"><i class="ti ti-home-2"></i><span>Portada</span></button>
            <?php foreach ($secciones as $clave => $s): ?>
                <button type="button" role="tab" id="cif-tab-<?= e($clave) ?>" aria-controls="cif-<?= e($clave) ?>" data-cif-tab="<?= e($clave) ?>" class="<?= $s['oculta'] ? 'es-oculta' : '' ?>" style="--cif-color:<?= e($s['color']) ?>"><i class="ti <?= e($s['oculta'] ? 'ti-eye-off' : $s['icono']) ?>"></i><span><?= e($corto($clave, $s)) ?></span></button>
            <?php endforeach; ?>
            <?php if ($verCierre): ?>
                <button type="button" role="tab" id="cif-tab-cierre" aria-controls="cif-cierre" data-cif-tab="cierre" class="<?= $cierre['oculta'] ? 'es-oculta' : '' ?>" style="--cif-color:#BCF32E"><i class="ti <?= $cierre['oculta'] ? 'ti-eye-off' : 'ti-flag' ?>"></i><span>Cierre</span></button>
            <?php endif; ?>
            <?php if ($gestor): ?><button type="button" class="cif-tab-nueva" data-cif-nueva-diapo><i class="ti ti-plus"></i><span>Nueva</span></button><?php endif; ?>
            <span class="cif-tabs-sep" aria-hidden="true"></span>
            <button type="button" role="tab" id="cif-tab-registro" aria-controls="alianzas-registro" data-cif-tab="registro" class="cif-tab-gestion" style="--cif-color:#A3D300"><i class="ti ti-list-details"></i><span>Registro de alianzas</span></button>
        </nav>

        <div class="cif-stage">
            <!-- Portada -->
            <section class="cif-slide cif-portada" id="cif-portada" role="tabpanel" aria-labelledby="cif-tab-portada" data-cif-slide="portada" style="<?= e($fondo($portada['foto'])) ?>">
                <div class="cif-slide-top">
                    <span class="cif-marca"><img src="assets/img/cengicana-logo.png" alt="CENGICAÑA"></span>
                    <span class="cif-top-der"><?= $botonEditar(['clave' => 'portada', 'tipo' => 'portada', 'nombre' => 'Portada', 'titulo' => $portada['titulo_personalizado'], 'titulo_original' => 'CENGICAÑA en cifras', 'mensaje' => $portada['mensaje_personalizado'], 'foto' => $fotoEditor($portada['foto']), 'elegidos' => $portada['destacados_ids']]) ?><span class="cif-pag">01 / <?= sprintf('%02d', $total) ?></span></span>
                </div>
                <div class="cif-portada-cuerpo">
                    <span class="cif-chip">Informe institucional <?= $anioC ?></span>
                    <h2><?= e($portada['titulo']) ?><br><em><?= $anioC ?></em></h2>
                    <p><?= e($portada['mensaje']) ?></p>
                    <div class="cif-indice">
                        <?php $n = 2; foreach ($secciones as $clave => $s): ?>
                            <button type="button" data-cif-ir="<?= e($clave) ?>" class="<?= $s['oculta'] ? 'es-oculta' : '' ?>" style="--cif-color:<?= e($s['color']) ?>"><b><?= sprintf('%02d', $n++) ?></b><?= e($corto($clave, $s)) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="cif-destacados">
                    <?php foreach ($portada['destacados'] as $k): ?>
                        <div class="cif-kpi" style="--cif-color:<?= e($k['color']) ?>"><b data-cif-num><?= e(cifras_num($k['valor'])) ?></b><span><?= e($k['etiqueta']) ?></span><?= $deltaHtml($k['delta'] ?? null) ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="cif-slide-pie">
                    <div class="cif-franja" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                    <button type="button" class="cif-copiar" data-cif-copiar="<?= e($textoPortada) ?>"><i class="ti ti-copy"></i> Copiar cifras</button>
                </div>
            </section>

            <?php $n = 2; foreach ($secciones as $clave => $s):
                $conBarras = !empty($s['barras']['items']); $conGrafico = !empty($s['grafico']['items']); $conTexto = !empty($s['cuerpo']); $conLista = isset($s['lista']);
                $conPanel = $conBarras || $conGrafico || $conTexto || $conLista;
                $clases = ['cif-slide']; if ($conPanel && $s['kpis']) $clases[] = 'cif-con-detalle'; if ($conPanel && !$s['kpis']) $clases[] = 'cif-solo-panel'; if ($s['oculta']) $clases[] = 'cif-oculta'; ?>
                <section class="<?= implode(' ', $clases) ?>" id="cif-<?= e($clave) ?>" role="tabpanel" aria-labelledby="cif-tab-<?= e($clave) ?>" data-cif-slide="<?= e($clave) ?>" hidden style="--cif-color:<?= e($s['color']) ?>;<?= e($fondo($s['foto'])) ?>">
                    <div class="cif-slide-top">
                        <span class="cif-marca"><img src="assets/img/cengicana-logo.png" alt="CENGICAÑA">en cifras <?= $anioC ?></span>
                        <span class="cif-top-der"><?= $s['oculta'] ? $aviso : '' ?><?= $botonEditar($datosEditor($clave, $s)) ?><span class="cif-pag"><?= sprintf('%02d', $n) ?> / <?= sprintf('%02d', $total) ?></span></span>
                    </div>
                    <header class="cif-slide-titulo">
                        <span class="cif-icono"><i class="ti <?= e($s['icono']) ?>"></i></span>
                        <div><small>Capítulo <?= sprintf('%02d', $n++) ?></small><h3><?= e($s['titulo']) ?></h3></div>
                    </header>
                    <?php if ($s['mensaje'] !== ''): ?><p class="cif-mensaje"><?= e($s['mensaje']) ?></p><?php endif; ?>

                    <?php if ($s['tipo'] === 'evolucion'): ?>
                        <div class="cif-slide-cuerpo">
                            <div class="cif-evo">
                                <?php foreach ($s['series'] as $se): $max = max(1, ...array_map(static fn($v) => (float) $v, $se['valores'])); $ult = array_key_last($se['valores']); ?>
                                    <div class="cif-evo-tarjeta" style="--cif-color:<?= e($se['color']) ?>">
                                        <span><?= e($se['etiqueta']) ?></span><?= $deltaHtml($se['delta']) ?>
                                        <div class="cif-evo-cols">
                                            <?php foreach ($se['valores'] as $a => $v): ?>
                                                <div class="<?= $a === $ult ? 'es-actual' : '' ?>"><b><?= $v === null ? '—' : e(number_format($v, 0, '.', ',')) ?></b><i style="--h:<?= $v === null ? 0 : max(3, round($v / $max * 100)) ?>%"></i><small><?= $a ?></small></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$s['series']): ?><p class="cif-vacio">Elige las cifras a comparar en «Editar diapositiva».</p><?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                    <div class="cif-slide-cuerpo">
                        <?php if ($s['kpis'] || !$conPanel): ?>
                        <div class="cif-kpis">
                            <?php foreach ($s['kpis'] as $k): ?>
                                <div class="cif-kpi<?= !empty($k['manual']) ? ' es-manual' : '' ?>"<?= !empty($k['manual']) ? ' title="Cifra ingresada manualmente"' : '' ?>>
                                    <b data-cif-num><?= e(cifras_num($k['valor'])) ?></b>
                                    <span><?= e($k['etiqueta']) ?></span>
                                    <?php if ($k['detalle'] !== ''): ?><small><?= e($k['detalle']) ?></small><?php endif; ?>
                                    <?= $deltaHtml($k['delta'] ?? null) ?>
                                    <?php if (isset($k['meta'])): ?>
                                        <div class="cif-meta" title="Meta: <?= e(cifras_num((string) $k['meta'])) ?>"><div><i style="--w:<?= min(100, $k['avance']) ?>%"></i></div><small><?= (int) $k['avance'] ?> % de la meta (<?= e(cifras_num((string) $k['meta'])) ?>)</small></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$s['kpis']): ?><p class="cif-vacio">Sin contenido todavía. <?= $gestor ? 'Llénala con «Editar diapositiva».' : '' ?></p><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($conTexto && !$conBarras && !$conGrafico): ?>
                            <div class="cif-panel cif-texto"><ul><?php foreach ($s['cuerpo'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></div>
                        <?php elseif ($conBarras): $b = $s['barras']; $max = max(1, ...array_map(static fn($i) => (float) $i[$b['valor']], $b['items'])); ?>
                            <div class="cif-panel">
                                <?php if ($b['titulo'] !== ''): ?><h4><?= e($b['titulo']) ?></h4><?php endif; ?>
                                <?php foreach ($b['items'] as $i): ?>
                                    <div class="cif-barra"><span title="<?= e((string) $i[$b['etiqueta']]) ?>"><?= e((string) $i[$b['etiqueta']]) ?></span><div><i style="--w: <?= max(2, round((float) $i[$b['valor']] / $max * 100)) ?>%"></i></div><b><?= e(cifras_num($i[$b['valor']])) ?></b></div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($conGrafico && $s['grafico']['tipo'] === 'columnas'): $g = $s['grafico']; $max = max(1, ...array_map(static fn($i) => (float) $i['valor'], $g['items'])); ?>
                            <div class="cif-panel">
                                <?php if ($g['titulo'] !== ''): ?><h4><?= e($g['titulo']) ?></h4><?php endif; ?>
                                <div class="cif-columnas">
                                    <?php foreach ($g['items'] as $i): ?>
                                        <div title="<?= e($i['nombre']) ?>"><b><?= e(cifras_num($i['valor'])) ?></b><i style="--h:<?= max(2, round((float) $i['valor'] / $max * 100)) ?>%"></i><small><?= e($i['nombre']) ?></small></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php elseif ($conGrafico): $g = $s['grafico']; $suma = array_sum(array_map(static fn($i) => max(0, (float) $i['valor']), $g['items'])) ?: 1;
                            $acum = 0; $partes = [];
                            foreach ($g['items'] as $j => $i) { $ini = $acum; $acum += max(0, (float) $i['valor']) / $suma * 100; $partes[] = CIFRAS_SERIE[$j % count(CIFRAS_SERIE)] . ' ' . round($ini, 2) . '% ' . round($acum, 2) . '%'; } ?>
                            <div class="cif-panel">
                                <?php if ($g['titulo'] !== ''): ?><h4><?= e($g['titulo']) ?></h4><?php endif; ?>
                                <div class="cif-dona">
                                    <div class="cif-dona-anillo" style="background:conic-gradient(<?= e(implode(',', $partes)) ?>)"></div>
                                    <ul><?php foreach ($g['items'] as $j => $i): ?><li><i style="background:<?= CIFRAS_SERIE[$j % count(CIFRAS_SERIE)] ?>"></i><span><?= e($i['nombre']) ?></span><b><?= round(max(0, (float) $i['valor']) / $suma * 100) ?> %</b></li><?php endforeach; ?></ul>
                                </div>
                            </div>
                        <?php elseif ($conLista): ?>
                            <div class="cif-panel">
                                <h4>Aliados vigentes</h4>
                                <?php if (!$s['lista']): ?>
                                    <p class="cif-vacio">Aún no hay alianzas vigentes. Regístralas en la pestaña <button type="button" data-cif-ir="registro">Registro de alianzas</button>.</p>
                                <?php else: ?>
                                    <div class="cif-aliados">
                                        <?php foreach (array_slice($s['lista'], 0, 12) as $a): ?>
                                            <div class="cif-aliado" title="<?= e($a['nombre'] . ' · ' . $a['pais']) ?>">
                                                <span class="cif-aliado-logo"><?php if ($a['logo']): ?><img src="<?= e($a['logo']) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($a['nombre'], 0, 2))) ?><?php endif; ?></span>
                                                <b><?= e($a['nombre']) ?></b><small><?= e($a['pais']) ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($s['lista']) > 12): ?><p class="cif-vacio">y <?= count($s['lista']) - 12 ?> aliados más.</p><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="cif-slide-pie">
                        <div class="cif-franja" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                        <button type="button" class="cif-copiar" data-cif-copiar="<?= e($texto($s)) ?>"><i class="ti ti-copy"></i> Copiar cifras</button>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if ($verCierre): ?>
                <section class="cif-slide cif-cierre<?= $cierre['oculta'] ? ' cif-oculta' : '' ?>" id="cif-cierre" role="tabpanel" aria-labelledby="cif-tab-cierre" data-cif-slide="cierre" hidden style="<?= e($fondo($cierre['foto'])) ?>">
                    <div class="cif-slide-top">
                        <span class="cif-marca"><img src="assets/img/cengicana-logo.png" alt="CENGICAÑA">en cifras <?= $anioC ?></span>
                        <span class="cif-top-der"><?= $cierre['oculta'] ? $aviso : '' ?><?= $botonEditar(['clave' => 'cierre', 'tipo' => 'cierre', 'nombre' => 'Cierre', 'titulo' => $cierre['titulo_personalizado'], 'titulo_original' => 'Trabajemos juntos por la agroindustria azucarera', 'mensaje' => $cierre['mensaje_personalizado'], 'oculta' => $cierre['oculta'], 'foto' => $fotoEditor($cierre['foto'])]) ?><span class="cif-pag"><?= sprintf('%02d / %02d', $total, $total) ?></span></span>
                    </div>
                    <div class="cif-cierre-cuerpo">
                        <h2><?= e($cierre['titulo']) ?></h2>
                        <p><?= e($cierre['mensaje']) ?></p>
                        <img src="assets/img/cengicana-logo.png" alt="CENGICAÑA">
                    </div>
                    <div class="cif-slide-pie"><div class="cif-franja" aria-hidden="true"><span></span><span></span><span></span><span></span></div></div>
                </section>
            <?php endif; ?>

            <section class="cif-registro" id="alianzas-registro" role="tabpanel" aria-labelledby="cif-tab-registro" data-cif-slide="registro" hidden>
                <div class="cif-ali-head">
                    <h3><i class="ti ti-list-details"></i> Registro de alianzas y convenios</h3>
                    <?php if ($gestor): ?><button type="button" class="btn-primary" data-cif-nueva><i class="ti ti-plus"></i> Nueva alianza</button><?php endif; ?>
                </div>
                <p class="cif-ayuda">Ingenios del extranjero, empresas tecnológicas (riego, drones…), universidades y organismos con los que CENGICAÑA colabora. Con el país se calculan los ingenios de Centroamérica y los convenios en el extranjero.</p>
                <?php if (!$alianzasTodas): ?>
                    <div class="empty-state"><i class="ti ti-heart-handshake"></i><p>Aún no hay alianzas registradas.</p></div>
                <?php else: ?>
                    <div class="table-scroll"><table class="cif-tabla">
                        <thead><tr><th></th><th>Institución</th><th>Tipo</th><th>País</th><th>Estado</th><th>Vigencia</th><th>Colaboración</th><?php if ($gestor): ?><th></th><?php endif; ?></tr></thead>
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
                                <?php if ($gestor): ?>
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

            <button type="button" class="cif-nav cif-prev" data-cif-paso="-1" aria-label="Diapositiva anterior"><i class="ti ti-chevron-left"></i></button>
            <button type="button" class="cif-nav cif-next" data-cif-paso="1" aria-label="Diapositiva siguiente"><i class="ti ti-chevron-right"></i></button>
        </div>
        <div class="cif-puntos" aria-hidden="true"></div>
        <div class="cif-aviso" role="status" aria-live="polite"></div>
    </div>
    <p class="cif-fuentes">Fuentes: Cengicursos (cursos, diplomas, eventos y participantes por ingenio), Comités (eventos de transferencia, comités, acuerdos, directorio, biblioteca, memorias, redes y sitio web), Visitas (solicitudes de visita aprobadas), Laboratorio (informes firmados y muestras) y Experimentos (ensayos y proyectos). La comparación usa los mismos cálculos del año anterior. Un “—” indica que el módulo no tiene datos disponibles.</p>
</div>

<script>
(() => {
    const pres = document.getElementById('cif-pres');
    const tabs = [...pres.querySelectorAll('[data-cif-tab]')];
    const slides = [...pres.querySelectorAll('[data-cif-slide]')];
    const orden = slides.map(s => s.dataset.cifSlide);
    const puntos = pres.querySelector('.cif-puntos');
    const reducir = matchMedia('(prefers-reduced-motion: reduce)').matches;
    let actual = null;

    orden.forEach(c => { const p = document.createElement('i'); p.dataset.c = c; puntos.append(p); });
    // Al presentar en pantalla completa se saltan las diapositivas ocultas y el registro.
    const visibles = () => pres.classList.contains('es-completa') ? orden.filter(c => c !== 'registro' && !pres.querySelector(`[data-cif-slide="${c}"]`).classList.contains('cif-oculta')) : orden;

    const contar = slide => {
        if (reducir) return;
        slide.querySelectorAll('[data-cif-num]').forEach(el => {
            const final = el.dataset.final ?? (el.dataset.final = el.textContent);
            if (!/^[\d,]+$/.test(final)) return; // textos como "85 %" o "4.6" no se animan
            const meta = parseInt(final.replace(/,/g, ''), 10);
            if (!meta) return;
            const t0 = performance.now(), dur = 900;
            const paso = t => {
                const p = Math.min(1, (t - t0) / dur), v = Math.round(meta * (1 - Math.pow(1 - p, 3)));
                el.textContent = p < 1 ? v.toLocaleString('en-US') : final;
                if (p < 1) requestAnimationFrame(paso);
            };
            requestAnimationFrame(paso);
        });
    };

    const mostrar = (clave, enfocar = false) => {
        if (!orden.includes(clave)) clave = 'portada';
        if (clave === actual) return;
        actual = clave;
        slides.forEach(s => { s.hidden = s.dataset.cifSlide !== clave; });
        tabs.forEach(t => {
            const sel = t.dataset.cifTab === clave;
            t.setAttribute('aria-selected', sel); t.tabIndex = sel ? 0 : -1;
            if (sel) { t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); if (enfocar) t.focus(); }
        });
        puntos.querySelectorAll('i').forEach(p => p.classList.toggle('on', p.dataset.c === clave));
        const lista = visibles(), i = lista.indexOf(clave);
        pres.querySelector('.cif-prev').disabled = i <= 0;
        pres.querySelector('.cif-next').disabled = i === lista.length - 1;
        pres.classList.toggle('es-registro', clave === 'registro');
        history.replaceState(null, '', '#' + (clave === 'registro' ? 'alianzas-registro' : clave));
        contar(slides[orden.indexOf(clave)]);
    };
    const mover = d => { const l = visibles(); mostrar(l[Math.max(0, Math.min(l.length - 1, l.indexOf(actual) + d))] ?? l[0], true); };

    tabs.forEach(t => t.addEventListener('click', () => mostrar(t.dataset.cifTab)));
    pres.querySelectorAll('[data-cif-ir]').forEach(b => b.addEventListener('click', () => mostrar(b.dataset.cifIr, true)));
    pres.querySelectorAll('[data-cif-paso]').forEach(b => b.addEventListener('click', () => mover(+b.dataset.cifPaso)));
    puntos.addEventListener('click', e => e.target.dataset.c && mostrar(e.target.dataset.c));

    document.addEventListener('keydown', e => {
        if (e.altKey || e.ctrlKey || e.metaKey || document.querySelector('dialog[open]')) return;
        if (e.target.closest('input, select, textarea, [contenteditable]')) return;
        if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); mover(1); }
        else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); mover(-1); }
        else if (e.key === 'Home' && e.target.closest('[role=tab]')) { e.preventDefault(); mostrar(orden[0], true); }
        else if (e.key === 'End' && e.target.closest('[role=tab]')) { e.preventDefault(); mostrar(orden.at(-1), true); }
    });

    // Deslizar en pantallas táctiles.
    let x0 = null;
    const escenario = pres.querySelector('.cif-stage');
    escenario.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
    escenario.addEventListener('touchend', e => {
        if (x0 === null || actual === 'registro') return;
        const dx = e.changedTouches[0].clientX - x0; x0 = null;
        if (Math.abs(dx) > 60) mover(dx < 0 ? 1 : -1);
    });

    // Pantalla completa.
    const btnPresentar = document.querySelector('[data-cif-presentar]');
    const alEntrar = () => { if (!visibles().includes(actual)) mostrar('portada'); else { const a = actual; actual = null; mostrar(a); } };
    btnPresentar.addEventListener('click', () => {
        if (document.fullscreenElement) return document.exitFullscreen();
        (pres.requestFullscreen?.() ?? Promise.reject()).catch(() => { pres.classList.add('es-completa'); alEntrar(); });
    });
    document.addEventListener('fullscreenchange', () => {
        const on = document.fullscreenElement === pres;
        pres.classList.toggle('es-completa', on);
        btnPresentar.innerHTML = on ? '<i class="ti ti-player-stop"></i> Salir' : '<i class="ti ti-player-play"></i> Presentar';
        const a = actual; actual = null; on ? alEntrar() : mostrar(a);
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !document.fullscreenElement && pres.classList.contains('es-completa')) { pres.classList.remove('es-completa'); const a = actual; actual = null; mostrar(a); } });

    // Copiar cifras en texto (con respaldo para conexiones sin HTTPS).
    const aviso = pres.querySelector('.cif-aviso');
    const avisar = msg => { aviso.textContent = msg; aviso.classList.add('on'); clearTimeout(avisar.t); avisar.t = setTimeout(() => aviso.classList.remove('on'), 2200); };
    const copiar = async txt => {
        try { await navigator.clipboard.writeText(txt); return true; } catch {
            const ta = Object.assign(document.createElement('textarea'), { value: txt });
            ta.style.cssText = 'position:fixed;opacity:0'; (document.fullscreenElement || document.body).append(ta); ta.select();
            const ok = document.execCommand('copy'); ta.remove(); return ok;
        }
    };
    document.querySelectorAll('[data-cif-copiar]').forEach(b => b.addEventListener('click', async () => {
        avisar(await copiar(b.dataset.cifCopiar) ? 'Cifras copiadas. Pégalas en tu correo, documento o presentación.' : 'No se pudo copiar. Selecciona el texto manualmente.');
    }));

    const inicial = location.hash.slice(1);
    mostrar(inicial === 'alianzas-registro' ? 'registro' : inicial);
})();
</script>

<?php if ($gestor): ?>
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

<!-- Elegir plantilla para una diapositiva nueva -->
<dialog class="cif-dialog cif-plantillas" id="cif-nueva-dialog" aria-labelledby="cif-nueva-titulo">
    <header><h3 id="cif-nueva-titulo">Nueva diapositiva</h3><button type="button" class="icon-btn" data-cif-cerrar aria-label="Cerrar"><i class="ti ti-x"></i></button></header>
    <div class="cif-plantillas-lista">
        <?php foreach (CIFRAS_PLANTILLAS as $pl => [$nombre, $icono, $desc]): ?>
            <button type="button" data-cif-plantilla="<?= e($pl) ?>"><i class="ti <?= e($icono) ?>"></i><b><?= e($nombre) ?></b><small><?= e($desc) ?></small></button>
        <?php endforeach; ?>
    </div>
</dialog>

<!-- Editor de diapositiva -->
<script type="application/json" id="cif-editor-datos"><?= json_encode(['diapos' => $listaDiapos, 'candidatos' => $candidatos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<dialog class="cif-dialog cif-editor" id="cif-diapo-dialog" aria-labelledby="cif-diapo-titulo">
    <form method="post" enctype="multipart/form-data">
        <header><h3 id="cif-diapo-titulo"><span data-accion>Editar diapositiva</span> · <span data-nombre></span></h3><button type="button" class="icon-btn" data-cif-cerrar aria-label="Cerrar"><i class="ti ti-x"></i></button></header>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['cifras_csrf']) ?>"><input type="hidden" name="accion" value="cifras_diapositiva"><input type="hidden" name="clave" value=""><input type="hidden" name="plantilla" value="">
        <div class="cif-campos">
            <fieldset class="cif-ancho">
                <legend><i class="ti ti-typography"></i> Textos</legend>
                <label>Título<input class="form-control" name="titulo" maxlength="120"><small class="cif-nota" data-nota-titulo>Déjalo vacío para usar el título original.</small></label>
                <label>Mensaje<textarea class="form-control" name="mensaje" rows="2" maxlength="300" placeholder="Frase breve que acompaña las cifras (opcional). Ej. «Más técnicos de ingenio capacitados que nunca»."></textarea></label>
                <label data-tipos="libre" data-plantillas="texto">Puntos clave <small class="cif-nota">(uno por línea, hasta 8)</small><textarea class="form-control" name="cuerpo" rows="5" maxlength="1500" placeholder="Ej.&#10;Nueva variedad CG 13-10 liberada&#10;12 ingenios participaron en los ensayos&#10;Rendimiento 8 % mayor que la testigo"></textarea></label>
                <label data-tipos="libre">Color<select class="form-control" name="color"><?php foreach (CIFRAS_COLORES as $hex => $nombre): ?><option value="<?= e($hex) ?>"><?= e($nombre) ?></option><?php endforeach; ?></select></label>
            </fieldset>

            <fieldset class="cif-ancho" data-tipos="libre" data-plantillas="grafico">
                <legend><i class="ti ti-chart-bar"></i> Gráfico</legend>
                <div class="cif-fila2">
                    <label>Tipo<select class="form-control" name="grafico_tipo"><?php foreach (CIFRAS_GRAFICOS as $k => $nombre): ?><option value="<?= e($k) ?>"><?= e($nombre) ?></option><?php endforeach; ?></select></label>
                    <label>Título del gráfico<input class="form-control" name="grafico_titulo" maxlength="80" placeholder="Ej. Hectáreas por zona"></label>
                </div>
                <label>Datos <small class="cif-nota">una fila por línea: «Etiqueta; valor». Puedes copiar dos columnas desde Excel y pegarlas aquí. Hasta 12 filas.</small>
                    <textarea class="form-control cif-mono" name="grafico_filas" rows="6" placeholder="Zona litoral; 1250&#10;Zona media; 980&#10;Zona alta; 430"></textarea></label>
            </fieldset>

            <fieldset class="cif-ancho" data-tipos="seccion libre">
                <legend><i class="ti ti-numbers"></i> Cifras que se muestran</legend>
                <div class="cif-checks cif-checks-metas" data-kpis></div>
                <p class="cif-nota" data-tipos="seccion">Meta: escribe el valor esperado para el año y la tarjeta mostrará el % de avance.</p>
                <p class="cif-nota">Cifras manuales: para datos que no están en el sistema. El valor puede llevar texto, como «1,200 ha» o «95 %».</p>
                <div class="cif-extras">
                    <?php for ($i = 0; $i < CIFRAS_EXTRAS_MAX_LIBRE; $i++): ?>
                        <div class="cif-extra" data-extra="<?= $i ?>">
                            <input class="form-control" name="extra_valor[<?= $i ?>]" maxlength="20" placeholder="Valor" aria-label="Valor de la cifra manual <?= $i + 1 ?>">
                            <input class="form-control" name="extra_etiqueta[<?= $i ?>]" maxlength="80" placeholder="Descripción" aria-label="Descripción de la cifra manual <?= $i + 1 ?>">
                            <input class="form-control" name="extra_detalle[<?= $i ?>]" maxlength="120" placeholder="Detalle (opcional)" aria-label="Detalle de la cifra manual <?= $i + 1 ?>">
                        </div>
                    <?php endfor; ?>
                </div>
            </fieldset>

            <fieldset class="cif-ancho" data-tipos="portada evolucion">
                <legend><i class="ti ti-star"></i> <span data-titulo-elegir>Cifras</span> <small data-maximo></small></legend>
                <div class="cif-checks cif-checks-largo" data-candidatos></div>
            </fieldset>

            <fieldset class="cif-ancho">
                <legend><i class="ti ti-photo"></i> Foto de fondo</legend>
                <div class="cif-foto-marco"><span>Así se verá detrás de las cifras</span></div>
                <label>Nueva foto (JPG o PNG)
                    <input class="form-control" type="file" name="foto" accept="image/jpeg,image/png">
                    <small class="cif-nota" data-estado>Usa una foto horizontal, como las del dron. Se reduce sola a 1920 px antes de subirla.</small>
                </label>
                <label>Encuadre vertical
                    <input type="range" name="pos" min="0" max="100" step="1">
                    <span class="cif-rango"><span>Arriba</span><output></output><span>Abajo</span></span>
                </label>
                <label class="cif-check" data-restaurar-foto hidden><input type="checkbox" name="restaurar_foto" value="1"> Quitar la foto subida y volver a la original</label>
            </fieldset>

            <fieldset class="cif-ancho" data-tipos="seccion evolucion libre cierre">
                <legend><i class="ti ti-arrows-sort"></i> Posición y visibilidad</legend>
                <label data-tipos="seccion evolucion libre">Ubicar<select class="form-control" name="despues"></select></label>
                <label class="cif-check"><input type="checkbox" name="oculta" value="1"> Ocultar esta diapositiva al presentar y en PDF/PowerPoint</label>
            </fieldset>
        </div>
        <footer>
            <button type="submit" class="btn-outline cif-restaurar" name="restaurar_todo" value="1" formnovalidate data-solo-fijas onclick="return confirm('¿Volver esta diapositiva a su versión original? Se quitan los textos, cifras manuales, metas y la foto que hayas cambiado.')"><i class="ti ti-restore"></i> Restaurar original</button>
            <button type="submit" class="btn-outline cif-eliminar" name="eliminar" value="1" formnovalidate data-solo-libres onclick="return confirm('¿Eliminar esta diapositiva? No se puede deshacer.')"><i class="ti ti-trash"></i> Eliminar</button>
            <span class="cif-footer-sep"></span>
            <button type="button" class="btn-outline" data-cif-cerrar>Cancelar</button>
            <button type="submit" class="btn-primary" data-guardar><i class="ti ti-device-floppy"></i> Guardar</button>
        </footer>
    </form>
</dialog>
<script>
(() => {
    const datos = JSON.parse(document.getElementById('cif-editor-datos').textContent);
    const dlg = document.getElementById('cif-diapo-dialog'), form = dlg.querySelector('form');
    const marco = dlg.querySelector('.cif-foto-marco'), salida = dlg.querySelector('output'), estado = dlg.querySelector('[data-estado]');
    const guardar = dlg.querySelector('[data-guardar]'), notaBase = estado.textContent;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const FOTO_NUEVA = <?= json_encode(cifras_fotos()['portada']['url'], JSON_UNESCAPED_SLASHES) ?>;
    let slide = null, original = null, urlTemporal = null, enviado = false, maxElegir = 4;

    const pintar = (url, pos) => {
        if (url) { marco.style.setProperty('--f', `url("${url}")`); slide?.style.setProperty('--cif-foto', `url("${url}")`); }
        marco.style.setProperty('--p', pos + '%'); slide?.style.setProperty('--cif-foto-pos', `center ${pos}%`);
        salida.textContent = pos + ' %';
    };
    const limpiarTemporal = () => { if (urlTemporal) { URL.revokeObjectURL(urlTemporal); urlTemporal = null; } };
    const limiteElegir = () => {
        const marcados = dlg.querySelectorAll('[data-elegir]:checked').length;
        dlg.querySelectorAll('[data-elegir]').forEach(c => { c.disabled = !c.checked && marcados >= maxElegir; });
    };
    // Muestra solo los campos que aplican al tipo de diapositiva (y desactiva los demás para no enviarlos).
    const ajustarCampos = (tipo, plantilla) => {
        dlg.querySelectorAll('[data-tipos]').forEach(el => {
            const ok = el.dataset.tipos.split(' ').includes(tipo) && (!el.dataset.plantillas || el.dataset.plantillas.split(' ').includes(plantilla));
            el.hidden = !ok;
            el.querySelectorAll('input, select, textarea').forEach(i => { i.disabled = !ok; });
            if (el.matches('input, select, textarea')) el.disabled = !ok;
        });
        const maxExtras = tipo === 'libre' ? <?= CIFRAS_EXTRAS_MAX_LIBRE ?> : <?= CIFRAS_EXTRAS_MAX ?>;
        dlg.querySelectorAll('[data-extra]').forEach(f => { const ok = +f.dataset.extra < maxExtras && (tipo === 'seccion' || tipo === 'libre'); f.hidden = !ok; f.querySelectorAll('input').forEach(i => { i.disabled = !ok; }); });
        dlg.querySelector('[data-solo-fijas]').hidden = tipo === 'libre';
        dlg.querySelector('[data-solo-libres]').hidden = tipo !== 'libre' || form.elements.clave.value === 'nueva';
    };

    const abrir = d => {
        form.reset(); enviado = false; limpiarTemporal();
        const nueva = d.clave === 'nueva';
        slide = nueva ? null : document.getElementById('cif-' + d.clave);
        original = slide ? { foto: slide.style.getPropertyValue('--cif-foto'), pos: slide.style.getPropertyValue('--cif-foto-pos') } : null;
        form.elements.clave.value = d.clave;
        form.elements.plantilla.value = d.plantilla || '';
        dlg.querySelector('[data-accion]').textContent = nueva ? 'Nueva diapositiva' : 'Editar diapositiva';
        dlg.querySelector('[data-nombre]').textContent = d.nombre;
        ajustarCampos(d.tipo, d.plantilla || '');
        form.elements.titulo.value = d.titulo || '';
        form.elements.titulo.placeholder = d.titulo_original || '';
        form.elements.titulo.required = d.tipo === 'libre';
        dlg.querySelector('[data-nota-titulo]').hidden = d.tipo === 'libre';
        form.elements.mensaje.value = d.mensaje || '';
        form.elements.oculta.checked = !!d.oculta;
        form.elements.cuerpo.value = d.cuerpo || '';
        form.elements.color.value = d.color || '#73BC25';
        form.elements.grafico_tipo.value = d.grafico?.tipo || 'barras';
        form.elements.grafico_titulo.value = d.grafico?.titulo || '';
        form.elements.grafico_filas.value = d.grafico?.filas || '';

        // Cifras automáticas (marcadas = visibles) con su meta, y cifras manuales.
        dlg.querySelector('[data-kpis]').innerHTML = (d.kpis || []).map(k =>
            `<div class="cif-kpi-fila"><label class="cif-check"><input type="hidden" name="kpis_todos[]" value="${esc(k.id)}"><input type="checkbox" name="kpis_visibles[]" value="${esc(k.id)}" ${k.visible ? 'checked' : ''}> <b>${esc(k.valor)}</b> ${esc(k.etiqueta)}</label>`
            + `<input class="form-control" name="meta[${esc(k.id)}]" value="${esc(k.meta)}" inputmode="decimal" placeholder="Meta" aria-label="Meta para ${esc(k.etiqueta)}"></div>`).join('');
        (d.extras || []).forEach((x, i) => {
            if (!form.elements[`extra_valor[${i}]`]) return;
            form.elements[`extra_valor[${i}]`].value = x.valor || '';
            form.elements[`extra_etiqueta[${i}]`].value = x.etiqueta || '';
            form.elements[`extra_detalle[${i}]`].value = x.detalle || '';
        });

        // Portada (hasta 4 destacadas) o Crecimiento (hasta 6 cifras a comparar).
        const esPortada = d.tipo === 'portada';
        maxElegir = esPortada ? 4 : 6;
        dlg.querySelector('[data-titulo-elegir]').textContent = esPortada ? 'Cifras destacadas en la portada' : 'Cifras para comparar entre años';
        dlg.querySelector('[data-maximo]').textContent = `(máximo ${maxElegir})`;
        let grupo = '';
        dlg.querySelector('[data-candidatos]').innerHTML = (d.tipo === 'portada' || d.tipo === 'evolucion') ? datos.candidatos.map(k => {
            const titulo = k.seccion !== grupo ? `<span class="cif-grupo">${esc(grupo = k.seccion)}</span>` : '';
            return titulo + `<label class="cif-check"><input type="checkbox" data-elegir name="${esPortada ? 'destacados[]' : 'evolucion_kpis[]'}" value="${esc(k.id)}" ${(d.elegidos || []).includes(k.id) ? 'checked' : ''}> <b>${esc(k.valor)}</b> ${esc(k.etiqueta)}</label>`;
        }).join('') : '';
        limiteElegir();

        // Posición: después de qué diapositiva va.
        const sel = form.elements.despues;
        sel.innerHTML = (nueva ? '' : '<option value="">Sin cambios</option>') + (d.tipo !== 'libre' && !nueva ? '<option value="_original">Posición original</option>' : '')
            + '<option value="_inicio">Al inicio (después de la portada)</option>'
            + datos.diapos.filter(([c]) => c !== d.clave).map(([c, n]) => `<option value="${esc(c)}">Después de «${esc(n)}»</option>`).join('');
        if (nueva) sel.value = datos.diapos.at(-1)?.[0] || '_inicio';

        const foto = d.foto || { url: FOTO_NUEVA, pos: 50, propia: false };
        form.elements.pos.value = foto.pos;
        dlg.querySelector('[data-restaurar-foto]').hidden = !foto.propia;
        form.elements.foto.disabled = false;
        estado.textContent = notaBase; estado.classList.remove('error');
        marco.classList.remove('apagado');
        guardar.disabled = false; guardar.innerHTML = '<i class="ti ti-device-floppy"></i> ' + (nueva ? 'Crear diapositiva' : 'Guardar');
        pintar(foto.url, foto.pos);
        dlg.showModal();
    };

    document.querySelectorAll('[data-cif-editar-diapo]').forEach(b => b.addEventListener('click', () => abrir(JSON.parse(b.dataset.cifEditarDiapo))));

    // "+ Nueva": elegir plantilla y abrir el editor vacío.
    const elegirDlg = document.getElementById('cif-nueva-dialog');
    document.querySelector('[data-cif-nueva-diapo]')?.addEventListener('click', () => elegirDlg.showModal());
    elegirDlg.querySelector('[data-cif-cerrar]').addEventListener('click', () => elegirDlg.close());
    elegirDlg.querySelectorAll('[data-cif-plantilla]').forEach(b => b.addEventListener('click', () => {
        elegirDlg.close();
        const pl = b.dataset.cifPlantilla, nombre = b.querySelector('b').textContent;
        abrir({ clave: 'nueva', tipo: 'libre', plantilla: pl, nombre, titulo: '', titulo_original: 'Título de la diapositiva', grafico: pl === 'grafico' ? { tipo: 'barras', titulo: '', filas: '' } : null });
    }));

    dlg.addEventListener('change', e => { if (e.target.matches('[data-elegir]')) limiteElegir(); });
    form.elements.pos.addEventListener('input', e => pintar(null, e.target.value));

    // Reducir en el navegador: las fotos del dron (8000 px, ~30 MB) superan el límite de subida.
    form.elements.foto.addEventListener('change', async e => {
        const archivo = e.target.files[0];
        if (!archivo) return;
        guardar.disabled = true; estado.classList.remove('error'); estado.textContent = 'Preparando la foto…';
        try {
            const bmp = await createImageBitmap(archivo);
            if (bmp.width < 800) throw new Error('La foto es muy pequeña: usa una de al menos 800 px de ancho.');
            const k = Math.min(1, 1920 / bmp.width);
            const lienzo = Object.assign(document.createElement('canvas'), { width: Math.round(bmp.width * k), height: Math.round(bmp.height * k) });
            lienzo.getContext('2d').drawImage(bmp, 0, 0, lienzo.width, lienzo.height);
            const blob = await new Promise(r => lienzo.toBlob(r, 'image/jpeg', 0.85));
            const dt = new DataTransfer();
            dt.items.add(new File([blob], 'foto.jpg', { type: 'image/jpeg' }));
            e.target.files = dt.files;
            limpiarTemporal(); urlTemporal = URL.createObjectURL(blob);
            pintar(urlTemporal, form.elements.pos.value);
            estado.textContent = `Lista: ${lienzo.width} × ${lienzo.height} px, ${(blob.size / 1048576).toFixed(1)} MB. Ajusta el encuadre y guarda.`;
        } catch (err) {
            e.target.value = '';
            estado.textContent = err.message?.startsWith('La foto') ? err.message : 'No se pudo leer la foto. Usa un archivo JPG o PNG.';
            estado.classList.add('error');
        } finally { guardar.disabled = false; }
    });

    form.elements.restaurar_foto.addEventListener('change', e => {
        form.elements.foto.disabled = e.target.checked;
        marco.classList.toggle('apagado', e.target.checked);
    });
    form.addEventListener('submit', () => { enviado = true; setTimeout(() => { guardar.disabled = true; guardar.innerHTML = '<i class="ti ti-loader-2"></i> Guardando…'; }); });
    dlg.querySelectorAll('[data-cif-cerrar]').forEach(b => b.addEventListener('click', () => dlg.close()));
    dlg.addEventListener('close', () => {
        if (enviado || !slide) return;
        slide.style.setProperty('--cif-foto', original.foto); slide.style.setProperty('--cif-foto-pos', original.pos);
        limpiarTemporal();
    });
})();
</script>
<?php endif; ?>
