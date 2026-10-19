<?php
ob_start();

/**
 * Genera el "informe de instructor" como un PDF real (Dompdf), en vez de
 * depender de window.print()/@media print del navegador: Dompdf renderiza un
 * documento HTML propio en el servidor (basado en tablas y bloques simples,
 * sin flexbox/grid ni JS/canvas) y lo envía como archivo adjunto.
 *
 * Requiere el mismo guard de permisos que instructores.php (sin bypass).
 */
require_once "conexion.php";
require_once "menu.php";
require_once __DIR__ . '/classes/export_helpers.php';

cengi_require_generar_informe_instructor();

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function cengi_pdf_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_pdf_formato_fecha($fecha)
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

function cengi_pdf_evaluacion_texto($valor)
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    return number_format((float) $valor, 1);
}

function cengi_pdf_barra_progreso($valor, float $escalaMaxima): string
{
    $porcentaje = $valor !== null ? max(0, min(100, ((float) $valor / $escalaMaxima) * 100)) : 0;
    return '<div class="pdf-progress-track"><div class="pdf-progress-fill" style="width:' . number_format($porcentaje, 2, '.', '') . '%;"></div></div>';
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    echo 'Parámetro "id" inválido.';
    exit;
}

$db = conectar();

// Scoping por ingenio para el rol "Administrador de ingenio": este reporte
// agrega TODOS los cursos de un instructor, asi que si el usuario actual es
// administrador de ingenio, tanto los contadores del encabezado como el
// listado de cursos mas abajo deben limitarse a cursos con al menos un
// participante de su propio ingenio (mismo criterio que instructores.php,
// via cengi_frag_curso_participante_ingenio() en conexion.php). Admin/
// gestor/formador no llevan estas condiciones y ven el informe completo.
$esAdminIngenioReporte = cengi_es_administrador_ingenio();
$ingenioIdReporte = cengi_ingenio_id_actual();

$stmt = $db->prepare("SELECT i.* FROM instructores i WHERE i.id = ?");
$stmt->execute([$id]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instructor) {
    http_response_code(404);
    echo 'El instructor solicitado no existe.';
    exit;
}

// Guard 403: un "Administrador de ingenio" solo puede ver el informe de un
// instructor que tenga al menos un curso (principal o co-instructor de
// modulo) con un participante de su propio ingenio. Evita que enumere
// ?id= de instructores fuera de su alcance para leer datos agregados de
// otros ingenios.
if ($esAdminIngenioReporte && !cengi_instructor_tiene_curso_relevante_ingenio($db, $id, $ingenioIdReporte)) {
    http_response_code(403);
    echo 'No tiene permiso para ver el informe de este instructor.';
    exit;
}

$promedio = static function ($valor) {
    return $valor !== null ? (float) $valor : null;
};

// El rol "Administrador de ingenio" no tiene acceso a las encuestas de
// satisfaccion del instructor (ni agregadas ni de su propio ingenio, ver
// tarea de instructores.php): las secciones quedan vacias sin siquiera
// consultar la BD, en vez de filtrarlas por ingenio.
$encuesta = [
    'total' => 0,
    'instructor' => [
        'lenguaje_claro' => null,
        'material_adecuado' => null,
        'conocimiento_tema' => null,
        'respeto_participantes' => null,
        'puntualidad_objetivos' => null,
    ],
    'recomendaria_instructor' => null,
    'tema' => [
        'relevancia_utilidad' => null,
        'logistica_evento' => null,
    ],
    'recomendaria_contexto' => null,
];
$comentarios = [];
$evaluacionesModulo = [];

