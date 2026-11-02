<?php
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/planificacion_helpers.php';
if (!cengi_puede_ver_solicitudes() || (cengi_es_gestor_ingenio() && cengi_ingenio_id_actual() <= 0)) { http_response_code(403); exit('No tienes acceso a esta oferta de cursos.'); }
$db = conectar();
$buscar = trim((string) ($_GET['q'] ?? ''));
$q = $db->prepare("SELECT id, nombre, modalidad, dirigido_a, fecha_inicio, fecha_fin, costo_asociados, cupo_maximo, 'plan' AS origen
 FROM planificacion_cursos WHERE estado IN ('aprobado','reprogramado') AND fecha_inicio >= ? AND nombre LIKE ?
 UNION ALL
 SELECT c.id, c.nombre_cursos, c.tipo, 'Curso programado', c.inicio, c.fin, NULL, c.cupo, 'curso' AS origen
 FROM cursos c WHERE c.inicio >= ? AND c.nombre_cursos LIKE ? AND NOT EXISTS (SELECT 1 FROM planificacion_cursos pc WHERE pc.curso_id=c.id)
 ORDER BY fecha_inicio, nombre");
$q->execute([cengi_pp_hoy(), '%' . $buscar . '%', cengi_pp_hoy(), '%' . $buscar . '%']);
$oferta = $q->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Próximos cursos</title>
<link rel="stylesheet" href="css/bootstrap.min.css"><link rel="stylesheet" href="css/proyecto.css"><link rel="stylesheet" href="css/cengi-tema.css"><link rel="stylesheet" href="css/ingenios.css">
</head><body class="cengi-canvas cg-tema"><?php menu_render(); ?>
<main class="container ing-page"><header class="ing-hero"><div><small>Oferta de capacitación</small><h1>Próximos cursos</h1><p>Cursos programados y convocatorias aprobadas o reprogramadas por iniciar. Consulta fechas y comparte el formulario con los interesados de tu ingenio.</p></div></header>
<section class="ing-panel"><div class="ing-toolbar"><form method="get" class="ing-buscar"><input type="search" name="q" value="<?php echo cengi_pp_h($buscar); ?>" placeholder="Buscar curso" aria-label="Buscar curso"><button class="ing-btn">Buscar</button></form><a class="ing-btn is-linea" href="inscripciones.php">Validar interesados</a><a class="ing-btn is-linea" href="dashboard_ingenio.php">Seguimiento de participantes</a><a class="ing-btn is-linea" href="pagos_inscripcion.php?tab=proformas">Proformas de pago</a></div>
<div class="table-responsive"><table class="table"><thead><tr><th>Curso</th><th>Fechas</th><th>Modalidad</th><th>Cuota asociada</th><th>Inscripción</th></tr></thead><tbody>
<?php foreach ($oferta as $p): ?><tr><td><strong><?php echo cengi_pp_h($p['nombre']); ?></strong><br><small><?php echo cengi_pp_h($p['dirigido_a']); ?></small></td><td><?php echo cengi_pp_h($p['fecha_inicio']); ?><br><small>Hasta <?php echo cengi_pp_h($p['fecha_fin']); ?></small></td><td><?php echo cengi_pp_h($p['modalidad']); ?></td><td><?php echo $p['costo_asociados'] === null ? 'Consultar cuota' : 'Q ' . number_format((float) $p['costo_asociados'], 2); ?></td><td><a class="ing-btn is-linea" href="inscripcion.php?<?php echo $p['origen'] === 'plan' ? 'plan' : 'curso_id'; ?>=<?php echo (int) $p['id']; ?>">Ver formulario</a></td></tr><?php endforeach; ?>
<?php if (!$oferta): ?><tr><td colspan="5">No hay convocatorias próximas que coincidan con la búsqueda.</td></tr><?php endif; ?>
</tbody></table></div></section></main></body></html>