<?php
/**
 * Genera el "informe de instructor" para UN curso puntual como PDF real
 * (Dompdf), a diferencia de informe_instructor_pdf.php que agrega TODOS
 * los cursos de un instructor. Este archivo es independiente y no debe
 * compartir helpers con aquel (se definen versiones propias abajo).
 *
 * Requiere el mismo guard de permisos que instructores.php (sin bypass).
 */
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_instructores();

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function cengi_pdf_curso_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_pdf_curso_formato_fecha($fecha)
{
    if (empty($fecha)) {
        return 'Sin fecha';
    }
    $timestamp = strtotime((string) $fecha);
    if ($timestamp === false) {
        return 'Sin fecha';
    }
    $meses = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];
    return sprintf('%02d %s %d', (int) date('d', $timestamp), $meses[(int) date('n', $timestamp)], (int) date('Y', $timestamp));
}

function cengi_pdf_curso_evaluacion_texto($valor)
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    return number_format((float) $valor, 1);
}

function cengi_pdf_curso_barra_progreso($valor, float $escalaMaxima): string
{
    $porcentaje = $valor !== null ? max(0, min(100, ((float) $valor / $escalaMaxima) * 100)) : 0;
    return '<div class="pdf-progress-track"><div class="pdf-progress-fill" style="width:' . number_format($porcentaje, 2, '.', '') . '%;"></div></div>';
}

$instructorId = filter_input(INPUT_GET, 'instructor_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cursoId = filter_input(INPUT_GET, 'curso_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$instructorId || !$cursoId) {
    http_response_code(400);
    echo 'Parámetros "instructor_id" y "curso_id" son requeridos y deben ser enteros positivos.';
    exit;
}

$db = conectar();

$stmt = $db->prepare("
    SELECT i.id, i.nombre, i.especialidad, i.estado
    FROM instructores i
    WHERE i.id = ?
");
$stmt->execute([$instructorId]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instructor) {
    http_response_code(404);
    echo 'El instructor solicitado no existe.';
    exit;
}

$cursoStmt = $db->prepare("
    SELECT
        c.id AS curso_id,
        c.nombre_cursos,
        c.tipo AS modalidad,
        c.inicio,
        c.fin,
        ing.nombre_ingenios AS ingenio,
        (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id) AS total_inscritos,
        (
            SELECT AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)))
            FROM asignaciones a
            INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
            WHERE a.cursos_id = c.id AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'
        ) AS evaluacion_promedio
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    INNER JOIN ingenios ing ON ing.id = c.ingenio_id
    WHERE c.id = ? AND c.instructor_id = ?
");
$cursoStmt->execute([$cursoId, $instructorId]);
$curso = $cursoStmt->fetch(PDO::FETCH_ASSOC);

if (!$curso) {
    http_response_code(404);
    echo 'El curso solicitado no existe o no pertenece a este instructor.';
    exit;
}

$statsStmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        AVG(ei.instructor_lenguaje_claro) AS avg_lenguaje_claro,
        AVG(ei.instructor_material_adecuado) AS avg_material_adecuado,
        AVG(ei.instructor_conocimiento_tema) AS avg_conocimiento_tema,
        AVG(ei.instructor_respeto_participantes) AS avg_respeto_participantes,
        AVG(ei.instructor_puntualidad_objetivos) AS avg_puntualidad_objetivos,
        AVG(ei.recomendaria_instructor) AS avg_recomendaria_instructor,
        AVG(ei.tema_relevancia_utilidad) AS avg_tema_relevancia_utilidad,
        AVG(ei.logistica_evento) AS avg_logistica_evento,
        AVG(ei.recomendaria_contexto) AS avg_recomendaria_contexto
    FROM evaluaciones_instructor ei
    INNER JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
    WHERE eei.instructor_id = ? AND eei.curso_id = ?
