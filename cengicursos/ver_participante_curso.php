<?php
require_once "conexion.php";
require_once "menu.php";
require_once "curso_form_helpers.php";

cengi_require_calificador('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$soloCalifica = cengi_puede_calificar() && !$puedeGestionar;
$puedeSubirDiploma = cengi_puede_subir_diploma();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar && trim((string) ($_POST['accion'] ?? '')) === 'agregar_modulo') {
    $cursoIdModulo = (int) ($_POST['curso_id'] ?? 0);
    $nombreModulo = trim((string) ($_POST['nombre_modulo'] ?? ''));
    $horasModulo = (float) ($_POST['horas_modulo'] ?? 0);
    $temasTexto = trim((string) ($_POST['temas'] ?? ''));

    if ($cursoIdModulo > 0 && $nombreModulo !== '') {
        $stmtOrden = $db->prepare("SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_modulos WHERE curso_id = ?");
        $stmtOrden->execute([$cursoIdModulo]);
        $orden = (int) $stmtOrden->fetchColumn();

        $stmt = $db->prepare("INSERT INTO curso_modulos (curso_id, nombre, horas, orden) VALUES (?, ?, ?, ?)");
        $stmt->execute([$cursoIdModulo, $nombreModulo, $horasModulo, $orden]);
        $moduloId = (int) $db->lastInsertId();

        $temaOrden = 1;
        foreach (preg_split('/\r\n|\r|\n/', $temasTexto) as $tema) {
            $tema = trim($tema);
            if ($tema === '') {
                continue;
            }
            $stmtTema = $db->prepare("INSERT INTO curso_modulo_temas (curso_modulo_id, tema, orden) VALUES (?, ?, ?)");
            $stmtTema->execute([$moduloId, $tema, $temaOrden]);
            $temaOrden++;
        }
    }

    header('Location: ver_participante_curso.php?id=' . $cursoIdModulo);
    exit;
}

