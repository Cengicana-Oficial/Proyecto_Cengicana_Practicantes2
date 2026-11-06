<?php
// Pestana "Publicaciones y WhatsApp": lista con rendimiento de cada publicacion frente
// al promedio de su red, desglose de interacciones, tema y pauta, vista de tarjetas y
// ficha de diagnostico. Datos en $publicaciones (con 'eval'), $resumenRedes y filtros.
$totalesRedes = ['total' => 0, 'alcance' => 0, 'interacciones' => 0, 'clics' => 0];
foreach ($resumenRedes as $r) {
    foreach ($totalesRedes as $k => $v) {
        $totalesRedes[$k] += (int) $r[$k];
    }
}
$hayFiltros = $q !== '' || $filtroPlataforma !== '' || $filtroMes !== '' || $filtroPilar !== '' || $filtroPauta !== '' || $filtroNivel !== '';
$desgloseEtiquetas = ['reacciones' => 'Reacciones', 'comentarios' => 'Comentarios', 'compartidos' => 'Compartidos', 'guardados' => 'Guardados', 'clics' => 'Clics'];
$conteoNiveles = array_count_values(array_map(static fn($p) => $p['eval']['nivel'], $publicaciones));

/** Miniatura: imagen del material o icono de la red. */
$miniatura = static function (array $p, string $clase = 'pub-mini') use ($plataformasRedes): string {
    $cfg = $plataformasRedes[$p['plataforma']] ?? $plataformasRedes['Otro'];
    $img = !empty($p['material']) && preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $p['material']);
    return '<span class="' . $clase . '" style="--red:' . e($cfg['color']) . '">' . ($img ? '<img src="' . e((string) $p['material']) . '" alt="" loading="lazy">' : '<i class="ti ' . e($cfg['icon']) . '"></i>') . '</span>';
};
/** Barra apilada del desglose de interacciones (con tooltip por segmento). */
$barraDesglose = static function (array $ev) use ($desgloseEtiquetas): string {
    if (!array_sum($ev['partes'])) {
        return '<span class="pub-desglose is-vacio" title="Sin desglose"></span>';
    }
    $h = '<span class="pub-desglose" role="img" aria-label="Desglose de interacciones">';
    foreach ($desgloseEtiquetas as $k => $et) {
        if ($ev['partes'][$k] > 0) {
            $h .= '<span class="d-' . $k . '" style="flex:' . $ev['partes'][$k] . '" title="' . e($et) . ': ' . estadisticas_num($ev['partes'][$k]) . ' (' . number_format($ev['desglose'][$k], 0) . '%)"></span>';
        }
    }
    return $h . '</span>';
};
$insignia = static function (array $ev): string {
    [$et, $clase, $icono] = REDES_NIVELES[$ev['nivel']];
    return '<span class="pub-nivel ' . $clase . '"><i class="ti ' . $icono . '"></i> ' . e($et) . '</span>';
};
$chipsPub = static function (array $p) use ($chipPlataforma): string {
    $ev = $p['eval'];
    $h = $chipPlataforma((string) $p['plataforma']);
    if (!empty($p['tipo'])) {
        $h .= '<span class="pub-chip">' . e(redes_tipo_normalizado((string) $p['tipo'])) . '</span>';
    }
    if ($ev['pilar'] !== '') {
        $h .= '<span class="pub-chip pub-chip-pilar' . ($ev['pilar_sugerido'] ? ' is-sugerido' : '') . '" title="' . ($ev['pilar_sugerido'] ? 'Sugerido por el texto; confírmelo al editar' : 'Pilar de contenido') . '"><i class="ti ti-category-2"></i> ' . e($ev['pilar']) . '</span>';
    }
    if (!empty($p['pautada'])) {
        $h .= '<span class="pub-chip pub-chip-pauta"><i class="ti ti-cash"></i> Pautada' . ((float) $p['inversion'] > 0 ? ' · ' . redes_quetzales((float) $p['inversion']) : '') . '</span>';
    }
    if (($p['origen'] ?? '') === 'importado' || ($p['origen'] ?? '') === 'api') {
        $h .= '<span class="red-origen" title="' . (($p['origen'] ?? '') === 'api' ? 'Métricas sincronizadas por API' : 'Métricas importadas del archivo exportado') . '"><i class="ti ' . (($p['origen'] ?? '') === 'api' ? 'ti-refresh' : 'ti-file-import') . '"></i> ' . (($p['origen'] ?? '') === 'api' ? 'API' : 'Importado') . '</span>';
    }
    if ($p['estado'] !== 'Publicada') {
        $h .= '<span class="badge ' . comites_estado_badge_class((string) $p['estado']) . '">' . e((string) $p['estado']) . '</span>';
    }
    return $h;
};
$tituloPub = static function (array $p): string {
    $t = trim((string) $p['titulo']);
    return ($t === '' || preg_match('/^Publicación( de \S+)?( \d{4}-\d{2}-\d{2})?$/u', $t)) && $p['eval']['texto'] !== '' ? $p['eval']['texto'] : $t;
};
/** Extracto del texto publicado cuando aporta algo distinto al titulo. */
$extracto = static function (array $p) use (&$tituloPub): string {
    $copy = trim((string) ($p['copy_texto'] ?? ''));
    if ($copy === '' || $copy === $tituloPub($p)) {
        return '';
    }
    $t = $tituloPub($p);
    $copy = str_starts_with($copy, $t) ? ltrim(mb_substr($copy, mb_strlen($t)), ' .:-–') : $copy;
    return $copy;
};
?>
<link rel="stylesheet" href="assets/css/estadisticas.css?v=<?= @filemtime(__DIR__ . '/../assets/css/estadisticas.css') ?>">

