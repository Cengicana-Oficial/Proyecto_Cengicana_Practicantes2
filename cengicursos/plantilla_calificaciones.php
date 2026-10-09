<?php
/**
 * Plantilla CSV para "Cargar calificaciones" (participantes.php -> carga_calificaciones.php),
 * prellenada con los participantes asignados al curso y sus valores actuales.
 *
 * Columnas: CUI, ASISTENCIA, PRE_EVALUACION, POST_EVALUACION, y NOMBRE / INGENIO solo de
 * referencia (no se leen). En un curso con modulos la nota se carga por modulo
 * (Seguimiento por curso), asi que aqui solo va la asistencia acumulada.
 */
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';

cengi_require_calificador('participantes.php');

$db = conectar();
$cursoId = (int) ($_GET['curso_id'] ?? 0);

$stmt = $db->prepare('SELECT nombre_cursos, codigo_curso, (SELECT COUNT(*) FROM curso_modulos m WHERE m.curso_id = c.id) AS modulos FROM cursos c WHERE c.id = ?');
$stmt->execute([$cursoId]);
$curso = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$curso) {
    http_response_code(404);
    exit('El curso no existe.');
}
$conModulos = (int) $curso['modulos'] > 0;

$sql = 'SELECT p.cui_participantes, p.nombre_participantes, i.nombre_ingenios, cc.asistencia, cc.evaluacion, cc.posevaluacion
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    LEFT JOIN ingenios i ON i.id = p.ingenio_id
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    WHERE a.cursos_id = ? AND a.estado_asignaciones = 1';
$params = [$cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sql .= ' AND p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
$stmt = $db->prepare($sql . ' ORDER BY p.nombre_participantes');
$stmt->execute($params);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$valor = static function ($v) {
    return is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : '';
};

$nombre = 'plantilla_calificaciones_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $curso['codigo_curso'] ?: $curso['nombre_cursos']) ?: 'curso');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . trim($nombre, '_') . '.csv"');
$salida = fopen('php://output', 'w');
fwrite($salida, "\xEF\xBB\xBF");
if ($conModulos) {
    fputcsv($salida, ['CUI', 'ASISTENCIA', 'NOMBRE (referencia)', 'INGENIO (referencia)']);
    foreach ($filas as $f) {
        fputcsv($salida, [$f['cui_participantes'], $valor($f['asistencia']), $f['nombre_participantes'], $f['nombre_ingenios']]);
    }
} else {
    fputcsv($salida, ['CUI', 'ASISTENCIA', 'PRE_EVALUACION', 'POST_EVALUACION', 'NOMBRE (referencia)', 'INGENIO (referencia)']);
    foreach ($filas as $f) {
        fputcsv($salida, [$f['cui_participantes'], $valor($f['asistencia']), $valor($f['evaluacion']), $valor($f['posevaluacion']), $f['nombre_participantes'], $f['nombre_ingenios']]);
    }
}
if (!$filas) {
    fputcsv($salida, $conModulos ? ['1234567890101', '95', 'Ejemplo (borrar esta fila)', ''] : ['1234567890101', '95', '45', '85', 'Ejemplo (borrar esta fila)', '']);
}
fclose($salida);
