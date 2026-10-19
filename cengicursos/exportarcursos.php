<?php
/**
 * "Excel por curso": participantes asignados a cada curso (todos, o uno con ?curso_id=),
 * como .xlsx real (PhpSpreadsheet vía cengi_cob_excel). Antes usaba PHPExcel, que en
 * PHP 8 escribe avisos "Deprecated" dentro del archivo y lo dejaba dañado.
 */
require_once __DIR__ . '/revisar_permisos.php';
cengi_require_ver_participantes();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/cobranza_helpers.php';
$db = conectar();
$cursoId = (int) ($_GET['curso_id'] ?? 0);

$condiciones = [];
$params = [];
if ($cursoId > 0) {
    $condiciones[] = 'a.cursos_id = ?';
    $params[] = $cursoId;
}
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$stmt = $db->prepare("SELECT
        c.nombre_cursos,
        c.dias,
        c.horario,
        p.nombre_participantes,
        p.correo_participantes,
        p.grado_academico_participantes,
        p.telefono_participantes,
        i.nombre_ingenios
    FROM asignaciones a
    INNER JOIN cursos c ON a.cursos_id = c.id
    INNER JOIN participantes p ON a.participantes_id = p.id
    INNER JOIN ingenios i ON p.ingenio_id = i.id
    $where
    ORDER BY i.id, c.id");
$stmt->execute($params);

$filas = array_map(static function ($r) {
    return [$r['nombre_cursos'], $r['dias'], $r['horario'], $r['nombre_participantes'], $r['correo_participantes'],
        $r['grado_academico_participantes'], $r['telefono_participantes'], $r['nombre_ingenios']];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$titulo = 'Participantes por curso';
if ($cursoId > 0 && $filas) {
    $titulo .= ' - ' . $filas[0][0];
}
cengi_cob_excel('Participantes por curso', ($cursoId > 0 && $filas ? $filas[0][0] . ' · ' : '') . count($filas) . ' asignación(es)', [
    ['Curso', 34, 'texto'], ['Días', 18, 'texto'], ['Horario', 16, 'texto'], ['Participante', 32, 'texto'], ['Correo electrónico', 32, 'texto'],
    ['Grado académico', 20, 'texto'], ['Teléfono', 14, 'texto'], ['Ingenio', 24, 'texto'],
], $filas, $titulo);
