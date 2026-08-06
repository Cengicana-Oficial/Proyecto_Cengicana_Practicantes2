<?php

function cengi_curso_form_datos()
{
    $codigo = strtoupper(trim((string) ($_POST['codigo_curso'] ?? '')));
    $actividad = trim((string) ($_POST['actividad_tipo'] ?? 'formacion_academica'));
    if (!in_array($actividad, ['formacion_academica', 'evento_tecnico'], true)) {
        $actividad = 'formacion_academica';
    }

    $cupoTexto = trim((string) ($_POST['cupo'] ?? ''));
    $cupo = $cupoTexto === '' ? null : (int) $cupoTexto;
    $modulos = [];
    $modulosValidos = true;
    foreach ((array) ($_POST['modulos'] ?? []) as $modulo) {
        if (!is_array($modulo)) {
            continue;
        }
        $nombre = trim((string) ($modulo['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }
        $horasTexto = trim((string) ($modulo['horas'] ?? ''));
        if ($horasTexto === '' || !is_numeric($horasTexto)) {
            $modulosValidos = false;
            continue;
        }
        $horas = (float) $horasTexto;
        if ($horas < 0 || $horas > 9999) {
            $modulosValidos = false;
            continue;
        }
        // Instructor(es) del modulo (opcional): un modulo puede tener cero, uno o
        // varios instructores propios. Se normalizan tres entradas del formulario a una
        // sola representacion interna, 'instructores' (array de ids, sin duplicados):
        //   - modulos[key][mismo_principal]: checkbox "mismo instructor que el
        //     principal"; si viene marcado se ignora cualquier seleccion y el modulo
        //     queda con 'instructores' vacio (hereda implicitamente al instructor
        //     principal del curso al momento de mostrarlo/usarlo; ese fallback se
        //     resuelve donde se consume el dato, no se duplica en la BD).
        //   - modulos[key][multi_instructor]: checkbox "tendra mas de un instructor";
        //     si viene marcado se usa el arreglo modulos[key][instructores][], si no se
        //     usa el select unico modulos[key][instructor_id].
        $mismoPrincipal = !empty($modulo['mismo_principal']);
        $multiInstructor = !empty($modulo['multi_instructor']);
        $instructoresModulo = [];
        if (!$mismoPrincipal) {
            if ($multiInstructor) {
                foreach ((array) ($modulo['instructores'] ?? []) as $instructorTexto) {
                    $instructorTexto = trim((string) $instructorTexto);
                    if ($instructorTexto !== '' && ctype_digit($instructorTexto) && (int) $instructorTexto > 0) {
                        $instructoresModulo[(int) $instructorTexto] = true;
                    }
                }
            } else {
                $instructorModuloTexto = trim((string) ($modulo['instructor_id'] ?? ''));
                if ($instructorModuloTexto !== '' && ctype_digit($instructorModuloTexto) && (int) $instructorModuloTexto > 0) {
                    $instructoresModulo[(int) $instructorModuloTexto] = true;
                }
            }
        }
        $modulos[] = [
            'id' => (int) ($modulo['id'] ?? 0),
            'nombre' => $nombre,
            'horas' => $horas,
            'pre' => !empty($modulo['pre']) ? 1 : 0,
            'post' => !empty($modulo['post']) ? 1 : 0,
            'instructores' => array_values(array_map('intval', array_keys($instructoresModulo))),
        ];
    }

    return [
        'categoria_id' => (int) ($_POST['categorias_cursos'] ?? $_POST['categorias'] ?? 0),
        'ingenio_id' => (int) ($_POST['ingenio'] ?? 0),
        'instructor_id' => (int) ($_POST['instructor_id'] ?? 0) ?: null,
        'codigo' => $codigo,
        'actividad' => $actividad,
        'modalidad' => trim((string) ($_POST['tipo'] ?? '')),
        'nombre' => trim((string) ($_POST['nombre_cursos'] ?? '')),
        'area' => trim((string) ($_POST['area_tecnica'] ?? '')),
        'jornada' => trim((string) ($_POST['jornada_cursos'] ?? '')),
        'dias' => trim((string) ($_POST['dias'] ?? '')),
        'horario' => trim((string) ($_POST['horario'] ?? '')),
        'cupo' => $cupo,
        'inicio' => trim((string) ($_POST['inicio'] ?? '')),
        'fin' => trim((string) ($_POST['fin'] ?? '')),
        'modulos' => $modulos,
        'modulos_validos' => $modulosValidos,
    ];
}

/**
 * El formulario de creacion/edicion de curso ya no pide "Ingenio / institucion" (los
 * cursos no son exclusivos de un ingenio: participantes de cualquier institucion se
 * pueden inscribir). La columna cursos.ingenio_id sigue existiendo y sigue siendo
 * NOT NULL, asi que se completa automaticamente con Cengicana (la institucion que
 * organiza los cursos) en vez de pedirselo a quien crea el curso.
 */
function cengi_curso_ingenio_por_defecto(PDO $db)
{
    $id = $db->query("SELECT id FROM ingenios WHERE nombre_ingenios = 'Cengicana' LIMIT 1")->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    return (int) $db->query('SELECT MIN(id) FROM ingenios')->fetchColumn();
}

function cengi_curso_form_valido(array $datos)
{
    $codigoValido = $datos['codigo'] === '' || preg_match('/^[A-Z0-9._-]{3,30}$/', $datos['codigo']);
    return $datos['categoria_id'] > 0
        && $datos['ingenio_id'] > 0
        && $datos['modalidad'] !== ''
        && $datos['nombre'] !== ''
        && $datos['jornada'] !== ''
        && $datos['dias'] !== ''
        && $datos['horario'] !== ''
        && ($datos['cupo'] === null || $datos['cupo'] > 0)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['inicio'])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['fin'])
        && $datos['fin'] >= $datos['inicio']
        && $datos['modulos_validos']
        && $codigoValido;
}

function cengi_curso_guardar_modulos(PDO $db, $cursoId, array $modulos, $actualizar)
{
    $existentes = [];
    if ($actualizar) {
        $stmt = $db->prepare('SELECT id FROM curso_modulos WHERE curso_id = ?');
        $stmt->execute([$cursoId]);
        $existentes = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    $conservados = [];
    $orden = 1;
    $stmtInsertar = $db->prepare('INSERT INTO curso_modulos (curso_id, nombre, horas, orden, acepta_pre, acepta_post) VALUES (?, ?, ?, ?, ?, ?)');
    $stmtActualizar = $db->prepare('UPDATE curso_modulos SET nombre = ?, horas = ?, orden = ?, acepta_pre = ?, acepta_post = ? WHERE id = ? AND curso_id = ?');
    $stmtBorrarInstructoresModulo = $db->prepare('DELETE FROM curso_modulo_instructores WHERE curso_modulo_id = ?');
    $stmtInsertarInstructorModulo = $db->prepare('INSERT INTO curso_modulo_instructores (curso_modulo_id, instructor_id) VALUES (?, ?)');
    foreach ($modulos as $modulo) {
        $moduloId = (int) $modulo['id'];
        if ($actualizar && $moduloId > 0 && isset($existentes[$moduloId])) {
            $stmtActualizar->execute([$modulo['nombre'], $modulo['horas'], $orden, $modulo['pre'], $modulo['post'], $moduloId, $cursoId]);
        } else {
            $stmtInsertar->execute([$cursoId, $modulo['nombre'], $modulo['horas'], $orden, $modulo['pre'], $modulo['post']]);
            $moduloId = (int) $db->lastInsertId();
        }
        $conservados[] = $moduloId;

        // Sincroniza los instructores de este modulo: borra y vuelve a insertar el set
        // actual (mismo patron "borrar y reinsertar" que se usa abajo para sincronizar
        // los modulos completos del curso).
        $stmtBorrarInstructoresModulo->execute([$moduloId]);
        $instructoresModulo = array_unique(array_map('intval', $modulo['instructores'] ?? []));
        foreach ($instructoresModulo as $instructorModuloId) {
            if ($instructorModuloId > 0) {
                $stmtInsertarInstructorModulo->execute([$moduloId, $instructorModuloId]);
            }
        }

        $orden++;
    }

    if ($actualizar) {
        if ($conservados) {
            $marcadores = implode(',', array_fill(0, count($conservados), '?'));
            $stmt = $db->prepare("DELETE FROM curso_modulos WHERE curso_id = ? AND id NOT IN ($marcadores)");
            $stmt->execute(array_merge([$cursoId], $conservados));
        } else {
            $stmt = $db->prepare('DELETE FROM curso_modulos WHERE curso_id = ?');
            $stmt->execute([$cursoId]);
        }
    }
}

/**
 * Determina, para una vista de calificacion dada (un modulo puntual o "todo el
 * curso"), si las columnas Pre-Evaluacion y Pos-Evaluacion deben mostrarse (y
 * ser editables). Se basa en curso_modulos.acepta_pre / acepta_post:
 *
 * - $moduloId > 0: usa unicamente el modulo con ese id.
 * - $moduloId == 0 ("todo el curso"): si el curso no tiene modulos, ambas
 *   quedan ocultas (solo asistencia); si tiene, se muestra pre/post si ALGUN
 *   modulo del curso acepta esa evaluacion.
 *
 * @param array $modulos Lista de modulos del curso, cada uno con al menos las
 *                        llaves 'id', 'acepta_pre', 'acepta_post' (tal como se
 *                        cargan con "SELECT * FROM curso_modulos WHERE
 *                        curso_id = ? ORDER BY orden"). Se pasa la misma lista
 *                        desde ver_participante_curso.php y guardar_control.php
 *                        para no duplicar esta regla con criterios distintos.
 * @return array{pre: bool, post: bool}
 */
function cengi_curso_pre_post_visibles(array $modulos, $moduloId)
{
    $moduloId = (int) $moduloId;

    if ($moduloId > 0) {
        foreach ($modulos as $modulo) {
            if ((int) $modulo['id'] === $moduloId) {
                return [
                    'pre' => !empty($modulo['acepta_pre']),
                    'post' => !empty($modulo['acepta_post']),
                ];
            }
        }
        return ['pre' => false, 'post' => false];
    }

    if (!$modulos) {
        return ['pre' => false, 'post' => false];
    }

    $pre = false;
    $post = false;
    foreach ($modulos as $modulo) {
        if (!empty($modulo['acepta_pre'])) {
            $pre = true;
        }
        if (!empty($modulo['acepta_post'])) {
            $post = true;
        }
    }

    return ['pre' => $pre, 'post' => $post];
}

/**
 * Recalcula el resumen del curso completo (control_cursos) para un participante
 * como el promedio de las notas de TODOS los modulos del curso que ese
 * participante ya tiene cargadas en control_curso_modulos (se relee completo,
 * no se suma incrementalmente). Los modulos sin nota aun se excluyen del
 * promedio: AVG() de MySQL ya ignora los NULL, asi que si el participante no
 * tiene ninguna nota de modulo, el resultado es NULL (no 0), igual que antes
 * de calificar.
 *
 * El diploma es exclusivo del flujo "todo el curso": no se toca aqui. La
 * columna asistencia solo se persiste en control_cursos si $puedeGestionar es
 * true, igual que el resto de los flujos de guardado de este modulo.
 *
 * Se llama justo despues de guardar cada fila de control_curso_modulos, tanto
 * desde el guardado manual (guardar_control.php) como desde la carga masiva
 * por modulo (carga_calificaciones_modulo.php).
 */
function cengi_recalcular_resumen_curso(PDO $db, $asignacionId, $cursoId, $puedeGestionar)
{
    $stmtPromedioModulos = $db->prepare("
        SELECT
            AVG(CAST(ccm.asistencia AS DECIMAL(10,2))) AS asistencia_prom,
            AVG(CAST(ccm.evaluacion AS DECIMAL(10,2))) AS evaluacion_prom,
            AVG(CAST(ccm.posevaluacion AS DECIMAL(10,2))) AS posevaluacion_prom
        FROM control_curso_modulos ccm
        INNER JOIN curso_modulos cm ON cm.id = ccm.curso_modulo_id
        WHERE ccm.asignacion_id = ? AND cm.curso_id = ?
    ");
    $stmtPromedioModulos->execute([$asignacionId, $cursoId]);
    $promediosModulos = $stmtPromedioModulos->fetch(PDO::FETCH_ASSOC) ?: [];

    $asistenciaProm = $promediosModulos['asistencia_prom'] ?? null;
    $evaluacionProm = $promediosModulos['evaluacion_prom'] ?? null;
    $posevaluacionProm = $promediosModulos['posevaluacion_prom'] ?? null;

    $stmtVerificarLote = $db->prepare("
        SELECT id_control
        FROM control_cursos
        WHERE asignacion_id = ?
    ");
    $stmtVerificarLote->execute([$asignacionId]);
    $existeControlCurso = (bool) $stmtVerificarLote->fetch(PDO::FETCH_ASSOC);

    if ($existeControlCurso && $puedeGestionar) {
        $stmt = $db->prepare("UPDATE control_cursos SET asistencia = ?, evaluacion = ?, posevaluacion = ? WHERE asignacion_id = ?");
        $stmt->execute([$asistenciaProm, $evaluacionProm, $posevaluacionProm, $asignacionId]);
    } elseif ($existeControlCurso) {
        $stmt = $db->prepare("UPDATE control_cursos SET evaluacion = ?, posevaluacion = ? WHERE asignacion_id = ?");
        $stmt->execute([$evaluacionProm, $posevaluacionProm, $asignacionId]);
    } elseif ($puedeGestionar) {
        $stmt = $db->prepare("INSERT INTO control_cursos (asignacion_id, asistencia, evaluacion, posevaluacion, diploma) VALUES (?, ?, ?, ?, '')");
        $stmt->execute([$asignacionId, $asistenciaProm, $evaluacionProm, $posevaluacionProm]);
    } else {
        $stmt = $db->prepare("INSERT INTO control_cursos (asignacion_id, evaluacion, posevaluacion, diploma) VALUES (?, ?, ?, '')");
        $stmt->execute([$asignacionId, $evaluacionProm, $posevaluacionProm]);
    }
}

/**
 * Asegura que exista un enlace publico de evaluacion para la combinacion
 * curso_id + instructor_id (idempotente: no se regenera si ya existe).
 * Se debe llamar cada vez que un curso se guarda/actualiza con un instructor
 * asignado. No hace nada si $instructorId es nulo/0.
 */
function cengi_asegurar_enlace_evaluacion_instructor(PDO $db, $cursoId, $instructorId)
{
    $cursoId = (int) $cursoId;
    $instructorId = (int) $instructorId;
    if ($cursoId <= 0 || $instructorId <= 0) {
        return;
    }

    $stmt = $db->prepare('SELECT id FROM enlaces_evaluacion_instructor WHERE curso_id = ? AND instructor_id = ?');
    $stmt->execute([$cursoId, $instructorId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $token = bin2hex(random_bytes(16));
    try {
        $stmtInsertar = $db->prepare('INSERT INTO enlaces_evaluacion_instructor (curso_id, instructor_id, token) VALUES (?, ?, ?)');
        $stmtInsertar->execute([$cursoId, $instructorId, $token]);
    } catch (PDOException $e) {
        // Carrera entre dos peticiones concurrentes contra la misma llave unica
        // (curso_id, instructor_id): la fila ya existe, no es un error real.
        error_log('No fue posible crear el enlace de evaluacion (probable duplicado): ' . $e->getMessage());
    }
}

/**
 * Asegura los enlaces publicos de evaluacion tanto para el instructor principal del
 * curso como para cualquier instructor distinto asignado a nivel de modulo
 * (co-ensenanza): cualquier instructor que de al menos un modulo debe tener su propio
 * enlace de evaluacion, no solo el instructor principal. Centraliza la logica que antes
 * se repetia en guardar_cursos.php y actualizar_cursos.php.
 *
 * @param array $modulos Igual formato que devuelve cengi_curso_form_datos()['modulos'].
 */
function cengi_curso_asegurar_enlaces_evaluacion(PDO $db, $cursoId, $instructorPrincipalId, array $modulos)
{
    cengi_asegurar_enlace_evaluacion_instructor($db, $cursoId, $instructorPrincipalId);

    $instructoresModulos = [];
    foreach ($modulos as $modulo) {
        foreach ((array) ($modulo['instructores'] ?? []) as $instructorModuloId) {
            $instructorModuloId = (int) $instructorModuloId;
            if ($instructorModuloId > 0) {
                $instructoresModulos[$instructorModuloId] = true;
            }
        }
    }

    foreach (array_keys($instructoresModulos) as $instructorModuloId) {
        cengi_asegurar_enlace_evaluacion_instructor($db, $cursoId, $instructorModuloId);
    }
}
