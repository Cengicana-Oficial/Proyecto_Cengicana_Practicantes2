<?php
require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "menu.php";

cengi_require_admin("ver_cursos.php");

$db = conectar();
$cursoID = (int) ($_GET['curso_id'] ?? 0);

$stmtIngenios = $db->query("
    SELECT id, nombre_ingenios
    FROM ingenios
    ORDER BY nombre_ingenios
");

$stmtCursos = $db->query("
    SELECT c.id, c.nombre_cursos
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    WHERE ca.estado_categorias_cursos = 1
    ORDER BY c.nombre_cursos
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo participante</title>
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
            <span class="cengi-chip">Participantes</span>
            <h2>Nuevo participante del curso</h2>
            <p>Registra un participante y asignalo directamente a un curso e ingenio.</p>
        </div>

        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">Nuevo participante del curso</h3>
            </div>
            <div class="panel-body">
                <form method="POST" action="guardar_participante_curso.php" autocomplete="off">
                    <div class="cengi-form-grid">
                        <div class="form-group">
                            <label for="cui" class="control-label">CUI</label>
                            <input type="text" class="form-control" id="cui" name="cui" placeholder="0000-00000-0000" required>
                        </div>
                        <div class="form-group">
                            <label for="nombre" class="control-label">Nombre</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Nombre del participante" required>
                        </div>
                        <div class="form-group">
                            <label for="ingenio" class="control-label">Ingenio</label>
                            <select class="form-control" id="ingenio" name="ingenio" required>
                                <option value="">Selecciona un ingenio</option>
<?php while ($ingenio = $stmtIngenios->fetch(PDO::FETCH_ASSOC)) { ?>
                                <option value="<?php echo (int) $ingenio['id']; ?>">
                                    <?php echo htmlspecialchars($ingenio['nombre_ingenios']); ?>
                                </option>
<?php } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="curso" class="control-label">Curso</label>
                            <select class="form-control" id="curso" name="curso" required>
                                <option value="">Selecciona un curso</option>
<?php while ($curso = $stmtCursos->fetch(PDO::FETCH_ASSOC)) { ?>
                                <option value="<?php echo (int) $curso['id']; ?>" <?php echo $cursoID === (int) $curso['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nombre_cursos']); ?>
                                </option>
<?php } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="area" class="control-label">Area</label>
                            <input type="text" class="form-control" id="area" name="area" placeholder="Area" required>
                        </div>
                        <div class="form-group">
                            <label for="puesto" class="control-label">Puesto</label>
                            <input type="text" class="form-control" id="puesto" name="puesto" placeholder="Puesto" required>
                        </div>
                        <div class="form-group">
                            <label for="correo" class="control-label">Correo electrónico</label>
                            <input type="email" class="form-control" id="correo" name="correo" maxlength="255" placeholder="persona@ejemplo.com">
                        </div>
                        <div class="form-group">
                            <label for="grado_academico" class="control-label">Grado académico</label>
                            <input type="text" class="form-control" id="grado_academico" name="grado_academico" maxlength="255" placeholder="Licenciatura, técnico, diversificado...">
                        </div>
                        <div class="form-group">
                            <label for="telefono" class="control-label">Teléfono</label>
                            <input type="tel" class="form-control" id="telefono" name="telefono" maxlength="50" placeholder="Número de teléfono">
                        </div>
                    </div>

                    <div class="cengi-form-actions">
                        <button type="submit" class="btn btn-success">Guardar y asignar</button>
                        <a href="<?php echo $cursoID > 0 ? 'ver_participante_curso.php?id=' . $cursoID : 'participantes.php'; ?>" class="btn btn-danger">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
