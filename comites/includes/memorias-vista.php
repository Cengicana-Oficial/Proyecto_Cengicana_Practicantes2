<link rel="stylesheet" href="assets/css/biblioteca.css?v=<?= @filemtime(__DIR__ . '/../assets/css/biblioteca.css') ?>">
<link rel="stylesheet" href="assets/css/memorias.css?v=<?= @filemtime(__DIR__ . '/../assets/css/memorias.css') ?>">
<section id="biblioteca-ingenios" aria-label="Memoria de presentación de resultados">
    <div class="bib-heading"><div id="mem-actions" class="bib-row"></div></div>
    <div id="bib-message" role="status" hidden></div><div id="mem-notices"></div>
    <div id="mem-filters" class="bib-row"></div>
    <div class="bib-search"><input id="mem-search" class="bib-input" placeholder="Buscar por número, título, autor o área…" aria-label="Buscar artículos"></div>
    <div id="mem-content"></div>
    <dialog id="bib-dialog" aria-labelledby="bib-title"><header><h2 id="bib-title"></h2><button type="button" data-mem="cerrar" aria-label="Cerrar">×</button></header><div id="bib-error" role="alert" hidden></div><div id="bib-modal"></div></dialog>
    <noscript>Activa JavaScript para consultar las memorias y sus expedientes.</noscript>
</section>
<script id="mem-data" type="application/json"><?= json_encode(mem_datos($pdo,$user),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script>
<script src="assets/js/memorias.js?v=<?= @filemtime(__DIR__ . '/../assets/js/memorias.js') ?>" defer></script>
