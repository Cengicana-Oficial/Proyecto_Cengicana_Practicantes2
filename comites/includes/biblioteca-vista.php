<?php $esInterna=$view==='biblioteca_interna'; $bibliotecaDatos=$esInterna?interna_datos($pdo,$user):biblioteca_datos($pdo,$user); ?>
<link rel="stylesheet" href="assets/css/biblioteca.css?v=<?= @filemtime(__DIR__ . '/../assets/css/biblioteca.css') ?>">
<section id="biblioteca-ingenios" aria-label="<?= $esInterna?'Biblioteca documental interna':'Biblioteca documental para ingenios' ?>">
    <div class="bib-heading"><?php if (!$esInterna): ?><p class="bib-note"><i class="ti ti-info-circle"></i> Antes del envío, cada documento pasa por la revisión del Jefe de Transferencia (anotaciones y verificación de correcciones) y la aprobación del Director General.</p><?php endif; ?><div id="bib-actions"></div></div>
    <div id="bib-message" role="status" hidden></div>
    <div id="bib-help" hidden></div><div id="bib-filters"></div>
    <div class="bib-search"><input id="bib-search" class="bib-input" placeholder="Buscar en toda la biblioteca por título, autor o tipo…" aria-label="Buscar documentos"><span id="bib-count"></span></div>
    <div id="bib-content"></div>
    <dialog id="bib-dialog" aria-labelledby="bib-title"><header><h2 id="bib-title"></h2><button type="button" data-bib="cerrar" aria-label="Cerrar">×</button></header><div id="bib-error" role="alert" hidden></div><div id="bib-modal"></div></dialog>
    <noscript>Activa JavaScript para consultar los documentos y su proceso de revisión.</noscript>
</section>
<script id="bib-data" type="application/json"><?= json_encode($bibliotecaDatos,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script>
<script src="assets/js/biblioteca.js?v=<?= @filemtime(__DIR__ . '/../assets/js/biblioteca.js') ?>" defer></script>
