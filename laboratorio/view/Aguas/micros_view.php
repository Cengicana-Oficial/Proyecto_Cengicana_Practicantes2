<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.micros');

$doc_elemento  = "Micro Nutrientes";
$doc_tipo      = "Aguas";
$doc_codigo    = "LAB-FS-042";
$doc_fecha_doc = "2024-03-01";
$doc_edicion   = "03";
$doc_vf        = "V2";

$fecha_actual  = date('d-m-Y');
$lote_actual   = "";
$tecnicos      = [
    ['id' => 1, 'nombre' => 'Ana LÃ³pez MÃ©ndez'],
    ['id' => 2, 'nombre' => 'Carlos Ruiz'],
    ['id' => 3, 'nombre' => 'MarÃ­a PÃ©rez'],
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

    <a href="../../view/labc_index.php" class="back-link">â† Volver</a>
    <h2>Micro Nutrientes en Aguas</h2>

    <?php if (!empty($resultado)): ?>
        <div class="alerta <?= $resultado['exito'] ? 'exito' : 'error' ?>">
            <?= htmlspecialchars($resultado['mensaje']) ?>
        </div>
    <?php endif; ?>
<div class="card">
        <?php include '../../components/encabezado_doc.php'; ?>
        <form method="POST" action="">
            <div class="form-body">

                <div class="section-title">Datos de anÃ¡lisis</div>
                <div class="field-group">
                    <div class="field">
        <h3>Concentraciones (Âµg/ml)</h3>
        <div class="grid2">
            <label>Cu <input type="number" step="any" name="conc_cu" id="conc_cu" value="0.0"></label>
            <label>Zn <input type="number" step="any" name="conc_zn" id="conc_zn" value="0.0"></label>
            <label>Fe <input type="number" step="any" name="conc_fe" id="conc_fe" value="0.0"></label>
            <label>Mn <input type="number" step="any" name="conc_mn" id="conc_mn" value="0.0"></label>
        </div>

        <h3>Blancos</h3>
        <div class="grid2">
            <label>BLK Cu <input type="number" step="any" name="blk_cu" id="blk_cu" value="0.0"></label>
            <label>BLK Zn <input type="number" step="any" name="blk_zn" id="blk_zn" value="0.0"></label>
            <label>BLK Fe <input type="number" step="any" name="blk_fe" id="blk_fe" value="0.0"></label>
            <label>BLK Mn <input type="number" step="any" name="blk_mn" id="blk_mn" value="0.0"></label>
        </div>

        <!--<h3>Puntos de la curva</h3>
        <div class="table-wrap">
            <table class="analisis-table">
                <thead>
                    <tr>
                        <th>PatrÃ³n</th>
                        <th>Cu-Zn</th>
                        <th>Fe-Mn</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>PatrÃ³n 1</td>
                        <td>0.25-0.25</td>
                        <td>3.0-3.0</td>
                    </tr>
                    <tr>
                        <td>PatrÃ³n 2</td>
                        <td>1.25-1.25</td>
                        <td>6.0-6.0</td>
                    </tr>
                    <tr>
                        <td>PatrÃ³n 3</td>
                        <td>3.00-3.00</td>
                        <td>12.0-12.0</td>
                    </tr>
                </tbody>
            </table>
        </div>-->
        <br>
        <?php include '../../components/pie_pagina.php'; ?>

            </div>
        </form>
    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
