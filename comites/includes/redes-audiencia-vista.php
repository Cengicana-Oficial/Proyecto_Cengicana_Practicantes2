<?php
// Pestana Audiencia de Redes sociales. Datos en $audiencia (redes_audiencia_datos()) y $periodoAud.
$plataformasRedes = redes_plataformas();
$paisesTodos = redes_audiencia_paises_combinados($audiencia);
$urlAud = static fn(array $extra = []): string => str_replace('view=estadisticas', 'view=publicaciones&tab=audiencia', estadisticas_query($periodoAud, $extra));
$lista = static function (array $items, string $dimension, int $limite = 10): string {
    if (!$items) {
        return '<div class="empty-state"><i class="ti ti-inbox"></i>Sin datos.</div>';
    }
    $items = array_slice($items, 0, $limite, true);
    $max = max(1, reset($items)['pct']);
    $html = '<ul class="aud-paises">';
    foreach ($items as $clave => $i) {
        $icono = $dimension === 'pais' ? redes_bandera((string) $clave)
            : ($dimension === 'ciudad' ? (str_contains((string) $clave, '|') ? redes_bandera(explode('|', (string) $clave)[1]) : '<span class="aud-flag aud-flag-txt"><i class="ti ti-building"></i></span>') : '');
        $html .= '<li title="' . e(estadisticas_num($i['valor'])) . '">' . $icono . '<span class="aud-pais-nombre">' . e($i['etiqueta']) . '</span>'
            . '<span class="aud-pais-barra"><span style="width:' . round($i['pct'] / $max * 100, 1) . '%"></span></span>'
            . '<span class="aud-pais-pct">' . number_format($i['pct'], 1) . '%<small>' . estadisticas_num($i['valor']) . '</small></span></li>';
    }
    return $html . '</ul>';
};
?>
<link rel="stylesheet" href="assets/css/estadisticas.css?v=<?= @filemtime(__DIR__ . '/../assets/css/estadisticas.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/css/flag-icons.min.css">

