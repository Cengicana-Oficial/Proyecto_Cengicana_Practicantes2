<?php
require_once "revisar_permisos.php";
require_once "conexion.php";

cengi_require_calificador('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$puedeSubirDiploma = cengi_puede_subir_diploma();

if (trim((string) ($_POST['accion'] ?? '')) === 'guardar_general') {
    $cursoId = (int) ($_POST['curso_id'] ?? 0);
    $registros = $_POST['registros'] ?? [];

    if ($cursoId <= 0 || !is_array($registros)) {
        header("Location: ver_cursos.php");
        exit;
    }

    $normalizarNota = static function ($valor) {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        if (!is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;
        return ($numero >= 0 && $numero <= 100) ? $numero : null;
    };

    $stmtScopeLote = $db->prepare("
        SELECT a.cursos_id, p.ingenio_id
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmtVerificarLote = $db->prepare("
        SELECT id_control
        FROM control_cursos
        WHERE asignacion_id = ?
    ");

    $db->beginTransaction();

    try {
        foreach ($registros as $asignacionId => $valores) {
            $asignacionId = (int) $asignacionId;
            if ($asignacionId <= 0 || !is_array($valores)) {
                continue;
            }

            $stmtScopeLote->execute([$asignacionId]);
            $asignacion = $stmtScopeLote->fetch(PDO::FETCH_ASSOC);
            if (
                !$asignacion ||
                (int) $asignacion['cursos_id'] !== $cursoId ||
                (
                    !cengi_ve_todo_por_rol_o_ingenio() &&
                    (int) ($asignacion['ingenio_id'] ?? 0) !== cengi_ingenio_id_actual()
                )
            ) {
                continue;
            }

            $asistencia = $normalizarNota($valores['asistencia'] ?? null);
            $evaluacion = $normalizarNota($valores['evaluacion'] ?? null);
            $posevaluacion = $normalizarNota($valores['posevaluacion'] ?? null);
            $diploma = '';

            if (
                $puedeSubirDiploma &&
                isset($_FILES['diplomas']['error'][$asignacionId]) &&
                (int) $_FILES['diplomas']['error'][$asignacionId] === UPLOAD_ERR_OK
            ) {
                $nombreOriginal = (string) $_FILES['diplomas']['name'][$asignacionId];
                $archivoTemporal = (string) $_FILES['diplomas']['tmp_name'][$asignacionId];
                $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivoTemporal);

                if ($extension === 'pdf' && $mime === 'application/pdf') {
                    $nombrePDF = time() . "_{$asignacionId}_" . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $nombreOriginal);
                    $ruta = "../uploads/diplomas/" . $nombrePDF;

                    if (move_uploaded_file($archivoTemporal, $ruta)) {
                        $diploma = $ruta;
                    }
                }
            }

            $stmtVerificarLote->execute([$asignacionId]);
            $existe = (bool) $stmtVerificarLote->fetch(PDO::FETCH_ASSOC);

            if ($existe && $puedeGestionar && $diploma !== '') {
                $stmt = $db->prepare("
                    UPDATE control_cursos
                    SET asistencia = ?, evaluacion = ?, posevaluacion = ?, diploma = ?
                    WHERE asignacion_id = ?
                ");
                $stmt->execute([$asistencia, $evaluacion, $posevaluacion, $diploma, $asignacionId]);
            } elseif ($existe && $puedeGestionar) {
                $stmt = $db->prepare("
                    UPDATE control_cursos
                    SET asistencia = ?, evaluacion = ?, posevaluacion = ?
                    WHERE asignacion_id = ?
                ");
                $stmt->execute([$asistencia, $evaluacion, $posevaluacion, $asignacionId]);
            } elseif ($existe && $diploma !== '') {
                $stmt = $db->prepare("
                    UPDATE control_cursos
                    SET evaluacion = ?, posevaluacion = ?, diploma = ?
                    WHERE asignacion_id = ?
                ");
                $stmt->execute([$evaluacion, $posevaluacion, $diploma, $asignacionId]);
            } elseif ($existe) {
                $stmt = $db->prepare("
                    UPDATE control_cursos
                    SET evaluacion = ?, posevaluacion = ?
                    WHERE asignacion_id = ?
                ");
                $stmt->execute([$evaluacion, $posevaluacion, $asignacionId]);
            } elseif ($puedeGestionar) {
                $stmt = $db->prepare("
                    INSERT INTO control_cursos
                        (asignacion_id, asistencia, evaluacion, posevaluacion, diploma)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$asignacionId, $asistencia, $evaluacion, $posevaluacion, $diploma]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO control_cursos
                        (asignacion_id, evaluacion, posevaluacion, diploma)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$asignacionId, $evaluacion, $posevaluacion, $diploma]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    header("Location: ver_participante_curso.php?id=$cursoId");
    exit;
}

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

if (trim((string) ($_POST['return_to'] ?? '')) === 'participantes') {
    header("Location: participantes.php?curso_id=$idcurso&mensaje=calificacion");
} else {
    header("Location: ver_participante_curso.php?id=$idcurso");
}
exit;
?>
