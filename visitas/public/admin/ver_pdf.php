<?php
// Resolver siempre desde la ubicación del endpoint. Esto evita que Apache
// dependa del directorio de trabajo actual y devuelva 500 al abrir un PDF.
require_once __DIR__ . "/../../config/auth.php";
require_once __DIR__ . "/../../config/VisitStorage.php";
require_login();

// Obtener el nombre del archivo del parámetro GET
$archivo = isset($_GET['file']) ? trim($_GET['file']) : null;

if (!$archivo) {
    header("HTTP/1.0 400 Bad Request");
    die("Archivo no especificado");
}

$tipo = isset($_GET['tipo']) && $_GET['tipo'] === 'listado' ? 'listado' : 'cartas';

if (Conexion::driver() === 'pgsql') {
    $conn = Conexion::conectar();
    if ($tipo === 'listado') {
        $statement = $conn->prepare('select ruta_carta_pdf from solicitud_museo where nombre_archivo_pdf = ? limit 1');
    } else {
        $statement = $conn->prepare('select ruta_carta_pdf from solicitudes where nombre_archivo_pdf = ? limit 1');
    }
    $statement->execute([basename($archivo)]);
    $objectPath = (string) ($statement->fetchColumn() ?: '');
    // Algunas solicitudes antiguas guardaron el documento en la tabla
    // opuesta. Si no se encuentra en su tabla habitual, intenta la otra para
    // que las cartas y listados existentes sigan siendo consultables.
    if ($objectPath === '') {
        $fallback = $tipo === 'listado'
            ? $conn->prepare('select ruta_carta_pdf from solicitudes where nombre_archivo_pdf = ? limit 1')
            : $conn->prepare('select ruta_carta_pdf from solicitud_museo where nombre_archivo_pdf = ? limit 1');
        $fallback->execute([basename($archivo)]);
        $objectPath = (string) ($fallback->fetchColumn() ?: '');
    }
    if ($objectPath === '') {
        http_response_code(404);
        die('Documento no encontrado.');
    }
    // Nunca mezclar espacios o avisos PHP con los bytes del PDF.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    VisitStorage::streamObject($objectPath, basename($archivo));
}

$carpeta_esperada = realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . $tipo);

// Debug log
error_log("=== VER ARCHIVO DEBUG ===");
error_log("Tipo de archivo: " . $tipo);
error_log("Archivo solicitado: " . $archivo);
error_log("Carpeta esperada: " . $carpeta_esperada);
error_log("Carpeta existe: " . (is_dir($carpeta_esperada) ? "SI" : "NO"));

// Extensiones permitidas
$extensiones_permitidas = ['pdf', 'jpg', 'jpeg', 'png'];

$ruta_archivo = null;

// Verificar que la carpeta existe
if (!$carpeta_esperada || !is_dir($carpeta_esperada)) {
    error_log("ERROR: Carpeta de descargas no existe");
    header("HTTP/1.0 404 Not Found");
    die("Carpeta de archivos no encontrada");
}

// Opción 1: Búsqueda directa con el nombre exacto
$ruta_intento = $carpeta_esperada . DIRECTORY_SEPARATOR . $archivo;
if (file_exists($ruta_intento)) {
    $ruta_archivo = $ruta_intento;
    error_log("Archivo encontrado (búsqueda directa): " . $ruta_archivo);
}

