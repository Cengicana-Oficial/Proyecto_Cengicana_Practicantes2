<?php

/**
 * Envia por correo el gafete/badge de un evento a uno o varios participantes
 * ya registrados (evento_participantes), como PDF adjunto generado en el
 * servidor con Dompdf (mismo motor que informe_instructor_pdf.php) y un
 * codigo QR renderizado con chillerlan/php-qrcode (Dompdf no ejecuta JS, asi
 * que el QR client-side de qrcode-generator.js, usado en eventos_qr.php, no
 * sirve aqui).
 *
 * Endpoint AJAX dedicado (no vive dentro de eventos_qr.php) para poder
 * devolver JSON limpio con el resumen del envio masivo, en vez de mezclarlo
 * con el flujo de mensaje flash + redirect que usan las demas acciones POST
 * de eventos_qr.php.
 *
 * Recibe (POST):
 *   evento_id         int
 *   participante_ids  int[] (ids de evento_participantes, no de participantes)
 *
 * Requiere permiso de gestion de eventos (mismo nivel que crear eventos o
 * registrar participantes), no solo de ver_eventos_cengi: se revisa aqui
 * mismo con cengi_puede_gestionar_eventos() en vez de
 * cengi_require_gestionar_eventos(), porque ese helper redirige con
 * header("Location: ...") y esto es un endpoint JSON.
 */

require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

header('Content-Type: application/json; charset=UTF-8');

function cengi_gaf_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Genera el codigo QR como data-URI PNG (base64), para poder embeberlo
 * directamente en el HTML que se le pasa a Dompdf (que no puede leer
 * <script>/<canvas>/SVG generado en el navegador).
 */
function cengi_gaf_generar_qr_data_uri(string $codigo): string
{
    $opciones = new QROptions([
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'scale' => 6,
        'imageTransparent' => false,
    ]);

    return (new QRCode($opciones))->render($codigo);
}

/**
 * Arma el PDF del gafete (mismo contenido visual que la tarjeta
 * .cengi-badge-card de eventos_qr.php: eyebrow, nombre del evento, nombre del
 * participante, ingenio/institucion, caja de QR y codigo mono) y devuelve los
 * bytes del PDF, listos para adjuntar a un correo o guardar en disco.
 */
function cengi_gaf_generar_pdf_bytes(string $nombreParticipante, string $ingenio, string $nombreEvento, string $codigoQr): string
{
    $qrDataUri = cengi_gaf_generar_qr_data_uri($codigoQr);

    $html = '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 0; }
    body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #294033; margin: 0; padding: 40px; }
    .gaf-card { width: 320px; margin: 0 auto; border: 1px solid #dbe8dc; border-radius: 14px; overflow: hidden; }
    .gaf-top { background-color: #1f5632; padding: 18px 20px; color: #ffffff; text-align: center; }
    .gaf-eyebrow { font-size: 11px; letter-spacing: 1px; text-transform: uppercase; opacity: .9; }
    .gaf-event { margin-top: 6px; font-size: 16px; font-weight: bold; }
    .gaf-body { padding: 22px 20px; text-align: center; }
    .gaf-name { font-size: 19px; font-weight: bold; color: #294033; }
    .gaf-company { margin: 4px 0 18px; color: #55705f; font-size: 13px; }
    .gaf-qr-box { width: 160px; height: 160px; margin: 0 auto; border: 1.5px solid #dbe8dc; border-radius: 10px; text-align: center; }
    .gaf-qr-box img { width: 140px; height: 140px; margin-top: 9px; }
    .gaf-code { margin-top: 12px; color: #55705f; font-size: 11px; letter-spacing: .5px; }
</style>
</head>
<body>
    <div class="gaf-card">
        <div class="gaf-top">
            <div class="gaf-eyebrow">CENGICAÑA &middot; Evento t&eacute;cnico</div>
            <div class="gaf-event">' . cengi_gaf_html($nombreEvento) . '</div>
        </div>
        <div class="gaf-body">
            <div class="gaf-name">' . cengi_gaf_html($nombreParticipante) . '</div>
            <div class="gaf-company">' . cengi_gaf_html($ingenio) . '</div>
            <div class="gaf-qr-box"><img src="' . $qrDataUri . '" alt="QR"></div>
            <div class="gaf-code">' . cengi_gaf_html($codigoQr) . '</div>
        </div>
    </div>
</body>
</html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, 340, 480], 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'mensaje' => 'Metodo no permitido.']);
    exit;
}

if (!cengi_puede_gestionar_eventos()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'No tienes permiso para enviar gafetes de este evento.']);
    exit;
}

$db = conectar();

$eventoId = (int) ($_POST['evento_id'] ?? 0);
$participanteIdsCrudo = $_POST['participante_ids'] ?? [];
if (!is_array($participanteIdsCrudo)) {
    $participanteIdsCrudo = [$participanteIdsCrudo];
}
$participanteIds = array_values(array_unique(array_filter(array_map('intval', $participanteIdsCrudo), static function ($valor) {
    return $valor > 0;
})));

if ($eventoId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Falta el evento_id.']);
    exit;
}

$stmtEvento = $db->prepare('SELECT id, nombre FROM eventos WHERE id = ?');
$stmtEvento->execute([$eventoId]);
$evento = $stmtEvento->fetch(PDO::FETCH_ASSOC);
if (!$evento) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'mensaje' => 'El evento no existe.']);
    exit;
}

