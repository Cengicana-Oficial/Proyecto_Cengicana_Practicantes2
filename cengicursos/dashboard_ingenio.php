<?php
require_once "conexion.php";
require_once "menu.php";

// Dashboard exclusivo para el rol "Administrador de ingenio" (y admins, que ven
// todo por cortesia). Un usuario de ingenio SOLO puede ver la info de su propio
// ingenio_id de sesion: nunca se confia en el parametro de query string para
// eso, solo se usa para que un admin elija que ingenio revisar.
cengi_require_dashboard_ingenio('index.php');

$esAdmin = cengi_ve_todo_por_rol_o_ingenio();
$ingenioUsuarioId = cengi_ingenio_id_actual();

$db = conectar();

$ingenios = $esAdmin
    ? $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$ingenioId = (int) ($_GET['ingenio_id'] ?? 0);
if (!$esAdmin) {
    // Un usuario de ingenio solo puede ver su propio ingenio, sin importar el parametro recibido.
    $ingenioId = $ingenioUsuarioId;
} elseif ($ingenioId <= 0) {
    if ($ingenios) {
        $ingenioId = (int) $ingenios[0]['id'];
    }
}

$ingenioActual = null;
if ($esAdmin) {
    foreach ($ingenios as $ing) {
        if ((int) $ing['id'] === $ingenioId) {
            $ingenioActual = $ing;
            break;
        }
    }
} elseif ($ingenioId > 0) {
    $stmtIng = $db->prepare("SELECT id, nombre_ingenios FROM ingenios WHERE id = ? LIMIT 1");
    $stmtIng->execute([$ingenioId]);
    $ingenioActual = $stmtIng->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cengi_dbi_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_dbi_iniciales($nombre)
{
    $partes = preg_split('/\s+/', trim((string) $nombre));
    $iniciales = '';
    foreach (array_slice(array_filter($partes), 0, 2) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $iniciales !== '' ? $iniciales : 'P';
}

function cengi_dbi_fecha($valor)
{
    $valor = trim((string) ($valor ?? ''));
    if ($valor === '' || $valor === '0000-00-00') {
        return '—';
    }
    $fecha = strtotime($valor);
    return $fecha ? date('d/m/Y', $fecha) : '—';
}

function cengi_dbi_estado_curso($inicio, $fin)
{
    $hoy = date('Y-m-d');
    if ($fin && $fin !== '0000-00-00' && $fin < $hoy) {
        return ['Finalizado', 'is-finished'];
    }
    if ($inicio && $inicio !== '0000-00-00' && $inicio > $hoy) {
        return ['Planificación', 'is-upcoming'];
    }
    return ['Activo', 'is-active'];
}

// ---------------------------------------------------------------------
// Ficha AJAX: historial de un participante (misma logica que
// directorio_participantes.php, pero siempre acotada al $ingenioId ya
// resuelto arriba, que para un usuario de ingenio nunca puede ser distinto
// al de su propia sesion).
// ---------------------------------------------------------------------
$fichaId = (int) ($_GET['ficha_id'] ?? 0);
if ($fichaId > 0) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if ($ingenioId <= 0) {
        http_response_code(404);
        echo json_encode(['error' => 'No hay un ingenio disponible para esta cuenta.']);
        exit;
    }

    $stmtFicha = $db->prepare("SELECT p.id, p.nombre_participantes, p.cui_participantes,
            p.puesto_participantes, p.area_participantes, p.correo_participantes,
            p.grado_academico_participantes, p.telefono_participantes,
            p.estado_participantes, i.nombre_ingenios
        FROM participantes p
        INNER JOIN ingenios i ON i.id = p.ingenio_id
        WHERE p.id = ? AND p.ingenio_id = ? LIMIT 1");
    $stmtFicha->execute([$fichaId, $ingenioId]);
    $ficha = $stmtFicha->fetch(PDO::FETCH_ASSOC);

    if (!$ficha) {
        http_response_code(404);
        echo json_encode(['error' => 'El participante no existe o no pertenece a este ingenio.']);
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
    foreach ($cursosFicha as $cursoFicha) {
        if ($cursoFicha['estado_curso'] === 'finalizado') {
            $resumen['completados']++;
        } elseif ($cursoFicha['estado_curso'] === 'activo') {
            $resumen['activos']++;
        }
        if (!empty($cursoFicha['diploma'])) {
            $resumen['diplomas']++;
        }
    }

    echo json_encode(['participante' => $ficha, 'resumen' => $resumen, 'cursos' => $cursosFicha], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------
// KPIs, graficas, cursos y participantes del ingenio resuelto.
// ---------------------------------------------------------------------
$busqueda = trim((string) ($_GET['q'] ?? ''));

$kpi = [
    'participantes_activos' => 0,
    'cursos_en_curso' => 0,
    'cursos_completados' => 0,
    'asistencia_prom' => null,
    'diplomas' => 0,
];
$cursosIngenio = [];
$participantesIngenio = [];
$serieAnual = ['labels' => [], 'valores' => []];
$serieCategoria = ['labels' => [], 'valores' => []];

if ($ingenioId > 0) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM participantes WHERE ingenio_id = ? AND estado_participantes = 1");
    $stmt->execute([$ingenioId]);
    $kpi['participantes_activos'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT c.id)
        FROM cursos c
        INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        WHERE (c.inicio IS NULL OR c.inicio <= CURDATE()) AND (c.fin IS NULL OR c.fin >= CURDATE())
    ");
    $stmt->execute([$ingenioId]);
    $kpi['cursos_en_curso'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT c.id)
        FROM cursos c
        INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        WHERE c.fin IS NOT NULL AND c.fin < CURDATE()
    ");
    $stmt->execute([$ingenioId]);
    $kpi['cursos_completados'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT AVG(CASE WHEN cc.asistencia REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) AS asistencia_prom
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE p.ingenio_id = ?
    ");
    $stmt->execute([$ingenioId]);
    $asistenciaFila = $stmt->fetch(PDO::FETCH_ASSOC);
    $kpi['asistencia_prom'] = $asistenciaFila['asistencia_prom'] !== null ? (float) $asistenciaFila['asistencia_prom'] : null;

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT d.id)
        FROM diplomas d
        INNER JOIN asignaciones a ON a.id = d.asignacion_id
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        WHERE d.tipo = 'curso'
    ");
    $stmt->execute([$ingenioId]);
    $kpi['diplomas'] = (int) $stmt->fetchColumn();

    $stmtAnual = $db->prepare("
        SELECT YEAR(a.creado) AS anio, COUNT(DISTINCT a.participantes_id) AS total
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        WHERE p.ingenio_id = ? AND a.creado IS NOT NULL
        GROUP BY YEAR(a.creado)
        ORDER BY anio
    ");
    $stmtAnual->execute([$ingenioId]);
    foreach ($stmtAnual->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $serieAnual['labels'][] = (string) $r['anio'];
        $serieAnual['valores'][] = (int) $r['total'];
    }

    $stmtCategoria = $db->prepare("
        SELECT ca.descripcion_categorias_cursos AS categoria, COUNT(DISTINCT c.id) AS total
        FROM cursos c
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        INNER JOIN asignaciones a ON a.cursos_id = c.id
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        GROUP BY ca.descripcion_categorias_cursos
        ORDER BY total DESC
    ");
    $stmtCategoria->execute([$ingenioId]);
    foreach ($stmtCategoria->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $serieCategoria['labels'][] = (string) $r['categoria'];
        $serieCategoria['valores'][] = (int) $r['total'];
    }

    $stmtCursos = $db->prepare("
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
    $stmtCursos->execute([$ingenioId]);
    $cursosIngenio = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);

    $condicionesPart = ['p.ingenio_id = ?'];
    $paramsPart = [$ingenioId];
    if ($busqueda !== '') {
        $condicionesPart[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR p.area_participantes LIKE ? OR p.correo_participantes LIKE ? OR p.grado_academico_participantes LIKE ? OR p.telefono_participantes LIKE ?)';
        $termino = '%' . $busqueda . '%';
        array_push($paramsPart, $termino, $termino, $termino, $termino, $termino, $termino);
    }
    $stmtPart = $db->prepare("
        SELECT p.id, p.nombre_participantes, p.cui_participantes,
            p.puesto_participantes, p.area_participantes, p.correo_participantes,
            p.grado_academico_participantes, p.telefono_participantes, p.estado_participantes,
            COUNT(DISTINCT CASE WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN a.id END) AS cursos_completados,
            COUNT(DISTINCT CASE WHEN c.fin IS NULL OR c.fin >= CURDATE() THEN a.id END) AS cursos_activos,
            COUNT(DISTINCT a.id) AS total_cursos,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS evaluacion_promedio,
            COUNT(DISTINCT d.id) AS diplomas,
            MAX(c.inicio) AS ultima_capacitacion
        FROM participantes p
        LEFT JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
        LEFT JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE " . implode(' AND ', $condicionesPart) . "
        GROUP BY p.id, p.nombre_participantes, p.cui_participantes, p.puesto_participantes, p.area_participantes,
            p.correo_participantes, p.grado_academico_participantes, p.telefono_participantes, p.estado_participantes
        ORDER BY p.nombre_participantes
    ");
    $stmtPart->execute($paramsPart);
    $participantesIngenio = $stmtPart->fetchAll(PDO::FETCH_ASSOC);
}

$parametrosExportParticipantes = ['ingenio_id' => $ingenioId, 'vista' => 'participantes', 'q' => $busqueda];
$urlExportParticipantes = 'exportardashboardingenio.php?' . http_build_query($parametrosExportParticipantes);
$parametrosExportCursos = ['ingenio_id' => $ingenioId, 'vista' => 'cursos'];
$urlExportCursos = 'exportardashboardingenio.php?' . http_build_query($parametrosExportCursos);
?>
<!DOCTYPE html>
<html lang="es">
<?php include('head.php'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<body class="cengi-canvas cengi-directory-page">
<?php menu_render(); ?>
<main class="container">

    <div class="panel panel-success">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 class="panel-title"><?php echo cengi_dbi_html($ingenioActual['nombre_ingenios'] ?? 'Sin ingenio asignado'); ?></h3>
                <small>Panel de tu ingenio: participantes, cursos e historial de capacitación</small>
            </div>
            <?php if ($esAdmin && $ingenios): ?>
                <form method="GET">
                    <select name="ingenio_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($ingenios as $ing): ?>
                            <option value="<?php echo (int) $ing['id']; ?>" <?php echo $ingenioId === (int) $ing['id'] ? 'selected' : ''; ?>><?php echo cengi_dbi_html($ing['nombre_ingenios']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ingenioId <= 0): ?>
        <div class="alert alert-warning" role="alert">Tu cuenta todavía no tiene un ingenio asignado. Contacta a un administrador para poder ver esta información.</div>
    <?php else: ?>

    <div class="cengi-kpi-grid">
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo number_format($kpi['participantes_activos']); ?></div>
            <div class="cengi-kpi-label">Participantes activos</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo number_format($kpi['cursos_en_curso']); ?></div>
            <div class="cengi-kpi-label">Cursos en curso</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo number_format($kpi['cursos_completados']); ?></div>
            <div class="cengi-kpi-label">Cursos completados</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo $kpi['asistencia_prom'] !== null ? number_format($kpi['asistencia_prom'], 0) . '%' : '—'; ?></div>
            <div class="cengi-kpi-label">Asistencia promedio</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo number_format($kpi['diplomas']); ?></div>
            <div class="cengi-kpi-label">Diplomas emitidos</div>
        </div>
    </div>

    <div class="cengi-charts-row">
        <div class="panel panel-success">
            <div class="panel-heading"><h3 class="panel-title">Participantes capacitados por año</h3></div>
            <div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartIngenioAnual"></canvas></div></div>
        </div>
        <div class="panel panel-success">
            <div class="panel-heading"><h3 class="panel-title">Cursos por categoría</h3></div>
            <div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartIngenioCategoria"></canvas></div></div>
        </div>
    </div>

    <div class="panel panel-success">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 class="panel-title">Cursos de tu ingenio</h3>
                <small>Cursos en los que tienen participantes inscritos</small>
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" <?php echo $cursosIngenio ? '' : 'disabled'; ?>>
                    <span class="glyphicon glyphicon-download-alt"></span> Descargar listado <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li><a href="<?php echo cengi_dbi_html($urlExportCursos . '&format=pdf'); ?>"><span class="glyphicon glyphicon-file"></span> Descargar PDF</a></li>
                    <li><a href="<?php echo cengi_dbi_html($urlExportCursos . '&format=excel'); ?>"><span class="glyphicon glyphicon-list-alt"></span> Descargar Excel</a></li>
                </ul>
            </div>
        </div>
        <div class="cengi-table-wrap">
            <table class="table table-striped table-bordered table-hover">
                <thead>
                    <tr><th>Código</th><th>Curso</th><th>Categoría</th><th>Modalidad</th><th>Inicio</th><th>Fin</th><th>Inscritos</th><th>Asistencia</th><th>Evaluación final</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php if (!$cursosIngenio): ?>
                        <tr><td colspan="10" class="text-center">Tu ingenio todavía no tiene cursos con participantes inscritos.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($cursosIngenio as $c):
                        [$estadoLabel, $estadoClase] = cengi_dbi_estado_curso($c['inicio'], $c['fin']);
                    ?>
                        <tr>
                            <td><?php echo cengi_dbi_html($c['codigo_curso'] ?: ('CEN-' . str_pad((string) $c['id'], 3, '0', STR_PAD_LEFT))); ?></td>
                            <td><strong><?php echo cengi_dbi_html($c['nombre_cursos']); ?></strong></td>
                            <td><?php echo cengi_dbi_html($c['categoria']); ?></td>
                            <td><?php echo cengi_dbi_html($c['modalidad'] ?: 'Sin definir'); ?></td>
                            <td><?php echo cengi_dbi_html(cengi_dbi_fecha($c['inicio'])); ?></td>
                            <td><?php echo cengi_dbi_html(cengi_dbi_fecha($c['fin'])); ?></td>
                            <td><?php echo (int) $c['inscritos']; ?></td>
                            <td><?php echo $c['asistencia_prom'] !== null ? number_format((float) $c['asistencia_prom'], 0) . '%' : '—'; ?></td>
                            <td><?php echo $c['eval_prom'] !== null ? number_format((float) $c['eval_prom'], 0) . ' pts' : '—'; ?></td>
                            <td><span class="cengi-status-badge <?php echo $estadoClase; ?>"><i></i><?php echo $estadoLabel; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <section class="cengi-directory-filter-card">
        <form class="cengi-directory-filters" method="get" id="dashboard-ingenio-filters">
            <?php if ($esAdmin): ?><input type="hidden" name="ingenio_id" value="<?php echo (int) $ingenioId; ?>"><?php endif; ?>
            <label class="cengi-directory-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span><span class="sr-only">Buscar por nombre, CUI o área</span>
                <input type="search" name="q" placeholder="Buscar participante por nombre, CUI o área..." value="<?php echo cengi_dbi_html($busqueda); ?>">
            </label>
            <button type="submit" class="btn btn-default"><span class="glyphicon glyphicon-filter"></span> Filtrar</button>
            <?php if ($busqueda !== ''): ?>
                <a class="cengi-directory-clear" href="dashboard_ingenio.php<?php echo $esAdmin ? '?ingenio_id=' . (int) $ingenioId : ''; ?>">Limpiar</a>
            <?php endif; ?>
            <div class="btn-group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" <?php echo $participantesIngenio ? '' : 'disabled'; ?>>
                    <span class="glyphicon glyphicon-download-alt"></span> Descargar listado <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li><a href="<?php echo cengi_dbi_html($urlExportParticipantes . '&format=pdf'); ?>"><span class="glyphicon glyphicon-file"></span> Descargar PDF</a></li>
                    <li><a href="<?php echo cengi_dbi_html($urlExportParticipantes . '&format=excel'); ?>"><span class="glyphicon glyphicon-list-alt"></span> Descargar Excel</a></li>
                </ul>
            </div>
        </form>
    </section>

    <section class="cengi-directory-table-card">
        <div class="cengi-directory-table-scroll" tabindex="0" aria-label="Participantes de tu ingenio">
            <table class="cengi-directory-table">
                <thead><tr><th>Participante</th><th>CUI</th><th>Cursos completados</th><th>Cursos activos</th><th>Evaluación prom.</th><th>Diplomas</th><th>Última capacitación</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                <?php if (!$participantesIngenio): ?>
                    <tr><td colspan="8" class="cengi-directory-empty"><span class="glyphicon glyphicon-user"></span><strong>No se encontraron participantes</strong><small>Modifica la búsqueda para ampliar los resultados.</small></td></tr>
                <?php endif; ?>
                <?php foreach ($participantesIngenio as $participante): ?>
                    <tr>
                        <td><div class="cengi-directory-person"><span class="cengi-directory-avatar"><?php echo cengi_dbi_html(cengi_dbi_iniciales($participante['nombre_participantes'])); ?></span><span><strong><?php echo cengi_dbi_html($participante['nombre_participantes']); ?></strong><small><?php echo cengi_dbi_html($participante['puesto_participantes']); ?></small></span></div></td>
                        <td class="cengi-directory-cui"><?php echo cengi_dbi_html($participante['cui_participantes']); ?></td>
                        <td><?php echo (int) $participante['cursos_completados']; ?></td>
                        <td><?php echo (int) $participante['cursos_activos']; ?></td>
                        <td><?php echo $participante['evaluacion_promedio'] !== null ? number_format((float) $participante['evaluacion_promedio'], 1) . ' pts' : '—'; ?></td>
                        <td><?php echo (int) $participante['diplomas']; ?></td>
                        <td><?php echo cengi_dbi_html(cengi_dbi_fecha($participante['ultima_capacitacion'])); ?></td>
                        <td><button type="button" class="cengi-directory-profile-button" data-toggle="modal" data-target="#dashboard-ingenio-profile-modal" data-participant-id="<?php echo (int) $participante['id']; ?>">Ver ficha</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="cengi-directory-table-footer"><?php echo number_format(count($participantesIngenio)); ?> resultado<?php echo count($participantesIngenio) === 1 ? '' : 's'; ?></footer>
    </section>

    <?php endif; ?>
</main>

<div class="modal fade cengi-directory-modal" id="dashboard-ingenio-profile-modal" tabindex="-1" role="dialog" aria-labelledby="dashboard-ingenio-profile-name">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <header class="cengi-directory-profile-head">
            <span class="cengi-directory-profile-avatar" id="dashboard-ingenio-profile-avatar">P</span>
            <div><h2 id="dashboard-ingenio-profile-name">Ficha del participante</h2><p id="dashboard-ingenio-profile-subtitle"></p></div>
            <button type="button" class="cengi-directory-modal-close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">×</span></button>
        </header>
        <div class="modal-body">
            <div class="cengi-directory-modal-loading" id="dashboard-ingenio-profile-loading"><span class="glyphicon glyphicon-refresh"></span> Cargando ficha…</div>
            <div id="dashboard-ingenio-profile-content" hidden>
                <div class="cengi-directory-profile-stats">
                    <div><strong id="dashboard-ingenio-stat-total">0</strong><span>Cursos registrados</span></div>
                    <div><strong id="dashboard-ingenio-stat-completed">0</strong><span>Completados</span></div>
                    <div><strong id="dashboard-ingenio-stat-active">0</strong><span>Activos</span></div>
                    <div><strong id="dashboard-ingenio-stat-diplomas">0</strong><span>Diplomas</span></div>
                </div>
                <div class="cengi-directory-profile-details"><div><span>Puesto</span><strong id="dashboard-ingenio-profile-position">—</strong></div><div><span>Área</span><strong id="dashboard-ingenio-profile-area">—</strong></div><div><span>Correo electrónico</span><strong id="dashboard-ingenio-profile-email">—</strong></div><div><span>Grado académico</span><strong id="dashboard-ingenio-profile-degree">—</strong></div><div><span>Teléfono</span><strong id="dashboard-ingenio-profile-phone">—</strong></div></div>
                <h3 class="cengi-directory-history-title">Historial de capacitación</h3>
                <div class="cengi-directory-course-list" id="dashboard-ingenio-profile-courses"></div>
            </div>
            <div class="cengi-directory-modal-error" id="dashboard-ingenio-profile-error" hidden></div>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button></footer>
    </div></div>
</div>

<script>
Chart.defaults.font.family = "'Inter',sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = "#55705f";

<?php if ($ingenioId > 0): ?>
new Chart(document.getElementById('chartIngenioAnual'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($serieAnual['labels']); ?>,
        datasets: [{ label: 'Participantes', data: <?php echo json_encode($serieAnual['valores']); ?>, backgroundColor: '#73BC25', borderRadius: 5, maxBarThickness: 40 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, maintainAspectRatio: false }
});

new Chart(document.getElementById('chartIngenioCategoria'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($serieCategoria['labels']); ?>,
        datasets: [{ data: <?php echo json_encode($serieCategoria['valores']); ?>, backgroundColor: ['#73BC25', '#A3D300', '#FFCC00', '#FF6B00', '#CED2D5', '#5e9b1d'], borderWidth: 0 }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 9, padding: 12 } } }, maintainAspectRatio: false, cutout: '62%' }
});
<?php endif; ?>

(function ($) {
    'use strict';
    var request = null;
    var currentIngenioId = <?php echo (int) $ingenioId; ?>;
    var $modal = $('#dashboard-ingenio-profile-modal');
    var $loading = $('#dashboard-ingenio-profile-loading');
    var $content = $('#dashboard-ingenio-profile-content');
    var $error = $('#dashboard-ingenio-profile-error');
    var $courses = $('#dashboard-ingenio-profile-courses');

    $('#dashboard-ingenio-filters').find('select').on('change', function () {
        this.form.submit();
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
        $('#dashboard-ingenio-profile-avatar').text(initials(participant.nombre_participantes));
        $('#dashboard-ingenio-profile-name').text(participant.nombre_participantes || 'Ficha del participante');
        $('#dashboard-ingenio-profile-subtitle').text((participant.nombre_ingenios || 'Sin ingenio') + ' · CUI ' + (participant.cui_participantes || '—'));
        $('#dashboard-ingenio-profile-position').text(participant.puesto_participantes || '—');
        $('#dashboard-ingenio-profile-area').text(participant.area_participantes || '—');
        $('#dashboard-ingenio-profile-email').text(participant.correo_participantes || '—');
        $('#dashboard-ingenio-profile-degree').text(participant.grado_academico_participantes || '—');
        $('#dashboard-ingenio-profile-phone').text(participant.telefono_participantes || '—');
        $('#dashboard-ingenio-stat-total').text(summary.total || 0);
        $('#dashboard-ingenio-stat-completed').text(summary.completados || 0);
        $('#dashboard-ingenio-stat-active').text(summary.activos || 0);
        $('#dashboard-ingenio-stat-diplomas').text(summary.diplomas || 0);
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
        $('#dashboard-ingenio-profile-avatar').text('P');
        $('#dashboard-ingenio-profile-name').text('Ficha del participante');
        $('#dashboard-ingenio-profile-subtitle').text('');
        $content.attr('hidden', true);
        $error.attr('hidden', true).text('');
        $loading.removeAttr('hidden');
        request = $.ajax({url: 'dashboard_ingenio.php', data: {ficha_id: participantId, ingenio_id: currentIngenioId}, dataType: 'json', cache: false})
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
