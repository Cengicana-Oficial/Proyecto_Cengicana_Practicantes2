<?php $agendaDatos=agenda_datos($pdo,$user,$agendaTipo); ?>
<link rel="stylesheet" href="assets/css/agenda.css?v=<?= @filemtime(__DIR__ . '/../assets/css/agenda.css') ?>">
<section id="agenda" aria-label="<?= $agendaTipo==='eventos'?'Eventos de transferencia':'Reuniones' ?>">
    <div class="ag-heading"><div id="ag-actions"></div></div>
    <div id="ag-message" role="status" hidden></div>
    <div id="ag-navigation"></div><div id="ag-content"></div>
    <dialog id="ag-dialog" aria-labelledby="ag-title"><header><h2 id="ag-title"></h2><button type="button" data-ag="cerrar" aria-label="Cerrar">×</button></header><div id="ag-error" role="alert" hidden></div><div id="ag-modal"></div></dialog>
    <noscript>Activa JavaScript para consultar la planificación y sus formularios.</noscript>
</section>
<script id="ag-data" type="application/json"><?= json_encode($agendaDatos,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script>
<script src="assets/js/agenda.js?v=<?= @filemtime(__DIR__ . '/../assets/js/agenda.js') ?>" defer></script>
