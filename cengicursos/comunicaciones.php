<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';
$reabrir = null;

/** Destinatarios de una comunicacion manual segun la audiencia elegida. */
function cengi_com_audiencia(PDO $db, $audiencia, $planId, $manual)
{
    if ($audiencia === 'curso' || $audiencia === 'instructores_curso') {
        $plan = cengi_pp_plan($db, $planId);
        if (!$plan) {
            throw new InvalidArgumentException('Selecciona el curso.');
        }
        if ($audiencia === 'curso') {
            return [cengi_pp_inscritos($db, $plan), $plan];
        }
        $stmt = $db->prepare("SELECT DISTINCT i.nombre, i.correo FROM instructores i
            WHERE i.id IN (SELECT instructor_id FROM curso_sesiones WHERE planificacion_id = ? AND estado <> 'cancelada') OR i.id = ?");
        $stmt->execute([$plan['id'], (int) $plan['instructor_id']]);
        return [$stmt->fetchAll(PDO::FETCH_ASSOC), $plan];
    }
    if ($audiencia === 'instructores') {
        return [$db->query("SELECT nombre, correo FROM instructores WHERE estado = 1 AND correo <> ''")->fetchAll(PDO::FETCH_ASSOC), null];
    }
    if ($audiencia === 'soporte') {
        return [$db->query("SELECT nombre, correo FROM soporte_tecnico WHERE estado = 1 AND correo <> ''")->fetchAll(PDO::FETCH_ASSOC), null];
    }
    $destinatarios = [];
    foreach (preg_split('/[\s,;]+/', (string) $manual, -1, PREG_SPLIT_NO_EMPTY) as $correo) {
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Correo no válido: ' . cengi_pp_h($correo));
        }
        $destinatarios[] = ['nombre' => '', 'correo' => $correo];
    }
    return [$destinatarios, null];
}


// Categorías cuyo correo lleva calendario, adjuntos o tabla: se reenvían desde su propia sección.
const CENGI_COM_NO_REINTENTABLES = ['Invitación de calendario', 'Cancelación de calendario', 'Programación de sesiones'];
// Icono y tono de cada categoría (las demás: sobre gris).
const CENGI_COM_CATEGORIAS = [
    'Comunicación manual' => ['pencil', 'is-deep'], 'Cambios de programación' => ['refresh', 'is-amarillo'],
    'Recordatorio automático' => ['bell', 'is-verde2'], 'Recordatorio de pago' => ['credit-card', 'is-naranja'],
    'Pagos de inscripción' => ['usd', 'is-verde'], 'Validación del ingenio' => ['check', 'is-amarillo'],
    'Invitación de calendario' => ['calendar', 'is-verde2'], 'Cancelación de calendario' => ['calendar', 'is-rojo'],
    'Programación de sesiones' => ['list', 'is-verde'], 'Inscripción' => ['inbox', 'is-verde'], 'Asignación al curso' => ['education', 'is-verde'],
    'Diplomas' => ['certificate', 'is-verde'], 'Pago a instructores' => ['credit-card', 'is-verde2'],
    'Eventos' => ['flag', 'is-verde2'], 'Correo del sistema' => ['cog', 'is-gris'],
];

// Áreas para organizar el historial: cada categoría pertenece a una. Lo que no esté aquí cae en "Otras".
const CENGI_COM_GRUPOS = [
    'cursos' => ['Cursos y sesiones', 'education', ['Cambios de programación', 'Programación de sesiones', 'Invitación de calendario', 'Cancelación de calendario']],
    'inscripciones' => ['Inscripciones y pagos', 'inbox', ['Inscripción', 'Asignación al curso', 'Validación del ingenio', 'Pagos de inscripción', 'Recordatorio de pago']],
    'recordatorios' => ['Recordatorios automáticos', 'bell', ['Recordatorio automático']],
    'instructores' => ['Instructores', 'user', ['Pago a instructores']],
    'eventos' => ['Eventos', 'flag', ['Eventos']],
    'certificacion' => ['Certificación', 'certificate', ['Diplomas']],
    'manual' => ['Escritas a mano', 'pencil', ['Comunicación manual']],
    'sistema' => ['Avisos del sistema', 'cog', ['Correo del sistema']],
];

/** Área de una categoría (clave de CENGI_COM_GRUPOS u "otras"). */
function cengi_com_grupo($categoria)
{
    foreach (CENGI_COM_GRUPOS as $clave => [, , $categorias]) {
        if (in_array($categoria, $categorias, true)) {
            return $clave;
        }
    }
    return 'otras';
}

function cengi_com_reintentable(array $c)
{
    return (int) $c['fallidos'] > 0 && trim((string) $c['mensaje']) !== '' && !in_array($c['categoria'], CENGI_COM_NO_REINTENTABLES, true);
}

