<?php
require_once "conexion.php";
require_once "menu.php";
require_once "curso_form_helpers.php";

cengi_require_calificador('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$soloCalifica = cengi_puede_calificar() && !$puedeGestionar;
$puedeSubirDiploma = cengi_puede_subir_diploma();
$puedeEliminar = cengi_puede_eliminar_participantes();

// Materiales del curso (links a carpetas compartidas externas): tanto quien gestiona
// el curso como quien solo califica (instructor) pueden agregar/editar/eliminar, ya
// que ambos ya pasaron el guard cengi_require_calificador() de arriba. No hay una
// audiencia de "solo lectura" distinta en esta pagina.
$puedeGestionarContenido = $puedeGestionar || cengi_puede_calificar();

/**
 * Valida que $url sea una URL http/https bien formada antes de guardarla o
 * imprimirla como <iframe src="..."> / <a href="...">. Devuelve la URL validada
 * (tal como la devuelve filter_var) o null si no pasa la validacion -- rechaza
 * explicitamente esquemas como "javascript:" o URLs mal formadas en vez de
 * guardarlas silenciosamente en otro formato.
 */
function cengi_validar_url_contenido($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }

    $urlFiltrada = filter_var($url, FILTER_VALIDATE_URL);
    if ($urlFiltrada === false) {
        return null;
    }

    $esquema = strtolower((string) parse_url($urlFiltrada, PHP_URL_SCHEME));
    if ($esquema !== 'http' && $esquema !== 'https') {
        return null;
    }

    return $urlFiltrada;
}

/**
 * Mejor esfuerzo para convertir el link guardado en una URL apta para <iframe>.
 * Muchos proveedores (SharePoint, Dropbox, a veces Drive) bloquean el embed via
 * X-Frame-Options/CSP y el iframe queda en blanco -- por eso el link "Abrir en una
 * pestaña nueva" siempre se muestra tambien, sin depender de que el iframe cargue.
 * Solo se reconoce especificamente el patron de carpeta de Google Drive, que tiene
 * una URL de embed dedicada mucho mas confiable que la URL de carpeta normal.
 */
