<?php
require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "menu.php";
require_once realpath("classes/class.participantes.php");
require_once realpath("classes/class.cursos.php");
require_once realpath("classes/class.ingenios.php");
require_once realpath("classes/class.users.php");

if (cengi_es_estudiante()) {
    header("Location: ver_cursos.php");
    exit();
}

$participantes = new participantes();
$cursos = new cursos();
$ingenios = new ingenios();
$users = new users();
$puedeGestionar = cengi_puede_gestionar();
$campo = trim($_POST['campo'] ?? '');

if ($campo !== '') {
    $resultado = $participantes->getParticipantesByNombre($campo);
} else {
    $resultado = $participantes->consultar_visibles();
}
?>

<html lang="es">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
    <link rel="stylesheet" type="text/css" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/main.js"></script>
    <meta charset="utf-8">
</head>

<body class="cengi-canvas">
    <?php menu_render(); ?>

    <?php if ($puedeGestionar): ?>
    <div class="container" id="cargarParticipantes" style="display:none;">
        <button type="button" id="btnShowGrid" class="btn btn-lg btn-info">
            <span class="glyphicon glyphicon-list-alt"></span> Ver participantes
        </button>
        <br><br>

        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">Carga masiva de participantes</h3>
            </div>
            <div class="panel-body">
                <form method="POST" action="carga_participantes.php" enctype="multipart/form-data" autocomplete="off" accept-charset="UTF-8">
                    <div class="form-group">
                        <label for="ingenio" class="control-label">Ingenio</label>
                        <select class="form-control" id="ingenio" name="ingenio" required>
                            <?php
                            $resultIngenios = $ingenios->consultar_visibles();
                            while ($ingenio = $resultIngenios->fetch(PDO::FETCH_ASSOC)) {
                                ?>
                                <option value="<?php echo (int) $ingenio['id']; ?>">
                                    <?php echo htmlspecialchars($ingenio['nombre_ingenios']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="user" class="control-label">Usuario asignado</label>
                        <select class="form-control" id="user" name="user" required>
                            <?php
                            $resultUsers = $users->consultar_visibles();
                            while ($usuario = $resultUsers->fetch_assoc()) {
                                ?>
                                <option value="<?php echo (int) $usuario['id']; ?>">
                                    <?php echo htmlspecialchars($usuario['nombre'] . ' - ' . $usuario['nombre_rol']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="curso" class="control-label">Curso</label>
                        <select class="form-control" id="curso" name="curso" required>
                            <?php
                            $resultCursos = $cursos->consultar_visibles();
                            while ($curso = $resultCursos->fetch(PDO::FETCH_ASSOC)) {
                                ?>
                                <option value="<?php echo (int) $curso['cursoid']; ?>">
                                    <?php echo htmlspecialchars($curso['nombre_cursos']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="well">
                        <p>Puedes cargar archivos <strong>.csv</strong>, <strong>.xls</strong> o <strong>.xlsx</strong> con este formato:</p>
                        <table class="table table-bordered">
                            <tr>
                                <th>CUI</th>
                                <th>NOMBRE</th>
                                <th>PUESTO</th>
                                <th>AREA</th>
                            </tr>
                        </table>
                    </div>

                    <div class="form-group">
                        <label for="archivo" class="control-label">Archivo</label>
                        <input id="archivo" accept=".csv,.xls,.xlsx" class="form-control" name="archivo" type="file" required>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-success"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar</button>
                        <a href="index.php" class="btn btn-danger"><span class="glyphicon glyphicon-remove"></span> Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="container" id="gridParticipantes">
        <?php if ($puedeGestionar): ?>
            <button type="button" id="btnLoadCSV" class="btn btn-lg btn-success">
                <span class="glyphicon glyphicon-cloud-upload"></span> Cargar Excel o CSV
            </button>
            <br><br>
        <?php endif; ?>

        <div class="cengi-hero">
            <span class="cengi-chip">Participantes</span>
            <h2>Listado general</h2>
            <p>Busca participantes por nombre y administra las cargas masivas por ingenio y curso.</p>
        </div>

        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">Participantes registrados</h3>
            </div>

            <div class="panel-body">
                <div class="row">
                    <form action="<?php $_SERVER['PHP_SELF']; ?>" method="POST">
                        <div class="col-sm-4">
                            <input type="text" placeholder="Nombre del participante" class="form-control" name="campo" id="campo" value="<?php echo htmlspecialchars($campo); ?>">
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
                            <th>Ingenio</th>
                            <th>CUI</th>
                            <th>Nombre</th>
                            <th>Puesto</th>
                            <th>Area</th>
                            <?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($resultado !== false): ?>
                            <?php while ($row = $resultado->fetch(PDO::FETCH_ASSOC)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['idparticipante']); ?></td>
                                    <td><?php echo htmlspecialchars($row['nombre_ingenios']); ?></td>
                                    <td><?php echo htmlspecialchars($row['cui_participantes']); ?></td>
                                    <td><?php echo htmlspecialchars($row['nombre_participantes']); ?></td>
                                    <td><?php echo htmlspecialchars($row['puesto_participantes']); ?></td>
                                    <td><?php echo htmlspecialchars($row['area_participantes']); ?></td>
                                    <?php if ($puedeGestionar): ?>
                                        <td>
                                            <div class="cengi-row-actions">
                                                <a class="cengi-action-btn is-edit" href="modificar_participantes.php?id=<?php echo (int) $row['idparticipante']; ?>" data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span><span class="sr-only">Editar</span></a>
                                                <a class="cengi-action-btn is-delete" href="#" data-href="eliminar_participante.php?id=<?php echo (int) $row['idparticipante']; ?>" data-toggle="modal" data-target="#confirm-delete" data-tooltip="Eliminar" aria-label="Eliminar"><span class="glyphicon glyphicon-trash"></span><span class="sr-only">Eliminar</span></a>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php } ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $puedeGestionar ? 7 : 6; ?>" class="text-center">No se encontraron resultados.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="model-header">
                    <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h4 class="modal-title" id="myModalLabel">Eliminar registro</h4>
                </div>

                <div class="modal-body">
                    Desea eliminar este registro?
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                    <a class="btn btn-danger btn-ok">Eliminar</a>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        $('#confirm-delete').on('show.bs.modal', function (e) {
            $(this).find('.btn-ok').attr('href', $(e.relatedTarget).data('href'));
        });
    </script>
</body>
</html>
