<?php
/**
 * Tablero de avance para pantalla (TV): resumen por tipo de muestra de los
 * lotes en curso (los que no tienen informe firmado). Solo lectura.
 *
 * Diseño para pantalla 16:9 sin desplazamiento: una columna por tipo de
 * muestra, leyenda única en la cabecera y, por lote, solo las hojas de trabajo
 * que faltan (las completas se resumen). Si los lotes no caben en la columna,
 * se reparten en páginas que rotan solas.
 *
 * La página se actualiza sola cada minuto pidiendo los datos en segundo plano:
 * si el servidor no responde, se quedan a la vista los últimos datos con un
 * aviso y se sigue reintentando (una recarga normal dejaría la TV en la página
 * de error del navegador hasta que alguien la refresque).
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

/*
 * Datos del tablero con caché de 55 s en el servidor: si hay varias pantallas abiertas,
 * la base se consulta una sola vez por minuto y todas reciben el mismo resultado.
 * Solo se leen los lotes en curso; de los finalizados basta un conteo.
 */
$cacheArchivo = sys_get_temp_dir() . '/cengilabs_tablero_' . substr(hash('sha256', __DIR__ . filemtime(__FILE__)), 0, 12) . '.cache';
$cacheSegundos = 55;
$datos = null;
if (is_file($cacheArchivo) && time() - (int) @filemtime($cacheArchivo) < $cacheSegundos) {
    $datos = @unserialize((string) @file_get_contents($cacheArchivo), ['allowed_classes' => false]) ?: null;
}
$error = null;
if ($datos === null) {
    try {
        $pdo = Conexion::conectar();
        $datos = ['tareas' => wfTareas($pdo, true), 'seguimiento' => wfSeguimientoLotes($pdo), 'finalizados' => ['mes' => 0, 'anterior' => 0], 'hora' => time()];
        try {
            // Lotes finalizados (informe firmado) este mes y el mes anterior, solo como estadística.
            $inicioMes = date('Y-m-01');
            $q = $pdo->prepare("SELECT COALESCE(SUM(fecha_firma >= ?), 0) AS mes, COALESCE(SUM(fecha_firma < ?), 0) AS anterior FROM lab_informe WHERE estado = 'FIRMADO' AND fecha_firma >= ?");
            $q->execute([$inicioMes, $inicioMes, date('Y-m-01', strtotime($inicioMes . ' -1 month'))]);
            $datos['finalizados'] = array_map('intval', $q->fetch(PDO::FETCH_ASSOC) ?: ['mes' => 0, 'anterior' => 0]);
        } catch (Throwable $e) {
            // Sin tabla de informes todavía: los conteos quedan en cero.
        }
        // Escritura atómica: otra pantalla nunca lee un archivo a medio escribir.
        $tmp = $cacheArchivo . '.' . getmypid();
        if (@file_put_contents($tmp, serialize($datos)) !== false) {
            @rename($tmp, $cacheArchivo);
        }
    } catch (Throwable $e) {
        error_log('[laboratorio][tablero] ' . $e->getMessage());
        $datos = ['tareas' => [], 'seguimiento' => [], 'finalizados' => ['mes' => 0, 'anterior' => 0], 'hora' => time()];
        $error = 'No se pudo cargar la información. Se reintentará en un minuto.';
        // 503: la actualización en segundo plano conserva los datos que ya están en pantalla.
        http_response_code(503);
    }
}
$tareas = $datos['tareas'];
$seguimiento = $datos['seguimiento'];
$finalizados = $datos['finalizados'];
$horaDatos = (int) $datos['hora'];

