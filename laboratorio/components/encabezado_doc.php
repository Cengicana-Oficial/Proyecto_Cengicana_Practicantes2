

<?php
$doc_elemento = $doc_elemento ?? 'Análisis';
$doc_tipo = $doc_tipo ?? 'muestra';
$doc_codigo = $doc_codigo ?? 'LAB-001';
$doc_edicion = $doc_edicion ?? '001';
$doc_vf = $doc_vf ?? 'VF-000';

if (!function_exists('eDoc')) {
    function eDoc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
?>

<div class="doc-header">
    <div class="doc-brand-mark">CG</div>
    <div class="doc-title-block">
        <h1>Determinación de <?= eDoc($doc_elemento) ?> en <?= eDoc($doc_tipo) ?></h1>
        <p><?= eDoc($doc_codigo) ?> · Edición <?= eDoc($doc_edicion) ?> · <?= eDoc($doc_vf) ?></p>
    </div>
</div>
