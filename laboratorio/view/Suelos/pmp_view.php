<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.pmp');

$doc_elemento  = "Punto de Marchitez Permanente";
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
    <title>Punto de Marchitez Permanente</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">

    <a href="../../view/labc_index.php" class="back-link">← Volver</a>
    <h2>Punto de Marchitez Permanente</h2>

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
                        <label for="peso_caja">Peso de caja</label>
                        <input type="number" step="any" name="peso_caja" id="peso_caja" required>
                    </div>
                    <div class="field">
                        <label for="no_caja">Número de caja</label>
                        <input type="text" name="no_caja" id="no_caja" required>
                    </div>
                    <div class="field">
                        <label for="control">Control</label>
                        <input type="number" step="any" name="control" id="control" required>
                    </div>
                    <div class="field">
                        <label for="peso_caja_mhumeda">Peso de caja + Muestra Húmeda</label>
                        <input type="number" step="any" name="peso_caja_mhumeda" id="peso_caja_mhumeda" required>
                    </div>
                    <div class="field">
                        <label for="peso_caja_mseca">Peso de caja + Muestra Seca</label>
                        <input type="number" step="any" name="peso_caja_mseca" id="peso_caja_mseca" required>
                    </div>
                </div>

            <?php include '../../components/pie_pagina.php'; ?>

        </form>

    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
