<?php

/**
 * Envia por correo el gafete/badge de un evento (PDF generado con Dompdf y QR con
 * chillerlan/php-qrcode, ver cengi_evp_gafete_pdf() en eventos_pagos_helpers.php) a
 * los participantes seleccionados en "Ver participantes" de eventos_qr.php.
 *
 * Solo se envia a participantes con la participacion confirmada: en un evento pagado
 * el QR queda habilitado cuando Administracion valida el pago y Capacitacion confirma.
 *
 * Recibe (POST): evento_id int, participante_ids int[] (ids de evento_participantes).
 * Requiere permiso de gestion de eventos; se revisa aqui (no con cengi_require_*)
 * porque es un endpoint JSON.
 */

require_once __DIR__ . '/eventos_pagos_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

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
$participanteIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['participante_ids'] ?? [])))));

$stmtEvento = $db->prepare('SELECT id FROM eventos WHERE id = ?');
$stmtEvento->execute([$eventoId]);
if (!$stmtEvento->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'mensaje' => 'El evento no existe.']);
    exit;
}
if (!$participanteIds) {
    echo json_encode(['ok' => false, 'mensaje' => 'Selecciona al menos un participante.']);
    exit;
}

// cengi_evp_enviar_gafetes() filtra siempre por "AND ep.evento_id = ?".
$r = cengi_evp_enviar_gafetes($db, $eventoId, $participanteIds);

echo json_encode([
    'ok' => true,
    'evento_id' => $eventoId,
    'total_seleccionados' => count($participanteIds),
    'enviados' => $r['enviados'],
    'sin_correo' => $r['sin_correo'],
    'fallidos' => $r['fallidos'],
    'sin_confirmar' => $r['sin_confirmar'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
