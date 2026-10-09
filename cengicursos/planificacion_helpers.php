<?php
/**
 * Logica compartida de "Planificacion y programacion": planificacion_cursos.php,
 * calendario_cursos.php, programa_sesiones.php, pago_instructores.php,
 * bitacora_cambios.php, comunicaciones.php y cron_comunicaciones.php.
 *
 * Esquema: cengicursos/migrations/20261007_planificacion_cursos.sql.
 */

require_once __DIR__ . '/conexion.php';
// No incluye revisar_permisos.php (redirige al login): estas funciones tambien se usan
// desde el formulario publico de inscripcion y el cron. Las paginas con login lo cargan
// via menu.php.
require_once __DIR__ . '/cengi_texto.php';
require_once __DIR__ . '/curso_form_helpers.php';

// PHP del contenedor corre en UTC; "hoy" (sesiones impartidas, recordatorios, atrasos)
// debe ser la fecha de Guatemala.
date_default_timezone_set('America/Guatemala');

const CENGI_PP_IMPUESTO = 0.05;
const CENGI_PP_MODALIDADES = ['Presencial', 'Virtual', 'Híbrida'];
const CENGI_PP_MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const CENGI_PP_MESES_CORTOS = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const CENGI_PP_DIAS_CORTOS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const CENGI_PP_ESTADOS_PLAN = [
    'planificado' => ['Planificado', 'is-upcoming'],
    'aprobado' => ['Aprobado', 'is-active'],
    'reprogramado' => ['Reprogramado', 'is-pending'],
    'cancelado' => ['Cancelado', 'is-rejected'],
];
const CENGI_PP_ESTADOS_SESION = [
    'programada' => ['Programada', 'is-upcoming'],
    'impartida' => ['Impartida', 'is-active'],
    'cancelada' => ['Cancelada', 'is-neutral'],
];
const CENGI_PP_ESTADOS_LIQ = [
    'enviada' => ['Enviada a Administración', 'is-upcoming'],
    'aprobada' => ['Aprobada', 'is-pending'],
    'pagada' => ['Pagada', 'is-active'],
    'anulada' => ['Anulada', 'is-neutral'],
];
// Cambios que afectan a los participantes: si el curso ya tiene inscritos quedan
// "pendientes de notificar" en la bitacora.
const CENGI_PP_TIPOS_AFECTAN = [
    'Reprogramación', 'Cambio de horario', 'Cambio de modalidad', 'Cambio de instructor responsable',
    'Curso cancelado', 'Cambio de fecha de sesión', 'Cambio de lugar', 'Cambio de instructor',
    'Sesión cancelada', 'Sesión restaurada', 'Sesión agregada', 'Sesiones agregadas', 'Sesión eliminada',
];

// ---------------------------------------------------------------- formato

