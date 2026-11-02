<?php
require_once "revisar_permisos.php";
require_once "conexion.php";
require_once "curso_form_helpers.php";

cengi_require_calificador('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();
$puedeSubirDiploma = cengi_puede_subir_diploma();

// "Sesiones asistidas": numero entero editable manualmente en paralelo a "asistencia"
// (porcentaje), sin relacion automatica entre ambos ni concepto de "total de sesiones".
function cengi_normalizar_sesiones_asistidas($valor)
{
    if ($valor === null || trim((string) $valor) === '') {
        return null;
    }

    if (!is_numeric($valor)) {
        return null;
    }

    $numero = (int) round((float) $valor);
    return ($numero >= 0) ? $numero : null;
}

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
    $soloAsistencia = cengi_curso_solo_asistencia($db, $cursoId);
    if ($soloAsistencia) {
        $moduloId = 0; // curso corto: no hay notas por módulo, solo asistencia del curso
    }
    $prePostVisibles = cengi_curso_pre_post_visibles($modulosCurso, $moduloId, $soloAsistencia);
    $puedeGuardarAsistencia = $puedeGestionar || (!$prePostVisibles['pre'] && !$prePostVisibles['post']);

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
            $sesionesAsistidas = cengi_normalizar_sesiones_asistidas($valores['sesiones_asistidas'] ?? null);
            $evaluacion = $normalizarNota($valores['evaluacion'] ?? null);
            $posevaluacion = $normalizarNota($valores['posevaluacion'] ?? null);

            if ($moduloId > 0) {
                // Notas por modulo: solo Pre/Post. La asistencia es acumulada del curso
                // completo (Teams/Moodle) y el diploma tambien es del curso: ambos viven
                // en control_cursos y se registran en la vista "Todo el curso".
                $stmtVerificarModulo->execute([$asignacionId, $moduloId]);
                if ($stmtVerificarModulo->fetch(PDO::FETCH_ASSOC)) {
                    $db->prepare('UPDATE control_curso_modulos SET evaluacion = ?, posevaluacion = ? WHERE asignacion_id = ? AND curso_modulo_id = ?')
                        ->execute([$evaluacion, $posevaluacion, $asignacionId, $moduloId]);
                } elseif ($evaluacion !== null || $posevaluacion !== null) {
                    $db->prepare('INSERT INTO control_curso_modulos (asignacion_id, curso_modulo_id, evaluacion, posevaluacion) VALUES (?, ?, ?, ?)')
                        ->execute([$asignacionId, $moduloId, $evaluacion, $posevaluacion]);
                } else {
                    continue; // sin nota y sin registro previo: nada que guardar ni recalcular
                }

                // Nota del curso completo = promedio de los modulos ponderado por horas.
                cengi_recalcular_resumen_curso($db, $asignacionId, $cursoId);

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
                    // cengi_guardar_archivo_subido() (conexion.php) usa una ruta de
                    // filesystem absoluta para escribir y devuelve una URL raiz-absoluta
                    // ("/uploads/...") en vez de la ruta relativa fragil que se usaba antes.
                    $rutaGuardada = cengi_guardar_archivo_subido($archivoTemporal, 'diplomas', $nombrePDF);

                    if ($rutaGuardada !== null) {
                        $diploma = $rutaGuardada;
                    }
                }
            }

            // Columnas a guardar en control_cursos. En un curso con modulos la nota del
            // curso (Pre/Post) es calculada desde los modulos y no se edita aqui.
            $campos = [];
            if ($puedeGuardarAsistencia) {
                $campos['asistencia'] = $asistencia;
                $campos['sesiones_asistidas'] = $sesionesAsistidas;
            }
            if (!$modulosCurso) {
                $campos['evaluacion'] = $evaluacion;
                $campos['posevaluacion'] = $posevaluacion;
            }
            if ($diploma !== '') {
                $campos['diploma'] = $diploma;
            }

            $stmtVerificarLote->execute([$asignacionId]);
            $existe = (bool) $stmtVerificarLote->fetch(PDO::FETCH_ASSOC);

            if ($existe && $campos) {
                $db->prepare('UPDATE control_cursos SET ' . implode(', ', array_map(static function ($c) {
                    return "$c = ?";
                }, array_keys($campos))) . ' WHERE asignacion_id = ?')
                    ->execute(array_merge(array_values($campos), [$asignacionId]));
            } elseif (!$existe) {
                $campos += ['diploma' => ''];
                $db->prepare('INSERT INTO control_cursos (asignacion_id, ' . implode(', ', array_keys($campos)) . ') VALUES (?' . str_repeat(', ?', count($campos)) . ')')
                    ->execute(array_merge([$asignacionId], array_values($campos)));
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    header("Location: ver_participante_curso.php?id=$cursoId" . ($moduloId > 0 ? "&modulo_id={$moduloId}" : '') . '&mensaje=calificacion');
    exit;
}

$asignacion_id = (int) ($_POST['asignacion_id'] ?? 0);
$asistencia = $_POST['asistencia'] ?? null;
$sesiones_asistidas = cengi_normalizar_sesiones_asistidas($_POST['sesiones_asistidas'] ?? null);
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
        // cengi_guardar_archivo_subido() (conexion.php) usa una ruta de filesystem
        // absoluta para escribir y devuelve una URL raiz-absoluta ("/uploads/...")
        // en vez de la ruta relativa fragil que se usaba antes.
        $rutaGuardada = cengi_guardar_archivo_subido($_FILES['diploma']['tmp_name'], 'diplomas', $nombrePDF);

        if ($rutaGuardada !== null) {
            $diploma = $rutaGuardada;
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
                sesiones_asistidas = ?,
                evaluacion = ?,
                posevaluacion = ?,
                diploma = ?
            WHERE asignacion_id = ?
        ");
        $stmt->execute([
            $asistencia,
            $sesiones_asistidas,
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
                sesiones_asistidas = ?,
                evaluacion = ?,
                posevaluacion = ?
            WHERE asignacion_id = ?
        ");
        $stmt->execute([
            $asistencia,
            $sesiones_asistidas,
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
                sesiones_asistidas,
                evaluacion,
                posevaluacion,
                diploma
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $asignacion_id,
            $asistencia,
            $sesiones_asistidas,
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

// Curso con modulos: la nota del curso se calcula desde los modulos, asi que se
// restaura aunque el formulario individual haya enviado otros valores.
$stmtTieneModulos = $db->prepare('SELECT COUNT(*) FROM curso_modulos WHERE curso_id = ?');
$stmtTieneModulos->execute([$idcurso]);
if ((int) $stmtTieneModulos->fetchColumn() > 0) {
    cengi_recalcular_resumen_curso($db, $asignacion_id, $idcurso);
}

if (trim((string) ($_POST['return_to'] ?? '')) === 'participantes') {
    header("Location: participantes.php?curso_id=$idcurso&mensaje=calificacion");
} else {
    header("Location: ver_participante_curso.php?id=$idcurso");
}
exit;
?>
