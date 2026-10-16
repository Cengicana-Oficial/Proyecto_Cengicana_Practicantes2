<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';

const CENGI_PLAN_MENSAJES = [
    'creado' => 'Planificación guardada como planificada.',
    'actualizado' => 'Planificación actualizada.',
    'aprobado' => 'Planificación aprobada: la convocatoria está abierta en el formulario de inscripción. El curso pasa a Cursos al completar el cupo mínimo de inscripciones validadas.',
    'curso_abierto' => 'Curso abierto: ya aparece en Cursos.',
    'reprogramado' => 'Curso reprogramado. Revisa la bitácora para notificar a los inscritos.',
    'cancelado' => 'Planificación cancelada.',
    'reactivado' => 'Planificación reactivada como planificada.',
    'eliminado' => 'Planificación eliminada.',
    'duplicado' => 'Planificación duplicada en el año siguiente.',
    'sin_cambios' => 'No hubo cambios que guardar.',
];

/** Lee y valida el formulario de planificacion. */
function cengi_plan_leer_form(PDO $db)
{
    $texto = static function ($campo, $max) {
        $valor = trim((string) ($_POST[$campo] ?? ''));
        if (mb_strlen($valor, 'UTF-8') > $max) {
            throw new InvalidArgumentException('El campo "' . $campo . '" es demasiado largo.');
        }
        return $valor;
    };
    $monto = static function ($campo, $etiqueta) {
        $valor = str_replace(',', '', trim((string) ($_POST[$campo] ?? '')));
        if ($valor === '') {
            return 0.0;
        }
        if (!is_numeric($valor) || (float) $valor < 0 || (float) $valor > 9999999) {
            throw new InvalidArgumentException('El costo para ' . $etiqueta . ' debe ser 0 o mayor.');
        }
        return round((float) $valor, 2);
    };

    $v = [
        'anio' => (int) ($_POST['anio'] ?? 0),
        'nombre' => $texto('nombre', 255),
        'categoria_curso_id' => (int) ($_POST['categoria_curso_id'] ?? 0) ?: null,
        'area_tecnica' => $texto('area_tecnica', 120),
        'actividad_tipo' => ($_POST['actividad_tipo'] ?? '') === 'evento_tecnico' ? 'evento_tecnico' : 'formacion_academica',
        'modalidad' => $texto('modalidad', 60),
        'dirigido_a' => $texto('dirigido_a', 255) ?: 'Todos los ingenios',
        'fecha_inicio' => (string) ($_POST['fecha_inicio'] ?? ''),
        'fecha_fin' => (string) ($_POST['fecha_fin'] ?? ''),
        'hora_inicio' => (string) ($_POST['hora_inicio'] ?? ''),
        'hora_fin' => (string) ($_POST['hora_fin'] ?? ''),
        'dias' => $texto('dias', 255),
        'sesiones_planificadas' => (int) ($_POST['sesiones_planificadas'] ?? 0),
        'cupo_minimo' => (int) ($_POST['cupo_minimo'] ?? 0),
        'cupo_maximo' => trim((string) ($_POST['cupo_maximo'] ?? '')) === '' ? null : (int) $_POST['cupo_maximo'],
        'costo_asociados' => $monto('costo_asociados', 'asociados'),
        'costo_externos' => $monto('costo_externos', 'externos'),
        'costo_extranjero' => $monto('costo_extranjero', 'extranjeros'),
        'instructor_id' => (int) ($_POST['instructor_id'] ?? 0) ?: null,
        'modulos' => $texto('modulos', 5000),
        'observaciones' => $texto('observaciones', 5000),
    ];

    $errores = [];
    if ($v['anio'] < 2000 || $v['anio'] > 2100) {
        $errores[] = 'Selecciona el año.';
    }
    if (mb_strlen($v['nombre'], 'UTF-8') < 3) {
        $errores[] = 'Escribe el nombre del curso o diplomado.';
    }
    if (!cengi_pp_es_fecha($v['fecha_inicio']) || !cengi_pp_es_fecha($v['fecha_fin'])) {
        $errores[] = 'Indica la fecha inicial y la final.';
    } elseif ($v['fecha_fin'] < $v['fecha_inicio']) {
        $errores[] = 'La fecha final no puede ser anterior a la inicial.';
    }
    if (!cengi_pp_es_hora($v['hora_inicio']) || !cengi_pp_es_hora($v['hora_fin']) || $v['hora_fin'] <= $v['hora_inicio']) {
        $errores[] = 'Indica el horario: la hora final debe ser mayor que la inicial.';
    }
    if ($v['modalidad'] === '') {
        $errores[] = 'Selecciona la modalidad.';
    }
    if ($v['sesiones_planificadas'] < 1 || $v['sesiones_planificadas'] > 999) {
        $errores[] = 'El número de sesiones debe ser 1 o más.';
    }
    if ($v['cupo_minimo'] < 1 || $v['cupo_minimo'] > 10000) {
        $errores[] = 'El cupo mínimo debe ser 1 o más.';
    }
    if ($v['cupo_maximo'] !== null && ($v['cupo_maximo'] < $v['cupo_minimo'] || $v['cupo_maximo'] > 10000)) {
        $errores[] = 'El cupo máximo no puede ser menor que el mínimo.';
    }
    if ($errores) {
        throw new InvalidArgumentException(implode('<br>', array_map('cengi_pp_h', $errores)));
    }
    $v['hora_inicio'] = substr($v['hora_inicio'], 0, 5) . ':00';
    $v['hora_fin'] = substr($v['hora_fin'], 0, 5) . ':00';
    return $v;
}

