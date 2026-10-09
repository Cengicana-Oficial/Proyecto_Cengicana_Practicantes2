<?php
/**
 * enviar_reporte_correo.php
 *
 * Reportes: envía por correo el PDF vigente de un informe del repositorio (lab_documento).
 * El PDF se genera en el servidor a partir de la versión guardada, no del navegador.
 * POST JSON { id_documento, emails, csrf } → JSON { ok, message }.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/documentos_model.php';
require_once __DIR__ . '/../includes/documento_pdf_helper.php';
require_once __DIR__ . '/../includes/correo_helper.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: application/json; charset=UTF-8');

function reporte_correo_responder(int $estado, bool $ok, string $mensaje): void
{
    http_response_code($estado);
    echo json_encode(['ok' => $ok, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!lab_is_authenticated()) {
    reporte_correo_responder(401, false, 'La sesión venció. Inicie sesión nuevamente.');
}
if (!lab_can('laboratorio.documentos.ver')) {
    reporte_correo_responder(403, false, 'Su usuario no tiene acceso a Reportes.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reporte_correo_responder(405, false, 'Método no permitido.');
}

$datos = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($datos)) {
    reporte_correo_responder(400, false, 'Solicitud no válida.');
}
if (empty($_SESSION['reportes_csrf']) || !hash_equals((string) $_SESSION['reportes_csrf'], (string) ($datos['csrf'] ?? ''))) {
    reporte_correo_responder(419, false, 'La página expiró. Recárguela e intente de nuevo.');
}

try {
    $emails = normalizarCorreos($datos['emails'] ?? []);
    if (!$emails) {
        throw new RuntimeException('Ingrese al menos un correo válido.');
    }
    if (count($emails) > 10) {
        throw new RuntimeException('Puede enviar el reporte a un máximo de 10 correos a la vez.');
    }

    $pdo = Conexion::conectar();
    $documento = lab_documento_obtener($pdo, (int) ($datos['id_documento'] ?? 0));
    if (!$documento || ($documento['tipo_documento'] ?? '') !== 'informe') {
        throw new RuntimeException('El reporte solicitado no existe.');
    }

    $pdf = lab_documento_pdf_binario($documento);
    $lote = trim((string) ($documento['codigo_lote'] ?? '')) ?: '—';
    $tipo = trim((string) ($documento['tipo_muestra'] ?? ''));
    $tipo = $tipo === '' ? '' : mb_convert_case($tipo, MB_CASE_TITLE, 'UTF-8');
    $cliente = trim((string) ($documento['cliente'] ?? '')) ?: 'Sin institución';
    $version = (int) ($documento['version'] ?? 1);
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $mail = labCorreoNuevoMailer();
    foreach ($emails as $email) {
        $mail->addAddress($email);
    }
    $mail->Subject = 'Informe de resultados · Lote ' . $lote . ($tipo !== '' ? ' (' . $tipo . ')' : '') . ' · CENGILABS';
    $mail->isHTML(true);
    $mail->Body = '<div style="font-family:Arial,sans-serif;color:#20242a;font-size:14px;line-height:1.5">'
        . '<p>Estimado(a) cliente:</p>'
        . '<p>Adjuntamos el <strong>informe de resultados</strong> del lote <strong>' . $e($lote) . '</strong>'
        . ($tipo !== '' ? ' (' . $e($tipo) . ')' : '') . ' correspondiente a <strong>' . $e($cliente) . '</strong>'
        . ' — versión v' . $version . '.</p>'
        . '<p>Ante cualquier consulta, responda a este correo.</p>'
        . '<p style="color:#55705f">CENGILABS · Laboratorio Agroindustrial CENGICAÑA</p></div>';
    $mail->AltBody = "Estimado(a) cliente:\n\nAdjuntamos el informe de resultados del lote {$lote}"
        . ($tipo !== '' ? " ({$tipo})" : '') . " correspondiente a {$cliente} — versión v{$version}.\n\n"
        . "CENGILABS · Laboratorio Agroindustrial CENGICAÑA";
    $mail->addStringAttachment($pdf, lab_documento_pdf_nombre($documento), 'base64', 'application/pdf');

    try {
        $mail->send();
    } catch (PHPMailerException $ex) {
        throw new RuntimeException('No se pudo enviar el correo: ' . $mail->ErrorInfo);
    }

    // Queda constancia del envío en el historial del documento (no cambia la versión).
    $usuario = lab_current_user();
    $pdo->prepare('INSERT INTO lab_documento_historial (id_documento, version, cambios, usuario) VALUES (?, ?, ?, ?)')
        ->execute([
            (int) $documento['id_documento'],
            $version,
            'Enviado por correo a: ' . implode(', ', $emails),
            (string) ($usuario['nombre'] ?? $usuario['correo'] ?? 'Usuario'),
        ]);

    reporte_correo_responder(200, true, 'Reporte del lote ' . $lote . ' enviado a ' . implode(', ', $emails) . '.');
} catch (Throwable $ex) {
    reporte_correo_responder(400, false, $ex->getMessage());
}
