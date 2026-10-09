<?php

require_once __DIR__ . '/../includes/auth.php';
lab_require_permission('laboratorio.consolidacion.ver');

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/control_calidad_model.php';

$diasCalidad = (int) ($_GET['dias'] ?? 30);
$errorCalidad = '';
try {
    $calidad = lab_control_calidad(Conexion::conectar(), $diasCalidad);
} catch (Throwable $e) {
    error_log('[laboratorio][control_calidad] ' . $e->getMessage());
    $errorCalidad = 'No se pudieron calcular los indicadores de calidad. Intente nuevamente.';
    $calidad = lab_control_calidad_vacio($diasCalidad);
}

require_once __DIR__ . '/../view/consolidacion_view.php';
