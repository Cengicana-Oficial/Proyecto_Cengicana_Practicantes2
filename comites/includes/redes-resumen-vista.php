<?php
// Pestana Resumen de Redes sociales. Datos en $rs (redes_resumen()) y $periodoRs.
$plataformasRedes = redes_plataformas();
$paisesTop = array_slice(redes_audiencia_paises_combinados($rs['audiencia']), 0, 6, true);
$fmtKpi = static fn(string $clave, $v): string => $clave === 'tasa' ? number_format((float) $v, 1) . '%' : estadisticas_num($v);
$urlTab = static fn(string $tab, array $extra = []): string => str_replace('view=estadisticas', 'view=publicaciones&tab=' . $tab, estadisticas_query($periodoRs, $extra));
$urlPeriodo = static fn(string $clave): string => 'index.php?' . http_build_query(['view' => 'publicaciones', 'tab' => 'resumen', 'periodo' => $clave]);
$seriesTendencia = [
    'alcance' => ['Alcance', 'num'],
    'interacciones' => ['Interacciones', 'num'],
    'tasa' => ['Tasa de interacción', 'pct'],
    'publicaciones' => ['Publicaciones', 'num'],
];
$sinWeb = !$rs['web'];
$calidadOk = count(array_filter(array_column($rs['calidad'], 'ok')));
$cal = $rs['calendario'];
?>
<link rel="stylesheet" href="assets/css/estadisticas.css?v=<?= @filemtime(__DIR__ . '/../assets/css/estadisticas.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/css/flag-icons.min.css">

