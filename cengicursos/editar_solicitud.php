<?php
require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "menu.php";

cengi_require_gestor_solicitudes('solicitudes.php');

$db = conectar();
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: solicitudes.php");
    exit;
}

$stmt = $db->prepare("
    SELECT
        s.*,
        i.nombre_ingenios
    FROM solicitudes_inscripcion s
    LEFT JOIN ingenios i ON i.id = s.ingenio_id
    WHERE s.id_solicitud = ?
    LIMIT 1
");
$stmt->execute([$id]);
$solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$solicitud || $solicitud['estado'] !== 'Pendiente') {
    header("Location: solicitudes.php");
    exit;
}

if (
    !cengi_ve_todo_por_rol_o_ingenio() &&
    cengi_texto_normalizado($solicitud['nombre_ingenios'] ?? '') !== cengi_texto_normalizado(cengi_ingenio_nombre_actual())
) {
    header("Location: solicitudes.php");
    exit;
}

$ingenios = $db->query("
    SELECT id, nombre_ingenios
    FROM ingenios
    ORDER BY nombre_ingenios
");

$sqlCursos = "
    SELECT
        c.id,
        c.nombre_cursos
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    WHERE ca.estado_categorias_cursos <> 0
";

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sqlCursos .= "
        AND c.ingenio_id = " . (int) cengi_ingenio_id_actual();
}

$sqlCursos .= "
    ORDER BY c.nombre_cursos
";

$cursos = $db->query($sqlCursos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar solicitud</title>
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
    <link rel="stylesheet" type="text/css" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<div class="container">
    <div class="cengi-hero">
        <span class="cengi-chip">Solicitudes</span>
        <h2>Editar solicitud pendiente</h2>
        <p>Corrige los datos de la solicitud antes de aprobarla o rechazarla.</p>
    </div>

    <div class="panel panel-success">
        <div class="panel-heading">
            <h3 class="panel-title">Editar solicitud pendiente</h3>
        </div>
        <div class="panel-body">
            <form method="POST" action="actualizar_solicitud.php" autocomplete="off">
                <input type="hidden" name="id_solicitud" value="<?php echo (int) $solicitud['id_solicitud']; ?>">

                <div class="cengi-form-grid">
                    <div class="form-group">
                        <label>Nombre</label>
                        <input type="text" name="nombre_participante" class="form-control" required value="<?php echo htmlspecialchars($solicitud['nombre_participante']); ?>">
                    </div>
                    <div class="form-group">
                        <label>CUI</label>
                        <input type="text" name="cui_participante" class="form-control" required value="<?php echo htmlspecialchars($solicitud['cui_participante']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Correo</label>
                        <input type="email" name="correo" class="form-control" required value="<?php echo htmlspecialchars($solicitud['correo']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Telefono</label>
                        <input type="text" name="telefono" class="form-control" required value="<?php echo htmlspecialchars($solicitud['telefono']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Puesto</label>
                        <input type="text" name="puesto_participante" class="form-control" required value="<?php echo htmlspecialchars($solicitud['puesto_participante']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Area</label>
                        <input type="text" name="area_participante" class="form-control" required value="<?php echo htmlspecialchars($solicitud['area_participante']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Grado académico</label>
                        <input type="text" name="grado_academico" class="form-control" required value="<?php echo htmlspecialchars($solicitud['grado_academico']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Tipo de pago</label>
                        <select name="tipo_pago" class="form-control" required>
                            <option value="Ingenio" <?php echo $solicitud['tipo_pago'] === 'Ingenio' ? 'selected' : ''; ?>>Ingenio</option>
                            <option value="Propio" <?php echo $solicitud['tipo_pago'] === 'Propio' ? 'selected' : ''; ?>>Propio</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Ingenio</label>
                        <select name="ingenio_id" class="form-control" required>
                            <?php while ($ingenio = $ingenios->fetch(PDO::FETCH_ASSOC)) { ?>
                                <option value="<?php echo (int) $ingenio['id']; ?>" <?php echo (int) $solicitud['ingenio_id'] === (int) $ingenio['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ingenio['nombre_ingenios']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Curso</label>
                        <select name="curso_id" class="form-control" required>
                            <?php while ($curso = $cursos->fetch(PDO::FETCH_ASSOC)) { ?>
                                <option value="<?php echo (int) $curso['id']; ?>" <?php echo (int) $solicitud['curso_id'] === (int) $curso['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nombre_cursos']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="cengi-form-actions">
                    <a href="solicitudes.php" class="btn btn-default">Cancelar</a>
                    <button type="submit" class="btn btn-success">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
