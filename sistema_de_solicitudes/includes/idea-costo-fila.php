<tr data-costo-fila>
    <td><select class="form-control" name="costo_items[<?= e((string) $i) ?>][categoria]" aria-label="Categoria"><?php foreach (IDEA_COSTO_CATEGORIAS as $categoria): ?><option <?= ($item['categoria'] ?? '') === $categoria ? 'selected' : '' ?>><?= e($categoria) ?></option><?php endforeach; ?></select></td>
    <?php foreach (['descripcion' => 'Descripcion', 'cantidad' => 'Cantidad', 'unidad' => 'Unidad (horas, unidades...)', 'precio' => 'Costo unitario'] as $campo => $label): ?>
        <td<?= in_array($campo, ['cantidad', 'precio'], true) ? ' class="num"' : '' ?>><input class="form-control" name="costo_items[<?= e((string) $i) ?>][<?= $campo ?>]" aria-label="<?= e($label) ?>" placeholder="<?= e($label) ?>" value="<?= e((string) ($item[$campo] ?? '')) ?>" <?php if (in_array($campo, ['cantidad', 'precio'], true)): ?>type="number" inputmode="decimal" step="0.01" min="<?= $campo === 'cantidad' ? '0.01' : '0' ?>" required<?php else: ?>maxlength="<?= $campo === 'descripcion' ? 250 : 40 ?>" <?= $campo === 'descripcion' ? 'required' : '' ?><?php endif; ?>></td>
    <?php endforeach; ?>
    <td class="num" data-subtotal><?= e(number_format((float) ($item['subtotal'] ?? 0), 2)) ?></td>
    <td class="idea-costo-acciones">
        <button type="button" class="btn-outline btn-sm" data-duplicar-costo title="Duplicar item" aria-label="Duplicar item"><i class="ti ti-copy"></i></button>
        <button type="button" class="btn-outline btn-sm idea-btn-reject" data-quitar-costo title="Quitar item" aria-label="Quitar item"><i class="ti ti-trash"></i></button>
    </td>
</tr>