<div class="est-page rs-page">
    <header class="rs-hero">
        <div class="rs-hero-texto">
            <span class="rs-hero-eyebrow"><i class="ti ti-calendar-stats"></i> <?= e($periodoRs['etiqueta']) ?><?= $rs['anterior'] ? ' · comparado con ' . e($rs['anterior']['etiqueta']) : '' ?></span>
            <h2>¿Cómo nos fue en redes?</h2>
            <p class="rs-hero-frase"><?= redes_resumen_frase($rs) ?></p>
        </div>
        <div class="rs-hero-acciones">
            <nav class="rs-periodos" aria-label="Periodo">
                <?php foreach (['12m' => '12 meses', 'anio' => 'Este año', 'anterior' => 'Año anterior', 'todo' => 'Todo'] as $clave => $etiqueta): ?>
                    <a href="<?= e($urlPeriodo($clave)) ?>" class="<?= $periodoRs['clave'] === $clave ? 'is-active' : '' ?>"><?= e($etiqueta) ?></a>
                <?php endforeach; ?>
                <details class="rs-rango" <?= $periodoRs['clave'] === 'rango' ? 'open' : '' ?>>
                    <summary class="<?= $periodoRs['clave'] === 'rango' ? 'is-active' : '' ?>"><i class="ti ti-calendar-search"></i> Rango</summary>
                    <form method="get">
                        <input type="hidden" name="view" value="publicaciones"><input type="hidden" name="tab" value="resumen"><input type="hidden" name="periodo" value="rango">
                        <label>Desde<input class="form-control" type="month" name="desde" value="<?= e($periodoRs['desde_mes']) ?>" required></label>
                        <label>Hasta<input class="form-control" type="month" name="hasta" value="<?= e($periodoRs['hasta_mes']) ?>" required></label>
                        <button type="submit" class="btn-primary btn-sm">Aplicar</button>
                    </form>
                </details>
            </nav>
            <a class="rs-btn-claro" href="<?= e($urlTab('resumen', ['reporte' => 1])) ?>"><i class="ti ti-file-analytics"></i> Generar reporte</a>
        </div>
    </header>

    <section class="rs-kpis">
        <?php foreach ($rs['kpis'] as $clave => [$nombre, $icono, $valor, $previo, $nota]):
            $var = redes_variacion((float) $valor, $previo === null ? null : (float) $previo);
            $vacio = $clave === 'web' && $sinWeb; ?>
            <article class="rs-kpi <?= $vacio ? 'is-vacio' : '' ?>">
                <div class="rs-kpi-top">
                    <span class="rs-kpi-icono"><i class="ti <?= e($icono) ?>"></i></span>
                    <?php if ($var !== null): ?>
                        <span class="rs-delta <?= $var >= 0.5 ? 'is-up' : ($var <= -0.5 ? 'is-down' : '') ?>" title="Periodo anterior: <?= e($fmtKpi($clave, $previo)) ?>"><?= e(redes_variacion_txt($var)) ?></span>
                    <?php endif; ?>
                </div>
                <span class="rs-kpi-nombre"><?= e($nombre) ?></span>
                <?php if ($vacio): ?>
                    <strong class="rs-kpi-valor rs-kpi-sin">Sin datos</strong>
                    <a class="rs-kpi-nota rs-link" href="<?= e($urlTab('web')) ?>">Conecte o registre Google Analytics <i class="ti ti-arrow-right"></i></a>
                <?php else: ?>
                    <strong class="rs-kpi-valor"><?= e($fmtKpi($clave, $valor)) ?></strong>
                    <span class="rs-kpi-nota"><?= $var !== null ? 'antes: ' . e($fmtKpi($clave, $previo)) : e($nota) ?></span>
                    <?= redes_svg_sparkline($rs['series'][$clave], 160, 30) ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="est-grid est-grid-2-1">
        <div class="card est-card">
            <div class="est-card-head">
                <div class="card-title"><i class="ti ti-chart-bar"></i> Evolución mensual</div>
                <div class="est-seg" role="tablist" aria-label="Métrica">
                    <?php foreach ($seriesTendencia as $clave => [$etiqueta]): ?>
                        <button type="button" role="tab" data-serie="<?= e($clave) ?>" aria-selected="<?= $clave === 'alcance' ? 'true' : 'false' ?>"><?= e($etiqueta) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php foreach ($seriesTendencia as $clave => [$etiqueta, $formato]):
                $valores = array_column($rs['meses'], $clave);
                $max = max(1, ...$valores);
                $fmt = static fn($v) => $formato === 'pct' ? number_format((float) $v, 1) . '%' : estadisticas_num($v);
                $ultimo = array_key_last($rs['meses']);
                $mesMax = array_search(max($valores), array_combine(array_keys($rs['meses']), $valores), true); ?>
                <div class="est-columnas" data-serie-panel="<?= e($clave) ?>" <?= $clave === 'alcance' ? '' : 'hidden' ?> style="--n:<?= count($rs['meses']) ?>">
                    <div class="est-columnas-grid" aria-hidden="true"><span><?= $fmt($max) ?></span><span><?= $fmt($max / 2) ?></span><span>0</span></div>
                    <?php foreach ($rs['meses'] as $mes => $m): $v = $m[$clave]; ?>
                        <div class="est-col" tabindex="0" data-tip="<?= e(estadisticas_mes_corto($mes)) ?>: <?= $m['publicaciones'] ?> publicaciones · <?= estadisticas_num($m['alcance']) ?> alcance · <?= estadisticas_num($m['interacciones']) ?> interacciones · <?= number_format($m['tasa'], 1) ?>% tasa">
                            <span class="est-col-area">
                                <?php if ($v && ($mes === $mesMax || $mes === $ultimo)): ?><span class="est-col-num"><?= $fmt($v) ?></span><?php endif; ?>
                                <span class="est-col-bar" style="height:<?= $v ? max(1.5, round($v / $max * 100, 1)) : 0 ?>%"></span>
                            </span>
                            <span class="est-col-mes"><?= e(estadisticas_mes_corto($mes)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="rs-columna">
            <div class="card est-card ana-recos">
                <div class="card-title"><i class="ti ti-bulb"></i> Lo que dicen los datos</div>
                <ul>
                    <?php foreach (array_slice($rs['analisis']['recomendaciones'], 0, 3) as $r): ?>
                        <li class="is-<?= e($r['tono']) ?>"><i class="ti <?= e($r['icono']) ?>"></i><span><?= $r['html'] ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <a class="rs-link" href="<?= e($urlTab('analisis')) ?>">Ver análisis completo <i class="ti ti-arrow-right"></i></a>
            </div>
        </div>
    </div>

            <?php if ($calidadOk < count($rs['calidad'])): ?>
                <div class="card est-card rs-calidad">
                    <div class="est-card-head">
                        <div class="card-title"><i class="ti ti-checklist"></i> Para un mejor análisis</div>
                        <span class="rs-calidad-cuenta"><?= $calidadOk ?>/<?= count($rs['calidad']) ?></span>
                    </div>
                    <div class="rs-calidad-barra"><span style="width:<?= round($calidadOk / count($rs['calidad']) * 100) ?>%"></span></div>
                    <ul>
                        <?php foreach ($rs['calidad'] as $c): ?>
                            <li class="<?= $c['ok'] ? 'is-ok' : '' ?>">
                                <i class="ti <?= $c['ok'] ? 'ti-circle-check-filled' : 'ti-circle-dashed' ?>"></i>
                                <span><b><?= e($c['titulo']) ?></b> <small><?= e($c['valor']) ?></small><?php if (!$c['ok']): ?><em><?= e($c['ayuda']) ?></em><?php endif; ?></span>
                                <?php if (!$c['ok'] && ($c['tab'] !== 'conexiones' || $puedeGestionarSeccionActual)): ?><a href="<?= e($c['tab'] === 'conexiones' ? 'index.php?view=publicaciones&tab=conexiones' : ($c['tab'] === 'cuenta' ? $urlTab('resumen', ['cuenta' => 'nuevo']) : $urlTab($c['tab']))) ?>" aria-label="Ir a <?= e($c['titulo']) ?>"><i class="ti ti-chevron-right"></i></a><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

    <?php $cu = $rs['cuenta']; ?>
    <section class="card est-card rs-cuenta" id="cuenta">
        <div class="est-card-head">
            <div class="card-title"><i class="ti ti-chart-arrows-vertical"></i> Crecimiento de la cuenta y pauta</div>
            <?php if ($puedeGestionarSeccionActual): ?><a class="btn-outline btn-sm" href="<?= e($urlTab('resumen', ['cuenta' => 'nuevo'])) ?>"><i class="ti ti-plus"></i> Registrar mes</a><?php endif; ?>
        </div>
        <?php if (!$cu['plataformas']): ?>
            <div class="rs-vacio-accion">
                <i class="ti ti-chart-arrows-vertical"></i>
                <p>Aquí se ven las métricas de la <b>página</b> (no de cada publicación): <b>alcance total, orgánico y pagado</b>, <b>seguidores nuevos</b> y la <b>inversión en pauta</b>, con el costo por seguidor y por cada 1,000 personas alcanzadas.</p>
                <p>Llegan <b>solas</b> al conectar Meta y LinkedIn en «Conexiones» (la inversión, agregando la cuenta publicitaria de Meta). Mientras tanto, puede registrarlas a mano con «Registrar mes».</p>
                <small>Meta Business Suite › Estadísticas › Resultados (alcance y seguidores) y › Anuncios (importe gastado). LinkedIn › Análisis › Contenido y Seguidores.</small>
            </div>
        <?php else: ?>
            <div class="rs-cuenta-grid">
                <?php foreach ($cu['plataformas'] as $nombre => $pc): $t = $pc['total']; $pv = $pc['previo']; $cfg = $plataformasRedes[$nombre] ?? $plataformasRedes['Otro'];
                    $varAlc = redes_variacion((float) $t['alcance_total'], $pv ? (float) $pv['alcance_total'] : null);
                    $varSeg = redes_variacion((float) $t['seguidores_nuevos'], $pv ? (float) $pv['seguidores_nuevos'] : null); ?>
                    <article class="rs-cuenta-plat" style="--red:<?= e($cfg['color']) ?>">
                        <header><span class="rs-plat-icono"><i class="ti <?= e($cfg['icon']) ?>"></i></span><b><?= e($nombre) ?></b><?php $fuentes = array_unique(array_column($pc['meses'], 'fuente')); $porApi = (bool) array_filter($fuentes, static fn($f) => str_contains((string) $f, 'API')); ?><small title="<?= e(implode(', ', $fuentes)) ?>"><?= $porApi ? '<i class="ti ti-refresh"></i> automático' : '<i class="ti ti-pencil"></i> manual' ?> · <?= $t['meses'] ?> mes(es)</small></header>
                        <div class="rs-cuenta-cifras">
                            <span><small>Alcance total</small><b><?= estadisticas_num($t['alcance_total']) ?></b><?php if ($varAlc !== null): ?><em class="rs-delta <?= $varAlc >= 0 ? 'is-up' : 'is-down' ?>"><?= e(redes_variacion_txt($varAlc)) ?></em><?php endif; ?></span>
                            <span><small>Seguidores nuevos</small><b>+<?= estadisticas_num($t['seguidores_nuevos']) ?></b><?php if ($varSeg !== null): ?><em class="rs-delta <?= $varSeg >= 0 ? 'is-up' : 'is-down' ?>"><?= e(redes_variacion_txt($varSeg)) ?></em><?php endif; ?></span>
                            <span><small>Seguidores totales</small><b><?= $t['seguidores_total'] ? estadisticas_num($t['seguidores_total']) : '—' ?></b></span>
                        </div>
                        <?php if ($t['alcance_total']): ?>
                            <div class="rs-org-pag" title="Orgánico <?= estadisticas_num($t['alcance_organico']) ?> · Pagado <?= estadisticas_num($t['alcance_pagado']) ?>">
                                <span class="rs-org" style="flex:<?= max(0.001, $t['alcance_organico']) ?>"></span><span class="rs-pag" style="flex:<?= max(0.001, $t['alcance_pagado']) ?>"></span>
                            </div>
                            <p class="rs-org-ley"><span class="rs-org"></span> Orgánico <b><?= $t['pct_organico'] !== null ? number_format($t['pct_organico'], 0) . '%' : '—' ?></b> <span class="rs-pag"></span> Pagado <b><?= $t['pct_organico'] !== null ? number_format(100 - $t['pct_organico'], 0) . '%' : '—' ?></b></p>
                        <?php endif; ?>
                        <div class="rs-cuenta-pauta">
                            <span><small>Inversión</small><b><?= $t['inversion_q'] ? redes_quetzales($t['inversion_q']) : 'Sin pauta' ?></b><?php if ($t['inversion_usd']): ?><small>US$<?= number_format($t['inversion_usd'], 2) ?></small><?php endif; ?></span>
                            <span><small>Costo por 1,000 alcanzados</small><b><?= redes_quetzales($t['cpm_q']) ?></b></span>
                            <span><small>Costo por seguidor nuevo</small><b><?= redes_quetzales($t['costo_seguidor_q']) ?></b></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <details class="rs-cuenta-detalle">
                <summary>Ver detalle mes a mes</summary>
                <div class="table-wrap">
                    <table class="rs-plat-tabla">
                        <thead><tr><th>Mes</th><th>Plataforma</th><th class="num">Alcance total</th><th class="num">Orgánico</th><th class="num">Pagado</th><th class="num">Seg. nuevos</th><th class="num">Seg. totales</th><th class="num">Inversión</th><th class="num">Costo/seguidor</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($cu['filas']) as $f): ?>
                            <tr>
                                <td><?= e(estadisticas_fecha_corta($f['periodo'] . '-01')) ?></td><td><?= e($f['plataforma']) ?></td>
                                <td class="num"><?= estadisticas_num((int) $f['alcance_total']) ?></td>
                                <td class="num"><?= estadisticas_num((int) $f['alcance_organico']) ?><?= $f['pct_organico'] !== null ? ' <small>(' . number_format($f['pct_organico'], 0) . '%)</small>' : '' ?></td>
                                <td class="num"><?= estadisticas_num((int) $f['alcance_pagado']) ?></td>
                                <td class="num">+<?= estadisticas_num((int) $f['seguidores_nuevos']) ?></td>
                                <td class="num"><?= (int) $f['seguidores_total'] ? estadisticas_num((int) $f['seguidores_total']) : '—' ?></td>
                                <td class="num"><?= (float) $f['inversion_q'] ? redes_quetzales((float) $f['inversion_q']) : '—' ?></td>
                                <td class="num"><?= redes_quetzales($f['costo_seguidor_q']) ?></td>
                                <td><?php if ($puedeGestionarSeccionActual): ?><a class="rs-link" href="<?= e($urlTab('resumen', ['cuenta' => $f['plataforma'] . '|' . $f['periodo']])) ?>"><i class="ti ti-edit"></i></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </section>

    <div class="card est-card">
        <div class="est-card-head">
            <div class="card-title"><i class="ti ti-apps"></i> Comparación por plataforma</div>
            <a class="rs-link" href="<?= e($urlTab('publicaciones')) ?>">Ver publicaciones <i class="ti ti-arrow-right"></i></a>
        </div>
        <?php if (!$rs['plataformas']): ?>
            <div class="empty-state"><i class="ti ti-share"></i>No hay publicaciones publicadas en este periodo.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="rs-plat-tabla">
                    <thead><tr><th>Plataforma</th><th class="num">Publicaciones</th><th class="num">Alcance</th><th class="num">Interacciones</th><th class="num">Tasa</th><th class="num">Clics</th><th>Participación en interacciones</th><th>Tendencia</th></tr></thead>
                    <tbody>
                    <?php foreach ($rs['plataformas'] as $nombre => $p): $cfg = $plataformasRedes[$nombre] ?? $plataformasRedes['Otro']; ?>
                        <tr style="--red:<?= e($cfg['color']) ?>">
                            <td><a class="rs-plat-nombre" href="<?= e('index.php?' . http_build_query(['view' => 'publicaciones', 'tab' => 'publicaciones', 'plataforma' => $nombre])) ?>"><span class="rs-plat-icono"><i class="ti <?= e($cfg['icon']) ?>"></i></span><?= e($nombre) ?></a></td>
                            <td class="num"><?= estadisticas_num($p['publicaciones']) ?></td>
                            <td class="num"><?= estadisticas_num($p['alcance']) ?></td>
                            <td class="num"><b><?= estadisticas_num($p['interacciones']) ?></b><?php if ($p['var_interacciones'] !== null): ?> <span class="rs-delta <?= $p['var_interacciones'] >= 0.5 ? 'is-up' : ($p['var_interacciones'] <= -0.5 ? 'is-down' : '') ?>"><?= e(redes_variacion_txt($p['var_interacciones'])) ?></span><?php endif; ?></td>
                            <td class="num"><?= $p['alcance'] ? number_format($p['tasa'], 1) . '%' : '—' ?></td>
                            <td class="num"><?= estadisticas_num($p['clics']) ?></td>
                            <td><span class="rs-part"><span class="rs-part-barra"><span style="width:<?= round($p['participacion'], 1) ?>%"></span></span><b><?= number_format($p['participacion'], 0) ?>%</b></span></td>
                            <td><?= redes_svg_sparkline($p['serie'], 120, 28, $cfg['color']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="est-grid">
        <div class="card est-card">
            <div class="est-card-head">
                <div class="card-title"><i class="ti ti-calendar-week"></i> Constancia al publicar</div>
                <span class="rs-cal-resumen"><b><?= $cal['semanas_con_pub'] ?></b> de 26 semanas con publicaciones</span>
            </div>
            <?php
            $diasCal = [];
            for ($d = $cal['inicio']; $d <= $cal['fin']; $d = $d->modify('+1 day')) {
                $diasCal[] = $d;
            }
            $semanasCal = array_chunk($diasCal, 7);
            ?>
            <div class="rs-cal" role="img" aria-label="Calendario de publicaciones de las últimas 26 semanas">
                <div class="rs-cal-dias" aria-hidden="true"><span>L</span><span></span><span>M</span><span></span><span>V</span><span></span><span></span></div>
                <div class="rs-cal-semanas">
                    <?php $mesPrevio = ''; foreach ($semanasCal as $semana): $mesSemana = $semana[0]->format('Y-m'); ?>
                        <div class="rs-cal-semana">
                            <span class="rs-cal-mes"><?= $mesSemana !== $mesPrevio ? e(ESTADISTICAS_MESES[(int) $semana[0]->format('n')]) : '' ?></span>
                            <?php foreach ($semana as $dia): $info = $rs['calendario']['dias'][$dia->format('Y-m-d')] ?? null; $n = $info['n'] ?? 0; ?>
                                <span class="rs-cal-dia n<?= min(3, $n) ?>" title="<?= e($dia->format('d/m/Y')) ?>: <?= $n ? $n . ' publicación' . ($n > 1 ? 'es' : '') . ' (' . e($info['redes']) . ')' : 'sin publicaciones' ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    <?php $mesPrevio = $mesSemana; endforeach; ?>
                </div>
            </div>
            <p class="est-leyenda rs-cal-leyenda">Menos <span class="rs-cal-dia n0"></span><span class="rs-cal-dia n1"></span><span class="rs-cal-dia n2"></span><span class="rs-cal-dia n3"></span> Más · publicar con regularidad ayuda a que las redes muestren más su contenido.</p>
        </div>

        <div class="card est-card">
            <div class="est-card-head">
                <div class="card-title"><i class="ti ti-world-pin"></i> ¿Dónde está nuestra audiencia?</div>
                <a class="rs-link" href="<?= e($urlTab('audiencia')) ?>">Ver audiencia <i class="ti ti-arrow-right"></i></a>
            </div>
            <?php if (!$paisesTop): ?>
                <div class="rs-vacio-accion">
                    <i class="ti ti-world-search"></i>
                    <p>Aún no hay datos por país. Llegan solos al <b>conectar</b> Google Analytics, Instagram o LinkedIn, o puede <b>importar</b> el archivo de audiencia de cada red.</p>
                    <div>
                        <?php if ($puedeGestionarSeccionActual): ?><a class="btn-outline btn-sm" href="index.php?view=publicaciones&tab=conexiones"><i class="ti ti-plug"></i> Conectar redes</a>
                        <a class="btn-outline btn-sm" href="<?= e($urlTab('audiencia', ['importar' => 'audiencia'])) ?>"><i class="ti ti-file-import"></i> Importar audiencia</a><?php endif; ?>
                        <a class="btn-outline btn-sm" href="<?= e($urlTab('audiencia', ['ejemplo' => 1])) ?>"><i class="ti ti-eye"></i> Ver ejemplo</a>
                    </div>
                </div>
            <?php else: ?>
                <ul class="aud-paises">
                    <?php foreach ($paisesTop as $codigo => $pais): ?>
                        <li title="<?= e(implode(' · ', array_map(static fn($pl, $v) => "$pl: " . estadisticas_num($v), array_keys($pais['por']), $pais['por']))) ?>">
                            <?= redes_bandera((string) $codigo) ?>
                            <span class="aud-pais-nombre"><?= e($pais['etiqueta']) ?></span>
                            <span class="aud-pais-barra"><span style="width:<?= round($pais['pct'] / max(1, reset($paisesTop)['pct']) * 100, 1) ?>%"></span></span>
                            <span class="aud-pais-pct"><?= number_format($pais['pct'], 1) ?>%</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="ana-nota">Suma de <?= e(implode(', ', array_map(static fn($pl, $a) => $pl . ' (' . ($a['metrica'] === 'seguidores' ? 'seguidores' : 'visitantes') . ')', array_keys($rs['audiencia']), $rs['audiencia']))) ?>.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card est-card">
        <div class="est-card-head">
            <div class="card-title"><i class="ti ti-trophy"></i> Lo que más gustó</div>
            <a class="rs-link" href="<?= e($urlTab('publicaciones')) ?>">Ver todas <i class="ti ti-arrow-right"></i></a>
        </div>
        <?php if (!$rs['top']): ?>
            <div class="empty-state"><i class="ti ti-inbox"></i>Sin publicaciones en el periodo.</div>
        <?php else: ?>
            <div class="rs-posts">
                <?php foreach (array_slice($rs['top'], 0, 3) as $i => $p): $cfg = $plataformasRedes[$p['plataforma']] ?? $plataformasRedes['Otro'];
                    $esImagen = !empty($p['material']) && preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $p['material']);
                    $tasa = (int) $p['alcance'] ? (int) $p['interacciones'] / (int) $p['alcance'] * 100 : null;
                    $texto = redes_texto_publicacion($p);
                    $titulo = $texto !== '' ? $texto : (string) $p['titulo']; ?>
                    <article class="rs-post" style="--red:<?= e($cfg['color']) ?>">
                        <div class="rs-post-media">
                            <?php if ($esImagen): ?><img src="<?= e((string) $p['material']) ?>" alt="" loading="lazy"><?php else: ?><i class="ti <?= e($cfg['icon']) ?>"></i><?php endif; ?>
                            <span class="rs-post-pos">#<?= $i + 1 ?></span>
                            <span class="rs-post-red"><i class="ti <?= e($cfg['icon']) ?>"></i> <?= e((string) $p['plataforma']) ?></span>
                        </div>
                        <div class="rs-post-cuerpo">
                            <small><?= $p['fecha'] ? e(REDES_DIAS[(int) date('N', strtotime((string) $p['fecha']))] . ' ' . date('d/m/Y', strtotime((string) $p['fecha']))) : 'Sin fecha' ?><?= !empty($p['tipo']) ? ' · ' . e(redes_tipo_normalizado((string) $p['tipo'])) : '' ?></small>
                            <p><?= e(mb_strimwidth($titulo, 0, 140, '…')) ?></p>
                            <?php if ($texto === ''): ?><span class="rs-post-aviso"><i class="ti ti-alert-circle"></i> Sin texto registrado</span><?php endif; ?>
                        </div>
                        <div class="rs-post-metricas">
                            <span><b><?= estadisticas_num((int) $p['interacciones']) ?></b>interacciones</span>
                            <span><b><?= estadisticas_num((int) $p['alcance']) ?></b>alcance</span>
                            <span><b><?= $tasa !== null ? number_format($tasa, 1) . '%' : '—' ?></b>tasa</span>
                            <span><b><?= estadisticas_num((int) $p['clics']) ?></b>clics</span>
                        </div>
                        <?php if (!empty($p['url'])): ?><a class="rs-post-link" href="<?= e((string) $p['url']) ?>" target="_blank" rel="noopener noreferrer">Ver publicación <i class="ti ti-external-link"></i></a><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="est-tooltip" id="est-tooltip" role="status" hidden></div>
