<?php
require_once "conexion.php";
require_once "menu.php";

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$puedeCalificar = cengi_puede_calificar();
$esEstudiante = cengi_es_estudiante();
$campo = trim($_POST['campo'] ?? '');
$params = [];

if ($esEstudiante) {
    $sql = "
        SELECT
            c.id AS idcurso,
            c.nombre_cursos,
            ca.descripcion_categorias_cursos,
            i.nombre_ingenios,
            c.jornada_cursos,
            c.tipo,
            c.inicio,
            c.fin,
            COALESCE(cc.posevaluacion, cc.evaluacion, 0) AS nota
        FROM asignaciones a
        INNER JOIN cursos c ON c.id = a.cursos_id
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        INNER JOIN ingenios i ON i.id = c.ingenio_id
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE (a.usuarios_id = ? OR p.usuarios_id = ?)
    ";

    $params[] = cengi_usuario_actual_id();
    $params[] = cengi_usuario_actual_id();

    if ($campo !== '') {
        $sql .= " AND c.nombre_cursos LIKE ?";
        $params[] = '%' . $campo . '%';
    }

    $sql .= " ORDER BY c.inicio DESC, c.nombre_cursos";
} else {
    $condiciones = [];

    if ($campo !== '') {
        $condiciones[] = "c.nombre_cursos LIKE ?";
        $params[] = '%' . $campo . '%';
    }

    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $normalizado = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
        $condiciones[] = cengi_sql_texto_normalizado('i.nombre_ingenios') . " = ?";
        $params[] = $normalizado;
        $condiciones[] = "
            EXISTS (
                SELECT 1
                FROM asignaciones a
                INNER JOIN participantes p ON p.id = a.participantes_id
                INNER JOIN ingenios ip ON ip.id = p.ingenio_id
                WHERE a.cursos_id = c.id
                  AND a.estado_asignaciones = 1
                  AND " . cengi_sql_texto_normalizado('ip.nombre_ingenios') . " = ?
            )
        ";
        $params[] = $normalizado;
    }

    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    $sql = "
        SELECT
            c.id AS idcurso,
            ca.descripcion_categorias_cursos,
            i.nombre_ingenios,
            c.nombre_cursos,
            c.jornada_cursos,
            c.tipo,
            c.dias,
            c.horario,
            c.inicio,
            c.fin
        FROM cursos c
        INNER JOIN categorias_cursos ca ON c.categoria_curso_id = ca.id
        INNER JOIN ingenios i ON c.ingenio_id = i.id
        {$where}
        ORDER BY c.nombre_cursos
    ";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);

function cengi_curso_html($valor)
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_curso_estado($inicio, $fin)
{
    $hoy = date('Y-m-d');
    $inicioValido = ($inicio && $inicio !== '0000-00-00') ? $inicio : null;
    $finValido = ($fin && $fin !== '0000-00-00') ? $fin : null;

    if ($finValido !== null && $finValido < $hoy) {
        return ['Finalizado', 'is-finished'];
    }
    if ($inicioValido !== null && $inicioValido > $hoy) {
        return ['Proximo', 'is-upcoming'];
    }

    return ['Activo', 'is-active'];
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
    <meta charset="utf-8">
    <style>
        @media (min-width: 1200px) {
            .cengi-courses-page {
                width: calc(100% - 48px);
                max-width: 1480px;
            }
        }

        .cengi-courses-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
            margin-bottom: 18px;
        }

        .cengi-courses-toolbar form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin: 0;
        }

        .cengi-courses-toolbar .form-control {
            width: 360px;
            max-width: 360px;
        }

        .cengi-courses-table {
            width: 100%;
            min-width: 1260px;
            margin-bottom: 0;
            table-layout: fixed;
        }

        .cengi-courses-table > thead > tr > th {
            background: #eef8ea;
            color: #4b9600;
            font-size: 13px;
            letter-spacing: .04em;
            text-transform: uppercase;
            vertical-align: middle;
            white-space: nowrap;
        }

        .cengi-courses-table > tbody > tr > td {
            color: #07303a;
            font-size: 13px;
            line-height: 1.35;
            vertical-align: middle;
            word-break: normal;
            overflow-wrap: break-word;
        }

        .cengi-courses-table .col-id {
            width: 46px;
            text-align: center;
        }

        .cengi-courses-table .col-course {
            width: 175px;
        }

        .cengi-courses-table .col-category {
            width: 105px;
        }

        .cengi-courses-table .col-ingenio {
            width: 106px;
        }

        .cengi-courses-table .col-tipo {
            width: 100px;
        }

        .cengi-courses-table .col-estado {
            width: 116px;
        }

        .cengi-courses-table .col-jornada {
            width: 92px;
        }

        .cengi-courses-table .col-days {
            width: 124px;
        }

        .cengi-courses-table .col-time {
            width: 116px;
        }

        .cengi-courses-table .col-date {
            width: 92px;
            white-space: nowrap;
        }

        .cengi-courses-table .col-actions,
        .cengi-courses-table td.col-actions {
            width: 230px;
            white-space: nowrap;
        }

        .cengi-courses-table td.col-actions {
            padding-left: 8px;
            padding-right: 8px;
        }

        @media (max-width: 767px) {
            .cengi-courses-toolbar {
                display: block;
            }

            .cengi-courses-toolbar .btn,
            .cengi-courses-toolbar .form-control {
                width: 100%;
                max-width: none;
                margin-bottom: 10px;
            }

            .cengi-courses-toolbar form {
                display: block;
            }
        }
    </style>
</head>