/** Sección relacionada con la comunicación: [url, texto] o null. */
function cengi_com_enlace(array $c)
{
    $plan = (int) $c['planificacion_id'];
    switch ($c['categoria']) {
        case 'Cambios de programación':
            return $plan ? ['bitacora_cambios.php?plan=' . $plan, 'Bitácora del curso'] : null;
        case 'Recordatorio de pago':
        case 'Pagos de inscripción':
        case 'Validación del ingenio':
            return ['pagos_inscripcion.php?' . http_build_query(array_filter(['tab' => 'cobranza', 'plan' => $plan ?: null])), 'Pagos de inscripción'];
        case 'Invitación de calendario':
        case 'Cancelación de calendario':
        case 'Programación de sesiones':
            return $plan ? ['programa_sesiones.php?ver=' . $plan, 'Programa de sesiones'] : null;
        case 'Inscripción':
        case 'Asignación al curso':
            return $plan ? ['inscripciones.php?plan=' . $plan, 'Inscripciones'] : null;
        case 'Diplomas':
            return ['diplomas.php?tab=emitidos', 'Certificación'];
        case 'Eventos':
            return ['eventos_qr.php?tab=eventos', 'Eventos'];
        case 'Pago a instructores':
            return preg_match('/^liq:(\d+)$/', (string) ($c['referencia'] ?? ''), $m)
                ? ['pago_instructores.php?tab=liquidaciones&ver=' . $m[1], 'Liquidación'] : ['pago_instructores.php?tab=liquidaciones', 'Pago a instructores'];
    }
    return $plan ? ['programa_sesiones.php?ver=' . $plan, 'Ver el curso'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $codigo = '';
    $extra = [];
    try {
        if ($accion === 'ejecutar') {
            $r = cengi_pp_ejecutar_pendientes($db, cengi_pp_usuario_nombre());
            $mensaje = cengi_pp_h('Se procesaron ' . $r['recordatorios'] . ' recordatorio(s) y ' . $r['programadas'] . ' envío(s) programado(s): '
                . $r['enviados'] . ' correo(s) enviados' . ($r['fallidos'] ? ', ' . $r['fallidos'] . ' fallidos' : '') . '.');
            $mensajeTipo = $r['fallidos'] ? 'warning' : 'success';
        } elseif ($accion === 'nueva') {
            $asunto = trim((string) ($_POST['asunto'] ?? ''));
            $texto = trim((string) ($_POST['mensaje'] ?? ''));
            $cuando = ($_POST['cuando'] ?? 'ahora') === 'programar' ? str_replace('T', ' ', (string) ($_POST['programada_para'] ?? '')) : null;
            if ($asunto === '' || $texto === '') {
                throw new InvalidArgumentException('Escribe el asunto y el mensaje.');
            }
            if ($cuando !== null && (strtotime($cuando) === false || strtotime($cuando) <= time())) {
                throw new InvalidArgumentException('La fecha programada debe ser futura.');
            }
            [$destinatarios, $plan] = cengi_com_audiencia($db, (string) ($_POST['audiencia'] ?? ''), (int) ($_POST['plan_id'] ?? 0), $_POST['manual'] ?? '');
            if (!$destinatarios) {
                throw new InvalidArgumentException('La audiencia elegida no tiene destinatarios con correo.');
            }
            [, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, [
                'categoria' => 'Comunicación manual', 'origen' => 'manual', 'planificacion_id' => $plan['id'] ?? null,
                'asunto' => $asunto, 'mensaje' => $texto, 'variables' => $plan ? cengi_pp_variables($plan) : [],
                'programada_para' => $cuando ? date('Y-m-d H:i:00', strtotime($cuando)) : null,
            ], $destinatarios);
            if ($cuando) {
                $codigo = 'programada';
            } elseif ($enviados === 0) {
                throw new RuntimeException('Ningún correo se pudo enviar (' . $fallidos . ' fallidos). Revisa la configuración de correo; el detalle quedó en Enviadas.');
            } else {
                $codigo = $fallidos ? 'parcial' : 'enviada';
            }
        } elseif ($accion === 'reintentar') {
            // Vuelve a enviar solo a los destinatarios que fallaron.
            $stmt = $db->prepare("SELECT * FROM comunicaciones WHERE id = ? AND estado IN ('enviada', 'fallida')");
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $com = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$com || !cengi_com_reintentable($com)) {
                throw new InvalidArgumentException('Esta comunicación no tiene envíos fallidos que se puedan reintentar desde aquí.');
            }
            $db->prepare("UPDATE comunicacion_destinatarios SET estado = 'pendiente', error = '' WHERE comunicacion_id = ? AND estado = 'fallido'")->execute([(int) $com['id']]);
            [, $enviados, $fallidos] = cengi_pp_despachar_comunicacion($db, (int) $com['id']);
            $extra = ['n' => (int) $com['fallidos'] - $fallidos, 'nf' => $fallidos];
            $codigo = 'reintento';
        } elseif ($accion === 'regla') {
            $clave = (string) ($_POST['clave'] ?? '');
            $reglas = cengi_pp_reglas($db);
            if (!isset($reglas[$clave])) {
                throw new InvalidArgumentException('Regla no válida.');
            }
            if (isset($_POST['solo_activo'])) {
                $db->prepare('UPDATE comunicacion_reglas SET activo = ? WHERE clave = ?')->execute([!empty($_POST['activo']) ? 1 : 0, $clave]);
            } else {
                $asunto = trim((string) ($_POST['asunto'] ?? ''));
                $texto = trim((string) ($_POST['mensaje'] ?? ''));
                $dias = max(0, min(30, (int) ($_POST['dias_antes'] ?? 1)));
                if ($asunto === '' || $texto === '') {
                    $reabrir = ['clave' => $clave, 'asunto' => $asunto, 'mensaje' => $texto, 'dias_antes' => $dias];
                    throw new InvalidArgumentException('Escribe el asunto y el mensaje de la plantilla.');
                }
                $db->prepare('UPDATE comunicacion_reglas SET asunto = ?, mensaje = ?, dias_antes = ? WHERE clave = ?')->execute([$asunto, $texto, $dias, $clave]);
            }
            $codigo = 'regla';
            $extra = ['tab' => 'reglas'];
        } elseif ($accion === 'recordatorio') {
            // Enviar ahora u omitir un recordatorio de la agenda.
            $regla = (string) ($_POST['regla'] ?? '');
            $referencia = (string) ($_POST['referencia'] ?? '');
            $item = null;
            foreach (cengi_pp_recordatorios($db, cengi_pp_sumar_dias($hoy, -7), cengi_pp_sumar_dias($hoy, 14)) as $r) {
                if ($r['regla'] === $regla && $r['referencia'] === $referencia && !$r['hecho']) {
                    $item = $r;
                    break;
                }
            }
            if (!$item) {
                throw new InvalidArgumentException('Ese recordatorio ya se envió o ya no aplica.');
            }
            $omitir = ($_POST['modo'] ?? '') === 'omitir';
            $datos = ['categoria' => 'Recordatorio automático', 'origen' => 'recordatorio', 'regla' => $regla, 'referencia' => $referencia,
                'planificacion_id' => $item['plan_id'], 'asunto' => $item['asunto'], 'mensaje' => $item['mensaje'], 'variables' => $item['variables']];
            if ($omitir) {
                // Se registra como cancelado para que el cron no lo envie.
                $db->prepare("INSERT INTO comunicaciones (categoria, origen, regla, referencia, planificacion_id, asunto, mensaje, estado, creado_por_nombre) VALUES (?, 'recordatorio', ?, ?, ?, ?, ?, 'cancelada', ?)")
                    ->execute(['Recordatorio automático', $regla, $referencia, $item['plan_id'], cengi_pp_llenar($item['asunto'], $item['variables']), cengi_pp_llenar($item['mensaje'], $item['variables']), cengi_pp_usuario_nombre()]);
                $codigo = 'omitido';
            } else {
                if (!$item['destinatarios']) {
                    throw new InvalidArgumentException('Este recordatorio no tiene destinatarios.');
                }
                [, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, $datos, $item['destinatarios']);
                $codigo = $fallidos ? 'parcial' : 'enviada';
            }
            $extra = ['tab' => 'programados'];
        } elseif ($accion === 'cancelar_programada') {
            $db->prepare("UPDATE comunicaciones SET estado = 'cancelada' WHERE id = ? AND estado = 'programada'")->execute([(int) ($_POST['id'] ?? 0)]);
            $codigo = 'cancelada';
            $extra = ['tab' => 'programados'];
        } elseif ($accion === 'avisar_cambios' || $accion === 'omitir_cambios') {
            // Cambios de programación que afectan a los inscritos (registrados en la Bitácora):
            // se avisan por correo (y, si se marca, como anuncio en el aula) o se marcan "no avisar".
            $planAviso = cengi_pp_plan($db, (int) ($_POST['plan_id'] ?? 0));
            $cambiosSel = array_map('intval', (array) ($_POST['cambios'] ?? []));
            $extra = ['tab' => 'avisar'];
            if (!$planAviso || !$cambiosSel) {
                throw new InvalidArgumentException('Marca al menos un cambio.');
            }
            $marcadores = implode(',', array_fill(0, count($cambiosSel), '?'));
            if ($accion === 'omitir_cambios') {
                $db->prepare("UPDATE planificacion_cambios SET notificacion = 'omitida' WHERE planificacion_id = ? AND notificacion = 'pendiente' AND id IN ($marcadores)")
                    ->execute(array_merge([(int) $planAviso['id']], $cambiosSel));
                $codigo = 'no_avisado';
            } else {
                $asunto = trim((string) ($_POST['asunto'] ?? ''));
                $texto = trim((string) ($_POST['mensaje'] ?? ''));
                $elegidos = array_flip(array_map('strval', (array) ($_POST['destinatarios'] ?? [])));
                $destinatarios = array_values(array_filter(cengi_pp_inscritos($db, $planAviso), static function ($i) use ($elegidos) {
                    return isset($elegidos[$i['clave']]);
                }));
                if (!$destinatarios || $asunto === '' || $texto === '') {
                    $reabrirAviso = (int) $planAviso['id'];
                    throw new InvalidArgumentException('Marca al menos un destinatario y escribe el asunto y el mensaje.');
                }
                [$comId, $enviados, $fallidos] = cengi_pp_enviar_comunicacion($db, [
                    'categoria' => 'Cambios de programación', 'origen' => 'cambios', 'planificacion_id' => (int) $planAviso['id'],
                    'asunto' => $asunto, 'mensaje' => $texto,
                ], $destinatarios);
                if ($enviados === 0) {
                    $reabrirAviso = (int) $planAviso['id'];
                    throw new RuntimeException('No se pudo enviar el aviso (' . $fallidos . ' correo(s) fallaron). Revisa la configuración de correo; los cambios siguen pendientes.');
                }
                $db->prepare("UPDATE planificacion_cambios SET notificacion = 'enviada', comunicacion_id = ? WHERE planificacion_id = ? AND notificacion = 'pendiente' AND id IN ($marcadores)")
                    ->execute(array_merge([$comId, (int) $planAviso['id']], $cambiosSel));
                // El mismo aviso como anuncio en el aula del curso (sin el saludo personalizado).
                if (!empty($_POST['publicar_aula']) && $planAviso['curso_id'] && aula_vista_ready($db)) {
                    $textoAula = trim(preg_replace('/^Hola\s*\{nombre\}\s*:?\s*/u', '', str_replace('{nombre}', 'participante', $texto)));
                    $db->prepare('INSERT INTO aula_anuncios(curso_id, titulo, mensaje, visible, fijado, creado_por, creado_por_nombre) VALUES (?, ?, ?, 1, 0, ?, ?)')
                        ->execute([(int) $planAviso['curso_id'], mb_substr($asunto, 0, 180, 'UTF-8'), mb_substr($textoAula, 0, 5000, 'UTF-8'),
                            cengi_usuario_actual_id() ?: null, mb_substr(cengi_pp_usuario_nombre(), 0, 255, 'UTF-8')]);
                }
                $codigo = $fallidos ? 'avisado_parcial' : 'avisado';
            }
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        if ($codigo !== '') {
            $_GET = array_diff_key($_GET, array_flip(['msg', 'n', 'nf', 'nueva', 'exportar']));
            cengi_pp_redirigir('comunicaciones.php', ['msg' => $codigo] + $extra);
        }
    } catch (Throwable $e) {
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException && !$e instanceof PDOException) {
            $mensaje = $e instanceof InvalidArgumentException ? $e->getMessage() : cengi_pp_h($e->getMessage());
        } else {
            error_log('Comunicaciones: ' . $e->getMessage());
            $mensaje = (string) $e->getCode() === '23000' ? 'Ese recordatorio ya se registró.' : 'No fue posible completar la acción.';
        }
        if ($accion === 'nueva') {
            $reabrir = ['nueva' => array_intersect_key($_POST, array_flip(['audiencia', 'plan_id', 'manual', 'asunto', 'mensaje', 'cuando', 'programada_para']))];
        }
        if ($accion === 'avisar_cambios' || $accion === 'omitir_cambios') {
            $_GET['tab'] = 'avisar';
            if (!empty($reabrirAviso)) {
                $_GET['avisar'] = $reabrirAviso;
            }
        }
    }
}