function cengi_contenido_embed_url($url)
{
    if (preg_match('#drive\.google\.com/drive/(?:u/\d+/)?folders/([a-zA-Z0-9_-]+)#i', $url, $coincidencia)) {
        return 'https://drive.google.com/embeddedfolderview?id=' . $coincidencia[1] . '#list';
    }

    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionarContenido && trim((string) ($_POST['accion'] ?? '')) === 'agregar_contenido') {
    $cursoIdContenido = (int) ($_POST['curso_id'] ?? 0);
    $tituloContenido = trim((string) ($_POST['titulo'] ?? ''));
    $urlContenido = cengi_validar_url_contenido($_POST['url'] ?? '');

    if ($cursoIdContenido > 0 && $tituloContenido !== '' && $urlContenido !== null) {
        $stmtOrden = $db->prepare("SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_contenidos WHERE curso_id = ?");
        $stmtOrden->execute([$cursoIdContenido]);
        $ordenContenido = (int) $stmtOrden->fetchColumn();

        $usuarioIdContenido = !empty($_SESSION['id_usuario']) ? (int) $_SESSION['id_usuario'] : null;
        $usuarioNombreContenido = trim((string) ($_SESSION['usuario'] ?? ''));

        $stmt = $db->prepare("INSERT INTO curso_contenidos (curso_id, titulo, url, orden, creado_por, creado_por_nombre) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cursoIdContenido, $tituloContenido, $urlContenido, $ordenContenido, $usuarioIdContenido, $usuarioNombreContenido]);

        header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&mensaje=contenido_agregado');
        exit;
    }

    header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&error=url_invalida');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionarContenido && trim((string) ($_POST['accion'] ?? '')) === 'editar_contenido') {
    $contenidoIdEditar = (int) ($_POST['contenido_id'] ?? 0);
    $cursoIdContenido = (int) ($_POST['curso_id'] ?? 0);
    $tituloContenido = trim((string) ($_POST['titulo'] ?? ''));
    $urlContenido = cengi_validar_url_contenido($_POST['url'] ?? '');

    if ($contenidoIdEditar > 0 && $cursoIdContenido > 0 && $tituloContenido !== '' && $urlContenido !== null) {
        $stmt = $db->prepare("UPDATE curso_contenidos SET titulo = ?, url = ? WHERE id = ? AND curso_id = ?");
        $stmt->execute([$tituloContenido, $urlContenido, $contenidoIdEditar, $cursoIdContenido]);

        header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&mensaje=contenido_actualizado');
        exit;
    }

    header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&error=url_invalida');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionarContenido && trim((string) ($_POST['accion'] ?? '')) === 'eliminar_contenido') {
    $contenidoIdEliminar = (int) ($_POST['contenido_id'] ?? 0);
    $cursoIdContenido = (int) ($_POST['curso_id'] ?? 0);

    if ($contenidoIdEliminar > 0 && $cursoIdContenido > 0) {
        $stmt = $db->prepare("DELETE FROM curso_contenidos WHERE id = ? AND curso_id = ?");
        $stmt->execute([$contenidoIdEliminar, $cursoIdContenido]);
    }

    header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&mensaje=contenido_eliminado');
    exit;
}

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

// Ingenios para el bloque "Participante nuevo" del modal de agregar participante
// (mismo listado que usa agregar_participantes1.php). Solo se necesita si el
// usuario puede gestionar (es quien ve el boton/modal).
$ingeniosParaModal = [];
if ($puedeGestionar) {
    $ingeniosParaModal = $db->query("
        SELECT id, nombre_ingenios
        FROM ingenios
        ORDER BY nombre_ingenios
    ")->fetchAll(PDO::FETCH_ASSOC);
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

// Materiales del curso (links a carpetas compartidas externas: Drive/OneDrive/etc.).
// A diferencia del temario, esto se guarda por esta edicion especifica del curso
// (curso_id) y no se replica manualmente entre "hermanos" del mismo nombre_cursos.
$contenidos = [];
if ($idcurso > 0) {
    $stmtContenidos = $db->prepare("SELECT * FROM curso_contenidos WHERE curso_id = ? ORDER BY orden, id");
    $stmtContenidos->execute([$idcurso]);
    $contenidos = $stmtContenidos->fetchAll(PDO::FETCH_ASSOC);
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
// La asistencia es acumulada del curso completo (Teams/Moodle): solo se registra en
// "Todo el curso". En un curso con modulos, Pre/Post del curso son calculados (promedio
// de los modulos ponderado por horas) y se muestran como solo lectura en esa vista.
$cursoConModulos = (bool) $modulos;
$vistaResumenModulos = $cursoConModulos && $moduloId === 0;
$mostrarAsistencia = $moduloId === 0 && ($puedeGestionar || (!$mostrarPre && !$mostrarPost));
if ($vistaResumenModulos) {
    $mostrarPre = false;
    $mostrarPost = false;
}

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
            ccm.sesiones_asistidas,
            ccm.evaluacion,
            ccm.posevaluacion,
            NULL AS diploma
        FROM asignaciones a
        INNER JOIN participantes p ON a.participantes_id = p.id
        INNER JOIN ingenios i ON p.ingenio_id = i.id
        LEFT JOIN control_curso_modulos ccm ON ccm.asignacion_id = a.id AND ccm.curso_modulo_id = ?
        WHERE a.cursos_id = ?
        AND a.estado_asignaciones = 1
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
            cc.sesiones_asistidas,
            cc.evaluacion,
            cc.posevaluacion,
            cc.diploma
        FROM asignaciones a
        INNER JOIN participantes p ON a.participantes_id = p.id
        INNER JOIN ingenios i ON p.ingenio_id = i.id
        LEFT JOIN control_cursos cc ON a.id = cc.asignacion_id
        WHERE a.cursos_id = ?
        AND a.estado_asignaciones = 1
    ";
}

if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sql .= " AND p.ingenio_id = " . (int) cengi_ingenio_id_actual();
}

$stmt = $db->prepare($sql);
$stmt->execute($moduloId > 0 ? [$moduloId, $idcurso] : [$idcurso]);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Notas de cada modulo por participante: [asignacion_id][curso_modulo_id] => fila.
// Alimentan la matriz de "Todo el curso" y el avance de calificacion de cada modulo.
$notasModulo = [];
if ($cursoConModulos) {
    $stmtNotasModulo = $db->prepare("
        SELECT ccm.asignacion_id, ccm.curso_modulo_id, ccm.evaluacion, ccm.posevaluacion
        FROM control_curso_modulos ccm
        INNER JOIN curso_modulos cm ON cm.id = ccm.curso_modulo_id
        WHERE cm.curso_id = ?
    ");
    $stmtNotasModulo->execute([$idcurso]);
    foreach ($stmtNotasModulo->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $notasModulo[(int) $n['asignacion_id']][(int) $n['curso_modulo_id']] = $n;
    }
}

/** Un participante esta calificado en un modulo cuando tiene todas las notas que el modulo pide. */
function cengi_pc_modulo_calificado(array $modulo, $nota)
{
    if (empty($modulo['acepta_pre']) && empty($modulo['acepta_post'])) {
        return true;
    }
    return $nota !== null
        && (empty($modulo['acepta_pre']) || is_numeric($nota['evaluacion']))
        && (empty($modulo['acepta_post']) || is_numeric($nota['posevaluacion']));
}

$avanceModulos = [];
foreach ($modulos as $m) {
    $calificados = 0;
    foreach ($filas as $f) {
        if (cengi_pc_modulo_calificado($m, $notasModulo[(int) $f['asignacion_id']][(int) $m['id']] ?? null)) {
            $calificados++;
        }
    }
    $avanceModulos[(int) $m['id']] = ['calificados' => $calificados, 'total' => count($filas),
        'sinNotas' => empty($m['acepta_pre']) && empty($m['acepta_post'])];
}

// KPIs del curso (o del modulo seleccionado, si aplica)
$totalInscritos = count($filas);
$evaluacionesValidas = array_filter($filas, static function ($f) { return is_numeric($f['posevaluacion']); });
$asistenciasValidas = array_filter($filas, static function ($f) { return is_numeric($f['asistencia']); });
$evalPromedio = $evaluacionesValidas ? array_sum(array_map(static function ($f) { return (float) $f['posevaluacion']; }, $evaluacionesValidas)) / count($evaluacionesValidas) : null;
$asistPromedio = $asistenciasValidas ? array_sum(array_map(static function ($f) { return (float) $f['asistencia']; }, $asistenciasValidas)) / count($asistenciasValidas) : null;
$aprobados = count(array_filter($filas, static function ($f) { return is_numeric($f['posevaluacion']) && (float) $f['posevaluacion'] >= CENGI_NOTA_APROBACION; }));

function cengi_pc_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

// Igual que cengi_pc_html(), pero para el href de un archivo subido (diploma): normaliza
// el path legado (cengi_normalizar_url_archivo() en conexion.php) antes de escaparlo, en
// vez de imprimir el string de BD tal cual.
function cengi_pc_href($valor)
{
    return htmlspecialchars(cengi_normalizar_url_archivo($valor), ENT_QUOTES, 'UTF-8');
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
            <?php
            $cengiMensajesSeguimiento = [
                'calificacion' => 'Las calificaciones se guardaron correctamente.',
                'removido' => 'El participante fue removido del curso correctamente.',
                'contenido_agregado' => 'El material se agregó correctamente.',
                'contenido_actualizado' => 'El material se actualizó correctamente.',
                'contenido_eliminado' => 'El material se eliminó correctamente.',
                'diploma_eliminado' => 'El diploma se eliminó correctamente.',
            ];
            echo $cengiMensajesSeguimiento[$mensaje] ?? 'La operación se completó correctamente.';
            ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible cengi-flash" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            <?php
            $cengiErroresSeguimiento = [
                'url_invalida' => 'El link ingresado no es una URL http/https válida. Verifica el enlace e inténtalo nuevamente.',
                'eliminar_diploma' => 'No fue posible eliminar el diploma. Verifica que exista e inténtalo nuevamente.',
            ];
            echo $cengiErroresSeguimiento[$error] ?? 'No fue posible completar la operación. Verifica los datos e inténtalo nuevamente.';
            ?>
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
        <div class="cengi-kpi"><div class="cengi-kpi-val"><?php echo $aprobados; ?></div><div class="cengi-kpi-label">Aprobados (pos-evaluación ≥ <?php echo CENGI_NOTA_APROBACION; ?>)</div></div>
        <?php if ($moduloId > 0): $av = $avanceModulos[$moduloId]; ?>
            <div class="cengi-kpi"><div class="cengi-kpi-val"><?php echo $av['calificados'] . ' / ' . $av['total']; ?></div><div class="cengi-kpi-label">Calificados en este módulo</div></div>
        <?php else: ?>
            <div class="cengi-kpi"><div class="cengi-kpi-val"><?php echo $asistPromedio !== null ? number_format($asistPromedio, 0) . '%' : '—'; ?></div><div class="cengi-kpi-label">Asistencia promedio (curso)</div></div>
        <?php endif; ?>
        <div class="cengi-kpi"><div class="cengi-kpi-val" style="color:var(--cengi-primary-deep);"><?php echo $evalPromedio !== null ? number_format($evalPromedio, 0) . ' pts' : '—'; ?></div><div class="cengi-kpi-label"><?php echo $moduloId > 0 ? 'Post-evaluación promedio del módulo' : 'Evaluacion final promedio'; ?></div></div>
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
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 class="panel-title">Materiales del curso</h3>
                <small>Links a carpetas compartidas (Google Drive, OneDrive, etc.) con el material de este curso</small>
            </div>
        </div>
        <div class="panel-body">
            <?php if (!$contenidos): ?>
                <div class="cengi-empty">Este curso todavía no tiene materiales/enlaces compartidos.</div>
            <?php else: ?>
                <?php foreach ($contenidos as $contenido): ?>
                    <?php $embedUrlContenido = cengi_contenido_embed_url($contenido['url']); ?>
                    <div style="border:1px solid var(--cengi-border);border-radius:9px;padding:12px 14px;margin-bottom:10px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                            <div>
                                <div style="font-weight:700;font-size:12.5px;"><?php echo cengi_pc_html($contenido['titulo']); ?></div>
                                <a href="<?php echo cengi_pc_html($contenido['url']); ?>" target="_blank" rel="noopener" class="text-muted" style="font-size:11px;">
                                    <span class="glyphicon glyphicon-new-window"></span> Abrir carpeta en una pestaña nueva
                                </a>
                            </div>
                            <?php if ($puedeGestionarContenido): ?>
                            <div class="cengi-row-actions">
                                <button
                                    type="button"
                                    class="cengi-action-btn"
                                    data-toggle="modal"
                                    data-target="#edit-contenido-modal-<?php echo (int) $contenido['id']; ?>"
                                    data-tooltip="Editar material"
                                    aria-label="Editar material"
                                >
                                    <span class="glyphicon glyphicon-pencil"></span>
                                    <span class="sr-only">Editar material</span>
                                </button>
                                <button
                                    type="button"
                                    class="cengi-action-btn is-delete contenido-delete-trigger"
                                    data-toggle="modal"
                                    data-target="#confirm-contenido-delete"
                                    data-name="<?php echo cengi_pc_html($contenido['titulo']); ?>"
                                    data-contenido-id="<?php echo (int) $contenido['id']; ?>"
                                    data-curso-id="<?php echo (int) $idcurso; ?>"
                                    data-tooltip="Eliminar material"
                                    aria-label="Eliminar material"
                                >
                                    <span class="glyphicon glyphicon-trash"></span>
                                    <span class="sr-only">Eliminar material</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:8px;">
                            <iframe
                                src="<?php echo cengi_pc_html($embedUrlContenido); ?>"
                                style="width:100%;height:400px;border:1px solid var(--cengi-border);border-radius:6px;"
                                loading="lazy"
                                title="<?php echo cengi_pc_html($contenido['titulo']); ?>"
                            ></iframe>
                            <p class="text-muted" style="font-size:10.5px;margin-top:4px;">
                                Si el contenido de arriba no se muestra, es porque el proveedor no permite mostrarlo embebido dentro de otra pagina. Usa el link "Abrir carpeta en una pestaña nueva".
                            </p>
                        </div>
                    </div>

                    <?php if ($puedeGestionarContenido): ?>
                    <div class="modal fade" id="edit-contenido-modal-<?php echo (int) $contenido['id']; ?>" tabindex="-1" role="dialog" aria-labelledby="edit-contenido-title-<?php echo (int) $contenido['id']; ?>">
                        <div class="modal-dialog" role="document"><div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title" id="edit-contenido-title-<?php echo (int) $contenido['id']; ?>">Editar material</h4>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="accion" value="editar_contenido">
                                    <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                                    <input type="hidden" name="contenido_id" value="<?php echo (int) $contenido['id']; ?>">
                                    <div class="form-group">
                                        <label class="control-label">Título</label>
                                        <input type="text" name="titulo" class="form-control" value="<?php echo cengi_pc_html($contenido['titulo']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="control-label">Link de carpeta compartida</label>
                                        <input type="url" name="url" class="form-control" value="<?php echo cengi_pc_html($contenido['url']); ?>" required>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                                    <button type="submit" class="btn btn-success">Guardar cambios</button>
                                </div>
                            </form>
                        </div></div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($puedeGestionarContenido): ?>
            <details style="margin-top:10px;">
                <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--cengi-primary-deep);">+ Agregar material / enlace</summary>
                <form method="POST" class="cengi-form-grid" style="margin-top:10px;">
                    <input type="hidden" name="accion" value="agregar_contenido">
                    <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                    <div class="form-group">
                        <label class="control-label">Título</label>
                        <input type="text" name="titulo" class="form-control" required>
                    </div>
                    <div class="form-group cengi-form-full">
                        <label class="control-label">Link de carpeta compartida (Google Drive, OneDrive, etc.)</label>
                        <input type="url" name="url" class="form-control" placeholder="https://drive.google.com/drive/folders/..." required>
                    </div>
                    <div class="form-group cengi-form-full">
                        <button type="submit" class="btn btn-success btn-sm">Guardar material</button>
                    </div>
                </form>
            </details>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel panel-success cengi-roster">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 class="panel-title">Participantes del curso <span class="cengi-roster-count"><?php echo count($filas); ?></span></h3>
                <?php if ($modulos): ?>
                    <small>La asistencia es del curso completo; las notas se cargan en cada módulo y la nota final se calcula sola.</small>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($modulos): ?>
            <nav class="cengi-mod-tabs" aria-label="Vista de notas">
                <a href="ver_participante_curso.php?id=<?php echo (int) $idcurso; ?>" class="cengi-mod-tab<?php echo $moduloId === 0 ? ' is-active' : ''; ?>"<?php echo $moduloId === 0 ? ' aria-current="page"' : ''; ?>>
                    <span class="cengi-mod-tab-name"><span class="glyphicon glyphicon-th-list" aria-hidden="true"></span> Todo el curso</span>
                    <span class="cengi-mod-tab-meta">Asistencia, nota final y diploma</span>
                </a>
                <?php foreach ($modulos as $k => $m):
                    $av = $avanceModulos[(int) $m['id']];
                    $completo = $av['total'] > 0 && $av['calificados'] >= $av['total'];
                    $activo = $moduloId === (int) $m['id'];
                ?>
                    <a href="ver_participante_curso.php?id=<?php echo (int) $idcurso; ?>&amp;modulo_id=<?php echo (int) $m['id']; ?>" class="cengi-mod-tab<?php echo $activo ? ' is-active' : ''; ?><?php echo $completo ? ' is-complete' : ''; ?>"<?php echo $activo ? ' aria-current="page"' : ''; ?>>
                        <span class="cengi-mod-tab-name"><span class="cengi-mod-tab-num"><?php echo $k + 1; ?></span> <?php echo cengi_pc_html($m['nombre']); ?></span>
                        <?php if ($av['sinNotas']): ?>
                            <span class="cengi-mod-tab-meta">Sin evaluación</span>
                        <?php else: ?>
                            <span class="cengi-mod-tab-meta"><?php echo $completo ? '<span class="glyphicon glyphicon-ok" aria-hidden="true"></span> ' : ''; ?><?php echo $av['calificados'] . ' de ' . $av['total']; ?> calificados</span>
                            <span class="cengi-mod-tab-bar"><span style="width:<?php echo $av['total'] ? round($av['calificados'] / $av['total'] * 100) : 0; ?>%;"></span></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="panel-body">
            <?php if ($moduloSeleccionado): ?>
                <div class="cengi-mod-info">
                    <span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span>
                    <div>
                        <strong><?php echo cengi_pc_html($moduloSeleccionado['nombre']); ?></strong>
                        <?php echo (float) $moduloSeleccionado['horas'] > 0 ? '· ' . rtrim(rtrim(number_format((float) $moduloSeleccionado['horas'], 1), '0'), '.') . ' h' : ''; ?>
                        · <?php echo cengi_pc_html(implode(' y ', array_filter([!empty($moduloSeleccionado['acepta_pre']) ? 'pre-evaluación' : null, !empty($moduloSeleccionado['acepta_post']) ? 'post-evaluación' : null])) ?: 'sin evaluación'); ?>.
                        Se aprueba con <b><?php echo CENGI_NOTA_APROBACION; ?> puntos o más</b> en la pos-evaluación. Al guardar, la nota final del curso se recalcula con el promedio de los módulos ponderado por horas.
                    </div>
                </div>
            <?php elseif ($vistaResumenModulos): ?>
                <div class="cengi-mod-info">
                    <span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span>
                    <div>Registra aquí la <strong>asistencia acumulada</strong> del curso (Teams/Moodle) y los diplomas. Las notas por módulo se ven como referencia: para cambiarlas abre la pestaña del módulo.</div>
                </div>
            <?php elseif ($soloCalifica): ?>
                <div class="cengi-empty" style="margin-bottom: 20px;">
                    En esta vista solo puedes registrar notas del curso.
                </div>
            <?php endif; ?>

            <?php
                // Numero de columnas de la tabla (se usa tanto para la fila "sin
                // participantes" como para la fila "sin coincidencias" del filtro).
                $cengiColspan = 3
                    + ($mostrarAsistencia ? 2 : 0)
                    + ($mostrarPre ? 1 : 0)
                    + ($mostrarPost ? 1 : 0)
                    + ($vistaResumenModulos ? count($modulos) + 1 : 0)
                    + ($moduloId === 0 ? 1 : 0)
                    + ($puedeGestionar ? 1 : 0);
                $moduloSinNotas = $moduloSeleccionado && !$mostrarPre && !$mostrarPost;
            ?>

            <div class="cengi-roster-toolbar">
                <?php if (!empty($filas)): ?>
                    <div class="cengi-roster-search">
                        <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                        <input
                            type="text"
                            id="cengi-participante-buscar"
                            class="form-control"
                            placeholder="Buscar por nombre, CUI o ingenio..."
                            autocomplete="off"
                            aria-label="Buscar participantes en la tabla"
                        >
                    </div>
                    <?php if ($moduloSeleccionado && !$moduloSinNotas): ?>
                        <label class="cengi-roster-toggle">
                            <input type="checkbox" id="cengi-solo-pendientes">
                            <span>Solo pendientes (<?php echo $avanceModulos[$moduloId]['total'] - $avanceModulos[$moduloId]['calificados']; ?>)</span>
                        </label>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="cengi-roster-actions">
                    <?php if ($moduloId > 0 && !$moduloSinNotas): ?>
                        <a href="exportar_notas_modulo.php?curso_id=<?php echo (int) $idcurso; ?>&modulo_id=<?php echo (int) $moduloId; ?>&format=excel" class="btn btn-default btn-sm" download>
                            <span class="glyphicon glyphicon-download-alt"></span> Excel
                        </a>
                        <a href="exportar_notas_modulo.php?curso_id=<?php echo (int) $idcurso; ?>&modulo_id=<?php echo (int) $moduloId; ?>&format=pdf" class="btn btn-default btn-sm" download>
                            <span class="glyphicon glyphicon-file"></span> PDF
                        </a>
                        <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#bulk-grades-modulo-modal">
                            <span class="glyphicon glyphicon-upload"></span> Cargar notas del modulo
                        </button>
                    <?php endif; ?>
                    <?php if ($puedeGestionar): ?>
                        <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#add-participant-modal">
                            <span class="glyphicon glyphicon-plus"></span> Agregar participante
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <form action="guardar_control.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="guardar_general">
            <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
            <input type="hidden" name="modulo_id" value="<?php echo (int) $moduloId; ?>">

            <div class="cengi-table-wrap">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>CUI</th>
                        <th>Ingenio</th>
                        <?php if ($mostrarAsistencia): ?><th>Asistencia</th><th>Sesiones asistidas</th><?php endif; ?>
                        <?php if ($mostrarPre): ?><th>Pre-Evaluacion</th><?php endif; ?>
                        <?php if ($mostrarPost): ?><th>Pos-Evaluacion</th><?php endif; ?>
                        <?php if ($vistaResumenModulos): ?>
                            <?php foreach ($modulos as $k => $m): ?>
                                <th class="cengi-mod-col" title="<?php echo cengi_pc_html($m['nombre']); ?>">M<?php echo $k + 1; ?><small><?php echo cengi_pc_html(mb_strimwidth($m['nombre'], 0, 18, '…', 'UTF-8')); ?></small></th>
                            <?php endforeach; ?>
                            <th>Nota final</th>
                        <?php endif; ?>
                        <?php if ($moduloId === 0): ?><th>Diploma</th><?php endif; ?>
                        <?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filas)) { ?>
                        <tr>
                            <td colspan="<?php echo $cengiColspan; ?>" class="text-center">
                                No hay participantes asignados a este curso todavia.
                            </td>
                        </tr>
                    <?php } ?>

                    <?php foreach ($filas as $fila) { ?>
                        <tr<?php echo $moduloSeleccionado && !cengi_pc_modulo_calificado($moduloSeleccionado, $notasModulo[(int) $fila['asignacion_id']][$moduloId] ?? null) ? ' data-pendiente="1"' : ''; ?>>
                                <?php
                                    $cengiIniciales = '';
                                    foreach (preg_split('/\s+/', trim((string) $fila['nombre_participantes']), -1, PREG_SPLIT_NO_EMPTY) as $cengiParte) {
                                        $cengiIniciales .= mb_strtoupper(mb_substr($cengiParte, 0, 1, 'UTF-8'), 'UTF-8');
                                        if (mb_strlen($cengiIniciales, 'UTF-8') >= 2) {
                                            break;
                                        }
                                    }
                                    $cengiAsig = (int) $fila['asignacion_id'];
                                ?>
                                <td>
                                    <div class="cengi-roster-person" data-ini="<?= htmlspecialchars($cengiIniciales ?: '?') ?>">
                                        <div>
                                            <strong><?= htmlspecialchars($fila['nombre_participantes']) ?></strong>
                                            <small><?= htmlspecialchars(implode(' · ', array_filter([$fila['correo_participantes'], $fila['telefono_participantes'], $fila['grado_academico_participantes']]))) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="cengi-roster-mono"><?= htmlspecialchars($fila['cui_participantes'] ?: '—') ?></span></td>
                                <td><span class="cengi-roster-chip"><?= htmlspecialchars($fila['nombre_ingenios']) ?></span></td>

                                <?php if ($mostrarAsistencia): ?>
                                    <td>
                                        <div class="cengi-roster-field has-suffix">
                                            <input type="number" name="registros[<?= $cengiAsig ?>][asistencia]" class="form-control" min="0" max="100" step="0.01" placeholder="0" aria-label="Asistencia (%)" value="<?= cengi_pc_html($fila['asistencia']) ?>">
                                            <span>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cengi-roster-field">
                                            <input type="number" name="registros[<?= $cengiAsig ?>][sesiones_asistidas]" class="form-control" min="0" step="1" placeholder="0" aria-label="Sesiones asistidas" value="<?= cengi_pc_html($fila['sesiones_asistidas']) ?>">
                                        </div>
                                    </td>
                                <?php endif; ?>

                                <?php if ($mostrarPre): ?>
                                <td>
                                    <div class="cengi-roster-field">
                                        <input type="number" name="registros[<?= $cengiAsig ?>][evaluacion]" class="form-control" min="0" max="100" step="0.01" placeholder="0–100" aria-label="Pre-evaluación" value="<?= cengi_pc_html($fila['evaluacion']) ?>">
                                    </div>
                                </td>
                                <?php endif; ?>

                                <?php if ($mostrarPost): ?>
                                <td>
                                    <div class="cengi-roster-post">
                                        <div class="cengi-roster-field">
                                            <input type="number" name="registros[<?= $cengiAsig ?>][posevaluacion]" class="form-control" min="0" max="100" step="0.01" placeholder="0–100" aria-label="Pos-evaluación" value="<?= cengi_pc_html($fila['posevaluacion']) ?>" data-posevaluacion>
                                        </div>
                                        <?php $cengiPost = is_numeric($fila['posevaluacion']) ? (float) $fila['posevaluacion'] : null; ?>
                                        <span class="cengi-roster-estado<?= $cengiPost === null ? '' : ($cengiPost >= CENGI_NOTA_APROBACION ? ' is-ok' : ' is-low') ?>" data-estado-post><?= $cengiPost === null ? 'Sin nota' : ($cengiPost >= CENGI_NOTA_APROBACION ? 'Aprobado' : 'No aprobado') ?></span>
                                    </div>
                                </td>
                                <?php endif; ?>

                                <?php if ($vistaResumenModulos): ?>
                                    <?php
                                    $cengiFaltan = 0;
                                    foreach ($modulos as $m):
                                        $nota = $notasModulo[$cengiAsig][(int) $m['id']] ?? null;
                                        $sinEval = empty($m['acepta_pre']) && empty($m['acepta_post']);
                                        $listo = cengi_pc_modulo_calificado($m, $nota);
                                        $cengiFaltan += $listo ? 0 : 1;
                                        $principal = !empty($m['acepta_post']) ? ($nota['posevaluacion'] ?? null) : ($nota['evaluacion'] ?? null);
                                    ?>
                                        <td class="cengi-mod-cell">
                                            <?php if ($sinEval): ?>
                                                <span class="cengi-roster-empty">—</span>
                                            <?php else: ?>
                                                <a href="ver_participante_curso.php?id=<?= (int) $idcurso ?>&amp;modulo_id=<?= (int) $m['id'] ?>" class="cengi-mod-nota<?= !$listo ? ' is-pending' : ((float) $principal < CENGI_NOTA_APROBACION ? ' is-low' : '') ?>" title="<?= cengi_pc_html($m['nombre']) ?>">
                                                    <?php if (is_numeric($principal)): ?>
                                                        <b><?= cengi_pc_html(rtrim(rtrim(number_format((float) $principal, 1), '0'), '.')) ?></b>
                                                        <?php if (!empty($m['acepta_pre']) && !empty($m['acepta_post']) && is_numeric($nota['evaluacion'] ?? null)): ?>
                                                            <small>pre <?= cengi_pc_html(rtrim(rtrim(number_format((float) $nota['evaluacion'], 1), '0'), '.')) ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <small>Pendiente</small>
                                                    <?php endif; ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <?php if (is_numeric($fila['posevaluacion'])): $final = (float) $fila['posevaluacion']; ?>
                                            <span class="cengi-mod-final<?= $final >= CENGI_NOTA_APROBACION ? ' is-ok' : ' is-low' ?>"><?= cengi_pc_html(rtrim(rtrim(number_format($final, 1), '0'), '.')) ?></span>
                                            <small class="cengi-mod-final-hint"><?= $cengiFaltan ? 'parcial · faltan ' . $cengiFaltan : ($final >= CENGI_NOTA_APROBACION ? 'aprobado' : 'no aprobado') ?></small>
                                        <?php else: ?>
                                            <span class="cengi-roster-empty">Sin notas</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>

                                <?php if ($moduloId === 0): ?>
                                <td>
                                    <?php if ($puedeSubirDiploma && empty($fila['diploma'])): ?>
                                        <label class="cengi-roster-upload" title="Adjuntar diploma en PDF">
                                            <input type="file" name="diplomas[<?= $cengiAsig ?>]" accept="application/pdf,.pdf">
                                            <span class="glyphicon glyphicon-paperclip" aria-hidden="true"></span>
                                            <span class="cengi-roster-upload-text">Adjuntar PDF</span>
                                        </label>
                                    <?php endif; ?>
                                    <?php if (!empty($fila['diploma'])) { ?>
                                        <div class="cengi-roster-diploma">
                                        <a href="descargar_diploma.php?asignacion_id=<?= $cengiAsig ?>" target="_blank" class="cengi-roster-pill">
                                            <span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver diploma
                                        </a>
                                        <?php if ($puedeSubirDiploma): ?>
                                            <button
                                                type="button"
                                                class="cengi-action-btn is-delete diploma-delete-trigger"
                                                data-toggle="modal"
                                                data-target="#confirm-diploma-delete"
                                                data-name="<?= htmlspecialchars($fila['nombre_participantes']) ?>"
                                                data-href="eliminar_diploma_participante.php?asignacion_id=<?= (int) $fila['asignacion_id'] ?>&amp;curso_id=<?= $idcurso ?>"
                                                data-tooltip="Eliminar diploma"
                                                aria-label="Eliminar diploma"
                                            >
                                                <span class="glyphicon glyphicon-trash"></span>
                                                <span class="sr-only">Eliminar diploma</span>
                                            </button>
                                        <?php endif; ?>
                                        </div>
                                    <?php } elseif (!$puedeSubirDiploma) { ?>
                                        <span class="cengi-roster-empty">Sin diploma</span>
                                    <?php } ?>
                                </td>
                                <?php endif; ?>

                                <?php if ($puedeGestionar): ?>
                                <td class="cengi-roster-col-actions">
                                    <div class="cengi-row-actions">
                                        <?php if ($puedeEliminar): ?>
                                            <button
                                                type="button"
                                                class="cengi-action-btn is-delete participant-delete-trigger"
                                                data-toggle="modal"
                                                data-target="#confirm-participant-delete"
                                                data-name="<?= htmlspecialchars($fila['nombre_participantes']) ?>"
                                                data-href="remover_participante_curso.php?asignacion_id=<?= (int) $fila['asignacion_id'] ?>&amp;curso_id=<?= $idcurso ?>&amp;return_to=seguimiento"
                                                data-tooltip="Desasignar del curso"
                                                aria-label="Desasignar del curso"
                                            >
                                                <span class="glyphicon glyphicon-remove"></span>
                                                <span class="sr-only">Desasignar del curso</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endif; ?>
                        </tr>
                    <?php } ?>

                    <?php if (!empty($filas)): ?>
                        <tr id="cengi-participante-sin-coincidencias" style="display:none;">
                            <td colspan="<?php echo $cengiColspan; ?>" class="text-center text-muted">
                                No hay participantes que coincidan con el filtro.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php if (!empty($filas) && !$moduloSinNotas): ?>
                <div class="cengi-roster-footer">
                    <span class="cengi-roster-footer-hint" id="cengi-roster-estado">Edita los valores de la tabla y guarda una sola vez para todos los participantes.<?php echo $mostrarPost || $vistaResumenModulos ? ' Aprobado: ' . CENGI_NOTA_APROBACION . ' puntos o más en la pos-evaluación.' : ''; ?></span>
                    <button type="submit" class="btn btn-success">
                        <span class="glyphicon glyphicon-floppy-disk"></span>
                        Guardar cambios
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
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-upload"></span></span><div><h4 class="modal-title" id="bulk-grades-modulo-title">Cargar notas del modulo</h4><p>Actualiza los resultados de "<?php echo cengi_pc_html($moduloSeleccionado['nombre'] ?? ''); ?>" desde el archivo Excel (.xlsx) descargado.</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                <input type="hidden" name="modulo_id" value="<?php echo (int) $moduloId; ?>">
                <div class="cengi-upload-guide"><strong>Formato requerido</strong><span>CUI<?php echo $mostrarPre ? ', PRE_EVALUACION' : ''; ?><?php echo $mostrarPost ? ', POST_EVALUACION' : ''; ?></span><small>Los valores deben estar entre 0 y 100. La plantilla ya trae a los participantes del módulo con sus notas actuales: llénala y súbela tal cual (Excel .xlsx, también se acepta CSV).<?php if (!$mostrarPre || !$mostrarPost): ?> Las columnas de evaluación que este módulo no utiliza pueden dejarse vacías o quitarse.<?php endif; ?></small>
                    <a class="btn btn-default btn-sm cengi-upload-template" href="exportar_notas_modulo.php?curso_id=<?php echo (int) $idcurso; ?>&amp;modulo_id=<?php echo (int) $moduloId; ?>&amp;format=excel" download><span class="glyphicon glyphicon-download-alt"></span> Descargar plantilla del módulo (Excel)</a></div>
                <label class="cengi-upload-dropzone" for="bulk-grades-modulo-file"><span class="glyphicon glyphicon-cloud-upload"></span><strong>Selecciona el archivo Excel (.xlsx)</strong><small>Máximo 5 MB</small><input type="file" id="bulk-grades-modulo-file" name="archivo" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required></label>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Procesar archivo</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if ($puedeGestionar): ?>
<div class="modal fade cengi-participant-modal" id="add-participant-modal" tabindex="-1" role="dialog" aria-labelledby="add-participant-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <span class="cengi-modal-icon"><span class="glyphicon glyphicon-user"></span></span>
            <div>
                <h4 class="modal-title" id="add-participant-title">Agregar participante al curso</h4>
                <p>Asigna a alguien que ya esta registrado o crea un participante nuevo para "<?php echo cengi_pc_html($cursoInfo['nombre_cursos'] ?? ''); ?>".</p>
            </div>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="control-label" style="display:block;">¿Deseas asignar un participante existente o agregar uno nuevo?</label>
                <label class="radio-inline"><input type="radio" name="tipo_participante" value="existente" id="cengi-tipo-existente" checked> Participante existente</label>
                <label class="radio-inline"><input type="radio" name="tipo_participante" value="nuevo" id="cengi-tipo-nuevo"> Participante nuevo</label>
            </div>

            <div id="cengi-bloque-existente">
                <form id="cengi-form-existente" method="POST" action="asignar_participante_existente.php">
                    <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                    <input type="hidden" name="participante_id" id="cengi-participante-id-input" value="">
                    <div class="form-group">
                        <label for="cengi-buscar-participante" class="control-label">Buscar por nombre o CUI</label>
                        <div class="cengi-combo">
                            <div class="input-group">
                                <input type="text" id="cengi-buscar-participante" class="form-control" placeholder="Escribe para buscar o abre la lista..." autocomplete="off">
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default" id="cengi-buscar-toggle" tabindex="-1" aria-label="Mostrar lista de participantes">
                                        <span class="caret"></span>
                                    </button>
                                </span>
                            </div>
                            <div id="cengi-buscar-resultados" class="cengi-combo-menu list-group"></div>
                        </div>
                    </div>
                    <div id="cengi-participante-seleccionado" class="alert alert-info" style="display:none;margin-top:10px;">
                        Participante seleccionado: <strong id="cengi-participante-seleccionado-nombre"></strong>
                        <button type="button" class="close" id="cengi-participante-seleccionado-limpiar" aria-label="Quitar selección"><span aria-hidden="true">&times;</span></button>
                    </div>
                </form>
            </div>

            <div id="cengi-bloque-nuevo" style="display:none;">
                <form id="cengi-form-nuevo" method="POST" action="guardar_participante_curso.php" autocomplete="off">
                    <input type="hidden" name="curso" value="<?php echo (int) $idcurso; ?>">
                    <div class="cengi-participant-modal-grid">
                        <div class="form-group">
                            <label for="cengi-nuevo-cui" class="control-label">CUI</label>
                            <input type="text" class="form-control" id="cengi-nuevo-cui" name="cui" placeholder="0000-00000-0000" required>
                        </div>
                        <div class="form-group">
                            <label for="cengi-nuevo-nombre" class="control-label">Nombre</label>
                            <input type="text" class="form-control" id="cengi-nuevo-nombre" name="nombre" placeholder="Nombre del participante" required>
                        </div>
                        <div class="form-group">
                            <label for="cengi-nuevo-ingenio" class="control-label">Ingenio</label>
                            <select class="form-control" id="cengi-nuevo-ingenio" name="ingenio" required>
                                <option value="">Selecciona un ingenio</option>
                                <?php foreach ($ingeniosParaModal as $ingenioModal): ?>
                                    <option value="<?php echo (int) $ingenioModal['id']; ?>"><?php echo cengi_pc_html($ingenioModal['nombre_ingenios']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="cengi-nuevo-area" class="control-label">Area</label>
                            <input type="text" class="form-control" id="cengi-nuevo-area" name="area" placeholder="Area" required>
                        </div>
                        <div class="form-group">
                            <label for="cengi-nuevo-puesto" class="control-label">Puesto</label>
                            <input type="text" class="form-control" id="cengi-nuevo-puesto" name="puesto" placeholder="Puesto" required>
                        </div>
                        <div class="form-group">
                            <label for="cengi-nuevo-correo" class="control-label">Correo electrónico</label>
                            <input type="email" class="form-control" id="cengi-nuevo-correo" name="correo" maxlength="255" placeholder="persona@ejemplo.com">
                        </div>
                        <div class="form-group">
                            <label for="cengi-nuevo-grado" class="control-label">Grado académico</label>
                            <input type="text" class="form-control" id="cengi-nuevo-grado" name="grado_academico" maxlength="255" placeholder="Licenciatura, técnico, diversificado...">
                        </div>
                        <div class="form-group">
                            <label for="cengi-nuevo-telefono" class="control-label">Teléfono</label>
                            <input type="tel" class="form-control" id="cengi-nuevo-telefono" name="telefono" maxlength="50" placeholder="Número de teléfono">
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
            <button type="submit" form="cengi-form-existente" class="btn btn-success" id="cengi-btn-asignar-existente" disabled>Asignar al curso</button>
            <button type="submit" form="cengi-form-nuevo" class="btn btn-success" id="cengi-btn-guardar-nuevo" style="display:none;">Guardar y asignar</button>
        </div>
    </div></div>
</div>

<script>
(function ($) {
    'use strict';

    var $modal = $('#add-participant-modal');
    if (!$modal.length) {
        return;
    }

    var $radios = $('input[name="tipo_participante"]', $modal);
    var $bloqueExistente = $('#cengi-bloque-existente');
    var $bloqueNuevo = $('#cengi-bloque-nuevo');
    var $btnExistente = $('#cengi-btn-asignar-existente');
    var $btnNuevo = $('#cengi-btn-guardar-nuevo');
    var $buscarInput = $('#cengi-buscar-participante');
    var $buscarToggle = $('#cengi-buscar-toggle');
    var $resultados = $('#cengi-buscar-resultados');
    var $seleccionadoBox = $('#cengi-participante-seleccionado');
    var $seleccionadoNombre = $('#cengi-participante-seleccionado-nombre');
    var $participanteIdInput = $('#cengi-participante-id-input');
    var buscarTimeout = null;
    var buscarRequest = null;

    function abrirDropdown() {
        $resultados.addClass('open');
    }

    function cerrarDropdown() {
        $resultados.removeClass('open');
    }

    function buscarParticipantes(termino) {
        if (buscarRequest) {
            buscarRequest.abort();
        }
        $resultados.empty().append($('<div>', { class: 'list-group-item text-muted' }).text('Buscando...'));
        abrirDropdown();
        buscarRequest = $.ajax({
            url: 'buscar_participantes_ajax.php',
            data: { q: termino },
            dataType: 'json',
            cache: false
        }).done(function (participantes) {
            $resultados.empty();
            if (!participantes.length) {
                $resultados.append($('<div>', { class: 'list-group-item text-muted' }).text('No se encontraron participantes.'));
                return;
            }
            participantes.forEach(function (participante) {
                $('<button>', {
                    type: 'button',
                    class: 'list-group-item',
                    text: participante.nombre + ' — CUI ' + participante.cui + ' (' + participante.ingenio + ')'
                }).on('click', function () {
                    seleccionarParticipante(participante);
                }).appendTo($resultados);
            });
        }).fail(function (xhr, status) {
            if (status === 'abort') {
                return;
            }
            $resultados.empty().append($('<div>', { class: 'list-group-item text-danger' }).text('No fue posible buscar participantes.'));
        });
    }

    function mostrarBloque(tipo) {
        if (tipo === 'nuevo') {
            $bloqueExistente.hide();
            $bloqueNuevo.show();
            $btnExistente.hide();
            $btnNuevo.show();
        } else {
            $bloqueNuevo.hide();
            $bloqueExistente.show();
            $btnNuevo.hide();
            $btnExistente.show();
        }
    }

    $radios.on('change', function () {
        mostrarBloque($(this).val());
    });

    function limpiarSeleccion() {
        $participanteIdInput.val('');
        $seleccionadoBox.hide();
        $seleccionadoNombre.text('');
        $btnExistente.prop('disabled', true);
    }

    function seleccionarParticipante(participante) {
        $participanteIdInput.val(participante.id);
        $seleccionadoNombre.text(participante.nombre + ' (CUI ' + participante.cui + ' · ' + participante.ingenio + ')');
        $seleccionadoBox.show();
        $btnExistente.prop('disabled', false);
        cerrarDropdown();
        $buscarInput.val('');
    }

    $('#cengi-participante-seleccionado-limpiar').on('click', function () {
        limpiarSeleccion();
    });

    $buscarInput.on('input', function () {
        var termino = $.trim($(this).val());
        limpiarSeleccion();
        window.clearTimeout(buscarTimeout);
        buscarTimeout = window.setTimeout(function () {
            buscarParticipantes(termino);
        }, 300);
    });

    // Al enfocar (o abrir con el caret) sin haber escrito nada, se muestra
    // el listado completo (limitado) a modo de dropdown para elegir.
    $buscarInput.on('focus', function () {
        if ($resultados.hasClass('open')) {
            return;
        }
        buscarParticipantes($.trim($(this).val()));
    });

    $buscarToggle.on('click', function () {
        if ($resultados.hasClass('open')) {
            cerrarDropdown();
            return;
        }
        $buscarInput.focus();
        buscarParticipantes($.trim($buscarInput.val()));
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.cengi-combo').length) {
            cerrarDropdown();
        }
    });

    $modal.on('hidden.bs.modal', function () {
        $radios.filter('[value="existente"]').prop('checked', true);
        mostrarBloque('existente');
        limpiarSeleccion();
        cerrarDropdown();
        $resultados.empty();
        $buscarInput.val('');
        document.getElementById('cengi-form-nuevo').reset();
    });

    mostrarBloque('existente');
})(jQuery);
</script>
<?php endif; ?>

<div class="container">
    <div class="row">
        <div class="row" style="text-align: center;">
            <a href="ver_cursos.php" class="btn btn-success">Regresar</a>
        </div>
    </div>
</div>

<?php if ($puedeEliminar): ?>
<div class="modal fade cengi-participant-modal" id="confirm-participant-delete" tabindex="-1" role="dialog" aria-labelledby="participant-delete-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content cengi-confirm-content">
        <div class="modal-body"><span class="cengi-confirm-icon"><span class="glyphicon glyphicon-remove"></span></span><h4 id="participant-delete-title">Remover del curso</h4><p>¿Deseas remover a <strong id="participant-delete-name"></strong> de este curso? El participante seguirá disponible y sus demás asignaciones no serán modificadas.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><a class="btn btn-danger btn-ok" href="#">Sí, remover</a></div>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    $('#confirm-participant-delete').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        document.getElementById('participant-delete-name').textContent = trigger ? trigger.getAttribute('data-name') : '';
        $(this).find('.btn-ok').attr('href', trigger ? trigger.getAttribute('data-href') : '#');
    });
})();
</script>
<?php endif; ?>

