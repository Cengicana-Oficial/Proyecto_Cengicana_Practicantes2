<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.cc');

$doc_elemento = 'Capacidad de Campo';
$doc_tipo = 'Suelos';
$doc_codigo = 'LAB-FS-042';
$doc_fecha_doc = '2024-03-01';
$doc_edicion = '03';
$doc_vf = 'V2';

$fecha_actual = date('d-m-Y');
$lote_actual = $_POST['lote'][0] ?? $_GET['lote'] ?? ($lote_actual ?? '');
$tecnicos = [
    ['id' => 1, 'nombre' => 'Ana Lopez Mendez'],
    ['id' => 2, 'nombre' => 'Carlos Ruiz'],
    ['id' => 3, 'nombre' => 'Maria Perez'],
];
$observaciones = '';
$resultado = $resultado ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capacidad de Campo</title>
    <link rel="stylesheet" href="../../styles/formularios.css">
</head>
<body>
    <div class="page-wrap">
    <a class="back-link" href="../../view/labc_index.php">Volver</a>
    <h2>Capacidad de Campo</h2>

    <?php if (!empty($resultado)): ?>
        <div class="alerta <?= $resultado['exito'] ? 'exito' : 'error' ?>">
            <?= htmlspecialchars($resultado['mensaje']) ?>
        </div>
    <?php endif; ?>

    <div class="card">

        <?php include '../../components/encabezado_doc.php'; ?>

    <form method="POST" action="" data-lab-shared-rows="1">
        <div class="form-body">

                <div class="section-title">Datos de analisis</div>
                <div class="field-group">
                    <div class="field">
        <label>Peso de caja
            <input type="number" step="any" name="peso_caja" required>
        </label>
        <label>Numero de caja
            <input type="text" step="any" name="no_caja" required>
        </label>
        <label>Control
            <input type="number" step="any" name="control" required>
        </label>
        <label>Peso de caja + Muestra Humeda
            <input type="number" step="any" name="peso_caja_mhumeda" required>
        </label>
        <label>Peso de caja + Muestra Seca
            <input type="number" step="any" name="peso_caja_mseca" required>
        </label>

        <br>
            <?php include '../../components/pie_pagina.php'; ?>

        </form>

    </div><!-- /.card -->

</div><!-- /.page-wrap -->
</body>
</html>
