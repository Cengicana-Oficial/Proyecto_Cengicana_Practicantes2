<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/calendario_ics_helpers.php';
require_once __DIR__ . '/revisar_permisos.php';

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
    'reprogramado' => 'Curso reprogramado. Avisa a los inscritos desde Comunicaciones → Por avisar.',
    'cancelado' => 'Planificación cancelada.',
    'reactivado' => 'Planificación reactivada como planificada.',
    'eliminado' => 'Planificación eliminada.',
    'duplicado' => 'Planificación duplicada en el año siguiente.',
    'sin_cambios' => 'No hubo cambios que guardar.',
];

/** Lee y valida el formulario de planificacion. */
function cengi_plan_leer_form(PDO $db, $vinculado = false)
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
    // Módulos: todos con su número de sesiones (se asignan solos por número de sesión) o ninguno.
    // Con el curso creado los módulos se editan en el curso, así que aquí no se validan.
    $modulos = cengi_pp_parse_modulos($v['modulos']);
    $conSesiones = count(array_filter(array_column($modulos, 'sesiones')));
    if (!$vinculado && $conSesiones && $conSesiones < count($modulos)) {
        $errores[] = 'Indica el número de sesiones de todos los módulos (o de ninguno).';
    } elseif (!$vinculado && $conSesiones && array_sum(array_column($modulos, 'sesiones')) !== $v['sesiones_planificadas']) {
        $errores[] = 'Los módulos suman ' . array_sum(array_column($modulos, 'sesiones')) . ' sesiones y el plan indica ' . $v['sesiones_planificadas'] . '. Ajusta los módulos o el número de sesiones.';
    }
    $v['modulos'] = $vinculado ? $v['modulos'] : cengi_pp_serializar_modulos($modulos);
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
        ['Edición', 'Módulos', cengi_pp_serializar_modulos(cengi_pp_parse_modulos($p['modulos'])), cengi_pp_serializar_modulos(cengi_pp_parse_modulos($v['modulos']))],
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
        // Reprogramar, cancelar o eliminar mueve o quita sesiones: se actualizan los calendarios ya invitados.
        $icsAntes = $plan && in_array($accion, ['reprogramar', 'cancelar', 'eliminar', 'guardar'], true) ? cengi_ics_instantanea($db, [], $plan['id']) : [];

        if ($accion === 'guardar') {
            if (!empty($plan['curso_id'])) {
                // Con el curso creado, esos datos se editan en Gestión de cursos: se ignora lo que llegue del formulario.
                foreach (CENGI_PP_CAMPOS_CURSO as $campo) {
                    $_POST[$campo] = (string) $plan[$campo];
                }
            }
            $v = cengi_plan_leer_form($db, !empty($plan['curso_id']));
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
                cengi_pp_asignar_modulos($db, $id);
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
            $movidas = ['movidas' => 0, 'fijas' => 0, 'dias' => 0, 'primera' => null];
            if (!empty($_POST['mover_sesiones'])) {
                $movidas = cengi_pp_mover_sesiones($db, $id, $plan['fecha_inicio'], $fi, !empty($_POST['mismo_dia']));
                // Que el plan cubra todas las sesiones pendientes ya movidas.
                $ultima = $db->prepare('SELECT MAX(s.fecha) FROM curso_sesiones s WHERE s.planificacion_id = ? AND ' . CENGI_PP_SESION_MOVIBLE);
                $ultima->execute([$id]);
                $ultima = (string) $ultima->fetchColumn();
                if ($ultima !== '' && $ultima > $ff) {
                    $ff = $ultima;
                }
            }
            $extra += ['mov' => $movidas['movidas'], 'fijas' => $movidas['fijas']];
            $db->prepare("UPDATE planificacion_cursos SET fecha_inicio = ?, fecha_fin = ?, estado = IF(estado IN ('aprobado', 'reprogramado'), 'reprogramado', estado) WHERE id = ?")
                ->execute([$fi, $ff, $id]);
            cengi_pp_log($db, $plan, 'Reprogramación', 'Fechas del curso' . cengi_pp_texto_movidas($movidas), [
                'antes' => cengi_pp_fecha($plan['fecha_inicio']) . ' – ' . cengi_pp_fecha($plan['fecha_fin']),
                'despues' => cengi_pp_fecha($fi) . ' – ' . cengi_pp_fecha($ff), 'motivo' => $motivo,
            ]);
            cengi_pp_sincronizar_fechas_curso($db, $id);
            cengi_pp_asignar_modulos($db, $id);
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
            $copia['modulos'] = cengi_pp_serializar_modulos(cengi_pp_estructura_modulos($db, $plan));
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
        $extra += cengi_ics_parametros(cengi_ics_sincronizar($db, $icsAntes));
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
    if ($_GET['msg'] === 'reprogramado' && isset($_GET['mov'])) {
        $mensaje .= ' Sesiones movidas: ' . (int) $_GET['mov'] . '.';
        if ((int) ($_GET['fijas'] ?? 0)) {
            $mensaje .= ' ' . (int) $_GET['fijas'] . ' sesión(es) impartidas o incluidas en una liquidación de pago se conservaron en su fecha: si el curso no se impartió, revísalas en Programa de sesiones.';
            $mensajeTipo = 'warning';
        }
    }
}
if ($mensaje !== '' && $mensajeTipo === 'success' && ($resumenIcs = cengi_ics_resumen_envio(cengi_ics_desde_parametros($_GET))) !== '') {
    $mensaje .= ' ' . $resumenIcs;
}

// ---------------------------------------------------------------- datos
$anio = (int) ($_GET['anio'] ?? cengi_pp_anio_actual());
if ($anio < 2000 || $anio > 2100) {
    $anio = cengi_pp_anio_actual();
}
// "ejecucion" no es un estado guardado: son los planes que ya tienen curso (y no están cancelados).
$estadoFiltro = array_key_exists($_GET['estado'] ?? '', CENGI_PP_ESTADOS_PLAN) || ($_GET['estado'] ?? '') === 'ejecucion' ? $_GET['estado'] : '';
$modalidadFiltro = trim((string) ($_GET['modalidad'] ?? ''));
$busqueda = trim((string) ($_GET['q'] ?? ''));

