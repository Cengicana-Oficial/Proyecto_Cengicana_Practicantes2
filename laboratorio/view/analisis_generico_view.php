<?php
$doc_elemento = $config['elemento'] ?? 'Análisis';
$doc_tipo = $config['tipo'] ?? 'muestra';
$doc_codigo = $config['codigo'] ?? 'LAB-001';
$doc_edicion = $config['edicion'] ?? '001';
$doc_vf = $config['vf'] ?? 'VF-000';
$resultado = $resultado ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($doc_elemento, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
    <div class="page-wrap">
        <a class="back-link" href="../../view/labc_index.php">Volver</a>
        <h2><?= htmlspecialchars($doc_elemento, ENT_QUOTES, 'UTF-8') ?></h2>

        <?php if (!empty($resultado)): ?>
            <div class="alerta <?= !empty($resultado['exito']) ? 'exito' : 'error' ?>">
                <?= htmlspecialchars($resultado['mensaje'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php include __DIR__ . '/../components/encabezado_doc.php'; ?>

            <form method="POST" action=""<?= !empty($config['shared_rows']) ? ' data-lab-shared-rows="1"' : '' ?>>
                <div class="form-body">
                    <div class="section-title">Datos de análisis</div>
                    <div class="field-group">
                        <div class="field">
                            <?php foreach ($config['fields'] as $field): ?>
                                <?php if (!empty($field['computed'])) { continue; } ?>
                                <?php if (
                                    (($config['key'] ?? '') === 'cana.peso_seco' && in_array(($field['name'] ?? ''), ['bandeja_humeda', 'torta_seca'], true))
                                    || (($config['key'] ?? '') === 'cana.fibra' && ($field['name'] ?? '') === 'fibra')
                                ) { continue; } ?>
                                <?php $type = ($field['type'] ?? 'number') === 'text' ? 'text' : 'number'; ?>
                                <label><?= htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8') ?>
                                    <input
                                        type="<?= $type ?>"
                                        <?= $type === 'number' ? 'step="any"' : '' ?>
                                        name="<?= htmlspecialchars($field['name'], ENT_QUOTES, 'UTF-8') ?>">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php include __DIR__ . '/../components/pie_pagina.php'; ?>
            </form>
        </div>
    </div>
</body>
</html>
