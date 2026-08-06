<?php
require_once __DIR__ . "/classes/export_helpers.php";

$encabezados = ['INGENIO', 'CUI', 'NOMBRE', 'PUESTO', 'AREA', 'CORREO_ELECTRONICO', 'GRADO_ACADEMICO', 'TELEFONO'];

$filas = [
    ['Nombre del ingenio', '1234567890101', 'Nombre de ejemplo', 'Analista', 'Capacitación', 'persona@ejemplo.com', 'Licenciatura', '5555-5555'],
];

$anchosColumnas = [24, 18, 30, 20, 20, 30, 20, 16];

cengi_export_enviar_excel($encabezados, $filas, 'Plantilla participantes', 'plantilla_participantes', $anchosColumnas);
