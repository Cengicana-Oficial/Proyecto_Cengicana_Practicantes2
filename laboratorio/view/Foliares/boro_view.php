<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('foliares.boro');

$doc_elemento = $config['elemento'] ?? 'Boro';
$doc_tipo = $config['tipo'] ?? 'Foliares';
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
            <?php include __DIR__ . '/../../components/encabezado_doc.php'; ?>

            <form method="POST" action="">
                <div class="form-body">
                    <div class="section-title">Datos de análisis</div>
                    <div class="field-group">
                        <div class="field">
                            <?php foreach ($config['fields'] as $field): ?>
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

                    <hr class="divider">
                    <div class="section-title">Curva de calibración</div>
                    <div class="table-wrap calibration-table-wrap">
                        <table class="calibration-table">
                            <thead>
                                <tr>
                                    <th>Punto curva</th>
                                    <th>Absorbancia</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $puntosCurvaFijos = [
                                    0.00,
                                    0.00,
                                    0.25,
                                    0.25,
                                    0.50,
                                    0.50,
                                    1.00,
                                    1.00,
                                    2.00,
                                    2.00,
                                ];
                                ?>
                                <?php foreach ($puntosCurvaFijos as $puntoCurva): ?>
                                    <tr>
                                        <td>
                                            <span><?= number_format((float) $puntoCurva, 2, '.', '') ?></span>
                                            <input type="hidden" name="punto_curva[]" value="<?= number_format((float) $puntoCurva, 2, '.', '') ?>">
                                        </td>
                                        <td><input type="number" step="any" name="abs_curva[]"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php include __DIR__ . '/../../components/pie_pagina.php'; ?>
            </form>
        </div>
    </div>
</body>
</html>
