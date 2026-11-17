<?php
ob_start();

require_once __DIR__ . "/classes/export_helpers.php";

$encabezados = ['NOMBRE', 'CUI', 'CORREO_ELECTRONICO', 'INGENIO', 'TELEFONO', 'CARGO'];

$filas = [
    ['Nombre de ejemplo', '1234567890101', 'persona@ejemplo.com', 'Nombre del ingenio', '5555-5555', 'Jefe de campo'],
];

$anchosColumnas = [30, 18, 30, 24, 16, 26];

cengi_export_enviar_excel($encabezados, $filas, 'Plantilla carga masiva eventos', 'plantilla_carga_masiva_eventos', $anchosColumnas);
