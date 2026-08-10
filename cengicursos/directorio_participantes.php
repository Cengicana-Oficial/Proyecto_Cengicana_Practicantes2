<?php
require_once 'conexion.php';
require_once 'menu.php';

cengi_require_ver_participantes();
$db = conectar();
$puedeEliminar = cengi_puede_eliminar_participantes();

function cengi_dir_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_dir_iniciales($nombre)
{
    $partes = preg_split('/\s+/', trim((string) $nombre));
    $iniciales = '';
    foreach (array_slice(array_filter($partes), 0, 2) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $iniciales ?: 'P';
}

function cengi_dir_fecha($valor)
{
    $valor = trim((string) ($valor ?? ''));
    if ($valor === '' || $valor === '0000-00-00') {
        return '—';
    }
    $fecha = strtotime($valor);
    return $fecha ? date('d/m/Y', $fecha) : '—';
}

// La ficha se obtiene al abrir el modal para no cargar todos los historiales en la tabla.
$fichaId = (int) ($_GET['ficha_id'] ?? 0);
if ($fichaId > 0) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $condicionesFicha = ['p.id = ?'];
    $paramsFicha = [$fichaId];
    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $condicionesFicha[] = 'p.ingenio_id = ?';
        $paramsFicha[] = cengi_ingenio_id_actual();
    }

    $stmtFicha = $db->prepare('SELECT p.id, p.nombre_participantes, p.cui_participantes,
            p.puesto_participantes, p.area_participantes, p.correo_participantes,
            p.grado_academico_participantes, p.telefono_participantes,
            p.estado_participantes, i.nombre_ingenios
        FROM participantes p
        INNER JOIN ingenios i ON i.id = p.ingenio_id
        WHERE ' . implode(' AND ', $condicionesFicha) . ' LIMIT 1');
    $stmtFicha->execute($paramsFicha);
    $ficha = $stmtFicha->fetch(PDO::FETCH_ASSOC);

    if (!$ficha) {
        http_response_code(404);
        echo json_encode(['error' => 'El participante no existe o no está disponible.']);
        exit;
    }

    $stmtHistorial = $db->prepare("SELECT a.id AS asignacion_id, c.nombre_cursos,
            ca.descripcion_categorias_cursos AS categoria, c.tipo AS modalidad,
            c.inicio, c.fin, a.estado_asignaciones, cc.asistencia, cc.evaluacion,
            cc.posevaluacion,
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS diploma,
            d.codigo_unico AS diploma_codigo, d.emitido_en AS diploma_fecha,
            CASE
                WHEN a.estado_asignaciones <> 1 THEN 'inactivo'
                WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN 'finalizado'
                ELSE 'activo'
            END AS estado_curso
        FROM asignaciones a
        INNER JOIN cursos c ON c.id = a.cursos_id
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (
            SELECT asignacion_id, MAX(pdf_path) AS pdf_path,
                   MAX(codigo_unico) AS codigo_unico, MAX(emitido_en) AS emitido_en
            FROM diplomas WHERE tipo = 'curso' GROUP BY asignacion_id
        ) d ON d.asignacion_id = a.id
        WHERE a.participantes_id = ?
        ORDER BY COALESCE(c.inicio, c.creado) DESC, c.nombre_cursos");
    $stmtHistorial->execute([$fichaId]);
    $cursosFicha = $stmtHistorial->fetchAll(PDO::FETCH_ASSOC);
    $resumen = ['total' => count($cursosFicha), 'completados' => 0, 'activos' => 0, 'diplomas' => 0];
    foreach ($cursosFicha as &$cursoFicha) {
        // El path guardado en BD (diplomas.pdf_path o control_cursos.diploma) puede
        // venir en formatos legados fragiles ("../uploads/...", rutas de filesystem
        // absolutas, etc.). Se normaliza aqui a la URL raiz-absoluta correcta antes de
        // exponerlo en el JSON, para que diplomaHref() en el JS no dependa de resolver
        // el string tal cual quedo guardado.
        $cursoFicha['diploma'] = cengi_normalizar_url_archivo($cursoFicha['diploma']);

        if ($cursoFicha['estado_curso'] === 'finalizado') {
            $resumen['completados']++;
        } elseif ($cursoFicha['estado_curso'] === 'activo') {
            $resumen['activos']++;
        }
        if (!empty($cursoFicha['diploma'])) {
            $resumen['diplomas']++;
        }
    }
    unset($cursoFicha);

    echo json_encode(['participante' => $ficha, 'resumen' => $resumen, 'cursos' => $cursosFicha], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$ingenioFiltro = (int) ($_GET['ingenio_id'] ?? 0);
$cantidadFiltro = trim((string) ($_GET['cantidad'] ?? ''));
$condiciones = ['p.estado_participantes = 1'];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR p.correo_participantes LIKE ? OR p.grado_academico_participantes LIKE ? OR p.telefono_participantes LIKE ?)';
    $termino = '%' . $busqueda . '%';
    array_push($params, $termino, $termino, $termino, $termino, $termino);
}
if ($ingenioFiltro > 0) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = $ingenioFiltro;
}
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$sql = "SELECT p.id, p.nombre_participantes, p.cui_participantes,
        p.puesto_participantes, p.area_participantes, p.correo_participantes,
        p.grado_academico_participantes, p.telefono_participantes, i.nombre_ingenios,
        COUNT(DISTINCT CASE WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN a.id END) AS cursos_completados,
        COUNT(DISTINCT CASE WHEN c.fin IS NULL OR c.fin >= CURDATE() THEN a.id END) AS cursos_activos,
        COUNT(DISTINCT a.id) AS total_cursos,
        AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS evaluacion_promedio,
        COUNT(DISTINCT CASE WHEN COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) IS NOT NULL THEN a.id END) AS diplomas,
        MAX(c.inicio) AS ultima_capacitacion
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    LEFT JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
    LEFT JOIN cursos c ON c.id = a.cursos_id
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
    {$where}
    GROUP BY p.id, p.nombre_participantes, p.cui_participantes,
             p.puesto_participantes, p.area_participantes, p.correo_participantes,
             p.grado_academico_participantes, p.telefono_participantes, i.nombre_ingenios";
