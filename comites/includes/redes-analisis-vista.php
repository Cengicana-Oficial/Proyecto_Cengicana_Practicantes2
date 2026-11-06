<?php
// Pestana "¿Que funciona?" de Redes sociales. Datos en $analisis (redes_analisis()),
// $periodoAna (estadisticas_periodo()) y $filtroPlataformaAna.
$a = $analisis;
$plataformasRedes = redes_plataformas();
$corto = static fn(float $v): string => $a['usa_tasa'] ? number_format($v, 1) . '%' : number_format($v, 1);
$tendencia = static fn(float $indice): string => ($indice >= 1.15 ? '▲ ' : ($indice <= 0.85 ? '▼ ' : '')) . redes_veces($indice);
$items = static function (array $grupos, callable $etiqueta) use ($a, $corto, $tendencia): array {
    $res = [];
    foreach ($grupos as $clave => $g) {
        $res[] = [
            'html' => $etiqueta($clave),
            'valor' => $g['valor'],
            'texto' => $corto($g['valor']),
            'detalle' => $g['n'] . ' pub. · ' . $tendencia($g['indice']),
            'atenuado' => $g['poca_muestra'],
            'nota' => $g['n'] . ' publicaciones · ' . estadisticas_num($g['interacciones']) . ' interacciones · ' . number_format($g['promedio'], 1) . ' por publicación'
                . ($g['poca_muestra'] ? ' · pocos datos, tómelo como referencia' : ''),
        ];
    }
    return $res;
};
$metricaNombre = $a['usa_tasa'] ? 'Tasa de interacción' : 'Interacciones por publicación';
$refEtiqueta = 'Promedio general: ' . $corto($a['global']) . ' · las barras tenues tienen menos de ' . REDES_MIN_MUESTRA . ' publicaciones';
$chip = static function (string $nombre) use ($plataformasRedes): string {
    $p = $plataformasRedes[$nombre] ?? $plataformasRedes['Otro'];
    return '<i class="ti ' . e($p['icon']) . '" style="color:' . e($p['color']) . '"></i> ' . e($nombre);
};
$tablaPosts = static function (array $posts) use ($plataformasRedes): string {
    $html = '<ol class="est-top">';
    foreach ($posts as $p) {
        $titulo = redes_texto_publicacion($p) ?: (string) $p['titulo'];
        $cfg = $plataformasRedes[$p['plataforma']] ?? $plataformasRedes['Otro'];
        $html .= '<li><i class="ti ' . e($cfg['icon']) . '" style="color:' . e($cfg['color']) . '" title="' . e((string) $p['plataforma']) . '"></i><span class="est-top-texto">'
            . (!empty($p['url']) ? '<a href="' . e((string) $p['url']) . '" target="_blank" rel="noopener noreferrer">' . e(mb_strimwidth($titulo, 0, 110, '…')) . '</a>' : '<strong>' . e(mb_strimwidth($titulo, 0, 110, '…')) . '</strong>')
            . '<small>' . ($p['fecha'] ? e(date('d/m/Y', strtotime((string) $p['fecha']))) . ' · ' . e(REDES_DIAS[$p['dia']] ?? '') : 'Sin fecha') . ' · ' . e($p['tipo_n']) . '</small></span>'
            . '<span class="est-top-num"><strong>' . number_format((float) $p['tasa'], 1) . '%</strong><small>' . estadisticas_num($p['inter']) . ' de ' . estadisticas_num($p['base']) . '</small></span></li>';
    }
    return $html . '</ol>';
};
$totalComp = max(1, array_sum($a['composicion']));

