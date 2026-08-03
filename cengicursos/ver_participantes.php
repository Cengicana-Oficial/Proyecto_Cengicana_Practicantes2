<?php
require_once("revisar_permisos.php");
cengi_require_ver_participantes();
//require c'conexion.php
require_once("conexion.php");
$db = conectar();
//include menu
//include('menu.php');
$valor = trim($_POST['campo'] ?? '');

$sql = "
SELECT
    p.id AS idparticipante,
    i.nombre_ingenios,
    p.cui_participantes,
    p.nombre_participantes,
    p.puesto_participantes,
    p.area_participantes
FROM participantes p
INNER JOIN ingenios i
    ON p.ingenio_id = i.id
";

$params = [];

if ($valor !== '') {

    $sql .= "
        WHERE p.nombre_participantes LIKE ?
    ";

    $params[] = "%{$valor}%";
}

$sql .= "
    ORDER BY p.nombre_participantes
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
?>

<html lang="es">
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
	<meta charset="utf-8">
</head>

<body>
	<?php include_once("menu.php"); ?>
	<div class="container">
		<div class="panel panel-success">
			<div class="panel-heading">
				<h3 class="panel-title"> Participantes Registrados</h3>
			</div>

		<div class="panel-body">
			<div class="row">
				<div class="col-sm-6">
<a href="agregar_participantes.php" class="btn btn-primary">
    Nuevo Registro
</a>
			</div>
			</div>
			<div class="row">
				<form action="<?php $_SERVER['PHP_SELF']; ?>" method="POST">
				 <div class="col-sm-4">
					<input type="text" placeholder="Nombre" class="form-control" name="campo" id="campo">
				 </div>
				 <div class="col-sm-2">
					<input type="submit" name="enviar" id="enviar" value="Buscar" class="btn btn-success">
				 </div>
				</form>
			</div>
		<br>
			<table class="table table-striped table-bordered table-hover">
				<thead>
					<tr>
						<th>ID</th>
						<th>Ingenio</th>
						<th>CUI</th>
						<th>Nombre</th>
						<th>Puesto</th>
						<th>Área</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { ?>
				<tr>
						<td><?php echo htmlspecialchars($row['idparticipante']); ?></td>
						<td><?php echo htmlspecialchars($row['nombre_ingenios']); ?></td>
						<td><?php echo htmlspecialchars($row['cui_participantes']); ?></td>
						<td><?php echo htmlspecialchars($row['nombre_participantes']); ?></td>
						<td><?php echo $row['puesto_participantes']; ?></td>
						<td><?php echo $row['area_participantes']; ?></td>
						<td><a href="modificar_participantes.php?id=<?php echo $row['idparticipante']; ?>"><span class="glyphicon glyphicon-pencil"></span></a>&nbsp;<a href="#" data-href="eliminar_participante.php?id=<?php echo $row['idparticipante']; ?>" data-toggle="modal" data-target="#confirm-delete"><span class="glyphicon glyphicon-trash"></span></a></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
	
	</div>
</div>
	<!-- Modal -->
	<div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				
				<div class="model-header">
					<button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h4 class="modal-title" id="myModalLabel">Eliminar Registro</h4>
				</div>

				<div class="modal-body">
					¿Desea eliminar este registro?
				</div>

				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
					<a class="btn btn-danger btn-ok">Eliminar</a>
				</div>
			</div>
		</div>
	</div>
	<script type="text/javascript">
		$('#confirm-delete').on('show.bs.modal',function(e){
			$(this).find('.btn-ok').attr('href', $(e.relatedTarget).data('href'));

			$('.debug-url').html('Delete URL: <strong> '+ $(this).find('.btn-ok').attr('href')+'</strong>');
		});
	</script>
</body>
</html>