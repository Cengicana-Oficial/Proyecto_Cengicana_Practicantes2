<?php
require_once __DIR__ . "/revisar_permisos.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/menu.php";
$usuario = cengi_cargar_usuario_actual();
$puedeGestionar = cengi_puede_gestionar();
$esEstudiante = cengi_es_estudiante();
?>

<html lang="es">
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
	<link rel="stylesheet" type="text/css" href="css/proyecto.css">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>

</head>

<body class="cengi-canvas">
	<?php menu_render();?>
	<div class="container">
		<div class="cengi-hero">
			<span class="cengi-chip">Cengicursos</span>
			<h1><?php echo $esEstudiante ? 'Tus cursos y resultados' : 'Gestion de cursos e inscripciones'; ?></h1>
			<p>
				<?php echo $esEstudiante
				    ? 'Consulta unicamente los cursos asignados a tu usuario y revisa tu nota sin opciones de edicion.'
				    : 'Administra participantes, solicitudes, usuarios y reportes desde una interfaz mas clara y ordenada.'; ?>
			</p>
		</div>

		<div class="cengi-card-grid">
			<div class="cengi-card">
				<h3>Usuario activo</h3>
				<p><strong><?php echo htmlspecialchars($usuario['nombre'] ?? ($_SESSION['usuario'] ?? 'Usuario')); ?></strong></p>
				<p><?php echo htmlspecialchars($usuario['nombre_rol'] ?? ($_SESSION['rol'] ?? '')); ?></p>
			</div>
			<div class="cengi-card">
				<h3>Ingenio</h3>
				<p><?php echo htmlspecialchars($_SESSION['ingenio_nombre'] ?? 'Sin ingenio'); ?></p>
			</div>
			<div class="cengi-card">
				<h3>Acceso</h3>
				<p><?php echo $puedeGestionar ? 'Administracion completa del modulo de cursos.' : 'Consulta limitada segun tu rol y tu ingenio.'; ?></p>
			</div>
		</div>
	</div>
</body>
</html>

