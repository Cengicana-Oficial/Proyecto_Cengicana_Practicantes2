<?php
require_once "menu.php";

cengi_require_ver_usuarios();

$mysqli = conectar_usuarios_menu();
$campo = trim($_POST['campo'] ?? '');

$sql = "
    SELECT
        DISTINCT
        u.id AS idusuario,
        u.nombre,
        u.correo,
        r.nombre_rol,
        COALESCE(i.nombre_ingenio, 'Sin ingenio') AS nombre_ingenio
    FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id
    LEFT JOIN ingenios i ON i.id = u.ingenio_id
    INNER JOIN usuario_modulo um ON um.usuario_id = u.id
    INNER JOIN modulos m ON m.id = um.modulo_id
    WHERE LOWER(m.nombre) IN ('cursos', 'cengicursos')
";

if ($campo !== '') {
    $like = '%' . $campo . '%';
    $sql .= " AND (u.nombre LIKE ? OR u.correo LIKE ? OR r.nombre_rol LIKE ? OR i.nombre_ingenio LIKE ?)";
}

$sql .= " ORDER BY u.nombre";

$stmt = $mysqli->prepare($sql);
if ($campo !== '') {
    $stmt->bind_param('ssss', $like, $like, $like, $like);
}
$stmt->execute();
$resultado = $stmt->get_result();
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
    <?php menu_render(); ?>
    <div class="container">
        <div class="cengi-hero">
            <span class="cengi-chip">Usuarios</span>
            <h2>Usuarios del modulo de cursos</h2>
            <p>Esta vista solo lista usuarios vinculados al modulo Cengicursos.</p>
        </div>

        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">Usuarios del sistema</h3>
            </div>

            <div class="panel-body">
                <?php if (cengi_puede_gestionar_usuarios()): ?>
                    <div class="row">
                        <div class="col-sm-6">
                            <a href="../login/usuarios/crear_usuario.php?scope=cursos" class="btn btn-primary"><span class="glyphicon glyphicon-plus"></span> Nuevo registro</a>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="row">
                    <form action="<?php $_SERVER['PHP_SELF']; ?>" method="POST">
                        <div class="col-sm-4">
                            <input type="text" placeholder="Nombre, correo, rol o ingenio" class="form-control" name="campo" id="campo" value="<?php echo htmlspecialchars($campo); ?>">
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
                            <th>Correo</th>
                            <th>Ingenio</th>
                            <th>Rol</th>
                            <?php if (cengi_puede_gestionar_usuarios()): ?><th>Acciones</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $resultado->fetch_assoc()) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['idusuario']); ?></td>
                                <td><?php echo htmlspecialchars($row['nombre']); ?></td>
                                <td><?php echo htmlspecialchars($row['correo']); ?></td>
                                <td><?php echo htmlspecialchars($row['nombre_ingenio']); ?></td>
                                <td><?php echo htmlspecialchars($row['nombre_rol']); ?></td>
                                <?php if (cengi_puede_gestionar_usuarios()): ?>
                                    <td>
                                        <div class="cengi-row-actions">
                                            <a class="cengi-action-btn is-edit" href="../login/usuarios/editar_usuario.php?id=<?php echo (int) $row['idusuario']; ?>&scope=cursos" data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span><span class="sr-only">Editar</span></a>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
