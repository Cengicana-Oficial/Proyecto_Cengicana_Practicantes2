<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/recepcion_boleta_helper.php';

lab_require_module_access();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$id = filter_input(INPUT_GET, 'id_solicitud', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Solicitud inválida.']);
    exit;
}
try {
    $tipo = labSolicitudObtenerTipoActual($conexion, $id);
    if (!$tipo) {
        http_response_code(404);
        echo json_encode(['error' => 'La recepción no existe.']);
        exit;
    }
    if (!lab_can('laboratorio.solicitudes.crear') && !lab_can('laboratorio.solicitudes.ver') && !lab_can('laboratorio.documentos.ver') && !labSolicitudPuedeEditarTipo($tipo)) {
        http_response_code(403);
        echo json_encode(['error' => 'No tiene permiso para consultar esta boleta.']);
        exit;
    }
    $datos = labRecepcionObtenerBoleta($conexion, $id);
    if (!$datos) {
        http_response_code(404);
        echo json_encode(['error' => 'La recepción no existe.']);
        exit;
    }
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[laboratorio][boleta] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo cargar la boleta. Intente nuevamente.']);
}
