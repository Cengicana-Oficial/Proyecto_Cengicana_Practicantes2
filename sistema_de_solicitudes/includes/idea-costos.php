<?php
$costoItems = json_decode($rev('costo_items'), true) ?: [];
$costoTotal = 0.0;
$costoPorCategoria = [];
foreach ($costoItems as $costoItem) {
    $costoTotal += (float) ($costoItem['subtotal'] ?? 0);
    $costoPorCategoria[$costoItem['categoria'] ?? 'Otros'] = ($costoPorCategoria[$costoItem['categoria'] ?? 'Otros'] ?? 0) + (float) ($costoItem['subtotal'] ?? 0);
}
arsort($costoPorCategoria);
$q = static fn(float $monto): string => 'Q ' . number_format($monto, 2);
?>
<?php if (!$canEditReview && !$costoItems): return; endif; ?>
<div class="idea-costos" data-costos>
    <p class="idea-group-help">Desglose de implementacion: cantidad × costo unitario. Montos en quetzales.</p>
    <?php if ($canEditReview): ?><input type="hidden" name="costo_items_enviados" value="1"><?php endif; ?>
    <div class="idea-costos-scroll"><table>
        <thead><tr><th>Categoria</th><th>Descripcion / requerimiento</th><th class="num">Cantidad</th><th>Unidad</th><th class="num">Costo unitario (Q)</th><th class="num">Subtotal (Q)</th><?php if ($canEditReview): ?><th><span class="sr-only">Acciones</span></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($costoItems as $i => $item): ?>
            <?php if ($canEditReview): include __DIR__ . '/idea-costo-fila.php'; else: ?>
                <tr>
                    <td><?= e((string) $item['categoria']) ?></td>
                    <td><?= e((string) $item['descripcion']) ?></td>
                    <td class="num"><?= e(rtrim(rtrim(number_format((float) $item['cantidad'], 2, '.', ','), '0'), '.')) ?></td>
                    <td><?= e((string) $item['unidad']) ?></td>
                    <td class="num"><?= e(number_format((float) $item['precio'], 2)) ?></td>
                    <td class="num"><?= e(number_format((float) $item['subtotal'], 2)) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($canEditReview): ?>
            <tr class="idea-costos-vacio" data-costos-vacio <?= $costoItems ? 'hidden' : '' ?>><td colspan="7">Sin items. Agrega el primero o registra abajo una estimacion general.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr>
            <th colspan="5" class="num">Total</th>
            <th class="num" data-costos-total><?= e(number_format($costoTotal, 2)) ?></th>
            <?php if ($canEditReview): ?><th></th><?php endif; ?>
        </tr></tfoot>
    </table></div>
    <div class="idea-costos-resumen" data-costos-resumen>
        <?php foreach ($costoPorCategoria as $categoria => $monto): ?>
            <span><?= e($categoria) ?> <strong><?= e($q($monto)) ?></strong></span>
        <?php endforeach; ?>
    </div>
    <?php if ($canEditReview): ?>
        <template data-costo-template><?php $i = '__INDEX__'; $item = []; include __DIR__ . '/idea-costo-fila.php'; ?></template>
        <button type="button" class="btn-outline btn-sm" data-agregar-costo><i class="ti ti-plus"></i>Agregar item</button>
    <?php endif; ?>
</div>
