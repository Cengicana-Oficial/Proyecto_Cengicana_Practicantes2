<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.fosforo');

$doc_elemento  = 'Fosforo';
$doc_tipo      = 'Suelos';
$doc_codigo    = 'LAB-FS-042';
$doc_fecha_doc = '2024-03-01';
$doc_edicion   = '03';
$doc_vf        = 'V2';

$fecha_actual  = date('d-m-Y');
$lote_actual   = $_POST['lote'][0] ?? $_GET['lote'] ?? ($lote_actual ?? '');
$tecnicos      = [
    ['id' => 1, 'nombre' => 'Ana Lopez Mendez'],
    ['id' => 2, 'nombre' => 'Carlos Ruiz'],
    ['id' => 3, 'nombre' => 'Maria Perez'],
];
$observaciones = '';
$resultado = $resultado ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fosforo en Suelos</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">&larr; Volver</a>
    <h2>Fosforo en Suelos</h2>

    <?php if (!empty($resultado)): ?>
        <div class="alerta <?= $resultado['exito'] ? 'exito' : 'error' ?>">
            <?= htmlspecialchars($resultado['mensaje']) ?>
        </div>
    <?php endif; ?>

    <div class="card">

        <?php include '../../components/encabezado_doc.php'; ?>

        <form method="POST" action="" data-lab-shared-rows="1">
            <div class="form-body">

                <div class="section-title">Datos de analisis</div>
                <div class="field-group">
                    <div class="field">
                        <label for="abs_blanco">Absorbancia blanco</label>
                        <input type="number" step="any" name="abs_blanco" id="abs_blanco" value="0.00" required>
                    </div>
                    <div class="field">
                        <label for="absorbancia">Absorbancia muestra</label>
                        <input type="number" step="any" name="absorbancia" id="absorbancia" required>
                    </div>
                </div>

                <hr class="divider">
                <div class="section-title">Curva de calibracion</div>
                <div class="table-wrap calibration-table-wrap">
                    <table class="calibration-table">
                        <thead>
                            <tr>
                                <th>Punto curva</th>
                                <th>Absorbancia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><input type="number" name="punto_curva[]" value="1"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="1"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="2"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="2"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="4"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="4"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="5"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="5"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="10"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="10"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="20"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                            <tr><td><input type="number" name="punto_curva[]" value="20"></td><td><input type="number" step="any" name="abs_curva[]"></td></tr>
                        </tbody>
                    </table>
                </div>
                <br>
            </div>

            <?php include '../../components/pie_pagina.php'; ?>

        </form>

    </div><!-- /.card -->

</div><!-- /.page-wrap -->

<template id="fosforo-control-row-template">
    <tr class="lab-data-row lab-control-row" data-control-row="1">
        <td class="lab-row-index"></td>
        <td>
            <strong>CONTROL</strong>
            <input type="hidden" name="control_lote" value="CONTROL">
        </td>
        <td>
            <strong>CONTROL</strong>
            <input type="hidden" name="control_numero_laboratorio" value="CONTROL">
            <input type="hidden" name="control" value="0.00">
        </td>
        <td>
            <input type="number" step="any" name="control_abs_blanco" aria-label="Absorbancia blanco del control" value="0.00" required>
        </td>
        <td>
            <input type="number" step="any" name="control_absorbancia" aria-label="Absorbancia muestra del control" required>
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

    function insertControlRow() {
        const template = document.getElementById('fosforo-control-row-template');
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
        renumberRows(tbody);
    }

    window.addEventListener('load', insertControlRow);
})();
</script>
</body>
</html>
