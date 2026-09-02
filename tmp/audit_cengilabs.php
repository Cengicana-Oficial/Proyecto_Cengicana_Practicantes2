<?php
require '/var/www/html/laboratorio/conexion.php';

$queries = [
    'tipos' => "SELECT tm.id_tipo, tm.nombre, tm.prefijo, COUNT(DISTINCT s.id_solicitud) AS solicitudes, MAX(s.id_solicitud) AS ultima_solicitud FROM tipo_muestra tm LEFT JOIN solicitud s ON s.id_tipo = tm.id_tipo GROUP BY tm.id_tipo, tm.nombre, tm.prefijo ORDER BY tm.id_tipo",
    'solicitudes' => "SELECT s.id_solicitud, tm.nombre AS tipo, tm.prefijo, l.id_lote, l.codigo_lote, s.numero_muestras, s.fecha_ingreso FROM solicitud s INNER JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo INNER JOIN lote l ON l.id_lote = s.id_lote ORDER BY s.id_solicitud DESC LIMIT 30",
    'estados' => "SELECT ef.id_estado, ef.nombre, COUNT(f.id_formulario) AS formularios FROM estado_formulario ef LEFT JOIN formulario f ON f.id_estado = ef.id_estado GROUP BY ef.id_estado, ef.nombre ORDER BY ef.id_estado",
    'mezclas' => "SELECT l.id_lote, l.codigo_lote, COUNT(DISTINCT s.id_tipo) AS tipos, COUNT(DISTINCT s.id_solicitud) AS solicitudes FROM lote l INNER JOIN solicitud s ON s.id_lote = l.id_lote GROUP BY l.id_lote, l.codigo_lote HAVING tipos > 1 OR solicitudes > 1 ORDER BY l.id_lote DESC",
    'flujo' => "SELECT (SELECT COUNT(*) FROM solicitud) AS solicitudes, (SELECT COUNT(*) FROM muestra) AS muestras, (SELECT COUNT(*) FROM formulario) AS formularios, (SELECT COUNT(*) FROM formulario f LEFT JOIN estado_formulario ef ON ef.id_estado=f.id_estado WHERE LOWER(TRIM(COALESCE(ef.nombre,''))) LIKE '%revis%') AS por_validar",
];

$result = [];
foreach ($queries as $key => $sql) {
    $result[$key] = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
