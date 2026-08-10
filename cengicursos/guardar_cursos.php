<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';
require_once 'curso_form_helpers.php';

cengi_require_admin('ver_cursos.php');

$db = conectar();
$datos = cengi_curso_form_datos();
$datos['ingenio_id'] = cengi_curso_ingenio_por_defecto($db);
if (!cengi_curso_form_valido($datos)) {
    header('Location: ver_cursos.php?error=datos');
    exit;
}

try {
    $db->beginTransaction();
    $stmt = $db->prepare('INSERT INTO cursos (
        codigo_curso, categoria_curso_id, ingenio_id, instructor_id, actividad_tipo,
        tipo, nombre_cursos, area_tecnica, jornada_cursos, dias, horario, cupo, inicio, fin, creado
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
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
    ]);
    $cursoId = (int) $db->lastInsertId();

    if ($datos['codigo'] === '') {
        $codigoGenerado = 'CEN-T-' . str_pad((string) $cursoId, 3, '0', STR_PAD_LEFT);
        $stmtCodigo = $db->prepare('UPDATE cursos SET codigo_curso = ? WHERE id = ?');
        $stmtCodigo->execute([$codigoGenerado, $cursoId]);
    }

    $moduloIds = cengi_curso_guardar_modulos($db, $cursoId, $datos['modulos'], false);
    cengi_curso_asegurar_enlaces_evaluacion($db, $cursoId, $datos['instructor_id'], $datos['modulos'], $moduloIds);
    $db->commit();
    header('Location: ver_cursos.php?mensaje=creado');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('No fue posible crear el curso: ' . $e->getMessage());
    header('Location: ver_cursos.php?error=guardar');
}
exit;