<div class="est-page rs-page">
    <div class="est-toolbar">
        <form class="est-periodo" method="get" id="est-periodo">
            <input type="hidden" name="view" value="publicaciones">
            <input type="hidden" name="tab" value="audiencia">
            <?php if ($audienciaEjemplo): ?><input type="hidden" name="ejemplo" value="1"><?php endif; ?>
            <label><span>Periodo</span>
                <select class="form-control" name="periodo" id="est-periodo-sel">
                    <?php foreach (ESTADISTICAS_PERIODOS as $clave => $etiqueta): ?>
                        <option value="<?= e($clave) ?>" <?= $periodoAud['clave'] === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <span class="est-rango" id="est-rango" <?= $periodoAud['clave'] === 'rango' ? '' : 'hidden' ?>>
                <label><span>Desde</span><input class="form-control" type="month" name="desde" value="<?= e($periodoAud['desde_mes']) ?>"></label>
                <label><span>Hasta</span><input class="form-control" type="month" name="hasta" value="<?= e($periodoAud['hasta_mes']) ?>"></label>
                <button type="submit" class="btn-outline btn-sm"><i class="ti ti-filter"></i> Aplicar</button>
            </span>
        </form>
        <?php if ($puedeGestionarSeccionActual): ?>
            <div class="est-descargas">
                <a class="btn-primary" href="<?= e($urlAud(['importar' => 'audiencia'])) ?>"><i class="ti ti-file-import"></i> Importar audiencia</a>
            </div>
        <?php endif; ?>
    </div>

    <?php
    // Estado de cada conexion: la audiencia solo llega sincronizando por API (no con los archivos de publicaciones).
    $fuentesAud = [
        'meta' => [['Facebook', 'Instagram'], 'Seguidores de Instagram por país, ciudad, edad y género; de Facebook, por país si Meta lo entrega.'],
        'linkedin' => [['LinkedIn'], 'Seguidores de la página por país.'],
        'ga4' => [['Sitio web'], 'Visitantes del sitio web por país y ciudad, mes a mes.'],
    ];
    $hayConexion = (bool) array_filter($redesConexiones, static fn($c) => $c['activo']);
    $importadas = (int) $pdo->query("SELECT COUNT(*) FROM publicaciones WHERE origen = 'importado'")->fetchColumn();
    ?>
    <?php if ($audienciaEjemplo): ?>
        <div class="aud-ejemplo">
            <i class="ti ti-eye"></i>
            <span><b>Vista de ejemplo.</b> Así se verá esta página cuando las redes estén conectadas. Las cifras son <b>ilustrativas</b>, no son datos reales de CENGICAÑA y no se guardan.</span>
            <a class="btn-outline btn-sm" href="<?= e($urlAud()) ?>"><i class="ti ti-x"></i> Salir del ejemplo</a>
            <?php if ($puedeGestionarSeccionActual): ?><a class="btn-primary btn-sm" href="index.php?view=publicaciones&tab=conexiones"><i class="ti ti-plug"></i> Conectar redes</a><?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="card est-card aud-estado" <?= $audienciaEjemplo ? 'hidden' : '' ?>>
        <div class="est-card-head">
            <div class="card-title"><i class="ti ti-plug-connected"></i> ¿De dónde sale la audiencia?</div>
            <?php if (!$hayConexion): ?><span class="aud-estado-alerta"><i class="ti ti-alert-circle"></i> Ninguna red conectada</span><?php endif; ?>
        </div>
        <?php if (!$hayConexion && $importadas): ?>
            <p class="aud-estado-nota">Sus <b><?= $importadas ?> publicaciones</b> se cargaron desde <b>archivos importados</b>. Esos archivos solo traen las métricas de cada publicación (alcance, reacciones, clics), <b>no el país de la audiencia</b>. La audiencia se obtiene sola cuando la red está <b>conectada por API</b>, o importando aparte el archivo de audiencia.</p>
        <?php endif; ?>
        <ul class="aud-estado-lista">
            <?php foreach ($fuentesAud as $prov => [$plataformasProv, $queTrae]):
                $c = $redesConexiones[$prov];
                $info = redes_api_proveedores()[$prov];
                $recibidas = array_values(array_intersect($plataformasProv, array_keys($audiencia)));
                if (!$c['activo']) {
                    [$tono, $estado] = ['off', 'No conectado'];
                } elseif ($c['ultimo_sync'] === null) {
                    [$tono, $estado] = ['pend', 'Conectado · la primera sincronización corre en segundo plano al abrir Redes sociales'];
                } elseif ($c['ultimo_estado'] === 'error') {
                    [$tono, $estado] = ['error', 'Error en la última sincronización'];
                } elseif ($recibidas) {
                    [$tono, $estado] = ['ok', 'Audiencia recibida: ' . implode(', ', $recibidas) . ' · actualizado ' . redes_api_hace($c['minutos'])];
                } else {
                    [$tono, $estado] = ['aviso', 'Conectado, pero la red no entregó audiencia en este periodo'];
                } ?>
                <li class="is-<?= e($tono) ?>">
                    <span class="aud-estado-icono" style="color:<?= e($info['color']) ?>"><i class="ti <?= e($info['icon']) ?>"></i></span>
                    <span class="aud-estado-texto">
                        <span><b><?= e($info['nombre']) ?></b> <span class="aud-estado-pill"><?= e($estado) ?></span></span>
                        <small><?= e($queTrae) ?></small>
                        <?php if ($c['activo'] && $c['ultimo_mensaje'] !== '' && $tono !== 'ok'): ?><small class="aud-estado-msg"><i class="ti ti-message-2"></i> <?= e(mb_strimwidth($c['ultimo_mensaje'], 0, 260, '…')) ?></small><?php endif; ?>
                    </span>
                    <?php if ($puedeGestionarSeccionActual): ?>
                        <?php if ($c['activo']): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['redes_csrf'] ?? '') ?>">
                                <input type="hidden" name="proveedor" value="<?= e($prov) ?>">
                                <input type="hidden" name="volver" value="audiencia">
                                <button type="submit" name="accion" value="conexion_sincronizar" class="btn-outline btn-sm" data-sync-btn><i class="ti ti-refresh"></i> Sincronizar ahora</button>
                            </form>
                        <?php else: ?>
                            <a class="btn-outline btn-sm" href="index.php?view=publicaciones&tab=conexiones#conexion-<?= e($prov) ?>"><i class="ti ti-plug"></i> Conectar</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="ana-nota">Con las redes conectadas, la audiencia se actualiza sola junto con las publicaciones (cada vez que vence el intervalo de sincronización de la conexión).</p>
    </section>

    <?php if (!$audiencia): ?>
        <div class="card est-card aud-vacio">
            <i class="ti ti-world-search"></i>
            <h3>Aún no hay datos de audiencia en este periodo</h3>
            <p>Conecte las redes en el panel de arriba o, si una red no la entrega por API, importe su archivo de audiencia:</p>
            <p><a class="btn-primary" href="<?= e($urlAud(['ejemplo' => 1])) ?>"><i class="ti ti-eye"></i> Ver cómo se verá con datos (ejemplo)</a></p>
            <div class="aud-fuentes">
                <div><b><i class="ti ti-info-circle"></i> Qué entrega cada red al conectarla</b>
                    <ul>
                        <li><b>Google Analytics:</b> país y ciudad de quienes visitan el sitio web.</li>
                        <li><b>Instagram:</b> país, ciudad, edad y género de los seguidores (cuentas profesionales con 100 seguidores o más).</li>
                        <li><b>LinkedIn:</b> país de los seguidores de la página.</li>
                        <li><b>Facebook:</b> Meta retiró los seguidores por país de la API en las versiones recientes; si no llegan, use la importación.</li>
                    </ul>
                </div>
                <div><b><i class="ti ti-file-import"></i> Importar un archivo</b> (CSV o Excel con columnas <i>País</i> y <i>Seguidores</i>, <i>Usuarios</i> o <i>Alcance</i>)
                    <ul>
                        <li><b>Meta Business Suite:</b> Estadísticas › Público: países y ciudades principales.</li>
                        <li><b>LinkedIn:</b> Página › Análisis › Seguidores › Exportar (hoja de ubicación).</li>
                        <li><b>Google Analytics:</b> Informes › Usuario › Detalles demográficos › Compartir › Descargar CSV.</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php else: ?>
        <section class="aud-fuentes-chips">
            <?php foreach ($audiencia as $plataforma => $a): $cfg = $plataformasRedes[$plataforma] ?? $plataformasRedes['Otro']; ?>
                <span class="aud-chip" style="--red:<?= e($cfg['color']) ?>">
                    <i class="ti <?= e($cfg['icon']) ?>"></i> <b><?= e($plataforma) ?></b>
                    <?= estadisticas_num($a['total']) ?> <?= $a['metrica'] === 'seguidores' ? 'seguidores' : 'visitantes' ?>
                    <small><?= e($a['cuando']) ?> · <?= e((string) $a['fuente']) ?></small>
                    <?php if ($puedeGestionarSeccionActual && !$audienciaEjemplo): ?>
                        <form method="post" onsubmit="return confirm('¿Eliminar toda la audiencia guardada de <?= e($plataforma) ?>?');">
                            <input type="hidden" name="accion" value="audiencia_eliminar"><input type="hidden" name="plataforma" value="<?= e($plataforma) ?>">
                            <button type="submit" title="Eliminar audiencia de <?= e($plataforma) ?>"><i class="ti ti-x"></i></button>
                        </form>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </section>

        <?php
        // Datos clave como los del informe mensual: pais, ciudad, edad y genero principales.
        $buscarDim = static function (string $dim) use ($audiencia): ?array {
            foreach ($audiencia as $pl => $a) {
                if (!empty($a['dimensiones'][$dim])) {
                    return [$pl, $a['dimensiones'][$dim]];
                }
            }
            return null;
        };
        $datosClave = [];
        if ($paisesTodos) {
            $primerPais = reset($paisesTodos);
            $fueraGt = array_sum(array_column(array_diff_key($paisesTodos, ['GT' => 1]), 'pct'));
            $datosClave[] = ['ti-flag', 'País principal', e($primerPais['etiqueta']) . ' · ' . number_format($primerPais['pct'], 0) . '%', count($paisesTodos) . ' países en total'];
            $datosClave[] = ['ti-world', 'Audiencia internacional', number_format($fueraGt, 1) . '%', 'fuera de Guatemala' . (count($paisesTodos) > 1 ? ' · después: ' . e(implode(', ', array_slice(array_column(array_slice($paisesTodos, 1, 3, true), 'etiqueta'), 0, 3))) : '')];
        }
        if ($ciudad = $buscarDim('ciudad')) {
            [$plC, $itemsC] = $ciudad;
            $top3 = array_slice($itemsC, 0, 3, true);
            $datosClave[] = ['ti-building', 'Ciudad principal', e(explode(',', reset($top3)['etiqueta'])[0]) . ' · ' . number_format(reset($top3)['pct'], 1) . '%', 'Top 3: ' . number_format(array_sum(array_column($top3, 'pct')), 0) . '% · ' . e($plC)];
        }
        if ($edad = $buscarDim('edad')) {
            [$plE, $itemsE] = $edad;
            uasort($itemsE, static fn($x, $y) => $y['valor'] <=> $x['valor']);
            $edadTop = array_key_first($itemsE);
            $joven = array_sum(array_map(static fn($k, $i) => in_array($k, ['18-24', '25-34', '35-44'], true) ? $i['pct'] : 0, array_keys($itemsE), $itemsE));
            $datosClave[] = ['ti-cake', 'Edad principal', e((string) $edadTop) . ' años · ' . number_format($itemsE[$edadTop]['pct'], 0) . '%', number_format($joven, 0) . '% tiene entre 18 y 44 años · ' . e($plE)];
        }
        if ($genero = $buscarDim('genero')) {
            [$plG, $itemsG] = $genero;
            $datosClave[] = ['ti-gender-bigender', 'Género', number_format($itemsG['M']['pct'] ?? 0, 0) . '% hombres · ' . number_format($itemsG['F']['pct'] ?? 0, 0) . '% mujeres', e($plG)];
        }
        ?>
        <?php if ($datosClave): ?>
            <section class="aud-clave">
                <?php foreach ($datosClave as [$icono, $titulo, $valor, $detalle]): ?>
                    <div class="aud-clave-item"><span><i class="ti <?= $icono ?>"></i> <?= e($titulo) ?></span><strong><?= $valor ?></strong><small><?= $detalle ?></small></div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if (count($audiencia) > 1 && $paisesTodos): ?>
            <div class="card est-card">
                <div class="card-title"><i class="ti ti-world"></i> Países principales · todas las fuentes</div>
                <div class="aud-columnas"><?= $lista($paisesTodos, 'pais', 16) ?></div>
                <p class="ana-nota">Combina seguidores de cada red y visitantes del sitio. Pase el cursor sobre un país para ver la cantidad.</p>
            </div>
        <?php endif; ?>

        <div class="est-seg aud-seg" role="tablist" aria-label="Plataforma">
            <?php $primera = array_key_first($audiencia); foreach ($audiencia as $plataforma => $a): ?>
                <button type="button" role="tab" data-serie="<?= e(md5($plataforma)) ?>" aria-selected="<?= $plataforma === $primera ? 'true' : 'false' ?>"><?= e($plataforma) ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($audiencia as $plataforma => $a): ?>
            <div class="est-grid" data-serie-panel="<?= e(md5($plataforma)) ?>" <?= $plataforma === $primera ? '' : 'hidden' ?>>
                <?php foreach (['pais' => 'ti-flag', 'ciudad' => 'ti-building', 'edad' => 'ti-cake', 'genero' => 'ti-gender-bigender'] as $dim => $icono): if (empty($a['dimensiones'][$dim])) { continue; } ?>
                    <div class="card est-card">
                        <div class="card-title"><i class="ti <?= e($icono) ?>"></i> <?= e(REDES_AUDIENCIA_DIMENSIONES[$dim]) ?> · <?= e($plataforma) ?></div>
                        <?php if ($dim === 'genero'): $total = array_sum(array_column($a['dimensiones'][$dim], 'valor')) ?: 1; ?>
                            <div class="aud-genero">
                                <?php foreach ($a['dimensiones'][$dim] as $clave => $i): ?>
                                    <span class="aud-genero-<?= e(strtolower((string) $clave)) ?>" style="flex:<?= max(1, $i['valor']) ?>" title="<?= e($i['etiqueta']) ?>: <?= estadisticas_num($i['valor']) ?>"></span>
                                <?php endforeach; ?>
                            </div>
                            <ul class="aud-genero-leyenda">
                                <?php foreach ($a['dimensiones'][$dim] as $clave => $i): ?>
                                    <li><span class="aud-genero-<?= e(strtolower((string) $clave)) ?>"></span><?= e($i['etiqueta']) ?> <b><?= number_format($i['pct'], 1) ?>%</b></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif ($dim === 'edad'): ksort($a['dimensiones'][$dim], SORT_NATURAL); ?>
                            <?= estadisticas_barras_html(array_map(static fn($i) => ['html' => e($i['etiqueta']), 'valor' => $i['valor'], 'texto' => number_format($i['pct'], 1) . '%', 'detalle' => estadisticas_num($i['valor'])], array_values($a['dimensiones'][$dim])), 'Sin datos.') ?>
                        <?php else: ?>
                            <?= $lista($a['dimensiones'][$dim], $dim) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($puedeGestionarSeccionActual && ($_GET['importar'] ?? '') === 'audiencia'): ?>
    <div class="modal-backdrop is-open" data-close-href="<?= e($urlAud()) ?>">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-head">
                <h3>Importar audiencia</h3>
                <a class="modal-close" href="<?= e($urlAud()) ?>"><i class="ti ti-x"></i></a>
            </div>
            <form method="post" class="modal-body" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="audiencia_importar">
                <div class="form-grid">
                    <label class="form-group"><span class="form-label">Plataforma <span class="req">*</span></span>
                        <select class="form-control" name="plataforma" required>
                            <?php foreach (['Facebook', 'Instagram', 'LinkedIn', 'Sitio web', 'YouTube', 'TikTok', 'X'] as $opt): ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php endforeach; ?>
                        </select></label>
                    <label class="form-group"><span class="form-label">Mes de los datos</span>
                        <input class="form-control" type="month" name="periodo" value="<?= e(date('Y-m')) ?>"></label>
                </div>
                <label class="form-group"><span class="form-label">Archivo (CSV, XLSX o XLS) <span class="req">*</span></span>
                    <input class="form-control" type="file" name="archivo" accept=".csv,.xlsx,.xls" required></label>
                <div class="red-ayuda">
                    <strong><i class="ti ti-info-circle"></i> Formato del archivo</strong>
                    <p>Una columna con <b>País</b>, <b>Ciudad</b>, <b>Edad</b> o <b>Género</b>, y otra con la cantidad (<b>Seguidores</b>, <b>Usuarios</b>, <b>Alcance</b> o <b>Valor</b>). Sirven los nombres en español o inglés y los códigos de país (GT, MX, US).</p>
                    <p>Si la red no permite exportar, copie los países que muestra en sus estadísticas a un Excel con esas dos columnas. Importar de nuevo el mismo mes reemplaza los datos.</p>
                </div>
                <div class="actions">
                    <a class="btn-outline" href="<?= e($urlAud()) ?>">Cancelar</a>
                    <button type="submit" class="btn-primary"><i class="ti ti-file-import"></i> Importar</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<script src="assets/js/estadisticas.js?v=<?= @filemtime(__DIR__ . '/../assets/js/estadisticas.js') ?>" defer></script>