");
$statsStmt->execute([$instructorId, $cursoId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$promedio = static function ($valor) {
    return $valor !== null ? (float) $valor : null;
};

$totalEncuesta = $stats ? (int) $stats['total'] : 0;
$encuesta = [
    'total' => $totalEncuesta,
    'instructor' => [
        'lenguaje_claro' => $totalEncuesta ? $promedio($stats['avg_lenguaje_claro']) : null,
        'material_adecuado' => $totalEncuesta ? $promedio($stats['avg_material_adecuado']) : null,
        'conocimiento_tema' => $totalEncuesta ? $promedio($stats['avg_conocimiento_tema']) : null,
        'respeto_participantes' => $totalEncuesta ? $promedio($stats['avg_respeto_participantes']) : null,
        'puntualidad_objetivos' => $totalEncuesta ? $promedio($stats['avg_puntualidad_objetivos']) : null,
    ],
    'recomendaria_instructor' => $totalEncuesta ? $promedio($stats['avg_recomendaria_instructor']) : null,
    'tema' => [
        'relevancia_utilidad' => $totalEncuesta ? $promedio($stats['avg_tema_relevancia_utilidad']) : null,
        'logistica_evento' => $totalEncuesta ? $promedio($stats['avg_logistica_evento']) : null,
    ],
    'recomendaria_contexto' => $totalEncuesta ? $promedio($stats['avg_recomendaria_contexto']) : null,
];

$comentariosStmt = $db->prepare("
    SELECT ei.areas_mejora, ei.capacitaciones_necesarias, ei.creado
    FROM evaluaciones_instructor ei
    INNER JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
    WHERE eei.instructor_id = ? AND eei.curso_id = ?
        AND (
            (ei.areas_mejora IS NOT NULL AND TRIM(ei.areas_mejora) <> '')
            OR (ei.capacitaciones_necesarias IS NOT NULL AND TRIM(ei.capacitaciones_necesarias) <> '')
        )
    ORDER BY ei.creado DESC, ei.id DESC
    LIMIT 5
");
$comentariosStmt->execute([$instructorId, $cursoId]);
$comentarios = $comentariosStmt->fetchAll(PDO::FETCH_ASSOC);

$ingenioTexto = trim((string) ($curso['ingenio'] ?? '')) !== '' ? (string) $curso['ingenio'] : 'Sin institución asociada';
$estadoTexto = ((int) $instructor['estado']) === 1 ? 'Activo' : 'Inactivo';
$fechaGeneracion = date('d/m/Y H:i');
$nombreInstructor = (string) ($instructor['nombre'] ?? 'Instructor');
$especialidadInstructor = trim((string) ($instructor['especialidad'] ?? '')) !== ''
    ? (string) $instructor['especialidad']
    : 'Sin especialidad';
$nombreCurso = (string) ($curso['nombre_cursos'] ?? 'Curso sin nombre');
$modalidadCurso = trim((string) ($curso['modalidad'] ?? '')) !== '' ? (string) $curso['modalidad'] : '—';
$evaluacionPromedio = $curso['evaluacion_promedio'] !== null ? (float) $curso['evaluacion_promedio'] : null;

$rangoFechas = cengi_pdf_curso_formato_fecha($curso['inicio']);
if (!empty($curso['fin']) && $curso['fin'] !== $curso['inicio']) {
    $rangoFechas .= ' &ndash; ' . cengi_pdf_curso_formato_fecha($curso['fin']);
}

// ---------------------------------------------------------------------
// Construccion del HTML del PDF.
//
// Dompdf no soporta flexbox ni CSS grid, y no ejecuta JavaScript ni dibuja
// <canvas>. Por eso el layout de este documento usa unicamente <table>
// para las filas de estadisticas y <div> con "width" en % +
// "background-color" para representar las barras de progreso de las
// escalas Likert (esto si lo soporta el motor de render de Dompdf). La
// tipografia usa DejaVu Sans (viene embebida con Dompdf).
// ---------------------------------------------------------------------

if ($encuesta['total'] === 0) {
    $seccionSatisfaccion = '<p class="pdf-empty">Aún no hay evaluaciones de satisfacción registradas para este instructor en este curso.</p>';
} else {
    $aspectosInstructor = [
        'Lenguaje claro' => $encuesta['instructor']['lenguaje_claro'],
        'Material adecuado' => $encuesta['instructor']['material_adecuado'],
        'Conocimiento del tema' => $encuesta['instructor']['conocimiento_tema'],
        'Respeto a participantes' => $encuesta['instructor']['respeto_participantes'],
        'Puntualidad y objetivos' => $encuesta['instructor']['puntualidad_objetivos'],
    ];
    $filasLikertInstructor = '';
    foreach ($aspectosInstructor as $etiqueta => $valor) {
        $filasLikertInstructor .= '<tr>'
            . '<td class="pdf-likert-label">' . cengi_pdf_curso_html($etiqueta) . '</td>'
            . '<td class="pdf-likert-bar">' . cengi_pdf_curso_barra_progreso($valor, 4) . '</td>'
            . '<td class="pdf-likert-value">' . ($valor !== null ? number_format($valor, 1) . '/4' : '—/4') . '</td>'
            . '</tr>';
    }

    $aspectosTema = [
        'Relevancia del tema' => $encuesta['tema']['relevancia_utilidad'],
        'Logística del evento' => $encuesta['tema']['logistica_evento'],
    ];
    $filasLikertTema = '';
    foreach ($aspectosTema as $etiqueta => $valor) {
        $filasLikertTema .= '<tr>'
            . '<td class="pdf-likert-label">' . cengi_pdf_curso_html($etiqueta) . '</td>'
            . '<td class="pdf-likert-bar">' . cengi_pdf_curso_barra_progreso($valor, 4) . '</td>'
            . '<td class="pdf-likert-value">' . ($valor !== null ? number_format($valor, 1) . '/4' : '—/4') . '</td>'
            . '</tr>';
    }

    $recomendariaInstructor = $encuesta['recomendaria_instructor'];
    $recomendariaContexto = $encuesta['recomendaria_contexto'];

    $seccionSatisfaccion = '
        <p class="pdf-hint">' . (int) $encuesta['total'] . ' respuesta(s) recibida(s) &middot; escalas Likert 1 (Deficiente) a 4 (Excelente)</p>
        <table class="pdf-table pdf-table-likert">
            <thead><tr><th colspan="3">Acerca del instructor</th></tr></thead>
            <tbody>' . $filasLikertInstructor . '</tbody>
        </table>
        <table class="pdf-table pdf-table-likert">
            <thead><tr><th colspan="3">Tema y logística del curso</th></tr></thead>
            <tbody>' . $filasLikertTema . '</tbody>
        </table>
        <table class="pdf-table pdf-table-scores">
            <tr>
                <td class="pdf-score-box">
                    <div class="pdf-score-label">¿Recomendaría a este instructor?</div>
                    <div class="pdf-score-value">' . ($recomendariaInstructor !== null ? number_format($recomendariaInstructor, 1) . '/10' : '—/10') . '</div>
                </td>
                <td class="pdf-score-box">
                    <div class="pdf-score-label">¿Recomendaría el lugar/plataforma?</div>
                    <div class="pdf-score-value">' . ($recomendariaContexto !== null ? number_format($recomendariaContexto, 1) . '/10' : '—/10') . '</div>
                </td>
            </tr>
        </table>';
}

$seccionComentarios = '<p class="pdf-empty">Todavía no hay comentarios cualitativos registrados para este curso.</p>';
if (!empty($comentarios)) {
    $itemsComentarios = '';
    foreach ($comentarios as $comentario) {
        $partes = '';
        if (!empty($comentario['areas_mejora'])) {
            $partes .= '<div><strong>Oportunidades de mejora:</strong> ' . cengi_pdf_curso_html($comentario['areas_mejora']) . '</div>';
        }
        if (!empty($comentario['capacitaciones_necesarias'])) {
            $partes .= '<div><strong>Capacitaciones necesarias:</strong> ' . cengi_pdf_curso_html($comentario['capacitaciones_necesarias']) . '</div>';
        }
        $itemsComentarios .= '<div class="pdf-comment-item">' . $partes
            . '<div class="pdf-comment-date">' . cengi_pdf_curso_formato_fecha($comentario['creado']) . '</div></div>';
    }
    $seccionComentarios = $itemsComentarios;
}

$html = '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 28px 34px; }
    body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #1E2A1A; font-size: 11px; }
    h1, h2, h3, h4 { margin: 0; }
    p { margin: 0 0 6px; }
    .pdf-header { border-bottom: 2px solid #73BC25; padding-bottom: 10px; margin-bottom: 12px; }
    .pdf-header-name { font-size: 20px; font-weight: bold; color: #1E2A1A; }
    .pdf-header-sub { font-size: 12px; color: #4B5A45; margin-top: 3px; }
    .pdf-header-meta { font-size: 9.5px; color: #4B5A45; margin-top: 6px; }
    table.pdf-stats { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.pdf-stats td { width: 33.33%; text-align: center; border: 1px solid #E4E7E1; padding: 10px 6px; }
    .pdf-stat-value { font-size: 18px; font-weight: bold; color: #3E7A12; }
    .pdf-stat-label { font-size: 9.5px; color: #4B5A45; margin-top: 3px; }
    .pdf-section { margin-bottom: 18px; }
    .pdf-section-title { font-size: 13px; font-weight: bold; color: #3E7A12; text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid #E4E7E1; padding-bottom: 5px; margin-bottom: 10px; }
    table.pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.pdf-table th, table.pdf-table td { border: 1px solid #E4E7E1; padding: 6px 8px; font-size: 10px; text-align: left; }
    table.pdf-table thead th { background-color: #F6F7F3; color: #1E2A1A; font-size: 10.5px; }
    .pdf-empty { color: #6B7566; font-style: italic; font-size: 10.5px; }
    .pdf-hint { color: #4B5A45; font-size: 9.5px; margin-bottom: 8px; }
    table.pdf-table-likert td.pdf-likert-label { width: 34%; font-weight: bold; }
    table.pdf-table-likert td.pdf-likert-bar { width: 46%; }
    table.pdf-table-likert td.pdf-likert-value { width: 20%; text-align: right; font-weight: bold; color: #3E7A12; }
    .pdf-progress-track { width: 100%; height: 9px; background-color: #EDEFEA; border-radius: 5px; }
    .pdf-progress-fill { height: 9px; background-color: #73BC25; border-radius: 5px; }
    table.pdf-table-scores td.pdf-score-box { width: 50%; text-align: center; border: 1px solid #E4E7E1; padding: 10px; }
    .pdf-score-label { font-size: 9.5px; color: #4B5A45; margin-bottom: 4px; }
    .pdf-score-value { font-size: 16px; font-weight: bold; color: #3E7A12; }
    .pdf-comment-item { border: 1px solid #E4E7E1; border-radius: 4px; padding: 8px 10px; margin-bottom: 8px; font-size: 10px; }
    .pdf-comment-item div { margin-bottom: 3px; }
    .pdf-comment-date { color: #6B7566; font-size: 9px; margin-top: 4px; }
    .pdf-footer { margin-top: 16px; font-size: 8.5px; color: #9AA396; text-align: center; }
</style>
</head>
<body>
    <div class="pdf-header">
        <div class="pdf-header-name">' . cengi_pdf_curso_html($nombreInstructor) . '</div>
        <div class="pdf-header-sub">' . cengi_pdf_curso_html($nombreCurso) . ' &middot; ' . cengi_pdf_curso_html($ingenioTexto) . '</div>
        <div class="pdf-header-meta">' . cengi_pdf_curso_html($especialidadInstructor) . ' &middot; Modalidad: ' . cengi_pdf_curso_html($modalidadCurso) . ' &middot; Fechas: ' . $rangoFechas . '</div>
        <div class="pdf-header-meta">Estado del instructor: ' . cengi_pdf_curso_html($estadoTexto) . ' &middot; Informe generado el ' . cengi_pdf_curso_html($fechaGeneracion) . '</div>
    </div>

    <table class="pdf-stats">
        <tr>
            <td><div class="pdf-stat-value">' . (int) ($curso['total_inscritos'] ?? 0) . '</div><div class="pdf-stat-label">Participantes inscritos</div></td>
            <td><div class="pdf-stat-value">' . cengi_pdf_curso_evaluacion_texto($evaluacionPromedio) . '</div><div class="pdf-stat-label">Evaluación promedio (post-evaluación académica)</div></td>
            <td><div class="pdf-stat-value">' . cengi_pdf_curso_html($modalidadCurso) . '</div><div class="pdf-stat-label">Modalidad del curso</div></td>
        </tr>
    </table>

    <div class="pdf-section">
        <div class="pdf-section-title">Satisfacción de los participantes (encuesta al instructor)</div>
        ' . $seccionSatisfaccion . '
    </div>

    <div class="pdf-section">
        <div class="pdf-section-title">Comentarios recientes</div>
        ' . $seccionComentarios . '
    </div>

    <div class="pdf-section">
        <div class="pdf-section-title">Desempeño académico</div>
        <table class="pdf-stats">
            <tr>
                <td><div class="pdf-stat-value">' . cengi_pdf_curso_evaluacion_texto($evaluacionPromedio) . '</div><div class="pdf-stat-label">Evaluación promedio registrada</div></td>
                <td><div class="pdf-stat-value">' . (int) ($curso['total_inscritos'] ?? 0) . '</div><div class="pdf-stat-label">Participantes con datos de asignación</div></td>
            </tr>
        </table>
        <p class="pdf-hint">Nota de examen posterior al curso de los participantes; no mide la satisfacción con el instructor.</p>
    </div>

    <div class="pdf-footer">CENGICAÑA &middot; Cengicursos &middot; Informe generado automáticamente</div>
</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

$slugInstructor = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim((string) $nombreInstructor)));
$slugCurso = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim((string) $nombreCurso)));
$nombreArchivo = 'informe-instructor-' . trim((string) $slugInstructor, '-') . '-curso-' . trim((string) $slugCurso, '-') . '.pdf';
$nombreArchivo = trim($nombreArchivo, '-');
if ($nombreArchivo === '' || $nombreArchivo === '.pdf') {
    $nombreArchivo = 'informe-instructor-' . $instructorId . '-curso-' . $cursoId . '.pdf';
}

$dompdf->stream($nombreArchivo, ['Attachment' => true]);
exit;
