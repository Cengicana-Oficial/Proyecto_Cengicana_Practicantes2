<?php
/**
 * Muestra el comprobante de pago de una inscripcion (?s=<id_solicitud>) o de una orden
 * grupal del ingenio (?g=<pagos_grupales.id>) a quien puede ver pagos de inscripcion;
 * los usuarios de un ingenio solo ven los de su ingenio.
 */
require_once __DIR__ . '/revisar_permisos.php'; // exige sesion iniciada
require_once __DIR__ . '/inscripcion_helpers.php';

if (!cengi_ins_puede_ver_pagos()) {
    http_response_code(403);
    exit('No tienes acceso a este comprobante.');
}

$db = conectar();
if (!empty($_GET['s'])) {
    $stmt = $db->prepare('SELECT s.comprobante_ruta AS ruta, s.comprobante_nombre AS nombre, i.nombre_ingenios FROM solicitudes_inscripcion s LEFT JOIN ingenios i ON i.id = s.ingenio_id WHERE s.id_solicitud = ?');
    $stmt->execute([(int) $_GET['s']]);
} else {
    $stmt = $db->prepare('SELECT g.comprobante_ruta AS ruta, g.comprobante_nombre AS nombre, i.nombre_ingenios FROM pagos_grupales g LEFT JOIN ingenios i ON i.id = g.ingenio_id WHERE g.id = ?');
    $stmt->execute([(int) ($_GET['g'] ?? 0)]);
}
$fila = $stmt->fetch(PDO::FETCH_ASSOC);
$limitado = cengi_ins_ingenio_limitado();
if ($fila && $limitado !== null && cengi_texto_normalizado($fila['nombre_ingenios'] ?? '') !== cengi_texto_normalizado($limitado)) {
    $fila = null;
}

$base = realpath(__DIR__ . '/../uploads');
$url = $fila ? cengi_normalizar_url_archivo($fila['ruta']) : '';
$archivo = $url !== '' ? realpath(__DIR__ . '/..' . $url) : false;
if (!$base || !$archivo || strpos($archivo, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($archivo)) {
    http_response_code(404);
    exit('El comprobante no existe o ya no está disponible.');
}
$tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($archivo));
header('Content-Disposition: inline; filename="' . str_replace('"', '', preg_replace('/[^\w .()-]/u', '_', $fila['nombre'] ?: 'comprobante.' . $extension)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($archivo);
