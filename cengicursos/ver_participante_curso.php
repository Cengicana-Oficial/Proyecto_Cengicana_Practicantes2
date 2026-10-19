<?php
require_once "conexion.php";
require_once "menu.php";
require_once "curso_form_helpers.php";
require_once __DIR__ . '/aula_helpers.php';

cengi_require_calificador('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$soloCalifica = cengi_puede_calificar() && !$puedeGestionar;
$puedeSubirDiploma = cengi_puede_subir_diploma();
$puedeEliminar = cengi_puede_eliminar_participantes();
// Funciones que antes estaban en participantes.php (Participantes por curso), unificadas aquí.
$puedeCargar = cengi_puede_cargar_participantes();
$puedeEditar = cengi_puede_editar_participantes();
$puedeEnviarCorreo = $puedeGestionar || $puedeSubirDiploma;
$puedeVerDirectorio = cengi_puede_ver_participantes();

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

        header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&mensaje=contenido_agregado#materiales');
        exit;
    }

    header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&error=url_invalida#materiales');
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

        header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&mensaje=contenido_actualizado#materiales');
        exit;
    }

    header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&error=url_invalida#materiales');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionarContenido && trim((string) ($_POST['accion'] ?? '')) === 'eliminar_contenido') {
    $contenidoIdEliminar = (int) ($_POST['contenido_id'] ?? 0);
    $cursoIdContenido = (int) ($_POST['curso_id'] ?? 0);

    if ($contenidoIdEliminar > 0 && $cursoIdContenido > 0) {
        $stmt = $db->prepare("DELETE FROM curso_contenidos WHERE id = ? AND curso_id = ?");
        $stmt->execute([$contenidoIdEliminar, $cursoIdContenido]);
    }

    header('Location: ver_participante_curso.php?id=' . $cursoIdContenido . '&mensaje=contenido_eliminado#materiales');
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

    header('Location: ver_participante_curso.php?id=' . $cursoIdModulo . '#temario');
    exit;
}

