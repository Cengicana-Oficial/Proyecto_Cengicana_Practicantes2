<?php

/**
 * Endpoint AJAX (JSON) usado por cengicursos/escanear_evento.php: recibe el resultado de
 * cada escaneo de QR desde la camara del celular en la puerta del evento y marca el
 * ingreso del participante correspondiente.
 *
 * Publico (sin revisar_permisos.php), igual que escanear_evento.php: el "login" aqui es
 * la posesion del token de 128 bits, no una sesion de usuario (ver el comentario de
 * cabecera de escanear_evento.php para el porque).
 *
 * Recibe (POST, JSON body):
 *   token      string  token_escaneo del evento (mismo que en escanear_evento.php)
 *   codigo_qr  string  contenido decodificado del QR del gafete (evento_participantes.codigo_qr)
 *
 * El evento_id SIEMPRE se resuelve del token en el servidor, nunca se confia en un
 * evento_id que pudiera mandar el cliente (mismo cuidado de scoping que ya aplica
 * enviar_gafetes_evento.php con "AND ep.evento_id = ?").
 */

require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'mensaje' => 'Método no permitido.']);
    exit;
}

$cuerpo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($cuerpo)) {
    $cuerpo = [];
}

$token = trim((string) ($cuerpo['token'] ?? ($_POST['token'] ?? '')));
$codigoQr = trim((string) ($cuerpo['codigo_qr'] ?? ($_POST['codigo_qr'] ?? '')));

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'mensaje' => 'Enlace de escaneo inválido.']);
    exit;
}

if ($codigoQr === '') {
    echo json_encode(['ok' => false, 'mensaje' => 'No se recibió ningún código QR.']);
    exit;
}

$db = conectar();

$stmtEvento = $db->prepare('SELECT id, nombre FROM eventos WHERE token_escaneo = ?');
$stmtEvento->execute([$token]);
$evento = $stmtEvento->fetch(PDO::FETCH_ASSOC);

if (!$evento) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'mensaje' => 'Enlace de escaneo inválido.']);
    exit;
}

$eventoId = (int) $evento['id'];

$stmt = $db->prepare("
    SELECT ep.id, ep.ingreso_en,
           COALESCE(NULLIF(p.nombre_participantes, ''), ep.nombre_invitado) AS nombre
    FROM evento_participantes ep
    LEFT JOIN participantes p ON p.id = ep.participante_id
    WHERE ep.codigo_qr = ? AND ep.evento_id = ?
");
$stmt->execute([$codigoQr, $eventoId]);
$participante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participante) {
    echo json_encode(['ok' => false, 'mensaje' => 'Este código no pertenece a este evento.']);
    exit;
}

$nombre = (string) ($participante['nombre'] ?: 'Participante');

if ($participante['ingreso_en']) {
    $hora = date('H:i', strtotime((string) $participante['ingreso_en']));
    echo json_encode([
        'ok' => true,
        'ya_ingreso' => true,
        'nombre' => $nombre,
        'mensaje' => 'Ya había ingresado a las ' . $hora . '.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$stmtActualizar = $db->prepare('UPDATE evento_participantes SET ingreso_en = NOW() WHERE id = ? AND evento_id = ? AND ingreso_en IS NULL');
$stmtActualizar->execute([$participante['id'], $eventoId]);

echo json_encode([
    'ok' => true,
    'ya_ingreso' => false,
    'nombre' => $nombre,
    'mensaje' => 'Ingreso registrado correctamente.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
