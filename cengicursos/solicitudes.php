<?php
require_once "conexion.php";
require_once "menu.php";

$conexion = conectar();
$puedeGestionar = cengi_puede_gestionar();
$puedeEditarSolicitudes = cengi_puede_editar_solicitudes();
$puedeAprobarSolicitudes = cengi_puede_aprobar_solicitudes();
$puedeRechazarSolicitudes = cengi_puede_rechazar_solicitudes();

cengi_require_gestor_solicitudes('ver_cursos.php');

$campo = trim($_POST['campo'] ?? '');

$condiciones = [];
$tipos = '';
$params = [];

if ($campo !== '') {
    $condiciones[] = "(
        s.nombre_participante LIKE ?
        OR c.nombre_cursos LIKE ?
        OR i.nombre_ingenios LIKE ?
        OR s.correo LIKE ?
        OR s.telefono LIKE ?
        OR s.tipo_pago LIKE ?
    )";

    $like = '%' . $campo . '%';
    $tipos .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = "regexp_replace(lower(translate(i.nombre_ingenios, 'Ã¡Ã©Ã­Ã³ÃºÃÃ‰ÃÃ“ÃšÃ±Ã‘', 'aeiouAEIOUnN')), '\s+', '', 'g') = ?";
    $tipos .= 's';
    $params[] = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
}

$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$sql = "
    SELECT
        s.*,
        i.nombre_ingenios,
        c.nombre_cursos
    FROM solicitudes_inscripcion s
    LEFT JOIN ingenios i ON s.ingenio_id = i.id
    INNER JOIN cursos c ON s.curso_id = c.id
    {$where}
    ORDER BY s.id_solicitud DESC
";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Solicitudes</title>
<link rel="stylesheet" href="css/bootstrap.min.css">
<link rel="stylesheet" type="text/css" href="css/proyecto.css">
<script src="js/jquery-3.2.1.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<style>

.panel{margin-top:20px;}
.panel-body-scroll{overflow-x:auto;padding-bottom:6px;}
table{background:white;}
.tabla-solicitudes{table-layout:auto;min-width:1450px;}
.tabla-solicitudes th,
.tabla-solicitudes td{white-space:normal;vertical-align:middle;}
.tabla-solicitudes .col-acciones{min-width:220px;width:220px;}
.tabla-solicitudes .col-cui{min-width:150px;}
.tabla-solicitudes .col-correo{min-width:280px;}
.tabla-solicitudes .col-curso{min-width:220px;}
.acciones-solicitud{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.accion-solicitud{display:inline-flex;align-items:center;justify-content:center;min-width:96px;padding:9px 14px;text-decoration:none;border-radius:999px;font-weight:700;font-size:12px;letter-spacing:.02em;box-shadow:0 10px 20px rgba(0,0,0,.08);transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease;}
.accion-solicitud:hover{transform:translateY(-1px);box-shadow:0 14px 24px rgba(0,0,0,.12);text-decoration:none;}
.aprobar{background:linear-gradient(135deg,#1f8f34,#36b24a);color:white;}
.rechazar{background:linear-gradient(135deg,#d43131,#ff4d4d);color:white;}
.editar{background:linear-gradient(135deg,#2563eb,#3b82f6);color:white;}
.estado-label{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;background:#eef7df;color:#2d5e0e;font-weight:700;font-size:12px;}
</style>
</head>

<body class="cengi-canvas">
<?php menu_render(); ?>

<div class="container">
    <div class="cengi-hero">
        <span class="cengi-chip">Solicitudes</span>
        <h2>Inscripciones por ingenio</h2>
        <p>Todos los usuarios quedan filtrados por su ingenio. Solo superadmin puede ver solicitudes de todos los ingenios.</p>
    </div>
    <div class="panel panel-success">
        <div class="panel-heading">
            <h3 class="panel-title">Solicitudes de Inscripcion</h3>
        </div>

        <div class="panel-body">
            <form method="POST">
                <div class="row">
                    <div class="col-sm-4">
                        <input
                            type="text"
                            name="campo"
                            class="form-control"
                            placeholder="Buscar participante, curso, correo o ingenio"
                            value="<?= htmlspecialchars($campo) ?>"
                        >
                    </div>

                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-success">Buscar</button>
                    </div>
                </div>
            </form>

            <br>
            <div class="panel-body-scroll">
            <table class="table table-bordered table-hover tabla-solicitudes">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th class="col-cui">CUI</th>
                        <th class="col-correo">Correo</th>
                        <th>Telefono</th>
                        <th>Tipo Pago</th>
                        <th class="col-curso">Curso</th>
                        <th>Ingenio</th>
                        <th>Estado</th>
                        <?php if ($puedeEditarSolicitudes || $puedeAprobarSolicitudes || $puedeRechazarSolicitudes): ?><th class="col-acciones">Acciones</th><?php endif; ?>
                    </tr>
                </thead>

                <tbody>
<?php while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                        <td><?= htmlspecialchars($fila['id_solicitud']) ?></td>
                        <td><?= htmlspecialchars($fila['nombre_participante']) ?></td>
                        <td><?= htmlspecialchars($fila['cui_participante']) ?></td>
                        <td><?= htmlspecialchars($fila['correo']) ?></td>
                        <td><?= htmlspecialchars($fila['telefono']) ?></td>
                        <td><?= htmlspecialchars($fila['tipo_pago']) ?></td>
                        <td><?= htmlspecialchars($fila['nombre_cursos']) ?></td>
                        <td><?= htmlspecialchars($fila['nombre_ingenios'] ?? '') ?></td>
                        <td><?= htmlspecialchars($fila['estado']) ?></td>

                        <?php if ($puedeEditarSolicitudes || $puedeAprobarSolicitudes || $puedeRechazarSolicitudes): ?>
                            <td class="col-acciones">
                            <?php if ($fila['estado'] === 'Pendiente'): ?>
                                <div class="acciones-solicitud">
                                    <?php if ($puedeEditarSolicitudes): ?>
                                        <a class="accion-solicitud editar" href="editar_solicitud.php?id=<?= (int) $fila['id_solicitud'] ?>">Editar</a>
                                    <?php endif; ?>
                                    <?php if ($puedeAprobarSolicitudes): ?>
                                        <a class="accion-solicitud aprobar" href="aprobar.php?id=<?= (int) $fila['id_solicitud'] ?>">Aprobar</a>
                                    <?php endif; ?>
                                    <?php if ($puedeRechazarSolicitudes): ?>
                                        <a class="accion-solicitud rechazar" href="rechazar.php?id=<?= (int) $fila['id_solicitud'] ?>">Rechazar</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="estado-label">Gestionada</span>
                            <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>




