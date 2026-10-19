<?php
require_once 'conexion.php';
require_once 'menu.php';

cengi_require_ver_participantes('index.php');

if (cengi_es_estudiante()) {
    header('Location: ver_cursos.php');
    exit;
}

// "Participantes por curso" se unificó en Seguimiento por curso (ver_participante_curso.php):
// quien puede calificar va allá con el mismo curso y aviso. Aquí quedan la vista de consulta
// (roles que solo ven participantes) y la de retirados del curso (estado inactivo / todos).
if (cengi_puede_calificar() && !in_array($_GET['estado'] ?? '', ['inactivo', 'todos'], true)) {
    $destino = array_filter(['id' => (int) ($_GET['curso_id'] ?? 0), 'mensaje' => (string) ($_GET['mensaje'] ?? ''), 'error' => (string) ($_GET['error'] ?? '')]);
    header('Location: ver_participante_curso.php' . ($destino ? '?' . http_build_query($destino) : ''));
    exit;
}

$db = conectar();
$puedeCargar = cengi_puede_cargar_participantes();
$puedeEditar = cengi_puede_editar_participantes();
$puedeEliminar = cengi_puede_eliminar_participantes();
$puedeCalificar = cengi_puede_calificar();
$puedeDiploma = cengi_puede_subir_diploma();

$busqueda = trim((string) ($_GET['q'] ?? ''));
$estado = trim((string) ($_GET['estado'] ?? 'activo'));
$cursoId = (int) ($_GET['curso_id'] ?? 0);

// Un usuario no admin nunca debe poder ver inactivos, aunque manipule la URL.
if (!cengi_ve_todo_por_rol_o_ingenio() && !in_array($estado, ['activo', 'aprobado'], true)) {
    $estado = 'activo';
}
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
    SELECT c.id, c.nombre_cursos, c.codigo_curso, YEAR(COALESCE(c.inicio, c.creado)) AS anio
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
    $condiciones[] = 'p.estado_participantes = 1 AND a.estado_asignaciones = 1 AND CAST(cc.posevaluacion AS DECIMAL(10,2)) >= ' . CENGI_NOTA_APROBACION;
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
        cc.sesiones_asistidas,
        cc.evaluacion,
        cc.posevaluacion,
        COALESCE(NULLIF((SELECT MAX(dp.pdf_path) FROM diplomas dp WHERE dp.tipo = 'curso' AND dp.asignacion_id = a.id), ''), cc.diploma) AS diploma
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