<body class="cengi-canvas">
    <?php menu_render(); ?>
    <div class="container cengi-courses-page">
        <div class="cengi-hero">
            <span class="cengi-chip">Cursos</span>
            <h2><?php echo $esEstudiante ? 'Mis cursos asignados' : 'Cursos registrados'; ?></h2>
            <p>
                <?php echo $esEstudiante
                    ? 'Consulta tus cursos y la nota registrada sin permisos de edicion.'
                    : 'Administra la oferta de cursos por categoria, ingenio y calendario.'; ?>
            </p>
        </div>

        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title"><?php echo $esEstudiante ? 'Cursos y nota' : 'Cursos registrados'; ?></h3>
            </div>

            <div class="panel-body">
                <div class="cengi-courses-toolbar">
                    <?php if ($puedeGestionar): ?>
                        <a href="agregar_cursos.php" class="btn btn-primary"><span class="glyphicon glyphicon-plus"></span> Nuevo registro</a>
                    <?php endif; ?>
                    <form action="<?php $_SERVER['PHP_SELF']; ?>" method="POST">
                        <input type="text" placeholder="Nombre del curso" class="form-control" name="campo" id="campo" value="<?php echo cengi_curso_html($campo); ?>">
                        <button type="submit" name="enviar" id="enviar" value="Buscar" class="btn btn-success"><span class="glyphicon glyphicon-search"></span> Buscar</button>
                    </form>
                </div>

                <div class="cengi-table-wrap">
                <table class="table table-striped table-bordered table-hover cengi-courses-table">
                    <thead>
                    <?php if ($esEstudiante): ?>
                        <tr>
                            <th class="col-id">ID</th>
                            <th class="col-course">Curso</th>
                            <th class="col-category">Categoria</th>
                            <th class="col-ingenio">Ingenio</th>
                            <th class="col-tipo">Tipo</th>
                            <th class="col-jornada">Jornada</th>
                            <th class="col-date">Inicio</th>
                            <th class="col-date">Fin</th>
                            <th class="col-estado">Estado</th>
                            <th class="col-date">Nota</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th class="col-id">ID</th>
                            <th class="col-course">Curso</th>
                            <th class="col-category">Categoria</th>
                            <th class="col-ingenio">Ingenio</th>
                            <th class="col-tipo">Tipo</th>
                            <th class="col-jornada">Jornada</th>
                            <th class="col-days">Dias</th>
                            <th class="col-time">Horario</th>
                            <th class="col-date">Inicio</th>
                            <th class="col-date">Fin</th>
                            <th class="col-estado">Estado</th>
                            <?php if ($puedeGestionar || $puedeCalificar): ?><th class="col-actions">Acciones</th><?php endif; ?>
                        </tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            [$estadoLabel, $estadoClase] = cengi_curso_estado($row['inicio'], $row['fin']);
                        ?>
                            <tr>
                                <td class="col-id"><?php echo cengi_curso_html($row['idcurso']); ?></td>
                                <td class="col-course"><?php echo cengi_curso_html($row['nombre_cursos']); ?></td>
                                <td class="col-category"><?php echo cengi_curso_html($row['descripcion_categorias_cursos']); ?></td>
                                <td class="col-ingenio"><?php echo cengi_curso_html($row['nombre_ingenios']); ?></td>
                                <td class="col-tipo"><?php echo cengi_curso_html($row['tipo'] ?: '—'); ?></td>
                                <td class="col-jornada"><?php echo cengi_curso_html($row['jornada_cursos']); ?></td>
                                <?php if ($esEstudiante): ?>
                                    <td class="col-date"><?php echo cengi_curso_html($row['inicio']); ?></td>
                                    <td class="col-date"><?php echo cengi_curso_html($row['fin']); ?></td>
                                    <td class="col-estado"><span class="cengi-status-badge <?php echo $estadoClase; ?>"><i></i><?php echo $estadoLabel; ?></span></td>
                                    <td class="col-date"><strong><?php echo cengi_curso_html($row['nota']); ?></strong></td>
                                <?php else: ?>
                                    <td class="col-days"><?php echo cengi_curso_html($row['dias']); ?></td>
                                    <td class="col-time"><?php echo cengi_curso_html($row['horario']); ?></td>
                                    <td class="col-date"><?php echo cengi_curso_html($row['inicio']); ?></td>
                                    <td class="col-date"><?php echo cengi_curso_html($row['fin']); ?></td>
                                    <td class="col-estado"><span class="cengi-status-badge <?php echo $estadoClase; ?>"><i></i><?php echo $estadoLabel; ?></span></td>
                                    <?php if ($puedeGestionar || $puedeCalificar): ?>
                                        <td class="col-actions">
                                            <div class="cengi-row-actions">
                                                <?php if ($puedeGestionar): ?>
                                                    <a class="cengi-action-btn is-edit" href="modificar_cursos.php?id=<?php echo (int) $row['idcurso']; ?>" data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span><span class="sr-only">Editar</span></a>
                                                    <a class="cengi-action-btn is-delete" href="#" data-href="eliminar_cursos.php?id=<?php echo (int) $row['idcurso']; ?>" data-toggle="modal" data-target="#confirm-delete" data-tooltip="Eliminar" aria-label="Eliminar"><span class="glyphicon glyphicon-trash"></span><span class="sr-only">Eliminar</span></a>
                                                <?php endif; ?>
                                                <a class="cengi-action-btn is-view" href="ver_participante_curso.php?id=<?php echo (int) $row['idcurso']; ?>" data-tooltip="Ver participantes" aria-label="Ver participantes"><span class="glyphicon glyphicon-list-alt"></span><span class="sr-only">Ver participantes</span></a>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$esEstudiante): ?>
    <div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="model-header">
                    <button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h4 class="modal-title" id="myModalLabel">Eliminar registro</h4>
                </div>
                <div class="modal-body">Desea eliminar este registro?</div>
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
    <?php endif; ?>
</body>
</html>
