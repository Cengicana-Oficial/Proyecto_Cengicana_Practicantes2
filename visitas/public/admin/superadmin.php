<?php
$modulo = $_GET['modulo'] ?? 'inicio';
$estado = isset($_GET['estado']) ? '&estado=' . urlencode($_GET['estado']) : '';
header("Location: dashboard_unificado.php?modulo=" . urlencode($modulo) . $estado);
exit;
