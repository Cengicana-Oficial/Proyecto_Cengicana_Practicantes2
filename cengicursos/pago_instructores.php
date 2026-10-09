<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
$mensaje = '';
$mensajeTipo = 'success';

const CENGI_PAGO_MENSAJES = [
    'generada' => 'Liquidación generada y enviada a Administración.',
    'aprobada' => 'Liquidación aprobada: lista para pago.',
    'pagada' => 'Pago registrado.',
    'anulada' => 'Liquidación anulada: sus sesiones vuelven a quedar por liquidar.',
    'tarifas' => 'Tarifas y códigos actualizados.',
];

/** Periodo elegido: por mes (YYYY-MM) o por rango. */
function cengi_pago_periodo(array $fuente)
{
    if (($fuente['tipo'] ?? 'mes') === 'rango') {
        $desde = (string) ($fuente['desde'] ?? '');
        $hasta = (string) ($fuente['hasta'] ?? '');
        if (cengi_pp_es_fecha($desde) && cengi_pp_es_fecha($hasta) && $hasta >= $desde) {
            return ['tipo' => 'rango', 'desde' => $desde, 'hasta' => $hasta, 'mes' => substr($desde, 0, 7),
                'label' => cengi_pp_fecha($desde) . ' – ' . cengi_pp_fecha($hasta)];
        }
    }
    $mes = preg_match('/^\d{4}-\d{2}$/', (string) ($fuente['mes'] ?? '')) ? $fuente['mes'] : date('Y-m');
    $desde = $mes . '-01';
    return ['tipo' => 'mes', 'desde' => $desde, 'hasta' => date('Y-m-t', strtotime($desde)), 'mes' => $mes,
        'label' => CENGI_PP_MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4)];
}

/**
 * Sesiones impartidas del periodo que aun no estan en una liquidacion, agrupadas por
 * persona (instructor o soporte) y curso. Clave: i:<instructor>:<plan> / s:<soporte>:<plan>.
 */
