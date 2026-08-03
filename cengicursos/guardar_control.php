<?php
require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_calificador('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$puedeSubirDiploma = cengi_puede_subir_diploma();

$asignacion_id = (int) ($_POST['asignacion_id'] ?? 0);
$asistencia = $_POST['asistencia'] ?? null;
$evaluacion = $_POST['evaluacion'] ?? null;
$posevaluacion = $_POST['posevaluacion'] ?? null;
$diploma = "";

if (
    $puedeSubirDiploma &&
    isset($_FILES['diploma']) &&
    $_FILES['diploma']['error'] === 0
) {
    $extension = strtolower(pathinfo($_FILES['diploma']['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['diploma']['tmp_name']);

    if ($extension === 'pdf' && $mime === 'application/pdf') {
        $nombrePDF = time() . "_" . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $_FILES['diploma']['name']);
        $ruta = "../uploads/diplomas/" . $nombrePDF;

        if (move_uploaded_file($_FILES['diploma']['tmp_name'], $ruta)) {
            $diploma = $ruta;
        }
    }
}

$stmtScope = $db->prepare("
    SELECT
        a.cursos_id,
        p.ingenio_id
    FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id
    WHERE a.id = ?
    LIMIT 1
");
$stmtScope->execute([$asignacion_id]);
$asignacion = $stmtScope->fetch(PDO::FETCH_ASSOC);

if (
    !$asignacion ||
    (
        !cengi_ve_todo_por_rol_o_ingenio() &&
        (int) ($asignacion['ingenio_id'] ?? 0) !== cengi_ingenio_id_actual()
    )
) {
    header("Location: ver_cursos.php");
    exit;
}

$stmtVerificar = $db->prepare("
    SELECT id_control
    FROM control_cursos
    WHERE asignacion_id = ?
");
$stmtVerificar->execute([$asignacion_id]);
$registro = $stmtVerificar->fetch(PDO::FETCH_ASSOC);

if ($registro) {
    if ($puedeSubirDiploma && $diploma !== '') {
        $stmt = $db->prepare("
            UPDATE control_cursos
            SET
                asistencia = ?,
                evaluacion = ?,
                posevaluacion = ?,
                diploma = ?
            WHERE asignacion_id = ?
        ");
        $stmt->execute([
            $asistencia,
            $evaluacion,
            $posevaluacion,
            $diploma,
            $asignacion_id,
        ]);
    } elseif ($puedeGestionar) {
        $stmt = $db->prepare("
            UPDATE control_cursos
            SET
                asistencia = ?,
                evaluacion = ?,
                posevaluacion = ?
            WHERE asignacion_id = ?
        ");
        $stmt->execute([
            $asistencia,
            $evaluacion,
            $posevaluacion,
            $asignacion_id,
        ]);
    } else {
        $stmt = $db->prepare("
            UPDATE control_cursos
            SET
                evaluacion = ?,
                posevaluacion = ?
            WHERE asignacion_id = ?
        ");
        $stmt->execute([
            $evaluacion,
            $posevaluacion,
            $asignacion_id,
        ]);
    }
} else {
    if ($puedeGestionar || $puedeSubirDiploma) {
        $stmt = $db->prepare("
            INSERT INTO control_cursos
            (
                asignacion_id,
                asistencia,
                evaluacion,
                posevaluacion,
                diploma
            )
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $asignacion_id,
            $asistencia,
            $evaluacion,
            $posevaluacion,
            $diploma,
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO control_cursos
            (
                asignacion_id,
                evaluacion,
                posevaluacion,
                diploma
            )
            VALUES (?, ?, ?, '')
        ");
        $stmt->execute([
            $asignacion_id,
            $evaluacion,
            $posevaluacion,
        ]);
    }
}

$stmtCurso = $db->prepare("
    SELECT cursos_id
    FROM asignaciones
    WHERE id = ?
");
$stmtCurso->execute([$asignacion_id]);
$curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
$idcurso = (int) ($curso['cursos_id'] ?? 0);

header("Location: ver_participante_curso.php?id=$idcurso");
exit;
?>
