<?php
/**
 * Solicitudes directas a cursos: las que llegan del formulario público (inscripcion.php,
 * grupo "Otros cursos") para cursos creados en Gestión de cursos sin planificación.
 * Las de una convocatoria (planificacion_id) se gestionan en inscripciones.php y
 * pagos_inscripcion.php. Aprobar (aprobar.php) inscribe al participante en el curso;
 * rechazar (rechazar.php) la cierra; la edición se guarda en actualizar_solicitud.php.
 */
require_once "conexion.php";
require_once "menu.php";

$conexion = conectar();
$puedeEditarSolicitudes = cengi_puede_editar_solicitudes();
$puedeAprobarSolicitudes = cengi_puede_aprobar_solicitudes();
$puedeRechazarSolicitudes = cengi_puede_rechazar_solicitudes();
$puedeGestionar = $puedeEditarSolicitudes || $puedeAprobarSolicitudes || $puedeRechazarSolicitudes;

cengi_require_gestor_solicitudes('ver_cursos.php');

$h = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
// creado/actualizado los pone MySQL en UTC; se muestran en hora de Guatemala.
date_default_timezone_set('America/Guatemala');
$fecha = static function ($iso, $conHora = false, $utc = false) {
    return $iso ? date($conHora ? 'd/m/Y H:i' : 'd/m/Y', strtotime((string) $iso . ($utc ? ' UTC' : ''))) : '—';
};

$campo = trim((string) ($_GET['q'] ?? $_POST['campo'] ?? ''));
$estadoFiltro = in_array($_GET['estado'] ?? '', ['pendiente', 'aprobado', 'rechazado'], true) ? $_GET['estado'] : '';
$verTodo = cengi_ve_todo_por_rol_o_ingenio();

// Solo solicitudes directas a un curso (sin planificacion_id).
$condiciones = ['s.planificacion_id IS NULL'];
$params = [];
if (!$verTodo) {
    $condiciones[] = cengi_sql_texto_normalizado('i.nombre_ingenios') . " = ?";
    $params[] = cengi_texto_normalizado(cengi_ingenio_nombre_actual());
}
$whereBase = implode(' AND ', $condiciones);

// Resumen sobre todo el universo visible (no cambia con la búsqueda).
$stmt = $conexion->prepare("SELECT LOWER(TRIM(s.estado)) AS estado, COUNT(*) AS n FROM solicitudes_inscripcion s
    LEFT JOIN ingenios i ON s.ingenio_id = i.id WHERE {$whereBase} GROUP BY 1");
$stmt->execute($params);
$conteo = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0];
foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $e => $n) {
    $conteo[$e] = ($conteo[$e] ?? 0) + (int) $n;
}
$total = array_sum($conteo);

if ($campo !== '') {
    $condiciones[] = "(s.nombre_participante LIKE ? OR s.cui_participante LIKE ? OR c.nombre_cursos LIKE ? OR c.codigo_curso LIKE ?
        OR i.nombre_ingenios LIKE ? OR s.institucion_otra LIKE ? OR s.correo LIKE ? OR s.telefono LIKE ? OR s.tipo_pago LIKE ?)";
    array_push($params, ...array_fill(0, 9, '%' . $campo . '%'));
}
if ($estadoFiltro !== '') {
    $condiciones[] = 'LOWER(TRIM(s.estado)) = ?';
    $params[] = $estadoFiltro;
}

