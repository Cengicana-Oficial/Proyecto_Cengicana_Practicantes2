<?php $abrirPaso('notificacion'); ?>
    <p class="idea-step-help">Comunique la decisión registrada y su motivo a la persona que presentó la propuesta.</p>
    <div class="detail-value"><strong>Destinatario:</strong>
        <?= $correoRemitente !== '' ? e($correoRemitente) : 'Sin correo disponible: propuesta anonima o sin correo valido.' ?>
    </div>
    <?php if ($ultimaNotificacion): ?>
        <p>Último aviso: <?= e($ultimaNotificacion['estado']) ?> · <?= e($ultimaNotificacion['destinatario']) ?> · <?= e($ultimaNotificacion['creado_en']) ?>
        <?php if ($ultimaNotificacion['error']): ?><br><?= e($ultimaNotificacion['error']) ?><?php endif; ?></p>
    <?php endif; ?>
    <?php if (!$cerrada): ?>
        <p class="idea-empty">Pendiente de la decisión de Dirección General.</p>
    <?php else: ?>
        <p><strong>Resultado:</strong> <?= e(BUZON_ESTADOS[$estado]) ?></p>
        <?php if ($rev('decision_motivo') !== ''): ?><p><?= nl2br(e($rev('decision_motivo'))) ?></p><?php endif; ?>
        <?php if (!$correosActivos): ?><p class="idea-empty">Los envíos están desactivados en la configuración de Correos.</p><?php endif; ?>
        <?php if (($canManageIdeas || $canApproveIdeas) && $correoRemitente !== '' && $correosActivos): ?>
            <form method="post" action="index.php?view=idea&id=<?= $ideaId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="notificar_idea">
                <input type="hidden" name="idea_id" value="<?= $ideaId ?>">
                <button class="btn-primary" type="submit">Enviar notificación de la decisión</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div></details>
