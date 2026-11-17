<?php
/**
 * marcar_entregado.php
 *
 * Reportes: registra que un informe firmado se entregó al cliente por un medio distinto al correo
 * del sistema (en persona impreso, WhatsApp, correo personal…). Queda en el historial del documento
 * con el prefijo "Entregado al cliente", que el sistema trata igual que un envío por correo:
 * el reporte deja de estar "Pendiente de envío", la etapa Entrega se completa y la notificación
 * "Reportes firmados por enviar" desaparece.
 * POST JSON { id_documento, medio, detalle, csrf } → JSON { ok, message }.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/documentos_model.php';

header('Content-Type: application/json; charset=UTF-8');

const LAB_ENTREGA_MEDIOS = ['En persona (impreso)', 'WhatsApp', 'Correo electrónico personal', 'Otro medio'];

function entregado_responder(int $estado, bool $ok, string $mensaje): void
{
    http_response_code($estado);
    echo json_encode(['ok' => $ok, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!lab_is_authenticated()) {
    entregado_responder(401, false, 'La sesión venció. Inicie sesión nuevamente.');
}
if (!lab_can('laboratorio.documentos.ver')) {
    entregado_responder(403, false, 'Su usuario no tiene acceso a Informes emitidos.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    entregado_responder(405, false, 'Método no permitido.');
}

$datos = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($datos)) {
    entregado_responder(400, false, 'Solicitud no válida.');
}
if (empty($_SESSION['reportes_csrf']) || !hash_equals((string) $_SESSION['reportes_csrf'], (string) ($datos['csrf'] ?? ''))) {
    entregado_responder(419, false, 'La página expiró. Recárguela e intente de nuevo.');
}

try {
    $medio = trim((string) ($datos['medio'] ?? ''));
    if (!in_array($medio, LAB_ENTREGA_MEDIOS, true)) {
        throw new RuntimeException('Seleccione el medio de entrega.');
    }
    $detalle = trim(preg_replace('/\s+/u', ' ', (string) ($datos['detalle'] ?? '')));
    $detalle = mb_substr($detalle, 0, 300);
    if ($detalle === '') {
        throw new RuntimeException('Indique quién recibió el informe o el detalle de la entrega.');
    }

    $pdo = Conexion::conectar();
    $documento = lab_documento_obtener($pdo, (int) ($datos['id_documento'] ?? 0));
    if (!$documento || ($documento['tipo_documento'] ?? '') !== 'informe') {
        throw new RuntimeException('El reporte solicitado no existe.');
    }

    $usuario = lab_current_user();
    $pdo->prepare('INSERT INTO lab_documento_historial (id_documento, version, cambios, usuario) VALUES (?, ?, ?, ?)')
        ->execute([
            (int) $documento['id_documento'],
            (int) ($documento['version'] ?? 1),
            'Entregado al cliente · ' . $medio . ' · ' . $detalle,
            (string) ($usuario['nombre'] ?? $usuario['correo'] ?? 'Usuario'),
        ]);

    $lote = trim((string) ($documento['codigo_lote'] ?? '')) ?: '—';
    entregado_responder(200, true, 'Entrega del informe del lote ' . $lote . ' registrada (' . $medio . ').');
} catch (Throwable $ex) {
    entregado_responder(400, false, $ex->getMessage());
}