// Opción 2: Si no existe, buscar con coincidencia flexible en la carpeta
if (!$ruta_archivo) {
    error_log("Buscando con coincidencia flexible...");
    
    // Obtener todas las partes del nombre del archivo buscado
    $nombre_buscado_original = basename($archivo);
    $nombre_buscado_sin_ext = pathinfo($nombre_buscado_original, PATHINFO_FILENAME);
    $ext_buscada = strtolower(pathinfo($nombre_buscado_original, PATHINFO_EXTENSION));
    
    // Normalizar el nombre buscado para comparación (solo caracteres alfanuméricos)
    $nombre_buscado_norm = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($nombre_buscado_sin_ext));
    
    error_log("Nombre buscado normalizado: " . $nombre_buscado_norm);
    error_log("Extensión buscada: " . $ext_buscada);
    
    // Listar archivos en la carpeta
    $archivos = @scandir($carpeta_esperada);
    
    if ($archivos === false) {
        error_log("ERROR: No se pudo leer la carpeta");
    } else {
        error_log("Archivos en carpeta: " . count($archivos));
        
        foreach ($archivos as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $ruta_check = $carpeta_esperada . DIRECTORY_SEPARATOR . $file;
            
            if (!is_file($ruta_check)) continue;
            
            $ext_archivo = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            // Solo considerar archivos permitidos
            if (!in_array($ext_archivo, $extensiones_permitidas)) {
                error_log("Archivo ignorado (extensión no permitida): " . $file);
                continue;
            }
            
            error_log("Verificando: " . $file);
            
            // Extraer la parte del nombre sin la extensión
            $nombre_archivo_file = pathinfo($file, PATHINFO_FILENAME);
            
            // Normalizar el nombre del archivo en la carpeta
            $nombre_archivo_norm = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($nombre_archivo_file));
            
            // Si el nombre normalizado coincide, es nuestro archivo
            if ($nombre_archivo_norm === $nombre_buscado_norm) {
                $ruta_archivo = $ruta_check;
                error_log("¡Archivo encontrado!: " . $file . " (normalizado: " . $nombre_archivo_norm . ")");
                break;
            }
            
            // También intentar coincidencia parcial: si contiene el timestamp y coincide el resto
            if (strpos($file, '_') !== false) {
                $partes = explode('_', $nombre_archivo_file, 2);
                if (count($partes) === 2 && is_numeric($partes[0])) {
                    $nombre_sin_timestamp = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($partes[1]));
                    if ($nombre_sin_timestamp === $nombre_buscado_norm) {
                        $ruta_archivo = $ruta_check;
                        error_log("Archivo encontrado (por timestamp + nombre): " . $file);
                        break;
                    }
                }
            }
        }
    }
}

// Verificar que el archivo fue encontrado
if (!$ruta_archivo || !file_exists($ruta_archivo)) {
    error_log("ERROR: Archivo NO encontrado para: " . $archivo);
    
    // Listar archivos disponibles para debugging
    if (is_dir($carpeta_esperada)) {
        $archivos_disponibles = @scandir($carpeta_esperada);
        if ($archivos_disponibles) {
            error_log("Archivos disponibles:");
            foreach ($archivos_disponibles as $f) {
                if ($f !== '.' && $f !== '..' && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $extensiones_permitidas)) {
                    error_log("  - " . $f);
                }
            }
        }
    }
    
    header("HTTP/1.0 404 Not Found");
    die("Archivo no encontrado: " . htmlspecialchars($archivo));
}

// Verificar que la ruta está dentro de la carpeta permitida (prevenir path traversal)
$ruta_real = realpath($ruta_archivo);
if (!$ruta_real || strpos($ruta_real, $carpeta_esperada) !== 0) {
    error_log("ERROR: Intento de acceso fuera de la carpeta permitida");
    header("HTTP/1.0 403 Forbidden");
    die("Acceso denegado a este archivo");
}

// Verificar que es un archivo con extensión permitida
$ext_real = strtolower(pathinfo($ruta_real, PATHINFO_EXTENSION));
if (!in_array($ext_real, $extensiones_permitidas)) {
    error_log("ERROR: Archivo no tiene extensión permitida: " . $ext_real);
    header("HTTP/1.0 403 Forbidden");
    die("Tipo de archivo no permitido. Solo se permiten: PDF, JPG, PNG");
}

// Servir el archivo con los headers correctos según el tipo
error_log("Sirviendo archivo: " . $ruta_real);

// Determinar el Content-Type según la extensión
$content_types = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png'
];
$content_type = $content_types[$ext_real] ?? 'application/octet-stream';

// Para imágenes, usar inline; para PDF, usar attachment o inline según preferencia
$disposition = in_array($ext_real, ['jpg', 'jpeg', 'png']) ? 'inline' : 'inline';

header('Content-Type: ' . $content_type);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($ruta_real) . '"');
header('Content-Length: ' . filesize($ruta_real));
header('Cache-Control: public, must-revalidate, max-age=0');
header('Pragma: public');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

readfile($ruta_real);
exit;
