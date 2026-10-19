<?php if(!isset($db,$cid,$sessions)){http_response_code(403);exit;}
// Gestion del aula -> Anuncios: mensajes del curso para el estudiante, con enlace opcional (Teams) y sesion opcional.
// Acciones anuncio_guardar / anuncio_eliminar en aula_gestion.php.
$anunciosLista=aula_vista_ready($db);
$anunciosCurso=$anunciosLista?aula_anuncios($db,$cid):[];
$anunciosTeams=aula_teams_url($db,$cid)?:'';
?>
<section class="aula-card aula-anuncios" id="anuncios">
<div class="aula-editor-cab"><div><h2><span class="glyphicon glyphicon-bullhorn" aria-hidden="true"></span> Anuncios</h2>
<p>Mensajes que el estudiante ve arriba de su Resumen (y dentro de la sesión, si eliges una). Úsalos para compartir el <b>enlace de Teams</b>, avisos de cambios o indicaciones.</p></div></div>
<?php if(!$anunciosLista): ?><p class="alert alert-info">Aplica la migración <code>20261022_aula_anuncios.sql</code>.</p><?php else: ?>
    <?php foreach($anunciosCurso as $a)echo aula_anuncio_item($a,$sessions,$anunciosTeams); ?>
    <?php if(!$anunciosCurso): ?><p class="aula-muted">Aún no hay anuncios.</p><?php endif; ?>
    <details class="aula-ed-nuevo" <?= $anunciosCurso?'':'open' ?>><summary><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Nuevo anuncio</summary>
        <?= aula_anuncio_form($sessions,$anunciosTeams) ?>
    </details>
<?php endif; ?>
</section>
<script>
(function(){
    document.addEventListener('click',function(e){
        var b=e.target.closest('[data-usar-teams]');if(!b)return;
        var f=b.closest('form');f.querySelector('[name="enlace_url"]').value=b.getAttribute('data-usar-teams');
        var t=f.querySelector('[name="enlace_texto"]');if(!t.value)t.value='Entrar a Teams';
    });
})();
</script>
