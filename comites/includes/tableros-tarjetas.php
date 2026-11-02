<link rel="stylesheet" href="assets/css/tableros.css?v=<?= @filemtime(__DIR__ . '/../assets/css/tableros.css') ?>">
<p class="text-muted">Consulta los tableros aquí, amplía uno para concentrarte en él o contrae los que no estés utilizando.</p>
<div id="tableros-grid" class="tableros-grid">
<?php if (!$tableros): ?><div class="empty-state">No hay tableros con este criterio.</div><?php endif; ?>
<?php foreach($tableros as $t): $safeUrl=tableros_url_segura($t['url']);$tid=(int)$t['id']; ?>
<article class="tablero-card" data-board="<?= $tid ?>">
    <header><div class="tablero-heading"><h2><?= e($t['nombre']) ?></h2><span class="badge"><?= e($t['tipo'] ?: 'Tablero') ?></span><span class="badge"><?= e($t['estado']) ?></span><?php if($t['descripcion']): ?><p><?= e($t['descripcion']) ?></p><?php endif; ?></div>
    <div class="tablero-tools">
        <?php if($safeUrl): ?>
        <button type="button" class="btn-outline btn-sm" data-board-action="toggle" aria-expanded="true" aria-controls="board-content-<?= $tid ?>">Contraer</button>
        <button type="button" class="btn-outline btn-sm" data-board-action="expand" aria-pressed="false">Ampliar</button>
        <button type="button" class="btn-outline btn-sm" data-board-action="fullscreen">Pantalla completa</button>
        <a class="btn-outline btn-sm" href="<?= e($safeUrl) ?>" target="_blank" rel="noopener noreferrer">Abrir aparte ↗</a>
        <?php endif; ?>
        <?php if($puedeGestionarSeccionActual): ?><a class="btn-outline btn-sm" href="index.php?view=tableros&editar=<?= $tid ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este tablero?');"><input type="hidden" name="accion" value="tablero_eliminar"><input type="hidden" name="id" value="<?= $tid ?>"><input type="hidden" name="csrf_tableros" value="<?= e($_SESSION['tableros_csrf']) ?>"><button class="btn-outline btn-sm danger" type="submit">Eliminar</button></form><?php endif; ?>
    </div></header>
    <?php if($safeUrl): ?>
    <div id="board-content-<?= $tid ?>" class="tablero-content">
        <div class="tablero-view-controls"><label>Altura <select data-board-height aria-label="Altura de <?= e($t['nombre']) ?>"><option value="420">Compacta</option><option value="620" selected>Normal</option><option value="850">Grande</option></select></label><button type="button" class="btn-outline btn-sm" data-board-action="reload">Recargar</button><span class="tablero-status" role="status"></span></div>
        <iframe data-src="<?= e($safeUrl) ?>" title="<?= e($t['nombre']) ?>" loading="lazy" allow="fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-downloads"></iframe>
        <p class="tablero-help">Si no aparece, inicia sesión en el proveedor o utiliza «Abrir aparte». El enlace debe permitir la inserción en otros sitios.</p>
    </div>
    <?php else: ?><p class="tablero-help">Configura un enlace de inserción válido para visualizar este tablero.</p><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<script src="assets/js/tableros.js?v=<?= @filemtime(__DIR__ . '/../assets/js/tableros.js') ?>" defer></script>
