<tr>
    <td><select class="form-control" name="costo_items[<?= e((string) $i) ?>][categoria]" aria-label="Categoría"><?php foreach (IDEA_COSTO_CATEGORIAS as $categoria): ?><option <?= ($item['categoria'] ?? '') === $categoria ? 'selected' : '' ?>><?= e($categoria) ?></option><?php endforeach; ?></select></td>
    <?php foreach (['descripcion' => 'Descripción', 'cantidad' => 'Cantidad', 'unidad' => 'Unidad (horas, unidades...)', 'precio' => 'Costo unitario'] as $campo => $label): ?>
        <td><input class="form-control" name="costo_items[<?= e((string) $i) ?>][<?= $campo ?>]" aria-label="<?= e($label) ?>" placeholder="<?= e($label) ?>" value="<?= e((string) ($item[$campo] ?? '')) ?>" <?php if (in_array($campo, ['cantidad', 'precio'], true)): ?>type="number" step="0.01" min="<?= $campo === 'cantidad' ? '0.01' : '0' ?>" required<?php else: ?>maxlength="<?= $campo === 'descripcion' ? 250 : 40 ?>" <?= $campo === 'descripcion' ? 'required' : '' ?><?php endif; ?>></td>
    <?php endforeach; ?>
    <td data-subtotal><?= e((string) ($item['subtotal'] ?? '0.00')) ?></td>
    <td><button type="button" class="btn-outline" data-quitar-costo>Quitar</button></td>
</tr>
