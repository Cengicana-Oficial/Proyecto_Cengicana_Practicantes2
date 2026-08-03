<?php
require_once __DIR__ . "/revisar_permisos.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/menu.php";

$db = conectar();

function cengi_dash_valor($db, $sql)
{
    try {
        $stmt = $db->query($sql);
        $fila = $stmt->fetch(PDO::FETCH_NUM);
        return $fila ? (int) $fila[0] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function cengi_dash_serie($db, $sql)
{
    try {
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_NUM) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

$scopeIngenios = function_exists('cengi_scope_sql_por_nombre_ingenio')
    ? static function ($alias, $alreadyWhere) {
        return cengi_scope_sql_por_nombre_ingenio($alias, $alreadyWhere);
    }
    : static function ($alias, $alreadyWhere) {
        return '';
    };

$cursosActivos = cengi_dash_valor($db, "
    SELECT COUNT(*)
    FROM cursos c
    INNER JOIN ingenios i ON i.id = c.ingenio_id
    WHERE (c.fin IS NULL OR c.fin >= CURDATE())
" . $scopeIngenios('i', true));

$cursosFinalizados = cengi_dash_valor($db, "
    SELECT COUNT(*)
    FROM cursos c
    INNER JOIN ingenios i ON i.id = c.ingenio_id
    WHERE c.fin IS NOT NULL AND c.fin < CURDATE()
" . $scopeIngenios('i', true));

$participantesCapacitados = cengi_dash_valor($db, "
    SELECT COUNT(DISTINCT p.id)
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    INNER JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
" . $scopeIngenios('i', false));

$ingeniosAtendidos = cengi_dash_valor($db, "
    SELECT COUNT(DISTINCT p.ingenio_id)
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    INNER JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
" . $scopeIngenios('i', false));

$totalIngenios = cengi_dash_valor($db, "SELECT COUNT(*) FROM ingenios");

$serieAnual = cengi_dash_serie($db, "
    SELECT YEAR(a.creado) AS anio, COUNT(DISTINCT a.participantes_id) AS total
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    WHERE a.creado IS NOT NULL
" . $scopeIngenios('i', true) . "
    GROUP BY YEAR(a.creado)
    ORDER BY anio
");
$anioLabels = array_map(static function ($fila) { return (string) $fila[0]; }, $serieAnual);
$anioValores = array_map(static function ($fila) { return (int) $fila[1]; }, $serieAnual);

$serieIngenio = cengi_dash_serie($db, "
    SELECT i.nombre_ingenios AS ingenio, COUNT(DISTINCT p.id) AS total
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    INNER JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
" . $scopeIngenios('i', false) . "
    GROUP BY i.nombre_ingenios
    ORDER BY total DESC
    LIMIT 6
");
$ingenioLabels = array_map(static function ($fila) { return (string) $fila[0]; }, $serieIngenio);
$ingenioValores = array_map(static function ($fila) { return (int) $fila[1]; }, $serieIngenio);

$serieCategoria = cengi_dash_serie($db, "
    SELECT ca.descripcion_categorias_cursos AS categoria, COUNT(*) AS total
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    INNER JOIN ingenios i ON i.id = c.ingenio_id
" . $scopeIngenios('i', false) . "
    GROUP BY ca.descripcion_categorias_cursos
    ORDER BY total DESC
");
$categoriaLabels = array_map(static function ($fila) { return (string) $fila[0]; }, $serieCategoria);
$categoriaValores = array_map(static function ($fila) { return (int) $fila[1]; }, $serieCategoria);
?>

<html lang="es">
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta charset="utf-8">
	<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
	<link rel="stylesheet" type="text/css" href="css/proyecto.css">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>

<body class="cengi-canvas">
	<?php menu_render(); ?>
	<div class="container">

		<div class="cengi-kpi-grid">
			<div class="cengi-kpi">
				<div class="cengi-kpi-bar" style="background:var(--cengi-primary);"></div>
				<div class="cengi-kpi-icon"><span class="glyphicon glyphicon-education"></span></div>
				<div class="cengi-kpi-val"><?php echo $cursosActivos; ?></div>
				<div class="cengi-kpi-label">Cursos activos</div>
			</div>
			<div class="cengi-kpi">
				<div class="cengi-kpi-bar" style="background:var(--cengi-border);"></div>
				<div class="cengi-kpi-icon" style="background:#EDEFEA;color:#5B6459;"><span class="glyphicon glyphicon-ok"></span></div>
				<div class="cengi-kpi-val"><?php echo $cursosFinalizados; ?></div>
				<div class="cengi-kpi-label">Cursos finalizados</div>
			</div>
			<div class="cengi-kpi">
				<div class="cengi-kpi-bar" style="background:var(--cengi-amarillo);"></div>
				<div class="cengi-kpi-icon" style="background:#FFF6DA;color:#8A6600;"><span class="glyphicon glyphicon-user"></span></div>
				<div class="cengi-kpi-val"><?php echo $participantesCapacitados; ?></div>
				<div class="cengi-kpi-label">Participantes capacitados</div>
			</div>
			<div class="cengi-kpi">
				<div class="cengi-kpi-bar" style="background:var(--cengi-naranja);"></div>
				<div class="cengi-kpi-icon" style="background:#FFE9D9;color:#B34E00;"><span class="glyphicon glyphicon-tree-deciduous"></span></div>
				<div class="cengi-kpi-val"><?php echo $ingeniosAtendidos; ?></div>
				<div class="cengi-kpi-label">Ingenios atendidos <?php echo $totalIngenios ? 'de ' . $totalIngenios . ' afiliados' : ''; ?></div>
			</div>
		</div>

		<div class="cengi-charts-row">
			<div class="panel panel-success">
				<div class="panel-heading"><h3 class="panel-title">Participantes capacitados por año</h3></div>
				<div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartAnual"></canvas></div></div>
			</div>
			<div class="panel panel-success">
				<div class="panel-heading"><h3 class="panel-title">Distribución por ingenio</h3></div>
				<div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartIngenio"></canvas></div></div>
			</div>
		</div>

		<div class="panel panel-success">
			<div class="panel-heading"><h3 class="panel-title">Cursos por categoría</h3></div>
			<div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartCategoria"></canvas></div></div>
		</div>

	</div>

	<script>
	Chart.defaults.font.family = "'Inter',sans-serif";
	Chart.defaults.font.size = 11;
	Chart.defaults.color = "#55705f";

	var anioLabels = <?php echo json_encode($anioLabels); ?>;
	var anioValores = <?php echo json_encode($anioValores); ?>;
	var ingenioLabels = <?php echo json_encode($ingenioLabels); ?>;
	var ingenioValores = <?php echo json_encode($ingenioValores); ?>;
	var categoriaLabels = <?php echo json_encode($categoriaLabels); ?>;
	var categoriaValores = <?php echo json_encode($categoriaValores); ?>;

	new Chart(document.getElementById('chartAnual'), {
		type: 'bar',
		data: { labels: anioLabels, datasets: [{ label: 'Participantes', data: anioValores, backgroundColor: '#73BC25', borderRadius: 5, maxBarThickness: 40 }] },
		options: { plugins: { legend: { display: false } }, scales: { y: { grid: { color: '#EEF0EA' }, beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }, maintainAspectRatio: false }
	});

	new Chart(document.getElementById('chartIngenio'), {
		type: 'doughnut',
		data: { labels: ingenioLabels, datasets: [{ data: ingenioValores, backgroundColor: ['#73BC25', '#A3D300', '#FFCC00', '#FF6B00', '#CED2D5', '#5e9b1d'], borderWidth: 0 }] },
		options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 9, padding: 12 } } }, maintainAspectRatio: false, cutout: '62%' }
	});

	new Chart(document.getElementById('chartCategoria'), {
		type: 'bar',
		data: { labels: categoriaLabels, datasets: [{ data: categoriaValores, backgroundColor: '#A3D300', borderRadius: 5, maxBarThickness: 28 }] },
		options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#EEF0EA' }, beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } }, maintainAspectRatio: false }
	});
	</script>
</body>
</html>