$stmt = $conexion->prepare("
    SELECT s.*, i.nombre_ingenios, c.nombre_cursos, c.codigo_curso, c.tipo AS curso_tipo, c.inicio AS curso_inicio, c.fin AS curso_fin,
        -- ¿ya está inscrito en ese curso? (mismo CUI)
        EXISTS (SELECT 1 FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id
                WHERE a.cursos_id = s.curso_id AND a.estado_asignaciones = 1 AND p.cui_participantes = s.cui_participante AND s.cui_participante <> '') AS ya_inscrito,
        -- otras solicitudes pendientes de la misma persona al mismo curso
        (SELECT COUNT(*) FROM solicitudes_inscripcion o WHERE o.curso_id = s.curso_id AND o.planificacion_id IS NULL AND o.id_solicitud <> s.id_solicitud
                AND o.estado = 'Pendiente' AND (LOWER(o.correo) = LOWER(s.correo) OR (o.cui_participante <> '' AND o.cui_participante = s.cui_participante))) AS duplicadas
    FROM solicitudes_inscripcion s
    LEFT JOIN ingenios i ON s.ingenio_id = i.id
    INNER JOIN cursos c ON s.curso_id = c.id
    WHERE " . implode(' AND ', $condiciones) . "
    ORDER BY CASE s.estado WHEN 'Pendiente' THEN 1 WHEN 'Aprobado' THEN 2 WHEN 'Rechazado' THEN 3 ELSE 4 END, s.id_solicitud DESC
");
$stmt->execute($params);
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cursos que hoy reciben solicitudes directas (los mismos del grupo "Otros cursos" del formulario).
$cursosAbiertos = $conexion->query("
    SELECT c.id, c.codigo_curso, c.nombre_cursos, c.tipo, c.inicio, c.fin,
        (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.curso_id = c.id AND s.planificacion_id IS NULL AND s.estado = 'Pendiente') AS pendientes
    FROM cursos c
    WHERE (c.fin IS NULL OR c.fin >= CURDATE())
      AND c.id NOT IN (SELECT curso_id FROM planificacion_cursos WHERE curso_id IS NOT NULL)
    ORDER BY c.inicio, c.nombre_cursos
")->fetchAll(PDO::FETCH_ASSOC);
$pendientesConvocatoria = (int) $conexion->query("SELECT COUNT(*) FROM solicitudes_inscripcion WHERE planificacion_id IS NOT NULL AND estado = 'Pendiente'")->fetchColumn();

// Edición en ventana: ingenios y cursos directos (más el actual de la solicitud).
$ingenios = $puedeEditarSolicitudes ? $conexion->query('SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_ASSOC) : [];
$cursosEdicion = [];
if ($puedeEditarSolicitudes) {
    $cursosEdicion = $conexion->query("SELECT c.id, c.nombre_cursos, c.inicio FROM cursos c
        WHERE c.id NOT IN (SELECT curso_id FROM planificacion_cursos WHERE curso_id IS NOT NULL)
          AND ((c.fin IS NULL OR c.fin >= CURDATE()) OR c.id IN (SELECT curso_id FROM solicitudes_inscripcion WHERE planificacion_id IS NULL AND estado = 'Pendiente'))
        ORDER BY c.nombre_cursos")->fetchAll(PDO::FETCH_ASSOC);
}
$editarId = (int) ($_GET['editar'] ?? 0);

$resultado = trim((string) ($_GET['resultado'] ?? ''));
$resultadoId = (int) ($_GET['id'] ?? 0);
$mensajeResultado = trim((string) ($_GET['mensaje'] ?? ''));
$avisos = [
    'aprobada' => ['is-ok', 'ok', 'Solicitud aprobada', 'La solicitud #' . $resultadoId . ' fue aprobada: el participante quedó inscrito en el curso y se le envió el aviso por correo.'],
    'rechazada' => ['is-warn', 'remove', 'Solicitud rechazada', 'La solicitud #' . $resultadoId . ' quedó registrada como rechazada y se avisó al participante.'],
    'editada' => ['is-ok', 'pencil', 'Solicitud actualizada', 'Se guardaron los cambios de la solicitud #' . $resultadoId . '.'],
    'error' => ['is-error', 'warning-sign', 'No se pudo completar la acción', $mensajeResultado ?: 'Intenta nuevamente o verifica los datos de la solicitud.'],
];
$aviso = $avisos[$resultado] ?? null;

$url = static function (array $cambios) use ($campo, $estadoFiltro) {
    return 'solicitudes.php?' . http_build_query(array_filter(array_merge(['q' => $campo, 'estado' => $estadoFiltro], $cambios), 'strlen'));
};
$baseUrl = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']), '/');
$v = static function ($archivo) {
    return (int) (@filemtime(__DIR__ . '/css/' . $archivo) ?: 1);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitudes directas | CENGICURSOS</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo $v('proyecto.css'); ?>">
    <link rel="stylesheet" href="css/cengi-tema.css?v=<?php echo $v('cengi-tema.css'); ?>">
    <link rel="stylesheet" href="css/solicitudes.css?v=<?php echo $v('solicitudes.css'); ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container sol-page">
    <?php cengi_nav_barra('solicitudes.php', ['inscripciones.php' => $pendientesConvocatoria, 'solicitudes.php' => $conteo['pendiente']]); ?>

    <header class="sol-hero">
        <div>
            <small>Inscripciones · solicitudes directas</small>
            <h1>Solicitudes directas a cursos</h1>
            <p>Llegan del formulario público cuando la persona elige un curso creado en Gestión de cursos <b>sin planificación</b> (grupo “Otros cursos”).
                Al <b>aprobar</b>, el participante queda inscrito en el curso, con su cuenta de estudiante, y recibe un correo. Las inscripciones a convocatorias se gestionan en <a href="inscripciones.php">Inscripciones</a>.</p>
        </div>
    </header>

    <?php if ($aviso): [$clase, $icono, $titulo, $texto] = $aviso; ?>
        <div class="sol-aviso <?php echo $clase; ?>" role="status" aria-live="polite">
            <span class="glyphicon glyphicon-<?php echo $icono; ?>"></span>
            <div><strong><?php echo $titulo; ?></strong><p><?php echo $h($texto); ?></p></div>
            <button type="button" class="sol-aviso-cerrar" aria-label="Cerrar">&times;</button>
        </div>
    <?php endif; ?>

    <nav class="sol-kpis" aria-label="Filtrar por estado">
        <a href="<?php echo $h($url(['estado' => ''])); ?>" class="is-verde<?php echo $estadoFiltro === '' ? ' is-activo' : ''; ?>"><span class="glyphicon glyphicon-inbox"></span><b><?php echo $total; ?></b><em>Todas</em></a>
        <a href="<?php echo $h($url(['estado' => 'pendiente'])); ?>" class="is-amarillo<?php echo $estadoFiltro === 'pendiente' ? ' is-activo' : ''; ?>"><span class="glyphicon glyphicon-time"></span><b><?php echo $conteo['pendiente']; ?></b><em>Pendientes de revisar</em></a>
        <a href="<?php echo $h($url(['estado' => 'aprobado'])); ?>" class="is-verde2<?php echo $estadoFiltro === 'aprobado' ? ' is-activo' : ''; ?>"><span class="glyphicon glyphicon-ok"></span><b><?php echo $conteo['aprobado']; ?></b><em>Aprobadas (inscritos)</em></a>
        <a href="<?php echo $h($url(['estado' => 'rechazado'])); ?>" class="is-naranja<?php echo $estadoFiltro === 'rechazado' ? ' is-activo' : ''; ?>"><span class="glyphicon glyphicon-remove"></span><b><?php echo $conteo['rechazado']; ?></b><em>Rechazadas</em></a>
    </nav>

    <section class="sol-panel">
        <div class="sol-toolbar">
            <form method="get" class="sol-buscar" role="search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="search" name="q" value="<?php echo $h($campo); ?>" placeholder="Participante, CUI, curso, correo o ingenio" aria-label="Buscar solicitudes">
                <?php if ($estadoFiltro !== ''): ?><input type="hidden" name="estado" value="<?php echo $h($estadoFiltro); ?>"><?php endif; ?>
                <button type="submit" class="sol-btn">Buscar</button>
                <?php if ($campo !== ''): ?><a class="sol-btn is-linea" href="<?php echo $h($url(['q' => ''])); ?>">Limpiar</a><?php endif; ?>
            </form>
            <span class="sol-conteo"><?php echo count($solicitudes); ?> de <?php echo $total; ?> solicitud(es)<?php echo $conteo['pendiente'] ? ' · las pendientes primero' : ''; ?></span>
        </div>

        <?php if (!$solicitudes): ?>
            <div class="sol-vacio">
                <span class="glyphicon glyphicon-inbox"></span>
                <h2><?php echo $campo !== '' || $estadoFiltro !== '' ? 'Ninguna solicitud coincide' : 'No hay solicitudes directas'; ?></h2>
                <p><?php echo $campo !== '' || $estadoFiltro !== ''
                    ? 'Prueba con otro término o <a href="solicitudes.php">quita los filtros</a>.'
                    : ($cursosAbiertos
                        ? 'Aparecerán aquí cuando alguien se inscriba desde el formulario público a uno de los cursos abiertos de abajo.'
                        : 'Hoy ningún curso recibe solicitudes directas: todos los cursos vigentes vienen de una planificación y se inscriben por <a href="inscripciones.php">convocatoria</a>.'); ?></p>
            </div>
        <?php else: ?>
            <div class="sol-lista">
                <div class="sol-fila sol-cabecera" aria-hidden="true">
                    <span>Participante</span><span>Curso solicitado</span><span>Ingenio y pago</span><span>Estado</span><?php if ($puedeGestionar): ?><span>Acciones</span><?php endif; ?>
                </div>
                <?php foreach ($solicitudes as $fila):
                    $estado = trim((string) ($fila['estado'] ?: 'Pendiente'));
                    $clave = strtolower($estado);
                    $esPendiente = $clave === 'pendiente';
                    $claseEstado = ['aprobado' => 'is-verde', 'rechazado' => 'is-naranja'][$clave] ?? 'is-amarillo';
                    $ingenio = $fila['nombre_ingenios'] ?: ($fila['institucion_otra'] ?: 'Sin ingenio');
                    $datosEdicion = array_intersect_key($fila, array_flip(['id_solicitud', 'nombre_participante', 'cui_participante', 'puesto_participante', 'area_participante', 'grado_academico', 'correo', 'telefono', 'ingenio_id', 'curso_id', 'tipo_pago']));
                ?>
                    <article class="sol-fila <?php echo $claseEstado; ?>" id="sol-<?php echo (int) $fila['id_solicitud']; ?>">
                        <div class="sol-persona">
                            <span class="sol-avatar" aria-hidden="true"><?php echo $h(mb_strtoupper(mb_substr($fila['nombre_participante'], 0, 1, 'UTF-8'), 'UTF-8')); ?></span>
                            <div>
                                <strong><?php echo $h($fila['nombre_participante']); ?></strong>
                                <small>#<?php echo (int) $fila['id_solicitud']; ?> · CUI <?php echo $h($fila['cui_participante'] ?: '—'); ?> · <?php echo $h($fila['grado_academico']); ?></small>
                                <small><?php echo $h(trim($fila['puesto_participante'] . ' · ' . $fila['area_participante'], ' ·')); ?></small>
                                <small class="sol-contacto">
                                    <?php if ($fila['correo']): ?><a href="mailto:<?php echo $h($fila['correo']); ?>"><span class="glyphicon glyphicon-envelope"></span><?php echo $h($fila['correo']); ?></a><?php endif; ?>
                                    <?php if ($fila['telefono']): ?><a href="tel:<?php echo $h(preg_replace('/[^\d+]/', '', $fila['telefono'])); ?>"><span class="glyphicon glyphicon-earphone"></span><?php echo $h($fila['telefono']); ?></a><?php endif; ?>
                                </small>
                            </div>
                        </div>
                        <div class="sol-curso">
                            <strong><?php echo $h($fila['nombre_cursos']); ?></strong>
                            <small><?php echo $h(trim(($fila['codigo_curso'] ?: '') . ' · ' . ($fila['curso_tipo'] ?: ''), ' ·')); ?></small>
                            <small><?php echo $fecha($fila['curso_inicio']) . ' – ' . $fecha($fila['curso_fin']); ?></small>
                            <?php if ($fila['ya_inscrito'] && $esPendiente): ?><span class="sol-chip is-verde2" title="Ya tiene una asignación activa en este curso">Ya inscrito en el curso</span><?php endif; ?>
                            <?php if ($fila['duplicadas'] && $esPendiente): ?><span class="sol-chip is-naranja" title="Otra solicitud pendiente de la misma persona al mismo curso">Duplicada (<?php echo (int) $fila['duplicadas'] + 1; ?>)</span><?php endif; ?>
                        </div>
                        <div class="sol-ingenio">
                            <span class="sol-chip is-gris"><span class="glyphicon glyphicon-map-marker"></span><?php echo $h($ingenio); ?></span>
                            <small>Paga: <b><?php echo $h($fila['tipo_pago'] ?: '—'); ?></b></small>
                        </div>
                        <div class="sol-estado">
                            <span class="sol-chip <?php echo $claseEstado; ?>"><?php echo $h($estado); ?></span>
                            <small>Recibida <?php echo $fecha($fila['creado'], true, true); ?></small>
                            <?php if (!$esPendiente && !empty($fila['asignado_en'])): ?><small>Inscrito <?php echo $fecha($fila['asignado_en']); ?><?php echo $fila['asignado_por'] ? ' · ' . $h($fila['asignado_por']) : ''; ?></small><?php elseif (!$esPendiente && $fila['actualizado']): ?><small>Gestionada <?php echo $fecha($fila['actualizado'], false, true); ?></small><?php endif; ?>
                        </div>
                        <?php if ($puedeGestionar): ?>
                            <div class="sol-acciones">
                                <?php if ($esPendiente): ?>
                                    <?php if ($puedeAprobarSolicitudes): ?>
                                        <button type="button" class="sol-btn" data-toggle="modal" data-target="#confirm-request-action" data-action="aprobar.php" data-kind="aprobar"
                                            data-id="<?php echo (int) $fila['id_solicitud']; ?>" data-name="<?php echo $h($fila['nombre_participante']); ?>" data-course="<?php echo $h($fila['nombre_cursos']); ?>"
                                            data-aviso="<?php echo $fila['ya_inscrito'] ? 'Ya tiene una asignación activa en este curso: solo se actualizarán sus datos.' : ''; ?>"><span class="glyphicon glyphicon-ok"></span> Aprobar</button>
                                    <?php endif; ?>
                                    <?php if ($puedeEditarSolicitudes): ?>
                                        <button type="button" class="sol-btn is-linea" data-toggle="modal" data-target="#editar-modal" data-solicitud="<?php echo $h(json_encode($datosEdicion, JSON_UNESCAPED_UNICODE)); ?>" title="Corregir datos"><span class="glyphicon glyphicon-pencil"></span><span class="sr-only">Editar</span></button>
                                    <?php endif; ?>
                                    <?php if ($puedeRechazarSolicitudes): ?>
                                        <button type="button" class="sol-btn is-peligro" data-toggle="modal" data-target="#confirm-request-action" data-action="rechazar.php" data-kind="rechazar"
                                            data-id="<?php echo (int) $fila['id_solicitud']; ?>" data-name="<?php echo $h($fila['nombre_participante']); ?>" data-course="<?php echo $h($fila['nombre_cursos']); ?>" title="Rechazar"><span class="glyphicon glyphicon-remove"></span><span class="sr-only">Rechazar</span></button>
                                    <?php endif; ?>
                                <?php elseif ($clave === 'aprobado'): ?>
                                    <a class="sol-btn is-linea" href="ver_participante_curso.php?id=<?php echo (int) $fila['curso_id']; ?>"><span class="glyphicon glyphicon-user"></span> Ver en el curso</a>
                                <?php else: ?>
                                    <span class="sol-gestionada"><span class="glyphicon glyphicon-lock"></span> Gestionada</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="sol-panel sol-cursos">
        <div class="sol-panel-titulo">
            <h2>Cursos que reciben solicitudes directas <b><?php echo count($cursosAbiertos); ?></b></h2>
            <p>Cursos vigentes creados sin planificación. Comparte su enlace para que el formulario abra con el curso ya elegido.</p>
        </div>
        <?php if (!$cursosAbiertos): ?>
            <p class="sol-nota">Ninguno por ahora. Los cursos que vienen de una planificación se inscriben por convocatoria con su pago en <a href="inscripciones.php">Inscripciones</a>.</p>
        <?php else: ?>
            <div class="sol-lista">
                <?php foreach ($cursosAbiertos as $c): $enlace = $baseUrl . '/inscripcion.php?curso_id=' . (int) $c['id']; ?>
                    <div class="sol-fila sol-fila-curso">
                        <div class="sol-curso"><strong><?php echo $h($c['nombre_cursos']); ?></strong><small><?php echo $h(trim($c['codigo_curso'] . ' · ' . $c['tipo'], ' ·')); ?> · <?php echo $fecha($c['inicio']) . ' – ' . $fecha($c['fin']); ?></small></div>
                        <div class="sol-estado"><?php echo $c['pendientes'] ? '<a class="sol-chip is-amarillo" href="' . $h($url(['q' => $c['nombre_cursos'], 'estado' => 'pendiente'])) . '">' . (int) $c['pendientes'] . ' pendiente(s)</a>' : '<small>Sin pendientes</small>'; ?></div>
                        <div class="sol-acciones">
                            <button type="button" class="sol-btn is-linea" data-copiar="<?php echo $h($enlace); ?>"><span class="glyphicon glyphicon-link"></span> Copiar enlace</button>
                            <a class="sol-btn is-linea" href="<?php echo $h($enlace); ?>" target="_blank" rel="noopener" title="Abrir el formulario"><span class="glyphicon glyphicon-new-window"></span><span class="sr-only">Abrir formulario</span></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade cengi-confirm-modal" id="confirm-request-action" tabindex="-1" role="dialog" aria-labelledby="confirm-request-title" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-body">
                <span class="cengi-confirm-icon glyphicon glyphicon-ok" id="confirm-request-icon"></span>
                <span class="cengi-confirm-kicker" id="confirm-request-kicker">Confirmar aprobación</span>
                <h4 id="confirm-request-title">¿Aprobar esta solicitud?</h4>
                <p id="confirm-request-copy">Esta acción inscribirá al participante en el curso seleccionado.</p>
                <div class="cengi-confirm-context">
                    <strong id="confirm-request-name"></strong>
                    <span id="confirm-request-course"></span>
                </div>
                <p class="sol-modal-aviso" id="confirm-request-aviso" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <form method="post" id="confirm-request-form">
                    <input type="hidden" name="id" id="confirm-request-id">
                    <button type="submit" class="btn btn-success" id="confirm-request-submit"><span class="glyphicon glyphicon-ok"></span> Sí, aprobar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($puedeEditarSolicitudes): ?>
<div class="modal fade cengi-course-form-modal" id="editar-modal" tabindex="-1" role="dialog" aria-labelledby="editar-title">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <form method="post" action="actualizar_solicitud.php" autocomplete="off" id="editar-form">
            <input type="hidden" name="id_solicitud" id="ed-id">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker" id="ed-kicker">Solicitud</span><h4 class="modal-title" id="editar-title">Corregir solicitud pendiente</h4></div>
            <div class="modal-body">
                <div class="sol-form-grid">
                    <div class="form-group is-full"><label for="ed-nombre">Nombre completo</label><input type="text" class="form-control" id="ed-nombre" name="nombre_participante" maxlength="255" required></div>
                    <div class="form-group"><label for="ed-cui">CUI</label><input type="text" class="form-control" id="ed-cui" name="cui_participante" maxlength="20" title="DPI de 13 dígitos o el correlativo asignado"></div>
                    <div class="form-group"><label for="ed-correo">Correo</label><input type="email" class="form-control" id="ed-correo" name="correo" maxlength="255" required></div>
                    <div class="form-group"><label for="ed-tel">Teléfono</label><input type="text" class="form-control" id="ed-tel" name="telefono" maxlength="50" required></div>
                    <div class="form-group"><label for="ed-grado">Grado académico</label><input type="text" class="form-control" id="ed-grado" name="grado_academico" maxlength="255" required></div>
                    <div class="form-group"><label for="ed-puesto">Puesto</label><input type="text" class="form-control" id="ed-puesto" name="puesto_participante" maxlength="255" required></div>
                    <div class="form-group"><label for="ed-area">Área</label><input type="text" class="form-control" id="ed-area" name="area_participante" maxlength="255" required></div>
                    <div class="form-group"><label for="ed-ingenio">Ingenio</label><select class="form-control" id="ed-ingenio" name="ingenio_id" required <?php echo $verTodo ? '' : 'disabled'; ?>>
                        <?php foreach ($ingenios as $i): ?><option value="<?php echo (int) $i['id']; ?>"><?php echo $h($i['nombre_ingenios']); ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="form-group"><label for="ed-pago">Quién paga</label><select class="form-control" id="ed-pago" name="tipo_pago" required><option value="Ingenio">Ingenio</option><option value="Propio">Propio</option></select></div>
                    <div class="form-group is-full"><label for="ed-curso">Curso</label><select class="form-control" id="ed-curso" name="curso_id" required>
                        <?php foreach ($cursosEdicion as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo $h($c['nombre_cursos'] . ($c['inicio'] ? ' · inicia ' . $fecha($c['inicio']) : '')); ?></option><?php endforeach; ?>
                    </select><p class="help-block">Solo cursos sin planificación; los de una convocatoria se inscriben en Inscripciones.</p></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success" id="ed-guardar">Guardar cambios</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    $('#confirm-request-action').on('show.bs.modal', function (event) {
        var trigger = $(event.relatedTarget), isReject = trigger.data('kind') === 'rechazar', modal = $(this), aviso = trigger.data('aviso') || '';
        modal.toggleClass('is-reject', isReject);
        modal.find('#confirm-request-form').attr('action', trigger.data('action'));
        modal.find('#confirm-request-id').val(trigger.data('id'));
        modal.find('#confirm-request-name').text(trigger.data('name'));
        modal.find('#confirm-request-course').text(trigger.data('course'));
        modal.find('#confirm-request-kicker').text(isReject ? 'Confirmar rechazo' : 'Confirmar aprobación');
        modal.find('#confirm-request-title').text(isReject ? '¿Rechazar esta solicitud?' : '¿Aprobar esta solicitud?');
        modal.find('#confirm-request-copy').text(isReject
            ? 'La solicitud dejará de estar pendiente, quedará registrada como rechazada y se avisará al participante por correo.'
            : 'El participante será creado o actualizado, quedará inscrito en el curso con su cuenta de estudiante y recibirá un correo.');
        modal.find('#confirm-request-aviso').text(aviso).prop('hidden', !aviso);
        modal.find('#confirm-request-icon').toggleClass('glyphicon-ok', !isReject).toggleClass('glyphicon-remove', isReject);
        modal.find('#confirm-request-submit').prop('disabled', false)
            .toggleClass('btn-success', !isReject).toggleClass('btn-danger', isReject)
            .html(isReject ? '<span class="glyphicon glyphicon-remove"></span> Sí, rechazar' : '<span class="glyphicon glyphicon-ok"></span> Sí, aprobar');
    });
    $('#confirm-request-form').on('submit', function () {
        $('#confirm-request-submit').prop('disabled', true).html('<span class="glyphicon glyphicon-refresh cengi-is-spinning"></span> Procesando...');
    });

    // Edición en ventana con los datos de la fila.
    var campos = { id_solicitud: 'ed-id', nombre_participante: 'ed-nombre', cui_participante: 'ed-cui', correo: 'ed-correo', telefono: 'ed-tel',
        grado_academico: 'ed-grado', puesto_participante: 'ed-puesto', area_participante: 'ed-area', ingenio_id: 'ed-ingenio', tipo_pago: 'ed-pago', curso_id: 'ed-curso' };
    function llenar(d) {
        Object.keys(campos).forEach(function (k) { var el = document.getElementById(campos[k]); if (el) el.value = d[k] == null ? '' : d[k]; });
        document.getElementById('ed-kicker').textContent = 'Solicitud #' + d.id_solicitud;
    }
    $('#editar-modal').on('show.bs.modal', function (e) {
        var t = e.relatedTarget && e.relatedTarget.getAttribute('data-solicitud');
        if (t) llenar(JSON.parse(t));
        document.getElementById('ed-guardar').disabled = false;
    });
    $('#editar-form').on('submit', function () { document.getElementById('ed-guardar').disabled = true; });
    <?php if ($editarId): ?>
    (function () {
        var b = document.querySelector('#sol-<?php echo $editarId; ?> [data-solicitud]');
        if (b) b.click();
    })();
    <?php endif; ?>

    // Copiar enlace del formulario con el curso elegido.
    document.querySelectorAll('[data-copiar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var texto = b.getAttribute('data-copiar'), html = b.innerHTML;
            var listo = function () { b.innerHTML = '<span class="glyphicon glyphicon-ok"></span> Copiado'; setTimeout(function () { b.innerHTML = html; }, 1800); };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(texto).then(listo, function () { window.prompt('Copia el enlace:', texto); }); }
            else { window.prompt('Copia el enlace:', texto); }
        });
    });

    $('.sol-aviso-cerrar').on('click', function () { $(this).closest('.sol-aviso').slideUp(160); });
})();
</script>
</body>
</html>
