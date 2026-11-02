<?php
/**
 * Bitácora: registro de solo lectura de qué se cambió, quién y cuándo.
 *
 *  - Por curso: los cambios de planificación y del programa de sesiones de un curso
 *    (planificacion_cambios). Sin un curso elegido, la lista de cursos con cambios.
 *  - General del sistema: junta los historiales que ya guarda cada módulo (planificación,
 *    inscripciones, proformas, recibos de pago a instructores, eventos y asistencia).
 *
 * Avisar a los inscritos de un cambio se hace en Comunicaciones → "Por avisar"; aquí solo
 * se muestra el estado del aviso.
 */
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();

// Enlaces antiguos "Notificar" (bitacora_cambios.php?plan=X&notificar=1): el aviso ahora está en Comunicaciones.
if (!empty($_GET['notificar']) && (int) ($_GET['plan'] ?? 0)) {
    header('Location: comunicaciones.php?tab=avisar&avisar=' . (int) $_GET['plan']);
    exit;
}

// Icono y tono de cada tipo de cambio de planificación (los demás usan el lápiz gris).
const CENGI_BIT_TIPOS = [
    'Creación' => ['plus', 'is-verde'], 'Sesión agregada' => ['plus', 'is-verde'], 'Sesiones agregadas' => ['plus', 'is-verde'],
    'Aprobación' => ['ok', 'is-verde'], 'Curso abierto' => ['education', 'is-verde'], 'Sesión impartida' => ['ok-circle', 'is-verde'],
    'Sesión restaurada' => ['repeat', 'is-verde2'], 'Reactivación' => ['repeat', 'is-verde2'],
    'Reprogramación' => ['calendar', 'is-naranja'], 'Cambio de fecha de sesión' => ['calendar', 'is-naranja'], 'Cambio de horario' => ['time', 'is-naranja'],
    'Cambio de lugar' => ['map-marker', 'is-amarillo'], 'Cambio de modalidad' => ['facetime-video', 'is-amarillo'],
    'Cambio de instructor' => ['user', 'is-amarillo'], 'Cambio de instructor responsable' => ['user', 'is-amarillo'], 'Cambio de soporte técnico' => ['headphones', 'is-amarillo'],
    'Curso cancelado' => ['ban-circle', 'is-rojo'], 'Sesión cancelada' => ['ban-circle', 'is-rojo'], 'Sesión eliminada' => ['trash', 'is-rojo'], 'Sesiones eliminadas' => ['trash', 'is-rojo'],
];
const CENGI_BIT_MODULOS = [
    'planificacion' => ['Planificación y sesiones', 'calendar'],
    'inscripciones' => ['Inscripciones', 'inbox'],
    'proformas' => ['Proformas', 'file'],
    'pagos' => ['Pago a instructores', 'usd'],
    'eventos' => ['Eventos', 'flag'],
    'asistencia' => ['Asistencia del aula', 'check'],
];

// ---------------------------------------------------------------- filtros
$vista = ($_GET['vista'] ?? '') === 'general' ? 'general' : 'curso';
$planId = (int) ($_GET['plan'] ?? 0);
$planSel = $vista === 'curso' && $planId ? cengi_pp_plan($db, $planId) : null;
$anio = (string) ($_GET['anio'] ?? ($planSel ? $planSel['anio'] : cengi_pp_anio_actual()));
if ($anio !== 'todos' && !ctype_digit($anio)) {
    $anio = (string) cengi_pp_anio_actual();
}
$periodo = in_array($_GET['periodo'] ?? '', ['7', '30', '90'], true) ? $_GET['periodo'] : '';
$moduloFiltro = array_key_exists($_GET['modulo'] ?? '', CENGI_BIT_MODULOS) ? $_GET['modulo'] : '';
$usuarioFiltro = trim((string) ($_GET['usuario'] ?? ''));
$busqueda = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 100;
$desdePeriodo = $periodo !== '' ? date('Y-m-d H:i:s', strtotime('-' . (int) $periodo . ' days')) : '';

