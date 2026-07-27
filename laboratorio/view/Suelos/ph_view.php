<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.ph');

$doc_elemento = 'pH';
$doc_tipo = 'Suelos';
$doc_codigo = 'LAB-FS-042';
$doc_fecha_doc = '2024-03-01';
$doc_edicion = '03';
$doc_vf = 'V2';
$fecha_actual = date('d-m-Y');
$lote_actual = $_POST['lote'][0] ?? $_GET['lote'] ?? ($lote_actual ?? '');
$observaciones = '';
$resultado = $resultado ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>pH</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">
    <a class="back-link" href="../../view/labc_index.php">Volver</a>
    <h2>pH</h2>

    <?php if (!empty($resultado)): ?>
        <div class="alerta <?= !empty($resultado['exito']) ? 'exito' : 'error' ?>">
            <?= htmlspecialchars($resultado['mensaje'] ?? '') ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php include '../../components/encabezado_doc.php'; ?>
        <form method="POST" action="" data-lab-shared-rows="1">
            <div class="form-body">
                <div class="section-title">Datos de analisis</div>
                <div class="field-group">
                    <div class="field">
                        <label>pH<input type="number" step="any" name="ph"></label>
                        <label>Temperatura<input type="number" step="any" name="temperatura"></label>
                    </div>
                </div>
                <?php include '../../components/pie_pagina.php'; ?>
            </div>
        </form>
    </div>
</div>

<template id="ph-control-rows-template">
    <tr class="lab-data-row lab-control-row" data-control-row="agua_control">
        <td class="lab-row-index"></td>
        <td>
            <strong>AGUA</strong>
        </td>
        <td>
            <strong>AGUA</strong>
        </td>
        <td>
            <input type="number" step="any" name="agua_control_ph">
        </td>
        <td>
            <input type="number" step="any" name="agua_control_temperatura">
        </td>
        <td></td>
    </tr>
    <tr class="lab-data-row lab-control-row" data-control-row="control">
        <td class="lab-row-index"></td>
        <td>
            <strong>CONTROL</strong>
        </td>
        <td>
            <strong>CONTROL</strong>
        </td>
        <td>
            <input type="number" step="any" name="control_ph">
        </td>
        <td>
            <input type="number" step="any" name="control_temperatura">
        </td>
        <td></td>
    </tr>
</template>

<script>
(function () {
    function renumberRows(tbody) {
        Array.from(tbody.querySelectorAll('tr.lab-data-row')).forEach((row, index) => {
            const indexCell = row.querySelector('.lab-row-index');
            if (indexCell) {
                indexCell.textContent = String(index + 1);
            }
        });
    }

    function insertControlRows() {
        const template = document.getElementById('ph-control-rows-template');
        const table = document.querySelector('table.lab-entry-table');
        const tbody = table ? table.querySelector('tbody') : null;

        if (!template || !table || !tbody || tbody.querySelector('[data-control-row="agua_control"]')) {
            return;
        }

        const fragment = template.content.cloneNode(true);
        tbody.insertBefore(fragment, tbody.firstChild);
        renumberRows(tbody);
    }

    window.addEventListener('load', insertControlRows);
})();
</script>
</body>
</html>