</div>

<?php if ($puedeGestionarSeccionActual && isset($_GET['cuenta'])):
    $cuentaEditar = ['plataforma' => 'Facebook', 'periodo' => date('Y-m', strtotime('first day of last month')), 'notas' => '', 'inversion_q' => 0, 'inversion_usd' => 0] + array_fill_keys(array_keys(REDES_CUENTA_CAMPOS), 0);
    foreach ($rs['cuenta']['filas'] as $f) {
        if ($f['plataforma'] . '|' . $f['periodo'] === (string) $_GET['cuenta']) {
            $cuentaEditar = $f;
        }
    }
    $cerrarCuenta = $urlTab('resumen'); ?>
    <div class="modal-backdrop is-open" data-close-href="<?= e($cerrarCuenta) ?>">
        <div class="modal-box modal-wide" onclick="event.stopPropagation()">
            <div class="modal-head">
                <h3><i class="ti ti-chart-arrows-vertical"></i> Métricas del mes de la cuenta</h3>
                <a class="modal-close" href="<?= e($cerrarCuenta) ?>"><i class="ti ti-x"></i></a>
            </div>
            <form method="post" class="modal-body">
                <input type="hidden" name="accion" value="cuenta_guardar">
                <div class="form-grid">
                    <label class="form-group"><span class="form-label">Plataforma <span class="req">*</span></span>
                        <select class="form-control" name="plataforma" required>
                            <?php foreach (REDES_CUENTA_PLATAFORMAS as $opt): ?><option value="<?= e($opt) ?>" <?= $cuentaEditar['plataforma'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
                        </select></label>
                    <label class="form-group"><span class="form-label">Mes <span class="req">*</span></span>
                        <input class="form-control" type="month" name="periodo" required value="<?= e((string) $cuentaEditar['periodo']) ?>"></label>
                </div>
                <p class="rs-form-sub"><i class="ti ti-users-group"></i> Alcance de la página en el mes</p>
                <div class="form-grid form-grid-3">
                    <?php foreach (['alcance_total', 'alcance_organico', 'alcance_pagado'] as $campo): ?>
                        <label class="form-group"><span class="form-label"><?= e(REDES_CUENTA_CAMPOS[$campo]) ?></span>
                            <input class="form-control" type="number" min="0" name="<?= e($campo) ?>" value="<?= (int) $cuentaEditar[$campo] ?>"></label>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted">Con dos de los tres valores se calcula el tercero.</small>
                <p class="rs-form-sub"><i class="ti ti-user-plus"></i> Seguidores y visitas</p>
                <div class="form-grid form-grid-3">
                    <?php foreach (['seguidores_nuevos', 'seguidores_total', 'visitas_perfil'] as $campo): ?>
                        <label class="form-group"><span class="form-label"><?= e(REDES_CUENTA_CAMPOS[$campo]) ?></span>
                            <input class="form-control" type="number" min="0" name="<?= e($campo) ?>" value="<?= (int) $cuentaEditar[$campo] ?>"></label>
                    <?php endforeach; ?>
                </div>
                <p class="rs-form-sub"><i class="ti ti-cash"></i> Pauta (publicidad pagada)</p>
                <div class="form-grid form-grid-3">
                    <label class="form-group"><span class="form-label">Inversión (Q)</span>
                        <input class="form-control" type="number" min="0" step="0.01" name="inversion_q" value="<?= e(number_format((float) $cuentaEditar['inversion_q'], 2, '.', '')) ?>"></label>
                    <label class="form-group"><span class="form-label">Inversión (US$)</span>
                        <input class="form-control" type="number" min="0" step="0.01" name="inversion_usd" value="<?= e(number_format((float) $cuentaEditar['inversion_usd'], 2, '.', '')) ?>"></label>
                    <label class="form-group"><span class="form-label">Tipo de cambio (opcional)</span>
                        <input class="form-control" type="number" min="0" step="0.0001" name="tipo_cambio" placeholder="7.70"><small class="text-muted">Calcula US$ si lo deja vacío.</small></label>
                </div>
                <label class="form-group form-full"><span class="form-label">Notas del mes</span>
                    <textarea class="form-control" name="notas" rows="2" placeholder="Campañas activas, eventos, cambios en la estrategia..."><?= e((string) ($cuentaEditar['notas'] ?? '')) ?></textarea></label>
                <div class="actions">
                    <?php if (isset($cuentaEditar['id'])): ?>
                        <button type="submit" name="accion" value="cuenta_eliminar" class="btn-outline rs-btn-peligro" formnovalidate onclick="return confirm('¿Eliminar este mes?');"><i class="ti ti-trash"></i> Eliminar</button>
                    <?php endif; ?>
                    <a class="btn-outline" href="<?= e($cerrarCuenta) ?>">Cancelar</a>
                    <button type="submit" class="btn-primary"><i class="ti ti-device-floppy"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<script src="assets/js/estadisticas.js?v=<?= @filemtime(__DIR__ . '/../assets/js/estadisticas.js') ?>" defer></script>
