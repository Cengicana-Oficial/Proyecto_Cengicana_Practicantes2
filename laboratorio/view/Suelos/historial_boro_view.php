<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_analysis_access('suelos.boro');
$historial = $historial ?? [];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial Boro</title>
    <link rel="stylesheet" href="../../styles/formularios.css?v=20261006">
    <link rel="stylesheet" href="../../styles/historial.css?v=20261006">
</head>

<body>
<div class="page-wrap">
    <div class="card historial-card">

        <h2>Historial de Análisis de Boro</h2>
        
        <a class="back-link" href="../../view/labc_index.php">← Volver</a>

        <div class="table-wrap">
            <table class="historial-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Absorbancia</th>
                        <th>PPM Boro</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
    <?php foreach ($historial as $fila): ?>

        <tr>

            <td><?= $fila['id'] ?></td>

            <td><?= $fila['absorbancia'] ?></td>

            <td><?= $fila['ppm_b'] ?></td>

            <td>
                <a href="curva_boro_controller.php?id=<?= $fila['id'] ?>">
                    Ver gráfica
                </a>
            </td>

        </tr>

    <?php endforeach; ?>

</tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