/** Texto legible de una acción guardada como clave ("autorizar_ingenio" → "Autorizar ingenio"). */
function cengi_bit_accion($texto)
{
    $texto = trim(str_replace('_', ' ', (string) $texto));
    return $texto === '' ? 'Cambio' : mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
}

/** Asistencia guardada como JSON → "Presente" / "Ausente" / "Sin registro". */
function cengi_bit_asistencia($json)
{
    $d = json_decode((string) $json, true);
    if (!is_array($d) || !array_key_exists('presente', $d)) {
        return 'Sin registro';
    }
    return (int) $d['presente'] === 1 ? 'Presente' : 'Ausente';
}

/** Ejecuta una consulta de historial; si la tabla de un módulo no existe, ese módulo se omite. */
function cengi_bit_consulta(PDO $db, $sql)
{
    try {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Bitácora general: ' . $e->getMessage());
        return [];
    }
}

/**
 * Bitácora general: una fila por registro de cada historial, con la misma forma:
 * fecha, usuario, modulo, registro (sobre qué), cambio, detalle, antes, despues, enlace.
 */
function cengi_bit_general(PDO $db)
{
    $filas = [];
    foreach (cengi_bit_consulta($db, "SELECT c.creado, c.usuario_nombre, c.tipo, c.detalle, c.antes, c.despues, c.motivo, c.planificacion_id, p.nombre
        FROM planificacion_cambios c INNER JOIN planificacion_cursos p ON p.id = c.planificacion_id") as $r) {
        $filas[] = ['fecha' => $r['creado'], 'usuario' => $r['usuario_nombre'] ?: 'Sistema', 'modulo' => 'planificacion', 'registro' => $r['nombre'],
            'cambio' => $r['tipo'], 'detalle' => trim($r['detalle'] . ($r['motivo'] !== '' ? ' · Motivo: ' . $r['motivo'] : '')), 'antes' => $r['antes'], 'despues' => $r['despues'],
            'enlace' => 'bitacora_cambios.php?plan=' . (int) $r['planificacion_id']];
    }
    foreach (cengi_bit_consulta($db, "SELECT h.creado, h.usuario, h.accion, h.motivo, s.id_solicitud, s.nombre_participante, COALESCE(p.nombre, cu.nombre_cursos) AS curso
        FROM inscripcion_historial h INNER JOIN solicitudes_inscripcion s ON s.id_solicitud = h.solicitud_id
        LEFT JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN cursos cu ON cu.id = s.curso_id") as $r) {
        $filas[] = ['fecha' => $r['creado'], 'usuario' => $r['usuario'] ?: 'Sistema', 'modulo' => 'inscripciones', 'registro' => $r['nombre_participante'] . ($r['curso'] ? ' · ' . $r['curso'] : ''),
            'cambio' => cengi_bit_accion($r['accion']), 'detalle' => (string) $r['motivo'], 'antes' => '', 'despues' => '',
            'enlace' => 'inscripcion_detalle.php?id=' . (int) $r['id_solicitud']];
    }
    foreach (cengi_bit_consulta($db, "SELECT h.creado, h.por, h.texto, h.detalle, pr.id, pr.numero, pr.dirigido
        FROM proforma_historial h INNER JOIN proformas pr ON pr.id = h.proforma_id") as $r) {
        $filas[] = ['fecha' => $r['creado'], 'usuario' => $r['por'] ?: 'Sistema', 'modulo' => 'proformas', 'registro' => 'Proforma N.° ' . $r['numero'] . ($r['dirigido'] ? ' · ' . $r['dirigido'] : ''),
            'cambio' => cengi_bit_accion($r['texto']), 'detalle' => (string) $r['detalle'], 'antes' => '', 'despues' => '',
            'enlace' => 'proforma.php?id=' . (int) $r['id']];
    }
    foreach (cengi_bit_consulta($db, "SELECT h.creado, h.usuario, h.accion, h.detalle, h.monto, d.persona_nombre, l.id AS liq_id, l.numero
        FROM liquidacion_recibo_historial h INNER JOIN liquidacion_detalle d ON d.id = h.detalle_id LEFT JOIN liquidaciones l ON l.id = d.liquidacion_id") as $r) {
        $filas[] = ['fecha' => $r['creado'], 'usuario' => $r['usuario'] ?: 'Sistema', 'modulo' => 'pagos', 'registro' => 'Recibo de ' . $r['persona_nombre'] . ($r['numero'] ? ' · ' . $r['numero'] : ''),
            'cambio' => 'Recibo ' . mb_strtolower(cengi_bit_accion($r['accion']), 'UTF-8'), 'detalle' => trim((string) $r['detalle'] . ($r['monto'] !== null ? ' · Q ' . number_format((float) $r['monto'], 2) : '')), 'antes' => '', 'despues' => '',
            'enlace' => $r['liq_id'] ? 'pago_instructores.php?tab=liquidaciones&ver=' . (int) $r['liq_id'] : ''];
    }
    foreach (cengi_bit_consulta($db, "SELECT h.creado, h.por, h.texto, h.detalle, e.id, e.nombre
        FROM evento_acuerdo_historial h INNER JOIN eventos e ON e.id = h.evento_id") as $r) {
        $filas[] = ['fecha' => $r['creado'], 'usuario' => $r['por'] ?: 'Sistema', 'modulo' => 'eventos', 'registro' => $r['nombre'],
            'cambio' => cengi_bit_accion($r['texto']), 'detalle' => (string) $r['detalle'], 'antes' => '', 'despues' => '',
            'enlace' => 'eventos_qr.php?tab=eventos'];
    }
    foreach (cengi_bit_consulta($db, "SELECT h.fecha, h.antes, h.despues, h.motivo, pa.nombre_participantes, cu.id AS curso_id, cu.nombre_cursos, s.fecha AS sesion_fecha
        FROM aula_asistencia_historial h INNER JOIN asignaciones a ON a.id = h.asignacion_id INNER JOIN participantes pa ON pa.id = a.participantes_id
        INNER JOIN cursos cu ON cu.id = a.cursos_id LEFT JOIN curso_sesiones s ON s.id = h.sesion_id") as $r) {
        $despues = json_decode((string) $r['despues'], true);
        $filas[] = ['fecha' => $r['fecha'], 'usuario' => is_array($despues) && !empty($despues['responsable']) ? $despues['responsable'] : 'Sistema', 'modulo' => 'asistencia',
            'registro' => $r['nombre_participantes'] . ' · ' . $r['nombre_cursos'], 'cambio' => 'Asistencia' . ($r['sesion_fecha'] ? ' del ' . cengi_pp_fecha($r['sesion_fecha']) : ''),
            'detalle' => (string) $r['motivo'], 'antes' => cengi_bit_asistencia($r['antes']), 'despues' => cengi_bit_asistencia($r['despues']),
            'enlace' => 'aula_gestion.php?curso=' . (int) $r['curso_id'] . '#asistencia'];
    }
    usort($filas, static function ($a, $b) {
        return strcmp((string) $b['fecha'], (string) $a['fecha']);
    });
    return $filas;
}

/** Estado del aviso a los inscritos de un cambio de planificación (solo informativo). */
function cengi_bit_aviso(array $c)
{
    switch ($c['notificacion']) {
        case 'pendiente':
            return '<a class="pp-chip is-warn" href="comunicaciones.php?tab=avisar&amp;avisar=' . (int) $c['planificacion_id'] . '" title="Avisar en Comunicaciones">Pendiente de avisar</a>';
        case 'enviada':
            return '<span class="pp-chip is-ok" title="' . (int) $c['notif_destinatarios'] . ' persona(s)">Avisado ' . ($c['notif_en'] ? cengi_pp_fecha(substr($c['notif_en'], 0, 10)) : '') . '</span>';
        case 'omitida':
            return '<span class="pp-chip">No avisado</span>';
        default:
            return '';
    }
}

/** Encabezado de día para agrupar la línea de tiempo: Hoy, Ayer o la fecha. */
function cengi_bit_dia($fecha, $hoy)
{
    $dia = substr($fecha, 0, 10);
    if ($dia === $hoy) {
        return 'Hoy · ' . cengi_pp_fecha_larga($dia);
    }
    if ($dia === cengi_pp_sumar_dias($hoy, -1)) {
        return 'Ayer · ' . cengi_pp_fecha_larga($dia);
    }
    return ucfirst(cengi_pp_fecha_larga($dia));
}

$coincide = static function ($texto) use ($busqueda) {
    return $busqueda === '' || strpos(cengi_texto_normalizado($texto), cengi_texto_normalizado($busqueda)) !== false;
};

// ---------------------------------------------------------------- datos
$cursosConCambios = [];
$filas = [];
$usuarios = [];
if ($vista === 'curso') {
    $where = [];
    $params = [];
    if ($planSel) {
        $where[] = 'c.planificacion_id = ?';
        $params[] = $planSel['id'];
    } elseif ($anio !== 'todos') {
        $where[] = 'p.anio = ?';
        $params[] = (int) $anio;
    }
    $stmt = $db->prepare("SELECT c.*, p.nombre AS plan_nombre, p.anio, cu.codigo_curso, co.enviado_en AS notif_en, co.enviados AS notif_destinatarios
        FROM planificacion_cambios c
        INNER JOIN planificacion_cursos p ON p.id = c.planificacion_id
        LEFT JOIN cursos cu ON cu.id = p.curso_id
        LEFT JOIN comunicaciones co ON co.id = c.comunicacion_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.creado DESC, c.id DESC');
    $stmt->execute($params);
    $base = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $usuarios = array_values(array_filter(array_unique(array_column($base, 'usuario_nombre'))));
    $filas = array_values(array_filter($base, static function ($c) use ($desdePeriodo, $usuarioFiltro, $coincide) {
        return ($desdePeriodo === '' || $c['creado'] >= $desdePeriodo) && ($usuarioFiltro === '' || $c['usuario_nombre'] === $usuarioFiltro)
            && $coincide($c['tipo'] . ' ' . $c['detalle'] . ' ' . $c['antes'] . ' ' . $c['despues'] . ' ' . $c['motivo'] . ' ' . $c['usuario_nombre'] . ' ' . $c['plan_nombre']);
    }));
    if (!$planSel) {
        // Sin curso elegido: un renglón por curso con su último cambio.
        foreach ($filas as $c) {
            $k = (int) $c['planificacion_id'];
            if (!isset($cursosConCambios[$k])) {
                $cursosConCambios[$k] = ['id' => $k, 'nombre' => $c['plan_nombre'], 'codigo' => $c['codigo_curso'], 'cambios' => 0, 'pendientes' => 0, 'ultimo' => $c];
            }
            $cursosConCambios[$k]['cambios']++;
            $cursosConCambios[$k]['pendientes'] += $c['notificacion'] === 'pendiente' ? 1 : 0;
        }
    }
} else {
    $base = cengi_bit_general($db);
    $usuarios = array_values(array_filter(array_unique(array_column($base, 'usuario'))));
    $filas = array_values(array_filter($base, static function ($f) use ($desdePeriodo, $usuarioFiltro, $moduloFiltro, $coincide) {
        return ($desdePeriodo === '' || $f['fecha'] >= $desdePeriodo) && ($usuarioFiltro === '' || $f['usuario'] === $usuarioFiltro)
            && ($moduloFiltro === '' || $f['modulo'] === $moduloFiltro)
            && $coincide($f['registro'] . ' ' . $f['cambio'] . ' ' . $f['detalle'] . ' ' . $f['antes'] . ' ' . $f['despues'] . ' ' . $f['usuario']);
    }));
}
sort($usuarios);

// ---------------------------------------------------------------- Excel de lo que se está viendo
if (($_GET['exportar'] ?? '') === 'excel') {
    require_once __DIR__ . '/cobranza_helpers.php';
    if ($vista === 'curso') {
        cengi_cob_excel('Bitácora de cambios', ($planSel ? $planSel['nombre'] : ($anio === 'todos' ? 'Todos los años' : 'Año ' . $anio)) . ' · ' . count($filas) . ' cambio(s)', [
            ['Fecha', 11, 'fecha'], ['Hora', 7, 'texto'], ['Curso / Diplomado', 32, 'texto'], ['Usuario', 18, 'texto'], ['Cambio', 24, 'texto'], ['Detalle', 36, 'texto'],
            ['Antes', 24, 'texto'], ['Después', 24, 'texto'], ['Motivo', 28, 'texto'],
        ], array_map(static function ($c) {
            return [$c['creado'], date('H:i', strtotime($c['creado'])), $c['plan_nombre'], $c['usuario_nombre'] ?: 'Sistema', $c['tipo'], $c['detalle'], $c['antes'], $c['despues'], $c['motivo']];
        }, $filas), 'Bitácora de cambios' . ($planSel ? ' - ' . $planSel['nombre'] : ''));
    }
    cengi_cob_excel('Bitácora general del sistema', ($moduloFiltro !== '' ? CENGI_BIT_MODULOS[$moduloFiltro][0] . ' · ' : '') . count($filas) . ' registro(s)', [
        ['Fecha', 11, 'fecha'], ['Hora', 7, 'texto'], ['Usuario', 18, 'texto'], ['Módulo', 20, 'texto'], ['Registro', 34, 'texto'], ['Cambio', 24, 'texto'],
        ['Detalle', 36, 'texto'], ['Antes', 18, 'texto'], ['Después', 18, 'texto'],
    ], array_map(static function ($f) {
        return [$f['fecha'], date('H:i', strtotime($f['fecha'])), $f['usuario'], CENGI_BIT_MODULOS[$f['modulo']][0], $f['registro'], $f['cambio'], $f['detalle'], $f['antes'], $f['despues']];
    }, $filas), 'Bitácora general del sistema');
}

$totalFilas = $vista === 'curso' && !$planSel ? count($cursosConCambios) : count($filas);
$paginas = max(1, (int) ceil($totalFilas / $porPagina));
$pagina = min($pagina, $paginas);
$filasPagina = $vista === 'curso' && !$planSel ? [] : array_slice($filas, ($pagina - 1) * $porPagina, $porPagina);

$anios = cengi_pp_anios_disponibles($db, $anio === 'todos' ? cengi_pp_anio_actual() : (int) $anio);
$gruposPlanes = cengi_pp_planes_opciones($db, true);
$filtros = array_filter(['vista' => $vista === 'general' ? 'general' : '', 'anio' => $vista === 'curso' ? $anio : '', 'plan' => $planSel['id'] ?? '',
    'periodo' => $periodo, 'modulo' => $moduloFiltro, 'usuario' => $usuarioFiltro, 'q' => $busqueda], static function ($x) {
    return $x !== '' && $x !== null;
});
$urlBit = static function (array $cambios) use ($filtros) {
    $q = array_filter(array_merge($filtros, $cambios), static function ($x) {
        return $x !== '' && $x !== null;
    });
    return 'bitacora_cambios.php' . ($q ? '?' . http_build_query($q) : '');
};
$hayFiltros = $periodo !== '' || $moduloFiltro !== '' || $usuarioFiltro !== '' || $busqueda !== '';
$colspan = $vista === 'general' ? 5 : 4;
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Bitácora de cambios'); ?>
    <link rel="stylesheet" href="css/bitacora.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/bitacora.css') ?: 1); ?>">
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page bit-page">
    <?php cengi_banner(); ?>
    <?php cengi_pp_subnav('bitacora_cambios.php'); ?>

    <section class="pp-card bit-lista">
        <nav class="pp-tabs">
            <a href="bitacora_cambios.php" class="<?php echo $vista === 'curso' ? 'is-active' : ''; ?>"><span class="glyphicon glyphicon-education"></span> Por curso</a>
            <a href="bitacora_cambios.php?vista=general" class="<?php echo $vista === 'general' ? 'is-active' : ''; ?>"><span class="glyphicon glyphicon-list-alt"></span> General del sistema</a>
        </nav>

        <form class="ses-filtros-form" method="get">
            <?php if ($vista === 'general'): ?>
                <input type="hidden" name="vista" value="general">
                <label class="ses-campo"><span>Módulo</span>
                    <select name="modulo" onchange="this.form.submit()">
                        <option value="">Todos los módulos</option>
                        <?php foreach (CENGI_BIT_MODULOS as $clave => [$texto]): ?><option value="<?php echo $clave; ?>" <?php echo $moduloFiltro === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                    </select></label>
            <?php else: ?>
                <label class="ses-campo"><span>Año</span>
                    <select name="anio" onchange="this.form.plan.value='';this.form.submit()">
                        <option value="todos" <?php echo $anio === 'todos' ? 'selected' : ''; ?>>Todos los años</option>
                        <?php foreach ($anios as $z): ?><option value="<?php echo $z; ?>" <?php echo (string) $z === $anio ? 'selected' : ''; ?>><?php echo cengi_pp_anio_texto($z); ?></option><?php endforeach; ?>
                    </select></label>
                <label class="ses-campo is-curso"><span>Curso</span>
                    <select name="plan" onchange="this.form.submit()">
                        <option value="">Todos los cursos</option>
                        <?php echo cengi_pp_options_planes($anio === 'todos' ? $gruposPlanes : array_intersect_key($gruposPlanes, [(int) $anio => 1]), (string) ($planSel['id'] ?? '')); ?>
                    </select></label>
            <?php endif; ?>
            <label class="ses-campo"><span>Período</span>
                <select name="periodo" onchange="this.form.submit()">
                    <option value="">Todo</option>
                    <?php foreach (['7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días'] as $v => $t): ?><option value="<?php echo $v; ?>" <?php echo $periodo === $v ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?>
                </select></label>
            <?php if (count($usuarios) > 1): ?>
                <label class="ses-campo"><span>Usuario</span>
                    <select name="usuario" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($usuarios as $u): ?><option <?php echo $usuarioFiltro === $u ? 'selected' : ''; ?>><?php echo cengi_pp_h($u); ?></option><?php endforeach; ?>
                    </select></label>
            <?php endif; ?>
            <label class="ses-campo is-buscar"><span>Buscar</span>
                <span class="ses-buscar"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="<?php echo $vista === 'general' ? 'Registro, cambio, usuario...' : 'Cambio, valores, motivo, usuario...'; ?>"></span></label>
            <div class="ses-filtros-fin">
                <span class="ses-total"><b><?php echo $vista === 'curso' && !$planSel ? count($cursosConCambios) . '</b> curso(s) · <b>' . count($filas) : count($filas); ?></b> <?php echo $vista === 'general' ? 'registro(s)' : 'cambio(s)'; ?></span>
                <?php if ($hayFiltros): ?><a class="ses-limpiar" href="<?php echo cengi_pp_h($urlBit(['periodo' => '', 'modulo' => '', 'usuario' => '', 'q' => '', 'p' => ''])); ?>"><span class="glyphicon glyphicon-remove"></span>Quitar filtros</a><?php endif; ?>
                <a class="btn btn-default btn-sm" href="<?php echo cengi_pp_h($urlBit(['exportar' => 'excel', 'p' => ''])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Excel</a>
                <button type="submit" class="sr-only">Filtrar</button>
            </div>
        </form>

        <?php if ($planSel): ?>
            <div class="bit-curso-cab">
                <a class="ses-volver" href="<?php echo cengi_pp_h($urlBit(['plan' => '', 'anio' => (int) $planSel['anio'], 'p' => ''])); ?>"><span class="glyphicon glyphicon-arrow-left" aria-hidden="true"></span> Todos los cursos de <?php echo (int) $planSel['anio']; ?></a>
                <h2><?php echo cengi_pp_h($planSel['nombre']); ?></h2>
                <span class="pp-sub"><?php echo $planSel['codigo_curso'] ? '<span class="pp-mono">' . cengi_pp_h($planSel['codigo_curso']) . '</span> · ' : ''; ?><?php echo count($filas); ?> cambio(s) registrados</span>
            </div>
        <?php endif; ?>

        <div class="pp-table-wrap">
            <?php if ($vista === 'curso' && !$planSel): ?>
                <table class="cengi-org-table bit-tabla">
                    <thead><tr><th>Curso / Diplomado</th><th>Cambios</th><th>Último cambio</th><th>Avisos</th></tr></thead>
                    <tbody>
                        <?php if (!$cursosConCambios): ?><tr><td colspan="4"><div class="bit-vacio"><span class="glyphicon glyphicon-time"></span><b><?php echo $hayFiltros ? 'Ningún cambio coincide con los filtros' : 'Aún no hay cambios registrados'; ?></b></div></td></tr><?php endif; ?>
                        <?php foreach ($cursosConCambios as $cc): $u = $cc['ultimo']; ?>
                            <tr>
                                <td><strong><a href="<?php echo cengi_pp_h($urlBit(['plan' => $cc['id'], 'p' => ''])); ?>"><?php echo cengi_pp_h($cc['nombre']); ?></a></strong>
                                    <?php if ($cc['codigo']): ?><span class="pp-sub pp-mono"><?php echo cengi_pp_h($cc['codigo']); ?></span><?php endif; ?></td>
                                <td class="pp-num"><b><?php echo $cc['cambios']; ?></b></td>
                                <td><?php echo cengi_pp_h($u['tipo']); ?><span class="pp-sub"><?php echo date('d/m/Y H:i', strtotime($u['creado'])); ?> · <?php echo cengi_pp_h($u['usuario_nombre'] ?: 'Sistema'); ?></span></td>
                                <td><?php echo $cc['pendientes'] ? '<a class="pp-chip is-warn" href="comunicaciones.php?tab=avisar&amp;avisar=' . $cc['id'] . '">' . $cc['pendientes'] . ' por avisar</a>' : '<span class="pp-sub">—</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="cengi-org-table bit-tabla">
                    <thead><tr><th>Hora</th><th>Usuario</th><?php if ($vista === 'general'): ?><th>Módulo · registro</th><?php endif; ?><th>Qué se cambió</th><th>Antes → después</th></tr></thead>
                    <tbody>
                        <?php if (!$filasPagina): ?><tr><td colspan="<?php echo $colspan; ?>"><div class="bit-vacio"><span class="glyphicon glyphicon-time"></span><b><?php echo $hayFiltros ? 'Ningún registro coincide con los filtros' : 'Aún no hay cambios registrados'; ?></b></div></td></tr><?php endif; ?>
                        <?php $diaActual = null; foreach ($filasPagina as $f):
                            $fecha = $vista === 'general' ? $f['fecha'] : $f['creado'];
                            if (substr($fecha, 0, 10) !== $diaActual): $diaActual = substr($fecha, 0, 10); ?>
                                <tr class="bit-dia"><th colspan="<?php echo $colspan; ?>"><span class="glyphicon glyphicon-calendar"></span><?php echo cengi_pp_h(cengi_bit_dia($fecha, $hoy)); ?></th></tr>
                            <?php endif; ?>
                            <?php if ($vista === 'general'): [$modTexto, $modIcono] = CENGI_BIT_MODULOS[$f['modulo']]; ?>
                                <tr>
                                    <td class="pp-num bit-hora"><?php echo date('H:i', strtotime($fecha)); ?></td>
                                    <td><span class="glyphicon glyphicon-user bit-u"></span> <?php echo cengi_pp_h($f['usuario']); ?></td>
                                    <td style="min-width:200px;"><span class="bit-modulo"><span class="glyphicon glyphicon-<?php echo $modIcono; ?>"></span><?php echo $modTexto; ?></span>
                                        <span class="pp-sub"><?php echo $f['enlace'] ? '<a href="' . cengi_pp_h($f['enlace']) . '">' . cengi_pp_h($f['registro']) . '</a>' : cengi_pp_h($f['registro']); ?></span></td>
                                    <td style="min-width:220px;"><b class="bit-tipo"><?php echo cengi_pp_h($f['cambio']); ?></b><?php if ($f['detalle'] !== ''): ?><span class="pp-sub"><?php echo cengi_pp_h($f['detalle']); ?></span><?php endif; ?></td>
                                    <td><?php echo $f['antes'] !== '' || $f['despues'] !== '' ? '<div class="bit-diff"><s>' . cengi_pp_h($f['antes'] ?: '—') . '</s><span class="glyphicon glyphicon-arrow-right"></span><b>' . cengi_pp_h($f['despues'] ?: '—') . '</b></div>' : '<span class="pp-sub">—</span>'; ?></td>
                                </tr>
                            <?php else: [$icono, $tono] = CENGI_BIT_TIPOS[$f['tipo']] ?? ['pencil', 'is-gris']; ?>
                                <tr>
                                    <td class="pp-num bit-hora"><?php echo date('H:i', strtotime($fecha)); ?></td>
                                    <td><span class="glyphicon glyphicon-user bit-u"></span> <?php echo cengi_pp_h($f['usuario_nombre'] ?: 'Sistema'); ?></td>
                                    <td style="min-width:260px;"><div class="bit-cambio">
                                        <span class="bit-tipo-icono <?php echo $tono; ?>"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span></span>
                                        <div><b class="bit-tipo"><?php echo cengi_pp_h($f['tipo']); ?></b> <?php echo cengi_bit_aviso($f); ?>
                                            <?php if ($f['detalle'] !== ''): ?><span class="pp-sub"><?php echo cengi_pp_h($f['detalle']); ?></span><?php endif; ?>
                                            <?php if ($f['motivo'] !== ''): ?><span class="pp-sub">Motivo: <?php echo cengi_pp_h($f['motivo']); ?></span><?php endif; ?>
                                        </div></div></td>
                                    <td><?php echo $f['antes'] !== '' || $f['despues'] !== '' ? '<div class="bit-diff"><s>' . cengi_pp_h($f['antes'] ?: '—') . '</s><span class="glyphicon glyphicon-arrow-right"></span><b>' . cengi_pp_h($f['despues'] ?: '—') . '</b></div>' : '<span class="pp-sub">—</span>'; ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($paginas > 1): ?>
                    <nav class="bit-paginas" aria-label="Páginas">
                        <a class="<?php echo $pagina <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo cengi_pp_h($urlBit(['p' => max(1, $pagina - 1)])); ?>"><span class="glyphicon glyphicon-chevron-left"></span> Más recientes</a>
                        <span>Página <b><?php echo $pagina; ?></b> de <?php echo $paginas; ?></span>
                        <a class="<?php echo $pagina >= $paginas ? 'is-disabled' : ''; ?>" href="<?php echo cengi_pp_h($urlBit(['p' => min($paginas, $pagina + 1)])); ?>">Anteriores <span class="glyphicon glyphicon-chevron-right"></span></a>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