/** Tabla con las cifras exactas de cada grupo (desplegable bajo cada grafico). */
$tablaGrupos = static function (array $grupos, callable $etiqueta) use ($a): string {
    if (!$grupos) {
        return '';
    }
    $h = '<details class="ana-cifras"><summary><i class="ti ti-table"></i> Ver cifras</summary><div class="table-wrap"><table class="ana-tabla"><thead><tr>'
        . '<th></th><th class="num">Publ.</th><th class="num">Alcance prom.</th><th class="num">Interac. prom.</th><th class="num">Tasa</th><th class="num">vs. prom.</th><th class="num">Clics/100</th><th class="num">% compartidos</th><th class="num">% comentarios</th><th>Mejor publicación</th></tr></thead><tbody>';
    foreach ($grupos as $k => $g) {
        $h .= '<tr class="' . ($g['poca_muestra'] ? 'is-poca' : '') . '"><td>' . $etiqueta($k) . '</td><td class="num">' . $g['n'] . '</td>'
            . '<td class="num">' . estadisticas_num($g['alcance_promedio']) . '</td><td class="num">' . number_format($g['promedio'], 1) . '</td>'
            . '<td class="num"><b>' . number_format($g['tasa'], 1) . '%</b></td>'
            . '<td class="num ' . ($g['indice'] >= 1.15 ? 'txt-up' : ($g['indice'] <= 0.85 ? 'txt-down' : '')) . '">' . redes_veces($g['indice']) . '</td>'
            . '<td class="num">' . number_format($g['ctr'], 1) . '</td><td class="num">' . number_format($g['pct_compartidos'], 0) . '%</td><td class="num">' . number_format($g['pct_comentarios'], 0) . '%</td>'
            . '<td class="ana-mejor">' . ($g['mejor'] ? '<a href="index.php?view=publicaciones&tab=publicaciones&ficha=' . (int) $g['mejor']['id'] . '">' . e($g['mejor']['titulo']) . '</a> <small>' . number_format((float) $g['mejor']['tasa'], 1) . '%</small>' : '—') . '</td></tr>';
    }
    return $h . '</tbody></table></div><p class="ana-nota">Filas tenues: menos de ' . REDES_MIN_MUESTRA . ' publicaciones, tómelas como referencia.</p></details>';
};

/** Mejor grupo con muestra suficiente (o el mejor disponible, marcado como pocos datos). */
$mejorGrupo = static function (array $grupos, array $excluir = []): ?array {
    $grupos = array_diff_key($grupos, array_flip($excluir));
    if (!$grupos) {
        return null;
    }
    $solidos = array_filter($grupos, static fn($g) => !$g['poca_muestra']);
    $base = $solidos ?: $grupos;
    uasort($base, static fn($x, $y) => $y['valor'] <=> $x['valor']);
    $k = array_key_first($base);
    return ['clave' => $k, 'g' => $base[$k], 'poca' => !$solidos];
};
$enlaceFicha = static fn(array $p): string => 'index.php?view=publicaciones&tab=publicaciones&ficha=' . (int) $p['id'];

/** Color del mapa de calor (un solo tono, claro a oscuro) segun la tasa relativa al maximo. */
$colorCelda = static function (float $tasa, float $max): string {
    $pct = $max > 0 ? max(8, min(100, $tasa / $max * 100)) : 8;
    return 'background:color-mix(in srgb, #3d6614 ' . round($pct * 0.85) . '%, #f6fbf0);color:' . ($pct > 55 ? '#fff' : 'var(--texto)');
};
?>
<link rel="stylesheet" href="assets/css/estadisticas.css?v=<?= @filemtime(__DIR__ . '/../assets/css/estadisticas.css') ?>">

