<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('aguas.alcanilidad');

$doc_elemento  = "Alcalinidad";
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
    <title>Alcalinidad</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">← Volver</a>
    <h2>Alcalinidad en Aguas</h2>

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
                        <label for="ml_h2oso4">mL de H2SO4</label>
                        <input type="number" step="any" name="ml_h2oso4" id="ml_h2oso4" value="0.00" required>
                    </div>
                    <div class="field">
                        <label for="volumen_muestra">Volumen de la muestra</label>
                        <input type="number" step="any" name="volumen_muestra" id="volumen_muestra" value="100.00" required>
                    </div>
                </div>

                <?php include '../../components/pie_pagina.php'; ?>

            </div>
        </form>
    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