// Tipos en el mismo orden, colores e íconos que Planificación.
$tipos = [
    'Agua' => ['Agua', '#4F6B7A', '<path d="M12 2s7 8 7 13a7 7 0 0 1-14 0c0-5 7-13 7-13z"/>'],
    'Caña' => ['Caña', '#8A6E00', '<path d="M12 22V6M12 6l5-4M12 10L7 6M12 14l5-4M12 18l-5-4"/>'],
    'Foliar' => ['Foliares', '#4E8418', '<path d="M11 20A7 7 0 0 1 4 13V7a1 1 0 0 1 1-1h1a7 7 0 0 1 7 7v7z"/><path d="M4 12h4M14 20a7 7 0 0 0 7-7V8"/>'],
    'Suelo' => ['Suelos', '#B34B00', '<path d="M2 20h20M4 20V10l4-3 4 3 4-3 4 3v10"/>'],
    'Miel' => ['Mieles', '#C47A00', '<path d="M7 3h10l-1 4H8z"/><path d="M8 7c-2 2-3 4-3 7a7 7 0 0 0 14 0c0-3-1-5-3-7"/>'],
];
$iconoOtro = '<circle cx="12" cy="12" r="9"/>';
// Orden de la barra: lo aprobado primero, lo pendiente al final.
$estados = [
    'validado' => ['Aprobado', '#4E8418'],
    'validacion' => ['En validación', '#2F6DB5'],
    'proceso' => ['En análisis', '#E0A800'],
    'reanalisis' => ['Reanálisis', '#C0392B'],
    'pendiente' => ['Pendiente', '#C9CDD0'],
];
$hoy = date('Y-m-d');
$manana = date('Y-m-d', strtotime('+1 day'));
/** Situación de la fecha de entrega: late (vencida), hoy, manana o '' (a tiempo / sin fecha). */
$tb_vence = static fn(string $v): string => $v === '' ? '' : ($v < $hoy ? 'late' : ($v === $hoy ? 'hoy' : ($v === $manana ? 'manana' : '')));

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
    $l ??= ['codigo' => $t['codigo_lote'], 'cliente' => $t['cliente'] ?? '',
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

$global = ['lotes' => 0, 'muestras' => 0, 'total' => 0, 'aprobados' => 0, 'listos' => 0, 'atrasados' => 0];
foreach ($porTipo as &$g) {
    $g['atrasados'] = count(array_filter($g['lotes'], static fn($l) => $tb_vence($l['vence']) === 'late'));
    $g['pronto'] = count(array_filter($g['lotes'], static fn($l) => in_array($tb_vence($l['vence']), ['hoy', 'manana'], true)));
    $g['listos'] = count(array_filter($g['lotes'], static fn($l) => $l['estados']['validado'] === $l['total']));
    $g['pct'] = $g['total'] ? (int) floor($g['estados']['validado'] * 100 / $g['total']) : 0;
    // Lotes: primero los atrasados, luego por fecha de entrega (los que vencen hoy o mañana quedan arriba).
    uasort($g['lotes'], static function ($a, $b) use ($hoy) {
        $atA = $a['vence'] !== '' && $a['vence'] < $hoy;
        $atB = $b['vence'] !== '' && $b['vence'] < $hoy;
        return [$atB, $a['vence'] ?: '9999', $a['codigo']] <=> [$atA, $b['vence'] ?: '9999', $b['codigo']];
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
// Ancho de cada columna según sus lotes (1 a 4 partes): los tipos con más trabajo tienen más espacio.
$anchos = implode(' ', array_map(static fn($g) => 'minmax(0,' . max(1, min(count($g['lotes']), 4)) . 'fr)', $porTipo));
// Si cambia el código del tablero, la TV se recarga sola (solo tras una respuesta correcta del servidor).
$version = (string) filemtime(__FILE__);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tablero · CengiLabs</title>
<link rel="icon" type="image/svg+xml" href="../img/logo-cengilabs.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--bg:#EDF2E6;--card:#fff;--ink:#1F2A22;--muted:#5C665E;--line:#DCE6D1;--green:#4E8418;--late:#C0392B;--soon:#B86E00;
  --lima:#bcf32e;--lima-suave:#e6ff9e;--bosque:#04330f;
  --fs:clamp(11px,min(.78vw,1.45vh),20px)}
*{box-sizing:border-box}
html,body{margin:0;height:100%;color:var(--ink);font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif;font-size:var(--fs)}
body{overflow:hidden;background:radial-gradient(120% 80% at 100% 0%,#E2EED3 0%,transparent 55%),var(--bg)}
#tb-app{height:100%;display:flex;flex-direction:column;gap:1em;padding:1.1em 1.3em}
b,strong{font-variant-numeric:tabular-nums}

/* Cabecera CengiLabs: franja oscura con la foto del cañal, como el menú lateral */
.hero{position:relative;isolation:isolate;overflow:hidden;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:1.6em;
  padding:1em 1.4em 1.2em;border-radius:1.2em;color:#fff;
  background:linear-gradient(105deg,rgba(4,51,15,.97) 0%,rgba(10,74,28,.93) 55%,rgba(13,90,34,.80) 100%),url('../img/campo-cana.jpg') center 60%/cover;
  box-shadow:0 1.2em 2.6em rgba(7,60,24,.22)}
.hero::after{content:"";position:absolute;left:0;right:0;bottom:0;height:.32em;background:linear-gradient(90deg,#73BC25,#A3D300,#FFCC00,#FF6B00)}
.hero::before{content:"";position:absolute;top:0;bottom:0;left:-40%;width:28%;z-index:-1;transform:skewX(-18deg);
  background:linear-gradient(100deg,transparent,rgba(188,243,46,.13),transparent);animation:barrido 11s 2s ease-in-out infinite}
.marca{display:flex;align-items:center;gap:.9em;white-space:nowrap}
.marca img{width:4.6em;height:auto;filter:drop-shadow(0 .4em .7em rgba(0,0,0,.3))}
.marca strong{display:block;font-size:2.5em;font-weight:900;letter-spacing:-.055em;line-height:1}
.marca strong b{color:var(--lima);font-weight:900}
.marca small{display:flex;align-items:center;gap:.6em;margin-top:.35em;font-size:1.05em;font-weight:700;color:rgba(255,255,255,.75)}
.vivo{display:inline-flex;align-items:center;gap:.4em;padding:.15em .65em;border-radius:999px;background:rgba(188,243,46,.16);border:1px solid rgba(188,243,46,.45);color:var(--lima-suave);font-size:.82em;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.vivo i{width:.55em;height:.55em;border-radius:50%;background:var(--lima);box-shadow:0 0 0 0 rgba(188,243,46,.7);animation:pulso 2s infinite}
body.caido .vivo{background:rgba(255,107,0,.2);border-color:rgba(255,160,120,.6);color:#ffd2c9}
body.caido .vivo i{background:#FF6B00;animation:none}
.kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.7em}
.kpi{padding:.5em .85em .55em;border-radius:.9em;background:rgba(255,255,255,.08);border:1px solid rgba(188,243,46,.22);backdrop-filter:blur(6px)}
.kpi span{display:block;color:rgba(255,255,255,.72);font-weight:700;font-size:.88em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi b{display:block;font-size:2.3em;font-weight:900;letter-spacing:-.03em;line-height:1.05;color:#fff}
.kpi b small{font-size:.4em;font-weight:700;letter-spacing:0;color:rgba(255,255,255,.7)}
.kpi.ok b{color:var(--lima)}.kpi.late{border-color:rgba(255,140,110,.55);background:rgba(192,57,43,.22)}.kpi.late b{color:#ffc2b6}
.reloj{text-align:right;color:rgba(255,255,255,.72);font-size:1.05em;font-weight:600;white-space:nowrap}
.reloj b{display:block;color:#fff;font-size:2.6em;font-weight:900;letter-spacing:-.04em;line-height:1}
.leyenda{display:flex;flex-wrap:wrap;align-items:center;gap:.35em 1.4em;padding:.45em 1em;border-radius:999px;background:rgba(255,255,255,.7);border:1px solid var(--line);color:var(--muted);font-size:.92em;font-weight:600;align-self:flex-start}
.leyenda span{display:inline-flex;align-items:center;gap:.45em}
.leyenda i{display:inline-block;width:.95em;height:.95em;border-radius:.25em}
.leyenda .sep{width:1px;height:1.2em;background:var(--line)}
.leyenda .marca-late,.leyenda .marca-soon{width:.3em;height:1.2em;border-radius:2px;background:var(--late)}
.leyenda .marca-soon{background:var(--soon)}

/* Columnas: una por tipo de muestra, a toda la altura; el ancho depende de sus lotes */
.cols{flex:1;min-height:0;display:grid;gap:1em}
.col{min-height:0;display:flex;flex-direction:column;background:var(--card);border:1px solid var(--line);border-radius:1.1em;overflow:hidden;box-shadow:0 .6em 1.6em rgba(7,60,24,.08)}
.col-head{padding:.85em 1em .75em;border-bottom:1px solid var(--line);background:linear-gradient(180deg,color-mix(in srgb,var(--c) 9%,#fff),#fff);border-top:.35em solid var(--c)}
.col-tit{display:flex;justify-content:space-between;align-items:center;gap:.6em}
.col-tit h2{display:flex;align-items:center;gap:.5em;margin:0;font-size:1.8em;font-weight:900;letter-spacing:-.035em;color:var(--c)}
.ico{display:inline-flex;align-items:center;justify-content:center;width:1.45em;height:1.45em;border-radius:.4em;background:var(--c);box-shadow:0 .25em .6em color-mix(in srgb,var(--c) 40%,transparent)}
.ico svg{width:.9em;height:.9em}
.col-tit .pct{font-size:2.6em;font-weight:900;letter-spacing:-.04em;line-height:1}
.col-tit .pct small{font-size:.34em;font-weight:700;letter-spacing:0;color:var(--muted);margin-left:.2em}
.col-datos{display:flex;flex-wrap:wrap;gap:.1em 1em;color:var(--muted);margin:.25em 0 .5em}
.col-datos b{color:var(--ink)}.col-datos .late b,.col-datos .late{color:var(--late)}.col-datos .soon b,.col-datos .soon{color:var(--soon)}
.barra{display:flex;height:1.15em;border-radius:999px;overflow:hidden;background:#EEF0F1}
.barra i,.mini i{display:block;height:100%}
.cuentas{display:flex;flex-wrap:wrap;gap:.1em .9em;margin-top:.4em;font-size:.95em}
.cuentas span{display:inline-flex;align-items:center;gap:.35em;color:var(--muted)}
.cuentas i{width:.7em;height:.7em;border-radius:50%}
.cuentas b{color:var(--ink)}

/* Lotes */
.lotes{flex:1;min-height:0;overflow:hidden;display:flex;flex-direction:column}
.lote{padding:.6em 1em .65em;border-bottom:1px solid var(--line)}
.lote[hidden]{display:none}
.lote.is-late{box-shadow:inset .3em 0 0 var(--late)}
.lote.is-soon{box-shadow:inset .3em 0 0 var(--soon)}
.l1{display:flex;align-items:baseline;gap:.5em}
.l1 .cod{font-size:1.25em;font-weight:800;white-space:nowrap}
.l1 .cli{color:var(--muted);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.l1 .p{font-size:1.25em;font-weight:800}
.mini{display:flex;height:.4em;border-radius:999px;overflow:hidden;background:#EEF0F1;margin:.3em 0}
.l2{display:flex;justify-content:space-between;gap:.6em;color:var(--muted);font-size:.95em}
.l2 b{color:var(--ink);font-weight:700}.l2 .late{color:var(--late);font-weight:700}.l2 .soon{color:var(--soon);font-weight:700}
.hojas{display:flex;flex-wrap:wrap;gap:.3em;margin-top:.4em}
.hoja{display:inline-flex;align-items:center;gap:.35em;padding:.1em .6em;border-radius:999px;font-size:.92em;font-weight:600;white-space:nowrap}
.hoja em{font-style:normal;opacity:.7;font-size:.85em}
.hoja.st-pendiente{background:#EEF0F1;color:#474D51}.hoja.st-proceso{background:#FFF3CD;color:#7A5600}
.hoja.st-validacion{background:#E1ECF8;color:#1F4E85}.hoja.st-validado{background:#E3F1D3;color:#3A6312}.hoja.st-reanalisis{background:#FDE2DF;color:#9B2C20}
/* Pie de columna: página actual cuando los lotes no caben */
.mas{display:flex;align-items:center;justify-content:space-between;gap:.6em;padding:.55em 1em;color:var(--muted);font-weight:700;border-top:1px dashed var(--line);margin-top:auto}
.mas[hidden]{display:none}
.mas .puntos{display:flex;gap:.35em}
.mas .puntos i{width:.55em;height:.55em;border-radius:50%;background:#D5DAD0}
.mas .puntos i.on{background:var(--c)}
.vacio,.error{background:var(--card);border:1px dashed var(--line);border-radius:1em;padding:2em;text-align:center;font-size:1.5em;color:var(--muted)}
.error{color:var(--late)}
footer{color:var(--muted);font-size:.85em;text-align:right;margin-top:-.4em}
/* Aviso de conexión perdida: los datos en pantalla son los últimos recibidos */
.offline{position:fixed;left:50%;bottom:1.2em;transform:translateX(-50%);background:#7A1F16;color:#fff;padding:.6em 1.4em;border-radius:999px;font-weight:700;font-size:1.05em;box-shadow:0 .4em 1.2em rgba(0,0,0,.25);white-space:nowrap}
.offline[hidden]{display:none}

/* Movimiento: entrada solo en la primera carga (no en cada actualización) y fundido al cambiar de página */
body:not(.listo) .hero,body:not(.listo) .leyenda,body:not(.listo) .col{animation:entrar .6s cubic-bezier(.2,.8,.2,1) backwards}
body:not(.listo) .col:nth-child(2){animation-delay:.08s}body:not(.listo) .col:nth-child(3){animation-delay:.16s}
body:not(.listo) .col:nth-child(4){animation-delay:.24s}body:not(.listo) .col:nth-child(5){animation-delay:.32s}
.lotes.cambia .lote:not([hidden]){animation:fundido .55s ease-out backwards}
@keyframes entrar{from{opacity:0;transform:translateY(.9em)}}
@keyframes fundido{from{opacity:0;transform:translateX(.6em)}}
@keyframes barrido{0%{left:-40%}25%,100%{left:130%}}
@keyframes pulso{70%{box-shadow:0 0 0 .55em rgba(188,243,46,0)}100%{box-shadow:0 0 0 0 rgba(188,243,46,0)}}
@media (prefers-reduced-motion:reduce){*{animation:none!important}}

/* Pantallas angostas (no TV): apila y permite desplazarse */
@media (max-width:900px){
  body{overflow:auto}html,body,#tb-app{height:auto}
  .hero{grid-template-columns:1fr;gap:1em}.reloj{text-align:left}.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}
  .leyenda{border-radius:1em}
  .cols{grid-template-columns:1fr!important}.lotes{overflow:visible}
  .offline{white-space:normal;width:calc(100% - 32px);text-align:center;border-radius:1em}
}
</style>
</head>
<body>
<div id="tb-app" data-version="<?= $tb_e($version) ?>">
<header class="hero">
    <div class="marca">
        <img src="../img/logo-cengilabs.svg" alt="" aria-hidden="true">
        <div><strong>Cengi<b>Labs</b></strong><small>Avance del laboratorio <span class="vivo"><i></i><span id="tb-vivo">En vivo</span></span></small></div>
    </div>
    <section class="kpis" aria-label="Resumen general">
        <div class="kpi"><span>Lotes en curso</span><b><?= $nf($global['lotes']) ?></b></div>
        <div class="kpi"><span>Muestras</span><b><?= $nf($global['muestras']) ?></b></div>
        <div class="kpi"><span>Finalizados este mes</span><b><?= $nf($finalizados['mes']) ?><small> · <?= $nf($finalizados['anterior']) ?> el mes pasado</small></b></div>
        <div class="kpi ok"><span>Aprobado</span><b><?= $global['pct'] ?>%</b></div>
        <div class="kpi ok"><span>Listos para informe</span><b><?= $nf($global['listos']) ?></b></div>
        <div class="kpi<?= $global['atrasados'] ? ' late' : '' ?>"><span>Lotes atrasados</span><b><?= $nf($global['atrasados']) ?></b></div>
    </section>
    <div class="reloj"><b id="hora"><?= date('H:i') ?></b><?= $tb_e(date('d/m/Y')) ?></div>
</header>
<div class="leyenda" aria-label="Leyenda">
    <?php foreach ($estados as [$lbl, $col]): ?><span><i style="background:<?= $col ?>"></i><?= $tb_e($lbl) ?></span><?php endforeach; ?>
    <span class="sep"></span>
    <span><i class="marca-late"></i>Atrasado</span>
    <span><i class="marca-soon"></i>Vence hoy o mañana</span>
    <span class="sep"></span>
    <span>Hojas: aprobados/solicitados</span>
</div>

<?php if ($error): ?>
    <div class="error"><?= $tb_e($error) ?></div>
<?php endif; ?>

<?php if (!$porTipo && !$error): ?>
    <div class="vacio">No hay lotes en curso.</div>
<?php elseif ($porTipo): ?>
<main class="cols" style="grid-template-columns:<?= $anchos ?>" aria-label="Avance por tipo de muestra">
    <?php foreach ($porTipo as $tipo => $g): [$nombre, $color, $icono] = $tipos[$tipo] ?? [$tipo, '#858C91', $iconoOtro]; ?>
    <section class="col" style="--c:<?= $tb_e($color) ?>">
        <div class="col-head">
            <div class="col-tit"><h2><span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><?= $icono ?></svg></span><?= $tb_e($nombre) ?></h2><div class="pct"><?= $g['pct'] ?>%<small>aprobado</small></div></div>
            <div class="col-datos">
                <span><b><?= $nf(count($g['lotes'])) ?></b> lote<?= count($g['lotes']) === 1 ? '' : 's' ?></span>
                <span><b><?= $nf(count($g['muestras'])) ?></b> muestras</span>
                <span><b><?= $nf($g['total']) ?></b> análisis</span>
                <?php if ($g['listos']): ?><span><b><?= $nf($g['listos']) ?></b> para informe</span><?php endif; ?>
                <?php if ($g['atrasados']): ?><span class="late"><b><?= $nf($g['atrasados']) ?></b> atrasado<?= $g['atrasados'] === 1 ? '' : 's' ?></span><?php endif; ?>
                <?php if ($g['pronto']): ?><span class="soon"><b><?= $nf($g['pronto']) ?></b> por vencer</span><?php endif; ?>
            </div>
            <?= $tb_barra($g['estados'], $g['total'], 'barra') ?>
            <div class="cuentas">
                <?php foreach ($estados as $k => [$lbl, $col]): if (!$g['estados'][$k]) continue; ?>
                    <span title="<?= $tb_e($lbl) ?>"><i style="background:<?= $col ?>"></i><b><?= $nf($g['estados'][$k]) ?></b></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="lotes">
            <?php foreach (array_slice($g['lotes'], 0, 200, true) as $idLote => $l):
                $sv = $tb_vence($l['vence']);
                $pctL = $l['total'] ? (int) floor($l['estados']['validado'] * 100 / $l['total']) : 0;
                $completas = array_filter($l['hojas'], static fn($h) => $tb_estado($h['estados']) === 'validado');
                $faltan = array_diff_key($l['hojas'], $completas);
                $textoVence = match ($sv) {
                    'late' => 'Atrasado · ' . date('d/m', strtotime($l['vence'])),
                    'hoy' => 'Vence hoy',
                    'manana' => 'Vence mañana',
                    default => $l['vence'] !== '' ? 'Entrega ' . date('d/m', strtotime($l['vence'])) : 'Sin fecha',
                }; ?>
            <article class="lote<?= $sv === 'late' ? ' is-late' : ($sv !== '' ? ' is-soon' : '') ?>">
                <div class="l1">
                    <span class="cod"><?= $tb_e($l['codigo']) ?></span>
                    <span class="cli"><?= $tb_e($l['cliente'] ?: 'Sin cliente') ?> · <?= $nf(count($l['muestras'])) ?> m.</span>
                    <span class="p"><?= $pctL ?>%</span>
                </div>
                <?= $tb_barra($l['estados'], $l['total'], 'mini') ?>
                <div class="l2">
                    <b><?= $tb_e($tb_etapa($l, $seguimiento[$idLote]['informe'] ?? null)) ?></b>
                    <span class="<?= $sv === 'late' ? 'late' : ($sv !== '' ? 'soon' : '') ?>"><?= $tb_e($textoVence) ?></span>
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

<footer>CengiLabs · Laboratorio Agroindustrial CENGICAÑA · Datos de las <?= $tb_e(date('H:i', $horaDatos)) ?> del <?= $tb_e(date('d/m/Y', $horaDatos)) ?> · se actualiza solo cada minuto</footer>
</div>
<div id="tb-offline" class="offline" role="status" aria-live="polite" hidden></div>
<script>
(function(){
  var REFRESCO=60000,ROTACION=12000,pagina=0,caidoDesde=null;
  var dos=function(n){return String(n).padStart(2,'0');};
  var hhmm=function(d){return dos(d.getHours())+':'+dos(d.getMinutes());};
  setInterval(function(){var el=document.getElementById('hora');if(el)el.textContent=hhmm(new Date());},15000);

  // Reparte los lotes de cada columna en páginas del alto disponible.
  function paginar(){
    document.querySelectorAll('.col').forEach(function(col){
      var lista=col.querySelector('.lotes'),mas=col.querySelector('.mas'),lotes=[].slice.call(lista.querySelectorAll('.lote'));
      lotes.forEach(function(l){l.hidden=false;});mas.hidden=true;col._paginas=[lotes];
      if(getComputedStyle(lista).overflow!=='hidden'||lista.scrollHeight<=lista.clientHeight+1)return;
      mas.hidden=false;mas.innerHTML='<span>Página 1 de 9</span><span class="puntos"></span>'; // reserva su alto antes de medir
      var alto=lista.clientHeight,paginas=[],actual=[],usado=0;
      lotes.forEach(function(l){var h=l.offsetHeight;if(actual.length&&usado+h>alto){paginas.push(actual);actual=[];usado=0;}actual.push(l);usado+=h;});
      if(actual.length)paginas.push(actual);
      col._paginas=paginas;
    });
    mostrar();
  }
  function mostrar(){
    document.querySelectorAll('.col').forEach(function(col){
      var ps=col._paginas||[],mas=col.querySelector('.mas');if(ps.length<2)return;
      var p=pagina%ps.length,total=Number(mas.dataset.total)||0,fuera=total-ps.reduce(function(n,x){return n+x.length;},0);
      ps.forEach(function(lista,i){lista.forEach(function(l){l.hidden=i!==p;});});
      var cont=col.querySelector('.lotes');cont.classList.remove('cambia');void cont.offsetWidth;cont.classList.add('cambia');
      var puntos='';for(var i=0;i<ps.length;i++)puntos+='<i'+(i===p?' class="on"':'')+'></i>';
      mas.innerHTML='<span>Página '+(p+1)+' de '+ps.length+' · '+total+' lotes'+(fuera>0?' ('+fuera+' sin mostrar)':'')+'</span><span class="puntos" aria-hidden="true">'+puntos+'</span>';
    });
  }
  setInterval(function(){pagina++;mostrar();},ROTACION);

  // Actualización en segundo plano: si falla, se quedan los últimos datos y se avisa.
  function avisar(){
    var el=document.getElementById('tb-offline'),vivo=document.getElementById('tb-vivo');
    document.body.classList.toggle('caido',!!caidoDesde);
    if(vivo)vivo.textContent=caidoDesde?'Sin conexión':'En vivo';
    if(!caidoDesde){el.hidden=true;return;}
    el.textContent='Sin conexión con el servidor desde las '+hhmm(caidoDesde)+' · mostrando los últimos datos · reintentando…';el.hidden=false;
  }
  function actualizar(){
    var ctrl=window.AbortController?new AbortController():null,t=setTimeout(function(){if(ctrl)ctrl.abort();},20000);
    fetch(location.href,{cache:'no-store',credentials:'same-origin',signal:ctrl?ctrl.signal:undefined})
      .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text();})
      .then(function(html){
        var nuevo=new DOMParser().parseFromString(html,'text/html').getElementById('tb-app'),app=document.getElementById('tb-app');
        if(!nuevo)throw new Error('Respuesta inesperada');
        // El código del tablero cambió y el servidor responde: recarga completa para tomar la versión nueva.
        if(nuevo.dataset.version!==app.dataset.version){location.reload();return;}
        app.innerHTML=nuevo.innerHTML;caidoDesde=null;avisar();paginar();
      })
      .catch(function(){if(!caidoDesde)caidoDesde=new Date();avisar();})
      .then(function(){clearTimeout(t);});
  }
  // Con la pestaña oculta o la pantalla apagada no se piden datos; al volver se actualiza enseguida.
  var ultimo=Date.now();
  setInterval(function(){if(document.hidden)return;ultimo=Date.now();actualizar();},REFRESCO);
  document.addEventListener('visibilitychange',function(){if(!document.hidden&&Date.now()-ultimo>=REFRESCO){ultimo=Date.now();actualizar();}});
  window.addEventListener('resize',paginar);
  paginar();
  // Tras la primera carga se apagan las animaciones de entrada, para que no se repitan en cada actualización.
  setTimeout(function(){document.body.classList.add('listo');},1500);
  // Las fuentes web cambian el alto de los lotes: se vuelve a paginar cuando terminan de cargar.
  if(document.fonts&&document.fonts.ready)document.fonts.ready.then(paginar);
})();
</script>
</body>
</html>
