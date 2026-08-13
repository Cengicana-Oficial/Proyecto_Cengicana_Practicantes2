<?php
// Descarga en un solo ZIP todos los diplomas disponibles de un curso, acotados
// al ingenio del usuario actual (o al ingenio elegido, solo si es admin).
// Mismo patron de resolucion de $ingenioId que exportardashboardingenio.php:
// para un usuario de ingenio, el query string nunca decide el ingenio real.
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';

cengi_require_dashboard_ingenio('index.php');

$esAdmin = cengi_ve_todo_por_rol_o_ingenio();
$ingenioUsuarioId = cengi_ingenio_id_actual();

$db = conectar();

$cursoId = (int) ($_GET['curso_id'] ?? 0);
$ingenioId = (int) ($_GET['ingenio_id'] ?? 0);
if (!$esAdmin) {
    // Un usuario de ingenio solo puede exportar su propio ingenio, sin importar
    // el parametro recibido: nunca se confia en el query string para esto.
    $ingenioId = $ingenioUsuarioId;
}

if ($cursoId <= 0) {
    http_response_code(400);
    exit('Solicitud invalida: falta curso_id.');
}
if ($ingenioId <= 0) {
    http_response_code(404);
    exit('No hay un ingenio disponible para exportar.');
}

$stmtCurso = $db->prepare('SELECT id, nombre_cursos, YEAR(COALESCE(inicio, creado)) AS anio FROM cursos WHERE id = ? LIMIT 1');
$stmtCurso->execute([$cursoId]);
$curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
if (!$curso) {
    http_response_code(404);
    exit('El curso no existe.');
}

$stmtIngenio = $db->prepare('SELECT id, nombre_ingenios FROM ingenios WHERE id = ? LIMIT 1');
$stmtIngenio->execute([$ingenioId]);
$ingenio = $stmtIngenio->fetch(PDO::FETCH_ASSOC);
if (!$ingenio) {
    http_response_code(404);
    exit('El ingenio no existe o no está disponible.');
}

// Mismo alcance/COALESCE que la query AJAX "curso_detalle_id" ya existente en
// dashboard_ingenio.php: participantes de este curso, de este ingenio, con su
// diploma resuelto entre el mecanismo nuevo (diplomas) y el legado
// (control_cursos.diploma).
$stmt = $db->prepare("
    SELECT
        p.nombre_participantes,
        COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS pdf_path
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id AND p.ingenio_id = ?
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    LEFT JOIN (
        SELECT asignacion_id, MAX(pdf_path) AS pdf_path
        FROM diplomas
        WHERE tipo = 'curso'
        GROUP BY asignacion_id
    ) d ON d.asignacion_id = a.id
    WHERE a.cursos_id = ?
    ORDER BY p.nombre_participantes
");
$stmt->execute([$ingenioId, $cursoId]);
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Solo se empaquetan los participantes cuyo archivo de diploma exista
// realmente en disco (mismo criterio is_file() que ya usa el resto del modulo,
// p. ej. instructores.php para el CV). Los diplomas legados guardados como URL
// externa completa (ver cengi_normalizar_url_archivo()) tampoco se pueden
// empaquetar: no hay un archivo local que agregar al ZIP.
$archivosParaZip = [];
foreach ($participantes as $participante) {
    $urlNormalizada = cengi_normalizar_url_archivo($participante['pdf_path'] ?? '');
    if ($urlNormalizada === '' || preg_match('#^https?://#i', $urlNormalizada)) {
        continue;
    }

    $rutaAbsoluta = __DIR__ . '/..' . $urlNormalizada;
    if (!is_file($rutaAbsoluta)) {
        continue;
    }

    $archivosParaZip[] = [
        'ruta' => $rutaAbsoluta,
        'nombre' => cengi_diploma_nombre_archivo($curso['nombre_cursos'], $curso['anio'], $participante['nombre_participantes']),
    ];
}

if (!$archivosParaZip) {
    http_response_code(404);
    exit('Todavía no hay diplomas disponibles para descargar en este curso, para tu ingenio.');
}

// Desambigua nombres duplicados (p. ej. participantes homónimos que generan el
// mismo nombre de archivo) agregando un sufijo numérico antes de la extensión.
$nombresUsados = [];
foreach ($archivosParaZip as &$archivo) {
    $nombreBase = $archivo['nombre'];
    $nombreFinal = $nombreBase;
    $contador = 2;
    while (isset($nombresUsados[$nombreFinal])) {
        $posicionExtension = strrpos($nombreBase, '.');
        $sinExtension = $posicionExtension !== false ? substr($nombreBase, 0, $posicionExtension) : $nombreBase;
        $extension = $posicionExtension !== false ? substr($nombreBase, $posicionExtension) : '';
        $nombreFinal = $sinExtension . '_' . $contador . $extension;
        $contador++;
    }
    $nombresUsados[$nombreFinal] = true;
    $archivo['nombre'] = $nombreFinal;
}
unset($archivo);

$archivoTemporal = tempnam(sys_get_temp_dir(), 'cengi_dip_');
if ($archivoTemporal === false) {
    http_response_code(500);
    exit('No fue posible preparar el archivo ZIP.');
}

$zip = new ZipArchive();
if ($zip->open($archivoTemporal, ZipArchive::OVERWRITE) !== true) {
    @unlink($archivoTemporal);
    http_response_code(500);
    exit('No fue posible generar el archivo ZIP.');
}
foreach ($archivosParaZip as $archivo) {
    $zip->addFile($archivo['ruta'], $archivo['nombre']);
}
$zip->close();

$slugCurso = cengi_diploma_sanitizar_segmento($curso['nombre_cursos']) ?: 'curso';
$slugIngenio = cengi_diploma_sanitizar_segmento($ingenio['nombre_ingenios']) ?: 'ingenio';
$nombreZip = 'diplomas_' . $slugCurso . '_' . $slugIngenio . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nombreZip . '"');
header('Content-Length: ' . filesize($archivoTemporal));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($archivoTemporal);
@unlink($archivoTemporal);
exit;
