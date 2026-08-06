<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';
require_once 'curso_form_helpers.php';

cengi_require_gestionar_instructores('instructores.php');

$db = conectar();
$cursoId = (int) ($_POST['curso_id'] ?? 0);
$destino = 'instructores.php';

/**
 * Entero requerido dentro de [1, $max], igual que evaluacion_entero_rango() en
 * guardar_evaluacion_instructor.php (mismas reglas para las preguntas Likert 1..4 y
 * las calificaciones 1..10 estrellas).
 */
function cengi_carga_eval_entero_rango($valorCrudo, $max)
{
    $texto = trim((string) $valorCrudo);
    if ($texto === '' || !ctype_digit($texto)) {
        return null;
    }
    $numero = (int) $texto;
    if ($numero < 1 || $numero > $max) {
        return null;
    }
    return $numero;
}

/**
 * Trunca (no rechaza) un texto libre a $max caracteres, igual que
 * evaluacion_truncar() en guardar_evaluacion_instructor.php.
 */
function cengi_carga_eval_truncar($valor, $max)
{
    $valor = trim((string) $valor);
    if (mb_strlen($valor) > $max) {
        $valor = mb_substr($valor, 0, $max);
    }
    return $valor;
}

try {
    if ($cursoId <= 0 || !isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        throw new RuntimeException('No se recibió una edición de curso y un archivo válidos.');
    }
    if ((int) ($_FILES['archivo']['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('El archivo supera el límite de 5 MB.');
    }
    if (strtolower(pathinfo((string) $_FILES['archivo']['name'], PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('La carga de evaluaciones requiere un archivo CSV.');
    }

    $stmtCurso = $db->prepare('
        SELECT c.id, c.tipo AS modalidad, c.nombre_cursos, c.inicio
        FROM cursos c
        WHERE c.id = ?
    ');
    $stmtCurso->execute([$cursoId]);
    $curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
    if (!$curso) {
        throw new RuntimeException('La edición de curso seleccionada no está disponible.');
    }
    $modalidad = ($curso['modalidad'] === 'Virtual') ? 'Virtual' : 'Presencial';

    $archivo = fopen($_FILES['archivo']['tmp_name'], 'r');
    if ($archivo === false) {
        throw new RuntimeException('No fue posible leer el archivo CSV.');
    }
    $muestra = fgets($archivo);
    rewind($archivo);
    $separador = substr_count((string) $muestra, ';') > substr_count((string) $muestra, ',') ? ';' : ',';

    // Resuelve instructor_id por nombre, normalizando acentos/mayusculas/espacios con el
    // mismo patron que revisar_permisos.php (cengi_sql_texto_normalizado), ya que MySQL
    // no tiene TRANSLATE()/REGEXP_REPLACE(...,'g') como Postgres.
    $stmtInstructor = $db->prepare('
        SELECT id, nombre FROM instructores WHERE ' . cengi_sql_texto_normalizado('nombre') . ' = ' . cengi_sql_texto_normalizado('?') . '
        LIMIT 1
    ');
    $stmtIngenio = $db->prepare('
        SELECT id FROM ingenios WHERE ' . cengi_sql_texto_normalizado('nombre_ingenios') . ' = ' . cengi_sql_texto_normalizado('?') . '
        LIMIT 1
    ');
    $stmtEnlace = $db->prepare('SELECT id FROM enlaces_evaluacion_instructor WHERE curso_id = ? AND instructor_id = ?');
    $stmtInsertar = $db->prepare('
        INSERT INTO evaluaciones_instructor (
            enlace_id,
            ingenio_id,
            ingenio_otro,
            cargo,
            seccion,
            modalidad,
            curso_nombre,
            conferencista,
            fecha_curso,
            instructor_lenguaje_claro,
            instructor_material_adecuado,
            instructor_conocimiento_tema,
            instructor_respeto_participantes,
            instructor_puntualidad_objetivos,
            instructor_manejo_plataforma,
            recomendaria_instructor,
            tema_relevancia_utilidad,
            logistica_evento,
            calidad_instalaciones,
            recomendaria_contexto,
            capacitaciones_necesarias,
            areas_mejora
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ');

    $db->beginTransaction();
    $filaNumero = 0;
    $procesados = 0;
    while (($fila = fgetcsv($archivo, 0, $separador)) !== false) {
        $filaNumero++;
        if (count($fila) < 17) {
            if ($filaNumero === 1) {
                continue;
            }
            throw new RuntimeException('La fila ' . $filaNumero . ' no contiene las diecisiete columnas requeridas.');
        }

        $instructorNombre = trim((string) $fila[0]);
        if ($filaNumero === 1 && cengi_texto_normalizado($instructorNombre) === 'instructor') {
            continue;
        }
        if ($instructorNombre === '') {
            continue;
        }

        $ingenioNombre = trim((string) $fila[1]);
        $cargo = cengi_carga_eval_truncar($fila[2], 255);
        $seccion = cengi_carga_eval_truncar($fila[3], 255);

        if ($cargo === '') {
            throw new RuntimeException('La fila ' . $filaNumero . ' no indica el cargo.');
        }
        if ($seccion === '') {
            throw new RuntimeException('La fila ' . $filaNumero . ' no indica la sección.');
        }

        $lenguajeClaro = cengi_carga_eval_entero_rango($fila[4], 4);
        $materialAdecuado = cengi_carga_eval_entero_rango($fila[5], 4);
        $conocimientoTema = cengi_carga_eval_entero_rango($fila[6], 4);
        $respetoParticipantes = cengi_carga_eval_entero_rango($fila[7], 4);
        $puntualidadObjetivos = cengi_carga_eval_entero_rango($fila[8], 4);
        if ($lenguajeClaro === null || $materialAdecuado === null || $conocimientoTema === null
            || $respetoParticipantes === null || $puntualidadObjetivos === null) {
            throw new RuntimeException('La fila ' . $filaNumero . ' tiene una respuesta inválida sobre el instructor (debe ser 1 a 4).');
        }

        // Preguntas por modalidad: solo una de las dos aplica a todo el lote, según la
        // edición de curso seleccionada en el formulario (no hay columna "Modalidad" por fila).
        $instructorManejoPlataforma = null;
        $calidadInstalaciones = null;
        if ($modalidad === 'Virtual') {
            $instructorManejoPlataforma = cengi_carga_eval_entero_rango($fila[15], 4);
            if ($instructorManejoPlataforma === null) {
                throw new RuntimeException('La fila ' . $filaNumero . ' tiene una respuesta inválida sobre el manejo de la plataforma virtual (debe ser 1 a 4).');
            }
        } elseif ($modalidad === 'Presencial') {
            $calidadInstalaciones = cengi_carga_eval_entero_rango($fila[16], 4);
            if ($calidadInstalaciones === null) {
                throw new RuntimeException('La fila ' . $filaNumero . ' tiene una respuesta inválida sobre la calidad de las instalaciones (debe ser 1 a 4).');
            }
        }

        $recomendariaInstructor = cengi_carga_eval_entero_rango($fila[9], 10);
        if ($recomendariaInstructor === null) {
            throw new RuntimeException('La fila ' . $filaNumero . ' tiene una calificación inválida de "¿recomendaría al instructor?" (debe ser 1 a 10).');
        }

        $relevanciaUtilidad = cengi_carga_eval_entero_rango($fila[10], 4);
        $logisticaEvento = cengi_carga_eval_entero_rango($fila[11], 4);
        if ($relevanciaUtilidad === null || $logisticaEvento === null) {
            throw new RuntimeException('La fila ' . $filaNumero . ' tiene una respuesta inválida sobre el tema/logística (debe ser 1 a 4).');
        }

        $recomendariaContexto = cengi_carga_eval_entero_rango($fila[12], 10);
        if ($recomendariaContexto === null) {
            throw new RuntimeException('La fila ' . $filaNumero . ' tiene una calificación inválida de "¿recomendaría el lugar/plataforma?" (debe ser 1 a 10).');
        }

        $capacitacionesNecesarias = cengi_carga_eval_truncar($fila[13], 2000);
        $areasMejora = cengi_carga_eval_truncar($fila[14], 2000);
        if ($capacitacionesNecesarias === '') {
            throw new RuntimeException('La fila ' . $filaNumero . ' no indica las capacitaciones necesarias.');
        }
        if ($areasMejora === '') {
            throw new RuntimeException('La fila ' . $filaNumero . ' no indica las oportunidades de mejora.');
        }

        $stmtInstructor->execute([$instructorNombre]);
        $instructorFila = $stmtInstructor->fetch(PDO::FETCH_ASSOC);
        if (!$instructorFila) {
            throw new RuntimeException('La fila ' . $filaNumero . ' no coincide con ningún instructor registrado: "' . $instructorNombre . '".');
        }
        $instructorId = (int) $instructorFila['id'];
        // "conferencista" se guarda con el nombre ya registrado en instructores (igual que
        // guardar_evaluacion_instructor.php toma instructor_nombre del JOIN), no con el
        // texto crudo de la celda del CSV.
        $instructorNombreCanonico = (string) $instructorFila['nombre'];

        $ingenioId = null;
        $ingenioOtro = '';
        if ($ingenioNombre === '') {
            throw new RuntimeException('La fila ' . $filaNumero . ' no indica el ingenio.');
        }
        $stmtIngenio->execute([$ingenioNombre]);
        $ingenioIdEncontrado = (int) $stmtIngenio->fetchColumn();
        if ($ingenioIdEncontrado > 0) {
            $ingenioId = $ingenioIdEncontrado;
        } else {
            $ingenioOtro = mb_substr($ingenioNombre, 0, 255);
        }

        cengi_asegurar_enlace_evaluacion_instructor($db, $cursoId, $instructorId);
        $stmtEnlace->execute([$cursoId, $instructorId]);
        $enlaceId = (int) $stmtEnlace->fetchColumn();
        if ($enlaceId <= 0) {
            throw new RuntimeException('La fila ' . $filaNumero . ' no pudo vincularse a un enlace de evaluación válido.');
        }

        $stmtInsertar->execute([
            $enlaceId,
            $ingenioId,
            $ingenioOtro,
            $cargo,
            $seccion,
            $modalidad,
            $curso['nombre_cursos'],
            $instructorNombreCanonico,
            $curso['inicio'] ?: null,
            $lenguajeClaro,
            $materialAdecuado,
            $conocimientoTema,
            $respetoParticipantes,
            $puntualidadObjetivos,
            $instructorManejoPlataforma,
            $recomendariaInstructor,
            $relevanciaUtilidad,
            $logisticaEvento,
            $calidadInstalaciones,
            $recomendariaContexto,
            $capacitacionesNecesarias,
            $areasMejora,
        ]);
        $procesados++;
    }
    fclose($archivo);

    if ($procesados === 0) {
        throw new RuntimeException('El archivo no contiene registros para procesar.');
    }
    $db->commit();
    header('Location: ' . $destino . '?mensaje=evaluaciones');
} catch (Throwable $e) {
    if (isset($archivo) && is_resource($archivo)) {
        fclose($archivo);
    }
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Error en carga de evaluaciones de instructor: ' . $e->getMessage());
    $_SESSION['cengi_error_carga_evaluaciones'] = $e->getMessage();
    header('Location: ' . $destino . '?error=evaluaciones');
}
exit;