<?php if ($puedeSubirDiploma): ?>
<div class="modal fade cengi-participant-modal" id="confirm-diploma-delete" tabindex="-1" role="dialog" aria-labelledby="diploma-delete-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content cengi-confirm-content">
        <div class="modal-body"><span class="cengi-confirm-icon"><span class="glyphicon glyphicon-trash"></span></span><h4 id="diploma-delete-title">Eliminar diploma</h4><p>¿Deseas eliminar el diploma de <strong id="diploma-delete-name"></strong>? Esta acción no se puede deshacer y el participante podrá volver a subir uno nuevo.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><a class="btn btn-danger btn-ok" href="#">Sí, eliminar</a></div>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    $('#confirm-diploma-delete').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        document.getElementById('diploma-delete-name').textContent = trigger ? trigger.getAttribute('data-name') : '';
        $(this).find('.btn-ok').attr('href', trigger ? trigger.getAttribute('data-href') : '#');
    });
})();
</script>
<?php endif; ?>

<?php if ($puedeGestionarContenido): ?>
<div class="modal fade cengi-participant-modal" id="confirm-contenido-delete" tabindex="-1" role="dialog" aria-labelledby="contenido-delete-title">
    <div class="modal-dialog cengi-modal-compact" role="document"><div class="modal-content cengi-confirm-content">
        <form method="POST" id="confirm-contenido-delete-form">
            <input type="hidden" name="accion" value="eliminar_contenido">
            <input type="hidden" name="curso_id" id="contenido-delete-curso-id" value="">
            <input type="hidden" name="contenido_id" id="contenido-delete-id" value="">
            <div class="modal-body"><span class="cengi-confirm-icon"><span class="glyphicon glyphicon-trash"></span></span><h4 id="contenido-delete-title">Eliminar material</h4><p>¿Deseas eliminar <strong id="contenido-delete-name"></strong>? Esta acción no se puede deshacer.</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Sí, eliminar</button></div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    $('#confirm-contenido-delete').on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        document.getElementById('contenido-delete-name').textContent = trigger ? trigger.getAttribute('data-name') : '';
        document.getElementById('contenido-delete-id').value = trigger ? trigger.getAttribute('data-contenido-id') : '';
        document.getElementById('contenido-delete-curso-id').value = trigger ? trigger.getAttribute('data-curso-id') : '';
    });
})();
</script>
<?php endif; ?>

