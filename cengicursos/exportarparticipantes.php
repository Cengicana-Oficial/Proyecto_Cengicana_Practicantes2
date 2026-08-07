<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/classes/export_helpers.php';

cengi_require_ver_participantes('participantes.php');

$db = conectar();
$cursoId = (int) ($_GET['curso_id'] ?? 0);
$formato = strtolower(trim((string) ($_GET['format'] ?? '')));
$busqueda = trim((string) ($_GET['q'] ?? ''));
$estado = strtolower(trim((string) ($_GET['estado'] ?? 'todos')));

// "csv" se acepta como alias legado del boton "Descargar Excel" (antes
// generaba un CSV crudo vía fputcsv); ahora ambos generan el mismo archivo
// de Excel real, para no romper enlaces/favoritos ya guardados con
// &format=csv.
if ($formato === 'csv') {
    $formato = 'excel';
}
if (!in_array($formato, ['pdf', 'excel'], true)) {
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
$stmtCurso = $db->prepare('SELECT c.id, c.nombre_cursos, c.codigo_curso FROM cursos c WHERE '
    . implode(' AND ', $condicionesCurso) . ' LIMIT 1');
$stmtCurso->execute($paramsCurso);
$curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
if (!$curso) {
    http_response_code(404);
    exit('El curso no existe o no está disponible para este usuario.');
}

$codigoCurso = $curso['codigo_curso'] !== null && $curso['codigo_curso'] !== ''
    ? $curso['codigo_curso']
    : ('CEN-' . str_pad((string) $curso['id'], 3, '0', STR_PAD_LEFT));
$curso['codigo_curso'] = $codigoCurso;

$condiciones = ['a.cursos_id = ?'];
$params = [$cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
if ($busqueda !== '') {
    $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR i.nombre_ingenios LIKE ? OR p.area_participantes LIKE ? OR p.correo_participantes LIKE ? OR p.grado_academico_participantes LIKE ? OR p.telefono_participantes LIKE ?)';
    $termino = '%' . $busqueda . '%';
    array_push($params, $termino, $termino, $termino, $termino, $termino, $termino, $termino);
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
        p.puesto_participantes, p.area_participantes, p.correo_participantes,
        p.grado_academico_participantes, p.telefono_participantes, p.estado_participantes,
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

$encabezados = ['Código', 'Curso', 'Participante', 'CUI', 'Ingenio', 'Correo electrónico', 'Grado académico', 'Teléfono', 'Puesto', 'Área', 'Estado', 'Asistencia', 'Pre-evaluación', 'Post-evaluación'];
$filasExportacion = [];
foreach ($participantes as $fila) {
    $filasExportacion[] = [
        $codigoCurso, $curso['nombre_cursos'], $fila['nombre_participantes'], $fila['cui_participantes'],
        $fila['nombre_ingenios'], $fila['correo_participantes'], $fila['grado_academico_participantes'],
        $fila['telefono_participantes'], $fila['puesto_participantes'], $fila['area_participantes'],
        cengi_export_estado($fila), cengi_export_numero($fila['asistencia'], true),
        cengi_export_numero($fila['evaluacion']), cengi_export_numero($fila['posevaluacion']),
    ];
}

$nombreArchivoBase = 'participantes_' . $codigoCurso . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $curso['nombre_cursos']);

if ($formato === 'excel') {
    cengi_export_enviar_excel(
        $encabezados,
        $filasExportacion,
        'Participantes ' . $curso['nombre_cursos'],
        $nombreArchivoBase,
        [16, 28, 28, 16, 22, 30, 22, 16, 20, 20, 12, 12, 14, 14]
    );
}

// --- PDF ---------------------------------------------------------------
function cengi_pdf_pagina_participantes(array $filas, array $curso, $pagina, $total, $estado, $busqueda)
{
    $x = 39;
    $y = 506;
    $altoEncabezado = 20;
    $altoFila = 18;
    $columnas = [
        ['Participante', 100, 20], ['CUI', 65, 14], ['Ingenio', 65, 13],
        ['Correo', 95, 20], ['Grado', 75, 15], ['Teléfono', 55, 11],
        ['Puesto', 60, 12], ['Área', 60, 12], ['Estado', 45, 9],
        ['Asist.', 40, 7], ['Pre', 35, 6], ['Post', 35, 6],
    ];
    $ancho = array_sum(array_column($columnas, 1));
    $c = "0.10 0.28 0.16 rg\n" . cengi_export_pdf_texto($x, 558, 'Listado de participantes', 16, true);
    $c .= "0.15 0.15 0.15 rg\n" . cengi_export_pdf_texto($x, 538, 'Curso: ' . $curso['codigo_curso'] . ' — ' . cengi_export_pdf_recortar($curso['nombre_cursos'], 90), 10, true);
    $filtro = 'Estado: ' . ucfirst($estado) . ($busqueda !== '' ? '  |  Búsqueda: ' . cengi_export_pdf_recortar($busqueda, 65) : '');
    $c .= cengi_export_pdf_texto($x, 523, $filtro, 8);
    $c .= cengi_export_pdf_texto(744, 558, 'Pág. ' . $pagina . '/' . $total, 8);

    $c .= "0.88 0.93 0.89 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $y, $ancho, $altoEncabezado);
    foreach ($filas as $i => $fila) {
        $filaY = $y - (($i + 1) * $altoFila);
        if ($i % 2 === 1) {
            $c .= "0.97 0.98 0.97 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $filaY, $ancho, $altoFila);
        }
        $valores = [
            $fila['nombre_participantes'], $fila['cui_participantes'], $fila['nombre_ingenios'],
            $fila['correo_participantes'], $fila['grado_academico_participantes'], $fila['telefono_participantes'],
            $fila['puesto_participantes'], $fila['area_participantes'], cengi_export_estado($fila),
            cengi_export_numero($fila['asistencia'], true), cengi_export_numero($fila['evaluacion']),
            cengi_export_numero($fila['posevaluacion']),
        ];
        $cursor = $x;
        foreach ($columnas as $j => $columna) {
            $c .= "0.12 0.12 0.12 rg\n" . cengi_export_pdf_texto($cursor + 4, $filaY + 6, cengi_export_pdf_recortar($valores[$j], $columna[2]), 7);
            $cursor += $columna[1];
        }
    }

    $fondo = $y - count($filas) * $altoFila;
    $c .= "0.65 0.70 0.66 RG 0.45 w\n" . sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $fondo, $ancho, $altoEncabezado + count($filas) * $altoFila);
    $cursor = $x;
    foreach ($columnas as $columna) {
        $c .= "0.10 0.28 0.16 rg\n" . cengi_export_pdf_texto($cursor + 4, $y + 7, $columna[0], 7.5, true);
        $cursor += $columna[1];
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $cursor, $fondo, $cursor, $y + $altoEncabezado);
    }
    for ($i = 1; $i <= count($filas); $i++) {
        $lineaY = $y - $i * $altoFila;
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x, $lineaY, $x + $ancho, $lineaY);
    }
    if (!$filas) {
        $c .= "0.35 0.35 0.35 rg\n" . cengi_export_pdf_texto($x + 8, $y - 15, 'No se encontraron participantes con los filtros seleccionados.', 8);
    }
    $c .= "0.35 0.35 0.35 rg\n" . cengi_export_pdf_texto($x, 24, 'Generado: ' . date('d/m/Y H:i'), 7);
    return $c;
}

$grupos = $participantes ? array_chunk($participantes, 24) : [[]];
$paginas = [];
foreach ($grupos as $indice => $grupo) {
    $paginas[] = cengi_pdf_pagina_participantes($grupo, $curso, $indice + 1, count($grupos), $estado, $busqueda);
}
$pdf = cengi_export_pdf_crear($paginas);
cengi_export_enviar_pdf($pdf, $nombreArchivoBase . '.pdf');
