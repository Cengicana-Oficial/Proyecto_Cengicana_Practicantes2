<link rel="stylesheet" href="assets/css/biblioteca.css?v=<?= @filemtime(__DIR__ . '/../assets/css/biblioteca.css') ?>">
<link rel="stylesheet" href="assets/css/pasos.css?v=<?= @filemtime(__DIR__ . '/../assets/css/pasos.css') ?>">
<section id="biblioteca-ingenios" aria-label="Seguimiento a próximos pasos">
<div class="bib-heading"><div class="bib-row"><button type="button" class="bib-btn" data-paso="exportar">Exportar</button><?php if(can_manage_resource($user,'acuerdos')): ?><button type="button" class="bib-btn primary" data-paso="nuevo">+ Nuevo próximo paso</button><?php endif; ?></div></div>
<div id="bib-message" role="status" hidden></div><div id="paso-filtros" class="bib-row"></div><div class="bib-search"><input id="paso-query" class="bib-input" placeholder="Buscar próximo paso, responsable o comité…" aria-label="Buscar próximos pasos"><select id="paso-comite" class="bib-input" aria-label="Filtrar por comité"></select></div><div id="paso-tabla"></div>
<dialog id="bib-dialog" aria-labelledby="bib-title"><header><h2 id="bib-title"></h2><button type="button" data-paso="cerrar" aria-label="Cerrar">×</button></header><div id="bib-error" role="alert" hidden></div><div id="bib-modal"></div></dialog><noscript>Activa JavaScript para consultar y actualizar los próximos pasos.</noscript>
</section>
<script id="paso-data" type="application/json"><?= json_encode(pasos_datos($pdo,$user),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script>
<script src="assets/js/pasos.js?v=<?= @filemtime(__DIR__ . '/../assets/js/pasos.js') ?>" defer></script>
