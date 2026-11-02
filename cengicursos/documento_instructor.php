<?php
/**
 * Muestra un documento de un instructor (CV, carta de comprobacion, titulo...) solo a
 * quien puede gestionar instructores. ?doc=<instructor_documentos.id> o ?cv=<instructor id>
 * para el CV vigente de instructores.cv_path (incluye CVs subidos desde instructores.php).
 * ?descargar=1 fuerza la descarga en vez de abrirlo en el navegador.
 */
require_once __DIR__ . '/revisar_permisos.php';

// El CV vigente también lo ve quien consulta instructores sin ser de un ingenio (como en instructores.php).
if (!(!empty($_GET['cv']) && cengi_puede_ver_instructores() && !cengi_es_administrador_ingenio())) {
    cengi_require_gestionar_instructores();
}

$db = conectar();
$ruta = '';
$nombre = 'documento';
if (!empty($_GET['doc'])) {
    $stmt = $db->prepare('SELECT ruta, nombre_original FROM instructor_documentos WHERE id = ?');
    $stmt->execute([(int) $_GET['doc']]);
    if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
        [$ruta, $nombre] = [$fila['ruta'], $fila['nombre_original']];
    }
} elseif (!empty($_GET['cv'])) {
    $stmt = $db->prepare('SELECT cv_path, nombre FROM instructores WHERE id = ?');
    $stmt->execute([(int) $_GET['cv']]);
    if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ruta = (string) $fila['cv_path'];
        $nombre = 'CV ' . $fila['nombre'] . '.' . pathinfo($ruta, PATHINFO_EXTENSION);
    }
}

// Las rutas se guardan como "/uploads/..." o, en registros antiguos, "../uploads/...":
// se resuelven siempre dentro de la carpeta uploads/ del sitio.
$base = realpath(__DIR__ . '/../uploads');
$url = cengi_normalizar_url_archivo($ruta);
$archivo = $url !== '' ? realpath(__DIR__ . '/..' . $url) : false;
if (!$base || !$archivo || strpos($archivo, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($archivo)) {
    http_response_code(404);
    echo 'El documento no existe o ya no está disponible.';
    exit;
}

$tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
$enLinea = empty($_GET['descargar']) && in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true);
$nombreSeguro = preg_replace('/[^\w .()-]/u', '_', $nombre);

header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($archivo));
header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $nombreSeguro) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($archivo);