function cengi_pp_h($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_pp_hoy()
{
    return date('Y-m-d');
}

function cengi_pp_es_fecha($valor)
{
    if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function cengi_pp_es_hora($valor)
{
    return is_string($valor) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $valor) === 1;
}

function cengi_pp_fecha($iso)
{
    return $iso ? date('d/m/Y', strtotime((string) $iso)) : '—';
}

function cengi_pp_fecha_corta($iso)
{
    if (!$iso) {
        return '—';
    }
    $t = strtotime((string) $iso);
    return date('d', $t) . ' ' . CENGI_PP_MESES_CORTOS[(int) date('n', $t)];
}

function cengi_pp_fecha_larga($iso)
{
    $t = strtotime((string) $iso);
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    return $dias[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' de ' . mb_strtolower(CENGI_PP_MESES[(int) date('n', $t)], 'UTF-8') . ' de ' . date('Y', $t);
}

function cengi_pp_hora($hora)
{
    return $hora ? ltrim(substr((string) $hora, 0, 5), '0') : '';
}

function cengi_pp_horario($inicio, $fin)
{
    return cengi_pp_hora($inicio) . '–' . cengi_pp_hora($fin);
}

function cengi_pp_q($monto, $decimales = 0)
{
    return 'Q' . number_format((float) $monto, $decimales);
}

function cengi_pp_anio_texto($anio)
{
    $anio = (int) $anio;
    return (string) $anio;
}

function cengi_pp_dias_entre($desde, $hasta)
{
    return (int) round((strtotime($hasta . ' 12:00') - strtotime($desde . ' 12:00')) / 86400);
}

function cengi_pp_sumar_dias($iso, $dias)
{
    return date('Y-m-d', strtotime($iso . ' 12:00 ' . ($dias >= 0 ? '+' : '') . (int) $dias . ' days'));
}

function cengi_pp_badge(array $estados, $estado)
{
    [$texto, $clase] = $estados[$estado] ?? [ucfirst((string) $estado), 'is-neutral'];
    return '<span class="cengi-status-badge ' . $clase . '"><i></i>' . cengi_pp_h($texto) . '</span>';
}

function cengi_pp_usuario_nombre()
{
    return trim((string) ($_SESSION['usuario'] ?? '')) ?: 'Sistema';
}

/** Año activo de la planificación: año calendario (enero a diciembre). */
function cengi_pp_anio_actual()
{
    return (int) date('Y');
}

function cengi_pp_anios_disponibles(PDO $db, $seleccionada)
{
    $anios = array_map('intval', $db->query('SELECT DISTINCT anio FROM planificacion_cursos')->fetchAll(PDO::FETCH_COLUMN));
    $actual = cengi_pp_anio_actual();
    $anios = array_unique(array_merge($anios, [$actual - 1, $actual, $actual + 1, (int) $seleccionada]));
    rsort($anios);
    return $anios;
}

/** Redireccion Post/Redirect/Get conservando el query string actual. */
function cengi_pp_redirigir($pagina, array $extra = [])
{
    $query = array_merge($_GET, $extra);
    header('Location: ' . $pagina . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

function cengi_pp_csv($nombreArchivo, array $filas)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '.csv"');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    foreach ($filas as $fila) {
        fputcsv($salida, $fila);
    }
    fclose($salida);
    exit;
}

/** <head> comun de las pantallas de planificacion. */
function cengi_pp_head($titulo)
{
    $v = static function ($archivo) {
        return (int) (@filemtime(__DIR__ . '/css/' . $archivo) ?: 1);
    };
    ?>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo cengi_pp_h($titulo); ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo $v('proyecto.css'); ?>">
    <link rel="stylesheet" href="css/planificacion.css?v=<?php echo $v('planificacion.css'); ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <?php
}

function cengi_pp_feedback($mensaje, $tipo = 'success')
{
    if ($mensaje === '') {
        return;
    }
    $icono = ['error' => 'remove', 'warning' => 'warning-sign'][$tipo] ?? 'ok';
    $clase = ['error' => ' is-error', 'warning' => ' is-warning'][$tipo] ?? '';
    echo '<div class="cengi-feedback' . $clase . '"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-' . $icono . '"></span></div><div><p>' . $mensaje . '</p></div></div>';
}

function cengi_pp_kpi($valor, $etiqueta, $detalle, $color, $icono, $fondo = null)
{
    $fondo = $fondo ?: '#EAF6DD';
    return '<div class="cengi-kpi"><div class="cengi-kpi-bar" style="background:' . $color . ';"></div>'
        . '<div class="cengi-kpi-icon" style="background:' . $fondo . ';color:' . $color . ';"><span class="glyphicon glyphicon-' . $icono . '"></span></div>'
        . '<div class="cengi-kpi-val">' . $valor . '</div><div class="cengi-kpi-label">' . cengi_pp_h($etiqueta) . '</div>'
        . ($detalle !== '' ? '<div class="pp-kpi-detalle">' . $detalle . '</div>' : '') . '</div>';
}

// ---------------------------------------------------------------- planificaciones

function cengi_pp_convocado(array $plan)
{
    return in_array($plan['estado'] ?? '', ['aprobado', 'reprogramado'], true);
}

function cengi_pp_plan(PDO $db, $id)
{
    $stmt = $db->prepare("
        SELECT p.*, cc.descripcion_categorias_cursos AS categoria_nombre, i.nombre AS instructor_nombre,
               c.codigo_curso
        FROM planificacion_cursos p
        LEFT JOIN categorias_cursos cc ON cc.id = p.categoria_curso_id
        LEFT JOIN instructores i ON i.id = p.instructor_id
        LEFT JOIN cursos c ON c.id = p.curso_id
        WHERE p.id = ?
    ");
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Planes de un año (o todos) para selects agrupados. */
function cengi_pp_planes_opciones(PDO $db, $incluirCancelados = false)
{
    $sql = 'SELECT p.id, p.anio, p.nombre, p.estado, p.fecha_inicio, p.fecha_fin, c.codigo_curso
            FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id'
        . ($incluirCancelados ? '' : " WHERE p.estado <> 'cancelado'")
        . ' ORDER BY p.anio DESC, p.fecha_inicio, p.nombre';
    $grupos = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $plan) {
        $grupos[(int) $plan['anio']][] = $plan;
    }
    return $grupos;
}

function cengi_pp_options_planes(array $grupos, $seleccionado, $prefijoTodos = null)
{
    $html = '';
    foreach ($grupos as $anio => $planes) {
        if ($prefijoTodos !== null) {
            $valor = 'z:' . $anio;
            $html .= '<option value="' . $valor . '"' . ($seleccionado === $valor ? ' selected' : '') . '>' . cengi_pp_h($prefijoTodos . ' — año ' . cengi_pp_anio_texto($anio)) . '</option>';
        }
        $html .= '<optgroup label="Año ' . cengi_pp_anio_texto($anio) . '">';
        foreach ($planes as $plan) {
            $html .= '<option value="' . (int) $plan['id'] . '"' . ((string) $seleccionado === (string) $plan['id'] ? ' selected' : '') . '>'
                . cengi_pp_h($plan['nombre'] . ($plan['estado'] === 'cancelado' ? ' (cancelado)' : '')) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

/** Modulos disponibles para las sesiones: los del curso creado, los del plan o "Sesión única". */
function cengi_pp_modulos_plan(PDO $db, array $plan)
{
    if (!empty($plan['curso_id'])) {
        $stmt = $db->prepare('SELECT nombre FROM curso_modulos WHERE curso_id = ? ORDER BY orden, id');
        $stmt->execute([(int) $plan['curso_id']]);
        $modulos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($modulos) {
            return $modulos;
        }
    }
    $modulos = array_values(array_filter(array_map('trim', preg_split('/\R|,/', (string) ($plan['modulos'] ?? '')))));
    return $modulos ?: ['Sesión única'];
}

/**
 * Personas a quienes afecta el plan: asignadas al curso y, salvo $soloAsignados, las
 * inscritas en la convocatoria que aun no se asignan (por validar o en lista de espera).
 * Una fila por correo: ['clave' => correo en minusculas, 'nombre', 'correo', 'asignado' => bool].
 */
function cengi_pp_inscritos(PDO $db, array $plan, $soloAsignados = false)
{
    $personas = [];
    if (!empty($plan['curso_id'])) {
        $stmt = $db->prepare("
            SELECT p.nombre_participantes AS nombre, p.correo_participantes AS correo
            FROM asignaciones a
            INNER JOIN participantes p ON p.id = a.participantes_id
            WHERE a.cursos_id = ? AND a.estado_asignaciones = 1
        ");
        $stmt->execute([(int) $plan['curso_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $personas[mb_strtolower(trim($p['correo']), 'UTF-8')] = $p + ['asignado' => true];
        }
    }
    if (!$soloAsignados) {
        $stmt = $db->prepare("SELECT nombre_participante AS nombre, correo FROM solicitudes_inscripcion
            WHERE planificacion_id = ? AND estado IN ('Pendiente', 'Espera')");
        $stmt->execute([(int) $plan['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $personas[mb_strtolower(trim($p['correo']), 'UTF-8')] ??= $p + ['asignado' => false];
        }
    }
    unset($personas['']);
    foreach ($personas as $clave => &$p) {
        $p['clave'] = $clave;
    }
    unset($p);
    uasort($personas, static function ($a, $b) {
        return strcmp($a['nombre'], $b['nombre']);
    });
    return array_values($personas);
}

/**
 * [planificacion_id => personas que cuentan para el cupo]: asignadas al curso mas las
 * inscripciones validadas (o sin costo) que esperan asignacion.
 */
function cengi_pp_conteo_inscritos(PDO $db)
{
    $conteo = array_map('intval', $db->query("
        SELECT p.id, COUNT(a.id) AS total
        FROM planificacion_cursos p
        INNER JOIN asignaciones a ON a.cursos_id = p.curso_id AND a.estado_asignaciones = 1
        GROUP BY p.id
    ")->fetchAll(PDO::FETCH_KEY_PAIR));
    $validadas = $db->query("
        SELECT planificacion_id, COUNT(*) FROM solicitudes_inscripcion
        WHERE planificacion_id IS NOT NULL AND estado = 'Pendiente'
          AND (tipo_pago = 'Exonerado' OR validacion_ingenio = 'validada' OR pago_estado IN ('validado', 'pagado'))
        GROUP BY planificacion_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($validadas as $planId => $n) {
        $conteo[$planId] = ($conteo[$planId] ?? 0) + (int) $n;
    }
    return $conteo;
}

/**
 * Registra un cambio en la bitacora. Los que afectan a participantes de un curso
 * convocado quedan pendientes de notificar (o "sin inscritos" si nadie se inscribio).
 */
function cengi_pp_log(PDO $db, array $plan, $tipo, $detalle, array $opciones = [])
{
    $afecta = array_key_exists('afecta', $opciones)
        ? (bool) $opciones['afecta']
        : (in_array($tipo, CENGI_PP_TIPOS_AFECTAN, true) && (cengi_pp_convocado($plan) || $tipo === 'Curso cancelado'));
    $notificacion = 'no aplica';
    if ($afecta) {
        $notificacion = count(cengi_pp_inscritos($db, $plan)) > 0 ? 'pendiente' : 'sin inscritos';
    }
    $corta = static function ($texto) {
        return mb_substr((string) $texto, 0, 500, 'UTF-8');
    };
    $stmt = $db->prepare('INSERT INTO planificacion_cambios
        (planificacion_id, sesion_id, tipo, detalle, antes, despues, motivo, usuario_id, usuario_nombre, afecta, notificacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        (int) $plan['id'], $opciones['sesion_id'] ?? null, $tipo, $corta($detalle),
        $corta($opciones['antes'] ?? ''), $corta($opciones['despues'] ?? ''), $corta($opciones['motivo'] ?? ''),
        cengi_usuario_actual_id() ?: null, cengi_pp_usuario_nombre(), $afecta ? 1 : 0, $notificacion,
    ]);
}

/** Programacion vigente de un plan en texto (avisos, confirmaciones de inscripcion e historial). */
function cengi_pp_programacion_vigente(PDO $db, array $plan)
{
    $stmt = $db->prepare("SELECT s.*, i.nombre AS instructor FROM curso_sesiones s LEFT JOIN instructores i ON i.id = s.instructor_id
        WHERE s.planificacion_id = ? AND s.estado = 'programada' AND s.fecha >= CURDATE() ORDER BY s.fecha, s.hora_inicio LIMIT 1");
    $stmt->execute([(int) $plan['id']]);
    $proxima = $stmt->fetch(PDO::FETCH_ASSOC);
    $texto = 'Fechas: ' . cengi_pp_fecha($plan['fecha_inicio']) . ($plan['fecha_fin'] !== $plan['fecha_inicio'] ? ' – ' . cengi_pp_fecha($plan['fecha_fin']) : '')
        . "\nHorario: " . cengi_pp_horario($plan['hora_inicio'], $plan['hora_fin']) . ' · ' . $plan['modalidad']
        . ($plan['dias'] !== '' ? ' · ' . $plan['dias'] : '');
    if ($proxima) {
        $texto .= "\nPróxima sesión: " . cengi_pp_fecha($proxima['fecha']) . ', ' . cengi_pp_horario($proxima['hora_inicio'], $proxima['hora_fin'])
            . ' — ' . $proxima['lugar'] . ($proxima['instructor'] ? ' (' . $proxima['instructor'] . ')' : '');
    }
    return $texto;
}

/** [planificacion_id => cambios pendientes de notificar] */
function cengi_pp_conteo_pendientes(PDO $db)
{
    return array_map('intval', $db->query("SELECT planificacion_id, COUNT(*) FROM planificacion_cambios WHERE notificacion = 'pendiente' GROUP BY planificacion_id")->fetchAll(PDO::FETCH_KEY_PAIR));
}

// ---------------------------------------------------------------- aprobar y sincronizar con cursos

function cengi_pp_prefijo_categoria($categoria)
{
    $c = cengi_texto_normalizado((string) $categoria);
    foreach (['diplomado' => 'D', 'taller' => 'TA', 'seminario' => 'S', 'webinar' => 'W', 'evento' => 'EV'] as $clave => $prefijo) {
        if (strpos($c, $clave) !== false) {
            return $prefijo;
        }
    }
    return 'T';
}

function cengi_pp_siguiente_codigo(PDO $db, $categoria)
{
    $prefijo = 'CEN-' . cengi_pp_prefijo_categoria($categoria) . '-';
    $stmt = $db->prepare('SELECT codigo_curso FROM cursos WHERE codigo_curso LIKE ?');
    $stmt->execute([$prefijo . '%']);
    $maximo = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $codigo) {
        $sufijo = substr($codigo, strlen($prefijo));
        if (ctype_digit($sufijo)) {
            $maximo = max($maximo, (int) $sufijo);
        }
    }
    return $prefijo . str_pad((string) ($maximo + 1), 3, '0', STR_PAD_LEFT);
}

function cengi_pp_jornada(array $plan)
{
    $dias = cengi_texto_normalizado((string) $plan['dias']);
    if (strpos($dias, 'sabado') !== false || strpos($dias, 'domingo') !== false) {
        return 'Fin de semana';
    }
    return (int) substr((string) $plan['hora_inicio'], 0, 2) < 12 ? 'Matutina' : 'Vespertina';
}

function cengi_pp_datos_curso(array $plan)
{
    return [
        'nombre_cursos' => $plan['nombre'],
        'categoria_curso_id' => (int) $plan['categoria_curso_id'],
        'instructor_id' => $plan['instructor_id'] ?: null,
        'actividad_tipo' => $plan['actividad_tipo'],
        'tipo' => $plan['modalidad'],
        'area_tecnica' => $plan['area_tecnica'],
        'jornada_cursos' => cengi_pp_jornada($plan),
        'dias' => $plan['dias'] !== '' ? $plan['dias'] : 'Según programa de sesiones',
        'horario' => substr($plan['hora_inicio'], 0, 5) . ' - ' . substr($plan['hora_fin'], 0, 5),
        'cupo' => $plan['cupo_maximo'] ?: null,
        'inicio' => $plan['fecha_inicio'],
        'fin' => $plan['fecha_fin'],
    ];
}

/**
 * Aprueba el plan: abre la convocatoria (aparece en el formulario publico de inscripcion).
 * El curso NO se crea aqui: lo crea cengi_ins_procesar_plan() cuando las inscripciones
 * validadas llegan al cupo minimo, o cengi_pp_crear_curso() con "Abrir curso ahora".
 */
function cengi_pp_aprobar(PDO $db, array $plan)
{
    if ((int) $plan['categoria_curso_id'] <= 0) {
        throw new InvalidArgumentException('Asigna una categoría al plan antes de aprobarlo: el curso la necesita.');
    }
    $db->prepare("UPDATE planificacion_cursos SET estado = 'aprobado', motivo_cancelacion = '' WHERE id = ?")->execute([(int) $plan['id']]);
    cengi_pp_log($db, $plan, 'Aprobación', !empty($plan['curso_id'])
        ? 'Vinculado al curso ' . $plan['codigo_curso'] . '.'
        : 'Convocatoria abierta. El curso se crea al llegar a ' . (int) $plan['cupo_minimo'] . ' inscripciones validadas.');
    if (empty($plan['curso_id'])) {
        // Inscripciones que ya estaban validadas (p. ej. un plan reactivado) pueden completar el cupo.
        require_once __DIR__ . '/inscripcion_helpers.php';
        cengi_ins_procesar_plan($db, (int) $plan['id']);
    }
    return $plan['codigo_curso'] ?? null;
}

/** Crea el curso en Gestion de cursos a partir del plan (paso de Planificacion a Cursos). */
function cengi_pp_crear_curso(PDO $db, array $plan, $motivo = '')
{
    if (!empty($plan['curso_id'])) {
        return $plan['codigo_curso'];
    }
    if ((int) $plan['categoria_curso_id'] <= 0) {
        throw new InvalidArgumentException('Asigna una categoría al plan: el curso la necesita.');
    }
    $datos = cengi_pp_datos_curso($plan);
    $codigo = cengi_pp_siguiente_codigo($db, $plan['categoria_nombre'] ?? '');
    $datos['codigo_curso'] = $codigo;
    $datos['ingenio_id'] = cengi_curso_ingenio_por_defecto($db);
    $columnas = array_keys($datos);
    $db->prepare('INSERT INTO cursos (' . implode(', ', $columnas) . ', creado) VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ', NOW())')
        ->execute(array_values($datos));
    $cursoId = (int) $db->lastInsertId();

    $modulos = [];
    if ($plan['actividad_tipo'] !== 'evento_tecnico') {
        foreach (cengi_pp_modulos_plan($db, $plan) as $nombreModulo) {
            if ($nombreModulo !== 'Sesión única') {
                $modulos[] = ['id' => 0, 'nombre' => $nombreModulo, 'horas' => 0, 'pre' => 1, 'post' => 1, 'instructores' => []];
            }
        }
    }
    $moduloIds = $modulos ? cengi_curso_guardar_modulos($db, $cursoId, $modulos, false) : [];
    cengi_curso_asegurar_enlaces_evaluacion($db, $cursoId, $datos['instructor_id'], $modulos, $moduloIds);

    $db->prepare('UPDATE planificacion_cursos SET curso_id = ? WHERE id = ?')->execute([$cursoId, (int) $plan['id']]);
    // Las inscripciones ya registradas quedan apuntando al curso nuevo.
    $db->prepare('UPDATE solicitudes_inscripcion SET curso_id = ? WHERE planificacion_id = ?')->execute([$cursoId, (int) $plan['id']]);
    cengi_pp_log($db, $plan, 'Curso abierto', 'Se creó el curso ' . $codigo . ' en Gestión de cursos' . ($motivo !== '' ? ' (' . $motivo . ')' : '') . '.', ['afecta' => false]);
    return $codigo;
}

/** Lleva los datos vigentes del plan al curso ya creado (nombre, fechas, horario, instructor...). */
function cengi_pp_sincronizar_curso(PDO $db, $planId)
{
    $plan = cengi_pp_plan($db, $planId);
    if (!$plan || empty($plan['curso_id']) || (int) $plan['categoria_curso_id'] <= 0) {
        return;
    }
    $datos = cengi_pp_datos_curso($plan);
    $asignaciones = implode(', ', array_map(static function ($columna) {
        return $columna . ' = ?';
    }, array_keys($datos)));
    $db->prepare('UPDATE cursos SET ' . $asignaciones . ' WHERE id = ?')->execute(array_merge(array_values($datos), [(int) $plan['curso_id']]));
}

// ---------------------------------------------------------------- sesiones y conflictos

function cengi_pp_traslapan($inicioA, $finA, $inicioB, $finB)
{
    return substr($inicioA, 0, 5) < substr($finB, 0, 5) && substr($inicioB, 0, 5) < substr($finA, 0, 5);
}

/**
 * Sesiones (no canceladas) en conflicto: mismo instructor o mismo soporte tecnico, mismo
 * dia y horario traslapado. Devuelve ['instructor' => [id => true], 'soporte' => [...], 'pares' => [[a, b], ...]].
 */
function cengi_pp_conflictos(PDO $db)
{
    $resultado = ['instructor' => [], 'soporte' => [], 'pares' => []];
    foreach (['instructor' => 'instructor_id', 'soporte' => 'soporte_id'] as $tipo => $columna) {
        $filas = $db->query("
            SELECT a.id AS a_id, b.id AS b_id
            FROM curso_sesiones a
            INNER JOIN curso_sesiones b ON b.$columna = a.$columna AND b.fecha = a.fecha AND b.id > a.id
                AND a.hora_inicio < b.hora_fin AND b.hora_inicio < a.hora_fin
            WHERE a.$columna IS NOT NULL AND a.estado <> 'cancelada' AND b.estado <> 'cancelada'
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as $fila) {
            $resultado[$tipo][(int) $fila['a_id']] = true;
            $resultado[$tipo][(int) $fila['b_id']] = true;
            if ($tipo === 'instructor') {
                $resultado['pares'][] = [(int) $fila['a_id'], (int) $fila['b_id']];
            }
        }
    }
    return $resultado;
}

/** Avisos antes de guardar una sesion: choques de instructor, soporte o lugar. */
function cengi_pp_avisos_sesion(PDO $db, array $sesion, $excluirId = 0)
{
    $stmt = $db->prepare("
        SELECT s.*, p.nombre AS plan_nombre, i.nombre AS instructor_nombre, st.nombre AS soporte_nombre
        FROM curso_sesiones s
        INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
        LEFT JOIN instructores i ON i.id = s.instructor_id
        LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id
        WHERE s.fecha = ? AND s.estado <> 'cancelada' AND s.id <> ?
          AND s.hora_inicio < ? AND ? < s.hora_fin
    ");
    $stmt->execute([$sesion['fecha'], (int) $excluirId, $sesion['hora_fin'], $sesion['hora_inicio']]);
    $avisos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $otra) {
        $cuando = cengi_pp_horario($otra['hora_inicio'], $otra['hora_fin']);
        if ($sesion['instructor_id'] && (int) $otra['instructor_id'] === (int) $sesion['instructor_id']) {
            $avisos[] = cengi_pp_h($otra['instructor_nombre']) . ' ya tiene “' . cengi_pp_h($otra['tema']) . '” (' . cengi_pp_h($otra['plan_nombre']) . ') de ' . $cuando . ' ese día.';
        }
        if ($sesion['soporte_id'] && (int) $otra['soporte_id'] === (int) $sesion['soporte_id']) {
            $avisos[] = 'El soporte técnico ' . cengi_pp_h($otra['soporte_nombre']) . ' ya atiende “' . cengi_pp_h($otra['tema']) . '” de ' . $cuando . ' ese día.';
        }
        if ($sesion['modalidad'] !== 'Virtual' && $otra['modalidad'] !== 'Virtual'
            && cengi_texto_normalizado($otra['lugar']) === cengi_texto_normalizado($sesion['lugar'])
            && (int) $otra['instructor_id'] !== (int) $sesion['instructor_id']) {
            $avisos[] = 'El lugar “' . cengi_pp_h($sesion['lugar']) . '” ya está ocupado por “' . cengi_pp_h($otra['tema']) . '” de ' . $cuando . '.';
        }
    }
    return $avisos;
}

function cengi_pp_lugar_por_defecto($modalidad)
{
    return ['Virtual' => 'Enlace virtual — Teams', 'Híbrida' => 'Auditorio CENGICAÑA / Zoom'][$modalidad] ?? 'Auditorio CENGICAÑA';
}

/** Instructores activos con su codigo de catedratico y tarifa. */
function cengi_pp_instructores(PDO $db, $soloActivos = true)
{
    return $db->query('SELECT id, nombre, correo, codigo_catedratico, tarifa_sesion, estado FROM instructores'
        . ($soloActivos ? ' WHERE estado = 1' : '') . ' ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
}

function cengi_pp_soportes(PDO $db, $soloActivos = true)
{
    return $db->query('SELECT * FROM soporte_tecnico' . ($soloActivos ? ' WHERE estado = 1' : '') . ' ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
}

const CENGI_PP_TIPOS_DOCUMENTO = [
    'cv' => 'Hoja de vida (CV)',
    'carta' => 'Carta de comprobación de su formación',
    'titulo' => 'Título o diploma',
    'otro' => 'Otro documento',
];

/**
 * Estado de la documentacion de los instructores: [id => ['cv' => bool, 'carta' => bool,
 * 'titulo' => bool, 'faltan' => [...], 'completa' => bool, 'activo' => bool]].
 * Completa = CV + carta de comprobacion + formacion (titulo) registrada, y activo.
 */
function cengi_pp_documentacion(PDO $db, $instructorId = null)
{
    $sql = "SELECT i.id, i.estado, i.cv_path, i.titulo,
                   EXISTS (SELECT 1 FROM instructor_documentos d WHERE d.instructor_id = i.id AND d.tipo = 'carta' AND d.vigente = 1) AS tiene_carta
            FROM instructores i" . ($instructorId ? ' WHERE i.id = ' . (int) $instructorId : '');
    $estado = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $i) {
        // Igual que instructores.php: un cv_path que apunta a un archivo borrado no cuenta.
        $urlCv = cengi_normalizar_url_archivo($i['cv_path']);
        $cv = $urlCv !== '' && is_file(__DIR__ . '/..' . $urlCv);
        $carta = (bool) $i['tiene_carta'];
        $titulo = trim((string) $i['titulo']) !== '';
        $faltan = array_keys(array_filter(['CV' => !$cv, 'carta de comprobación' => !$carta, 'formación (título)' => !$titulo]));
        $estado[(int) $i['id']] = ['cv' => $cv, 'carta' => $carta, 'titulo' => $titulo, 'faltan' => $faltan,
            'activo' => (int) $i['estado'] === 1, 'completa' => !$faltan && (int) $i['estado'] === 1];
    }
    return $instructorId ? ($estado[(int) $instructorId] ?? null) : $estado;
}

function cengi_pp_doc_chips(array $doc)
{
    $html = $doc['cv'] ? '<span class="pp-chip is-ok">CV</span> ' : '<span class="pp-chip is-danger">Sin CV</span> ';
    $html .= $doc['carta'] ? '<span class="pp-chip is-ok">Carta de comprobación</span> ' : '<span class="pp-chip is-danger">Sin carta</span> ';
    $html .= $doc['titulo'] ? '' : '<span class="pp-chip is-yellow">Sin título</span> ';
    return $html . ($doc['activo'] ? '' : '<span class="pp-chip">Inactivo</span>');
}

function cengi_pp_codigo_instructor($id)
{
    return 'INS-' . str_pad((string) (int) $id, 3, '0', STR_PAD_LEFT);
}

function cengi_pp_iniciales($nombre)
{
    $limpio = preg_replace('/^(Ing\.|Inga\.|Dra\.|Dr\.|Licda\.|Lic\.|M\.Sc\.|MSc\.|PhD\.?)\s*/iu', '', trim((string) $nombre));
    $iniciales = '';
    foreach (preg_split('/\s+/', $limpio, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($iniciales, 'UTF-8') >= 2) {
            break;
        }
    }
    return $iniciales ?: 'IN';
}

/** Inscritos frente al cupo minimo: semaforo para "Cursos por iniciar". */
function cengi_pp_semaforo_cupo(array $plan, $inscritos)
{
    if (!cengi_pp_convocado($plan)) {
        return ['gris', 'Sin convocatoria', 'Aprueba el plan para abrir inscripciones.'];
    }
    $minimo = max(1, (int) $plan['cupo_minimo']);
    if ($inscritos >= $minimo) {
        return ['verde', 'Puede iniciar', $inscritos . ' asignados de ' . $minimo . ' mínimos.'];
    }
    $faltan = $minimo - $inscritos;
    if ($inscritos >= $minimo * 0.6) {
        return ['amarillo', 'En riesgo', 'Faltan ' . $faltan . ' para el mínimo de ' . $minimo . '.'];
    }
    return ['rojo', 'No alcanza el mínimo', 'Faltan ' . $faltan . ' inscritos para el mínimo de ' . $minimo . '.'];
}

// ---------------------------------------------------------------- comunicaciones

function cengi_pp_llenar($texto, array $variables)
{
    return preg_replace_callback('/\{(\w+)\}/', static function ($m) use ($variables) {
        return array_key_exists($m[1], $variables) ? (string) $variables[$m[1]] : $m[0];
    }, (string) $texto);
}

function cengi_pp_correo_html($asunto, $mensaje)
{
    $titulo = htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8');
    $cuerpo = nl2br(htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'));
    return "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>{$titulo}</title></head>
<body style='margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6f8;padding:32px 12px;'><tr><td align='center'>
<table width='100%' cellpadding='0' cellspacing='0' style='max-width:620px;background:#ffffff;border-radius:10px;overflow:hidden;'>
<tr><td style='background:#176b3a;padding:24px;text-align:center;color:#ffffff;'>
<table cellpadding='0' cellspacing='0' style='margin:0 auto;'><tr><td style='background:#ffffff;border-radius:12px;padding:10px 20px;'>
<img src='cid:logo_cengicana' alt='CENGICAÑA' style='display:block;max-width:160px;height:auto;'></td></tr></table>
<p style='margin:12px 0 0;font-size:14px;'>Plataforma de Cursos</p></td></tr>
<tr><td style='padding:32px 32px 12px;'><h2 style='color:#176b3a;margin:0 0 16px;font-size:20px;'>{$titulo}</h2>
<p style='color:#333333;font-size:15px;line-height:1.7;margin:0;'>{$cuerpo}</p></td></tr>
<tr><td style='padding:20px 32px 28px;color:#6b7566;font-size:12px;line-height:1.6;'>Coordinación de Capacitación — CENGICAÑA</td></tr>
</table></td></tr></table></body></html>";
}

/**
 * Registra una comunicacion y la envia por correo a cada destinatario (un correo por
 * persona, con {nombre} personalizado). Devuelve [id, enviados, fallidos].
 *
 * $datos: categoria, origen, regla, referencia, planificacion_id, asunto, mensaje,
 * variables (opcional, se aplican antes que {nombre}).
 * Con $datos['programada_para'] solo guarda la comunicacion y sus destinatarios
 * (estado 'programada'); cron_comunicaciones.php la envia en su fecha.
 */
function cengi_pp_enviar_comunicacion(PDO $db, array $datos, array $destinatarios)
{
    require_once __DIR__ . '/correo.php';

    $vistos = [];
    $destinatarios = array_values(array_filter($destinatarios, static function ($d) use (&$vistos) {
        $correo = mb_strtolower(trim((string) ($d['correo'] ?? '')), 'UTF-8');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || isset($vistos[$correo])) {
            return false;
        }
        $vistos[$correo] = true;
        return true;
    }));
    $variables = (array) ($datos['variables'] ?? []);
    $asunto = cengi_pp_llenar($datos['asunto'], $variables);
    $mensaje = cengi_pp_llenar($datos['mensaje'], $variables);
    $programada = $datos['programada_para'] ?? null;

    $db->prepare('INSERT INTO comunicaciones (categoria, origen, regla, referencia, planificacion_id, asunto, mensaje, estado, programada_para, creado_por_nombre)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $datos['categoria'] ?? 'General', $datos['origen'] ?? 'manual', $datos['regla'] ?? null, $datos['referencia'] ?? null,
        $datos['planificacion_id'] ?? null, mb_substr($asunto, 0, 255, 'UTF-8'), $mensaje,
        $programada ? 'programada' : 'enviando', $programada, $datos['usuario'] ?? cengi_pp_usuario_nombre(),
    ]);
    $comunicacionId = (int) $db->lastInsertId();
    $stmtDest = $db->prepare('INSERT INTO comunicacion_destinatarios (comunicacion_id, nombre, correo) VALUES (?, ?, ?)');
    foreach ($destinatarios as $d) {
        $stmtDest->execute([$comunicacionId, (string) ($d['nombre'] ?? ''), trim((string) $d['correo'])]);
    }
    if ($programada) {
        return [$comunicacionId, 0, 0];
    }
    return cengi_pp_despachar_comunicacion($db, $comunicacionId);
}

/** Envia los destinatarios pendientes de una comunicacion ya registrada. */
function cengi_pp_despachar_comunicacion(PDO $db, $comunicacionId)
{
    require_once __DIR__ . '/correo.php';

    $stmt = $db->prepare('SELECT * FROM comunicaciones WHERE id = ?');
    $stmt->execute([(int) $comunicacionId]);
    $com = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmtDest = $db->prepare("SELECT * FROM comunicacion_destinatarios WHERE comunicacion_id = ? AND estado = 'pendiente'");
    $stmtDest->execute([(int) $comunicacionId]);
    $stmtEstado = $db->prepare('UPDATE comunicacion_destinatarios SET estado = ?, error = ? WHERE id = ?');

    // Las comunicaciones de este modulo ya quedan registradas aqui; correo.php no debe
    // volver a registrarlas como correo "del sistema".
    $GLOBALS['cengi_correo_sin_registro'] = true;
    foreach ($stmtDest->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $nombre = trim((string) $d['nombre']);
        $asunto = str_replace('{nombre}', $nombre, $com['asunto']);
        $mensaje = str_replace('{nombre}', $nombre !== '' ? $nombre : 'participante', $com['mensaje']);
        $error = '';
        try {
            $ok = cengi_enviar_correo($d['correo'], $nombre, $asunto, cengi_pp_correo_html($asunto, $mensaje));
            if (!$ok) {
                $error = 'El servidor de correo rechazó el envío.';
            }
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 255, 'UTF-8');
        }
        $stmtEstado->execute([$ok ? 'enviado' : 'fallido', $error, (int) $d['id']]);
    }
    unset($GLOBALS['cengi_correo_sin_registro']);

    $conteo = $db->prepare("SELECT SUM(estado = 'enviado'), SUM(estado = 'fallido') FROM comunicacion_destinatarios WHERE comunicacion_id = ?");
    $conteo->execute([(int) $comunicacionId]);
    [$enviados, $fallidos] = array_map('intval', $conteo->fetch(PDO::FETCH_NUM));
    $db->prepare('UPDATE comunicaciones SET estado = ?, enviados = ?, fallidos = ?, enviado_en = NOW() WHERE id = ?')
        ->execute([$enviados > 0 || $fallidos === 0 ? 'enviada' : 'fallida', $enviados, $fallidos, (int) $comunicacionId]);
    return [(int) $comunicacionId, $enviados, $fallidos];
}

function cengi_pp_reglas(PDO $db)
{
    $reglas = [];
    foreach ($db->query('SELECT * FROM comunicacion_reglas ORDER BY FIELD(clave, "sesion", "inicio_curso", "instructor_sesion"), clave')->fetchAll(PDO::FETCH_ASSOC) as $regla) {
        $reglas[$regla['clave']] = $regla;
    }
    return $reglas;
}

/** Variables de plantilla para una sesion o un curso. */
function cengi_pp_variables(array $plan, ?array $sesion = null)
{
    $variables = [
        'curso' => $plan['nombre'],
        'codigo' => $plan['codigo_curso'] ?? '',
        'modalidad' => $sesion['modalidad'] ?? $plan['modalidad'],
        'fecha' => cengi_pp_fecha_larga($sesion['fecha'] ?? $plan['fecha_inicio']),
        'horario' => $sesion ? cengi_pp_horario($sesion['hora_inicio'], $sesion['hora_fin']) : cengi_pp_horario($plan['hora_inicio'], $plan['hora_fin']),
        'tema' => $sesion['tema'] ?? '',
        'lugar' => $sesion['lugar'] ?? '',
    ];
    return $variables;
}

/**
 * Recordatorios automaticos que tocan entre $desde y $hasta (fecha de envio), segun
 * las reglas activas. Cada uno: regla, referencia, fecha_envio, asunto, mensaje,
 * variables, destinatarios, actividad, plan_id, hecho (ya enviado).
 */
function cengi_pp_recordatorios(PDO $db, $desde, $hasta)
{
    $reglas = cengi_pp_reglas($db);
    $items = [];
    $planes = [];
    $plan = static function ($id) use ($db, &$planes) {
        return $planes[$id] ??= cengi_pp_plan($db, $id);
    };
    $hoy = cengi_pp_hoy();

    foreach (['sesion', 'instructor_sesion'] as $clave) {
        $regla = $reglas[$clave] ?? null;
        if (!$regla || !(int) $regla['activo']) {
            continue;
        }
        $dias = (int) $regla['dias_antes'];
        $stmt = $db->prepare("
            SELECT s.*, i.nombre AS instructor_nombre, i.correo AS instructor_correo
            FROM curso_sesiones s
            INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
            LEFT JOIN instructores i ON i.id = s.instructor_id
            WHERE s.estado = 'programada' AND p.estado IN ('aprobado', 'reprogramado')
              AND s.fecha BETWEEN ? AND ? AND s.fecha >= ?
            ORDER BY s.fecha, s.hora_inicio
        ");
        $stmt->execute([cengi_pp_sumar_dias($desde, $dias), cengi_pp_sumar_dias($hasta, $dias), $hoy]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sesion) {
            $p = $plan($sesion['planificacion_id']);
            if ($clave === 'sesion') {
                $destinatarios = cengi_pp_inscritos($db, $p, true);
            } else {
                $destinatarios = $sesion['instructor_correo'] ? [['nombre' => $sesion['instructor_nombre'], 'correo' => $sesion['instructor_correo']]] : [];
            }
            $items[] = [
                'regla' => $clave, 'regla_nombre' => $regla['nombre'],
                'referencia' => ($clave === 'sesion' ? 'sesion:' : 'instr:') . $sesion['id'] . ':' . $sesion['fecha'] . ':' . substr($sesion['hora_inicio'], 0, 5),
                'fecha_envio' => cengi_pp_sumar_dias($sesion['fecha'], -$dias),
                'asunto' => $regla['asunto'], 'mensaje' => $regla['mensaje'],
                'variables' => cengi_pp_variables($p, $sesion), 'destinatarios' => $destinatarios,
                'actividad' => $p['nombre'] . ' · ' . $sesion['tema'], 'plan_id' => (int) $p['id'],
            ];
        }
    }

    $regla = $reglas['inicio_curso'] ?? null;
    if ($regla && (int) $regla['activo']) {
        $dias = (int) $regla['dias_antes'];
        $stmt = $db->prepare("SELECT id FROM planificacion_cursos WHERE estado IN ('aprobado', 'reprogramado') AND curso_id IS NOT NULL
            AND fecha_inicio BETWEEN ? AND ? AND fecha_inicio >= ? ORDER BY fecha_inicio");
        $stmt->execute([cengi_pp_sumar_dias($desde, $dias), cengi_pp_sumar_dias($hasta, $dias), $hoy]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $planId) {
            $p = $plan($planId);
            $items[] = [
                'regla' => 'inicio_curso', 'regla_nombre' => $regla['nombre'],
                'referencia' => 'curso:' . $p['id'] . ':' . $p['fecha_inicio'],
                'fecha_envio' => cengi_pp_sumar_dias($p['fecha_inicio'], -$dias),
                'asunto' => $regla['asunto'], 'mensaje' => $regla['mensaje'],
                'variables' => cengi_pp_variables($p), 'destinatarios' => cengi_pp_inscritos($db, $p, true),
                'actividad' => $p['nombre'], 'plan_id' => (int) $p['id'],
            ];
        }
    }

    if ($items) {
        $hechos = $db->query('SELECT CONCAT(regla, "|", referencia) FROM comunicaciones WHERE regla IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $hechos = array_flip($hechos);
        foreach ($items as &$item) {
            $item['hecho'] = isset($hechos[$item['regla'] . '|' . $item['referencia']]);
        }
        unset($item);
    }
    usort($items, static function ($a, $b) {
        return strcmp($a['fecha_envio'], $b['fecha_envio']);
    });
    return $items;
}

/**
 * Envia los recordatorios cuya fecha de envio ya llego (y no se han enviado) y las
 * comunicaciones manuales programadas que ya vencieron. Lo ejecuta cron_comunicaciones.php
 * a diario y el boton "Enviar recordatorios de hoy". Devuelve un resumen.
 */
function cengi_pp_ejecutar_pendientes(PDO $db, $usuario = 'Sistema (automático)')
{
    $hoy = cengi_pp_hoy();
    $resumen = ['recordatorios' => 0, 'programadas' => 0, 'enviados' => 0, 'fallidos' => 0];
    foreach (cengi_pp_recordatorios($db, cengi_pp_sumar_dias($hoy, -7), $hoy) as $item) {
        if ($item['hecho'] || !$item['destinatarios']) {
            continue;
        }
        try {
            [, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, [
                'categoria' => 'Recordatorio automático', 'origen' => 'recordatorio', 'regla' => $item['regla'],
                'referencia' => $item['referencia'], 'planificacion_id' => $item['plan_id'],
                'asunto' => $item['asunto'], 'mensaje' => $item['mensaje'], 'variables' => $item['variables'], 'usuario' => $usuario,
            ], $item['destinatarios']);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                continue; // otro proceso ya lo envio (UNIQUE regla+referencia)
            }
            throw $e;
        }
        $resumen['recordatorios']++;
        $resumen['enviados'] += $enviados;
        $resumen['fallidos'] += $fallidos;
    }
    $vencidas = $db->query("SELECT id FROM comunicaciones WHERE estado = 'programada' AND programada_para <= NOW()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($vencidas as $id) {
        // Toma la comunicacion antes de enviarla para que dos ejecuciones no la dupliquen.
        $tomada = $db->prepare("UPDATE comunicaciones SET estado = 'enviando' WHERE id = ? AND estado = 'programada'");
        $tomada->execute([(int) $id]);
        if ($tomada->rowCount() === 0) {
            continue;
        }
        [, $enviados, $fallidos] = cengi_pp_despachar_comunicacion($db, $id);
        $resumen['programadas']++;
        $resumen['enviados'] += $enviados;
        $resumen['fallidos'] += $fallidos;
    }
    return $resumen;
}

/** Barra de navegacion entre las secciones de planificacion. */
function cengi_pp_subnav($activa)
{
    $secciones = [
        'planificacion_cursos.php' => ['Planificación', 'calendar'],
        'calendario_cursos.php' => ['Calendario', 'th'],
        'programa_sesiones.php' => ['Sesiones', 'list'],
        'pago_instructores.php' => ['Pago a instructores', 'credit-card'],
        'bitacora_cambios.php' => ['Bitácora', 'time'],
        'comunicaciones.php' => ['Comunicaciones', 'envelope'],
    ];
    echo '<nav class="pp-subnav" aria-label="Planificación y programación">';
    foreach ($secciones as $archivo => [$texto, $icono]) {
        echo '<a href="' . $archivo . '"' . ($archivo === $activa ? ' class="is-active" aria-current="page"' : '') . '><span class="glyphicon glyphicon-' . $icono . '"></span>' . $texto . '</a>';
    }
    echo '</nav>';
}
