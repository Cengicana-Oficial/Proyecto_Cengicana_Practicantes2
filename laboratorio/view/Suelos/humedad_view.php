<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.humedad');

$doc_elemento = 'Humedad';
$doc_tipo = 'Suelos';
$doc_codigo = 'LAB-FS-042';
$doc_fecha_doc = '2024-03-01';
$doc_edicion = '03';
$doc_vf = 'V2';
$fecha_actual = date('d-m-Y');
$lote_actual = $_POST['lote'][0] ?? $_GET['lote'] ?? ($lote_actual ?? '');
$observaciones = '';
$resultado = $resultado ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Humedad</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
<div class="page-wrap">
    <a class="back-link" href="../../view/labc_index.php">Volver</a>
    <h2>Humedad</h2>

    <?php if (!empty($resultado)): ?>
        <div class="alerta <?= !empty($resultado['exito']) ? 'exito' : 'error' ?>">
            <?= htmlspecialchars($resultado['mensaje'] ?? '') ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php include '../../components/encabezado_doc.php'; ?>
        <form method="POST" action="" data-lab-shared-rows="1">
            <div class="form-body">
                <div class="section-title">Datos de analisis</div>
                <div class="field-group">
                    <div class="field">
                        <label>Blanco<input type="number" step="any" name="blanco"></label>
                        <label>Control<input type="number" step="any" name="control"></label>
                        <label>Humedad<input type="number" step="any" name="humedad"></label>
                    </div>
                </div>
                <?php include '../../components/pie_pagina.php'; ?>
            </div>
        </form>
    </div>
</div>
</body>
</html>
