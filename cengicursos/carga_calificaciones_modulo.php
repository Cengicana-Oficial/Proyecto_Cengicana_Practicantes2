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
    // validacion que guardar_control.php con curso_modulos). Se cargan todos los
    // modulos del curso para reutilizar cengi_curso_pre_post_visibles() y saber
    // si este modulo requiere Pre/Pos-Evaluacion.
    $stmtModulosCurso = $db->prepare('SELECT * FROM curso_modulos WHERE curso_id = ? ORDER BY orden');
    $stmtModulosCurso->execute([$cursoId]);
    $modulosCurso = $stmtModulosCurso->fetchAll(PDO::FETCH_ASSOC);
    $moduloPertenece = false;
    foreach ($modulosCurso as $m) {
        if ((int) $m['id'] === $moduloId) {
            $moduloPertenece = true;
            break;
        }
    }
    if (!$moduloPertenece) {
        throw new RuntimeException('El módulo no existe o no pertenece a este curso.');
    }

    // Si el modulo no requiere Pre/Pos-Evaluacion, esas columnas del archivo son
    // opcionales: pueden faltar o venir vacias sin marcar error de validacion, y
    // su valor no se guarda (queda NULL), igual que en la carga manual
    // (ver_participante_curso.php / guardar_control.php).
    if (cengi_curso_solo_asistencia($db, $cursoId)) {
        throw new RuntimeException('Este curso dura menos de ' . CENGI_HORAS_MIN_EVALUACION . ' horas: solo se registra la asistencia.');
    }
    $prePostVisibles = cengi_curso_pre_post_visibles($modulosCurso, $moduloId);
    $requierePre = $prePostVisibles['pre'];
    $requierePost = $prePostVisibles['post'];
    if (!$requierePre && !$requierePost) {
        throw new RuntimeException('Este módulo no lleva pre ni post-evaluación.');
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
    $stmtActualizar = $db->prepare('UPDATE control_curso_modulos SET evaluacion = ?, posevaluacion = ? WHERE asignacion_id = ? AND curso_modulo_id = ?');
    $stmtInsertar = $db->prepare('INSERT INTO control_curso_modulos (asignacion_id, curso_modulo_id, evaluacion, posevaluacion) VALUES (?, ?, ?, ?)');

    // Columnas: se ubican por encabezado (CUI, PRE_EVALUACION, POST_EVALUACION) para
    // aceptar tambien la plantilla anterior que traia ASISTENCIA en la 2.a columna
    // (la asistencia ya no se carga por modulo: es acumulada del curso completo).
    // Sin encabezado reconocible se usa el orden de la plantilla actual: CUI, PRE, POST.
    $col = ['cui' => 0, 'pre' => 1, 'post' => 2];
    $encabezado = array_map(static function ($v) {
        return strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $v));
    }, array_values($filasDatos[0] ?? []));
    $tieneEncabezado = in_array('CUI', $encabezado, true) || in_array('DPI', $encabezado, true);
    if ($tieneEncabezado) {
        foreach ($encabezado as $i => $nombre) {
            if ($nombre === 'CUI' || $nombre === 'DPI') {
                $col['cui'] = $i;
            } elseif (in_array($nombre, ['PREEVALUACION', 'PRE'], true)) {
                $col['pre'] = $i;
            } elseif (in_array($nombre, ['POSTEVALUACION', 'POSEVALUACION', 'POST'], true)) {
                $col['post'] = $i;
            }
        }
    }

    $db->beginTransaction();
    $filaNumero = 0;
    $procesados = 0;
    foreach ($filasDatos as $fila) {
        $filaNumero++;
        if ($filaNumero === 1 && $tieneEncabezado) {
            continue;
        }
        if (!is_array($fila)) {
            continue;
        }
        $fila = array_values($fila);
        $cui = preg_replace('/[^0-9A-Za-z-]/', '', trim((string) ($fila[$col['cui']] ?? '')));
        if ($cui === '') {
            continue;
        }

        // Pre/Pos-Evaluacion solo se leen y validan si el modulo las requiere; si
        // no, se ignoran (aunque el archivo traiga la columna) y quedan NULL.
        $pre = $requierePre ? cengi_calificacion_modulo_numero($fila[$col['pre']] ?? '') : null;
        $post = $requierePost ? cengi_calificacion_modulo_numero($fila[$col['post']] ?? '') : null;
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

        if ($existe) {
            $stmtActualizar->execute([$pre, $post, $asignacionId, $moduloId]);
        } else {
            $stmtInsertar->execute([$asignacionId, $moduloId, $pre, $post]);
        }

        // Nota del curso completo = promedio de los modulos ponderado por horas,
        // igual que el flujo manual de guardar_control.php.
        cengi_recalcular_resumen_curso($db, $asignacionId, $cursoId);

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
