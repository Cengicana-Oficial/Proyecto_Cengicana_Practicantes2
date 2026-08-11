<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';
require_once 'curso_form_helpers.php';

cengi_require_admin('ver_cursos.php');

$db = conectar();
$cursoId = (int) ($_POST['id'] ?? 0);
$stmtActual = $db->prepare('SELECT codigo_curso, actividad_tipo, area_tecnica, instructor_id, ingenio_id, cupo FROM cursos WHERE id = ?');
$stmtActual->execute([$cursoId]);
$actual = $stmtActual->fetch(PDO::FETCH_ASSOC);
if (!$actual) {
    header('Location: ver_cursos.php?error=datos');
    exit;
}

$datos = cengi_curso_form_datos();
if (!array_key_exists('codigo_curso', $_POST)) {
    $datos['codigo'] = (string) $actual['codigo_curso'];
}
if (!array_key_exists('actividad_tipo', $_POST)) {
    $datos['actividad'] = (string) $actual['actividad_tipo'];
}
if (!array_key_exists('area_tecnica', $_POST)) {
    $datos['area'] = (string) $actual['area_tecnica'];
}
if (!array_key_exists('instructor_id', $_POST)) {
    $datos['instructor_id'] = $actual['instructor_id'] !== null ? (int) $actual['instructor_id'] : null;
}
// El formulario ya no pide "Ingenio / institucion": se conserva el valor que el curso
// ya tenia (o el default de Cengicana como red de seguridad si por alguna razon no lo
// tenia, ya que la columna sigue siendo NOT NULL).
$datos['ingenio_id'] = $actual['ingenio_id'] !== null ? (int) $actual['ingenio_id'] : cengi_curso_ingenio_por_defecto($db);
if (!array_key_exists('cupo', $_POST)) {
    $datos['cupo'] = $actual['cupo'] !== null ? (int) $actual['cupo'] : null;
}

if ($cursoId <= 0 || !cengi_curso_form_valido($datos)) {
    header('Location: ver_cursos.php?error=datos');
    exit;
}

try {
    $db->beginTransaction();
    $stmt = $db->prepare('UPDATE cursos SET
        codigo_curso = ?, categoria_curso_id = ?, ingenio_id = ?, instructor_id = ?, actividad_tipo = ?,
        tipo = ?, nombre_cursos = ?, area_tecnica = ?, jornada_cursos = ?, dias = ?, horario = ?,
        cupo = ?, inicio = ?, fin = ?
        WHERE id = ?');
    $stmt->execute([
        $datos['codigo'] !== '' ? $datos['codigo'] : null,
        $datos['categoria_id'],
        $datos['ingenio_id'],
        $datos['instructor_id'],
        $datos['actividad'],
        $datos['modalidad'],
        $datos['nombre'],
        $datos['area'],
        $datos['jornada'],
        $datos['dias'],
        $datos['horario'],
        $datos['cupo'],
        $datos['inicio'],
        $datos['fin'],
        $cursoId,
    ]);

    if (isset($_POST['modulos_present'])) {
        $moduloIds = cengi_curso_guardar_modulos($db, $cursoId, $datos['modulos'], true);
        cengi_curso_asegurar_enlaces_evaluacion($db, $cursoId, $datos['instructor_id'], $datos['modulos'], $moduloIds);
    } else {
        cengi_asegurar_enlace_evaluacion_instructor($db, $cursoId, $datos['instructor_id']);
    }
    $db->commit();
    header('Location: ver_cursos.php?mensaje=actualizado');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('No fue posible actualizar el curso: ' . $e->getMessage());
    header('Location: ver_cursos.php?error=actualizar');
}
exit;