/** Diferencias entre el plan guardado y el formulario: [tipo, campo, antes, despues]. */
function cengi_plan_diferencias(PDO $db, array $p, array $v)
{
    $nombre = static function ($tabla, $columna, $id) use ($db) {
        if (!$id) {
            return '—';
        }
        $stmt = $db->prepare("SELECT $columna FROM $tabla WHERE id = ?");
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    };
    $q = static function ($fila) {
        return 'Q' . number_format((float) $fila['costo_asociados'], 2) . ' / Q' . number_format((float) $fila['costo_externos'], 2) . ' / US$' . number_format((float) $fila['costo_extranjero'], 2);
    };
    $cambios = [
        ['Reprogramación', 'Fechas del curso', cengi_pp_fecha($p['fecha_inicio']) . ' – ' . cengi_pp_fecha($p['fecha_fin']), cengi_pp_fecha($v['fecha_inicio']) . ' – ' . cengi_pp_fecha($v['fecha_fin'])],
        ['Cambio de horario', 'Horario del curso', cengi_pp_horario($p['hora_inicio'], $p['hora_fin']), cengi_pp_horario($v['hora_inicio'], $v['hora_fin'])],
        ['Cambio de modalidad', 'Modalidad', $p['modalidad'], $v['modalidad']],
        ['Cambio de instructor responsable', 'Instructor responsable', $nombre('instructores', 'nombre', $p['instructor_id']), $nombre('instructores', 'nombre', $v['instructor_id'])],
        ['Edición', 'Nombre', $p['nombre'], $v['nombre']],
        ['Ajuste de sesiones', 'N.° de sesiones planificadas', $p['sesiones_planificadas'], $v['sesiones_planificadas']],
        ['Cambio de costos', 'Costos (asoc./ext./US$)', $q($p), $q($v)],
        ['Edición', 'Cupo mínimo', $p['cupo_minimo'], $v['cupo_minimo']],
        ['Edición', 'Cupo máximo', $p['cupo_maximo'] ?? '—', $v['cupo_maximo'] ?? '—'],
        ['Edición', 'Días de desarrollo', $p['dias'], $v['dias']],
        ['Edición', 'Dirigido a', $p['dirigido_a'], $v['dirigido_a']],
        ['Edición', 'Categoría', $nombre('categorias_cursos', 'descripcion_categorias_cursos', $p['categoria_curso_id']), $nombre('categorias_cursos', 'descripcion_categorias_cursos', $v['categoria_curso_id'])],
        ['Edición', 'Área técnica', $p['area_tecnica'], $v['area_tecnica']],
        ['Edición', 'Tipo de actividad', $p['actividad_tipo'], $v['actividad_tipo']],
        ['Edición', 'Módulos', trim((string) $p['modulos']), trim((string) $v['modulos'])],
        ['Edición', 'Observaciones', trim((string) $p['observaciones']) ?: '—', trim((string) $v['observaciones']) ?: '—'],
        ['Edición', 'Año', cengi_pp_anio_texto($p['anio']), cengi_pp_anio_texto($v['anio'])],
    ];
    return array_values(array_filter($cambios, static function ($c) {
        return (string) $c[2] !== (string) $c[3];
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');
    $codigoMensaje = '';
    $extra = [];

    try {
        $db->beginTransaction();
        $plan = $id > 0 ? cengi_pp_plan($db, $id) : null;
        if ($id > 0 && !$plan) {
            throw new InvalidArgumentException('La planificación ya no existe.');
        }

        if ($accion === 'guardar') {
            $v = cengi_plan_leer_form($db);
            $aprobar = !empty($_POST['aprobar']);
            if (!$plan) {
                $v['creado_por'] = cengi_usuario_actual_id() ?: null;
                $v['creado_por_nombre'] = cengi_pp_usuario_nombre();
                $columnas = array_keys($v);
                $db->prepare('INSERT INTO planificacion_cursos (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')
                    ->execute(array_values($v));
                $plan = cengi_pp_plan($db, (int) $db->lastInsertId());
                cengi_pp_log($db, $plan, 'Creación', 'Plan registrado en el año ' . cengi_pp_anio_texto($plan['anio']) . '.');
                $codigoMensaje = 'creado';
            } else {
                $diferencias = cengi_plan_diferencias($db, $plan, $v);
                $afectan = array_filter($diferencias, static function ($d) {
                    return in_array($d[0], CENGI_PP_TIPOS_AFECTAN, true);
                });
                if ($afectan && cengi_pp_convocado($plan) && $motivo === '') {
                    throw new InvalidArgumentException('Cambiaste ' . cengi_pp_h(implode(', ', array_map(static function ($d) {
                        return mb_strtolower($d[1], 'UTF-8');
                    }, $afectan))) . ' de un curso aprobado. Escribe el motivo: queda en la bitácora y en el aviso a los inscritos.');
                }
                $asignaciones = implode(', ', array_map(static function ($c) {
                    return $c . ' = ?';
                }, array_keys($v)));
                $db->prepare('UPDATE planificacion_cursos SET ' . $asignaciones . ' WHERE id = ?')->execute(array_merge(array_values($v), [$id]));
                foreach ($diferencias as [$tipo, $campo, $antes, $despues]) {
                    cengi_pp_log($db, $plan, $tipo, $campo, ['antes' => $antes, 'despues' => $despues, 'motivo' => $motivo]);
                }
                if (cengi_pp_convocado($plan) && array_filter($diferencias, static function ($d) {
                    return $d[0] === 'Reprogramación';
                })) {
                    $db->prepare("UPDATE planificacion_cursos SET estado = 'reprogramado' WHERE id = ?")->execute([$id]);
                }
                cengi_pp_sincronizar_curso($db, $id);
                $plan = cengi_pp_plan($db, $id);
                $codigoMensaje = $diferencias ? 'actualizado' : 'sin_cambios';
            }
            if ($aprobar && !cengi_pp_convocado($plan)) {
                cengi_pp_aprobar($db, $plan);
                $codigoMensaje = 'aprobado';
            }
            $extra['anio'] = $plan['anio'];
        } elseif ($accion === 'aprobar') {
            if ($plan['estado'] === 'cancelado') {
                throw new InvalidArgumentException('Reactiva la planificación antes de aprobarla.');
            }
            cengi_pp_aprobar($db, $plan);
            $codigoMensaje = 'aprobado';
        } elseif ($accion === 'reprogramar') {
            $fi = (string) ($_POST['fecha_inicio'] ?? '');
            $ff = (string) ($_POST['fecha_fin'] ?? '');
            if ($motivo === '') {
                throw new InvalidArgumentException('Escribe el motivo de la reprogramación: queda en el historial del plan.');
            }
            if (!cengi_pp_es_fecha($fi) || !cengi_pp_es_fecha($ff) || $ff < $fi) {
                throw new InvalidArgumentException('Revisa las fechas: la final no puede ser anterior a la inicial.');
            }
            if ($fi === $plan['fecha_inicio'] && $ff === $plan['fecha_fin']) {
                throw new InvalidArgumentException('Las fechas son iguales a las actuales.');
            }
            $desplazamiento = cengi_pp_dias_entre($plan['fecha_inicio'], $fi);
            $movidas = 0;
            if (!empty($_POST['mover_sesiones']) && $desplazamiento !== 0) {
                $stmt = $db->prepare("UPDATE curso_sesiones SET fecha = DATE_ADD(fecha, INTERVAL ? DAY) WHERE planificacion_id = ? AND estado = 'programada' AND liquidacion_id IS NULL");
                $stmt->execute([$desplazamiento, $id]);
                $movidas = $stmt->rowCount();
            }
            $db->prepare("UPDATE planificacion_cursos SET fecha_inicio = ?, fecha_fin = ?, estado = IF(estado IN ('aprobado', 'reprogramado'), 'reprogramado', estado) WHERE id = ?")
                ->execute([$fi, $ff, $id]);
            cengi_pp_log($db, $plan, 'Reprogramación', 'Fechas del curso' . ($movidas ? ' (' . $movidas . ' sesión(es) movidas ' . sprintf('%+d', $desplazamiento) . ' días)' : ''), [
                'antes' => cengi_pp_fecha($plan['fecha_inicio']) . ' – ' . cengi_pp_fecha($plan['fecha_fin']),
                'despues' => cengi_pp_fecha($fi) . ' – ' . cengi_pp_fecha($ff), 'motivo' => $motivo,
            ]);
            cengi_pp_sincronizar_curso($db, $id);
            $codigoMensaje = 'reprogramado';
        } elseif ($accion === 'cancelar') {
            if ($motivo === '') {
                throw new InvalidArgumentException('Escribe el motivo de la cancelación.');
            }
            $stmt = $db->prepare("UPDATE curso_sesiones SET estado = 'cancelada' WHERE planificacion_id = ? AND estado = 'programada'");
            $stmt->execute([$id]);
            $canceladas = $stmt->rowCount();
            $db->prepare("UPDATE planificacion_cursos SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
            cengi_pp_log($db, $plan, 'Curso cancelado', 'Se canceló la planificación' . ($canceladas ? ' y ' . $canceladas . ' sesión(es) programada(s)' : ''), [
                'antes' => ucfirst($plan['estado']), 'despues' => 'Cancelado', 'motivo' => $motivo,
                'afecta' => cengi_pp_convocado($plan),
            ]);
            $codigoMensaje = 'cancelado';
        } elseif ($accion === 'reactivar') {
            $db->prepare("UPDATE planificacion_cursos SET estado = 'planificado', motivo_cancelacion = '' WHERE id = ?")->execute([$id]);
            cengi_pp_log($db, $plan, 'Reactivación', 'Vuelve a estado planificado. Las sesiones canceladas siguen canceladas.', ['antes' => 'Cancelado', 'despues' => 'Planificado']);
            $codigoMensaje = 'reactivado';
        } elseif ($accion === 'eliminar') {
            if (!empty($plan['curso_id'])) {
                throw new InvalidArgumentException('No se puede eliminar: ya tiene el curso ' . cengi_pp_h($plan['codigo_curso']) . '. Cancélala en su lugar.');
            }
            $liquidadas = $db->prepare('SELECT COUNT(*) FROM curso_sesiones WHERE planificacion_id = ? AND (liquidacion_id IS NOT NULL OR liquidacion_soporte_id IS NOT NULL)');
            $liquidadas->execute([$id]);
            if ((int) $liquidadas->fetchColumn() > 0) {
                throw new InvalidArgumentException('No se puede eliminar: tiene sesiones incluidas en una liquidación de pago.');
            }
            $inscripciones = $db->prepare('SELECT COUNT(*) FROM solicitudes_inscripcion WHERE planificacion_id = ?');
            $inscripciones->execute([$id]);
            if ((int) $inscripciones->fetchColumn() > 0) {
                throw new InvalidArgumentException('No se puede eliminar: ya tiene inscripciones. Cancélala en su lugar para que quede el registro.');
            }
            $db->prepare('DELETE FROM planificacion_cursos WHERE id = ?')->execute([$id]);
            $codigoMensaje = 'eliminado';
        } elseif ($accion === 'duplicar') {
            $copia = array_intersect_key($plan, array_flip([
                'nombre', 'categoria_curso_id', 'area_tecnica', 'actividad_tipo', 'modalidad', 'dirigido_a', 'hora_inicio', 'hora_fin',
                'dias', 'sesiones_planificadas', 'cupo_minimo', 'cupo_maximo', 'costo_asociados', 'costo_externos', 'costo_extranjero',
                'instructor_id', 'observaciones',
            ]));
            // 364 dias = 52 semanas: conserva el dia de la semana.
            $copia['anio'] = (int) $plan['anio'] + 1;
            $copia['fecha_inicio'] = cengi_pp_sumar_dias($plan['fecha_inicio'], 364);
            $copia['fecha_fin'] = cengi_pp_sumar_dias($plan['fecha_fin'], 364);
            $copia['modulos'] = implode("\n", array_diff(cengi_pp_modulos_plan($db, $plan), ['Sesión única']));
            $copia['creado_por'] = cengi_usuario_actual_id() ?: null;
            $copia['creado_por_nombre'] = cengi_pp_usuario_nombre();
            $columnas = array_keys($copia);
            $db->prepare('INSERT INTO planificacion_cursos (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')
                ->execute(array_values($copia));
            $nuevo = cengi_pp_plan($db, (int) $db->lastInsertId());
            cengi_pp_log($db, $nuevo, 'Creación', 'Duplicado desde el año ' . cengi_pp_anio_texto($plan['anio']) . ' (mismo día de la semana).');
            $extra['anio'] = $copia['anio'];
            $codigoMensaje = 'duplicado';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }

        $db->commit();
        if (function_exists('cengi_ins_enviar_cola')) {
            // Aprobar puede completar el cupo de inscripciones ya validadas y asignarlas.
            cengi_ins_enviar_cola($db);
        }
        cengi_pp_redirigir('planificacion_cursos.php', $extra + ['msg' => $codigoMensaje]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (function_exists('cengi_ins_deshacer_cuentas_creadas')) {
            cengi_ins_deshacer_cuentas_creadas($db);
        }
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Planificacion de cursos: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar la planificación.';
        }
    }
}

if ($mensaje === '' && ($_GET['msg'] ?? '') === 'importado') {
    $mensaje = 'Carga masiva aplicada: ' . (int) ($_GET['n'] ?? 0) . ' plan(es) importado(s) como planificados.';
} elseif ($mensaje === '' && isset(CENGI_PLAN_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = CENGI_PLAN_MENSAJES[$_GET['msg']];
}

// ---------------------------------------------------------------- datos
$anio = (int) ($_GET['anio'] ?? cengi_pp_anio_actual());
if ($anio < 2000 || $anio > 2100) {
    $anio = cengi_pp_anio_actual();
}
$estadoFiltro = array_key_exists($_GET['estado'] ?? '', CENGI_PP_ESTADOS_PLAN) ? $_GET['estado'] : '';
$modalidadFiltro = trim((string) ($_GET['modalidad'] ?? ''));
$busqueda = trim((string) ($_GET['q'] ?? ''));

$stmt = $db->prepare("
    SELECT p.*, cc.descripcion_categorias_cursos AS categoria_nombre, i.nombre AS instructor_nombre, c.codigo_curso,
           COALESCE(s.activas, 0) AS sesiones_activas, COALESCE(s.impartidas, 0) AS sesiones_impartidas,
           COALESCE(s.fuera, 0) AS sesiones_fuera, s.fechas_fuera,
           (SELECT COUNT(*) FROM planificacion_cambios pc WHERE pc.planificacion_id = p.id) AS cambios
    FROM planificacion_cursos p
    LEFT JOIN categorias_cursos cc ON cc.id = p.categoria_curso_id
    LEFT JOIN instructores i ON i.id = p.instructor_id
    LEFT JOIN cursos c ON c.id = p.curso_id
    LEFT JOIN (
        SELECT s.planificacion_id,
               SUM(s.estado <> 'cancelada') AS activas,
               SUM(s.estado = 'impartida') AS impartidas,
               SUM(s.estado <> 'cancelada' AND (s.fecha < pl.fecha_inicio OR s.fecha > pl.fecha_fin)) AS fuera,
               GROUP_CONCAT(IF(s.estado <> 'cancelada' AND (s.fecha < pl.fecha_inicio OR s.fecha > pl.fecha_fin), DATE_FORMAT(s.fecha, '%d/%m'), NULL) ORDER BY s.fecha) AS fechas_fuera
        FROM curso_sesiones s INNER JOIN planificacion_cursos pl ON pl.id = s.planificacion_id
        GROUP BY s.planificacion_id
    ) s ON s.planificacion_id = p.id
    WHERE p.anio = ?
    ORDER BY p.fecha_inicio, p.nombre
");
$stmt->execute([$anio]);
$planesAnio = $stmt->fetchAll(PDO::FETCH_ASSOC);
$inscritos = cengi_pp_conteo_inscritos($db);
$pendientes = cengi_pp_conteo_pendientes($db);

$planes = array_values(array_filter($planesAnio, static function ($p) use ($estadoFiltro, $modalidadFiltro, $busqueda) {
    if ($estadoFiltro !== '' && $p['estado'] !== $estadoFiltro) {
        return false;
    }
    if ($modalidadFiltro !== '' && $p['modalidad'] !== $modalidadFiltro) {
        return false;
    }
    if ($busqueda !== '') {
        $texto = cengi_texto_normalizado($p['nombre'] . ' ' . $p['codigo_curso'] . ' ' . $p['categoria_nombre']);
        return strpos($texto, cengi_texto_normalizado($busqueda)) !== false;
    }
    return true;
}));

if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Código', 'Curso/Diplomado', 'Categoría', 'Área técnica', 'Dirigido a', 'Modalidad', 'Horario', 'Fecha inicial', 'Fecha final',
        'Sesiones planificadas', 'Sesiones programadas', 'Días de desarrollo', 'Cupo mínimo', 'Cupo máximo', 'Costo asociados (Q)',
        'Costo externos (Q)', 'Costo extranjero (US$)', 'Instructor responsable', 'Estado', 'Inscritos', 'Observaciones']];
    foreach ($planes as $p) {
        $filas[] = [$p['codigo_curso'], $p['nombre'], $p['categoria_nombre'], $p['area_tecnica'], $p['dirigido_a'], $p['modalidad'],
            cengi_pp_horario($p['hora_inicio'], $p['hora_fin']), $p['fecha_inicio'], $p['fecha_fin'], $p['sesiones_planificadas'],
            $p['sesiones_activas'], $p['dias'], $p['cupo_minimo'], $p['cupo_maximo'], $p['costo_asociados'], $p['costo_externos'],
            $p['costo_extranjero'], $p['instructor_nombre'], CENGI_PP_ESTADOS_PLAN[$p['estado']][0], $inscritos[$p['id']] ?? 0, $p['observaciones']];
    }
    cengi_pp_csv('Planificacion_anio_' . cengi_pp_anio_texto($anio), $filas);
}

// KPIs
$vigentes = array_filter($planesAnio, static function ($p) {
    return $p['estado'] !== 'cancelado';
});
$aprobados = count(array_filter($vigentes, 'cengi_pp_convocado'));
$sesPlan = array_sum(array_column($vigentes, 'sesiones_planificadas'));
$sesProg = array_sum(array_column($vigentes, 'sesiones_activas'));
$sesImp = array_sum(array_column($vigentes, 'sesiones_impartidas'));
$ingresoMinimo = array_sum(array_map(static function ($p) {
    return $p['cupo_minimo'] * $p['costo_asociados'];
}, $vigentes));

// Alertas de programacion
$alertas = [];
$planesPorId = array_column($planesAnio, null, 'id');
foreach ($planesAnio as $p) {
    if (!empty($pendientes[$p['id']])) {
        $alertas[] = ['is-danger', '<b>' . cengi_pp_h($p['nombre']) . '</b>: ' . $pendientes[$p['id']] . ' cambio(s) sin notificar a ' . ($inscritos[$p['id']] ?? 0) . ' inscrito(s).', 'bitacora_cambios.php?plan=' . $p['id'] . '&notificar=1', 'Notificar'];
    }
}
$conflictos = cengi_pp_conflictos($db);
if ($conflictos['pares']) {
    $ids = array_unique(array_merge(...$conflictos['pares']));
    $stmtSes = $db->query('SELECT s.id, s.planificacion_id, s.tema, s.fecha, s.hora_inicio, s.hora_fin, i.nombre AS instructor FROM curso_sesiones s LEFT JOIN instructores i ON i.id = s.instructor_id WHERE s.id IN (' . implode(',', array_map('intval', $ids)) . ')');
    $sesConf = array_column($stmtSes->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    $stmtNombre = $db->prepare('SELECT nombre FROM planificacion_cursos WHERE id = ?');
    foreach ($conflictos['pares'] as [$a, $b]) {
        $sa = $sesConf[$a];
        $sb = $sesConf[$b];
        if (!isset($planesPorId[$sa['planificacion_id']]) && !isset($planesPorId[$sb['planificacion_id']])) {
            continue;
        }
        $stmtNombre->execute([$sb['planificacion_id']]);
        $otro = $stmtNombre->fetchColumn();
        $alertas[] = ['is-danger', '<b>' . cengi_pp_h($sa['instructor']) . '</b> tiene dos sesiones el ' . cengi_pp_fecha_corta($sa['fecha']) . ': “' . cengi_pp_h($sa['tema']) . '” (' . cengi_pp_horario($sa['hora_inicio'], $sa['hora_fin']) . ') y “' . cengi_pp_h($sb['tema']) . '” de ' . cengi_pp_h($otro) . ' (' . cengi_pp_horario($sb['hora_inicio'], $sb['hora_fin']) . ').', 'programa_sesiones.php?editar=' . $b, 'Corregir'];
    }
}
foreach ($vigentes as $p) {
    $activas = (int) $p['sesiones_activas'];
    if ($activas < $p['sesiones_planificadas']) {
        $alertas[] = ['', '<b>' . cengi_pp_h($p['nombre']) . '</b>: ' . $activas . ' de ' . $p['sesiones_planificadas'] . ' sesiones programadas — faltan ' . ($p['sesiones_planificadas'] - $activas) . '.', 'programa_sesiones.php?plan=' . $p['id'] . '&generar=1', 'Programar'];
    } elseif ($activas > $p['sesiones_planificadas']) {
        $alertas[] = ['is-warn', '<b>' . cengi_pp_h($p['nombre']) . '</b>: hay ' . $activas . ' sesiones programadas y el plan indica ' . $p['sesiones_planificadas'] . '. Ajusta el plan o cancela sesiones.', 'programa_sesiones.php?plan=' . $p['id'], 'Revisar'];
    }
    if ((int) $p['sesiones_fuera'] > 0) {
        $alertas[] = ['is-warn', '<b>' . cengi_pp_h($p['nombre']) . '</b>: ' . $p['sesiones_fuera'] . ' sesión(es) fuera del rango ' . cengi_pp_fecha_corta($p['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($p['fecha_fin']) . ' (' . cengi_pp_h($p['fechas_fuera']) . ').', 'programa_sesiones.php?plan=' . $p['id'], 'Corregir'];
    }
    $diasParaIniciar = cengi_pp_dias_entre($hoy, $p['fecha_inicio']);
    if ($diasParaIniciar >= 0 && $diasParaIniciar <= 30) {
        [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($p, $inscritos[$p['id']] ?? 0);
        if ($color !== 'verde') {
            $alertas[] = [$color === 'rojo' ? 'is-danger' : '', '<b>' . cengi_pp_h($p['nombre']) . '</b> inicia en ' . $diasParaIniciar . ' días (' . cengi_pp_fecha_corta($p['fecha_inicio']) . '). ' . cengi_pp_h($etiqueta . ': ' . $detalle),
                cengi_pp_convocado($p) ? 'solicitudes.php' : null, cengi_pp_convocado($p) ? 'Inscripciones' : null, cengi_pp_convocado($p) ? null : (int) $p['id']];
        }
    }
}
// Instructores con sesiones futuras del año y documentacion incompleta.
$documentacion = cengi_pp_documentacion($db);
$stmtFuturas = $db->prepare("SELECT s.instructor_id, i.nombre, COUNT(*) AS n FROM curso_sesiones s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id INNER JOIN instructores i ON i.id = s.instructor_id
    WHERE p.anio = ? AND s.estado = 'programada' AND s.fecha >= ? GROUP BY s.instructor_id, i.nombre");
$stmtFuturas->execute([$anio, $hoy]);
foreach ($stmtFuturas->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $d = $documentacion[(int) $f['instructor_id']] ?? null;
    if ($d && !$d['completa']) {
        $alertas[] = ['is-warn', '<b>' . cengi_pp_h($f['nombre']) . '</b> tiene ' . (int) $f['n'] . ' sesión(es) programada(s) y ' . ($d['activo'] ? 'su documentación está incompleta (falta ' . cengi_pp_h(implode(', ', $d['faltan'])) . ')' : 'está marcado como inactivo') . '.',
            'ficha_instructor.php?id=' . (int) $f['instructor_id'] . '&tab=documentos', 'Ver ficha'];
    }
}
$nConflictos = count($conflictos['pares']);

$categorias = $db->query('SELECT id, descripcion_categorias_cursos FROM categorias_cursos WHERE estado_categorias_cursos = 1 ORDER BY descripcion_categorias_cursos')->fetchAll(PDO::FETCH_ASSOC);
$areasTecnicas = $db->query('SELECT nombre FROM areas_tecnicas WHERE estado = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN);
$instructores = cengi_pp_instructores($db);
$anios = cengi_pp_anios_disponibles($db, $anio);
$filtrosActivos = array_filter(['anio' => $anio, 'estado' => $estadoFiltro, 'modalidad' => $modalidadFiltro, 'q' => $busqueda], static function ($x) {
    return $x !== '';
});

function cengi_plan_boton($icono, $titulo, array $atributos, $clase = 'is-edit')
{
    $html = '<button type="button" class="cengi-action-btn ' . $clase . '" data-tooltip="' . cengi_pp_h($titulo) . '" aria-label="' . cengi_pp_h($titulo) . '"';
    foreach ($atributos as $nombre => $valor) {
        $html .= ' ' . $nombre . '="' . cengi_pp_h($valor) . '"';
    }
    return $html . '><span class="glyphicon glyphicon-' . $icono . '"></span></button>';
}

function cengi_plan_post($accion, $id, $icono, $titulo, $clase = 'is-view', $confirmar = '')
{
    return '<form method="post" class="cengi-inline-form"' . ($confirmar !== '' ? ' onsubmit="return confirm(' . cengi_pp_h(json_encode($confirmar, JSON_UNESCAPED_UNICODE)) . ');"' : '') . '>'
        . '<input type="hidden" name="accion" value="' . $accion . '"><input type="hidden" name="id" value="' . (int) $id . '">'
        . '<button type="submit" class="cengi-action-btn ' . $clase . '" data-tooltip="' . cengi_pp_h($titulo) . '" aria-label="' . cengi_pp_h($titulo) . '"><span class="glyphicon glyphicon-' . $icono . '"></span></button></form>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Planificación de cursos'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_pp_subnav('planificacion_cursos.php'); ?>
    <?php cengi_pp_feedback($mensajeTipo === 'error' ? $mensaje : cengi_pp_h($mensaje), $mensajeTipo); ?>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi(count($vigentes), 'Cursos en la oferta', $aprobados . ' aprobados · ' . (count($vigentes) - $aprobados) . ' por aprobar', '#3E7A12', 'calendar');
        echo cengi_pp_kpi($sesProg . '<small class="pp-kpi-detalle"> / ' . $sesPlan . '</small>', 'Sesiones programadas / planificadas', $sesImp . ' ya impartidas', '#8A6600', 'list', '#FFF6DA');
        echo cengi_pp_kpi(cengi_pp_q($ingresoMinimo), 'Ingreso mínimo proyectado', 'Cupo mínimo × costo de asociados', '#1f5632', 'usd');
        echo cengi_pp_kpi(count($alertas), 'Alertas de programación', $nConflictos ? $nConflictos . ' conflicto(s) de instructor' : 'Sin conflictos de instructor', count($alertas) ? '#B34E00' : '#5B6459', 'warning-sign', count($alertas) ? '#FFE9D9' : '#EDEFEA');
        ?>
    </div>

    <section class="pp-card">
        <form class="pp-toolbar" method="get">
            <div class="pp-toolbar-group">
                <select name="anio" aria-label="Año" onchange="this.form.submit()">
                    <?php foreach ($anios as $z): ?>
                        <option value="<?php echo $z; ?>" <?php echo $z === $anio ? 'selected' : ''; ?>>Año <?php echo cengi_pp_anio_texto($z); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="estado" aria-label="Estado" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <?php foreach (CENGI_PP_ESTADOS_PLAN as $clave => [$texto]): ?>
                        <option value="<?php echo $clave; ?>" <?php echo $estadoFiltro === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="modalidad" aria-label="Modalidad" onchange="this.form.submit()">
                    <option value="">Todas las modalidades</option>
                    <?php foreach (CENGI_PP_MODALIDADES as $m): ?>
                        <option <?php echo $modalidadFiltro === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Buscar curso o código..." aria-label="Buscar">
            </div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="planificacion_cursos.php?<?php echo cengi_pp_h(http_build_query($filtrosActivos + ['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar</a>
                <a class="btn btn-default btn-sm" href="carga_masiva_planificacion.php?tipo=plan&amp;destino=<?php echo (int) $anio; ?>"><span class="glyphicon glyphicon-upload"></span> Cargar Excel/CSV</a>
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#plan-modal"><span class="glyphicon glyphicon-plus"></span> Nueva planificación</button>
            </div>
        </form>
    </section>

    <section class="pp-card">
        <div class="pp-card-head">
            <div><h3>Alertas de programación</h3><span class="pp-sub">Conflictos de instructor, sesiones pendientes o fuera de rango, cupos en riesgo y cambios sin notificar</span></div>
            <span class="pp-chip<?php echo $alertas ? ' is-warn' : ''; ?>"><?php echo count($alertas); ?></span>
        </div>
        <div class="pp-card-body">
            <?php if (!$alertas): ?>
                <span class="pp-sub">Todo en orden: no hay conflictos ni sesiones pendientes para este año.</span>
            <?php endif; ?>
            <?php foreach ($alertas as $alerta): ?>
                <div class="pp-alert <?php echo $alerta[0]; ?>"><span class="pp-dot"></span><div><?php echo $alerta[1]; ?></div>
                    <?php if (!empty($alerta[2])): ?>
                        <a class="pp-link" href="<?php echo cengi_pp_h($alerta[2]); ?>"><?php echo cengi_pp_h($alerta[3]); ?></a>
                    <?php elseif (!empty($alerta[4])): ?>
                        <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Aprobar el plan? Se creará el curso en Gestión de cursos.');">
                            <input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?php echo (int) $alerta[4]; ?>">
                            <button type="submit" class="pp-link">Aprobar</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="cengi-courses-section">
        <div class="pp-table-wrap">
            <table class="cengi-org-table">
                <thead>
                    <tr>
                        <th>Curso / Diplomado</th><th>Modalidad</th><th>Horario</th><th>Fecha inicial</th><th>Fecha final</th>
                        <th>Sesiones prog. / plan.</th><th>Cupo mín.</th><th>Asociados</th><th>Externos</th><th>Extranjero</th><th>Estado</th>
                        <th class="is-actions"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$planes): ?>
                        <tr><td colspan="12" class="pp-empty"><?php echo $planesAnio ? 'Ningún plan coincide con los filtros.' : 'Aún no hay planificación para el año ' . cengi_pp_anio_texto($anio) . '. Usa “Nueva planificación”.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($planes as $p):
                        $activas = (int) $p['sesiones_activas'];
                        $pct = min(100, (int) round($activas * 100 / max(1, $p['sesiones_planificadas'])));
                        $claseProg = $activas < $p['sesiones_planificadas'] ? 'is-incomplete' : ($activas > $p['sesiones_planificadas'] ? 'is-over' : '');
                        $convocado = cengi_pp_convocado($p);
                        $datosPlan = array_intersect_key($p, array_flip(['id', 'anio', 'nombre', 'categoria_curso_id', 'area_tecnica', 'actividad_tipo', 'modalidad', 'dirigido_a',
                            'fecha_inicio', 'fecha_fin', 'dias', 'sesiones_planificadas', 'cupo_minimo', 'cupo_maximo', 'costo_asociados', 'costo_externos',
                            'costo_extranjero', 'instructor_id', 'modulos', 'observaciones', 'estado', 'codigo_curso']));
                        $datosPlan['hora_inicio'] = substr($p['hora_inicio'], 0, 5);
                        $datosPlan['hora_fin'] = substr($p['hora_fin'], 0, 5);
                    ?>
                        <tr class="<?php echo $p['estado'] === 'cancelado' ? 'pp-muted-row' : ''; ?>">
                            <td class="cengi-org-name" style="min-width:220px;">
                                <strong><?php echo cengi_pp_h($p['nombre']); ?></strong>
                                <span class="pp-sub"><?php echo $p['codigo_curso'] ? '<span class="pp-mono">' . cengi_pp_h($p['codigo_curso']) . '</span> · ' : ''; ?><?php echo cengi_pp_h(($p['categoria_nombre'] ?: 'Sin categoría') . ' · ' . $p['dirigido_a']); ?></span>
                                <span class="pp-sub">
                                    <?php if (cengi_pp_convocado($p) && !$p['curso_id']): ?>
                                        <a href="inscripciones.php?plan=<?php echo (int) $p['id']; ?>">Convocatoria abierta · <?php echo (int) ($inscritos[$p['id']] ?? 0); ?>/<?php echo (int) $p['cupo_minimo']; ?> validadas</a> ·
                                    <?php elseif ($p['curso_id']): ?>
                                        <a href="inscripciones.php?plan=<?php echo (int) $p['id']; ?>"><?php echo (int) ($inscritos[$p['id']] ?? 0); ?> inscrito(s)</a> ·
                                    <?php endif; ?>
                                    <a href="bitacora_cambios.php?plan=<?php echo (int) $p['id']; ?>"><?php echo (int) $p['cambios']; ?> cambio(s)</a>
                                    <?php if (!empty($pendientes[$p['id']])): ?>
                                        <a class="pp-chip is-warn" href="bitacora_cambios.php?plan=<?php echo (int) $p['id']; ?>&notificar=1"><?php echo $pendientes[$p['id']]; ?> sin notificar</a>
                                    <?php endif; ?>
                                </span>
                                <?php if ($p['estado'] === 'cancelado' && $p['motivo_cancelacion'] !== ''): ?>
                                    <span class="pp-sub"><i>Motivo: <?php echo cengi_pp_h($p['motivo_cancelacion']); ?></i></span>
                                <?php elseif (trim((string) $p['observaciones']) !== ''): ?>
                                    <span class="pp-sub"><i><?php echo cengi_pp_h(mb_strimwidth($p['observaciones'], 0, 120, '…', 'UTF-8')); ?></i></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo cengi_pp_h($p['modalidad']); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_horario($p['hora_inicio'], $p['hora_fin']); ?><span class="pp-sub"><?php echo cengi_pp_h($p['dias']); ?></span></td>
                            <td class="pp-num"><?php echo cengi_pp_fecha($p['fecha_inicio']); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_fecha($p['fecha_fin']); ?></td>
                            <td><a class="pp-prog <?php echo $claseProg; ?>" href="programa_sesiones.php?plan=<?php echo (int) $p['id']; ?>" title="<?php echo $activas . ' de ' . $p['sesiones_planificadas']; ?> sesiones programadas"><span class="pp-prog-track"><span style="width:<?php echo $pct; ?>%"></span></span><span class="pp-num"><?php echo $activas . ' / ' . (int) $p['sesiones_planificadas']; ?></span></a></td>
                            <td><?php echo (int) $p['cupo_minimo']; ?><?php echo $p['cupo_maximo'] ? '<span class="pp-sub">máx. ' . (int) $p['cupo_maximo'] . '</span>' : ''; ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($p['costo_asociados']); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($p['costo_externos']); ?></td>
                            <td class="pp-num"><?php echo (float) $p['costo_extranjero'] > 0 ? 'US$' . number_format((float) $p['costo_extranjero']) : '—'; ?></td>
                            <td><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $p['estado']); ?></td>
                            <td class="is-actions">
                                <div class="cengi-row-actions">
                                    <?php
                                    echo cengi_plan_boton('pencil', 'Editar', ['data-toggle' => 'modal', 'data-target' => '#plan-modal', 'data-plan' => json_encode($datosPlan, JSON_UNESCAPED_UNICODE)]);
                                    if ($p['estado'] === 'planificado') {
                                        echo cengi_plan_post('aprobar', $p['id'], 'ok', $p['curso_id'] ? 'Aprobar' : 'Aprobar y abrir convocatoria', 'is-view', '¿Aprobar “' . $p['nombre'] . '”? ' . ($p['curso_id'] ? 'Se vinculará al curso ' . $p['codigo_curso'] . '.' : 'Se abre la convocatoria en el formulario de inscripción. El curso se crea al completar ' . (int) $p['cupo_minimo'] . ' inscripciones validadas.'));
                                    }
                                    if ($convocado) {
                                        echo cengi_plan_boton('time', 'Reprogramar', ['data-toggle' => 'modal', 'data-target' => '#accion-modal', 'data-accion' => 'reprogramar', 'data-id' => $p['id'], 'data-nombre' => $p['nombre'], 'data-fi' => $p['fecha_inicio'], 'data-ff' => $p['fecha_fin']]);
                                    }
                                    echo '<a class="cengi-action-btn is-edit" href="programa_sesiones.php?plan=' . (int) $p['id'] . '" data-tooltip="Programa de sesiones" aria-label="Programa de sesiones"><span class="glyphicon glyphicon-list"></span></a>';
                                    echo '<a class="cengi-action-btn is-edit" href="bitacora_cambios.php?plan=' . (int) $p['id'] . '" data-tooltip="Historial de cambios" aria-label="Historial de cambios"><span class="glyphicon glyphicon-dashboard"></span></a>';
                                    echo cengi_plan_post('duplicar', $p['id'], 'duplicate', 'Duplicar para el año siguiente', 'is-edit', '¿Duplicar “' . $p['nombre'] . '” en el año ' . cengi_pp_anio_texto($p['anio'] + 1) . ' (mismas fechas, un año después)?');
                                    if ($p['estado'] === 'cancelado') {
                                        echo cengi_plan_post('reactivar', $p['id'], 'repeat', 'Reactivar como planificado');
                                    } else {
                                        echo cengi_plan_boton('ban-circle', 'Cancelar', ['data-toggle' => 'modal', 'data-target' => '#accion-modal', 'data-accion' => 'cancelar', 'data-id' => $p['id'], 'data-nombre' => $p['nombre'], 'data-fi' => $p['fecha_inicio'], 'data-ff' => $p['fecha_fin'], 'data-curso' => $p['codigo_curso'] ?? ''], 'is-delete');
                                    }
                                    if (!$p['curso_id']) {
                                        echo cengi_plan_post('eliminar', $p['id'], 'trash', 'Eliminar', 'is-delete', '¿Eliminar “' . $p['nombre'] . '” y sus sesiones? Esta acción no se puede deshacer.');
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="modal fade cengi-course-form-modal pp-modal" id="plan-modal" tabindex="-1" role="dialog" aria-labelledby="plan-modal-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="plan-form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Planificación de cursos</span>
                <h4 class="modal-title" id="plan-modal-title">Nueva planificación de curso</h4>
                <p id="plan-modal-sub"></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" id="pf-id" value="">
                <div class="pp-form-msg is-visible is-warn" id="pf-aviso-aprobar">Al <b>aprobar</b> se abre la convocatoria en el formulario público de inscripción. Cuando las inscripciones validadas llegan al <b>cupo mínimo</b>, el sistema crea el curso en Cursos con estos datos (no se vuelven a capturar) y asigna a los participantes.</div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group is-full">
                        <label for="pf-nombre">Curso / diplomado</label>
                        <input type="text" class="form-control" id="pf-nombre" name="nombre" maxlength="255" required placeholder="Ej. Manejo integrado de plagas">
                    </div>
                    <div class="form-group">
                        <label for="pf-anio">Año</label>
                        <select class="form-control" id="pf-anio" name="anio">
                            <?php foreach ($anios as $z): ?><option value="<?php echo $z; ?>"><?php echo cengi_pp_anio_texto($z); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pf-categoria">Categoría</label>
                        <select class="form-control" id="pf-categoria" name="categoria_curso_id">
                            <option value="">Sin definir (requerida para aprobar)</option>
                            <?php foreach ($categorias as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo cengi_pp_h($c['descripcion_categorias_cursos']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pf-area">Área técnica</label>
                        <select class="form-control" id="pf-area" name="area_tecnica">
                            <option value="">Sin definir</option>
                            <?php foreach ($areasTecnicas as $a): ?><option value="<?php echo cengi_pp_h($a); ?>"><?php echo cengi_pp_h($a); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pf-actividad">Tipo de actividad</label>
                        <select class="form-control" id="pf-actividad" name="actividad_tipo">
                            <option value="formacion_academica">Formación académica</option>
                            <option value="evento_tecnico">Evento técnico</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pf-dirigido">Dirigido a</label>
                        <input type="text" class="form-control" id="pf-dirigido" name="dirigido_a" maxlength="255" value="Todos los ingenios">
                    </div>
                    <div class="form-group">
                        <label for="pf-modalidad">Modalidad</label>
                        <select class="form-control" id="pf-modalidad" name="modalidad">
                            <?php foreach (CENGI_PP_MODALIDADES as $m): ?><option><?php echo $m; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pf-fi">Fecha inicial</label>
                        <input type="date" class="form-control" id="pf-fi" name="fecha_inicio" required>
                    </div>
                    <div class="form-group">
                        <label for="pf-ff">Fecha final</label>
                        <input type="date" class="form-control" id="pf-ff" name="fecha_fin" required>
                    </div>
                    <div class="form-group">
                        <label for="pf-hi">Hora de inicio</label>
                        <input type="time" class="form-control" id="pf-hi" name="hora_inicio" value="08:00" required>
                    </div>
                    <div class="form-group">
                        <label for="pf-hf">Hora de fin</label>
                        <input type="time" class="form-control" id="pf-hf" name="hora_fin" value="12:00" required>
                    </div>
                    <div class="form-group">
                        <label for="pf-ses">N.° de sesiones</label>
                        <input type="number" class="form-control" id="pf-ses" name="sesiones_planificadas" min="1" max="999" required>
                    </div>
                    <div class="form-group">
                        <label for="pf-dias">Días de desarrollo</label>
                        <input type="text" class="form-control" id="pf-dias" name="dias" maxlength="255" placeholder="Ej. Lunes a viernes">
                    </div>
                    <div class="form-group">
                        <label for="pf-cupo">Cupo mínimo</label>
                        <input type="number" class="form-control" id="pf-cupo" name="cupo_minimo" min="1" max="10000" required>
                    </div>
                    <div class="form-group">
                        <label for="pf-cupomax">Cupo máximo <small>(opcional)</small></label>
                        <input type="number" class="form-control" id="pf-cupomax" name="cupo_maximo" min="1" max="10000">
                    </div>
                    <div class="form-group">
                        <label for="pf-asoc">Costo asociados (Q)</label>
                        <input type="number" class="form-control" id="pf-asoc" name="costo_asociados" min="0" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label for="pf-ext">Costo externos (Q)</label>
                        <input type="number" class="form-control" id="pf-ext" name="costo_externos" min="0" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label for="pf-extr">Costo extranjero (US$)</label>
                        <input type="number" class="form-control" id="pf-extr" name="costo_extranjero" min="0" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label for="pf-instr">Instructor responsable</label>
                        <select class="form-control" id="pf-instr" name="instructor_id">
                            <option value="">Por definir</option>
                            <?php foreach ($instructores as $i): ?><option value="<?php echo (int) $i['id']; ?>"><?php echo cengi_pp_h($i['nombre']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group is-full">
                        <label for="pf-modulos">Módulos <small>(uno por línea; se crean en el curso al aprobar)</small></label>
                        <textarea class="form-control" id="pf-modulos" name="modulos" rows="3" placeholder="Agronomía cañera&#10;Riego y drenaje"></textarea>
                    </div>
                    <div class="form-group is-full">
                        <label for="pf-obs">Observaciones</label>
                        <textarea class="form-control" id="pf-obs" name="observaciones" rows="2"></textarea>
                    </div>
                    <div class="form-group is-full" id="pf-motivo-wrap">
                        <label for="pf-motivo">Motivo del cambio</label>
                        <input type="text" class="form-control" id="pf-motivo" name="motivo" maxlength="500" placeholder="Obligatorio si cambias fechas, horario, modalidad o instructor de un curso aprobado">
                        <p class="help-block">Queda en la bitácora y en el aviso a los inscritos.</p>
                    </div>
                </div>
                <div class="pp-form-msg is-warn" id="pf-avisos"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-default" id="pf-guardar">Guardar como planificado</button>
                <button type="submit" class="btn btn-primary" id="pf-aprobar" name="aprobar" value="1">Aprobar y abrir convocatoria</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal" id="accion-modal" tabindex="-1" role="dialog" aria-labelledby="accion-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="accion-form">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <h4 class="modal-title" id="accion-title">Reprogramar curso</h4>
                <p id="accion-sub"></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" id="accion-tipo" value="reprogramar">
                <input type="hidden" name="id" id="accion-id" value="">
                <div class="pp-form-msg is-warn" id="accion-aviso"></div>
                <div class="cengi-course-modal-grid" id="accion-fechas">
                    <div class="form-group">
                        <label for="accion-fi">Nueva fecha inicial <small id="accion-delta"></small></label>
                        <input type="date" class="form-control" id="accion-fi" name="fecha_inicio">
                    </div>
                    <div class="form-group">
                        <label for="accion-ff">Nueva fecha final</label>
                        <input type="date" class="form-control" id="accion-ff" name="fecha_fin">
                    </div>
                    <div class="form-group is-full">
                        <label><input type="checkbox" name="mover_sesiones" value="1" checked> Mover también las sesiones programadas los mismos días</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="accion-motivo">Motivo</label>
                    <input type="text" class="form-control" id="accion-motivo" name="motivo" maxlength="500" required>
                    <p class="help-block">Queda en el historial del plan y en el aviso a los inscritos.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Volver</button>
                <button type="submit" class="btn btn-primary" id="accion-btn">Reprogramar</button>
            </div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var anioSel = <?php echo (int) $anio; ?>;
    function $id(id) { return document.getElementById(id); }
    function set(id, valor) { $id(id).value = valor === null || valor === undefined ? '' : String(valor); }
    function addDays(iso, n) { var d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }
    function diffDays(a, b) { return Math.round((new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000); }

    $('#plan-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, p = {};
        try { p = t && t.getAttribute('data-plan') ? JSON.parse(t.getAttribute('data-plan')) : {}; } catch (e) {}
        var editando = !!p.id, convocado = p.estado === 'aprobado' || p.estado === 'reprogramado';
        $id('plan-form').reset();
        set('pf-id', p.id); set('pf-anio', p.anio || anioSel);
        var campos = { 'pf-nombre': 'nombre', 'pf-categoria': 'categoria_curso_id', 'pf-area': 'area_tecnica', 'pf-actividad': 'actividad_tipo',
            'pf-dirigido': 'dirigido_a', 'pf-modalidad': 'modalidad', 'pf-fi': 'fecha_inicio', 'pf-ff': 'fecha_fin', 'pf-hi': 'hora_inicio', 'pf-hf': 'hora_fin',
            'pf-ses': 'sesiones_planificadas', 'pf-dias': 'dias', 'pf-cupo': 'cupo_minimo', 'pf-cupomax': 'cupo_maximo', 'pf-asoc': 'costo_asociados',
            'pf-ext': 'costo_externos', 'pf-extr': 'costo_extranjero', 'pf-instr': 'instructor_id', 'pf-modulos': 'modulos', 'pf-obs': 'observaciones' };
        if (editando) Object.keys(campos).forEach(function (id) { set(id, p[campos[id]]); });
        $id('plan-modal-title').textContent = editando ? 'Editar planificación' : 'Nueva planificación de curso';
        $id('plan-modal-sub').textContent = editando ? ((p.codigo_curso ? p.codigo_curso + ' · ' : '') + 'Estado actual: ' + p.estado) : '';
        $id('pf-guardar').style.display = convocado ? 'none' : '';
        $id('pf-guardar').textContent = p.estado === 'cancelado' ? 'Guardar (sigue cancelado)' : 'Guardar como planificado';
        $id('pf-aprobar').textContent = convocado ? 'Guardar cambios' : 'Aprobar y abrir convocatoria';
        $id('pf-aprobar').style.display = p.estado === 'cancelado' ? 'none' : '';
        $id('pf-aviso-aprobar').style.display = convocado ? 'none' : '';
        $id('pf-motivo-wrap').style.display = convocado ? '' : 'none';
        $id('pf-ff').min = $id('pf-fi').value;
        avisos();
    });

    // Avisos no bloqueantes (el servidor valida lo obligatorio).
    function avisos() {
        var a = [], fi = $id('pf-fi').value, ff = $id('pf-ff').value, ses = +$id('pf-ses').value, anio = +$id('pf-anio').value;
        if (fi && ff && ff >= fi && ses > diffDays(fi, ff) + 1) a.push('Planificaste ' + ses + ' sesiones en ' + (diffDays(fi, ff) + 1) + ' día(s): habrá más de una sesión por día.');
        if (fi && +fi.slice(0, 4) !== anio) a.push('La fecha inicial no corresponde al año ' + anio + '.');
        if (+$id('pf-asoc').value > +$id('pf-ext').value) a.push('El costo para asociados es mayor que el de externos.');
        $id('pf-avisos').innerHTML = a.join('<br>');
        $id('pf-avisos').classList.toggle('is-visible', a.length > 0);
    }
    ['pf-fi', 'pf-ff', 'pf-ses', 'pf-anio', 'pf-asoc', 'pf-ext'].forEach(function (id) { $id(id).addEventListener('input', avisos); });
    $id('pf-fi').addEventListener('change', function () { $id('pf-ff').min = this.value; if ($id('pf-ff').value && $id('pf-ff').value < this.value) $id('pf-ff').value = this.value; });

    $('#accion-modal').on('show.bs.modal', function (event) {
        var t = event.relatedTarget, tipo = t.getAttribute('data-accion'), repro = tipo === 'reprogramar';
        $id('accion-form').reset();
        set('accion-tipo', tipo); set('accion-id', t.getAttribute('data-id'));
        $id('accion-title').textContent = repro ? 'Reprogramar curso' : 'Cancelar planificación';
        $id('accion-sub').textContent = t.getAttribute('data-nombre');
        $id('accion-fechas').style.display = repro ? '' : 'none';
        $id('accion-fi').required = $id('accion-ff').required = repro;
        set('accion-fi', t.getAttribute('data-fi')); set('accion-ff', t.getAttribute('data-ff'));
        $id('accion-fi').setAttribute('data-original', t.getAttribute('data-fi'));
        $id('accion-fi').setAttribute('data-duracion', diffDays(t.getAttribute('data-fi'), t.getAttribute('data-ff')));
        $id('accion-delta').textContent = '';
        $id('accion-btn').textContent = repro ? 'Reprogramar' : 'Cancelar planificación';
        $id('accion-btn').className = 'btn ' + (repro ? 'btn-primary' : 'btn-danger');
        var curso = t.getAttribute('data-curso');
        $id('accion-aviso').innerHTML = repro ? '' : 'Las sesiones programadas pendientes pasarán a “cancelada”; las impartidas se conservan para el pago.' + (curso ? ' El curso ' + curso + ' se conserva en Gestión de cursos con sus inscritos.' : '');
        $id('accion-aviso').classList.toggle('is-visible', !repro);
    });
    $id('accion-fi').addEventListener('change', function () {
        if (!this.value) return;
        set('accion-ff', addDays(this.value, +this.getAttribute('data-duracion') || 0));
        var d = diffDays(this.getAttribute('data-original'), this.value);
        $id('accion-delta').textContent = d ? '(' + (d > 0 ? '+' : '') + d + ' días)' : '';
    });
})();
</script>
</body>
</html>
