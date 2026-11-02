<?php
/**
 * Calendario general. Panorama desde hoy (sesiones de la semana, cursos que inician
 * y finalizan, pendientes por atender) y calendario en vista semanal (sesiones día
 * por día) o mensual. La semana inicia el lunes.
 */
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';

// Marcar como impartida desde el calendario (mismas reglas que en Programa de sesiones).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'impartida') {
    $volver = (string) ($_POST['volver'] ?? '');
    if (!preg_match('~^calendario_cursos\.php(\?[^\r\n]*)?$~', $volver)) {
        $volver = 'calendario_cursos.php';
    }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT * FROM curso_sesiones WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) ($_POST['id'] ?? 0)]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sesion) {
            throw new InvalidArgumentException('La sesión ya no existe.');
        }
        if ($sesion['estado'] !== 'programada') {
            throw new InvalidArgumentException('Solo se marca como impartida una sesión programada (esta está ' . cengi_pp_h($sesion['estado']) . ').');
        }
        if ($sesion['fecha'] > $hoy) {
            throw new InvalidArgumentException('No se puede marcar como impartida una sesión futura (' . cengi_pp_fecha_corta($sesion['fecha']) . ').');
        }
        if ($sesion['liquidacion_id'] || $sesion['liquidacion_soporte_id']) {
            throw new InvalidArgumentException('Esta sesión está en una liquidación de pago.');
        }
        $plan = cengi_pp_plan($db, $sesion['planificacion_id']);
        if (!$plan || $plan['estado'] === 'cancelado') {
            throw new InvalidArgumentException('El curso de esta sesión está cancelado.');
        }
        $db->prepare("UPDATE curso_sesiones SET estado = 'impartida' WHERE id = ?")->execute([$sesion['id']]);
        cengi_pp_log($db, $plan, 'Sesión impartida', 'Sesión del ' . cengi_pp_fecha($sesion['fecha']) . ' — ' . $sesion['tema'], [
            'antes' => 'programada', 'despues' => 'impartida', 'sesion_id' => (int) $sesion['id'], 'afecta' => false,
        ]);
        cengi_pp_asignar_modulos($db, $sesion['planificacion_id']);
        $db->commit();
        header('Location: ' . $volver . (strpos($volver, '?') === false ? '?' : '&') . 'msg=impartida');
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Calendario: ' . $e->getMessage());
            $mensaje = 'No fue posible marcar la sesión.';
        }
    }
}
if ($mensaje === '' && ($_GET['msg'] ?? '') === 'impartida') {
    $mensaje = 'Sesión marcada como impartida: ya cuenta para el pago del instructor.';
}

$planFiltro = (int) ($_GET['plan'] ?? 0);
$filtroPlanSql = $planFiltro ? ' AND p.id = ' . $planFiltro : '';
$vista = ($_GET['vista'] ?? '') === 'mes' ? 'mes' : 'semana';

$lunesDe = static function ($iso) {
    return cengi_pp_sumar_dias($iso, 1 - (int) date('N', strtotime($iso)));
};

// ---------------------------------------------------------------- periodo visible
if ($vista === 'semana') {
    $desde = $lunesDe(cengi_pp_es_fecha($_GET['sem'] ?? '') ? $_GET['sem'] : $hoy);
    $hasta = cengi_pp_sumar_dias($desde, 6);
    $mes = substr($desde, 0, 7);
} else {
    $mes = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? $_GET['mes'] : substr($hoy, 0, 7);
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
}
$diaSel = cengi_pp_es_fecha($_GET['dia'] ?? '') && $_GET['dia'] >= $desde && $_GET['dia'] <= $hasta
    ? $_GET['dia'] : ($hoy >= $desde && $hoy <= $hasta ? $hoy : null);

$estadoUrl = ['vista' => $vista, 'plan' => $planFiltro ?: '', 'sem' => $vista === 'semana' ? $desde : '', 'mes' => $vista === 'mes' ? $mes : ''];
$url = static function (array $cambios) use ($estadoUrl) {
    return 'calendario_cursos.php?' . http_build_query(array_filter(array_merge($estadoUrl, $cambios), static function ($v) {
        return $v !== '' && $v !== null;
    }));
};
if ($vista === 'semana') {
    $urlAnterior = $url(['sem' => cengi_pp_sumar_dias($desde, -7)]);
    $urlSiguiente = $url(['sem' => cengi_pp_sumar_dias($desde, 7)]);
    $mesesCortos = static function ($iso) {
        return (int) substr($iso, 8, 2) . ' ' . CENGI_PP_MESES_CORTOS[(int) substr($iso, 5, 2)];
    };
    $tituloPeriodo = $mesesCortos($desde) . ' – ' . $mesesCortos($hasta) . ' ' . substr($hasta, 0, 4);
    $esPeriodoActual = $hoy >= $desde && $hoy <= $hasta;
} else {
    $urlAnterior = $url(['mes' => date('Y-m', strtotime($desde . ' -1 month'))]);
    $urlSiguiente = $url(['mes' => date('Y-m', strtotime($desde . ' +1 month'))]);
    $tituloPeriodo = CENGI_PP_MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4);
    $esPeriodoActual = substr($hoy, 0, 7) === $mes;
}
$urlHoy = $url(['sem' => '', 'mes' => '', 'dia' => '']);
$urlVistaSemana = $url(['vista' => 'semana', 'mes' => '', 'sem' => $diaSel ?: ($esPeriodoActual ? $hoy : $desde)]);
$urlVistaMes = $url(['vista' => 'mes', 'sem' => '', 'mes' => $mes]);

