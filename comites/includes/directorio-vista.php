<?php $directorioDatos=directorio_datos($pdo,$user); ?>
<link rel="stylesheet" href="assets/css/directorio.css?v=<?= @filemtime(__DIR__ . '/../assets/css/directorio.css') ?>">
<section id="directorio" aria-label="Directorio de contactos">
    <div class="dir-heading"><div id="dir-toolbar"></div></div>
    <div id="dir-message" role="status" hidden></div>
    <div id="dir-segmentos" class="dir-panel"></div>
    <form id="dir-filtros" class="dir-filtros" autocomplete="off"></form>
    <div id="dir-clasificar"></div>
    <div id="dir-masivo"></div>
    <div class="dir-table-wrap"><table class="dir-table"><thead><tr><th><input type="checkbox" id="dir-all" aria-label="Seleccionar todos"></th><th>Nombre</th><th>Cargo / empresa</th><th>Área · nivel</th><th>Comités</th><th>Origen</th><th>Última interacción</th><th>Estado</th><th><span class="dir-sr">Acciones</span></th></tr></thead><tbody id="dir-rows"></tbody></table><div id="dir-empty" hidden>No hay contactos con esos filtros.</div></div>
    <dialog id="dir-dialog" aria-labelledby="dir-dialog-title"><header><h2 id="dir-dialog-title"></h2><button type="button" data-dir="cerrar" aria-label="Cerrar">×</button></header><div id="dir-modal-error" role="alert" hidden></div><div id="dir-modal"></div></dialog>
    <noscript>Activa JavaScript para utilizar los filtros y formularios del directorio.</noscript>
</section>
<script id="dir-data" type="application/json"><?= json_encode($directorioDatos,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script>
<script src="assets/js/directorio.js?v=<?= @filemtime(__DIR__ . '/../assets/js/directorio.js') ?>" defer></script>
