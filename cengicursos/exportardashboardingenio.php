<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';

// Exportacion PDF/CSV del dashboard de ingenio (participantes y cursos).
// Misma guarda que dashboard_ingenio.php: solo admins o el rol "ingenio" con
// ingenio_id asignado pueden entrar aqui.
cengi_require_dashboard_ingenio('index.php');

$esAdmin = cengi_ve_todo_por_rol_o_ingenio();
$ingenioUsuarioId = cengi_ingenio_id_actual();

$db = conectar();

$ingenioId = (int) ($_GET['ingenio_id'] ?? 0);
if (!$esAdmin) {
    // Un usuario de ingenio solo puede exportar su propio ingenio, sin importar
    // el parametro recibido: nunca se confia en el query string para esto.
    $ingenioId = $ingenioUsuarioId;
}

$vista = strtolower(trim((string) ($_GET['vista'] ?? '')));
$formato = strtolower(trim((string) ($_GET['format'] ?? '')));
$busqueda = trim((string) ($_GET['q'] ?? ''));

if (!in_array($vista, ['participantes', 'cursos'], true)) {
    http_response_code(400);
    exit('Vista de exportación no válida.');
}
if (!in_array($formato, ['pdf', 'csv'], true)) {
    http_response_code(400);
    exit('Formato de exportación no válido.');
}
if ($ingenioId <= 0) {
    http_response_code(404);
    exit('No hay un ingenio disponible para exportar.');
}

$stmtIngenio = $db->prepare('SELECT id, nombre_ingenios FROM ingenios WHERE id = ? LIMIT 1');
$stmtIngenio->execute([$ingenioId]);
$ingenio = $stmtIngenio->fetch(PDO::FETCH_ASSOC);
if (!$ingenio) {
    http_response_code(404);
    exit('El ingenio no existe o no está disponible.');
}

function cengi_dbi_export_numero($valor, $sufijo = '')
{
    if (!is_numeric($valor)) {
        return '';
    }
    $numero = rtrim(rtrim(number_format((float) $valor, 1, '.', ''), '0'), '.');
    return $numero . $sufijo;
}

function cengi_dbi_export_fecha($valor)
{
    $valor = trim((string) ($valor ?? ''));
    if ($valor === '' || $valor === '0000-00-00') {
        return '—';
    }
    $fecha = strtotime($valor);
    return $fecha ? date('d/m/Y', $fecha) : '—';
}

function cengi_dbi_export_csv_seguro($valor)
{
    $valor = (string) $valor;
    return $valor !== '' && in_array($valor[0], ['=', '+', '-', '@'], true) ? "'" . $valor : $valor;
}

function cengi_dbi_estado_curso_export($inicio, $fin)
{
    $hoy = date('Y-m-d');
    if ($fin && $fin !== '0000-00-00' && $fin < $hoy) {
        return 'Finalizado';
    }
    if ($inicio && $inicio !== '0000-00-00' && $inicio > $hoy) {
        return 'Planificación';
    }
    return 'Activo';
}

// ---------------------------------------------------------------------
// Datos segun la vista solicitada.
// ---------------------------------------------------------------------
$titulo = '';
$columnasPdf = [];
$encabezadoCsv = [];
$filas = [];
$nombreArchivoBase = '';

