<?php
// Bufferiza toda la salida desde el inicio del script (antes de cualquier
// require/consulta) para que un warning/notice/deprecated accidental de PHP
// (p. ej. algo que dispare error de PHP 8.2, con display_errors=On, que es
// el default de esta imagen de Docker por no traer un php.ini explicito) no
// se imprima como texto plano ANTES de los header() de
// cengi_export_enviar_pdf()/cengi_export_enviar_excel(). Sin este buffer, ese
// texto rompe el Content-Type (el header() falla con "headers already sent")
// y el navegador termina mostrando el PDF/Excel como texto plano en vez de
// descargarlo -- el mismo bug ya documentado y mitigado para el caso de Excel
// en cengi_export_enviar_excel() (classes/export_helpers.php), aplicado aqui
// a todo el script para cubrir tambien la ruta de PDF armado a mano (ver
// exportarparticipantes.php).
ob_start();

require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/classes/export_helpers.php';

// Exportacion PDF/Excel del dashboard de ingenio (participantes y cursos).
// Misma guarda que dashboard_ingenio.php: solo admins o el rol "ingenio" con
// ingenio_id asignado pueden entrar aqui.
cengi_require_dashboard_ingenio('index.php');

$esAdmin = cengi_ve_todo_por_rol_o_ingenio();
$ingenioUsuarioId = cengi_ingenio_id_actual();

$db = conectar();

$ingenioId = (int) ($_GET['ingenio_id'] ?? 0);
if (!$esAdmin) {
    // Un usuario de ingenio solo puede exportar su propio ingenio, sin importar
    // el parametro recibido: nunca se confia en el query string para esto.
    $ingenioId = $ingenioUsuarioId;
}

$vista = strtolower(trim((string) ($_GET['vista'] ?? '')));
$formato = strtolower(trim((string) ($_GET['format'] ?? '')));
$busqueda = trim((string) ($_GET['q'] ?? ''));
// Solo aplica a vista=curso_participantes: listado de participantes de UNA
// edicion de curso especifica (el mismo curso que el usuario ya tiene abierto
// en el modal "Ver participantes" de dashboard_ingenio.php), en vez del
// listado general de todos los participantes del ingenio.
$cursoIdFiltro = (int) ($_GET['curso_id'] ?? 0);

// "csv" se acepta como alias legado del boton "Descargar Excel" (antes
// generaba un CSV crudo vía fputcsv); ahora ambos generan el mismo archivo
// de Excel real, para no romper enlaces/favoritos ya guardados con
// &format=csv.
if ($formato === 'csv') {
    $formato = 'excel';
}

if (!in_array($vista, ['participantes', 'cursos', 'curso_participantes'], true)) {
    http_response_code(400);
    exit('Vista de exportación no válida.');
}
if (!in_array($formato, ['pdf', 'excel'], true)) {
    http_response_code(400);
    exit('Formato de exportación no válido.');
}
if ($vista === 'curso_participantes' && $cursoIdFiltro <= 0) {
    http_response_code(400);
    exit('Falta el curso a exportar.');
}
if ($ingenioId <= 0) {
    http_response_code(404);
    exit('No hay un ingenio disponible para exportar.');
}

$stmtIngenio = $db->prepare('SELECT id, nombre_ingenios FROM ingenios WHERE id = ? LIMIT 1');
$stmtIngenio->execute([$ingenioId]);
$ingenio = $stmtIngenio->fetch(PDO::FETCH_ASSOC);
if (!$ingenio) {
    http_response_code(404);
    exit('El ingenio no existe o no está disponible.');
}

$cursoFiltro = null;
if ($vista === 'curso_participantes') {
    $stmtCursoFiltro = $db->prepare('SELECT id, nombre_cursos FROM cursos WHERE id = ? LIMIT 1');
    $stmtCursoFiltro->execute([$cursoIdFiltro]);
    $cursoFiltro = $stmtCursoFiltro->fetch(PDO::FETCH_ASSOC);
    if (!$cursoFiltro) {
        http_response_code(404);
        exit('El curso no existe.');
    }
}

function cengi_dbi_export_numero($valor, $sufijo = '')
{
    if (!is_numeric($valor)) {
        return '';
    }
    $numero = rtrim(rtrim(number_format((float) $valor, 1, '.', ''), '0'), '.');
    return $numero . $sufijo;
}

