<?php
require_once "conexion.php";
require_once "menu.php";

// Cualquier usuario con acceso al modulo puede ver esta ficha, pero el scoping
// por ingenio se aplica siempre igual que en el resto de cengicursos.
$esAdmin = cengi_ve_todo_por_rol_o_ingenio();
$ingenioUsuarioId = cengi_ingenio_id_actual();

$db = conectar();
$ingenios = $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios")->fetchAll(PDO::FETCH_ASSOC);

$ingenioId = (int) ($_GET['ingenio_id'] ?? 0);

if (!$esAdmin) {
    // Un usuario no admin solo puede ver su propio ingenio, sin importar el parametro recibido.
    $ingenioId = $ingenioUsuarioId;
} elseif ($ingenioId <= 0 && $ingenios) {
    $ingenioId = (int) $ingenios[0]['id'];
}

$ingenioActual = null;
foreach ($ingenios as $ing) {
    if ((int) $ing['id'] === $ingenioId) {
        $ingenioActual = $ing;
        break;
    }
}

function cengi_seg_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

$kpi = ['colaboradores' => 0, 'cursos_con_inscritos' => 0, 'asistencia_prom' => null, 'brecha' => null];
$cursosIngenio = [];
$serieAnual = ['labels' => [], 'valores' => []];
$serieCategoria = ['labels' => [], 'valores' => []];

if ($ingenioId > 0) {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT p.id) AS total
        FROM participantes p
        INNER JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
        WHERE p.ingenio_id = ?
    ");
    $stmt->execute([$ingenioId]);
    $kpi['colaboradores'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT c.id) AS total
        FROM cursos c
        INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        WHERE c.ingenio_id = ? OR p.ingenio_id = ?
    ");
    $stmt->execute([$ingenioId, $ingenioId, $ingenioId]);
    $kpi['cursos_con_inscritos'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT
            AVG(CASE WHEN cc.asistencia REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) AS asistencia_prom,
            AVG(CASE WHEN cc.evaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.evaluacion AS DECIMAL(6,2)) END) AS pre_prom,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS post_prom
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE p.ingenio_id = ?
    ");
    $stmt->execute([$ingenioId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    $kpi['asistencia_prom'] = $fila['asistencia_prom'] !== null ? (float) $fila['asistencia_prom'] : null;
    if ($fila['pre_prom'] !== null && $fila['post_prom'] !== null) {
        $kpi['brecha'] = (float) $fila['post_prom'] - (float) $fila['pre_prom'];
    }

    $stmtCursos = $db->prepare("
        SELECT
            c.id, c.nombre_cursos, c.inicio, c.fin,
            COUNT(DISTINCT a.id) AS inscritos,
            AVG(CASE WHEN cc.asistencia REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) AS asistencia_prom,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS eval_prom
        FROM cursos c
        INNER JOIN asignaciones a ON a.cursos_id = c.id
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        GROUP BY c.id, c.nombre_cursos, c.inicio, c.fin
        ORDER BY c.inicio DESC
    ");
    $stmtCursos->execute([$ingenioId]);
    $cursosIngenio = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);

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
}
?>
<html lang="es">
<?php include('head.php'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<body class="cengi-canvas">
<?php menu_render(); ?>
<div class="container">

    <div class="panel panel-success">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 class="panel-title"><?php echo cengi_seg_html($ingenioActual['nombre_ingenios'] ?? 'Sin ingenio seleccionado'); ?></h3>
                <small>Ficha de seguimiento institucional de capacitacion</small>
            </div>
            <?php if ($esAdmin): ?>
                <form method="GET">
                    <select name="ingenio_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($ingenios as $ing): ?>
                            <option value="<?php echo (int) $ing['id']; ?>" <?php echo $ingenioId === (int) $ing['id'] ? 'selected' : ''; ?>><?php echo cengi_seg_html($ing['nombre_ingenios']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="cengi-kpi-grid">
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo number_format($kpi['colaboradores']); ?></div>
            <div class="cengi-kpi-label">Colaboradores capacitados</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo number_format($kpi['cursos_con_inscritos']); ?></div>
            <div class="cengi-kpi-label">Cursos con inscritos</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-val"><?php echo $kpi['asistencia_prom'] !== null ? number_format($kpi['asistencia_prom'], 0) . '%' : '—'; ?></div>
            <div class="cengi-kpi-label">Asistencia promedio</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-val" style="color:var(--cengi-primary-deep);"><?php echo $kpi['brecha'] !== null ? ($kpi['brecha'] >= 0 ? '+' : '') . number_format($kpi['brecha'], 0) . ' pts' : '—'; ?></div>
            <div class="cengi-kpi-label">Brecha de aprendizaje (post - pre)</div>
        </div>
    </div>

    <div class="cengi-charts-row">
        <div class="panel panel-success">
            <div class="panel-heading"><h3 class="panel-title">Participantes capacitados por anio</h3></div>
            <div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartIngenioAnual"></canvas></div></div>
        </div>
        <div class="panel panel-success">
            <div class="panel-heading"><h3 class="panel-title">Cursos por categoria</h3></div>
            <div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartIngenioCategoria"></canvas></div></div>
        </div>
    </div>

    <div class="panel panel-success">
        <div class="panel-heading"><h3 class="panel-title">Cursos de este ingenio</h3><small>Estado y avance por curso</small></div>
        <div class="cengi-table-wrap">
            <table class="table table-striped table-bordered table-hover">
                <thead>
                    <tr><th>Curso</th><th>Colaboradores inscritos</th><th>Asistencia</th><th>Evaluacion final prom.</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php if (!$cursosIngenio): ?>
                        <tr><td colspan="5" class="text-center">Sin cursos con inscritos de este ingenio todavia.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($cursosIngenio as $c):
                        $hoy = date('Y-m-d');
                        if ($c['fin'] && $c['fin'] < $hoy) {
                            $estadoLabel = 'Finalizado'; $estadoClase = 'is-finished';
                        } elseif ($c['inicio'] && $c['inicio'] > $hoy) {
                            $estadoLabel = 'Planificacion'; $estadoClase = 'is-upcoming';
                        } else {
                            $estadoLabel = 'Activo'; $estadoClase = 'is-active';
                        }
                    ?>
                        <tr>
                            <td><strong><?php echo cengi_seg_html($c['nombre_cursos']); ?></strong></td>
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
</div>

<script>
Chart.defaults.font.family = "'Inter',sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = "#55705f";

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
</script>
</body>
</html>
