<?php
/**
 * Descarga de sesiones en formato iCalendar (.ics) para importarlas en Outlook, Google o
 * Apple Calendar. ?sesion=ID, ?plan=ID o ?desde=AAAA-MM-DD&hasta=AAAA-MM-DD (con ?plan=ID
 * opcional). No incluye sesiones canceladas. Es una copia para el calendario propio; las
 * invitaciones al instructor y al soporte se envían desde Programa de sesiones.
 */
require_once __DIR__ . '/calendario_ics_helpers.php';
require_once __DIR__ . '/revisar_permisos.php';

cengi_require_admin();

$db = conectar();
$sesionId = (int) ($_GET['sesion'] ?? 0);
$planId = (int) ($_GET['plan'] ?? 0);
$desde = cengi_pp_es_fecha($_GET['desde'] ?? '') ? $_GET['desde'] : null;
$hasta = cengi_pp_es_fecha($_GET['hasta'] ?? '') ? $_GET['hasta'] : null;

if ($sesionId) {
    $sesiones = cengi_ics_sesiones($db, [$sesionId]);
    $nombre = 'sesion-' . $sesionId;
} elseif ($desde && $hasta && $hasta >= $desde && cengi_pp_dias_entre($desde, $hasta) <= 120) {
    $stmt = $db->prepare('SELECT s.id FROM curso_sesiones s WHERE s.fecha BETWEEN ? AND ?' . ($planId ? ' AND s.planificacion_id = ?' : ''));
    $stmt->execute($planId ? [$desde, $hasta, $planId] : [$desde, $hasta]);
    $sesiones = cengi_ics_sesiones($db, $stmt->fetchAll(PDO::FETCH_COLUMN));
    $nombre = 'sesiones-' . $desde . '-al-' . $hasta;
} elseif ($planId) {
    $sesiones = cengi_ics_sesiones($db, [], $planId);
    $nombre = 'curso-' . $planId;
} else {
    http_response_code(400);
    exit('Indica la sesión, el curso o un rango de fechas de hasta 120 días.');
}

$sesiones = array_filter($sesiones, static function ($s) {
    return $s['estado'] !== 'cancelada' && $s['plan_estado'] !== 'cancelado';
});
if (!$sesiones) {
    http_response_code(404);
    exit('No hay sesiones para exportar con esos datos.');
}

$cursos = array_values(array_unique(array_column($sesiones, 'plan_nombre')));
$titulo = count($cursos) === 1 ? $cursos[0] : ($desde && $hasta ? 'Sesiones del ' . cengi_pp_fecha_corta($desde) . ' al ' . cengi_pp_fecha_corta($hasta) : 'Sesiones');
$contenido = cengi_ics_calendario($sesiones, 'PUBLISH', [], $titulo);
cengi_descarga_encabezados('text/calendar; charset=utf-8', 'Sesiones - ' . $titulo, 'ics');
header('Content-Length: ' . strlen($contenido));
echo $contenido;
