<?php $costoItems = json_decode($rev('costo_items'), true) ?: []; ?>
<div class="idea-costos" data-costos>
    <p class="idea-group-help">Desglose de implementación: cantidad × costo unitario. Montos en quetzales.</p>
    <?php if ($canEditReview): ?><input type="hidden" name="costo_items_enviados" value="1"><?php endif; ?>
    <div class="idea-costos-scroll"><table>
        <thead><tr><th>Categoría</th><th>Descripción / requerimiento</th><th>Cantidad</th><th>Unidad</th><th>Costo unitario (Q)</th><th>Subtotal (Q)</th><?php if ($canEditReview): ?><th>Acción</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($costoItems as $i => $item): ?>
            <?php if ($canEditReview): include __DIR__ . '/idea-costo-fila.php'; else: ?>
                <tr><?php foreach (['categoria', 'descripcion', 'cantidad', 'unidad', 'precio', 'subtotal'] as $campo): ?><td><?= e((string) $item[$campo]) ?></td><?php endforeach; ?></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($canEditReview): ?>
        <template data-costo-template><?php $i = '__INDEX__'; $item = []; include __DIR__ . '/idea-costo-fila.php'; ?></template>
        <button type="button" class="btn-outline" data-agregar-costo>+ Agregar ítem</button>
        <p class="idea-group-help">Con ítems, el total se calcula automáticamente. Sin ítems puede registrar una estimación general.</p>
    <?php endif; ?>
</div>
