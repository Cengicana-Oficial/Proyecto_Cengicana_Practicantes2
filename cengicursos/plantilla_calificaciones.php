<?php
/**
 * Plantilla Excel para cargar asistencia o calificaciones,
 * prellenada con los participantes asignados al curso y sus valores actuales.
 *
 * Columnas: CUI, ASISTENCIA, PRE_EVALUACION, POST_EVALUACION, y NOMBRE / INGENIO solo de
 * referencia (no se leen). En un curso con modulos la nota se carga por modulo
 * (Seguimiento por curso), asi que aqui solo va la asistencia acumulada.
 */
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/vendor/autoload.php';

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

require_once __DIR__ . '/curso_form_helpers.php';
// Solo asistencia: cursos con módulos (la nota va por módulo), sin evaluaciones o de menos de 10 h.
$stmtMods = $db->prepare('SELECT * FROM curso_modulos WHERE curso_id = ? ORDER BY orden');
$stmtMods->execute([$cursoId]);
$visibles = cengi_curso_pre_post_visibles($stmtMods->fetchAll(PDO::FETCH_ASSOC), 0, cengi_curso_solo_asistencia($db, $cursoId));
$soloAsistencia = $conModulos || (!$visibles['pre'] && !$visibles['post']);

$sql = 'SELECT p.cui_participantes, p.nombre_participantes, i.nombre_ingenios, cc.asistencia, cc.sesiones_asistidas, cc.evaluacion, cc.posevaluacion
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
$salida = fopen('php://temp', 'w+');
if ($soloAsistencia) {
    fputcsv($salida, ['CUI', 'ASISTENCIA', 'SESIONES_ASISTIDAS', 'NOMBRE (referencia)', 'INGENIO (referencia)']);
    foreach ($filas as $f) {
        fputcsv($salida, [$f['cui_participantes'], $valor($f['asistencia']), $valor($f['sesiones_asistidas']), $f['nombre_participantes'], $f['nombre_ingenios']]);
    }
} else {
    fputcsv($salida, ['CUI', 'ASISTENCIA', 'PRE_EVALUACION', 'POST_EVALUACION', 'NOMBRE (referencia)', 'INGENIO (referencia)']);
    foreach ($filas as $f) {
        fputcsv($salida, [$f['cui_participantes'], $valor($f['asistencia']), $valor($f['evaluacion']), $valor($f['posevaluacion']), $f['nombre_participantes'], $f['nombre_ingenios']]);
    }
}
if (!$filas) {
    fputcsv($salida, $soloAsistencia ? ['1234567890101', '95', '4', 'Ejemplo (borrar esta fila)', ''] : ['1234567890101', '95', '45', '85', 'Ejemplo (borrar esta fila)', '']);
}
rewind($salida);
$libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $libro->getActiveSheet();
$hoja->setTitle('Asistencia');
$filaExcel = 0;
while (($datos = fgetcsv($salida)) !== false) {
    $filaExcel++;
    foreach ($datos as $indice => $dato) {
        $celda = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($indice + 1) . $filaExcel;
        // El documento se conserva como texto, incluidos ceros iniciales y códigos ND.
        $tipo = $filaExcel > 1 && $indice > 0 && $indice < ($soloAsistencia ? 3 : 4) && is_numeric($dato)
            ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
            : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
        $hoja->setCellValueExplicit($celda, $dato, $tipo);
    }
}
fclose($salida);
$ultimaColumna = $soloAsistencia ? 'E' : 'F';
$hoja->getStyle('A1:' . $ultimaColumna . '1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '73BC25']],
]);
$hoja->getRowDimension(1)->setRowHeight(26);
$hoja->freezePane('B2');
$hoja->setAutoFilter('A1:' . $ultimaColumna . $filaExcel);
$hoja->getColumnDimension('A')->setWidth(23);
foreach (range('B', $ultimaColumna) as $columna) $hoja->getColumnDimension($columna)->setWidth(25);
$hoja->getColumnDimension($soloAsistencia ? 'D' : 'E')->setWidth(45);
$hoja->getStyle('A2:A' . max(2, $filaExcel))->getNumberFormat()->setFormatCode('@');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . trim($nombre, '_') . '.xlsx"');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
$libro->disconnectWorksheets();
