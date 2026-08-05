<?php
require_once 'conexion.php';
require_once 'menu.php';

cengi_require_ver_participantes('index.php');

if (cengi_es_estudiante()) {
    header('Location: ver_cursos.php');
    exit;
}

$db = conectar();
$puedeCargar = cengi_puede_cargar_participantes();
$puedeEditar = cengi_puede_editar_participantes();
$puedeEliminar = cengi_puede_eliminar_participantes();
$puedeCalificar = cengi_puede_calificar();
$puedeDiploma = cengi_puede_subir_diploma();

$busqueda = trim((string) ($_GET['q'] ?? ''));
$estado = trim((string) ($_GET['estado'] ?? 'todos'));
$cursoId = (int) ($_GET['curso_id'] ?? 0);
$mensaje = trim((string) ($_GET['mensaje'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

$condicionesCurso = [];
$paramsCurso = [];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condicionesCurso[] = 'EXISTS (
        SELECT 1 FROM asignaciones ax
        INNER JOIN participantes px ON px.id = ax.participantes_id
        WHERE ax.cursos_id = c.id AND px.ingenio_id = ?
    )';
    $paramsCurso[] = cengi_ingenio_id_actual();
}
$whereCursos = $condicionesCurso ? 'WHERE ' . implode(' AND ', $condicionesCurso) : '';
$stmtCursos = $db->prepare("
    SELECT c.id, c.nombre_cursos, YEAR(COALESCE(c.inicio, c.creado)) AS anio
    FROM cursos c
    $whereCursos
    ORDER BY anio DESC, c.nombre_cursos
");
$stmtCursos->execute($paramsCurso);
$cursos = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);

$idsCursos = array_map('intval', array_column($cursos, 'id'));
if ($cursoId <= 0 || !in_array($cursoId, $idsCursos, true)) {
    $cursoId = $idsCursos[0] ?? 0;
}

$condiciones = ['a.cursos_id = ?'];
$params = [$cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
if ($busqueda !== '') {
    $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR i.nombre_ingenios LIKE ? OR p.area_participantes LIKE ? OR p.correo_participantes LIKE ? OR p.grado_academico_participantes LIKE ? OR p.telefono_participantes LIKE ?)';
    $termino = '%' . $busqueda . '%';
    array_push($params, $termino, $termino, $termino, $termino, $termino, $termino, $termino);
}
if ($estado === 'activo') {
    $condiciones[] = 'p.estado_participantes = 1 AND a.estado_asignaciones = 1';
} elseif ($estado === 'aprobado') {
    $condiciones[] = 'p.estado_participantes = 1 AND a.estado_asignaciones = 1 AND CAST(cc.posevaluacion AS DECIMAL(10,2)) >= 60';
} elseif ($estado === 'inactivo') {
    $condiciones[] = '(p.estado_participantes = 0 OR a.estado_asignaciones = 0)';
}

$stmtParticipantes = $db->prepare("
    SELECT
        p.id AS participante_id,
        p.ingenio_id,
        p.cui_participantes,
        p.nombre_participantes,
        p.puesto_participantes,
        p.area_participantes,
        p.correo_participantes,
        p.grado_academico_participantes,
        p.telefono_participantes,
        p.estado_participantes,
        a.id AS asignacion_id,
        a.estado_asignaciones,
        i.nombre_ingenios,
        cc.asistencia,
        cc.evaluacion,
        cc.posevaluacion,
        cc.diploma
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    WHERE " . implode(' AND ', $condiciones) . "
    ORDER BY p.nombre_participantes
");
$stmtParticipantes->execute($params);
$filas = $cursoId > 0 ? $stmtParticipantes->fetchAll(PDO::FETCH_ASSOC) : [];

$ingenios = $db->query('SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_ASSOC);

$usuarios = [];
if ($puedeCargar) {
    try {
        $dbUsuarios = conectar_usuarios_menu_pdo();
        $sqlUsuarios = "SELECT DISTINCT u.id, u.nombre, COALESCE(r.nombre_rol, '') AS rol
            FROM usuarios u
            INNER JOIN roles r ON r.id = u.rol_id
            LEFT JOIN usuario_modulo um ON um.usuario_id = u.id
            LEFT JOIN modulos m ON m.id = um.modulo_id
            WHERE LOWER(COALESCE(m.nombre, '')) IN ('cursos', 'cengicursos')";
        $paramsUsuarios = [];
        if (!cengi_ve_todo_por_rol_o_ingenio()) {
            $sqlUsuarios .= ' AND u.ingenio_id = ?';
            $paramsUsuarios[] = cengi_ingenio_id_actual();
        }
        $sqlUsuarios .= ' ORDER BY u.nombre';
        $stmtUsuarios = $dbUsuarios->prepare($sqlUsuarios);
        $stmtUsuarios->execute($paramsUsuarios);
        $usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $usuarios = [];
    }
}

$historial = [];
if ($filas) {
    $idsParticipantes = array_values(array_unique(array_map('intval', array_column($filas, 'participante_id'))));
    $marcadores = implode(',', array_fill(0, count($idsParticipantes), '?'));
    $stmtHistorial = $db->prepare("
        SELECT a.participantes_id, c.nombre_cursos, YEAR(COALESCE(c.inicio, c.creado)) AS anio,
               cc.asistencia, cc.posevaluacion, a.estado_asignaciones
        FROM asignaciones a
        INNER JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE a.participantes_id IN ($marcadores)
        ORDER BY COALESCE(c.inicio, c.creado) DESC
    ");
    $stmtHistorial->execute($idsParticipantes);
    foreach ($stmtHistorial->fetchAll(PDO::FETCH_ASSOC) as $registro) {
        $historial[(int) $registro['participantes_id']][] = $registro;
    }
}

function cengi_part_html($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function cengi_part_iniciales($nombre)
{
    $partes = preg_split('/\s+/', trim((string) $nombre));
    $iniciales = '';
    foreach (array_slice(array_filter($partes), 0, 2) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $iniciales ?: 'P';
}

function cengi_part_nota($valor)
{
    return is_numeric($valor) ? rtrim(rtrim(number_format((float) $valor, 1, '.', ''), '0'), '.') : '—';
}

function cengi_part_estado($fila)
{
    if ((int) $fila['estado_participantes'] !== 1 || (int) $fila['estado_asignaciones'] !== 1) {
        return ['Inactivo', 'is-inactive'];
    }
    if (is_numeric($fila['posevaluacion']) && (float) $fila['posevaluacion'] >= 60) {
        return ['Aprobado', 'is-approved'];
    }
    return ['Activo', 'is-active'];
}

$parametrosExportacion = [
    'curso_id' => $cursoId,
    'q' => $busqueda,
    'estado' => $estado,
];
$urlExportacion = 'exportarparticipantes.php?' . http_build_query($parametrosExportacion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Participantes por curso</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas cengi-participants-page">
<?php menu_render(); ?>

<main class="container-fluid cengi-participants-shell">
    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success alert-dismissible cengi-flash" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            <?php
            $mensajes = [
                'actualizado' => 'Los datos del participante se actualizaron correctamente.',
                'desactivado' => 'El participante fue desactivado correctamente.',
                'calificacion' => 'Las calificaciones se guardaron correctamente.',
                'carga' => 'La carga masiva de participantes finalizó correctamente.',
            ];
            echo cengi_part_html($mensajes[$mensaje] ?? 'La operación se completó correctamente.');
            ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible cengi-flash" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            No fue posible completar la operación. Verifica los datos e inténtalo nuevamente.
        </div>
    <?php endif; ?>

    <section class="cengi-participants-toolbar-card">
        <form method="get" class="cengi-participants-toolbar" id="participant-filters">
            <div class="cengi-participants-course-select">
                <label for="curso_id">Curso</label>
                <select class="form-control" name="curso_id" id="curso_id">
                    <?php foreach ($cursos as $curso): ?>
                        <option value="<?php echo (int) $curso['id']; ?>" <?php echo (int) $curso['id'] === $cursoId ? 'selected' : ''; ?>>
                            <?php echo cengi_part_html($curso['nombre_cursos'] . (!empty($curso['anio']) ? ' — ' . $curso['anio'] : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cengi-participants-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" class="form-control" name="q" value="<?php echo cengi_part_html($busqueda); ?>" placeholder="Buscar por nombre, CUI, ingenio o área">
            </div>
            <div class="cengi-participants-state-select">
                <label for="estado">Estado</label>
                <select class="form-control" name="estado" id="estado">
                    <option value="todos" <?php echo $estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
                    <option value="activo" <?php echo $estado === 'activo' ? 'selected' : ''; ?>>Activos</option>
                    <option value="aprobado" <?php echo $estado === 'aprobado' ? 'selected' : ''; ?>>Aprobados</option>
                    <option value="inactivo" <?php echo $estado === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                </select>
            </div>
            <button type="submit" class="btn btn-default"><span class="glyphicon glyphicon-filter"></span> Filtrar</button>
        </form>

        <div class="cengi-participants-toolbar-actions">
            <?php if ($puedeCalificar): ?>
                <button type="button" class="btn btn-default" data-toggle="modal" data-target="#bulk-grades-modal"><span class="glyphicon glyphicon-upload"></span> Cargar calificaciones (CSV)</button>
            <?php endif; ?>
            <?php if ($puedeCargar): ?>
                <button type="button" class="btn btn-default" data-toggle="modal" data-target="#bulk-participants-modal"><span class="glyphicon glyphicon-user"></span> Cargar participantes</button>
            <?php endif; ?>
            <div class="btn-group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" <?php echo $cursoId > 0 ? '' : 'disabled'; ?>>
                    <span class="glyphicon glyphicon-download-alt"></span> Descargar listado <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li><a href="<?php echo cengi_part_html($urlExportacion . '&format=pdf'); ?>"><span class="glyphicon glyphicon-file"></span> Descargar PDF</a></li>
                    <li><a href="<?php echo cengi_part_html($urlExportacion . '&format=excel'); ?>"><span class="glyphicon glyphicon-list-alt"></span> Descargar Excel</a></li>
                </ul>
            </div>
            <button type="button" class="btn btn-success" onclick="window.print()"><span class="glyphicon glyphicon-file"></span> Generar reporte</button>
        </div>
    </section>

    <section class="cengi-participants-table-card">
        <div class="table-responsive">
            <table class="table cengi-participants-table">
                <thead>
                    <tr>
                        <th>Participante</th>
                        <th>CUI</th>
                        <th>Ingenio</th>
                        <th>Área</th>
                        <th>Estado</th>
                        <th>Asistencia</th>
                        <th>Pre-eval.</th>
                        <th>Post-eval.</th>
                        <th>Diploma</th>
                        <th><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$filas): ?>
                    <tr><td colspan="10" class="cengi-participants-empty"><span class="glyphicon glyphicon-user"></span><strong>No hay participantes para mostrar</strong><small>Selecciona otro curso o modifica los filtros.</small></td></tr>
                <?php endif; ?>
                <?php foreach ($filas as $fila): ?>
                    <?php
                    [$etiquetaEstado, $claseEstado] = cengi_part_estado($fila);
                    $asistencia = is_numeric($fila['asistencia']) ? max(0, min(100, (float) $fila['asistencia'])) : 0;
                    $historialJson = json_encode($historial[(int) $fila['participante_id']] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    ?>
                    <tr>
                        <td>
                            <div class="cengi-participant-name">
                                <span class="cengi-participant-avatar"><?php echo cengi_part_html(cengi_part_iniciales($fila['nombre_participantes'])); ?></span>
                                <span><strong><?php echo cengi_part_html($fila['nombre_participantes']); ?></strong><small><?php echo cengi_part_html($fila['puesto_participantes']); ?></small><small><?php echo cengi_part_html($fila['correo_participantes']); ?></small></span>
                            </div>
                        </td>
                        <td><span class="cengi-participant-cui"><?php echo cengi_part_html($fila['cui_participantes']); ?></span></td>
                        <td><?php echo cengi_part_html($fila['nombre_ingenios']); ?></td>
                        <td><?php echo cengi_part_html($fila['area_participantes']); ?></td>
                        <td><span class="cengi-participant-status <?php echo $claseEstado; ?>"><?php echo $etiquetaEstado; ?></span></td>
                        <td>
                            <div class="cengi-participant-attendance"><div class="progress"><div class="progress-bar" style="width:<?php echo $asistencia; ?>%"></div></div><span><?php echo is_numeric($fila['asistencia']) ? cengi_part_nota($fila['asistencia']) . '%' : '—'; ?></span></div>
                        </td>
                        <td><strong><?php echo cengi_part_nota($fila['evaluacion']); ?></strong></td>
                        <td><strong><?php echo cengi_part_nota($fila['posevaluacion']); ?></strong></td>
                        <td>
                            <?php if (!empty($fila['diploma'])): ?>
                                <a class="cengi-participant-diploma" href="<?php echo cengi_part_html($fila['diploma']); ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file"></span> PDF</a>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="cengi-row-actions">
                                <?php if ($puedeCalificar): ?>
                                    <button type="button" class="cengi-action-btn participant-grades-trigger" data-toggle="modal" data-target="#participant-grades-modal" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-assignment="<?php echo (int) $fila['asignacion_id']; ?>" data-attendance="<?php echo cengi_part_html($fila['asistencia']); ?>" data-pre="<?php echo cengi_part_html($fila['evaluacion']); ?>" data-post="<?php echo cengi_part_html($fila['posevaluacion']); ?>" aria-label="Calificaciones" data-tooltip="Calificaciones"><span class="glyphicon glyphicon-education"></span></button>
                                <?php endif; ?>
                                <?php if ($puedeEditar): ?>
                                    <button type="button" class="cengi-action-btn participant-edit-trigger" data-toggle="modal" data-target="#participant-edit-modal" data-id="<?php echo (int) $fila['participante_id']; ?>" data-ingenio="<?php echo (int) $fila['ingenio_id']; ?>" data-cui="<?php echo cengi_part_html($fila['cui_participantes']); ?>" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-position="<?php echo cengi_part_html($fila['puesto_participantes']); ?>" data-area="<?php echo cengi_part_html($fila['area_participantes']); ?>" data-email="<?php echo cengi_part_html($fila['correo_participantes']); ?>" data-degree="<?php echo cengi_part_html($fila['grado_academico_participantes']); ?>" data-phone="<?php echo cengi_part_html($fila['telefono_participantes']); ?>" data-state="<?php echo (int) $fila['estado_participantes']; ?>" aria-label="Editar" data-tooltip="Editar"><span class="glyphicon glyphicon-pencil"></span></button>
                                <?php endif; ?>
                                <button type="button" class="cengi-action-btn participant-profile-trigger" data-toggle="modal" data-target="#participant-profile-modal" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-cui="<?php echo cengi_part_html($fila['cui_participantes']); ?>" data-company="<?php echo cengi_part_html($fila['nombre_ingenios']); ?>" data-area="<?php echo cengi_part_html($fila['area_participantes']); ?>" data-email="<?php echo cengi_part_html($fila['correo_participantes']); ?>" data-degree="<?php echo cengi_part_html($fila['grado_academico_participantes']); ?>" data-phone="<?php echo cengi_part_html($fila['telefono_participantes']); ?>" data-history="<?php echo cengi_part_html($historialJson); ?>" aria-label="Ver perfil" data-tooltip="Ver perfil"><span class="glyphicon glyphicon-eye-open"></span></button>
                                <?php if ($puedeEliminar): ?>
                                    <button type="button" class="cengi-action-btn is-delete participant-delete-trigger" data-toggle="modal" data-target="#confirm-participant-delete" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-href="eliminar_participante.php?id=<?php echo (int) $fila['participante_id']; ?>&amp;curso_id=<?php echo $cursoId; ?>" aria-label="Desactivar" data-tooltip="Desactivar"><span class="glyphicon glyphicon-trash"></span></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="cengi-participants-table-foot"><span><?php echo count($filas); ?> participante<?php echo count($filas) === 1 ? '' : 's'; ?></span><span>Actualizado: <?php echo date('d/m/Y H:i'); ?></span></div>
    </section>
</main>

<?php if ($puedeEditar): ?>
<div class="modal fade cengi-participant-modal" id="participant-edit-modal" tabindex="-1" role="dialog" aria-labelledby="participant-edit-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form action="actualizar_participantes.php" method="post">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-pencil"></span></span><div><h4 class="modal-title" id="participant-edit-title">Editar participante</h4><p>Actualiza sus datos personales y laborales.</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="id" id="participant-edit-id"><input type="hidden" name="curso_id" value="<?php echo $cursoId; ?>">
                <div class="cengi-participant-modal-grid">
                    <div class="form-group"><label for="participant-edit-name">Nombre completo</label><input class="form-control" id="participant-edit-name" name="nombre_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-cui">CUI</label><input class="form-control" id="participant-edit-cui" name="cui_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-company">Ingenio</label><select class="form-control" id="participant-edit-company" name="ingenio" required><?php foreach ($ingenios as $ingenio): ?><option value="<?php echo (int) $ingenio['id']; ?>"><?php echo cengi_part_html($ingenio['nombre_ingenios']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="participant-edit-area">Área</label><input class="form-control" id="participant-edit-area" name="area_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-position">Puesto</label><input class="form-control" id="participant-edit-position" name="puesto_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-email">Correo electrónico</label><input type="email" class="form-control" id="participant-edit-email" name="correo_participantes" maxlength="255"></div>
                    <div class="form-group"><label for="participant-edit-degree">Grado académico</label><input class="form-control" id="participant-edit-degree" name="grado_academico_participantes" maxlength="255"></div>
                    <div class="form-group"><label for="participant-edit-phone">Teléfono</label><input type="tel" class="form-control" id="participant-edit-phone" name="telefono_participantes" maxlength="50"></div>
                    <div class="form-group"><label for="participant-edit-state">Estado</label><select class="form-control" id="participant-edit-state" name="estado_participantes"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar cambios</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if ($puedeCalificar): ?>
<div class="modal fade cengi-participant-modal" id="participant-grades-modal" tabindex="-1" role="dialog" aria-labelledby="participant-grades-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form action="guardar_control.php" method="post" enctype="multipart/form-data">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-education"></span></span><div><h4 class="modal-title" id="participant-grades-title">Registrar resultados</h4><p id="participant-grades-name"></p></div></div>
            <div class="modal-body">
                <input type="hidden" name="asignacion_id" id="participant-grades-assignment"><input type="hidden" name="return_to" value="participantes">
                <div class="cengi-participant-modal-grid is-three">
                    <div class="form-group"><label for="participant-grades-attendance">Asistencia %</label><input type="number" min="0" max="100" step="0.01" class="form-control" id="participant-grades-attendance" name="asistencia"></div>
                    <div class="form-group"><label for="participant-grades-pre">Pre-evaluación</label><input type="number" min="0" max="100" step="0.01" class="form-control" id="participant-grades-pre" name="evaluacion"></div>
                    <div class="form-group"><label for="participant-grades-post">Post-evaluación</label><input type="number" min="0" max="100" step="0.01" class="form-control" id="participant-grades-post" name="posevaluacion"></div>
                </div>
                <?php if ($puedeDiploma): ?><div class="form-group"><label for="participant-grades-diploma">Diploma (PDF)</label><input type="file" class="form-control" id="participant-grades-diploma" name="diploma" accept="application/pdf,.pdf"></div><?php endif; ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar resultados</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-participant-modal" id="bulk-grades-modal" tabindex="-1" role="dialog" aria-labelledby="bulk-grades-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form action="carga_calificaciones.php" method="post" enctype="multipart/form-data">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-upload"></span></span><div><h4 class="modal-title" id="bulk-grades-title">Cargar calificaciones</h4><p>Actualiza resultados del curso desde un archivo CSV.</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="curso_id" value="<?php echo $cursoId; ?>">
                <div class="cengi-upload-guide"><strong>Formato requerido</strong><span>CUI, ASISTENCIA, PRE_EVALUACION, POST_EVALUACION</span><small>Los valores deben estar entre 0 y 100.</small></div>
                <label class="cengi-upload-dropzone" for="bulk-grades-file"><span class="glyphicon glyphicon-cloud-upload"></span><strong>Selecciona el archivo CSV</strong><small>Máximo 5 MB</small><input type="file" id="bulk-grades-file" name="archivo" accept=".csv,text/csv" required></label>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Procesar archivo</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if ($puedeCargar): ?>
<div class="modal fade cengi-participant-modal" id="bulk-participants-modal" tabindex="-1" role="dialog" aria-labelledby="bulk-participants-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form action="carga_participantes.php" method="post" enctype="multipart/form-data">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-user"></span></span><div><h4 class="modal-title" id="bulk-participants-title">Cargar participantes</h4><p>Agrega participantes al curso sin salir de esta vista.</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="curso" value="<?php echo $cursoId; ?>">
                <div class="cengi-participant-modal-grid">
                    <div class="form-group"><label for="bulk-participants-company">Ingenio</label><select class="form-control" id="bulk-participants-company" name="ingenio" required><?php foreach ($ingenios as $ingenio): ?><option value="<?php echo (int) $ingenio['id']; ?>"><?php echo cengi_part_html($ingenio['nombre_ingenios']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="bulk-participants-user">Usuario asignado</label><select class="form-control" id="bulk-participants-user" name="user" required><?php foreach ($usuarios as $usuario): ?><option value="<?php echo (int) $usuario['id']; ?>"><?php echo cengi_part_html($usuario['nombre'] . (!empty($usuario['rol']) ? ' — ' . $usuario['rol'] : '')); ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="cengi-upload-guide"><strong>Formato requerido</strong><span>CUI, NOMBRE, PUESTO, AREA, CORREO_ELECTRONICO, GRADO_ACADEMICO, TELEFONO</span><small>Admite archivos CSV, XLS y XLSX. <a href="plantilla_participantes.csv" download>Descargar plantilla</a></small></div>
                <label class="cengi-upload-dropzone" for="bulk-participants-file"><span class="glyphicon glyphicon-cloud-upload"></span><strong>Selecciona el archivo</strong><small>CSV, XLS o XLSX</small><input type="file" id="bulk-participants-file" name="archivo" accept=".csv,.xls,.xlsx" required></label>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Procesar archivo</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-participant-modal" id="participant-profile-modal" tabindex="-1" role="dialog" aria-labelledby="participant-profile-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-user"></span></span><div><h4 class="modal-title" id="participant-profile-title">Perfil del participante</h4><p>Información e historial de cursos.</p></div></div>
        <div class="modal-body"><div class="cengi-participant-profile-head"><span class="cengi-participant-profile-avatar" id="participant-profile-avatar">P</span><div><h3 id="participant-profile-name"></h3><p id="participant-profile-meta"></p><small id="participant-profile-cui"></small></div></div><div class="cengi-directory-profile-details"><div><span>Correo electrónico</span><strong id="participant-profile-email">—</strong></div><div><span>Teléfono</span><strong id="participant-profile-phone">—</strong></div><div><span>Grado académico</span><strong id="participant-profile-degree">—</strong></div></div><h5 class="cengi-participant-history-title">Historial de cursos</h5><div class="cengi-participant-history" id="participant-profile-history"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<?php if ($puedeEliminar): ?>
<div class="modal fade cengi-participant-modal" id="confirm-participant-delete" tabindex="-1" role="dialog" aria-labelledby="participant-delete-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content cengi-confirm-content">
        <div class="modal-body"><span class="cengi-confirm-icon"><span class="glyphicon glyphicon-trash"></span></span><h4 id="participant-delete-title">Desactivar participante</h4><p>¿Deseas desactivar a <strong id="participant-delete-name"></strong>? Sus asignaciones también quedarán inactivas.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><a class="btn btn-danger btn-ok" href="#">Sí, desactivar</a></div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var filters = document.getElementById('participant-filters');
    ['curso_id', 'estado'].forEach(function (id) {
        var field = document.getElementById(id);
        if (field) field.addEventListener('change', function () { filters.submit(); });
    });

    $('#participant-edit-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        document.getElementById('participant-edit-id').value = trigger.getAttribute('data-id') || '';
        document.getElementById('participant-edit-name').value = trigger.getAttribute('data-name') || '';
        document.getElementById('participant-edit-cui').value = trigger.getAttribute('data-cui') || '';
        document.getElementById('participant-edit-company').value = trigger.getAttribute('data-ingenio') || '';
        document.getElementById('participant-edit-area').value = trigger.getAttribute('data-area') || '';
        document.getElementById('participant-edit-position').value = trigger.getAttribute('data-position') || '';
        document.getElementById('participant-edit-email').value = trigger.getAttribute('data-email') || '';
        document.getElementById('participant-edit-degree').value = trigger.getAttribute('data-degree') || '';
        document.getElementById('participant-edit-phone').value = trigger.getAttribute('data-phone') || '';
        document.getElementById('participant-edit-state').value = trigger.getAttribute('data-state') || '1';
    });

    $('#participant-grades-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        document.getElementById('participant-grades-name').textContent = trigger.getAttribute('data-name') || '';
        document.getElementById('participant-grades-assignment').value = trigger.getAttribute('data-assignment') || '';
        document.getElementById('participant-grades-attendance').value = trigger.getAttribute('data-attendance') || '';
        document.getElementById('participant-grades-pre').value = trigger.getAttribute('data-pre') || '';
        document.getElementById('participant-grades-post').value = trigger.getAttribute('data-post') || '';
    });

    function initials(name) {
        return (name || 'P').trim().split(/\s+/).slice(0, 2).map(function (word) { return word.charAt(0).toUpperCase(); }).join('');
    }
    function escapeHtml(value) {
        var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML;
    }
    $('#participant-profile-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        var name = trigger.getAttribute('data-name') || '';
        document.getElementById('participant-profile-name').textContent = name;
        document.getElementById('participant-profile-avatar').textContent = initials(name);
        document.getElementById('participant-profile-meta').textContent = [trigger.getAttribute('data-company'), trigger.getAttribute('data-area')].filter(Boolean).join(' · ');
        document.getElementById('participant-profile-cui').textContent = 'CUI ' + (trigger.getAttribute('data-cui') || '—');
        document.getElementById('participant-profile-email').textContent = trigger.getAttribute('data-email') || '—';
        document.getElementById('participant-profile-degree').textContent = trigger.getAttribute('data-degree') || '—';
        document.getElementById('participant-profile-phone').textContent = trigger.getAttribute('data-phone') || '—';
        var history = [];
        try { history = JSON.parse(trigger.getAttribute('data-history') || '[]'); } catch (ignore) {}
        document.getElementById('participant-profile-history').innerHTML = history.length ? history.map(function (item) {
            var note = item.posevaluacion !== null && item.posevaluacion !== '' ? item.posevaluacion + ' pts' : 'Sin nota';
            var attendance = item.asistencia !== null && item.asistencia !== '' ? item.asistencia + '% asistencia' : 'Sin asistencia';
            return '<div class="cengi-participant-history-item"><span class="glyphicon glyphicon-book"></span><div><strong>' + escapeHtml(item.nombre_cursos) + '</strong><small>' + escapeHtml(item.anio || '') + ' · ' + escapeHtml(attendance) + '</small></div><b>' + escapeHtml(note) + '</b></div>';
        }).join('') : '<div class="cengi-participants-empty is-small">No hay otros cursos registrados.</div>';
    });

    $('#confirm-participant-delete').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        document.getElementById('participant-delete-name').textContent = trigger ? trigger.getAttribute('data-name') : '';
        $(this).find('.btn-ok').attr('href', trigger ? trigger.getAttribute('data-href') : '#');
    });
})();
</script>
</body>
</html>
