<?php
require_once __DIR__ . '/conexion.php';

$db = conectar();

function evaluacion_error($token, $mensaje)
{
    header('Location: evaluacion.php?token=' . rawurlencode($token) . '&resultado=error&mensaje=' . rawurlencode($mensaje));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: evaluacion.php');
    exit;
}

$token = trim((string) ($_POST['token'] ?? ''));

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    require __DIR__ . '/404.php';
    exit;
}

$stmt = $db->prepare('SELECT id FROM enlaces_evaluacion_instructor WHERE token = ?');
$stmt->execute([$token]);
$enlace = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$enlace) {
    require __DIR__ . '/404.php';
    exit;
}

$calificacionTexto = trim((string) ($_POST['calificacion'] ?? ''));
$comentario = trim((string) ($_POST['comentario'] ?? ''));
$areasMejora = trim((string) ($_POST['areas_mejora'] ?? ''));

if ($calificacionTexto === '' || !ctype_digit($calificacionTexto)) {
    evaluacion_error($token, 'Selecciona una calificación de 1 a 5 estrellas.');
}

$calificacion = (int) $calificacionTexto;
if ($calificacion < 1 || $calificacion > 5) {
    evaluacion_error($token, 'La calificación debe estar entre 1 y 5 estrellas.');
}

if (mb_strlen($comentario) > 2000) {
    $comentario = mb_substr($comentario, 0, 2000);
}

if (mb_strlen($areasMejora) > 2000) {
    $areasMejora = mb_substr($areasMejora, 0, 2000);
}

try {
    $stmtInsertar = $db->prepare('
        INSERT INTO evaluaciones_instructor (enlace_id, calificacion, comentario, areas_mejora)
        VALUES (?, ?, ?, ?)
    ');
    $stmtInsertar->execute([
        $enlace['id'],
        $calificacion,
        $comentario !== '' ? $comentario : null,
        $areasMejora !== '' ? $areasMejora : null,
    ]);
} catch (PDOException $e) {
    error_log('Error al guardar evaluacion de instructor: ' . $e->getMessage());
    evaluacion_error($token, 'No fue posible enviar tu evaluación. Intenta más tarde.');
}

header('Location: evaluacion.php?token=' . rawurlencode($token) . '&resultado=ok');
exit;
