<?php
// Foto de perfil del participante. Sin parámetro: la del usuario de la sesión.
// Con ?p=ID: solo para quien puede consultar participantes (directorio, seguimiento).
require_once __DIR__ . '/estudiante_perfil_helpers.php';
$db = conectar();
$participanteId = (int) ($_GET['p'] ?? 0);
if ($participanteId > 0) {
    if (!cengi_puede_ver_participantes()) {
        http_response_code(403);
        exit;
    }
    $ruta = null;
    if (cengi_est_foto_lista($db)) {
        $stmt = $db->prepare('SELECT foto_path FROM participantes WHERE id = ?');
        $stmt->execute([$participanteId]);
        $ruta = $stmt->fetchColumn();
    }
} else {
    $yo = cengi_est_participante($db, cengi_usuario_actual_id());
    $ruta = $yo['foto_path'] ?? null;
}
$archivo = $ruta ? cengi_est_foto_archivo($ruta) : null;
if (!$archivo) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($archivo));
readfile($archivo);
