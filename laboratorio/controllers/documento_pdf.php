<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/documentos_model.php';

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

$autoload = __DIR__ . '/../../cengicursos/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'El generador de PDF no está disponible en este ambiente.';
    exit;
}
require_once $autoload;

function documento_pdf_e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$contenido = trim((string) ($documento['contenido_html'] ?? ''));
if ($contenido === '') {
    $tipo = ($documento['tipo_documento'] ?? '') === 'boleta'
        ? 'Boleta de recepción'
        : 'Informe de resultados';
    $titulo = trim((string) ($documento['titulo'] ?? ''));
    if ($titulo === '') {
        $titulo = $tipo . ' #' . (int) $documento['id_documento'];
    }
    $fecha = trim((string) ($documento['generado_en'] ?? ''));
    $fechaFormateada = $fecha !== '' && strtotime($fecha)
        ? date('d/m/Y H:i', strtotime($fecha))
        : '—';

    $contenido = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page{margin:32px 38px}body{font-family:DejaVu Sans,sans-serif;color:#20242a;font-size:12px}
        .head{border-bottom:3px solid #73bc25;padding-bottom:14px;margin-bottom:24px}
        h1{font-size:21px;margin:0 0 5px}.sub{color:#6f777d}.grid{width:100%;border-collapse:collapse}
        .grid td{padding:10px 12px;border:1px solid #dfe3e5}.grid td:first-child{width:34%;font-weight:bold;background:#f5f7f4}
        .note{margin-top:24px;padding:13px;border:1px solid #dfe3e5;background:#f7f8f8;color:#6f777d}
    </style></head><body>
        <div class="head"><h1>' . documento_pdf_e($titulo) . '</h1><div class="sub">SIGELAB · Laboratorio Agroindustrial</div></div>
        <table class="grid">
            <tr><td>Documento</td><td>DOC-' . str_pad((string) (int) $documento['id_documento'], 4, '0', STR_PAD_LEFT) . '</td></tr>
            <tr><td>Tipo</td><td>' . documento_pdf_e($tipo) . '</td></tr>
            <tr><td>Lote</td><td>' . documento_pdf_e($documento['codigo_lote'] ?? '—') . '</td></tr>
            <tr><td>Cliente</td><td>' . documento_pdf_e($documento['cliente'] ?? 'Sin institución') . '</td></tr>
            <tr><td>Versión</td><td>v' . (int) ($documento['version'] ?? 1) . '</td></tr>
            <tr><td>Generado por</td><td>' . documento_pdf_e($documento['generado_por'] ?: '—') . '</td></tr>
            <tr><td>Fecha</td><td>' . documento_pdf_e($fechaFormateada) . '</td></tr>
        </table>
        <div class="note">Este registro todavía no contiene una representación HTML detallada. El PDF muestra los metadatos versionados disponibles.</div>
    </body></html>';
} elseif (stripos($contenido, '<html') === false) {
    $contenido = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page{margin:30px}body{font-family:DejaVu Sans,sans-serif;color:#20242a;font-size:11px}
        table{width:100%;border-collapse:collapse}th,td{padding:7px;border:1px solid #dfe3e5}
    </style></head><body>' . $contenido . '</body></html>';
}

$opciones = new Dompdf\Options();
$opciones->set('isRemoteEnabled', false);
$opciones->set('defaultFont', 'DejaVu Sans');
$opciones->set('chroot', dirname(__DIR__));
$dompdf = new Dompdf\Dompdf($opciones);
$dompdf->setPaper('letter', 'portrait');
$dompdf->loadHtml($contenido, 'UTF-8');
$dompdf->render();

$tituloArchivo = trim((string) ($documento['titulo'] ?? ''));
if ($tituloArchivo === '') {
    $tituloArchivo = 'documento-' . (int) $documento['id_documento'];
}
$tituloArchivo = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $tituloArchivo), '-');
if ($tituloArchivo === '') {
    $tituloArchivo = 'documento-' . (int) $documento['id_documento'];
}

$dompdf->stream($tituloArchivo . '-v' . (int) ($documento['version'] ?? 1) . '.pdf', [
    'Attachment' => isset($_GET['download']) && $_GET['download'] === '1',
]);
