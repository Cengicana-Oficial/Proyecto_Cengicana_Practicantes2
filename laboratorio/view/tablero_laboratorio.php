<?php
/**
 * Tablero de avance para pantalla (TV): resumen por tipo de muestra de los
 * lotes en curso (los que no tienen informe firmado). Solo lectura.
 *
 * Diseño para pantalla 16:9 sin desplazamiento: una columna por tipo de
 * muestra, leyenda única en la cabecera y, por lote, solo las hojas de trabajo
 * que faltan (las completas se resumen). Si los lotes no caben en la columna,
 * el navegador oculta los que sobran y muestra "+ N lotes más".
 *
 * Se abre sin iniciar sesión con una clave en la URL:
 *   tablero_laboratorio.php?k=<LAB_TABLERO_TOKEN>
 * La clave vive en laboratorio/.env (deploy/env/laboratorio.env). Si no está
 * configurada (o tiene menos de 16 caracteres) el tablero queda deshabilitado;
 * para revocar un enlace filtrado basta con cambiarla.
 */
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/workflow_trabajo_helper.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');

$clave = (string) (getenv('LAB_TABLERO_TOKEN') ?: '');
if (strlen($clave) < 16) {
    http_response_code(503);
    exit('Tablero no configurado: defina LAB_TABLERO_TOKEN (mínimo 16 caracteres) en la configuración del laboratorio.');
}
if (!hash_equals($clave, (string) ($_GET['k'] ?? ''))) {
    http_response_code(403);
    exit('Enlace del tablero no válido.');
}

$tb_e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$nf = static fn($n) => number_format((int) $n, 0, '.', ',');

try {
    $pdo = Conexion::conectar();
    $tareas = wfTareas($pdo);
    $seguimiento = wfSeguimientoLotes($pdo);
    $error = null;
} catch (Throwable $e) {
    error_log('[laboratorio][tablero] ' . $e->getMessage());
    $tareas = [];
    $seguimiento = [];
    $error = 'No se pudo cargar la información. Se reintentará en un minuto.';
}

// Tipos en el mismo orden y con los mismos colores que Planificación.
$tipos = [
    'Agua' => ['Agua', '#636A6F'],
    'Caña' => ['Caña', '#8A6E00'],
    'Foliar' => ['Foliares', '#4E8418'],
    'Suelo' => ['Suelos', '#B34B00'],
    'Miel' => ['Mieles', '#C47A00'],
];
// Orden de la barra: lo aprobado primero, lo pendiente al final.
$estados = [
    'validado' => ['Aprobado', '#4E8418'],
    'validacion' => ['En validación', '#2F6DB5'],
    'proceso' => ['En análisis', '#E0A800'],
    'reanalisis' => ['Reanálisis', '#C0392B'],
    'pendiente' => ['Pendiente', '#C9CDD0'],
];
$hoy = date('Y-m-d');

$porTipo = [];
foreach ($tareas as $t) {
    $idLote = (int) $t['id_lote'];
    if (($seguimiento[$idLote]['informe']['estado'] ?? '') === 'FIRMADO') {
        continue; // Finalizado: ya no forma parte del trabajo en curso.
    }
    $tipo = wfTipo((string) $t['tipo_muestra']);
    $est = in_array($t['estado']['key'], ['rechazado', 'repetido'], true) ? 'reanalisis' : $t['estado']['key'];
    if (!isset($estados[$est])) {
        $est = 'pendiente';
    }
    $vence = substr((string) ($t['fecha_estimada'] ?? ''), 0, 10);

    $g = &$porTipo[$tipo];
    $g ??= ['muestras' => [], 'total' => 0, 'estados' => array_fill_keys(array_keys($estados), 0), 'lotes' => []];
    $g['muestras'][(int) $t['id_muestra']] = true;
    $g['total']++;
    $g['estados'][$est]++;

    // Detalle por lote: lo solicitado agrupado por hoja de trabajo (análisis que se capturan juntos).
    $l = &$g['lotes'][$idLote];
    $l ??= ['codigo' => $t['codigo_lote'], 'cliente' => $t['cliente'] ?? '', 'prioridad' => $t['prioridad']['key'] ?? 'media',
        'vence' => $vence, 'muestras' => [], 'total' => 0, 'estados' => array_fill_keys(array_keys($estados), 0), 'hojas' => []];
    $l['muestras'][(int) $t['id_muestra']] = true;
    $l['total']++;
    $l['estados'][$est]++;
    $hk = $t['hoja'] ?: 'analisis:' . $t['id_tipo_analisis'];
    $l['hojas'][$hk]['analisis'][$t['analisis_nombre']] = true;
    $l['hojas'][$hk]['estados'][$est] = ($l['hojas'][$hk]['estados'][$est] ?? 0) + 1;
    unset($l, $g);
}
uksort($porTipo, static function ($a, $b) use ($tipos) {
    $orden = array_flip(array_keys($tipos));
    return [($orden[$a] ?? 99), $a] <=> [($orden[$b] ?? 99), $b];
});