// Recordatorio de la evaluación del instructor (meta: 40 % de los participantes por curso o módulo).
require_once __DIR__ . '/evaluacion_meta_helpers.php';
// Firmantes e imágenes de firma de la constancia de notas (constancia_notas_config.php).
$puedeConfigurarConstancia = cengi_puede_gestionar_diplomas();
if (empty($_SESSION['eval_meta_csrf'])) {
    $_SESSION['eval_meta_csrf'] = bin2hex(random_bytes(16));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar && trim((string) ($_POST['accion'] ?? '')) === 'recordar_evaluacion') {
    $cursoIdRecordar = (int) ($_POST['curso_id'] ?? 0);
    $volver = 'ver_participante_curso.php?id=' . $cursoIdRecordar;
    try {
        if (!hash_equals($_SESSION['eval_meta_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Recarga la página e inténtalo de nuevo.');
        }
        $stmtCursoRec = $db->prepare('SELECT id, nombre_cursos FROM cursos WHERE id = ?');
        $stmtCursoRec->execute([$cursoIdRecordar]);
        $cursoRec = $stmtCursoRec->fetch(PDO::FETCH_ASSOC);
        if (!$cursoRec) {
            throw new InvalidArgumentException('El curso no existe.');
        }
        [$enviados, $fallidos] = cengi_eval_meta_recordar($db, $cursoIdRecordar, (int) ($_POST['enlace_id'] ?? 0), $cursoRec);
        header('Location: ' . $volver . '&mensaje=recordatorio_eval&n=' . $enviados . '&f=' . $fallidos . '#evaluacion');
    } catch (Throwable $e) {
        error_log('Recordatorio de evaluación: ' . $e->getMessage());
        $_SESSION['eval_meta_error'] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No fue posible enviar el recordatorio. Intenta más tarde.';
        header('Location: ' . $volver . '&error=recordatorio_eval#evaluacion');
    }
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
if ($idcurso > 0 && aula_integrada($db)) aula_sync_course($db, $idcurso);

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
if ($puedeGestionar || $puedeEditar) {
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
// Cursos de menos de CENGI_HORAS_MIN_EVALUACION horas: solo asistencia (sin notas por módulo).
$horasCurso = $idcurso > 0 ? cengi_curso_horas($db, $idcurso) : 0.0;
$soloAsistencia = $horasCurso > 0 && $horasCurso < CENGI_HORAS_MIN_EVALUACION;
if ($soloAsistencia) {
    $moduloId = 0;
    $moduloSeleccionado = null;
}
$prePostVisibles = cengi_curso_pre_post_visibles($modulos, $moduloId, $soloAsistencia);
$mostrarPre = $prePostVisibles['pre'];
$mostrarPost = $prePostVisibles['post'];
// La asistencia es acumulada del curso completo (Teams/Moodle): solo se registra en
// "Todo el curso". En un curso con modulos, Pre/Post del curso son calculados (promedio
// de los modulos ponderado por horas) y se muestran como solo lectura en esa vista.
$cursoConModulos = (bool) $modulos;
$vistaResumenModulos = $cursoConModulos && $moduloId === 0 && !$soloAsistencia;
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
            p.id AS participante_id,
            p.ingenio_id,
            p.puesto_participantes,
            p.area_participantes,
            p.estado_participantes,
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
            p.id AS participante_id,
            p.ingenio_id,
            p.puesto_participantes,
            p.area_participantes,
            p.estado_participantes,
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
$evalMeta = $idcurso > 0 ? cengi_eval_meta_curso($db, $idcurso) : ['inscritos' => 0, 'meta' => 0, 'porcentaje_meta' => cengi_eval_meta_porcentaje($db), 'enlaces' => [], 'bajo_meta' => 0];
$evalMetaError = (string) ($_SESSION['eval_meta_error'] ?? '');
unset($_SESSION['eval_meta_error']);
// El aviso solo tiene sentido cuando el curso ya empezó.
$evalMetaVigente = $evalMeta['enlaces'] && $evalMeta['inscritos'] > 0 && !empty($cursoInfo['inicio']) && $cursoInfo['inicio'] <= date('Y-m-d');
?>

<html lang="es">
<?php include('head.php'); ?>
<body class="cengi-canvas cg-tema">
<link rel="stylesheet" href="css/evaluacion_meta.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/evaluacion_meta.css'); ?>">
<?php menu_render(); ?>

<div class="container">
    <?php cengi_banner(); ?>
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
                'actualizado' => 'Los datos del participante se actualizaron correctamente.',
                'carga' => 'La carga masiva de participantes finalizó correctamente.',
                'correo_enviado' => 'Se envió el correo al participante con los documentos adjuntos.',
                'desactivado' => 'El participante fue eliminado de los registros activos correctamente.',
                'recordatorio_eval' => 'Recordatorio de evaluación enviado a ' . (int) ($_GET['n'] ?? 0) . ' participante(s)' . ((int) ($_GET['f'] ?? 0) > 0 ? '; ' . (int) $_GET['f'] . ' no se pudieron enviar (revisa Comunicaciones)' : '') . '.',
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
                'cui_duplicado' => 'Ya existe otro participante registrado con ese CUI. Verifica el número e inténtalo nuevamente.',
                'sin_correo' => 'El participante no tiene un correo electrónico válido. Edita sus datos y vuelve a intentarlo.',
                'sin_diploma' => 'El participante aún no tiene un diploma cargado o generado; envía solo la constancia o carga el diploma primero.',
                'sin_adjuntos' => 'Marca al menos un documento para enviar.',
                'correo' => 'No fue posible enviar el correo. Intenta más tarde.',
                'permiso_correo' => 'No tienes permiso para enviar documentos por correo.',
                'carga' => 'No fue posible procesar el archivo de participantes. Revisa el formato e inténtalo de nuevo.',
                'calificacion' => 'No fue posible procesar el archivo de calificaciones. Revisa el formato e inténtalo de nuevo.',
            ];
            if ($error === 'recordatorio_eval') {
                $cengiErroresSeguimiento['recordatorio_eval'] = $evalMetaError !== '' ? $evalMetaError : 'No fue posible enviar el recordatorio.';
            }
            echo cengi_pc_html($cengiErroresSeguimiento[$error] ?? 'No fue posible completar la operación. Verifica los datos e inténtalo nuevamente.');
            ?>
        </div>
    <?php endif; ?>

    <?php
        // Encabezado: datos del curso y avance (porcentajes para las barras de los indicadores).
        $segHoras = array_sum(array_map(static function ($m) { return (float) $m['horas']; }, $modulos));
        $segFechas = array_filter([$cursoInfo['inicio'] ?? null, $cursoInfo['fin'] ?? null]);
        $segPeriodo = $segFechas ? implode(' – ', array_map(static function ($f) { return date('d/m/Y', strtotime($f)); }, $segFechas)) : '';
        $segPctAprob = $totalInscritos ? round($aprobados / $totalInscritos * 100) : 0;
        $segAvMod = $moduloId > 0 ? $avanceModulos[$moduloId] : null;
        $segPctTercero = $segAvMod ? ($segAvMod['total'] ? round($segAvMod['calificados'] / $segAvMod['total'] * 100) : 0) : ($asistPromedio !== null ? round($asistPromedio) : 0);
        $segPctEval = $evalPromedio !== null ? round(min(100, $evalPromedio)) : 0;
        // Días y horario sin los valores de relleno ("Por definir") que guardan algunos cursos.
        $segHorario = implode(' ', array_unique(array_filter(array_map('trim', [(string) ($cursoInfo['dias'] ?? ''), (string) ($cursoInfo['horario'] ?? '')]), static function ($t) {
            return $t !== '' && mb_strtolower($t, 'UTF-8') !== 'por definir';
        })));
    ?>
    <section class="cengi-seg-hero">
        <div class="cengi-seg-hero-main">
            <a href="ver_cursos.php" class="cengi-seg-back"><span class="glyphicon glyphicon-chevron-left" aria-hidden="true"></span> Cursos</a>
            <span class="cengi-seg-eyebrow">
                <?php echo cengi_pc_html(implode(' · ', array_filter([$cursoInfo['codigo_curso'] ?? '', $cursoInfo['descripcion_categorias_cursos'] ?? '']))); ?>
            </span>
            <h2 class="cengi-seg-title"><?php echo cengi_pc_html($cursoInfo['nombre_cursos'] ?? 'Selecciona un curso'); ?></h2>
            <div class="cengi-seg-meta">
                <?php if ($segPeriodo !== ''): ?><span><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?php echo cengi_pc_html($segPeriodo); ?></span><?php endif; ?>
                <?php if ($segHorario !== ''): ?><span><span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?php echo cengi_pc_html($segHorario); ?></span><?php endif; ?>
                <?php if ($modulos): ?><span><span class="glyphicon glyphicon-th-list" aria-hidden="true"></span> <?php echo count($modulos); ?> módulo<?php echo count($modulos) === 1 ? '' : 's'; ?><?php echo $segHoras > 0 ? ' · ' . rtrim(rtrim(number_format($segHoras, 1), '0'), '.') . ' h' : ''; ?></span><?php endif; ?>
                <span><span class="glyphicon glyphicon-user" aria-hidden="true"></span> <?php echo $totalInscritos; ?> participante<?php echo $totalInscritos === 1 ? '' : 's'; ?></span>
            </div>
        </div>
        <form method="GET" class="cengi-seg-switch">
            <label for="cengi-seg-curso">Cambiar de curso</label>
            <select name="id" id="cengi-seg-curso" class="form-control" onchange="this.form.submit()">
                <?php foreach ($listaCursos as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $idcurso === (int) $c['id'] ? 'selected' : ''; ?>>
                        <?php echo cengi_pc_html($c['nombre_cursos'] . ' (' . $c['anio'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($idcurso > 0 && ($puedeGestionar || cengi_puede_calificar())): ?>
                <a href="aula_gestion.php?curso=<?php echo (int) $idcurso; ?>" class="btn btn-success btn-sm" style="margin-top:10px;">
                    <span class="glyphicon glyphicon-blackboard" aria-hidden="true"></span> Gestionar aula del curso
                </a>
            <?php endif; ?>
        </form>
    </section>

    <div class="cengi-seg-kpis">
        <div class="cengi-seg-kpi">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-user"></span></span>
            <div><div class="cengi-seg-kpi-val"><?php echo $totalInscritos; ?></div><div class="cengi-seg-kpi-label">Inscritos</div></div>
        </div>
        <?php
        // Cursos de solo asistencia (menos de 10 h o sin módulos con evaluación): indicadores de asistencia.
        $cengiSoloAsis = $soloAsistencia || !$modulos;
        if ($cengiSoloAsis):
            $cengiConAsis = count(array_filter($filas, static function ($f) { return is_numeric($f['asistencia'] ?? null); }));
            $cengiSesiones = array_values(array_filter(array_column($filas, 'sesiones_asistidas'), 'is_numeric'));
            $cengiPctAsis = $totalInscritos ? (int) round($cengiConAsis / $totalInscritos * 100) : 0;
        ?>
        <div class="cengi-seg-kpi is-ok">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-calendar"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $cengiConAsis; ?> <small><?php echo $cengiPctAsis; ?>%</small></div>
                <div class="cengi-seg-kpi-label">Con asistencia registrada</div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $cengiPctAsis; ?>%"></span></span>
            </div>
        </div>
        <div class="cengi-seg-kpi is-info">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-check"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $asistPromedio !== null ? number_format($asistPromedio, 0) . '%' : '—'; ?></div>
                <div class="cengi-seg-kpi-label">Asistencia promedio</div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $asistPromedio !== null ? min(100, (int) $asistPromedio) : 0; ?>%"></span></span>
            </div>
        </div>
        <div class="cengi-seg-kpi is-gold">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-time"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $cengiSesiones ? rtrim(rtrim(number_format(array_sum($cengiSesiones) / count($cengiSesiones), 1), '0'), '.') : '—'; ?></div>
                <div class="cengi-seg-kpi-label">Sesiones asistidas en promedio<?php echo $soloAsistencia ? ' · curso de ' . rtrim(rtrim(number_format($horasCurso, 1), '0'), '.') . ' h' : ''; ?></div>
                <span class="cengi-seg-kpi-bar"><span style="width:0%"></span></span>
            </div>
        </div>
        <?php else: ?>
        <div class="cengi-seg-kpi is-ok">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-ok"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $aprobados; ?> <small><?php echo $segPctAprob; ?>%</small></div>
                <div class="cengi-seg-kpi-label">Aprobados (pos-evaluación ≥ <?php echo CENGI_NOTA_APROBACION; ?>)</div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $segPctAprob; ?>%"></span></span>
            </div>
        </div>
        <div class="cengi-seg-kpi is-info">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-<?php echo $segAvMod ? 'edit' : 'check'; ?>"></span></span>
            <div>
                <?php if ($segAvMod): ?>
                    <div class="cengi-seg-kpi-val"><?php echo $segAvMod['calificados'] . ' / ' . $segAvMod['total']; ?></div>
                    <div class="cengi-seg-kpi-label">Calificados en este módulo</div>
                <?php else: ?>
                    <div class="cengi-seg-kpi-val"><?php echo $asistPromedio !== null ? number_format($asistPromedio, 0) . '%' : '—'; ?></div>
                    <div class="cengi-seg-kpi-label">Asistencia promedio (curso)</div>
                <?php endif; ?>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $segPctTercero; ?>%"></span></span>
            </div>
        </div>
        <div class="cengi-seg-kpi is-gold">
            <span class="cengi-seg-kpi-icon"><span class="glyphicon glyphicon-star"></span></span>
            <div>
                <div class="cengi-seg-kpi-val"><?php echo $evalPromedio !== null ? number_format($evalPromedio, 0) . ' <small>pts</small>' : '—'; ?></div>
                <div class="cengi-seg-kpi-label"><?php echo $moduloId > 0 ? 'Pos-evaluación promedio del módulo' : 'Evaluación final promedio'; ?></div>
                <span class="cengi-seg-kpi-bar"><span style="width:<?php echo $segPctEval; ?>%"></span></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($evalMetaVigente && $evalMeta['bajo_meta'] > 0): ?>
        <a href="#evaluacion" class="cengi-evm-aviso" data-seg-ir="evaluacion">
            <span class="glyphicon glyphicon-bullhorn" aria-hidden="true"></span>
            <span><b>Evaluación del instructor por debajo de la meta:</b> <?php echo $evalMeta['bajo_meta']; ?> de <?php echo count($evalMeta['enlaces']); ?> evaluación<?php echo count($evalMeta['enlaces']) === 1 ? '' : 'es'; ?> no llega<?php echo $evalMeta['bajo_meta'] === 1 ? '' : 'n'; ?> al <?php echo $evalMeta['porcentaje_meta']; ?> % de participantes (<?php echo $evalMeta['meta']; ?> respuestas de <?php echo $evalMeta['inscritos']; ?>). Recuérdales que es importante para la mejora.</span>
            <span class="cengi-evm-aviso-ir">Ver y enviar recordatorio →</span>
        </a>
    <?php endif; ?>

    <nav class="cengi-seg-tabs" role="tablist" aria-label="Secciones del curso">
        <a href="#participantes" class="cengi-seg-tab is-active" role="tab" data-seg-tab="participantes"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> Participantes y <?php echo $cengiSoloAsis ? 'asistencia' : 'notas'; ?> <b><?php echo $totalInscritos; ?></b></a>
        <a href="#temario" class="cengi-seg-tab" role="tab" data-seg-tab="temario"><span class="glyphicon glyphicon-list-alt" aria-hidden="true"></span> Temario <b><?php echo count($modulos); ?></b></a>
        <a href="#materiales" class="cengi-seg-tab" role="tab" data-seg-tab="materiales"><span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span> Materiales <b><?php echo count($contenidos); ?></b></a>
        <?php if ($evalMeta['enlaces']): ?>
        <a href="#evaluacion" class="cengi-seg-tab" role="tab" data-seg-tab="evaluacion"><span class="glyphicon glyphicon-star" aria-hidden="true"></span> Evaluación del instructor <b class="<?php echo $evalMeta['bajo_meta'] ? 'cengi-evm-tab-bajo' : 'cengi-evm-tab-ok'; ?>"><?php echo $evalMeta['bajo_meta'] ? $evalMeta['bajo_meta'] . ' bajo meta' : '✓'; ?></b></a>
        <?php endif; ?>
    </nav>

    <?php if ($evalMeta['enlaces']): ?>
    <div class="panel panel-success cengi-seg-pane cengi-evm" id="evaluacion" data-seg-pane="evaluacion" hidden>
        <div class="panel-heading">
            <h3 class="panel-title">Evaluación del instructor · meta del <?php echo $evalMeta['porcentaje_meta']; ?> %</h3>
            <?php if (cengi_eval_meta_puede_configurar()): ?>
                <a class="cengi-evm-config" href="informe_instructores_resumen.php?vista=participacion#configurar-meta"><span class="glyphicon glyphicon-cog" aria-hidden="true"></span> Cambiar meta</a>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <p class="cengi-evm-intro">Para que la evaluación de cada instructor sea representativa, al menos el <b><?php echo $evalMeta['porcentaje_meta']; ?> %</b> de los participantes del curso debe responderla:
                <b><?php echo $evalMeta['meta']; ?> de <?php echo $evalMeta['inscritos']; ?></b> participante<?php echo $evalMeta['inscritos'] === 1 ? '' : 's'; ?> por cada instructor<?php echo count(array_filter(array_column($evalMeta['enlaces'], 'curso_modulo_id'))) ? ' o módulo' : ''; ?>.</p>
            <?php if ($evalMeta['inscritos'] === 0): ?>
                <div class="cengi-empty">El curso todavía no tiene participantes activos.</div>
            <?php endif; ?>
            <div class="cengi-evm-lista">
            <?php foreach ($evalMeta['enlaces'] as $e):
                $pct = (int) $e['porcentaje'];
                $tono = $e['cumple'] ? 'is-ok' : ($pct >= $evalMeta['porcentaje_meta'] / 2 ? 'is-medio' : 'is-bajo');
                $pend = $e['pendientes'];
            ?>
                <article class="cengi-evm-item <?php echo $tono; ?>">
                    <div class="cengi-evm-head">
                        <div>
                            <strong><?php echo cengi_pc_html($e['instructor']); ?></strong>
                            <small><?php echo $e['modulo'] ? 'Módulo: ' . cengi_pc_html($e['modulo']) : 'Todo el curso'; ?></small>
                        </div>
                        <span class="cengi-evm-estado"><?php echo $e['cumple'] ? '✓ Meta alcanzada' : ($e['faltan'] === 1 ? 'Falta 1 respuesta' : 'Faltan ' . $e['faltan'] . ' respuestas'); ?></span>
                    </div>
                    <div class="cengi-evm-progreso" role="img" aria-label="<?php echo $e['respuestas']; ?> de <?php echo $evalMeta['inscritos']; ?> respuestas (<?php echo $pct; ?> %), meta <?php echo $evalMeta['porcentaje_meta']; ?> %">
                        <span class="cengi-evm-barra"><i style="width:<?php echo min(100, $pct); ?>%"></i><em style="left:<?php echo $evalMeta['porcentaje_meta']; ?>%" title="Meta <?php echo $evalMeta['porcentaje_meta']; ?> %"></em></span>
                        <span class="cengi-evm-num"><b><?php echo $pct; ?> %</b> · <?php echo $e['respuestas']; ?> de <?php echo $evalMeta['inscritos']; ?></span>
                    </div>
                    <div class="cengi-evm-pie">
                        <a href="evaluacion.php?token=<?php echo rawurlencode($e['token']); ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-new-window" aria-hidden="true"></span> Ver formulario</a>
                        <?php if ($e['recordatorio']): ?><span class="cengi-evm-ultimo">Último recordatorio: <?php echo cengi_pc_html(date('d/m/Y H:i', strtotime($e['recordatorio']['fecha']))); ?> · <?php echo (int) $e['recordatorio']['veces']; ?> envío<?php echo (int) $e['recordatorio']['veces'] === 1 ? '' : 's'; ?></span><?php endif; ?>
                        <?php if ($puedeGestionar && !$e['cumple'] && $evalMeta['inscritos'] > 0 && ($pend === null || $pend)):
                            $nDest = $pend === null ? $evalMeta['inscritos'] : count($pend); ?>
                            <form method="POST" class="cengi-evm-form" onsubmit="return confirm('¿Enviar el recordatorio por correo a <?php echo $nDest; ?> participante(s)?');">
                                <input type="hidden" name="accion" value="recordar_evaluacion">
                                <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                                <input type="hidden" name="enlace_id" value="<?php echo (int) $e['id']; ?>">
                                <input type="hidden" name="csrf" value="<?php echo cengi_pc_html($_SESSION['eval_meta_csrf']); ?>">
                                <button class="btn btn-success btn-sm"><span class="glyphicon glyphicon-send" aria-hidden="true"></span> Enviar recordatorio <?php echo $pend === null ? 'a todos (' . $nDest . ')' : 'a quienes faltan (' . $nDest . ')'; ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($pend !== null && $pend && !$e['cumple']): ?>
                        <details class="cengi-evm-faltan"><summary><?php echo count($pend); ?> participante<?php echo count($pend) === 1 ? '' : 's'; ?> sin respuesta registrada en el Aula</summary>
                            <ul><?php foreach ($pend as $p): ?><li><?php echo cengi_pc_html($p['nombre']); ?><?php if ($p['ingenio']): ?> <small><?php echo cengi_pc_html($p['ingenio']); ?></small><?php endif; ?><?php if (!filter_var(trim((string) $p['correo']), FILTER_VALIDATE_EMAIL)): ?> <small class="cengi-evm-sin">sin correo</small><?php endif; ?></li><?php endforeach; ?></ul>
                        </details>
                    <?php elseif ($pend === null): ?>
                        <p class="cengi-evm-nota">Respuestas anónimas (enlace público o carga masiva): no se sabe quién falta, así que el recordatorio va a todos los inscritos. Para saber quién falta, habilita la evaluación en <a href="aula_gestion.php?curso=<?php echo (int) $idcurso; ?>">Gestión del aula</a>.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel panel-success cengi-seg-pane" id="temario" data-seg-pane="temario" hidden>
        <div class="panel-heading">
            <div>
                <h3 class="panel-title">Temario del curso</h3>
                <small>Índice temático por módulo (mismo temario para todas las ediciones de este registro)</small>
            </div>
        </div>
        <div class="panel-body">
            <?php if (!$modulos): ?>
                <div class="cengi-empty">Este curso todavía no tiene módulos ni temario configurados.</div>
            <?php else: ?>
                <div class="cengi-seg-modulos">
                <?php foreach ($modulos as $k => $modulo): ?>
                    <article class="cengi-seg-modulo">
                        <header>
                            <span class="cengi-seg-modulo-num"><?php echo $k + 1; ?></span>
                            <div>
                                <h4><?php echo cengi_pc_html($modulo['nombre']); ?></h4>
                                <small><?php echo rtrim(rtrim(number_format((float) $modulo['horas'], 1), '0'), '.') ?: '0'; ?> h · <?php echo count($modulo['temas']); ?> tema<?php echo count($modulo['temas']) === 1 ? '' : 's'; ?></small>
                            </div>
                        </header>
                        <?php if ($modulo['temas']): ?>
                            <ol class="cengi-seg-temas">
                                <?php foreach ($modulo['temas'] as $tema): ?>
                                    <li><?php echo cengi_pc_html($tema); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <p class="cengi-seg-sin-temas">Sin temas registrados.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($puedeGestionar): ?>
            <details class="cengi-seg-agregar">
                <summary><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Agregar módulo al temario</summary>
                <form method="POST" class="cengi-form-grid">
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

    <div class="panel panel-success cengi-seg-pane" id="materiales" data-seg-pane="materiales" hidden>
        <div class="panel-heading">
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
                    <div class="cengi-seg-material">
                        <div class="cengi-seg-material-head">
                            <span class="cengi-seg-material-icon"><span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span></span>
                            <div class="cengi-seg-material-info">
                                <strong><?php echo cengi_pc_html($contenido['titulo']); ?></strong>
                                <small><?php echo cengi_pc_html(parse_url($contenido['url'], PHP_URL_HOST) ?: $contenido['url']); ?></small>
                            </div>
                            <a href="<?php echo cengi_pc_html($contenido['url']); ?>" target="_blank" rel="noopener" class="btn btn-default btn-sm">
                                <span class="glyphicon glyphicon-new-window" aria-hidden="true"></span> Abrir
                            </a>
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
                        <?php // La vista previa se carga solo al abrirla (data-src): evita traer todos los iframes de golpe. ?>
                        <details class="cengi-seg-preview">
                            <summary>Vista previa</summary>
                            <iframe
                                data-src="<?php echo cengi_pc_html($embedUrlContenido); ?>"
                                loading="lazy"
                                title="<?php echo cengi_pc_html($contenido['titulo']); ?>"
                            ></iframe>
                            <p class="text-muted">
                                Si no se muestra, el proveedor no permite verlo dentro de otra página: usa el botón "Abrir".
                            </p>
                        </details>
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
            <details class="cengi-seg-agregar">
                <summary><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Agregar material o enlace</summary>
                <form method="POST" class="cengi-form-grid">
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

    <div class="panel panel-success cengi-roster cengi-seg-pane" id="participantes" data-seg-pane="participantes">
        <div class="panel-heading">
            <div>
                <h3 class="panel-title">Participantes del curso <span class="cengi-roster-count"><?php echo count($filas); ?></span></h3>
                <?php if ($modulos && !$soloAsistencia): ?>
                    <small>La asistencia del aula se acumula por sesión; las notas publicadas alimentan cada módulo y la nota final se calcula sola.</small>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($modulos && !$soloAsistencia): ?>
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
            <?php if ($soloAsistencia || (!$modulos && $moduloId === 0)): ?>
                <div class="cengi-mod-info is-asistencia">
                    <span class="glyphicon glyphicon-calendar" aria-hidden="true"></span>
                    <div><strong>Solo asistencia.</strong>
                        <?php if ($soloAsistencia): ?>Este curso dura <b><?php echo rtrim(rtrim(number_format($horasCurso, 1), '0'), '.'); ?> h</b> (menos de <?php echo CENGI_HORAS_MIN_EVALUACION; ?> h): se registra la asistencia y las sesiones asistidas, sin pre ni post-evaluación.
                        <?php else: ?>Este curso no tiene módulos con evaluación: se registra la asistencia y las sesiones asistidas.<?php endif; ?>
                        Para cargar a todos a la vez usa <b>Importar / exportar → Cargar asistencia</b>.</div>
                </div>
            <?php endif; ?>
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
                    <div>La <strong>asistencia por sesión y las notas publicadas del aula</strong> alimentan esta ficha automáticamente. También puedes registrar información histórica y diplomas. Para revisar las actividades abre Gestionar aula del curso.</div>
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
                    + ($mostrarPre && $mostrarPost ? 1 : 0)
                    + ($vistaResumenModulos ? count($modulos) + 1 : 0)
                    + ($moduloId === 0 ? 1 : 0)
                    + 1;
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
                    <?php
                    // Herramientas de archivo agrupadas en un solo menú para no saturar la barra.
                    $cengiHerramientas = ($moduloId > 0 && !$moduloSinNotas) || $moduloId === 0;
                    ?>
                    <?php if ($cengiHerramientas): ?>
                        <details class="cengi-acc cengi-roster-herr">
                            <summary class="cengi-course-actions-toggle"><span class="glyphicon glyphicon-transfer" aria-hidden="true"></span> Importar / exportar <span class="course-actions-chevron" aria-hidden="true"></span></summary>
                            <div class="cengi-course-actions-panel cengi-acc-panel">
                                <?php if ($moduloId > 0 && !$moduloSinNotas): ?>
                                    <div class="course-actions-heading"><strong>Notas del módulo</strong></div>
                                    <a href="exportar_notas_modulo.php?curso_id=<?php echo (int) $idcurso; ?>&amp;modulo_id=<?php echo (int) $moduloId; ?>&amp;format=excel" class="cengi-action-btn" download><span class="glyphicon glyphicon-download-alt"></span> Descargar en Excel</a>
                                    <a href="exportar_notas_modulo.php?curso_id=<?php echo (int) $idcurso; ?>&amp;modulo_id=<?php echo (int) $moduloId; ?>&amp;format=pdf" class="cengi-action-btn" download><span class="glyphicon glyphicon-file"></span> Descargar en PDF</a>
                                    <button type="button" class="cengi-action-btn" data-toggle="modal" data-target="#bulk-grades-modulo-modal"><span class="glyphicon glyphicon-upload"></span> Cargar notas del módulo (Excel)</button>
                                <?php endif; ?>
                                <?php if ($moduloId === 0): ?>
                                    <div class="course-actions-heading"><strong>Asistencia</strong></div>
                                    <a href="plantilla_calificaciones.php?curso_id=<?php echo (int) $idcurso; ?>" class="cengi-action-btn"><span class="glyphicon glyphicon-download-alt"></span> Descargar plantilla (Excel)</a>
                                    <button type="button" class="cengi-action-btn" data-toggle="modal" data-target="#bulk-grades-modal"><span class="glyphicon glyphicon-upload"></span> Cargar asistencia (Excel)</button>
                                <?php endif; ?>
                                <?php if ($puedeCargar && $moduloId === 0): ?>
                                    <div class="course-actions-heading"><strong>Participantes</strong></div>
                                    <button type="button" class="cengi-action-btn" data-toggle="modal" data-target="#bulk-participants-modal"><span class="glyphicon glyphicon-user"></span> Cargar participantes (CSV/Excel)</button>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                    <?php if (cengi_ve_todo_por_rol_o_ingenio() && $moduloId === 0): ?>
                        <a class="btn btn-link btn-sm cengi-roster-retirados" href="participantes.php?curso_id=<?php echo (int) $idcurso; ?>&amp;estado=inactivo" title="Participantes retirados de este curso (para reactivarlos)"><span class="glyphicon glyphicon-eye-close"></span> Retirados</a>
                    <?php endif; ?>
                    <?php if ($puedeGestionar && $moduloId === 0): ?>
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
                        <?php if ($mostrarPre && $mostrarPost): ?><th title="Posevaluación menos preevaluación. Alerta si la diferencia es menor a 20 puntos.">Brecha (puntos)</th><?php endif; ?>
                        <?php if ($vistaResumenModulos): ?>
                            <?php foreach ($modulos as $k => $m): ?>
                                <th class="cengi-mod-col" title="<?php echo cengi_pc_html($m['nombre']); ?>">M<?php echo $k + 1; ?><small><?php echo cengi_pc_html(mb_strimwidth($m['nombre'], 0, 18, '…', 'UTF-8')); ?></small></th>
                            <?php endforeach; ?>
                            <th>Nota final</th>
                        <?php endif; ?>
                        <?php if ($moduloId === 0): ?><th>Diploma</th><?php endif; ?>
                        <th>Acciones</th>
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
                                            <?php $cengiContacto = implode(' · ', array_filter([$fila['correo_participantes'], $fila['telefono_participantes'], $fila['grado_academico_participantes']])); ?>
                                            <small title="<?= htmlspecialchars($cengiContacto) ?>"><?= htmlspecialchars($fila['correo_participantes'] ?: $cengiContacto) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="cengi-roster-mono"><?= htmlspecialchars($fila['cui_participantes'] ?: '—') ?></span><?php if (cengi_cui_nd_es_generado($fila['cui_participantes'])): ?><small class="cengi-cui-sd" title="Código de referencia asignado por el sistema: reemplázalo por su documento cuando lo tenga (Editar datos)">Sin documento</small><?php endif; ?></td>
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
                                        <input type="number" name="registros[<?= $cengiAsig ?>][evaluacion]" class="form-control" min="0" max="100" step="0.01" placeholder="0–100" aria-label="Pre-evaluación" value="<?= cengi_pc_html($fila['evaluacion']) ?>" data-preevaluacion>
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

                                <?php if ($mostrarPre && $mostrarPost): ?>
                                <?php
                                    $brecha = is_numeric($fila['evaluacion']) && is_numeric($fila['posevaluacion'])
                                        && (float) $fila['evaluacion'] >= 0 && (float) $fila['evaluacion'] <= 100
                                        && (float) $fila['posevaluacion'] >= 0 && (float) $fila['posevaluacion'] <= 100
                                        ? round((float) $fila['posevaluacion'] - (float) $fila['evaluacion'], 2) : null;
                                ?>
                                <td data-brecha aria-live="polite">
                                    <div class="cengi-roster-post">
                                        <strong data-brecha-valor><?= $brecha === null ? '—' : cengi_pc_html(rtrim(rtrim(number_format($brecha, 2, '.', ''), '0'), '.')) . ' pts' ?></strong>
                                        <span data-brecha-estado class="cengi-roster-estado<?= $brecha === null ? '' : ($brecha < 20 ? ' is-low' : ' is-ok') ?>"><?= $brecha === null ? 'Faltan notas' : ($brecha < 20 ? '⚠ Brecha menor a 20' : 'Brecha ≥ 20') ?></span>
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

                                <?php
                                // En la vista de un módulo solo se editan los datos; el resto de acciones vive en "Todo el curso".
                                $cengiBtnEditar = '';
                                if ($puedeEditar):
                                    ob_start(); ?>
                                            <button type="button" class="cengi-action-btn is-edit" data-toggle="modal" data-target="#participant-edit-modal"
                                                data-id="<?= (int) $fila['participante_id'] ?>" data-ingenio="<?= (int) $fila['ingenio_id'] ?>" data-cui="<?= cengi_pc_html($fila['cui_participantes']) ?>"
                                                data-name="<?= cengi_pc_html($fila['nombre_participantes']) ?>" data-position="<?= cengi_pc_html($fila['puesto_participantes']) ?>" data-area="<?= cengi_pc_html($fila['area_participantes']) ?>"
                                                data-email="<?= cengi_pc_html($fila['correo_participantes']) ?>" data-degree="<?= cengi_pc_html($fila['grado_academico_participantes']) ?>" data-phone="<?= cengi_pc_html($fila['telefono_participantes']) ?>"
                                                data-state="<?= (int) $fila['estado_participantes'] === 1 && (int) $fila['estado_asignaciones'] === 1 ? 1 : 0 ?>" aria-label="Editar datos"><span class="glyphicon glyphicon-pencil"></span> Editar datos</button>
                                <?php $cengiBtnEditar = ob_get_clean();
                                endif; ?>
                                <td class="cengi-roster-col-actions" data-sin-menu>
                                    <?php if ($moduloId > 0): ?>
                                        <?php echo $cengiBtnEditar !== '' ? str_replace('class="cengi-action-btn is-edit"', 'class="cengi-roster-editar"', $cengiBtnEditar) : '<span class="cengi-roster-empty">—</span>'; ?>
                                    <?php else: ?>
                                    <details class="cengi-acc cengi-roster-acc">
                                    <summary class="cengi-course-actions-toggle" aria-label="Acciones de <?= cengi_pc_html($fila['nombre_participantes']) ?>"><span class="course-actions-dots" aria-hidden="true">⋯</span> Acciones <span class="course-actions-chevron" aria-hidden="true"></span></summary>
                                    <div class="cengi-course-actions-panel cengi-acc-panel">
                                        <div class="course-actions-heading"><strong>Gestionar participante</strong><small><?= cengi_pc_html($fila['nombre_participantes']) ?></small></div>
                                        <?php echo $cengiBtnEditar; ?>
                                        <a class="cengi-action-btn is-view" href="constancia_notas.php?asignacion_id=<?= (int) $fila['asignacion_id'] ?>" target="_blank" rel="noopener" aria-label="Constancia de notas"><span class="glyphicon glyphicon-list-alt"></span> Constancia de notas</a>
                                        <?php if ($puedeConfigurarConstancia): ?><a class="cengi-action-btn is-view" href="constancia_notas_config.php?curso=<?= (int) $idcurso ?>" aria-label="Firmas de la constancia"><span class="glyphicon glyphicon-pencil"></span> Firmas de la constancia</a><?php endif; ?>
                                        <?php if ($puedeEnviarCorreo && $moduloId === 0): ?>
                                            <button type="button" class="cengi-action-btn is-view" data-toggle="modal" data-target="#participant-send-modal" data-assignment="<?= (int) $fila['asignacion_id'] ?>"
                                                data-name="<?= cengi_pc_html($fila['nombre_participantes']) ?>" data-email="<?= cengi_pc_html($fila['correo_participantes']) ?>" data-has-diploma="<?= !empty($fila['diploma']) ? 1 : 0 ?>" aria-label="Enviar diploma y notas por correo"><span class="glyphicon glyphicon-envelope"></span> Enviar diploma y notas</button>
                                        <?php endif; ?>
                                        <?php if ($puedeVerDirectorio): ?>
                                            <a class="cengi-action-btn is-view" href="directorio_participantes.php?ver=<?= (int) $fila['participante_id'] ?>" aria-label="Ficha e historial"><span class="glyphicon glyphicon-user"></span> Ficha e historial</a>
                                        <?php endif; ?>
                                        <?php if ($puedeGestionar && $puedeEliminar): ?>
                                            <button
                                                type="button"
                                                class="cengi-action-btn is-delete participant-delete-trigger"
                                                data-toggle="modal"
                                                data-target="#confirm-participant-delete"
                                                data-name="<?= htmlspecialchars($fila['nombre_participantes']) ?>"
                                                data-href="remover_participante_curso.php?asignacion_id=<?= (int) $fila['asignacion_id'] ?>&amp;curso_id=<?= $idcurso ?>&amp;return_to=seguimiento"
                                                aria-label="Desasignar del curso"
                                            >
                                                <span class="glyphicon glyphicon-remove"></span> Desasignar del curso
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    </details>
                                    <?php endif; ?>
                                </td>
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
                            <label for="cengi-nuevo-cui" class="control-label">Documento (DPI, pasaporte u otro) <small>opcional</small></label>
                            <input type="text" class="form-control" id="cengi-nuevo-cui" name="cui" maxlength="25" placeholder="En blanco: se asigna un código de referencia" title="Si no tiene documento, déjalo en blanco: el sistema asigna un código de referencia (ej. 0001ND) que puedes reemplazar después.">
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
            <button type="submit" form="cengi-form-existente" class="btn btn-success" id="cengi-btn-asignar-existente" disabled>Registrar inscripción</button>
            <button type="submit" form="cengi-form-nuevo" class="btn btn-success" id="cengi-btn-guardar-nuevo" style="display:none;">Guardar inscripción</button>
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

<script>
// Pestañas de la página (Participantes / Temario / Materiales). La pestaña se guarda en
// el #hash para que los redirects tras agregar un módulo o material vuelvan a ella.
(function () {
    'use strict';
    var tabs = document.querySelectorAll('[data-seg-tab]');
    if (!tabs.length) {
        return;
    }
    function mostrar(nombre, desdeClic) {
        if (!document.querySelector('[data-seg-pane="' + nombre + '"]')) {
            nombre = 'participantes';
        }
        Array.prototype.forEach.call(tabs, function (t) {
            var activa = t.getAttribute('data-seg-tab') === nombre;
            t.classList.toggle('is-active', activa);
            t.setAttribute('aria-selected', activa ? 'true' : 'false');
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-seg-pane]'), function (p) {
            p.hidden = p.getAttribute('data-seg-pane') !== nombre;
        });
        if (desdeClic) {
            try { history.replaceState(null, '', '#' + nombre); } catch (e) {}
        }
    }
    Array.prototype.forEach.call(tabs, function (t) {
        t.addEventListener('click', function (ev) {
            ev.preventDefault();
            mostrar(t.getAttribute('data-seg-tab'), true);
        });
    });
    mostrar((location.hash || '').replace('#', '') || 'participantes', false);
    // Enlaces de contexto (p. ej. el aviso de la meta de evaluación) que abren una pestaña.
    Array.prototype.forEach.call(document.querySelectorAll('[data-seg-ir]'), function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            mostrar(a.getAttribute('data-seg-ir'), true);
            var pane = document.querySelector('[data-seg-pane="' + a.getAttribute('data-seg-ir') + '"]');
            if (pane && pane.scrollIntoView) { pane.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });

    // Vista previa de materiales: el iframe se carga la primera vez que se abre.
    Array.prototype.forEach.call(document.querySelectorAll('.cengi-seg-preview'), function (d) {
        d.addEventListener('toggle', function () {
            var f = d.querySelector('iframe[data-src]');
            if (d.open && f && !f.getAttribute('src')) {
                f.setAttribute('src', f.getAttribute('data-src'));
            }
        });
    });
})();
</script>

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
    $roster.on('input change', '[data-preevaluacion], [data-posevaluacion]', function () {
        var $fila = $(this).closest('tr');
        var $brecha = $fila.find('[data-brecha]');
        if (!$brecha.length) return;
        var pre = ($fila.find('[data-preevaluacion]').val() || '').trim();
        var post = ($fila.find('[data-posevaluacion]').val() || '').trim();
        var valida = pre !== '' && post !== '' && isFinite(Number(pre)) && isFinite(Number(post))
            && Number(pre) >= 0 && Number(pre) <= 100 && Number(post) >= 0 && Number(post) <= 100;
        var diferencia = valida ? Math.round((Number(post) - Number(pre)) * 100) / 100 : null;
        $brecha.find('[data-brecha-valor]').text(valida ? diferencia + ' pts' : '—');
        $brecha.find('[data-brecha-estado]').toggleClass('is-low', valida && diferencia < 20)
            .toggleClass('is-ok', valida && diferencia >= 20)
            .text(!valida ? 'Faltan notas' : (diferencia < 20 ? '⚠ Brecha menor a 20' : 'Brecha ≥ 20'));
    });
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

<?php /* Ventanas traídas de Participantes por curso (participantes.php), ahora unificado aquí. */ ?>
<?php if ($puedeEditar): ?>
<div class="modal fade cengi-participant-modal" id="participant-edit-modal" tabindex="-1" role="dialog" aria-labelledby="participant-edit-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form action="actualizar_participantes.php" method="post">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-pencil"></span></span><div><h4 class="modal-title" id="participant-edit-title">Editar participante</h4><p>Actualiza sus datos personales y laborales.</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="id" id="participant-edit-id"><input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                <div class="cengi-participant-modal-grid">
                    <div class="form-group"><label for="participant-edit-name">Nombre completo</label><input class="form-control" id="participant-edit-name" name="nombre_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-cui">Documento (DPI, pasaporte u otro)</label><input class="form-control" id="participant-edit-cui" name="cui_participantes" maxlength="25" placeholder="En blanco: se conserva el actual" title="Reemplaza el código de referencia (NNNND) por su documento cuando lo tenga: el anterior queda en el historial."></div>
                    <div class="form-group"><label for="participant-edit-company">Ingenio</label><select class="form-control" id="participant-edit-company" name="ingenio" required><?php foreach ($ingeniosParaModal as $ingenioModal): ?><option value="<?php echo (int) $ingenioModal['id']; ?>"><?php echo cengi_pc_html($ingenioModal['nombre_ingenios']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="participant-edit-area">Área</label><input class="form-control" id="participant-edit-area" name="area_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-position">Puesto</label><input class="form-control" id="participant-edit-position" name="puesto_participantes" required></div>
                    <div class="form-group"><label for="participant-edit-email">Correo electrónico</label><input type="email" class="form-control" id="participant-edit-email" name="correo_participantes" maxlength="255"></div>
                    <div class="form-group"><label for="participant-edit-degree">Grado académico</label><input class="form-control" id="participant-edit-degree" name="grado_academico_participantes" maxlength="255"></div>
                    <div class="form-group"><label for="participant-edit-phone">Teléfono</label><input type="tel" class="form-control" id="participant-edit-phone" name="telefono_participantes" maxlength="50"></div>
                    <div class="form-group"><label for="participant-edit-state">Estado en el curso</label><select class="form-control" id="participant-edit-state" name="estado_participantes"><option value="1">Activo</option><option value="0">Inactivo (retirarlo del curso)</option></select></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar cambios</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-participant-modal" id="bulk-grades-modal" tabindex="-1" role="dialog" aria-labelledby="bulk-grades-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form action="carga_calificaciones.php" method="post" enctype="multipart/form-data">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-upload"></span></span><div><h4 class="modal-title" id="bulk-grades-title">Cargar asistencia</h4><p>Actualiza la asistencia del curso desde un archivo Excel (.xlsx).</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="curso_id" value="<?php echo (int) $idcurso; ?>">
                <div class="cengi-upload-guide">
                    <strong>Formato requerido</strong>
                    <span>CUI, ASISTENCIA, SESIONES_ASISTIDAS</span>
                    <small>ASISTENCIA en porcentaje (0 a 100) y SESIONES_ASISTIDAS en número de sesiones (opcional: si la dejas vacía no se cambia). La plantilla ya trae a los participantes del curso con sus valores actuales: complétala y súbela tal cual; las columnas NOMBRE e INGENIO son solo de referencia.<?php echo $soloAsistencia ? ' Curso de menos de ' . CENGI_HORAS_MIN_EVALUACION . ' h: no lleva notas.' : (!empty($modulos) ? ' Las notas se cargan en la pestaña de cada módulo.' : ''); ?></small>
                    <a class="btn btn-default btn-sm cengi-upload-template" href="plantilla_calificaciones.php?curso_id=<?php echo (int) $idcurso; ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar plantilla de asistencia (Excel)</a>
                </div>
                <label class="cengi-upload-dropzone" for="bulk-grades-file"><span class="glyphicon glyphicon-cloud-upload"></span><strong>Selecciona el archivo Excel (.xlsx)</strong><small>Máximo 5 MB</small><input type="file" id="bulk-grades-file" name="archivo" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required></label>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Procesar archivo</button></div>
        </form>
    </div></div>
</div>

<?php if ($puedeCargar): ?>
<div class="modal fade cengi-participant-modal" id="bulk-participants-modal" tabindex="-1" role="dialog" aria-labelledby="bulk-participants-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form action="carga_participantes.php" method="post" enctype="multipart/form-data">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><span class="cengi-modal-icon"><span class="glyphicon glyphicon-user"></span></span><div><h4 class="modal-title" id="bulk-participants-title">Cargar participantes</h4><p>Agrega participantes al curso sin salir de esta vista.</p></div></div>
            <div class="modal-body">
                <input type="hidden" name="curso" value="<?php echo (int) $idcurso; ?>">
                <div class="cengi-upload-guide">
                    <strong>Formato requerido</strong>
                    <span>INGENIO, CUI, NOMBRE, PUESTO, AREA, CORREO_ELECTRONICO, GRADO_ACADEMICO, TELEFONO</span>
                    <small>Admite archivos CSV, XLS y XLSX. El INGENIO debe escribirse igual que en el catálogo de ingenios. Reemplaza la fila de ejemplo por tus participantes.</small>
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

<?php if ($puedeEnviarCorreo): ?>
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

<script>
(function () {
    'use strict';
    $('#participant-edit-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget;
        if (!t) return;
        [['id', 'participant-edit-id'], ['name', 'participant-edit-name'], ['cui', 'participant-edit-cui'], ['ingenio', 'participant-edit-company'], ['area', 'participant-edit-area'],
         ['position', 'participant-edit-position'], ['email', 'participant-edit-email'], ['degree', 'participant-edit-degree'], ['phone', 'participant-edit-phone']].forEach(function (par) {
            document.getElementById(par[1]).value = t.getAttribute('data-' + par[0]) || '';
        });
        document.getElementById('participant-edit-state').value = t.getAttribute('data-state') || '1';
    });
    $('#participant-send-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget;
        if (!t) return;
        var email = t.getAttribute('data-email') || '', valido = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email), conDiploma = t.getAttribute('data-has-diploma') === '1';
        document.getElementById('participant-send-assignment').value = t.getAttribute('data-assignment') || '';
        document.getElementById('participant-send-name').textContent = t.getAttribute('data-name') || '';
        document.getElementById('participant-send-email').textContent = email || 'sin correo';
        var diploma = document.getElementById('participant-send-diploma');
        diploma.checked = conDiploma; diploma.disabled = !conDiploma;
        document.getElementById('participant-send-diploma-note').textContent = conDiploma ? '' : '(aún no tiene diploma)';
        document.getElementById('participant-send-warning').hidden = valido;
        document.getElementById('participant-send-submit').disabled = !valido;
    });
})();
</script>
</body>
</html>
