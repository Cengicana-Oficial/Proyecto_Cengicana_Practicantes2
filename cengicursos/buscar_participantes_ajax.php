<?php
// Endpoint AJAX para el combo "participante existente" del modal en
// ver_participante_curso.php. Devuelve JSON, respeta el mismo scope por
// ingenio que directorio_participantes.php / agregar_participantes1.php.
// Con q vacio devuelve un listado (limitado) para poder usarse como dropdown.

require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_admin('ver_cursos.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$db = conectar();

$busqueda = trim((string) ($_GET['q'] ?? ''));

$condiciones = ['p.estado_participantes = 1'];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ?)';
    $termino = '%' . $busqueda . '%';
    $params[] = $termino;
    $params[] = $termino;
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}

$sql = "
    SELECT p.id, p.nombre_participantes, p.cui_participantes, i.nombre_ingenios
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    WHERE " . implode(' AND ', $condiciones) . "
    ORDER BY p.nombre_participantes
    LIMIT 20
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$salida = array_map(static function ($fila) {
    return [
        'id' => (int) $fila['id'],
        'nombre' => $fila['nombre_participantes'],
        'cui' => $fila['cui_participantes'],
        'ingenio' => $fila['nombre_ingenios'],
    ];
}, $resultados);

echo json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