<div class="pub-page">
    <section class="red-resumen">
        <a class="red-card red-card-total <?= $filtroPlataforma === '' ? 'is-active' : '' ?>" href="<?= e($urlFiltros(['plataforma' => ''])) ?>">
            <span class="red-card-head"><i class="ti ti-chart-donut"></i> Todas</span>
            <strong><?= redes_formato($totalesRedes['total']) ?></strong><small>publicaciones</small>
            <span class="red-card-meta"><?= redes_formato($totalesRedes['alcance']) ?> alcance · <?= redes_formato($totalesRedes['interacciones']) ?> interacciones<?= $totalesRedes['alcance'] ? ' · ' . number_format($totalesRedes['interacciones'] / $totalesRedes['alcance'] * 100, 1) . '% tasa' : '' ?></span>
        </a>
        <?php foreach ($resumenRedes as $r): $cfg = $plataformasRedes[$r['plataforma']] ?? $plataformasRedes['Otro']; $esWa = $r['plataforma'] === 'WhatsApp'; $ref = $promediosRedes[$r['plataforma']] ?? null; ?>
            <a class="red-card <?= $filtroPlataforma === $r['plataforma'] ? 'is-active' : '' ?>" style="--red:<?= e($cfg['color']) ?>" href="<?= e($urlFiltros(['plataforma' => $r['plataforma']])) ?>">
                <span class="red-card-head"><i class="ti <?= e($cfg['icon']) ?>"></i> <?= e((string) $r['plataforma']) ?></span>
                <strong><?= redes_formato((int) $r['total']) ?></strong><small><?= $esWa ? 'envíos' : 'publicaciones' ?></small>
                <span class="red-card-meta">
                    <?php if ($esWa): ?>
                        <?= redes_formato((int) $r['alcance']) ?> personas · <?= redes_formato((int) $r['compartidos']) ?> reenvíos
                    <?php else: ?>
                        <?= redes_formato((int) $r['alcance']) ?> alcance · <?= redes_formato((int) $r['interacciones']) ?> interacciones
                    <?php endif; ?>
                </span>
                <?php if ($ref && $ref['n'] >= REDES_MIN_MUESTRA): ?><span class="red-card-meta" title="Promedio de 12 meses usado para comparar cada publicación">Referencia: <b><?= number_format($ref['tasa'], 1) ?>%</b> de tasa<?= $ref['organica'] ? ' orgánica' : '' ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </section>

    <form class="pub-filtros" method="get">
        <input type="hidden" name="view" value="publicaciones">
        <input type="hidden" name="tab" value="publicaciones">
        <?php if ($vistaLista === 'tarjetas'): ?><input type="hidden" name="vista" value="tarjetas"><?php endif; ?>
        <div class="search-box">
            <i class="ti ti-search"></i>
            <input class="form-control" type="text" name="q" placeholder="Buscar en título, texto, tipo o grupo..." value="<?= e($q) ?>">
        </div>
        <select class="form-control" name="plataforma" aria-label="Plataforma" onchange="this.form.submit()">
            <option value="">Todas las redes</option>
            <?php foreach (array_keys($plataformasRedes) as $opt): ?><option value="<?= e($opt) ?>" <?= $filtroPlataforma === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
        </select>
        <input class="form-control" type="month" name="mes" value="<?= e($filtroMes) ?>" aria-label="Mes" onchange="this.form.submit()">
        <select class="form-control" name="pilar" aria-label="Tema" onchange="this.form.submit()">
            <option value="">Todos los temas</option>
            <?php foreach ([...REDES_PILARES, 'Sin clasificar'] as $opt): ?><option value="<?= e($opt) ?>" <?= $filtroPilar === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
        </select>
        <select class="form-control" name="pauta" aria-label="Pauta" onchange="this.form.submit()">
            <option value="">Orgánicas y pautadas</option>
            <option value="organica" <?= $filtroPauta === 'organica' ? 'selected' : '' ?>>Solo orgánicas</option>
            <option value="pautada" <?= $filtroPauta === 'pautada' ? 'selected' : '' ?>>Solo pautadas</option>
        </select>
        <select class="form-control" name="nivel" aria-label="Rendimiento" onchange="this.form.submit()">
            <option value="">Todo rendimiento</option>
            <?php foreach (REDES_NIVELES as $clave => [$et]): ?><option value="<?= e($clave) ?>" <?= $filtroNivel === $clave ? 'selected' : '' ?>><?= e($et) ?></option><?php endforeach; ?>
        </select>
        <select class="form-control" name="orden" aria-label="Ordenar" onchange="this.form.submit()">
            <?php foreach (['fecha' => 'Más recientes', 'tasa' => 'Mayor tasa de interacción', 'interacciones' => 'Más interacciones', 'alcance' => 'Mayor alcance'] as $clave => $et): ?><option value="<?= e($clave) ?>" <?= $ordenLista === $clave ? 'selected' : '' ?>><?= e($et) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Filtrar</button>
        <?php if ($hayFiltros): ?><a class="btn-outline btn-sm" href="index.php?view=publicaciones&tab=publicaciones">Limpiar</a><?php endif; ?>
    </form>

    <div class="pub-barra">
        <div class="pub-conteo">
            <b><?= count($publicaciones) ?></b> publicaciones
            <?php foreach (['destacada', 'arriba', 'abajo'] as $nv): if (!empty($conteoNiveles[$nv])): [$et, $clase, $icono] = REDES_NIVELES[$nv]; ?>
                <a class="pub-nivel <?= $clase ?>" href="<?= e($urlFiltros(['nivel' => $nv])) ?>"><i class="ti <?= $icono ?>"></i> <?= $conteoNiveles[$nv] ?> <?= e(mb_strtolower($et)) ?></a>
            <?php endif; endforeach; ?>
        </div>
        <div class="toolbar-actions">
            <div class="pub-vista" role="group" aria-label="Vista">
                <a class="<?= $vistaLista === 'lista' ? 'is-active' : '' ?>" href="<?= e($urlFiltros(['vista' => ''])) ?>" title="Lista"><i class="ti ti-list"></i></a>
                <a class="<?= $vistaLista === 'tarjetas' ? 'is-active' : '' ?>" href="<?= e($urlFiltros(['vista' => 'tarjetas'])) ?>" title="Tarjetas"><i class="ti ti-layout-grid"></i></a>
            </div>
            <a class="btn-outline" href="<?= e($urlFiltros(['exportar' => 'xlsx'])) ?>" title="Descarga en Excel las publicaciones con los filtros actuales, con su rendimiento"><i class="ti ti-file-spreadsheet"></i> Excel</a>
            <?php if ($puedeGestionarSeccionActual): ?>
                <a class="btn-outline" href="<?= e($urlFiltros(['importar' => 'redes'])) ?>"><i class="ti ti-file-import"></i> Importar</a>
                <a class="btn-outline" href="index.php?view=publicaciones&tab=publicaciones&nuevo=1&plataforma_nueva=WhatsApp"><i class="ti ti-brand-whatsapp"></i> Registrar envío</a>
                <a class="btn-primary" href="index.php?view=publicaciones&tab=publicaciones&nuevo=1"><i class="ti ti-plus"></i> Nueva publicación</a>
            <?php endif; ?>
        </div>
    </div>

    <p class="pub-leyenda">
        <span><i class="ti ti-info-circle"></i> El rendimiento compara la <b>tasa de interacción</b> de cada publicación con el promedio de su red (orgánico, últimos 12 meses).</span>
        <span class="pub-leyenda-desglose">Interacciones:
            <?php foreach ($desgloseEtiquetas as $k => $et): ?><i class="d-<?= e($k) ?>"></i><?= e($et) ?> <?php endforeach; ?>
        </span>
    </p>

    <?php if (!$publicaciones): ?>
        <div class="table-card"><div class="empty-state"><i class="ti ti-inbox"></i><?= $hayFiltros ? 'No hay publicaciones con estos filtros.' : 'Todavía no hay publicaciones registradas. Regístralas a mano, importa las estadísticas exportadas de cada red o conecta las cuentas.' ?></div></div>
    <?php elseif ($vistaLista === 'tarjetas'): ?>
        <div class="pub-tarjetas">
            <?php foreach ($publicaciones as $p): $ev = $p['eval']; $esWa = $p['plataforma'] === 'WhatsApp'; ?>
                <article class="pub-tarjeta">
                    <a class="pub-tarjeta-media" href="<?= e($urlFiltros(['ficha' => (int) $p['id']])) ?>">
                        <?= $miniatura($p, 'pub-media') ?>
                        <?= $insignia($ev) ?>
                    </a>
                    <div class="pub-tarjeta-cuerpo">
                        <div class="pub-chips"><?= $chipsPub($p) ?></div>
                        <small class="text-muted"><?= $p['fecha'] ? e($ev['dia'] . ' ' . date('d/m/Y', strtotime((string) $p['fecha']))) : 'Sin fecha' ?></small>
                        <p><?= e(mb_strimwidth($tituloPub($p), 0, 110, '…')) ?></p>
                        <?php if ($extracto($p) !== ''): ?><small class="pub-extracto"><?= e(mb_strimwidth($extracto($p), 0, 140, '…')) ?></small><?php endif; ?>
                    </div>
                    <div class="pub-tarjeta-metricas">
                        <span><b><?= $ev['tasa'] !== null ? number_format($ev['tasa'], 1) . '%' : '—' ?></b>tasa<?= $ev['indice'] !== null ? ' · ' . redes_veces($ev['indice']) : '' ?></span>
                        <span><b><?= estadisticas_num($ev['alcance']) ?></b><?= $esWa ? 'personas' : 'alcance' ?></span>
                        <span><b><?= estadisticas_num($ev['interacciones']) ?></b>interacciones</span>
                        <span><b><?= estadisticas_num((int) $p['clics']) ?></b>clics</span>
                    </div>
                    <div class="pub-tarjeta-desglose"><?= $barraDesglose($ev) ?></div>
                    <a class="pub-tarjeta-ficha" href="<?= e($urlFiltros(['ficha' => (int) $p['id']])) ?>"><i class="ti ti-report-analytics"></i> Ver ficha</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="table-card">
            <div class="table-wrap">
                <table class="red-tabla pub-tabla">
                    <thead><tr><th>Publicación</th><th>Rendimiento</th><th class="num">Alcance</th><th>Interacciones</th><th class="num">Clics</th><th class="acc"></th></tr></thead>
                    <tbody>
                    <?php foreach ($publicaciones as $p): $ev = $p['eval']; $esWa = $p['plataforma'] === 'WhatsApp'; ?>
                        <tr>
                            <td class="cell-wide">
                                <div class="pub-celda">
                                    <a href="<?= e($urlFiltros(['ficha' => (int) $p['id']])) ?>" aria-label="Ver ficha"><?= $miniatura($p) ?></a>
                                    <div class="pub-celda-texto">
                                        <div class="pub-chips"><?= $chipsPub($p) ?></div>
                                        <a class="pub-titulo" href="<?= e($urlFiltros(['ficha' => (int) $p['id']])) ?>"><?= e(mb_strimwidth($tituloPub($p), 0, 110, '…')) ?></a>
                                        <?php if ($extracto($p) !== ''): ?><span class="pub-extracto"><?= e(mb_strimwidth($extracto($p), 0, 160, '…')) ?></span><?php endif; ?>
                                        <small class="text-muted"><?= $p['fecha'] ? e($ev['dia'] . ' ' . date('d/m/Y', strtotime((string) $p['fecha']))) : 'Sin fecha' ?><?php if ($esWa && !empty($p['destinos'])): ?> · <i class="ti ti-users-group"></i> <?= e(mb_strimwidth((string) $p['destinos'], 0, 80, '…')) ?><?php endif; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="pub-rend">
                                    <?= $insignia($ev) ?>
                                    <?php if ($ev['tasa'] !== null): ?>
                                        <span><b><?= number_format($ev['tasa'], 1) ?>%</b> tasa<?= $ev['indice'] !== null ? ' · <span class="' . ($ev['indice'] >= 1.15 ? 'txt-up' : ($ev['indice'] <= 0.85 ? 'txt-down' : '')) . '" title="Comparado con el promedio de ' . e($ev['grupo_ref']) . '">' . redes_veces($ev['indice']) . ' el promedio</span>' : '' ?></span>
                                        <?php if ($ev['ref_tipo'] === 'pagada'): ?><small class="pub-ref">vs. otras pautadas</small><?php elseif ($ev['ref_tipo'] === 'organica_vs_pautada' && $ev['indice'] !== null): ?><small class="pub-ref" title="Aún no hay 3 pautadas para compararlas entre sí; la pauta suele bajar la tasa">vs. orgánicas (pauta)</small><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="num"><?= estadisticas_num($ev['alcance']) ?><?php if ($esWa): ?><small>personas</small><?php endif; ?></td>
                            <td class="pub-inter">
                                <b><?= estadisticas_num($ev['interacciones']) ?></b>
                                <?= $barraDesglose($ev) ?>
                                <small><?= $ev['partes']['comentarios'] ?> com. · <?= $ev['partes']['compartidos'] ?> <?= $esWa ? 'reenv.' : 'comp.' ?><?= $ev['partes']['guardados'] ? ' · ' . $ev['partes']['guardados'] . ' guard.' : '' ?></small>
                            </td>
                            <td class="num"><?= estadisticas_num((int) $p['clics']) ?><?php if ($ev['ctr'] !== null && (int) $p['clics']): ?><small><?= number_format($ev['ctr'], 1) ?> por 100</small><?php endif; ?></td>
                            <td class="acc">
                                <div class="pub-acciones">
                                    <a class="btn-outline btn-sm" href="<?= e($urlFiltros(['ficha' => (int) $p['id']])) ?>" title="Diagnóstico de la publicación"><i class="ti ti-report-analytics"></i> Ficha</a>
                                    <?php if ($puedeGestionarSeccionActual || !empty($p['url'])): ?>
                                    <details class="row-menu">
                                        <summary aria-label="Más acciones"><i class="ti ti-dots-vertical"></i></summary>
                                        <div class="row-menu-list" role="menu">
                                            <?php if (!empty($p['url'])): ?><a href="<?= e((string) $p['url']) ?>" target="_blank" rel="noopener noreferrer"><i class="ti ti-external-link"></i> Ver publicación</a><?php endif; ?>
                                            <?php if (!empty($p['material'])): ?><a href="<?= e((string) $p['material']) ?>" target="_blank" rel="noopener"><i class="ti ti-photo"></i> Ver material</a><?php endif; ?>
                                            <?php if ($puedeGestionarSeccionActual): ?>
                                                <a href="<?= e($urlFiltros(['editar' => (int) $p['id']])) ?>"><i class="ti ti-edit"></i> Editar y actualizar métricas</a>
                                                <form method="post" onsubmit="return confirm('¿Eliminar esta publicación?');">
                                                    <input type="hidden" name="accion" value="publicacion_eliminar">
                                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                                    <button type="submit" class="is-danger"><i class="ti ti-trash"></i> Eliminar</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