// Resumen del curso: fechas, modulos y sesiones impartidas (programa de sesiones de la
// planificacion ligada al curso, si existe), mas los indicadores de la tabla filtrada.
$cursoResumen = null;
if ($cursoId > 0) {
    $stmtResumen = $db->prepare("
        SELECT c.nombre_cursos, c.codigo_curso, c.inicio, c.fin,
               (SELECT COUNT(*) FROM curso_modulos m WHERE m.curso_id = c.id) AS modulos,
               (SELECT COUNT(*) FROM curso_sesiones s INNER JOIN planificacion_cursos pc ON pc.id = s.planificacion_id
                 WHERE pc.curso_id = c.id AND s.estado = 'impartida') AS sesiones_impartidas,
               (SELECT COUNT(*) FROM curso_sesiones s INNER JOIN planificacion_cursos pc ON pc.id = s.planificacion_id
                 WHERE pc.curso_id = c.id AND s.estado <> 'cancelada') AS sesiones_total
        FROM cursos c WHERE c.id = ?
    ");
    $stmtResumen->execute([$cursoId]);
    $cursoResumen = $stmtResumen->fetch(PDO::FETCH_ASSOC) ?: null;
}
$cursoConModulos = $cursoResumen && (int) $cursoResumen['modulos'] > 0;
$sesionesCurso = $cursoResumen ? (int) $cursoResumen['sesiones_impartidas'] : 0;

$promedio = static function (array $valores) {
    $valores = array_values(array_filter($valores, 'is_numeric'));
    return $valores ? array_sum(array_map('floatval', $valores)) / count($valores) : null;
};
$conNota = array_filter($filas, static function ($f) { return is_numeric($f['posevaluacion']); });
$resumenAprobados = count(array_filter($conNota, static function ($f) { return (float) $f['posevaluacion'] >= CENGI_NOTA_APROBACION; }));
$resumenAsistencia = $promedio(array_column($filas, 'asistencia'));
$resumenNota = $promedio(array_column($filas, 'posevaluacion'));
$resumenDiplomas = count(array_filter($filas, static function ($f) { return !empty($f['diploma']); }));

$historial = [];
if ($filas) {
    $idsParticipantes = array_values(array_unique(array_map('intval', array_column($filas, 'participante_id'))));
    $marcadores = implode(',', array_fill(0, count($idsParticipantes), '?'));
    $stmtHistorial = $db->prepare("
        SELECT a.participantes_id, c.nombre_cursos, YEAR(COALESCE(c.inicio, c.creado)) AS anio,
               cc.asistencia, cc.sesiones_asistidas, cc.posevaluacion, a.estado_asignaciones
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

// Igual que cengi_part_html(), pero para el href/data-diploma de un archivo subido:
// normaliza el path legado (cengi_normalizar_url_archivo() en conexion.php) antes de
// escaparlo, en vez de imprimir el string de BD tal cual. El atributo data-diploma se
// asigna directo como .href en JS (ver participant-grades-trigger mas abajo), asi que
// tiene que llegar ya normalizado desde aqui.
function cengi_part_href($valor)
{
    return htmlspecialchars(cengi_normalizar_url_archivo($valor), ENT_QUOTES, 'UTF-8');
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
    if (is_numeric($fila['posevaluacion']) && (float) $fila['posevaluacion'] >= CENGI_NOTA_APROBACION) {
        return ['Aprobado', 'is-approved'];
    }
    return ['Activo', 'is-active'];
}

// Conteo por estado del curso (sin la búsqueda) para las pestañas de filtro.
$conteoEstados = ['todos' => 0, 'activo' => 0, 'aprobado' => 0, 'inactivo' => 0];
if ($cursoId > 0) {
    $condicionesConteo = ['a.cursos_id = ?'];
    $paramsConteo = [$cursoId];
    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $condicionesConteo[] = 'p.ingenio_id = ?';
        $paramsConteo[] = cengi_ingenio_id_actual();
    }
    $stmtConteo = $db->prepare("
        SELECT COUNT(*) AS todos,
               SUM(p.estado_participantes = 1 AND a.estado_asignaciones = 1) AS activo,
               SUM(p.estado_participantes = 1 AND a.estado_asignaciones = 1 AND CAST(cc.posevaluacion AS DECIMAL(10,2)) >= " . CENGI_NOTA_APROBACION . ") AS aprobado,
               SUM(p.estado_participantes = 0 OR a.estado_asignaciones = 0) AS inactivo
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE " . implode(' AND ', $condicionesConteo));
    $stmtConteo->execute($paramsConteo);
    $conteoEstados = array_map('intval', $stmtConteo->fetch(PDO::FETCH_ASSOC) ?: $conteoEstados);
}
$pestanasEstado = cengi_ve_todo_por_rol_o_ingenio()
    ? ['todos' => 'Todos', 'activo' => 'Activos', 'aprobado' => 'Aprobados', 'inactivo' => 'Inactivos']
    : ['activo' => 'Activos', 'aprobado' => 'Aprobados'];
$urlEstado = static function ($valor) use ($cursoId, $busqueda) {
    return 'participantes.php?' . http_build_query(array_filter(['curso_id' => $cursoId, 'q' => $busqueda, 'estado' => $valor], 'strlen'));
};

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
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Participantes por curso</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/proyecto.css') ?: 1); ?>">
    <link rel="stylesheet" href="css/cengi-tema.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/cengi-tema.css') ?: 1); ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas cengi-participants-page cg-tema">
<?php menu_render(); ?>

<main class="container-fluid cengi-participants-shell">
    <?php cengi_banner(); ?>
    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success alert-dismissible cengi-flash" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            <?php
            $mensajes = [
                'actualizado' => 'Los datos del participante se actualizaron correctamente.',
                'desactivado' => 'El participante fue eliminado de los registros activos correctamente.',
                'removido' => 'El participante fue removido del curso correctamente.',
                'calificacion' => 'Las calificaciones se guardaron correctamente.',
                'carga' => 'La carga masiva de participantes finalizó correctamente.',
                'correo_enviado' => 'Se envió el correo al participante con los documentos adjuntos.',
            ];
            echo cengi_part_html($mensajes[$mensaje] ?? 'La operación se completó correctamente.');
            ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible cengi-flash" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            <?php
            $errores = [
                'cui_duplicado' => 'Ya existe otro participante registrado con ese CUI. Verifica el número e inténtalo nuevamente.',
                'sin_correo' => 'El participante no tiene un correo electrónico válido. Edita sus datos y vuelve a intentarlo.',
                'sin_diploma' => 'El participante aún no tiene un diploma cargado o generado; envía solo la constancia o carga el diploma primero.',
                'sin_adjuntos' => 'Marca al menos un documento para enviar.',
                'correo' => 'No fue posible enviar el correo. Intenta más tarde.',
                'permiso_correo' => 'No tienes permiso para enviar documentos por correo.',
            ];
            echo cengi_part_html($errores[$error] ?? 'No fue posible completar la operación. Verifica los datos e inténtalo nuevamente.');
            ?>
        </div>
    <?php endif; ?>

    <?php
        $codigoActual = $cursoResumen ? ($cursoResumen['codigo_curso'] ?: 'CEN-' . str_pad((string) $cursoId, 3, '0', STR_PAD_LEFT)) : '';
        $totalFilas = count($filas);
        $pctAprobados = $conNota ? round($resumenAprobados / count($conNota) * 100) : 0;
        $pctDiplomas = $totalFilas ? round($resumenDiplomas / $totalFilas * 100) : 0;
        $sesionesTotal = $cursoResumen ? (int) $cursoResumen['sesiones_total'] : 0;
    ?>
    <section class="cengi-seg-hero">
        <div class="cengi-seg-hero-main">
            <?php if ($cursoResumen): ?>
                <span class="cengi-seg-eyebrow"><?php echo cengi_part_html($codigoActual); ?> · Participantes del curso</span>
                <h2 class="cengi-seg-title"><?php echo cengi_part_html($cursoResumen['nombre_cursos']); ?></h2>
                <div class="cengi-seg-meta">
                    <?php if (!empty($cursoResumen['inicio']) && $cursoResumen['inicio'] !== '0000-00-00'): ?>
                        <span><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?php echo cengi_part_html(date('d/m/Y', strtotime($cursoResumen['inicio'])) . (!empty($cursoResumen['fin']) && $cursoResumen['fin'] !== '0000-00-00' ? ' – ' . date('d/m/Y', strtotime($cursoResumen['fin'])) : '')); ?></span>
                    <?php endif; ?>
                    <?php if ($cursoConModulos): ?>
                        <span><span class="glyphicon glyphicon-th-list" aria-hidden="true"></span> <?php echo (int) $cursoResumen['modulos']; ?> módulo<?php echo (int) $cursoResumen['modulos'] === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                    <?php if ($sesionesTotal > 0): ?>
                        <span><span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?php echo $sesionesCurso; ?> de <?php echo $sesionesTotal; ?> sesiones impartidas</span>
                    <?php endif; ?>
                </div>
                <a class="cengi-seg-hero-link" href="ver_participante_curso.php?id=<?php echo (int) $cursoId; ?>"><span class="glyphicon glyphicon-list-alt" aria-hidden="true"></span> Abrir seguimiento del curso</a>
            <?php else: ?>
                <span class="cengi-seg-eyebrow">Participantes por curso</span>
                <h2 class="cengi-seg-title">Selecciona un curso</h2>
            <?php endif; ?>
        </div>
        <form method="get" class="cengi-seg-switch">
            <label for="curso_id">Curso</label>
            <select class="form-control" name="curso_id" id="curso_id" onchange="this.form.submit()">
                <?php foreach ($cursos as $curso): ?>
                    <?php
                    $codigoCurso = $curso['codigo_curso'] ?? '';
                    if ($codigoCurso === null || $codigoCurso === '') {
                        $codigoCurso = 'CEN-' . str_pad((string) $curso['id'], 3, '0', STR_PAD_LEFT);
                    }
                    ?>
                    <option value="<?php echo (int) $curso['id']; ?>" <?php echo (int) $curso['id'] === $cursoId ? 'selected' : ''; ?>>
                        <?php echo cengi_part_html($codigoCurso . ' — ' . $curso['nombre_cursos'] . (!empty($curso['anio']) ? ' — ' . $curso['anio'] : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="estado" value="<?php echo cengi_part_html($estado); ?>">
        </form>
    </section>

    <?php if ($cursoResumen): ?>
    <div class="cengi-seg-kpis cengi-part-kpis">
        <div class="cengi-seg-kpi">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-user"></span></span>
            <div><div class="cengi-seg-kpi-val"><?php echo $totalFilas; ?></div><div class="cengi-seg-kpi-label">Participantes · <?php echo count($conNota); ?> con nota final</div></div>
        </div>
        <div class="cengi-seg-kpi is-ok">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-ok"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $resumenAprobados; ?><?php if ($conNota): ?> <small><?php echo $pctAprobados; ?>%</small><?php endif; ?></div>
                <div class="cengi-seg-kpi-label"><?php echo $conNota ? 'Aprobados de los evaluados' : 'Aprobados · sin notas aún'; ?></div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $pctAprobados; ?>%"></span></span>
            </div>
        </div>
        <div class="cengi-seg-kpi is-info">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-check"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $resumenAsistencia !== null ? round($resumenAsistencia) . '%' : '—'; ?></div>
                <div class="cengi-seg-kpi-label">Asistencia promedio</div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $resumenAsistencia !== null ? round(min(100, $resumenAsistencia)) : 0; ?>%"></span></span>
            </div>
        </div>
        <div class="cengi-seg-kpi is-gold">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-star"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $resumenNota !== null ? cengi_part_nota($resumenNota) . ' <small>pts</small>' : '—'; ?></div>
                <div class="cengi-seg-kpi-label"><?php echo $cursoConModulos ? 'Nota final · promedio de módulos' : 'Nota final promedio'; ?></div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $resumenNota !== null ? round(min(100, $resumenNota)) : 0; ?>%"></span></span>
            </div>
        </div>
        <div class="cengi-seg-kpi is-violet">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-education"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $resumenDiplomas; ?> <small>/ <?php echo $totalFilas; ?></small></div>
                <div class="cengi-seg-kpi-label">Diplomas · <?php echo $totalFilas - $resumenDiplomas; ?> pendiente<?php echo $totalFilas - $resumenDiplomas === 1 ? '' : 's'; ?></div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $pctDiplomas; ?>%"></span></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <section class="cengi-participants-table-card cengi-psum-table">
        <div class="cengi-part-toolbar">
            <nav class="cengi-part-estados" aria-label="Filtrar por estado">
                <?php foreach ($pestanasEstado as $valor => $texto): ?>
                    <a href="<?php echo cengi_part_html($urlEstado($valor)); ?>" class="cengi-part-estado<?php echo $estado === $valor ? ' is-active' : ''; ?>"<?php echo $estado === $valor ? ' aria-current="true"' : ''; ?>>
                        <?php echo cengi_part_html($texto); ?> <b><?php echo (int) ($conteoEstados[$valor] ?? 0); ?></b>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form method="get" class="cengi-part-buscar" id="participant-filters" role="search">
                <input type="hidden" name="curso_id" value="<?php echo (int) $cursoId; ?>">
                <input type="hidden" name="estado" value="<?php echo cengi_part_html($estado); ?>">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" class="form-control" name="q" value="<?php echo cengi_part_html($busqueda); ?>" placeholder="Buscar por nombre, CUI, ingenio o área" aria-label="Buscar participantes">
                <?php if ($busqueda !== ''): ?>
                    <a href="<?php echo cengi_part_html('participantes.php?' . http_build_query(['curso_id' => $cursoId, 'estado' => $estado])); ?>" class="cengi-part-limpiar" aria-label="Limpiar búsqueda">&times;</a>
                <?php endif; ?>
            </form>
            <div class="cengi-part-acciones">
                <?php if ($puedeCalificar): ?>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#bulk-grades-modal"><span class="glyphicon glyphicon-upload"></span> Calificaciones (CSV)</button>
                <?php endif; ?>
                <?php if ($puedeCargar): ?>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#bulk-participants-modal"><span class="glyphicon glyphicon-user"></span> Cargar participantes</button>
                <?php endif; ?>
                <div class="btn-group">
                    <button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" <?php echo $cursoId > 0 ? '' : 'disabled'; ?>>
                        <span class="glyphicon glyphicon-download-alt"></span> Descargar <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <li><a href="<?php echo cengi_part_html($urlExportacion . '&format=pdf'); ?>" download><span class="glyphicon glyphicon-file"></span> Listado en PDF</a></li>
                        <li><a href="<?php echo cengi_part_html($urlExportacion . '&format=excel'); ?>" download><span class="glyphicon glyphicon-list-alt"></span> Listado en Excel</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php if ($busqueda !== ''): ?>
            <div class="cengi-part-busqueda-aviso">
                <?php echo $totalFilas; ?> resultado<?php echo $totalFilas === 1 ? '' : 's'; ?> para «<?php echo cengi_part_html($busqueda); ?>»
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table cengi-participants-table">
                <thead>
                    <tr>
                        <th>Participante</th>
                        <th>CUI</th>
                        <th>Ingenio</th>
                        <th>Asistencia</th>
                        <th class="text-center">Sesiones</th>
                        <th>Nota final</th>
                        <th>Diploma</th>
                        <th><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$filas): ?>
                    <tr><td colspan="8" class="cengi-participants-empty"><span class="glyphicon glyphicon-user"></span><strong>No hay participantes para mostrar</strong><small>Selecciona otro curso o modifica los filtros.</small></td></tr>
                <?php endif; ?>
                <?php foreach ($filas as $fila): ?>
                    <?php
                    [$etiquetaEstado, $claseEstado] = cengi_part_estado($fila);
                    $inactivo = $claseEstado === 'is-inactive';
                    $asistencia = is_numeric($fila['asistencia']) ? max(0, min(100, (float) $fila['asistencia'])) : 0;
                    $claseAsistencia = !is_numeric($fila['asistencia']) ? '' : ($asistencia >= 80 ? ' is-good' : ($asistencia >= 60 ? ' is-mid' : ' is-low'));
                    $historialJson = json_encode($historial[(int) $fila['participante_id']] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    $subtitulo = implode(' · ', array_filter([$fila['puesto_participantes'], $fila['area_participantes']]));
                    ?>
                    <tr<?php echo $inactivo ? ' class="is-inactive-row"' : ''; ?>>
                        <td>
                            <div class="cengi-participant-name">
                                <span class="cengi-participant-avatar"><?php echo cengi_part_html(cengi_part_iniciales($fila['nombre_participantes'])); ?></span>
                                <span>
                                    <strong><?php echo cengi_part_html($fila['nombre_participantes']); ?><?php if ($inactivo): ?> <span class="cengi-psum-chip is-muted">Inactivo</span><?php endif; ?></strong>
                                    <?php if ($subtitulo !== ''): ?><small><?php echo cengi_part_html($subtitulo); ?></small><?php endif; ?>
                                    <small><?php echo cengi_part_html($fila['correo_participantes']); ?></small>
                                </span>
                            </div>
                        </td>
                        <td><span class="cengi-participant-cui"><?php echo cengi_part_html($fila['cui_participantes']); ?></span><?php if (cengi_cui_nd_es_generado($fila['cui_participantes'])): ?> <small class="cengi-cui-sd" title="Código de referencia asignado por el sistema: reemplázalo por su documento cuando lo tenga">Sin documento</small><?php endif; ?></td>
                        <td><span class="cengi-psum-chip"><?php echo cengi_part_html($fila['nombre_ingenios']); ?></span></td>
                        <td>
                            <div class="cengi-psum-attendance<?php echo $claseAsistencia; ?>">
                                <span class="cengi-psum-attendance-val"><?php echo is_numeric($fila['asistencia']) ? cengi_part_nota($fila['asistencia']) . '%' : '—'; ?></span>
                                <span class="cengi-psum-attendance-bar"><span style="width:<?php echo $asistencia; ?>%"></span></span>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="cengi-psum-sessions"><?php echo cengi_part_nota($fila['sesiones_asistidas']); ?><?php if ($sesionesCurso > 0 && is_numeric($fila['sesiones_asistidas'])): ?><small> / <?php echo $sesionesCurso; ?></small><?php endif; ?></span>
                        </td>
                        <td>
                            <?php if (is_numeric($fila['posevaluacion'])): $aprobado = (float) $fila['posevaluacion'] >= CENGI_NOTA_APROBACION; ?>
                                <div class="cengi-psum-grade <?php echo $aprobado ? 'is-ok' : 'is-low'; ?>">
                                    <strong><?php echo cengi_part_nota($fila['posevaluacion']); ?></strong>
                                    <span><?php echo $aprobado ? 'Aprobado' : 'No aprobado'; ?></span>
                                </div>
                            <?php else: ?>
                                <span class="cengi-psum-chip is-muted">Sin nota</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($fila['diploma'])): ?>
                                <a class="cengi-psum-diploma" href="descargar_diploma.php?asignacion_id=<?php echo (int) $fila['asignacion_id']; ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file"></span> Ver diploma</a>
                            <?php else: ?><span class="cengi-psum-pending">Pendiente</span><?php endif; ?>
                        </td>
                        <td>
                            <?php $puedeEnviarCorreo = cengi_puede_gestionar() || $puedeDiploma; ?>
                            <div class="dropdown cengi-psum-actions">
                                <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    Acciones <span class="caret"></span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-right">
                                    <?php if ($puedeCalificar): ?>
                                        <li><a href="#" class="participant-grades-trigger" data-toggle="modal" data-target="#participant-grades-modal" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-assignment="<?php echo (int) $fila['asignacion_id']; ?>" data-attendance="<?php echo cengi_part_html($fila['asistencia']); ?>" data-attendance-sessions="<?php echo cengi_part_html($fila['sesiones_asistidas']); ?>" data-pre="<?php echo cengi_part_html($fila['evaluacion']); ?>" data-post="<?php echo cengi_part_html($fila['posevaluacion']); ?>" data-diploma="<?php echo cengi_part_href($fila['diploma']); ?>"><span class="glyphicon glyphicon-education"></span> Calificaciones</a></li>
                                    <?php endif; ?>
                                    <?php if ($puedeEditar): ?>
                                        <li><a href="#" class="participant-edit-trigger" data-toggle="modal" data-target="#participant-edit-modal" data-id="<?php echo (int) $fila['participante_id']; ?>" data-ingenio="<?php echo (int) $fila['ingenio_id']; ?>" data-cui="<?php echo cengi_part_html($fila['cui_participantes']); ?>" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-position="<?php echo cengi_part_html($fila['puesto_participantes']); ?>" data-area="<?php echo cengi_part_html($fila['area_participantes']); ?>" data-email="<?php echo cengi_part_html($fila['correo_participantes']); ?>" data-degree="<?php echo cengi_part_html($fila['grado_academico_participantes']); ?>" data-phone="<?php echo cengi_part_html($fila['telefono_participantes']); ?>" data-state="<?php echo (int) $fila['estado_participantes'] === 1 && (int) $fila['estado_asignaciones'] === 1 ? 1 : 0; ?>"><span class="glyphicon glyphicon-pencil"></span> Editar datos</a></li>
                                    <?php endif; ?>
                                    <li><a href="#" class="participant-profile-trigger" data-toggle="modal" data-target="#participant-profile-modal" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-cui="<?php echo cengi_part_html($fila['cui_participantes']); ?>" data-company="<?php echo cengi_part_html($fila['nombre_ingenios']); ?>" data-area="<?php echo cengi_part_html($fila['area_participantes']); ?>" data-email="<?php echo cengi_part_html($fila['correo_participantes']); ?>" data-degree="<?php echo cengi_part_html($fila['grado_academico_participantes']); ?>" data-phone="<?php echo cengi_part_html($fila['telefono_participantes']); ?>" data-history="<?php echo cengi_part_html($historialJson); ?>" data-assignment="<?php echo (int) $fila['asignacion_id']; ?>" data-assignment-active="<?php echo (int) $fila['estado_asignaciones'] === 1 ? 1 : 0; ?>"><span class="glyphicon glyphicon-eye-open"></span> Ver perfil</a></li>
                                    <li role="separator" class="divider"></li>
                                    <li><a href="constancia_notas.php?asignacion_id=<?php echo (int) $fila['asignacion_id']; ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-list-alt"></span> Constancia de notas</a></li>
                                    <?php if (!empty($fila['diploma'])): ?>
                                        <li><a href="descargar_diploma.php?asignacion_id=<?php echo (int) $fila['asignacion_id']; ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file"></span> Ver diploma</a></li>
                                    <?php endif; ?>
                                    <?php if ($puedeEnviarCorreo): ?>
                                        <li><a href="#" data-toggle="modal" data-target="#participant-send-modal" data-assignment="<?php echo (int) $fila['asignacion_id']; ?>" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-email="<?php echo cengi_part_html($fila['correo_participantes']); ?>" data-has-diploma="<?php echo !empty($fila['diploma']) ? 1 : 0; ?>"><span class="glyphicon glyphicon-envelope"></span> Enviar diploma y notas por correo</a></li>
                                    <?php endif; ?>
                                    <?php if ($puedeEliminar && (int) $fila['estado_asignaciones'] === 1): ?>
                                        <li role="separator" class="divider"></li>
                                        <li><a href="#" class="participant-delete-trigger is-danger" data-toggle="modal" data-target="#confirm-participant-delete" data-name="<?php echo cengi_part_html($fila['nombre_participantes']); ?>" data-href="remover_participante_curso.php?asignacion_id=<?php echo (int) $fila['asignacion_id']; ?>&amp;curso_id=<?php echo $cursoId; ?>"><span class="glyphicon glyphicon-remove"></span> Remover del curso</a></li>
                                    <?php endif; ?>
                                </ul>
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
                    <div class="form-group"><label for="participant-edit-cui">Documento (DPI, pasaporte u otro)</label><input class="form-control" id="participant-edit-cui" name="cui_participantes" maxlength="25" placeholder="En blanco: se conserva el actual" title="Reemplaza el código de referencia (NNNND) por su documento cuando lo tenga: el anterior queda en el historial."></div>
                    <div class="form-group"><label for="participant-edit-company">Ingenio</label><select class="form-control" id="participant-edit-company" name="ingenio" required><?php foreach ($ingenios as $ingenio): ?><option value="<?php echo (int) $ingenio['id']; ?>"><?php echo cengi_part_html($ingenio['nombre_ingenios']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="participant-edit-area">Área</label><input class="form-control" id="participant-edit-area" name="area_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-position">Puesto</label><input class="form-control" id="participant-edit-position" name="puesto_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-email">Correo electrónico</label><input type="email" class="form-control" id="participant-edit-email" name="correo_participantes" maxlength="255"></div>
                    <div class="form-group"><label for="participant-edit-degree">Grado académico</label><input class="form-control" id="participant-edit-degree" name="grado_academico_participantes" maxlength="255"></div>
                    <div class="form-group"><label for="participant-edit-phone">Teléfono</label><input type="tel" class="form-control" id="participant-edit-phone" name="telefono_participantes" maxlength="50"></div>
                    <div class="form-group"><label for="participant-edit-state">Estado en el curso</label><select class="form-control" id="participant-edit-state" name="estado_participantes"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
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
                <?php /* La pre-evaluacion no se muestra en el resumen, pero se reenvia tal cual para no borrarla. */ ?>
                <input type="hidden" name="evaluacion" id="participant-grades-pre">
                <div class="cengi-participant-modal-grid is-three">
                    <div class="form-group"><label for="participant-grades-attendance">Asistencia %</label><input type="number" min="0" max="100" step="0.01" class="form-control" id="participant-grades-attendance" name="asistencia"></div>
                    <div class="form-group"><label for="participant-grades-attendance-sessions">Sesiones asistidas<?php echo $sesionesCurso > 0 ? ' (de ' . $sesionesCurso . ')' : ''; ?></label><input type="number" min="0" step="1" class="form-control" id="participant-grades-attendance-sessions" name="sesiones_asistidas"></div>
                    <div class="form-group">
                        <label for="participant-grades-post">Nota final</label>
                        <input type="number" min="0" max="100" step="0.01" class="form-control" id="participant-grades-post" name="posevaluacion"<?php echo $cursoConModulos ? ' readonly' : ''; ?>>
                        <?php if ($cursoConModulos): ?><p class="help-block">Se calcula con las notas de los módulos. <a href="ver_participante_curso.php?id=<?php echo (int) $cursoId; ?>">Cargar notas por módulo</a></p><?php endif; ?>
                    </div>
                </div>
                <?php if ($puedeDiploma): ?>
                    <div class="form-group" id="participant-grades-diploma-group"><label for="participant-grades-diploma">Diploma (PDF)</label><input type="file" class="form-control" id="participant-grades-diploma" name="diploma" accept="application/pdf,.pdf"></div>
                    <div class="form-group" id="participant-grades-diploma-existing" hidden><label>Diploma (PDF)</label><p class="help-block">Ya hay un diploma cargado. <a href="#" id="participant-grades-diploma-link" target="_blank" rel="noopener">Ver PDF</a></p></div>
                <?php endif; ?>
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
                <div class="cengi-upload-guide">
                    <strong>Formato requerido</strong>
                    <span><?php echo $cursoConModulos ? 'CUI, ASISTENCIA' : 'CUI, ASISTENCIA, PRE_EVALUACION, POST_EVALUACION'; ?></span>
                    <small>Los valores deben estar entre 0 y 100. La plantilla ya trae a los participantes del curso con sus valores actuales: complétala y súbela tal cual.<?php echo $cursoConModulos ? ' Este curso tiene módulos: las notas se cargan por módulo en Seguimiento por curso; aquí solo la asistencia acumulada.' : ''; ?></small>
                    <a class="btn btn-default btn-sm cengi-upload-template" href="plantilla_calificaciones.php?curso_id=<?php echo (int) $cursoId; ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar plantilla del curso (CSV)</a>
                </div>
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
                <div class="cengi-upload-guide">
                    <strong>Formato requerido</strong>
                    <span>INGENIO, CUI, NOMBRE, PUESTO, AREA, CORREO_ELECTRONICO, GRADO_ACADEMICO, TELEFONO</span>
                    <small>Admite archivos CSV, XLS y XLSX. El INGENIO debe escribirse igual que en el directorio de ingenios. Reemplaza la fila de ejemplo por tus participantes.</small>
                    <span class="cengi-upload-template-group">
                        <a class="btn btn-default btn-sm cengi-upload-template" href="plantilla_participantes.php"><span class="glyphicon glyphicon-download-alt"></span> Plantilla Excel</a>
                        <a class="btn btn-default btn-sm cengi-upload-template" href="plantilla_participantes.csv" download><span class="glyphicon glyphicon-download-alt"></span> Plantilla CSV</a>
                    </span>
                </div>
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
        <div class="modal-footer">
            <?php if ($puedeEliminar): ?>
                <button type="button" class="btn btn-danger" id="participant-profile-delete-trigger" style="margin-right:auto;" hidden><span class="glyphicon glyphicon-remove"></span> Desasignar del curso</button>
            <?php endif; ?>
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
        </div>
    </div></div>
</div>

<?php if (cengi_puede_gestionar() || $puedeDiploma): ?>
<div class="modal fade cengi-participant-modal" id="participant-send-modal" tabindex="-1" role="dialog" aria-labelledby="participant-send-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content">
        <form action="constancia_notas.php" method="post">
            <input type="hidden" name="accion" value="enviar"><input type="hidden" name="asignacion_id" id="participant-send-assignment">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-envelope"></span></span><div><h4 class="modal-title" id="participant-send-title">Enviar por correo</h4><p id="participant-send-name"></p></div></div>
            <div class="modal-body">
                <p class="help-block" style="margin-top:0;">Se enviará a <b id="participant-send-email"></b> con los documentos adjuntos en PDF.</p>
                <div class="checkbox"><label><input type="checkbox" name="constancia" value="1" checked> Constancia de notas por módulo</label></div>
                <div class="checkbox"><label><input type="checkbox" name="diploma" value="1" id="participant-send-diploma"> Diploma <small class="text-muted" id="participant-send-diploma-note"></small></label></div>
                <p class="help-block" id="participant-send-warning" hidden style="color:#B23223;">El participante no tiene un correo válido: edita sus datos primero.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success" id="participant-send-submit"><span class="glyphicon glyphicon-send"></span> Enviar</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if ($puedeEliminar): ?>
<div class="modal fade cengi-participant-modal" id="confirm-participant-delete" tabindex="-1" role="dialog" aria-labelledby="participant-delete-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content cengi-confirm-content">
        <div class="modal-body"><span class="cengi-confirm-icon"><span class="glyphicon glyphicon-remove"></span></span><h4 id="participant-delete-title">Remover del curso</h4><p>¿Deseas remover a <strong id="participant-delete-name"></strong> de este curso? El participante seguirá disponible y sus demás asignaciones no serán modificadas.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><a class="btn btn-danger btn-ok" href="#">Sí, remover</a></div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var CENGI_CURSO_ID = <?php echo (int) $cursoId; ?>;
    // El selector de curso se envía solo (onchange en su propio formulario) y el estado
    // se elige con las pestañas, que son enlaces.

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
        document.getElementById('participant-grades-attendance-sessions').value = trigger.getAttribute('data-attendance-sessions') || '';
        document.getElementById('participant-grades-pre').value = trigger.getAttribute('data-pre') || '';
        document.getElementById('participant-grades-post').value = trigger.getAttribute('data-post') || '';
        var diploma = trigger.getAttribute('data-diploma') || '';
        var assignmentId = trigger.getAttribute('data-assignment') || '';
        var $diplomaGroup = $('#participant-grades-diploma-group');
        var $diplomaExisting = $('#participant-grades-diploma-existing');
        if (diploma && assignmentId && $diplomaGroup.length) {
            $diplomaGroup.attr('hidden', true);
            $diplomaExisting.removeAttr('hidden');
            // El link ya no apunta al PDF crudo: se arma hacia descargar_diploma.php,
            // que sirve el archivo con el nombre formateado (curso_ano_participante.pdf).
            document.getElementById('participant-grades-diploma-link').href = 'descargar_diploma.php?asignacion_id=' + encodeURIComponent(assignmentId);
        } else {
            $diplomaGroup.removeAttr('hidden');
            $diplomaExisting.attr('hidden', true);
        }
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

        var $deleteTrigger = $('#participant-profile-delete-trigger');
        if ($deleteTrigger.length) {
            var assignmentId = trigger.getAttribute('data-assignment') || '';
            var assignmentActive = trigger.getAttribute('data-assignment-active') === '1';
            if (assignmentActive && assignmentId && CENGI_CURSO_ID > 0) {
                $deleteTrigger
                    .data('name', name)
                    .data('href', 'remover_participante_curso.php?asignacion_id=' + encodeURIComponent(assignmentId) + '&curso_id=' + CENGI_CURSO_ID)
                    .removeAttr('hidden');
            } else {
                $deleteTrigger.attr('hidden', true).removeData('name').removeData('href');
            }
        }
    });

    $(document).on('click', '#participant-profile-delete-trigger', function () {
        var $btn = $(this);
        var name = $btn.data('name') || '';
        var href = $btn.data('href') || '#';
        if (!href || href === '#') return;
        $('#participant-profile-modal').one('hidden.bs.modal', function () {
            document.getElementById('participant-delete-name').textContent = name;
            $('#confirm-participant-delete').find('.btn-ok').attr('href', href);
            $('#confirm-participant-delete').modal('show');
        });
        $('#participant-profile-modal').modal('hide');
    });

    $('#participant-send-modal').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        var email = trigger.getAttribute('data-email') || '';
        var valido = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email);
        var conDiploma = trigger.getAttribute('data-has-diploma') === '1';
        document.getElementById('participant-send-assignment').value = trigger.getAttribute('data-assignment') || '';
        document.getElementById('participant-send-name').textContent = trigger.getAttribute('data-name') || '';
        document.getElementById('participant-send-email').textContent = email || 'sin correo';
        var diploma = document.getElementById('participant-send-diploma');
        diploma.checked = conDiploma; diploma.disabled = !conDiploma;
        document.getElementById('participant-send-diploma-note').textContent = conDiploma ? '' : '(aún no tiene diploma)';
        document.getElementById('participant-send-warning').hidden = valido;
        document.getElementById('participant-send-submit').disabled = !valido;
    });

    // El menú "Acciones" vive dentro de .table-responsive (overflow): se posiciona
    // fijo respecto a la ventana para que no se recorte en las últimas filas.
    $(document).on('shown.bs.dropdown', '.cengi-psum-actions', function () {
        var menu = this.querySelector('.dropdown-menu');
        var r = this.querySelector('.dropdown-toggle').getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.right = 'auto';
        menu.style.left = Math.max(8, r.right - menu.offsetWidth) + 'px';
        var top = r.bottom + 4;
        if (top + menu.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - menu.offsetHeight - 4);
        menu.style.top = top + 'px';
    }).on('hidden.bs.dropdown', '.cengi-psum-actions', function () {
        this.querySelector('.dropdown-menu').removeAttribute('style');
    });
    $(window).on('scroll resize', function () { $('.cengi-psum-actions.open .dropdown-toggle').dropdown('toggle'); });

    $('#confirm-participant-delete').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        document.getElementById('participant-delete-name').textContent = trigger.getAttribute('data-name') || '';
        $(this).find('.btn-ok').attr('href', trigger.getAttribute('data-href') || '#');
    });
})();
</script>
</body>
</html>
