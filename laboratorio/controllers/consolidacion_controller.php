<?php

require_once __DIR__ . '/../includes/auth.php';
lab_require_permission('laboratorio.consolidacion.ver');

require_once __DIR__ . '/../models/consolidacion_model.php';

$indicadoresCalidad = obtenerIndicadoresControlCalidad();

require_once __DIR__ . '/../view/consolidacion_view.php';

?>
