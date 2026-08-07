<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

cengi_require_calificador('ver_cursos.php');

$db = conectar();

$cursoId = (int) ($_GET['curso_id'] ?? 0);
$moduloId = (int) ($_GET['modulo_id'] ?? 0);

if ($cursoId <= 0 || $moduloId <= 0) {
    http_response_code(400);
    exit('Curso o modulo invalido.');
}

// El modulo debe pertenecer al curso indicado.
$stmtModulo = $db->prepare('SELECT id, nombre FROM curso_modulos WHERE id = ? AND curso_id = ?');
$stmtModulo->execute([$moduloId, $cursoId]);
$modulo = $stmtModulo->fetch(PDO::FETCH_ASSOC);
if (!$modulo) {
    http_response_code(404);
    exit('El modulo no existe o no pertenece a este curso.');
}

// Mismo scope por ingenio que el resto de la vista de participantes: si el
// usuario no ve todo, el curso debe tener al menos un participante de su
// ingenio para que se le permita exportar.
$sqlCurso = 'SELECT c.id FROM cursos c WHERE c.id = ?';
$paramsCurso = [$cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sqlCurso .= ' AND EXISTS (
        SELECT 1 FROM asignaciones ax
        INNER JOIN participantes px ON px.id = ax.participantes_id
        WHERE ax.cursos_id = c.id AND px.ingenio_id = ?
    )';
    $paramsCurso[] = cengi_ingenio_id_actual();
}
$stmtCurso = $db->prepare($sqlCurso);
$stmtCurso->execute($paramsCurso);
if (!$stmtCurso->fetchColumn()) {
    http_response_code(404);
    exit('El curso no esta disponible para este usuario.');
}

$sql = "
    SELECT
        p.cui_participantes,
        p.nombre_participantes,
        ccm.asistencia,
        ccm.evaluacion,
        ccm.posevaluacion
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    LEFT JOIN control_curso_modulos ccm ON ccm.asignacion_id = a.id AND ccm.curso_modulo_id = ?
    WHERE a.cursos_id = ?
    AND a.estado_asignaciones = 1
";
$params = [$moduloId, $cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sql .= ' AND p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
$sql .= ' ORDER BY p.nombre_participantes';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

function cengi_notas_modulo_valor($valor)
{
    return is_numeric($valor) ? $valor : '';
}

// Mismo orden de columnas por posicion que carga_calificaciones_modulo.php ya
// sabe leer (CUI, ASISTENCIA, PRE_EVALUACION, POST_EVALUACION); NOMBRE se
// agrega solo como referencia humana en el indice 4, el parser por indice no
// la lee.
$encabezados = ['CUI', 'ASISTENCIA', 'PRE_EVALUACION', 'POST_EVALUACION', 'NOMBRE'];

$hoja = new Spreadsheet();
$sheet = $hoja->getActiveSheet();
$sheet->setTitle('Notas modulo');

$sheet->fromArray($encabezados, null, 'A1');

$filaExcel = 2;
foreach ($filas as $fila) {
    $sheet->fromArray([
        (string) $fila['cui_participantes'],
        cengi_notas_modulo_valor($fila['asistencia']),
        cengi_notas_modulo_valor($fila['evaluacion']),
        cengi_notas_modulo_valor($fila['posevaluacion']),
        (string) $fila['nombre_participantes'],
    ], null, 'A' . $filaExcel);
    $filaExcel++;
}

$ultimaFila = max($filaExcel - 1, 1);

// Encabezado en negrita con relleno de color, columnas CUI/NOMBRE resaltadas
// en gris para distinguirlas visualmente de las columnas editables.
$sheet->getStyle('A1:E1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1B5E20'],
    ],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

if ($ultimaFila > 1) {
    $sheet->getStyle('A1:E' . $ultimaFila)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
    ]);
    $sheet->getStyle('A2:A' . $ultimaFila)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
    ]);
    $sheet->getStyle('E2:E' . $ultimaFila)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
    ]);
}

foreach (['A' => 20, 'B' => 14, 'C' => 16, 'D' => 16, 'E' => 32] as $columna => $ancho) {
    $sheet->getColumnDimension($columna)->setWidth($ancho);
}

// Congela la fila de encabezado para que se mantenga visible al desplazarse.
$sheet->freezePane('A2');

$nombreArchivo = 'notas_modulo_' . $cursoId . '_' . $moduloId . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$writer = new Xlsx($hoja);
$writer->save('php://output');
exit;
