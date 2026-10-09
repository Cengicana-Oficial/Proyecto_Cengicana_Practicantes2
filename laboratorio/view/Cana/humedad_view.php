<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('cana.humedad');

$doc_elemento  = "Humedad";
$doc_tipo      = "Caña";
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
    <title>% Humedad</title>
    <link rel="stylesheet" href="../../styles/formularios.css?v=20261006">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">← Volver</a>
    <h2>Porcentaje de Humedad</h2>

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
        <label>Número de bandeja
            <input type="number" step="any" name="no_bandeja" id = "no_bandeja" required>
        </label>
        <label>Peso de bandeja (grs)
            <input type="number" step="any" name="peso_bandeja" id = "peso_bandeja" required>
        </label>
        <label>Peso muestra (grs)
            <input type="number" step="any" name="peso_muestra" id = "peso_bandeja" required>
        </label>
        <label>Peso bandeja + muestra seca
            <input type="number" step="any" name="peso_bandeja_seca" id = "peso_bandeja" required>
        </label>

        <br>

                <?php include '../../components/pie_pagina.php'; ?>

            </div>
        </form>
    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
