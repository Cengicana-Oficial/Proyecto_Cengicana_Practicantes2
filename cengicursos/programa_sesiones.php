<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';
$reabrir = null; // datos para volver a abrir un modal tras error o avisos

const CENGI_SES_MENSAJES = [
    'guardada' => 'Sesión guardada.',
    'generadas' => 'Sesiones creadas.',
    'impartida' => 'Sesión marcada como impartida: ya cuenta para el pago.',
    'cancelada' => 'Sesión cancelada.',
    'programada' => 'Sesión restaurada como programada.',
    'eliminada' => 'Sesión eliminada.',
    'soporte' => 'Soporte técnico guardado.',
];

function cengi_ses_obtener(PDO $db, $id)
{
    $stmt = $db->prepare('SELECT s.*, i.nombre AS instructor_nombre, st.nombre AS soporte_nombre FROM curso_sesiones s
        LEFT JOIN instructores i ON i.id = s.instructor_id LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id WHERE s.id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cengi_ses_nombre(PDO $db, $tabla, $id)
{
    if (!$id) {
        return '';
    }
    $stmt = $db->prepare("SELECT nombre FROM $tabla WHERE id = ?");
    $stmt->execute([(int) $id]);
    return (string) $stmt->fetchColumn();
}

/** Fechas que cumplen el patron del generador dentro del rango del plan. */
function cengi_ses_fechas_generador(array $plan, $desde, $cantidad, array $dias, $frecuencia)
{
    $fechas = [];
    if (!cengi_pp_es_fecha($desde) || $cantidad < 1 || !$dias) {
        return $fechas;
    }
    $lunes = cengi_pp_sumar_dias($desde, -(((int) date('w', strtotime($desde)) + 6) % 7));
    for ($d = max($desde, $plan['fecha_inicio']); $d <= $plan['fecha_fin'] && count($fechas) < $cantidad; $d = cengi_pp_sumar_dias($d, 1)) {
        $semana = intdiv(cengi_pp_dias_entre($lunes, $d), 7);
        if (in_array((int) date('w', strtotime($d)), $dias, true) && $semana % $frecuencia === 0) {
            $fechas[] = $d;
        }
    }
    return $fechas;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $codigoMensaje = '';
    $extra = [];
    try {
        $db->beginTransaction();

        if ($accion === 'guardar_sesion') {
            $id = (int) ($_POST['id'] ?? 0);
            $anterior = $id ? cengi_ses_obtener($db, $id) : null;
            if ($id && !$anterior) {
                throw new InvalidArgumentException('La sesión ya no existe.');
            }
            $v = [
                'planificacion_id' => (int) ($_POST['planificacion_id'] ?? 0),
                'modulo' => mb_substr(trim((string) ($_POST['modulo'] ?? '')), 0, 255, 'UTF-8') ?: 'Sesión única',
                'tema' => mb_substr(trim((string) ($_POST['tema'] ?? '')), 0, 255, 'UTF-8'),
                'fecha' => (string) ($_POST['fecha'] ?? ''),
                'hora_inicio' => (string) ($_POST['hora_inicio'] ?? ''),
                'hora_fin' => (string) ($_POST['hora_fin'] ?? ''),
                'instructor_id' => (int) ($_POST['instructor_id'] ?? 0) ?: null,
                'soporte_id' => (int) ($_POST['soporte_id'] ?? 0) ?: null,
                'modalidad' => in_array($_POST['modalidad'] ?? '', CENGI_PP_MODALIDADES, true) ? $_POST['modalidad'] : 'Presencial',
                'lugar' => mb_substr(trim((string) ($_POST['lugar'] ?? '')), 0, 255, 'UTF-8'),
                'estado' => array_key_exists($_POST['estado'] ?? '', CENGI_PP_ESTADOS_SESION) ? $_POST['estado'] : 'programada',
            ];
            $plan = cengi_pp_plan($db, $v['planificacion_id']);
            $errores = [];
            if (!$plan) {
                throw new InvalidArgumentException('Selecciona el curso.');
            }
            if (mb_strlen($v['tema'], 'UTF-8') < 3) {
                $errores[] = 'Escribe el tema de la sesión.';
            }
            if (!cengi_pp_es_fecha($v['fecha'])) {
                $errores[] = 'Indica la fecha.';
            } elseif ($v['fecha'] < $plan['fecha_inicio'] || $v['fecha'] > $plan['fecha_fin']) {
                $errores[] = 'La fecha debe estar dentro del rango del curso (' . cengi_pp_fecha_corta($plan['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($plan['fecha_fin']) . '). Si el curso cambió de fechas, reprográmalo primero.';
            }
            if (!cengi_pp_es_hora($v['hora_inicio']) || !cengi_pp_es_hora($v['hora_fin']) || $v['hora_fin'] <= $v['hora_inicio']) {
                $errores[] = 'La hora de fin debe ser mayor que la de inicio.';
            }
            if ($v['lugar'] === '') {
                $errores[] = 'Indica el lugar de desarrollo o el enlace virtual.';
            }
            if ($v['estado'] === 'impartida' && $v['fecha'] > $hoy) {
                $errores[] = 'Una sesión futura no puede quedar como impartida.';
            }
            if ($anterior && $anterior['liquidacion_id'] && ($anterior['estado'] !== $v['estado'] || (int) $anterior['instructor_id'] !== (int) $v['instructor_id']
                || $anterior['fecha'] !== $v['fecha'] || (int) $anterior['planificacion_id'] !== $v['planificacion_id'])) {
                $errores[] = 'La sesión ya está en una liquidación de pago: no se puede cambiar su estado, fecha, curso ni instructor. Solo tema, lugar u horario.';
            }
            if ($anterior && $anterior['liquidacion_soporte_id'] && (int) $anterior['soporte_id'] !== (int) $v['soporte_id']) {
                $errores[] = 'El pago del soporte técnico de esta sesión ya está liquidado: no se puede cambiar el soporte.';
            }
            if ($errores) {
                throw new InvalidArgumentException(implode('<br>', array_map('cengi_pp_h', $errores)));
            }
            $v['hora_inicio'] = substr($v['hora_inicio'], 0, 5) . ':00';
            $v['hora_fin'] = substr($v['hora_fin'], 0, 5) . ':00';

            if ($v['estado'] !== 'cancelada' && empty($_POST['confirmar'])) {
                $avisos = cengi_pp_avisos_sesion($db, $v, $id);
                if (!$v['soporte_id'] && $v['modalidad'] !== 'Presencial') {
                    $avisos[] = 'Es una sesión ' . mb_strtolower($v['modalidad'], 'UTF-8') . ' y no tiene soporte técnico asignado.';
                }
                $activas = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE planificacion_id = ? AND estado <> 'cancelada' AND id <> ?");
                $activas->execute([$v['planificacion_id'], $id]);
                $total = (int) $activas->fetchColumn() + 1;
                if ($total > $plan['sesiones_planificadas']) {
                    $avisos[] = 'Con esta sesión el curso tendrá ' . $total . ' sesiones y el plan indica ' . (int) $plan['sesiones_planificadas'] . '.';
                }
                if ($v['instructor_id'] && ($docInstr = cengi_pp_documentacion($db, $v['instructor_id']))) {
                    if (!$docInstr['activo']) {
                        $avisos[] = 'El instructor está marcado como inactivo.';
                    } elseif ($docInstr['faltan']) {
                        $avisos[] = 'El instructor tiene la documentación incompleta (falta ' . cengi_pp_h(implode(', ', $docInstr['faltan'])) . ').';
                    }
                }
                if ($avisos) {
                    $db->rollBack();
                    $reabrir = ['modal' => 'sesion', 'datos' => $_POST + ['id' => $id], 'avisos' => $avisos];
                    throw new DomainException('avisos');
                }
            }

            $convocado = cengi_pp_convocado($plan);
            if ($anterior) {
                $columnas = implode(', ', array_map(static function ($c) {
                    return $c . ' = ?';
                }, array_keys($v)));
                $db->prepare("UPDATE curso_sesiones SET $columnas WHERE id = ?")->execute(array_merge(array_values($v), [$id]));
                $afectaFutura = $convocado && ($anterior['fecha'] >= $hoy || $v['fecha'] >= $hoy) && $v['estado'] !== 'cancelada';
                $etiqueta = 'Sesión del ' . cengi_pp_fecha($anterior['fecha']) . ' — ' . $anterior['tema'];
                $registrar = static function ($tipo, $antes, $despues) use ($db, $plan, $etiqueta, $id, $afectaFutura) {
                    if ((string) $antes !== (string) $despues) {
                        cengi_pp_log($db, $plan, $tipo, $etiqueta, ['antes' => $antes, 'despues' => $despues, 'sesion_id' => $id,
                            'afecta' => $afectaFutura && in_array($tipo, CENGI_PP_TIPOS_AFECTAN, true)]);
                    }
                };
                $registrar('Cambio de fecha de sesión', cengi_pp_fecha($anterior['fecha']), cengi_pp_fecha($v['fecha']));
                $registrar('Cambio de horario', cengi_pp_horario($anterior['hora_inicio'], $anterior['hora_fin']), cengi_pp_horario($v['hora_inicio'], $v['hora_fin']));
                $registrar('Cambio de lugar', $anterior['lugar'], $v['lugar']);
                $registrar('Cambio de instructor', $anterior['instructor_nombre'] ?: 'Sin instructor', cengi_ses_nombre($db, 'instructores', $v['instructor_id']) ?: 'Sin instructor');
                $registrar('Cambio de soporte técnico', $anterior['soporte_nombre'] ?: 'Sin soporte', cengi_ses_nombre($db, 'soporte_tecnico', $v['soporte_id']) ?: 'Sin soporte');
                $registrar('Cambio de modalidad', $anterior['modalidad'], $v['modalidad']);
                $registrar('Ajuste de contenido', $anterior['modulo'] . ' — ' . $anterior['tema'], $v['modulo'] . ' — ' . $v['tema']);
                if ($anterior['estado'] !== $v['estado']) {
                    $tipo = $v['estado'] === 'cancelada' ? 'Sesión cancelada' : ($anterior['estado'] === 'cancelada' ? 'Sesión restaurada' : 'Sesión impartida');
                    cengi_pp_log($db, $plan, $tipo, $etiqueta, ['antes' => $anterior['estado'], 'despues' => $v['estado'], 'sesion_id' => $id,
                        'afecta' => $tipo !== 'Sesión impartida' && $convocado && $v['fecha'] >= $hoy]);
                }
            } else {
                $columnas = array_keys($v);
                $db->prepare('INSERT INTO curso_sesiones (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')->execute(array_values($v));
                $nuevoId = (int) $db->lastInsertId();
                cengi_pp_log($db, $plan, 'Sesión agregada', cengi_pp_fecha($v['fecha']) . ' ' . cengi_pp_horario($v['hora_inicio'], $v['hora_fin']) . ' — ' . $v['tema'], [
                    'despues' => $v['lugar'] . ' · ' . (cengi_ses_nombre($db, 'instructores', $v['instructor_id']) ?: 'Sin instructor'),
                    'sesion_id' => $nuevoId, 'afecta' => $convocado && $v['fecha'] >= $hoy,
                ]);
            }
            $extra['ver'] = $v['planificacion_id'];
            $codigoMensaje = 'guardada';
        } elseif ($accion === 'estado_sesion') {
            $sesion = cengi_ses_obtener($db, (int) ($_POST['id'] ?? 0));
            $estado = (string) ($_POST['estado'] ?? '');
            if (!$sesion || !array_key_exists($estado, CENGI_PP_ESTADOS_SESION)) {
                throw new InvalidArgumentException('La sesión no es válida.');
            }
            if ($sesion['liquidacion_id'] || $sesion['liquidacion_soporte_id']) {
                throw new InvalidArgumentException('Esta sesión está en una liquidación de pago. Anula la liquidación para cambiarla.');
            }
            if ($estado === 'impartida' && $sesion['fecha'] > $hoy) {
                throw new InvalidArgumentException('No se puede marcar como impartida una sesión futura (' . cengi_pp_fecha_corta($sesion['fecha']) . ').');
            }
            $plan = cengi_pp_plan($db, $sesion['planificacion_id']);
            $db->prepare('UPDATE curso_sesiones SET estado = ? WHERE id = ?')->execute([$estado, $sesion['id']]);
            $tipo = $estado === 'cancelada' ? 'Sesión cancelada' : ($sesion['estado'] === 'cancelada' ? 'Sesión restaurada' : 'Sesión impartida');
            cengi_pp_log($db, $plan, $tipo, 'Sesión del ' . cengi_pp_fecha($sesion['fecha']) . ' — ' . $sesion['tema'], [
                'antes' => $sesion['estado'], 'despues' => $estado, 'sesion_id' => (int) $sesion['id'],
                'afecta' => $tipo !== 'Sesión impartida' && cengi_pp_convocado($plan) && $sesion['fecha'] >= $hoy,
            ]);
            $codigoMensaje = $estado;
        } elseif ($accion === 'eliminar_sesion') {
            $sesion = cengi_ses_obtener($db, (int) ($_POST['id'] ?? 0));
            if (!$sesion) {
                throw new InvalidArgumentException('La sesión ya no existe.');
            }
            if ($sesion['liquidacion_id'] || $sesion['liquidacion_soporte_id']) {
                throw new InvalidArgumentException('No se puede eliminar: la sesión está en una liquidación de pago.');
            }
            $plan = cengi_pp_plan($db, $sesion['planificacion_id']);
            $db->prepare('DELETE FROM curso_sesiones WHERE id = ?')->execute([$sesion['id']]);
            cengi_pp_log($db, $plan, 'Sesión eliminada', 'Sesión del ' . cengi_pp_fecha($sesion['fecha']) . ' — ' . $sesion['tema'], [
                'antes' => cengi_pp_fecha($sesion['fecha']) . ' ' . cengi_pp_horario($sesion['hora_inicio'], $sesion['hora_fin']), 'despues' => 'Eliminada',
                'afecta' => cengi_pp_convocado($plan) && $sesion['estado'] === 'programada' && $sesion['fecha'] >= $hoy,
            ]);
            $codigoMensaje = 'eliminada';
        } elseif ($accion === 'generar') {
            $plan = cengi_pp_plan($db, (int) ($_POST['planificacion_id'] ?? 0));
            if (!$plan || $plan['estado'] === 'cancelado') {
                throw new InvalidArgumentException('Selecciona un curso vigente.');
            }
            $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['dias'] ?? [])), static function ($d) {
                return $d >= 0 && $d <= 6;
            })));
            $hi = (string) ($_POST['hora_inicio'] ?? '');
            $hf = (string) ($_POST['hora_fin'] ?? '');
            if (!cengi_pp_es_hora($hi) || !cengi_pp_es_hora($hf) || $hf <= $hi) {
                throw new InvalidArgumentException('La hora de fin debe ser mayor que la de inicio.');
            }
            $fechas = cengi_ses_fechas_generador($plan, (string) ($_POST['desde'] ?? ''), min(200, (int) ($_POST['cantidad'] ?? 0)), $dias, max(1, min(4, (int) ($_POST['frecuencia'] ?? 1))));
            if (!$fechas) {
                throw new InvalidArgumentException('No hay fechas para crear con ese patrón. Revisa los días, la cantidad y el rango del curso.');
            }
            $modulo = mb_substr(trim((string) ($_POST['modulo'] ?? '')), 0, 255, 'UTF-8') ?: 'Sesión única';
            $base = mb_substr(trim((string) ($_POST['tema'] ?? '')), 0, 240, 'UTF-8') ?: $modulo;
            $modalidad = in_array($_POST['modalidad'] ?? '', CENGI_PP_MODALIDADES, true) ? $_POST['modalidad'] : $plan['modalidad'];
            $lugar = mb_substr(trim((string) ($_POST['lugar'] ?? '')), 0, 255, 'UTF-8') ?: cengi_pp_lugar_por_defecto($modalidad);
            $instructorId = (int) ($_POST['instructor_id'] ?? 0) ?: null;
            $soporteId = (int) ($_POST['soporte_id'] ?? 0) ?: null;
            $stmt = $db->prepare('INSERT INTO curso_sesiones (planificacion_id, modulo, tema, fecha, hora_inicio, hora_fin, instructor_id, soporte_id, modalidad, lugar, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($fechas as $n => $fecha) {
                $stmt->execute([$plan['id'], $modulo, count($fechas) > 1 ? $base . ' (' . ($n + 1) . ')' : $base, $fecha,
                    substr($hi, 0, 5) . ':00', substr($hf, 0, 5) . ':00', $instructorId, $soporteId, $modalidad, $lugar, $fecha < $hoy ? 'impartida' : 'programada']);
            }
            cengi_pp_log($db, $plan, 'Sesiones agregadas', count($fechas) . ' sesión(es) generadas: ' . implode(', ', array_map('cengi_pp_fecha_corta', $fechas)), [
                'despues' => cengi_pp_horario($hi, $hf) . ' · ' . (cengi_ses_nombre($db, 'instructores', $instructorId) ?: 'Sin instructor'),
                'afecta' => cengi_pp_convocado($plan) && end($fechas) >= $hoy,
            ]);
            $extra['ver'] = $plan['id'];
            $codigoMensaje = 'generadas';
        } elseif ($accion === 'guardar_soporte') {
            $id = (int) ($_POST['id'] ?? 0);
            $v = [
                'nombre' => mb_substr(trim((string) ($_POST['nombre'] ?? '')), 0, 255, 'UTF-8'),
                'tipo' => ($_POST['tipo'] ?? '') === 'Externo' ? 'Externo' : 'Interno',
                'correo' => trim((string) ($_POST['correo'] ?? '')),
                'telefono' => mb_substr(trim((string) ($_POST['telefono'] ?? '')), 0, 50, 'UTF-8'),
                'tarifa' => (float) ($_POST['tarifa'] ?? -1),
                'estado' => !empty($_POST['estado']) ? 1 : 0,
                'notas' => mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 500, 'UTF-8'),
            ];
            if (mb_strlen($v['nombre'], 'UTF-8') < 4 || ($v['correo'] !== '' && !filter_var($v['correo'], FILTER_VALIDATE_EMAIL)) || $v['tarifa'] < 0) {
                $reabrir = ['modal' => 'soporte', 'datos' => $_POST];
                throw new InvalidArgumentException('Revisa el nombre, el correo y la tarifa por sesión (0 o mayor).');
            }
            if ($id) {
                $columnas = implode(', ', array_map(static function ($c) {
                    return $c . ' = ?';
                }, array_keys($v)));
                $db->prepare("UPDATE soporte_tecnico SET $columnas WHERE id = ?")->execute(array_merge(array_values($v), [$id]));
            } else {
                $siguiente = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 5) AS UNSIGNED)), 0) + 1 FROM soporte_tecnico WHERE codigo LIKE 'SOP-%'")->fetchColumn();
                $v['codigo'] = 'SOP-' . str_pad((string) $siguiente, 3, '0', STR_PAD_LEFT);
                $columnas = array_keys($v);
                $db->prepare('INSERT INTO soporte_tecnico (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')->execute(array_values($v));
            }
            $extra['soporte'] = 1;
            $codigoMensaje = 'soporte';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }

        $db->commit();
        $limpio = array_diff_key($_GET, array_flip(['editar', 'generar', 'soporte', 'msg']));
        $_GET = $limpio;
        cengi_pp_redirigir('programa_sesiones.php', $extra + ['msg' => $codigoMensaje]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof DomainException) {
            $mensajeTipo = 'warning';
            $mensaje = '';
        } else {
            $mensajeTipo = 'error';
            if ($e instanceof InvalidArgumentException) {
                $mensaje = $e->getMessage();
            } elseif ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                $mensaje = 'Ya existe un registro con ese nombre o código.';
            } else {
                error_log('Programa de sesiones: ' . $e->getMessage());
                $mensaje = 'No fue posible guardar los cambios.';
            }
            if ($accion === 'guardar_sesion' && !$reabrir) {
                $reabrir = ['modal' => 'sesion', 'datos' => $_POST, 'error' => $mensaje];
                $mensaje = '';
            }
            if ($accion === 'generar') {
                $reabrir = ['modal' => 'generar', 'datos' => $_POST, 'error' => $mensaje];
                $mensaje = '';
            }
            if ($reabrir && $reabrir['modal'] === 'soporte') {
                $reabrir['error'] = $mensaje;
                $mensaje = '';
            }
        }
    }
}