function cengi_pago_por_liquidar(PDO $db, array $periodo, $persona = '')
{
    $grupos = [];
    $consultas = [
        'i' => "SELECT s.id, s.fecha, s.planificacion_id, p.nombre AS curso, i.id AS persona_id, i.nombre AS persona, COALESCE(i.codigo_catedratico, '') AS codigo,
                       COALESCE(t.tarifa, i.tarifa_sesion) AS tarifa, COALESCE(t.observaciones, '') AS obs
                FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id INNER JOIN instructores i ON i.id = s.instructor_id
                LEFT JOIN tarifas_instructor_plan t ON t.instructor_id = i.id AND t.planificacion_id = s.planificacion_id
                WHERE s.estado = 'impartida' AND s.liquidacion_id IS NULL AND s.fecha BETWEEN ? AND ?",
        's' => "SELECT s.id, s.fecha, s.planificacion_id, p.nombre AS curso, st.id AS persona_id, st.nombre AS persona, st.codigo, st.tarifa, '' AS obs
                FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id INNER JOIN soporte_tecnico st ON st.id = s.soporte_id
                WHERE s.estado = 'impartida' AND s.liquidacion_soporte_id IS NULL AND s.fecha BETWEEN ? AND ?",
    ];
    foreach ($consultas as $tipo => $sql) {
        $stmt = $db->prepare($sql . ' ORDER BY s.fecha');
        $stmt->execute([$periodo['desde'], $periodo['hasta']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            if ($persona !== '' && $persona !== $tipo . ':' . $fila['persona_id']) {
                continue;
            }
            $clave = $tipo . ':' . $fila['persona_id'] . ':' . $fila['planificacion_id'];
            $grupos[$clave] ??= [
                'clave' => $clave, 'tipo' => $tipo === 'i' ? 'instructor' : 'soporte', 'persona_id' => (int) $fila['persona_id'],
                'persona' => $fila['persona'], 'codigo' => $fila['codigo'], 'plan_id' => (int) $fila['planificacion_id'], 'curso' => $fila['curso'],
                'tarifa' => $fila['tarifa'] !== null ? (float) $fila['tarifa'] : null, 'obs' => $fila['obs'], 'sesiones' => [], 'fechas' => [],
            ];
            $grupos[$clave]['sesiones'][] = (int) $fila['id'];
            $grupos[$clave]['fechas'][] = $fila['fecha'];
        }
    }
    uasort($grupos, static function ($a, $b) {
        return [$a['tipo'] !== 'instructor', $a['persona'], $a['curso']] <=> [$b['tipo'] !== 'instructor', $b['persona'], $b['curso']];
    });
    return $grupos;
}

function cengi_pago_calcular(array $grupo, $tarifa)
{
    $neto = round($tarifa * count($grupo['sesiones']), 2);
    $impuesto = round($neto * CENGI_PP_IMPUESTO, 2);
    return [$neto, $impuesto, $neto + $impuesto];
}

function cengi_pago_numero_libre(PDO $db, $base)
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM liquidaciones WHERE numero = ? AND estado <> 'anulada'");
    $numero = $base;
    for ($k = 2; ; $k++) {
        $stmt->execute([$numero]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $numero;
        }
        $numero = $base . '-' . $k;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $usuario = cengi_pp_usuario_nombre();
    $extra = [];
    try {
        $db->beginTransaction();
        $liq = null;
        if ($id) {
            $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $liq = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$liq) {
                throw new InvalidArgumentException('La liquidación ya no existe.');
            }
        }

        if ($accion === 'generar') {
            $periodo = cengi_pago_periodo($_POST);
            $grupos = cengi_pago_por_liquidar($db, $periodo, (string) ($_POST['persona'] ?? ''));
            $seleccion = array_intersect_key($grupos, array_flip((array) ($_POST['sel'] ?? [])));
            if (!$seleccion) {
                throw new InvalidArgumentException('Marca al menos una fila para liquidar.');
            }
            $numero = mb_substr(trim((string) ($_POST['numero'] ?? '')), 0, 40, 'UTF-8');
            if ($numero === '') {
                throw new InvalidArgumentException('Escribe el número de liquidación.');
            }
            $duplicado = $db->prepare("SELECT COUNT(*) FROM liquidaciones WHERE numero = ? AND estado <> 'anulada'");
            $duplicado->execute([$numero]);
            if ((int) $duplicado->fetchColumn() > 0) {
                throw new InvalidArgumentException('Ya existe una liquidación con el número ' . cengi_pp_h($numero) . '.');
            }
            $db->prepare('INSERT INTO liquidaciones (numero, desde, hasta, destino, nota, estado, creado_por, creado_por_nombre) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $numero, $periodo['desde'], $periodo['hasta'], mb_substr(trim((string) ($_POST['destino'] ?? '')), 0, 255, 'UTF-8') ?: 'Administración',
                trim((string) ($_POST['nota'] ?? '')), 'enviada', cengi_usuario_actual_id() ?: null, $usuario,
            ]);
            $liqId = (int) $db->lastInsertId();
            $stmtDet = $db->prepare('INSERT INTO liquidacion_detalle (liquidacion_id, tipo, persona_id, persona_nombre, persona_codigo, planificacion_id, curso_nombre, sesiones, fechas, tarifa, neto, impuesto, total, observaciones)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $total = 0;
            foreach ($seleccion as $clave => $g) {
                $tarifaTexto = str_replace(',', '', (string) ($_POST['tarifa'][$clave] ?? $g['tarifa']));
                if (!is_numeric($tarifaTexto) || (float) $tarifaTexto < 0) {
                    throw new InvalidArgumentException('Revisa la tarifa de ' . cengi_pp_h($g['persona']) . '.');
                }
                $tarifa = round((float) $tarifaTexto, 2);
                [$neto, $impuesto, $totalFila] = cengi_pago_calcular($g, $tarifa);
                $stmtDet->execute([$liqId, $g['tipo'], $g['persona_id'], $g['persona'], $g['codigo'], $g['plan_id'], $g['curso'], count($g['sesiones']),
                    implode(',', $g['fechas']), $tarifa, $neto, $impuesto, $totalFila, mb_substr(trim((string) ($_POST['obs'][$clave] ?? '')), 0, 500, 'UTF-8')]);
                $columna = $g['tipo'] === 'instructor' ? 'liquidacion_id' : 'liquidacion_soporte_id';
                $marcar = $db->prepare("UPDATE curso_sesiones SET $columna = ? WHERE $columna IS NULL AND estado = 'impartida' AND id IN (" . implode(',', array_fill(0, count($g['sesiones']), '?')) . ')');
                $marcar->execute(array_merge([$liqId], $g['sesiones']));
                if ($marcar->rowCount() !== count($g['sesiones'])) {
                    throw new InvalidArgumentException('Algunas sesiones cambiaron mientras generabas la liquidación. Vuelve a intentarlo.');
                }
                $total += $totalFila;
            }
            $db->prepare('UPDATE liquidaciones SET total = ? WHERE id = ?')->execute([$total, $liqId]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $liqId, 'msg' => 'generada'];
        } elseif ($accion === 'aprobar') {
            if ($liq['estado'] !== 'enviada') {
                throw new InvalidArgumentException('Solo se aprueban liquidaciones enviadas.');
            }
            $db->prepare("UPDATE liquidaciones SET estado = 'aprobada', aprobada_en = NOW(), aprobada_por = ? WHERE id = ?")->execute([$usuario, $id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'aprobada'];
        } elseif ($accion === 'pagar') {
            $forma = mb_substr(trim((string) ($_POST['pago_forma'] ?? '')), 0, 60, 'UTF-8');
            $referencia = mb_substr(trim((string) ($_POST['pago_referencia'] ?? '')), 0, 120, 'UTF-8');
            $fecha = (string) ($_POST['pago_fecha'] ?? '');
            if ($liq['estado'] !== 'aprobada') {
                throw new InvalidArgumentException('Registra el pago cuando Administración haya aprobado la liquidación.');
            }
            if ($forma === '' || !cengi_pp_es_fecha($fecha)) {
                throw new InvalidArgumentException('Indica la forma y la fecha de pago.');
            }
            $db->prepare("UPDATE liquidaciones SET estado = 'pagada', pago_forma = ?, pago_referencia = ?, pago_fecha = ?, pagada_en = NOW(), pagada_por = ? WHERE id = ?")
                ->execute([$forma, $referencia, $fecha, $usuario, $id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'pagada'];
        } elseif ($accion === 'anular') {
            $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');
            if (!in_array($liq['estado'], ['enviada', 'aprobada'], true)) {
                throw new InvalidArgumentException('Solo se anulan liquidaciones enviadas o aprobadas.');
            }
            if ($motivo === '') {
                throw new InvalidArgumentException('Escribe el motivo de la anulación.');
            }
            $db->prepare('UPDATE curso_sesiones SET liquidacion_id = NULL WHERE liquidacion_id = ?')->execute([$id]);
            $db->prepare('UPDATE curso_sesiones SET liquidacion_soporte_id = NULL WHERE liquidacion_soporte_id = ?')->execute([$id]);
            $db->prepare("UPDATE liquidaciones SET estado = 'anulada', anulada_en = NOW(), anulada_por = ?, motivo_anulacion = ? WHERE id = ?")->execute([$usuario, $motivo, $id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'anulada'];
        } elseif ($accion === 'tarifas') {
            $stmt = $db->prepare('UPDATE instructores SET codigo_catedratico = ?, tarifa_sesion = ? WHERE id = ?');
            foreach ((array) ($_POST['instr'] ?? []) as $instrId => $datos) {
                $tarifa = str_replace(',', '', trim((string) ($datos['tarifa'] ?? '')));
                if ($tarifa !== '' && (!is_numeric($tarifa) || (float) $tarifa < 0)) {
                    throw new InvalidArgumentException('Las tarifas deben ser 0 o mayores.');
                }
                $codigo = mb_substr(strtoupper(trim((string) ($datos['codigo'] ?? ''))), 0, 20, 'UTF-8');
                $stmt->execute([$codigo !== '' ? $codigo : null, $tarifa !== '' ? round((float) $tarifa, 2) : null, (int) $instrId]);
            }
            $extra = ['msg' => 'tarifas'];
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        $db->commit();
        $_GET = array_diff_key($_GET, array_flip(['ver', 'msg', 'tab']));
        cengi_pp_redirigir('pago_instructores.php', $extra);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Pago a instructores: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar los cambios.';
        }
    }
}

if ($mensaje === '' && ($_GET['msg'] ?? '') === 'importado') {
    $mensaje = cengi_pp_h('Carga masiva aplicada: ' . (int) ($_GET['n'] ?? 0) . ' tarifa(s) actualizada(s). Se usan como valor por defecto al liquidar.');
} elseif ($mensaje === '' && isset(CENGI_PAGO_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_PAGO_MENSAJES[$_GET['msg']]);
}

// ---------------------------------------------------------------- liquidacion: descarga de planilla
$verId = (int) ($_GET['ver'] ?? 0);
$liqVer = null;
$liqDetalle = [];
if ($verId) {
    $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
    $stmt->execute([$verId]);
    $liqVer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($liqVer) {
        $stmt = $db->prepare("SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ? ORDER BY tipo = 'soporte', persona_nombre, curso_nombre");
        $stmt->execute([$verId]);
        $liqDetalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
if ($liqVer && ($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Liquidación', $liqVer['numero'], 'Período', cengi_pp_fecha($liqVer['desde']) . ' – ' . cengi_pp_fecha($liqVer['hasta']), 'Dirigida a', $liqVer['destino']], [],
        ['Tipo', 'Nombre', 'Código', 'Curso/Diplomado', 'Fechas', 'Sesiones', 'Costo neto por sesión', 'Total neto', 'Impuesto (5%)', 'Total con impuesto', 'Observaciones']];
    foreach ($liqDetalle as $d) {
        $filas[] = [$d['tipo'] === 'soporte' ? 'Soporte técnico' : 'Instructor', $d['persona_nombre'], $d['persona_codigo'], $d['curso_nombre'],
            implode(', ', array_map('cengi_pp_fecha_corta', array_filter(explode(',', (string) $d['fechas'])))), $d['sesiones'], $d['tarifa'], $d['neto'], $d['impuesto'], $d['total'], $d['observaciones']];
    }
    $filas[] = ['', '', '', '', 'Total', array_sum(array_column($liqDetalle, 'sesiones')), '', array_sum(array_column($liqDetalle, 'neto')), array_sum(array_column($liqDetalle, 'impuesto')), $liqVer['total']];
    cengi_pp_csv('Liquidacion_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $liqVer['numero']), $filas);
}

// ---------------------------------------------------------------- datos
$tab = ($_GET['tab'] ?? '') === 'liquidaciones' ? 'liquidaciones' : 'liquidar';
$periodo = cengi_pago_periodo($_GET);
$persona = preg_match('/^[is]:\d+$/', (string) ($_GET['persona'] ?? '')) ? $_GET['persona'] : '';
$estadoLiq = array_key_exists($_GET['estado'] ?? '', CENGI_PP_ESTADOS_LIQ) ? $_GET['estado'] : '';

$porLiquidar = cengi_pago_por_liquidar($db, $periodo, $persona);
$totalPorLiquidar = 0;
foreach ($porLiquidar as &$g) {
    [$g['neto'], $g['impuesto'], $g['total']] = cengi_pago_calcular($g, (float) $g['tarifa']);
    $totalPorLiquidar += $g['total'];
}
unset($g);

// Sesiones del periodo que ya pasaron y siguen "programadas": no entran al pago hasta marcarlas.
$stmt = $db->prepare("SELECT s.id, s.fecha, s.tema, p.nombre AS curso, i.nombre AS instructor FROM curso_sesiones s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN instructores i ON i.id = s.instructor_id
    WHERE s.estado = 'programada' AND s.fecha BETWEEN ? AND ? AND s.fecha <= ? ORDER BY s.fecha");
$stmt->execute([$periodo['desde'], $periodo['hasta'], $hoy]);
$sinMarcar = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE estado = 'impartida' AND instructor_id IS NULL AND fecha BETWEEN ? AND ?");
$stmt->execute([$periodo['desde'], $periodo['hasta']]);
$sinInstructor = (int) $stmt->fetchColumn();
$sinTarifa = array_filter($porLiquidar, static function ($g) {
    return $g['tarifa'] === null;
});

$resumenLiq = $db->query("SELECT estado, COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM liquidaciones GROUP BY estado")->fetchAll(PDO::FETCH_UNIQUE);
$pagadoAnio = (float) $db->query("SELECT COALESCE(SUM(total), 0) FROM liquidaciones WHERE estado = 'pagada' AND YEAR(pago_fecha) = YEAR(CURDATE())")->fetchColumn();

$sqlLiq = "SELECT l.*, COUNT(DISTINCT CONCAT(d.tipo, d.persona_id)) AS personas, COALESCE(SUM(d.sesiones), 0) AS sesiones
    FROM liquidaciones l LEFT JOIN liquidacion_detalle d ON d.liquidacion_id = l.id"
    . ($estadoLiq !== '' ? ' WHERE l.estado = ?' : '') . ' GROUP BY l.id ORDER BY l.creado DESC';
$stmt = $db->prepare($sqlLiq);
$stmt->execute($estadoLiq !== '' ? [$estadoLiq] : []);
$liquidaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$instructores = cengi_pp_instructores($db, false);
$soportes = cengi_pp_soportes($db, false);
$meses = [];
for ($k = -12; $k <= 2; $k++) {
    $m = date('Y-m', strtotime(date('Y-m-01') . " $k months"));
    $meses[$m] = CENGI_PP_MESES[(int) substr($m, 5, 2)] . ' ' . substr($m, 0, 4);
}
if (!isset($meses[$periodo['mes']])) {
    $meses[$periodo['mes']] = CENGI_PP_MESES[(int) substr($periodo['mes'], 5, 2)] . ' ' . substr($periodo['mes'], 0, 4);
}
krsort($meses);
$numeroSugerido = cengi_pago_numero_libre($db, $periodo['tipo'] === 'mes' ? 'LIQ-' . $periodo['mes'] : 'LIQ-' . date('ymd', strtotime($periodo['desde'])) . '-' . date('ymd', strtotime($periodo['hasta'])));
$filtrosPeriodo = array_filter(['tipo' => $periodo['tipo'], 'mes' => $periodo['tipo'] === 'mes' ? $periodo['mes'] : '', 'desde' => $periodo['tipo'] === 'rango' ? $periodo['desde'] : '',
    'hasta' => $periodo['tipo'] === 'rango' ? $periodo['hasta'] : '', 'persona' => $persona]);
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Pago a instructores'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_pp_subnav('pago_instructores.php'); ?>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <div class="cengi-feedback is-warning" style="margin-bottom:16px;"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-info-sign"></span></div>
        <div><p>Elige un mes o un rango de fechas: el sistema reúne las sesiones <b>impartidas</b> que aún no se han liquidado, incluye el <b>pago del soporte técnico</b>, calcula el total con impuesto (5%) y genera la <b>liquidación para Administración</b>. Cada sesión solo puede estar en una liquidación. Seguimiento: Enviada → Aprobada → Pagada.</p></div></div>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi(cengi_pp_q($totalPorLiquidar), 'Por liquidar · ' . $periodo['label'], count($porLiquidar) . ' fila(s) · ' . array_sum(array_map(static function ($g) {
            return count($g['sesiones']);
        }, $porLiquidar)) . ' sesión(es)', '#8A6600', 'time', '#FFF6DA');
        echo cengi_pp_kpi((int) ($resumenLiq['enviada']['n'] ?? 0), 'Enviadas por aprobar', cengi_pp_q($resumenLiq['enviada']['total'] ?? 0), '#3E7A12', 'send');
        echo cengi_pp_kpi((int) ($resumenLiq['aprobada']['n'] ?? 0), 'Aprobadas por pagar', cengi_pp_q($resumenLiq['aprobada']['total'] ?? 0), '#B34E00', 'check', '#FFE9D9');
        echo cengi_pp_kpi(cengi_pp_q($pagadoAnio), 'Pagado en ' . date('Y'), (int) ($resumenLiq['pagada']['n'] ?? 0) . ' liquidación(es) pagadas', '#1f5632', 'usd');
        ?>
    </div>

    <section class="pp-card">
        <nav class="pp-tabs">
            <a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query($filtrosPeriodo)); ?>" class="<?php echo $tab === 'liquidar' ? 'is-active' : ''; ?>">Por liquidar</a>
            <a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query($filtrosPeriodo + ['tab' => 'liquidaciones'])); ?>" class="<?php echo $tab === 'liquidaciones' ? 'is-active' : ''; ?>">Liquidaciones enviadas <span class="pp-chip"><?php echo count($liquidaciones); ?></span></a>
        </nav>
        <form class="pp-toolbar" method="get">
            <input type="hidden" name="tab" value="<?php echo $tab; ?>">
            <div class="pp-toolbar-group">
                <?php if ($tab === 'liquidar'): ?>
                    <select name="tipo" id="pago-tipo" aria-label="Tipo de período">
                        <option value="mes" <?php echo $periodo['tipo'] === 'mes' ? 'selected' : ''; ?>>Por mes</option>
                        <option value="rango" <?php echo $periodo['tipo'] === 'rango' ? 'selected' : ''; ?>>Por rango de fechas</option>
                    </select>
                    <select name="mes" id="pago-mes" aria-label="Mes" onchange="this.form.submit()" <?php echo $periodo['tipo'] === 'rango' ? 'hidden' : ''; ?>>
                        <?php foreach ($meses as $valor => $texto): ?><option value="<?php echo $valor; ?>" <?php echo $valor === $periodo['mes'] ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                    </select>
                    <span id="pago-rango" <?php echo $periodo['tipo'] === 'mes' ? 'hidden' : ''; ?>>
                        <input type="date" name="desde" value="<?php echo cengi_pp_h($periodo['desde']); ?>" aria-label="Desde"> a
                        <input type="date" name="hasta" value="<?php echo cengi_pp_h($periodo['hasta']); ?>" aria-label="Hasta">
                    </span>
                    <select name="persona" aria-label="Persona" onchange="this.form.submit()">
                        <option value="">Instructores y soporte técnico</option>
                        <optgroup label="Instructores"><?php foreach ($instructores as $i): ?><option value="i:<?php echo (int) $i['id']; ?>" <?php echo $persona === 'i:' . $i['id'] ? 'selected' : ''; ?>><?php echo cengi_pp_h($i['nombre']); ?></option><?php endforeach; ?></optgroup>
                        <optgroup label="Soporte técnico"><?php foreach ($soportes as $sp): ?><option value="s:<?php echo (int) $sp['id']; ?>" <?php echo $persona === 's:' . $sp['id'] ? 'selected' : ''; ?>><?php echo cengi_pp_h($sp['nombre']); ?></option><?php endforeach; ?></optgroup>
                    </select>
                    <button type="submit" class="btn btn-default btn-sm">Ver</button>
                <?php else: ?>
                    <select name="estado" aria-label="Estado de liquidación" onchange="this.form.submit()">
                        <option value="">Todos los estados</option>
                        <?php foreach (CENGI_PP_ESTADOS_LIQ as $clave => [$texto]): ?><option value="<?php echo $clave; ?>" <?php echo $estadoLiq === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="pp-toolbar-group">
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#tarifas-modal"><span class="glyphicon glyphicon-usd"></span> Tarifas y códigos</button>
                <a class="btn btn-default btn-sm" href="carga_masiva_planificacion.php?tipo=tarifa"><span class="glyphicon glyphicon-upload"></span> Cargar tarifas</a>
            </div>
        </form>
    </section>

    <?php if ($tab === 'liquidar'): ?>
        <?php if ($sinMarcar): ?>
            <div class="cengi-feedback is-warning"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-warning-sign"></span></div><div><p>
                <b><?php echo count($sinMarcar); ?> sesión(es) del período ya pasaron y siguen “programadas”</b>: no entran al pago hasta marcarlas como impartidas.<br>
                <?php foreach (array_slice($sinMarcar, 0, 5) as $s): ?>
                    <?php echo cengi_pp_fecha_corta($s['fecha']) . ' · ' . cengi_pp_h($s['curso'] . ' — ' . $s['tema']) . ($s['instructor'] ? ' (' . cengi_pp_h($s['instructor']) . ')' : ''); ?>
                    <a class="pp-link" href="programa_sesiones.php?editar=<?php echo (int) $s['id']; ?>">Revisar</a><br>
                <?php endforeach; ?>
            </p></div></div>
        <?php endif; ?>
        <?php if ($sinInstructor || $sinTarifa): ?>
            <div class="cengi-feedback is-warning"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-info-sign"></span></div><div><p>
                <?php if ($sinInstructor): ?><?php echo $sinInstructor; ?> sesión(es) impartida(s) del período no tienen instructor asignado y no se pueden liquidar.<br><?php endif; ?>
                <?php if ($sinTarifa): ?>Hay <?php echo count($sinTarifa); ?> fila(s) sin tarifa registrada: escríbela abajo o en “Tarifas y códigos”.<?php endif; ?>
            </p></div></div>
        <?php endif; ?>

        <form method="post" id="liquidar-form">
            <input type="hidden" name="accion" value="generar">
            <?php foreach (['tipo' => $periodo['tipo'], 'mes' => $periodo['mes'], 'desde' => $periodo['desde'], 'hasta' => $periodo['hasta'], 'persona' => $persona] as $campo => $valor): ?>
                <input type="hidden" name="<?php echo $campo; ?>" value="<?php echo cengi_pp_h($valor); ?>">
            <?php endforeach; ?>
            <section class="cengi-courses-section">
                <div class="pp-card-head">
                    <div><h3>Sesiones impartidas por liquidar · <?php echo cengi_pp_h($periodo['label']); ?></h3><span class="pp-sub">Marca las filas a incluir. La tarifa se puede ajustar antes de generar la liquidación; después queda fija.</span></div>
                    <button type="button" class="btn btn-primary" id="pago-generar" <?php echo $porLiquidar ? '' : 'disabled'; ?> data-toggle="modal" data-target="#generar-liq-modal">Generar liquidación</button>
                </div>
                <div class="pp-table-wrap">
                    <table class="cengi-org-table">
                        <thead><tr>
                            <th style="width:30px;"><input type="checkbox" id="pago-todos" checked aria-label="Seleccionar todo"></th>
                            <th>Instructor / soporte técnico</th><th>Curso / Diplomado</th><th>Sesiones en el período</th><th>Costo neto / sesión</th>
                            <th>Total neto</th><th>Impuesto (5%)</th><th>Total con impuesto</th><th>Observaciones</th>
                        </tr></thead>
                        <tbody>
                            <?php if (!$porLiquidar): ?>
                                <tr><td colspan="9" class="pp-empty">No hay sesiones impartidas pendientes de liquidar en <?php echo cengi_pp_h($periodo['label']); ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($porLiquidar as $clave => $g): ?>
                                <tr data-sesiones="<?php echo count($g['sesiones']); ?>">
                                    <td><input type="checkbox" name="sel[]" value="<?php echo cengi_pp_h($clave); ?>" checked aria-label="Incluir"></td>
                                    <td><strong><?php echo cengi_pp_h($g['persona']); ?></strong>
                                        <span class="pp-sub"><?php echo $g['tipo'] === 'soporte' ? '<span class="pp-chip is-yellow">Soporte técnico</span> ' : ''; ?><span class="pp-mono"><?php echo cengi_pp_h($g['codigo'] ?: 'Sin código'); ?></span></span></td>
                                    <td><?php echo cengi_pp_h($g['curso']); ?></td>
                                    <td class="pp-num"><?php echo count($g['sesiones']); ?><span class="pp-sub"><?php echo cengi_pp_h(implode(', ', array_map('cengi_pp_fecha_corta', $g['fechas']))); ?></span></td>
                                    <td><input type="number" class="pp-input-inline" name="tarifa[<?php echo cengi_pp_h($clave); ?>]" min="0" step="0.01" value="<?php echo $g['tarifa'] !== null ? number_format($g['tarifa'], 2, '.', '') : ''; ?>" placeholder="0.00" aria-label="Tarifa por sesión"></td>
                                    <td class="pp-num" data-neto><?php echo cengi_pp_q($g['neto'], 2); ?></td>
                                    <td class="pp-num" data-imp><?php echo cengi_pp_q($g['impuesto'], 2); ?></td>
                                    <td class="pp-num" data-total style="font-weight:700;"><?php echo cengi_pp_q($g['total'], 2); ?></td>
                                    <td><input type="text" class="pp-input-obs" name="obs[<?php echo cengi_pp_h($clave); ?>]" maxlength="500" value="<?php echo cengi_pp_h($g['obs']); ?>" aria-label="Observaciones"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($porLiquidar): ?>
                            <tfoot><tr><td colspan="5">Total seleccionado</td><td class="pp-num" id="pago-neto"></td><td class="pp-num" id="pago-imp"></td><td class="pp-num" id="pago-total"></td><td></td></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </section>

            <div class="modal fade cengi-course-form-modal pp-modal" id="generar-liq-modal" tabindex="-1" role="dialog" aria-labelledby="generar-liq-title">
                <div class="modal-dialog" role="document"><div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                        <h4 class="modal-title" id="generar-liq-title">Generar liquidación</h4>
                        <p>Período <?php echo cengi_pp_h($periodo['label']); ?> · <span id="gl-resumen"></span></p>
                    </div>
                    <div class="modal-body">
                        <div class="cengi-course-modal-grid">
                            <div class="form-group"><label for="gl-numero">N.° de liquidación</label><input type="text" class="form-control" id="gl-numero" name="numero" maxlength="40" value="<?php echo cengi_pp_h($numeroSugerido); ?>" required></div>
                            <div class="form-group"><label for="gl-destino">Dirigida a</label><input type="text" class="form-control" id="gl-destino" name="destino" maxlength="255" value="Administración"></div>
                            <div class="form-group is-full"><label for="gl-nota">Nota para Administración <small>(opcional)</small></label><textarea class="form-control" id="gl-nota" name="nota" rows="3"></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Generar y enviar</button>
                    </div>
                </div></div>
            </div>
        </form>
    <?php else: ?>
        <section class="cengi-courses-section">
            <div class="pp-table-wrap">
                <table class="cengi-org-table">
                    <thead><tr><th>N.° liquidación</th><th>Período</th><th>Generada</th><th>Personas</th><th>Sesiones</th><th>Total con impuesto</th><th>Estado</th><th>Pago</th><th></th></tr></thead>
                    <tbody>
                        <?php if (!$liquidaciones): ?><tr><td colspan="9" class="pp-empty">Aún no hay liquidaciones.</td></tr><?php endif; ?>
                        <?php foreach ($liquidaciones as $l): ?>
                            <tr class="<?php echo $l['estado'] === 'anulada' ? 'pp-muted-row' : ''; ?>">
                                <td class="pp-mono"><strong><?php echo cengi_pp_h($l['numero']); ?></strong></td>
                                <td class="pp-num"><?php echo cengi_pp_fecha($l['desde']) . ' – ' . cengi_pp_fecha($l['hasta']); ?></td>
                                <td class="pp-num"><?php echo cengi_pp_fecha($l['creado']); ?><span class="pp-sub"><?php echo cengi_pp_h($l['creado_por_nombre']); ?></span></td>
                                <td><?php echo (int) $l['personas']; ?></td>
                                <td><?php echo (int) $l['sesiones']; ?></td>
                                <td class="pp-num"><strong><?php echo cengi_pp_q($l['total'], 2); ?></strong></td>
                                <td><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_LIQ, $l['estado']); ?></td>
                                <td><?php echo $l['pago_fecha'] ? cengi_pp_h($l['pago_forma'] . ' ' . $l['pago_referencia']) . '<span class="pp-sub">' . cengi_pp_fecha($l['pago_fecha']) . '</span>' : '—'; ?></td>
                                <td><a class="btn btn-default btn-sm" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'liquidaciones', 'estado' => $estadoLiq, 'ver' => $l['id']])); ?>">Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="tarifas-modal" tabindex="-1" role="dialog" aria-labelledby="tarifas-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post">
            <input type="hidden" name="accion" value="tarifas">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <h4 class="modal-title" id="tarifas-title">Tarifas y códigos de catedrático</h4>
                <p>La tarifa por sesión es el valor por defecto al liquidar; las liquidaciones ya generadas conservan la tarifa con la que se enviaron. La tarifa del soporte técnico se edita en Programa de sesiones → Soporte técnico.</p>
            </div>
            <div class="modal-body">
                <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:520px;">
                    <thead><tr><th>Instructor</th><th>Código de catedrático</th><th>Tarifa por sesión (Q)</th></tr></thead>
                    <tbody>
                        <?php foreach ($instructores as $i): ?>
                            <tr class="<?php echo (int) $i['estado'] !== 1 ? 'pp-muted-row' : ''; ?>">
                                <td><?php echo cengi_pp_h($i['nombre']); ?><?php echo (int) $i['estado'] !== 1 ? ' <span class="pp-chip">Inactivo</span>' : ''; ?></td>
                                <td><input type="text" class="pp-input-obs" name="instr[<?php echo (int) $i['id']; ?>][codigo]" maxlength="20" value="<?php echo cengi_pp_h($i['codigo_catedratico']); ?>" placeholder="CAT-000" aria-label="Código de <?php echo cengi_pp_h($i['nombre']); ?>"></td>
                                <td><input type="number" class="pp-input-inline" name="instr[<?php echo (int) $i['id']; ?>][tarifa]" min="0" step="0.01" value="<?php echo cengi_pp_h($i['tarifa_sesion']); ?>" aria-label="Tarifa de <?php echo cengi_pp_h($i['nombre']); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div></div>
</div>

<?php if ($liqVer):
    $porPersona = [];
    foreach ($liqDetalle as $d) {
        $porPersona[$d['persona_nombre']] = ($porPersona[$d['persona_nombre']] ?? 0) + $d['total'];
    }
    $volver = cengi_pp_h(http_build_query(['tab' => 'liquidaciones', 'estado' => $estadoLiq]));
?>
<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="liq-modal" tabindex="-1" role="dialog" aria-labelledby="liq-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header">
            <a class="close" href="pago_instructores.php?<?php echo $volver; ?>" aria-label="Cerrar"><span>&times;</span></a>
            <span class="cengi-course-modal-kicker">Liquidación</span>
            <h4 class="modal-title" id="liq-title"><?php echo cengi_pp_h($liqVer['numero']); ?></h4>
            <p>Período <?php echo cengi_pp_fecha($liqVer['desde']) . ' – ' . cengi_pp_fecha($liqVer['hasta']); ?> · <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_LIQ, $liqVer['estado']); ?></p>
        </div>
        <div class="modal-body">
            <div class="pp-strip">
                <div class="pp-mini-stat"><span>Dirigida a</span><strong style="font-size:13px;"><?php echo cengi_pp_h($liqVer['destino']); ?></strong></div>
                <div class="pp-mini-stat"><span>Sesiones</span><strong><?php echo array_sum(array_column($liqDetalle, 'sesiones')); ?></strong></div>
                <div class="pp-mini-stat"><span>Total neto</span><strong><?php echo cengi_pp_q(array_sum(array_column($liqDetalle, 'neto')), 2); ?></strong></div>
                <div class="pp-mini-stat"><span>Impuesto 5%</span><strong><?php echo cengi_pp_q(array_sum(array_column($liqDetalle, 'impuesto')), 2); ?></strong></div>
                <div class="pp-mini-stat"><span>Total a pagar</span><strong><?php echo cengi_pp_q($liqVer['total'], 2); ?></strong></div>
            </div>
            <?php if (trim((string) $liqVer['nota']) !== ''): ?><div class="pp-msgbox" style="margin-bottom:14px;"><?php echo cengi_pp_h($liqVer['nota']); ?></div><?php endif; ?>
            <div class="pp-table-wrap"><table class="cengi-org-table">
                <thead><tr><th>Instructor / soporte</th><th>Curso</th><th>Fechas</th><th>Sesiones</th><th>Tarifa</th><th>Neto</th><th>Impuesto</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach ($liqDetalle as $d): ?>
                        <tr>
                            <td><strong><?php echo cengi_pp_h($d['persona_nombre']); ?></strong><span class="pp-sub"><?php echo $d['tipo'] === 'soporte' ? '<span class="pp-chip is-yellow">Soporte técnico</span> ' : ''; ?><span class="pp-mono"><?php echo cengi_pp_h($d['persona_codigo']); ?></span></span>
                                <?php if ($d['observaciones'] !== ''): ?><span class="pp-sub"><i><?php echo cengi_pp_h($d['observaciones']); ?></i></span><?php endif; ?></td>
                            <td><?php echo cengi_pp_h($d['curso_nombre']); ?></td>
                            <td style="font-size:11.5px;"><?php echo cengi_pp_h(implode(', ', array_map('cengi_pp_fecha_corta', array_filter(explode(',', (string) $d['fechas']))))); ?></td>
                            <td class="pp-num"><?php echo (int) $d['sesiones']; ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($d['tarifa'], 2); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($d['neto'], 2); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($d['impuesto'], 2); ?></td>
                            <td class="pp-num"><strong><?php echo cengi_pp_q($d['total'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7">Total</td><td class="pp-num"><?php echo cengi_pp_q($liqVer['total'], 2); ?></td></tr></tfoot>
            </table></div>
            <div class="pp-two-col" style="margin-top:16px;">
                <div>
                    <h5 style="font-weight:700;">Total por persona</h5>
                    <?php foreach ($porPersona as $nombre => $total): ?><div class="pp-mini-row"><span><?php echo cengi_pp_h($nombre); ?></span><b class="pp-num"><?php echo cengi_pp_q($total, 2); ?></b></div><?php endforeach; ?>
                </div>
                <div>
                    <h5 style="font-weight:700;">Seguimiento</h5>
                    <div class="pp-timeline">
                        <div class="pp-timeline-item is-ok"><span class="pp-sub"><?php echo cengi_pp_fecha($liqVer['creado']) . ' · ' . cengi_pp_h($liqVer['creado_por_nombre']); ?></span>Enviada a <?php echo cengi_pp_h($liqVer['destino']); ?></div>
                        <?php if ($liqVer['aprobada_en']): ?><div class="pp-timeline-item is-ok"><span class="pp-sub"><?php echo cengi_pp_fecha($liqVer['aprobada_en']) . ' · ' . cengi_pp_h($liqVer['aprobada_por']); ?></span>Aprobada por Administración</div><?php endif; ?>
                        <?php if ($liqVer['pagada_en']): ?><div class="pp-timeline-item is-ok"><span class="pp-sub"><?php echo cengi_pp_fecha($liqVer['pago_fecha']) . ' · ' . cengi_pp_h($liqVer['pagada_por']); ?></span>Pagada: <?php echo cengi_pp_h($liqVer['pago_forma'] . ' ' . $liqVer['pago_referencia']); ?></div><?php endif; ?>
                        <?php if ($liqVer['anulada_en']): ?><div class="pp-timeline-item is-warn"><span class="pp-sub"><?php echo cengi_pp_fecha($liqVer['anulada_en']) . ' · ' . cengi_pp_h($liqVer['anulada_por']); ?></span>Anulada: <?php echo cengi_pp_h($liqVer['motivo_anulacion']); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($liqVer['estado'] === 'aprobada'): ?>
                <form method="post" id="pagar-form" style="margin-top:16px;" hidden>
                    <input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <h5 style="font-weight:700;">Registrar pago</h5>
                    <div class="cengi-course-modal-grid">
                        <div class="form-group"><label for="pg-forma">Forma de pago</label><select class="form-control" id="pg-forma" name="pago_forma"><option>Transferencia</option><option>Cheque</option><option>Depósito</option><option>Efectivo</option></select></div>
                        <div class="form-group"><label for="pg-ref">Referencia</label><input type="text" class="form-control" id="pg-ref" name="pago_referencia" maxlength="120" placeholder="N.° de transferencia o cheque"></div>
                        <div class="form-group"><label for="pg-fecha">Fecha de pago</label><input type="date" class="form-control" id="pg-fecha" name="pago_fecha" value="<?php echo $hoy; ?>" required></div>
                    </div>
                    <div style="text-align:right;margin-top:10px;"><button type="submit" class="btn btn-primary">Guardar pago</button></div>
                </form>
            <?php endif; ?>
            <?php if (in_array($liqVer['estado'], ['enviada', 'aprobada'], true)): ?>
                <form method="post" id="anular-form" style="margin-top:16px;" hidden>
                    <input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <div class="form-group"><label for="an-motivo">Motivo de la anulación <small>(las sesiones vuelven a “por liquidar”)</small></label><input type="text" class="form-control" id="an-motivo" name="motivo" maxlength="500" required></div>
                    <div style="text-align:right;"><button type="submit" class="btn btn-danger">Anular liquidación</button></div>
                </form>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <?php if (in_array($liqVer['estado'], ['enviada', 'aprobada'], true)): ?><button type="button" class="btn btn-link" onclick="document.getElementById('anular-form').hidden=false;document.getElementById('an-motivo').focus();">Anular</button><?php endif; ?>
            <a class="btn btn-default" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['ver' => $liqVer['id'], 'exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar planilla</a>
            <?php if ($liqVer['estado'] === 'enviada'): ?>
                <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>"><button type="submit" class="btn btn-primary">Aprobada por Administración</button></form>
            <?php elseif ($liqVer['estado'] === 'aprobada'): ?>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('pagar-form').hidden=false;document.getElementById('pg-ref').focus();">Registrar pago</button>
            <?php else: ?>
                <a class="btn btn-primary" href="pago_instructores.php?<?php echo $volver; ?>">Cerrar</a>
            <?php endif; ?>
        </div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var IMPUESTO = <?php echo CENGI_PP_IMPUESTO; ?>;
    function q(n) { return 'Q' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    var tipo = document.getElementById('pago-tipo');
    if (tipo) tipo.addEventListener('change', function () {
        document.getElementById('pago-mes').hidden = this.value === 'rango';
        document.getElementById('pago-rango').hidden = this.value !== 'rango';
        if (this.value === 'mes') this.form.submit();
    });
    var form = document.getElementById('liquidar-form');
    function recalcular() {
        if (!form) return;
        var neto = 0, imp = 0, filas = 0, personas = {};
        form.querySelectorAll('tbody tr[data-sesiones]').forEach(function (tr) {
            var t = parseFloat(tr.querySelector('input[type="number"]').value) || 0, n = +tr.getAttribute('data-sesiones');
            var fn = Math.round(t * n * 100) / 100, fi = Math.round(fn * IMPUESTO * 100) / 100;
            tr.querySelector('[data-neto]').textContent = q(fn); tr.querySelector('[data-imp]').textContent = q(fi); tr.querySelector('[data-total]').textContent = q(fn + fi);
            if (tr.querySelector('input[type="checkbox"]').checked) { neto += fn; imp += fi; filas++; personas[tr.querySelector('strong').textContent] = 1; }
        });
        if (document.getElementById('pago-total')) {
            document.getElementById('pago-neto').textContent = q(neto); document.getElementById('pago-imp').textContent = q(imp); document.getElementById('pago-total').textContent = q(neto + imp);
        }
        var b = document.getElementById('pago-generar'); if (b) b.disabled = !filas;
        var r = document.getElementById('gl-resumen'); if (r) r.textContent = Object.keys(personas).length + ' persona(s) · ' + filas + ' fila(s) · ' + q(neto + imp) + ' con impuesto';
    }
    if (form) {
        form.addEventListener('input', recalcular); form.addEventListener('change', recalcular);
        var todos = document.getElementById('pago-todos');
        if (todos) todos.addEventListener('change', function () { form.querySelectorAll('input[name="sel[]"]').forEach(function (c) { c.checked = todos.checked; }); recalcular(); });
        recalcular();
    }
    if (document.getElementById('liq-modal')) {
        $('#liq-modal').modal({ backdrop: 'static', show: true });
    }
})();
</script>
</body>
</html>
