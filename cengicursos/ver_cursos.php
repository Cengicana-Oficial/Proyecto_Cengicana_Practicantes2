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

$instructores = $db->query("
    SELECT id, nombre, especialidad
    FROM instructores
    WHERE estado = 1
    ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

$areasCatalogo = $db->query("
    SELECT nombre
    FROM areas_tecnicas
    WHERE estado = 1
    ORDER BY nombre
")->fetchAll(PDO::FETCH_COLUMN);
$areasTecnicas = $areasCatalogo;

$siguienteCursoId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM cursos')->fetchColumn();
$codigoSugerido = 'CEN-T-' . str_pad((string) $siguienteCursoId, 3, '0', STR_PAD_LEFT);

$tiposCurso = array_values(array_unique(array_merge(
    ['Presencial', 'Virtual', 'Híbrida', 'Hibrido'],
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
    // Un curso no pertenece a un solo ingenio (no se hacen cursos directos: gente de
    // cualquier ingenio puede inscribirse), asi que la visibilidad se decide solo por
    // si el ingenio del usuario tiene participantes inscritos, sin importar cual
    // ingenio quedo registrado como "Ingenio / Institucion" del curso.
    $normalizado = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
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
        OR COALESCE(c.codigo_curso, '') LIKE ?
        OR CONCAT('CEN-', LPAD(c.id, 3, '0')) LIKE ?
    )";
    $termino = '%' . $busqueda . '%';
    array_push($params, $termino, $termino, $termino, $termino, $termino, $termino);
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

$porPaginaOpciones = [10, 15, 20];
$porPagina = (int) ($_GET['por_pagina'] ?? 15);
if (!in_array($porPagina, $porPaginaOpciones, true)) {
    $porPagina = 15;
}
$paginaActual = (int) ($_GET['pagina'] ?? 1);
if ($paginaActual < 1) {
    $paginaActual = 1;
}

$sqlConteo = "
    SELECT COUNT(*)
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    INNER JOIN ingenios i ON i.id = c.ingenio_id
    LEFT JOIN instructores ins ON ins.id = c.instructor_id
    {$joinsEstudiante}
    {$where}
";
$stmtConteo = $db->prepare($sqlConteo);
$stmtConteo->execute($params);
$totalCursos = (int) $stmtConteo->fetchColumn();

$totalPaginas = max(1, (int) ceil($totalCursos / $porPagina));
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $porPagina;

function cengi_curso_pagina_url(int $pagina): string
{
    $query = $_GET;
    $query['pagina'] = $pagina;
    return 'ver_cursos.php?' . http_build_query($query);
}

$sql = "
    SELECT
        c.id AS idcurso,
        c.codigo_curso,
        c.categoria_curso_id,
        c.ingenio_id,
        c.instructor_id,
        c.actividad_tipo,
        c.nombre_cursos,
        c.area_tecnica,
        ca.descripcion_categorias_cursos,
        i.nombre_ingenios,
        COALESCE(ins.nombre, '') AS instructor_nombre,
        c.jornada_cursos,
        c.tipo,
        c.dias,
        c.horario,
        c.cupo,
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
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);
$posicion = 1;
foreach ($params as $valorParam) {
    $stmt->bindValue($posicion, $valorParam);
    $posicion++;
}
$stmt->bindValue($posicion++, $porPagina, PDO::PARAM_INT);
$stmt->bindValue($posicion++, $offset, PDO::PARAM_INT);
$stmt->execute();
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$modulosPorCurso = [];
if ($cursos) {
    $idsCursos = array_map('intval', array_column($cursos, 'idcurso'));
    $marcadores = implode(',', array_fill(0, count($idsCursos), '?'));
    // Un modulo puede tener 0, 1 o varios instructores propios (co-ensenanza), asi que
    // se trae con un LEFT JOIN hacia curso_modulo_instructores + instructores y se agrupa
    // en PHP en un array de {id, nombre} por modulo (una fila NULL significa 0
    // instructores propios para ese modulo, es decir que hereda al instructor principal).
    $stmtModulos = $db->prepare("
        SELECT cm.id, cm.curso_id, cm.nombre, cm.horas, cm.orden, cm.acepta_pre, cm.acepta_post,
            cmi.instructor_id, ins2.nombre AS instructor_nombre
        FROM curso_modulos cm
        LEFT JOIN curso_modulo_instructores cmi ON cmi.curso_modulo_id = cm.id
        LEFT JOIN instructores ins2 ON ins2.id = cmi.instructor_id
        WHERE cm.curso_id IN ($marcadores)
        ORDER BY cm.curso_id, cm.orden, cm.id, ins2.nombre
    ");
    $stmtModulos->execute($idsCursos);
    $modulosIndex = [];
    foreach ($stmtModulos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $moduloId = (int) $fila['id'];
        if (!isset($modulosIndex[$moduloId])) {
            $modulosIndex[$moduloId] = [
                'id' => $fila['id'],
                'curso_id' => $fila['curso_id'],
                'nombre' => $fila['nombre'],
                'horas' => $fila['horas'],
                'orden' => $fila['orden'],
                'acepta_pre' => $fila['acepta_pre'],
                'acepta_post' => $fila['acepta_post'],
                'instructores' => [],
            ];
            $modulosPorCurso[(int) $fila['curso_id']][] = &$modulosIndex[$moduloId];
        }
        if ((int) ($fila['instructor_id'] ?? 0) > 0) {
            $modulosIndex[$moduloId]['instructores'][] = [
                'id' => (int) $fila['instructor_id'],
                'nombre' => $fila['instructor_nombre'],
            ];
        }
    }
    unset($modulosIndex);
}

foreach ($cursos as &$cursoFila) {
    $cursoFila['modulos'] = $modulosPorCurso[(int) $cursoFila['idcurso']] ?? [];
}
unset($cursoFila);

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

function cengi_curso_codigo_visible(array $curso)
{
    $codigo = trim((string) ($curso['codigo_curso'] ?? ''));
    return $codigo !== '' ? $codigo : cengi_curso_codigo($curso['idcurso'] ?? 0);
}

function cengi_curso_modal_atributos(array $curso)
{
    $datos = [
        'id' => $curso['idcurso'] ?? '',
        'codigo' => cengi_curso_codigo_visible($curso),
        'categoria' => $curso['categoria_curso_id'] ?? '',
        'instructor' => $curso['instructor_id'] ?? '',
        'actividad' => $curso['actividad_tipo'] ?? 'formacion_academica',
        'tipo' => $curso['tipo'] ?? '',
        'nombre' => $curso['nombre_cursos'] ?? '',
        'area' => $curso['area_tecnica'] ?? '',
        'jornada' => $curso['jornada_cursos'] ?? '',
        'dias' => $curso['dias'] ?? '',
        'horario' => $curso['horario'] ?? '',
        'inicio' => $curso['inicio'] ?? '',
        'fin' => $curso['fin'] ?? '',
        'cupo' => $curso['cupo'] ?? '',
        'modulos' => json_encode($curso['modulos'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
    ];

    $atributos = '';
    foreach ($datos as $nombre => $valor) {
        $atributos .= ' data-course-' . $nombre . '="' . cengi_curso_html($valor) . '"';
    }

    return $atributos;
}
?>

<?php if (false): // Diseño legado reemplazado por la vista Cengicursos solicitada. ?>
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
                                                    <button type="button" class="cengi-action-btn is-copy-link" data-copy-url="<?php echo htmlspecialchars('inscripcion.php?curso_id=' . (int) $row['idcurso']); ?>" data-tooltip="Copiar enlace de inscripción" aria-label="Copiar enlace de inscripción"><span class="glyphicon glyphicon-link"></span><span class="sr-only">Copiar enlace de inscripción</span></button>
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
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cursos · Cengicursos</title>
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
            <select name="por_pagina" aria-label="Cursos por página">
                <?php foreach ($porPaginaOpciones as $opcionPorPagina): ?>
                    <option value="<?php echo $opcionPorPagina; ?>" <?php echo $opcionPorPagina === $porPagina ? 'selected' : ''; ?>><?php echo $opcionPorPagina; ?> por página</option>
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
                    <th>Código</th><th>Curso</th><th>Categoría</th>
                    <th>Modalidad</th><th>Fecha inicio</th><th>Fecha fin</th><th>Avance</th>
                    <th>Instructor</th><th>Cupo</th><th>Estado</th><th class="is-actions"><span class="sr-only">Acciones</span></th>
                </tr></thead>
                <tbody>
                <?php if (!$cursos): ?>
                    <tr><td colspan="12" class="cengi-courses-empty"><span class="glyphicon glyphicon-education" aria-hidden="true"></span><strong>No se encontraron cursos</strong><small>Prueba con otros filtros o registra un curso nuevo.</small></td></tr>
                <?php endif; ?>

                <?php foreach ($cursos as $row):
                    [$estadoLabel, $estadoClase] = cengi_curso_estado($row['inicio'], $row['fin']);
                    [$avance, $avanceLabel] = cengi_curso_avance($row['inicio'], $row['fin']);
                    $cursoId = (int) $row['idcurso'];
                    $edicion = cengi_curso_fecha_valida($row['inicio']) ? substr($row['inicio'], 0, 4) : 'sin año';
                ?>
                    <tr class="cengi-course-row">
                        <td class="is-expand"><button type="button" class="cengi-course-expand" data-course-toggle="course-detail-<?php echo $cursoId; ?>" aria-controls="course-detail-<?php echo $cursoId; ?>" aria-expanded="false" aria-label="Mostrar detalle de <?php echo cengi_curso_html($row['nombre_cursos']); ?>"><span class="glyphicon glyphicon-menu-down" aria-hidden="true"></span></button></td>
                        <td class="cengi-course-code" data-label="Código"><?php echo cengi_curso_html(cengi_curso_codigo_visible($row)); ?></td>
                        <td class="cengi-course-name" data-label="Curso"><strong><?php echo cengi_curso_html($row['nombre_cursos']); ?></strong><small><?php echo cengi_curso_html($row['descripcion_categorias_cursos']); ?> · edición <?php echo cengi_curso_html($edicion); ?></small></td>
                        <td data-label="Categoría"><?php echo cengi_curso_html($row['descripcion_categorias_cursos']); ?></td>
                        <td data-label="Modalidad"><?php echo cengi_curso_html($row['tipo'] ?: 'Sin definir'); ?></td>
                        <td class="is-date" data-label="Fecha inicio"><?php echo cengi_curso_fecha($row['inicio']); ?></td>
                        <td class="is-date" data-label="Fecha fin"><?php echo cengi_curso_fecha($row['fin']); ?></td>
                        <td class="cengi-course-progress-cell" data-label="Avance"><div class="cengi-course-progress" aria-label="<?php echo cengi_curso_html($avanceLabel); ?>"><span style="width:<?php echo $avance; ?>%;<?php echo $avance === 100 ? 'background:#CED2D5;' : ''; ?>"></span></div><small><?php echo cengi_curso_html($avanceLabel); ?></small></td>
                        <td data-label="Instructor"><?php echo cengi_curso_html($row['instructor_nombre'] ?: 'Sin asignar'); ?></td>
                        <td data-label="Cupo"><?php echo (int) $row['inscritos']; ?><?php echo (int) $row['cupo'] > 0 ? ' / ' . (int) $row['cupo'] : ''; ?></td>
                        <td data-label="Estado"><span class="cengi-status-badge <?php echo $estadoClase; ?>"><i></i><?php echo cengi_curso_html($estadoLabel); ?></span></td>
                        <td class="is-actions"><div class="cengi-row-actions">
                            <?php if ($puedeGestionar || $puedeCalificar): ?><a class="cengi-action-btn is-view" href="ver_participante_curso.php?id=<?php echo $cursoId; ?>" data-tooltip="Ver participantes" aria-label="Ver participantes"><span class="glyphicon glyphicon-eye-open"></span></a><?php endif; ?>
                            <?php if ($puedeGestionar): ?>
                                <button type="button" class="cengi-action-btn is-edit" data-course-modal="edit" data-toggle="modal" data-target="#course-form-modal"<?php echo cengi_curso_modal_atributos($row); ?> data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span></button>
                                <a class="cengi-action-btn is-delete" href="#" data-href="eliminar_cursos.php?id=<?php echo $cursoId; ?>" data-toggle="modal" data-target="#confirm-delete" data-tooltip="Eliminar" aria-label="Eliminar"><span class="glyphicon glyphicon-trash"></span></a>
                                <button type="button" class="cengi-action-btn is-copy-link" data-copy-url="<?php echo htmlspecialchars('inscripcion.php?curso_id=' . $cursoId); ?>" data-tooltip="Copiar enlace de inscripción" aria-label="Copiar enlace de inscripción"><span class="glyphicon glyphicon-link"></span></button>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                    <tr class="cengi-course-detail-row" id="course-detail-<?php echo $cursoId; ?>" hidden><td colspan="12">
                        <div class="cengi-course-detail">
                            <div class="cengi-course-detail-copy">
                                <span class="cengi-course-detail-label">Información del curso</span>
                                <p><?php echo cengi_curso_html($row['dias'] ?: 'Días por definir'); ?> · <?php echo cengi_curso_html($row['horario'] ?: 'Horario por definir'); ?> · Jornada <?php echo cengi_curso_html($row['jornada_cursos'] ?: 'por definir'); ?></p>
                                <div class="cengi-course-detail-meta">
                                    <span><b>Actividad</b><?php echo ($row['actividad_tipo'] ?? '') === 'evento_tecnico' ? 'Evento técnico' : 'Formación académica'; ?></span>
                                    <span><b>Área técnica</b><?php echo cengi_curso_html($row['area_tecnica'] ?: 'Sin definir'); ?></span>
                                    <span><b>Instructor</b><?php echo cengi_curso_html($row['instructor_nombre'] ?: 'Sin asignar'); ?></span>
                                    <span><b>Cupo</b><?php echo (int) $row['cupo'] > 0 ? (int) $row['cupo'] . ' participantes' : 'Sin límite definido'; ?></span>
                                </div>
                                <div class="cengi-course-detail-actions"><?php if ($puedeGestionar || $puedeCalificar): ?><a href="ver_participante_curso.php?id=<?php echo $cursoId; ?>" class="btn btn-default btn-sm">Ver seguimiento del curso</a><?php endif; ?> <?php if ($puedeGestionar): ?><button type="button" class="btn btn-default btn-sm" data-course-modal="edit" data-toggle="modal" data-target="#course-form-modal"<?php echo cengi_curso_modal_atributos($row); ?>>Editar curso</button><?php endif; ?></div>
                            </div>
                            <div class="cengi-course-detail-modules">
                                <span class="cengi-course-detail-label">Módulos de evaluación configurados</span>
                                <?php if (!empty($row['modulos'])): ?>
                                    <div class="cengi-course-modules-mini is-head"><span>Módulo</span><span>Horas</span><span>Instructor(es)</span><span>Pre</span><span>Post</span></div>
                                    <?php foreach ($row['modulos'] as $modulo):
                                        $instructoresModuloNombres = array_map(function ($instructorModulo) {
                                            return $instructorModulo['nombre'];
                                        }, $modulo['instructores'] ?? []);
                                        $instructoresModuloTexto = $instructoresModuloNombres ? implode(', ', $instructoresModuloNombres) : 'Instructor del curso';
                                    ?>
                                        <div class="cengi-course-modules-mini"><span><?php echo cengi_curso_html($modulo['nombre']); ?></span><span><?php echo cengi_curso_html(rtrim(rtrim(number_format((float) $modulo['horas'], 2, '.', ''), '0'), '.')); ?> h</span><span><?php echo cengi_curso_html($instructoresModuloTexto); ?></span><span><?php echo (int) $modulo['acepta_pre'] === 1 ? '✓' : '—'; ?></span><span><?php echo (int) $modulo['acepta_post'] === 1 ? '✓' : '—'; ?></span></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="cengi-course-modules-empty"><?php echo ($row['actividad_tipo'] ?? '') === 'evento_tecnico' ? 'Este evento técnico no requiere evaluación académica.' : 'Aún no se han configurado módulos de evaluación.'; ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalCursos > 0):
            $primerRegistro = $offset + 1;
            $ultimoRegistro = min($offset + $porPagina, $totalCursos);
        ?>
            <div class="cengi-courses-pagination">
                <div class="cengi-courses-pagination-info">
                    Mostrando <?php echo $primerRegistro; ?>–<?php echo $ultimoRegistro; ?> de <?php echo $totalCursos; ?> cursos
                </div>
                <?php if ($totalPaginas > 1): ?>
                    <nav class="cengi-courses-pagination-nav" aria-label="Paginación de cursos">
                        <a class="cengi-pagination-btn is-prev<?php echo $paginaActual <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo $paginaActual > 1 ? cengi_curso_html(cengi_curso_pagina_url($paginaActual - 1)) : '#'; ?>" aria-label="Página anterior"<?php echo $paginaActual <= 1 ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><span class="glyphicon glyphicon-chevron-left" aria-hidden="true"></span><span>Anterior</span></a>
                        <div class="cengi-pagination-pages">
                            <?php
                            $ventana = 2;
                            $rangoInicio = max(1, $paginaActual - $ventana);
                            $rangoFin = min($totalPaginas, $paginaActual + $ventana);
                            if ($rangoInicio > 1): ?>
                                <a class="cengi-pagination-page" href="<?php echo cengi_curso_html(cengi_curso_pagina_url(1)); ?>">1</a>
                                <?php if ($rangoInicio > 2): ?><span class="cengi-pagination-ellipsis">&hellip;</span><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($pagina = $rangoInicio; $pagina <= $rangoFin; $pagina++): ?>
                                <?php if ($pagina === $paginaActual): ?>
                                    <span class="cengi-pagination-page is-current" aria-current="page"><?php echo $pagina; ?></span>
                                <?php else: ?>
                                    <a class="cengi-pagination-page" href="<?php echo cengi_curso_html(cengi_curso_pagina_url($pagina)); ?>"><?php echo $pagina; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($rangoFin < $totalPaginas): ?>
                                <?php if ($rangoFin < $totalPaginas - 1): ?><span class="cengi-pagination-ellipsis">&hellip;</span><?php endif; ?>
                                <a class="cengi-pagination-page" href="<?php echo cengi_curso_html(cengi_curso_pagina_url($totalPaginas)); ?>"><?php echo $totalPaginas; ?></a>
                            <?php endif; ?>
                        </div>
                        <a class="cengi-pagination-btn is-next<?php echo $paginaActual >= $totalPaginas ? ' is-disabled' : ''; ?>" href="<?php echo $paginaActual < $totalPaginas ? cengi_curso_html(cengi_curso_pagina_url($paginaActual + 1)) : '#'; ?>" aria-label="Página siguiente"<?php echo $paginaActual >= $totalPaginas ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><span>Siguiente</span><span class="glyphicon glyphicon-chevron-right" aria-hidden="true"></span></a>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
                    <input type="hidden" name="modulos_present" value="1">
                    <div class="cengi-course-modal-grid">
                        <div class="form-group is-full">
                            <label>Tipo de actividad</label>
                            <div class="cengi-course-activity-cards" role="radiogroup" aria-label="Tipo de actividad">
                                <label class="cengi-course-activity-card is-selected">
                                    <input type="radio" name="actividad_tipo" value="formacion_academica" checked>
                                    <span class="glyphicon glyphicon-education"></span>
                                    <span><strong>Formación académica</strong><small>Asistencia, pre/post evaluación, nota final y diploma.</small></span>
                                </label>
                                <label class="cengi-course-activity-card">
                                    <input type="radio" name="actividad_tipo" value="evento_tecnico">
                                    <span class="glyphicon glyphicon-list-alt"></span>
                                    <span><strong>Evento técnico</strong><small>Registro, asistencia por QR, gafete y constancia.</small></span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="course-form-code">Código del curso</label>
                            <input type="text" class="form-control cengi-course-code-input" id="course-form-code" name="codigo_curso" value="<?php echo cengi_curso_html($codigoSugerido); ?>" placeholder="CEN-T-000" maxlength="30" required>
                        </div>
                        <div class="form-group">
                            <label for="course-form-category">Categoría</label>
                            <select class="form-control" id="course-form-category" name="categorias_cursos" required>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?php echo (int) $categoria['id']; ?>"><?php echo cengi_curso_html($categoria['descripcion_categorias_cursos']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group is-full">
                            <label for="course-form-name">Nombre del curso</label>
                            <input type="text" class="form-control" id="course-form-name" name="nombre_cursos" placeholder="Ej. Manejo integrado de plagas" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="course-form-area">Área técnica</label>
                            <select class="form-control" id="course-form-area" name="area_tecnica" required>
                                <?php foreach ($areasTecnicas as $areaTecnica): ?>
                                    <option value="<?php echo cengi_curso_html($areaTecnica); ?>"><?php echo cengi_curso_html($areaTecnica); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="course-form-type">Modalidad</label>
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
                                <option value="Fin de semana">Fin de semana</option>
                                <option value="Todo Completo">Todo completo</option>
                            </select>
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
                        <div class="form-group">
                            <label for="course-form-instructor">Instructor</label>
                            <select class="form-control" id="course-form-instructor" name="instructor_id">
                                <option value="">Sin instructor asignado</option>
                                <?php foreach ($instructores as $instructor): ?>
                                    <option value="<?php echo (int) $instructor['id']; ?>"><?php echo cengi_curso_html($instructor['nombre'] . ($instructor['especialidad'] !== '' ? ' — ' . $instructor['especialidad'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="course-form-capacity">Cupo</label>
                            <input type="number" class="form-control" id="course-form-capacity" name="cupo" min="1" max="10000" placeholder="35">
                        </div>
                    </div>

                    <section class="cengi-course-modules-editor" id="course-modules-editor">
                        <div class="cengi-course-modules-editor-head">
                            <div><strong>Módulos de evaluación</strong><small>Define los módulos antes de abrir inscripciones; esta configuración habilita la carga de calificaciones.</small></div>
                            <button type="button" class="btn btn-default btn-sm" id="course-module-add"><span class="glyphicon glyphicon-plus"></span> Agregar módulo</button>
                        </div>
                        <div class="cengi-course-module-row is-head"><div class="cengi-course-module-main"><span>Módulo</span><span>Horas</span><span>Pre-eval.</span><span>Post-eval.</span><span></span></div></div>
                        <div id="course-module-rows"></div>
                        <div class="cengi-course-modules-notice"><span class="glyphicon glyphicon-info-sign"></span><span>Si el curso no requiere calificación por módulo, déjalo vacío: el sistema solo solicitará asistencia y constancia.</span></div>
                    </section>
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
    var cengiInstructoresDisponibles = <?php echo json_encode(array_map(function ($instructor) {
        return [
            'id' => (int) $instructor['id'],
            'nombre' => $instructor['nombre'] . ($instructor['especialidad'] !== '' ? ' — ' . $instructor['especialidad'] : ''),
        ];
    }, $instructores), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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

    document.querySelectorAll('[data-copy-url]').forEach(function (button) {
        button.addEventListener('click', function () {
            var url = new URL(button.getAttribute('data-copy-url'), window.location.href).href;
            navigator.clipboard.writeText(url).then(function () {
                var tooltip = button.getAttribute('data-tooltip');
                var icon = button.querySelector('.glyphicon');
                button.setAttribute('data-tooltip', '¡Enlace copiado!');
                if (icon) icon.className = 'glyphicon glyphicon-ok';
                setTimeout(function () {
                    button.setAttribute('data-tooltip', tooltip);
                    if (icon) icon.className = 'glyphicon glyphicon-link';
                }, 1500);
            });
        });
    });

    var courseModal = $('#course-form-modal');
    var courseForm = document.getElementById('course-form');
    var courseSubmit = document.getElementById('course-form-submit');
    var courseStart = document.getElementById('course-form-start');
    var courseEnd = document.getElementById('course-form-end');
    var moduleRows = document.getElementById('course-module-rows');
    var moduleIndex = 0;

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

    function updateActivityCards() {
        document.querySelectorAll('.cengi-course-activity-card').forEach(function (card) {
            var radio = card.querySelector('input[type="radio"]');
            card.classList.toggle('is-selected', radio && radio.checked);
        });
    }

    function buildInstructorOptions(select) {
        select.add(new Option('Selecciona un instructor', ''));
        cengiInstructoresDisponibles.forEach(function (instructor) {
            select.add(new Option(instructor.nombre, String(instructor.id)));
        });
    }

    function addModuleRow(module) {
        if (!moduleRows) return;
        module = module || {};
        var instructoresModulo = Array.isArray(module.instructores) ? module.instructores : [];
        moduleIndex += 1;
        var key = 'm' + moduleIndex;
        var row = document.createElement('div');
        row.className = 'cengi-course-module-row';
        row.innerHTML =
            '<div class="cengi-course-module-main">' +
                '<input type="hidden" name="modulos[' + key + '][id]">' +
                '<input type="text" class="form-control" name="modulos[' + key + '][nombre]" placeholder="Ej. Agronomía cañera" maxlength="255">' +
                '<input type="number" class="form-control" name="modulos[' + key + '][horas]" placeholder="Horas" min="0" max="9999" step="0.25">' +
                '<label class="cengi-course-module-check"><input type="checkbox" name="modulos[' + key + '][pre]" value="1"><span>Sí</span></label>' +
                '<label class="cengi-course-module-check"><input type="checkbox" name="modulos[' + key + '][post]" value="1"><span>Sí</span></label>' +
                '<button type="button" class="cengi-course-module-remove" aria-label="Eliminar módulo"><span class="glyphicon glyphicon-remove"></span></button>' +
            '</div>' +
            '<div class="cengi-course-module-instructors">' +
                '<label class="cengi-course-module-toggle">' +
                    '<input type="checkbox" name="modulos[' + key + '][mismo_principal]" value="1">' +
                    '<span>Mismo instructor que el principal</span>' +
                '</label>' +
                '<label class="cengi-course-module-toggle cengi-course-module-multi-toggle">' +
                    '<input type="checkbox" name="modulos[' + key + '][multi_instructor]" value="1">' +
                    '<span>¿Este módulo tendrá más de un instructor?</span>' +
                '</label>' +
                '<div class="cengi-course-module-instructor-single">' +
                    '<select class="form-control" name="modulos[' + key + '][instructor_id]"></select>' +
                '</div>' +
                '<div class="cengi-course-module-instructor-multi">' +
                    '<div class="cengi-course-module-instructor-multi-rows"></div>' +
                    '<button type="button" class="btn btn-default btn-xs cengi-course-module-instructor-add"><span class="glyphicon glyphicon-plus"></span> Agregar instructor</button>' +
                '</div>' +
            '</div>';
        row.querySelector('[name$="[id]"]').value = module.id || '';
        row.querySelector('[name$="[nombre]"]').value = module.nombre || '';
        row.querySelector('[name$="[horas]"]').value = module.horas == null ? '' : module.horas;
        row.querySelector('[name$="[pre]"]').checked = String(module.acepta_pre == null ? 1 : module.acepta_pre) === '1';
        row.querySelector('[name$="[post]"]').checked = String(module.acepta_post == null ? 1 : module.acepta_post) === '1';
        row.querySelector('.cengi-course-module-remove').addEventListener('click', function () { row.remove(); });

        var mismoPrincipalCheckbox = row.querySelector('[name$="[mismo_principal]"]');
        var multiInstructorCheckbox = row.querySelector('[name$="[multi_instructor]"]');
        var multiToggleLabel = row.querySelector('.cengi-course-module-multi-toggle');
        var singleWrap = row.querySelector('.cengi-course-module-instructor-single');
        var multiWrap = row.querySelector('.cengi-course-module-instructor-multi');
        var singleSelect = row.querySelector('[name$="[instructor_id]"]');
        var multiRowsContainer = row.querySelector('.cengi-course-module-instructor-multi-rows');
        var multiAddButton = row.querySelector('.cengi-course-module-instructor-add');

        buildInstructorOptions(singleSelect);

        function addInstructorMultiRow(selectedId) {
            var instructorRow = document.createElement('div');
            instructorRow.className = 'cengi-course-module-instructor-row';
            var select = document.createElement('select');
            select.className = 'form-control';
            select.name = 'modulos[' + key + '][instructores][]';
            buildInstructorOptions(select);
            select.value = selectedId ? String(selectedId) : '';
            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'cengi-course-module-instructor-remove';
            removeButton.setAttribute('aria-label', 'Quitar instructor');
            removeButton.innerHTML = '<span class="glyphicon glyphicon-remove"></span>';
            removeButton.addEventListener('click', function () { instructorRow.remove(); });
            instructorRow.appendChild(select);
            instructorRow.appendChild(removeButton);
            multiRowsContainer.appendChild(instructorRow);
        }

        function updateInstructorVisibility() {
            var mismoPrincipal = mismoPrincipalCheckbox.checked;
            multiToggleLabel.hidden = mismoPrincipal;
            if (mismoPrincipal) {
                multiInstructorCheckbox.checked = false;
            }
            var multi = !mismoPrincipal && multiInstructorCheckbox.checked;
            singleWrap.hidden = mismoPrincipal || multi;
            multiWrap.hidden = mismoPrincipal || !multi;
            if (multi && multiRowsContainer.children.length === 0) {
                addInstructorMultiRow(singleSelect.value || null);
            }
        }

        multiAddButton.addEventListener('click', function () { addInstructorMultiRow(); });
        mismoPrincipalCheckbox.addEventListener('change', updateInstructorVisibility);
        multiInstructorCheckbox.addEventListener('change', updateInstructorVisibility);

        if (instructoresModulo.length >= 2) {
            mismoPrincipalCheckbox.checked = false;
            multiInstructorCheckbox.checked = true;
            instructoresModulo.forEach(function (instructorModulo) {
                addInstructorMultiRow(instructorModulo.id);
            });
        } else if (instructoresModulo.length === 1) {
            mismoPrincipalCheckbox.checked = false;
            multiInstructorCheckbox.checked = false;
            singleSelect.value = String(instructoresModulo[0].id);
        } else {
            mismoPrincipalCheckbox.checked = true;
            multiInstructorCheckbox.checked = false;
        }
        updateInstructorVisibility();

        moduleRows.appendChild(row);
    }

    if (courseForm) courseModal.on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var mode = trigger && trigger.getAttribute('data-course-modal') === 'edit' ? 'edit' : 'create';
        courseForm.reset();
        moduleRows.innerHTML = '';
        moduleIndex = 0;
        courseForm.action = mode === 'edit' ? 'actualizar_cursos.php' : 'guardar_cursos.php';
        document.getElementById('course-form-id').value = mode === 'edit' ? trigger.getAttribute('data-course-id') || '' : '';
        document.getElementById('course-form-title').textContent = mode === 'edit' ? 'Editar curso' : 'Crear curso';
        document.getElementById('course-form-subtitle').textContent = mode === 'edit'
            ? 'Actualiza la información general y el calendario del curso seleccionado.'
            : 'Completa la información general y el calendario del curso.';
        courseSubmit.lastChild.nodeValue = mode === 'edit' ? ' Guardar cambios' : ' Crear curso';

        if (mode === 'edit') {
            document.getElementById('course-form-code').value = trigger.getAttribute('data-course-codigo') || '';
            setSelectValue(document.getElementById('course-form-category'), trigger.getAttribute('data-course-categoria') || '');
            setSelectValue(document.getElementById('course-form-instructor'), trigger.getAttribute('data-course-instructor') || '');
            setSelectValue(document.getElementById('course-form-type'), trigger.getAttribute('data-course-tipo') || '');
            setSelectValue(document.getElementById('course-form-area'), trigger.getAttribute('data-course-area') || '');
            setSelectValue(document.getElementById('course-form-shift'), trigger.getAttribute('data-course-jornada') || '');
            document.getElementById('course-form-name').value = trigger.getAttribute('data-course-nombre') || '';
            document.getElementById('course-form-days').value = trigger.getAttribute('data-course-dias') || '';
            document.getElementById('course-form-schedule').value = trigger.getAttribute('data-course-horario') || '';
            document.getElementById('course-form-capacity').value = trigger.getAttribute('data-course-cupo') || '';
            var activity = trigger.getAttribute('data-course-actividad') || 'formacion_academica';
            var activityRadio = courseForm.querySelector('[name="actividad_tipo"][value="' + activity + '"]');
            if (activityRadio) activityRadio.checked = true;
            courseStart.value = trigger.getAttribute('data-course-inicio') || '';
            courseEnd.value = trigger.getAttribute('data-course-fin') || '';
            var modules = [];
            try { modules = JSON.parse(trigger.getAttribute('data-course-modulos') || '[]'); } catch (ignore) {}
            modules.forEach(addModuleRow);
            if (!modules.length && activity !== 'evento_tecnico') addModuleRow();
        } else {
            document.getElementById('course-form-code').value = <?php echo json_encode($codigoSugerido); ?>;
            addModuleRow();
        }

        updateActivityCards();
        courseEnd.min = courseStart.value;
        courseSubmit.disabled = false;
    });

    if (courseStart) courseStart.addEventListener('change', function () {
        courseEnd.min = courseStart.value;
        if (courseEnd.value && courseEnd.value < courseStart.value) {
            courseEnd.value = courseStart.value;
        }
    });

    if (courseForm) courseForm.addEventListener('submit', function () {
        courseSubmit.disabled = true;
    });

    var moduleAdd = document.getElementById('course-module-add');
    if (moduleAdd) moduleAdd.addEventListener('click', function () { addModuleRow(); });
    document.querySelectorAll('[name="actividad_tipo"]').forEach(function (radio) {
        radio.addEventListener('change', updateActivityCards);
    });

    $('#confirm-delete').on('show.bs.modal', function (event) {
        $(this).find('.btn-ok').attr('href', $(event.relatedTarget).data('href'));
    });
})();
</script>
</body>
</html>
