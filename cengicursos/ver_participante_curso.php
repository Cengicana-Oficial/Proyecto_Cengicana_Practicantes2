<?php
require_once "conexion.php";
require_once "menu.php";

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

if ($soloCalifica) {
    $sql .= " AND a.estado_asignaciones = 1";
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sql .= " AND p.ingenio_id = " . (int) cengi_ingenio_id_actual();
}

$stmt = $db->prepare($sql);
$stmt->execute([$idcurso]);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs del curso
$totalInscritos = count($filas);
$evaluacionesValidas = array_filter($filas, static function ($f) { return is_numeric($f['posevaluacion']); });
$asistenciasValidas = array_filter($filas, static function ($f) { return is_numeric($f['asistencia']); });
$evalPromedio = $evaluacionesValidas ? array_sum(array_map(static function ($f) { return (float) $f['posevaluacion']; }, $evaluacionesValidas)) / count($evaluacionesValidas) : null;
$asistPromedio = $asistenciasValidas ? array_sum(array_map(static function ($f) { return (float) $f['asistencia']; }, $asistenciasValidas)) / count($asistenciasValidas) : null;
$aprobados = count(array_filter($filas, static function ($f) { return is_numeric($f['posevaluacion']) && (float) $f['posevaluacion'] >= 60; }));

// Contenido del curso (temario)
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

function cengi_pc_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas">
<?php menu_render(); ?>

<div class="container">
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
        <div class="panel-heading">
            <h3 class="panel-title">Participantes del curso</h3>
        </div>

        <div class="panel-body">
            <?php if ($soloCalifica): ?>
                <div class="cengi-empty" style="margin-bottom: 20px;">
                    En esta vista solo puedes registrar notas del curso.
                </div>
            <?php endif; ?>

            <?php if ($puedeGestionar): ?>
                <div style="margin-bottom: 20px; text-align: right;">
                    <a href="agregar_participantes1.php?curso_id=<?php echo $idcurso; ?>" class="btn btn-success">
                        Agregar participante al curso
                    </a>
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
                        <?php if ($puedeGestionar): ?><th>Asistencia</th><?php endif; ?>
                        <th>Pre-Evaluacion</th>
                        <th>Pos-Evaluacion</th>
                        <th>Diploma</th>
                        <th>Guardar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filas)) { ?>
                        <tr>
                            <td colspan="<?php echo $puedeGestionar ? 9 : 7; ?>" class="text-center">
                                No hay participantes asignados a este curso todavia.
                            </td>
                        </tr>
                    <?php } ?>

                    <?php foreach ($filas as $fila) { ?>
                        <tr>
                            <form action="guardar_control.php" method="POST" enctype="multipart/form-data">
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

                                <?php if ($puedeGestionar): ?>
                                    <td>
                                        <input
                                            type="number"
                                            name="asistencia"
                                            class="form-control"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            value="<?= htmlspecialchars($fila['asistencia']) ?>"
                                        >
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <input
                                        type="number"
                                        name="evaluacion"
                                        class="form-control"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value="<?= htmlspecialchars($fila['evaluacion']) ?>"
                                    >
                                </td>

                                <td>
                                    <input
                                        type="number"
                                        name="posevaluacion"
                                        class="form-control"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value="<?= htmlspecialchars($fila['posevaluacion']) ?>"
                                    >
                                </td>

                                <td>
                                    <?php if ($puedeSubirDiploma): ?>
                                        <input type="file" name="diploma" class="form-control">
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

                                <td>
                                    <input type="hidden" name="asignacion_id" value="<?= (int) $fila['asignacion_id'] ?>">
                                    <div class="cengi-row-actions">
                                        <button type="submit" class="cengi-action-btn is-edit" style="border:0;" data-tooltip="Guardar" aria-label="Guardar"><span class="glyphicon glyphicon-floppy-disk"></span><span class="sr-only">Guardar</span></button>
                                        <?php if ($puedeGestionar): ?>
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
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </form>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <div class="row">
        <div class="row" style="text-align: center;">
            <a href="ver_cursos.php" class="btn btn-success">Regresar</a>
        </div>
    </div>
</div>
</body>
</html>