if (!$esAdminIngenioReporte) {
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
        WHERE eei.instructor_id = ?
    ");
    $statsStmt->execute([$id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

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
        WHERE eei.instructor_id = ?
            AND (
                (ei.areas_mejora IS NOT NULL AND TRIM(ei.areas_mejora) <> '')
            )
        ORDER BY ei.creado DESC, ei.id DESC
        LIMIT 5
    ");
    $comentariosStmt->execute([$id]);
    $comentarios = $comentariosStmt->fetchAll(PDO::FETCH_ASSOC);


}

// Cursos como instructor principal, sin notas académicas de participantes.
$scopeParams=[];
$scopeCursos=$esAdminIngenioReporte ? ' AND '.cengi_frag_curso_participante_ingenio('c','informe',$scopeParams,$ingenioIdReporte) : '';
$edicionesStmt = $db->prepare("SELECT c.id,c.nombre_cursos,c.tipo AS modalidad,c.inicio,c.fin,
    ing.nombre_ingenios AS ingenio,
    (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id=c.id) AS total_inscritos,
    (SELECT AVG((ei.instructor_lenguaje_claro+ei.instructor_material_adecuado+ei.instructor_conocimiento_tema+ei.instructor_respeto_participantes+ei.instructor_puntualidad_objetivos)/5.0)
     FROM evaluaciones_instructor ei JOIN enlaces_evaluacion_instructor e ON e.id=ei.enlace_id
     WHERE e.curso_id=c.id AND e.instructor_id=? AND e.curso_modulo_id IS NULL) AS evaluacion_promedio
    FROM cursos c LEFT JOIN ingenios ing ON ing.id=c.ingenio_id
    WHERE c.instructor_id=? $scopeCursos ORDER BY c.inicio DESC,c.nombre_cursos");
$edicionesStmt->execute(array_merge([$id,$id],$scopeParams));
$ediciones=$edicionesStmt->fetchAll(PDO::FETCH_ASSOC);
// Asignaciones de módulo explícitas o heredadas; mostrar también sin encuestas.
$modulosStmt=$db->prepare("SELECT cm.id AS modulo_id,cm.nombre AS modulo_nombre,c.nombre_cursos,
    cm.orden,cm.horas,
    (SELECT COUNT(*) FROM evaluaciones_instructor ei JOIN enlaces_evaluacion_instructor e ON e.id=ei.enlace_id WHERE e.curso_modulo_id=cm.id AND e.instructor_id=?) AS total,
    (SELECT AVG((ei.instructor_lenguaje_claro+ei.instructor_material_adecuado+ei.instructor_conocimiento_tema+ei.instructor_respeto_participantes+ei.instructor_puntualidad_objetivos)/5.0)
      FROM evaluaciones_instructor ei JOIN enlaces_evaluacion_instructor e ON e.id=ei.enlace_id WHERE e.curso_modulo_id=cm.id AND e.instructor_id=?) AS nota
    FROM curso_modulos cm JOIN cursos c ON c.id=cm.curso_id
    WHERE (EXISTS(SELECT 1 FROM curso_modulo_instructores m WHERE m.curso_modulo_id=cm.id AND m.instructor_id=?)
      OR (c.instructor_id=? AND NOT EXISTS(SELECT 1 FROM curso_modulo_instructores m WHERE m.curso_modulo_id=cm.id))) $scopeCursos
    ORDER BY c.nombre_cursos,c.inicio DESC,cm.orden,cm.id");
$modulosStmt->execute(array_merge([$id,$id,$id,$id],$scopeParams));
$modulosAsignados=$modulosStmt->fetchAll(PDO::FETCH_ASSOC);
if($esAdminIngenioReporte){
    foreach($ediciones as &$fila)$fila['evaluacion_promedio']=null;unset($fila);
    foreach($modulosAsignados as &$fila){$fila['nota']=null;$fila['total']=0;}unset($fila);
}
$instructor['total_cursos']=count($ediciones);
$ingeniosInstructor = [];
foreach ($ediciones as $edicionFila) {
    $nombreIngenio = trim((string) ($edicionFila['ingenio'] ?? ''));
    if ($nombreIngenio !== '' && !in_array($nombreIngenio, $ingeniosInstructor, true)) {
        $ingeniosInstructor[] = $nombreIngenio;
    }
}
$ingenioTexto = 'Sin institución asociada';
if (count($ingeniosInstructor) === 1) {
    $ingenioTexto = $ingeniosInstructor[0];
} elseif (count($ingeniosInstructor) > 1) {
    $ingenioTexto = 'Varios ingenios';
}

$estadoTexto = ((int) $instructor['estado']) === 1 ? 'Activo' : 'Inactivo';
$fechaGeneracion = date('d/m/Y H:i');

// ---------------------------------------------------------------------
// Construccion del HTML del PDF.
//
// Dompdf no soporta flexbox ni CSS grid, y no ejecuta JavaScript ni dibuja
// <canvas> (los graficos Chart.js de la SPA quedan fuera). Por eso el
// layout de este documento usa unicamente <table> para las filas de
// estadisticas/cursos y <div> con "width" en % + "background-color" para
// representar las barras de progreso de las escalas Likert (esto si lo
// soporta el motor de render de Dompdf). La tipografia usa DejaVu Sans
// (viene embebida con Dompdf) en vez de 'Space Grotesk'/'Inter', que no
// estan disponibles para el renderizador.
// ---------------------------------------------------------------------

$filasCursos = '';
if (empty($ediciones)) {
    $filasCursos = '<tr><td colspan="6" class="pdf-empty">Este instructor todavía no tiene cursos asociados.</td></tr>';
} else {
    foreach ($ediciones as $curso) {
        $rango = cengi_pdf_formato_fecha($curso['inicio']);
        if (!empty($curso['fin']) && $curso['fin'] !== $curso['inicio']) {
            $rango .= ' &ndash; ' . cengi_pdf_formato_fecha($curso['fin']);
        }
        $filasCursos .= '<tr>'
            . '<td>' . cengi_pdf_html($curso['nombre_cursos']) . '</td>'
            . '<td>' . cengi_pdf_html($curso['ingenio'] ?: '—') . '</td>'
            . '<td>' . $rango . '</td>'
            . '<td>' . cengi_pdf_html($curso['modalidad'] ?: '—') . '</td>'
            . '<td>' . (int) $curso['total_inscritos'] . '</td>'
            . '<td>' . ($curso['evaluacion_promedio']===null?'Sin evaluaciones':number_format((float)$curso['evaluacion_promedio'],2).'/4') . '</td>'
            . '</tr>';
    }
}

if ($encuesta['total'] === 0) {
    $seccionSatisfaccion = '<p class="pdf-empty">Aún no hay evaluaciones de satisfacción registradas para este instructor todavía.</p>';
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
            . '<td class="pdf-likert-label">' . cengi_pdf_html($etiqueta) . '</td>'
            . '<td class="pdf-likert-bar">' . cengi_pdf_barra_progreso($valor, 4) . '</td>'
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
            . '<td class="pdf-likert-label">' . cengi_pdf_html($etiqueta) . '</td>'
            . '<td class="pdf-likert-bar">' . cengi_pdf_barra_progreso($valor, 4) . '</td>'
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

$seccionComentarios = '<p class="pdf-empty">Todavía no hay comentarios cualitativos registrados.</p>';
if (!empty($comentarios)) {
    $itemsComentarios = '';
    foreach ($comentarios as $comentario) {
        $partes = '';
        if (!empty($comentario['areas_mejora'])) {
            $partes .= '<div><strong>Oportunidades de mejora:</strong> ' . cengi_pdf_html($comentario['areas_mejora']) . '</div>';
        }
        $itemsComentarios .= '<div class="pdf-comment-item">' . $partes
            . '<div class="pdf-comment-date">' . cengi_pdf_formato_fecha($comentario['creado']) . '</div></div>';
    }
    $seccionComentarios = $itemsComentarios;
}

$seccionEvaluacionesModulo = '<p class="pdf-empty">Este instructor todavía no tiene evaluaciones de módulo registradas.</p>';
$filasModulo='';
foreach($modulosAsignados as $modulo){
    $filasModulo.='<tr><td>'.cengi_pdf_html($modulo['nombre_cursos']).'</td><td>'.cengi_pdf_html($modulo['modulo_nombre']).'</td><td>'.cengi_pdf_html($modulo['horas']).'</td><td>'.(int)$modulo['total'].'</td><td>'.($modulo['nota']===null?'Sin evaluaciones':number_format((float)$modulo['nota'],2).'/4').'</td></tr>';
}
$seccionEvaluacionesModulo=$filasModulo ? '<table class="pdf-table"><thead><tr><th>Diplomado / curso</th><th>Módulo asignado</th><th>Horas</th><th>Respuestas</th><th>Evaluación del instructor</th></tr></thead><tbody>'.$filasModulo.'</tbody></table>' : '<p class="pdf-empty">Este instructor no tiene módulos asignados.</p>';
$aspectosNota=array_values(array_filter($encuesta['instructor'],static function($v){return $v!==null;}));
$evaluacionPromedio=$aspectosNota?array_sum($aspectosNota)/count($aspectosNota):null;
$nombreInstructor = (string) ($instructor['nombre'] ?? 'Instructor');
$especialidadInstructor = trim((string) ($instructor['especialidad'] ?? '')) !== ''
    ? (string) $instructor['especialidad']
    : 'Sin especialidad';

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
    table.pdf-stats td { width: 25%; text-align: center; border: 1px solid #E4E7E1; padding: 10px 6px; }
    .pdf-stat-value { font-size: 18px; font-weight: bold; color: #3E7A12; }
    .pdf-stat-label { font-size: 9.5px; color: #4B5A45; margin-top: 3px; }
    .pdf-section { margin-bottom: 18px; } tr, .pdf-comment-item { page-break-inside: avoid; } .pdf-section-title { page-break-after: avoid; }
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
        <div class="pdf-header-name">' . cengi_pdf_html($nombreInstructor) . '</div>
        <div class="pdf-header-sub">' . cengi_pdf_html($especialidadInstructor) . ' &middot; ' . cengi_pdf_html($ingenioTexto) . '</div>
        <div class="pdf-header-meta">Estado: ' . cengi_pdf_html($estadoTexto) . ' &middot; Informe generado el ' . cengi_pdf_html($fechaGeneracion) . '</div>
    </div>

    <table class="pdf-stats">
        <tr>
            <td><div class="pdf-stat-value">' . (int) ($instructor['total_cursos'] ?? 0) . '</div><div class="pdf-stat-label">Cursos impartidos</div></td>
            <td><div class="pdf-stat-value">' . count($modulosAsignados) . '</div><div class="pdf-stat-label">Módulos asignados</div></td>
            <td><div class="pdf-stat-value">' . ($evaluacionPromedio===null?'—':number_format($evaluacionPromedio,2).'/4') . '</div><div class="pdf-stat-label">Evaluación del instructor (1–4)</div></td>
            <td><div class="pdf-stat-value">' . cengi_pdf_html($estadoTexto) . '</div><div class="pdf-stat-label">Estado del instructor</div></td>
        </tr>
    </table>

    <div class="pdf-section">
        <div class="pdf-section-title">Cursos impartidos</div>
        <table class="pdf-table">
            <thead>
                <tr><th>Curso</th><th>Ingenio</th><th>Fecha</th><th>Modalidad</th><th>Participantes</th><th>Evaluación del instructor</th></tr>
            </thead>
            <tbody>' . $filasCursos . '</tbody>
        </table>
    </div>

    <div class="pdf-section">
        <div class="pdf-section-title">Satisfacción de los participantes (encuesta al instructor)</div>
        <p class="pdf-hint">Nota del instructor: promedio de los cinco criterios de Acerca del instructor; no incluye exámenes de participantes, logística ni recomendación.</p>' . $seccionSatisfaccion . '
    </div>

    <div class="pdf-section">
        <div class="pdf-section-title">Módulos asignados de diplomados y cursos</div>
        ' . $seccionEvaluacionesModulo . '
    </div>

    <div class="pdf-section">
        <div class="pdf-section-title">Comentarios recientes</div>
        ' . $seccionComentarios . '
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

$nombreArchivo = 'informe-instructor-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim((string) $nombreInstructor))) . '.pdf';
$nombreArchivo = trim($nombreArchivo, '-');
if ($nombreArchivo === '' || $nombreArchivo === '.pdf') {
    $nombreArchivo = 'informe-instructor-' . $id . '.pdf';
}

cengi_export_enviar_pdf($dompdf->output(), $nombreArchivo);