$listaCursos = $db->query("
    SELECT c.id, c.nombre_cursos, YEAR(COALESCE(c.inicio, c.creado)) AS anio
    FROM cursos c
    ORDER BY anio DESC, c.nombre_cursos
")->fetchAll(PDO::FETCH_ASSOC);

$idcurso = (int) ($_GET['id'] ?? 0);
if ($idcurso <= 0 && $listaCursos) {
    $idcurso = (int) $listaCursos[0]['id'];
}

$cursoInfo = null;
if ($idcurso > 0) {
    $stmtCurso = $db->prepare("
        SELECT c.*, ca.descripcion_categorias_cursos
        FROM cursos c
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        WHERE c.id = ?
    ");
    $stmtCurso->execute([$idcurso]);
    $cursoInfo = $stmtCurso->fetch(PDO::FETCH_ASSOC);
}

// Contenido del curso (temario). Se carga antes que la tabla de participantes
// porque el selector de modulo (mas abajo) necesita esta lista para validar
// el "modulo_id" que venga por GET.
$modulos = [];
if ($idcurso > 0) {
    $stmtModulos = $db->prepare("SELECT * FROM curso_modulos WHERE curso_id = ? ORDER BY orden");
    $stmtModulos->execute([$idcurso]);
    $modulos = $stmtModulos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($modulos as &$modulo) {
        $stmtTemas = $db->prepare("SELECT tema FROM curso_modulo_temas WHERE curso_modulo_id = ? ORDER BY orden");
        $stmtTemas->execute([$modulo['id']]);
        $modulo['temas'] = $stmtTemas->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($modulo);
}

// Modulo seleccionado para cargar/editar notas por modulo (seguimiento de co-ensenanza).
// modulo_id = 0 (o ausente/invalido) conserva el comportamiento historico: notas de
// resumen del curso completo en control_cursos, sin tocar esa tabla.
$moduloId = (int) ($_GET['modulo_id'] ?? 0);
$moduloSeleccionado = null;
if ($moduloId > 0) {
    foreach ($modulos as $m) {
        if ((int) $m['id'] === $moduloId) {
            $moduloSeleccionado = $m;
            break;
        }
    }
    if ($moduloSeleccionado === null) {
        $moduloId = 0; // modulo inexistente o de otro curso: se ignora silenciosamente
    }
}

// Visibilidad de las columnas Pre/Pos-Evaluacion para esta vista (modulo puntual
// o "todo el curso"): si un modulo/curso no las requiere, esas columnas no se
// muestran. Cuando ninguna de las dos aplica, quien solo califica (sin permiso
// de gestion) tambien puede ver/editar Asistencia, para no quedarse sin ningun
// campo editable en esa fila.
$prePostVisibles = cengi_curso_pre_post_visibles($modulos, $moduloId);
$mostrarPre = $prePostVisibles['pre'];
$mostrarPost = $prePostVisibles['post'];
$mostrarAsistencia = $puedeGestionar || (!$mostrarPre && !$mostrarPost);

if ($moduloId > 0) {
    $sql = "
        SELECT
            a.id AS asignacion_id,
            a.estado_asignaciones,
            p.nombre_participantes,
            p.cui_participantes,
            p.correo_participantes,
            p.grado_academico_participantes,
            p.telefono_participantes,
            i.nombre_ingenios,
            ccm.asistencia,
            ccm.evaluacion,
            ccm.posevaluacion,
            NULL AS diploma
        FROM asignaciones a
        INNER JOIN participantes p ON a.participantes_id = p.id
        INNER JOIN ingenios i ON p.ingenio_id = i.id
        LEFT JOIN control_curso_modulos ccm ON ccm.asignacion_id = a.id AND ccm.curso_modulo_id = ?
        WHERE a.cursos_id = ?
    ";
} else {
    $sql = "
        SELECT
            a.id AS asignacion_id,
            a.estado_asignaciones,
            p.nombre_participantes,
            p.cui_participantes,
            p.correo_participantes,
            p.grado_academico_participantes,
            p.telefono_participantes,
            i.nombre_ingenios,
            cc.asistencia,
            cc.evaluacion,
            cc.posevaluacion,
            cc.diploma
        FROM asignaciones a
        INNER JOIN participantes p ON a.participantes_id = p.id
        INNER JOIN ingenios i ON p.ingenio_id = i.id
        LEFT JOIN control_cursos cc ON a.id = cc.asignacion_id
        WHERE a.cursos_id = ?
    ";
}

if ($soloCalifica) {
    $sql .= " AND a.estado_asignaciones = 1";
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sql .= " AND p.ingenio_id = " . (int) cengi_ingenio_id_actual();
}

$stmt = $db->prepare($sql);
$stmt->execute($moduloId > 0 ? [$moduloId, $idcurso] : [$idcurso]);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs del curso (o del modulo seleccionado, si aplica)
$totalInscritos = count($filas);
$evaluacionesValidas = array_filter($filas, static function ($f) { return is_numeric($f['posevaluacion']); });
$asistenciasValidas = array_filter($filas, static function ($f) { return is_numeric($f['asistencia']); });
$evalPromedio = $evaluacionesValidas ? array_sum(array_map(static function ($f) { return (float) $f['posevaluacion']; }, $evaluacionesValidas)) / count($evaluacionesValidas) : null;
$asistPromedio = $asistenciasValidas ? array_sum(array_map(static function ($f) { return (float) $f['asistencia']; }, $asistenciasValidas)) / count($asistenciasValidas) : null;
$aprobados = count(array_filter($filas, static function ($f) { return is_numeric($f['posevaluacion']) && (float) $f['posevaluacion'] >= 60; }));

// Segundo dropdown (solo super admin): instructor que impartio/califico el modulo
// seleccionado, para trazabilidad en control_curso_modulos.registrado_por_instructor_id.
// Si el modulo no tiene instructores propios en curso_modulo_instructores, cae al
// instructor principal del curso (cursos.instructor_id) -- mismo fallback que ya usa
// ver_cursos.php/curso_form_helpers.php.
$moduloInstructores = [];
$instructorModuloActual = null;
if ($moduloId > 0 && cengi_es_superadmin()) {
    $stmtModInstr = $db->prepare("
        SELECT ins.id, ins.nombre
        FROM curso_modulo_instructores cmi
        INNER JOIN instructores ins ON ins.id = cmi.instructor_id
        WHERE cmi.curso_modulo_id = ?
        ORDER BY ins.nombre
    ");
    $stmtModInstr->execute([$moduloId]);
    $moduloInstructores = $stmtModInstr->fetchAll(PDO::FETCH_ASSOC);

    if (!$moduloInstructores && !empty($cursoInfo['instructor_id'])) {
        $stmtInstrPrincipal = $db->prepare("SELECT id, nombre FROM instructores WHERE id = ?");
        $stmtInstrPrincipal->execute([(int) $cursoInfo['instructor_id']]);
        $instructorPrincipal = $stmtInstrPrincipal->fetch(PDO::FETCH_ASSOC);
        if ($instructorPrincipal) {
            $moduloInstructores = [$instructorPrincipal];
        }
    }

    // Preselecciona el dropdown con el instructor ya registrado para este modulo, si
    // alguna fila de control_curso_modulos ya lo tiene guardado (simplificacion: toma
    // el primero no nulo, asumiendo que un modulo lo imparte/califica un solo instructor
    // a la vez).
    $stmtInstrActual = $db->prepare("
        SELECT registrado_por_instructor_id
        FROM control_curso_modulos
        WHERE curso_modulo_id = ? AND registrado_por_instructor_id IS NOT NULL
        LIMIT 1
    ");
    $stmtInstrActual->execute([$moduloId]);
    $valorInstrActual = $stmtInstrActual->fetchColumn();
    $instructorModuloActual = $valorInstrActual !== false ? (int) $valorInstrActual : null;
}

function cengi_pc_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

$mensaje = trim((string) ($_GET['mensaje'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
?>

<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
<?php menu_render(); ?>

<div class="container">
    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success alert-dismissible cengi-flash" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            <?php echo $mensaje === 'calificacion' ? 'Las calificaciones se guardaron correctamente.' : 'La operación se completó correctamente.'; ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible cengi-flash" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            No fue posible completar la operación. Verifica los datos e inténtalo nuevamente.
        </div>
    <?php endif; ?>

    <div class="panel panel-success">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 class="panel-title"><?php echo cengi_pc_html($cursoInfo['nombre_cursos'] ?? 'Selecciona un curso'); ?></h3>
                <small><?php echo cengi_pc_html($cursoInfo['descripcion_categorias_cursos'] ?? ''); ?></small>
            </div>
            <form method="GET">
                <select name="id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($listaCursos as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $idcurso === (int) $c['id'] ? 'selected' : ''; ?>>
                            <?php echo cengi_pc_html($c['nombre_cursos'] . ' (' . $c['anio'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="cengi-kpi-grid">
        <div class="cengi-kpi"><div class="cengi-kpi-val"><?php echo $totalInscritos; ?></div><div class="cengi-kpi-label">Inscritos</div></div>
        <div class="cengi-kpi"><div class="cengi-kpi-val"><?php echo $aprobados; ?></div><div class="cengi-kpi-label">Aprobados (post. >= 60)</div></div>
        <div class="cengi-kpi"><div class="cengi-kpi-val"><?php echo $asistPromedio !== null ? number_format($asistPromedio, 0) . '%' : '—'; ?></div><div class="cengi-kpi-label">Asistencia promedio</div></div>
        <div class="cengi-kpi"><div class="cengi-kpi-val" style="color:var(--cengi-primary-deep);"><?php echo $evalPromedio !== null ? number_format($evalPromedio, 0) . ' pts' : '—'; ?></div><div class="cengi-kpi-label">Evaluacion final promedio</div></div>
    </div>

    <div class="panel panel-success">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 class="panel-title">Contenido del curso</h3>
                <small>Indice tematico por modulo (mismo temario para todas las ediciones de este registro)</small>
            </div>
        </div>
        <div class="panel-body">
            <?php if (!$modulos): ?>
                <div class="cengi-empty">Este curso todavia no tiene modulos/temario configurados.</div>
            <?php else: ?>
                <?php foreach ($modulos as $modulo): ?>
                    <div style="border:1px solid var(--cengi-border);border-radius:9px;padding:12px 14px;margin-bottom:10px;">
                        <div style="display:flex;justify-content:space-between;font-weight:700;font-size:12.5px;">
                            <span><?php echo cengi_pc_html($modulo['nombre']); ?></span>
                            <span class="text-muted"><?php echo number_format((float) $modulo['horas'], 1); ?> h · <?php echo count($modulo['temas']); ?> temas</span>
                        </div>
                        <?php if ($modulo['temas']): ?>
                            <ul style="margin:8px 0 0 18px;font-size:11.5px;color:var(--cengi-muted);">
                                <?php foreach ($modulo['temas'] as $tema): ?>
                                    <li><?php echo cengi_pc_html($tema); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($puedeGestionar): ?>
            <details style="margin-top:10px;">
                <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--cengi-primary-deep);">+ Agregar modulo al temario</summary>
                <form method="POST" class="cengi-form-grid" style="margin-top:10px;">
                    <input type="hidden" name="accion" value="agregar_modulo">
                    <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                    <div class="form-group">
                        <label class="control-label">Nombre del modulo</label>
                        <input type="text" name="nombre_modulo" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Horas</label>
                        <input type="number" step="0.5" min="0" name="horas_modulo" class="form-control">
                    </div>
                    <div class="form-group cengi-form-full">
                        <label class="control-label">Temas (uno por linea)</label>
                        <textarea name="temas" class="form-control" rows="4"></textarea>
                    </div>
                    <div class="form-group cengi-form-full">
                        <button type="submit" class="btn btn-success btn-sm">Guardar modulo</button>
                    </div>
                </form>
            </details>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel panel-success">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 class="panel-title">Participantes del curso</h3>
                <?php if ($modulos): ?>
                    <small>Elige "Todo el curso" para el resumen general, o un modulo especifico para cargar sus notas por separado.</small>
                <?php endif; ?>
            </div>
            <?php if ($modulos): ?>
                <form method="GET">
                    <input type="hidden" name="id" value="<?php echo (int) $idcurso; ?>">
                    <select name="modulo_id" class="form-control" onchange="this.form.submit()">
                        <option value="0" <?php echo $moduloId === 0 ? 'selected' : ''; ?>>Todo el curso (resumen general)</option>
                        <?php foreach ($modulos as $m): ?>
                            <option value="<?php echo (int) $m['id']; ?>" <?php echo $moduloId === (int) $m['id'] ? 'selected' : ''; ?>>
                                <?php echo cengi_pc_html($m['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>

        <div class="panel-body">
            <?php if ($soloCalifica): ?>
                <div class="cengi-empty" style="margin-bottom: 20px;">
                    En esta vista solo puedes registrar notas del curso.
                </div>
            <?php endif; ?>

            <?php if ($moduloId > 0): ?>
                <div style="margin-bottom: 20px; text-align: right;">
                    <a href="exportar_notas_modulo.php?curso_id=<?php echo (int) $idcurso; ?>&modulo_id=<?php echo (int) $moduloId; ?>" class="btn btn-default">
                        <span class="glyphicon glyphicon-download-alt"></span> Descargar listado de este modulo
                    </a>
                    <button type="button" class="btn btn-default" data-toggle="modal" data-target="#bulk-grades-modulo-modal">
                        <span class="glyphicon glyphicon-upload"></span> Cargar notas de este modulo
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($puedeGestionar): ?>
                <div style="margin-bottom: 20px; text-align: right;">
                    <a href="agregar_participantes1.php?curso_id=<?php echo $idcurso; ?>" class="btn btn-success">
                        Agregar participante al curso
                    </a>
                </div>
            <?php endif; ?>

            <form action="guardar_control.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="guardar_general">
            <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
            <input type="hidden" name="modulo_id" value="<?php echo (int) $moduloId; ?>">

            <?php if ($moduloId > 0 && cengi_es_superadmin() && $moduloInstructores): ?>
                <div class="form-group" style="max-width:340px;margin-bottom:15px;">
                    <label class="control-label">Instructor que impartio/califico "<?php echo cengi_pc_html($moduloSeleccionado['nombre'] ?? ''); ?>"</label>
                    <select name="instructor_modulo_id" class="form-control">
                        <option value="">-- Sin registrar --</option>
                        <?php foreach ($moduloInstructores as $ins): ?>
                            <option value="<?php echo (int) $ins['id']; ?>" <?php echo $instructorModuloActual === (int) $ins['id'] ? 'selected' : ''; ?>>
                                <?php echo cengi_pc_html($ins['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help-block">Se guarda como trazabilidad junto con las notas de este modulo.</p>
                </div>
            <?php endif; ?>

            <div class="cengi-table-wrap">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>CUI</th>
                        <th>Ingenio</th>
                        <?php if ($puedeGestionar): ?><th>Estado</th><?php endif; ?>
                        <?php if ($mostrarAsistencia): ?><th>Asistencia</th><?php endif; ?>
                        <?php if ($mostrarPre): ?><th>Pre-Evaluacion</th><?php endif; ?>
                        <?php if ($mostrarPost): ?><th>Pos-Evaluacion</th><?php endif; ?>
                        <?php if ($moduloId === 0): ?><th>Diploma</th><?php endif; ?>
                        <?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filas)) { ?>
                        <?php
                            $cengiColspan = 3
                                + ($puedeGestionar ? 1 : 0)
                                + ($mostrarAsistencia ? 1 : 0)
                                + ($mostrarPre ? 1 : 0)
                                + ($mostrarPost ? 1 : 0)
                                + ($moduloId === 0 ? 1 : 0)
                                + ($puedeGestionar ? 1 : 0);
                        ?>
                        <tr>
                            <td colspan="<?php echo $cengiColspan; ?>" class="text-center">
                                No hay participantes asignados a este curso todavia.
                            </td>
                        </tr>
                    <?php } ?>

                    <?php foreach ($filas as $fila) { ?>
                        <tr>
                                <td><strong><?= htmlspecialchars($fila['nombre_participantes']) ?></strong><br><small><?= htmlspecialchars(implode(' · ', array_filter([$fila['correo_participantes'], $fila['telefono_participantes'], $fila['grado_academico_participantes']]))) ?></small></td>
                                <td><?= htmlspecialchars($fila['cui_participantes']) ?></td>
                                <td><?= htmlspecialchars($fila['nombre_ingenios']) ?></td>
                                <?php if ($puedeGestionar): ?>
                                    <td>
                                        <?php if ((int) $fila['estado_asignaciones'] === 1) { ?>
                                            <span class="label label-success">Activo</span>
                                        <?php } else { ?>
                                            <span class="label label-default">Inactivo</span>
                                        <?php } ?>
                                    </td>
                                <?php endif; ?>

                                <?php if ($mostrarAsistencia): ?>
                                    <td>
                                        <input
                                            type="number"
                                            name="registros[<?= (int) $fila['asignacion_id'] ?>][asistencia]"
                                            class="form-control"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            value="<?= htmlspecialchars($fila['asistencia']) ?>"
                                        >
                                    </td>
                                <?php endif; ?>

                                <?php if ($mostrarPre): ?>
                                <td>
                                    <input
                                        type="number"
                                        name="registros[<?= (int) $fila['asignacion_id'] ?>][evaluacion]"
                                        class="form-control"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value="<?= htmlspecialchars($fila['evaluacion']) ?>"
                                    >
                                </td>
                                <?php endif; ?>

                                <?php if ($mostrarPost): ?>
                                <td>
                                    <input
                                        type="number"
                                        name="registros[<?= (int) $fila['asignacion_id'] ?>][posevaluacion]"
                                        class="form-control"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value="<?= htmlspecialchars($fila['posevaluacion']) ?>"
                                    >
                                </td>
                                <?php endif; ?>

                                <?php if ($moduloId === 0): ?>
                                <td>
                                    <?php if ($puedeSubirDiploma && empty($fila['diploma'])): ?>
                                        <input type="file" name="diplomas[<?= (int) $fila['asignacion_id'] ?>]" class="form-control" accept="application/pdf,.pdf">
                                        <br>
                                    <?php endif; ?>
                                    <?php if (!empty($fila['diploma'])) { ?>
                                        <a href="<?= htmlspecialchars($fila['diploma']) ?>" target="_blank" class="btn btn-info btn-sm">
                                            Ver PDF
                                        </a>
                                    <?php } elseif (!$puedeGestionar) { ?>
                                        <span class="text-muted">Sin diploma</span>
                                    <?php } ?>
                                </td>
                                <?php endif; ?>

                                <?php if ($puedeGestionar): ?>
                                <td>
                                    <div class="cengi-row-actions">
                                        <?php $estadoActivo = (int) $fila['estado_asignaciones'] === 1; ?>
                                        <a
                                            href="toggle_asignacion.php?id=<?= (int) $fila['asignacion_id'] ?>&curso_id=<?= $idcurso ?>&estado=<?= (int) $fila['estado_asignaciones'] ?>"
                                            class="cengi-action-btn <?php echo $estadoActivo ? 'is-toggle-on' : 'is-toggle-off'; ?>"
                                            data-tooltip="<?php echo $estadoActivo ? 'Desactivar del curso' : 'Reactivar en curso'; ?>"
                                            aria-label="<?php echo $estadoActivo ? 'Desactivar del curso' : 'Reactivar en curso'; ?>"
                                        >
                                            <span class="glyphicon <?php echo $estadoActivo ? 'glyphicon-eye-close' : 'glyphicon-eye-open'; ?>"></span>
                                            <span class="sr-only"><?php echo $estadoActivo ? 'Desactivar del curso' : 'Reactivar en curso'; ?></span>
                                        </a>
                                    </div>
                                </td>
                                <?php endif; ?>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            </div>
            <?php if (!empty($filas)): ?>
                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="btn btn-success">
                        <span class="glyphicon glyphicon-floppy-disk"></span>
                        Guardar todos los registros
                    </button>
                </div>
            <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<?php if ($moduloId > 0): ?>
<div class="modal fade cengi-participant-modal" id="bulk-grades-modulo-modal" tabindex="-1" role="dialog" aria-labelledby="bulk-grades-modulo-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form action="carga_calificaciones_modulo.php" method="post" enctype="multipart/form-data">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-upload"></span></span><div><h4 class="modal-title" id="bulk-grades-modulo-title">Cargar notas del modulo</h4><p>Actualiza los resultados de "<?php echo cengi_pc_html($moduloSeleccionado['nombre'] ?? ''); ?>" desde un archivo CSV.</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                <input type="hidden" name="modulo_id" value="<?php echo (int) $moduloId; ?>">
                <div class="cengi-upload-guide"><strong>Formato requerido</strong><span>CUI, ASISTENCIA, PRE_EVALUACION, POST_EVALUACION</span><small>Los valores deben estar entre 0 y 100. Usa el listado descargado como plantilla.</small></div>
                <label class="cengi-upload-dropzone" for="bulk-grades-modulo-file"><span class="glyphicon glyphicon-cloud-upload"></span><strong>Selecciona el archivo CSV</strong><small>Máximo 5 MB</small><input type="file" id="bulk-grades-modulo-file" name="archivo" accept=".csv,text/csv" required></label>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Procesar archivo</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="container">
    <div class="row">
        <div class="row" style="text-align: center;">
            <a href="ver_cursos.php" class="btn btn-success">Regresar</a>
        </div>
    </div>
</div>
</body>
</html>
