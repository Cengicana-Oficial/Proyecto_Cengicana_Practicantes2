<?php
require_once "conexion.php";
require_once "menu.php";

cengi_require_ver_participantes();

$db = conectar();

$busqueda = trim((string) ($_GET['q'] ?? ''));
$ingenioFiltro = (int) ($_GET['ingenio_id'] ?? 0);
$cantidadFiltro = trim((string) ($_GET['cantidad'] ?? ''));

$condiciones = [];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ?)';
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
}

if ($ingenioFiltro > 0) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = $ingenioFiltro;
}

// Ojo: cengi_scope_sql() con su columna por defecto ('ingenio_id') debe usarse sobre el
// alias de "participantes" (p.ingenio_id existe); la tabla "ingenios" (alias "i") solo
// tiene "id", no "ingenio_id".
$scope = cengi_scope_sql('p', !empty($condiciones));
$where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

$sql = "
    SELECT
        p.id, p.nombre_participantes, p.cui_participantes, i.nombre_ingenios,
        COUNT(DISTINCT CASE WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN a.id END) AS cursos_completados,
        COUNT(DISTINCT CASE WHEN c.fin IS NULL OR c.fin >= CURDATE() THEN a.id END) AS cursos_activos,
        COUNT(DISTINCT a.id) AS total_cursos,
        AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS evaluacion_promedio,
        COUNT(DISTINCT d.id) AS diplomas,
        MAX(c.inicio) AS ultima_capacitacion
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    LEFT JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
    LEFT JOIN cursos c ON c.id = a.cursos_id
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
    {$where}{$scope}
    GROUP BY p.id, p.nombre_participantes, p.cui_participantes, i.nombre_ingenios
";

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

// KPIs (sobre el universo visible, sin filtros de busqueda/cantidad para que representen el total real)
$scopeKpi = cengi_scope_sql('p', true);
$kpi = $db->query("
    SELECT
        COUNT(DISTINCT p.id) AS personas,
        SUM(CASE WHEN t.total_cursos > 1 THEN 1 ELSE 0 END) AS con_mas_de_uno,
        COALESCE(SUM(t.diplomas), 0) AS diplomas_totales,
        COALESCE(AVG(t.total_cursos), 0) AS promedio_cursos
    FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    LEFT JOIN (
        SELECT
            a.participantes_id,
            COUNT(DISTINCT a.id) AS total_cursos,
            COUNT(DISTINCT d.id) AS diplomas
        FROM asignaciones a
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE a.estado_asignaciones = 1
        GROUP BY a.participantes_id
    ) t ON t.participantes_id = p.id
    WHERE 1=1 {$scopeKpi}
")->fetch(PDO::FETCH_ASSOC);

$ingenios = $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios")->fetchAll(PDO::FETCH_ASSOC);

function cengi_dir_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
<?php menu_render(); ?>
<div class="container">

    <div class="cengi-kpi-grid">
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-primary);"></div>
            <div class="cengi-kpi-val"><?php echo number_format((int) $kpi['personas']); ?></div>
            <div class="cengi-kpi-label">Personas registradas</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-secondary);"></div>
            <div class="cengi-kpi-val"><?php echo number_format((int) $kpi['con_mas_de_uno']); ?></div>
            <div class="cengi-kpi-label">Con mas de 1 curso</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-amarillo);"></div>
            <div class="cengi-kpi-val"><?php echo number_format((int) $kpi['diplomas_totales']); ?></div>
            <div class="cengi-kpi-label">Diplomas acumulados</div>
        </div>
        <div class="cengi-kpi">
            <div class="cengi-kpi-bar" style="background:var(--cengi-naranja);"></div>
            <div class="cengi-kpi-val"><?php echo number_format((float) $kpi['promedio_cursos'], 1); ?></div>
            <div class="cengi-kpi-label">Promedio de cursos por persona</div>
        </div>
    </div>

    <div class="panel panel-success">
        <div class="panel-heading"><h3 class="panel-title">Directorio de participantes</h3></div>
        <div class="panel-body">
            <form class="cengi-toolbar" method="GET">
                <div class="cengi-toolbar-filters">
                    <input type="text" name="q" class="form-control" placeholder="Buscar por nombre o CUI..." value="<?php echo cengi_dir_html($busqueda); ?>" style="min-width:220px;">
                    <select name="ingenio_id" class="form-control">
                        <option value="0">Todos los ingenios</option>
                        <?php foreach ($ingenios as $ing): ?>
                            <option value="<?php echo (int) $ing['id']; ?>" <?php echo $ingenioFiltro === (int) $ing['id'] ? 'selected' : ''; ?>><?php echo cengi_dir_html($ing['nombre_ingenios']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="cantidad" class="form-control">
                        <option value="">Cualquier cantidad de cursos</option>
                        <option value="1" <?php echo $cantidadFiltro === '1' ? 'selected' : ''; ?>>1 curso</option>
                        <option value="2-4" <?php echo $cantidadFiltro === '2-4' ? 'selected' : ''; ?>>2-4 cursos</option>
                        <option value="5+" <?php echo $cantidadFiltro === '5+' ? 'selected' : ''; ?>>5+ cursos</option>
                    </select>
                    <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-search"></span> Filtrar</button>
                </div>
            </form>

            <div class="cengi-table-wrap">
                <table class="table table-striped table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>Participante</th>
                            <th>CUI</th>
                            <th>Ingenio</th>
                            <th>Cursos completados</th>
                            <th>Cursos activos</th>
                            <th>Evaluacion prom.</th>
                            <th>Diplomas</th>
                            <th>Ultima capacitacion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$participantes): ?>
                            <tr><td colspan="8" class="text-center">No se encontraron participantes con esos filtros.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($participantes as $p): ?>
                            <tr>
                                <td>
                                    <div class="cengi-person-cell">
                                        <div class="cengi-person-avatar"><?php echo cengi_dir_html(mb_strtoupper(mb_substr($p['nombre_participantes'], 0, 1, 'UTF-8'), 'UTF-8')); ?></div>
                                        <strong><?php echo cengi_dir_html($p['nombre_participantes']); ?></strong>
                                    </div>
                                </td>
                                <td class="mono" style="font-family:'JetBrains Mono',monospace;"><?php echo cengi_dir_html($p['cui_participantes']); ?></td>
                                <td><?php echo cengi_dir_html($p['nombre_ingenios']); ?></td>
                                <td><?php echo (int) $p['cursos_completados']; ?></td>
                                <td><?php echo (int) $p['cursos_activos']; ?></td>
                                <td><?php echo $p['evaluacion_promedio'] !== null ? number_format((float) $p['evaluacion_promedio'], 1) . ' pts' : '—'; ?></td>
                                <td><?php echo (int) $p['diplomas']; ?></td>
                                <td><?php echo cengi_dir_html($p['ultima_capacitacion'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
