<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';

cengi_require_ver_participantes('participantes.php');

$db = conectar();
$cursoId = (int) ($_GET['curso_id'] ?? 0);
$formato = strtolower(trim((string) ($_GET['format'] ?? '')));
$busqueda = trim((string) ($_GET['q'] ?? ''));
$estado = strtolower(trim((string) ($_GET['estado'] ?? 'todos')));

if (!in_array($formato, ['pdf', 'csv'], true)) {
    http_response_code(400);
    exit('Formato de exportación no válido.');
}
if (!in_array($estado, ['todos', 'activo', 'aprobado', 'inactivo'], true)) {
    $estado = 'todos';
}

$condicionesCurso = ['c.id = ?'];
$paramsCurso = [$cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condicionesCurso[] = 'EXISTS (
        SELECT 1 FROM asignaciones ax
        INNER JOIN participantes px ON px.id = ax.participantes_id
        WHERE ax.cursos_id = c.id AND px.ingenio_id = ?
    )';
    $paramsCurso[] = cengi_ingenio_id_actual();
}
$stmtCurso = $db->prepare('SELECT c.id, c.nombre_cursos FROM cursos c WHERE '
    . implode(' AND ', $condicionesCurso) . ' LIMIT 1');
$stmtCurso->execute($paramsCurso);
$curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
if (!$curso) {
    http_response_code(404);
    exit('El curso no existe o no está disponible para este usuario.');
}

$condiciones = ['a.cursos_id = ?'];
$params = [$cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
if ($busqueda !== '') {
    $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR i.nombre_ingenios LIKE ? OR p.area_participantes LIKE ?)';
    $termino = '%' . $busqueda . '%';
    array_push($params, $termino, $termino, $termino, $termino);
}
if ($estado === 'activo') {
    $condiciones[] = 'p.estado_participantes = 1 AND a.estado_asignaciones = 1';
} elseif ($estado === 'aprobado') {
    $condiciones[] = 'p.estado_participantes = 1 AND a.estado_asignaciones = 1 AND CAST(cc.posevaluacion AS DECIMAL(10,2)) >= 60';
} elseif ($estado === 'inactivo') {
    $condiciones[] = '(p.estado_participantes = 0 OR a.estado_asignaciones = 0)';
}

$stmt = $db->prepare('SELECT
        p.nombre_participantes, p.cui_participantes, i.nombre_ingenios,
        p.puesto_participantes, p.area_participantes, p.estado_participantes,
        a.estado_asignaciones, cc.asistencia, cc.evaluacion, cc.posevaluacion
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    WHERE ' . implode(' AND ', $condiciones) . '
    ORDER BY p.nombre_participantes');
$stmt->execute($params);
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

function cengi_export_estado(array $fila)
{
    if ((int) $fila['estado_participantes'] !== 1 || (int) $fila['estado_asignaciones'] !== 1) {
        return 'Inactivo';
    }
    return is_numeric($fila['posevaluacion']) && (float) $fila['posevaluacion'] >= 60
        ? 'Aprobado' : 'Activo';
}

function cengi_export_numero($valor, $porcentaje = false)
{
    if (!is_numeric($valor)) {
        return '';
    }
    $numero = rtrim(rtrim(number_format((float) $valor, 1, '.', ''), '0'), '.');
    return $porcentaje ? $numero . '%' : $numero;
}

function cengi_export_csv_seguro($valor)
{
    $valor = (string) $valor;
    return $valor !== '' && in_array($valor[0], ['=', '+', '-', '@'], true) ? "'" . $valor : $valor;
}

$nombreArchivo = 'participantes_curso_' . $cursoId . '.' . $formato;

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $salida = fopen('php://output', 'wb');
    fputcsv($salida, ['Curso', 'Participante', 'CUI', 'Ingenio', 'Puesto', 'Área', 'Estado', 'Asistencia', 'Pre-evaluación', 'Post-evaluación']);
    foreach ($participantes as $fila) {
        fputcsv($salida, array_map('cengi_export_csv_seguro', [
            $curso['nombre_cursos'], $fila['nombre_participantes'], $fila['cui_participantes'],
            $fila['nombre_ingenios'], $fila['puesto_participantes'], $fila['area_participantes'],
            cengi_export_estado($fila), cengi_export_numero($fila['asistencia'], true),
            cengi_export_numero($fila['evaluacion']), cengi_export_numero($fila['posevaluacion']),
        ]));
    }
    fclose($salida);
    exit;
}

