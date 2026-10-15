<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!can_access('ver_reportes')) { http_response_code(403); exit('Acceso no permitido.'); }
require_once __DIR__ . '/../../config/presentation.php';
header('Cache-Control: no-store, private');
$conn = Conexion::conectar();
$month = (string) ($_GET['mes'] ?? date('Y-m'));
$type = (string) ($_GET['tipo'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || !in_array($type, ['', 'cengicana', 'museo_azucar', 'ambos'], true)) { http_response_code(422); exit('Filtro no válido.'); }
$start = $month . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));
$query = $conn->prepare("SELECT s.fecha_visita,s.tipo_visita,s.cantidad_visitantes,so.nombre_institucion FROM solicitudes s JOIN solicitantes so ON so.id_solicitante=s.id_solicitante LEFT JOIN solicitudes_ocultas o ON o.id_solicitud=s.id_solicitud WHERE o.id_solicitud IS NULL AND s.fecha_visita>=? AND s.fecha_visita<?" . ($type !== '' ? ' AND s.tipo_visita=?' : '') . ' ORDER BY s.fecha_visita');
$query->execute($type === '' ? [$start,$end] : [$start,$end,$type]);
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="visitas-' . $month . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha','Tipo de visita','Visitantes','Institución']);
    foreach ($rows as $row) { foreach ($row as &$value) { if (preg_match('/^[\s]*[=+@-]/u', (string) $value)) $value = "'" . $value; } unset($value); fputcsv($out, array_values($row)); }
    fclose($out); exit;
}
$visitors = 0; $institutions = []; $days = [];
foreach ($rows as $row) {
    $count = (int) $row['cantidad_visitantes']; $visitors += $count;
    $name = trim((string) $row['nombre_institucion']) ?: 'Sin institución';
    $institutions[$name] ??= ['solicitudes'=>0,'visitantes'=>0];
    $institutions[$name]['solicitudes']++; $institutions[$name]['visitantes'] += $count;
    $day = substr($row['fecha_visita'], 0, 10); $days[$day] = ($days[$day] ?? 0) + $count;
}
uasort($institutions, fn($a,$b) => $b['visitantes'] <=> $a['visitantes']);
$max = max([1, ...array_values($days)]);
$types = [''=>'Todos','cengicana'=>'CENGICAÑA','museo_azucar'=>'Museo del Azúcar','ambos'=>'Ambos'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reportes de visitas | CENGICAÑA</title>
<link rel="stylesheet" href="../../assets/dashboard.css"><link rel="stylesheet" href="../../assets/reports.css?v=20261008"><?php visitas_ui_head(); ?></head>
<body class="visits-admin"><?php visitas_ui_shell('Reportes de visitas', 'Resumen de solicitudes e instituciones', 'reportes'); ?>
<div class="layout"><main class="main-content" id="visits-content" tabindex="-1">
<header class="dashboard-header"><h1>Reportes de visitas</h1><p>Consulta las solicitudes y los visitantes previstos por mes.</p></header>
<form method="get" class="visits-report-filters"><label>Mes<input type="month" name="mes" value="<?= visitas_ui_escape($month) ?>" required></label><label>Tipo de visita<select name="tipo"><?php foreach ($types as $key=>$label): ?><option value="<?= $key ?>" <?= $type === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><button type="submit">Aplicar filtros</button><a class="report-export" href="?<?= visitas_ui_escape(http_build_query(['mes'=>$month,'tipo'=>$type,'export'=>'csv'])) ?>">Descargar CSV</a></form>
<section class="visits-report-metrics" aria-label="Indicadores del mes"><?php foreach (['Solicitudes'=>count($rows),'Visitantes'=>$visitors,'Instituciones'=>count($institutions),'Visitantes por solicitud'=>count($rows) ? round($visitors/count($rows),1) : 0] as $label=>$value): ?><article><span><?= $label ?></span><strong><?= number_format($value, is_float($value) ? 1 : 0) ?></strong></article><?php endforeach; ?></section>
<section class="visits-report-panel"><h2>Visitantes por fecha</h2><?php if (!$days): ?><p class="report-empty">No hay solicitudes para los filtros seleccionados.</p><?php endif; ?><?php foreach ($days as $day=>$count): ?><div class="report-bar"><span><?= visitas_ui_escape(date('d/m',strtotime($day))) ?></span><meter min="0" max="<?= $max ?>" value="<?= $count ?>"><?= $count ?></meter><strong><?= $count ?></strong></div><?php endforeach; ?></section>
<section class="visits-report-panel"><h2>Instituciones</h2><div class="table-scroll"><table><thead><tr><th>Institución</th><th>Solicitudes</th><th>Visitantes</th></tr></thead><tbody><?php foreach ($institutions as $name=>$counts): ?><tr><td><?= visitas_ui_escape($name) ?></td><td><?= $counts['solicitudes'] ?></td><td><?= $counts['visitantes'] ?></td></tr><?php endforeach; ?><?php if (!$institutions): ?><tr><td colspan="3">No hay instituciones para este período.</td></tr><?php endif; ?></tbody></table></div></section>
</main></div></body></html>