<script>
// Tabla "Participantes del curso": muestra el nombre del PDF adjuntado y avisa que hay
// cambios sin guardar (resalta la fila editada y la barra de guardado).
(function ($) {
    'use strict';

    var $roster = $('.cengi-roster');
    var $estado = $('#cengi-roster-estado');
    var pendientes = 0;

    $roster.on('change', '.cengi-roster-upload input[type="file"]', function () {
        var $label = $(this).closest('.cengi-roster-upload');
        var nombre = this.files && this.files.length ? this.files[0].name : '';
        $label.toggleClass('has-file', nombre !== '');
        $label.find('.cengi-roster-upload-text').text(nombre || 'Adjuntar PDF');
        $label.attr('title', nombre || 'Adjuntar diploma en PDF');
    });

    // Estado de aprobacion junto a la pos-evaluacion, mientras se escribe.
    var NOTA_APROBACION = <?php echo CENGI_NOTA_APROBACION; ?>;
    $roster.on('input change', '[data-posevaluacion]', function () {
        var $estado = $(this).closest('.cengi-roster-post').find('[data-estado-post]');
        var v = this.value.trim() === '' ? NaN : parseFloat(this.value);
        var ok = !isNaN(v) && v >= NOTA_APROBACION;
        $estado.toggleClass('is-ok', !isNaN(v) && ok).toggleClass('is-low', !isNaN(v) && !ok)
            .text(isNaN(v) ? 'Sin nota' : (ok ? 'Aprobado' : 'No aprobado'));
    });

    $roster.on('input change', 'tbody input', function () {
        var $fila = $(this).closest('tr');
        if (!$fila.hasClass('is-dirty')) {
            $fila.addClass('is-dirty');
            pendientes++;
        }
        $roster.find('.cengi-roster-footer').addClass('is-dirty');
        $estado.text(pendientes === 1 ? '1 participante con cambios sin guardar.' : pendientes + ' participantes con cambios sin guardar.');
    });
})(jQuery);
</script>