function cengi_pdf_codificar($texto)
{
    $texto = (string) $texto;
    if (function_exists('mb_convert_encoding')) {
        $texto = mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
    } elseif (function_exists('iconv')) {
        $convertido = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
        $texto = $convertido === false ? $texto : $convertido;
    }
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function cengi_pdf_recortar($texto, $maximo)
{
    $texto = trim((string) $texto);
    if (function_exists('mb_strlen')) {
        $longitud = mb_strlen($texto, 'UTF-8');
        $recorte = function ($valor, $limite) {
            return mb_substr($valor, 0, $limite, 'UTF-8');
        };
    } elseif (function_exists('iconv_strlen')) {
        $longitud = iconv_strlen($texto, 'UTF-8');
        $recorte = function ($valor, $limite) {
            return iconv_substr($valor, 0, $limite, 'UTF-8');
        };
    } else {
        $longitud = strlen($texto);
        $recorte = function ($valor, $limite) {
            return substr($valor, 0, $limite);
        };
    }
    return $longitud <= $maximo ? $texto : rtrim($recorte($texto, $maximo - 1)) . '…';
}

function cengi_pdf_texto($x, $y, $texto, $tamano = 8, $negrita = false)
{
    return sprintf("BT /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
        $negrita ? 2 : 1, $tamano, $x, $y, cengi_pdf_codificar($texto));
}

function cengi_pdf_pagina(array $filas, array $curso, $pagina, $total, $estado, $busqueda)
{
    $x = 39;
    $y = 506;
    $altoEncabezado = 20;
    $altoFila = 18;
    $columnas = [
        ['Participante', 150, 31], ['CUI', 78, 17], ['Ingenio', 100, 21],
        ['Puesto', 100, 21], ['Área', 120, 26], ['Estado', 60, 11],
        ['Asist.', 55, 10], ['Pre', 50, 9], ['Post', 50, 9],
    ];
    $ancho = array_sum(array_column($columnas, 1));
    $c = "0.10 0.28 0.16 rg\n" . cengi_pdf_texto($x, 558, 'Listado de participantes', 16, true);
    $c .= "0.15 0.15 0.15 rg\n" . cengi_pdf_texto($x, 538, 'Curso: ' . cengi_pdf_recortar($curso['nombre_cursos'], 100), 10, true);
    $filtro = 'Estado: ' . ucfirst($estado) . ($busqueda !== '' ? '  |  Búsqueda: ' . cengi_pdf_recortar($busqueda, 65) : '');
    $c .= cengi_pdf_texto($x, 523, $filtro, 8);
    $c .= cengi_pdf_texto(744, 558, 'Pág. ' . $pagina . '/' . $total, 8);

    $c .= "0.88 0.93 0.89 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $y, $ancho, $altoEncabezado);
    foreach ($filas as $i => $fila) {
        $filaY = $y - (($i + 1) * $altoFila);
        if ($i % 2 === 1) {
            $c .= "0.97 0.98 0.97 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $filaY, $ancho, $altoFila);
        }
        $valores = [
            $fila['nombre_participantes'], $fila['cui_participantes'], $fila['nombre_ingenios'],
            $fila['puesto_participantes'], $fila['area_participantes'], cengi_export_estado($fila),
            cengi_export_numero($fila['asistencia'], true), cengi_export_numero($fila['evaluacion']),
            cengi_export_numero($fila['posevaluacion']),
        ];
        $cursor = $x;
        foreach ($columnas as $j => $columna) {
            $c .= "0.12 0.12 0.12 rg\n" . cengi_pdf_texto($cursor + 4, $filaY + 6, cengi_pdf_recortar($valores[$j], $columna[2]), 7);
            $cursor += $columna[1];
        }
    }

    $fondo = $y - count($filas) * $altoFila;
    $c .= "0.65 0.70 0.66 RG 0.45 w\n" . sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $fondo, $ancho, $altoEncabezado + count($filas) * $altoFila);
    $cursor = $x;
    foreach ($columnas as $columna) {
        $c .= "0.10 0.28 0.16 rg\n" . cengi_pdf_texto($cursor + 4, $y + 7, $columna[0], 7.5, true);
        $cursor += $columna[1];
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $cursor, $fondo, $cursor, $y + $altoEncabezado);
    }
    for ($i = 1; $i <= count($filas); $i++) {
        $lineaY = $y - $i * $altoFila;
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x, $lineaY, $x + $ancho, $lineaY);
    }
    if (!$filas) {
        $c .= "0.35 0.35 0.35 rg\n" . cengi_pdf_texto($x + 8, $y - 15, 'No se encontraron participantes con los filtros seleccionados.', 8);
    }
    $c .= "0.35 0.35 0.35 rg\n" . cengi_pdf_texto($x, 24, 'Generado: ' . date('d/m/Y H:i'), 7);
    return $c;
}

function cengi_pdf_crear(array $contenidos)
{
    $objetos = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $referencias = [];
    foreach ($contenidos as $i => $contenido) {
        $pagina = 5 + $i * 2;
        $flujo = $pagina + 1;
        $referencias[] = $pagina . ' 0 R';
        $objetos[$pagina] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 841.89 595.28] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $flujo . ' 0 R >>';
        $objetos[$flujo] = '<< /Length ' . strlen($contenido) . ">>\nstream\n" . $contenido . "endstream";
    }
    $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $referencias) . '] /Count ' . count($referencias) . ' >>';
    ksort($objetos);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objetos as $numero => $objeto) {
        $offsets[$numero] = strlen($pdf);
        $pdf .= $numero . " 0 obj\n" . $objeto . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $cantidad = max(array_keys($objetos)) + 1;
    $pdf .= "xref\n0 $cantidad\n0000000000 65535 f \n";
    for ($i = 1; $i < $cantidad; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    return $pdf . "trailer\n<< /Size $cantidad /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}

$grupos = $participantes ? array_chunk($participantes, 24) : [[]];
$paginas = [];
foreach ($grupos as $indice => $grupo) {
    $paginas[] = cengi_pdf_pagina($grupo, $curso, $indice + 1, count($grupos), $estado, $busqueda);
}
$pdf = cengi_pdf_crear($paginas);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $pdf;
exit;
