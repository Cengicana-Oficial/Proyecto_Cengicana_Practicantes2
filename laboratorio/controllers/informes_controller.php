<?php
/**
 * informes_controller.php — informe de un lote (POST JSON):
 *   'vista'    → HTML del informe con el formato del laboratorio (vista previa = mismo documento del PDF)
 *   'firmar'   → aprobar y firmar (jefe de laboratorio)
 *   'devolver' → devolver al técnico con motivo (jefe de laboratorio)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/informes_model.php';

header('Content-Type: application/json; charset=utf-8');
lab_require_permission('laboratorio.informes.ver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$datos = json_decode((string) file_get_contents('php://input', false, null, 0, 6000001), true);
if (!is_array($datos)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Solicitud inválida.']);
    exit;
}
if (empty($_SESSION['informes_csrf']) || !hash_equals((string) $_SESSION['informes_csrf'], (string) ($datos['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'La sesión venció. Recargue la página.']);
    exit;
}

$idLote = (int) ($datos['id_lote'] ?? 0);
$usuario = lab_current_user();
try {
    $pdo = Conexion::conectar();
    lab_informes_esquema($pdo);
    $accion = (string) ($datos['accion'] ?? '');
    if (in_array($accion, ['firmar', 'devolver'], true) && !lab_can('laboratorio.firma.gestionar')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo el jefe de laboratorio puede aprobar, firmar o devolver informes.']);
        exit;
    }
    if ($accion === 'vista') {
        $detalle = lab_informe_detalle($pdo, $idLote);
        if (!$detalle) throw new InvalidArgumentException('El lote no tiene análisis registrados.');
        $est = lab_informe_estructura($detalle);
        $firmado = $detalle['lote']['estado'] === 'FIRMADO';
        // Firmado: valores aprobados guardados con la firma. Borrador: valores calculados en el navegador.
        $valores = $firmado
            ? (json_decode((string) ($detalle['lote']['informe']['resultados_json'] ?? ''), true) ?: [])
            : lab_informe_valores_limpios($est, is_array($datos['resultados'] ?? null) ? $datos['resultados'] : []);
        $meta = lab_informe_meta($pdo, $detalle, !$firmado);
        if (!$firmado && isset($datos['observaciones'])) $meta['observaciones'] = mb_substr(trim((string) $datos['observaciones']), 0, 2000);
        $resultado = ['html' => lab_informe_reporte_html($est, $detalle, $valores, $meta)];
    } elseif ($accion === 'firmar') {
        $resultado = lab_informe_firmar($pdo, $idLote, (string) ($datos['observaciones'] ?? ''), (string) ($datos['firma_png'] ?? ''), is_array($datos['resultados'] ?? null) ? $datos['resultados'] : [], $usuario);
    } elseif ($accion === 'devolver') {
        $resultado = lab_informe_devolver($pdo, $idLote, (string) ($datos['motivo'] ?? ''), $usuario);
    } else {
        throw new InvalidArgumentException('Acción no válida.');
    }
    echo json_encode(['ok' => true, 'data' => $resultado], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('[laboratorio][informes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar la acción.']);
} catch (RuntimeException $e) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[laboratorio][informes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar la acción.']);
}
