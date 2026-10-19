<?php
/**
 * "Excel por ingenio": participantes activos de cada ingenio con su delegado, como .xlsx
 * real (PhpSpreadsheet vía cengi_cob_excel). Antes usaba PHPExcel, que en PHP 8 escribe
 * avisos "Deprecated" dentro del archivo y lo dejaba dañado.
 */
require_once __DIR__ . '/revisar_permisos.php';
cengi_require_admin();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/cobranza_helpers.php';
$db = conectar();

$stmt = $db->prepare("SELECT
        i.nombre_ingenios,
        p.cui_participantes,
        p.nombre_participantes,
        p.puesto_participantes,
        p.area_participantes,
        p.correo_participantes,
        p.grado_academico_participantes,
        p.telefono_participantes,
        u.nombre
    FROM cengi_cursos.participantes p
    INNER JOIN cengi_cursos.ingenios i ON (p.ingenio_id = i.id)
    INNER JOIN cengi_cursos.users u ON (p.ingenio_id = u.ingenio_id)
    WHERE p.estado_participantes = 1" . cengi_scope_sql('p', true) . "
    ORDER BY i.id, p.nombre_participantes");
$stmt->execute();

$filas = array_map(static function ($r) {
    return [$r['nombre_ingenios'], $r['nombre_participantes'], $r['cui_participantes'], $r['puesto_participantes'], $r['area_participantes'],
        $r['correo_participantes'], $r['grado_academico_participantes'], $r['telefono_participantes'], $r['nombre']];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

cengi_cob_excel('Participantes por ingenio', count($filas) . ' participante(s) activo(s)', [
    ['Ingenio', 24, 'texto'], ['Participante', 34, 'texto'], ['CUI', 16, 'texto'], ['Puesto', 24, 'texto'], ['Área', 22, 'texto'],
    ['Correo electrónico', 32, 'texto'], ['Grado académico', 20, 'texto'], ['Teléfono', 14, 'texto'], ['Delegado', 28, 'texto'],
], $filas, 'Participantes por ingenio');
