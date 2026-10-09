<?php
/**
 * Informe de gestión de capacitación (prototipo SIGEC v29, "Informe de gestión"):
 * cursos/diplomados y eventos de un período con participación, resultados académicos,
 * percepción, cobertura por institución y balance financiero, para la Dirección.
 *
 * Fuentes: planificacion_cursos / cursos (creados sin planificación), solicitudes_inscripcion
 * (+ pagos_grupales), asignaciones + control_cursos, curso_sesiones (+ tarifas),
 * evaluaciones_instructor, eventos / evento_participantes, encuestas y acuerdos de
 * colaboración, diplomas y gestion_gastos (migrations/20261013_informe_gestion_gastos.sql).
 */
require_once __DIR__ . '/inscripcion_helpers.php';
require_once __DIR__ . '/eventos_acuerdo_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_ver_reportes();
// La Dirección e instituciones consultan; solo el administrador registra o quita gastos.
$puedeEditarGastos = cengi_es_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';

const CENGI_IG_CATEGORIAS = ['Alimentación', 'Materiales', 'Logística', 'Transporte', 'Alquiler de equipo', 'Publicidad', 'Honorarios de ponentes', 'Otro'];
const CENGI_IG_TIPOS = ['plan' => 'Curso', 'curso' => 'Curso', 'evento' => 'Evento'];
const CENGI_IG_MENSAJES = ['gasto' => 'Gasto registrado.', 'gasto-quitado' => 'Gasto quitado.'];
const CENGI_IG_CRITERIOS = ['instructor_lenguaje_claro', 'instructor_material_adecuado', 'instructor_conocimiento_tema', 'instructor_respeto_participantes', 'instructor_puntualidad_objetivos'];
const CENGI_IG_APRUEBA = CENGI_NOTA_APROBACION; // nota final minima (cengi_texto.php)

// ---------------------------------------------------------------- utilidades

/** Periodos rapidos: [desde, hasta, etiqueta]. Por año calendario y semestre. */
function cengi_ig_presets()
{
    $y = (int) date('Y');
    return [
        'anio' => ["$y-01-01", "$y-12-31", 'Año ' . $y],
        'anio-ant' => [($y - 1) . '-01-01', ($y - 1) . '-12-31', 'Año ' . ($y - 1)],
        'sem1' => ["$y-01-01", "$y-06-30", 'Primer semestre ' . $y],
        'sem2' => ["$y-07-01", "$y-12-31", 'Segundo semestre ' . $y],
    ];
}

function cengi_ig_prom(array $valores)
{
    $valores = array_values(array_filter($valores, static function ($v) {
        return $v !== null;
    }));
    return $valores ? array_sum($valores) / count($valores) : null;
}

function cengi_ig_num($valor)
{
    return is_numeric($valor) ? (float) $valor : null;
}

/** Misma persona en cursos y eventos: por CUI, si no por correo, si no por nombre. */
function cengi_ig_clave($cui, $correo, $nombre)
{
    $digitos = preg_replace('/\D/', '', (string) $cui);
    if (strlen($digitos) >= 8) {
        return 'cui:' . $digitos;
    }
    $correo = mb_strtolower(trim((string) $correo), 'UTF-8');
    return $correo !== '' ? 'mail:' . $correo : 'n:' . cengi_texto_normalizado((string) $nombre);
}

function cengi_ig_horas_evento($hora)
{
    if (!preg_match('/(\d{1,2}):(\d{2})\s*(?:–|—|-|a)\s*(\d{1,2}):(\d{2})/u', (string) $hora, $h)) {
        return 0.0;
    }
    return max(0, ((int) $h[3] * 60 + (int) $h[4] - (int) $h[1] * 60 - (int) $h[2]) / 60);
}

function cengi_ig_horas_rango($inicio, $fin)
{
    return max(0, (strtotime('2000-01-01 ' . $fin) - strtotime('2000-01-01 ' . $inicio)) / 3600);
}

function cengi_ig_f1($valor, $sufijo = '')
{
    return $valor === null ? '—' : number_format($valor, 1) . $sufijo;
}

function cengi_ig_balance($valor)
{
    return '<b class="' . ($valor < 0 ? 'ig-neg' : 'ig-pos') . '">' . ($valor < 0 ? '−' : '') . cengi_pp_q(abs($valor)) . '</b>';
}

function cengi_ig_barra($valor, $maximo)
{
    $pct = $maximo > 0 ? max(0, min(100, $valor / $maximo * 100)) : 0;
    return '<span class="pp-prog-track ig-bar"><span style="width:' . round($pct) . '%;"></span></span>';
}

