<?php
require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "curso_form_helpers.php";

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

    // Notas por modulo (co-ensenanza): si viene un modulo_id valido para este curso,
    // el guardado se hace contra control_curso_modulos (una fila por asignacion+modulo)
    // en vez de control_cursos, que se deja intacta como resumen del curso completo.
    $moduloId = (int) ($_POST['modulo_id'] ?? 0);
    if ($moduloId > 0) {
        $stmtModuloValido = $db->prepare("SELECT id FROM curso_modulos WHERE id = ? AND curso_id = ?");
        $stmtModuloValido->execute([$moduloId, $cursoId]);
        if (!$stmtModuloValido->fetch()) {
            $moduloId = 0; // modulo inexistente o de otro curso: se ignora, cae a control_cursos
        }
    }

    // Misma regla de visibilidad de Pre/Pos-Evaluacion que en ver_participante_curso.php
    // (cengi_curso_pre_post_visibles), para decidir si quien solo califica (sin permiso
    // de gestion) puede guardar Asistencia: si esta vista no muestra ni pre ni post, no
    // tiene otro campo editable, asi que se le permite tambien guardar asistencia.
    $stmtModulosCurso = $db->prepare("SELECT * FROM curso_modulos WHERE curso_id = ? ORDER BY orden");
    $stmtModulosCurso->execute([$cursoId]);
    $modulosCurso = $stmtModulosCurso->fetchAll(PDO::FETCH_ASSOC);
    $prePostVisibles = cengi_curso_pre_post_visibles($modulosCurso, $moduloId);
    $puedeGuardarAsistencia = $puedeGestionar || (!$prePostVisibles['pre'] && !$prePostVisibles['post']);

    // El segundo dropdown (instructor que impartio/califico el modulo) solo lo ve y lo
    // puede fijar el super admin; para el resto de roles que califican, este guardado no
    // debe tocar esa columna en absoluto (si no, un guardado posterior de un formador
    // borraria la trazabilidad que ya habia fijado el super admin).
    $esSuperadminActual = cengi_es_superadmin();
    $instructorModuloId = null;
    if ($moduloId > 0 && $esSuperadminActual) {
        $instructorModuloIdRaw = trim((string) ($_POST['instructor_modulo_id'] ?? ''));
        if ($instructorModuloIdRaw !== '' && ctype_digit($instructorModuloIdRaw)) {
            $instructorModuloId = (int) $instructorModuloIdRaw;
        }
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
    $stmtVerificarModulo = $db->prepare("
        SELECT id
        FROM control_curso_modulos
        WHERE asignacion_id = ? AND curso_modulo_id = ?
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

            if ($moduloId > 0) {
                // Notas por modulo: sin diploma (el diploma sigue siendo a nivel de curso
                // completo, en control_cursos).
                $stmtVerificarModulo->execute([$asignacionId, $moduloId]);
                $existeModulo = (bool) $stmtVerificarModulo->fetch(PDO::FETCH_ASSOC);

                if ($existeModulo && $puedeGuardarAsistencia) {
                    $stmt = $db->prepare("
                        UPDATE control_curso_modulos
                        SET asistencia = ?, evaluacion = ?, posevaluacion = ?
                        WHERE asignacion_id = ? AND curso_modulo_id = ?
                    ");
                    $stmt->execute([$asistencia, $evaluacion, $posevaluacion, $asignacionId, $moduloId]);
                } elseif ($existeModulo) {
                    $stmt = $db->prepare("
                        UPDATE control_curso_modulos
                        SET evaluacion = ?, posevaluacion = ?
                        WHERE asignacion_id = ? AND curso_modulo_id = ?
                    ");
                    $stmt->execute([$evaluacion, $posevaluacion, $asignacionId, $moduloId]);
                } elseif ($puedeGuardarAsistencia) {
                    $stmt = $db->prepare("
                        INSERT INTO control_curso_modulos
                            (asignacion_id, curso_modulo_id, asistencia, evaluacion, posevaluacion)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$asignacionId, $moduloId, $asistencia, $evaluacion, $posevaluacion]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO control_curso_modulos
                            (asignacion_id, curso_modulo_id, evaluacion, posevaluacion)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$asignacionId, $moduloId, $evaluacion, $posevaluacion]);
                }

                // La trazabilidad de que instructor impartio/califico el modulo solo la
                // fija el super admin (incluye limpiarla explicitamente si elige "Sin
                // registrar"); el resto de roles que califican no la tocan.
                if ($esSuperadminActual) {
                    $stmt = $db->prepare("
                        UPDATE control_curso_modulos
                        SET registrado_por_instructor_id = ?
                        WHERE asignacion_id = ? AND curso_modulo_id = ?
                    ");
                    $stmt->execute([$instructorModuloId, $asignacionId, $moduloId]);
                }

                // Recalcular el resumen del curso completo (control_cursos) como
                // promedio de las notas de TODOS los modulos del curso que este
                // participante ya tiene cargadas en control_curso_modulos. Misma
                // logica que usa la carga masiva por modulo (extraida a
                // curso_form_helpers.php para no duplicarla).
                cengi_recalcular_resumen_curso($db, $asignacionId, $cursoId, $puedeGuardarAsistencia);

                continue;
            }

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

            if ($existe && $puedeGuardarAsistencia && $diploma !== '') {
                $stmt = $db->prepare("
                    UPDATE control_cursos
                    SET asistencia = ?, evaluacion = ?, posevaluacion = ?, diploma = ?
                    WHERE asignacion_id = ?
                ");
                $stmt->execute([$asistencia, $evaluacion, $posevaluacion, $diploma, $asignacionId]);
            } elseif ($existe && $puedeGuardarAsistencia) {
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
            } elseif ($puedeGuardarAsistencia) {
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

    header("Location: ver_participante_curso.php?id=$cursoId" . ($moduloId > 0 ? "&modulo_id={$moduloId}" : ''));
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