if ($vista === 'participantes') {
    $titulo = 'Participantes de ' . $ingenio['nombre_ingenios'];
    $nombreArchivoBase = 'participantes_ingenio_' . $ingenioId;

    $condiciones = ['p.ingenio_id = ?'];
    $params = [$ingenioId];
    if ($busqueda !== '') {
        $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR p.area_participantes LIKE ?)';
        $termino = '%' . $busqueda . '%';
        array_push($params, $termino, $termino, $termino);
    }

    $stmt = $db->prepare("
        SELECT p.nombre_participantes, p.cui_participantes, p.puesto_participantes, p.area_participantes,
            COUNT(DISTINCT CASE WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN a.id END) AS cursos_completados,
            COUNT(DISTINCT CASE WHEN c.fin IS NULL OR c.fin >= CURDATE() THEN a.id END) AS cursos_activos,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS evaluacion_promedio,
            COUNT(DISTINCT d.id) AS diplomas,
            MAX(c.inicio) AS ultima_capacitacion
        FROM participantes p
        LEFT JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
        LEFT JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE " . implode(' AND ', $condiciones) . "
        GROUP BY p.id, p.nombre_participantes, p.cui_participantes, p.puesto_participantes, p.area_participantes
        ORDER BY p.nombre_participantes
    ");
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $encabezadoCsv = ['Participante', 'CUI', 'Puesto', 'Área', 'Cursos completados', 'Cursos activos', 'Evaluación promedio', 'Diplomas', 'Última capacitación'];
    $columnasPdf = [
        ['Participante', 140, 30], ['CUI', 75, 16], ['Puesto', 110, 23], ['Área', 100, 21],
        ['Complet.', 55, 9], ['Activos', 50, 9], ['Eval. prom.', 60, 10], ['Diplomas', 55, 9], ['Últ. capacitación', 72, 13],
    ];

    foreach ($registros as $r) {
        $filas[] = [
            $r['nombre_participantes'], $r['cui_participantes'], $r['puesto_participantes'], $r['area_participantes'],
            (string) (int) $r['cursos_completados'], (string) (int) $r['cursos_activos'],
            cengi_dbi_export_numero($r['evaluacion_promedio'], ' pts'), (string) (int) $r['diplomas'],
            cengi_dbi_export_fecha($r['ultima_capacitacion']),
        ];
    }
} else {
    $titulo = 'Cursos de ' . $ingenio['nombre_ingenios'];
    $nombreArchivoBase = 'cursos_ingenio_' . $ingenioId;

    $stmt = $db->prepare("
        SELECT
            c.id, c.codigo_curso, c.nombre_cursos, ca.descripcion_categorias_cursos AS categoria,
            c.tipo AS modalidad, c.inicio, c.fin,
            COUNT(DISTINCT a.id) AS inscritos,
            AVG(CASE WHEN cc.asistencia REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) AS asistencia_prom,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS eval_prom
        FROM cursos c
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        INNER JOIN asignaciones a ON a.cursos_id = c.id
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        GROUP BY c.id, c.codigo_curso, c.nombre_cursos, ca.descripcion_categorias_cursos, c.tipo, c.inicio, c.fin
        ORDER BY c.inicio DESC, c.nombre_cursos
    ");
    $stmt->execute([$ingenioId]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $encabezadoCsv = ['Código', 'Curso', 'Categoría', 'Modalidad', 'Inicio', 'Fin', 'Inscritos', 'Asistencia', 'Evaluación final', 'Estado'];
    $columnasPdf = [
        ['Código', 55, 10], ['Curso', 150, 32], ['Categoría', 90, 18], ['Modalidad', 70, 14],
        ['Inicio', 55, 10], ['Fin', 55, 10], ['Inscritos', 50, 8], ['Asist.', 45, 8], ['Eval.', 45, 8], ['Estado', 60, 12],
    ];

    foreach ($registros as $r) {
        $codigo = $r['codigo_curso'] !== null && $r['codigo_curso'] !== '' ? $r['codigo_curso'] : ('CEN-' . str_pad((string) $r['id'], 3, '0', STR_PAD_LEFT));
        $filas[] = [
            $codigo, $r['nombre_cursos'], $r['categoria'], $r['modalidad'] ?: 'Sin definir',
            cengi_dbi_export_fecha($r['inicio']), cengi_dbi_export_fecha($r['fin']), (string) (int) $r['inscritos'],
            cengi_dbi_export_numero($r['asistencia_prom'], '%'), cengi_dbi_export_numero($r['eval_prom'], ' pts'),
            cengi_dbi_estado_curso_export($r['inicio'], $r['fin']),
        ];
    }
}

$nombreArchivo = $nombreArchivoBase . '.' . $formato;

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $salida = fopen('php://output', 'wb');
    fputcsv($salida, $encabezadoCsv);
    foreach ($filas as $fila) {
        fputcsv($salida, array_map('cengi_dbi_export_csv_seguro', $fila));
    }
    fclose($salida);
    exit;
}

// ---------------------------------------------------------------------
// PDF generado a mano (sin dependencias externas), mismo enfoque que
// exportarparticipantes.php pero generalizado a columnas variables para
// poder reutilizarlo tanto en la vista de participantes como en la de cursos.
// ---------------------------------------------------------------------
function cengi_dbi_pdf_codificar($texto)
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

function cengi_dbi_pdf_recortar($texto, $maximo)
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

function cengi_dbi_pdf_texto($x, $y, $texto, $tamano = 8, $negrita = false)
{
    return sprintf("BT /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
        $negrita ? 2 : 1, $tamano, $x, $y, cengi_dbi_pdf_codificar($texto));
}

function cengi_dbi_pdf_pagina(array $filas, array $columnas, $titulo, $subtitulo, $pagina, $total)
{
    $x = 39;
    $y = 506;
    $altoEncabezado = 20;
    $altoFila = 18;
    $ancho = array_sum(array_column($columnas, 1));

    $c = "0.10 0.28 0.16 rg\n" . cengi_dbi_pdf_texto($x, 558, $titulo, 16, true);
    $c .= "0.15 0.15 0.15 rg\n" . cengi_dbi_pdf_texto($x, 538, cengi_dbi_pdf_recortar($subtitulo, 110), 9);
    $c .= cengi_dbi_pdf_texto(744, 558, 'Pág. ' . $pagina . '/' . $total, 8);

    $c .= "0.88 0.93 0.89 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $y, $ancho, $altoEncabezado);
    foreach ($filas as $i => $fila) {
        $filaY = $y - (($i + 1) * $altoFila);
        if ($i % 2 === 1) {
            $c .= "0.97 0.98 0.97 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $filaY, $ancho, $altoFila);
        }
        $cursor = $x;
        foreach ($columnas as $j => $columna) {
            $valor = $fila[$j] ?? '';
            $c .= "0.12 0.12 0.12 rg\n" . cengi_dbi_pdf_texto($cursor + 4, $filaY + 6, cengi_dbi_pdf_recortar($valor, $columna[2]), 7);
            $cursor += $columna[1];
        }
    }

    $fondo = $y - count($filas) * $altoFila;
    $c .= "0.65 0.70 0.66 RG 0.45 w\n" . sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $fondo, $ancho, $altoEncabezado + count($filas) * $altoFila);
    $cursor = $x;
    foreach ($columnas as $columna) {
        $c .= "0.10 0.28 0.16 rg\n" . cengi_dbi_pdf_texto($cursor + 4, $y + 7, $columna[0], 7.5, true);
        $cursor += $columna[1];
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $cursor, $fondo, $cursor, $y + $altoEncabezado);
    }
    for ($i = 1; $i <= count($filas); $i++) {
        $lineaY = $y - $i * $altoFila;
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x, $lineaY, $x + $ancho, $lineaY);
    }
    if (!$filas) {
        $c .= "0.35 0.35 0.35 rg\n" . cengi_dbi_pdf_texto($x + 8, $y - 15, 'No se encontraron registros para este ingenio.', 8);
    }
    $c .= "0.35 0.35 0.35 rg\n" . cengi_dbi_pdf_texto($x, 24, 'Generado: ' . date('d/m/Y H:i'), 7);
    return $c;
}

function cengi_dbi_pdf_crear(array $contenidos)
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

$subtitulo = $vista === 'participantes' && $busqueda !== '' ? 'Búsqueda: ' . $busqueda : 'Ingenio: ' . $ingenio['nombre_ingenios'];
$grupos = $filas ? array_chunk($filas, 24) : [[]];
$paginas = [];
foreach ($grupos as $indice => $grupo) {
    $paginas[] = cengi_dbi_pdf_pagina($grupo, $columnasPdf, $titulo, $subtitulo, $indice + 1, count($grupos));
}
$pdf = cengi_dbi_pdf_crear($paginas);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $pdf;
exit;
