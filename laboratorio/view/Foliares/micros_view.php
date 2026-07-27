<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('foliares.micros');

$doc_elemento  = "Micro Nutrientes";
$doc_tipo      = "Foliares";
$doc_codigo    = "LAB-FS-042";
$doc_fecha_doc = "2024-03-01";
$doc_edicion   = "03";
$doc_vf        = "V2";

$fecha_actual  = date('d-m-Y');
$lote_actual   = "LT-2025-083";
$tecnicos      = [
    ['id' => 1, 'nombre' => 'Ana López Méndez'],
    ['id' => 2, 'nombre' => 'Carlos Ruiz'],
    ['id' => 3, 'nombre' => 'María Pérez'],
];
$observaciones = "";
$resultado = $resultado ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Micro Nutrientes</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">← Volver</a>
    <h2>Micro Nutrientes en Foliares</h2>

    <?php if (!empty($resultado)): ?>
        <div class="alerta <?= $resultado['exito'] ? 'exito' : 'error' ?>">
            <?= htmlspecialchars($resultado['mensaje']) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php include '../../components/encabezado_doc.php'; ?>

        <form method="POST" action="">
            <div class="form-body">

                <div class="section-title">Datos de análisis</div>
                <div class="field-group">
                    <div class="field">
                        <label for="peso">Peso (g)</label>
                        <input type="number" step="any" name="peso" id="peso" required>
                    </div>
                </div>

                <h3>Concentraciones (CONC)</h3>
                <div class="field-group">
                    <div class="field">
                        <label for="conc_cu">Cu</label>
                        <input type="number" step="any" name="conc_cu" id="conc_cu" value="0">
                    </div>
                    <div class="field">
                        <label for="conc_zn">Zn</label>
                        <input type="number" step="any" name="conc_zn" id="conc_zn" value="0">
                    </div>
                    <div class="field">
                        <label for="conc_fe">Fe</label>
                        <input type="number" step="any" name="conc_fe" id="conc_fe" value="0">
                    </div>
                    <div class="field">
                        <label for="conc_mn">Mn</label>
                        <input type="number" step="any" name="conc_mn" id="conc_mn" value="0">
                    </div>
                </div>

                <h3>Blancos (BLK)</h3>
                <div class="field-group">
                    <div class="field">
                        <label for="blk_cu">BLK Cu</label>
                        <input type="number" step="any" name="blk_cu" id="blk_cu" value="0">
                    </div>
                    <div class="field">
                        <label for="blk_zn">BLK Zn</label>
                        <input type="number" step="any" name="blk_zn" id="blk_zn" value="0">
                    </div>
                    <div class="field">
                        <label for="blk_fe">BLK Fe</label>
                        <input type="number" step="any" name="blk_fe" id="blk_fe" value="0">
                    </div>
                    <div class="field">
                        <label for="blk_mn">BLK Mn</label>
                        <input type="number" step="any" name="blk_mn" id="blk_mn" value="0">
                    </div>
                </div>

                <h3>Puntos de la curva</h3>
                <div class="table-wrap calibration-table-wrap">
                    <table class="analisis-table calibration-table">
                        <thead>
                            <tr>
                                <th>Patrón</th>
                                <th>Cu-Zn</th>
                                <th>Fe-Mn</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Patrón 1</td>
                                <td>0.25-0.25</td>
                                <td>3.0-3.0</td>
                            </tr>
                            <tr>
                                <td>Patrón 2</td>
                                <td>1.25-1.25</td>
                                <td>6.0-6.0</td>
                            </tr>
                            <tr>
                                <td>Patrón 3</td>
                                <td>3.00-3.00</td>
                                <td>12.0-12.0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <br>

                <?php include '../../components/pie_pagina.php'; ?>

            </div>
        </form>
    </div><!-- /.card -->

</div><!-- /.page-wrap -->

    <template id="micros-control-row-template">
        <tr class="lab-data-row lab-control-row" data-control-row="1">
            <td class="lab-row-index"></td>
            <td>
                <strong>CONTROL</strong>
                <input type="hidden" name="lote[]" value="CONTROL">
            </td>
            <td>
                <strong>CONTROL</strong>
                <input type="hidden" name="numero_laboratorio[]" value="CONTROL">
                <input type="hidden" name="control[]" value="0.00">
            </td>
            <td>
                <input type="number" step="any" name="peso[]" aria-label="Peso del control" required>
            </td>
            <td>
                <input type="number" step="any" name="conc_cu[]" aria-label="Concentracion Cu del control" value="0">
            </td>
            <td>
                <input type="number" step="any" name="conc_zn[]" aria-label="Concentracion Zn del control" value="0">
            </td>
            <td>
                <input type="number" step="any" name="conc_fe[]" aria-label="Concentracion Fe del control" value="0">
            </td>
            <td>
                <input type="number" step="any" name="conc_mn[]" aria-label="Concentracion Mn del control" value="0">
            </td>
            <td>
                <input type="number" step="any" name="blk_cu[]" aria-label="BLK Cu del control" value="0">
            </td>
            <td>
                <input type="number" step="any" name="blk_zn[]" aria-label="BLK Zn del control" value="0">
            </td>
            <td>
                <input type="number" step="any" name="blk_fe[]" aria-label="BLK Fe del control" value="0">
            </td>
            <td>
                <input type="number" step="any" name="blk_mn[]" aria-label="BLK Mn del control" value="0">
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
            const template = document.getElementById('micros-control-row-template');
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
