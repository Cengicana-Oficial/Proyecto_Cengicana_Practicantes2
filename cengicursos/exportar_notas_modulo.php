<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/conexion.php';

cengi_require_calificador('ver_cursos.php');

$db = conectar();

$cursoId = (int) ($_GET['curso_id'] ?? 0);
$moduloId = (int) ($_GET['modulo_id'] ?? 0);

if ($cursoId <= 0 || $moduloId <= 0) {
    http_response_code(400);
    exit('Curso o modulo invalido.');
}

// El modulo debe pertenecer al curso indicado.
$stmtModulo = $db->prepare('SELECT id, nombre FROM curso_modulos WHERE id = ? AND curso_id = ?');
$stmtModulo->execute([$moduloId, $cursoId]);
$modulo = $stmtModulo->fetch(PDO::FETCH_ASSOC);
if (!$modulo) {
    http_response_code(404);
    exit('El modulo no existe o no pertenece a este curso.');
}

// Mismo scope por ingenio que el resto de la vista de participantes: si el
// usuario no ve todo, el curso debe tener al menos un participante de su
// ingenio para que se le permita exportar.
$sqlCurso = 'SELECT c.id FROM cursos c WHERE c.id = ?';
$paramsCurso = [$cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sqlCurso .= ' AND EXISTS (
        SELECT 1 FROM asignaciones ax
        INNER JOIN participantes px ON px.id = ax.participantes_id
        WHERE ax.cursos_id = c.id AND px.ingenio_id = ?
    )';
    $paramsCurso[] = cengi_ingenio_id_actual();
}
$stmtCurso = $db->prepare($sqlCurso);
$stmtCurso->execute($paramsCurso);
if (!$stmtCurso->fetchColumn()) {
    http_response_code(404);
    exit('El curso no esta disponible para este usuario.');
}

$sql = "
    SELECT
        p.cui_participantes,
        p.nombre_participantes,
        ccm.asistencia,
        ccm.evaluacion,
        ccm.posevaluacion
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    LEFT JOIN control_curso_modulos ccm ON ccm.asignacion_id = a.id AND ccm.curso_modulo_id = ?
    WHERE a.cursos_id = ?
";
$params = [$moduloId, $cursoId];
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $sql .= ' AND p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
$sql .= ' ORDER BY p.nombre_participantes';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

function cengi_notas_modulo_valor($valor)
{
    return is_numeric($valor) ? $valor : '';
}

$nombreArchivo = 'notas_modulo_' . $cursoId . '_' . $moduloId . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Pragma: no-cache');
header('Expires: 0');

$salida = fopen('php://output', 'w');
// Mismo orden de columnas por posicion que carga_calificaciones.php ya sabe
// leer (CUI, ASISTENCIA, PRE_EVALUACION, POST_EVALUACION); NOMBRE se agrega
// solo como referencia humana en el indice 4, el parser por indice no la lee.
fputcsv($salida, ['CUI', 'ASISTENCIA', 'PRE_EVALUACION', 'POST_EVALUACION', 'NOMBRE']);
foreach ($filas as $fila) {
    fputcsv($salida, [
        $fila['cui_participantes'],
        cengi_notas_modulo_valor($fila['asistencia']),
        cengi_notas_modulo_valor($fila['evaluacion']),
        cengi_notas_modulo_valor($fila['posevaluacion']),
        $fila['nombre_participantes'],
    ]);
}
fclose($salida);
exit;