/** Estado resumido de un conjunto de análisis (color del chip de hoja). */
$tb_estado = static function (array $cuenta): string {
    $total = array_sum($cuenta);
    if (($cuenta['validado'] ?? 0) === $total) return 'validado';
    if (!empty($cuenta['reanalisis'])) return 'reanalisis';
    if (empty($cuenta['pendiente']) && empty($cuenta['proceso'])) return 'validacion';
    if (($cuenta['pendiente'] ?? 0) === $total) return 'pendiente';
    return 'proceso';
};
/** Etapa del lote en la secuencia Análisis → Validación → Informe. */
$tb_etapa = static function (array $l, ?array $informe): string {
    $e = $l['estados'];
    if ($informe && ($informe['estado'] ?? '') === 'DEVUELTO') return 'Informe devuelto';
    if ($e['validado'] === $l['total']) return $informe ? 'Informe en aprobación' : 'Listo para informe';
    if (!$e['pendiente'] && !$e['proceso'] && !$e['reanalisis']) return 'En validación';
    if ($e['pendiente'] === $l['total']) return 'Por iniciar';
    return 'En análisis';
};
/** Nombre corto de la hoja: el análisis si es uno solo, o la parte tras "Tipo|". */
$tb_hoja = static function (string $key, array $h): string {
    if (count($h['analisis']) === 1) {
        // Nombres largos con aclaración entre paréntesis: "CIC (capacidad de intercambio catiónico)" → "CIC".
        $n = (string) array_key_first($h['analisis']);
        return mb_strlen($n) > 24 && preg_match('/^(.+?)\s*\(/u', $n, $m) ? $m[1] : $n;
    }
    return strpos($key, '|') !== false ? substr($key, strpos($key, '|') + 1) : (string) array_key_first($h['analisis']);
};
/** Barra apilada por estado (sin texto: la leyenda está en la cabecera). */
$tb_barra = static function (array $cuenta, int $total, string $clase) use ($estados, $tb_e): string {
    $html = '<div class="' . $clase . '" role="img" aria-label="' . $tb_e(implode(', ', array_map(static fn($k) => $estados[$k][0] . ' ' . $cuenta[$k], array_keys($estados)))) . '">';
    foreach ($estados as $k => [$lbl, $col]) {
        if ($cuenta[$k]) {
            $html .= '<i style="width:' . round($cuenta[$k] * 100 / max(1, $total), 3) . '%;background:' . $col . '"></i>';
        }
    }
    return $html . '</div>';
};
$prioRank = ['alta' => 0, 'media' => 1, 'baja' => 2];

$global = ['lotes' => 0, 'muestras' => 0, 'total' => 0, 'aprobados' => 0, 'listos' => 0, 'atrasados' => 0];
foreach ($porTipo as &$g) {
    $g['atrasados'] = count(array_filter($g['lotes'], static fn($l) => $l['vence'] !== '' && $l['vence'] < $hoy));
    $g['listos'] = count(array_filter($g['lotes'], static fn($l) => $l['estados']['validado'] === $l['total']));
    $g['pct'] = $g['total'] ? (int) floor($g['estados']['validado'] * 100 / $g['total']) : 0;
    // Lotes: primero los atrasados, luego por prioridad y fecha de entrega.
    uasort($g['lotes'], static function ($a, $b) use ($hoy, $prioRank) {
        $atA = $a['vence'] !== '' && $a['vence'] < $hoy;
        $atB = $b['vence'] !== '' && $b['vence'] < $hoy;
        return [$atB, $prioRank[$a['prioridad']] ?? 1, $a['vence'] ?: '9999', $a['codigo']] <=> [$atA, $prioRank[$b['prioridad']] ?? 1, $b['vence'] ?: '9999', $b['codigo']];
    });
    $global['lotes'] += count($g['lotes']);
    $global['muestras'] += count($g['muestras']);
    $global['total'] += $g['total'];
    $global['aprobados'] += $g['estados']['validado'];
    $global['listos'] += $g['listos'];
    $global['atrasados'] += $g['atrasados'];
}
unset($g);
$global['pct'] = $global['total'] ? (int) floor($global['aprobados'] * 100 / $global['total']) : 0;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<meta name="robots" content="noindex, nofollow">
<title>Tablero del laboratorio</title>
<style>
:root{--bg:#EEF2EA;--card:#fff;--ink:#22272B;--muted:#5F666B;--line:#DCE5D2;--green:#4E8418;--late:#C0392B;
  --fs:clamp(11px,min(.78vw,1.45vh),20px)}
