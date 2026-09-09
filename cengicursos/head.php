<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
	<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
	<?php /* Cache-busting de la hoja de estilos (mismo patron que solicitudes.php):
	         sin esto el navegador reutiliza el proyecto.css cacheado tras un deploy. */ ?>
	<link rel="stylesheet" type="text/css" href="css/proyecto.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/proyecto.css') ?: 1); ?>">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
	<!--<script type="text/javascript" src="ajax_requi.js"></script>-->
	<script type="text/javascript" src="js/main.js"></script>
</head>
