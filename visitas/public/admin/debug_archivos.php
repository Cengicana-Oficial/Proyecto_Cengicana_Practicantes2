<?php
// DEBUG: Listar todos los archivos en la carpeta de cartas

require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!can_access('gestionar_solicitudes')) { http_response_code(403); exit('No autorizado'); }

if (!isset($_SESSION['usuario'])) {
    die("No autorizado");
}

$carpeta = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "cartas";

echo "<h2>DEBUG: Archivos en carpeta de cartas</h2>";
echo "<p>Carpeta: " . htmlspecialchars($carpeta) . "</p>";
echo "<p>Existe: " . (is_dir($carpeta) ? "SÍ" : "NO") . "</p>";

if (!is_dir($carpeta)) {
    echo "<p style='color: red;'><strong>ERROR: La carpeta no existe</strong></p>";
    exit;
}

$archivos = @scandir($carpeta);

if ($archivos === false) {
    echo "<p style='color: red;'><strong>ERROR: No se pudo leer la carpeta</strong></p>";
    exit;
}

echo "<table border='1' cellpadding='10'>";
echo "<thead><tr><th>Nombre del archivo</th><th>Tamaño</th><th>Fecha Modificación</th><th>Es PDF</th></tr></thead>";
echo "<tbody>";

$count = 0;
foreach ($archivos as $file) {
    if ($file === '.' || $file === '..') continue;
    
    $ruta_completa = $carpeta . DIRECTORY_SEPARATOR . $file;
    
    if (!is_file($ruta_completa)) continue;
    
    $count++;
    $es_pdf = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf' ? "✓ SÍ" : "✗ NO";
    $tamaño = filesize($ruta_completa);
    $fecha = date("Y-m-d H:i:s", filemtime($ruta_completa));
    
    echo "<tr>";
    echo "<td><code>" . htmlspecialchars($file) . "</code></td>";
    echo "<td>" . $tamaño . " bytes</td>";
    echo "<td>" . $fecha . "</td>";
    echo "<td>" . $es_pdf . "</td>";
    echo "</tr>";
}

if ($count === 0) {
    echo "<tr><td colspan='4' style='text-align: center; color: red;'><strong>No hay archivos</strong></td></tr>";
}

echo "</tbody>";
echo "</table>";

echo "<p><strong>Total de archivos: " . $count . "</strong></p>";

echo "<p><a href='dashboard.php' style='padding: 10px 20px; background: #0066cc; color: white; text-decoration: none; border-radius: 5px;'>Volver al Dashboard</a></p>";
?>
