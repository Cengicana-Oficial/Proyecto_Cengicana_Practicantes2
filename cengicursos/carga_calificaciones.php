<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';

cengi_require_calificador('participantes.php');

$db = conectar();
$cursoId = (int) ($_POST['curso_id'] ?? 0);
$destino = 'participantes.php?curso_id=' . $cursoId;

function cengi_calificacion_numero($valor)
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    $valor = str_replace(',', '.', $valor);
    if (!is_numeric($valor)) {
        throw new RuntimeException('Las calificaciones deben ser numéricas.');
    }
    $numero = (float) $valor;
    if ($numero < 0 || $numero > 100) {
        throw new RuntimeException('Las calificaciones deben estar entre 0 y 100.');
    }
    return $numero;
}

try {
    if ($cursoId <= 0 || !isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        throw new RuntimeException('No se recibió un curso y un archivo válidos.');
    }
    if ((int) ($_FILES['archivo']['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('El archivo supera el límite de 5 MB.');
    }
    if (strtolower(pathinfo((string) $_FILES['archivo']['name'], PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('La carga de calificaciones requiere un archivo CSV.');
    }

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
        throw new RuntimeException('El curso no está disponible para este usuario.');
    }

    $archivo = fopen($_FILES['archivo']['tmp_name'], 'r');
    if ($archivo === false) {
        throw new RuntimeException('No fue posible leer el archivo CSV.');
    }
    $muestra = fgets($archivo);
    rewind($archivo);
    $separador = substr_count((string) $muestra, ';') > substr_count((string) $muestra, ',') ? ';' : ',';

    $sqlAsignacion = 'SELECT a.id
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        WHERE a.cursos_id = ? AND p.cui_participantes = ?';
    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $sqlAsignacion .= ' AND p.ingenio_id = ?';
    }
    $sqlAsignacion .= ' LIMIT 1';
    $stmtAsignacion = $db->prepare($sqlAsignacion);
    $stmtExiste = $db->prepare('SELECT id_control FROM control_cursos WHERE asignacion_id = ? LIMIT 1');
    $stmtActualizar = $db->prepare('UPDATE control_cursos SET asistencia = ?, evaluacion = ?, posevaluacion = ? WHERE asignacion_id = ?');
    $stmtInsertar = $db->prepare("INSERT INTO control_cursos (asignacion_id, asistencia, evaluacion, posevaluacion, diploma) VALUES (?, ?, ?, ?, '')");

    $db->beginTransaction();
    $filaNumero = 0;
    $procesados = 0;
    while (($fila = fgetcsv($archivo, 0, $separador)) !== false) {
        $filaNumero++;
        if (count($fila) < 4) {
            if ($filaNumero === 1) {
                continue;
            }
            throw new RuntimeException('La fila ' . $filaNumero . ' no contiene las cuatro columnas requeridas.');
        }
        $cui = preg_replace('/[^0-9A-Za-z-]/', '', trim((string) $fila[0]));
        if ($filaNumero === 1 && in_array(strtolower($cui), ['cui', 'dpi'], true)) {
            continue;
        }
        if ($cui === '') {
            continue;
        }

        $asistencia = cengi_calificacion_numero($fila[1]);
        $pre = cengi_calificacion_numero($fila[2]);
        $post = cengi_calificacion_numero($fila[3]);
        $paramsAsignacion = [$cursoId, $cui];
        if (!cengi_ve_todo_por_rol_o_ingenio()) {
            $paramsAsignacion[] = cengi_ingenio_id_actual();
        }
        $stmtAsignacion->execute($paramsAsignacion);
        $asignacionId = (int) $stmtAsignacion->fetchColumn();
        if ($asignacionId <= 0) {
            throw new RuntimeException('No se encontró el CUI ' . $cui . ' dentro del curso seleccionado.');
        }

        $stmtExiste->execute([$asignacionId]);
        if ($stmtExiste->fetchColumn()) {
            $stmtActualizar->execute([$asistencia, $pre, $post, $asignacionId]);
        } else {
            $stmtInsertar->execute([$asignacionId, $asistencia, $pre, $post]);
        }
        $procesados++;
    }
    fclose($archivo);

    if ($procesados === 0) {
        throw new RuntimeException('El archivo no contiene registros para procesar.');
    }
    $db->commit();
    header('Location: ' . $destino . '&mensaje=calificacion');
} catch (Throwable $e) {
    if (isset($archivo) && is_resource($archivo)) {
        fclose($archivo);
    }
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Error en carga de calificaciones: ' . $e->getMessage());
    header('Location: ' . $destino . '&error=calificacion');
}
exit;
