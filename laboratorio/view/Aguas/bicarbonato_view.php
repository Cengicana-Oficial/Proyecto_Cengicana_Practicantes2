<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.bicarbonato');

$doc_elemento  = "Bicarbonato";
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
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">← Volver</a>
    <h2>Bicarbonato en Aguas</h2>

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
        <label>mL de H2SO4 para Alcalinidad Total
            <input type="number" step="any" name="ml_acl" id="ml_acl" value = "0.00" required>
        </label>
        <label>mL de H2SO4 para Carbonatos
            <input type="number" step="any" name="ml_carbonatos" id="ml_carbonatos" value="0.00" required>
        </label>
        <label>Volumen de la muestra
            <input type="number" step="any" name="volumen_muestra" id="volumen_muestra" value="100.00" required>
        </label>
        </div>
        
       </div>

                <?php include '../../components/pie_pagina.php'; ?>

            </div>
        </form>
    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