// Ficha de diagnostico de una publicacion (?ficha=id).
$fichaId = (int) ($_GET['ficha'] ?? 0);
$ficha = null;
if ($fichaId > 0) {
    foreach ($publicaciones as $p) {
        if ((int) $p['id'] === $fichaId) {
            $ficha = $p;
        }
    }
    if (!$ficha) {
        $stmtFicha = $pdo->prepare('SELECT * FROM publicaciones WHERE id = ?');
        $stmtFicha->execute([$fichaId]);
        $ficha = $stmtFicha->fetch() ?: null;
        if ($ficha) {
            $ficha['eval'] = redes_evaluar_publicacion($ficha, $promediosRedes);
        }
    }
}
if ($ficha):
    $ev = $ficha['eval'];
    $ref = $ev['referencia'];
    $esWa = $ficha['plataforma'] === 'WhatsApp';
    $cerrarFicha = $urlFiltros();
    $maxTasa = max(1, ($ev['tasa'] ?? 0), ($ref['tasa'] ?? 0) * 1.6);
    $comparar = $ref ? [
        ['Tasa de interacción', $ev['tasa'], $ref['tasa'], '%'],
        ['Clics por cada 100 alcanzados', $ev['ctr'], $ref['ctr'], ''],
        ['Compartidos (% de interacciones)', $ev['interacciones'] ? $ev['partes']['compartidos'] / $ev['interacciones'] * 100 : null, $ref['pct_compartidos'], '%'],
        ['Comentarios (% de interacciones)', $ev['interacciones'] ? $ev['partes']['comentarios'] / $ev['interacciones'] * 100 : null, $ref['pct_comentarios'], '%'],
        ['Alcance', (float) $ev['alcance'], $ref['alcance'], 'n'],
    ] : [];
