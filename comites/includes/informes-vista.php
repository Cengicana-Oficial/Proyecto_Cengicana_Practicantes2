<link rel="stylesheet" href="assets/css/biblioteca.css?v=<?= @filemtime(__DIR__ . '/../assets/css/biblioteca.css') ?>">
<link rel="stylesheet" href="assets/css/informes.css?v=<?= @filemtime(__DIR__ . '/../assets/css/informes.css') ?>">
<section id="biblioteca-ingenios" aria-label="Informes cuatrimestrales">
    <div class="bib-heading"><div id="inf-actions" class="bib-row"></div></div>
    <div id="bib-message" role="status" hidden></div><div id="inf-nav" class="bib-row"></div><div id="inf-content"></div>
    <dialog id="bib-dialog" aria-labelledby="bib-title"><header><h2 id="bib-title"></h2><button type="button" data-inf="cerrar" aria-label="Cerrar">×</button></header><div id="bib-error" role="alert" hidden></div><div id="bib-modal"></div></dialog>
    <noscript>Activa JavaScript para consultar los informes.</noscript>
</section>
<script id="inf-data" type="application/json"><?= json_encode(inf_datos($pdo,$user),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script>
<script src="assets/js/informes.js?v=<?= @filemtime(__DIR__ . '/../assets/js/informes.js') ?>" defer></script>