if ($mensaje === '' && ($_GET['msg'] ?? '') === 'importado') {
    $mensaje = cengi_pp_h('Carga masiva aplicada: ' . (int) ($_GET['n'] ?? 0) . ' sesión(es) importada(s). Si el curso tiene inscritos, revisa la bitácora para avisarles.');
} elseif ($mensaje === '' && isset(CENGI_SES_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_SES_MENSAJES[$_GET['msg']]);
}

// ---------------------------------------------------------------- datos
$gruposPlanes = cengi_pp_planes_opciones($db, true);
$ver = (string) ($_GET['ver'] ?? $_GET['plan'] ?? '');
$sesionEditar = (int) ($_GET['editar'] ?? 0) ? cengi_ses_obtener($db, (int) $_GET['editar']) : null;
if ($sesionEditar && $ver === '') {
    $ver = (string) $sesionEditar['planificacion_id'];
}
if ($ver === '' || ($ver[0] !== 'z' && !ctype_digit($ver))) {
    $ver = 'z:' . cengi_pp_anio_actual();
}
$planSel = ctype_digit($ver) ? cengi_pp_plan($db, (int) $ver) : null;
if (!$planSel && ctype_digit($ver)) {
    $ver = 'z:' . cengi_pp_anio_actual();
}
$anioSel = $planSel ? (int) $planSel['anio'] : (int) substr($ver, 2);
$modalidadFiltro = in_array($_GET['modalidad'] ?? '', CENGI_PP_MODALIDADES, true) ? $_GET['modalidad'] : '';
$estadoFiltro = array_key_exists($_GET['estado'] ?? '', CENGI_PP_ESTADOS_SESION) ? $_GET['estado'] : '';
$busqueda = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT s.*, p.nombre AS plan_nombre, p.fecha_inicio AS plan_fi, p.fecha_fin AS plan_ff, p.estado AS plan_estado,
           i.nombre AS instructor_nombre, i.codigo_catedratico, i.estado AS instructor_estado,
           st.nombre AS soporte_nombre, l.numero AS liq_numero, ls.numero AS liq_soporte_numero
    FROM curso_sesiones s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    LEFT JOIN instructores i ON i.id = s.instructor_id
    LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id
    LEFT JOIN liquidaciones l ON l.id = s.liquidacion_id
    LEFT JOIN liquidaciones ls ON ls.id = s.liquidacion_soporte_id
    WHERE " . ($planSel ? 's.planificacion_id = ?' : 'p.anio = ?') . "
    ORDER BY s.fecha, s.hora_inicio";
