<?php
$estado = isset($_GET['estado']) ? '&estado=' . urlencode($_GET['estado']) : '';
header("Location: dashboard_unificado.php?modulo=solicitudes{$estado}");
exit;
