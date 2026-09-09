<?php
// Buffer desde el inicio: evita que warnings/notices previos a los header()
// hagan que el navegador muestre bytes de Excel/PDF como texto.
ob_start();

require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/curso_form_helpers.php';
require_once __DIR__ . '/classes/export_helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

cengi_require_calificador('ver_cursos.php');

$db = conectar();

$cursoId = (int) ($_GET['curso_id'] ?? 0);
$moduloId = (int) ($_GET['modulo_id'] ?? 0);
$formato = strtolower(trim((string) ($_GET['format'] ?? 'excel')));
if ($formato === 'xlsx') {
    $formato = 'excel';
}

if ($cursoId <= 0 || $moduloId <= 0) {
    http_response_code(400);
    exit('Curso o modulo invalido.');
}
if (!in_array($formato, ['excel', 'pdf'], true)) {
    http_response_code(400);
    exit('Formato de exportacion no valido.');
}

$stmtModulosCurso = $db->prepare('
    SELECT cm.*, c.nombre_cursos, c.codigo_curso, c.inicio, c.fin
    FROM curso_modulos cm
    INNER JOIN cursos c ON c.id = cm.curso_id
    WHERE cm.curso_id = ?
    ORDER BY cm.orden
');
$stmtModulosCurso->execute([$cursoId]);
$modulosCurso = $stmtModulosCurso->fetchAll(PDO::FETCH_ASSOC);

$modulo = null;
foreach ($modulosCurso as $m) {
    if ((int) $m['id'] === $moduloId) {
        $modulo = $m;
        break;
    }
}
if (!$modulo) {
    http_response_code(404);
    exit('El modulo no existe o no pertenece a este curso.');
}

// Mismo scope por ingenio que la vista de participantes.
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
        p.correo_participantes,
        i.nombre_ingenios,
        ccm.asistencia,
        ccm.evaluacion,
        ccm.posevaluacion
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    INNER JOIN ingenios i ON i.id = p.ingenio_id
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

$prePostVisibles = cengi_curso_pre_post_visibles($modulosCurso, $moduloId);
$nombreCurso = (string) ($modulo['nombre_cursos'] ?? 'Curso sin nombre');
$nombreModulo = (string) ($modulo['nombre'] ?? 'Modulo sin nombre');
$codigoCurso = trim((string) ($modulo['codigo_curso'] ?? ''));
if ($codigoCurso === '') {
    $codigoCurso = 'CEN-' . str_pad((string) $cursoId, 3, '0', STR_PAD_LEFT);
}

function cengi_notas_modulo_valor($valor)
{
    return is_numeric($valor) ? rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.') : '';
}

function cengi_notas_modulo_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_notas_modulo_slug($valor)
{
    $valor = trim((string) $valor);
    if (function_exists('iconv')) {
        $convertido = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if ($convertido !== false) {
            $valor = $convertido;
        }
    }
    $valor = strtolower($valor);
    $valor = preg_replace('/[^a-z0-9._-]+/', '_', $valor);
    $valor = trim((string) $valor, '._-');
    return $valor !== '' ? $valor : 'sin_nombre';
}

function cengi_notas_modulo_enviar_xlsx($xlsxBytes, $nombreArchivo)
{
    $xlsxBytes = (string) $xlsxBytes;
    if (strncmp($xlsxBytes, "PK\x03\x04", 4) !== 0) {
        throw new RuntimeException('No se pudo generar un archivo de Excel valido.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (function_exists('header_remove')) {
        header_remove('Content-Type');
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header_remove('Content-Encoding');
    }
    @ini_set('zlib.output_compression', '0');

    $nombreArchivo = cengi_export_nombre_archivo($nombreArchivo, 'listado_modulo.xlsx');
    $nombreAscii = function_exists('iconv')
        ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombreArchivo)
        : $nombreArchivo;
    $nombreAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $nombreAscii);
    if ($nombreAscii === '') {
        $nombreAscii = 'listado_modulo.xlsx';
    }

    http_response_code(200);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
    header("Content-Disposition: attachment; filename=\"{$nombreAscii}\"; filename*=UTF-8''" . rawurlencode($nombreArchivo), true);
    header('Content-Transfer-Encoding: binary', true);
    header('Content-Length: ' . strlen($xlsxBytes), true);
    header('Cache-Control: no-store, no-cache, must-revalidate', true);
    header('Pragma: no-cache', true);
    header('X-Content-Type-Options: nosniff', true);
    echo $xlsxBytes;
    exit;
}

$encabezados = ['CUI', 'ASISTENCIA', 'PRE_EVALUACION', 'POST_EVALUACION', 'NOMBRE', 'INGENIO', 'CORREO', 'CURSO', 'MODULO'];
$filasExportacion = [];
foreach ($filas as $fila) {
    $filasExportacion[] = [
        (string) $fila['cui_participantes'],
        cengi_notas_modulo_valor($fila['asistencia']),
        $prePostVisibles['pre'] ? cengi_notas_modulo_valor($fila['evaluacion']) : '',
        $prePostVisibles['post'] ? cengi_notas_modulo_valor($fila['posevaluacion']) : '',
        (string) $fila['nombre_participantes'],
        (string) $fila['nombre_ingenios'],
        (string) $fila['correo_participantes'],
        $nombreCurso,
        $nombreModulo,
    ];
}