if ($cantidadFiltro === '1') {
    $sql .= ' HAVING total_cursos = 1';
} elseif ($cantidadFiltro === '2-4') {
    $sql .= ' HAVING total_cursos BETWEEN 2 AND 4';
} elseif ($cantidadFiltro === '5+') {
    $sql .= ' HAVING total_cursos >= 5';
}
$sql .= ' ORDER BY p.nombre_participantes';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="directorio_participantes_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $salida = fopen('php://output', 'wb');
    fputcsv($salida, ['Participante', 'CUI', 'Ingenio', 'Puesto', 'Área', 'Correo electrónico', 'Grado académico', 'Teléfono', 'Cursos completados', 'Cursos activos', 'Evaluación promedio', 'Diplomas', 'Última capacitación']);
    foreach ($participantes as $participante) {
        $filaCsv = [$participante['nombre_participantes'], $participante['cui_participantes'], $participante['nombre_ingenios'],
            $participante['puesto_participantes'], $participante['area_participantes'], $participante['correo_participantes'],
            $participante['grado_academico_participantes'], $participante['telefono_participantes'], $participante['cursos_completados'],
            $participante['cursos_activos'], $participante['evaluacion_promedio'], $participante['diplomas'],
            cengi_dir_fecha($participante['ultima_capacitacion'])];
        foreach ($filaCsv as &$campoCsv) {
            $campoCsv = (string) $campoCsv;
            if ($campoCsv !== '' && in_array($campoCsv[0], ['=', '+', '-', '@'], true)) {
                $campoCsv = "'" . $campoCsv;
            }
        }
        unset($campoCsv);
        fputcsv($salida, $filaCsv);
    }
    fclose($salida);
    exit;
}

$paramsKpi = [];
$whereKpi = 'WHERE p.estado_participantes = 1';
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $whereKpi .= ' AND p.ingenio_id = ?';
    $paramsKpi[] = cengi_ingenio_id_actual();
}
$stmtKpi = $db->prepare("SELECT COUNT(DISTINCT p.id) AS personas,
        SUM(CASE WHEN COALESCE(t.total_cursos, 0) > 1 THEN 1 ELSE 0 END) AS con_mas_de_uno,
        COALESCE(SUM(t.diplomas), 0) AS diplomas_totales,
        COALESCE(AVG(COALESCE(t.total_cursos, 0)), 0) AS promedio_cursos
    FROM participantes p
    LEFT JOIN (
        SELECT a.participantes_id, COUNT(DISTINCT a.id) AS total_cursos,
            COUNT(DISTINCT CASE WHEN COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) IS NOT NULL THEN a.id END) AS diplomas
        FROM asignaciones a
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE a.estado_asignaciones = 1 GROUP BY a.participantes_id
    ) t ON t.participantes_id = p.id {$whereKpi}");