if (!$participanteIds) {
    echo json_encode(['ok' => false, 'mensaje' => 'Selecciona al menos un participante.']);
    exit;
}

// Los ids recibidos siempre se filtran por "AND ep.evento_id = ?": nunca se
// confia en que un id de evento_participantes recibido desde el cliente
// realmente pertenezca al evento_id tambien recibido (podria manipularse
// para intentar mandar el gafete de un participante de otro evento).
$marcadores = implode(',', array_fill(0, count($participanteIds), '?'));
$stmt = $db->prepare("
    SELECT
        ep.id,
        COALESCE(NULLIF(p.nombre_participantes, ''), ep.nombre_invitado) AS nombre,
        ep.codigo_qr,
        COALESCE(NULLIF(p.correo_participantes, ''), NULLIF(ep.correo_invitado, '')) AS correo,
        COALESCE(i.nombre_ingenios, 'Invitado externo') AS ingenio
    FROM evento_participantes ep
    LEFT JOIN participantes p ON p.id = ep.participante_id
    LEFT JOIN ingenios i ON i.id = p.ingenio_id
    WHERE ep.evento_id = ? AND ep.id IN ({$marcadores})
    ORDER BY nombre
");
$stmt->execute(array_merge([$eventoId], $participanteIds));
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$enviados = 0;
$sinCorreo = [];
$fallidos = [];

foreach ($filas as $fila) {
    $nombre = (string) ($fila['nombre'] ?? 'Participante');
    $correo = trim((string) ($fila['correo'] ?? ''));

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $sinCorreo[] = $nombre;
        continue;
    }

    try {
        $pdfBytes = cengi_gaf_generar_pdf_bytes($nombre, (string) $fila['ingenio'], (string) $evento['nombre'], (string) $fila['codigo_qr']);

        $participanteNombre = $nombre;
        $eventoNombre = (string) $evento['nombre'];
        $codigoQr = (string) $fila['codigo_qr'];
        require __DIR__ . '/correos/plantilla_gafete_evento.php';

        $nombreArchivo = 'gafete-' . (cengi_diploma_sanitizar_segmento($nombre) ?: 'participante') . '.pdf';

        $enviado = cengi_enviar_correo(
            $correo,
            $nombre,
            'Su gafete para el evento: ' . $evento['nombre'],
            $html,
            [[
                'contenido' => $pdfBytes,
                'nombre' => $nombreArchivo,
                'tipo' => 'application/pdf',
            ]]
        );

        if ($enviado) {
            $enviados++;
        } else {
            $fallidos[] = $nombre;
        }
    } catch (Throwable $errorEnvio) {
        error_log('enviar_gafetes_evento: error enviando gafete de "' . $nombre . '" (evento_participante_id ' . $fila['id'] . '): ' . $errorEnvio->getMessage());
        $fallidos[] = $nombre;
    }
}

echo json_encode([
    'ok' => true,
    'evento_id' => $eventoId,
    'total_seleccionados' => count($participanteIds),
    'enviados' => $enviados,
    'sin_correo' => $sinCorreo,
    'fallidos' => $fallidos,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