$nombreArchivoBase = 'listado_' . cengi_notas_modulo_slug($codigoCurso) . '_' . cengi_notas_modulo_slug($nombreCurso)
    . '_modulo_' . cengi_notas_modulo_slug($nombreModulo);

if ($formato === 'excel') {
    $hoja = new Spreadsheet();
    $sheet = $hoja->getActiveSheet();
    $sheet->setTitle(mb_substr('Modulo ' . $nombreModulo, 0, 31, 'UTF-8'));
    $sheet->fromArray($encabezados, null, 'A1');

    $filaExcel = 2;
    foreach ($filasExportacion as $datosFila) {
        foreach ($datosFila as $indice => $valor) {
            $celda = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($indice + 1) . $filaExcel;
            $sheet->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
        }
        $filaExcel++;
    }

    $ultimaFila = max($filaExcel - 1, 1);
    $sheet->getStyle('A1:I1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1B5E20'],
        ],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    if ($ultimaFila > 1) {
        $sheet->getStyle('A1:I' . $ultimaFila)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ]);
        $sheet->getStyle('A2:A' . $ultimaFila)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
        ]);
        $sheet->getStyle('E2:I' . $ultimaFila)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
        ]);
    }

    foreach (['A' => 20, 'B' => 14, 'C' => 16, 'D' => 16, 'E' => 34, 'F' => 24, 'G' => 30, 'H' => 34, 'I' => 28] as $columna => $ancho) {
        $sheet->getColumnDimension($columna)->setWidth($ancho);
    }
    $sheet->freezePane('A2');
    $sheet->getAutoFilter()->setRange('A1:I' . $ultimaFila);

    $writer = new Xlsx($hoja);
    ob_start();
    $writer->save('php://output');
    $xlsxBytes = ob_get_clean();

    cengi_notas_modulo_enviar_xlsx($xlsxBytes, $nombreArchivoBase . '.xlsx');
}

$filasTablaPdf = '';
foreach ($filasExportacion as $datosFila) {
    $filasTablaPdf .= '<tr>'
        . '<td>' . cengi_notas_modulo_html($datosFila[4]) . '</td>'
        . '<td>' . cengi_notas_modulo_html($datosFila[0]) . '</td>'
        . '<td>' . cengi_notas_modulo_html($datosFila[5]) . '</td>'
        . '<td class="num">' . cengi_notas_modulo_html($datosFila[1]) . '</td>'
        . '<td class="num">' . cengi_notas_modulo_html($datosFila[2]) . '</td>'
        . '<td class="num">' . cengi_notas_modulo_html($datosFila[3]) . '</td>'
        . '</tr>';
}
if ($filasTablaPdf === '') {
    $filasTablaPdf = '<tr><td colspan="6" class="empty">No hay participantes activos para este modulo.</td></tr>';
}

$html = '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 30px 34px; }
    body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #1E2A1A; font-size: 10px; }
    h1 { margin: 0; font-size: 18px; color: #1B5E20; }
    p { margin: 3px 0; }
    .meta { color: #4B5A45; font-size: 10px; }
    .header { border-bottom: 2px solid #73BC25; padding-bottom: 10px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #D9DED3; padding: 5px 6px; text-align: left; vertical-align: top; }
    th { background: #EAF3E4; color: #1E2A1A; font-weight: bold; }
    .num { text-align: center; }
    .empty { color: #6B7566; font-style: italic; text-align: center; padding: 12px; }
    .footer { margin-top: 12px; color: #6B7566; font-size: 8px; text-align: right; }
</style>
</head>
<body>
    <div class="header">
        <h1>Listado de participantes por modulo</h1>
        <p><strong>Curso:</strong> ' . cengi_notas_modulo_html($codigoCurso . ' - ' . $nombreCurso) . '</p>
        <p><strong>Modulo:</strong> ' . cengi_notas_modulo_html($nombreModulo) . '</p>
        <p class="meta">Generado: ' . cengi_notas_modulo_html(date('d/m/Y H:i')) . ' | Participantes: ' . count($filasExportacion) . '</p>
    </div>
    <table>
        <thead>
            <tr>
                <th>Participante</th>
                <th>CUI</th>
                <th>Ingenio</th>
                <th>Asistencia</th>
                <th>Pre-evaluacion</th>
                <th>Post-evaluacion</th>
            </tr>
        </thead>
        <tbody>' . $filasTablaPdf . '</tbody>
    </table>
    <div class="footer">CENGICANA | Cengicursos</div>
</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'landscape');
$dompdf->render();

cengi_export_enviar_pdf($dompdf->output(), $nombreArchivoBase . '.pdf');
