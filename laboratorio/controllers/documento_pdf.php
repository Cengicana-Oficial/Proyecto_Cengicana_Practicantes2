<?php
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/documentos_model.php';
require_once __DIR__ . '/../includes/documento_pdf_helper.php';

lab_require_permission('laboratorio.documentos.ver');

$idDocumento = filter_input(INPUT_GET, 'id_documento', FILTER_VALIDATE_INT) ?: 0;
$pdo = Conexion::conectar();
$documento = lab_documento_obtener($pdo, $idDocumento);

if (!$documento) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'El documento solicitado no existe.';
    exit;
}

try {
    $pdf = lab_documento_pdf_binario($documento);
} catch (Throwable $e) {
    // El detalle (rutas, Dompdf, BD) va al log; al usuario solo un mensaje generico.
    error_log('[laboratorio][documento_pdf] documento ' . $idDocumento . ': ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No se pudo generar el PDF del documento. Inténtelo más tarde o contacte al administrador.';
    exit;
}
require_once __DIR__ . '/../../cengicursos/classes/export_helpers.php';

cengi_export_enviar_pdf(
    $pdf,
    lab_documento_pdf_nombre($documento),
    isset($_GET['download']) && $_GET['download'] === '1'
);
