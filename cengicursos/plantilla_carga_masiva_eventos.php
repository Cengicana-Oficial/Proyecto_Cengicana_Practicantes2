<?php
ob_start();

require_once __DIR__ . "/classes/export_helpers.php";

$encabezados = ['NOMBRE', 'CUI', 'CORREO_ELECTRONICO', 'INGENIO'];

$filas = [
    ['Nombre de ejemplo', '1234567890101', 'persona@ejemplo.com', 'Nombre del ingenio'],
];

$anchosColumnas = [30, 18, 30, 24];

cengi_export_enviar_excel($encabezados, $filas, 'Plantilla carga masiva eventos', 'plantilla_carga_masiva_eventos', $anchosColumnas);