// ---------------------------------------------------------------- eventos del periodo
$stmt = $db->prepare("SELECT p.*, c.codigo_curso FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id
    WHERE p.estado <> 'cancelado' AND (p.fecha_inicio BETWEEN ? AND ? OR p.fecha_fin BETWEEN ? AND ?)$filtroPlanSql");
$stmt->execute([$desde, $hasta, $desde, $hasta]);
$planesPeriodo = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare("SELECT s.*, p.nombre AS plan_nombre, i.nombre AS instructor, st.nombre AS soporte
    FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    LEFT JOIN instructores i ON i.id = s.instructor_id LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id
    WHERE s.estado <> 'cancelada' AND p.estado <> 'cancelado' AND s.fecha BETWEEN ? AND ?$filtroPlanSql ORDER BY s.fecha, s.hora_inicio");
$stmt->execute([$desde, $hasta]);
$sesionesPeriodo = $stmt->fetchAll(PDO::FETCH_ASSOC);
$conflictos = cengi_pp_conflictos($db);
$inscritos = cengi_pp_conteo_inscritos($db);
$tieneConflicto = static function (array $s) use ($conflictos) {
    return isset($conflictos['instructor'][$s['id']]) || isset($conflictos['soporte'][$s['id']]);
};

$eventos = [];
foreach ($planesPeriodo as $p) {
    if ($p['fecha_inicio'] >= $desde && $p['fecha_inicio'] <= $hasta) {
        $eventos[$p['fecha_inicio']][] = ['tipo' => 'inicio', 'orden' => 0, 'plan' => $p];
    }
    if ($p['fecha_fin'] !== $p['fecha_inicio'] && $p['fecha_fin'] >= $desde && $p['fecha_fin'] <= $hasta) {
        $eventos[$p['fecha_fin']][] = ['tipo' => 'fin', 'orden' => 3, 'plan' => $p];
    }
}
foreach ($sesionesPeriodo as $s) {
    $conflicto = isset($conflictos['instructor'][$s['id']]);
    $eventos[$s['fecha']][] = ['tipo' => $conflicto ? 'conflicto' : 'sesion', 'orden' => $conflicto ? 1 : 2, 'sesion' => $s];
}
foreach ($eventos as &$lista) {
    usort($lista, static function ($a, $b) {
        return $a['orden'] <=> $b['orden'];
    });
}
unset($lista);

function cengi_cal_etiqueta(array $e, array $inscritos)
{
    if ($e['tipo'] === 'inicio') {
        $p = $e['plan'];
        return 'Inicia' . (cengi_pp_convocado($p) ? ' (' . ($inscritos[$p['id']] ?? 0) . '/' . $p['cupo_minimo'] . ')' : '') . ': ' . $p['nombre'];
    }
    if ($e['tipo'] === 'fin') {
        return 'Finaliza: ' . $e['plan']['nombre'];
    }
    return cengi_pp_hora($e['sesion']['hora_inicio']) . ' ' . $e['sesion']['tema'];
}

/** Enlace del inicio de un curso: inscripciones mientras está en convocatoria; si no, sus sesiones. */
function cengi_cal_url_plan(array $p)
{
    return cengi_pp_convocado($p) && !$p['curso_id'] ? 'inscripciones.php?plan=' . (int) $p['id'] : 'programa_sesiones.php?ver=' . (int) $p['id'];
}

function cengi_cal_cuando($dias, $verbo)
{
    if ($dias <= 0) {
        return $verbo . ' hoy';
    }
    return $dias === 1 ? $verbo . ' mañana' : 'en ' . $dias . ' días';
}

function cengi_cal_horas(array $sesiones)
{
    $horas = 0;
    foreach ($sesiones as $s) {
        $horas += max(0, strtotime('2000-01-01 ' . $s['hora_fin']) - strtotime('2000-01-01 ' . $s['hora_inicio'])) / 3600;
    }
    return round($horas, 1);
}

// ---------------------------------------------------------------- panorama desde hoy
$lunesHoy = $lunesDe($hoy);
$stmt = $db->prepare("SELECT s.* FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    WHERE s.estado <> 'cancelada' AND p.estado <> 'cancelado' AND s.fecha BETWEEN ? AND ?$filtroPlanSql");
$stmt->execute([$lunesHoy, cengi_pp_sumar_dias($lunesHoy, 6)]);
$sesionesSemanaHoy = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sesionesDeHoy = count(array_filter($sesionesSemanaHoy, static function ($s) use ($hoy) {
    return $s['fecha'] === $hoy;
}));

$sqlPlanesConSesiones = "SELECT p.*, c.codigo_curso,
        (SELECT COUNT(*) FROM curso_sesiones s WHERE s.planificacion_id = p.id AND s.estado <> 'cancelada') AS ses_activas,
        (SELECT COUNT(*) FROM curso_sesiones s WHERE s.planificacion_id = p.id AND s.estado = 'impartida') AS ses_impartidas
    FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id
    WHERE p.estado <> 'cancelado' AND p.%s BETWEEN ? AND ?$filtroPlanSql ORDER BY p.%s, p.nombre";
$stmt = $db->prepare(sprintf($sqlPlanesConSesiones, 'fecha_inicio', 'fecha_inicio'));
$stmt->execute([$hoy, cengi_pp_sumar_dias($hoy, 45)]);
$porIniciar = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare(sprintf($sqlPlanesConSesiones, 'fecha_fin', 'fecha_fin'));
$stmt->execute([$hoy, cengi_pp_sumar_dias($hoy, 30)]);
$porFinalizar = $stmt->fetchAll(PDO::FETCH_ASSOC);

$inician30 = array_filter($porIniciar, static function ($p) use ($hoy) {
    return cengi_pp_dias_entre($hoy, $p['fecha_inicio']) <= 30;
});
$cupoEnRiesgo = array_filter($inician30, static function ($p) use ($inscritos) {
    return in_array(cengi_pp_semaforo_cupo($p, $inscritos[$p['id']] ?? 0)[0], ['amarillo', 'rojo'], true);
});
$sinAprobar = array_filter($inician30, static function ($p) {
    return !cengi_pp_convocado($p);
});
$faltanSesiones = array_filter($inician30, static function ($p) {
    return (int) $p['ses_activas'] < (int) $p['sesiones_planificadas'];
});
$finalizanSemana = array_filter($porFinalizar, static function ($p) use ($hoy) {
    return cengi_pp_dias_entre($hoy, $p['fecha_fin']) <= 7;
});

// Sesiones de las dos semanas alrededor de hoy que necesitan algo.
$stmt = $db->prepare("SELECT s.*, p.nombre AS plan_nombre FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    WHERE s.estado = 'programada' AND p.estado <> 'cancelado' AND s.fecha BETWEEN ? AND ?$filtroPlanSql ORDER BY s.fecha, s.hora_inicio");
$stmt->execute([cengi_pp_sumar_dias($hoy, -14), cengi_pp_sumar_dias($hoy, 14)]);
$revisar = ['conflicto' => [], 'instructor' => [], 'soporte' => [], 'marcar' => []];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    if ($s['fecha'] < $hoy) {
        $revisar['marcar'][] = $s;
        continue;
    }
    if ($tieneConflicto($s)) {
        $revisar['conflicto'][] = $s;
    }
    if (!$s['instructor_id']) {
        $revisar['instructor'][] = $s;
    }
    if ($s['modalidad'] !== 'Presencial' && !$s['soporte_id']) {
        $revisar['soporte'][] = $s;
    }
}

$nombresSesiones = static function (array $sesiones) {
    $textos = array_map(static function ($s) {
        return cengi_pp_fecha_corta($s['fecha']) . ' · ' . $s['plan_nombre'];
    }, array_slice($sesiones, 0, 3));
    return implode('; ', $textos) . (count($sesiones) > 3 ? ' y ' . (count($sesiones) - 3) . ' más' : '');
};
$nombresPlanes = static function (array $planes) {
    $planes = array_values($planes);
    $textos = array_map(static function ($p) {
        return $p['nombre'];
    }, array_slice($planes, 0, 3));
    return implode('; ', $textos) . (count($planes) > 3 ? ' y ' . (count($planes) - 3) . ' más' : '');
};
$primero = static function (array $lista) {
    return reset($lista);
};

// [nivel, icono, titulo, detalle, enlace, texto del enlace]
$atencion = [];
if ($revisar['conflicto']) {
    $s = $revisar['conflicto'][0];
    $atencion[] = ['is-danger', 'random', count($revisar['conflicto']) . ' sesión(es) chocan con otra del mismo instructor o soporte',
        $nombresSesiones($revisar['conflicto']), 'programa_sesiones.php?editar=' . (int) $s['id'], 'Reprogramar'];
}
if ($revisar['instructor']) {
    $s = $revisar['instructor'][0];
    $atencion[] = ['is-danger', 'user', count($revisar['instructor']) . ' sesión(es) de los próximos 14 días sin instructor',
        $nombresSesiones($revisar['instructor']), 'programa_sesiones.php?editar=' . (int) $s['id'], 'Asignar instructor'];
}
if ($cupoEnRiesgo) {
    $p = $primero($cupoEnRiesgo);
    $atencion[] = ['is-warn', 'bullhorn', count($cupoEnRiesgo) . ' curso(s) inician en 30 días sin el cupo mínimo',
        $nombresPlanes($cupoEnRiesgo) . '. Conviene reforzar la convocatoria o decidir si se reprograma.', 'inscripciones.php?plan=' . (int) $p['id'], 'Ver inscripciones'];
}
if ($sinAprobar) {
    $atencion[] = ['is-warn', 'calendar', count($sinAprobar) . ' curso(s) inician en 30 días y su plan aún no está aprobado',
        $nombresPlanes($sinAprobar) . '. Sin aprobación no se abren las inscripciones.', 'planificacion_cursos.php?estado=planificado', 'Ir a Planificación'];
}
if ($faltanSesiones) {
    $p = $primero($faltanSesiones);
    $atencion[] = ['is-warn', 'list', count($faltanSesiones) . ' curso(s) por iniciar no tienen todas sus sesiones programadas',
        $nombresPlanes($faltanSesiones), 'programa_sesiones.php?ver=' . (int) $p['id'], 'Programar sesiones'];
}
if ($revisar['soporte']) {
    $s = $revisar['soporte'][0];
    $atencion[] = ['is-warn', 'blackboard', count($revisar['soporte']) . ' sesión(es) virtuales o híbridas sin soporte técnico',
        $nombresSesiones($revisar['soporte']), 'programa_sesiones.php?editar=' . (int) $s['id'], 'Asignar soporte'];
}
if ($revisar['marcar']) {
    $s = $revisar['marcar'][0];
    $atencion[] = ['is-info', 'check', count($revisar['marcar']) . ' sesión(es) ya pasaron y siguen como “Programada”',
        $nombresSesiones($revisar['marcar']) . '. Márcalas como impartidas para que entren al pago de instructores.', $url(['vista' => 'semana', 'mes' => '', 'sem' => $s['fecha'], 'dia' => '']), 'Marcar en el calendario'];
}
$finalizanConCurso = array_filter($finalizanSemana, static function ($p) {
    return (bool) $p['curso_id'];
});
if ($finalizanConCurso) {
    $p = $primero($finalizanConCurso);
    $atencion[] = ['is-info', 'education', count($finalizanConCurso) . ' curso(s) finalizan esta semana',
        $nombresPlanes($finalizanConCurso) . '. Prepara la carga de notas, la evaluación del instructor y los diplomas.', 'ver_participante_curso.php?id=' . (int) $p['curso_id'], 'Abrir seguimiento'];
}

$coloresSemaforo = ['verde' => '#73BC25', 'amarillo' => '#FFCC00', 'rojo' => '#FF6B00', 'gris' => '#CED2D5'];
$clasesSemaforo = ['verde' => 'is-active', 'amarillo' => 'is-upcoming', 'rojo' => 'is-rejected', 'gris' => 'is-neutral'];
$coloresCurso = ['#73BC25', '#FF6B00', '#A3D300', '#1f5632', '#FFCC00', '#8A9585'];
$colorCurso = static function ($planId) use ($coloresCurso) {
    return $coloresCurso[(int) $planId % count($coloresCurso)];
};
$gruposPlanes = cengi_pp_planes_opciones($db);
$diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$urlVolver = $url(['dia' => $vista === 'mes' ? ($diaSel ?: '') : '']);
// Botón "Impartida" para una sesión programada de hoy o de días anteriores.
$botonImpartida = static function (array $s) use ($hoy, $urlVolver) {
    if ($s['estado'] !== 'programada') {
        return '';
    }
    if ($s['fecha'] > $hoy) {
        // Futura: se puede reprogramar (con vista previa de las siguientes) en Programa de sesiones.
        return '<div class="cal-impartida-form"><a class="cal-ses-btn is-reprogramar" href="programa_sesiones.php?reprogramar=' . (int) $s['id'] . '">'
            . '<span class="glyphicon glyphicon-share-alt"></span>Reprogramar</a></div>';
    }
    return '<form method="post" class="cal-impartida-form" onsubmit="return confirm(\'¿Marcar la sesión «' . cengi_pp_h(addslashes($s['tema'])) . '» como impartida? Cuenta para el pago del instructor.\');">'
        . '<input type="hidden" name="accion" value="impartida"><input type="hidden" name="id" value="' . (int) $s['id'] . '">'
        . '<input type="hidden" name="volver" value="' . cengi_pp_h($urlVolver) . '">'
        . '<button type="submit" class="cal-ses-btn"><span class="glyphicon glyphicon-ok"></span>Marcar impartida</button></form>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Calendario general'); ?>
    <link rel="stylesheet" href="css/calendario.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/calendario.css') ?: 1); ?>">
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page cal-page">
    <?php cengi_banner(); ?>
    <?php cengi_pp_subnav('calendario_cursos.php'); ?>
    <?php cengi_pp_feedback(cengi_pp_h($mensaje), $mensajeTipo); ?>

    <!-- Panorama desde hoy: no depende del periodo que se esté viendo. -->
    <div class="cal-kpis" aria-label="Panorama desde hoy">
        <a class="cal-kpi is-verde" href="<?php echo cengi_pp_h($url(['vista' => 'semana', 'sem' => '', 'mes' => '', 'dia' => ''])); ?>">
            <span class="cal-kpi-icon"><span class="glyphicon glyphicon-time"></span></span>
            <span class="cal-kpi-body"><span class="cal-kpi-val"><?php echo count($sesionesSemanaHoy); ?></span>
                <span class="cal-kpi-label">Sesiones esta semana</span>
                <span class="cal-kpi-detail"><?php echo cengi_cal_horas($sesionesSemanaHoy); ?> h en total · <?php echo $sesionesDeHoy; ?> hoy</span></span>
        </a>
        <a class="cal-kpi is-verde2" href="#por-iniciar">
            <span class="cal-kpi-icon"><span class="glyphicon glyphicon-play"></span></span>
            <span class="cal-kpi-body"><span class="cal-kpi-val"><?php echo count($inician30); ?></span>
                <span class="cal-kpi-label">Inician en 30 días</span>
                <span class="cal-kpi-detail"><?php echo count($cupoEnRiesgo) ? count($cupoEnRiesgo) . ' sin cupo mínimo' : 'Cupos en orden'; ?></span></span>
        </a>
        <a class="cal-kpi is-naranja" href="#por-finalizar">
            <span class="cal-kpi-icon"><span class="glyphicon glyphicon-flag"></span></span>
            <span class="cal-kpi-body"><span class="cal-kpi-val"><?php echo count($porFinalizar); ?></span>
                <span class="cal-kpi-label">Finalizan en 30 días</span>
                <span class="cal-kpi-detail"><?php echo count($finalizanSemana); ?> esta semana</span></span>
        </a>
        <a class="cal-kpi <?php echo $atencion ? 'is-amarillo' : 'is-gris'; ?>" href="#atencion">
            <span class="cal-kpi-icon"><span class="glyphicon glyphicon-<?php echo $atencion ? 'bell' : 'ok'; ?>"></span></span>
            <span class="cal-kpi-body"><span class="cal-kpi-val"><?php echo count($atencion); ?></span>
                <span class="cal-kpi-label">Pendientes por atender</span>
                <span class="cal-kpi-detail"><?php echo $atencion ? 'Recomendaciones abajo' : 'Todo en orden'; ?></span></span>
        </a>
    </div>

    <?php if ($atencion): ?>
        <details class="cal-atencion" id="atencion" open>
            <summary><span class="glyphicon glyphicon-bell"></span>Recomendaciones para las próximas semanas <span class="cal-count"><?php echo count($atencion); ?></span></summary>
            <ul>
                <?php foreach ($atencion as [$nivel, $icono, $titulo, $detalle, $enlace, $textoEnlace]): ?>
                    <li class="<?php echo $nivel; ?>">
                        <span class="cal-atencion-icon"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span></span>
                        <div><b><?php echo cengi_pp_h($titulo); ?></b><span><?php echo cengi_pp_h($detalle); ?></span></div>
                        <a class="cal-atencion-btn" href="<?php echo cengi_pp_h($enlace); ?>"><?php echo cengi_pp_h($textoEnlace); ?> <span class="glyphicon glyphicon-arrow-right"></span></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <div class="cal-layout">
        <section class="pp-card cal-main">
            <div class="cal-toolbar">
                <div class="cal-nav">
                    <a class="cal-nav-btn" href="<?php echo cengi_pp_h($urlAnterior); ?>" aria-label="<?php echo $vista === 'semana' ? 'Semana anterior' : 'Mes anterior'; ?>"><span class="glyphicon glyphicon-chevron-left"></span></a>
                    <h2 class="cal-periodo"><?php echo cengi_pp_h($tituloPeriodo); ?></h2>
                    <a class="cal-nav-btn" href="<?php echo cengi_pp_h($urlSiguiente); ?>" aria-label="<?php echo $vista === 'semana' ? 'Semana siguiente' : 'Mes siguiente'; ?>"><span class="glyphicon glyphicon-chevron-right"></span></a>
                    <a class="cal-hoy<?php echo $esPeriodoActual ? ' is-current' : ''; ?>" href="<?php echo cengi_pp_h($urlHoy); ?>">Hoy</a>
                </div>
                <div class="cal-vistas" role="group" aria-label="Vista del calendario">
                    <a href="<?php echo cengi_pp_h($urlVistaSemana); ?>" class="<?php echo $vista === 'semana' ? 'is-active' : ''; ?>"<?php echo $vista === 'semana' ? ' aria-current="page"' : ''; ?>><span class="glyphicon glyphicon-th-list"></span>Semana</a>
                    <a href="<?php echo cengi_pp_h($urlVistaMes); ?>" class="<?php echo $vista === 'mes' ? 'is-active' : ''; ?>"<?php echo $vista === 'mes' ? ' aria-current="page"' : ''; ?>><span class="glyphicon glyphicon-th"></span>Mes</a>
                </div>
                <form method="get" class="cal-filtro">
                    <input type="hidden" name="vista" value="<?php echo $vista; ?>">
                    <input type="hidden" name="<?php echo $vista === 'semana' ? 'sem' : 'mes'; ?>" value="<?php echo $vista === 'semana' ? $desde : $mes; ?>">
                    <span class="glyphicon glyphicon-filter" aria-hidden="true"></span>
                    <select name="plan" aria-label="Curso" onchange="this.form.submit()">
                        <option value="">Todos los cursos</option>
                        <?php echo cengi_pp_options_planes($gruposPlanes, (string) $planFiltro); ?>
                    </select>
                </form>
                <a class="cal-exportar" href="<?php echo cengi_pp_h('sesiones_ics.php?' . http_build_query(array_filter(['desde' => $desde, 'hasta' => $hasta, 'plan' => $planFiltro ?: '']))); ?>" title="Descarga las sesiones de este periodo para importarlas en Outlook u otro calendario"><span class="glyphicon glyphicon-calendar"></span>Exportar a Outlook</a>
            </div>
            <div class="cal-resumen-periodo">
                <span><b><?php echo count($sesionesPeriodo); ?></b> sesión(es) · <?php echo cengi_cal_horas($sesionesPeriodo); ?> h</span>
                <span><i class="is-inicio"></i>Inicio de curso</span>
                <span><i class="is-fin"></i>Finaliza curso</span>
                <span><i class="is-sesion"></i>Sesión</span>
                <span><i class="is-conflicto"></i>Conflicto</span>
                <?php if ($vista === 'semana'): ?><span class="cal-nota">El borde de cada sesión identifica a su curso.</span><?php endif; ?>
            </div>

            <?php if ($vista === 'semana'): ?>
                <div class="cal-week">
                    <?php for ($i = 0; $i < 7; $i++):
                        $iso = cengi_pp_sumar_dias($desde, $i);
                        $lista = $eventos[$iso] ?? [];
                        $marcas = array_filter($lista, static function ($e) {
                            return $e['tipo'] === 'inicio' || $e['tipo'] === 'fin';
                        });
                        $sesionesDia = array_filter($lista, static function ($e) {
                            return isset($e['sesion']);
                        });
                        usort($sesionesDia, static function ($a, $b) {
                            return strcmp($a['sesion']['hora_inicio'], $b['sesion']['hora_inicio']);
                        });
                        $clases = 'cal-wday' . ($iso === $hoy ? ' is-today' : '') . ($iso < $hoy ? ' is-past' : '') . ($i >= 5 ? ' is-weekend' : '') . (!$lista ? ' is-empty' : '');
                    ?>
                        <section class="<?php echo $clases; ?>" aria-label="<?php echo cengi_pp_h(cengi_pp_fecha_larga($iso)); ?>">
                            <header>
                                <span class="cal-wday-name"><?php echo $diasSemana[$i]; ?></span>
                                <span class="cal-wday-num"><?php echo (int) substr($iso, 8, 2); ?></span>
                                <?php if ($sesionesDia): ?><span class="cal-wday-count" title="Sesiones"><?php echo count($sesionesDia); ?></span><?php endif; ?>
                            </header>
                            <div class="cal-wday-body">
                                <?php foreach ($marcas as $e): $p = $e['plan']; ?>
                                    <a class="cal-mark is-<?php echo $e['tipo']; ?>" href="<?php echo cengi_pp_h($e['tipo'] === 'inicio' ? cengi_cal_url_plan($p) : ($p['curso_id'] ? 'ver_participante_curso.php?id=' . (int) $p['curso_id'] : 'programa_sesiones.php?ver=' . (int) $p['id'])); ?>">
                                        <span class="glyphicon glyphicon-<?php echo $e['tipo'] === 'inicio' ? 'play' : 'flag'; ?>"></span>
                                        <span><b><?php echo $e['tipo'] === 'inicio' ? 'Inicia' : 'Finaliza'; ?></b> <?php echo cengi_pp_h($p['nombre']); ?></span>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach ($sesionesDia as $e): $s = $e['sesion']; $conflicto = $tieneConflicto($s); $pendiente = $s['estado'] === 'programada' && $s['fecha'] < $hoy; ?>
                                    <article class="cal-ses<?php echo $conflicto ? ' is-conflicto' : ''; ?><?php echo $s['estado'] === 'impartida' ? ' is-impartida' : ''; ?><?php echo $pendiente ? ' is-pendiente' : ''; ?>" style="--curso:<?php echo $colorCurso($s['planificacion_id']); ?>">
                                        <a class="cal-ses-link" href="programa_sesiones.php?editar=<?php echo (int) $s['id']; ?>" title="<?php echo cengi_pp_h($s['plan_nombre'] . ' — ' . $s['modulo'] . ' (clic para editar)'); ?>">
                                            <span class="cal-ses-time"><?php echo cengi_pp_horario($s['hora_inicio'], $s['hora_fin']); ?>
                                                <?php if ($s['estado'] === 'impartida'): ?><span class="cal-ses-estado is-ok"><span class="glyphicon glyphicon-ok"></span>Impartida</span><?php endif; ?>
                                                <?php if ($pendiente): ?><span class="cal-ses-estado is-pendiente">Sin marcar</span><?php endif; ?>
                                                <?php if ($conflicto): ?><span class="glyphicon glyphicon-warning-sign" title="Conflicto de horario"></span><?php endif; ?></span>
                                            <b class="cal-ses-tema"><?php echo cengi_pp_h($s['tema']); ?></b>
                                            <span class="cal-ses-curso"><?php echo cengi_pp_h($s['plan_nombre']); ?></span>
                                            <span class="cal-ses-meta"><span class="glyphicon glyphicon-user"></span><?php echo cengi_pp_h($s['instructor'] ?: 'Sin instructor'); ?></span>
                                            <span class="cal-ses-meta"><span class="glyphicon glyphicon-<?php echo $s['modalidad'] === 'Presencial' ? 'map-marker' : 'facetime-video'; ?>"></span><?php echo cengi_pp_h($s['lugar'] !== '' ? $s['lugar'] : $s['modalidad']); ?></span>
                                        </a>
                                        <?php echo $botonImpartida($s); ?>
                                    </article>
                                <?php endforeach; ?>
                                <?php if (!$lista): ?><span class="cal-libre">Sin actividades</span><?php endif; ?>
                            </div>
                        </section>
                    <?php endfor; ?>
                </div>
            <?php else: ?>
                <div class="cal-month">
                    <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $d): ?><div class="cal-dow"><?php echo $d; ?></div><?php endforeach; ?>
                    <?php for ($i = 1, $inicio = (int) date('N', strtotime($desde)); $i < $inicio; $i++): ?><div class="cal-day-blank"></div><?php endfor; ?>
                    <?php for ($d = 1, $total = (int) date('t', strtotime($desde)); $d <= $total; $d++):
                        $iso = sprintf('%s-%02d', $mes, $d);
                        $lista = $eventos[$iso] ?? [];
                        $dow = (int) date('N', strtotime($iso));
                        $clases = 'pp-cal-day' . ($lista ? ' has-events' : '') . ($iso === $hoy ? ' is-today' : '') . ($iso === $diaSel ? ' is-selected' : '') . ($dow >= 6 ? ' is-weekend' : '') . ($iso < $hoy ? ' is-past' : '');
                    ?>
                        <a class="<?php echo $clases; ?>" href="<?php echo cengi_pp_h($url(['dia' => $iso])); ?>#detalle-dia" aria-label="<?php echo cengi_pp_h(cengi_pp_fecha_larga($iso) . ': ' . count($lista) . ' actividad(es)'); ?>">
                            <span class="pp-cal-n"><?php echo $d; ?></span>
                            <?php foreach (array_slice($lista, 0, 3) as $e): $texto = cengi_cal_etiqueta($e, $inscritos); ?>
                                <span class="pp-cal-ev is-<?php echo $e['tipo']; ?>" title="<?php echo cengi_pp_h($texto . (isset($e['sesion']) ? ' — ' . $e['sesion']['plan_nombre'] : '')); ?>"><?php echo cengi_pp_h($texto); ?></span>
                            <?php endforeach; ?>
                            <?php if (count($lista) > 3): ?><span class="pp-cal-more">+<?php echo count($lista) - 3; ?> más</span><?php endif; ?>
                        </a>
                    <?php endfor; ?>
                </div>

                <div class="cal-detalle" id="detalle-dia">
                    <h3><?php echo $diaSel ? cengi_pp_h(ucfirst(cengi_pp_fecha_larga($diaSel))) : 'Selecciona un día'; ?></h3>
                    <?php $delDia = $diaSel ? ($eventos[$diaSel] ?? []) : []; ?>
                    <?php if (!$diaSel): ?>
                        <span class="pp-sub">Haz clic en un día del calendario para ver el detalle.</span>
                    <?php elseif (!$delDia): ?>
                        <span class="pp-sub">Sin actividades este día. <a href="programa_sesiones.php">Programar una sesión</a></span>
                    <?php endif; ?>
                    <?php foreach ($delDia as $e): ?>
                        <div class="pp-day-item is-<?php echo $e['tipo']; ?>">
                            <?php if ($e['tipo'] === 'inicio' || $e['tipo'] === 'fin'): $p = $e['plan']; ?>
                                <strong><?php echo $e['tipo'] === 'inicio' ? 'Inicia' : 'Finaliza'; ?>: <?php echo cengi_pp_h($p['nombre']); ?></strong>
                                <span class="pp-sub"><?php echo cengi_pp_h($p['modalidad'] . ' · ' . cengi_pp_horario($p['hora_inicio'], $p['hora_fin'])); ?> · <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $p['estado']); ?></span>
                                <?php if ($e['tipo'] === 'inicio'): [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($p, $inscritos[$p['id']] ?? 0); ?>
                                    <span class="pp-sub"><span class="cengi-status-badge <?php echo $clasesSemaforo[$color]; ?>"><i></i><?php echo $etiqueta; ?></span> <?php echo cengi_pp_h($detalle); ?></span>
                                <?php endif; ?>
                            <?php else: $s = $e['sesion']; ?>
                                <strong><?php echo cengi_pp_horario($s['hora_inicio'], $s['hora_fin']) . ' · ' . cengi_pp_h($s['tema']); ?></strong>
                                <span class="pp-sub"><?php echo cengi_pp_h($s['plan_nombre'] . ' — ' . $s['modulo']); ?></span>
                                <span class="pp-sub"><?php echo cengi_pp_h(($s['instructor'] ?: 'Sin instructor') . ' · ' . ($s['lugar'] !== '' ? $s['lugar'] : $s['modalidad']) . ($s['soporte'] ? ' · Soporte: ' . $s['soporte'] : '')); ?></span>
                                <?php if ($e['tipo'] === 'conflicto'): ?><span class="pp-chip is-danger">Conflicto de horario del instructor</span><?php endif; ?>
                                <span class="pp-sub"><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_SESION, $s['estado']); ?> <a href="programa_sesiones.php?editar=<?php echo (int) $s['id']; ?>">Editar sesión</a></span>
                                <?php echo $botonImpartida($s); ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="cal-side">
            <section class="pp-card cal-list" id="por-iniciar">
                <div class="pp-card-head"><div><h3>Por iniciar</h3><span class="pp-sub">Próximos 45 días · cupo y sesiones listas</span></div><span class="cal-count is-verde2"><?php echo count($porIniciar); ?></span></div>
                <div class="pp-card-body">
                    <?php if (!$porIniciar): ?><p class="cal-vacio"><span class="glyphicon glyphicon-calendar"></span>Ningún curso inicia en los próximos 45 días.</p><?php endif; ?>
                    <?php foreach ($porIniciar as $p):
                        $n = $inscritos[$p['id']] ?? 0;
                        [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($p, $n);
                        $dias = cengi_pp_dias_entre($hoy, $p['fecha_inicio']);
                        $faltan = max(0, (int) $p['sesiones_planificadas'] - (int) $p['ses_activas']);
                    ?>
                        <a class="cal-item" href="<?php echo cengi_pp_h(cengi_cal_url_plan($p)); ?>" style="--tono:<?php echo $coloresSemaforo[$color]; ?>">
                            <span class="cal-fecha"><b><?php echo (int) substr($p['fecha_inicio'], 8, 2); ?></b><?php echo CENGI_PP_MESES_CORTOS[(int) substr($p['fecha_inicio'], 5, 2)]; ?></span>
                            <span class="cal-item-body">
                                <span class="cal-item-top"><b><?php echo cengi_pp_h($p['nombre']); ?></b></span>
                                <span class="cal-item-meta"><span class="cal-cuando<?php echo $dias <= 7 ? ' is-pronto' : ''; ?>"><?php echo cengi_cal_cuando($dias, 'inicia'); ?></span> · <?php echo cengi_pp_h($p['modalidad']); ?></span>
                                <span class="cal-bar"><span style="width:<?php echo min(100, (int) round($n * 100 / max(1, $p['cupo_minimo']))); ?>%"></span></span>
                                <span class="cal-item-meta"><span class="cengi-status-badge <?php echo $clasesSemaforo[$color]; ?>"><i></i><?php echo cengi_pp_h($etiqueta); ?></span>
                                    <?php echo cengi_pp_convocado($p) ? $n . '/' . (int) $p['cupo_minimo'] . ' cupo' : ''; ?>
                                    <?php if ($faltan): ?><span class="cal-falta"><?php echo $faltan; ?> sesión(es) sin programar</span><?php endif; ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="pp-card cal-list" id="por-finalizar">
                <div class="pp-card-head"><div><h3>Por finalizar</h3><span class="pp-sub">Próximos 30 días · avance de sesiones</span></div><span class="cal-count is-naranja"><?php echo count($porFinalizar); ?></span></div>
                <div class="pp-card-body">
                    <?php if (!$porFinalizar): ?><p class="cal-vacio"><span class="glyphicon glyphicon-flag"></span>Ningún curso finaliza en los próximos 30 días.</p><?php endif; ?>
                    <?php foreach ($porFinalizar as $p):
                        $dias = cengi_pp_dias_entre($hoy, $p['fecha_fin']);
                        $total = max(1, (int) $p['sesiones_planificadas'], (int) $p['ses_activas']);
                        $impartidas = (int) $p['ses_impartidas'];
                    ?>
                        <a class="cal-item is-fin" href="<?php echo cengi_pp_h($p['curso_id'] ? 'ver_participante_curso.php?id=' . (int) $p['curso_id'] : 'programa_sesiones.php?ver=' . (int) $p['id']); ?>">
                            <span class="cal-fecha"><b><?php echo (int) substr($p['fecha_fin'], 8, 2); ?></b><?php echo CENGI_PP_MESES_CORTOS[(int) substr($p['fecha_fin'], 5, 2)]; ?></span>
                            <span class="cal-item-body">
                                <span class="cal-item-top"><b><?php echo cengi_pp_h($p['nombre']); ?></b></span>
                                <span class="cal-item-meta"><span class="cal-cuando<?php echo $dias <= 7 ? ' is-pronto' : ''; ?>"><?php echo cengi_cal_cuando($dias, 'finaliza'); ?></span><?php echo $p['codigo_curso'] ? ' · ' . cengi_pp_h($p['codigo_curso']) : ''; ?></span>
                                <span class="cal-bar"><span style="width:<?php echo min(100, (int) round($impartidas * 100 / $total)); ?>%"></span></span>
                                <span class="cal-item-meta"><?php echo $impartidas . ' de ' . $total; ?> sesiones impartidas<?php echo $p['curso_id'] ? '' : ' · <span class="cal-falta">sin curso abierto</span>'; ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </aside>
    </div>
</main>
</body>
</html>