$stmt = $db->prepare("
    SELECT p.*, cc.descripcion_categorias_cursos AS categoria_nombre, i.nombre AS instructor_nombre, c.codigo_curso,
           COALESCE(s.activas, 0) AS sesiones_activas, COALESCE(s.impartidas, 0) AS sesiones_impartidas,
           COALESCE(s.movibles, 0) AS sesiones_movibles, s.primera_movible, COALESCE(s.fijas, 0) AS sesiones_fijas,
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
               SUM(s.estado = 'programada' AND s.liquidacion_id IS NULL AND s.liquidacion_soporte_id IS NULL) AS movibles,
               MIN(IF(s.estado = 'programada' AND s.liquidacion_id IS NULL AND s.liquidacion_soporte_id IS NULL, s.fecha, NULL)) AS primera_movible,
               SUM(s.estado <> 'cancelada' AND NOT (s.estado = 'programada' AND s.liquidacion_id IS NULL AND s.liquidacion_soporte_id IS NULL)) AS fijas,
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

/** Texto en el que busca el filtro de la tabla (el mismo en el navegador y en la exportación). */
function cengi_plan_texto_busqueda(array $p)
{
    return implode(' ', [$p['nombre'], $p['codigo_curso'], $p['categoria_nombre'], $p['instructor_nombre'], $p['area_tecnica'],
        $p['dirigido_a'], $p['modalidad'], $p['observaciones']]);
}

/** Estado que se muestra y filtra: un plan con curso abierto (no cancelado) está "en ejecución". */
function cengi_plan_estado_vista(array $p)
{
    return $p['curso_id'] && $p['estado'] !== 'cancelado' ? 'ejecucion' : $p['estado'];
}

// La tabla muestra todo el año y filtra en el navegador; este filtro aplica a la exportación.
$planes = array_values(array_filter($planesAnio, static function ($p) use ($estadoFiltro, $modalidadFiltro, $busqueda) {
    if ($estadoFiltro !== '' && cengi_plan_estado_vista($p) !== $estadoFiltro) {
        return false;
    }
    if ($modalidadFiltro !== '' && $p['modalidad'] !== $modalidadFiltro) {
        return false;
    }
    if ($busqueda !== '') {
        $texto = cengi_texto_normalizado(cengi_plan_texto_busqueda($p));
        foreach (preg_split('/\s+/', cengi_texto_normalizado($busqueda)) as $palabra) {
            if ($palabra !== '' && strpos($texto, $palabra) === false) {
                return false;
            }
        }
    }
    return true;
}));

if (in_array($_GET['exportar'] ?? '', ['xlsx', 'csv'], true)) {
    $filas = [['Código', 'Curso/Diplomado', 'Categoría', 'Área técnica', 'Dirigido a', 'Modalidad', 'Horario', 'Fecha inicial', 'Fecha final',
        'Sesiones planificadas', 'Sesiones programadas', 'Días de desarrollo', 'Cupo mínimo', 'Cupo máximo', 'Costo asociados (Q)',
        'Costo externos (Q)', 'Costo extranjero (US$)', 'Instructor responsable', 'Estado', 'Inscritos', 'Observaciones']];
    foreach ($planes as $p) {
        $filas[] = [$p['codigo_curso'], $p['nombre'], $p['categoria_nombre'], $p['area_tecnica'], $p['dirigido_a'], $p['modalidad'],
            cengi_pp_horario($p['hora_inicio'], $p['hora_fin']), $p['fecha_inicio'], $p['fecha_fin'], $p['sesiones_planificadas'],
            $p['sesiones_activas'], $p['dias'], $p['cupo_minimo'], $p['cupo_maximo'], $p['costo_asociados'], $p['costo_externos'],
            $p['costo_extranjero'], $p['instructor_nombre'], CENGI_PP_ESTADOS_PLAN[$p['estado']][0], $inscritos[$p['id']] ?? 0, $p['observaciones']];
    }
    require_once __DIR__ . '/classes/export_helpers.php';
    $encabezados = array_shift($filas);
    cengi_export_enviar_excel($encabezados, $filas, 'Planificación de cursos',
        'Planificacion_anio_' . cengi_pp_anio_texto($anio),
        [18, 50, 28, 28, 30, 20, 25, 18, 18, 24, 24, 30, 18, 18, 24, 24, 24, 35, 20, 18, 55], 'xlsx');
}

require_once __DIR__ . '/menu.php';

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
        $alertas[] = ['is-danger', '<b>' . cengi_pp_h($p['nombre']) . '</b>: ' . $pendientes[$p['id']] . ' cambio(s) sin notificar a ' . ($inscritos[$p['id']] ?? 0) . ' inscrito(s).', 'comunicaciones.php?tab=avisar&avisar=' . $p['id'], 'Avisar', null, 'notificar', (int) $p['id']];
    }
}
$conflictos = cengi_pp_conflictos($db);
if ($conflictos['pares']) {
    $ids = array_unique(array_merge(...$conflictos['pares']));
    $stmtSes = $db->query('SELECT s.id, s.planificacion_id, s.tema, s.fecha, s.hora_inicio, s.hora_fin, s.instructor_id, i.nombre AS instructor, p.nombre AS plan_nombre
        FROM curso_sesiones s LEFT JOIN instructores i ON i.id = s.instructor_id INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
        WHERE s.id IN (' . implode(',', array_map('intval', $ids)) . ')');
    $sesConf = array_column($stmtSes->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    // Un aviso por instructor y par de planes: cuántos choques y entre qué fechas.
    $grupos = [];
    foreach ($conflictos['pares'] as [$a, $b]) {
        $sa = $sesConf[$a];
        $sb = $sesConf[$b];
        if (!isset($planesPorId[$sa['planificacion_id']]) && !isset($planesPorId[$sb['planificacion_id']])) {
            continue;
        }
        $planes = [(int) $sa['planificacion_id'], (int) $sb['planificacion_id']];
        sort($planes);
        $clave = $sa['instructor_id'] . '-' . implode('-', $planes);
        $g = &$grupos[$clave];
        $g = $g ?? ['instructor' => $sa['instructor'], 'instructor_id' => (int) $sa['instructor_id'], 'planes' => array_unique([$sa['plan_nombre'], $sb['plan_nombre']]),
            'plan_id' => (int) $sa['planificacion_id'], 'n' => 0, 'desde' => $sa['fecha'], 'hasta' => $sa['fecha'], 'primera' => $b];
        $g['n']++;
        $g['desde'] = min($g['desde'], $sa['fecha']);
        $g['hasta'] = max($g['hasta'], $sa['fecha']);
        unset($g);
    }
    foreach ($grupos as $g) {
        $entre = count($g['planes']) > 1 ? 'entre “' . cengi_pp_h($g['planes'][0]) . '” y “' . cengi_pp_h(end($g['planes'])) . '”' : 'dentro de “' . cengi_pp_h($g['planes'][0]) . '”';
        $cuando = $g['n'] === 1 ? 'el ' . cengi_pp_fecha_corta($g['desde']) : 'del ' . cengi_pp_fecha_corta($g['desde']) . ' al ' . cengi_pp_fecha_corta($g['hasta']);
        $alertas[] = ['is-danger', '<b>' . cengi_pp_h($g['instructor']) . '</b>: ' . $g['n'] . ' sesión(es) a la misma hora ' . $entre . ' (' . $cuando . ').',
            $g['n'] === 1 ? 'programa_sesiones.php?editar=' . $g['primera'] : 'programa_sesiones.php?instructor=' . $g['instructor_id'], $g['n'] === 1 ? 'Corregir' : 'Ver sesiones', null, 'conflicto', $g['plan_id']];
    }
}
$sinSesionesAun = 0;
$porProgramar = [];
foreach ($vigentes as $p) {
    $activas = (int) $p['sesiones_activas'];
    if ($activas < $p['sesiones_planificadas']) {
        // Aprobado: aviso propio. Por aprobar e inicia en 45 días o menos: va en un solo aviso agrupado.
        // Por aprobar y lejano: no es alerta (se ve en su barra de sesiones).
        if (cengi_pp_convocado($p)) {
            $alertas[] = ['', '<b>' . cengi_pp_h($p['nombre']) . '</b>: ' . $activas . ' de ' . $p['sesiones_planificadas'] . ' sesiones programadas — faltan ' . ($p['sesiones_planificadas'] - $activas) . '.', 'programa_sesiones.php?plan=' . $p['id'] . '&generar=1', 'Programar', null, 'sesiones', (int) $p['id']];
        } elseif (cengi_pp_dias_entre($hoy, $p['fecha_inicio']) <= 45) {
            $porProgramar[] = $p;
        } else {
            $sinSesionesAun++;
        }
    } elseif ($activas > $p['sesiones_planificadas']) {
        $alertas[] = ['is-warn', '<b>' . cengi_pp_h($p['nombre']) . '</b>: hay ' . $activas . ' sesiones programadas y el plan indica ' . $p['sesiones_planificadas'] . '. Ajusta el plan o cancela sesiones.', 'programa_sesiones.php?plan=' . $p['id'], 'Revisar', null, 'sesiones', (int) $p['id']];
    }
    if ((int) $p['sesiones_fuera'] > 0) {
        $alertas[] = ['is-warn', '<b>' . cengi_pp_h($p['nombre']) . '</b>: ' . $p['sesiones_fuera'] . ' sesión(es) fuera del rango ' . cengi_pp_fecha_corta($p['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($p['fecha_fin']) . ' (' . cengi_pp_h($p['fechas_fuera']) . ').', 'programa_sesiones.php?plan=' . $p['id'], 'Corregir', null, 'rango', (int) $p['id']];
    }
    $diasParaIniciar = cengi_pp_dias_entre($hoy, $p['fecha_inicio']);
    if ($diasParaIniciar >= 0 && $diasParaIniciar <= 30) {
        [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($p, $inscritos[$p['id']] ?? 0);
        if ($color !== 'verde') {
            $alertas[] = [$color === 'rojo' ? 'is-danger' : '', '<b>' . cengi_pp_h($p['nombre']) . '</b> inicia en ' . $diasParaIniciar . ' días (' . cengi_pp_fecha_corta($p['fecha_inicio']) . '). ' . cengi_pp_h($etiqueta . ': ' . $detalle),
                cengi_pp_convocado($p) ? 'inscripciones.php?plan=' . (int) $p['id'] : null, cengi_pp_convocado($p) ? 'Inscripciones' : null, cengi_pp_convocado($p) ? null : (int) $p['id'], 'cupo', (int) $p['id']];
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
            'ficha_instructor.php?id=' . (int) $f['instructor_id'] . '&tab=documentos', 'Ver ficha', null, 'documentos'];
    }
}
if ($porProgramar) {
    $items = '';
    foreach ($porProgramar as $p) {
        $dias = cengi_pp_dias_entre($hoy, $p['fecha_inicio']);
        $items .= '<li><a href="programa_sesiones.php?plan=' . (int) $p['id'] . '&amp;generar=1">' . cengi_pp_h($p['nombre']) . '</a> <span>'
            . ($dias < 0 ? 'debía iniciar el ' : 'inicia ') . cengi_pp_fecha_corta($p['fecha_inicio']) . ' · ' . (int) $p['sesiones_activas'] . '/' . (int) $p['sesiones_planificadas'] . ' sesiones</span></li>';
    }
    $atrasados = count(array_filter($porProgramar, static function ($p) use ($hoy) {
        return $p['fecha_inicio'] < $hoy;
    }));
    $proximos = count($porProgramar) - $atrasados;
    $alertas[] = [$atrasados ? 'is-warn' : '', '<b>' . count($porProgramar) . ' plan(es) por aprobar sin sus sesiones</b>: ' . implode(' y ', array_filter([$atrasados ? $atrasados . ' ya pasaron su fecha de inicio' : '', $proximos ? $proximos . ' inician en 45 días o menos' : ''])) . '. Apruébalos y programa sus sesiones, reprograma su fecha o cancélalos si no se impartirán.'
        . '<details class="pp-alerta-detalle"><summary>Ver cuáles</summary><ul>' . $items . '</ul></details>', null, null, null, 'sesiones'];
}
$nConflictos = count($conflictos['pares']);
// Orden: lo urgente primero (rojo, naranja, amarillo).
$prioridad = ['is-danger' => 0, 'is-warn' => 1, '' => 2];
usort($alertas, static function ($x, $y) use ($prioridad) {
    return ($prioridad[$x[0]] ?? 2) <=> ($prioridad[$y[0]] ?? 2);
});
$alertasPorPlan = [];
foreach ($alertas as $al) {
    if (!empty($al[6])) {
        $alertasPorPlan[$al[6]][] = strip_tags($al[1]);
    }
}

$categorias = $db->query('SELECT id, descripcion_categorias_cursos FROM categorias_cursos WHERE estado_categorias_cursos = 1 ORDER BY descripcion_categorias_cursos')->fetchAll(PDO::FETCH_ASSOC);
$areasTecnicas = $db->query('SELECT nombre FROM areas_tecnicas WHERE estado = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN);
$instructores = cengi_pp_instructores($db);
$anios = cengi_pp_anios_disponibles($db, $anio);
$filtrosActivos = array_filter(['anio' => $anio, 'estado' => $estadoFiltro, 'modalidad' => $modalidadFiltro, 'q' => $busqueda], static function ($x) {
    return $x !== '';
});

// Opciones del menú desplegable "Acciones" de cada fila.
function cengi_plan_boton($icono, $titulo, array $atributos, $peligro = false)
{
    $html = '<li><a href="#"' . ($peligro ? ' class="is-danger"' : '');
    foreach ($atributos as $nombre => $valor) {
        $html .= ' ' . $nombre . '="' . cengi_pp_h($valor) . '"';
    }
    return $html . '><span class="glyphicon glyphicon-' . $icono . '"></span> ' . cengi_pp_h($titulo) . '</a></li>';
}

function cengi_plan_enlace($href, $icono, $titulo)
{
    return '<li><a href="' . cengi_pp_h($href) . '"><span class="glyphicon glyphicon-' . $icono . '"></span> ' . cengi_pp_h($titulo) . '</a></li>';
}

function cengi_plan_post($accion, $id, $icono, $titulo, $peligro = false, $confirmar = '')
{
    return '<li><form method="post"' . ($confirmar !== '' ? ' onsubmit="return confirm(' . cengi_pp_h(json_encode($confirmar, JSON_UNESCAPED_UNICODE)) . ');"' : '') . '>'
        . '<input type="hidden" name="accion" value="' . $accion . '"><input type="hidden" name="id" value="' . (int) $id . '">'
        . '<button type="submit"' . ($peligro ? ' class="is-danger"' : '') . '><span class="glyphicon glyphicon-' . $icono . '"></span> ' . cengi_pp_h($titulo) . '</button></form></li>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Planificación de cursos'); ?></head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_banner(); ?>
    <?php cengi_pp_subnav('planificacion_cursos.php'); ?>
    <?php cengi_pp_feedback($mensajeTipo === 'error' ? $mensaje : cengi_pp_h($mensaje), $mensajeTipo); ?>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi(count($vigentes), 'Cursos en la oferta', $aprobados . ' aprobados · ' . (count($vigentes) - $aprobados) . ' por aprobar', '#3E7A12', 'calendar');
        echo cengi_pp_kpi($sesProg . '<small class="pp-kpi-detalle"> / ' . $sesPlan . '</small>', 'Sesiones programadas / planificadas', $sesImp . ' ya impartidas', '#8A6600', 'list', '#FFF6DA');
        echo cengi_pp_kpi(cengi_pp_q($ingresoMinimo), 'Ingreso mínimo proyectado', 'Cupo mínimo × costo de asociados', '#1f5632', 'usd');
        echo '<a href="#pp-alertas" class="pp-kpi-enlace" data-abrir-alertas>' . cengi_pp_kpi(count($alertas), 'Alertas por atender', $nConflictos ? $nConflictos . ' choque(s) de horario de instructor' : 'Sin choques de instructor', count($alertas) ? '#B34E00' : '#5B6459', 'warning-sign', count($alertas) ? '#FFE9D9' : '#EDEFEA') . '</a>';
        ?>
    </div>

    <?php
    // Alertas: resumen por tipo (chips) y solo las primeras visibles; el resto con "Ver todas".
    $tiposAlerta = [
        'notificar' => ['Cambios sin notificar', 'is-danger'],
        'conflicto' => ['Conflictos de instructor', 'is-danger'],
        'cupo' => ['Cupos en riesgo', 'is-warn'],
        'sesiones' => ['Sesiones por ajustar', 'is-yellow'],
        'rango' => ['Sesiones fuera de rango', 'is-warn'],
        'documentos' => ['Documentación de instructores', 'is-warn'],
    ];
    $conteoAlertas = array_count_values(array_column($alertas, 5));
    $alertasIniciales = 5;
    ?>
    <section class="pp-alertas-barra<?php echo $alertas ? '' : ' is-ok'; ?>" id="pp-alertas">
        <div class="pp-alertas-cab">
            <button type="button" class="pp-alertas-abrir" id="pp-alertas-abrir" aria-expanded="false" aria-controls="pp-alertas-lista"<?php echo $alertas ? '' : ' disabled'; ?>>
                <span class="glyphicon glyphicon-<?php echo $alertas ? 'bell' : 'ok-circle'; ?>" aria-hidden="true"></span>
                <b><?php echo $alertas ? count($alertas) . ' alerta(s) por atender' : 'Sin alertas'; ?></b>
                <?php if ($alertas): ?><span class="pp-alertas-ver">Ver <span class="glyphicon glyphicon-menu-down" aria-hidden="true"></span></span><?php endif; ?>
            </button>
            <?php if ($alertas): ?>
                <div class="pp-alertas-resumen" role="group" aria-label="Filtrar alertas por tipo">
                    <?php foreach ($tiposAlerta as $tipo => [$texto, $clase]): if (empty($conteoAlertas[$tipo])) continue; ?>
                        <button type="button" class="pp-chip <?php echo $clase; ?>" data-alerta-filtro="<?php echo $tipo; ?>" aria-pressed="false"><?php echo $texto; ?> <b><?php echo $conteoAlertas[$tipo]; ?></b></button>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <span class="pp-sub">No hay choques de horario, cambios sin notificar ni sesiones pendientes urgentes en <?php echo cengi_pp_anio_texto($anio); ?>.</span>
            <?php endif; ?>
        </div>
        <div class="pp-alertas-lista" id="pp-alertas-lista" hidden>
            <?php if ($sinSesionesAun): ?>
                <p class="pp-alertas-nota"><span class="glyphicon glyphicon-info-sign"></span> <?php echo $sinSesionesAun; ?> plan(es) por aprobar aún no tienen todas sus sesiones. No es alerta: se programan al aprobarlos (lo indica su barra de sesiones).</p>
            <?php endif; ?>
            <?php foreach ($alertas as $i => $alerta): ?>
                <div class="pp-alert <?php echo $alerta[0]; ?>" data-alerta-tipo="<?php echo cengi_pp_h($alerta[5] ?? ''); ?>"<?php echo $i >= $alertasIniciales ? ' hidden' : ''; ?>><span class="pp-dot"></span><div><?php echo $alerta[1]; ?></div>
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
            <?php if (count($alertas) > $alertasIniciales): ?>
                <button type="button" class="pp-link pp-alertas-mas" id="pp-alertas-mas" data-total="<?php echo count($alertas); ?>">Ver todas (<?php echo count($alertas); ?>)</button>
            <?php endif; ?>
        </div>
    </section>

    <?php
    $estadosVista = ['planificado' => 'Planificado', 'aprobado' => 'Aprobado', 'reprogramado' => 'Reprogramado', 'ejecucion' => 'En ejecución', 'cancelado' => 'Cancelado'];
    $conteoEstados = array_count_values(array_map('cengi_plan_estado_vista', $planesAnio));
    ?>
    <section class="pp-card pp-planes">
        <form class="pp-toolbar" method="get" id="pp-filtros">
            <div class="pp-toolbar-group">
                <select name="anio" aria-label="Año" onchange="this.form.submit()">
                    <?php foreach ($anios as $z): ?>
                        <option value="<?php echo $z; ?>" <?php echo $z === $anio ? 'selected' : ''; ?>>Año <?php echo cengi_pp_anio_texto($z); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="pp-search">
                    <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                    <input type="search" name="q" id="pp-q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Buscar curso, código, instructor, área..." aria-label="Buscar" autocomplete="off">
                </label>
                <select name="modalidad" id="pp-modalidad" aria-label="Modalidad">
                    <option value="">Todas las modalidades</option>
                    <?php foreach (CENGI_PP_MODALIDADES as $m): ?>
                        <option <?php echo $modalidadFiltro === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="estado" id="pp-estado" value="<?php echo cengi_pp_h($estadoFiltro); ?>">
            </div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" id="pp-exportar" href="planificacion_cursos.php?<?php echo cengi_pp_h(http_build_query($filtrosActivos + ['exportar' => 'xlsx'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar</a>
                <a class="btn btn-default btn-sm" href="carga_masiva_planificacion.php?tipo=plan&amp;destino=<?php echo (int) $anio; ?>"><span class="glyphicon glyphicon-upload"></span> Cargar Excel/CSV</a>
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#plan-modal"><span class="glyphicon glyphicon-plus"></span> Nueva planificación</button>
            </div>
        </form>
        <div class="pp-estados" role="group" aria-label="Filtrar por estado">
            <button type="button" class="pp-estado-chip" data-estado="">Todos <b data-conteo=""><?php echo count($planesAnio); ?></b></button>
            <?php foreach ($estadosVista as $clave => $texto): ?>
                <button type="button" class="pp-estado-chip is-<?php echo $clave; ?>" data-estado="<?php echo $clave; ?>"><?php echo $texto; ?> <b data-conteo="<?php echo $clave; ?>"><?php echo (int) ($conteoEstados[$clave] ?? 0); ?></b></button>
            <?php endforeach; ?>
            <span class="pp-resultados" id="pp-resultados" aria-live="polite"></span>
            <button type="button" class="pp-link" id="pp-limpiar" hidden>Limpiar filtros</button>
        </div>
        <div class="pp-table-wrap" id="pp-tabla-wrap">
            <table class="cengi-org-table pp-plan-table">
                <thead>
                    <tr>
                        <th>Curso / Diplomado</th><th>Fechas y modalidad</th><th>Sesiones</th><th>Cupo y costo</th><th>Estado</th>
                        <th class="is-actions"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody id="pp-planes">
                    <?php if (!$planesAnio): ?>
                        <tr><td colspan="6" class="pp-empty">Aún no hay planificación para el año <?php echo cengi_pp_anio_texto($anio); ?>. Usa “Nueva planificación”.</td></tr>
                    <?php endif; ?>
                    <tr id="pp-sin-resultados" hidden><td colspan="6" class="pp-empty">Ningún plan coincide con la búsqueda o los filtros.</td></tr>
                    <?php
                    $mesActual = null;
                    $planesPorMes = array_count_values(array_map(static function ($p) {
                        return substr($p['fecha_inicio'], 0, 7);
                    }, $planesAnio));
                    foreach ($planesAnio as $p):
                        $mes = substr($p['fecha_inicio'], 0, 7);
                        if ($mes !== $mesActual):
                            $mesActual = $mes;
                    ?>
                        <tr class="pp-mes" data-mes="<?php echo cengi_pp_h($mes); ?>">
                            <th colspan="6"><button type="button" class="pp-mes-toggle" aria-expanded="true">
                                <span class="glyphicon glyphicon-menu-down" aria-hidden="true"></span>
                                <?php echo CENGI_PP_MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4); ?>
                                <span class="pp-mes-n"><?php echo $planesPorMes[$mes] . ($planesPorMes[$mes] === 1 ? ' plan' : ' planes'); ?></span>
                            </button></th>
                        </tr>
                    <?php endif;
                        $activas = (int) $p['sesiones_activas'];
                        $pct = min(100, (int) round($activas * 100 / max(1, $p['sesiones_planificadas'])));
                        $claseProg = $activas < $p['sesiones_planificadas'] ? 'is-incomplete' : ($activas > $p['sesiones_planificadas'] ? 'is-over' : '');
                        $convocado = cengi_pp_convocado($p);
                        $datosPlan = array_intersect_key($p, array_flip(['id', 'anio', 'nombre', 'categoria_curso_id', 'area_tecnica', 'actividad_tipo', 'modalidad', 'dirigido_a',
                            'fecha_inicio', 'fecha_fin', 'dias', 'sesiones_planificadas', 'cupo_minimo', 'cupo_maximo', 'costo_asociados', 'costo_externos',
                            'costo_extranjero', 'instructor_id', 'modulos', 'observaciones', 'estado', 'codigo_curso', 'curso_id']));
                        $enEjecucion = $p['curso_id'] && $p['estado'] !== 'cancelado';
                        $datosPlan['hora_inicio'] = substr($p['hora_inicio'], 0, 5);
                        $datosPlan['hora_fin'] = substr($p['hora_fin'], 0, 5);
                    ?>
                        <tr class="pp-plan-row<?php echo $p['estado'] === 'cancelado' ? ' pp-muted-row' : ''; ?>" data-mes="<?php echo cengi_pp_h($mes); ?>"
                            data-estado="<?php echo cengi_plan_estado_vista($p); ?>" data-modalidad="<?php echo cengi_pp_h($p['modalidad']); ?>"
                            data-buscar="<?php echo cengi_pp_h(cengi_texto_normalizado(cengi_plan_texto_busqueda($p))); ?>">
                            <td class="cengi-org-name pp-plan-name">
                                <strong><?php echo cengi_pp_h($p['nombre']); ?></strong>
                                <?php if ($p['estado'] === 'cancelado' && $p['motivo_cancelacion'] !== ''): ?>
                                    <span class="glyphicon glyphicon-info-sign pp-nota" title="<?php echo cengi_pp_h('Motivo de cancelación: ' . $p['motivo_cancelacion']); ?>" aria-label="Motivo de cancelación"></span>
                                <?php elseif (trim((string) $p['observaciones']) !== ''): ?>
                                    <span class="glyphicon glyphicon-comment pp-nota" title="<?php echo cengi_pp_h($p['observaciones']); ?>" aria-label="Observaciones"></span>
                                <?php endif; ?>
                                <?php if (!empty($alertasPorPlan[$p['id']])): ?>
                                    <a href="#pp-alertas" class="pp-fila-alerta" data-abrir-alertas title="<?php echo cengi_pp_h(implode("\n", $alertasPorPlan[$p['id']])); ?>"><span class="glyphicon glyphicon-bell"></span><?php echo count($alertasPorPlan[$p['id']]); ?></a>
                                <?php endif; ?>
                                <span class="pp-sub">
                                    <?php echo $p['codigo_curso'] ? '<span class="pp-mono">' . cengi_pp_h($p['codigo_curso']) . '</span> · ' : ''; ?><?php echo cengi_pp_h($p['categoria_nombre'] ?: 'Sin categoría'); ?>
                                    <?php if (cengi_pp_convocado($p) && !$p['curso_id']): ?>
                                        · <a href="inscripciones.php?plan=<?php echo (int) $p['id']; ?>"><?php echo (int) ($inscritos[$p['id']] ?? 0); ?>/<?php echo (int) $p['cupo_minimo']; ?> validadas</a>
                                    <?php elseif ($p['curso_id']): ?>
                                        · <a href="inscripciones.php?plan=<?php echo (int) $p['id']; ?>"><?php echo (int) ($inscritos[$p['id']] ?? 0); ?> inscrito(s)</a>
                                    <?php endif; ?>
                                    <?php if (!empty($pendientes[$p['id']])): ?>
                                        <a class="pp-chip is-warn" href="comunicaciones.php?tab=avisar&amp;avisar=<?php echo (int) $p['id']; ?>"><?php echo $pendientes[$p['id']]; ?> por avisar</a>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="pp-num" title="<?php echo cengi_pp_h(trim(cengi_pp_horario($p['hora_inicio'], $p['hora_fin']) . ($p['dias'] !== '' ? ' · ' . $p['dias'] : '') . ($p['dirigido_a'] ? ' · ' . $p['dirigido_a'] : ''))); ?>"><?php echo cengi_pp_fecha_corta($p['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($p['fecha_fin']); ?>
                                <span class="pp-sub"><?php echo cengi_pp_h($p['modalidad']); ?> · <?php echo cengi_pp_horario($p['hora_inicio'], $p['hora_fin']); ?></span></td>
                            <td><a class="pp-prog <?php echo $claseProg; ?>" href="programa_sesiones.php?plan=<?php echo (int) $p['id']; ?>" title="<?php echo $activas . ' de ' . $p['sesiones_planificadas']; ?> sesiones programadas"><span class="pp-prog-track"><span style="width:<?php echo $pct; ?>%"></span></span><span class="pp-num"><?php echo $activas . ' / ' . (int) $p['sesiones_planificadas']; ?></span></a></td>
                            <td class="pp-num" title="Cupo mínimo<?php echo $p['cupo_maximo'] ? ' – máximo' : ''; ?>. Costo asociados / externos<?php echo (float) $p['costo_extranjero'] > 0 ? ' / extranjero US$' . number_format((float) $p['costo_extranjero']) : ''; ?>"><?php echo (int) $p['cupo_minimo'] . ($p['cupo_maximo'] ? '–' . (int) $p['cupo_maximo'] : '+'); ?> personas
                                <span class="pp-sub"><?php echo cengi_pp_q($p['costo_asociados']) . ' asoc. · ' . cengi_pp_q($p['costo_externos']) . ' ext.'; ?></span></td>
                            <td>
                                <?php if ($enEjecucion): ?>
                                    <span class="cengi-status-badge is-running"><i></i>En ejecución</span>
                                    <span class="pp-sub"><a href="ver_participante_curso.php?id=<?php echo (int) $p['curso_id']; ?>" class="pp-mono"><?php echo cengi_pp_h($p['codigo_curso']); ?></a><?php echo $p['estado'] === 'reprogramado' ? ' · reprogramado' : ''; ?></span>
                                <?php else: ?>
                                    <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $p['estado']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="is-actions">
                                <div class="dropdown cengi-psum-actions">
                                    <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        Acciones <span class="caret"></span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-right">
                                    <?php
                                    if ($p['curso_id']) {
                                        echo cengi_plan_enlace('ver_participante_curso.php?id=' . (int) $p['curso_id'], 'eye-open', 'Ver curso');
                                        echo cengi_plan_enlace(cengi_pp_url_editar_curso($p), 'pencil', 'Editar datos y módulos del curso');
                                        echo cengi_plan_boton('usd', 'Editar costos y cupo del plan', ['data-toggle' => 'modal', 'data-target' => '#plan-modal', 'data-plan' => json_encode($datosPlan, JSON_UNESCAPED_UNICODE)]);
                                    } else {
                                        echo cengi_plan_boton('pencil', 'Editar', ['data-toggle' => 'modal', 'data-target' => '#plan-modal', 'data-plan' => json_encode($datosPlan, JSON_UNESCAPED_UNICODE)]);
                                    }
                                    if ($p['estado'] === 'planificado') {
                                        echo cengi_plan_post('aprobar', $p['id'], 'ok', $p['curso_id'] ? 'Aprobar' : 'Aprobar y abrir convocatoria', false, '¿Aprobar “' . $p['nombre'] . '”? ' . ($p['curso_id'] ? 'Se vinculará al curso ' . $p['codigo_curso'] . '.' : 'Se abre la convocatoria en el formulario de inscripción. El curso se crea al completar ' . (int) $p['cupo_minimo'] . ' inscripciones validadas.'));
                                    }
                                    if ($convocado) {
                                        echo cengi_plan_boton('time', 'Reprogramar', ['data-toggle' => 'modal', 'data-target' => '#accion-modal', 'data-accion' => 'reprogramar', 'data-id' => $p['id'], 'data-nombre' => $p['nombre'], 'data-fi' => $p['fecha_inicio'], 'data-ff' => $p['fecha_fin'],
                                            'data-movibles' => (int) $p['sesiones_movibles'], 'data-primera' => (string) $p['primera_movible'], 'data-fijas' => (int) $p['sesiones_fijas'], 'data-impartidas' => (int) $p['sesiones_impartidas'], 'data-plan-url' => 'programa_sesiones.php?plan=' . (int) $p['id']]);
                                    }
                                    echo cengi_plan_enlace('programa_sesiones.php?plan=' . (int) $p['id'], 'list', 'Programa de sesiones');
                                    echo cengi_plan_enlace('bitacora_cambios.php?plan=' . (int) $p['id'], 'dashboard', 'Historial de cambios');
                                    echo cengi_plan_post('duplicar', $p['id'], 'duplicate', 'Duplicar para el año siguiente', false, '¿Duplicar “' . $p['nombre'] . '” en el año ' . cengi_pp_anio_texto($p['anio'] + 1) . ' (mismas fechas, un año después)?');
                                    echo '<li role="separator" class="divider"></li>';
                                    if ($p['estado'] === 'cancelado') {
                                        echo cengi_plan_post('reactivar', $p['id'], 'repeat', 'Reactivar como planificado');
                                    } else {
                                        echo cengi_plan_boton('ban-circle', 'Cancelar', ['data-toggle' => 'modal', 'data-target' => '#accion-modal', 'data-accion' => 'cancelar', 'data-id' => $p['id'], 'data-nombre' => $p['nombre'], 'data-fi' => $p['fecha_inicio'], 'data-ff' => $p['fecha_fin'], 'data-curso' => $p['codigo_curso'] ?? ''], true);
                                    }
                                    if (!$p['curso_id']) {
                                        echo cengi_plan_post('eliminar', $p['id'], 'trash', 'Eliminar', true, '¿Eliminar “' . $p['nombre'] . '” y sus sesiones? Esta acción no se puede deshacer.');
                                    }
                                    ?>
                                    </ul>
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
                <div class="pp-form-msg is-warn" id="pf-aviso-curso">Este plan ya tiene el curso <b id="pf-curso-codigo"></b> en ejecución. El nombre, la categoría, las fechas, el horario, la modalidad, el instructor, el cupo máximo y los módulos se editan en <a id="pf-curso-link" href="#">Gestión de cursos</a> (los cambios se reflejan aquí y quedan en la bitácora). Para mover fechas avisando a los inscritos usa <b>Reprogramar</b>.</div>
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
                        <label>Módulos <small>(nombre y número de sesiones; se copian al curso cuando se abre)</small></label>
                        <div class="pp-mod-editor" id="pf-mod-editor" aria-label="Módulos del plan"></div>
                        <div class="pp-mod-pie">
                            <button type="button" class="btn btn-default btn-xs" id="pf-mod-add"><span class="glyphicon glyphicon-plus"></span> Agregar módulo</button>
                            <span class="pp-sub" id="pf-mod-suma"></span>
                        </div>
                        <!-- Se guarda como "Nombre|sesiones" por línea (cengi_pp_parse_modulos). -->
                        <textarea id="pf-modulos" name="modulos" hidden></textarea>
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
                        <label><input type="checkbox" name="mover_sesiones" id="accion-mover" value="1" checked> Mover también las sesiones programadas</label>
                        <label><input type="checkbox" name="mismo_dia" id="accion-mismo-dia" value="1" checked> Conservar el día de la semana de cada sesión</label>
                        <div class="pp-form-msg is-visible" id="accion-sesiones"></div>
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
        pintarModulos();
        $id('plan-modal-title').textContent = editando ? 'Editar planificación' : 'Nueva planificación de curso';
        $id('plan-modal-sub').textContent = editando ? ((p.codigo_curso ? p.codigo_curso + ' · ' : '') + 'Estado actual: ' + p.estado) : '';
        $id('pf-guardar').style.display = convocado ? 'none' : '';
        $id('pf-guardar').textContent = p.estado === 'cancelado' ? 'Guardar (sigue cancelado)' : 'Guardar como planificado';
        $id('pf-aprobar').textContent = convocado ? 'Guardar cambios' : 'Aprobar y abrir convocatoria';
        $id('pf-aprobar').style.display = p.estado === 'cancelado' ? 'none' : '';
        $id('pf-aviso-aprobar').style.display = convocado ? 'none' : '';
        $id('pf-motivo-wrap').style.display = convocado ? '' : 'none';
        // Con el curso creado, sus datos se editan en Gestión de cursos (el servidor también los ignora).
        var vinculado = editando && !!p.curso_id;
        ['pf-nombre', 'pf-categoria', 'pf-area', 'pf-actividad', 'pf-modalidad', 'pf-fi', 'pf-ff', 'pf-hi', 'pf-hf', 'pf-dias', 'pf-cupomax', 'pf-instr', 'pf-modulos']
            .forEach(function (id) { $id(id).disabled = vinculado; });
        $id('pf-mod-editor').querySelectorAll('input, button').forEach(function (el) { el.disabled = vinculado; });
        $id('pf-mod-add').disabled = vinculado;
        $id('pf-aviso-curso').classList.toggle('is-visible', vinculado);
        if (vinculado) {
            $id('pf-curso-codigo').textContent = p.codigo_curso || '';
            $id('pf-curso-link').href = 'ver_cursos.php?' + new URLSearchParams({ q: p.codigo_curso || '', anio: 'todos', editar: p.curso_id });
            $id('plan-modal-title').textContent = 'Editar costos y cupo del plan';
        }
        $id('pf-ff').min = $id('pf-fi').value;
        avisos();
    });

    // ---- Módulos del plan: filas "nombre + sesiones" guardadas en el textarea oculto como "Nombre|6" por línea.
    function filaModulo(nombre, sesiones) {
        var fila = document.createElement('div');
        fila.className = 'pp-mod-fila';
        fila.innerHTML = '<input type="text" class="form-control" maxlength="255" placeholder="Nombre del módulo" aria-label="Nombre del módulo">' +
            '<input type="number" class="form-control" min="1" max="999" placeholder="Sesiones" aria-label="Sesiones del módulo">' +
            '<button type="button" class="pp-mod-quitar" aria-label="Quitar módulo"><span class="glyphicon glyphicon-remove"></span></button>';
        fila.children[0].value = nombre || '';
        fila.children[1].value = sesiones || '';
        fila.children[2].addEventListener('click', function () { fila.remove(); guardarModulos(); });
        [fila.children[0], fila.children[1]].forEach(function (el) { el.addEventListener('input', guardarModulos); });
        $id('pf-mod-editor').appendChild(fila);
        return fila;
    }
    function pintarModulos() {
        $id('pf-mod-editor').innerHTML = '';
        var texto = $id('pf-modulos').value.trim(), lineas = texto.indexOf('|') >= 0 || /\n/.test(texto) ? texto.split(/\r?\n/) : texto.split(',');
        lineas.forEach(function (l) {
            var partes = l.split('|'), nombre = partes[0].trim();
            if (nombre && nombre !== 'Sin identificar') filaModulo(nombre, (partes[1] || '').trim());
        });
        guardarModulos();
    }
    function guardarModulos() {
        var filas = [].slice.call($id('pf-mod-editor').children), total = 0, conSesiones = 0, nombres = 0;
        $id('pf-modulos').value = filas.map(function (f) {
            var nombre = f.children[0].value.trim().replace(/\|/g, '/'), n = parseInt(f.children[1].value, 10);
            if (!nombre) return '';
            nombres++;
            if (n > 0) { total += n; conSesiones++; }
            return nombre + (n > 0 ? '|' + n : '');
        }).filter(Boolean).join('\n');
        var planificadas = +$id('pf-ses').value || 0, suma = $id('pf-mod-suma');
        suma.className = 'pp-sub';
        if (!nombres) {
            suma.textContent = 'Sin módulos: las sesiones quedan como “Sesión única”.';
        } else if (conSesiones < nombres) {
            suma.textContent = 'Indica las sesiones de cada módulo para que se asignen solas a las sesiones del curso.';
        } else {
            suma.textContent = 'Los módulos suman ' + total + ' de ' + planificadas + ' sesiones.';
            if (total !== planificadas) suma.className = 'pp-sub is-warn';
        }
    }
    $id('pf-mod-add').addEventListener('click', function () { filaModulo().children[0].focus(); guardarModulos(); });
    $id('pf-ses').addEventListener('input', guardarModulos);

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
        reproDatos = repro ? { movibles: +t.getAttribute('data-movibles') || 0, fijas: +t.getAttribute('data-fijas') || 0, impartidas: +t.getAttribute('data-impartidas') || 0, primera: t.getAttribute('data-primera') || '', url: t.getAttribute('data-plan-url') } : null;
        previaSesiones();
    });
    $id('accion-fi').addEventListener('change', function () {
        if (!this.value) return;
        set('accion-ff', addDays(this.value, +this.getAttribute('data-duracion') || 0));
        var d = diffDays(this.getAttribute('data-original'), this.value);
        $id('accion-delta').textContent = d ? '(' + (d > 0 ? '+' : '') + d + ' días)' : '';
        previaSesiones();
    });
    // Vista previa de lo que pasara con las sesiones (misma regla que cengi_pp_mover_sesiones).
    var reproDatos = null;
    function fechaCorta(iso) { var p = iso.split('-'); var dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb']; return dias[new Date(+p[0], p[1] - 1, +p[2]).getDay()] + ' ' + p[2] + '/' + p[1] + '/' + p[0]; }
    function previaSesiones() {
        var caja = $id('accion-sesiones');
        var repro = reproDatos;
        if (!repro) { caja.innerHTML = ''; return; }
        var html = [], fi = $id('accion-fi').value;
        if (!repro.movibles && !repro.fijas) html.push('El plan aún no tiene sesiones en Programa de sesiones.');
        if (repro.movibles) {
            if (!$id('accion-mover').checked) html.push('<b>' + repro.movibles + ' sesión(es) pendientes NO se moverán.</b>');
            else if (fi) {
                var d = diffDays(repro.impartidas ? $id('accion-fi').getAttribute('data-original') : repro.primera, fi);
                if ($id('accion-mismo-dia').checked) { var r = ((d % 7) + 7) % 7; if (r) d += 7 - r; }
                html.push(d ? '<b>' + repro.movibles + ' sesión(es) pendientes se moverán ' + (d > 0 ? '+' : '') + d + ' días</b>: la primera queda el ' + fechaCorta(addDays(repro.primera, d)) + '.'
                    : repro.movibles + ' sesión(es) pendientes quedan en su fecha.');
            }
        }
        if (repro.fijas) html.push('⚠ ' + repro.fijas + ' sesión(es) ya impartidas o incluidas en una liquidación de pago <b>no se mueven</b>. Si el curso no se impartió, revísalas en <a href="' + repro.url + '" target="_blank">Programa de sesiones</a>.');
        caja.innerHTML = html.join('<br>');
        caja.className = 'pp-form-msg is-visible' + (repro.fijas ? ' is-warn' : '');
    }
    $id('accion-mover').addEventListener('change', previaSesiones);
    $id('accion-mismo-dia').addEventListener('change', previaSesiones);

    // El menú "Acciones" vive dentro de .pp-table-wrap (overflow): se posiciona
    // fijo respecto a la ventana para que no se recorte en las últimas filas.
    $(document).on('shown.bs.dropdown', '.cengi-psum-actions', function () {
        var menu = this.querySelector('.dropdown-menu');
        var r = this.querySelector('.dropdown-toggle').getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.right = 'auto';
        menu.style.left = Math.max(8, r.right - menu.offsetWidth) + 'px';
        var top = r.bottom + 4;
        if (top + menu.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - menu.offsetHeight - 4);
        menu.style.top = top + 'px';
    }).on('hidden.bs.dropdown', '.cengi-psum-actions', function () {
        this.querySelector('.dropdown-menu').removeAttribute('style');
    });
    function cerrarMenus() { $('.cengi-psum-actions.open .dropdown-toggle').dropdown('toggle'); }
    $(window).on('scroll resize', cerrarMenus);
    $('#pp-tabla-wrap').on('scroll', cerrarMenus);

    // ---- Filtro instantáneo de la tabla (búsqueda, estado, modalidad) y grupos por mes.
    var filas = [].slice.call(document.querySelectorAll('#pp-planes .pp-plan-row'));
    var meses = [].slice.call(document.querySelectorAll('#pp-planes .pp-mes'));
    var chips = [].slice.call(document.querySelectorAll('.pp-estado-chip'));
    var q = $id('pp-q'), estado = $id('pp-estado'), modalidad = $id('pp-modalidad'), colapsados = {}, espera;
    var normalizar = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); };

    function filtrar() {
        var palabras = normalizar(q.value).split(/\s+/).filter(Boolean), est = estado.value, mod = modalidad.value;
        var visibles = 0, porMes = {}, porEstado = { '': 0 };
        filas.forEach(function (tr) {
            var coincideTexto = palabras.every(function (p) { return tr.getAttribute('data-buscar').indexOf(p) !== -1; });
            var base = coincideTexto && (!mod || tr.getAttribute('data-modalidad') === mod);
            if (base) {
                porEstado[''] += 1;
                porEstado[tr.getAttribute('data-estado')] = (porEstado[tr.getAttribute('data-estado')] || 0) + 1;
            }
            var ok = base && (!est || tr.getAttribute('data-estado') === est);
            tr.setAttribute('data-coincide', ok ? '1' : '0');
            if (ok) {
                visibles++;
                porMes[tr.getAttribute('data-mes')] = (porMes[tr.getAttribute('data-mes')] || 0) + 1;
            }
        });
        meses.forEach(function (m) {
            var n = porMes[m.getAttribute('data-mes')] || 0;
            m.hidden = !n;
            m.querySelector('.pp-mes-n').textContent = n + (n === 1 ? ' plan' : ' planes');
        });
        filas.forEach(function (tr) {
            tr.hidden = tr.getAttribute('data-coincide') !== '1' || !!colapsados[tr.getAttribute('data-mes')];
        });
        chips.forEach(function (c) {
            var clave = c.getAttribute('data-estado');
            c.classList.toggle('is-active', clave === est);
            c.setAttribute('aria-pressed', clave === est ? 'true' : 'false');
            c.querySelector('b').textContent = porEstado[clave] || 0;
        });
        if (filas.length) {
            $id('pp-resultados').textContent = visibles === filas.length ? filas.length + ' planes' : 'Mostrando ' + visibles + ' de ' + filas.length;
        }
        $id('pp-sin-resultados').hidden = visibles > 0 || !filas.length;
        $id('pp-limpiar').hidden = !(palabras.length || est || mod);

        // La URL y "Exportar" llevan los mismos filtros (recargar o exportar da lo mismo que se ve).
        var params = new URLSearchParams(location.search);
        [['anio', $id('pp-filtros').elements.anio.value], ['q', q.value.trim()], ['estado', est], ['modalidad', mod]].forEach(function (par) {
            if (par[1]) { params.set(par[0], par[1]); } else { params.delete(par[0]); }
        });
        params.delete('msg'); params.delete('exportar');
        var consulta = params.toString();
        params.set('exportar', 'xlsx');
        $id('pp-exportar').href = 'planificacion_cursos.php?' + params;
        try { history.replaceState(null, '', location.pathname + (consulta ? '?' + consulta : '')); } catch (e) {}
    }

    q.addEventListener('input', function () { clearTimeout(espera); espera = setTimeout(filtrar, 120); });
    modalidad.addEventListener('change', filtrar);
    chips.forEach(function (c) {
        c.addEventListener('click', function () { estado.value = c.getAttribute('data-estado'); filtrar(); });
    });
    $id('pp-limpiar').addEventListener('click', function () { q.value = ''; estado.value = ''; modalidad.value = ''; filtrar(); q.focus(); });
    // El formulario solo se envía al cambiar de año; Enter en la búsqueda no recarga la página.
    $id('pp-filtros').addEventListener('submit', function (e) { e.preventDefault(); filtrar(); });
    meses.forEach(function (m) {
        var boton = m.querySelector('.pp-mes-toggle');
        boton.addEventListener('click', function () {
            var clave = m.getAttribute('data-mes');
            colapsados[clave] = !colapsados[clave];
            boton.setAttribute('aria-expanded', colapsados[clave] ? 'false' : 'true');
            m.classList.toggle('is-colapsado', colapsados[clave]);
            filtrar();
        });
    });
    filtrar();

    // ---- Alertas: chips por tipo y "Ver todas".
    var alertas = [].slice.call(document.querySelectorAll('#pp-alertas .pp-alert'));
    var filtrosAlerta = [].slice.call(document.querySelectorAll('[data-alerta-filtro]'));
    var masAlertas = $id('pp-alertas-mas'), tipoAlerta = '', todasAlertas = false;
    function mostrarAlertas() {
        alertas.forEach(function (a, i) {
            a.hidden = tipoAlerta ? a.getAttribute('data-alerta-tipo') !== tipoAlerta : (!todasAlertas && i >= 5);
        });
        filtrosAlerta.forEach(function (b) {
            var activo = b.getAttribute('data-alerta-filtro') === tipoAlerta;
            b.classList.toggle('is-active', activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
        if (masAlertas) {
            masAlertas.hidden = !!tipoAlerta;
            masAlertas.textContent = todasAlertas ? 'Ver menos' : 'Ver todas (' + masAlertas.getAttribute('data-total') + ')';
        }
    }
    var lista = $id('pp-alertas-lista'), abrir = $id('pp-alertas-abrir');
    function abrirAlertas(si) {
        if (!lista) return;
        lista.hidden = !si;
        abrir.setAttribute('aria-expanded', si ? 'true' : 'false');
        $id('pp-alertas').classList.toggle('is-abierta', si);
        try { sessionStorage.setItem('ppAlertas', si ? '1' : ''); } catch (e) {}
    }
    if (abrir) abrir.addEventListener('click', function () { abrirAlertas(lista.hidden); });
    [].slice.call(document.querySelectorAll('[data-abrir-alertas]')).forEach(function (a) {
        a.addEventListener('click', function () { abrirAlertas(true); });
    });
    try { if (sessionStorage.getItem('ppAlertas') === '1') abrirAlertas(true); } catch (e) {}
    filtrosAlerta.forEach(function (b) {
        b.addEventListener('click', function () {
            tipoAlerta = tipoAlerta === b.getAttribute('data-alerta-filtro') ? '' : b.getAttribute('data-alerta-filtro');
            if (tipoAlerta) abrirAlertas(true);
            mostrarAlertas();
        });
    });
    if (masAlertas) {
        masAlertas.addEventListener('click', function () { todasAlertas = !todasAlertas; mostrarAlertas(); });
    }
})();
</script>
</body>
</html>