<script>
// Filtro en el cliente de la tabla "Participantes del curso": muestra/oculta las
// filas del <tbody> segun el texto escrito, comparando contra nombre, CUI e
// ingenio (las primeras tres celdas). No hace AJAX ni recarga: la tabla ya viene
// renderizada. El input vive fuera del <form action="guardar_control.php"> para
// que Enter no dispare el guardado de registros.
(function ($) {
    'use strict';

    var $input = $('#cengi-participante-buscar');
    if (!$input.length) {
        return;
    }

    var $tabla = $input.closest('.panel-body').find('table.table').first();
    var $sinCoincidencias = $('#cengi-participante-sin-coincidencias');
    var $filas = $tabla.find('tbody > tr').not($sinCoincidencias);

    function normalizar(texto) {
        texto = (texto == null ? '' : texto).toString().toLowerCase();
        if (texto.normalize) {
            texto = texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return texto;
    }

    // Se indexa el texto buscable de cada fila una sola vez (nombre, CUI e
    // ingenio = primeras tres celdas) para no releer el DOM en cada pulsacion.
    $filas.each(function () {
        var partes = [];
        $(this).children('td').slice(0, 3).each(function () {
            partes.push($(this).text());
        });
        $(this).data('cengiBuscar', normalizar(partes.join(' ')));
    });

    // "Solo pendientes" (vista de modulo): se combina con el texto buscado.
    var $soloPendientes = $('#cengi-solo-pendientes');

    function filtrar() {
        var termino = normalizar($.trim($input.val()));
        var pendientes = $soloPendientes.is(':checked');
        var visibles = 0;
        $filas.each(function () {
            var coincide = (termino === '' || $(this).data('cengiBuscar').indexOf(termino) !== -1)
                && (!pendientes || $(this).attr('data-pendiente') === '1');
            $(this).toggle(coincide);
            if (coincide) {
                visibles++;
            }
        });
        $sinCoincidencias.toggle(visibles === 0 && (termino !== '' || pendientes));
    }

    $input.on('input', filtrar);
    $soloPendientes.on('change', filtrar);
})(jQuery);
</script>
</body>
</html>
