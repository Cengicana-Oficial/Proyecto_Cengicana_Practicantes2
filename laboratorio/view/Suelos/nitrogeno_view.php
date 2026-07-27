<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.nitrogeno');

$doc_elemento  = "Nitrógeno total";
$doc_tipo      = "Suelos";
$doc_codigo    = "LAB-FS-042";
$doc_fecha_doc = "2024-03-01";
$doc_edicion   = "03";
$doc_vf        = "V2";

$fecha_actual  = date('d-m-Y');
$lote_actual   = $_POST['lote'][0] ?? $_GET['lote'] ?? ($lote_actual ?? '');
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
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">← Volver</a>
    <h2>Determinación de Nitrógeno total en suelo</h2>

    <?php if (!empty($resultado)): ?>
        <div class="alerta <?= $resultado['exito'] ? 'exito' : 'error' ?>">
            <?= htmlspecialchars($resultado['mensaje']) ?>
        </div>
    <?php endif; ?>
    <div class="card">

        <?php include '../../components/encabezado_doc.php'; ?>

        <form method="POST" action="" data-lab-shared-rows="1">
            <div class="form-body">

                <div class="section-title">Datos de análisis</div>
                <div class="field-group">
                    <div class="field">
        <label>Peso (g)
            <input type="number" step="any" name="peso" id="peso" required>
        </label>
        <label>Control
            <input type="number" step="any" name="control" id="control" required>
        </label>
        <label>mL HCl Blanco
            <input type="number" step="any" name="ml_blanco" id="ml_blanco" required>
        </label>
        <label>mL HCl Muestra
            <input type="number" step="any" name="ml_muestra" id="ml_muestra" required>
        </label><br>
        <label>x = Nitrógeno
            <input type="number" step="any" name="x_nitrogeno" id="x_nitrogeno" value = "0" required>
        </label><br>
            <?php include '../../components/pie_pagina.php'; ?>

        </form>

    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
