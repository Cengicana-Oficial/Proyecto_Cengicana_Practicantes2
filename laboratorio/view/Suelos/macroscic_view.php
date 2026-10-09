<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.macroscic');

$doc_elemento  = "Macronutrientes y CIC";
$doc_tipo      = "Suelos";
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
    <title>Macros y Cic</title>
    <link rel="stylesheet" href="../../styles/formularios.css?v=20261006">
</head>
<body>
    <div class="page-wrap">

    <a class="back-link" href="../../view/labc_index.php">← Volver</a>
    <h2>Macronutrientes y Capacidad de Intercambio Catiónico en Suelos</h2>

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
        <label>Peso (g) <input type="number" step="any" name="peso" id="peso" value = "5.00" required></label>

        <label>Control <input type="number" step="any" name="control" id="control" required></label>

        <h3>PPM</h3>
        <div class="grid2">
            <label>Ca <input type="number" step="any" name="ppm_ca" id="ppm_ca" value="0"></label>
            <label>Mg <input type="number" step="any" name="ppm_mg" id="ppm_mg" value="0"></label>
            <label>K  <input type="number" step="any" name="ppm_k"  id="ppm_k" value="0"></label>
            <label>Na <input type="number" step="any" name="ppm_na" id="ppm_na" value="0"></label>
        </div>

        <h3>Blancos (BLK)</h3>
        <div class="grid2">
            <label>BLK Ca <input type="number" step="any" name="blk_ca" id="blk_ca" value="0"></label>
            <label>BLK Mg <input type="number" step="any" name="blk_mg" id="blk_mg" value="0"></label>
            <label>BLK K  <input type="number" step="any" name="blk_k"  id="blk_k"  value="0"></label>
            <label>BLK Na <input type="number" step="any" name="blk_na" id="blk_na" value="0"></label>
        </div>

        <br>
        <h2>Capacidad de Intercambio Catiónico (CIC)</h2>
        <div class="grid2">
            <label>Cic ml muestra <input type="number" step="any" name="cic_muestra" id="cic_muestra" value="0" required></label>
        </div>

        <br>

            <?php include '../../components/pie_pagina.php'; ?>

        </form>

    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
