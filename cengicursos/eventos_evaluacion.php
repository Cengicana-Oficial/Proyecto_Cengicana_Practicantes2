<?php
/**
 * Evaluación de eventos: percepción de participantes, tasa de respuesta con meta configurable,
 * colaboración de empresas aliadas y comentarios clasificados por tema.
 * Antes era la pestaña «Evaluación de eventos» de eventos_qr.php (que ahora redirige aquí).
 * El informe y las encuestas de cada evento siguen en eventos_qr.php (?informe=ID / ?encuestas=ID).
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/eventos_evaluacion_helpers.php';
require_once __DIR__ . '/planificacion_helpers.php';
cengi_require_ver_eventos();
date_default_timezone_set('America/Guatemala');
$db = conectar();
$h = static function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$hoy = date('Y-m-d');

// Dos vistas: resultados por evento y comentarios (temas de mejora).
$vista = ($_GET['vista'] ?? '') === 'comentarios' ? 'comentarios' : 'eventos';
$anio = (int) ($_GET['anio'] ?? 0);
if ($anio < 2000 || $anio > 2100) {
    $anio = 0;
}
$tipo = in_array($_GET['tipo'] ?? '', CENGI_EVT_TIPOS, true) ? $_GET['tipo'] : '';
$colabF = in_array($_GET['colab'] ?? '', ['si', 'no'], true) ? $_GET['colab'] : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
$soloBajo = !empty($_GET['bajo']);
$ordenes = ['fecha' => 'Más recientes', 'percepcion' => 'Mejor percepción', 'tasa' => 'Menor tasa de respuesta', 'colaboracion' => 'Mejor índice de colaboración', 'nombre' => 'Nombre (A–Z)'];
$orden = isset($ordenes[$_GET['orden'] ?? '']) ? $_GET['orden'] : 'fecha';
$origenes = ['participantes' => 'Participantes', 'aliados' => 'Empresas aliadas'];
$origenF = isset($origenes[$_GET['origen'] ?? '']) ? $_GET['origen'] : '';
$temaF = mb_substr(trim((string) ($_GET['tema'] ?? '')), 0, 80);
$eventoF = max(0, (int) ($_GET['evento'] ?? 0));

// Meta de respuesta configurable (eventos_config, clave meta_eval_evento).
$puedeConfigurarMeta = cengi_es_admin() || cengi_puede_gestionar_eventos();
if (empty($_SESSION['eval_evento_csrf'])) {
    $_SESSION['eval_evento_csrf'] = bin2hex(random_bytes(16));
}
$errorMeta = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_meta') {
    try {
        if (!$puedeConfigurarMeta) {
            throw new InvalidArgumentException('No tienes permiso para cambiar la meta.');
        }
        if (!hash_equals($_SESSION['eval_evento_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Recarga la página e inténtalo de nuevo.');
        }
        cengi_evg_meta_guardar($db, $_POST['porcentaje'] ?? '', cengi_pp_usuario_nombre());
        header('Location: eventos_evaluacion.php?meta_guardada=1#configurar-meta');
        exit;
    } catch (InvalidArgumentException $e) {
        $errorMeta = $e->getMessage();
    }
}
$configMeta = cengi_evg_meta_config($db);
$meta = (int) $configMeta['porcentaje'];

// ---------------------------------------------------------------- datos
$todos = [];
foreach (cengi_evg_datos($db) as $e) {
    if ($e['estado'] === 'Cancelado') {
        continue; // como en el Informe de gestión: los cancelados no se evalúan
    }
    $e['res'] = cengi_evg_resumen($e, $meta, $hoy);
    $todos[] = $e;
}
$years = array_values(array_unique(array_filter(array_map(static function ($e) { return (int) substr((string) $e['fecha'], 0, 4); }, $todos))));
rsort($years);
$eventos = array_values(array_filter($todos, static function ($e) use ($anio, $tipo, $colabF, $q) {
    return (!$anio || (int) substr((string) $e['fecha'], 0, 4) === $anio)
        && ($tipo === '' || $e['tipo'] === $tipo)
        && ($colabF === '' || ($colabF === 'si') === (bool) $e['en_colaboracion'])
        && ($q === '' || mb_stripos($e['nombre'] . ' ' . $e['lugar'] . ' ' . implode(' ', array_column($e['aliados'], 'nombre')), $q) !== false);
}));

// Indicadores sobre lo filtrado (antes de «solo bajo la meta»).
$realizados = array_values(array_filter($eventos, static function ($e) use ($hoy) { return (string) $e['fecha'] <= $hoy; }));
$conResp = array_filter($eventos, static function ($e) { return $e['res']['n'] > 0; });
$estrellasTodas = [];
foreach ($eventos as $e) {
    $estrellasTodas = array_merge($estrellasTodas, array_column($e['percep'], 'estrellas'));
}
$totalResp = count($estrellasTodas);
$cuentan = array_filter($eventos, static function ($e) { return $e['res']['cuenta']; });
$sumAsist = array_sum(array_map(static function ($e) { return (int) $e['asistentes']; }, $cuentan));
$sumRespCuentan = array_sum(array_map(static function ($e) { return min($e['res']['n'], (int) $e['asistentes']); }, $cuentan));
$colabTodas = [];
foreach ($eventos as $e) {
    foreach ($e['colab'] as $c) {
        $colabTodas[] = $c;
    }
}
$itemsGlobal = [];
foreach (CENGI_EVT_COLAB_ITEMS as $i) {
    $itemsGlobal[$i['k']] = cengi_evg_promedio(array_map(static function ($c) use ($i) { return $c['r'][$i['k']]; }, $colabTodas));
}
$kpi = [
    'eventos' => count($eventos),
    'realizados' => count($realizados),
    'con_resp' => count($conResp),
    'respuestas' => $totalResp,
    'prom' => $totalResp ? array_sum($estrellasTodas) / $totalResp : null,
    'satisf' => $totalResp ? (int) round(count(array_filter($estrellasTodas, static function ($x) { return $x >= 4; })) / $totalResp * 100) : null,
    'evaluables' => count($cuentan),
    'cumple' => count(array_filter($cuentan, static function ($e) { return $e['res']['estado'] === 'cumple'; })),
    'bajo' => count(array_filter($cuentan, static function ($e) { return $e['res']['estado'] !== 'cumple'; })),
    'tasa' => $sumAsist ? (int) round($sumRespCuentan / $sumAsist * 100) : null,
    'indice' => cengi_evg_promedio(array_values($itemsGlobal)),
    'colab_eventos' => count(array_filter($eventos, static function ($e) { return $e['en_colaboracion']; })),
    'aliados_pend' => array_sum(array_map(static function ($e) { return $e['en_colaboracion'] ? max(0, count($e['aliados']) - count($e['colab'])) : 0; }, $eventos)),
];

// Comparación por tipo de evento (solo realizados).
$porTipo = [];
foreach ($realizados as $e) {
    $t = $e['tipo'] ?: 'Sin tipo';
    $porTipo[$t] = $porTipo[$t] ?? ['tipo' => $t, 'eventos' => 0, 'asist' => 0, 'estrellas' => []];
    $porTipo[$t]['eventos']++;
    $porTipo[$t]['asist'] += (int) $e['asistentes'];
    $porTipo[$t]['estrellas'] = array_merge($porTipo[$t]['estrellas'], array_column($e['percep'], 'estrellas'));
}
foreach ($porTipo as &$t) {
    $t['n'] = count($t['estrellas']);
    $t['prom'] = $t['n'] ? array_sum($t['estrellas']) / $t['n'] : null;
}
unset($t);
uasort($porTipo, static function ($a, $b) { return [$b['prom'] !== null, (float) $b['prom'], $b['eventos']] <=> [$a['prom'] !== null, (float) $a['prom'], $a['eventos']]; });

// Lista de eventos de la vista principal.
$lista = $soloBajo ? array_values(array_filter($eventos, static function ($e) { return in_array($e['res']['estado'], ['abierto', 'cerrado'], true); })) : $eventos;
if ($orden !== 'fecha') {
    usort($lista, static function ($a, $b) use ($orden) {
        switch ($orden) {
            case 'nombre':
                return strcasecmp($a['nombre'], $b['nombre']);
            case 'tasa': // sin tasa al final
                return [$a['res']['tasa'] === null, (int) $a['res']['tasa'], $a['nombre']] <=> [$b['res']['tasa'] === null, (int) $b['res']['tasa'], $b['nombre']];
            default:
                $k = $orden === 'percepcion' ? 'prom' : 'indice';
                return [$b['res'][$k] !== null, (float) $b['res'][$k], $a['nombre']] <=> [$a['res'][$k] !== null, (float) $a['res'][$k], $b['nombre']];
        }
    });
}

// Comentarios: oportunidades de mejora de participantes y respuestas abiertas de las empresas aliadas.
$comentarios = [];
foreach ($eventos as $e) {
    foreach ($e['percep'] as $r) {
        if (trim($r['mejora']) !== '') {
            $comentarios[] = ['texto' => $r['mejora'], 'origen' => 'participantes', 'pregunta' => 'Oportunidad de mejora', 'quien' => $r['participante'] ?: 'Anónimo (enlace general / QR)',
                'institucion' => (string) $r['institucion'], 'estrellas' => $r['estrellas'], 'ts' => $r['ts'], 'evento' => $e];
        }
    }
    foreach ($e['colab'] as $c) {
        foreach (['mejorar' => '¿Qué debería mejorar el Centro?', 'apoyo' => 'Apoyo o valor deseado'] as $campo => $pregunta) {
            if (trim($c[$campo]) !== '') {
                $comentarios[] = ['texto' => $c[$campo], 'origen' => 'aliados', 'pregunta' => $pregunta, 'quien' => $c['empresa'] . ($c['nombre'] !== '' ? ' · ' . $c['nombre'] : ''),
                    'institucion' => '', 'estrellas' => null, 'ts' => $c['ts'], 'evento' => $e];
            }
        }
    }
}
foreach ($comentarios as &$c) {
    $c['tema'] = cengi_evg_clasificar($c['texto']);
}
unset($c);
usort($comentarios, static function ($a, $b) { return strcmp((string) $b['ts'], (string) $a['ts']); });
// Lista de eventos para el filtro (con su número de comentarios) y filtro por evento.
$comentariosPorEvento = array_count_values(array_map(static function ($c) { return $c['evento']['id']; }, $comentarios));
$eventoSel = null;
foreach ($eventos as $e) {
    if ($e['id'] === $eventoF) {
        $eventoSel = $e;
    }
}
if ($eventoF) {
    $comentarios = array_values(array_filter($comentarios, static function ($c) use ($eventoF) { return $c['evento']['id'] === $eventoF; }));
}
$comentariosOrigen = $origenF === '' ? $comentarios : array_values(array_filter($comentarios, static function ($c) use ($origenF) { return $c['origen'] === $origenF; }));
$temas = [];
foreach ($comentariosOrigen as $c) {
    $temas[$c['tema']] = ($temas[$c['tema']] ?? 0) + 1;
}
// Temas de mejora primero (por cantidad); «Comentarios positivos» y «Otros» al final.
uksort($temas, static function ($a, $b) use ($temas) {
    $pa = in_array($a, ['Comentarios positivos', 'Otros'], true);
    $pb = in_array($b, ['Comentarios positivos', 'Otros'], true);
    return [$pa, $a === 'Otros', -$temas[$a], $a] <=> [$pb, $b === 'Otros', -$temas[$b], $b];
});
$maxTema = $temas ? max($temas) : 0;
$comentariosVisibles = $temaF === '' ? $comentariosOrigen : array_values(array_filter($comentariosOrigen, static function ($c) use ($temaF) { return $c['tema'] === $temaF; }));

// ---------------------------------------------------------------- formato
$nivelEstrellas = static function ($v) { return $v === null ? 'is-sin' : ($v >= 4.2 ? 'is-bien' : ($v >= 3.5 ? 'is-medio' : 'is-bajo')); };
$nivelPct = static function ($v) { return $v === null ? 'is-sin' : ($v >= 85 ? 'is-bien' : ($v >= 70 ? 'is-medio' : 'is-bajo')); };
$est = static function ($v) { return $v === null ? '—' : number_format((float) $v, 1) . ' ★'; };
$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$fecha = static function ($f) use ($meses) {
    $ts = $f ? strtotime((string) $f) : false;
    return $ts ? date('d', $ts) . ' ' . $meses[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts) : '—';
};
$descripcion = ($anio ? 'Eventos de ' . $anio : 'Todos los años') . ($tipo !== '' ? ' · ' . $tipo : '') . ($colabF === 'si' ? ' · En colaboración' : ($colabF === 'no' ? ' · Sin colaboración' : ''))
    . ($q !== '' ? ' · Búsqueda: ' . $q : '') . ($vista === 'eventos' && $soloBajo ? ' · Solo bajo la meta' : '')
    . ($vista === 'comentarios' && $origenF !== '' ? ' · ' . $origenes[$origenF] : '') . ($vista === 'comentarios' && $temaF !== '' ? ' · Tema: ' . $temaF : '')
    . ($vista === 'comentarios' && $eventoSel ? ' · Evento: ' . $eventoSel['nombre'] : '');
$textoColab = static function ($e) {
    if (!$e['en_colaboracion']) {
        return 'No es colaboración';
    }
    return $e['res']['aliados_resp'] . ' de ' . $e['res']['aliados'] . ' aliada' . ($e['res']['aliados'] === 1 ? '' : 's');
};

// ---------------------------------------------------------------- Excel
if (($_GET['exportar'] ?? '') === 'excel') {
    require_once __DIR__ . '/classes/export_helpers.php';
    $filas = [];
    if ($vista === 'comentarios') {
        foreach ($comentariosVisibles as $c) {
            $filas[] = [$c['evento']['nombre'], (string) $c['evento']['fecha'], $c['evento']['tipo'], $origenes[$c['origen']], $c['pregunta'], $c['quien'], $c['institucion'],
                $c['estrellas'] === null ? '' : (string) $c['estrellas'], $c['tema'], $c['texto'], substr((string) $c['ts'], 0, 16)];
        }
        cengi_export_enviar_excel(['Evento', 'Fecha del evento', 'Tipo', 'Encuesta', 'Pregunta', 'Quién responde', 'Institución', 'Estrellas', 'Tema', 'Comentario', 'Respondido'],
            $filas, 'Comentarios', 'comentarios-eventos' . ($anio ? '-' . $anio : ''), [34, 14, 16, 16, 28, 30, 26, 10, 26, 70, 17], 'xlsx');
    }
    foreach ($lista as $e) {
        $r = $e['res'];
        $fila = [$e['nombre'], (string) $e['fecha'], $e['tipo'], $e['estado'], (string) $e['confirmados'], (string) $e['asistentes'], (string) $r['n'], (string) $r['personales'],
            $r['tasa'] === null ? '—' : $r['tasa'] . '%', CENGI_EVG_ESTADOS_META[$r['estado']][0],
            $r['prom'] === null ? '' : number_format($r['prom'], 2, '.', ''), $r['satisf'] === null ? '' : $r['satisf'] . '%', $r['tema'],
            $e['en_colaboracion'] ? 'Sí' : 'No', $e['en_colaboracion'] ? (string) $r['aliados'] : '', $e['en_colaboracion'] ? (string) $r['aliados_resp'] : '',
            $r['indice'] === null ? '' : number_format($r['indice'], 2, '.', '')];
        foreach (CENGI_EVT_COLAB_ITEMS as $i) {
            $fila[] = $r['items'][$i['k']] === null ? '' : number_format($r['items'][$i['k']], 2, '.', '');
        }
        $filas[] = $fila;
    }
    cengi_export_enviar_excel(array_merge(['Evento', 'Fecha', 'Tipo', 'Estado', 'Confirmados', 'Asistentes', 'Respuestas de participantes', 'Con enlace personal', 'Tasa de respuesta',
        'Meta del ' . $meta . ' %', 'Percepción (1–5)', '% satisfechos (4–5 ★)', 'Tema de mejora principal', 'En colaboración', 'Empresas aliadas', 'Respondieron', 'Índice de colaboración (1–5)'],
        array_column(CENGI_EVT_COLAB_ITEMS, 't')),
        $filas, 'Evaluación', 'evaluacion-eventos' . ($anio ? '-' . $anio : ''), [34, 12, 16, 12, 12, 11, 14, 14, 12, 24, 13, 14, 26, 13, 12, 12, 14], 'xlsx');
}

// ---------------------------------------------------------------- PDF
if (($_GET['pdf'] ?? '') === '1') {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/classes/export_helpers.php';
    $color = static function ($clase) { return ['is-bien' => '#2f6a12', 'is-medio' => '#7a6000', 'is-bajo' => '#b34a00', 'is-sin' => '#8a968f'][$clase]; };
    $logoRuta = __DIR__ . '/img/logo-comite-capacitacion-pdf.png'; // versión liviana para Dompdf
    $logo = is_file($logoRuta) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoRuta)) : '';
    $tituloPdf = $vista === 'comentarios' ? 'Comentarios y temas de mejora de eventos' : 'Evaluación de eventos';
    ob_start(); ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
    @page { margin: 26px 28px 34px; }
    body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #263238; font-size: 9px; }
    table { border-collapse: collapse; width: 100%; }
    .banda { background: #1f5632; color: #fff; border-radius: 8px; }
    .banda td { padding: 12px 16px; vertical-align: middle; }
    .banda small { color: #D9F2A6; font-size: 7.5px; letter-spacing: .1em; text-transform: uppercase; }
    .banda h1 { margin: 3px 0 2px; font-size: 17px; }
    .banda p { margin: 0; font-size: 8.5px; color: #e3efd9; }
    .logo { background: #fff; border-radius: 6px; padding: 4px 7px; } .logo img { height: 34px; }
    .franja td { height: 4px; padding: 0; }
    .kpis { margin: 12px 0; } .kpis td { width: 20%; padding: 0 4px; }
    .kpi { border: 1px solid #e0e5dc; border-top: 3px solid #73BC25; border-radius: 6px; padding: 7px 9px; }
    .kpi b { display: block; font-size: 15px; color: #1f5632; } .kpi span { font-size: 7.5px; color: #55605a; }
    h2 { font-size: 11px; color: #1f5632; margin: 14px 0 6px; }
    .datos th { background: #f2f9e9; color: #1f5632; font-size: 7.5px; text-align: left; padding: 6px 5px; border-bottom: 1px solid #cfe3b6; }
    .datos td { padding: 5px; border-bottom: 1px solid #e8ece4; vertical-align: top; }
    .datos thead { display: table-header-group; } .datos tr { page-break-inside: avoid; }
    .datos .num { text-align: center; white-space: nowrap; font-weight: bold; }
    .sub { color: #6b7a72; font-size: 7.5px; }
    .dos > tbody > tr > td { width: 50%; vertical-align: top; padding: 0 6px; }
    .nota { margin-top: 8px; color: #6b7a72; font-size: 7px; }
    .pie { position: fixed; bottom: -20px; left: 0; font-size: 7px; color: #6b7a72; }
</style></head><body>
<table class="banda"><tr><td><small>Eventos · evaluación</small><h1><?= $h($tituloPdf) ?></h1><p><?= $h($descripcion) ?> · Generado el <?= date('d/m/Y H:i') ?></p></td>
<?php if ($logo !== ''): ?><td style="text-align:right;width:140px"><span class="logo"><img src="<?= $logo ?>" alt=""></span></td><?php endif; ?></tr></table>
<table class="franja"><tr><td style="background:#73BC25"></td><td style="background:#A3D300"></td><td style="background:#FFCC00"></td><td style="background:#FF6B00"></td></tr></table>
<table class="kpis"><tr>
    <td><div class="kpi"><b><?= $kpi['realizados'] ?></b><span>Eventos realizados · <?= $kpi['con_resp'] ?> con respuestas</span></div></td>
    <td><div class="kpi"><b style="color:<?= $color($nivelEstrellas($kpi['prom'])) ?>"><?= $h($est($kpi['prom'])) ?></b><span>Percepción promedio · <?= $kpi['respuestas'] ?> respuestas</span></div></td>
    <td><div class="kpi"><b style="color:<?= $color($nivelPct($kpi['satisf'])) ?>"><?= $kpi['satisf'] === null ? '—' : $kpi['satisf'] . ' %' ?></b><span>Participantes satisfechos (4–5 ★)</span></div></td>
    <td><div class="kpi"><b><?= $kpi['cumple'] ?> de <?= $kpi['evaluables'] ?></b><span>Eventos que cumplen la meta del <?= $meta ?> % · tasa global <?= $kpi['tasa'] === null ? '—' : $kpi['tasa'] . ' %' ?></span></div></td>
    <td><div class="kpi"><b style="color:<?= $color($nivelEstrellas($kpi['indice'])) ?>"><?= $h($est($kpi['indice'])) ?></b><span>Índice de colaboración · <?= $kpi['aliados_pend'] ?> encuesta(s) pendiente(s)</span></div></td>
</tr></table>
<?php if ($vista === 'comentarios'): ?>
<h2>Temas</h2>
<table class="datos"><thead><tr><th>Tema</th><th class="num">Comentarios</th></tr></thead><tbody>
<?php foreach ($temas as $t => $n): ?><tr><td><?= $h($t) ?></td><td class="num"><?= $n ?></td></tr><?php endforeach; ?>
<?php if (!$temas): ?><tr><td colspan="2">Sin comentarios.</td></tr><?php endif; ?>
</tbody></table>
<h2>Comentarios (<?= count($comentariosVisibles) ?>)</h2>
<table class="datos"><thead><tr><th style="width:44%">Comentario</th><th>Tema</th><th>Evento</th><th>Encuesta</th><th>Quién responde</th></tr></thead><tbody>
<?php foreach ($comentariosVisibles as $c): ?><tr>
    <td><?= nl2br($h($c['texto'])) ?></td><td><?= $h($c['tema']) ?></td>
    <td><?= $h($c['evento']['nombre']) ?><br><span class="sub"><?= $h($fecha($c['evento']['fecha'])) ?></span></td>
    <td><?= $h($origenes[$c['origen']]) ?><br><span class="sub"><?= $h($c['pregunta']) ?><?= $c['estrellas'] ? ' · ' . $c['estrellas'] . ' ★' : '' ?></span></td>
    <td><?= $h($c['quien']) ?><?php if ($c['institucion'] !== ''): ?><br><span class="sub"><?= $h($c['institucion']) ?></span><?php endif; ?></td>
</tr><?php endforeach; ?>
<?php if (!$comentariosVisibles): ?><tr><td colspan="5">No hay comentarios para estos filtros.</td></tr><?php endif; ?>
</tbody></table>
<p class="nota">El tema se asigna automáticamente por palabras clave del comentario (organización, contenido, instalaciones, difusión, alimentación, networking). «Comentarios positivos» = elogios sin propuesta de mejora.</p>
<?php else: ?>
<table class="datos"><thead><tr><th style="width:22%">Evento</th><th class="num">Asistentes</th><th class="num">Respuestas</th><th class="num">Tasa (meta <?= $meta ?> %)</th><th>Estado de la meta</th><th class="num">Percepción</th><th class="num">Satisfechos</th><th>Tema de mejora principal</th><th class="num">Colaboración</th></tr></thead><tbody>
<?php foreach ($lista as $e): $r = $e['res']; [$txtEst, $clsEst] = CENGI_EVG_ESTADOS_META[$r['estado']]; ?><tr>
    <td><b><?= $h($e['nombre']) ?></b><br><span class="sub"><?= $h($fecha($e['fecha']) . ' · ' . $e['tipo'] . ' · ' . $e['estado']) ?></span></td>
    <td class="num"><?= (int) $e['asistentes'] ?> <span class="sub">/ <?= (int) $e['confirmados'] ?></span></td>
    <td class="num"><?= $r['n'] ?></td>
    <td class="num" style="color:<?= $color($clsEst) ?>"><?= $r['tasa'] === null ? '—' : $r['tasa'] . ' %' ?></td>
    <td style="color:<?= $color($clsEst) ?>"><?= $h($txtEst) ?><?= $r['cuenta'] && $r['faltan'] ? ' · falta' . ($r['faltan'] === 1 ? '' : 'n') . ' ' . $r['faltan'] : '' ?></td>
    <td class="num" style="color:<?= $color($nivelEstrellas($r['prom'])) ?>"><?= $h($est($r['prom'])) ?></td>
    <td class="num" style="color:<?= $color($nivelPct($r['satisf'])) ?>"><?= $r['satisf'] === null ? '—' : $r['satisf'] . ' %' ?></td>
    <td><?= $h($r['tema'] ?: '—') ?></td>
    <td class="num" style="color:<?= $color($nivelEstrellas($r['indice'])) ?>"><?= $h($est($r['indice'])) ?><br><span class="sub"><?= $h($textoColab($e)) ?></span></td>
</tr><?php endforeach; ?>
<?php if (!$lista): ?><tr><td colspan="9">No hay eventos para estos filtros.</td></tr><?php endif; ?>
</tbody></table>
<table class="dos" style="margin-top:6px"><tr><td>
    <h2>Percepción por tipo de evento</h2>
    <table class="datos"><thead><tr><th>Tipo</th><th class="num">Eventos</th><th class="num">Asistentes</th><th class="num">Respuestas</th><th class="num">Percepción</th></tr></thead><tbody>
    <?php foreach ($porTipo as $t): ?><tr><td><?= $h($t['tipo']) ?></td><td class="num"><?= $t['eventos'] ?></td><td class="num"><?= $t['asist'] ?></td><td class="num"><?= $t['n'] ?></td><td class="num" style="color:<?= $color($nivelEstrellas($t['prom'])) ?>"><?= $h($est($t['prom'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$porTipo): ?><tr><td colspan="5">Sin eventos realizados.</td></tr><?php endif; ?>
    </tbody></table>
</td><td>
    <h2>Colaboración por criterio (<?= count($colabTodas) ?> respuesta<?= count($colabTodas) === 1 ? '' : 's' ?> de empresas aliadas)</h2>
    <table class="datos"><thead><tr><th>Criterio</th><th class="num">Promedio (1–5)</th></tr></thead><tbody>
    <?php foreach (CENGI_EVT_COLAB_ITEMS as $i): $p = $itemsGlobal[$i['k']]; ?><tr><td><?= $h($i['t']) ?><br><span class="sub"><?= $h($i['g']) ?></span></td><td class="num" style="color:<?= $color($nivelEstrellas($p)) ?>"><?= $h($est($p)) ?></td></tr><?php endforeach; ?>
    </tbody></table>
</td></tr></table>
<p class="nota">Tasa de respuesta = respuestas a la encuesta de percepción (enlace personal y QR general) ÷ asistentes con ingreso registrado, con tope de 100 %. Meta: al menos el <?= $meta ?> % de los asistentes responde. Solo cuentan los eventos ya realizados con ingresos registrados. Percepción en escala de 1 a 5 estrellas; colores: verde ≥ 4.2, amarillo ≥ 3.5. Satisfechos = 4 o 5 estrellas (verde ≥ 85 %). No incluye eventos cancelados.</p>
<?php endif; ?>
<div class="pie">CENGICAÑA · Comité de Capacitación</div>
</body></html>
    <?php
    $html = ob_get_clean();
    $opciones = new Dompdf\Options();
    $opciones->set('isRemoteEnabled', false);
    $opciones->set('isHtml5ParserEnabled', true);
    $opciones->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($opciones);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'landscape');
    $dompdf->render();
    $canvas = $dompdf->getCanvas();
    $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.42, 0.48, 0.45]);
    cengi_export_enviar_pdf($dompdf->output(), ($vista === 'comentarios' ? 'comentarios-eventos' : 'evaluacion-eventos') . ($anio ? '-' . $anio : '') . '.pdf', false);
}

// ---------------------------------------------------------------- página
$porPagina = 25;
$total = $vista === 'comentarios' ? count($comentariosVisibles) : count($lista);
$pages = max(1, (int) ceil($total / $porPagina));
$page = max(1, min($pages, (int) ($_GET['pagina'] ?? 1)));
$visibles = array_slice($vista === 'comentarios' ? $comentariosVisibles : $lista, ($page - 1) * $porPagina, $porPagina);
$filtros = ['vista' => $vista === 'comentarios' ? 'comentarios' : '', 'anio' => $anio ?: '', 'tipo' => $tipo, 'colab' => $colabF, 'q' => $q,
    'bajo' => $soloBajo ? 1 : '', 'orden' => $orden === 'fecha' ? '' : $orden, 'origen' => $origenF, 'tema' => $temaF, 'evento' => $eventoF ?: ''];
$url = static function (array $extra) use ($filtros) {
    return 'eventos_evaluacion.php?' . http_build_query(array_filter(array_merge($filtros, $extra), static function ($v) { return $v !== '' && $v !== null; }));
};
$hayFiltros = $anio || $tipo !== '' || $colabF !== '' || $q !== '' || ($vista === 'eventos' && $soloBajo) || ($vista === 'comentarios' && ($origenF !== '' || $temaF !== '' || $eventoF));
$sinVista = ['vista' => '', 'bajo' => '', 'orden' => '', 'origen' => '', 'tema' => '', 'pagina' => ''];
require_once __DIR__ . '/menu.php';
?>
<!DOCTYPE html><html lang="es"><head><?php cengi_pp_head('Evaluación de eventos'); ?>
<link rel="stylesheet" href="css/necesidades_capacitacion.css?v=<?= filemtime(__DIR__ . '/css/necesidades_capacitacion.css') ?>">
<link rel="stylesheet" href="css/informe_instructores.css?v=<?= filemtime(__DIR__ . '/css/informe_instructores.css') ?>">
<link rel="stylesheet" href="css/eventos_evaluacion.css?v=<?= filemtime(__DIR__ . '/css/eventos_evaluacion.css') ?>"></head>
<body class="cengi-canvas cg-tema"><?php menu_render(); ?>
<main class="container nc-page ir-page ev-eval-page">
<?php cengi_nav_barra('eventos_evaluacion.php'); ?>
<?php cengi_banner(null, null, 'Eventos · evaluación',
    '<a class="nc-btn is-claro" href="' . $h($url(['pdf' => 1, 'pagina' => ''])) . '" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver PDF</a>'
    . '<a class="nc-btn is-linea-claro" href="' . $h($url(['exportar' => 'excel', 'pagina' => ''])) . '"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Excel</a>'); ?>

<nav class="ir-vistas" aria-label="Vistas de la evaluación">
    <a href="<?= $h($url(['vista' => '', 'pagina' => '', 'origen' => '', 'tema' => '', 'evento' => ''])) ?>" class="<?= $vista === 'eventos' ? 'is-active' : '' ?>"<?= $vista === 'eventos' ? ' aria-current="page"' : '' ?>><span class="glyphicon glyphicon-star" aria-hidden="true"></span><span><b>Resultados por evento</b><small>Percepción, meta de respuesta del <?= $meta ?> % y colaboración</small></span><?php if ($kpi['bajo']): ?><em><?= $kpi['bajo'] ?> bajo meta</em><?php endif; ?></a>
    <a href="<?= $h($url(['vista' => 'comentarios', 'pagina' => '', 'bajo' => '', 'orden' => ''])) ?>" class="<?= $vista === 'comentarios' ? 'is-active' : '' ?>"<?= $vista === 'comentarios' ? ' aria-current="page"' : '' ?>><span class="glyphicon glyphicon-comment" aria-hidden="true"></span><span><b>Comentarios y temas de mejora</b><small>Lo que piden participantes y empresas aliadas</small></span><?php if ($comentarios): ?><em class="is-gris"><?= count($comentarios) ?></em><?php endif; ?></a>
</nav>

<div class="nc-kpis ir-kpis">
    <div class="nc-kpi"><b><?= $kpi['realizados'] ?></b><span>Eventos realizados · <?= $kpi['con_resp'] ?> con respuestas<?= $kpi['eventos'] > $kpi['realizados'] ? ' · ' . ($kpi['eventos'] - $kpi['realizados']) . ' por realizar' : '' ?></span></div>
    <div class="nc-kpi is-verde2"><b class="<?= $nivelEstrellas($kpi['prom']) ?>"><?= $h($est($kpi['prom'])) ?></b><span>Percepción promedio · <?= $kpi['respuestas'] ?> respuesta<?= $kpi['respuestas'] === 1 ? '' : 's' ?></span></div>
    <div class="nc-kpi is-amarillo"><b class="<?= $nivelPct($kpi['satisf']) ?>"><?= $kpi['satisf'] === null ? '—' : $kpi['satisf'] . ' %' ?></b><span>Participantes satisfechos (4–5 ★)</span></div>
    <a class="nc-kpi is-naranja ir-kpi-link" href="#configurar-meta"><b class="<?= $kpi['evaluables'] && $kpi['cumple'] === $kpi['evaluables'] ? 'is-bien' : ($kpi['evaluables'] ? 'is-bajo' : 'is-sin') ?>"><?= $kpi['cumple'] ?> de <?= $kpi['evaluables'] ?></b><span>Cumplen la meta del <?= $meta ?> % · tasa global <?= $kpi['tasa'] === null ? '—' : $kpi['tasa'] . ' %' ?></span></a>
    <div class="nc-kpi is-gris"><b class="<?= $nivelEstrellas($kpi['indice']) ?>"><?= $h($est($kpi['indice'])) ?></b><span>Índice de colaboración · <?= $kpi['aliados_pend'] ? $kpi['aliados_pend'] . ' encuesta' . ($kpi['aliados_pend'] === 1 ? '' : 's') . ' de aliados pendiente' . ($kpi['aliados_pend'] === 1 ? '' : 's') : $kpi['colab_eventos'] . ' evento' . ($kpi['colab_eventos'] === 1 ? '' : 's') . ' en colaboración' ?></span></div>
</div>

<section class="nc-panel">
<form method="get" class="nc-form">
    <?php if ($vista === 'comentarios'): ?><input type="hidden" name="vista" value="comentarios"><?php if ($temaF !== ''): ?><input type="hidden" name="tema" value="<?= $h($temaF) ?>"><?php endif; ?><?php endif; ?>
    <label>Año<select class="form-control" name="anio" onchange="this.form.submit()"><option value="">Todos los años</option><?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $anio === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></label>
    <label>Tipo de evento<select class="form-control" name="tipo" onchange="this.form.submit()"><option value="">Todos</option><?php foreach (CENGI_EVT_TIPOS as $t): ?><option <?= $tipo === $t ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?></select></label>
    <label>Colaboración<select class="form-control" name="colab" onchange="this.form.submit()"><option value="">Todos</option><option value="si" <?= $colabF === 'si' ? 'selected' : '' ?>>En colaboración</option><option value="no" <?= $colabF === 'no' ? 'selected' : '' ?>>Sin colaboración</option></select></label>
    <?php if ($vista === 'comentarios'): ?>
    <label class="ev-eval-evento">Evento<select class="form-control" name="evento" onchange="this.form.submit()"><option value="">Todos los eventos</option><?php foreach ($eventos as $e): ?><option value="<?= (int) $e['id'] ?>" <?= $eventoF === $e['id'] ? 'selected' : '' ?>><?= $h($e['nombre'] . ' · ' . $fecha($e['fecha']) . ' (' . ($comentariosPorEvento[$e['id']] ?? 0) . ')') ?></option><?php endforeach; ?><?php if ($eventoF && !$eventoSel): ?><option value="<?= $eventoF ?>" selected>Evento fuera de los filtros</option><?php endif; ?></select></label>
    <label>Encuesta<select class="form-control" name="origen" onchange="this.form.submit()"><option value="">Todas</option><?php foreach ($origenes as $k => $t): ?><option value="<?= $k ?>" <?= $origenF === $k ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?></select></label>
    <?php else: ?>
    <label>Ordenar por<select class="form-control" name="orden" onchange="this.form.submit()"><?php foreach ($ordenes as $k => $t): ?><option value="<?= $k ?>" <?= $orden === $k ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label class="nc-buscar">Buscar<span class="nc-search"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input class="form-control" type="search" name="q" value="<?= $h($q) ?>" placeholder="Evento, lugar o empresa aliada"></span></label>
    <?php if ($vista === 'eventos'): ?><label class="ir-check"><input type="checkbox" name="bajo" value="1" <?= $soloBajo ? 'checked' : '' ?> onchange="this.form.submit()"> Solo bajo la meta</label><?php endif; ?>
    <button class="nc-btn" type="submit">Buscar</button>
</form>
<?php if ($hayFiltros): ?><div class="nc-activos"><span>Filtrando por:</span>
    <?php if ($anio): ?><a class="nc-chip" href="<?= $h($url(['anio' => '', 'pagina' => ''])) ?>">Año: <b><?= $anio ?></b> ✕</a><?php endif; ?>
    <?php if ($tipo !== ''): ?><a class="nc-chip" href="<?= $h($url(['tipo' => '', 'pagina' => ''])) ?>">Tipo: <b><?= $h($tipo) ?></b> ✕</a><?php endif; ?>
    <?php if ($colabF !== ''): ?><a class="nc-chip" href="<?= $h($url(['colab' => '', 'pagina' => ''])) ?>"><?= $colabF === 'si' ? 'En colaboración' : 'Sin colaboración' ?> ✕</a><?php endif; ?>
    <?php if ($q !== ''): ?><a class="nc-chip" href="<?= $h($url(['q' => '', 'pagina' => ''])) ?>">Texto: <b><?= $h($q) ?></b> ✕</a><?php endif; ?>
    <?php if ($vista === 'eventos' && $soloBajo): ?><a class="nc-chip" href="<?= $h($url(['bajo' => '', 'pagina' => ''])) ?>">Solo bajo la meta ✕</a><?php endif; ?>
    <?php if ($vista === 'comentarios' && $origenF !== ''): ?><a class="nc-chip" href="<?= $h($url(['origen' => '', 'pagina' => ''])) ?>">Encuesta: <b><?= $h($origenes[$origenF]) ?></b> ✕</a><?php endif; ?>
    <?php if ($vista === 'comentarios' && $eventoF): ?><a class="nc-chip" href="<?= $h($url(['evento' => '', 'pagina' => ''])) ?>">Evento: <b><?= $h($eventoSel ? $eventoSel['nombre'] : '#' . $eventoF) ?></b> ✕</a><?php endif; ?>
    <?php if ($vista === 'comentarios' && $temaF !== ''): ?><a class="nc-chip" href="<?= $h($url(['tema' => '', 'pagina' => ''])) ?>">Tema: <b><?= $h($temaF) ?></b> ✕</a><?php endif; ?>
    <a class="nc-limpiar" href="<?= $h('eventos_evaluacion.php' . ($vista === 'comentarios' ? '?vista=comentarios' : '')) ?>">Quitar todos</a></div><?php endif; ?>
</section>

<?php if ($vista === 'comentarios'): ?>
<div class="ev-eval-com">
<section class="nc-panel nc-ranking">
    <h2>Temas</h2>
    <?php if ($temas): ?><ol>
        <?php foreach ($temas as $t => $n): ?>
        <li><a href="<?= $h($url(['tema' => $temaF === $t ? '' : $t, 'pagina' => ''])) ?>" class="<?= $temaF === $t ? 'is-active' : '' ?><?= $t === 'Comentarios positivos' ? ' is-positivo' : '' ?>" title="<?= $temaF === $t ? 'Quitar el filtro' : 'Ver solo este tema' ?>">
            <span class="nc-rk-nombre"><?= $h($t) ?></span><span class="nc-rk-n"><?= $n ?></span>
            <span class="nc-rk-barra"><i style="width:<?= $maxTema ? round($n / $maxTema * 100) : 0 ?>%"></i></span></a></li>
        <?php endforeach; ?>
    </ol>
    <p class="ev-eval-ayuda">El tema se asigna por palabras clave. Haz clic en uno para filtrar los comentarios.</p>
    <?php else: ?><p class="ir-muted">Sin comentarios todavía.</p><?php endif; ?>
</section>
<section class="nc-panel">
    <div class="nc-lista-head"><h2><?= $temaF !== '' ? $h($temaF) : 'Todos los comentarios' ?><?= $eventoSel ? ' · ' . $h($eventoSel['nombre']) : '' ?></h2><span><?= $total ?> comentario<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ' · página ' . $page . ' de ' . $pages : '' ?></span></div>
    <?php if ($visibles): ?><ul class="nc-lista">
        <?php foreach ($visibles as $c): ?>
        <li class="nc-item ev-eval-c is-<?= $c['origen'] ?>">
            <p class="nc-comentario"><?= $h($c['texto']) ?></p>
            <div class="nc-meta">
                <span class="nc-tag <?= $c['origen'] === 'aliados' ? 'is-aliado' : '' ?>"><?= $h($origenes[$c['origen']]) ?></span>
                <a class="nc-tag" href="<?= $h($url(['tema' => $c['tema'], 'pagina' => ''])) ?>"><?= $h($c['tema']) ?></a>
                <?php if ($c['estrellas']): ?><span class="ev-eval-stars" title="<?= $c['estrellas'] ?> de 5"><?= str_repeat('★', $c['estrellas']) ?><i><?= str_repeat('★', 5 - $c['estrellas']) ?></i></span><?php endif; ?>
                <span class="nc-dato"><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span><?= $h($c['evento']['nombre']) ?> · <?= $h($fecha($c['evento']['fecha'])) ?></span>
                <span class="nc-dato"><span class="glyphicon glyphicon-user" aria-hidden="true"></span><?= $h($c['quien']) ?><?= $c['institucion'] !== '' ? ' · ' . $h($c['institucion']) : '' ?></span>
                <?php if ($c['origen'] === 'aliados'): ?><span class="nc-dato ir-muted"><?= $h($c['pregunta']) ?></span><?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul><?php else: ?>
    <div class="nc-vacio"><span class="glyphicon glyphicon-comment" aria-hidden="true"></span><p><b><?= $hayFiltros ? 'No hay comentarios para estos filtros.' : 'Todavía no hay comentarios en las encuestas de eventos.' ?></b></p><?php if ($hayFiltros): ?><a class="nc-btn is-linea" href="eventos_evaluacion.php?vista=comentarios">Quitar filtros</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($pages > 1): ?><nav class="nc-paginacion" aria-label="Paginación">
        <?php if ($page > 1): ?><a href="<?= $h($url(['pagina' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
        <?php for ($n = max(1, $page - 3); $n <= min($pages, $page + 3); $n++): ?><a href="<?= $h($url(['pagina' => $n])) ?>" class="<?= $n === $page ? 'is-active' : '' ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a><?php endfor; ?>
        <?php if ($page < $pages): ?><a href="<?= $h($url(['pagina' => $page + 1])) ?>">Siguiente →</a><?php endif; ?>
    </nav><?php endif; ?>
    <p class="nc-note">Incluye las oportunidades de mejora de la encuesta de percepción (participantes) y las dos preguntas abiertas de la encuesta de colaboración (empresas aliadas). El tema se asigna automáticamente por palabras clave; «Comentarios positivos» son elogios sin propuesta de mejora.</p>
</section>
</div>

<?php else: ?>
<section class="nc-panel">
    <div class="nc-lista-head"><h2>Resultados por evento</h2><span><?= $total ?> evento<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ' · página ' . $page . ' de ' . $pages : '' ?></span></div>
    <?php if ($visibles): ?>
    <div class="table-responsive"><table class="table ir-table ev-eval-tabla">
        <thead><tr><th>Evento</th><th class="ir-c">Asistentes<small>con ingreso / confirmados</small></th><th class="ev-eval-tasa-th">Tasa de respuesta<small>meta <?= $meta ?> %</small></th>
            <th class="ir-c">Percepción<small>1–5 ★ · satisfechos</small></th><th>Tema de mejora</th><th class="ir-c">Colaboración<small>índice 1–5 ★</small></th><th><span class="sr-only">Acciones</span></th></tr></thead>
        <tbody>
        <?php foreach ($visibles as $e): $r = $e['res']; [$txtEst, $clsEst] = CENGI_EVG_ESTADOS_META[$r['estado']]; ?>
        <tr>
            <td><b class="ir-nombre"><?= $h($e['nombre']) ?></b>
                <span class="ev-eval-sub"><?= $h($fecha($e['fecha'])) ?> · <?= $h($e['tipo']) ?><?= $e['lugar'] ? ' · ' . $h($e['lugar']) : '' ?></span>
                <span class="ev-eval-etqs"><span class="ev-eval-etq is-<?= $h(strtolower(str_replace(' ', '-', $e['estado']))) ?>"><?= $h($e['estado']) ?></span><?php if ($e['en_colaboracion']): ?><span class="ev-eval-etq is-colab">En colaboración</span><?php endif; ?></span></td>
            <td class="ir-c"><b><?= (int) $e['asistentes'] ?></b> <span class="ir-muted">/ <?= (int) $e['confirmados'] ?></span></td>
            <td>
                <?php if ($r['tasa'] !== null): ?>
                <span class="ir-part-barra <?= $clsEst ?>"><i style="width:<?= $r['tasa'] ?>%"></i><em style="left:<?= $meta ?>%"></em></span>
                <span class="ir-part-num"><b><?= $r['tasa'] ?> %</b> · <?= $r['n'] ?> de <?= (int) $e['asistentes'] ?><?= $r['cuenta'] && $r['faltan'] ? ' · falta' . ($r['faltan'] === 1 ? '' : 'n') . ' ' . $r['faltan'] : '' ?></span>
                <?php else: ?><span class="ir-part-num"><?= $r['n'] ?> respuesta<?= $r['n'] === 1 ? '' : 's' ?></span><?php endif; ?>
                <span class="ir-estado <?= $clsEst ?>"><?= $h($txtEst) ?></span>
            </td>
            <td class="ir-c"><?php if ($r['n']): ?><span class="ir-score <?= $nivelEstrellas($r['prom']) ?>"><?= $h($est($r['prom'])) ?></span>
                <span class="ev-eval-dist" aria-label="Distribución de estrellas"><?php foreach ([5, 4, 3, 2, 1] as $s): ?><i class="s<?= $s ?>" style="width:<?= round($r['dist'][$s] / $r['n'] * 100) ?>%" title="<?= $s ?> ★: <?= $r['dist'][$s] ?>"></i><?php endforeach; ?></span>
                <small class="ir-meta-pct"><?= $r['satisf'] ?> % satisfechos · <?= $r['n'] ?> resp.</small>
                <?php else: ?><span class="ir-muted">Sin respuestas</span><?php endif; ?></td>
            <td class="ev-eval-tema"><?= $r['tema'] !== '' ? '<a href="' . $h($url(['vista' => 'comentarios', 'tema' => $r['tema'], 'evento' => $e['id'], 'bajo' => '', 'orden' => '', 'pagina' => ''])) . '">' . $h($r['tema']) . '</a>' : '<span class="ir-muted">—</span>' ?></td>
            <td class="ir-c"><?php if (!$e['en_colaboracion']): ?><span class="ir-muted">No aplica</span>
                <?php elseif ($r['indice'] !== null): ?><span class="ir-score <?= $nivelEstrellas($r['indice']) ?>"><?= $h($est($r['indice'])) ?></span><small class="ir-meta-pct"><?= $h($textoColab($e)) ?></small>
                <?php else: ?><span class="ir-estado is-medio"><?= $r['aliados'] ?> sin responder</span><?php endif; ?></td>
            <td class="ev-eval-acc">
                <a class="nc-btn" href="eventos_qr.php?informe=<?= (int) $e['id'] ?>" title="Informe del evento: resumen, temas y recomendaciones">Informe</a>
                <a class="nc-btn is-linea" href="eventos_qr.php?encuestas=<?= (int) $e['id'] ?>" title="Enlaces, QR y envío de las encuestas">Encuestas</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <div class="nc-vacio"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><p><b><?= $hayFiltros ? 'No hay eventos para estos filtros.' : 'Todavía no hay eventos registrados.' ?></b></p><?php if ($hayFiltros): ?><a class="nc-btn is-linea" href="eventos_evaluacion.php">Quitar filtros</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($pages > 1): ?><nav class="nc-paginacion" aria-label="Paginación">
        <?php if ($page > 1): ?><a href="<?= $h($url(['pagina' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
        <?php for ($n = max(1, $page - 3); $n <= min($pages, $page + 3); $n++): ?><a href="<?= $h($url(['pagina' => $n])) ?>" class="<?= $n === $page ? 'is-active' : '' ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a><?php endfor; ?>
        <?php if ($page < $pages): ?><a href="<?= $h($url(['pagina' => $page + 1])) ?>">Siguiente →</a><?php endif; ?>
    </nav><?php endif; ?>
    <div class="ir-leyenda"><span><i class="is-bien"></i> Cumple la meta · percepción ≥ 4.2</span><span><i class="is-medio"></i> Bajo la meta con el evento abierto · ≥ 3.5</span><span><i class="is-bajo"></i> Cerró bajo la meta · por mejorar</span><span><i class="is-sin"></i> Por realizar o sin ingresos (no cuenta)</span></div>
</section>

<div class="nc-rankings">
<section class="nc-panel">
    <h2>Percepción por tipo de evento</h2>
    <?php if ($porTipo): ?><table class="table ir-table ev-eval-mini"><thead><tr><th>Tipo</th><th class="ir-c">Eventos</th><th class="ir-c">Respuestas</th><th class="ir-c">Percepción</th></tr></thead><tbody>
        <?php foreach ($porTipo as $t): ?><tr><td><a href="<?= $h($url(['tipo' => $t['tipo'], 'pagina' => ''])) ?>"><?= $h($t['tipo']) ?></a></td><td class="ir-c"><?= $t['eventos'] ?></td><td class="ir-c"><?= $t['n'] ?></td>
            <td class="ir-c"><?= $t['prom'] === null ? '<span class="ir-muted">—</span>' : '<span class="ir-score ' . $nivelEstrellas($t['prom']) . '">' . $h($est($t['prom'])) . '</span>' ?></td></tr><?php endforeach; ?>
    </tbody></table><?php else: ?><p class="ir-muted">Sin eventos realizados para estos filtros.</p><?php endif; ?>
</section>
<section class="nc-panel">
    <h2>Colaboración por criterio <small class="ir-muted"><?= count($colabTodas) ?> respuesta<?= count($colabTodas) === 1 ? '' : 's' ?> de empresas aliadas</small></h2>
    <?php if ($colabTodas): ?><ul class="ev-eval-criterios">
        <?php foreach (CENGI_EVT_COLAB_ITEMS as $i): $p = $itemsGlobal[$i['k']]; ?>
        <li><span><?= $h($i['t']) ?><small><?= $h($i['g']) ?></small></span>
            <b class="ir-score <?= $nivelEstrellas($p) ?>"><?= $h($est($p)) ?></b>
            <span class="ir-barra <?= $nivelEstrellas($p) ?>" aria-hidden="true"><i style="width:<?= $p === null ? 0 : round($p / 5 * 100) ?>%"></i></span></li>
        <?php endforeach; ?>
    </ul><?php else: ?><p class="ir-muted">Ninguna empresa aliada ha respondido todavía.</p><?php endif; ?>
</section>
</div>

<section class="nc-panel ir-meta-config" id="configurar-meta">
    <div class="ir-meta-actual">
        <span class="ir-meta-valor"><?= $meta ?><small>%</small></span>
        <div>
            <h2>Meta de respuesta</h2>
            <p>Porcentaje mínimo de asistentes de cada evento que debe responder la encuesta de percepción (enlace personal o QR general). Si un evento abierto está bajo la meta, en <b>Encuestas</b> se puede recordar a quienes no han respondido.</p>
            <small class="ir-muted"><?= $configMeta['actualizado_por'] !== '' ? 'Último cambio: ' . $h($configMeta['actualizado_por']) . ' · ' . $h(date('d/m/Y H:i', strtotime($configMeta['actualizado']))) : 'Valor por defecto del sistema (' . CENGI_META_EVAL_EVENTO . ' %).' ?></small>
        </div>
    </div>
    <?php if ($puedeConfigurarMeta): ?>
    <form method="post" action="eventos_evaluacion.php#configurar-meta" class="ir-meta-form">
        <input type="hidden" name="accion" value="guardar_meta"><input type="hidden" name="csrf" value="<?= $h($_SESSION['eval_evento_csrf']) ?>">
        <label>Nueva meta<span class="ir-meta-input"><input class="form-control" type="number" name="porcentaje" min="1" max="100" step="1" value="<?= $meta ?>" required><b>%</b></span></label>
        <button class="nc-btn" type="submit" onclick="return confirm('¿Cambiar la meta de respuesta de los eventos?');">Guardar meta</button>
    </form>
    <?php endif; ?>
    <?php if ($errorMeta !== ''): ?><div class="alert alert-danger ir-meta-alerta" role="alert"><?= $h($errorMeta) ?></div><?php endif; ?>
    <?php if (!empty($_GET['meta_guardada'])): ?><div class="alert alert-success ir-meta-alerta" role="status">Meta actualizada al <?= $meta ?> %.</div><?php endif; ?>
</section>
<p class="nc-note">Tasa de respuesta = respuestas a la encuesta de percepción (enlace personal y QR general) ÷ asistentes con ingreso registrado, con tope de 100 %. Solo cuentan para la meta los eventos ya realizados con ingresos registrados. Percepción y colaboración en escala de 1 a 5 estrellas (verde ≥ 4.2, amarillo ≥ 3.5); satisfechos = 4 o 5 estrellas. Los eventos cancelados no se incluyen. «Informe» y «Encuestas» abren el evento en Eventos y pagos.</p>
<?php endif; ?>
</main></body></html>
