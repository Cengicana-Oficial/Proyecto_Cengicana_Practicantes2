<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.fosforo');

$doc_elemento  = "Fósforo";
$doc_tipo      = "Aguas";
$doc_codigo    = "LAB-FS-042";
$doc_fecha_doc = "2024-03-01";
$doc_edicion   = "03";
$doc_vf        = "V2";

$fecha_actual  = date('d-m-Y');
$lote_actual   = "";
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
    <title>Fósforo en Aguas</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">← Volver</a>
    <h2>Fósforo en Aguas</h2>

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
        <label>Absorbancia Blanco
            <input type="number" step="any" name="abs_blanco" id="abs_blanco" value="0.00" required>
        </label>
        <label>Absorbancia Muestra
            <input type="number" step="any" name="absorbancia" id="absorbancia" required>
        </label>

        <h3>Curva de Calibración</h3>

    <div class="table-wrap calibration-table-wrap">
    <table class="calibration-table">
        <tr>
            <th>Punto Curva</th>
            <th>Absorbancia</th>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="2"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="2"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="4"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="4"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="8"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="8"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="16"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="16"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="32"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

        <tr>
            <td><input type="number" name="punto_curva[]" value="32"></td>
            <td><input type="number" step="any" name="abs_curva[]"></td>
        </tr>

    </table>
    </div>
        </div>
        </div>

                <?php include '../../components/pie_pagina.php'; ?>

            </div>
        </form>
    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
