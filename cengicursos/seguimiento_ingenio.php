<?php
/**
 * "Seguimiento por ingenio" se unifico con "Mi ingenio" en dashboard_ingenio.php (mismos
 * indicadores y graficas, mas participantes, diplomas y exportacion). Se conserva esta
 * ruta para enlaces guardados: redirige conservando el ingenio elegido.
 */
$ingenioId = (int) ($_GET['ingenio_id'] ?? 0);
header('Location: dashboard_ingenio.php' . ($ingenioId > 0 ? '?ingenio_id=' . $ingenioId : ''), true, 301);
exit;