function cengi_dbi_export_fecha($valor)
{
    $valor = trim((string) ($valor ?? ''));
    if ($valor === '' || $valor === '0000-00-00') {
        return '—';
    }
    $fecha = strtotime($valor);
    return $fecha ? date('d/m/Y', $fecha) : '—';
}

function cengi_dbi_estado_curso_export($inicio, $fin)
{
    $hoy = date('Y-m-d');
    if ($fin && $fin !== '0000-00-00' && $fin < $hoy) {
        return 'Finalizado';
    }
    if ($inicio && $inicio !== '0000-00-00' && $inicio > $hoy) {
        return 'Planificación';
    }
    return 'Activo';
}

// ---------------------------------------------------------------------
// Datos segun la vista solicitada.
// ---------------------------------------------------------------------
$titulo = '';
$columnasPdf = [];
$encabezadoExcel = [];
$anchosExcel = [];
$filas = [];
$nombreArchivoBase = '';

if ($vista === 'participantes') {
    $titulo = 'Participantes de ' . $ingenio['nombre_ingenios'];
    $nombreArchivoBase = 'participantes_ingenio_' . $ingenioId;

    $condiciones = ['p.ingenio_id = ?'];
    $params = [$ingenioId];
    if ($busqueda !== '') {
        $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR p.area_participantes LIKE ? OR p.correo_participantes LIKE ? OR p.grado_academico_participantes LIKE ? OR p.telefono_participantes LIKE ?)';
        $termino = '%' . $busqueda . '%';
        array_push($params, $termino, $termino, $termino, $termino, $termino, $termino);
    }

    $stmt = $db->prepare("
        SELECT p.nombre_participantes, p.cui_participantes, p.puesto_participantes, p.area_participantes,
            p.correo_participantes, p.grado_academico_participantes, p.telefono_participantes,
            COUNT(DISTINCT CASE WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN a.id END) AS cursos_completados,
            COUNT(DISTINCT CASE WHEN c.fin IS NULL OR c.fin >= CURDATE() THEN a.id END) AS cursos_activos,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS evaluacion_promedio,
            COUNT(DISTINCT d.id) AS diplomas,
            MAX(c.inicio) AS ultima_capacitacion
        FROM participantes p
        LEFT JOIN asignaciones a ON a.participantes_id = p.id AND a.estado_asignaciones = 1
        LEFT JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE " . implode(' AND ', $condiciones) . "
        GROUP BY p.id, p.nombre_participantes, p.cui_participantes, p.puesto_participantes, p.area_participantes,
            p.correo_participantes, p.grado_academico_participantes, p.telefono_participantes
        ORDER BY p.nombre_participantes
    ");
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $encabezadoExcel = ['Participante', 'CUI', 'Correo electrónico', 'Grado académico', 'Teléfono', 'Puesto', 'Área', 'Cursos completados', 'Cursos activos', 'Evaluación promedio', 'Diplomas', 'Última capacitación'];
    $anchosExcel = [28, 16, 30, 22, 16, 20, 20, 16, 14, 18, 12, 18];
    $columnasPdf = [
        ['Participante', 95, 19], ['CUI', 65, 14], ['Correo', 95, 20], ['Grado', 70, 14],
        ['Teléfono', 55, 11], ['Puesto', 55, 11], ['Área', 55, 11], ['Complet.', 40, 7],
        ['Activos', 35, 6], ['Eval.', 45, 8], ['Diplomas', 35, 6], ['Última', 55, 10],
    ];

    foreach ($registros as $r) {
        $filas[] = [
            $r['nombre_participantes'], $r['cui_participantes'], $r['correo_participantes'],
            $r['grado_academico_participantes'], $r['telefono_participantes'], $r['puesto_participantes'], $r['area_participantes'],
            (string) (int) $r['cursos_completados'], (string) (int) $r['cursos_activos'],
            cengi_dbi_export_numero($r['evaluacion_promedio'], ' pts'), (string) (int) $r['diplomas'],
            cengi_dbi_export_fecha($r['ultima_capacitacion']),
        ];
    }
} elseif ($vista === 'curso_participantes') {
    // Listado de los participantes de ESTE ingenio inscritos en la edicion de
    // curso $cursoIdFiltro: mismo alcance/COALESCE que la consulta AJAX
    // "curso_detalle_id" del modal "Ver participantes" de dashboard_ingenio.php,
    // para que el PDF/Excel coincida con lo que el usuario ve en el modal.
    $titulo = 'Participantes de ' . $cursoFiltro['nombre_cursos'] . ' — ' . $ingenio['nombre_ingenios'];
    $nombreArchivoBase = 'participantes_curso_' . $cursoIdFiltro . '_ingenio_' . $ingenioId;

    $stmt = $db->prepare("
        SELECT p.nombre_participantes, p.cui_participantes, p.puesto_participantes,
            a.estado_asignaciones, cc.asistencia, cc.sesiones_asistidas, cc.evaluacion, cc.posevaluacion,
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS diploma
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (
            SELECT asignacion_id, MAX(pdf_path) AS pdf_path
            FROM diplomas WHERE tipo = 'curso' GROUP BY asignacion_id
        ) d ON d.asignacion_id = a.id
        WHERE a.cursos_id = ? AND p.ingenio_id = ?
        ORDER BY p.nombre_participantes
    ");
    $stmt->execute([$cursoIdFiltro, $ingenioId]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $encabezadoExcel = ['Participante', 'CUI', 'Puesto', 'Estado', 'Asistencia', 'Sesiones asistidas', 'Pre-evaluación', 'Post-evaluación', 'Diploma'];
    $anchosExcel = [30, 16, 22, 12, 12, 16, 16, 16, 10];
    $columnasPdf = [
        ['Participante', 130, 28], ['CUI', 75, 16], ['Puesto', 90, 18], ['Estado', 55, 10],
        ['Asist.', 45, 8], ['Sesiones', 50, 9], ['Pre-eval.', 50, 9], ['Post-eval.', 50, 9], ['Diploma', 45, 9],
    ];

    foreach ($registros as $r) {
        $filas[] = [
            $r['nombre_participantes'], $r['cui_participantes'], $r['puesto_participantes'],
            (int) $r['estado_asignaciones'] === 1 ? 'Activo' : 'Inactivo',
            cengi_dbi_export_numero($r['asistencia'], '%'), cengi_dbi_export_numero($r['sesiones_asistidas']),
            cengi_dbi_export_numero($r['evaluacion'], ' pts'),
            cengi_dbi_export_numero($r['posevaluacion'], ' pts'), $r['diploma'] ? 'Sí' : 'No',
        ];
    }
} else {
    $titulo = 'Cursos de ' . $ingenio['nombre_ingenios'];
    $nombreArchivoBase = 'cursos_ingenio_' . $ingenioId;

    $stmt = $db->prepare("
        SELECT
            c.id, c.codigo_curso, c.nombre_cursos, ca.descripcion_categorias_cursos AS categoria,
            c.tipo AS modalidad, c.inicio, c.fin,
            COUNT(DISTINCT a.id) AS inscritos,
            AVG(CASE WHEN cc.asistencia REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.asistencia AS DECIMAL(6,2)) END) AS asistencia_prom,
            AVG(cc.sesiones_asistidas) AS sesiones_asistidas_prom,
            AVG(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?\$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS eval_prom
        FROM cursos c
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        INNER JOIN asignaciones a ON a.cursos_id = c.id
        INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        GROUP BY c.id, c.codigo_curso, c.nombre_cursos, ca.descripcion_categorias_cursos, c.tipo, c.inicio, c.fin
        ORDER BY c.inicio DESC, c.nombre_cursos
    ");
    $stmt->execute([$ingenioId]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $encabezadoExcel = ['Código', 'Curso', 'Categoría', 'Modalidad', 'Inicio', 'Fin', 'Inscritos', 'Asistencia', 'Sesiones asistidas', 'Evaluación final', 'Estado'];
    $anchosExcel = [14, 32, 20, 16, 12, 12, 10, 12, 16, 16, 14];
    $columnasPdf = [
        ['Código', 50, 10], ['Curso', 135, 30], ['Categoría', 85, 17], ['Modalidad', 65, 13],
        ['Inicio', 50, 10], ['Fin', 50, 10], ['Inscritos', 45, 8], ['Asist.', 42, 8], ['Sesiones', 48, 9],
        ['Eval.', 42, 8], ['Estado', 55, 11],
    ];

    foreach ($registros as $r) {
        $codigo = $r['codigo_curso'] !== null && $r['codigo_curso'] !== '' ? $r['codigo_curso'] : ('CEN-' . str_pad((string) $r['id'], 3, '0', STR_PAD_LEFT));
        $filas[] = [
            $codigo, $r['nombre_cursos'], $r['categoria'], $r['modalidad'] ?: 'Sin definir',
            cengi_dbi_export_fecha($r['inicio']), cengi_dbi_export_fecha($r['fin']), (string) (int) $r['inscritos'],
            cengi_dbi_export_numero($r['asistencia_prom'], '%'), cengi_dbi_export_numero($r['sesiones_asistidas_prom']),
            cengi_dbi_export_numero($r['eval_prom'], ' pts'),
            cengi_dbi_estado_curso_export($r['inicio'], $r['fin']),
        ];
    }
}

if ($formato === 'excel') {
    cengi_export_enviar_excel($encabezadoExcel, $filas, $titulo, $nombreArchivoBase, $anchosExcel);
}

// ---------------------------------------------------------------------
// PDF generado a mano (sin dependencias externas), mismo enfoque que
// exportarparticipantes.php pero generalizado a columnas variables para
// poder reutilizarlo tanto en la vista de participantes como en la de cursos.
// Las funciones de bajo nivel (codificar/recortar/ensamblar el PDF) viven en
// classes/export_helpers.php, compartidas con exportarparticipantes.php.
// ---------------------------------------------------------------------
function cengi_dbi_pdf_pagina(array $filas, array $columnas, $titulo, $subtitulo, $pagina, $total)
{
    $x = 39;
    $y = 506;
    $altoEncabezado = 20;
    $altoFila = 18;
    $ancho = array_sum(array_column($columnas, 1));

    $c = "0.10 0.28 0.16 rg\n" . cengi_export_pdf_texto($x, 558, $titulo, 16, true);
    $c .= "0.15 0.15 0.15 rg\n" . cengi_export_pdf_texto($x, 538, cengi_export_pdf_recortar($subtitulo, 110), 9);
    $c .= cengi_export_pdf_texto(744, 558, 'Pág. ' . $pagina . '/' . $total, 8);

    $c .= "0.88 0.93 0.89 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $y, $ancho, $altoEncabezado);
    foreach ($filas as $i => $fila) {
        $filaY = $y - (($i + 1) * $altoFila);
        if ($i % 2 === 1) {
            $c .= "0.97 0.98 0.97 rg\n" . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $filaY, $ancho, $altoFila);
        }
        $cursor = $x;
        foreach ($columnas as $j => $columna) {
            $valor = $fila[$j] ?? '';
            $c .= "0.12 0.12 0.12 rg\n" . cengi_export_pdf_texto($cursor + 4, $filaY + 6, cengi_export_pdf_recortar($valor, $columna[2]), 7);
            $cursor += $columna[1];
        }
    }

    $fondo = $y - count($filas) * $altoFila;
    $c .= "0.65 0.70 0.66 RG 0.45 w\n" . sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $fondo, $ancho, $altoEncabezado + count($filas) * $altoFila);
    $cursor = $x;
    foreach ($columnas as $columna) {
        $c .= "0.10 0.28 0.16 rg\n" . cengi_export_pdf_texto($cursor + 4, $y + 7, $columna[0], 7.5, true);
        $cursor += $columna[1];
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $cursor, $fondo, $cursor, $y + $altoEncabezado);
    }
    for ($i = 1; $i <= count($filas); $i++) {
        $lineaY = $y - $i * $altoFila;
        $c .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x, $lineaY, $x + $ancho, $lineaY);
    }
    if (!$filas) {
        $c .= "0.35 0.35 0.35 rg\n" . cengi_export_pdf_texto($x + 8, $y - 15, 'No se encontraron registros para este ingenio.', 8);
    }
    $c .= "0.35 0.35 0.35 rg\n" . cengi_export_pdf_texto($x, 24, 'Generado: ' . date('d/m/Y H:i'), 7);
    return $c;
}

if ($vista === 'participantes' && $busqueda !== '') {
    $subtitulo = 'Búsqueda: ' . $busqueda;
} elseif ($vista === 'curso_participantes') {
    $subtitulo = 'Curso: ' . $cursoFiltro['nombre_cursos'] . ' · Ingenio: ' . $ingenio['nombre_ingenios'];
} else {
    $subtitulo = 'Ingenio: ' . $ingenio['nombre_ingenios'];
}
$grupos = $filas ? array_chunk($filas, 24) : [[]];
$paginas = [];
foreach ($grupos as $indice => $grupo) {
    $paginas[] = cengi_dbi_pdf_pagina($grupo, $columnasPdf, $titulo, $subtitulo, $indice + 1, count($grupos));
}
$pdf = cengi_export_pdf_crear($paginas);
cengi_export_enviar_pdf($pdf, $nombreArchivoBase . '.pdf');
