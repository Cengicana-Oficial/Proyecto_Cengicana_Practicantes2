<?php
// Vista de estadisticas consolidadas. Datos en $est (estadisticas_datos()).
$k = $est['kpis'];
$periodoEst = $est['periodo'];
$plataformasRedes = redes_plataformas();

$barras = 'estadisticas_barras_html';

$series = [
    'alcance' => ['Alcance', 'personas alcanzadas'],
    'interacciones' => ['Interacciones', 'interacciones'],
    'publicaciones' => ['Publicaciones', 'publicaciones y envíos'],
];
$hayRedes = $k['publicaciones'] > 0;
?>
<link rel="stylesheet" href="assets/css/estadisticas.css?v=<?= @filemtime(__DIR__ . '/../assets/css/estadisticas.css') ?>">

<div class="est-page">
    <div class="est-toolbar">
        <form class="est-periodo" method="get" id="est-periodo">
            <input type="hidden" name="view" value="estadisticas">
            <label><span>Periodo</span>
                <select class="form-control" name="periodo" id="est-periodo-sel">
                    <?php foreach (ESTADISTICAS_PERIODOS as $clave => $etiqueta): ?>
                        <option value="<?= e($clave) ?>" <?= $periodoEst['clave'] === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <span class="est-rango" id="est-rango" <?= $periodoEst['clave'] === 'rango' ? '' : 'hidden' ?>>
                <label><span>Desde</span><input class="form-control" type="month" name="desde" value="<?= e($periodoEst['desde_mes']) ?>"></label>
                <label><span>Hasta</span><input class="form-control" type="month" name="hasta" value="<?= e($periodoEst['hasta_mes']) ?>"></label>
                <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Aplicar</button>
            </span>
            <span class="est-periodo-etiqueta"><i class="ti ti-calendar"></i> <?= e($periodoEst['etiqueta']) ?></span>
        </form>
        <div class="est-descargas">
            <a class="btn-outline" href="<?= e(estadisticas_query($periodoEst, ['exportar' => 'html'])) ?>" target="_blank" rel="noopener"><i class="ti ti-printer"></i> Imprimir</a>
            <a class="btn-outline" href="<?= e(estadisticas_query($periodoEst, ['exportar' => 'xlsx'])) ?>"><i class="ti ti-file-spreadsheet"></i> Excel</a>
            <a class="btn-primary" href="<?= e(estadisticas_query($periodoEst, ['exportar' => 'pdf'])) ?>"><i class="ti ti-file-type-pdf"></i> Descargar reporte PDF</a>
        </div>
    </div>

    <section class="est-kpis">
        <article class="est-kpi">
            <span class="est-kpi-head"><i class="ti ti-calendar-event"></i> Reuniones realizadas</span>
            <strong><?= $k['reuniones_realizadas'] ?><small> / <?= $k['reuniones'] ?></small></strong>
            <span class="est-meter" role="img" aria-label="<?= $k['pct_reuniones'] ?>% de cumplimiento"><span style="width:<?= $k['pct_reuniones'] ?>%"></span></span>
            <span class="est-kpi-nota"><?= $k['pct_reuniones'] ?>% de cumplimiento<?= $k['reuniones_canceladas'] ? ' · ' . $k['reuniones_canceladas'] . ' canceladas' : '' ?></span>
        </article>
        <article class="est-kpi">
            <span class="est-kpi-head"><i class="ti ti-clipboard-check"></i> Acuerdos completados</span>
            <strong><?= $k['acuerdos_completados'] ?><small> / <?= $k['acuerdos'] ?></small></strong>
            <span class="est-meter" role="img" aria-label="<?= $k['avance_acuerdos'] ?>% de avance promedio"><span style="width:<?= min(100, $k['avance_acuerdos']) ?>%"></span></span>
            <span class="est-kpi-nota"><?= $k['avance_acuerdos'] ?>% avance promedio<?php if ($k['acuerdos_vencidos']): ?> · <b class="est-alerta"><i class="ti ti-alert-triangle"></i> <?= $k['acuerdos_vencidos'] ?> vencidos</b><?php endif; ?></span>
        </article>
        <article class="est-kpi">
            <span class="est-kpi-head"><i class="ti ti-presentation"></i> Eventos de transferencia</span>
            <strong><?= $k['eventos_realizados'] ?><small> / <?= $k['eventos'] ?></small></strong>
            <span class="est-kpi-nota"><?= estadisticas_num($k['participantes']) ?> participantes</span>
        </article>
        <article class="est-kpi">
            <span class="est-kpi-head"><i class="ti ti-share"></i> Publicaciones y envíos</span>
            <strong><?= estadisticas_num($k['publicaciones']) ?></strong>
            <span class="est-kpi-nota"><?= estadisticas_num($k['alcance']) ?> de alcance · <?= estadisticas_num($k['clics']) ?> clics</span>
        </article>
        <article class="est-kpi">
            <span class="est-kpi-head"><i class="ti ti-heart-handshake"></i> Interacciones</span>
            <strong><?= estadisticas_num($k['interacciones']) ?></strong>
            <span class="est-kpi-nota" title="Interacciones ÷ alcance"><?= estadisticas_num($k['tasa_interaccion'], 1) ?>% tasa de interacción</span>
        </article>
        <article class="est-kpi">
            <span class="est-kpi-head"><i class="ti ti-mail"></i> Correos enviados</span>
            <strong><?= estadisticas_num($k['correos']) ?></strong>
            <span class="est-kpi-nota"><?= $k['documentos'] ?> documentos · <?= $k['memorias'] ?> memorias</span>
        </article>
    </section>

    <h2 class="est-seccion"><i class="ti ti-share"></i> Redes sociales y WhatsApp</h2>
    <section class="card est-card ana-recos">
        <div class="est-card-head">
            <div class="card-title"><i class="ti ti-bulb"></i> Qué está funcionando en redes</div>
            <a class="btn-outline btn-sm" href="<?= e(str_replace('view=estadisticas', 'view=publicaciones&tab=analisis', estadisticas_query($periodoEst))) ?>">Ver análisis completo <i class="ti ti-arrow-right"></i></a>
        </div>
        <ul>
            <?php foreach (array_slice($est['analisis']['recomendaciones'], 0, 3) as $r): ?>
                <li class="is-<?= e($r['tono']) ?>"><i class="ti <?= e($r['icono']) ?>"></i><span><?= $r['html'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <div class="est-grid est-grid-2-1">
        <div class="card est-card">
            <div class="est-card-head">
                <div class="card-title"><i class="ti ti-chart-bar"></i> Tendencia mensual</div>
                <div class="est-seg" role="tablist" aria-label="Métrica de la tendencia">
                    <?php foreach ($series as $clave => [$etiqueta]): ?>
                        <button type="button" role="tab" data-serie="<?= e($clave) ?>" aria-selected="<?= $clave === 'alcance' ? 'true' : 'false' ?>"><?= e($etiqueta) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (!$hayRedes): ?>
                <div class="empty-state"><i class="ti ti-inbox"></i>Sin publicaciones en el periodo.</div>
            <?php else: ?>
                <?php foreach ($series as $clave => [$etiqueta, $unidad]):
                    $valores = array_column($est['meses'], $clave);
                    $max = max(1, ...$valores);
                    $ultimo = array_key_last($est['meses']);
                    $mesMax = array_search(max($valores), array_combine(array_keys($est['meses']), $valores), true);
                    ?>
                    <div class="est-columnas" data-serie-panel="<?= e($clave) ?>" <?= $clave === 'alcance' ? '' : 'hidden' ?> style="--n:<?= count($est['meses']) ?>">
                        <div class="est-columnas-grid" aria-hidden="true"><span><?= estadisticas_num($max) ?></span><span><?= estadisticas_num($max / 2) ?></span><span>0</span></div>
                        <?php foreach ($est['meses'] as $mes => $m): $v = $m[$clave]; ?>
                            <div class="est-col" tabindex="0" data-tip="<?= e(estadisticas_mes_corto($mes)) ?>: <?= estadisticas_num($m['publicaciones']) ?> publicaciones · <?= estadisticas_num($m['alcance']) ?> alcance · <?= estadisticas_num($m['interacciones']) ?> interacciones">
                                <span class="est-col-area">
                                    <?php if ($v && ($mes === $mesMax || $mes === $ultimo)): ?><span class="est-col-num"><?= estadisticas_num($v) ?></span><?php endif; ?>
                                    <span class="est-col-bar" style="height:<?= $v ? max(1.5, round($v / $max * 100, 1)) : 0 ?>%"></span>
                                </span>
                                <span class="est-col-mes"><?= e(estadisticas_mes_corto($mes)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="card est-card">
            <div class="card-title"><i class="ti ti-chart-donut"></i> Interacciones por plataforma</div>
            <?= $barras(array_map(static function ($p) use ($plataformasRedes) {
                $cfg = $plataformasRedes[$p['plataforma']] ?? $plataformasRedes['Otro'];
                return [
                    'html' => '<i class="ti ' . e($cfg['icon']) . '" style="color:' . e($cfg['color']) . '"></i> ' . e($p['plataforma'] ?: 'Sin plataforma'),
                    'valor' => (int) $p['interacciones'],
                    'nota' => $p['total'] . ' publicaciones · ' . estadisticas_num((int) $p['alcance']) . ' alcance · ' . $p['tasa'] . '% tasa',
                ];
            }, $est['plataformas']), 'Sin publicaciones en el periodo.') ?>
        </div>
    </div>

    <div class="card est-card">
        <div class="card-title"><i class="ti ti-table"></i> Resultados por plataforma</div>
        <div class="table-wrap">
            <table class="est-tabla">
                <thead><tr><th>Plataforma</th><th class="num">Publicaciones</th><th class="num">Alcance</th><th class="num">Impresiones</th><th class="num">Interacciones</th><th class="num">Clics</th><th class="num">Tasa de interacción</th></tr></thead>
                <tbody>
                <?php if (!$est['plataformas']): ?><tr><td colspan="7"><div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div></td></tr><?php endif; ?>
                <?php foreach ($est['plataformas'] as $p): $cfg = $plataformasRedes[$p['plataforma']] ?? $plataformasRedes['Otro']; ?>
                    <tr>
                        <td><span class="red-chip" style="--red:<?= e($cfg['color']) ?>"><i class="ti <?= e($cfg['icon']) ?>"></i> <?= e($p['plataforma'] ?: 'Sin plataforma') ?></span></td>
                        <td class="num"><?= estadisticas_num((int) $p['total']) ?></td>
                        <td class="num"><?= estadisticas_num((int) $p['alcance']) ?></td>
                        <td class="num"><?= estadisticas_num((int) $p['impresiones']) ?></td>
                        <td class="num"><?= estadisticas_num((int) $p['interacciones']) ?></td>
                        <td class="num"><?= estadisticas_num((int) $p['clics']) ?></td>
                        <td class="num"><?= estadisticas_num($p['tasa'], 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (count($est['plataformas']) > 1): ?>
                    <tfoot><tr><th>Total</th><th class="num"><?= estadisticas_num($k['publicaciones']) ?></th><th class="num"><?= estadisticas_num($k['alcance']) ?></th><th class="num"><?= estadisticas_num($k['impresiones']) ?></th><th class="num"><?= estadisticas_num($k['interacciones']) ?></th><th class="num"><?= estadisticas_num($k['clics']) ?></th><th class="num"><?= estadisticas_num($k['tasa_interaccion'], 1) ?>%</th></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="est-grid est-grid-2-1">
        <div class="card est-card">
            <div class="card-title"><i class="ti ti-trophy"></i> Publicaciones con más interacción</div>
            <?php if (!$est['top']): ?>
                <div class="empty-state"><i class="ti ti-inbox"></i>Sin publicaciones en el periodo.</div>
            <?php else: ?>
                <ol class="est-top">
                    <?php foreach ($est['top'] as $p): $cfg = $plataformasRedes[$p['plataforma']] ?? $plataformasRedes['Otro']; ?>
                        <li>
                            <i class="ti <?= e($cfg['icon']) ?>" style="color:<?= e($cfg['color']) ?>" title="<?= e((string) $p['plataforma']) ?>"></i>
                            <span class="est-top-texto">
                                <?php if (!empty($p['url'])): ?><a href="<?= e((string) $p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $p['titulo']) ?></a><?php else: ?><strong><?= e((string) $p['titulo']) ?></strong><?php endif; ?>
                                <small><?= $p['fecha'] ? e(date('d/m/Y', strtotime((string) $p['fecha']))) : 'Sin fecha' ?><?= !empty($p['tipo']) ? ' · ' . e((string) $p['tipo']) : '' ?></small>
                            </span>
                            <span class="est-top-num"><strong><?= estadisticas_num((int) $p['interacciones']) ?></strong><small><?= estadisticas_num((int) $p['alcance']) ?> alcance</small></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
        <div class="card est-card">
            <div class="card-title"><i class="ti ti-category"></i> Publicaciones por tipo</div>
            <?= $barras(array_map(static fn($t) => ['html' => e((string) $t['tipo']), 'valor' => (int) $t['total'], 'nota' => estadisticas_num((int) $t['interacciones']) . ' interacciones'], $est['tipos']), 'Sin publicaciones en el periodo.') ?>
        </div>
    </div>

    <?php if ($est['web']): $w = $est['web_totales']; ?>
        <h2 class="est-seccion"><i class="ti ti-world"></i> Sitio web (Google Analytics)</h2>
        <section class="est-mini">
            <div><strong><?= estadisticas_num($w['usuarios']) ?></strong><span>Usuarios</span></div>
            <div><strong><?= estadisticas_num($w['nuevos_usuarios']) ?></strong><span>Nuevos usuarios</span></div>
            <div><strong><?= estadisticas_num($w['sesiones']) ?></strong><span>Sesiones</span></div>
            <div><strong><?= estadisticas_num($w['vistas']) ?></strong><span>Vistas</span></div>
            <div><strong><?= estadisticas_num($w['sesiones_redes'] + $w['sesiones_whatsapp']) ?></strong><span>Sesiones desde redes y WhatsApp</span></div>
            <div><strong><?= estadisticas_num($w['rebote'], 1) ?>%</strong><span>Rebote promedio</span></div>
        </section>
    <?php endif; ?>

    <h2 class="est-seccion"><i class="ti ti-affiliate"></i> Comités y seguimiento</h2>
    <div class="est-grid">
        <div class="card est-card">
            <div class="card-title"><i class="ti ti-calendar-event"></i> Reuniones realizadas por comité</div>
            <?= $barras(array_map(static fn($r) => ['html' => e((string) $r['comite']), 'valor' => (int) $r['realizadas'], 'total' => (int) $r['total'], 'nota' => $r['realizadas'] . ' de ' . $r['total'] . ' reuniones realizadas'], $est['reuniones_comite']), 'No hay comités activos.') ?>
            <p class="est-leyenda"><span class="est-leyenda-fill"></span> Realizadas <span class="est-leyenda-base"></span> Programadas</p>
        </div>
        <div class="card est-card">
            <div class="card-title"><i class="ti ti-clipboard-list"></i> Acuerdos por estado</div>
            <?= $barras(array_map(static fn($r) => ['html' => '<span class="badge ' . comites_estado_badge_class((string) $r['estado']) . '">' . e((string) $r['estado']) . '</span>', 'valor' => (int) $r['total']], $est['acuerdos_estado']), 'Sin acuerdos en el periodo.') ?>
        </div>
    </div>

    <div class="card est-card">
        <div class="card-title"><i class="ti ti-timeline"></i> Actividad mensual</div>
        <div class="table-wrap">
            <table class="est-tabla">
                <thead><tr><th>Mes</th><th class="num">Reuniones</th><th class="num">Realizadas</th><th class="num">Eventos</th><th class="num">Correos</th><th class="num">Publicaciones</th><th class="num">Alcance</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($est['meses'], true) as $mes => $m): ?>
                    <tr class="<?= array_sum($m) === 0 ? 'is-vacio' : '' ?>">
                        <td><?= e(estadisticas_mes_corto($mes)) ?></td>
                        <td class="num"><?= $m['reuniones'] ?: '—' ?></td>
                        <td class="num"><?= $m['reuniones_realizadas'] ?: '—' ?></td>
                        <td class="num"><?= $m['eventos'] ?: '—' ?></td>
                        <td class="num"><?= $m['correos'] ?: '—' ?></td>
                        <td class="num"><?= $m['publicaciones'] ?: '—' ?></td>
                        <td class="num"><?= $m['alcance'] ? estadisticas_num($m['alcance']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="est-seccion"><i class="ti ti-folder"></i> Documentación</h2>
    <div class="est-grid">
        <div class="card est-card">
            <div class="card-title"><i class="ti ti-folder"></i> Documentos por estado</div>
            <?= $barras(array_map(static fn($r) => ['html' => '<span class="est-ambito">' . e((string) $r['ambito']) . '</span> <span class="badge ' . comites_estado_badge_class((string) $r['estado']) . '">' . e((string) $r['estado']) . '</span>', 'valor' => (int) $r['total']], $est['documentos_estado']), 'Sin documentos en el periodo.') ?>
        </div>
        <div class="card est-card">
            <div class="card-title"><i class="ti ti-book-2"></i> Memorias por estado</div>
            <?= $barras(array_map(static fn($r) => ['html' => '<span class="badge ' . comites_estado_badge_class((string) $r['estado']) . '">' . e((string) $r['estado']) . '</span>', 'valor' => (int) $r['total']], $est['memorias_estado']), 'Sin artículos en el periodo.') ?>
        </div>
    </div>
    <div class="est-tooltip" id="est-tooltip" role="status" hidden></div>
</div>

<script src="assets/js/estadisticas.js?v=<?= @filemtime(__DIR__ . '/../assets/js/estadisticas.js') ?>" defer></script>