$stmt = $db->prepare($sql);
$stmt->execute([$planSel ? $planSel['id'] : $anioSel]);
$base = $stmt->fetchAll(PDO::FETCH_ASSOC);
$conflictos = cengi_pp_conflictos($db);
$documentacion = cengi_pp_documentacion($db);

$sesiones = array_values(array_filter($base, static function ($s) use ($modalidadFiltro, $estadoFiltro, $busqueda) {
    if (($modalidadFiltro !== '' && $s['modalidad'] !== $modalidadFiltro) || ($estadoFiltro !== '' && $s['estado'] !== $estadoFiltro)) {
        return false;
    }
    return $busqueda === '' || strpos(cengi_texto_normalizado($s['tema'] . ' ' . $s['instructor_nombre'] . ' ' . $s['modulo'] . ' ' . $s['lugar'] . ' ' . $s['plan_nombre']), cengi_texto_normalizado($busqueda)) !== false;
}));

if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Curso/Diplomado', 'Módulo', 'Tema', 'Fecha', 'Horario', 'Instructor', 'Código catedrático', 'Soporte técnico', 'Modalidad', 'Lugar de desarrollo', 'Estado']];
    foreach ($sesiones as $s) {
        $filas[] = [$s['plan_nombre'], $s['modulo'], $s['tema'], $s['fecha'], cengi_pp_horario($s['hora_inicio'], $s['hora_fin']), $s['instructor_nombre'],
            $s['codigo_catedratico'], $s['soporte_nombre'], $s['modalidad'], $s['lugar'], CENGI_PP_ESTADOS_SESION[$s['estado']][0]];
    }
    cengi_pp_csv('Programa_de_sesiones', $filas);
}