?>
    <div class="modal-backdrop is-open" data-close-href="<?= e($cerrarFicha) ?>">
        <div class="modal-box modal-wide pub-ficha" onclick="event.stopPropagation()">
            <div class="modal-head">
                <h3><i class="ti ti-report-analytics"></i> Ficha de la publicación</h3>
                <a class="modal-close" href="<?= e($cerrarFicha) ?>"><i class="ti ti-x"></i></a>
            </div>
            <div class="modal-body">
                <div class="pub-ficha-grid">
                    <div class="pub-ficha-post">
                        <?= $miniatura($ficha, 'pub-media pub-ficha-media') ?>
                        <div class="pub-chips"><?= $chipsPub($ficha) ?></div>
                        <small class="text-muted"><?= $ficha['fecha'] ? e($ev['dia'] . ' ' . date('d/m/Y', strtotime((string) $ficha['fecha']))) : 'Sin fecha' ?><?= !empty($ficha['responsable']) ? ' · ' . e((string) $ficha['responsable']) : '' ?></small>
                        <strong class="pub-ficha-titulo"><?= e($tituloPub($ficha)) ?></strong>
                        <?php if ($extracto($ficha) !== ''): ?><p class="pub-ficha-texto"><?= nl2br(e(mb_strimwidth($extracto($ficha), 0, 900, '…'))) ?></p><?php endif; ?>
                        <?php if (!empty($ficha['url'])): ?><a class="rs-link" href="<?= e((string) $ficha['url']) ?>" target="_blank" rel="noopener noreferrer"><i class="ti ti-external-link"></i> Ver publicación original</a><?php endif; ?>
                    </div>
                    <div class="pub-ficha-datos">
                        <div class="pub-ficha-nivel"><?= $insignia($ev) ?><?php if ($ev['indice'] !== null): ?><span><b><?= redes_veces($ev['indice']) ?></b> el promedio de <?= e($ev['grupo_ref']) ?></span><?php endif; ?></div>
                        <div class="pub-ficha-kpis">
                            <span><small><?= $esWa ? 'Personas alcanzadas' : 'Alcance' ?></small><b><?= estadisticas_num($ev['alcance']) ?></b></span>
                            <span><small>Interacciones</small><b><?= estadisticas_num($ev['interacciones']) ?></b></span>
                            <span><small>Tasa de interacción</small><b><?= $ev['tasa'] !== null ? number_format($ev['tasa'], 1) . '%' : '—' ?></b></span>
                            <span><small>Clics</small><b><?= estadisticas_num((int) $ficha['clics']) ?></b><?php if ($ev['ctr'] !== null): ?><em><?= number_format($ev['ctr'], 1) ?> por 100</em><?php endif; ?></span>
                            <?php if ((int) $ficha['impresiones']): ?><span><small>Impresiones</small><b><?= estadisticas_num((int) $ficha['impresiones']) ?></b></span><?php endif; ?>
                            <?php if ((int) $ficha['reproducciones']): ?><span><small>Reproducciones</small><b><?= estadisticas_num((int) $ficha['reproducciones']) ?></b></span><?php endif; ?>
                        </div>
                        <?php if ($ev['tasa'] !== null && $ref): ?>
                            <div class="pub-ficha-medidor" title="Tasa de esta publicación frente al promedio de su red">
                                <span class="pub-ficha-medidor-fill" style="width:<?= round(min(100, $ev['tasa'] / $maxTasa * 100), 1) ?>%"></span>
                                <span class="pub-ficha-medidor-ref" style="left:<?= round($ref['tasa'] / $maxTasa * 100, 1) ?>%"></span>
                            </div>
                            <p class="est-leyenda"><span class="est-leyenda-fill"></span> Esta publicación <span class="est-leyenda-ref"></span> Promedio de <?= e($ev['grupo_ref']) ?> (<?= number_format($ref['tasa'], 1) ?>%, <?= $ref['n'] ?> publicaciones)</p>
                        <?php endif; ?>

                        <h4>Cómo interactuaron</h4>
                        <?php if (array_sum($ev['partes'])): ?>
                            <?= $barraDesglose($ev) ?>
                            <ul class="pub-ficha-desglose">
                                <?php foreach ($desgloseEtiquetas as $k => $et): if (!$ev['partes'][$k]) { continue; } ?>
                                    <li><i class="d-<?= e($k) ?>"></i><?= e($esWa && $k === 'compartidos' ? 'Reenvíos' : ($esWa && $k === 'comentarios' ? 'Respuestas' : $et)) ?> <b><?= estadisticas_num($ev['partes'][$k]) ?></b> <small><?= number_format($ev['desglose'][$k], 0) ?>%</small></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted">Sin desglose de interacciones registrado.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($ev['hallazgos']): ?>
                    <h4>Qué nos dice esta publicación</h4>
                    <ul class="pub-hallazgos">
                        <?php foreach ($ev['hallazgos'] as [$tono, $icono, $html]): ?>
                            <li class="is-<?= e($tono) ?>"><i class="ti <?= e($icono) ?>"></i><span><?= $html ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($comparar): ?>
                    <h4>Frente al promedio de <?= e($ev['grupo_ref']) ?></h4>
                    <table class="pub-comparar">
                        <thead><tr><th></th><th class="num">Esta publicación</th><th class="num">Promedio</th><th class="num">Diferencia</th></tr></thead>
                        <tbody>
                        <?php foreach ($comparar as [$et, $valor, $prom, $u]):
                            $fmt = static fn($v) => $v === null ? '—' : ($u === 'n' ? estadisticas_num($v) : number_format($v, 1) . $u);
                            $dif = $valor !== null && $prom > 0 ? ($valor - $prom) / $prom * 100 : null; ?>
                            <tr><td><?= e($et) ?></td><td class="num"><b><?= $fmt($valor) ?></b></td><td class="num"><?= $fmt($prom) ?></td>
                                <td class="num <?= $dif === null ? '' : ($dif >= 15 ? 'txt-up' : ($dif <= -15 ? 'txt-down' : '')) ?>"><?= $dif === null ? '—' : ($dif >= 0 ? '+' : '') . number_format($dif, 0) . '%' ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!empty($ficha['pautada'])): ?><p class="ana-nota">Esta publicación fue pautada: su alcance incluye publicidad, por eso conviene juzgarla por la tasa y el costo, no por el alcance.</p><?php endif; ?>
                <?php elseif ($ev['tasa'] !== null): ?>
                    <p class="ana-nota">Aún no hay suficientes publicaciones de <?= e((string) $ficha['plataforma']) ?> con alcance (mínimo <?= REDES_MIN_MUESTRA ?>) para comparar con un promedio.</p>
                <?php endif; ?>

                <?php if ($ev['faltan']): ?>
                    <div class="pub-faltan"><i class="ti ti-alert-circle"></i><span>Para analizarla mejor falta: <b><?= e(implode(', ', $ev['faltan'])) ?></b>.</span>
                        <?php if ($puedeGestionarSeccionActual): ?><a class="btn-outline btn-sm" href="<?= e($urlFiltros(['editar' => (int) $ficha['id']])) ?>"><i class="ti ti-edit"></i> Completar</a><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="actions">
                    <a class="btn-outline" href="<?= e($cerrarFicha) ?>">Cerrar</a>
                    <?php if ($puedeGestionarSeccionActual): ?><a class="btn-primary" href="<?= e($urlFiltros(['editar' => (int) $ficha['id']])) ?>"><i class="ti ti-edit"></i> Editar y actualizar métricas</a><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
