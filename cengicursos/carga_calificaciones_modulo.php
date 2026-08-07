<?php
require_once 'revisar_permisos.php';
require_once 'conexion.php';
require_once 'curso_form_helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

cengi_require_calificador('ver_cursos.php');

$db = conectar();
$puedeGestionar = cengi_puede_gestionar();

$cursoId = (int) ($_POST['curso_id'] ?? 0);
$moduloId = (int) ($_POST['modulo_id'] ?? 0);
$destino = 'ver_participante_curso.php?id=' . $cursoId . '&modulo_id=' . $moduloId;

// Misma funcion de parseo/validacion de calificaciones que ya usa
// carga_calificaciones.php (0-100, admite coma decimal). Se duplica aqui a
// proposito: es una funcion pequena y pura, y ambos archivos son copias
// adaptadas del mismo patron de carga masiva.
function cengi_calificacion_modulo_numero($valor)
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
    if ($cursoId <= 0 || $moduloId <= 0 || !isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        throw new RuntimeException('No se recibió un curso, un módulo y un archivo válidos.');
    }
    if ((int) ($_FILES['archivo']['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('El archivo supera el límite de 5 MB.');
    }
    $extension = strtolower(pathinfo((string) $_FILES['archivo']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'csv'], true)) {
        throw new RuntimeException('La carga de calificaciones requiere un archivo XLSX o CSV.');
    }

    // El modulo debe pertenecer al curso indicado (mismo estilo de
    // validacion que guardar_control.php con curso_modulos).
    $stmtModuloValido = $db->prepare('SELECT id FROM curso_modulos WHERE id = ? AND curso_id = ?');
    $stmtModuloValido->execute([$moduloId, $cursoId]);
    if (!$stmtModuloValido->fetch()) {
        throw new RuntimeException('El módulo no existe o no pertenece a este curso.');
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

    // Ambos formatos se normalizan a un mismo arreglo de filas (cada una un
    // arreglo indexado 0..n) antes de entrar al mismo bucle de validacion/
    // guardado, para no duplicar esa logica por formato.
    $filasDatos = [];
    if ($extension === 'xlsx') {
        $hojaCargada = IOFactory::load($_FILES['archivo']['tmp_name']);
        $filasDatos = $hojaCargada->getActiveSheet()->toArray(null, true, true, false);
    } else {
        $archivo = fopen($_FILES['archivo']['tmp_name'], 'r');
        if ($archivo === false) {
            throw new RuntimeException('No fue posible leer el archivo CSV.');
        }
        $muestra = fgets($archivo);
        rewind($archivo);
        $separador = substr_count((string) $muestra, ';') > substr_count((string) $muestra, ',') ? ';' : ',';
        while (($fila = fgetcsv($archivo, 0, $separador)) !== false) {
            $filasDatos[] = $fila;
        }
        fclose($archivo);
    }

    $sqlAsignacion = 'SELECT a.id
        FROM asignaciones a
        INNER JOIN participantes p ON p.id = a.participantes_id
        WHERE a.cursos_id = ? AND p.cui_participantes = ?';
    if (!cengi_ve_todo_por_rol_o_ingenio()) {
        $sqlAsignacion .= ' AND p.ingenio_id = ?';
    }
    $sqlAsignacion .= ' LIMIT 1';
    $stmtAsignacion = $db->prepare($sqlAsignacion);
    $stmtExiste = $db->prepare('SELECT id FROM control_curso_modulos WHERE asignacion_id = ? AND curso_modulo_id = ? LIMIT 1');
    // La columna asistencia solo la puede escribir quien cengi_puede_gestionar(); si
    // el usuario actual no puede gestionar, se omite del INSERT/UPDATE en vez de
    // forzarla a NULL (mismo criterio que el bloque de modulo en guardar_control.php).
    $stmtActualizarConAsistencia = $db->prepare('UPDATE control_curso_modulos SET asistencia = ?, evaluacion = ?, posevaluacion = ? WHERE asignacion_id = ? AND curso_modulo_id = ?');
    $stmtActualizarSinAsistencia = $db->prepare('UPDATE control_curso_modulos SET evaluacion = ?, posevaluacion = ? WHERE asignacion_id = ? AND curso_modulo_id = ?');
    $stmtInsertarConAsistencia = $db->prepare('INSERT INTO control_curso_modulos (asignacion_id, curso_modulo_id, asistencia, evaluacion, posevaluacion) VALUES (?, ?, ?, ?, ?)');
    $stmtInsertarSinAsistencia = $db->prepare('INSERT INTO control_curso_modulos (asignacion_id, curso_modulo_id, evaluacion, posevaluacion) VALUES (?, ?, ?, ?)');

    $db->beginTransaction();
    $filaNumero = 0;
    $procesados = 0;
    foreach ($filasDatos as $fila) {
        $filaNumero++;
        if (!is_array($fila) || count($fila) < 4) {
            if ($filaNumero === 1) {
                continue;
            }
            throw new RuntimeException('La fila ' . $filaNumero . ' no contiene las cuatro columnas requeridas.');
        }
        $fila = array_values($fila);
        $cui = preg_replace('/[^0-9A-Za-z-]/', '', trim((string) $fila[0]));
        if ($filaNumero === 1 && in_array(strtolower($cui), ['cui', 'dpi'], true)) {
            continue;
        }
        if ($cui === '') {
            continue;
        }

        $asistencia = cengi_calificacion_modulo_numero($fila[1]);
        $pre = cengi_calificacion_modulo_numero($fila[2]);
        $post = cengi_calificacion_modulo_numero($fila[3]);
        $paramsAsignacion = [$cursoId, $cui];
        if (!cengi_ve_todo_por_rol_o_ingenio()) {
            $paramsAsignacion[] = cengi_ingenio_id_actual();
        }
        $stmtAsignacion->execute($paramsAsignacion);
        $asignacionId = (int) $stmtAsignacion->fetchColumn();
        if ($asignacionId <= 0) {
            throw new RuntimeException('No se encontró el CUI ' . $cui . ' dentro del curso seleccionado.');
        }

        $stmtExiste->execute([$asignacionId, $moduloId]);
        $existe = (bool) $stmtExiste->fetchColumn();

        if ($existe && $puedeGestionar) {
            $stmtActualizarConAsistencia->execute([$asistencia, $pre, $post, $asignacionId, $moduloId]);
        } elseif ($existe) {
            $stmtActualizarSinAsistencia->execute([$pre, $post, $asignacionId, $moduloId]);
        } elseif ($puedeGestionar) {
            $stmtInsertarConAsistencia->execute([$asignacionId, $moduloId, $asistencia, $pre, $post]);
        } else {
            $stmtInsertarSinAsistencia->execute([$asignacionId, $moduloId, $pre, $post]);
        }

        // Mantiene control_cursos (resumen del curso completo) consistente con el
        // nuevo promedio de modulos, igual que el flujo manual de guardar_control.php.
        cengi_recalcular_resumen_curso($db, $asignacionId, $cursoId, $puedeGestionar);

        $procesados++;
    }

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
    error_log('Error en carga de calificaciones por módulo: ' . $e->getMessage());
    header('Location: ' . $destino . '&error=calificacion');
}
exit;