$cuenta = static function ($estado) use ($base) {
    return count(array_filter($base, static function ($s) use ($estado) {
        return $s['estado'] === $estado;
    }));
};
$nConflictos = count(array_filter($base, static function ($s) use ($conflictos) {
    return isset($conflictos['instructor'][$s['id']]);
}));
if ($planSel) {
    $activas = count($base) - $cuenta('cancelada');
    $faltan = max(0, $planSel['sesiones_planificadas'] - $activas);
    $resumen = [['Planificadas', (int) $planSel['sesiones_planificadas'], ''], ['Programadas', $activas, ''], ['Impartidas', $cuenta('impartida'), '#3E7A12'],
        ['Por programar', $faltan, $faltan ? '#8A6600' : ''], ['Conflictos', $nConflictos, $nConflictos ? '#B23223' : ''],
        ['Rango del curso', '<small>' . cengi_pp_fecha_corta($planSel['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($planSel['fecha_fin']) . '</small>', '']];
} else {
    $resumen = [['Sesiones en el año', count($base), ''], ['Programadas', $cuenta('programada'), ''], ['Impartidas', $cuenta('impartida'), '#3E7A12'],
        ['Canceladas', $cuenta('cancelada'), ''], ['Conflictos', $nConflictos, $nConflictos ? '#B23223' : '']];
}

// Datos para los modales (JS)
$planesJs = [];
$stmtPlanes = $db->query("
    SELECT p.*, COALESCE(SUM(s.estado <> 'cancelada'), 0) AS activas, MAX(IF(s.estado <> 'cancelada', s.fecha, NULL)) AS ultima
    FROM planificacion_cursos p LEFT JOIN curso_sesiones s ON s.planificacion_id = p.id
    GROUP BY p.id ORDER BY p.anio DESC, p.fecha_inicio
");
foreach ($stmtPlanes->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $planesJs[(int) $p['id']] = [
        'nombre' => $p['nombre'], 'estado' => $p['estado'], 'fi' => $p['fecha_inicio'], 'ff' => $p['fecha_fin'],
        'hi' => substr($p['hora_inicio'], 0, 5), 'hf' => substr($p['hora_fin'], 0, 5), 'modalidad' => $p['modalidad'],
        'instructor' => $p['instructor_id'] ? (int) $p['instructor_id'] : '', 'sesiones' => (int) $p['sesiones_planificadas'],
        'activas' => (int) $p['activas'], 'ultima' => $p['ultima'], 'modulos' => cengi_pp_modulos_plan($db, $p),
    ];
}
$ocupadas = $db->query("SELECT id, fecha, TIME_FORMAT(hora_inicio, '%H:%i') AS hi, TIME_FORMAT(hora_fin, '%H:%i') AS hf, instructor_id, soporte_id, tema
    FROM curso_sesiones WHERE estado <> 'cancelada' AND fecha >= CURDATE() - INTERVAL 60 DAY")->fetchAll(PDO::FETCH_ASSOC);
$instructores = cengi_pp_instructores($db, false);
$soportes = cengi_pp_soportes($db, false);
$soportesUso = $db->query("SELECT soporte_id, SUM(estado = 'impartida') AS impartidas, SUM(estado = 'programada') AS programadas FROM curso_sesiones
    WHERE soporte_id IS NOT NULL AND YEAR(fecha) = YEAR(CURDATE()) GROUP BY soporte_id")->fetchAll(PDO::FETCH_UNIQUE);

if ($sesionEditar && !$reabrir) {
    $reabrir = ['modal' => 'sesion', 'datos' => [
        'id' => $sesionEditar['id'], 'planificacion_id' => $sesionEditar['planificacion_id'], 'modulo' => $sesionEditar['modulo'], 'tema' => $sesionEditar['tema'],
        'fecha' => $sesionEditar['fecha'], 'hora_inicio' => substr($sesionEditar['hora_inicio'], 0, 5), 'hora_fin' => substr($sesionEditar['hora_fin'], 0, 5),
        'instructor_id' => $sesionEditar['instructor_id'], 'soporte_id' => $sesionEditar['soporte_id'], 'modalidad' => $sesionEditar['modalidad'],
        'lugar' => $sesionEditar['lugar'], 'estado' => $sesionEditar['estado'],
    ]];
} elseif (!$reabrir && !empty($_GET['generar'])) {
    $reabrir = ['modal' => 'generar', 'datos' => ['planificacion_id' => $planSel['id'] ?? '']];
} elseif (!$reabrir && !empty($_GET['soporte'])) {
    $reabrir = ['modal' => 'soporte', 'datos' => []];
}
$filtrosActivos = array_filter(['ver' => $ver, 'modalidad' => $modalidadFiltro, 'estado' => $estadoFiltro, 'q' => $busqueda], static function ($x) {
    return $x !== '';
});

function cengi_ses_datos_fila(array $s)
{
    return json_encode([
        'id' => (int) $s['id'], 'planificacion_id' => (int) $s['planificacion_id'], 'modulo' => $s['modulo'], 'tema' => $s['tema'], 'fecha' => $s['fecha'],
        'hora_inicio' => substr($s['hora_inicio'], 0, 5), 'hora_fin' => substr($s['hora_fin'], 0, 5), 'instructor_id' => $s['instructor_id'],
        'soporte_id' => $s['soporte_id'], 'modalidad' => $s['modalidad'], 'lugar' => $s['lugar'], 'estado' => $s['estado'],
        'liquidada' => (bool) ($s['liquidacion_id'] || $s['liquidacion_soporte_id']),
    ], JSON_UNESCAPED_UNICODE);
}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Programa de sesiones'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_pp_subnav('programa_sesiones.php'); ?>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <section class="pp-card">
        <form class="pp-toolbar" method="get">
            <div class="pp-toolbar-group">
                <select name="ver" aria-label="Curso o año" onchange="this.form.submit()">
                    <?php echo cengi_pp_options_planes($gruposPlanes ?: [cengi_pp_anio_actual() => []], $ver, 'Todos los cursos'); ?>
                </select>
                <select name="modalidad" aria-label="Modalidad" onchange="this.form.submit()">
                    <option value="">Todas las modalidades</option>
                    <?php foreach (CENGI_PP_MODALIDADES as $m): ?><option <?php echo $modalidadFiltro === $m ? 'selected' : ''; ?>><?php echo $m; ?></option><?php endforeach; ?>
                </select>
                <select name="estado" aria-label="Estado" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <?php foreach (CENGI_PP_ESTADOS_SESION as $clave => [$texto]): ?><option value="<?php echo $clave; ?>" <?php echo $estadoFiltro === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                </select>
                <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Buscar tema o instructor..." aria-label="Buscar">
            </div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="programa_sesiones.php?<?php echo cengi_pp_h(http_build_query($filtrosActivos + ['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar</a>
                <a class="btn btn-default btn-sm" href="carga_masiva_planificacion.php?tipo=ses&amp;destino=<?php echo $planSel ? (int) $planSel['id'] : cengi_pp_h(urlencode($ver)); ?>"><span class="glyphicon glyphicon-upload"></span> Cargar Excel/CSV</a>
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#soporte-modal"><span class="glyphicon glyphicon-blackboard"></span> Soporte técnico</button>
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#generar-modal"><span class="glyphicon glyphicon-calendar"></span> Generar sesiones</button>
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#sesion-modal"><span class="glyphicon glyphicon-plus"></span> Nueva sesión</button>
            </div>
        </form>
    </section>

    <div class="pp-strip">
        <?php foreach ($resumen as [$etiqueta, $valor, $color]): ?>
            <div class="pp-mini-stat"><span><?php echo $etiqueta; ?></span><strong<?php echo $color ? ' style="color:' . $color . '"' : ''; ?>><?php echo $valor; ?></strong></div>
        <?php endforeach; ?>
    </div>

    <section class="cengi-courses-section">
        <div class="pp-table-wrap">
            <table class="cengi-org-table">
                <thead><tr>
                    <th>Curso / Diplomado</th><th>Módulo</th><th>Tema</th><th>Fecha</th><th>Horario</th><th>Instructor</th>
                    <th>Cód. catedrático</th><th>Soporte técnico</th><th>Modalidad</th><th>Lugar</th><th>Estado</th>
                    <th class="is-actions"><span class="sr-only">Acciones</span></th>
                </tr></thead>
                <tbody>
                    <?php if (!$sesiones): ?>
                        <tr><td colspan="12" class="pp-empty"><?php echo $base ? 'Ninguna sesión coincide con los filtros.' : 'Aún no hay sesiones. Usa “Generar sesiones” o “Nueva sesión”.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($sesiones as $s):
                        $fuera = $s['fecha'] < $s['plan_fi'] || $s['fecha'] > $s['plan_ff'];
                        $liquidada = $s['liquidacion_id'] || $s['liquidacion_soporte_id'];
                    ?>
                        <tr class="<?php echo $s['estado'] === 'cancelada' ? 'pp-muted-row' : ''; ?>">
                            <td class="cengi-org-name" style="min-width:160px;"><strong><a href="programa_sesiones.php?ver=<?php echo (int) $s['planificacion_id']; ?>"><?php echo cengi_pp_h($s['plan_nombre']); ?></a></strong></td>
                            <td><?php echo cengi_pp_h($s['modulo']); ?></td>
                            <td style="min-width:180px;"><?php echo cengi_pp_h($s['tema']); ?>
                                <?php if (isset($conflictos['instructor'][$s['id']])): ?><span class="pp-sub"><span class="pp-chip is-danger">Choca con otra sesión del instructor</span></span><?php endif; ?>
                                <?php if ($fuera && $s['estado'] !== 'cancelada'): ?><span class="pp-sub"><span class="pp-chip is-warn">Fuera del rango del curso</span></span><?php endif; ?>
                            </td>
                            <td class="pp-num"><?php echo cengi_pp_fecha($s['fecha']); ?><span class="pp-sub"><?php echo CENGI_PP_DIAS_CORTOS[(int) date('w', strtotime($s['fecha']))]; ?></span></td>
                            <td class="pp-num"><?php echo cengi_pp_horario($s['hora_inicio'], $s['hora_fin']); ?></td>
                            <td><?php echo cengi_pp_h($s['instructor_nombre'] ?: '—'); ?>
                                <?php $docS = $s['instructor_id'] ? ($documentacion[(int) $s['instructor_id']] ?? null) : null; ?>
                                <?php if ($docS && $s['estado'] === 'programada' && !$docS['completa']): ?><span class="pp-sub"><a class="pp-chip is-yellow" href="ficha_instructor.php?id=<?php echo (int) $s['instructor_id']; ?>&amp;tab=documentos" title="<?php echo cengi_pp_h($docS['activo'] ? 'Falta: ' . implode(', ', $docS['faltan']) : 'Instructor inactivo'); ?>"><?php echo $docS['activo'] ? 'Doc. incompleta' : 'Inactivo'; ?></a></span><?php endif; ?>
                            </td>
                            <td class="pp-mono"><?php echo cengi_pp_h($s['codigo_catedratico'] ?: '—'); ?></td>
                            <td style="min-width:120px;">
                                <?php if ($s['soporte_nombre']): ?>
                                    <?php echo cengi_pp_h($s['soporte_nombre']); ?>
                                    <?php if (isset($conflictos['soporte'][$s['id']])): ?><span class="pp-sub"><span class="pp-chip is-danger">Soporte con otra sesión</span></span><?php endif; ?>
                                <?php elseif ($s['modalidad'] !== 'Presencial' && $s['estado'] === 'programada'): ?>
                                    <span class="pp-chip is-yellow">Sin soporte</span>
                                <?php else: ?><span class="pp-sub">—</span><?php endif; ?>
                            </td>
                            <td><?php echo cengi_pp_h($s['modalidad']); ?></td>
                            <td><?php echo cengi_pp_h($s['lugar']); ?></td>
                            <td><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_SESION, $s['estado']); ?>
                                <?php if ($s['liq_numero']): ?><span class="pp-sub pp-mono" title="Incluida en liquidación"><?php echo cengi_pp_h($s['liq_numero']); ?></span><?php endif; ?>
                            </td>
                            <td class="is-actions">
                                <div class="cengi-row-actions">
                                    <button type="button" class="cengi-action-btn is-edit" data-toggle="modal" data-target="#sesion-modal" data-sesion="<?php echo cengi_pp_h(cengi_ses_datos_fila($s)); ?>" data-tooltip="Editar" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span></button>
                                    <?php if (!$liquidada): ?>
                                        <?php foreach ($s['estado'] === 'programada' ? ['impartida' => ['ok-circle', 'Marcar como impartida', 'is-view'], 'cancelada' => ['ban-circle', 'Cancelar sesión', 'is-delete']]
                                            : ($s['estado'] === 'impartida' ? ['cancelada' => ['ban-circle', 'Cancelar sesión', 'is-delete']] : ['programada' => ['repeat', 'Restaurar como programada', 'is-view']]) as $nuevo => [$icono, $titulo, $clase]):
                                            if ($nuevo === 'impartida' && $s['fecha'] > $hoy) { continue; } ?>
                                            <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="estado_sesion"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>"><input type="hidden" name="estado" value="<?php echo $nuevo; ?>">
                                                <button type="submit" class="cengi-action-btn <?php echo $clase; ?>" data-tooltip="<?php echo $titulo; ?>" aria-label="<?php echo $titulo; ?>"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span></button></form>
                                        <?php endforeach; ?>
                                        <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Eliminar esta sesión?');"><input type="hidden" name="accion" value="eliminar_sesion"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                            <button type="submit" class="cengi-action-btn is-delete" data-tooltip="Eliminar" aria-label="Eliminar"><span class="glyphicon glyphicon-trash"></span></button></form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php
$opcionesInstructores = '<option value="">Sin instructor</option>';
foreach ($instructores as $i) {
    $opcionesInstructores .= '<option value="' . (int) $i['id'] . '">' . cengi_pp_h($i['nombre'] . ((int) $i['estado'] !== 1 ? ' (inactivo)' : (empty($documentacion[(int) $i['id']]['faltan']) ? '' : ' — doc. incompleta'))) . '</option>';
}
$opcionesSoporte = '<option value="">No requiere soporte técnico</option>';
foreach ($soportes as $sp) {
    $opcionesSoporte .= '<option value="' . (int) $sp['id'] . '">' . cengi_pp_h($sp['nombre'] . ' · ' . $sp['tipo'] . ' · ' . cengi_pp_q($sp['tarifa']) . '/sesión' . ((int) $sp['estado'] !== 1 ? ' (inactivo)' : '')) . '</option>';
}
$opcionesModalidad = '';
foreach (CENGI_PP_MODALIDADES as $m) {
    $opcionesModalidad .= '<option>' . $m . '</option>';
}
$opcionesPlanes = cengi_pp_options_planes(array_map(static function ($g) {
    return array_values(array_filter($g, static function ($p) {
        return $p['estado'] !== 'cancelado';
    }));
}, $gruposPlanes), $planSel['id'] ?? '');
?>

<div class="modal fade cengi-course-form-modal pp-modal" id="sesion-modal" tabindex="-1" role="dialog" aria-labelledby="sesion-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="sesion-form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Programa de sesiones</span>
                <h4 class="modal-title" id="sesion-title">Nueva sesión</h4>
                <p id="sf-rango"></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="guardar_sesion">
                <input type="hidden" name="id" id="sf-id">
                <input type="hidden" name="confirmar" id="sf-confirmar" value="">
                <div class="pp-form-msg" id="sf-msg"></div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group is-full"><label for="sf-plan">Curso / diplomado</label><select class="form-control" id="sf-plan" name="planificacion_id" required><?php echo $opcionesPlanes; ?></select></div>
                    <div class="form-group"><label for="sf-modulo">Módulo</label><select class="form-control" id="sf-modulo" name="modulo"></select></div>
                    <div class="form-group"><label for="sf-estado">Estado</label><select class="form-control" id="sf-estado" name="estado"><?php foreach (CENGI_PP_ESTADOS_SESION as $clave => [$texto]): ?><option value="<?php echo $clave; ?>"><?php echo $texto; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group is-full"><label for="sf-tema">Tema</label><input type="text" class="form-control" id="sf-tema" name="tema" maxlength="255" required></div>
                    <div class="form-group"><label for="sf-fecha">Fecha</label><input type="date" class="form-control" id="sf-fecha" name="fecha" required></div>
                    <div class="form-group"><label>Horario</label><div style="display:flex;gap:6px;"><input type="time" class="form-control" id="sf-hi" name="hora_inicio" required aria-label="Hora de inicio"><input type="time" class="form-control" id="sf-hf" name="hora_fin" required aria-label="Hora de fin"></div></div>
                    <div class="form-group"><label for="sf-instr">Instructor</label><select class="form-control" id="sf-instr" name="instructor_id"><?php echo $opcionesInstructores; ?></select></div>
                    <div class="form-group"><label for="sf-soporte">Soporte técnico</label><select class="form-control" id="sf-soporte" name="soporte_id"><?php echo $opcionesSoporte; ?></select></div>
                    <div class="form-group"><label for="sf-modalidad">Modalidad</label><select class="form-control" id="sf-modalidad" name="modalidad"><?php echo $opcionesModalidad; ?></select></div>
                    <div class="form-group"><label for="sf-lugar">Lugar o enlace</label><input type="text" class="form-control" id="sf-lugar" name="lugar" maxlength="255" required></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="sf-btn">Guardar sesión</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal" id="generar-modal" tabindex="-1" role="dialog" aria-labelledby="generar-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="generar-form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Programa de sesiones</span>
                <h4 class="modal-title" id="generar-title">Generar sesiones</h4>
                <p id="gn-rango"></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="generar">
                <div class="pp-form-msg" id="gn-msg"></div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group is-full"><label for="gn-plan">Curso / diplomado</label><select class="form-control" id="gn-plan" name="planificacion_id"><?php echo $opcionesPlanes; ?></select></div>
                    <div class="form-group"><label for="gn-modulo">Módulo</label><select class="form-control" id="gn-modulo" name="modulo"></select></div>
                    <div class="form-group"><label for="gn-tema">Tema base <small>(se numera)</small></label><input type="text" class="form-control" id="gn-tema" name="tema" maxlength="240" placeholder="Por defecto, el nombre del módulo"></div>
                    <div class="form-group"><label for="gn-desde">Desde</label><input type="date" class="form-control" id="gn-desde" name="desde"></div>
                    <div class="form-group"><label for="gn-cant">Cantidad</label><input type="number" class="form-control" id="gn-cant" name="cantidad" min="1" max="200"></div>
                    <div class="form-group is-full"><label>Días de la semana</label>
                        <div class="pp-days" id="gn-dias"><?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?><label><input type="checkbox" name="dias[]" value="<?php echo $d; ?>"> <?php echo ucfirst(CENGI_PP_DIAS_CORTOS[$d]); ?></label><?php endforeach; ?></div>
                    </div>
                    <div class="form-group"><label for="gn-frec">Frecuencia</label><select class="form-control" id="gn-frec" name="frecuencia"><option value="1">Cada semana</option><option value="2">Cada 2 semanas</option><option value="4">Cada 4 semanas</option></select></div>
                    <div class="form-group"><label>Horario</label><div style="display:flex;gap:6px;"><input type="time" class="form-control" id="gn-hi" name="hora_inicio" aria-label="Hora de inicio"><input type="time" class="form-control" id="gn-hf" name="hora_fin" aria-label="Hora de fin"></div></div>
                    <div class="form-group"><label for="gn-instr">Instructor</label><select class="form-control" id="gn-instr" name="instructor_id"><?php echo $opcionesInstructores; ?></select></div>
                    <div class="form-group"><label for="gn-soporte">Soporte técnico</label><select class="form-control" id="gn-soporte" name="soporte_id"><?php echo $opcionesSoporte; ?></select></div>
                    <div class="form-group"><label for="gn-modalidad">Modalidad</label><select class="form-control" id="gn-modalidad" name="modalidad"><?php echo $opcionesModalidad; ?></select></div>
                    <div class="form-group"><label for="gn-lugar">Lugar o enlace</label><input type="text" class="form-control" id="gn-lugar" name="lugar" maxlength="255"></div>
                    <div class="form-group is-full"><label>Vista previa</label><div class="pp-preview" id="gn-preview"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="gn-btn">Crear sesiones</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="soporte-modal" tabindex="-1" role="dialog" aria-labelledby="soporte-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            <span class="cengi-course-modal-kicker">Programa de sesiones</span>
            <h4 class="modal-title" id="soporte-title">Soporte técnico</h4>
            <p>Personal que atiende las sesiones virtuales e híbridas (o el audio en campo). Su pago por sesión impartida se liquida junto con el de los instructores.</p>
        </div>
        <div class="modal-body">
            <div class="pp-table-wrap" style="margin-bottom:16px;">
                <table class="cengi-org-table" style="min-width:640px;">
                    <thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Contacto</th><th>Tarifa / sesión</th><th>Sesiones <?php echo date('Y'); ?></th><th></th></tr></thead>
                    <tbody>
                        <?php if (!$soportes): ?><tr><td colspan="7" class="pp-empty">Aún no hay personal de soporte técnico.</td></tr><?php endif; ?>
                        <?php foreach ($soportes as $sp): $uso = $soportesUso[$sp['id']] ?? ['impartidas' => 0, 'programadas' => 0]; ?>
                            <tr class="<?php echo (int) $sp['estado'] !== 1 ? 'pp-muted-row' : ''; ?>">
                                <td class="pp-mono"><?php echo cengi_pp_h($sp['codigo']); ?></td>
                                <td><strong><?php echo cengi_pp_h($sp['nombre']); ?></strong><span class="pp-sub"><?php echo cengi_pp_h($sp['notas']); ?></span></td>
                                <td><?php echo cengi_pp_h($sp['tipo']); ?></td>
                                <td><?php echo cengi_pp_h($sp['correo']); ?><span class="pp-sub"><?php echo cengi_pp_h($sp['telefono']); ?></span></td>
                                <td class="pp-num"><?php echo cengi_pp_q($sp['tarifa'], 2); ?></td>
                                <td class="pp-num"><?php echo (int) $uso['impartidas']; ?> impartidas<span class="pp-sub"><?php echo (int) $uso['programadas']; ?> programadas</span></td>
                                <td><button type="button" class="cengi-action-btn is-edit" data-soporte="<?php echo cengi_pp_h(json_encode($sp, JSON_UNESCAPED_UNICODE)); ?>" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" id="soporte-form" autocomplete="off">
                <input type="hidden" name="accion" value="guardar_soporte">
                <input type="hidden" name="id" id="sp-id">
                <h5 id="sp-titulo" style="font-weight:700;">Agregar persona de soporte técnico</h5>
                <div class="pp-form-msg" id="sp-msg"></div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group"><label for="sp-nombre">Nombre</label><input type="text" class="form-control" id="sp-nombre" name="nombre" maxlength="255" required></div>
                    <div class="form-group"><label for="sp-tipo">Tipo</label><select class="form-control" id="sp-tipo" name="tipo"><option>Interno</option><option>Externo</option></select></div>
                    <div class="form-group"><label for="sp-correo">Correo</label><input type="email" class="form-control" id="sp-correo" name="correo" maxlength="255"></div>
                    <div class="form-group"><label for="sp-tel">Teléfono</label><input type="text" class="form-control" id="sp-tel" name="telefono" maxlength="50"></div>
                    <div class="form-group"><label for="sp-tarifa">Tarifa por sesión (Q)</label><input type="number" class="form-control" id="sp-tarifa" name="tarifa" min="0" step="0.01" value="250" required></div>
                    <div class="form-group"><label for="sp-estado">Estado</label><select class="form-control" id="sp-estado" name="estado"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
                    <div class="form-group is-full"><label for="sp-notas">Notas</label><input type="text" class="form-control" id="sp-notas" name="notas" maxlength="500" placeholder="Ej. Zoom, Teams y sonido del auditorio"></div>
                </div>
                <div style="margin-top:12px;text-align:right;">
                    <button type="button" class="btn btn-default" id="sp-nuevo">Nuevo</button>
                    <button type="submit" class="btn btn-primary" id="sp-btn">Agregar</button>
                </div>
            </form>
        </div>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var PLANES = <?php echo json_encode($planesJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var OCUPADAS = <?php echo json_encode($ocupadas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var REABRIR = <?php echo json_encode($reabrir, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var HOY = <?php echo json_encode($hoy); ?>;
    var DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    function $id(id) { return document.getElementById(id); }
    function set(id, v) { $id(id).value = v === null || v === undefined ? '' : String(v); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function addDays(iso, n) { var d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
    function dow(iso) { return new Date(iso + 'T12:00:00').getDay(); }
    function diffDays(a, b) { return Math.round((new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000); }
    function corta(iso) { var p = iso.split('-'); return p[2] + '/' + p[1]; }
    function lugarDefecto(m) { return m === 'Virtual' ? 'Enlace virtual — Teams' : m === 'Híbrida' ? 'Auditorio CENGICAÑA / Zoom' : 'Auditorio CENGICAÑA'; }
    function msg(id, tipo, html) { var m = $id(id); m.className = 'pp-form-msg' + (html ? ' is-visible is-' + tipo : ''); m.innerHTML = html || ''; }
    function modulos(selectId, planId, valor) {
        var p = PLANES[planId], s = $id(selectId); s.innerHTML = '';
        ((p && p.modulos) || ['Sesión única']).forEach(function (m) { s.add(new Option(m, m)); });
        if (valor && ![].some.call(s.options, function (o) { return o.value === valor; })) s.add(new Option(valor, valor));
        if (valor) s.value = valor;
    }

    // ---------- sesion
    function sesionPlan(keepModulo) {
        var p = PLANES[$id('sf-plan').value]; if (!p) return;
        modulos('sf-modulo', $id('sf-plan').value, keepModulo);
        $id('sf-rango').textContent = 'Rango del curso: ' + corta(p.fi) + ' – ' + corta(p.ff) + ' · ' + p.activas + ' de ' + p.sesiones + ' sesiones programadas · ' + p.modalidad;
        $id('sf-fecha').min = p.fi; $id('sf-fecha').max = p.ff;
    }
    function abrirSesion(d, avisos, error) {
        d = d || {};
        var nuevo = !d.id, planId = d.planificacion_id || $id('sf-plan').value;
        $id('sesion-form').reset();
        if (planId && ![].some.call($id('sf-plan').options, function (o) { return o.value == planId; }) && PLANES[planId]) $id('sf-plan').add(new Option(PLANES[planId].nombre, planId));
        set('sf-id', d.id); set('sf-plan', planId); set('sf-confirmar', '');
        sesionPlan(d.modulo);
        var p = PLANES[$id('sf-plan').value] || {};
        set('sf-tema', d.tema); set('sf-fecha', d.fecha);
        set('sf-hi', d.hora_inicio || p.hi); set('sf-hf', d.hora_fin || p.hf);
        set('sf-instr', d.instructor_id !== undefined ? d.instructor_id : p.instructor);
        set('sf-soporte', d.soporte_id); set('sf-modalidad', d.modalidad || p.modalidad || 'Presencial');
        set('sf-lugar', d.lugar || lugarDefecto(d.modalidad || p.modalidad)); set('sf-estado', d.estado || 'programada');
        $id('sesion-title').textContent = nuevo ? 'Nueva sesión' : 'Editar sesión';
        $id('sf-btn').textContent = 'Guardar sesión';
        msg('sf-msg', '', '');
        if (d.liquidada) msg('sf-msg', 'warn', 'Esta sesión ya está en una liquidación de pago: solo puedes cambiar tema, lugar u horario.');
        if (error) msg('sf-msg', 'error', error);
        if (avisos && avisos.length) {
            msg('sf-msg', 'warn', '<b>Antes de guardar:</b><br>' + avisos.join('<br>') + '<br>Pulsa “Guardar de todos modos” para confirmar, o ajusta los datos.');
            set('sf-confirmar', '1'); $id('sf-btn').textContent = 'Guardar de todos modos';
        }
    }
    $('#sesion-modal').on('show.bs.modal', function (e) {
        if (!e.relatedTarget) return; // abierto por REABRIR
        var d = {}; try { d = JSON.parse(e.relatedTarget.getAttribute('data-sesion') || '{}'); } catch (x) {}
        abrirSesion(d);
    });
    $id('sf-plan').addEventListener('change', function () { sesionPlan(); var p = PLANES[this.value]; if (p) { set('sf-hi', p.hi); set('sf-hf', p.hf); set('sf-modalidad', p.modalidad); set('sf-lugar', lugarDefecto(p.modalidad)); set('sf-instr', p.instructor); } });
    $id('sf-modalidad').addEventListener('change', function () { set('sf-lugar', lugarDefecto(this.value)); });
    ['sf-tema', 'sf-fecha', 'sf-hi', 'sf-hf', 'sf-instr', 'sf-soporte', 'sf-lugar', 'sf-modalidad', 'sf-estado'].forEach(function (id) {
        $id(id).addEventListener('input', function () { if ($id('sf-confirmar').value) { set('sf-confirmar', ''); $id('sf-btn').textContent = 'Guardar sesión'; msg('sf-msg', '', ''); } });
    });

    // ---------- generador
    function fechasGenerador() {
        var p = PLANES[$id('gn-plan').value], desde = $id('gn-desde').value, cant = +$id('gn-cant').value, frec = +$id('gn-frec').value;
        var dias = [].map.call(document.querySelectorAll('#gn-dias input:checked'), function (c) { return +c.value; });
        var fechas = [];
        if (!p || !desde || !(cant >= 1) || !dias.length) return { fechas: fechas, p: p, cant: cant, dias: dias };
        var lunes = addDays(desde, -((dow(desde) + 6) % 7));
        for (var d = desde < p.fi ? p.fi : desde; d <= p.ff && fechas.length < cant; d = addDays(d, 1)) {
            if (dias.indexOf(dow(d)) >= 0 && Math.floor(diffDays(lunes, d) / 7) % frec === 0) fechas.push(d);
        }
        return { fechas: fechas, p: p, cant: cant, dias: dias };
    }
    function previewGenerador() {
        var r = fechasGenerador(), hi = $id('gn-hi').value, hf = $id('gn-hf').value, instr = $id('gn-instr').value, sop = $id('gn-soporte').value;
        msg('gn-msg', '', '');
        if (!r.dias.length) { $id('gn-preview').innerHTML = '<span class="pp-sub">Elige al menos un día de la semana.</span>'; return; }
        $id('gn-preview').innerHTML = r.fechas.length ? r.fechas.map(function (f, i) {
            var choque = OCUPADAS.filter(function (o) { return o.fecha === f && hi < o.hf && o.hi < hf && ((instr && o.instructor_id == instr) || (sop && o.soporte_id == sop)); })[0];
            return '<div><span><b>' + (i + 1) + '.</b> ' + corta(f) + ' · ' + DIAS[dow(f)] + ' · ' + hi + '–' + hf + '</span>' +
                (choque ? '<span class="pp-chip is-danger">Choca con ' + esc(choque.tema) + '</span>' : '<span class="pp-chip is-ok">Libre</span>') + '</div>';
        }).join('') : '<span class="pp-sub">No hay fechas disponibles con ese patrón dentro del rango del curso.</span>';
        if (r.p && r.fechas.length && r.fechas.length < r.cant) msg('gn-msg', 'warn', 'Solo caben ' + r.fechas.length + ' de ' + r.cant + ' sesiones antes de la fecha final del curso (' + corta(r.p.ff) + '). Cambia la frecuencia, los días o reprograma el curso.');
        $id('gn-btn').textContent = r.fechas.length ? 'Crear ' + r.fechas.length + ' sesión(es)' : 'Crear sesiones';
        $id('gn-btn').disabled = !r.fechas.length;
    }
    function generadorPlan() {
        var p = PLANES[$id('gn-plan').value]; if (!p) return;
        modulos('gn-modulo', $id('gn-plan').value);
        var desde = p.ultima && p.ultima >= p.fi ? addDays(p.ultima, 1) : p.fi;
        if (desde > p.ff) desde = p.fi;
        set('gn-desde', desde); $id('gn-desde').min = p.fi; $id('gn-desde').max = p.ff;
        set('gn-cant', Math.max(1, p.sesiones - p.activas)); set('gn-hi', p.hi); set('gn-hf', p.hf);
        set('gn-instr', p.instructor); set('gn-modalidad', p.modalidad); set('gn-lugar', lugarDefecto(p.modalidad)); set('gn-tema', '');
        var dia = dow(p.fi), corto = diffDays(p.fi, p.ff) < 7 && p.sesiones > 1;
        document.querySelectorAll('#gn-dias input').forEach(function (c) { c.checked = corto ? (+c.value >= 1 && +c.value <= 5) : +c.value === dia; });
        set('gn-frec', diffDays(p.fi, p.ff) > 120 && p.sesiones < 30 ? '2' : '1');
        $id('gn-rango').textContent = 'Rango: ' + corta(p.fi) + ' – ' + corta(p.ff) + ' · ' + p.activas + ' de ' + p.sesiones + ' sesiones programadas' + (p.ultima ? ' · última: ' + corta(p.ultima) : '');
        previewGenerador();
    }
    $('#generar-modal').on('show.bs.modal', function (e) { if (e.relatedTarget) generadorPlan(); });
    $id('gn-plan').addEventListener('change', generadorPlan);
    $id('gn-modalidad').addEventListener('change', function () { set('gn-lugar', lugarDefecto(this.value)); });
    ['gn-desde', 'gn-cant', 'gn-frec', 'gn-hi', 'gn-hf', 'gn-instr', 'gn-soporte'].forEach(function (id) { $id(id).addEventListener('input', previewGenerador); $id(id).addEventListener('change', previewGenerador); });
    document.querySelectorAll('#gn-dias input').forEach(function (c) { c.addEventListener('change', previewGenerador); });

    // ---------- soporte tecnico
    function editarSoporte(sp) {
        sp = sp || {};
        $id('soporte-form').reset();
        set('sp-id', sp.id); set('sp-nombre', sp.nombre); set('sp-tipo', sp.tipo || 'Interno'); set('sp-correo', sp.correo);
        set('sp-tel', sp.telefono); set('sp-tarifa', sp.tarifa !== undefined ? sp.tarifa : 250); set('sp-estado', sp.estado !== undefined ? +sp.estado : 1); set('sp-notas', sp.notas);
        $id('sp-titulo').textContent = sp.id ? 'Editar ' + sp.nombre : 'Agregar persona de soporte técnico';
        $id('sp-btn').textContent = sp.id ? 'Guardar cambios' : 'Agregar';
        msg('sp-msg', '', '');
    }
    document.querySelectorAll('[data-soporte]').forEach(function (b) { b.addEventListener('click', function () { editarSoporte(JSON.parse(b.getAttribute('data-soporte'))); $id('sp-nombre').focus(); }); });
    $id('sp-nuevo').addEventListener('click', function () { editarSoporte(); });
    $('#soporte-modal').on('show.bs.modal', function (e) { if (e.relatedTarget) editarSoporte(); });

    ['sesion-form', 'generar-form', 'soporte-form'].forEach(function (id) {
        $id(id).addEventListener('submit', function () { var b = this.querySelector('button[type="submit"]'); setTimeout(function () { b.disabled = true; }, 0); });
    });

    if (REABRIR) {
        if (REABRIR.modal === 'sesion') { abrirSesion(REABRIR.datos, REABRIR.avisos, REABRIR.error); $('#sesion-modal').modal('show'); }
        if (REABRIR.modal === 'generar') {
            if (REABRIR.datos.planificacion_id) set('gn-plan', REABRIR.datos.planificacion_id);
            generadorPlan();
            if (REABRIR.error) msg('gn-msg', 'error', REABRIR.error);
            $('#generar-modal').modal('show');
        }
        if (REABRIR.modal === 'soporte') {
            var d = REABRIR.datos || {};
            editarSoporte(d.nombre !== undefined ? { id: d.id, nombre: d.nombre, tipo: d.tipo, correo: d.correo, telefono: d.telefono, tarifa: d.tarifa, estado: d.estado, notas: d.notas } : null);
            if (REABRIR.error) msg('sp-msg', 'error', REABRIR.error);
            $('#soporte-modal').modal('show');
        }
    }
})();
</script>
</body>
</html>
