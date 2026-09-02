<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/eventos_helpers.php';

function cengi_inscripcion_evento_redirigir($token, $resultado, $mensaje = '', $codigo = '')
{
    $parametros = ['token' => $token, 'resultado' => $resultado];
    if ($mensaje !== '') {
        $parametros['mensaje'] = $mensaje;
    }
    if ($codigo !== '') {
        $parametros['codigo'] = $codigo;
    }
    header('Location: inscripcion_evento.php?' . http_build_query($parametros));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inscripcion_evento.php');
    exit;
}

$token = strtolower(trim((string) ($_POST['token'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    cengi_inscripcion_evento_redirigir('', 'error', 'El enlace de inscripción no es válido.');
}

// Campo señuelo: se deja fuera de la vista y debe permanecer vacío.
if (trim((string) ($_POST['sitio_web'] ?? '')) !== '') {
    cengi_inscripcion_evento_redirigir($token, 'ok');
}

$nombre = trim((string) ($_POST['nombre'] ?? ''));
$cui = trim((string) ($_POST['cui'] ?? ''));
$correo = trim((string) ($_POST['correo'] ?? ''));

if ($nombre === '' || $correo === '') {
    cengi_inscripcion_evento_redirigir($token, 'error', 'Completa tu nombre y correo electrónico.');
}
if (strlen($nombre) > 255 || strlen($cui) > 25 || strlen($correo) > 255) {
    cengi_inscripcion_evento_redirigir($token, 'error', 'Uno de los datos supera la longitud permitida.');
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    cengi_inscripcion_evento_redirigir($token, 'error', 'El correo electrónico no es válido.');
}

$db = conectar();
$stmtEvento = $db->prepare("
    SELECT id, nombre
    FROM eventos
    WHERE token_inscripcion = ?
      AND estado NOT IN ('Finalizado', 'Cancelado')
      AND (fecha IS NULL OR fecha >= CURDATE())
    LIMIT 1
");
$stmtEvento->execute([$token]);
$evento = $stmtEvento->fetch(PDO::FETCH_ASSOC);
if (!$evento) {
    cengi_inscripcion_evento_redirigir($token, 'error', 'Este evento ya no está disponible para inscripción.');
}

$condicionCui = $cui !== ''
    ? " OR COALESCE(NULLIF(ep.cui_invitado, ''), NULLIF(p.cui_participantes, '')) = ?"
    : '';
$stmtDuplicado = $db->prepare("
    SELECT ep.codigo_qr
    FROM evento_participantes ep
    LEFT JOIN participantes p ON p.id = ep.participante_id
    WHERE ep.evento_id = ?
      AND (
        LOWER(COALESCE(NULLIF(ep.correo_invitado, ''), NULLIF(p.correo_participantes, ''))) = LOWER(?)
        {$condicionCui}
      )
    LIMIT 1
");
$parametrosDuplicado = [(int) $evento['id'], $correo];
if ($cui !== '') {
    $parametrosDuplicado[] = $cui;
}
$stmtDuplicado->execute($parametrosDuplicado);
$codigoExistente = $stmtDuplicado->fetchColumn();
if ($codigoExistente) {
    cengi_inscripcion_evento_redirigir($token, 'existente', '', (string) $codigoExistente);
}

$participanteId = null;
if ($cui !== '') {
    $stmtParticipante = $db->prepare('SELECT id FROM participantes WHERE cui_participantes = ? LIMIT 1');
    $stmtParticipante->execute([$cui]);
    $participanteId = $stmtParticipante->fetchColumn() ?: null;
}
if ($participanteId === null) {
    $stmtParticipante = $db->prepare("SELECT id FROM participantes WHERE correo_participantes <> '' AND LOWER(correo_participantes) = LOWER(?) LIMIT 1");
    $stmtParticipante->execute([$correo]);
    $participanteId = $stmtParticipante->fetchColumn() ?: null;
}

try {
    $codigo = cengi_evento_generar_codigo_qr($db);
    $stmt = $db->prepare("
        INSERT INTO evento_participantes
          (evento_id, participante_id, nombre_invitado, cui_invitado, correo_invitado, codigo_qr)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([(int) $evento['id'], $participanteId, $nombre, $cui, $correo, $codigo]);
} catch (Throwable $e) {
    error_log('Error en inscripción pública al evento ' . $evento['id'] . ': ' . $e->getMessage());
    cengi_inscripcion_evento_redirigir($token, 'error', 'No fue posible completar la inscripción. Intenta nuevamente.');
}

cengi_inscripcion_evento_redirigir($token, 'ok', '', $codigo);

