<?php

require_once "conexion.php";
require_once "menu.php";
cengi_require_ver_ingenios();
$db = conectar();
//include menu
//include('menu.php');
$valor = trim($_POST['campo'] ?? '');

$sql = "SELECT * FROM ingenios";
$params = [];

if ($valor !== '') {

    $sql .= "
        WHERE nombre_ingenios LIKE ?
    ";

    $params[] = "%{$valor}%";
}

$sql .= "
    ORDER BY nombre_ingenios
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
?>

<html lang="es">
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
	<link rel="stylesheet" type="text/css" href="css/proyecto.css">
	<script src="js/jquery-3.2.1.min.js"></script>
	<script src="js/bootstrap.min.js"></script>
	<meta charset="utf-8">
</head>

<body class="cengi-canvas">
	<?php menu_render();?>
	<div class="container">
		<div class="cengi-hero">
			<span class="cengi-chip">Ingenios</span>
			<h2>Ingenios registrados</h2>
			<p>Consulta y administra los ingenios disponibles para cursos y participantes.</p>
		</div>

		<div class="panel panel-success">
			<div class="panel-heading">
				<h3 class="panel-title"> Ingenios Registrados</h3>
			</div>

		<div class="panel-body">
            <?php if (cengi_puede_gestionar_ingenios()): ?>
			<div class="row">
				<div class="col-sm-6">
					<a href="agregar_ingenios.php" class="btn btn-primary"><span class="glyphicon glyphicon-plus"></span> Nuevo registro</a>
				</div>
			</div>
            <?php endif; ?>
			<div class="row">
				<form action="<?php $_SERVER['PHP_SELF'];?>" method="POST">
				 <div class="col-sm-4">
					<input type="text" placeholder="Nombre" class="form-control" name="campo" id="campo" value="<?php echo htmlspecialchars($valor); ?>">
				 </div>
				 <div class="col-sm-2">
					<button type="submit" name="enviar" id="enviar" value="Buscar" class="btn btn-success"><span class="glyphicon glyphicon-search"></span> Buscar</button>
				 </div>
				</form>
			</div>
		<br>
			<div class="cengi-table-wrap">
			<table class="table table-striped table-bordered table-hover">
				<thead>
					<tr>
						<th>ID</th>
						<th>Nombre</th>
                        <?php if (cengi_puede_gestionar_ingenios()): ?><th>Acciones</th><?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { ?>
					<tr>
<td><?php echo htmlspecialchars($row['id']); ?></td>
<td><?php echo htmlspecialchars($row['nombre_ingenios']); ?></td>
                        <?php if (cengi_puede_gestionar_ingenios()): ?>
						    <td>
						        <div class="cengi-row-actions">
						            <a class="cengi-action-btn is-edit" href="modificar_ingenios.php?id=<?php echo $row['id']; ?>" data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span><span class="sr-only">Editar</span></a>
						            <a class="cengi-action-btn is-delete" href="#" data-href="eliminar_ingenios.php?id=<?php echo $row['id']; ?>" data-toggle="modal" data-target="#confirm-delete" data-tooltip="Eliminar" aria-label="Eliminar"><span class="glyphicon glyphicon-trash"></span><span class="sr-only">Eliminar</span></a>
						        </div>
						    </td>
                        <?php endif; ?>
					</tr>
					<?php }?>
				</tbody>
			</table>
			</div>

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