$stmtKpi->execute($paramsKpi);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);
$ingenios = $db->query('SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_ASSOC);
$exportarUrl = 'directorio_participantes.php?' . http_build_query([
    'q' => $busqueda, 'ingenio_id' => $ingenioFiltro, 'cantidad' => $cantidadFiltro, 'export' => 'csv',
]);

// Evita que el navegador sirva una copia cacheada de la tabla (p. ej. al
// volver con el boton "atras") con conteos desactualizados de cursos/diplomas.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="es">
<?php include 'head.php'; ?>
<body class="cengi-canvas cengi-directory-page">
<?php menu_render(); ?>

<main class="container cengi-directory-shell">
    <section class="cengi-directory-kpis" aria-label="Resumen del directorio">
        <article class="cengi-directory-kpi"><div>Personas registradas</div><strong><?php echo number_format((int) $kpi['personas']); ?></strong></article>
        <article class="cengi-directory-kpi"><div>Con más de 1 curso</div><strong><?php echo number_format((int) $kpi['con_mas_de_uno']); ?></strong></article>
        <article class="cengi-directory-kpi"><div>Diplomas acumulados</div><strong><?php echo number_format((int) $kpi['diplomas_totales']); ?></strong></article>
        <article class="cengi-directory-kpi"><div>Promedio de cursos por persona</div><strong><?php echo number_format((float) $kpi['promedio_cursos'], 1); ?></strong></article>
    </section>

    <section class="cengi-directory-filter-card">
        <form class="cengi-directory-filters" method="get" id="directory-filters">
            <label class="cengi-directory-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span><span class="sr-only">Buscar por nombre o CUI</span>
                <input type="search" name="q" placeholder="Buscar por nombre o CUI..." value="<?php echo cengi_dir_html($busqueda); ?>">
            </label>
            <label><span class="sr-only">Filtrar por ingenio</span><select name="ingenio_id" aria-label="Filtrar por ingenio">
                <option value="0">Todos los ingenios</option>
                <?php foreach ($ingenios as $ingenio): ?>
                    <option value="<?php echo (int) $ingenio['id']; ?>" <?php echo $ingenioFiltro === (int) $ingenio['id'] ? 'selected' : ''; ?>><?php echo cengi_dir_html($ingenio['nombre_ingenios']); ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span class="sr-only">Filtrar por cantidad de cursos</span><select name="cantidad" aria-label="Filtrar por cantidad de cursos">
                <option value="">Cualquier cantidad de cursos</option>
                <option value="1" <?php echo $cantidadFiltro === '1' ? 'selected' : ''; ?>>1 curso</option>
                <option value="2-4" <?php echo $cantidadFiltro === '2-4' ? 'selected' : ''; ?>>2–4 cursos</option>
                <option value="5+" <?php echo $cantidadFiltro === '5+' ? 'selected' : ''; ?>>5+ cursos</option>
            </select></label>
            <?php if ($busqueda !== '' || $ingenioFiltro > 0 || $cantidadFiltro !== ''): ?>
                <a class="cengi-directory-clear" href="directorio_participantes.php">Limpiar</a>
            <?php endif; ?>
            <a class="cengi-directory-export" href="<?php echo cengi_dir_html($exportarUrl); ?>"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Exportar directorio</a>
        </form>
    </section>

    <section class="cengi-directory-table-card">
        <div class="cengi-directory-table-scroll" tabindex="0" aria-label="Directorio de participantes">
            <table class="cengi-directory-table">
                <thead><tr><th>Participante</th><th>CUI</th><th>Ingenio</th><th>Cursos completados</th><th>Cursos activos</th><th>Evaluación prom.</th><th>Diplomas</th><th>Última capacitación</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                <?php if (!$participantes): ?>
                    <tr><td colspan="9" class="cengi-directory-empty"><span class="glyphicon glyphicon-user"></span><strong>No se encontraron participantes</strong><small>Modifica los filtros para ampliar los resultados.</small></td></tr>
                <?php endif; ?>
                <?php foreach ($participantes as $participante): ?>
                    <tr>
                        <td><div class="cengi-directory-person"><span class="cengi-directory-avatar"><?php echo cengi_dir_html(cengi_dir_iniciales($participante['nombre_participantes'])); ?></span><span><strong><?php echo cengi_dir_html($participante['nombre_participantes']); ?></strong><small><?php echo cengi_dir_html($participante['puesto_participantes']); ?></small></span></div></td>
                        <td class="cengi-directory-cui"><?php echo cengi_dir_html($participante['cui_participantes']); ?></td>
                        <td><?php echo cengi_dir_html($participante['nombre_ingenios']); ?></td>
                        <td><?php echo (int) $participante['cursos_completados']; ?></td>
                        <td><?php echo (int) $participante['cursos_activos']; ?></td>
                        <td><?php echo $participante['evaluacion_promedio'] !== null ? number_format((float) $participante['evaluacion_promedio'], 1) . ' pts' : '—'; ?></td>
                        <td><?php echo (int) $participante['diplomas']; ?></td>
                        <td><?php echo cengi_dir_html(cengi_dir_fecha($participante['ultima_capacitacion'])); ?></td>
                        <td>
                            <div class="cengi-directory-row-actions">
                                <button type="button" class="cengi-directory-profile-button" data-toggle="modal" data-target="#directory-profile-modal" data-participant-id="<?php echo (int) $participante['id']; ?>">Ver ficha</button>
                                <?php if ($puedeEliminar): ?>
                                    <a class="cengi-action-btn is-delete" href="#" data-href="eliminar_participante.php?origen=directorio&id=<?php echo (int) $participante['id']; ?>" data-toggle="modal" data-target="#directory-confirm-delete" data-tooltip="Eliminar" aria-label="Eliminar participante"><span class="glyphicon glyphicon-trash"></span><span class="sr-only">Eliminar</span></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="cengi-directory-table-footer"><?php echo number_format(count($participantes)); ?> resultado<?php echo count($participantes) === 1 ? '' : 's'; ?></footer>
    </section>
</main>

<div class="modal fade cengi-directory-modal" id="directory-profile-modal" tabindex="-1" role="dialog" aria-labelledby="directory-profile-name">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <header class="cengi-directory-profile-head">
            <span class="cengi-directory-profile-avatar" id="directory-profile-avatar">P</span>
            <div><h2 id="directory-profile-name">Ficha del participante</h2><p id="directory-profile-subtitle"></p></div>
            <button type="button" class="cengi-directory-modal-close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">×</span></button>
        </header>
        <div class="modal-body">
            <div class="cengi-directory-modal-loading" id="directory-profile-loading"><span class="glyphicon glyphicon-refresh"></span> Cargando ficha…</div>
            <div id="directory-profile-content" hidden>
                <div class="cengi-directory-profile-stats">
                    <div><strong id="directory-stat-total">0</strong><span>Cursos registrados</span></div>
                    <div><strong id="directory-stat-completed">0</strong><span>Completados</span></div>
                    <div><strong id="directory-stat-active">0</strong><span>Activos</span></div>
                    <div><strong id="directory-stat-diplomas">0</strong><span>Diplomas</span></div>
                </div>
                <div class="cengi-directory-profile-details"><div><span>Puesto</span><strong id="directory-profile-position">—</strong></div><div><span>Área</span><strong id="directory-profile-area">—</strong></div><div><span>Correo electrónico</span><strong id="directory-profile-email">—</strong></div><div><span>Grado académico</span><strong id="directory-profile-degree">—</strong></div><div><span>Teléfono</span><strong id="directory-profile-phone">—</strong></div></div>
                <h3 class="cengi-directory-history-title">Historial de capacitación</h3>
                <div class="cengi-directory-course-list" id="directory-profile-courses"></div>
            </div>
            <div class="cengi-directory-modal-error" id="directory-profile-error" hidden></div>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button></footer>
    </div></div>
</div>

<?php if ($puedeEliminar): ?>
<div class="modal fade" id="directory-confirm-delete" tabindex="-1" role="dialog" aria-labelledby="directory-confirm-delete-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="model-header">
                <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title" id="directory-confirm-delete-label">Eliminar participante</h4>
            </div>
            <div class="modal-body">
                ¿Desea eliminar este participante?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <a class="btn btn-danger btn-ok">Eliminar</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function ($) {
    'use strict';
    var request = null;
    var $modal = $('#directory-profile-modal');
    var $loading = $('#directory-profile-loading');
    var $content = $('#directory-profile-content');
    var $error = $('#directory-profile-error');
    var $courses = $('#directory-profile-courses');

    $('#directory-filters select').on('change', function () {
        document.getElementById('directory-filters').submit();
    });

    $('#directory-confirm-delete').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        $(this).find('.btn-ok').attr('href', $(trigger).data('href'));
    });

    function initials(name) {
        return String(name || 'P').trim().split(/\s+/).slice(0, 2).map(function (part) {
            return part.charAt(0).toUpperCase();
        }).join('') || 'P';
    }

    function valueOrDash(value, suffix) {
        if (value === null || value === undefined || value === '' || isNaN(Number(value))) return '—';
        return (Math.round(Number(value) * 10) / 10) + (suffix || '');
    }

    function formatDate(value) {
        if (!value || value === '0000-00-00') return 'Sin fecha';
        var parts = String(value).slice(0, 10).split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
    }

    function diplomaHref(path) {
        if (!path) return '';
        try {
            var url = new URL(path, window.location.href);
            return /^(https?:)$/.test(url.protocol) ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function metric(label, value) {
        return $('<div>', {class: 'cengi-directory-course-metric'})
            .append($('<span>').text(label)).append($('<strong>').text(value));
    }

    function renderCourse(course) {
        var state = ['activo', 'finalizado', 'inactivo'].indexOf(course.estado_curso) >= 0 ? course.estado_curso : 'inactivo';
        var labels = {activo: 'Activo', finalizado: 'Finalizado', inactivo: 'Inactivo'};
        var dateText = formatDate(course.inicio);
        if (course.fin && course.fin !== '0000-00-00') dateText += ' – ' + formatDate(course.fin);

        var $card = $('<article>', {class: 'cengi-directory-course-card'});
        var $head = $('<div>', {class: 'cengi-directory-course-head'})
            .append($('<strong>').text(course.nombre_cursos || 'Curso sin nombre'))
            .append($('<span>', {class: 'cengi-directory-course-state is-' + state}).text(labels[state]));
        var meta = [course.categoria, course.modalidad, dateText].filter(Boolean).join(' · ');
        var $metrics = $('<div>', {class: 'cengi-directory-course-metrics'})
            .append(metric('Asistencia', valueOrDash(course.asistencia, '%')))
            .append(metric('Pre-evaluación', valueOrDash(course.evaluacion, ' pts')))
            .append(metric('Post-evaluación', valueOrDash(course.posevaluacion, ' pts')));
        $card.append($head).append($('<p>', {class: 'cengi-directory-course-meta'}).text(meta)).append($metrics);

        var href = diplomaHref(course.diploma);
        if (href) {
            var label = course.diploma_codigo ? 'Ver diploma · ' + course.diploma_codigo : 'Ver diploma';
            $card.append($('<a>', {class: 'cengi-directory-diploma-link', href: href, target: '_blank', rel: 'noopener'})
                .append($('<span>', {class: 'glyphicon glyphicon-file', 'aria-hidden': 'true'}))
                .append(document.createTextNode(' ' + label)));
        }
        return $card;
    }

    function renderProfile(data) {
        var participant = data.participante || {};
        var summary = data.resumen || {};
        var courses = data.cursos || [];
        $('#directory-profile-avatar').text(initials(participant.nombre_participantes));
        $('#directory-profile-name').text(participant.nombre_participantes || 'Ficha del participante');
        $('#directory-profile-subtitle').text((participant.nombre_ingenios || 'Sin ingenio') + ' · CUI ' + (participant.cui_participantes || '—'));
        $('#directory-profile-position').text(participant.puesto_participantes || '—');
        $('#directory-profile-area').text(participant.area_participantes || '—');
        $('#directory-profile-email').text(participant.correo_participantes || '—');
        $('#directory-profile-degree').text(participant.grado_academico_participantes || '—');
        $('#directory-profile-phone').text(participant.telefono_participantes || '—');
        $('#directory-stat-total').text(summary.total || 0);
        $('#directory-stat-completed').text(summary.completados || 0);
        $('#directory-stat-active').text(summary.activos || 0);
        $('#directory-stat-diplomas').text(summary.diplomas || 0);
        $courses.empty();
        if (!courses.length) {
            $courses.append($('<div>', {class: 'cengi-directory-no-courses'}).text('Este participante todavía no tiene cursos registrados.'));
        } else {
            courses.forEach(function (course) { $courses.append(renderCourse(course)); });
        }
        $loading.attr('hidden', true);
        $error.attr('hidden', true);
        $content.removeAttr('hidden');
    }

    $modal.on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var participantId = trigger ? trigger.getAttribute('data-participant-id') : '';
        if (request) request.abort();
        $('#directory-profile-avatar').text('P');
        $('#directory-profile-name').text('Ficha del participante');
        $('#directory-profile-subtitle').text('');
        $content.attr('hidden', true);
        $error.attr('hidden', true).text('');
        $loading.removeAttr('hidden');
        request = $.ajax({url: 'directorio_participantes.php', data: {ficha_id: participantId}, dataType: 'json', cache: false})
            .done(renderProfile).fail(function (xhr, status) {
                if (status === 'abort') return;
                var message = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'No fue posible cargar la ficha. Intenta nuevamente.';
                $loading.attr('hidden', true);
                $content.attr('hidden', true);
                $error.text(message).removeAttr('hidden');
            });
    });

    $modal.on('hidden.bs.modal', function () {
        if (request) request.abort();
        request = null;
    });
})(jQuery);
</script>
</body>
</html>