$mensajes = [
    'enviada' => 'Comunicación enviada.',
    'parcial' => 'Comunicación enviada, pero algunos correos fallaron: revisa el detalle.',
    'programada' => 'Comunicación programada: se enviará en la fecha indicada.',
    'regla' => 'Regla de recordatorio actualizada.',
    'omitido' => 'Recordatorio omitido: no se enviará.',
    'cancelada' => 'Envío programado cancelado.',
    'avisado' => 'Aviso enviado a los inscritos. Los cambios quedan como avisados en la Bitácora.',
    'avisado_parcial' => 'Aviso enviado, pero algunos correos fallaron: revisa el detalle en Enviadas.',
    'no_avisado' => 'Cambios marcados como “No avisar”.',
];
if ($mensaje === '' && ($_GET['msg'] ?? '') === 'reintento') {
    $mensaje = cengi_pp_h('Reintento terminado: ' . (int) ($_GET['n'] ?? 0) . ' correo(s) enviados' . ((int) ($_GET['nf'] ?? 0) ? ' y ' . (int) $_GET['nf'] . ' siguen fallando (revisa el error en el detalle).' : '.'));
    $mensajeTipo = (int) ($_GET['nf'] ?? 0) ? 'warning' : 'success';
} elseif ($mensaje === '' && isset($mensajes[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h($mensajes[$_GET['msg']]);
    $mensajeTipo = in_array($_GET['msg'], ['parcial', 'avisado_parcial'], true) ? 'warning' : 'success';
}

// ---------------------------------------------------------------- datos
$tab = in_array($_GET['tab'] ?? '', ['avisar', 'programados', 'reglas'], true) ? $_GET['tab'] : 'enviadas';

// "Por avisar": cambios de programación que afectan a los inscritos y aún no se les comunican,
// agrupados por curso (el más antiguo primero). La Bitácora solo muestra su estado.
$pendientesAviso = $db->query("SELECT c.*, p.nombre AS plan_nombre, cu.codigo_curso
    FROM planificacion_cambios c INNER JOIN planificacion_cursos p ON p.id = c.planificacion_id LEFT JOIN cursos cu ON cu.id = p.curso_id
    WHERE c.notificacion = 'pendiente' ORDER BY c.creado, c.id")->fetchAll(PDO::FETCH_ASSOC);
$avisosPorCurso = [];
foreach ($pendientesAviso as $c) {
    $k = (int) $c['planificacion_id'];
    $avisosPorCurso[$k] = $avisosPorCurso[$k] ?? ['id' => $k, 'nombre' => $c['plan_nombre'], 'codigo' => $c['codigo_curso'], 'cambios' => [], 'desde' => $c['creado']];
    $avisosPorCurso[$k]['cambios'][] = $c;
}
foreach ($avisosPorCurso as &$ac) {
    $ac['inscritos'] = count(cengi_pp_inscritos($db, cengi_pp_plan($db, $ac['id'])));
}
unset($ac);
// Curso cuyo aviso se abre (?tab=avisar&avisar=<plan>): cambios, inscritos, programación vigente y asunto sugerido.
$aviso = null;
$avisarId = (int) ($_GET['avisar'] ?? 0);
if ($avisarId && isset($avisosPorCurso[$avisarId])) {
    $tab = 'avisar';
    $planAvisoSel = cengi_pp_plan($db, $avisarId);
    $cambiosAviso = $avisosPorCurso[$avisarId]['cambios'];
    $aviso = [
        'plan' => $planAvisoSel, 'pendientes' => $cambiosAviso, 'inscritos' => cengi_pp_inscritos($db, $planAvisoSel),
        'vigente' => cengi_pp_programacion_vigente($db, $planAvisoSel),
        'aula' => $planAvisoSel['curso_id'] && aula_vista_ready($db),
        'asunto' => ($planAvisoSel['estado'] === 'cancelado' ? 'Cancelación' : (array_filter($cambiosAviso, static function ($c) {
            return $c['tipo'] === 'Reprogramación';
        }) ? 'Reprogramación' : 'Actualización')) . ': ' . $planAvisoSel['nombre'],
    ];
}
$busqueda = trim((string) ($_GET['q'] ?? ''));
$categoriaFiltro = trim((string) ($_GET['categoria'] ?? ''));
$grupoFiltro = array_key_exists($_GET['grupo'] ?? '', CENGI_COM_GRUPOS) || ($_GET['grupo'] ?? '') === 'otras' ? $_GET['grupo'] : '';
$estadoFiltro = in_array($_GET['estado'] ?? '', ['ok', 'fallos'], true) ? $_GET['estado'] : '';
$periodo = in_array($_GET['periodo'] ?? '', ['7', '30', '90'], true) ? $_GET['periodo'] : '';
$planFiltro = (int) ($_GET['plan'] ?? 0);
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 50;

// Indicadores con una sola consulta agregada.
$kpi = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(enviados), 0) AS correos, COALESCE(SUM(fallidos), 0) AS fallidos,
        COALESCE(SUM(fallidos > 0 OR estado = 'fallida'), 0) AS con_fallos,
        COALESCE(SUM(DATE_FORMAT(COALESCE(enviado_en, creado), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')), 0) AS mes,
        COALESCE(SUM(IF(DATE_FORMAT(COALESCE(enviado_en, creado), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m'), enviados, 0)), 0) AS correos_mes
    FROM comunicaciones WHERE estado IN ('enviada', 'fallida', 'enviando')")->fetch(PDO::FETCH_ASSOC);
$categorias = $db->query("SELECT categoria, COUNT(*) FROM comunicaciones WHERE estado IN ('enviada', 'fallida', 'enviando') GROUP BY categoria ORDER BY categoria")->fetchAll(PDO::FETCH_KEY_PAIR);
$planFiltroNombre = '';
if ($planFiltro) {
    $stmt = $db->prepare('SELECT nombre FROM planificacion_cursos WHERE id = ?');
    $stmt->execute([$planFiltro]);
    $planFiltroNombre = (string) $stmt->fetchColumn();
}

// Enviadas: filtros y paginación en la base de datos; destinatarios solo de la página.
$donde = ["co.estado IN ('enviada', 'fallida', 'enviando')"];
$params = [];
if ($categoriaFiltro !== '') {
    $donde[] = 'co.categoria = ?';
    $params[] = $categoriaFiltro;
}
if ($grupoFiltro !== '') {
    // Categorías del área (o, para "otras", las que no están en ninguna área).
    $catsGrupo = $grupoFiltro === 'otras' ? array_merge(...array_column(CENGI_COM_GRUPOS, 2)) : CENGI_COM_GRUPOS[$grupoFiltro][2];
    $donde[] = 'co.categoria ' . ($grupoFiltro === 'otras' ? 'NOT ' : '') . 'IN (' . implode(',', array_fill(0, count($catsGrupo), '?')) . ')';
    array_push($params, ...$catsGrupo);
}
if ($estadoFiltro === 'fallos') {
    $donde[] = "(co.fallidos > 0 OR co.estado = 'fallida')";
} elseif ($estadoFiltro === 'ok') {
    $donde[] = "co.fallidos = 0 AND co.estado = 'enviada'";
}
if ($periodo !== '') {
    $donde[] = 'COALESCE(co.enviado_en, co.creado) >= ?';
    $params[] = date('Y-m-d H:i:s', strtotime('-' . (int) $periodo . ' days'));
}
if ($planFiltro) {
    $donde[] = 'co.planificacion_id = ?';
    $params[] = $planFiltro;
}
if ($busqueda !== '') {
    $donde[] = '(co.asunto LIKE ? OR p.nombre LIKE ? OR EXISTS (SELECT 1 FROM comunicacion_destinatarios d WHERE d.comunicacion_id = co.id AND (d.nombre LIKE ? OR d.correo LIKE ?)))';
    array_push($params, ...array_fill(0, 4, '%' . $busqueda . '%'));
}
$whereSql = ' WHERE ' . implode(' AND ', $donde);
$desdeSql = ' FROM comunicaciones co LEFT JOIN planificacion_cursos p ON p.id = co.planificacion_id';
$stmt = $db->prepare('SELECT COUNT(*)' . $desdeSql . $whereSql);
$stmt->execute($params);
$totalLista = (int) $stmt->fetchColumn();
$paginas = max(1, (int) ceil($totalLista / $porPagina));
$pagina = min($pagina, $paginas);
$exportar = ($_GET['exportar'] ?? '') !== '';
$stmt = $db->prepare('SELECT co.*, p.nombre AS plan_nombre' . $desdeSql . $whereSql . ' ORDER BY COALESCE(co.enviado_en, co.creado) DESC, co.id DESC LIMIT '
    . ($exportar ? 5000 : $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina)));
$stmt->execute($params);
$lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
$destPorCom = [];
if ($lista) {
    $ids = implode(',', array_map('intval', array_column($lista, 'id')));
    foreach ($db->query("SELECT comunicacion_id, nombre, correo, estado, error FROM comunicacion_destinatarios WHERE comunicacion_id IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $destPorCom[$d['comunicacion_id']][] = $d;
    }
}

if ($exportar) {
    require_once __DIR__ . '/cobranza_helpers.php';
    $filas = [];
    foreach ($lista as $n) {
        foreach ($destPorCom[$n['id']] ?? [['nombre' => '', 'correo' => '', 'estado' => '', 'error' => '']] as $d) {
            $filas[] = [$n['enviado_en'] ?: $n['creado'], date('H:i', strtotime($n['enviado_en'] ?: $n['creado'])), $n['categoria'], $n['asunto'], $n['plan_nombre'],
                $d['nombre'], $d['correo'], $d['estado'], $d['error'], $n['creado_por_nombre']];
        }
    }
    cengi_cob_excel('Comunicaciones enviadas', count($lista) . ' comunicación(es) · un renglón por destinatario', [
        ['Fecha', 11, 'fecha'], ['Hora', 7, 'texto'], ['Categoría', 22, 'texto'], ['Asunto', 40, 'texto'], ['Curso', 28, 'texto'],
        ['Destinatario', 26, 'texto'], ['Correo', 28, 'texto'], ['Estado', 10, 'texto'], ['Error', 30, 'texto'], ['Enviado por', 18, 'texto'],
    ], $filas, 'Comunicaciones');
}

$agenda = cengi_pp_recordatorios($db, $hoy, cengi_pp_sumar_dias($hoy, 14));
$programadasManual = $db->query("SELECT co.*, p.nombre AS plan_nombre, (SELECT COUNT(*) FROM comunicacion_destinatarios d WHERE d.comunicacion_id = co.id) AS total_dest
    FROM comunicaciones co LEFT JOIN planificacion_cursos p ON p.id = co.planificacion_id
    WHERE co.estado = 'programada' ORDER BY co.programada_para")->fetchAll(PDO::FETCH_ASSOC);
$paraHoy = array_filter(cengi_pp_recordatorios($db, cengi_pp_sumar_dias($hoy, -7), $hoy), static function ($x) {
    return !$x['hecho'] && $x['destinatarios'];
});
$vencidasManual = array_filter($programadasManual, static function ($c) {
    return strtotime($c['programada_para']) <= time();
});
$pendientesAgenda = array_filter($agenda, static function ($x) {
    return !$x['hecho'];
});
$reglas = cengi_pp_reglas($db);
$planesAprobados = $db->query("SELECT p.id, p.anio, p.nombre, p.estado, p.fecha_inicio, p.fecha_fin, c.codigo_curso FROM planificacion_cursos p
    LEFT JOIN cursos c ON c.id = p.curso_id WHERE p.estado IN ('aprobado', 'reprogramado') ORDER BY p.anio DESC, p.fecha_inicio")->fetchAll(PDO::FETCH_ASSOC);
$gruposAprobados = [];
foreach ($planesAprobados as $p) {
    $gruposAprobados[(int) $p['anio']][] = $p;
}

$agendaPorDia = [];
foreach ($agenda as $item) {
    $agendaPorDia[$item['fecha_envio']][] = ['tipo' => 'regla', 'item' => $item];
}
foreach ($programadasManual as $c) {
    $agendaPorDia[substr($c['programada_para'], 0, 10)][] = ['tipo' => 'manual', 'item' => $c];
}
ksort($agendaPorDia);

// "Nueva comunicación" abierta desde otra sección (?nueva=1&plan=ID): audiencia del curso ya elegida.
if (!$reabrir && !empty($_GET['nueva'])) {
    $reabrir = ['nueva' => array_filter(['audiencia' => $planFiltro ? 'curso' : '', 'plan_id' => $planFiltro ?: ''])];
}
$filtrosEnviadas = array_filter(['grupo' => $grupoFiltro, 'q' => $busqueda, 'categoria' => $categoriaFiltro, 'estado' => $estadoFiltro, 'periodo' => $periodo, 'plan' => $planFiltro ?: '']);
// Conteo del historial por área (para los chips) y categorías del área elegida (para el selector).
$conteoGrupos = array_fill_keys(array_merge(array_keys(CENGI_COM_GRUPOS), ['otras']), 0);
foreach ($categorias as $c => $n) {
    $conteoGrupos[cengi_com_grupo($c)] += (int) $n;
}
$categoriasVista = $grupoFiltro === '' ? $categorias : array_filter($categorias, static function ($c) use ($grupoFiltro) {
    return cengi_com_grupo($c) === $grupoFiltro;
}, ARRAY_FILTER_USE_KEY);
$inscritosPorAvisar = array_sum(array_column($avisosPorCurso, 'inscritos'));
$paraHoyTotal = count($paraHoy) + count($vencidasManual);
$urlCom = static function (array $cambios) use ($filtrosEnviadas) {
    $q = array_filter(array_merge($filtrosEnviadas, $cambios), static function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'comunicaciones.php' . ($q ? '?' . http_build_query($q) : '');
};
$hayFiltros = (bool) $filtrosEnviadas;
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Comunicaciones'); ?>
    <link rel="stylesheet" href="css/comunicaciones.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/comunicaciones.css') ?: 1); ?>">
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page com-page">
    <?php cengi_banner(); ?>
    <?php cengi_pp_subnav('comunicaciones.php'); ?>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <header class="com-portada">
        <div><span class="com-eyebrow">CENTRO DE COMUNICACIONES</span><h1>Comunicaciones</h1><p>Gestiona los mensajes, avisos de cambios y recordatorios desde un solo lugar.</p></div>
        <button type="button" class="btn btn-primary com-nueva" data-toggle="modal" data-target="#nueva-modal"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Nueva comunicación</button>
    </header>

    <?php /* Lo que requiere atención primero; cada indicador lleva a donde se resuelve. */ ?>
    <div class="cengi-kpi-grid com-kpis">
        <a href="comunicaciones.php?tab=avisar"><?php echo cengi_pp_kpi(count($avisosPorCurso), 'Cursos por avisar', $avisosPorCurso ? $inscritosPorAvisar . ' inscrito(s) sin aviso de cambios' : 'Todos los cambios comunicados', $avisosPorCurso ? '#B34E00' : '#5B6459', 'bullhorn', $avisosPorCurso ? '#FFE9D9' : '#EDEFEA'); ?></a>
        <a href="comunicaciones.php?tab=programados"><?php echo cengi_pp_kpi($paraHoyTotal, 'Para enviar hoy', (count($pendientesAgenda) + count($programadasManual)) . ' programado(s) en 14 días', $paraHoyTotal ? '#8A6600' : '#5B6459', 'bell', $paraHoyTotal ? '#FFF6DA' : '#EDEFEA'); ?></a>
        <a href="<?php echo cengi_pp_h('comunicaciones.php?estado=fallos'); ?>"><?php echo cengi_pp_kpi((int) $kpi['con_fallos'], 'Con envíos fallidos', (int) $kpi['fallidos'] . ' correo(s) sin entregar', $kpi['con_fallos'] ? '#B23223' : '#5B6459', 'alert', $kpi['con_fallos'] ? '#FBE3E0' : '#EDEFEA'); ?></a>
        <a href="<?php echo cengi_pp_h('comunicaciones.php?periodo=30'); ?>"><?php echo cengi_pp_kpi((int) $kpi['mes'], 'Enviadas este mes', (int) $kpi['correos_mes'] . ' destinatario(s) · ' . (int) $kpi['total'] . ' en total', '#3E7A12', 'envelope', '#F4FAD9'); ?></a>
    </div>

    <?php if ($paraHoy || $vencidasManual): ?>
        <div class="com-hoy">
            <span class="com-hoy-icono"><span class="glyphicon glyphicon-bell"></span></span>
            <div><b><?php echo count($paraHoy) + count($vencidasManual); ?> envío(s) pendiente(s) para hoy</b>
                <span>Se envían solos cada mañana con la tarea programada (<code>cron_comunicaciones.php</code>); también puedes enviarlos ahora.</span></div>
            <form method="post"><input type="hidden" name="accion" value="ejecutar"><button type="submit" class="btn btn-primary btn-sm" onclick="this.disabled=true;this.form.submit();"><span class="glyphicon glyphicon-send"></span> Enviar pendientes de hoy</button></form>
        </div>
    <?php endif; ?>

    <section class="pp-card com-lista">
        <div class="com-cabecera">
            <nav class="pp-tabs" aria-label="Secciones de comunicaciones">
                <?php foreach ([
                    'enviadas' => ['Historial', 'envelope', (int) $kpi['total'], false],
                    'avisar' => ['Por avisar', 'bullhorn', count($avisosPorCurso), (bool) $avisosPorCurso],
                    'programados' => ['Programados', 'time', count($pendientesAgenda) + count($programadasManual), $paraHoyTotal > 0],
                    'reglas' => ['Recordatorios automáticos', 'cog', count(array_filter($reglas, static function ($r) {
                        return (int) $r['activo'];
                    })) . '/' . count($reglas), false],
                ] as $clave => [$texto, $icono, $n, $alerta]): ?>
                    <a href="comunicaciones.php<?php echo $clave === 'enviadas' ? '' : '?tab=' . $clave; ?>" class="<?php echo $tab === $clave ? 'is-active' : ''; ?>"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span> <?php echo $texto; ?> <span class="pp-chip<?php echo $alerta ? ' is-warn' : ''; ?>"><?php echo $n; ?></span></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <?php if ($tab === 'enviadas'): ?>
            <nav class="com-grupos" aria-label="Áreas de comunicación">
                <a href="<?php echo cengi_pp_h($urlCom(['grupo' => '', 'categoria' => '', 'p' => ''])); ?>" class="<?php echo $grupoFiltro === '' ? 'is-active' : ''; ?>"><span class="glyphicon glyphicon-th-large"></span> Todas <b><?php echo (int) $kpi['total']; ?></b></a>
                <?php foreach (CENGI_COM_GRUPOS + ['otras' => ['Otras', 'option-horizontal', []]] as $clave => [$texto, $icono]):
                    if (!$conteoGrupos[$clave] && $grupoFiltro !== $clave) { continue; } ?>
                    <a href="<?php echo cengi_pp_h($urlCom(['grupo' => $clave, 'categoria' => '', 'p' => ''])); ?>" class="<?php echo $grupoFiltro === $clave ? 'is-active' : ''; ?>"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span> <?php echo $texto; ?> <b><?php echo $conteoGrupos[$clave]; ?></b></a>
                <?php endforeach; ?>
            </nav>
            <form class="ses-filtros-form com-filtros" method="get" role="search">
                <?php if ($planFiltro): ?><input type="hidden" name="plan" value="<?php echo $planFiltro; ?>"><?php endif; ?>
                <?php if ($grupoFiltro !== ''): ?><input type="hidden" name="grupo" value="<?php echo cengi_pp_h($grupoFiltro); ?>"><?php endif; ?>
                <label class="ses-campo is-buscar"><span>Buscar</span>
                    <span class="ses-buscar"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Asunto, curso, nombre o correo del destinatario..."></span></label>
                <label class="ses-campo"><span>Tipo</span>
                    <select name="categoria" onchange="this.form.submit()">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($categoriasVista as $c => $n): ?><option value="<?php echo cengi_pp_h($c); ?>" <?php echo $categoriaFiltro === $c ? 'selected' : ''; ?>><?php echo cengi_pp_h($c . ' (' . $n . ')'); ?></option><?php endforeach; ?>
                    </select></label>
                <label class="ses-campo"><span>Resultado</span>
                    <select name="estado" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="ok" <?php echo $estadoFiltro === 'ok' ? 'selected' : ''; ?>>Entregadas sin fallos</option>
                        <option value="fallos" <?php echo $estadoFiltro === 'fallos' ? 'selected' : ''; ?>>Con fallos</option>
                    </select></label>
                <label class="ses-campo"><span>Período</span>
                    <select name="periodo" onchange="this.form.submit()">
                        <option value="">Todo</option>
                        <?php foreach (['7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días'] as $v => $t): ?><option value="<?php echo $v; ?>" <?php echo $periodo === $v ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?>
                    </select></label>
                <div class="ses-filtros-fin">
                    <span class="ses-total"><b><?php echo $totalLista; ?></b> comunicación(es)</span>
                    <?php if ($hayFiltros): ?><a class="ses-limpiar" href="comunicaciones.php"><span class="glyphicon glyphicon-remove"></span>Quitar filtros</a><?php endif; ?>
                    <a class="btn btn-default btn-sm" href="<?php echo cengi_pp_h($urlCom(['exportar' => 'excel'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Excel</a>
                    <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-search" aria-hidden="true"></span> Buscar</button>
                </div>
            </form>
            <?php if ($planFiltro): ?>
                <div class="com-filtro-curso"><span class="glyphicon glyphicon-education"></span>Mostrando solo las comunicaciones de <b><?php echo cengi_pp_h($planFiltroNombre ?: 'este curso'); ?></b>.
                    <a href="<?php echo cengi_pp_h($urlCom(['plan' => '', 'p' => ''])); ?>">Ver todas</a> · <a href="comunicaciones.php?nueva=1&amp;plan=<?php echo $planFiltro; ?>">Escribir a sus participantes</a></div>
            <?php endif; ?>

            <div class="com-items">
                <?php if ($lista): ?><div class="com-columnas" aria-hidden="true"><span>Mensaje y destinatarios</span><span>Hora</span><span>Entrega</span><span>Acciones</span></div><?php endif; ?>
                <?php if (!$lista): ?><div class="com-vacio"><span class="glyphicon glyphicon-envelope"></span><b><?php echo $kpi['total'] ? 'Ninguna comunicación coincide con los filtros' : 'Aún no se han enviado comunicaciones'; ?></b><span><?php echo $kpi['total'] ? 'Prueba con otra categoría, período o búsqueda.' : 'Usa “Nueva comunicación” para escribir a los participantes de un curso.'; ?></span></div><?php endif; ?>
                <?php $diaActual = null; foreach ($lista as $n):
                    $dest = $destPorCom[$n['id']] ?? [];
                    $fecha = $n['enviado_en'] ?: $n['creado'];
                    [$icono, $tono] = CENGI_COM_CATEGORIAS[$n['categoria']] ?? ['envelope', 'is-gris'];
                    $total = count($dest);
                    $ok = (int) $n['enviados'];
                    $fallos = (int) $n['fallidos'];
                    $enlace = cengi_com_enlace($n);
                    if (substr($fecha, 0, 10) !== $diaActual): $diaActual = substr($fecha, 0, 10); ?>
                        <div class="com-dia"><?php echo cengi_pp_h($diaActual === $hoy ? 'Hoy · ' . cengi_pp_fecha_larga($diaActual) : ($diaActual === cengi_pp_sumar_dias($hoy, -1) ? 'Ayer · ' . cengi_pp_fecha_larga($diaActual) : ucfirst(cengi_pp_fecha_larga($diaActual)))); ?></div>
                    <?php endif; ?>
                    <article class="com-item<?php echo ($fallos || $n['estado'] === 'fallida') ? ' is-fallo' : ''; ?>">
                        <span class="com-icono <?php echo $tono; ?>"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span></span>
                        <div class="com-cuerpo">
                            <div class="com-linea1"><b class="com-asunto"><?php echo cengi_pp_h($n['asunto'] ?: '(sin asunto)'); ?></b></div>
                            <div class="com-meta">
                                <a class="com-cat" href="<?php echo cengi_pp_h($urlCom(['categoria' => $n['categoria'], 'p' => ''])); ?>"><?php echo cengi_pp_h($n['categoria']); ?></a>
                                <?php if ($n['plan_nombre']): ?><a href="<?php echo cengi_pp_h($urlCom(['plan' => (int) $n['planificacion_id'], 'p' => ''])); ?>" title="Ver las comunicaciones de este curso"><span class="glyphicon glyphicon-education"></span><?php echo cengi_pp_h($n['plan_nombre']); ?></a><?php elseif ($n['origen'] === 'sistema'): ?><span>Enviado automáticamente por Cengicursos</span><?php endif; ?>
                                <span><span class="glyphicon glyphicon-user"></span><?php echo $total === 1 ? cengi_pp_h($dest[0]['nombre'] ?: $dest[0]['correo']) : $total . ' destinatario(s)'; ?></span>
                                <?php if ($n['creado_por_nombre'] && $n['origen'] !== 'sistema'): ?><span>por <?php echo cengi_pp_h($n['creado_por_nombre']); ?></span><?php endif; ?>
                            </div>
                            <?php if ($total > 1): ?><span class="com-barra" title="<?php echo $ok . ' entregado(s) · ' . $fallos . ' fallido(s)'; ?>"><span class="is-ok" style="width:<?php echo round($ok * 100 / $total, 1); ?>%"></span><span class="is-fallo" style="width:<?php echo round($fallos * 100 / $total, 1); ?>%"></span></span><?php endif; ?>
                        </div>
                        <time class="com-hora" datetime="<?php echo cengi_pp_h(date('c', strtotime($fecha))); ?>"><?php echo date('H:i', strtotime($fecha)); ?></time>
                        <div class="com-estado">
                            <?php echo $n['estado'] === 'fallida' ? '<span class="cengi-status-badge is-rejected"><i></i>No se entregó</span>' : ($fallos ? '<span class="cengi-status-badge is-pending"><i></i>' . $ok . ' de ' . $total . ' entregados</span>' : '<span class="cengi-status-badge is-active"><i></i>' . ($total > 1 ? 'Entregada a ' . $total : 'Entregada') . '</span>'); ?>
                            <div class="com-acciones dropdown com-menu">
                                <button type="button" class="btn btn-default btn-sm dropdown-toggle com-menu-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Acciones del mensaje <?php echo cengi_pp_h($n['asunto'] ?: '(sin asunto)'); ?>">Acciones <span class="caret"></span></button>
                                <div class="dropdown-menu dropdown-menu-right com-menu-opciones">
                                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ver-modal" data-com="<?php echo cengi_pp_h(json_encode([
                                    'asunto' => $n['asunto'], 'mensaje' => $n['mensaje'], 'sub' => date('d/m/Y H:i', strtotime($fecha)) . ' · ' . $n['categoria'] . ($n['creado_por_nombre'] ? ' · por ' . $n['creado_por_nombre'] : ''), 'dest' => $dest,
                                ], JSON_UNESCAPED_UNICODE)); ?>"><span class="glyphicon glyphicon-eye-open"></span> Ver</button>
                                <?php if (cengi_com_reintentable($n)): ?>
                                    <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Volver a enviar a los <?php echo $fallos; ?> destinatario(s) que fallaron?');"><input type="hidden" name="accion" value="reintentar"><input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                        <button type="submit" class="btn btn-warning btn-sm com-reintentar"><span class="glyphicon glyphicon-repeat"></span> Reintentar <?php echo $fallos; ?></button></form>
                                <?php elseif ($fallos && in_array($n['categoria'], CENGI_COM_NO_REINTENTABLES, true) && $enlace): ?>
                                    <a class="btn btn-link btn-sm" href="<?php echo cengi_pp_h($enlace[0]); ?>" title="Lleva calendario o adjuntos: se reenvía desde su sección">Reenviar desde <?php echo cengi_pp_h($enlace[1]); ?></a>
                                <?php elseif ($enlace): ?>
                                    <a class="btn btn-link btn-sm" href="<?php echo cengi_pp_h($enlace[0]); ?>"><?php echo cengi_pp_h($enlace[1]); ?></a>
                                <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($paginas > 1): ?>
                <nav class="com-paginas" aria-label="Páginas">
                    <a class="<?php echo $pagina <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo cengi_pp_h($urlCom(['p' => max(1, $pagina - 1)])); ?>"><span class="glyphicon glyphicon-chevron-left"></span> Más recientes</a>
                    <span>Página <b><?php echo $pagina; ?></b> de <?php echo $paginas; ?></span>
                    <a class="<?php echo $pagina >= $paginas ? 'is-disabled' : ''; ?>" href="<?php echo cengi_pp_h($urlCom(['p' => min($paginas, $pagina + 1)])); ?>">Anteriores <span class="glyphicon glyphicon-chevron-right"></span></a>
                </nav>
            <?php endif; ?>

        <?php elseif ($tab === 'avisar'): ?>
            <p class="com-intro">Cambios de fechas, horario, lugar, modalidad, instructor o cancelaciones que afectan a los inscritos y aún no se les comunican. Se registran solos en la <a href="bitacora_cambios.php">Bitácora</a>; aquí decides si avisar.</p>
            <?php if (!$avisosPorCurso): ?>
                <div class="com-avisar-vacio"><span class="glyphicon glyphicon-ok-circle"></span><b>No hay cambios por avisar</b><span>Todos los cambios que afectan a los participantes ya se comunicaron.</span></div>
            <?php endif; ?>
            <div class="com-avisar-lista">
                <?php foreach ($avisosPorCurso as $ac): $dias = cengi_pp_dias_entre(substr($ac['desde'], 0, 10), $hoy); ?>
                    <div class="com-avisar<?php echo $dias >= 3 ? ' is-atrasado' : ''; ?>">
                        <div class="com-avisar-info">
                            <b><?php echo cengi_pp_h($ac['nombre']); ?></b>
                            <span class="pp-sub"><?php echo $ac['codigo'] ? '<span class="pp-mono">' . cengi_pp_h($ac['codigo']) . '</span> · ' : ''; ?><?php echo $ac['inscritos']; ?> inscrito(s) · pendiente <?php echo $dias === 0 ? 'desde hoy' : ($dias === 1 ? 'desde ayer' : 'hace ' . $dias . ' días'); ?></span>
                            <ul class="com-avisar-cambios">
                                <?php foreach (array_slice($ac['cambios'], 0, 4) as $c): ?>
                                    <li><b><?php echo cengi_pp_h($c['tipo']); ?></b><?php echo $c['antes'] !== '' || $c['despues'] !== '' ? ': <s>' . cengi_pp_h($c['antes'] ?: '—') . '</s> → ' . cengi_pp_h($c['despues'] ?: '—') : ($c['detalle'] !== '' ? ': ' . cengi_pp_h($c['detalle']) : ''); ?></li>
                                <?php endforeach; ?>
                                <?php if (count($ac['cambios']) > 4): ?><li class="pp-sub">y <?php echo count($ac['cambios']) - 4; ?> cambio(s) más</li><?php endif; ?>
                            </ul>
                        </div>
                        <div class="com-avisar-acciones">
                            <a class="btn btn-primary btn-sm" href="comunicaciones.php?tab=avisar&amp;avisar=<?php echo $ac['id']; ?>"><span class="glyphicon glyphicon-envelope"></span> Avisar a los inscritos</a>
                            <form method="post" class="cengi-inline-form" onsubmit="return confirm('Los <?php echo count($ac['cambios']); ?> cambio(s) de este curso quedarán como “No avisar”. ¿Continuar?');">
                                <input type="hidden" name="accion" value="omitir_cambios"><input type="hidden" name="plan_id" value="<?php echo $ac['id']; ?>">
                                <?php foreach ($ac['cambios'] as $c): ?><input type="hidden" name="cambios[]" value="<?php echo (int) $c['id']; ?>"><?php endforeach; ?>
                                <button type="submit" class="btn btn-link btn-sm">No avisar</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($tab === 'programados'): ?>
            <p class="com-intro">Recordatorios automáticos según las reglas activas y comunicaciones programadas a mano para los próximos 14 días.</p>
            <div class="com-agenda">
                <?php if (!$agendaPorDia): ?><div class="com-vacio"><span class="glyphicon glyphicon-calendar"></span><b>Nada programado en los próximos 14 días</b><span>Con las reglas activas no hay recordatorios por enviar. Puedes programar una comunicación con “Nueva comunicación”.</span></div><?php endif; ?>
                <?php foreach ($agendaPorDia as $dia => $items): ?>
                    <div class="com-agenda-dia<?php echo $dia <= $hoy ? ' is-hoy' : ''; ?>">
                        <div class="com-agenda-fecha"><b><?php echo (int) substr(max($dia, $hoy), 8, 2); ?></b><span><?php echo CENGI_PP_MESES_CORTOS[(int) substr(max($dia, $hoy), 5, 2)]; ?></span><?php echo $dia <= $hoy ? '<em>Hoy</em>' : ''; ?></div>
                        <div class="com-agenda-items">
                            <?php foreach ($items as $entrada): $x = $entrada['item']; ?>
                                <div class="com-agenda-item">
                                    <?php if ($entrada['tipo'] === 'regla'): ?>
                                        <span class="com-icono is-verde2"><span class="glyphicon glyphicon-bell"></span></span>
                                        <div class="com-cuerpo"><b><?php echo cengi_pp_h(trim(str_replace('{nombre}', '', cengi_pp_llenar($x['asunto'], $x['variables'])))); ?></b>
                                            <span class="pp-sub"><?php echo cengi_pp_h($x['regla_nombre'] . ' · ' . $x['actividad']); ?> · <?php echo count($x['destinatarios']); ?> destinatario(s)</span></div>
                                        <div class="com-acciones">
                                            <?php if ($x['hecho']): ?>
                                                <span class="cengi-status-badge is-active"><i></i>Procesado</span>
                                            <?php elseif (!$x['destinatarios']): ?>
                                                <span class="pp-chip">Sin destinatarios</span>
                                            <?php else: ?>
                                                <span class="cengi-status-badge <?php echo $dia <= $hoy ? 'is-pending' : 'is-upcoming'; ?>"><i></i><?php echo $dia <= $hoy ? 'Hoy' : 'Programado'; ?></span>
                                                <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="recordatorio"><input type="hidden" name="regla" value="<?php echo cengi_pp_h($x['regla']); ?>"><input type="hidden" name="referencia" value="<?php echo cengi_pp_h($x['referencia']); ?>">
                                                    <button type="submit" name="modo" value="enviar" class="btn btn-default btn-sm" onclick="return confirm('¿Enviar este recordatorio ahora a <?php echo count($x['destinatarios']); ?> destinatario(s)?');"><span class="glyphicon glyphicon-send"></span> Enviar ahora</button>
                                                    <button type="submit" name="modo" value="omitir" class="btn btn-link btn-sm" onclick="return confirm('¿Omitir este envío? No se enviará en la fecha programada.');">Omitir</button></form>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="com-icono is-deep"><span class="glyphicon glyphicon-time"></span></span>
                                        <div class="com-cuerpo"><b><?php echo cengi_pp_h($x['asunto']); ?></b>
                                            <span class="pp-sub">Comunicación programada · <?php echo date('H:i', strtotime($x['programada_para'])); ?> · <?php echo cengi_pp_h($x['plan_nombre'] ?: 'General'); ?> · <?php echo (int) $x['total_dest']; ?> destinatario(s) · por <?php echo cengi_pp_h($x['creado_por_nombre']); ?></span></div>
                                        <div class="com-acciones">
                                            <span class="cengi-status-badge is-upcoming"><i></i>Programada</span>
                                            <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Cancelar este envío programado?');"><input type="hidden" name="accion" value="cancelar_programada"><input type="hidden" name="id" value="<?php echo (int) $x['id']; ?>"><button type="submit" class="btn btn-link btn-sm">Cancelar</button></form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <p class="com-intro">Activa o desactiva cada recordatorio automático y ajusta cuándo se envía y su texto. Los cambios aplican de inmediato a la agenda de programados.</p>
            <div class="com-reglas">
                <?php foreach ($reglas as $r): ?>
                    <div class="com-regla<?php echo (int) $r['activo'] ? ' is-activa' : ''; ?>">
                        <form method="post" class="cengi-inline-form">
                            <input type="hidden" name="accion" value="regla"><input type="hidden" name="clave" value="<?php echo cengi_pp_h($r['clave']); ?>"><input type="hidden" name="solo_activo" value="1">
                            <label class="pp-switch" title="<?php echo (int) $r['activo'] ? 'Activa: clic para desactivar' : 'Inactiva: clic para activar'; ?>"><input type="checkbox" name="activo" value="1" <?php echo (int) $r['activo'] ? 'checked' : ''; ?> onchange="this.form.submit()" aria-label="Activar <?php echo cengi_pp_h($r['nombre']); ?>"><span></span></label>
                        </form>
                        <div class="com-regla-info">
                            <b><?php echo cengi_pp_h($r['nombre']); ?></b> <span class="com-regla-estado"><?php echo (int) $r['activo'] ? 'Activa' : 'Inactiva'; ?></span>
                            <span class="pp-sub"><?php echo cengi_pp_h($r['descripcion']); ?></span>
                            <span class="com-regla-cuando"><span class="glyphicon glyphicon-time"></span>Se envía <b><?php echo (int) $r['dias_antes']; ?> día(s) antes</b> · Asunto: <i><?php echo cengi_pp_h($r['asunto']); ?></i></span>
                        </div>
                        <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#regla-modal" data-regla="<?php echo cengi_pp_h(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>"><span class="glyphicon glyphicon-pencil"></span> Editar mensaje</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php if ($aviso): ?>
<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="aviso-modal" tabindex="-1" role="dialog" aria-labelledby="aviso-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="aviso-form">
            <input type="hidden" name="plan_id" value="<?php echo (int) $aviso['plan']['id']; ?>">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Avisar cambios a los inscritos</span>
                <h4 class="modal-title" id="aviso-title"><?php echo cengi_pp_h($aviso['plan']['nombre']); ?></h4>
                <p><?php echo count($aviso['inscritos']); ?> inscrito(s) · cada persona recibe un correo personalizado</p>
            </div>
            <div class="modal-body">
                <div class="pp-two-col" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label>Cambios a comunicar</label>
                        <div class="pp-check-list" id="av-cambios">
                            <?php foreach ($aviso['pendientes'] as $c): ?>
                                <label><input type="checkbox" name="cambios[]" value="<?php echo (int) $c['id']; ?>" checked>
                                    <span><b><?php echo cengi_pp_h($c['tipo']); ?></b> · <?php echo date('d/m H:i', strtotime($c['creado'])); ?><br><span class="pp-sub"><?php echo cengi_pp_h($c['detalle'] . ($c['antes'] !== '' || $c['despues'] !== '' ? ': ' . $c['antes'] . ' → ' . $c['despues'] : '')); ?></span></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group"><label>Destinatarios</label>
                        <div class="pp-check-list" id="av-dest">
                            <?php foreach ($aviso['inscritos'] as $i): ?>
                                <label><input type="checkbox" name="destinatarios[]" value="<?php echo cengi_pp_h($i['clave']); ?>" <?php echo filter_var($i['correo'], FILTER_VALIDATE_EMAIL) ? 'checked' : 'disabled'; ?>>
                                    <span><?php echo cengi_pp_h($i['nombre']); ?><br><span class="pp-sub"><?php echo cengi_pp_h(($i['correo'] ?: 'Sin correo registrado') . ($i['asignado'] ? '' : ' · inscrito, aún sin asignar')); ?></span></span></label>
                            <?php endforeach; ?>
                            <?php if (!$aviso['inscritos']): ?><label><span class="pp-sub">No hay inscritos.</span></label><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label for="av-asunto">Asunto</label><input type="text" class="form-control" id="av-asunto" name="asunto" maxlength="255" value="<?php echo cengi_pp_h($aviso['asunto']); ?>" required></div>
                <div class="form-group"><label for="av-mensaje">Mensaje <small>({nombre} se reemplaza por el nombre de cada persona)</small></label><textarea class="form-control" id="av-mensaje" name="mensaje" rows="10" required></textarea></div>
                <?php if ($aviso['aula']): ?>
                    <label class="com-aviso-aula"><input type="checkbox" name="publicar_aula" value="1" checked>
                        <span><b>Publicar también como anuncio en el aula del curso</b><span class="pp-sub">El estudiante lo verá en Mi aula, arriba de su resumen.</span></span></label>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Más tarde</button>
                <button type="submit" class="btn btn-primary" name="accion" value="avisar_cambios" id="av-btn">Enviar</button>
            </div>
        </form>
    </div></div>
</div>
<script>
(function () {
    'use strict';
    var PLAN = <?php echo json_encode(['nombre' => $aviso['plan']['nombre'], 'codigo' => $aviso['plan']['codigo_curso'], 'cancelado' => $aviso['plan']['estado'] === 'cancelado', 'vigente' => $aviso['vigente']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var CAMBIOS = <?php echo json_encode(array_column(array_map(static function ($c) {
        return ['id' => (int) $c['id'], 'texto' => '• ' . $c['tipo'] . ' — ' . $c['detalle'] . ($c['antes'] !== '' || $c['despues'] !== '' ? ': ' . ($c['antes'] ?: '—') . ' → ' . ($c['despues'] ?: '—') : '') . '.' . ($c['motivo'] !== '' ? "\n  Motivo: " . $c['motivo'] : '')];
    }, $aviso['pendientes']), 'texto', 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    function regenerar() {
        var sel = [].filter.call(document.querySelectorAll('#av-cambios input'), function (c) { return c.checked; }).map(function (c) { return CAMBIOS[c.value]; });
        // La firma la agrega la plantilla del correo.
        document.getElementById('av-mensaje').value = sel.length ? 'Hola {nombre}:\n\nTe informamos ' + (sel.length > 1 ? 'los siguientes cambios' : 'un cambio') + ' en ' + PLAN.nombre + (PLAN.codigo ? ' (' + PLAN.codigo + ')' : '') + ':\n' + sel.join('\n') + '\n\n' +
            (PLAN.cancelado ? 'Si realizaste un pago, se reintegrará en los próximos 10 días hábiles.' : 'Programación vigente:\n' + PLAN.vigente) : '';
    }
    function contar() {
        var n = document.querySelectorAll('#av-dest input:checked').length, b = document.getElementById('av-btn');
        b.textContent = n ? 'Enviar a ' + n + ' participante(s)' : 'Enviar';
        b.disabled = !n;
    }
    document.querySelectorAll('#av-cambios input').forEach(function (c) { c.addEventListener('change', regenerar); });
    document.querySelectorAll('#av-dest input').forEach(function (c) { c.addEventListener('change', contar); });
    regenerar(); contar();
    document.getElementById('aviso-form').addEventListener('submit', function () { var b = document.getElementById('av-btn'); setTimeout(function () { b.disabled = true; b.textContent = 'Enviando…'; }, 0); });
    $('#aviso-modal').modal('show');
})();
</script>
<?php endif; ?>

<div class="modal fade cengi-course-form-modal pp-modal" id="ver-modal" tabindex="-1" role="dialog" aria-labelledby="ver-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Comunicación</span><h4 class="modal-title" id="ver-title"></h4><p id="ver-sub"></p></div>
        <div class="modal-body">
            <div id="ver-msg-wrap"><h5 class="com-modal-t">Mensaje <small id="ver-msg-nota"></small></h5><div class="pp-msgbox com-mensaje" id="ver-msg"></div></div>
            <div class="com-modal-dest-head"><h5 class="com-modal-t" id="ver-dest-t"></h5><button type="button" class="btn btn-link btn-sm" id="ver-copiar"><span class="glyphicon glyphicon-copy"></span> Copiar correos</button></div>
            <div id="ver-dest" class="com-dest-lista"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal" id="regla-modal" tabindex="-1" role="dialog" aria-labelledby="regla-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post">
            <input type="hidden" name="accion" value="regla"><input type="hidden" name="clave" id="rg-clave">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Plantilla de recordatorio</span><h4 class="modal-title" id="regla-title"></h4></div>
            <div class="modal-body">
                <div class="form-group"><label for="rg-dias">Días antes de la fecha</label><input type="number" class="form-control" id="rg-dias" name="dias_antes" min="0" max="30" style="max-width:120px;"></div>
                <div class="form-group"><label for="rg-asunto">Asunto</label><input type="text" class="form-control" id="rg-asunto" name="asunto" maxlength="255" required></div>
                <div class="form-group"><label for="rg-mensaje">Mensaje</label><textarea class="form-control" id="rg-mensaje" name="mensaje" rows="9" required></textarea>
                    <div class="com-variables" data-para="rg-mensaje"><span class="pp-sub">Insertar:</span><?php foreach (['{nombre}', '{curso}', '{codigo}', '{fecha}', '{horario}', '{modalidad}', '{tema}', '{lugar}'] as $v): ?><button type="button" data-variable="<?php echo $v; ?>"><?php echo $v; ?></button><?php endforeach; ?></div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar plantilla</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="nueva-modal" tabindex="-1" role="dialog" aria-labelledby="nueva-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="nueva-form" autocomplete="off">
            <input type="hidden" name="accion" value="nueva">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Comunicaciones</span><h4 class="modal-title" id="nueva-title">Nueva comunicación</h4></div>
            <div class="modal-body">
                <div class="pp-form-msg" id="nv-msg"></div>
                <div class="com-nueva-grid">
                    <div>
                        <div class="cengi-course-modal-grid">
                            <div class="form-group"><label for="nv-aud">Destinatarios</label>
                                <select class="form-control" id="nv-aud" name="audiencia">
                                    <option value="curso">Participantes asignados a un curso</option>
                                    <option value="instructores_curso">Instructores de un curso</option>
                                    <option value="instructores">Todos los instructores activos</option>
                                    <option value="soporte">Personal de soporte técnico</option>
                                    <option value="manual">Correos escritos a mano</option>
                                </select></div>
                            <div class="form-group" id="nv-plan-wrap"><label for="nv-plan">Curso</label>
                                <select class="form-control" id="nv-plan" name="plan_id"><?php echo $gruposAprobados ? cengi_pp_options_planes($gruposAprobados, '') : '<option value="">No hay cursos aprobados</option>'; ?></select></div>
                            <div class="form-group is-full" id="nv-manual-wrap" hidden><label for="nv-manual">Correos <small>(separados por coma o salto de línea)</small></label><textarea class="form-control" id="nv-manual" name="manual" rows="2"></textarea></div>
                            <div class="form-group is-full"><label for="nv-asunto">Asunto</label><input type="text" class="form-control" id="nv-asunto" name="asunto" maxlength="255" required></div>
                            <div class="form-group is-full"><label for="nv-mensaje">Mensaje</label><textarea class="form-control" id="nv-mensaje" name="mensaje" rows="9" required>Hola {nombre}:

</textarea>
                                <div class="com-variables" data-para="nv-mensaje"><span class="pp-sub">Insertar:</span><?php foreach (['{nombre}', '{curso}', '{codigo}', '{fecha}', '{horario}', '{modalidad}'] as $v): ?><button type="button" data-variable="<?php echo $v; ?>"><?php echo $v; ?></button><?php endforeach; ?></div>
                                <p class="help-block">{curso}, {codigo}, {fecha}, {horario} y {modalidad} se llenan cuando eliges un curso.</p></div>
                            <div class="form-group"><label for="nv-cuando">Envío</label><select class="form-control" id="nv-cuando" name="cuando"><option value="ahora">Enviar ahora</option><option value="programar">Programar para una fecha</option></select></div>
                            <div class="form-group" id="nv-fecha-wrap" hidden><label for="nv-fecha">Fecha y hora</label><input type="datetime-local" class="form-control" id="nv-fecha" name="programada_para"></div>
                        </div>
                    </div>
                    <div class="com-previa">
                        <span class="com-previa-t">Vista previa</span>
                        <div class="com-previa-correo">
                            <div class="com-previa-banda">CENGICAÑA · Plataforma de Cursos</div>
                            <b id="nv-previa-asunto">(sin asunto)</b>
                            <div id="nv-previa-texto"></div>
                            <div class="com-previa-firma">Coordinación de Capacitación — CENGICAÑA</div>
                        </div>
                        <span class="pp-sub">Ejemplo con datos de muestra; cada persona recibe su nombre.</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="nv-btn">Enviar</button></div>
        </form>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var REABRIR = <?php echo json_encode($reabrir, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var ERROR = <?php echo json_encode($mensajeTipo === 'error' ? $mensaje : '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PLANES = <?php echo json_encode(array_column(array_map(static function ($p) {
        return ['id' => (int) $p['id'], 'curso' => $p['nombre'], 'codigo' => (string) $p['codigo_curso'], 'fecha' => cengi_pp_fecha($p['fecha_inicio']) . ' al ' . cengi_pp_fecha($p['fecha_fin'])];
    }, $planesAprobados), null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    function $id(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    $('#ver-modal').on('show.bs.modal', function (e) {
        var c = JSON.parse(e.relatedTarget.getAttribute('data-com')), primero = c.dest[0] ? (c.dest[0].nombre || 'participante') : 'participante';
        $id('ver-title').textContent = c.asunto || '(sin asunto)'; $id('ver-sub').textContent = c.sub;
        $id('ver-msg-wrap').hidden = !c.mensaje;
        $id('ver-msg-nota').textContent = c.dest.length > 1 ? '(como lo recibió ' + primero + ')' : '';
        $id('ver-msg').textContent = (c.mensaje || '').replace(/\{nombre\}/g, primero);
        var ok = c.dest.filter(function (d) { return d.estado === 'enviado'; }).length;
        $id('ver-dest-t').textContent = 'Destinatarios (' + c.dest.length + ')' + (c.dest.length ? ' · ' + ok + ' entregado(s)' : '');
        $id('ver-dest').innerHTML = c.dest.length ? c.dest.map(function (d) {
            return '<div class="com-dest' + (d.estado === 'fallido' ? ' is-fallo' : '') + '"><span><b>' + esc(d.nombre || d.correo) + '</b><span class="pp-sub">' + esc(d.correo) + (d.error ? ' · ' + esc(d.error) : '') + '</span></span><span class="pp-chip ' + (d.estado === 'enviado' ? 'is-ok' : d.estado === 'fallido' ? 'is-danger' : '') + '">' + esc(d.estado) + '</span></div>';
        }).join('') : '<p class="pp-sub">Sin destinatarios registrados.</p>';
        $id('ver-copiar').hidden = !c.dest.length;
        $id('ver-copiar').onclick = function () {
            var texto = c.dest.map(function (d) { return d.correo; }).join(', '), b = this;
            var listo = function () { b.innerHTML = '<span class="glyphicon glyphicon-ok"></span> Copiados'; setTimeout(function () { b.innerHTML = '<span class="glyphicon glyphicon-copy"></span> Copiar correos'; }, 1500); };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(texto).then(listo, function () { window.prompt('Correos:', texto); }); } else { window.prompt('Correos:', texto); }
        };
    });
    $('#regla-modal').on('show.bs.modal', function (e) {
        if (!e.relatedTarget) return;
        var r = JSON.parse(e.relatedTarget.getAttribute('data-regla'));
        $id('regla-title').textContent = r.nombre; $id('rg-clave').value = r.clave; $id('rg-dias').value = r.dias_antes;
        $id('rg-asunto').value = r.asunto; $id('rg-mensaje').value = r.mensaje;
    });

    // Insertar una variable en la posición del cursor.
    document.querySelectorAll('.com-variables').forEach(function (caja) {
        caja.addEventListener('click', function (e) {
            var b = e.target.closest('[data-variable]'); if (!b) return;
            var t = $id(caja.getAttribute('data-para')), v = b.getAttribute('data-variable'), i = t.selectionStart || t.value.length;
            t.value = t.value.slice(0, i) + v + t.value.slice(t.selectionEnd || i);
            t.focus(); t.selectionStart = t.selectionEnd = i + v.length;
            t.dispatchEvent(new Event('input'));
        });
    });

    function audiencia() {
        var v = $id('nv-aud').value;
        $id('nv-plan-wrap').hidden = v !== 'curso' && v !== 'instructores_curso';
        $id('nv-manual-wrap').hidden = v !== 'manual';
        previa();
    }
    function cuando() {
        var prog = $id('nv-cuando').value === 'programar';
        $id('nv-fecha-wrap').hidden = !prog; $id('nv-fecha').required = prog;
        $id('nv-btn').innerHTML = prog ? '<span class="glyphicon glyphicon-time"></span> Programar' : '<span class="glyphicon glyphicon-send"></span> Enviar';
    }
    // Vista previa con datos de muestra (los reales los pone el servidor al enviar).
    function previa() {
        var v = $id('nv-aud').value, p = (v === 'curso' || v === 'instructores_curso') ? PLANES[$id('nv-plan').value] : null;
        var datos = { '{nombre}': 'Ana Pérez', '{curso}': p ? p.curso : '{curso}', '{codigo}': p ? (p.codigo || '—') : '{codigo}', '{fecha}': p ? p.fecha : '{fecha}', '{horario}': p ? 'según la programación' : '{horario}', '{modalidad}': p ? 'según el curso' : '{modalidad}' };
        var llenar = function (t) { return String(t || '').replace(/\{[a-z]+\}/g, function (m) { return datos[m] !== undefined ? datos[m] : m; }); };
        $id('nv-previa-asunto').textContent = llenar($id('nv-asunto').value) || '(sin asunto)';
        $id('nv-previa-texto').textContent = llenar($id('nv-mensaje').value);
    }
    $id('nv-aud').addEventListener('change', audiencia);
    $id('nv-plan').addEventListener('change', previa);
    $id('nv-cuando').addEventListener('change', cuando);
    ['nv-asunto', 'nv-mensaje'].forEach(function (id) { $id(id).addEventListener('input', previa); });
    $id('nueva-form').addEventListener('submit', function () { var b = $id('nv-btn'); setTimeout(function () { b.disabled = true; }, 0); });
    if (REABRIR && REABRIR.nueva) {
        var d = REABRIR.nueva;
        ['aud:audiencia', 'plan:plan_id', 'manual:manual', 'asunto:asunto', 'mensaje:mensaje', 'cuando:cuando', 'fecha:programada_para'].forEach(function (par) {
            var p = par.split(':'); if (d[p[1]] !== undefined) $id('nv-' + p[0]).value = d[p[1]];
        });
        audiencia(); cuando();
        if (ERROR) { $id('nv-msg').className = 'pp-form-msg is-visible is-error'; $id('nv-msg').innerHTML = ERROR; }
        $('#nueva-modal').modal('show');
    }
    if (REABRIR && REABRIR.clave) {
        $id('regla-title').textContent = 'Editar plantilla'; $id('rg-clave').value = REABRIR.clave; $id('rg-dias').value = REABRIR.dias_antes;
        $id('rg-asunto').value = REABRIR.asunto; $id('rg-mensaje').value = REABRIR.mensaje;
        $('#regla-modal').modal('show');
    }
    audiencia(); cuando();
})();
</script>
</body>
</html>
