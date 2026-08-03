<?php
require_once "conexion.php";
require_once "menu.php";

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$puedeCalificar = cengi_puede_calificar();
$esEstudiante = cengi_es_estudiante();

$anioActual = (int) date('Y');
$busqueda = trim((string) ($_GET['q'] ?? $_POST['campo'] ?? ''));
$anio = trim((string) ($_GET['anio'] ?? $anioActual));
$categoriaId = (int) ($_GET['categoria'] ?? 0);
$estado = trim((string) ($_GET['estado'] ?? ''));
$modalidad = trim((string) ($_GET['modalidad'] ?? ''));

$categorias = $db->query("
    SELECT id, descripcion_categorias_cursos
    FROM categorias_cursos
    WHERE estado_categorias_cursos = 1
    ORDER BY descripcion_categorias_cursos
")->fetchAll(PDO::FETCH_ASSOC);

$modalidades = $db->query("
    SELECT DISTINCT tipo
    FROM cursos
    WHERE tipo IS NOT NULL AND TRIM(tipo) <> ''
    ORDER BY tipo
")->fetchAll(PDO::FETCH_COLUMN);

$ingenios = $db->query("
    SELECT id, nombre_ingenios
    FROM ingenios
    ORDER BY nombre_ingenios
")->fetchAll(PDO::FETCH_ASSOC);

$tiposCurso = array_values(array_unique(array_merge(
    ['Curso', 'Diplomado', 'Seminario', 'Presencial', 'Virtual', 'Hibrido'],
    $modalidades
)));

$mensajeCurso = trim((string) ($_GET['mensaje'] ?? ''));
$errorCurso = trim((string) ($_GET['error'] ?? ''));

$condiciones = [];
$params = [];

if ($esEstudiante) {
    $condiciones[] = '(a.usuarios_id = ? OR p.usuarios_id = ?)';
    $params[] = cengi_usuario_actual_id();
    $params[] = cengi_usuario_actual_id();
} elseif (!cengi_ve_todo_por_rol_o_ingenio()) {
    $normalizado = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
    $condiciones[] = cengi_sql_texto_normalizado('i.nombre_ingenios') . ' = ?';
    $params[] = $normalizado;
    $condiciones[] = "
        EXISTS (
            SELECT 1
            FROM asignaciones ax
            INNER JOIN participantes px ON px.id = ax.participantes_id
            INNER JOIN ingenios ix ON ix.id = px.ingenio_id
            WHERE ax.cursos_id = c.id
              AND ax.estado_asignaciones = 1
              AND " . cengi_sql_texto_normalizado('ix.nombre_ingenios') . " = ?
        )
    ";
    $params[] = $normalizado;
}

if ($busqueda !== '') {
    $condiciones[] = "(
        c.nombre_cursos LIKE ?
        OR ca.descripcion_categorias_cursos LIKE ?
        OR i.nombre_ingenios LIKE ?
        OR COALESCE(ins.nombre, '') LIKE ?
        OR CONCAT('CEN-', LPAD(c.id, 3, '0')) LIKE ?
    )";
    $termino = '%' . $busqueda . '%';
    array_push($params, $termino, $termino, $termino, $termino, $termino);
}

if ($anio !== '' && $anio !== 'todos' && ctype_digit($anio)) {
    $condiciones[] = 'YEAR(c.inicio) = ?';
    $params[] = (int) $anio;
}
if ($categoriaId > 0) {
    $condiciones[] = 'c.categoria_curso_id = ?';
    $params[] = $categoriaId;
}
if ($modalidad !== '') {
    $condiciones[] = 'c.tipo = ?';
    $params[] = $modalidad;
}
if ($estado === 'finalizado') {
    $condiciones[] = 'c.fin IS NOT NULL AND c.fin < CURDATE()';
} elseif ($estado === 'planificacion') {
    $condiciones[] = 'c.inicio IS NOT NULL AND c.inicio > CURDATE()';
} elseif ($estado === 'activo') {
    $condiciones[] = '(c.inicio IS NULL OR c.inicio <= CURDATE()) AND (c.fin IS NULL OR c.fin >= CURDATE())';
}

$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$joinsEstudiante = $esEstudiante ? "
    INNER JOIN asignaciones a ON a.cursos_id = c.id
    INNER JOIN participantes p ON p.id = a.participantes_id
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
" : '';
$camposEstudiante = $esEstudiante
    ? ', COALESCE(cc.posevaluacion, cc.evaluacion, 0) AS nota'
    : ', NULL AS nota';

$sql = "
    SELECT
        c.id AS idcurso,
        c.categoria_curso_id,
        c.ingenio_id,
        c.nombre_cursos,
        ca.descripcion_categorias_cursos,
        i.nombre_ingenios,
        COALESCE(ins.nombre, '') AS instructor_nombre,
        c.jornada_cursos,
        c.tipo,
        c.dias,
        c.horario,
        c.inicio,
        c.fin,
        (SELECT COUNT(*) FROM asignaciones ac WHERE ac.cursos_id = c.id AND ac.estado_asignaciones = 1) AS inscritos,
        (SELECT COUNT(*) FROM curso_modulos cm WHERE cm.curso_id = c.id) AS modulos_total
        {$camposEstudiante}
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    INNER JOIN ingenios i ON i.id = c.ingenio_id
    LEFT JOIN instructores ins ON ins.id = c.instructor_id
    {$joinsEstudiante}
    {$where}
    ORDER BY c.inicio DESC, c.nombre_cursos
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

function cengi_curso_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_curso_fecha_valida($fecha)
{
    return $fecha && $fecha !== '0000-00-00';
}

function cengi_curso_fecha($fecha)
{
    if (!cengi_curso_fecha_valida($fecha)) {
        return 'Sin fecha';
    }
    $meses = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $fechaObj = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$fechaObj) {
        return $fecha;
    }
    return $fechaObj->format('d') . ' ' . $meses[(int) $fechaObj->format('n')] . ' ' . $fechaObj->format('Y');
}

function cengi_curso_estado($inicio, $fin)
{
    $hoy = date('Y-m-d');
    $inicioValido = cengi_curso_fecha_valida($inicio) ? $inicio : null;
    $finValido = cengi_curso_fecha_valida($fin) ? $fin : null;
    if ($finValido !== null && $finValido < $hoy) {
        return ['Finalizado', 'is-finished'];
    }
    if ($inicioValido !== null && $inicioValido > $hoy) {
        return ['Planificación', 'is-upcoming'];
    }
    return ['Activo', 'is-active'];
}

function cengi_curso_avance($inicio, $fin)
{
    if (!cengi_curso_fecha_valida($inicio) || !cengi_curso_fecha_valida($fin)) {
        return [0, 'Sin calendario'];
    }
    $hoy = strtotime(date('Y-m-d'));
    $inicioTs = strtotime($inicio);
    $finTs = strtotime($fin);
    if ($hoy < $inicioTs) {
        return [0, 'Por iniciar'];
    }
    if ($hoy > $finTs) {
        return [100, 'Finalizado'];
    }
    if ($finTs <= $inicioTs) {
        return [50, 'En curso'];
    }
    $porcentaje = (int) round((($hoy - $inicioTs) / ($finTs - $inicioTs)) * 100);
    $porcentaje = max(4, min(96, $porcentaje));
    return [$porcentaje, $porcentaje . '% avanzado'];
}

function cengi_curso_codigo($id)
{
    return 'CEN-' . str_pad((string) (int) $id, 3, '0', STR_PAD_LEFT);
}

function cengi_curso_modal_atributos(array $curso)
{
    $datos = [
        'id' => $curso['idcurso'] ?? '',
        'categoria' => $curso['categoria_curso_id'] ?? '',
        'ingenio' => $curso['ingenio_id'] ?? '',
        'tipo' => $curso['tipo'] ?? '',
        'nombre' => $curso['nombre_cursos'] ?? '',
        'jornada' => $curso['jornada_cursos'] ?? '',
        'dias' => $curso['dias'] ?? '',
        'horario' => $curso['horario'] ?? '',
        'inicio' => $curso['inicio'] ?? '',
        'fin' => $curso['fin'] ?? '',
    ];

    $atributos = '';
    foreach ($datos as $nombre => $valor) {
        $atributos .= ' data-course-' . $nombre . '="' . cengi_curso_html($valor) . '"';
    }

    return $atributos;
}
?>

<?php if (false): // Diseño legado reemplazado por la vista SIGEC solicitada. ?>
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
<?php endif; ?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cursos · SIGEC</title>
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="css/bootstrap-theme.css">
    <link rel="stylesheet" type="text/css" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-courses-page">
    <?php if ($mensajeCurso === 'creado' || $mensajeCurso === 'actualizado'): ?>
        <div class="cengi-course-feedback is-success" role="status">
            <span class="glyphicon glyphicon-ok-circle" aria-hidden="true"></span>
            <span><?php echo $mensajeCurso === 'creado' ? 'El curso fue creado correctamente.' : 'Los cambios del curso fueron guardados correctamente.'; ?></span>
            <a href="ver_cursos.php" aria-label="Cerrar mensaje">&times;</a>
        </div>
    <?php elseif ($errorCurso !== ''): ?>
        <div class="cengi-course-feedback is-error" role="alert">
            <span class="glyphicon glyphicon-exclamation-sign" aria-hidden="true"></span>
            <span>No fue posible guardar el curso. Revisa los datos e inténtalo nuevamente.</span>
            <a href="ver_cursos.php" aria-label="Cerrar mensaje">&times;</a>
        </div>
    <?php endif; ?>

    <section class="cengi-courses-filter-card" aria-label="Filtros de cursos">
        <form class="cengi-courses-filters" action="ver_cursos.php" method="get">
            <div class="cengi-course-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" name="q" value="<?php echo cengi_curso_html($busqueda); ?>" placeholder="Buscar curso, código o instructor..." aria-label="Buscar curso, código o instructor">
            </div>
            <select name="anio" aria-label="Filtrar por año">
                <?php for ($opcionAnio = $anioActual; $opcionAnio >= $anioActual - 2; $opcionAnio--): ?>
                    <option value="<?php echo $opcionAnio; ?>" <?php echo (string) $opcionAnio === $anio ? 'selected' : ''; ?>>Año <?php echo $opcionAnio; ?><?php echo $opcionAnio === $anioActual ? ' (actual)' : ''; ?></option>
                <?php endfor; ?>
                <option value="todos" <?php echo $anio === 'todos' ? 'selected' : ''; ?>>Histórico completo</option>
            </select>
            <select name="categoria" aria-label="Filtrar por categoría">
                <option value="0">Todas las categorías</option>
                <?php foreach ($categorias as $categoria): ?>
                    <option value="<?php echo (int) $categoria['id']; ?>" <?php echo (int) $categoria['id'] === $categoriaId ? 'selected' : ''; ?>><?php echo cengi_curso_html($categoria['descripcion_categorias_cursos']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="estado" aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
                <option value="planificacion" <?php echo $estado === 'planificacion' ? 'selected' : ''; ?>>Planificación</option>
                <option value="activo" <?php echo $estado === 'activo' ? 'selected' : ''; ?>>Activo</option>
                <option value="finalizado" <?php echo $estado === 'finalizado' ? 'selected' : ''; ?>>Finalizado</option>
            </select>
            <select name="modalidad" aria-label="Filtrar por modalidad o tipo">
                <option value="">Todas las modalidades</option>
                <?php foreach ($modalidades as $opcionModalidad): ?>
                    <option value="<?php echo cengi_curso_html($opcionModalidad); ?>" <?php echo $modalidad === $opcionModalidad ? 'selected' : ''; ?>><?php echo cengi_curso_html($opcionModalidad); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="cengi-filter-submit" aria-label="Aplicar filtros"><span class="glyphicon glyphicon-filter" aria-hidden="true"></span><span>Filtrar</span></button>
            <?php if ($busqueda !== '' || $categoriaId > 0 || $estado !== '' || $modalidad !== '' || $anio !== (string) $anioActual): ?>
                <a href="ver_cursos.php" class="cengi-filter-clear" aria-label="Limpiar filtros">Limpiar</a>
            <?php endif; ?>
            <?php if ($puedeGestionar): ?>
                <button type="button" class="btn btn-primary cengi-course-create" data-course-modal="create" data-toggle="modal" data-target="#course-form-modal"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span>Crear curso</button>
            <?php endif; ?>
        </form>
    </section>

    <section class="cengi-courses-section">
        <div class="cengi-courses-table-wrap">
            <table class="cengi-courses-table">
                <thead><tr>
                    <th class="is-expand"><span class="sr-only">Detalle</span></th>
                    <th>Código</th><th>Curso</th><th>Categoría</th><th>Ingenio / Institución</th>
                    <th>Modalidad</th><th>Fecha inicio</th><th>Fecha fin</th><th>Avance</th>
                    <th>Instructor</th><th>Cupo</th><th>Estado</th><th class="is-actions"><span class="sr-only">Acciones</span></th>
                </tr></thead>
                <tbody>
                <?php if (!$cursos): ?>
                    <tr><td colspan="13" class="cengi-courses-empty"><span class="glyphicon glyphicon-education" aria-hidden="true"></span><strong>No se encontraron cursos</strong><small>Prueba con otros filtros o registra un curso nuevo.</small></td></tr>
                <?php endif; ?>

                <?php foreach ($cursos as $row):
                    [$estadoLabel, $estadoClase] = cengi_curso_estado($row['inicio'], $row['fin']);
                    [$avance, $avanceLabel] = cengi_curso_avance($row['inicio'], $row['fin']);
                    $cursoId = (int) $row['idcurso'];
                    $edicion = cengi_curso_fecha_valida($row['inicio']) ? substr($row['inicio'], 0, 4) : 'sin año';
                ?>
                    <tr class="cengi-course-row">
                        <td class="is-expand"><button type="button" class="cengi-course-expand" data-course-toggle="course-detail-<?php echo $cursoId; ?>" aria-controls="course-detail-<?php echo $cursoId; ?>" aria-expanded="false" aria-label="Mostrar detalle de <?php echo cengi_curso_html($row['nombre_cursos']); ?>"><span class="glyphicon glyphicon-menu-down" aria-hidden="true"></span></button></td>
                        <td class="cengi-course-code"><?php echo cengi_curso_codigo($cursoId); ?></td>
                        <td class="cengi-course-name"><strong><?php echo cengi_curso_html($row['nombre_cursos']); ?></strong><small><?php echo cengi_curso_html($row['descripcion_categorias_cursos']); ?> · edición <?php echo cengi_curso_html($edicion); ?></small></td>
                        <td><?php echo cengi_curso_html($row['descripcion_categorias_cursos']); ?></td>
                        <td><?php echo cengi_curso_html($row['nombre_ingenios']); ?></td>
                        <td><?php echo cengi_curso_html($row['tipo'] ?: 'Sin definir'); ?></td>
                        <td class="is-date"><?php echo cengi_curso_fecha($row['inicio']); ?></td>
                        <td class="is-date"><?php echo cengi_curso_fecha($row['fin']); ?></td>
                        <td class="cengi-course-progress-cell"><div class="cengi-course-progress" aria-label="<?php echo cengi_curso_html($avanceLabel); ?>"><span style="width:<?php echo $avance; ?>%;<?php echo $avance === 100 ? 'background:#CED2D5;' : ''; ?>"></span></div><small><?php echo cengi_curso_html($avanceLabel); ?></small></td>
                        <td><?php echo cengi_curso_html($row['instructor_nombre'] ?: 'Sin asignar'); ?></td>
                        <td><?php echo (int) $row['inscritos']; ?> inscritos</td>
                        <td><span class="cengi-status-badge <?php echo $estadoClase; ?>"><i></i><?php echo cengi_curso_html($estadoLabel); ?></span></td>
                        <td class="is-actions"><div class="cengi-row-actions">
                            <?php if ($puedeGestionar || $puedeCalificar): ?><a class="cengi-action-btn is-view" href="ver_participante_curso.php?id=<?php echo $cursoId; ?>" data-tooltip="Ver participantes" aria-label="Ver participantes"><span class="glyphicon glyphicon-eye-open"></span></a><?php endif; ?>
                            <?php if ($puedeGestionar): ?>
                                <button type="button" class="cengi-action-btn is-edit" data-course-modal="edit" data-toggle="modal" data-target="#course-form-modal"<?php echo cengi_curso_modal_atributos($row); ?> data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span></button>
                                <a class="cengi-action-btn is-delete" href="#" data-href="eliminar_cursos.php?id=<?php echo $cursoId; ?>" data-toggle="modal" data-target="#confirm-delete" data-tooltip="Eliminar" aria-label="Eliminar"><span class="glyphicon glyphicon-trash"></span></a>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                    <tr class="cengi-course-detail-row" id="course-detail-<?php echo $cursoId; ?>" hidden><td colspan="13">
                        <div class="cengi-course-detail">
                            <div><span class="cengi-course-detail-label">Información del curso</span><p><?php echo cengi_curso_html($row['dias'] ?: 'Días por definir'); ?> · <?php echo cengi_curso_html($row['horario'] ?: 'Horario por definir'); ?> · Jornada <?php echo cengi_curso_html($row['jornada_cursos'] ?: 'por definir'); ?></p>
                                <div class="cengi-course-detail-actions"><?php if ($puedeGestionar || $puedeCalificar): ?><a href="ver_participante_curso.php?id=<?php echo $cursoId; ?>" class="btn btn-default btn-sm">Ver seguimiento del curso</a><?php endif; ?> <?php if ($puedeGestionar): ?><button type="button" class="btn btn-default btn-sm" data-course-modal="edit" data-toggle="modal" data-target="#course-form-modal"<?php echo cengi_curso_modal_atributos($row); ?>>Editar curso</button><?php endif; ?></div>
                            </div>
                            <div class="cengi-course-detail-stats"><div><strong><?php echo (int) $row['modulos_total']; ?></strong><span>Módulos configurados</span></div><div><strong><?php echo (int) $row['inscritos']; ?></strong><span>Participantes inscritos</span></div><?php if ($esEstudiante): ?><div><strong><?php echo cengi_curso_html($row['nota']); ?></strong><span>Nota registrada</span></div><?php endif; ?></div>
                        </div>
                    </td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php if ($puedeGestionar): ?>
<div class="modal fade cengi-course-form-modal" id="course-form-modal" tabindex="-1" role="dialog" aria-labelledby="course-form-title" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="course-form" method="post" action="guardar_cursos.php" autocomplete="off">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
                    <span class="cengi-course-modal-kicker">Gestión de cursos</span>
                    <h4 class="modal-title" id="course-form-title">Crear curso</h4>
                    <p id="course-form-subtitle">Completa la información general y el calendario del curso.</p>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="course-form-id" value="">
                    <div class="cengi-course-modal-grid">
                        <div class="form-group">
                            <label for="course-form-category">Categoría</label>
                            <select class="form-control" id="course-form-category" name="categorias_cursos" required>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?php echo (int) $categoria['id']; ?>"><?php echo cengi_curso_html($categoria['descripcion_categorias_cursos']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="course-form-ingenio">Ingenio / institución</label>
                            <select class="form-control" id="course-form-ingenio" name="ingenio" required>
                                <?php foreach ($ingenios as $ingenio): ?>
                                    <option value="<?php echo (int) $ingenio['id']; ?>"><?php echo cengi_curso_html($ingenio['nombre_ingenios']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="course-form-type">Tipo / modalidad</label>
                            <select class="form-control" id="course-form-type" name="tipo" required>
                                <?php foreach ($tiposCurso as $tipoCurso): ?>
                                    <option value="<?php echo cengi_curso_html($tipoCurso); ?>"><?php echo cengi_curso_html($tipoCurso); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="course-form-shift">Jornada</label>
                            <select class="form-control" id="course-form-shift" name="jornada_cursos" required>
                                <option value="Matutina">Matutina</option>
                                <option value="Vespertina">Vespertina</option>
                                <option value="Todo Completo">Todo completo</option>
                            </select>
                        </div>
                        <div class="form-group is-full">
                            <label for="course-form-name">Nombre del curso</label>
                            <input type="text" class="form-control" id="course-form-name" name="nombre_cursos" placeholder="Ej. Manejo integrado de plagas" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="course-form-days">Días</label>
                            <input type="text" class="form-control" id="course-form-days" name="dias" placeholder="Ej. Lunes y miércoles" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="course-form-schedule">Horario</label>
                            <input type="text" class="form-control" id="course-form-schedule" name="horario" placeholder="Ej. 08:00 - 12:00" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="course-form-start">Fecha de inicio</label>
                            <input type="date" class="form-control" id="course-form-start" name="inicio" required>
                        </div>
                        <div class="form-group">
                            <label for="course-form-end">Fecha de finalización</label>
                            <input type="date" class="form-control" id="course-form-end" name="fin" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="course-form-submit"><span class="glyphicon glyphicon-floppy-disk" aria-hidden="true"></span>Crear curso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="delete-course-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title" id="delete-course-title">Eliminar curso</h4></div>
    <div class="modal-body">¿Deseas eliminar este curso? Esta acción no se puede deshacer.</div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><a class="btn btn-danger btn-ok">Eliminar</a></div>
</div></div></div>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-course-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var row = document.getElementById(button.getAttribute('data-course-toggle'));
            if (!row) return;
            var open = row.hasAttribute('hidden');
            row.toggleAttribute('hidden', !open);
            button.classList.toggle('is-open', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
    document.querySelectorAll('.cengi-courses-filters select').forEach(function (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    });

    var courseModal = $('#course-form-modal');
    var courseForm = document.getElementById('course-form');
    var courseSubmit = document.getElementById('course-form-submit');
    var courseStart = document.getElementById('course-form-start');
    var courseEnd = document.getElementById('course-form-end');

    function setSelectValue(select, value) {
        if (!select || !value) return;
        var exists = Array.prototype.some.call(select.options, function (option) {
            return option.value === value;
        });
        if (!exists) {
            select.add(new Option(value, value));
        }
        select.value = value;
    }

    courseModal.on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var mode = trigger && trigger.getAttribute('data-course-modal') === 'edit' ? 'edit' : 'create';
        courseForm.reset();
        courseForm.action = mode === 'edit' ? 'actualizar_cursos.php' : 'guardar_cursos.php';
        document.getElementById('course-form-id').value = mode === 'edit' ? trigger.getAttribute('data-course-id') || '' : '';
        document.getElementById('course-form-title').textContent = mode === 'edit' ? 'Editar curso' : 'Crear curso';
        document.getElementById('course-form-subtitle').textContent = mode === 'edit'
            ? 'Actualiza la información general y el calendario del curso seleccionado.'
            : 'Completa la información general y el calendario del curso.';
        courseSubmit.lastChild.nodeValue = mode === 'edit' ? ' Guardar cambios' : ' Crear curso';

        if (mode === 'edit') {
            setSelectValue(document.getElementById('course-form-category'), trigger.getAttribute('data-course-categoria') || '');
            setSelectValue(document.getElementById('course-form-ingenio'), trigger.getAttribute('data-course-ingenio') || '');
            setSelectValue(document.getElementById('course-form-type'), trigger.getAttribute('data-course-tipo') || '');
            setSelectValue(document.getElementById('course-form-shift'), trigger.getAttribute('data-course-jornada') || '');
            document.getElementById('course-form-name').value = trigger.getAttribute('data-course-nombre') || '';
            document.getElementById('course-form-days').value = trigger.getAttribute('data-course-dias') || '';
            document.getElementById('course-form-schedule').value = trigger.getAttribute('data-course-horario') || '';
            courseStart.value = trigger.getAttribute('data-course-inicio') || '';
            courseEnd.value = trigger.getAttribute('data-course-fin') || '';
        }

        courseEnd.min = courseStart.value;
        courseSubmit.disabled = false;
    });

    courseStart.addEventListener('change', function () {
        courseEnd.min = courseStart.value;
        if (courseEnd.value && courseEnd.value < courseStart.value) {
            courseEnd.value = courseStart.value;
        }
    });

    courseForm.addEventListener('submit', function () {
        courseSubmit.disabled = true;
    });

    $('#confirm-delete').on('show.bs.modal', function (event) {
        $(this).find('.btn-ok').attr('href', $(event.relatedTarget).data('href'));
    });
})();
</script>
</body>
</html>
