<?php
require_once "conexion.php";
require_once "menu.php";
date_default_timezone_set('America/Guatemala');

// Dashboard exclusivo para el rol "Administrador de ingenio" (y admins, que ven
// todo por cortesia). Un usuario de ingenio SOLO puede ver la info de su propio
// ingenio_id de sesion: nunca se confia en el parametro de query string para
// eso, solo se usa para que un admin elija que ingenio revisar.
cengi_require_dashboard_ingenio('index.php');

$esAdmin = cengi_ve_todo_por_rol_o_ingenio();
$ingenioUsuarioId = cengi_ingenio_id_actual();

$db = conectar();

$ingenios = $esAdmin
    ? $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$ingenioId = (int) ($_GET['ingenio_id'] ?? 0);
if (!$esAdmin) {
    // Un usuario de ingenio solo puede ver su propio ingenio, sin importar el parametro recibido.
    $ingenioId = $ingenioUsuarioId;
} elseif ($ingenioId <= 0) {
    if ($ingenios) {
        $ingenioId = (int) $ingenios[0]['id'];
    }
}

$ingenioActual = null;
if ($esAdmin) {
    foreach ($ingenios as $ing) {
        if ((int) $ing['id'] === $ingenioId) {
            $ingenioActual = $ing;
            break;
        }
    }
} elseif ($ingenioId > 0) {
    $stmtIng = $db->prepare("SELECT id, nombre_ingenios FROM ingenios WHERE id = ? LIMIT 1");
    $stmtIng->execute([$ingenioId]);
    $ingenioActual = $stmtIng->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cengi_dbi_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_dbi_iniciales($nombre)
{
    $partes = preg_split('/\s+/', trim((string) $nombre));
    $iniciales = '';
    foreach (array_slice(array_filter($partes), 0, 2) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $iniciales !== '' ? $iniciales : 'P';
}

function cengi_dbi_fecha($valor)
{
    $valor = trim((string) ($valor ?? ''));
    if ($valor === '' || $valor === '0000-00-00') {
        return '—';
    }
    $fecha = strtotime($valor);
    return $fecha ? date('d/m/Y', $fecha) : '—';
}

function cengi_dbi_estado_curso($inicio, $fin)
{
    $hoy = date('Y-m-d');
    if ($fin && $fin !== '0000-00-00' && $fin < $hoy) {
        return ['Finalizado', 'is-finished'];
    }
    if ($inicio && $inicio !== '0000-00-00' && $inicio > $hoy) {
        return ['Planificación', 'is-upcoming'];
    }
    return ['Activo', 'is-active'];
}

// ---------------------------------------------------------------------
// Ficha AJAX: historial de un participante (misma logica que
// directorio_participantes.php, pero siempre acotada al $ingenioId ya
// resuelto arriba, que para un usuario de ingenio nunca puede ser distinto
// al de su propia sesion).
// ---------------------------------------------------------------------
$fichaId = (int) ($_GET['ficha_id'] ?? 0);
if ($fichaId > 0) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if ($ingenioId <= 0) {
        http_response_code(404);
        echo json_encode(['error' => 'No hay un ingenio disponible para esta cuenta.']);
        exit;
    }

    $stmtFicha = $db->prepare("SELECT p.id, p.nombre_participantes, p.cui_participantes,
            p.puesto_participantes, p.area_participantes, p.correo_participantes,
            p.grado_academico_participantes, p.telefono_participantes,
            p.estado_participantes, i.nombre_ingenios
        FROM participantes p
        INNER JOIN ingenios i ON i.id = p.ingenio_id
        WHERE p.id = ? AND p.ingenio_id = ? LIMIT 1");
    $stmtFicha->execute([$fichaId, $ingenioId]);
    $ficha = $stmtFicha->fetch(PDO::FETCH_ASSOC);

    if (!$ficha) {
        http_response_code(404);
        echo json_encode(['error' => 'El participante no existe o no pertenece a este ingenio.']);
        exit;
    }

    $stmtHistorial = $db->prepare("SELECT a.id AS asignacion_id, c.nombre_cursos,
            ca.descripcion_categorias_cursos AS categoria, c.tipo AS modalidad,
            c.inicio, c.fin, a.estado_asignaciones, cc.asistencia, cc.sesiones_asistidas,
            cc.evaluacion,
            cc.posevaluacion,
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS diploma,
            d.codigo_unico AS diploma_codigo, d.emitido_en AS diploma_fecha,
            CASE
                WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN 'finalizado'
                ELSE 'activo'
            END AS estado_curso
        FROM asignaciones a
        INNER JOIN cursos c ON c.id = a.cursos_id
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (
            SELECT asignacion_id, MAX(pdf_path) AS pdf_path,
                   MAX(codigo_unico) AS codigo_unico, MAX(emitido_en) AS emitido_en
            FROM diplomas WHERE tipo = 'curso' GROUP BY asignacion_id
        ) d ON d.asignacion_id = a.id
        WHERE a.participantes_id = ? AND a.estado_asignaciones = 1
        ORDER BY COALESCE(c.inicio, c.creado) DESC, c.nombre_cursos");
    $stmtHistorial->execute([$fichaId]);
    $cursosFicha = $stmtHistorial->fetchAll(PDO::FETCH_ASSOC);

    $resumen = ['total' => count($cursosFicha), 'completados' => 0, 'activos' => 0, 'diplomas' => 0];
    foreach ($cursosFicha as &$cursoFicha) {
        // Normaliza cualquier formato legado de path (ver conexion.php) a la URL
        // raiz-absoluta correcta antes de exponerlo en el JSON, igual que en
        // directorio_participantes.php.
        $cursoFicha['diploma'] = cengi_normalizar_url_archivo($cursoFicha['diploma']);

        if ($cursoFicha['estado_curso'] === 'finalizado') {
            $resumen['completados']++;
        } elseif ($cursoFicha['estado_curso'] === 'activo') {
            $resumen['activos']++;
        }
        if (!empty($cursoFicha['diploma']) || !empty($cursoFicha['diploma_codigo'])) {
            $resumen['diplomas']++;
        }
    }
    unset($cursoFicha);

    echo json_encode(['participante' => $ficha, 'resumen' => $resumen, 'cursos' => $cursosFicha], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------
// Detalle AJAX: participantes de un curso, siempre acotado al $ingenioId ya
// resuelto arriba (nunca se vuelve a leer $_GET['ingenio_id'] para decidir el
// filtro real), para que un usuario de ingenio jamas pueda ver participantes
// de otro ingenio en un curso. Solo lectura, sin ninguna accion de escritura.
// ---------------------------------------------------------------------
$cursoDetalleId = (int) ($_GET['curso_detalle_id'] ?? 0);
if ($cursoDetalleId > 0) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if ($ingenioId <= 0) {
        http_response_code(404);
        echo json_encode(['error' => 'No hay un ingenio disponible para esta cuenta.']);
        exit;
    }

    $stmtCursoDetalle = $db->prepare("SELECT c.id, c.codigo_curso, c.nombre_cursos,
            ca.descripcion_categorias_cursos AS categoria, c.tipo AS modalidad, c.inicio, c.fin
        FROM cursos c
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        WHERE c.id = ? LIMIT 1");
    $stmtCursoDetalle->execute([$cursoDetalleId]);
    $cursoDetalle = $stmtCursoDetalle->fetch(PDO::FETCH_ASSOC);

    if (!$cursoDetalle) {
        http_response_code(404);
        echo json_encode(['error' => 'El curso no existe.']);
        exit;
    }

    $stmtParticipantesCurso = $db->prepare("SELECT p.id, a.id AS asignacion_id, p.nombre_participantes, p.cui_participantes, p.puesto_participantes,
            a.estado_asignaciones, cc.asistencia, cc.sesiones_asistidas, cc.evaluacion, cc.posevaluacion,
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS diploma,
            d.codigo_unico AS diploma_codigo
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (
            SELECT asignacion_id, MAX(pdf_path) AS pdf_path, MAX(codigo_unico) AS codigo_unico
            FROM diplomas WHERE tipo = 'curso' GROUP BY asignacion_id
        ) d ON d.asignacion_id = a.id
        WHERE a.cursos_id = ? AND p.ingenio_id = ? AND p.estado_participantes = 1 AND a.estado_asignaciones = 1
        ORDER BY p.nombre_participantes");
    $stmtParticipantesCurso->execute([$cursoDetalleId, $ingenioId]);
    $participantesCurso = $stmtParticipantesCurso->fetchAll(PDO::FETCH_ASSOC);

    // Misma normalizacion de path legado (ver conexion.php) que en el bloque de
    // ficha_id de arriba, antes de exponer el path en el JSON.
    foreach ($participantesCurso as &$participanteCurso) {
        $participanteCurso['diploma'] = cengi_normalizar_url_archivo($participanteCurso['diploma']);
    }
    unset($participanteCurso);

    echo json_encode(['curso' => $cursoDetalle, 'participantes' => $participantesCurso], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------
// KPIs, graficas, cursos y participantes del ingenio resuelto.
// ---------------------------------------------------------------------
$busqueda = trim((string) ($_GET['q'] ?? ''));

$kpi = [
    'participantes_activos' => 0,
    'cursos_en_curso' => 0,
    'cursos_completados' => 0,
    'asistencia_prom' => null,
    'brecha' => null,
    'diplomas' => 0,
];
$cursosIngenio = [];
$participantesIngenio = [];
$serieAnual = ['labels' => [], 'valores' => []];
$serieCategoria = ['labels' => [], 'valores' => []];

if ($ingenioId > 0) {
    // Asistencia al dia: los cursos en curso con aula se recalculan sobre las sesiones ya impartidas.
    if (is_file(__DIR__ . '/aula_helpers.php')) {
        require_once __DIR__ . '/aula_helpers.php';
        try {
            if (aula_integrada($db)) {
                $stmt = $db->prepare("SELECT DISTINCT c.id FROM cursos c
                    INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
                    INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
                    WHERE (c.inicio IS NULL OR c.inicio <= CURDATE()) AND (c.fin IS NULL OR c.fin >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))");
                $stmt->execute([$ingenioId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cursoSync) {
                    aula_sync_course($db, (int) $cursoSync);
                }
            }
        } catch (Throwable $e) {
            error_log('Panel de ingenio (asistencia): ' . $e->getMessage());
        }
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM participantes WHERE ingenio_id = ? AND estado_participantes = 1");
    $stmt->execute([$ingenioId]);
    $kpi['participantes_activos'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT c.id)
        FROM cursos c
        INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ? AND p.estado_participantes = 1
        WHERE (c.inicio IS NULL OR c.inicio <= CURDATE()) AND (c.fin IS NULL OR c.fin >= CURDATE())
    ");
    $stmt->execute([$ingenioId]);
    $kpi['cursos_en_curso'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT c.id)
        FROM cursos c
        INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ? AND p.estado_participantes = 1
        WHERE c.fin IS NOT NULL AND c.fin < CURDATE()
    ");
    $stmt->execute([$ingenioId]);
    $kpi['cursos_completados'] = (int) $stmt->fetchColumn();

    // Brecha de aprendizaje (antes en Seguimiento por ingenio): promedio post - promedio pre.
    $stmt = $db->prepare("
        SELECT AVG(CASE WHEN cc.asistencia REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) AS asistencia_prom,
            AVG(CASE WHEN cc.evaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.evaluacion AS DECIMAL(6,2)) END) AS pre_prom,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS post_prom
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE p.ingenio_id = ? AND p.estado_participantes = 1 AND a.estado_asignaciones = 1
    ");
    $stmt->execute([$ingenioId]);
    $asistenciaFila = $stmt->fetch(PDO::FETCH_ASSOC);
    $kpi['asistencia_prom'] = $asistenciaFila['asistencia_prom'] !== null ? (float) $asistenciaFila['asistencia_prom'] : null;
    // Comparar las mismas inscripciones: una nota faltante no equivale a cero.
    $stmtPares = $db->prepare("SELECT COUNT(*) pares,
        AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)) - CAST(cc.evaluacion AS DECIMAL(6,2))) avance
        FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id
        INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE p.ingenio_id = ? AND p.estado_participantes = 1 AND a.estado_asignaciones = 1
        AND cc.evaluacion REGEXP '^[0-9]+([.][0-9]+)?$'
        AND cc.posevaluacion REGEXP '^[0-9]+([.][0-9]+)?$'");
    $stmtPares->execute([$ingenioId]);
    $pares = $stmtPares->fetch(PDO::FETCH_ASSOC);
    $kpi['brecha'] = $pares['avance'] !== null ? (float) $pares['avance'] : null;
    $kpi['pares'] = (int) $pares['pares'];

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT CASE WHEN COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, ''), d.codigo_unico) IS NOT NULL THEN a.id END)
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ? AND p.estado_participantes = 1
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE a.estado_asignaciones = 1
    ");
    $stmt->execute([$ingenioId]);
    $kpi['diplomas'] = (int) $stmt->fetchColumn();

    $stmtAnual = $db->prepare("
        SELECT YEAR(a.creado) AS anio, COUNT(DISTINCT a.participantes_id) AS total
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        WHERE p.ingenio_id = ? AND a.creado IS NOT NULL AND p.estado_participantes = 1 AND a.estado_asignaciones = 1
        GROUP BY YEAR(a.creado)
        ORDER BY anio
    ");
    $stmtAnual->execute([$ingenioId]);
    foreach ($stmtAnual->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $serieAnual['labels'][] = (string) $r['anio'];
        $serieAnual['valores'][] = (int) $r['total'];
    }

    $stmtCategoria = $db->prepare("
        SELECT ca.descripcion_categorias_cursos AS categoria, COUNT(DISTINCT c.id) AS total
        FROM cursos c
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ? AND p.estado_participantes = 1
        GROUP BY ca.descripcion_categorias_cursos
        ORDER BY total DESC
    ");
    $stmtCategoria->execute([$ingenioId]);
    foreach ($stmtCategoria->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $serieCategoria['labels'][] = (string) $r['categoria'];
        $serieCategoria['valores'][] = (int) $r['total'];
    }

    $stmtCursos = $db->prepare("
        SELECT
            c.id, c.codigo_curso, c.nombre_cursos, ca.descripcion_categorias_cursos AS categoria,
            c.tipo AS modalidad, c.inicio, c.fin,
            COUNT(DISTINCT a.id) AS inscritos,
            AVG(CASE WHEN cc.asistencia REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) AS asistencia_prom,
            AVG(cc.sesiones_asistidas) AS sesiones_asistidas_prom,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS eval_prom,
            MAX(ses.total_ses) AS sesiones_total, MAX(ses.impartidas_ses) AS sesiones_impartidas
        FROM cursos c
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        INNER JOIN asignaciones a ON a.cursos_id = c.id AND a.estado_asignaciones = 1
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ? AND p.estado_participantes = 1
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (
            SELECT pc.curso_id, SUM(s.estado <> 'cancelada') AS total_ses,
                SUM(s.estado <> 'cancelada' AND (s.estado = 'impartida' OR TIMESTAMP(s.fecha, s.hora_fin) <= ?)) AS impartidas_ses
            FROM curso_sesiones s INNER JOIN planificacion_cursos pc ON pc.id = s.planificacion_id
            WHERE pc.curso_id IS NOT NULL GROUP BY pc.curso_id
        ) ses ON ses.curso_id = c.id
        GROUP BY c.id, c.codigo_curso, c.nombre_cursos, ca.descripcion_categorias_cursos, c.tipo, c.inicio, c.fin
        ORDER BY c.inicio DESC, c.nombre_cursos
    ");
    $stmtCursos->execute([$ingenioId, date('Y-m-d H:i:s')]);
    $cursosIngenio = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);

    $condicionesPart = ['p.ingenio_id = ?', 'p.estado_participantes = 1'];
    $paramsPart = [$ingenioId];
    if ($busqueda !== '') {
        $condicionesPart[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR p.area_participantes LIKE ? OR p.correo_participantes LIKE ? OR p.grado_academico_participantes LIKE ? OR p.telefono_participantes LIKE ?)';
        $termino = '%' . $busqueda . '%';
        array_push($paramsPart, $termino, $termino, $termino, $termino, $termino, $termino);
    }
    $stmtPart = $db->prepare("
        SELECT p.id, p.nombre_participantes, p.cui_participantes,
            p.puesto_participantes, p.area_participantes, p.correo_participantes,
            p.grado_academico_participantes, p.telefono_participantes, p.estado_participantes,
            COUNT(DISTINCT CASE WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN a.id END) AS cursos_completados,
            COUNT(DISTINCT CASE WHEN c.fin IS NULL OR c.fin >= CURDATE() THEN a.id END) AS cursos_activos,
            COUNT(DISTINCT a.id) AS total_cursos,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS evaluacion_promedio,
            COUNT(DISTINCT CASE WHEN COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, ''), d.codigo_unico) IS NOT NULL THEN a.id END) AS diplomas,
            MAX(c.inicio) AS ultima_capacitacion
        FROM participantes p
        LEFT JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
        LEFT JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE " . implode(' AND ', $condicionesPart) . "
        GROUP BY p.id, p.nombre_participantes, p.cui_participantes, p.puesto_participantes, p.area_participantes,
            p.correo_participantes, p.grado_academico_participantes, p.telefono_participantes, p.estado_participantes
        ORDER BY p.nombre_participantes
    ");
    $stmtPart->execute($paramsPart);
    $participantesIngenio = $stmtPart->fetchAll(PDO::FETCH_ASSOC);
}

$parametrosExportParticipantes = ['ingenio_id' => $ingenioId, 'vista' => 'participantes', 'q' => $busqueda];
$urlExportParticipantes = 'exportardashboardingenio.php?' . http_build_query($parametrosExportParticipantes);
$parametrosExportCursos = ['ingenio_id' => $ingenioId, 'vista' => 'cursos'];
$urlExportCursos = 'exportardashboardingenio.php?' . http_build_query($parametrosExportCursos);
$urlResumenPdf = 'dashboard_ingenio.php?' . http_build_query(['ingenio_id' => $ingenioId, 'pdf' => 'resumen']);

// "Ver PDF": el mismo resumen (indicadores, cobertura y cursos) como PDF real (Dompdf),
// que el navegador abre en una pestaña en lugar de depender de window.print().
if (($_GET['pdf'] ?? '') === 'resumen' && $ingenioId > 0) {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/classes/export_helpers.php';
    $h = 'cengi_dbi_html';
    $nivelColor = static function ($valor) {
        return $valor === null ? '#1f5632' : ($valor >= 80 ? '#4d8a14' : ($valor >= 60 ? '#8a6d00' : '#c95400'));
    };
    $nombreIngenio = (string) ($ingenioActual['nombre_ingenios'] ?? '');
    $logoRuta = __DIR__ . '/img/logo-comite-capacitacion-pdf.png'; // versión liviana: el logo original (6667 px) agota la memoria de Dompdf
    $logo = is_file($logoRuta) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoRuta)) : '';
    $inscripcionesResumen = array_sum(array_column($cursosIngenio, 'inscritos'));
    $sinEvaluacion = count(array_filter($cursosIngenio, static function ($curso) { return $curso['eval_prom'] === null; }));
    $sinAsistencia = count(array_filter($cursosIngenio, static function ($curso) { return $curso['asistencia_prom'] === null; }));
    $kpisPdf = [
        [number_format($kpi['participantes_activos']), 'Participantes activos', '', '#73BC25', '#1f5632'],
        [number_format($kpi['cursos_en_curso']), 'Cursos en curso', '', '#A3D300', '#1f5632'],
        [number_format($kpi['cursos_completados']), 'Cursos finalizados por fecha', '', '#CED2D5', '#1f5632'],
        [$kpi['asistencia_prom'] !== null ? number_format($kpi['asistencia_prom'], 0) . '%' : '—', 'Asistencia promedio', 'sobre las sesiones ya impartidas', '#FFCC00', $nivelColor($kpi['asistencia_prom'])],
        [$kpi['brecha'] !== null ? ($kpi['brecha'] >= 0 ? '+' : '') . number_format($kpi['brecha'], 0) . ' pts' : '—', 'Avance de aprendizaje (post − pre)', (int) ($kpi['pares'] ?? 0) . ' evaluaciones con ambas notas', '#73BC25', $kpi['brecha'] !== null && $kpi['brecha'] < 0 ? '#c95400' : '#1f5632'],
        [number_format($kpi['diplomas']), 'Inscripciones con diploma', '', '#FF6B00', '#1f5632'],
    ];

    ob_start(); ?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><style>
    @page { margin: 26px 30px 34px; }
    body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #263238; font-size: 10px; }
    .banda { background: #1f5632; color: #fff; padding: 14px 18px 12px; border-radius: 8px; }
    .banda td { vertical-align: middle; }
    .banda small { color: #D9F2A6; font-size: 8px; letter-spacing: .1em; text-transform: uppercase; }
    .banda h1 { margin: 3px 0 2px; font-size: 19px; }
    .banda p { margin: 0; font-size: 9px; color: #e3efd9; }
    .logo { background: #fff; border-radius: 6px; padding: 5px 8px; }
    .logo img { height: 38px; }
    .franja { height: 4px; margin: 0 0 14px; }
    .franja td { height: 4px; padding: 0; }
    table { border-collapse: collapse; width: 100%; }
    .kpis td { width: 16.66%; padding: 0 4px; vertical-align: top; }
    .kpi { border: 1px solid #e0e5dc; border-top-width: 4px; border-radius: 6px; padding: 9px 9px 8px; height: 62px; }
    .kpi b { display: block; font-size: 17px; }
    .kpi span { display: block; font-size: 8.5px; margin-top: 3px; color: #3c4a40; }
    .kpi i { display: block; font-style: normal; font-size: 7.5px; color: #6b7a72; margin-top: 2px; }
    .resumen { margin: 12px 0 14px; background: #f2f9e9; border-left: 4px solid #73BC25; }
    .resumen td { width: 33.3%; padding: 8px 12px; vertical-align: top; }
    .resumen b { font-size: 15px; color: #1f5632; margin-right: 4px; }
    .resumen small { display: block; color: #6b7a72; font-size: 7.5px; margin-top: 2px; }
    h2 { font-size: 12px; color: #1f5632; margin: 0 0 6px; padding-bottom: 4px; border-bottom: 2px solid #73BC25; }
    .cursos th { background: #f2f9e9; color: #1f5632; font-size: 8.5px; text-align: left; padding: 6px; border-bottom: 1px solid #cfe3b6; }
    .cursos td { padding: 6px; border-bottom: 1px solid #e8ece4; font-size: 8.5px; vertical-align: top; }
    .cursos tr:nth-child(even) td { background: #fbfcfa; }
    .cursos thead { display: table-header-group; }
    .cursos tr { page-break-inside: avoid; }
    .cod { color: #527438; font-size: 7.5px; font-weight: bold; }
    .sub { color: #6b7a72; font-size: 7.5px; }
    .estado { font-size: 7.5px; padding: 1px 6px; border-radius: 8px; background: #edf7e1; color: #35561e; }
    .estado.is-finished { background: #eceff1; color: #455a64; }
    .estado.is-upcoming { background: #fff5cc; color: #8a6d00; }
    .cursos .num { text-align: right; white-space: nowrap; }
    .nota { color: #6b7a72; font-size: 7.5px; margin-top: 8px; }
    .pie { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 7.5px; color: #6b7a72; }
</style></head><body>
    <table class="banda"><tr>
        <td>
            <small>Panel del ingenio · resultados de capacitación</small>
            <h1><?php echo $h($nombreIngenio); ?></h1>
            <p>Historial completo del ingenio: cursos, asistencia, evaluaciones y diplomas de sus participantes.</p>
            <p>Consulta: <?php echo date('d/m/Y H:i'); ?></p>
        </td>
        <?php if ($logo !== ''): ?><td style="text-align:right;width:150px"><span class="logo"><img src="<?php echo $logo; ?>" alt=""></span></td><?php endif; ?>
    </tr></table>
    <table class="franja"><tr><td style="background:#73BC25"></td><td style="background:#A3D300"></td><td style="background:#FFCC00"></td><td style="background:#FF6B00"></td></tr></table>

    <table class="kpis"><tr>
        <?php foreach ($kpisPdf as [$valor, $etiqueta, $detalle, $borde, $color]): ?>
            <td><div class="kpi" style="border-top-color:<?php echo $borde; ?>"><b style="color:<?php echo $color; ?>"><?php echo $h($valor); ?></b><span><?php echo $h($etiqueta); ?></span><?php if ($detalle !== ''): ?><i><?php echo $h($detalle); ?></i><?php endif; ?></div></td>
        <?php endforeach; ?>
    </tr></table>

    <table class="resumen"><tr>
        <td><b><?php echo number_format($inscripcionesResumen); ?></b> Inscripciones en <?php echo count($cursosIngenio); ?> cursos<small>Una persona puede participar en varios cursos.</small></td>
        <td><b><?php echo $sinEvaluacion; ?></b> Cursos sin evaluación final registrada<small>Sin notas finales numéricas de participantes del ingenio.</small></td>
        <td><b><?php echo $sinAsistencia; ?></b> Cursos sin asistencia registrada<small>Incluye cursos que todavía no han iniciado.</small></td>
    </tr></table>

    <h2>Cursos del ingenio</h2>
    <table class="cursos">
        <thead><tr><th style="width:34%">Curso</th><th>Período</th><th>Sesiones impartidas</th><th class="num">Inscritos</th><th class="num">Asistencia</th><th class="num">Evaluación final</th><th class="num">Sesiones asistidas</th></tr></thead>
        <tbody>
            <?php if (!$cursosIngenio): ?>
                <tr><td colspan="7">El ingenio todavía no tiene cursos con participantes inscritos.</td></tr>
            <?php endif; ?>
            <?php foreach ($cursosIngenio as $c):
                [$estadoLabel, $estadoClase] = cengi_dbi_estado_curso($c['inicio'], $c['fin']);
                $asisCurso = $c['asistencia_prom'] !== null ? (float) $c['asistencia_prom'] : null;
                $evalCurso = $c['eval_prom'] !== null ? (float) $c['eval_prom'] : null;
                $sesTotal = (int) $c['sesiones_total']; $sesImp = (int) $c['sesiones_impartidas'];
            ?>
                <tr>
                    <td><span class="cod"><?php echo $h($c['codigo_curso'] ?: ('CEN-' . str_pad((string) $c['id'], 3, '0', STR_PAD_LEFT))); ?></span> <span class="estado <?php echo $estadoClase; ?>"><?php echo $estadoLabel; ?></span><br>
                        <b><?php echo $h($c['nombre_cursos']); ?></b><br><span class="sub"><?php echo $h($c['categoria']); ?> · <?php echo $h($c['modalidad'] ?: 'Modalidad sin definir'); ?></span></td>
                    <td><?php echo $h(cengi_dbi_fecha($c['inicio'])); ?> → <?php echo $h(cengi_dbi_fecha($c['fin'])); ?></td>
                    <td><?php echo $sesTotal > 0 ? $sesImp . ' de ' . $sesTotal . ' (' . round(100 * $sesImp / $sesTotal) . '%)' : '<span class="sub">Sin programa</span>'; ?></td>
                    <td class="num"><?php echo (int) $c['inscritos']; ?></td>
                    <td class="num" style="color:<?php echo $nivelColor($asisCurso); ?>"><?php echo $asisCurso !== null ? number_format($asisCurso, 0) . '%' : '—'; ?></td>
                    <td class="num" style="color:<?php echo $nivelColor($evalCurso); ?>"><?php echo $evalCurso !== null ? number_format($evalCurso, 1) . ' pts' : '—'; ?></td>
                    <td class="num"><?php echo $c['sesiones_asistidas_prom'] !== null ? number_format((float) $c['sesiones_asistidas_prom'], 1) . ($sesImp ? ' / ' . $sesImp : '') : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="nota">Historial de participantes activos. Los cursos finalizados se clasifican por fecha, no por aprobación. «—» indica que no hay resultado registrado. Colores: verde ≥ 80, amarillo 60–79, naranja &lt; 60.</p>
    <div class="pie">CENGICAÑA · Comité de Capacitación · Generado el <?php echo date('d/m/Y H:i'); ?></div>
</body></html>
    <?php
    $html = ob_get_clean();
    $opciones = new Dompdf\Options();
    $opciones->set('isRemoteEnabled', false);
    $opciones->set('isHtml5ParserEnabled', true);
    $opciones->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($opciones);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'landscape');
    $dompdf->render();
    $canvas = $dompdf->getCanvas();
    $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.42, 0.48, 0.45]);
    $segmento = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombreIngenio))), '-');
    cengi_export_enviar_pdf($dompdf->output(), 'resumen-ingenio-' . ($segmento !== '' ? $segmento : $ingenioId) . '.pdf', false);
}
?>
<!DOCTYPE html>
<html lang="es">
<?php include('head.php'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<body class="cengi-canvas cengi-directory-page cengi-ingenio-page cg-tema">
<link rel="stylesheet" href="css/dashboard_ingenio.css?v=<?php echo filemtime(__DIR__ . '/css/dashboard_ingenio.css'); ?>">
<?php menu_render(); ?>
<main class="container">

    <header class="dbi-hero">
        <div class="dbi-hero-txt">
                <small class="dbi-hero-eyebrow">Panel del ingenio · resultados de capacitación</small>
                <h1><?php echo cengi_dbi_html($ingenioActual['nombre_ingenios'] ?? 'Sin ingenio asignado'); ?></h1>
                <span class="dbi-hero-sub">Historial completo del ingenio: cursos, asistencia, evaluaciones y diplomas de sus participantes.</span>
                <p class="dbi-print-date">Consulta: <?php echo date('d/m/Y H:i'); ?> · <?php echo $busqueda !== '' ? 'Participantes filtrados por: ' . cengi_dbi_html($busqueda) : 'Todos los participantes activos'; ?></p>
            </div>
            <?php if ($esAdmin && $ingenios): ?>
                <form method="GET" class="dbi-hero-select">
                    <label for="dbi-ingenio">Ver ingenio</label>
                    <select name="ingenio_id" id="dbi-ingenio" aria-label="Seleccionar ingenio" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($ingenios as $ing): ?>
                            <option value="<?php echo (int) $ing['id']; ?>" <?php echo $ingenioId === (int) $ing['id'] ? 'selected' : ''; ?>><?php echo cengi_dbi_html($ing['nombre_ingenios']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
    </header>

    <?php if ($ingenioId <= 0): ?>
        <div class="alert alert-warning" role="alert">Tu cuenta todavía no tiene un ingenio asignado. Contacta a un administrador para poder ver esta información.</div>
    <?php else: ?>

    <div class="dbi-actions">
        <a class="dbi-primary" href="#dbi-cursos">Resultados por curso</a>
        <a href="#dashboard-ingenio-filters">Consultar participantes</a>
        <a href="<?php echo cengi_dbi_html($urlResumenPdf); ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver PDF</a>
    </div>
    <p class="dbi-caption">Historial de participantes activos. Los cursos finalizados se clasifican por fecha, no por aprobación. «—» indica que no hay resultado registrado. La búsqueda de participantes no modifica los indicadores generales.</p>
    <?php
        // Semaforo de la paleta: verde >= 80, amarillo 60-79, naranja < 60.
        $dbiNivel = static function ($valor) { return $valor === null ? '' : ($valor >= 80 ? 'is-bien' : ($valor >= 60 ? 'is-medio' : 'is-bajo')); };
    ?>
    <div class="cengi-kpi-grid dbi-kpis">
        <div class="cengi-kpi is-verde"><span class="dbi-kpi-ico glyphicon glyphicon-user" aria-hidden="true"></span>
            <div class="cengi-kpi-val"><?php echo number_format($kpi['participantes_activos']); ?></div>
            <div class="cengi-kpi-label">Participantes activos</div>
        </div>
        <div class="cengi-kpi is-verde2"><span class="dbi-kpi-ico glyphicon glyphicon-play-circle" aria-hidden="true"></span>
            <div class="cengi-kpi-val"><?php echo number_format($kpi['cursos_en_curso']); ?></div>
            <div class="cengi-kpi-label">Cursos en curso</div>
        </div>
        <div class="cengi-kpi is-gris"><span class="dbi-kpi-ico glyphicon glyphicon-flag" aria-hidden="true"></span>
            <div class="cengi-kpi-val"><?php echo number_format($kpi['cursos_completados']); ?></div>
            <div class="cengi-kpi-label">Cursos finalizados por fecha</div>
        </div>
        <div class="cengi-kpi is-amarillo <?php echo $dbiNivel($kpi['asistencia_prom']); ?>"><span class="dbi-kpi-ico glyphicon glyphicon-calendar" aria-hidden="true"></span>
            <div class="cengi-kpi-val"><?php echo $kpi['asistencia_prom'] !== null ? number_format($kpi['asistencia_prom'], 0) . '%' : '—'; ?></div>
            <div class="cengi-kpi-label">Asistencia promedio<br><small>sobre las sesiones ya impartidas</small></div>
        </div>
        <div class="cengi-kpi is-verde <?php echo $kpi['brecha'] !== null && $kpi['brecha'] < 0 ? 'is-bajo' : ''; ?>"><span class="dbi-kpi-ico glyphicon glyphicon-stats" aria-hidden="true"></span>
            <div class="cengi-kpi-val"><?php echo $kpi['brecha'] !== null ? ($kpi['brecha'] >= 0 ? '+' : '') . number_format($kpi['brecha'], 0) . ' pts' : '—'; ?></div>
            <div class="cengi-kpi-label">Avance de aprendizaje (post − pre)<br><small><?php echo (int) ($kpi['pares'] ?? 0); ?> evaluaciones con ambas notas</small></div>
        </div>
        <div class="cengi-kpi is-naranja"><span class="dbi-kpi-ico glyphicon glyphicon-certificate" aria-hidden="true"></span>
            <div class="cengi-kpi-val"><?php echo number_format($kpi['diplomas']); ?></div>
            <div class="cengi-kpi-label">Inscripciones con diploma</div>
        </div>
    </div>

    <?php
        $inscripcionesResumen = array_sum(array_column($cursosIngenio, 'inscritos'));
        $sinEvaluacion = count(array_filter($cursosIngenio, static function ($curso) { return $curso['eval_prom'] === null; }));
        $sinAsistencia = count(array_filter($cursosIngenio, static function ($curso) { return $curso['asistencia_prom'] === null; }));
    ?>
    <section class="dbi-summary" aria-label="Cobertura de resultados">
        <div><strong><?php echo number_format($inscripcionesResumen); ?></strong><span>Inscripciones en <?php echo count($cursosIngenio); ?> cursos</span><small>Una persona puede participar en varios cursos.</small></div>
        <div><strong><?php echo $sinEvaluacion; ?></strong><span>Cursos sin evaluación final registrada</span><small>Sin notas finales numéricas de participantes del ingenio.</small></div>
        <div><strong><?php echo $sinAsistencia; ?></strong><span>Cursos sin asistencia registrada</span><small>Incluye cursos que todavía no han iniciado.</small></div>
    </section>
    <div class="cengi-charts-row">
        <div class="panel panel-success">
            <div class="panel-heading"><h3 class="panel-title">Personas inscritas por año de asignación</h3></div>
            <div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartIngenioAnual"></canvas></div></div>
        </div>
        <div class="panel panel-success">
            <div class="panel-heading"><h3 class="panel-title">Cursos por categoría</h3></div>
            <div class="panel-body"><div class="cengi-chart-wrap"><canvas id="chartIngenioCategoria"></canvas></div></div>
        </div>
    </div>

    <div class="panel panel-success cengi-dropdown-overflow-fix" id="dbi-cursos">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 class="panel-title">Cursos de tu ingenio</h3>
                <small>Cursos en los que tienen participantes inscritos</small>
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" <?php echo $cursosIngenio ? '' : 'disabled'; ?>>
                    <span class="glyphicon glyphicon-download-alt"></span> Descargar listado <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li><a href="<?php echo cengi_dbi_html($urlExportCursos . '&format=pdf'); ?>" download><span class="glyphicon glyphicon-file"></span> Descargar PDF</a></li>
                    <li><a href="<?php echo cengi_dbi_html($urlExportCursos . '&format=excel'); ?>" download><span class="glyphicon glyphicon-list-alt"></span> Descargar Excel</a></li>
                </ul>
            </div>
        </div>
        <div class="dbi-course-filter" id="dbi-course-filter" hidden>
            <label><span class="sr-only">Buscar curso, categoría o código</span><input type="search" id="dbi-course-search" placeholder="Buscar curso, categoría o código…" aria-controls="dbi-course-table"></label>
            <span id="dbi-course-count" role="status"></span>
            <span>PDF y Excel incluyen todos los cursos del ingenio.</span>
        </div>
        <div class="cengi-table-wrap" tabindex="0" aria-label="Resultados de cursos del ingenio">
            <table class="table table-striped table-bordered table-hover" id="dbi-course-table">
                <thead>
                    <tr><th scope="col">Curso</th><th scope="col">Período y avance</th><th scope="col">Asistencia</th><th scope="col">Resultados <small>promedio del ingenio</small></th><th scope="col"><span class="sr-only">Participantes</span></th></tr>
                </thead>
                <tbody>
                    <?php if (!$cursosIngenio): ?>
                        <tr><td colspan="5" class="text-center">Tu ingenio todavía no tiene cursos con participantes inscritos.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($cursosIngenio as $c):
                        [$estadoLabel, $estadoClase] = cengi_dbi_estado_curso($c['inicio'], $c['fin']);
                        $asisCurso = $c['asistencia_prom'] !== null ? (float) $c['asistencia_prom'] : null;
                        $evalCurso = $c['eval_prom'] !== null ? (float) $c['eval_prom'] : null;
                        $sesTotal = (int) $c['sesiones_total']; $sesImp = (int) $c['sesiones_impartidas'];
                    ?>
                        <tr class="dbi-curso-fila <?php echo $estadoClase; ?>">
                            <td class="dbi-course-name" data-label="Curso">
                                <div class="dbi-curso-top"><span class="dbi-code"><?php echo cengi_dbi_html($c['codigo_curso'] ?: ('CEN-' . str_pad((string) $c['id'], 3, '0', STR_PAD_LEFT))); ?></span><span class="cengi-status-badge <?php echo $estadoClase; ?>"><i></i><?php echo $estadoLabel; ?></span></div>
                                <strong><?php echo cengi_dbi_html($c['nombre_cursos']); ?></strong>
                                <small><?php echo cengi_dbi_html($c['categoria']); ?> · <?php echo cengi_dbi_html($c['modalidad'] ?: 'Modalidad sin definir'); ?></small>
                            </td>
                            <td data-label="Período y avance">
                                <div class="dbi-periodo"><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?php echo cengi_dbi_html(cengi_dbi_fecha($c['inicio'])); ?> <span class="dbi-flecha">→</span> <?php echo cengi_dbi_html(cengi_dbi_fecha($c['fin'])); ?></div>
                                <?php if ($sesTotal > 0): $avance = round(100 * $sesImp / $sesTotal); ?>
                                    <div class="dbi-avance" title="Sesiones impartidas de las programadas"><span><b><?php echo $sesImp; ?></b> de <?php echo $sesTotal; ?> sesiones impartidas</span><span class="dbi-avance-pct"><?php echo $avance; ?>%</span></div>
                                    <div class="dbi-avance-track" aria-hidden="true"><i style="width:<?php echo $avance; ?>%"></i></div>
                                <?php else: ?><div class="dbi-avance dbi-muted">Sin programa de sesiones</div><?php endif; ?>
                            </td>
                            <td data-label="Asistencia">
                                <div class="dbi-asis"><b class="dbi-asis-val <?php echo $dbiNivel($asisCurso); ?>"><?php echo $asisCurso !== null ? number_format($asisCurso, 0) . '%' : '—'; ?></b><span><?php echo $asisCurso !== null ? 'de las sesiones impartidas' : 'Sin asistencia registrada'; ?></span></div>
                                <?php if ($asisCurso !== null): ?><div class="dbi-attendance-track <?php echo $dbiNivel($asisCurso); ?>" aria-hidden="true"><span style="width:<?php echo max(0, min(100, $asisCurso)); ?>%"></span></div><?php endif; ?>
                            </td>
                            <td data-label="Resultados">
                                <div class="dbi-result"><span>Evaluación final</span><b class="dbi-chip <?php echo $dbiNivel($evalCurso); ?>"><?php echo $evalCurso !== null ? number_format($evalCurso, 1) . ' pts' : '—'; ?></b></div>
                                <div class="dbi-result"><span>Sesiones asistidas</span><b class="dbi-chip"><?php echo $c['sesiones_asistidas_prom'] !== null ? number_format((float) $c['sesiones_asistidas_prom'], 1) . ($sesImp ? ' / ' . $sesImp : '') : '—'; ?></b></div>
                            </td>
                            <td data-label="Participantes" class="dbi-course-action">
                                <button type="button" class="dbi-btn-participantes" data-toggle="modal" data-target="#dashboard-ingenio-course-modal" data-curso-id="<?php echo (int) $c['id']; ?>" aria-label="Ver participantes de <?php echo cengi_dbi_html($c['nombre_cursos']); ?>">
                                    <span class="dbi-btn-num"><?php echo (int) $c['inscritos']; ?></span>
                                    <span class="dbi-btn-txt"><b><?php echo (int) $c['inscritos'] === 1 ? 'Participante' : 'Participantes'; ?></b><small>Ver detalle y diplomas</small></span>
                                    <span class="glyphicon glyphicon-chevron-right" aria-hidden="true"></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <section class="cengi-directory-filter-card cengi-dropdown-overflow-fix">
        <form class="cengi-directory-filters" method="get" id="dashboard-ingenio-filters">
            <?php if ($esAdmin): ?><input type="hidden" name="ingenio_id" value="<?php echo (int) $ingenioId; ?>"><?php endif; ?>
            <label class="cengi-directory-search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span><span class="sr-only">Buscar por nombre, CUI o área</span>
                <input type="search" name="q" placeholder="Buscar participante por nombre, CUI o área..." value="<?php echo cengi_dbi_html($busqueda); ?>">
            </label>
            <button type="submit" class="btn btn-default"><span class="glyphicon glyphicon-filter"></span> Filtrar</button>
            <?php if ($busqueda !== ''): ?>
                <a class="cengi-directory-clear" href="dashboard_ingenio.php<?php echo $esAdmin ? '?ingenio_id=' . (int) $ingenioId : ''; ?>">Limpiar</a>
            <?php endif; ?>
            <div class="btn-group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" <?php echo $participantesIngenio ? '' : 'disabled'; ?>>
                    <span class="glyphicon glyphicon-download-alt"></span> Descargar listado <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li><a href="<?php echo cengi_dbi_html($urlExportParticipantes . '&format=pdf'); ?>" download><span class="glyphicon glyphicon-file"></span> Descargar PDF</a></li>
                    <li><a href="<?php echo cengi_dbi_html($urlExportParticipantes . '&format=excel'); ?>" download><span class="glyphicon glyphicon-list-alt"></span> Descargar Excel</a></li>
                </ul>
            </div>
        </form>
    </section>

    <section class="cengi-directory-table-card">
        <div class="cengi-directory-table-scroll" tabindex="0" aria-label="Participantes de tu ingenio">
            <table class="cengi-directory-table">
                <thead><tr><th>Participante</th><th>CUI</th><th>Cursos completados</th><th>Cursos activos</th><th>Evaluación prom.</th><th>Diplomas</th><th>Última capacitación</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                <?php if (!$participantesIngenio): ?>
                    <tr><td colspan="8" class="cengi-directory-empty"><span class="glyphicon glyphicon-user"></span><strong>No se encontraron participantes</strong><small>Modifica la búsqueda para ampliar los resultados.</small></td></tr>
                <?php endif; ?>
                <?php foreach ($participantesIngenio as $participante): ?>
                    <tr>
                        <td><div class="cengi-directory-person"><span class="cengi-directory-avatar"><?php echo cengi_dbi_html(cengi_dbi_iniciales($participante['nombre_participantes'])); ?></span><span><strong><?php echo cengi_dbi_html($participante['nombre_participantes']); ?></strong><small><?php echo cengi_dbi_html($participante['puesto_participantes']); ?></small></span></div></td>
                        <td class="cengi-directory-cui"><?php echo cengi_dbi_html($participante['cui_participantes']); ?></td>
                        <td><?php echo (int) $participante['cursos_completados']; ?></td>
                        <td><?php echo (int) $participante['cursos_activos']; ?></td>
                        <td><span class="dbi-chip <?php echo $dbiNivel($participante['evaluacion_promedio'] !== null ? (float) $participante['evaluacion_promedio'] : null); ?>"><?php echo $participante['evaluacion_promedio'] !== null ? number_format((float) $participante['evaluacion_promedio'], 1) . ' pts' : '—'; ?></span></td>
                        <td><?php if ((int) $participante['diplomas'] > 0): ?><span class="dbi-chip is-diploma"><span class="glyphicon glyphicon-certificate" aria-hidden="true"></span> <?php echo (int) $participante['diplomas']; ?></span><?php else: ?><span class="dbi-muted">0</span><?php endif; ?></td>
                        <td><?php echo cengi_dbi_html(cengi_dbi_fecha($participante['ultima_capacitacion'])); ?></td>
                        <td><button type="button" class="cengi-directory-profile-button" data-toggle="modal" data-target="#dashboard-ingenio-profile-modal" data-participant-id="<?php echo (int) $participante['id']; ?>">Ver ficha</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="cengi-directory-table-footer"><?php echo number_format(count($participantesIngenio)); ?> resultado<?php echo count($participantesIngenio) === 1 ? '' : 's'; ?></footer>
    </section>

    <?php endif; ?>
</main>

<div class="modal fade cengi-directory-modal" id="dashboard-ingenio-profile-modal" tabindex="-1" role="dialog" aria-labelledby="dashboard-ingenio-profile-name">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <header class="cengi-directory-profile-head">
            <span class="cengi-directory-profile-avatar" id="dashboard-ingenio-profile-avatar">P</span>
            <div><h2 id="dashboard-ingenio-profile-name">Ficha del participante</h2><p id="dashboard-ingenio-profile-subtitle"></p></div>
            <button type="button" class="cengi-directory-modal-close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">×</span></button>
        </header>
        <div class="modal-body">
            <div class="cengi-directory-modal-loading" id="dashboard-ingenio-profile-loading"><span class="glyphicon glyphicon-refresh"></span> Cargando ficha…</div>
            <div id="dashboard-ingenio-profile-content" hidden>
                <div class="cengi-directory-profile-stats">
                    <div><strong id="dashboard-ingenio-stat-total">0</strong><span>Cursos registrados</span></div>
                    <div><strong id="dashboard-ingenio-stat-completed">0</strong><span>Completados</span></div>
                    <div><strong id="dashboard-ingenio-stat-active">0</strong><span>Activos</span></div>
                    <div><strong id="dashboard-ingenio-stat-diplomas">0</strong><span>Diplomas</span></div>
                </div>
                <div class="cengi-directory-profile-details"><div><span>Puesto</span><strong id="dashboard-ingenio-profile-position">—</strong></div><div><span>Área</span><strong id="dashboard-ingenio-profile-area">—</strong></div><div><span>Correo electrónico</span><strong id="dashboard-ingenio-profile-email">—</strong></div><div><span>Grado académico</span><strong id="dashboard-ingenio-profile-degree">—</strong></div><div><span>Teléfono</span><strong id="dashboard-ingenio-profile-phone">—</strong></div></div>
                <h3 class="cengi-directory-history-title">Historial de capacitación</h3>
                <div class="cengi-directory-course-list" id="dashboard-ingenio-profile-courses"></div>
            </div>
            <div class="cengi-directory-modal-error" id="dashboard-ingenio-profile-error" hidden></div>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button></footer>
    </div></div>
</div>

<div class="modal fade cengi-directory-modal cengi-directory-course-modal" id="dashboard-ingenio-course-modal" tabindex="-1" role="dialog" aria-labelledby="dashboard-ingenio-course-name">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <header class="cengi-directory-profile-head">
            <span class="cengi-directory-profile-avatar" id="dashboard-ingenio-course-avatar"><span class="glyphicon glyphicon-book" aria-hidden="true"></span></span>
            <div><h2 id="dashboard-ingenio-course-name">Participantes del curso</h2><p id="dashboard-ingenio-course-subtitle"></p></div>
            <button type="button" class="cengi-directory-modal-close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">×</span></button>
        </header>
        <div class="modal-body">
            <div class="cengi-directory-modal-loading" id="dashboard-ingenio-course-loading"><span class="glyphicon glyphicon-refresh"></span> Cargando participantes…</div>
            <div id="dashboard-ingenio-course-content" hidden>
                <h3 class="cengi-directory-history-title">Participantes de tu ingenio en este curso</h3>
                <div class="cengi-table-wrap cengi-directory-course-table-wrap" tabindex="0" aria-label="Participantes inscritos en el curso">
                    <table class="table table-striped table-bordered cengi-directory-table cengi-directory-course-table">
                        <thead>
                            <tr><th>Participante</th><th>CUI</th><th>Estado</th><th>Asistencia</th><th>Sesiones asistidas</th><th>Pre-evaluación</th><th>Evaluación final</th><th>Diploma</th></tr>
                        </thead>
                        <tbody id="dashboard-ingenio-course-participants"></tbody>
                    </table>
                </div>
            </div>
            <div class="cengi-directory-modal-error" id="dashboard-ingenio-course-error" hidden></div>
        </div>
        <footer class="modal-footer cengi-directory-course-footer">
            <div class="btn-group dropup" id="dashboard-ingenio-course-export-group" hidden>
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Descargar listado <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="#" id="dashboard-ingenio-course-export-pdf" download><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Descargar PDF</a></li>
                    <li><a href="#" id="dashboard-ingenio-course-export-excel"><span class="glyphicon glyphicon-list-alt" aria-hidden="true"></span> Descargar Excel</a></li>
                </ul>
            </div>
            <a href="#" id="dashboard-ingenio-course-zip" class="btn btn-success" target="_blank" rel="noopener" hidden>
                <span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Descargar diplomas (ZIP)
            </a>
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
        </footer>
    </div></div>
</div>

<script>
if (typeof Chart !== 'undefined') {
Chart.defaults.font.family = "'Inter',sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = "#55705f";

<?php if ($ingenioId > 0): ?>
new Chart(document.getElementById('chartIngenioAnual'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($serieAnual['labels']); ?>,
        datasets: [{ label: 'Participantes', data: <?php echo json_encode($serieAnual['valores']); ?>, backgroundColor: <?php echo json_encode(array_map(static function ($i, $n) { return $i === $n - 1 ? '#FF6B00' : ($i % 2 ? '#A3D300' : '#73BC25'); }, array_keys($serieAnual['valores']), array_fill(0, count($serieAnual['valores']), count($serieAnual['valores'])))); ?>, borderRadius: 6, maxBarThickness: 42 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#E4E7E1' } } }, maintainAspectRatio: false }
});

new Chart(document.getElementById('chartIngenioCategoria'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($serieCategoria['labels']); ?>,
        datasets: [{ data: <?php echo json_encode($serieCategoria['valores']); ?>, backgroundColor: ['#73BC25', '#A3D300', '#FFCC00', '#FF6B00', '#1f5632', '#CED2D5', '#5e9b1d', '#FFE066'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 9, padding: 12 } } }, maintainAspectRatio: false, cutout: '62%' }
});
<?php endif; ?>
}

(function ($) {
    'use strict';
    var request = null;
    var currentIngenioId = <?php echo (int) $ingenioId; ?>;
    var $modal = $('#dashboard-ingenio-profile-modal');
    var $loading = $('#dashboard-ingenio-profile-loading');
    var $content = $('#dashboard-ingenio-profile-content');
    var $error = $('#dashboard-ingenio-profile-error');
    var $courses = $('#dashboard-ingenio-profile-courses');

    $('#dashboard-ingenio-filters').find('select').on('change', function () {
        this.form.submit();
    });

    function initials(name) {
        return String(name || 'P').trim().split(/\s+/).slice(0, 2).map(function (part) {
            return part.charAt(0).toUpperCase();
        }).join('') || 'P';
    }

    function valueOrDash(value, suffix) {
        if (value === null || value === undefined || value === '' || isNaN(Number(value))) return '—';
        return (Math.round(Number(value) * 10) / 10) + (suffix || '');
    }

    function formatDate(value) {
        if (!value || value === '0000-00-00') return 'Sin fecha';
        var parts = String(value).slice(0, 10).split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
    }

    // El href ya no apunta al PDF crudo: se arma hacia descargar_diploma.php,
    // que sirve el archivo con el nombre formateado (curso_ano_participante.pdf).
    // El campo "diploma" (URL normalizada) solo se usa aqui para decidir SI hay
    // un diploma disponible, igual que antes.
    function diplomaHref(record) {
        if (!record || !record.asignacion_id) return '';
        if (record.diploma) return 'descargar_diploma.php?asignacion_id=' + encodeURIComponent(Number(record.asignacion_id));
        // Emitido con el disenador: no hay archivo, se abre su pagina de validacion (ver / imprimir).
        if (record.diploma_codigo) return 'validar_diploma.php?c=' + encodeURIComponent(record.diploma_codigo);
        return '';
    }

    function nivel(value) {
        if (value === null || value === undefined || value === '' || isNaN(Number(value))) return '';
        var n = Number(value);
        return n >= 80 ? 'is-bien' : (n >= 60 ? 'is-medio' : 'is-bajo');
    }

    function chip(value, suffix) {
        return $('<span>', {class: 'dbi-chip ' + nivel(value)}).text(valueOrDash(value, suffix));
    }

    function metric(label, value) {
        return $('<div>', {class: 'cengi-directory-course-metric'})
            .append($('<span>').text(label)).append($('<strong>').text(value));
    }

    function renderCourse(course) {
        var state = ['activo', 'finalizado', 'inactivo'].indexOf(course.estado_curso) >= 0 ? course.estado_curso : 'inactivo';
        var labels = {activo: 'Activo', finalizado: 'Finalizado', inactivo: 'Inactivo'};
        var dateText = formatDate(course.inicio);
        if (course.fin && course.fin !== '0000-00-00') dateText += ' – ' + formatDate(course.fin);

        var $card = $('<article>', {class: 'cengi-directory-course-card'});
        var $head = $('<div>', {class: 'cengi-directory-course-head'})
            .append($('<strong>').text(course.nombre_cursos || 'Curso sin nombre'))
            .append($('<span>', {class: 'cengi-directory-course-state is-' + state}).text(labels[state]));
        var meta = [course.categoria, course.modalidad, dateText].filter(Boolean).join(' · ');
        var $metrics = $('<div>', {class: 'cengi-directory-course-metrics'})
            .append(metric('Asistencia', valueOrDash(course.asistencia, '%')).addClass(nivel(course.asistencia)))
            .append(metric('Sesiones asistidas', valueOrDash(course.sesiones_asistidas, '')))
            .append(metric('Pre-evaluación', valueOrDash(course.evaluacion, ' pts')))
            .append(metric('Post-evaluación', valueOrDash(course.posevaluacion, ' pts')).addClass(nivel(course.posevaluacion)));
        $card.append($head).append($('<p>', {class: 'cengi-directory-course-meta'}).text(meta)).append($metrics);

        var href = diplomaHref(course);
        if (href) {
            var label = course.diploma_codigo ? 'Ver diploma · ' + course.diploma_codigo : 'Ver diploma';
            $card.append($('<a>', {class: 'cengi-directory-diploma-link', href: href, target: '_blank', rel: 'noopener'})
                .append($('<span>', {class: 'glyphicon glyphicon-file', 'aria-hidden': 'true'}))
                .append(document.createTextNode(' ' + label)));
        }
        return $card;
    }

    function renderProfile(data) {
        var participant = data.participante || {};
        var summary = data.resumen || {};
        var courses = data.cursos || [];
        $('#dashboard-ingenio-profile-avatar').text(initials(participant.nombre_participantes));
        $('#dashboard-ingenio-profile-name').text(participant.nombre_participantes || 'Ficha del participante');
        $('#dashboard-ingenio-profile-subtitle').text((participant.nombre_ingenios || 'Sin ingenio') + ' · CUI ' + (participant.cui_participantes || '—'));
        $('#dashboard-ingenio-profile-position').text(participant.puesto_participantes || '—');
        $('#dashboard-ingenio-profile-area').text(participant.area_participantes || '—');
        $('#dashboard-ingenio-profile-email').text(participant.correo_participantes || '—');
        $('#dashboard-ingenio-profile-degree').text(participant.grado_academico_participantes || '—');
        $('#dashboard-ingenio-profile-phone').text(participant.telefono_participantes || '—');
        $('#dashboard-ingenio-stat-total').text(summary.total || 0);
        $('#dashboard-ingenio-stat-completed').text(summary.completados || 0);
        $('#dashboard-ingenio-stat-active').text(summary.activos || 0);
        $('#dashboard-ingenio-stat-diplomas').text(summary.diplomas || 0);
        $courses.empty();
        if (!courses.length) {
            $courses.append($('<div>', {class: 'cengi-directory-no-courses'}).text('Este participante todavía no tiene cursos registrados.'));
        } else {
            courses.forEach(function (course) { $courses.append(renderCourse(course)); });
        }
        $loading.attr('hidden', true);
        $error.attr('hidden', true);
        $content.removeAttr('hidden');
    }

    $modal.on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var participantId = trigger ? trigger.getAttribute('data-participant-id') : '';
        if (request) request.abort();
        $('#dashboard-ingenio-profile-avatar').text('P');
        $('#dashboard-ingenio-profile-name').text('Ficha del participante');
        $('#dashboard-ingenio-profile-subtitle').text('');
        $content.attr('hidden', true);
        $error.attr('hidden', true).text('');
        $loading.removeAttr('hidden');
        request = $.ajax({url: 'dashboard_ingenio.php', data: {ficha_id: participantId, ingenio_id: currentIngenioId}, dataType: 'json', cache: false})
            .done(renderProfile).fail(function (xhr, status) {
                if (status === 'abort') return;
                var message = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'No fue posible cargar la ficha. Intenta nuevamente.';
                $loading.attr('hidden', true);
                $content.attr('hidden', true);
                $error.text(message).removeAttr('hidden');
            });
    });

    $modal.on('hidden.bs.modal', function () {
        if (request) request.abort();
        request = null;
    });

    var courseRequest = null;
    var $courseModal = $('#dashboard-ingenio-course-modal');
    var $courseLoading = $('#dashboard-ingenio-course-loading');
    var $courseContent = $('#dashboard-ingenio-course-content');
    var $courseError = $('#dashboard-ingenio-course-error');
    var $courseParticipants = $('#dashboard-ingenio-course-participants');
    var $courseZipButton = $('#dashboard-ingenio-course-zip');
    var $courseExportGroup = $('#dashboard-ingenio-course-export-group');
    var $courseExportPdf = $('#dashboard-ingenio-course-export-pdf');
    var $courseExportExcel = $('#dashboard-ingenio-course-export-excel');

    function renderCourseParticipantRow(participant) {
        var estadoLabel = Number(participant.estado_asignaciones) === 1 ? 'Activo' : 'Inactivo';
        var $row = $('<tr>');
        $row.append($('<td>').append(
            $('<div>', {class: 'cengi-directory-person'})
                .append($('<span>', {class: 'cengi-directory-avatar'}).text(initials(participant.nombre_participantes)))
                .append($('<span>').append($('<strong>').text(participant.nombre_participantes || '—')).append($('<small>').text(participant.puesto_participantes || '')))
        ));
        $row.append($('<td>').text(participant.cui_participantes || '—'));
        $row.append($('<td>').text(estadoLabel));
        $row.append($('<td>').append(chip(participant.asistencia, '%')));
        $row.append($('<td>').text(valueOrDash(participant.sesiones_asistidas, '')));
        $row.append($('<td>').text(valueOrDash(participant.evaluacion, ' pts')));
        $row.append($('<td>').append(chip(participant.posevaluacion, ' pts')));

        var href = diplomaHref(participant);
        var $diplomaCell = $('<td>');
        if (href) {
            var label = participant.diploma_codigo ? 'Ver diploma · ' + participant.diploma_codigo : 'Ver diploma';
            $diplomaCell.append($('<a>', {class: 'cengi-directory-diploma-link', href: href, target: '_blank', rel: 'noopener'})
                .append($('<span>', {class: 'glyphicon glyphicon-file', 'aria-hidden': 'true'}))
                .append(document.createTextNode(' ' + label)));
        } else {
            $diplomaCell.text('—');
        }
        $row.append($diplomaCell);
        return $row;
    }

    function renderCourseDetail(data) {
        var course = data.curso || {};
        var participants = data.participantes || [];
        var dateText = formatDate(course.inicio);
        if (course.fin && course.fin !== '0000-00-00') dateText += ' – ' + formatDate(course.fin);
        var meta = [course.categoria, course.modalidad, dateText].filter(Boolean).join(' · ');

        $('#dashboard-ingenio-course-name').text(course.nombre_cursos || 'Participantes del curso');
        $('#dashboard-ingenio-course-subtitle').text((course.codigo_curso ? course.codigo_curso + ' · ' : '') + meta);
        $courseParticipants.empty();
        if (!participants.length) {
            $courseParticipants.append($('<tr>').append($('<td>', {colspan: 8, class: 'text-center'}).text('Tu ingenio no tiene participantes inscritos en este curso.')));
        } else {
            participants.forEach(function (participant) { $courseParticipants.append(renderCourseParticipantRow(participant)); });
        }

        // El boton de ZIP solo se muestra si al menos un participante de este
        // ingenio tiene diploma disponible en este curso (los participantes ya
        // vienen acotados a currentIngenioId desde el AJAX, asi que cualquier
        // "diploma" presente en la lista ya pertenece al ingenio del usuario).
        var tieneDiplomas = participants.some(function (participant) { return !!participant.diploma; });
        if (tieneDiplomas && course.id) {
            $courseZipButton.attr('href', 'descargar_diplomas_curso_zip.php?curso_id=' + encodeURIComponent(Number(course.id)) + '&ingenio_id=' + encodeURIComponent(currentIngenioId)).removeAttr('hidden');
        } else {
            $courseZipButton.attr('hidden', true).attr('href', '#');
        }

        // Boton "Descargar listado" (PDF/Excel) de los participantes de este
        // curso: coexiste con el boton de ZIP de diplomas de arriba, igual que
        // el resto del dashboard ya ofrece "Descargar listado" junto a otras
        // acciones. Visible siempre que haya al menos un participante que listar.
        if (participants.length && course.id) {
            var exportBase = 'exportardashboardingenio.php?' + $.param({ingenio_id: currentIngenioId, vista: 'curso_participantes', curso_id: Number(course.id)});
            $courseExportPdf
                .attr('href', exportBase + '&format=pdf')
                .attr('download', '');
            $courseExportExcel
                .attr('href', exportBase + '&format=excel')
                .attr('download', '');
            $courseExportGroup.removeAttr('hidden');
        } else {
            $courseExportGroup.attr('hidden', true);
            $courseExportPdf.attr('href', '#').removeAttr('download');
            $courseExportExcel.attr('href', '#').removeAttr('download');
        }

        $courseLoading.attr('hidden', true);
        $courseError.attr('hidden', true);
        $courseContent.removeAttr('hidden');
    }

    $courseModal.on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var cursoId = trigger ? trigger.getAttribute('data-curso-id') : '';
        if (courseRequest) courseRequest.abort();
        $('#dashboard-ingenio-course-name').text('Participantes del curso');
        $('#dashboard-ingenio-course-subtitle').text('');
        $courseContent.attr('hidden', true);
        $courseError.attr('hidden', true).text('');
        $courseZipButton.attr('hidden', true).attr('href', '#');
        $courseExportGroup.attr('hidden', true);
        $courseExportPdf.attr('href', '#').removeAttr('download');
        $courseExportExcel.attr('href', '#').removeAttr('download');
        $courseLoading.removeAttr('hidden');
        courseRequest = $.ajax({url: 'dashboard_ingenio.php', data: {curso_detalle_id: cursoId, ingenio_id: currentIngenioId}, dataType: 'json', cache: false})
            .done(renderCourseDetail).fail(function (xhr, status) {
                if (status === 'abort') return;
                var message = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'No fue posible cargar los participantes. Intenta nuevamente.';
                $courseLoading.attr('hidden', true);
                $courseContent.attr('hidden', true);
                $courseError.text(message).removeAttr('hidden');
            });
    });

    $courseModal.on('hidden.bs.modal', function () {
        if (courseRequest) courseRequest.abort();
        courseRequest = null;
    });
})(jQuery);
</script>
<script src="js/dashboard_ingenio.js?v=<?php echo filemtime(__DIR__ . '/js/dashboard_ingenio.js'); ?>"></script>
</body>
</html>