/** Actividad a la que se asocia un gasto (para validar y titular el modal), o null. */
function cengi_ig_actividad(PDO $db, $tipo, $id)
{
    $sql = [
        'plan' => 'SELECT nombre FROM planificacion_cursos WHERE id = ?',
        'curso' => 'SELECT nombre_cursos FROM cursos WHERE id = ?',
        'evento' => 'SELECT nombre FROM eventos WHERE id = ?',
    ][$tipo] ?? null;
    if (!$sql) {
        return null;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([(int) $id]);
    $nombre = $stmt->fetchColumn();
    return $nombre === false ? null : (string) $nombre;
}

// ---------------------------------------------------------------- datos del informe

/** Gastos por "tipo:id". */
function cengi_ig_gastos(PDO $db)
{
    $gastos = [];
    foreach ($db->query('SELECT * FROM gestion_gastos ORDER BY fecha, id')->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $gastos[$g['tipo'] . ':' . $g['ref_id']][] = $g;
    }
    return $gastos;
}

function cengi_ig_suma_gastos(array $gastos, $clave)
{
    return array_sum(array_map(static function ($g) {
        return (float) $g['monto'];
    }, $gastos[$clave] ?? []));
}

/**
 * Cursos y diplomados que inician en el periodo: planes convocados (aprobados o
 * reprogramados) y cursos creados sin planificacion.
 */
function cengi_ig_cursos(PDO $db, $desde, $hasta, array $gastos, array &$personas)
{
    $actividades = [];
    $stmt = $db->prepare("SELECT p.id, p.nombre, p.curso_id, p.modalidad, p.fecha_inicio, p.fecha_fin, cc.descripcion_categorias_cursos AS categoria
        FROM planificacion_cursos p LEFT JOIN categorias_cursos cc ON cc.id = p.categoria_curso_id
        WHERE p.estado IN ('aprobado', 'reprogramado') AND p.fecha_inicio BETWEEN ? AND ?");
    $stmt->execute([$desde, $hasta]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $actividades[] = ['tipo' => 'plan', 'id' => (int) $p['id'], 'plan_id' => (int) $p['id'], 'curso_id' => (int) $p['curso_id'], 'nombre' => $p['nombre'],
            'categoria' => (string) $p['categoria'], 'modalidad' => $p['modalidad'], 'inicio' => $p['fecha_inicio'], 'fin' => $p['fecha_fin']];
    }
    $stmt = $db->prepare("SELECT c.id, c.nombre_cursos, c.tipo, c.inicio, c.fin, cc.descripcion_categorias_cursos AS categoria
        FROM cursos c LEFT JOIN categorias_cursos cc ON cc.id = c.categoria_curso_id
        WHERE c.inicio BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM planificacion_cursos p WHERE p.curso_id = c.id)");
    $stmt->execute([$desde, $hasta]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $actividades[] = ['tipo' => 'curso', 'id' => (int) $c['id'], 'plan_id' => 0, 'curso_id' => (int) $c['id'], 'nombre' => $c['nombre_cursos'],
            'categoria' => (string) $c['categoria'], 'modalidad' => (string) $c['tipo'], 'inicio' => $c['inicio'], 'fin' => $c['fin']];
    }
    usort($actividades, static function ($a, $b) {
        return [$a['inicio'], $a['nombre']] <=> [$b['inicio'], $b['nombre']];
    });

    $stAsig = $db->prepare("SELECT p.nombre_participantes AS nombre, p.cui_participantes AS cui, p.correo_participantes AS correo,
            COALESCE(i.nombre_ingenios, '') AS ingenio, cc.asistencia, cc.evaluacion, cc.posevaluacion
        FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id
        LEFT JOIN ingenios i ON i.id = p.ingenio_id LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        WHERE a.cursos_id = ? AND a.estado_asignaciones = 1");
    $stSol = $db->prepare("SELECT s.tipo_pago, s.pago_estado, s.monto, s.moneda, g.estado AS grupo_estado
        FROM solicitudes_inscripcion s LEFT JOIN pagos_grupales g ON g.id = s.grupo_pago_id
        WHERE s.estado <> 'Rechazado' AND (s.planificacion_id = ? OR (s.planificacion_id IS NULL AND s.curso_id = ?))");
    $stSes = $db->prepare("SELECT s.estado, s.hora_inicio, s.hora_fin, COALESCE(t.tarifa, i.tarifa_sesion, 0) AS tarifa_instr, COALESCE(st.tarifa, 0) AS tarifa_sop
        FROM curso_sesiones s LEFT JOIN instructores i ON i.id = s.instructor_id
        LEFT JOIN tarifas_instructor_plan t ON t.instructor_id = s.instructor_id AND t.planificacion_id = s.planificacion_id
        LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id
        WHERE s.planificacion_id = ? AND s.estado <> 'cancelada'");
    $stHoras = $db->prepare('SELECT COALESCE(SUM(horas), 0) FROM curso_modulos WHERE curso_id = ?');
    $stEval = $db->prepare('SELECT ' . implode(', ', CENGI_IG_CRITERIOS) . ' FROM evaluaciones_instructor ei
        LEFT JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id LEFT JOIN cargas_evaluaciones_instructor ce ON ce.id = ei.carga_id
        WHERE COALESCE(eei.curso_id, ce.curso_id) = ?');

    $cursos = [];
    foreach ($actividades as $a) {
        $asignados = [];
        $evaluaciones = [];
        $horasCurso = 0.0;
        if ($a['curso_id']) {
            $stAsig->execute([$a['curso_id']]);
            $asignados = $stAsig->fetchAll(PDO::FETCH_ASSOC);
            $stEval->execute([$a['curso_id']]);
            $evaluaciones = array_map(static function ($e) {
                return cengi_ig_prom(array_map('cengi_ig_num', $e));
            }, $stEval->fetchAll(PDO::FETCH_ASSOC));
            $stHoras->execute([$a['curso_id']]);
            $horasCurso = (float) $stHoras->fetchColumn();
        }

        $sesiones = 0;
        $impartidas = 0;
        $horasSesiones = 0.0;
        $instr = 0.0;
        $instrProg = 0.0;
        if ($a['plan_id']) {
            $stSes->execute([$a['plan_id']]);
            foreach ($stSes->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $costo = round((float) $s['tarifa_instr'] * (1 + CENGI_PP_IMPUESTO), 2) + round((float) $s['tarifa_sop'] * (1 + CENGI_PP_IMPUESTO), 2);
                $sesiones++;
                $horasSesiones += cengi_ig_horas_rango($s['hora_inicio'], $s['hora_fin']);
                if ($s['estado'] === 'impartida') {
                    $impartidas++;
                    $instr += $costo;
                } else {
                    $instrProg += $costo;
                }
            }
        }
        $horasCurso = $horasCurso ?: $horasSesiones;

        $stSol->execute([$a['plan_id'], $a['tipo'] === 'curso' ? $a['curso_id'] : 0]);
        $solicitudes = $stSol->fetchAll(PDO::FETCH_ASSOC);
        $ingresos = 0.0;
        $porCobrar = 0.0;
        foreach ($solicitudes as $s) {
            $cobro = cengi_ins_estado_cobro($s);
            $monto = cengi_ins_en_quetzales($s['monto'] ?? 0, $s['moneda'] ?: 'GTQ');
            if ($cobro === 'pagado') {
                $ingresos += $monto;
            } elseif (in_array($cobro, ['pendiente', 'en revisión', 'validado'], true)) {
                $porCobrar += $monto;
            }
        }

        $conResultado = array_values(array_filter($asignados, static function ($p) {
            return cengi_ig_num($p['posevaluacion']) !== null;
        }));
        $aprobados = array_values(array_filter($conResultado, static function ($p) {
            return (float) $p['posevaluacion'] >= CENGI_IG_APRUEBA;
        }));
        $mejoras = [];
        foreach ($asignados as $p) {
            $aprobo = cengi_ig_num($p['posevaluacion']) !== null && (float) $p['posevaluacion'] >= CENGI_IG_APRUEBA;
            if (cengi_ig_num($p['evaluacion']) !== null && cengi_ig_num($p['posevaluacion']) !== null) {
                $mejoras[] = (float) $p['posevaluacion'] - (float) $p['evaluacion'];
            }
            $k = cengi_ig_clave($p['cui'], $p['correo'], $p['nombre']);
            $ing = $p['ingenio'] ?: 'Sin institución';
            $personas['todas'][$k] = true;
            $personas['ing'][$ing]['p'][$k] = true;
            $personas['ing'][$ing]['cursos'] = ($personas['ing'][$ing]['cursos'] ?? 0) + 1;
            $personas['ing'][$ing]['horas'] = ($personas['ing'][$ing]['horas'] ?? 0) + ($aprobo ? $horasCurso : 0);
            $personas['horasCursos'] = ($personas['horasCursos'] ?? 0) + ($aprobo ? $horasCurso : 0);
        }

        $otros = cengi_ig_suma_gastos($gastos, $a['tipo'] . ':' . $a['id']);
        $cursos[] = $a + [
            'insc' => max(count($solicitudes), count($asignados)), 'asig' => count($asignados),
            'ses' => $sesiones, 'imp' => $impartidas, 'avance' => $sesiones ? $impartidas / $sesiones * 100 : null,
            'asist' => cengi_ig_prom(array_map(static function ($p) { return cengi_ig_num($p['asistencia']); }, $asignados)),
            'pre' => cengi_ig_prom(array_map(static function ($p) { return cengi_ig_num($p['evaluacion']); }, $asignados)),
            'post' => cengi_ig_prom(array_map(static function ($p) { return cengi_ig_num($p['posevaluacion']); }, $asignados)),
            'mejora' => cengi_ig_prom($mejoras), 'mejoras' => $mejoras,
            'conRes' => count($conResultado), 'aprob' => count($aprobados), 'evalInst' => cengi_ig_prom($evaluaciones), 'horas' => $horasCurso,
            'ingresos' => $ingresos, 'porCobrar' => $porCobrar, 'instr' => $instr, 'instrProg' => $instrProg, 'otros' => $otros,
            'balance' => $ingresos - $instr - $otros,
        ];
    }
    return $cursos;
}

/** Eventos del periodo (no cancelados) con asistencia, percepcion, colaboracion y finanzas. */
function cengi_ig_eventos(PDO $db, $desde, $hasta, array $gastos, array &$personas)
{
    $stmt = $db->prepare("SELECT * FROM eventos WHERE fecha BETWEEN ? AND ? AND estado <> 'Cancelado' ORDER BY fecha, nombre");
    $stmt->execute([$desde, $hasta]);
    $stPart = $db->prepare("SELECT ep.*, COALESCE(NULLIF(ep.nombre_invitado, ''), p.nombre_participantes, '') AS nombre,
            COALESCE(NULLIF(ep.cui_invitado, ''), p.cui_participantes, '') AS cui, COALESCE(NULLIF(ep.correo_invitado, ''), p.correo_participantes, '') AS correo,
            COALESCE(i.nombre_ingenios, NULLIF(ep.institucion_invitado, ''), '') AS ingenio
        FROM evento_participantes ep LEFT JOIN participantes p ON p.id = ep.participante_id LEFT JOIN ingenios i ON i.id = COALESCE(ep.ingenio_id, p.ingenio_id)
        WHERE ep.evento_id = ?");
    $stPercep = $db->prepare('SELECT estrellas FROM evento_encuesta_percep WHERE evento_id = ? AND estrellas IS NOT NULL');
    $stAliados = $db->prepare('SELECT nombre FROM evento_aliados WHERE evento_id = ? ORDER BY orden, id');
    $stColab = $db->prepare('SELECT ' . implode(', ', array_column(CENGI_EVT_COLAB_ITEMS, 'k')) . ' FROM evento_encuesta_colab WHERE evento_id = ?');
    $stAportes = $db->prepare('SELECT COALESCE(SUM(monto), 0) FROM evento_acuerdo_pagos WHERE evento_id = ?');
    $stAcuerdo = $db->prepare('SELECT estado FROM evento_acuerdos WHERE evento_id = ?');

    $eventos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $pagado = $e['modalidad_pago'] === 'Pagado';
        $horas = cengi_ig_horas_evento($e['hora']);
        $stPart->execute([(int) $e['id']]);
        $participantes = $stPart->fetchAll(PDO::FETCH_ASSOC);
        $conf = 0;
        $asist = 0;
        $cobros = 0.0;
        $porCobrar = 0.0;
        foreach ($participantes as $p) {
            $usd = $p['pago_origen'] === 'extranjero' && (float) $e['costo_usd'] > 0;
            if (!$pagado || $p['confirmado_en'] || (int) $p['pagado'] === 1) {
                $conf++;
            }
            if ($pagado && $p['pago_estado'] === 'validado') {
                $cobros += $usd ? (float) ($p['pago_monto'] ?? $e['costo_usd']) * CENGI_INS_TIPO_CAMBIO : (float) ($p['pago_monto'] ?? $e['costo']);
            } elseif ($pagado && in_array($p['pago_estado'], ['pendiente', 'rechazado', 'por_validar'], true)) {
                $porCobrar += $usd ? (float) $e['costo_usd'] * CENGI_INS_TIPO_CAMBIO : (float) $e['costo'];
            }
            if ($p['ingreso_en']) {
                $asist++;
                $k = cengi_ig_clave($p['cui'], $p['correo'], $p['nombre']);
                $ing = $p['ingenio'] ?: 'Sin institución';
                $personas['todas'][$k] = true;
                $personas['ing'][$ing]['p'][$k] = true;
                $personas['ing'][$ing]['eventos'] = ($personas['ing'][$ing]['eventos'] ?? 0) + 1;
                $personas['ing'][$ing]['horas'] = ($personas['ing'][$ing]['horas'] ?? 0) + $horas;
                $personas['horasEventos'] = ($personas['horasEventos'] ?? 0) + $horas;
            }
        }

        $stPercep->execute([(int) $e['id']]);
        $estrellas = array_map('floatval', $stPercep->fetchAll(PDO::FETCH_COLUMN));

        $colab = (int) $e['en_colaboracion'] === 1;
        $aliados = [];
        $indice = null;
        $aportes = 0.0;
        $aporteSaldo = 0.0;
        $modalidad = '';
        if ($colab) {
            $stAliados->execute([(int) $e['id']]);
            $aliados = $stAliados->fetchAll(PDO::FETCH_COLUMN);
            $stColab->execute([(int) $e['id']]);
            $indice = cengi_ig_prom(array_map(static function ($r) {
                return cengi_ig_prom(array_map('cengi_ig_num', $r));
            }, $stColab->fetchAll(PDO::FETCH_ASSOC)));
            $modalidad = CENGI_ACU_MODALIDADES[(int) $e['colab_modalidad']]['corto'] ?? '';
            $stAportes->execute([(int) $e['id']]);
            $aportes = (float) $stAportes->fetchColumn();
            $stAcuerdo->execute([(int) $e['id']]);
            $estadoAcuerdo = $stAcuerdo->fetchColumn();
            // Saldo del aporte: solo con propuesta aceptada (antes no hay compromiso de pago).
            if ($estadoAcuerdo !== false && !in_array($estadoAcuerdo, ['borrador', 'enviada'], true)) {
                try {
                    $st = cengi_acu_estado($db, (int) $e['id'], false);
                    $aporteSaldo = $st ? max(0, $st['resumen']['totalFinal'] - $st['resumen']['pagado']) : 0;
                } catch (Throwable $ex) {
                    error_log('Informe de gestión, acuerdo del evento ' . $e['id'] . ': ' . $ex->getMessage());
                }
            }
        }

        $otros = cengi_ig_suma_gastos($gastos, 'evento:' . $e['id']);
        $eventos[] = ['id' => (int) $e['id'], 'nombre' => $e['nombre'], 'tipo' => (string) $e['tipo'], 'fecha' => $e['fecha'], 'pagado' => $pagado,
            'costo' => (float) $e['costo'], 'reg' => count($participantes), 'conf' => $conf, 'asist' => $asist,
            'pctAsist' => $e['fecha'] < cengi_pp_hoy() && ($conf ?: count($participantes)) ? $asist / ($conf ?: count($participantes)) * 100 : null,
            'percep' => cengi_ig_prom($estrellas), 'nPercep' => count($estrellas), 'colab' => $colab, 'aliados' => $aliados, 'modalidad' => $modalidad, 'indice' => $indice,
            'cobros' => $cobros, 'aportes' => $aportes, 'ingresos' => $cobros + $aportes, 'porCobrar' => $porCobrar + $aporteSaldo, 'otros' => $otros,
            'balance' => $cobros + $aportes - $otros];
    }
    return $eventos;
}

function cengi_ig_informe(PDO $db, $desde, $hasta)
{
    $gastos = cengi_ig_gastos($db);
    $personas = ['todas' => [], 'ing' => [], 'horasCursos' => 0, 'horasEventos' => 0];
    $cursos = cengi_ig_cursos($db, $desde, $hasta, $gastos, $personas);
    $eventos = cengi_ig_eventos($db, $desde, $hasta, $gastos, $personas);

    $stmt = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE estado = 'impartida' AND fecha BETWEEN ? AND ?");
    $stmt->execute([$desde, $hasta]);
    $sesionesImpartidas = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT ' . implode(', ', CENGI_IG_CRITERIOS) . ' FROM evaluaciones_instructor WHERE COALESCE(fecha_curso, DATE(creado)) BETWEEN ? AND ?');
    $stmt->execute([$desde, $hasta]);
    $evalInst = cengi_ig_prom(array_map(static function ($e) {
        return cengi_ig_prom(array_map('cengi_ig_num', $e));
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));

    $stmt = $db->prepare('SELECT COUNT(*) FROM diplomas WHERE DATE(emitido_en) BETWEEN ? AND ?');
    $stmt->execute([$desde, $hasta]);
    $constancias = (int) $stmt->fetchColumn();

    $ings = $personas['ing'];
    uasort($ings, static function ($a, $b) {
        return [$b['horas'] ?? 0, count($b['p'])] <=> [$a['horas'] ?? 0, count($a['p'])];
    });

    // Gastos de las actividades del informe (para la descarga).
    $nombres = [];
    foreach ($cursos as $c) {
        $nombres[$c['tipo'] . ':' . $c['id']] = ['Curso', $c['nombre']];
    }
    foreach ($eventos as $e) {
        $nombres['evento:' . $e['id']] = ['Evento', $e['nombre']];
    }
    $gastosInforme = [];
    foreach (array_intersect_key($gastos, $nombres) as $clave => $lista) {
        foreach ($lista as $g) {
            $gastosInforme[] = $g + ['actividad_tipo' => $nombres[$clave][0], 'actividad' => $nombres[$clave][1]];
        }
    }

    $hoy = cengi_pp_hoy();
    return [
        'cursos' => $cursos, 'eventos' => $eventos, 'ings' => $ings, 'gastos' => $gastosInforme,
        'realizados' => count(array_filter($cursos, static function ($c) use ($hoy) { return $c['fin'] && $c['fin'] !== '0000-00-00' && $c['fin'] < $hoy; })),
        'evReal' => count(array_filter($eventos, static function ($e) use ($hoy) { return $e['fecha'] < $hoy; })),
        'sesiones' => $sesionesImpartidas, 'unicos' => count($personas['todas']),
        'horas' => $personas['horasCursos'] + $personas['horasEventos'],
        'mejora' => cengi_ig_prom(array_merge([], ...array_column($cursos, 'mejoras'))),
        'evalInst' => $evalInst,
        'percep' => cengi_ig_prom(array_merge([], ...array_map(static function ($e) {
            return $e['nPercep'] ? array_fill(0, $e['nPercep'], $e['percep']) : [];
        }, $eventos))),
        'colab' => cengi_ig_prom(array_column($eventos, 'indice')),
        'constancias' => $constancias,
    ];
}

// ---------------------------------------------------------------- acciones: otros gastos

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if (!$puedeEditarGastos) {
            throw new InvalidArgumentException('No tienes permiso para modificar gastos.');
        }
        if ($accion === 'gasto_agregar') {
            $tipo = (string) ($_POST['tipo'] ?? '');
            $ref = (int) ($_POST['ref'] ?? 0);
            if (cengi_ig_actividad($db, $tipo, $ref) === null) {
                throw new InvalidArgumentException('La actividad ya no existe.');
            }
            $fecha = (string) ($_POST['fecha'] ?? '');
            $concepto = mb_substr(trim((string) ($_POST['concepto'] ?? '')), 0, 255, 'UTF-8');
            $categoria = in_array($_POST['categoria'] ?? '', CENGI_IG_CATEGORIAS, true) ? $_POST['categoria'] : 'Otro';
            $monto = str_replace(',', '', trim((string) ($_POST['monto'] ?? '')));
            if (!cengi_pp_es_fecha($fecha)) {
                throw new InvalidArgumentException('Escribe la fecha del gasto.');
            }
            if ($concepto === '') {
                throw new InvalidArgumentException('Escribe el concepto del gasto.');
            }
            if (!is_numeric($monto) || (float) $monto <= 0) {
                throw new InvalidArgumentException('El monto debe ser mayor que 0.');
            }
            $db->prepare('INSERT INTO gestion_gastos (tipo, ref_id, fecha, concepto, categoria, documento, monto, creado_por_nombre) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tipo, $ref, $fecha, $concepto, $categoria, mb_substr(trim((string) ($_POST['documento'] ?? '')), 0, 80, 'UTF-8'), round((float) $monto, 2), cengi_pp_usuario_nombre()]);
            cengi_pp_redirigir('informe_gestion.php', ['msg' => 'gasto']);
        } elseif ($accion === 'gasto_quitar') {
            $db->prepare('DELETE FROM gestion_gastos WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            cengi_pp_redirigir('informe_gestion.php', ['msg' => 'gasto-quitado']);
        }
        throw new InvalidArgumentException('Acción no válida.');
    } catch (Throwable $e) {
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = cengi_pp_h($e->getMessage());
        } else {
            error_log('Informe de gestión: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar el gasto.';
        }
    }
}
if ($mensaje === '' && isset(CENGI_IG_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = CENGI_IG_MENSAJES[$_GET['msg']];
}

// ---------------------------------------------------------------- periodo e informe

$presets = cengi_ig_presets();
$preset = isset($presets[$_GET['periodo'] ?? '']) ? $_GET['periodo'] : '';
$desde = (string) ($_GET['desde'] ?? '');
$hasta = (string) ($_GET['hasta'] ?? '');
if ($preset !== '') {
    [$desde, $hasta] = $presets[$preset];
} elseif (!cengi_pp_es_fecha($desde) || !cengi_pp_es_fecha($hasta) || $desde > $hasta) {
    if ($desde !== '' || $hasta !== '') {
        $mensaje = $mensaje ?: 'Revisa el rango de fechas: se muestra el año actual.';
        $mensajeTipo = $mensajeTipo === 'error' ? 'error' : 'warning';
    }
    $preset = 'anio';
    [$desde, $hasta] = $presets['anio'];
}
foreach ($presets as $clave => [$d, $h]) {
    if ($preset === '' && $d === $desde && $h === $hasta) {
        $preset = $clave;
    }
}
$periodoQuery = ['desde' => $desde, 'hasta' => $hasta];

try {
    $g = cengi_ig_informe($db, $desde, $hasta);
} catch (PDOException $e) {
    error_log('Informe de gestión: ' . $e->getMessage());
    if (stripos($e->getMessage(), 'gestion_gastos') !== false) {
        exit('Falta aplicar la migración cengicursos/migrations/20261013_informe_gestion_gastos.sql');
    }
    throw $e;
}
$cursos = $g['cursos'];
$eventos = $g['eventos'];
$S = static function (array $lista, $campo) {
    return array_sum(array_map(static function ($x) use ($campo) {
        return (float) ($x[$campo] ?? 0);
    }, $lista));
};
$cI = $S($cursos, 'ingresos');
$cC = $S($cursos, 'instr');
$cO = $S($cursos, 'otros');
$eI = $S($eventos, 'ingresos');
$eO = $S($eventos, 'otros');
$total = $cI + $eI - $cC - $cO - $eO;

// ---------------------------------------------------------------- descarga (CSV)

if (($_GET['exportar'] ?? '') === 'csv') {
    $r1 = static function ($v) {
        return $v === null ? '' : round($v, 1);
    };
    $f = [['Informe de gestión', $desde, 'a', $hasta], [], ['CURSOS'],
        ['Curso', 'Categoría', 'Inicio', 'Fin', 'Inscritos', 'Asignados', 'Sesiones impartidas', 'Sesiones totales', 'Avance %', 'Asistencia prom. %', 'Pre', 'Post', 'Mejora', 'Aprobados', 'Con resultado', 'Eval. instructor (de 4)', 'Ingresos cobrados', 'Por cobrar', 'Instructores y soporte', 'Otros gastos', 'Balance', 'Instructores por ejecutar']];
    foreach ($cursos as $c) {
        $f[] = [$c['nombre'], $c['categoria'], $c['inicio'], $c['fin'], $c['insc'], $c['asig'], $c['imp'], $c['ses'], $c['avance'] === null ? '' : round($c['avance']), $r1($c['asist']),
            $r1($c['pre']), $r1($c['post']), $r1($c['mejora']), $c['aprob'], $c['conRes'], $r1($c['evalInst']), $c['ingresos'], $c['porCobrar'], $c['instr'], $c['otros'], $c['balance'], $c['instrProg']];
    }
    $f[] = [];
    $f[] = ['EVENTOS'];
    $f[] = ['Evento', 'Tipo', 'Fecha', 'Registrados', 'Confirmados', 'Asistentes', 'Asistencia %', 'Percepción (de 5)', 'Respuestas', 'Colaboración', 'Modalidad', 'Índice colaboración (de 5)', 'Cobros participantes', 'Aportes aliados', 'Ingresos', 'Por cobrar', 'Otros gastos', 'Balance'];
    foreach ($eventos as $e) {
        $f[] = [$e['nombre'], $e['tipo'], $e['fecha'], $e['reg'], $e['conf'], $e['asist'], $e['pctAsist'] === null ? '' : round($e['pctAsist']), $r1($e['percep']), $e['nPercep'],
            $e['colab'] ? 'Sí' : 'No', $e['modalidad'], $r1($e['indice']), $e['cobros'], $e['aportes'], $e['ingresos'], $e['porCobrar'], $e['otros'], $e['balance']];
    }
    $f[] = [];
    $f[] = ['GASTOS REGISTRADOS'];
    $f[] = ['Tipo', 'Actividad', 'Fecha', 'Concepto', 'Categoría', 'Documento', 'Monto'];
    foreach ($g['gastos'] as $x) {
        $f[] = [$x['actividad_tipo'], $x['actividad'], $x['fecha'], $x['concepto'], $x['categoria'], $x['documento'], $x['monto']];
    }
    $f[] = [];
    $f[] = ['INSTITUCIÓN', 'Participantes', 'Cursos', 'Eventos', 'Horas acreditadas'];
    foreach ($g['ings'] as $n => $o) {
        $f[] = [$n, count($o['p']), $o['cursos'] ?? 0, $o['eventos'] ?? 0, round($o['horas'] ?? 0)];
    }
    $f[] = [];
    $f[] = ['BALANCE GENERAL', 'Cursos', 'Eventos', 'Total'];
    $f[] = ['Ingresos', $cI, $eI, $cI + $eI];
    $f[] = ['Pago a instructores y soporte', -$cC, 0, -$cC];
    $f[] = ['Otros gastos', -$cO, -$eO, -($cO + $eO)];
    $f[] = ['Balance', $cI - $cC - $cO, $eI - $eO, $total];
    cengi_pp_csv('Informe_gestion_' . $desde . '_' . $hasta, $f);
}

// ---------------------------------------------------------------- modal de otros gastos

$gastosModal = null;
if (preg_match('/^(plan|curso|evento):(\d+)$/', (string) ($_GET['gastos'] ?? ''), $m)) {
    $nombre = cengi_ig_actividad($db, $m[1], (int) $m[2]);
    if ($nombre !== null) {
        $stmt = $db->prepare('SELECT * FROM gestion_gastos WHERE tipo = ? AND ref_id = ? ORDER BY fecha, id');
        $stmt->execute([$m[1], (int) $m[2]]);
        $gastosModal = ['tipo' => $m[1], 'ref' => (int) $m[2], 'nombre' => $nombre, 'lista' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
}
$urlGastos = static function ($tipo, $id) use ($periodoQuery) {
    return 'informe_gestion.php?' . cengi_pp_h(http_build_query($periodoQuery + ['gastos' => $tipo . ':' . $id]));
};
$btnGastos = static function ($tipo, $id) use ($urlGastos) {
    return ' <a class="pp-link ig-no-print" href="' . $urlGastos($tipo, $id) . '">gastos</a>';
};

$th = static function ($texto, $izq = false) {
    return '<th' . ($izq ? ' class="ig-l"' : '') . '>' . $texto . '</th>';
};
$item = static function ($k, $v) {
    return '<div class="ig-item"><span>' . $k . '</span><b>' . $v . '</b></div>';
};
$kpi = static function ($v, $l) {
    return '<div class="ig-kpi"><div class="v">' . $v . '</div><div class="l">' . $l . '</div></div>';
};
$q = 'cengi_pp_q';
$maxHoras = max(1, ...array_map(static function ($o) {
    return $o['horas'] ?? 0;
}, array_values($g['ings']) ?: [['horas' => 0]]));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php cengi_pp_head('Informe de gestión'); ?>
    <link rel="stylesheet" href="css/informe_gestion.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/informe_gestion.css') ?: 1); ?>">
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page ig-page">
    <?php cengi_pp_feedback(cengi_pp_h($mensaje), $mensajeTipo); ?>

    <section class="pp-card ig-no-print">
        <form class="pp-toolbar" method="get" id="ig-periodo">
            <div class="pp-toolbar-group">
                <select name="periodo" aria-label="Período" id="ig-preset">
                    <option value="">Rango personalizado</option>
                    <?php foreach ($presets as $clave => [$d, $h, $texto]): ?>
                        <option value="<?php echo $clave; ?>" data-desde="<?php echo $d; ?>" data-hasta="<?php echo $h; ?>" <?php echo $preset === $clave ? 'selected' : ''; ?>><?php echo cengi_pp_h($texto); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="pp-sub ig-fecha">Desde <input type="date" name="desde" value="<?php echo cengi_pp_h($desde); ?>" required></label>
                <label class="pp-sub ig-fecha">Hasta <input type="date" name="hasta" value="<?php echo cengi_pp_h($hasta); ?>" required></label>
                <button type="submit" class="btn btn-default btn-sm">Ver</button>
            </div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="informe_gestion.php?<?php echo cengi_pp_h(http_build_query($periodoQuery + ['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar datos</a>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><span class="glyphicon glyphicon-print"></span> Imprimir / guardar PDF</button>
            </div>
        </form>
    </section>

    <article class="ig-doc">
        <header class="ig-head">
            <div>
                <div class="ig-k">INFORME DE GESTIÓN DE CAPACITACIÓN</div>
                <div class="ig-t"><?php echo cengi_pp_h(cengi_evp_fecha($desde) . ' al ' . cengi_evp_fecha($hasta)); ?></div>
                <div class="ig-s">Cursos, diplomados y eventos · CENGICAÑA</div>
            </div>
            <img src="img/logo-comite-capacitacion.png" alt="CENGICAÑA">
        </header>

        <div class="ig-kpis">
            <?php
            echo $kpi(count($cursos) . ' / ' . count($eventos), 'Cursos / eventos');
            echo $kpi($g['unicos'], 'Participantes únicos');
            echo $kpi(number_format(round($g['horas'])), 'Horas-persona acreditadas');
            echo $kpi($q($cI + $eI), 'Ingresos');
            echo $kpi(cengi_ig_balance($total), 'Balance general');
            ?>
        </div>

        <h3 class="ig-sec">1. Resumen de actividad y resultados</h3>
        <div class="ig-2">
            <div>
                <?php
                $fin = $S($cursos, 'conRes');
                $aprob = $S($cursos, 'aprob');
                echo $item('Cursos y diplomados (finalizados)', count($cursos) . ' (' . $g['realizados'] . ')');
                echo $item('Sesiones impartidas', $g['sesiones']);
                echo $item('Inscritos / asignados en cursos', (int) $S($cursos, 'insc') . ' / ' . (int) $S($cursos, 'asig'));
                echo $item('Aprobados / con resultado', (int) $aprob . ' / ' . (int) $fin . ($fin ? ' (' . round($aprob / $fin * 100) . '%)' : ''));
                echo $item('Mejora promedio pre → post', $g['mejora'] !== null ? ($g['mejora'] >= 0 ? '+' : '') . number_format($g['mejora'], 1) . ' pts' : '—');
                echo $item('Diplomas y constancias emitidos', $g['constancias']);
                ?>
            </div>
            <div>
                <?php
                echo $item('Eventos (realizados)', count($eventos) . ' (' . $g['evReal'] . ')');
                echo $item('Registrados / asistentes en eventos', (int) $S($eventos, 'reg') . ' / ' . (int) $S($eventos, 'asist'));
                echo $item('Evaluación de instructores', cengi_ig_f1($g['evalInst'], $g['evalInst'] !== null ? ' de 4' : ''));
                echo $item('Percepción en eventos', cengi_ig_f1($g['percep'], $g['percep'] !== null ? ' de 5' : ''));
                echo $item('Índice de colaboración', cengi_ig_f1($g['colab'], $g['colab'] !== null ? ' de 5' : ''));
                ?>
            </div>
        </div>

        <h3 class="ig-sec">2. Detalle por curso — participación y resultados académicos</h3>
        <?php if ($cursos): ?>
            <div class="ig-scroll"><table class="ig-tb">
                <thead><tr><?php echo $th('Curso / diplomado', true) . $th('Inscritos') . $th('Asignados') . $th('Avance (sesiones)', true) . $th('Asistencia prom.') . $th('Pre') . $th('Post') . $th('Mejora') . $th('Aprobados') . $th('Eval. instructor'); ?></tr></thead>
                <tbody>
                <?php foreach ($cursos as $c): ?>
                    <tr>
                        <td class="ig-l"><b><?php echo cengi_pp_h($c['nombre']); ?></b>
                            <div class="ig-muted"><?php echo cengi_pp_h(implode(' · ', array_filter([$c['categoria'], cengi_pp_fecha_corta($c['inicio']) . ($c['fin'] && $c['fin'] !== '0000-00-00' ? '–' . cengi_pp_fecha_corta($c['fin']) : ''), $c['modalidad'], $c['tipo'] === 'curso' ? 'sin planificación' : null]))); ?></div></td>
                        <td><?php echo $c['insc']; ?></td>
                        <td><?php echo $c['asig']; ?></td>
                        <td class="ig-l"><?php echo $c['ses'] ? '<span class="pp-prog">' . cengi_ig_barra($c['imp'], $c['ses']) . '<span class="ig-nowrap">' . $c['imp'] . '/' . $c['ses'] . ' · ' . round($c['avance']) . '%</span></span>' : '<span class="ig-muted">sin sesiones</span>'; ?></td>
                        <td><?php echo $c['asist'] !== null ? round($c['asist']) . '%' : '—'; ?></td>
                        <td><?php echo cengi_ig_f1($c['pre']); ?></td>
                        <td><?php echo cengi_ig_f1($c['post']); ?></td>
                        <td><?php echo $c['mejora'] !== null ? '<b class="' . ($c['mejora'] < 0 ? 'ig-neg' : 'ig-pos') . '">' . ($c['mejora'] >= 0 ? '+' : '') . number_format($c['mejora'], 1) . '</b>' : '—'; ?></td>
                        <td><?php echo $c['conRes'] ? $c['aprob'] . '/' . $c['conRes'] : '<span class="ig-muted">' . ($c['asig'] ? 'en curso' : '—') . '</span>'; ?></td>
                        <td><?php echo cengi_ig_f1($c['evalInst']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td class="ig-l">Total</td><td><?php echo (int) $S($cursos, 'insc'); ?></td><td><?php echo (int) $S($cursos, 'asig'); ?></td>
                    <td class="ig-l"><?php echo (int) $S($cursos, 'imp') . '/' . (int) $S($cursos, 'ses'); ?></td>
                    <td><?php $v = cengi_ig_prom(array_column($cursos, 'asist')); echo $v !== null ? round($v) . '%' : '—'; ?></td>
                    <td><?php echo cengi_ig_f1(cengi_ig_prom(array_column($cursos, 'pre'))); ?></td>
                    <td><?php echo cengi_ig_f1(cengi_ig_prom(array_column($cursos, 'post'))); ?></td>
                    <td></td><td><?php echo (int) $aprob . '/' . (int) $fin; ?></td><td></td>
                </tr></tfoot>
            </table></div>
            <p class="ig-muted ig-nota">Aprobado: nota final (post-evaluación) de <?php echo CENGI_IG_APRUEBA; ?> o más. Evaluación del instructor: promedio de los criterios, escala de 1 a 4.</p>
        <?php else: ?>
            <p class="ig-muted">Sin cursos en el período.</p>
        <?php endif; ?>

        <h3 class="ig-sec">3. Detalle por curso — balance financiero</h3>
        <?php if ($cursos): ?>
            <div class="ig-scroll"><table class="ig-tb">
                <thead><tr><?php echo $th('Curso / diplomado', true) . $th('Ingresos cobrados') . $th('Por cobrar') . $th('Instructores y soporte') . $th('Otros gastos') . $th('Balance') . $th('Instructores por ejecutar'); ?></tr></thead>
                <tbody>
                <?php foreach ($cursos as $c): ?>
                    <tr>
                        <td class="ig-l"><b><?php echo cengi_pp_h($c['nombre']); ?></b><?php echo $btnGastos($c['tipo'], $c['id']); ?></td>
                        <td><?php echo $q($c['ingresos']); ?></td>
                        <td><?php echo $c['porCobrar'] ? $q($c['porCobrar']) : '—'; ?></td>
                        <td><?php echo $q($c['instr']); ?></td>
                        <td><?php echo $c['otros'] ? $q($c['otros']) : '—'; ?></td>
                        <td><?php echo cengi_ig_balance($c['balance']); ?></td>
                        <td><?php echo $c['instrProg'] ? '<span class="ig-muted">' . $q($c['instrProg']) . '</span>' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td class="ig-l">Total cursos</td><td><?php echo $q($cI); ?></td><td><?php echo $q($S($cursos, 'porCobrar')); ?></td><td><?php echo $q($cC); ?></td>
                    <td><?php echo $q($cO); ?></td><td><?php echo cengi_ig_balance($cI - $cC - $cO); ?></td><td><span class="ig-muted"><?php echo $q($S($cursos, 'instrProg')); ?></span></td>
                </tr></tfoot>
            </table></div>
            <p class="ig-muted ig-nota">Instructores y soporte: sesiones impartidas a la tarifa vigente con <?php echo CENGI_PP_IMPUESTO * 100; ?>% de impuesto. “Por ejecutar”: sesiones programadas aún no impartidas. Ingresos: pagos de inscripción marcados como pagados (US$ a Q<?php echo CENGI_INS_TIPO_CAMBIO; ?>).</p>
        <?php endif; ?>

        <h3 class="ig-sec">4. Detalle por evento — participación y percepción</h3>
        <?php if ($eventos): ?>
            <div class="ig-scroll"><table class="ig-tb">
                <thead><tr><?php echo $th('Evento', true) . $th('Registrados') . $th('Confirmados') . $th('Asistentes') . $th('Asistencia') . $th('Percepción') . $th('Colaboración', true) . $th('Índice colab.'); ?></tr></thead>
                <tbody>
                <?php foreach ($eventos as $e): ?>
                    <tr>
                        <td class="ig-l"><b><?php echo cengi_pp_h($e['nombre']); ?></b>
                            <div class="ig-muted"><?php echo cengi_pp_h(implode(' · ', array_filter([$e['tipo'], cengi_pp_fecha_corta($e['fecha']), $e['fecha'] >= $hoy ? 'próximo' : null]))); ?></div></td>
                        <td><?php echo $e['reg']; ?></td>
                        <td><?php echo $e['conf']; ?></td>
                        <td><?php echo $e['asist']; ?></td>
                        <td><?php echo $e['pctAsist'] !== null ? round($e['pctAsist']) . '%' : '—'; ?></td>
                        <td><?php echo $e['percep'] !== null ? number_format($e['percep'], 1) . ' <span class="ig-muted">(' . $e['nPercep'] . ')</span>' : '—'; ?></td>
                        <td class="ig-l"><?php echo $e['colab'] ? cengi_pp_h(implode(', ', $e['aliados']) ?: 'Sí') . ($e['modalidad'] ? '<div class="ig-muted">' . cengi_pp_h($e['modalidad']) . '</div>' : '') : '<span class="ig-muted">No</span>'; ?></td>
                        <td><?php echo $e['indice'] !== null ? number_format($e['indice'], 1) : ($e['colab'] ? '<span class="ig-muted">sin respuesta</span>' : '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="ig-muted">Sin eventos en el período.</p>
        <?php endif; ?>

        <h3 class="ig-sec">5. Detalle por evento — estado financiero</h3>
        <?php if ($eventos): ?>
            <div class="ig-scroll"><table class="ig-tb">
                <thead><tr><?php echo $th('Evento', true) . $th('Cobros a participantes') . $th('Aportes de aliados') . $th('Ingresos') . $th('Por cobrar') . $th('Otros gastos') . $th('Balance'); ?></tr></thead>
                <tbody>
                <?php foreach ($eventos as $e): ?>
                    <tr>
                        <td class="ig-l"><b><?php echo cengi_pp_h($e['nombre']); ?></b><?php echo $btnGastos('evento', $e['id']); ?>
                            <div class="ig-muted"><?php echo $e['pagado'] ? 'Pagado ' . $q($e['costo']) : ($e['colab'] ? 'Gratuito · en colaboración' : 'Gratuito'); ?></div></td>
                        <td><?php echo $e['cobros'] ? $q($e['cobros']) : '—'; ?></td>
                        <td><?php echo $e['aportes'] ? $q($e['aportes']) : '—'; ?></td>
                        <td><b><?php echo $q($e['ingresos']); ?></b></td>
                        <td><?php echo $e['porCobrar'] ? $q($e['porCobrar']) : '—'; ?></td>
                        <td><?php echo $e['otros'] ? $q($e['otros']) : '—'; ?></td>
                        <td><?php echo cengi_ig_balance($e['balance']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td class="ig-l">Total eventos</td><td><?php echo $q($S($eventos, 'cobros')); ?></td><td><?php echo $q($S($eventos, 'aportes')); ?></td><td><?php echo $q($eI); ?></td>
                    <td><?php echo $q($S($eventos, 'porCobrar')); ?></td><td><?php echo $q($eO); ?></td><td><?php echo cengi_ig_balance($eI - $eO); ?></td>
                </tr></tfoot>
            </table></div>
            <p class="ig-muted ig-nota">Cobros: pagos validados. Aportes: pagos registrados en el acuerdo de colaboración. Por cobrar incluye el saldo de aportes de acuerdos aceptados.</p>
        <?php endif; ?>

        <h3 class="ig-sec">6. Cobertura por institución</h3>
        <?php if ($g['ings']): ?>
            <div class="ig-scroll"><table class="ig-tb">
                <thead><tr><?php echo $th('Institución', true) . $th('Participantes') . $th('Cursos') . $th('Eventos') . $th('Horas acreditadas') . $th('', true); ?></tr></thead>
                <tbody>
                <?php foreach ($g['ings'] as $n => $o): ?>
                    <tr>
                        <td class="ig-l"><b><?php echo cengi_pp_h($n); ?></b></td>
                        <td><?php echo count($o['p']); ?></td>
                        <td><?php echo (int) ($o['cursos'] ?? 0); ?></td>
                        <td><?php echo (int) ($o['eventos'] ?? 0); ?></td>
                        <td><?php echo number_format(round($o['horas'] ?? 0)); ?></td>
                        <td class="ig-l ig-barcell"><?php echo cengi_ig_barra($o['horas'] ?? 0, $maxHoras); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="ig-muted ig-nota">Cursos: asignaciones; eventos: asistencias con ingreso registrado. Horas: cursos aprobados y eventos con asistencia.</p>
        <?php else: ?>
            <p class="ig-muted">Sin participación en el período.</p>
        <?php endif; ?>

        <h3 class="ig-sec">7. Balance general</h3>
        <div class="ig-scroll"><table class="ig-tb ig-bal">
            <thead><tr><?php echo $th('Concepto', true) . $th('Cursos') . $th('Eventos') . $th('Total'); ?></tr></thead>
            <tbody>
                <tr><td class="ig-l">Ingresos (inscripciones, cobros y aportes)</td><td><?php echo $q($cI); ?></td><td><?php echo $q($eI); ?></td><td><b><?php echo $q($cI + $eI); ?></b></td></tr>
                <tr><td class="ig-l">Pago a instructores y soporte técnico</td><td>−<?php echo $q($cC); ?></td><td>—</td><td>−<?php echo $q($cC); ?></td></tr>
                <tr><td class="ig-l">Otros gastos registrados</td><td>−<?php echo $q($cO); ?></td><td>−<?php echo $q($eO); ?></td><td>−<?php echo $q($cO + $eO); ?></td></tr>
                <tr class="ig-bal-total"><td class="ig-l"><b>Balance</b></td><td><?php echo cengi_ig_balance($cI - $cC - $cO); ?></td><td><?php echo cengi_ig_balance($eI - $eO); ?></td><td><?php echo cengi_ig_balance($total); ?></td></tr>
                <tr><td class="ig-l ig-muted">Pendiente por cobrar</td><td><?php echo $q($S($cursos, 'porCobrar')); ?></td><td><?php echo $q($S($eventos, 'porCobrar')); ?></td><td><?php echo $q($S($cursos, 'porCobrar') + $S($eventos, 'porCobrar')); ?></td></tr>
                <tr><td class="ig-l ig-muted">Instructores por ejecutar (sesiones programadas)</td><td><?php echo $q($S($cursos, 'instrProg')); ?></td><td>—</td><td><?php echo $q($S($cursos, 'instrProg')); ?></td></tr>
            </tbody>
        </table></div>
        <p class="ig-muted ig-nota">Montos en quetzales; US$ convertidos a Q<?php echo CENGI_INS_TIPO_CAMBIO; ?>. Los otros gastos son los registrados por actividad (alimentación, materiales, logística, transporte, etc.); no incluye costos fijos institucionales.</p>

        <footer class="ig-pie">Generado por Cengicursos · CENGICAÑA · <?php echo date('d/m/Y H:i'); ?> · <?php echo cengi_pp_h(cengi_pp_usuario_nombre()); ?></footer>
    </article>
</main>

<?php if ($gastosModal):
    $volver = 'informe_gestion.php?' . cengi_pp_h(http_build_query($periodoQuery));
    $totalGastos = array_sum(array_column($gastosModal['lista'], 'monto'));
?>
<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="gastos-modal" tabindex="-1" role="dialog" aria-labelledby="gastos-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header">
            <a class="close" href="<?php echo $volver; ?>" aria-label="Cerrar"><span>&times;</span></a>
            <span class="cengi-course-modal-kicker">Otros gastos · <?php echo CENGI_IG_TIPOS[$gastosModal['tipo']]; ?></span>
            <h4 class="modal-title" id="gastos-title"><?php echo cengi_pp_h($gastosModal['nombre']); ?></h4>
            <p>Alimentación, materiales, logística y demás gastos de la actividad. Se restan en el balance del informe.</p>
        </div>
        <div class="modal-body">
            <?php if ($gastosModal['lista']): ?>
                <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:620px;">
                    <thead><tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th>Documento</th><th class="pp-num">Monto</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($gastosModal['lista'] as $x): ?>
                        <tr>
                            <td class="pp-num"><?php echo cengi_pp_fecha($x['fecha']); ?></td>
                            <td><?php echo cengi_pp_h($x['concepto']); ?><div class="pp-sub"><?php echo cengi_pp_h($x['creado_por_nombre']); ?></div></td>
                            <td><?php echo cengi_pp_h($x['categoria']); ?></td>
                            <td class="pp-mono"><?php echo cengi_pp_h($x['documento'] ?: '—'); ?></td>
                            <td class="pp-num"><b><?php echo cengi_pp_q($x['monto'], 2); ?></b></td>
                            <td><?php if ($puedeEditarGastos): ?><form method="post" onsubmit="return confirm('¿Quitar este gasto?');"><input type="hidden" name="accion" value="gasto_quitar"><input type="hidden" name="id" value="<?php echo (int) $x['id']; ?>">
                                <button type="submit" class="btn btn-default btn-xs" aria-label="Quitar gasto"><span class="glyphicon glyphicon-trash"></span></button></form><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="4" style="text-align:right;">Total</td><td class="pp-num"><?php echo cengi_pp_q($totalGastos, 2); ?></td><td></td></tr></tfoot>
                </table></div>
            <?php else: ?>
                <p class="pp-sub">Aún no hay gastos registrados para esta actividad.</p>
            <?php endif; ?>

            <?php if ($puedeEditarGastos): ?>
            <form method="post" class="ig-gasto-form">
                <input type="hidden" name="accion" value="gasto_agregar">
                <input type="hidden" name="tipo" value="<?php echo cengi_pp_h($gastosModal['tipo']); ?>">
                <input type="hidden" name="ref" value="<?php echo (int) $gastosModal['ref']; ?>">
                <h5>Registrar gasto</h5>
                <div class="ig-gasto-grid">
                    <label>Fecha<input type="date" name="fecha" class="form-control" value="<?php echo $hoy; ?>" required></label>
                    <label class="ig-gasto-concepto">Concepto<input type="text" name="concepto" class="form-control" maxlength="255" placeholder="Refacciones de la sesión presencial" required></label>
                    <label>Categoría<select name="categoria" class="form-control"><?php foreach (CENGI_IG_CATEGORIAS as $cat): ?><option><?php echo cengi_pp_h($cat); ?></option><?php endforeach; ?></select></label>
                    <label>Documento<input type="text" name="documento" class="form-control" maxlength="80" placeholder="FAC-A-0001"></label>
                    <label>Monto (Q)<input type="number" name="monto" class="form-control" min="0.01" step="0.01" required></label>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-default" href="<?php echo $volver; ?>">Cerrar</a>
                    <button type="submit" class="btn btn-primary">Agregar gasto</button>
                </div>
            </form>
            <?php else: ?>
                <div class="modal-footer"><a class="btn btn-default" href="<?php echo $volver; ?>">Cerrar</a></div>
            <?php endif; ?>
        </div>
    </div></div>
</div>
<script>
$(function () {
    $('#gastos-modal').modal('show').on('hidden.bs.modal', function () { window.location.href = <?php echo json_encode(html_entity_decode($volver)); ?>; });
});
</script>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var preset = document.getElementById('ig-preset');
    var form = document.getElementById('ig-periodo');
    preset.addEventListener('change', function () {
        var op = preset.options[preset.selectedIndex];
        if (!op.value) return;
        form.desde.value = op.getAttribute('data-desde');
        form.hasta.value = op.getAttribute('data-hasta');
        form.submit();
    });
    // Al cambiar las fechas a mano el rango deja de ser un período rápido.
    [form.desde, form.hasta].forEach(function (input) {
        input.addEventListener('change', function () { preset.value = ''; });
    });
})();
</script>
</body>
</html>