<div class="est-page ana-page">
    <div class="est-toolbar">
        <form class="est-periodo" method="get" id="est-periodo">
            <input type="hidden" name="view" value="publicaciones">
            <input type="hidden" name="tab" value="analisis">
            <label><span>Periodo</span>
                <select class="form-control" name="periodo" id="est-periodo-sel">
                    <?php foreach (ESTADISTICAS_PERIODOS as $clave => $etiqueta): ?>
                        <option value="<?= e($clave) ?>" <?= $periodoAna['clave'] === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label><span>Plataforma</span>
                <select class="form-control" name="plataforma" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php foreach (array_keys($plataformasRedes) as $opt): ?><option value="<?= e($opt) ?>" <?= $filtroPlataformaAna === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
                </select></label>
            <span class="est-rango" id="est-rango" <?= $periodoAna['clave'] === 'rango' ? '' : 'hidden' ?>>
                <label><span>Desde</span><input class="form-control" type="month" name="desde" value="<?= e($periodoAna['desde_mes']) ?>"></label>
                <label><span>Hasta</span><input class="form-control" type="month" name="hasta" value="<?= e($periodoAna['hasta_mes']) ?>"></label>
                <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Aplicar</button>
            </span>
            <span class="est-periodo-etiqueta"><i class="ti ti-calendar"></i> <?= e($periodoAna['etiqueta']) ?></span>
        </form>
        <div class="est-descargas">
            <a class="btn-outline" href="<?= e(estadisticas_query($periodoAna, ['exportar' => 'xlsx'])) ?>"><i class="ti ti-file-spreadsheet"></i> Excel</a>
            <a class="btn-primary" href="<?= e(estadisticas_query($periodoAna, ['exportar' => 'pdf'])) ?>"><i class="ti ti-file-type-pdf"></i> Reporte PDF</a>
        </div>
    </div>

    <p class="ana-intro"><i class="ti ti-info-circle"></i><span>
        Se analizan <b><?= $a['n'] ?> publicaciones</b> publicadas con métricas<?= $filtroPlataformaAna !== '' ? ' en ' . e($filtroPlataformaAna) : '' ?>.
        <?php if ($a['usa_tasa']): ?>La comparación usa la <b>tasa de interacción</b>: de cada 100 personas que vieron la publicación, cuántas reaccionaron, comentaron, compartieron o guardaron. Así se compara de forma justa una publicación con mucho alcance y otra con poco.
        <?php else: ?>Aún no hay alcance registrado, así que se compara por <b>interacciones por publicación</b>.<?php endif; ?></span>
    </p>

    <section class="card est-card ana-recos">
        <div class="card-title"><i class="ti ti-bulb"></i> Qué nos dicen los datos</div>
        <ul>
            <?php foreach ($a['recomendaciones'] as $r): ?>
                <li class="is-<?= e($r['tono']) ?>"><i class="ti <?= e($r['icono']) ?>"></i><span><?= $r['html'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="est-kpis">
        <article class="est-kpi"><span class="est-kpi-head"><i class="ti ti-list-check"></i> Publicaciones analizadas</span><strong><?= estadisticas_num($a['n']) ?></strong><span class="est-kpi-nota"><?= estadisticas_num($a['alcance']) ?> personas alcanzadas</span></article>
        <article class="est-kpi"><span class="est-kpi-head"><i class="ti ti-heart-handshake"></i> Tasa de interacción</span><strong><?= $a['alcance'] ? number_format($a['interacciones'] / $a['alcance'] * 100, 1) . '%' : '—' ?></strong><span class="est-kpi-nota">De cada 100 personas alcanzadas, <?= $a['alcance'] ? number_format($a['interacciones'] / $a['alcance'] * 100, 1) : '0' ?> interactúan</span></article>
        <article class="est-kpi"><span class="est-kpi-head"><i class="ti ti-thumb-up"></i> Interacciones por publicación</span><strong><?= number_format($a['promedio'], 1) ?></strong><span class="est-kpi-nota"><?= estadisticas_num($a['interacciones']) ?> en total</span></article>
        <article class="est-kpi"><span class="est-kpi-head"><i class="ti ti-click"></i> Clics por cada 100 personas</span><strong><?= number_format($a['ctr'], 1) ?></strong><span class="est-kpi-nota"><?= estadisticas_num($a['clics']) ?> clics en enlaces</span></article>
        <article class="est-kpi"><span class="est-kpi-head"><i class="ti ti-message-heart"></i> Interacciones de valor</span><strong><?= number_format($a['valor_pct'], 0) ?>%</strong><span class="est-kpi-nota" title="Comentarios, compartidos y guardados: requieren más interés que una reacción">Comentarios, compartidos y guardados</span></article>
    </section>

    <?php if ($a['n'] === 0): ?>
        <div class="card est-card"><div class="empty-state"><i class="ti ti-chart-dots-3"></i>No hay publicaciones publicadas con métricas en este periodo. Registre las métricas, importe el archivo de cada red o conecte las cuentas en «Conexiones».</div></div>
    <?php else: ?>
        <?php
        $d = $a['distribucion'];
        $mTema = $mejorGrupo($a['pilar'], ['Sin clasificar']);
        $mFormato = $mejorGrupo($a['tipo'], ['Sin tipo']);
        $mDia = $mejorGrupo($a['dia']);
        $mLargo = $mejorGrupo($a['longitud']);
        $fichaDato = static function (string $icono, string $titulo, string $valor, string $detalle, ?string $href = null, bool $poca = false): string {
            return '<' . ($href ? 'a href="' . e($href) . '"' : 'div') . ' class="ana-dato' . ($poca ? ' is-poca' : '') . '"><span class="ana-dato-head"><i class="ti ' . $icono . '"></i> ' . e($titulo) . '</span>'
                . '<strong>' . $valor . '</strong><small>' . $detalle . ($poca ? ' · <em>pocos datos</em>' : '') . '</small></' . ($href ? 'a' : 'div') . '>';
        };
        ?>
        <h2 class="est-seccion"><i class="ti ti-pin"></i> Datos clave del periodo</h2>
        <section class="ana-datos">
            <?php if ($a['mejor_post']): $mp = $a['mejor_post']; ?>
                <?= $fichaDato('ti-trophy', 'Publicación con mejor respuesta', e(mb_strimwidth(redes_titulo_corto($mp), 0, 70, '…')),
                    '<b>' . number_format((float) $mp['tasa'], 1) . '%</b> de tasa · ' . estadisticas_num($mp['inter']) . ' interacciones · ' . e((string) $mp['plataforma']) . ($mp['fecha'] ? ' · ' . e(date('d/m/Y', strtotime((string) $mp['fecha']))) : ''), $enlaceFicha($mp)) ?>
            <?php endif; ?>
            <?php if ($a['peor_post']): $pp = $a['peor_post']; ?>
                <?= $fichaDato('ti-zoom-question', 'Publicación con menor respuesta', e(mb_strimwidth(redes_titulo_corto($pp), 0, 70, '…')),
                    '<b>' . number_format((float) $pp['tasa'], 1) . '%</b> de tasa · ' . estadisticas_num($pp['inter']) . ' interacciones · ' . e((string) $pp['plataforma']), $enlaceFicha($pp)) ?>
            <?php endif; ?>
            <?php if ($d['mediana'] !== null): ?>
                <?= $fichaDato('ti-chart-dots', 'Tasa típica de la cuenta', number_format($d['mediana'], 1) . '%', 'la mitad de las publicaciones está entre <b>' . number_format((float) $d['p25'], 1) . '%</b> y <b>' . number_format((float) $d['p75'], 1) . '%</b>') ?>
                <?= $fichaDato('ti-target-arrow', 'Meta para destacar', '≥ ' . number_format((float) $d['p75'], 1) . '%', 'tasa que alcanza el 25% mejor de sus publicaciones') ?>
            <?php endif; ?>
            <?php if ($mTema): ?>
                <?= $fichaDato('ti-category-2', 'Tema con mejor respuesta', e((string) $mTema['clave']), '<b>' . number_format($mTema['g']['tasa'], 1) . '%</b> de tasa · ' . redes_veces($mTema['g']['indice']) . ' el promedio · ' . $mTema['g']['n'] . ' publ.', null, $mTema['poca']) ?>
            <?php endif; ?>
            <?php if ($mFormato): ?>
                <?= $fichaDato('ti-photo-video', 'Formato con mejor respuesta', e((string) $mFormato['clave']), '<b>' . number_format($mFormato['g']['tasa'], 1) . '%</b> de tasa · ' . $mFormato['g']['n'] . ' publ.', null, $mFormato['poca']) ?>
            <?php endif; ?>
            <?php if ($mDia): ?>
                <?= $fichaDato('ti-calendar-star', 'Mejor día para publicar', e(REDES_DIAS[$mDia['clave']]), '<b>' . number_format($mDia['g']['tasa'], 1) . '%</b> de tasa · ' . $mDia['g']['n'] . ' publ.', null, $mDia['poca']) ?>
            <?php endif; ?>
            <?php if ($mLargo): ?>
                <?= $fichaDato('ti-text-size', 'Largo de texto que mejor funciona', e(strtok(REDES_LONGITUDES[$mLargo['clave']], ' ')), e(substr(REDES_LONGITUDES[$mLargo['clave']], strpos(REDES_LONGITUDES[$mLargo['clave']], '('))) . ' · <b>' . number_format($mLargo['g']['tasa'], 1) . '%</b>', null, $mLargo['poca']) ?>
            <?php endif; ?>
            <?= $fichaDato('ti-calendar-repeat', 'Ritmo de publicación', number_format($a['por_semana'], 1) . ' por semana', $a['n'] . ' publicaciones en el periodo analizado') ?>
            <?= $fichaDato('ti-share-3', 'De cada 100 interacciones', number_format($a['composicion']['Compartidos'] / $totalComp * 100, 0) . ' compartidos', number_format($a['composicion']['Comentarios'] / $totalComp * 100, 0) . ' comentarios · ' . number_format($a['composicion']['Reacciones'] / $totalComp * 100, 0) . ' reacciones') ?>
        </section>

        <?php if ($d['n'] >= 3): $maxH = max(1, ...array_column($d['histograma'], 'n')); ?>
            <div class="est-grid est-grid-2-1">
                <div class="card est-card">
                    <div class="card-title"><i class="ti ti-chart-histogram"></i> ¿Cómo se reparten sus publicaciones por tasa de interacción?</div>
                    <div class="ana-histo" style="--n:<?= count($d['histograma']) ?>">
                        <?php foreach ($d['histograma'] as $b):
                            $esMediana = $d['mediana'] >= $b['desde'] && ($b['hasta'] === null || $d['mediana'] < $b['hasta']); ?>
                            <div class="ana-histo-col <?= $esMediana ? 'is-mediana' : '' ?>" title="<?= e($b['etiqueta']) ?>: <?= $b['n'] ?> publicación(es)">
                                <span class="ana-histo-area"><?php if ($b['n']): ?><b><?= $b['n'] ?></b><?php endif; ?><span style="height:<?= round($b['n'] / $maxH * 100, 1) ?>%"></span></span>
                                <small><?= e($b['etiqueta']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="ana-nota">Barra oscura: donde cae la tasa típica (mediana <?= number_format($d['mediana'], 1) ?>%). Rango observado: <?= number_format((float) $d['min'], 1) ?>% a <?= number_format((float) $d['max'], 1) ?>%.</p>
                </div>
                <div class="card est-card ana-lectura">
                    <div class="card-title"><i class="ti ti-book"></i> Cómo leer estos datos</div>
                    <ul>
                        <li><b>Tasa de interacción:</b> de cada 100 personas que vieron la publicación, cuántas reaccionaron, comentaron, compartieron o guardaron.</li>
                        <li><b>Tasa típica:</b> la mediana; no la mueven unas pocas publicaciones extraordinarias.</li>
                        <li><b>vs. promedio:</b> 1.5× significa 50% más que el promedio de la cuenta.</li>
                        <li><b>Pocos datos:</b> grupos con menos de <?= REDES_MIN_MUESTRA ?> publicaciones; úselos como pista, no como conclusión.</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($a['pauta']['solo_organico']): ?>
            <p class="ana-intro"><i class="ti ti-filter"></i><span>Para juzgar el <b>contenido</b> se comparan solo las <b><?= $a['n'] ?> publicaciones orgánicas</b> (de <?= $a['pauta']['n_total'] ?>): la pauta aumenta el alcance y bajaría la tasa de las publicaciones pagadas sin que el contenido sea peor. La pauta se analiza aparte, más abajo.</span></p>
        <?php endif; ?>
        <h2 class="est-seccion"><i class="ti ti-category-2"></i> Qué temas interesan más</h2>
        <div class="est-grid est-grid-2-1">
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-category-2"></i> <?= e($metricaNombre) ?> por pilar de contenido</div>
                <?= estadisticas_barras_html($items($a['pilar'], static fn($k) => e((string) $k)), 'Sin datos.', $a['global'], $refEtiqueta) ?>
                <?php if ($a['pilares_sugeridos']): ?><p class="ana-nota"><i class="ti ti-wand"></i> <?= $a['pilares_sugeridos'] ?> publicación(es) se clasificaron automáticamente por las palabras de su texto. Asigne el pilar al editar cada publicación para mayor precisión.</p><?php endif; ?>
                <?= $tablaGrupos($a['pilar'], static fn($k) => e((string) $k)) ?>
            </div>
            <div class="card est-card ana-pilares-guia">
                <div class="card-title"><i class="ti ti-star"></i> La mejor publicación de cada tema</div>
                <ul class="ana-mejores">
                    <?php foreach ($a['pilar'] as $pil => $g): if (!$g['mejor'] || $pil === 'Sin clasificar') { continue; } ?>
                        <li>
                            <span class="ana-mejores-tema"><?= e((string) $pil) ?></span>
                            <a href="index.php?view=publicaciones&tab=publicaciones&ficha=<?= (int) $g['mejor']['id'] ?>"><?= e($g['mejor']['titulo']) ?></a>
                            <small><b><?= number_format((float) $g['mejor']['tasa'], 1) ?>%</b> de tasa · <?= e((string) $g['mejor']['plataforma']) ?> · tema con <?= $g['n'] ?> publ.</small>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <details class="ana-cifras"><summary><i class="ti ti-info-circle"></i> ¿Qué incluye cada tema?</summary>
                    <ul class="ana-guia">
                        <li><b>Capacitación y eventos:</b> diplomados, cursos, días de campo.</li>
                        <li><b>Investigación y resultados:</b> variedades, estudios, innovación.</li>
                        <li><b>Recomendaciones técnicas:</b> manejo, riego, plagas, cosecha.</li>
                        <li><b>Fechas conmemorativas:</b> Día del Ingeniero Agrónomo, Sanidad Vegetal.</li>
                        <li><b>Institucional:</b> visitas, equipo, alianzas.</li>
                        <li><b>Empleo y convocatorias:</b> plazas abiertas.</li>
                    </ul>
                </details>
            </div>
        </div>

        <?php
        $tiposCruce = [];
        foreach ($a['cruce'] as $fila) {
            foreach ($fila as $tip => $g) {
                $tiposCruce[$tip] = true;
            }
        }
        $tiposCruce = array_keys($tiposCruce);
        $maxCruce = 0.0;
        foreach ($a['cruce'] as $fila) {
            foreach ($fila as $g) {
                $maxCruce = max($maxCruce, $g['tasa']);
            }
        }
        ?>
        <?php if (count($a['cruce']) > 1 && count($tiposCruce) > 1 && $a['usa_tasa']): ?>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-grid-dots"></i> Tema × formato: dónde funciona mejor cada tema</div>
                <div class="table-wrap">
                    <table class="ana-calor">
                        <thead><tr><th>Tema</th><?php foreach ($tiposCruce as $tip): ?><th><?= e((string) $tip) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach (array_keys($a['pilar']) as $pil): if (!isset($a['cruce'][$pil])) { continue; } ?>
                            <tr>
                                <th><?= e((string) $pil) ?></th>
                                <?php foreach ($tiposCruce as $tip): $g = $a['cruce'][$pil][$tip] ?? null; ?>
                                    <?php if ($g && $g['base'] > 0): ?>
                                        <td style="<?= $colorCelda($g['tasa'], $maxCruce) ?>" title="<?= e($pil . ' · ' . $tip) ?>: <?= number_format($g['tasa'], 1) ?>% de tasa en <?= $g['n'] ?> publicación(es)"><b><?= number_format($g['tasa'], 1) ?>%</b><small><?= $g['n'] ?> publ.</small></td>
                                    <?php else: ?>
                                        <td class="is-vacia">—</td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="ana-nota">Cada celda es la tasa de interacción de esa combinación; más oscuro, mejor respuesta. Las celdas vacías son combinaciones que aún no se han probado: buena oportunidad para experimentar.</p>
            </div>
        <?php endif; ?>

        <h2 class="est-seccion"><i class="ti ti-adjustments-horizontal"></i> Qué formato y cuándo publicar</h2>
        <div class="est-grid">
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-photo-video"></i> <?= e($metricaNombre) ?> por formato</div>
                <?= estadisticas_barras_html($items($a['tipo'], static fn($k) => e((string) $k)), 'Sin datos.', $a['global'], $refEtiqueta) ?>
                <?= $tablaGrupos($a['tipo'], static fn($k) => e((string) $k)) ?>
            </div>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-calendar-week"></i> <?= e($metricaNombre) ?> por día de la semana</div>
                <?= estadisticas_barras_html($items($a['dia'], static fn($k) => e(REDES_DIAS[$k])), 'Sin fechas registradas.', $a['global'], $refEtiqueta) ?>
                <?= $tablaGrupos($a['dia'], static fn($k) => e(REDES_DIAS[$k])) ?>
            </div>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-share"></i> <?= e($metricaNombre) ?> por plataforma</div>
                <?= estadisticas_barras_html($items($a['plataforma'], $chip), 'Sin datos.', $a['global'], $refEtiqueta) ?>
                <?= $tablaGrupos($a['plataforma'], $chip) ?>
            </div>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-text-size"></i> <?= e($metricaNombre) ?> según el largo del texto</div>
                <?= estadisticas_barras_html($items($a['longitud'], static fn($k) => e(REDES_LONGITUDES[$k])), 'Las publicaciones no tienen texto registrado.', $a['global'], $refEtiqueta) ?>
                <?= $tablaGrupos($a['longitud'], static fn($k) => e(REDES_LONGITUDES[$k])) ?>
            </div>
        </div>

        <h2 class="est-seccion"><i class="ti ti-mood-heart"></i> Qué le interesa a la audiencia</h2>
        <div class="est-grid">
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-flame"></i> Temas y palabras que más conectan</div>
                <?= estadisticas_barras_html($items($a['temas'], static fn($k) => '<span class="ana-tema">' . e((string) $k) . '</span>'), 'Aún no hay palabras que se repitan en 2 o más publicaciones. Escriba el texto de cada publicación o use hashtags para identificar temas.', $a['global'], $refEtiqueta) ?>
            </div>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-hand-click"></i> Cómo interactúan</div>
                <?= estadisticas_barras_html(array_map(static fn($et, $v) => ['html' => e($et), 'valor' => $v, 'texto' => number_format($v / $totalComp * 100, 0) . '%', 'detalle' => estadisticas_num($v), 'nota' => estadisticas_num($v) . ' ' . mb_strtolower($et)], array_keys($a['composicion']), $a['composicion']), 'Sin desglose de interacciones.') ?>
                <p class="ana-nota">Las reacciones indican agrado; los <b>comentarios</b> generan conversación; los <b>compartidos</b> y <b>guardados</b> indican que el contenido es útil.</p>
            </div>
        </div>

        <h2 class="est-seccion"><i class="ti ti-timeline"></i> Ritmo y evolución</h2>
        <div class="est-grid est-grid-2-1">
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-chart-line"></i> <?= e($metricaNombre) ?> por mes</div>
                <?php $valoresMes = array_column($a['mes'], 'valor'); $maxMes = max(1, $a['global'], ...$valoresMes); $ultimoMes = array_key_last($a['mes']); ?>
                <div class="est-columnas" style="--n:<?= count($a['mes']) ?>">
                    <div class="est-columnas-grid" aria-hidden="true"><span><?= $corto($maxMes) ?></span><span><?= $corto($maxMes / 2) ?></span><span>0</span></div>
                    <span class="est-columnas-ref" style="bottom:calc(22px + (100% - 40px) * <?= round($a['global'] / $maxMes, 4) ?>)" title="Promedio general"></span>
                    <?php foreach ($a['mes'] as $mes => $g): ?>
                        <div class="est-col" tabindex="0" data-tip="<?= e(estadisticas_mes_corto($mes)) ?>: <?= $corto($g['valor']) ?> · <?= $g['n'] ?> publicaciones · <?= estadisticas_num($g['interacciones']) ?> interacciones">
                            <span class="est-col-area">
                                <?php if ($mes === $ultimoMes || $g['valor'] === max($valoresMes)): ?><span class="est-col-num"><?= $corto($g['valor']) ?></span><?php endif; ?>
                                <span class="est-col-bar" style="height:<?= $g['valor'] ? max(1.5, round($g['valor'] / $maxMes * 100, 1)) : 0 ?>%"></span>
                            </span>
                            <span class="est-col-mes"><?= e(estadisticas_mes_corto($mes)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="est-leyenda"><span class="est-leyenda-ref"></span> Promedio general: <?= $corto($a['global']) ?> · solo meses con publicaciones</p>
            </div>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-stack-2"></i> ¿Cuántas publicaciones por semana?</div>
                <?= estadisticas_barras_html($items($a['frecuencia'], static fn($k) => e((string) $k)), 'Sin fechas registradas.', $a['global'], $refEtiqueta) ?>
                <p class="ana-nota">Rendimiento de cada publicación según cuántas se publicaron esa misma semana.</p>
            </div>
        </div>

        <?php $pa = $a['pauta']; ?>
        <h2 class="est-seccion"><i class="ti ti-cash"></i> Orgánico frente a pautado</h2>
        <div class="est-grid est-grid-2-1">
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-scale"></i> ¿Qué aporta la pauta?</div>
                <?php if (!$pa['pagado']['n']): ?>
                    <div class="empty-state"><i class="ti ti-cash-off"></i>No hay publicaciones marcadas como pautadas en este periodo. Al editar una publicación, marque <b>«¿Se pautó?»</b> e indique la inversión para comparar.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="est-tabla">
                            <thead><tr><th></th><th class="num">Publicaciones</th><th class="num">Alcance promedio</th><th class="num">Tasa de interacción</th><th class="num">Inversión</th><th class="num">Costo por 1,000 alcanzados</th><th class="num">Costo por interacción</th></tr></thead>
                            <tbody>
                                <tr><td><b>Orgánico</b></td><td class="num"><?= $pa['organico']['n'] ?></td><td class="num"><?= estadisticas_num($pa['organico']['alcance_promedio']) ?></td><td class="num"><?= number_format($pa['organico']['tasa'], 1) ?>%</td><td class="num">—</td><td class="num">—</td><td class="num">—</td></tr>
                                <tr><td><b>Pautado</b></td><td class="num"><?= $pa['pagado']['n'] ?></td><td class="num"><?= estadisticas_num($pa['pagado']['alcance_promedio']) ?></td><td class="num"><?= number_format($pa['pagado']['tasa'], 1) ?>%</td><td class="num"><?= redes_quetzales($pa['pagado']['inversion'] ?: null) ?></td><td class="num"><?= redes_quetzales($pa['pagado']['cpm']) ?></td><td class="num"><?= redes_quetzales($pa['pagado']['costo_interaccion']) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="ana-nota">La pauta debe <b>ampliar</b> el alcance de contenido que ya interesa. Si la tasa pautada cae mucho frente a la orgánica, la segmentación está llegando a personas menos interesadas.</p>
                <?php endif; ?>
            </div>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-rocket"></i> Candidatas a pautar</div>
                <?php if ($a['candidatas']): ?>
                    <?= $tablaPosts($a['candidatas']) ?>
                    <p class="ana-nota">Publicaciones orgánicas con una tasa al menos 25% mayor que el promedio orgánico: ya demostraron que interesan.</p>
                <?php else: ?>
                    <div class="empty-state"><i class="ti ti-inbox"></i>Aún ninguna publicación orgánica destaca lo suficiente sobre el promedio.</div>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="est-seccion"><i class="ti ti-trophy"></i> Publicaciones para aprender</h2>
        <div class="est-grid">
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-star"></i> Las que más gustaron</div>
                <?= $a['destacadas'] ? $tablaPosts($a['destacadas']) : '<div class="empty-state"><i class="ti ti-inbox"></i>Sin publicaciones con alcance registrado.</div>' ?>
                <p class="ana-nota">Ordenadas por tasa de interacción. Úselas como modelo: formato, tema, tono y día.</p>
            </div>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-zoom-question"></i> Las que menos conectaron</div>
                <?= $a['revisar'] ? $tablaPosts($a['revisar']) : '<div class="empty-state"><i class="ti ti-inbox"></i>Se muestran cuando hay al menos 6 publicaciones con alcance.</div>' ?>
                <p class="ana-nota">Compárelas con las destacadas: ¿qué tienen en común (formato, tema, día, largo del texto)?</p>
            </div>
        </div>
    <?php endif; ?>
    <div class="est-tooltip" id="est-tooltip" role="status" hidden></div>
</div>
<script src="assets/js/estadisticas.js?v=<?= @filemtime(__DIR__ . '/../assets/js/estadisticas.js') ?>" defer></script>
