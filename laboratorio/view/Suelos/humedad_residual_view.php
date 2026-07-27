<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.humedad_residual');

<<<<<<< HEAD
$doc_elemento = 'Humedad residual';
=======
$doc_elemento = 'Humedad Residual';
>>>>>>> ca7cd2cf78e3923d85607d59567ef9e47c102fb5
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
    <title>Humedad Residual</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">
    <a class="back-link" href="../../view/labc_index.php">Volver</a>
<<<<<<< HEAD
    <h2>Humedad residual</h2>
=======
    <h2>Humedad Residual</h2>
>>>>>>> ca7cd2cf78e3923d85607d59567ef9e47c102fb5

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
                        <label>Peso caja<input type="number" step="any" name="PesoCaja"></label>
                        <label>Peso Muestra humeda<input type="number" step="any" name="PesoMuestraHumedo"></label>
                        <label>Peso caja + muestra seca<input type="number" step="any" name="PesoCajaMseca"></label>
                    </div>
                </div>
                <?php include '../../components/pie_pagina.php'; ?>
            </div>
        </form>
    </div>
</div>

<template id="humedad-residual-control-row-template">
    <tr class="lab-control-row" data-control-row="1">
        <td>
            <div class="lab-control-stack">
                <strong>CONTROL</strong>
                <input type="hidden"  name="control"  value="CONTROL">
            </div>
        </td>
        <td>
            <span>CONTROL</span>
            <input type="hidden" name="control_lote" value="CONTROL">
        </td>
        <td>
            <span>CONTROL</span>
            <input type="hidden" name="control_numero_laboratorio" value="CONTROL">
        </td>
        <td>
            <input type="number" step="any" name="control_PesoCaja" aria-label="Peso caja del control" required>
        </td>
        <td>
            <input type="number" step="any" name="control_PesoMuestraHumedo" aria-label="Peso muestra húmeda del control" required>
        </td>
        <td>
            <input type="number" step="any" name="control_PesoCajaMseca" aria-label="Peso caja + muestra seca del control" required>
        </td>
        <td></td>
    </tr>
</template>

<script>
(function () {
    function insertControlRow() {
        const template = document.getElementById('humedad-residual-control-row-template');
        const table = document.querySelector('table.lab-entry-table');
        const tbody = table ? table.querySelector('tbody') : null;

        if (!template || !table || !tbody || tbody.querySelector('[data-control-row="1"]')) {
            return;
        }

        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('tr');
        if (!row) {
            return;
        }

        tbody.insertBefore(row, tbody.firstChild);
    }

    window.addEventListener('load', insertControlRow);
})();
</script>
</body>
</html>