*{box-sizing:border-box}
html,body{margin:0;height:100%;background:var(--bg);color:var(--ink);font-family:"Segoe UI",Roboto,Arial,sans-serif;font-size:var(--fs)}
body{display:flex;flex-direction:column;gap:1em;padding:1.2em 1.4em;overflow:hidden}
b,strong{font-variant-numeric:tabular-nums}

/* Cabecera: título · indicadores · hora, en una franja; debajo la leyenda única */
.top{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:1.4em}
.top h1{margin:0;font-size:2.1em;line-height:1.05;white-space:nowrap}
.top h1 small{display:block;font-size:.42em;font-weight:600;color:var(--muted);margin-top:.2em}
.kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.6em}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:.8em;padding:.45em .8em}
.kpi span{display:block;color:var(--muted);font-weight:600;font-size:.9em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi b{display:block;font-size:2.2em;line-height:1.05}
.kpi.ok b{color:var(--green)}.kpi.late b{color:var(--late)}
.reloj{text-align:right;color:var(--muted);font-size:1.05em;white-space:nowrap}
.reloj b{display:block;color:var(--ink);font-size:2.3em;line-height:1}
.leyenda{display:flex;flex-wrap:wrap;align-items:center;gap:.35em 1.4em;color:var(--muted);font-size:.95em;margin-top:-.3em}
.leyenda span{display:inline-flex;align-items:center;gap:.45em}
.leyenda i{display:inline-block;width:.95em;height:.95em;border-radius:.2em}
.leyenda .sep{width:1px;height:1.2em;background:var(--line)}
.leyenda .marca-late{width:.3em;height:1.2em;border-radius:2px;background:var(--late)}

/* Columnas: una por tipo de muestra, a toda la altura */
.cols{flex:1;min-height:0;display:grid;grid-template-columns:repeat(var(--n),minmax(0,1fr));gap:1em}
.col{min-height:0;display:flex;flex-direction:column;background:var(--card);border:1px solid var(--line);border-top:.45em solid var(--c);border-radius:1em;overflow:hidden}
.col-head{padding:.8em 1em .7em;border-bottom:1px solid var(--line)}
.col-tit{display:flex;justify-content:space-between;align-items:baseline;gap:.6em}
.col-tit h2{margin:0;font-size:1.9em;color:var(--c)}
.col-tit .pct{font-size:2.6em;font-weight:800;line-height:1}
.col-tit .pct small{font-size:.34em;font-weight:600;color:var(--muted);margin-left:.2em}
.col-datos{display:flex;flex-wrap:wrap;gap:.1em 1em;color:var(--muted);margin:.25em 0 .5em}
.col-datos b{color:var(--ink)}.col-datos .late b,.col-datos .late{color:var(--late)}
.barra{display:flex;height:1.15em;border-radius:999px;overflow:hidden;background:#EEF0F1}
.barra i,.mini i{display:block;height:100%}
.cuentas{display:flex;flex-wrap:wrap;gap:.1em .9em;margin-top:.4em;font-size:.95em}
.cuentas span{display:inline-flex;align-items:center;gap:.35em;color:var(--muted)}
.cuentas i{width:.7em;height:.7em;border-radius:50%}
.cuentas b{color:var(--ink)}

/* Lotes */
.lotes{flex:1;min-height:0;overflow:hidden;display:flex;flex-direction:column}
.lote{padding:.6em 1em .65em;border-bottom:1px solid var(--line)}
.lote.is-late{box-shadow:inset .3em 0 0 var(--late)}
.l1{display:flex;align-items:baseline;gap:.5em}
.l1 .cod{font-size:1.25em;font-weight:800;white-space:nowrap}
.l1 .cli{color:var(--muted);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.l1 .p{font-size:1.25em;font-weight:800}
.prio{padding:0 .5em;border-radius:999px;background:#FDE2DF;color:var(--late);font-size:.8em;font-weight:800;align-self:center}
.mini{display:flex;height:.4em;border-radius:999px;overflow:hidden;background:#EEF0F1;margin:.3em 0}
.l2{display:flex;justify-content:space-between;gap:.6em;color:var(--muted);font-size:.95em}
.l2 b{color:var(--ink);font-weight:700}.l2 .late{color:var(--late);font-weight:700}
.hojas{display:flex;flex-wrap:wrap;gap:.3em;margin-top:.4em}
.hoja{display:inline-flex;align-items:center;gap:.35em;padding:.1em .6em;border-radius:999px;font-size:.92em;font-weight:600;white-space:nowrap}
.hoja em{font-style:normal;opacity:.7;font-size:.85em}
.hoja.st-pendiente{background:#EEF0F1;color:#474D51}.hoja.st-proceso{background:#FFF3CD;color:#7A5600}
.hoja.st-validacion{background:#E1ECF8;color:#1F4E85}.hoja.st-validado{background:#E3F1D3;color:#3A6312}.hoja.st-reanalisis{background:#FDE2DF;color:#9B2C20}
.mas{padding:.55em 1em;color:var(--muted);font-weight:700;border-top:1px dashed var(--line);margin-top:auto}
.mas[hidden]{display:none}
.vacio,.error{background:var(--card);border:1px dashed var(--line);border-radius:1em;padding:2em;text-align:center;font-size:1.5em;color:var(--muted)}
.error{color:var(--late)}
footer{color:var(--muted);font-size:.85em;text-align:right;margin-top:-.4em}

/* Pantallas angostas (no TV): apila y permite desplazarse */
@media (max-width:900px){
  body{overflow:auto;height:auto}html,body{height:auto}
  .top{grid-template-columns:1fr}.reloj{text-align:left}.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}
  .cols{grid-template-columns:1fr}.lotes{overflow:visible}
}
</style>
</head>
<body>
<header class="top">
    <h1>Avance del laboratorio<small>Lotes en curso · análisis solicitados por tipo de muestra</small></h1>
    <section class="kpis" aria-label="Resumen general">
        <div class="kpi"><span>Lotes en curso</span><b><?= $nf($global['lotes']) ?></b></div>
        <div class="kpi"><span>Muestras</span><b><?= $nf($global['muestras']) ?></b></div>
        <div class="kpi"><span>Análisis</span><b><?= $nf($global['total']) ?></b></div>
        <div class="kpi ok"><span>Aprobado</span><b><?= $global['pct'] ?>%</b></div>
        <div class="kpi ok"><span>Listos para informe</span><b><?= $nf($global['listos']) ?></b></div>
        <div class="kpi<?= $global['atrasados'] ? ' late' : '' ?>"><span>Lotes atrasados</span><b><?= $nf($global['atrasados']) ?></b></div>
    </section>
    <div class="reloj"><b id="hora"><?= date('H:i') ?></b><?= $tb_e(date('d/m/Y')) ?></div>
</header>
<div class="leyenda" aria-label="Leyenda">
    <?php foreach ($estados as [$lbl, $col]): ?><span><i style="background:<?= $col ?>"></i><?= $tb_e($lbl) ?></span><?php endforeach; ?>
    <span class="sep"></span>
    <span><i class="marca-late"></i>Lote atrasado</span>
    <span><span class="prio">Alta</span>Prioridad alta</span>
    <span>Hojas de trabajo: aprobados/solicitados · <b>✓ N completas</b> = hojas ya aprobadas</span>
</div>

<?php if ($error): ?>
    <div class="error"><?= $tb_e($error) ?></div>
<?php endif; ?>

<?php if (!$porTipo && !$error): ?>
    <div class="vacio">No hay lotes en curso.</div>
<?php elseif ($porTipo): ?>
<main class="cols" style="--n:<?= count($porTipo) ?>" aria-label="Avance por tipo de muestra">
    <?php foreach ($porTipo as $tipo => $g): [$nombre, $color] = $tipos[$tipo] ?? [$tipo, '#858C91']; ?>
    <section class="col" style="--c:<?= $tb_e($color) ?>">
        <div class="col-head">
            <div class="col-tit"><h2><?= $tb_e($nombre) ?></h2><div class="pct"><?= $g['pct'] ?>%<small>aprobado</small></div></div>
            <div class="col-datos">
                <span><b><?= $nf(count($g['lotes'])) ?></b> lote<?= count($g['lotes']) === 1 ? '' : 's' ?></span>
                <span><b><?= $nf(count($g['muestras'])) ?></b> muestras</span>
                <span><b><?= $nf($g['total']) ?></b> análisis</span>
                <?php if ($g['listos']): ?><span><b><?= $nf($g['listos']) ?></b> para informe</span><?php endif; ?>
                <?php if ($g['atrasados']): ?><span class="late"><b><?= $nf($g['atrasados']) ?></b> atrasado<?= $g['atrasados'] === 1 ? '' : 's' ?></span><?php endif; ?>
            </div>
            <?= $tb_barra($g['estados'], $g['total'], 'barra') ?>
            <div class="cuentas">
                <?php foreach ($estados as $k => [$lbl, $col]): if (!$g['estados'][$k]) continue; ?>
                    <span title="<?= $tb_e($lbl) ?>"><i style="background:<?= $col ?>"></i><b><?= $nf($g['estados'][$k]) ?></b></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="lotes">
            <?php foreach (array_slice($g['lotes'], 0, 40, true) as $idLote => $l):
                $atrasado = $l['vence'] !== '' && $l['vence'] < $hoy;
                $pctL = $l['total'] ? (int) floor($l['estados']['validado'] * 100 / $l['total']) : 0;
                $completas = array_filter($l['hojas'], static fn($h) => $tb_estado($h['estados']) === 'validado');
                $faltan = array_diff_key($l['hojas'], $completas); ?>
            <article class="lote<?= $atrasado ? ' is-late' : '' ?>">
                <div class="l1">
                    <span class="cod"><?= $tb_e($l['codigo']) ?></span>
                    <?php if ($l['prioridad'] === 'alta'): ?><span class="prio">Alta</span><?php endif; ?>
                    <span class="cli"><?= $tb_e($l['cliente'] ?: 'Sin cliente') ?> · <?= $nf(count($l['muestras'])) ?> m.</span>
                    <span class="p"><?= $pctL ?>%</span>
                </div>
                <?= $tb_barra($l['estados'], $l['total'], 'mini') ?>
                <div class="l2">
                    <b><?= $tb_e($tb_etapa($l, $seguimiento[$idLote]['informe'] ?? null)) ?></b>
                    <span class="<?= $atrasado ? 'late' : '' ?>"><?= $l['vence'] !== '' ? ($atrasado ? 'Atrasado · ' : 'Entrega ') . $tb_e(date('d/m', strtotime($l['vence']))) : 'Sin fecha' ?></span>
                </div>
                <div class="hojas">
                    <?php foreach ($faltan as $hk => $h): $st = $tb_estado($h['estados']); ?>
                        <span class="hoja st-<?= $st ?>" title="<?= $tb_e(implode(', ', array_keys($h['analisis']))) ?>"><?= $tb_e($tb_hoja($hk, $h)) ?><?php if (count($h['analisis']) > 1): ?><em>(<?= count($h['analisis']) ?>)</em><?php endif; ?> <b><?= $nf($h['estados']['validado'] ?? 0) ?>/<?= $nf(array_sum($h['estados'])) ?></b></span>
                    <?php endforeach; ?>
                    <?php if ($completas): ?>
                        <span class="hoja st-validado" title="<?= $tb_e(implode(', ', array_map(static fn($k) => $tb_hoja($k, $completas[$k]), array_keys($completas)))) ?>">✓ <?= count($completas) ?> completa<?= count($completas) === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <div class="mas" data-total="<?= count($g['lotes']) ?>" hidden></div>
    </section>
    <?php endforeach; ?>
</main>
<?php endif; ?>

<footer>Actualizado <?= $tb_e(date('d/m/Y H:i')) ?> · se actualiza solo cada minuto</footer>
<script>
(function(){
  var el=document.getElementById('hora');
  setInterval(function(){var d=new Date();el.textContent=String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');},15000);
  // Oculta los lotes que no caben en su columna y muestra cuántos quedan fuera.
  function ajustar(){
    document.querySelectorAll('.col').forEach(function(col){
      var lista=col.querySelector('.lotes'),mas=col.querySelector('.mas'),lotes=lista.querySelectorAll('.lote');
      lotes.forEach(function(l){l.hidden=false;});mas.hidden=true;
      if(getComputedStyle(lista).overflow!=='hidden')return;
      var total=Number(mas.dataset.total)||lotes.length,visibles=lotes.length;
      var cabe=function(){return lista.scrollHeight<=lista.clientHeight+1;};
      if(cabe()&&total===lotes.length)return;
      mas.hidden=false;
      while(visibles>1&&!cabe()){lotes[visibles-1].hidden=true;visibles--;}
      var ocultos=total-visibles;mas.hidden=ocultos<=0;mas.textContent='+ '+ocultos+' lote'+(ocultos===1?'':'s')+' más';
    });
  }
  window.addEventListener('resize',ajustar);ajustar();
})();
</script>
</body>
</html>
