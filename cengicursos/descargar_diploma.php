<?php
ob_start();
// Endpoint de descarga de un diploma/constancia individual, con el nombre de
// archivo formateado por cengi_diploma_nombre_archivo() (conexion.php), en vez
// de servir el PDF crudo por su URL/nombre de archivo en disco tal cual.
//
// No usa menu.php porque no renderiza UI: es solo un endpoint que responde con
// el PDF (o un error), igual que exportardashboardingenio.php.
//
// Recibe uno de los dos parametros (nunca ambos a la vez):
//   ?asignacion_id=N          -> diploma de curso (tabla diplomas + legado control_cursos.diploma)
//   ?evento_participante_id=N -> constancia de evento (tabla diplomas, tipo evento)
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/classes/export_helpers.php';

$db = conectar();

$asignacionId = (int) ($_GET['asignacion_id'] ?? 0);
$eventoParticipanteId = (int) ($_GET['evento_participante_id'] ?? 0);

if ($asignacionId <= 0 && $eventoParticipanteId <= 0) {
    http_response_code(400);
    exit('Solicitud invalida: falta asignacion_id o evento_participante_id.');
}

$pdfPath = '';
$nombreArchivo = '';

if ($asignacionId > 0) {
    // Mismo COALESCE que el resto del modulo (dashboard_ingenio.php,
    // directorio_participantes.php, diplomas.php): el diploma "nuevo"
    // (tabla diplomas) tiene prioridad sobre el legado (control_cursos.diploma).
    $stmt = $db->prepare("
        SELECT
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS pdf_path,
            c.nombre_cursos,
            YEAR(COALESCE(c.inicio, c.creado)) AS anio,
            p.nombre_participantes,
            p.ingenio_id
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        INNER JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (
            SELECT asignacion_id, MAX(pdf_path) AS pdf_path
            FROM diplomas
            WHERE tipo = 'curso'
            GROUP BY asignacion_id
        ) d ON d.asignacion_id = a.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$asignacionId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila) {
        http_response_code(404);
        exit('El diploma solicitado no existe.');
    }

    // Autorizacion: cubre tanto los contextos "de gestion" (diplomas.php,
    // participantes.php, ver_participante_curso.php -- via cengi_puede_ver_diplomas())
    // como el rol "Administrador de ingenio" en dashboard_ingenio.php /
    // directorio_participantes.php, que no necesariamente tiene el permiso
    // ver_diplomas_cengi pero si debe poder bajar los diplomas de participantes
    // de SU PROPIO ingenio.
    $ingenioActual = cengi_ingenio_id_actual();
    $autorizado = cengi_puede_ver_diplomas()
        || (cengi_puede_ver_dashboard_ingenio() && $ingenioActual > 0 && (int) $fila['ingenio_id'] === $ingenioActual);

    if (!$autorizado) {
        http_response_code(403);
        exit('No tienes permiso para descargar este diploma.');
    }

    $pdfPath = (string) ($fila['pdf_path'] ?? '');
    $nombreArchivo = cengi_diploma_nombre_archivo($fila['nombre_cursos'], $fila['anio'], $fila['nombre_participantes']);
} else {
    // Constancias de evento: sin scoping por ingenio (los eventos no llevan
    // ingenio_id), solo cengi_puede_ver_diplomas().
    if (!cengi_puede_ver_diplomas()) {
        http_response_code(403);
        exit('No tienes permiso para descargar esta constancia.');
    }

    $stmt = $db->prepare("
        SELECT d.pdf_path, e.nombre AS nombre_evento, YEAR(e.fecha) AS anio, ep.nombre_invitado
        FROM evento_participantes ep
        INNER JOIN eventos e ON e.id = ep.evento_id
        LEFT JOIN diplomas d ON d.tipo = 'evento' AND d.evento_participante_id = ep.id
        WHERE ep.id = ?
        LIMIT 1
    ");
    $stmt->execute([$eventoParticipanteId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila) {
        http_response_code(404);
        exit('La constancia solicitada no existe.');
    }

    $pdfPath = (string) ($fila['pdf_path'] ?? '');
    $nombreArchivo = cengi_diploma_nombre_archivo($fila['nombre_evento'], $fila['anio'], $fila['nombre_invitado']);
}

$urlNormalizada = cengi_normalizar_url_archivo($pdfPath);
if ($urlNormalizada === '') {
    http_response_code(404);
    exit('Este participante todavia no tiene un diploma cargado.');
}

// Caso legado poco comun: el path guardado ya es una URL http(s) externa
// completa (ver cengi_normalizar_url_archivo()). No hay un archivo local que
// leer con readfile() ni forma de forzar el nombre de descarga sobre un
// recurso externo, asi que se redirige tal cual para no romper el enlace.
if (preg_match('#^https?://#i', $urlNormalizada)) {
    header('Location: ' . $urlNormalizada);
    exit;
}

// cengicursos/ y uploads/ son directorios hermanos bajo el DocumentRoot (ver
// cengi_guardar_archivo_subido() en este mismo archivo), asi que la URL
// raiz-absoluta "/uploads/..." se resuelve a filesystem anteponiendo __DIR__/..
$rutaAbsoluta = __DIR__ . '/..' . $urlNormalizada;

if (!is_file($rutaAbsoluta)) {
    http_response_code(404);
    exit('El archivo del diploma no esta disponible.');
}

$pdfBytes = file_get_contents($rutaAbsoluta);
if ($pdfBytes === false) {
    http_response_code(500);
    exit('No se pudo leer el archivo del diploma.');
}
cengi_export_enviar_pdf($pdfBytes, $nombreArchivo);
