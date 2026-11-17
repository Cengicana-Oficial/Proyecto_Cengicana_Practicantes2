<?php
declare(strict_types=1);
require_once __DIR__ . '/marca.php';

// Resumen de redes sociales: indicadores del periodo comparados con el periodo
// anterior, tendencia mensual, rendimiento por plataforma, audiencia por pais y
// el reporte descargable (PDF, HTML para imprimir y Excel) con secciones a elegir.

const REDES_REPORTE_SECCIONES = [
    'resumen' => 'Resumen ejecutivo e indicadores',
    'cuenta' => 'Alcance orgánico y pagado, crecimiento e inversión',
    'tendencia' => 'Tendencia mensual',
    'plataformas' => 'Rendimiento por plataforma',
    'audiencia' => 'Público: país, ciudad, edad y género',
    'destacadas' => 'Publicaciones destacadas por red',
    'recomendaciones' => 'Qué funciona: temas, formatos, pauta y recomendaciones',
    'top' => 'Ranking de publicaciones',
    'web' => 'Sitio web (Google Analytics)',
    'conclusiones' => 'Conclusiones',
];

/** Periodo inmediatamente anterior con la misma cantidad de meses (null para "todo el historial"). */
function redes_periodo_anterior(array $periodo): ?array
{
    if ($periodo['desde'] === null) {
        return null;
    }
    $desde = new DateTimeImmutable($periodo['desde_mes'] . '-01');
    $hasta = new DateTimeImmutable($periodo['hasta_mes'] . '-01');
    $meses = ((int) $hasta->format('Y') - (int) $desde->format('Y')) * 12 + (int) $hasta->format('n') - (int) $desde->format('n') + 1;
    $ini = $desde->modify("-$meses months");
    $fin = $desde->modify('-1 day');
    return [
        'clave' => 'rango', 'desde' => $ini->format('Y-m-d'), 'hasta' => $fin->format('Y-m-d'),
        'desde_mes' => $ini->format('Y-m'), 'hasta_mes' => $fin->format('Y-m'),
        'etiqueta' => estadisticas_fecha_corta($ini->format('Y-m-d')) . ' – ' . estadisticas_fecha_corta($fin->format('Y-m-d')),
    ];
}

/** Variacion porcentual (null si no hay base para comparar). */
function redes_variacion(float $actual, ?float $anterior): ?float
{
    if ($anterior === null || $anterior <= 0) {
        return null;
    }
    return ($actual - $anterior) / $anterior * 100;
}

/** Totales de publicaciones publicadas en un periodo, opcionalmente por plataformas. */
function redes_totales(PDO $pdo, array $periodo, array $plataformas): array
{
    [$where, $params] = redes_filtro_sql($periodo, $plataformas);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS publicaciones, COALESCE(SUM(alcance),0) AS alcance, COALESCE(SUM(impresiones),0) AS impresiones,
                COALESCE(SUM(interacciones),0) AS interacciones, COALESCE(SUM(clics),0) AS clics, COALESCE(SUM(compartidos),0) AS compartidos,
                COALESCE(SUM(comentarios),0) AS comentarios, COALESCE(SUM(reproducciones),0) AS reproducciones
         FROM publicaciones WHERE estado = 'Publicada'" . $where
    );
    $stmt->execute($params);
    $t = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    $t['tasa'] = $t['alcance'] ? $t['interacciones'] / $t['alcance'] * 100 : 0.0;
    return $t;
}

function redes_filtro_sql(array $periodo, array $plataformas): array
{
    $where = '';
    $params = [];
    if ($periodo['desde'] !== null) {
        $where .= ' AND fecha BETWEEN ? AND ?';
        array_push($params, $periodo['desde'], $periodo['hasta']);
    }
    if ($plataformas) {
        $where .= ' AND plataforma IN (' . implode(',', array_fill(0, count($plataformas), '?')) . ')';
        array_push($params, ...$plataformas);
    }
    return [$where, $params];
}

/** Todo lo que muestran la pestana Resumen y el reporte de redes. */
function redes_resumen(PDO $pdo, array $periodo, array $plataformas = []): array
{
    $anterior = redes_periodo_anterior($periodo);
    $actual = redes_totales($pdo, $periodo, $plataformas);
    $previo = $anterior ? redes_totales($pdo, $anterior, $plataformas) : null;

    [$where, $params] = redes_filtro_sql($periodo, $plataformas);
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(fecha,'%Y-%m') AS mes, plataforma, COUNT(*) AS publicaciones, COALESCE(SUM(alcance),0) AS alcance,
                COALESCE(SUM(interacciones),0) AS interacciones, COALESCE(SUM(clics),0) AS clics
         FROM publicaciones WHERE estado = 'Publicada' AND fecha IS NOT NULL" . $where . ' GROUP BY mes, plataforma'
    );
    $stmt->execute($params);
    $filasMes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Meses continuos del periodo para tendencias y minigraficos.
    if ($periodo['desde'] !== null) {
        $inicio = $periodo['desde_mes'];
        $fin = $periodo['hasta_mes'];
    } else {
        $claves = array_column($filasMes, 'mes');
        sort($claves);
        $inicio = $claves[0] ?? date('Y-m');
        $fin = max(end($claves) ?: date('Y-m'), date('Y-m'));
    }
    $vacio = ['publicaciones' => 0, 'alcance' => 0, 'interacciones' => 0, 'clics' => 0];
    $meses = [];
    for ($c = new DateTimeImmutable($inicio . '-01'); $c <= new DateTimeImmutable($fin . '-01'); $c = $c->modify('+1 month')) {
        $meses[$c->format('Y-m')] = $vacio;
    }
    $meses = array_slice($meses, -36, null, true);

    $porPlataforma = [];
    foreach ($filasMes as $f) {
        foreach ($vacio as $k => $_) {
            if (isset($meses[$f['mes']])) {
                $meses[$f['mes']][$k] += (int) $f[$k];
            }
            $porPlataforma[$f['plataforma']]['meses'][$f['mes']][$k] = ($porPlataforma[$f['plataforma']]['meses'][$f['mes']][$k] ?? 0) + (int) $f[$k];
        }
    }
    foreach ($meses as &$m) {
        $m['tasa'] = $m['alcance'] ? $m['interacciones'] / $m['alcance'] * 100 : 0.0;
    }
    unset($m);

    $plats = [];
    foreach (array_keys($porPlataforma) as $nombre) {
        $t = redes_totales($pdo, $periodo, [$nombre]);
        $tp = $anterior ? redes_totales($pdo, $anterior, [$nombre]) : null;
        $serie = [];
        foreach (array_keys($meses) as $mes) {
            $serie[$mes] = (int) ($porPlataforma[$nombre]['meses'][$mes]['interacciones'] ?? 0);
        }
        $plats[$nombre] = $t + [
            'var_interacciones' => redes_variacion($t['interacciones'], $tp['interacciones'] ?? null),
            'var_alcance' => redes_variacion($t['alcance'], $tp['alcance'] ?? null),
            'serie' => $serie,
        ];
    }
    uasort($plats, static fn($a, $b) => $b['interacciones'] <=> $a['interacciones'] ?: $b['publicaciones'] <=> $a['publicaciones']);

    $stmt = $pdo->prepare("SELECT * FROM publicaciones WHERE estado = 'Publicada'" . $where . ' ORDER BY interacciones DESC, alcance DESC LIMIT 6');
    $stmt->execute($params);
    $top = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sitio web: totales del periodo y del anterior.
    $web = static function (?array $p) use ($pdo): array {
        if ($p === null) {
            return [];
        }
        $sql = 'SELECT periodo, usuarios, sesiones, vistas, sesiones_redes, sesiones_whatsapp, rebote FROM sitio_web_metricas'
            . ($p['desde'] !== null ? ' WHERE periodo BETWEEN ? AND ?' : '') . ' ORDER BY periodo';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p['desde'] !== null ? [$p['desde_mes'], $p['hasta_mes']] : []);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $webActual = $web($periodo);
    $webPrevio = $web($anterior);
    $sumaWeb = static fn(array $filas, string $c): int => array_sum(array_map('intval', array_column($filas, $c)));

    $kpis = [
        'publicaciones' => ['Publicaciones', 'ti-news', $actual['publicaciones'], $previo['publicaciones'] ?? null, 'publicadas en el periodo'],
        'alcance' => ['Personas alcanzadas', 'ti-users-group', $actual['alcance'], $previo['alcance'] ?? null, 'suma del alcance de cada publicación'],
        'interacciones' => ['Interacciones', 'ti-heart-handshake', $actual['interacciones'], $previo['interacciones'] ?? null, 'reacciones, comentarios, compartidos y más'],
        'tasa' => ['Tasa de interacción', 'ti-percentage', $actual['tasa'], $previo['tasa'] ?? null, 'interacciones ÷ alcance'],
        'clics' => ['Clics en enlaces', 'ti-click', $actual['clics'], $previo['clics'] ?? null, 'visitas generadas desde las publicaciones'],
        'web' => ['Visitas al sitio web', 'ti-world', $sumaWeb($webActual, 'usuarios'), $webPrevio ? $sumaWeb($webPrevio, 'usuarios') : null, $sumaWeb($webActual, 'sesiones_redes') . ' sesiones llegaron desde redes'],
    ];
    $serieKpi = [
        'publicaciones' => array_column($meses, 'publicaciones'),
        'alcance' => array_column($meses, 'alcance'),
        'interacciones' => array_column($meses, 'interacciones'),
        'tasa' => array_column($meses, 'tasa'),
        'clics' => array_column($meses, 'clics'),
        'web' => array_values(array_map(static fn($m) => (int) (array_column($webActual, 'usuarios', 'periodo')[$m] ?? 0), array_keys($meses))),
    ];

    $filasAnalisis = redes_analisis_filas($pdo, $periodo);
    if ($plataformas) {
        $filasAnalisis = array_values(array_filter($filasAnalisis, static fn($f) => in_array($f['plataforma'], $plataformas, true)));
    }

    // Participacion de cada plataforma en el total de interacciones.
    foreach ($plats as &$p) {
        $p['participacion'] = $actual['interacciones'] ? $p['interacciones'] / $actual['interacciones'] * 100 : 0.0;
    }
    unset($p);

    // Calendario de actividad: 26 semanas que terminan al final del periodo (o hoy).
    $finCal = new DateTimeImmutable(min($periodo['hasta'] ?? date('Y-m-d'), date('Y-m-d')));
    $iniCal = $finCal->modify('monday this week')->modify('-25 weeks');
    $stmt = $pdo->prepare(
        "SELECT fecha, COUNT(*) AS n, GROUP_CONCAT(DISTINCT plataforma ORDER BY plataforma SEPARATOR ', ') AS redes
         FROM publicaciones WHERE estado = 'Publicada' AND fecha BETWEEN ? AND ?"
        . ($plataformas ? ' AND plataforma IN (' . implode(',', array_fill(0, count($plataformas), '?')) . ')' : '') . ' GROUP BY fecha'
    );
    $stmt->execute([$iniCal->format('Y-m-d'), $finCal->format('Y-m-d'), ...$plataformas]);
    $porDia = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $porDia[$f['fecha']] = ['n' => (int) $f['n'], 'redes' => (string) $f['redes']];
    }
    $semanasConPub = count(array_unique(array_map(static fn($d) => date('o-W', strtotime($d)), array_keys($porDia))));

    // Que tan completos estan los datos para analizar.
    $conexiones = redes_api_conexiones($pdo);
    $activas = array_filter($conexiones, static fn($c) => $c['activo']);
    $conTexto = count(array_filter($filasAnalisis, static fn($f) => redes_texto_publicacion($f) !== ''));
    $conAlcance = count(array_filter($filasAnalisis, static fn($f) => (int) $f['alcance'] > 0));
    $nAnalisis = count($filasAnalisis);
    $audiencia = redes_audiencia_datos($pdo, $periodo);
    $cuenta = redes_cuenta_datos($pdo, $periodo, $anterior);
    $conPilar = count(array_filter($filasAnalisis, static fn($f) => !empty($f['pilar'])));
    $calidad = [
        ['ok' => (bool) $activas, 'icono' => 'ti-plug-connected', 'titulo' => 'Redes conectadas por API', 'valor' => count($activas) . ' de ' . count($conexiones),
            'ayuda' => 'Las publicaciones, métricas y audiencia se actualizan solas.', 'tab' => 'conexiones'],
        ['ok' => $nAnalisis >= 10, 'icono' => 'ti-stack-2', 'titulo' => 'Publicaciones con métricas', 'valor' => $nAnalisis . ($nAnalisis < 10 ? ' (mín. 10)' : ''),
            'ayuda' => 'Con 10 o más, las comparaciones y recomendaciones son confiables.', 'tab' => 'publicaciones'],
        ['ok' => $nAnalisis > 0 && $conTexto / $nAnalisis >= 0.8, 'icono' => 'ti-align-left', 'titulo' => 'Publicaciones con su texto', 'valor' => $conTexto . ' de ' . $nAnalisis,
            'ayuda' => 'Sin el texto no se detectan los temas que más interesan.', 'tab' => 'publicaciones'],
        ['ok' => $nAnalisis > 0 && $conAlcance === $nAnalisis, 'icono' => 'ti-users-group', 'titulo' => 'Publicaciones con alcance', 'valor' => $conAlcance . ' de ' . $nAnalisis,
            'ayuda' => 'El alcance permite calcular la tasa de interacción.', 'tab' => 'publicaciones'],
        ['ok' => (bool) $audiencia, 'icono' => 'ti-world-pin', 'titulo' => 'Audiencia por país', 'valor' => $audiencia ? implode(', ', array_keys($audiencia)) : 'Sin datos',
            'ayuda' => 'Muestra de dónde son sus seguidores y visitantes.', 'tab' => 'audiencia'],
        ['ok' => (bool) $webActual, 'icono' => 'ti-world', 'titulo' => 'Sitio web (Google Analytics)', 'valor' => $webActual ? count($webActual) . ' mes(es)' : 'Sin datos',
            'ayuda' => 'Mide cuántas visitas llegan al sitio desde las redes.', 'tab' => 'web'],
        ['ok' => (bool) $cuenta['filas'], 'icono' => 'ti-chart-arrows-vertical', 'titulo' => 'Métricas mensuales de la cuenta', 'valor' => $cuenta['filas'] ? count($cuenta['filas']) . ' registro(s)' : 'Sin datos',
            'ayuda' => 'Alcance orgánico y pagado, seguidores nuevos e inversión, como en el informe mensual.', 'tab' => 'cuenta'],
        ['ok' => $nAnalisis > 0 && $conPilar / $nAnalisis >= 0.8, 'icono' => 'ti-category-2', 'titulo' => 'Publicaciones con pilar de contenido', 'valor' => $conPilar . ' de ' . $nAnalisis,
            'ayuda' => 'Asignar el tema a cada publicación muestra qué temas interesan más.', 'tab' => 'publicaciones'],
    ];

    // Publicacion destacada de cada red (mayor alcance y, si empata, mas interacciones), como en el informe mensual.
    $destacadasRed = [];
    foreach (array_keys($plats) as $nombre) {
        [$w, $pr] = redes_filtro_sql($periodo, [$nombre]);
        $stmt = $pdo->prepare("SELECT * FROM publicaciones WHERE estado = 'Publicada'" . $w . ' ORDER BY alcance DESC, interacciones DESC LIMIT 2');
        $stmt->execute($pr);
        $destacadasRed[$nombre] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'destacadas_red' => $destacadasRed,
        'cuenta' => $cuenta,
        'calendario' => ['inicio' => $iniCal, 'fin' => $finCal, 'dias' => $porDia, 'semanas_con_pub' => $semanasConPub],
        'calidad' => $calidad,
        'periodo' => $periodo,
        'anterior' => $anterior,
        'plataformas_filtro' => $plataformas,
        'generado' => date('d/m/Y H:i'),
        'actual' => $actual,
        'previo' => $previo,
        'kpis' => $kpis,
        'series' => $serieKpi,
        'meses' => $meses,
        'plataformas' => $plats,
        'top' => $top,
        'web' => $webActual,
        'web_totales' => [
            'usuarios' => $sumaWeb($webActual, 'usuarios'), 'sesiones' => $sumaWeb($webActual, 'sesiones'), 'vistas' => $sumaWeb($webActual, 'vistas'),
            'sesiones_redes' => $sumaWeb($webActual, 'sesiones_redes'), 'sesiones_whatsapp' => $sumaWeb($webActual, 'sesiones_whatsapp'),
        ],
        'analisis' => redes_analisis($filasAnalisis),
        'audiencia' => $audiencia,
    ];
}

/** Texto de variacion con flecha: "▲ 12%" / "▼ 8%" / "" sin base. */
function redes_variacion_txt(?float $v): string
{
    if ($v === null) {
        return '';
    }
    if (abs($v) < 0.5) {
        return '= 0%';
    }
    return ($v > 0 ? '▲ ' : '▼ ') . number_format(abs($v), abs($v) < 10 ? 1 : 0) . '%';
}

/** Una frase que resume el periodo en lenguaje claro (HTML con <b>). */
function redes_resumen_frase(array $rs): string
{
    $t = $rs['actual'];
    if (!$t['publicaciones']) {
        return 'No hay publicaciones publicadas en este periodo.';
    }
    $frase = 'Se publicaron <b>' . estadisticas_num($t['publicaciones']) . ' contenidos</b>';
    if ($t['alcance']) {
        $frase .= ' que llegaron a <b>' . estadisticas_num($t['alcance']) . ' personas</b>';
    }
    $frase .= ' y generaron <b>' . estadisticas_num($t['interacciones']) . ' interacciones</b>';
    if ($t['alcance']) {
        $frase .= ' (' . number_format($t['tasa'], 1) . '% de cada 100 personas interactuó)';
    }
    $frase .= '.';
    $var = redes_variacion((float) $t['interacciones'], $rs['previo'] ? (float) $rs['previo']['interacciones'] : null);
    if ($var !== null && abs($var) >= 1) {
        $frase .= ' Las interacciones <b>' . ($var > 0 ? 'subieron' : 'bajaron') . ' ' . number_format(abs($var), 0) . '%</b> frente al periodo anterior.';
    }
    if (count($rs['plataformas']) > 1) {
        $frase .= ' <b>' . e((string) array_key_first($rs['plataformas'])) . '</b> fue la red con más interacción.';
    }
    return $frase;
}

// ---------------------------------------------------------------- Graficos SVG

/** Minigrafico de linea (sin ejes) para tarjetas. */
function redes_svg_sparkline(array $valores, int $ancho = 120, int $alto = 34, string $color = '#73bc25'): string
{
    $valores = array_values($valores);
    $n = count($valores);
    if ($n < 2 || max($valores) <= 0) {
        return '<svg class="spark" width="' . $ancho . '" height="' . $alto . '" viewBox="0 0 ' . $ancho . ' ' . $alto . '" aria-hidden="true"><line x1="0" y1="' . ($alto - 2) . '" x2="' . $ancho . '" y2="' . ($alto - 2) . '" stroke="#d5d9dc" stroke-width="2" stroke-dasharray="3 3"/></svg>';
    }
    $max = max($valores);
    $pts = [];
    foreach ($valores as $i => $v) {
        $pts[] = round($i / ($n - 1) * ($ancho - 4) + 2, 1) . ',' . round($alto - 3 - $v / $max * ($alto - 8), 1);
    }
    $area = '2,' . ($alto - 1) . ' ' . implode(' ', $pts) . ' ' . ($ancho - 2) . ',' . ($alto - 1);
    [$ux, $uy] = explode(',', end($pts));
    return '<svg class="spark" width="' . $ancho . '" height="' . $alto . '" viewBox="0 0 ' . $ancho . ' ' . $alto . '" aria-hidden="true">'
        . '<polygon points="' . $area . '" fill="' . $color . '" fill-opacity=".12"/>'
        . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'
        . '<circle cx="' . $ux . '" cy="' . $uy . '" r="3" fill="' . $color . '" stroke="#fff" stroke-width="1.5"/></svg>';
}

/** Grafico de columnas con eje, etiquetas de mes y valor en la mas alta y la ultima (para el PDF). */
function redes_svg_columnas(array $serie, string $formato = 'num', int $ancho = 700, int $alto = 200, string $color = '#73bc25'): string
{
    $n = max(1, count($serie));
    $max = max(1, ...array_values($serie) ?: [1]);
    $izq = 8;
    $base = $alto - 22;
    $paso = ($ancho - $izq) / $n;
    $barra = min(34, $paso * 0.7);
    $fmt = static fn($v) => $formato === 'pct' ? number_format((float) $v, 1) . '%' : number_format((float) $v, 0, '.', ',');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $ancho . '" height="' . $alto . '" viewBox="0 0 ' . $ancho . ' ' . $alto . '" font-family="DejaVu Sans, Arial" font-size="10">';
    foreach ([0.5, 1] as $g) {
        $y = round($base - $g * ($base - 18), 1);
        $svg .= '<line x1="' . $izq . '" y1="' . $y . '" x2="' . $ancho . '" y2="' . $y . '" stroke="#eef0f2"/>';
    }
    $svg .= '<line x1="' . $izq . '" y1="' . $base . '" x2="' . $ancho . '" y2="' . $base . '" stroke="#ced2d5"/>';
    $maxClave = array_search(max($serie ?: [0]), $serie, true);
    $ultima = array_key_last($serie);
    $i = 0;
    foreach ($serie as $clave => $v) {
        $h = $v > 0 ? max(2, $v / $max * ($base - 18)) : 0;
        $x = $izq + $i * $paso + ($paso - $barra) / 2;
        $cx = round($x + $barra / 2, 1);
        if ($h > 0) {
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($base - $h, 1) . '" width="' . round($barra, 1) . '" height="' . round($h, 1) . '" rx="3" fill="' . $color . '"/>';
        }
        if ($v > 0 && ($clave === $maxClave || $clave === $ultima)) {
            $svg .= '<text x="' . $cx . '" y="' . round($base - $h - 5, 1) . '" text-anchor="middle" font-weight="bold" fill="#3d4247">' . $fmt($v) . '</text>';
        }
        if ($n <= 18 || $i % 2 === 0) {
            $svg .= '<text x="' . $cx . '" y="' . ($alto - 6) . '" text-anchor="middle" fill="#5f666c">' . htmlspecialchars(estadisticas_mes_corto((string) $clave)) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

/**
 * Imagen de una publicacion (material subido) como data URI para el PDF, que no
 * carga archivos remotos. Solo JPG/PNG dentro de uploads/ y de hasta 2 MB.
 */
function redes_imagen_data_uri(?string $material): string
{
    if (!$material || !preg_match('/\.(jpe?g|png)$/i', $material, $m)) {
        return '';
    }
    $base = realpath(dirname(__DIR__, 2) . '/uploads');
    $ruta = realpath(dirname(__DIR__) . '/' . $material);
    if (!$base || !$ruta || !str_starts_with($ruta, $base . DIRECTORY_SEPARATOR) || filesize($ruta) > 2 * 1024 * 1024) {
        return '';
    }
    return 'data:image/' . (strtolower($m[1]) === 'png' ? 'png' : 'jpeg') . ';base64,' . base64_encode((string) file_get_contents($ruta));
}

/** SVG como imagen embebida (Dompdf no interpreta SVG en linea). */
function redes_svg_img(string $svg, int $ancho): string
{
    return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="width:' . $ancho . 'px;max-width:100%" alt="">';
}

// ---------------------------------------------------------------- Reporte

/** Reporte de redes en HTML (PDF con Dompdf o pagina para imprimir). */
function redes_reporte_html(array $r, array $secciones, bool $imprimir = false, string $conclusiones = ''): string
{
    $verde = MARCA_OSCURO;
    $a = $r['analisis'];
    $t = $r['actual'];
    $titulo = 'Informe de redes sociales';
    $filtro = $r['plataformas_filtro'] ? implode(', ', $r['plataformas_filtro']) : 'Todas las plataformas';
    $tabla = static function (array $encabezados, array $filas): string {
        if (!$filas) {
            return '<p class="vacio">Sin datos en el periodo.</p>';
        }
        $h = '<table class="datos"><thead><tr>';
        foreach ($encabezados as $i => $e) {
            $h .= '<th' . ($i ? ' class="n"' : '') . '>' . e($e) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($filas as $fila) {
            $h .= '<tr>';
            foreach (array_values($fila) as $i => $v) {
                $h .= '<td' . ($i ? ' class="n"' : '') . '>' . (is_int($v) ? estadisticas_num($v) : (is_float($v) ? number_format($v, 1) : (string) $v)) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    };
    $barras = static function (array $items, int $ancho = 300): string {
        $max = max(1, ...array_column($items, 'valor'));
        $h = '<table class="barras">';
        foreach ($items as $i) {
            $px = (int) round($i['valor'] / $max * $ancho);
            $h .= '<tr><td class="et">' . e($i['etiqueta']) . '</td><td style="width:' . $ancho . 'px">' . ($px ? '<div style="width:' . $px . 'px"></div>' : '') . '</td><td class="n">' . e($i['texto']) . '</td></tr>';
        }
        return $h . '</table>';
    };

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>' . $titulo . ' · ' . e($r['periodo']['etiqueta']) . '</title><style>'
        . '@page{margin:18mm 14mm}body{font:11px "DejaVu Sans",Arial,sans-serif;color:#334155;margin:0}'
        . '.portada{background:' . $verde . ';color:#fff;padding:22px 24px;border-radius:8px;margin-bottom:16px}.portada h1{margin:0 0 4px;font-size:22px}.portada p{margin:0;color:#e3f2d3}'
        . '.franja{height:5px;margin:-16px 0 16px}.franja span{display:inline-block;height:5px;width:24.6%}'
        . 'h2{font-size:14px;color:' . $verde . ';margin:22px 0 8px;border-bottom:2px solid #e3f2d3;padding-bottom:4px;page-break-after:avoid}h3{font-size:12px;margin:12px 0 6px;color:#1e293b}'
        . '.kpis{width:100%;border-collapse:separate;border-spacing:6px}.kpis td{width:33%;border:1px solid #e2e8f0;border-top:3px solid #73bc25;border-radius:6px;padding:8px 10px;vertical-align:top}'
        . '.kpis small{display:block;color:#64748b;font-size:9.5px}.kpis strong{display:block;font-size:18px;color:#1e293b;margin:3px 0}.up{color:#3d6614;font-weight:bold}.down{color:#b34b00;font-weight:bold}'
        . 'table.datos{width:100%;border-collapse:collapse;margin:4px 0 10px}table.datos th{background:#EEF7E4;color:#2F5A0B;border-bottom:2px solid #73BC25;text-align:left;padding:5px 6px;font-size:9.5px}table.datos .n{text-align:right}'
        . 'table.datos td{padding:4px 6px;border-bottom:1px solid #e2e8f0}table.datos tr:nth-child(even) td{background:#f8fafc}'
        . 'table.barras{border-collapse:collapse;margin:4px 0 10px}table.barras td{padding:3px 4px}table.barras .et{width:150px}table.barras .n{width:70px;text-align:right;font-weight:bold}table.barras div{height:10px;background:#73bc25}'
        . 'ul.recos{list-style:none;margin:4px 0 10px;padding:0}ul.recos li{margin:0 0 5px;padding:6px 9px;border-left:4px solid #ced2d5;background:#f8fafc;line-height:1.45}'
        . 'ul.recos li.bien{border-left-color:#73bc25;background:#f6fbf0}ul.recos li.mejorar{border-left-color:#ff6b00;background:#fff7f0}'
        . '.cols{width:100%;border-collapse:collapse}.cols>tbody>tr>td{width:50%;vertical-align:top;padding-right:10px}.sub{color:#64748b}.vacio{color:#94a3b8;font-style:italic}'
        . 'footer{margin-top:18px;font-size:9px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:6px}'
        . '.frase{font-size:12px;line-height:1.55;margin:4px 0 8px}'
        . 'table.post{width:100%;border-collapse:collapse;margin:0 0 10px;border:1px solid #e2e8f0;page-break-inside:avoid}table.post td{vertical-align:top;padding:8px 10px}'
        . 'table.post .post-img{width:150px;padding:6px}table.post .post-img img{width:150px;border-radius:4px}table.post p{margin:4px 0;line-height:1.45}.post-metricas{color:#1e293b}'
        . '.conclusiones{padding:10px 12px;border-left:4px solid #3d6614;background:#f6fbf0;line-height:1.55;margin:4px 0 10px}'
        . '@media screen{body{max-width:900px;margin:24px auto;padding:0 16px}}@media print{.no-print{display:none}thead{display:table-header-group}tr{page-break-inside:avoid}}'
        . '.no-print{position:fixed;top:12px;right:12px}.no-print button{background:' . $verde . ';color:#fff;border:0;border-radius:6px;padding:9px 14px;font-weight:bold;cursor:pointer}'
        . '</style></head><body>';
    if ($imprimir) {
        $html .= '<div class="no-print"><button onclick="window.print()">Imprimir / guardar PDF</button></div>';
    }
    $html .= '<div class="portada"><h1>' . $titulo . '</h1><p>CENGICAÑA · Comités, Transferencia y Comunicación</p><p>' . e($r['periodo']['etiqueta']) . ' · ' . e($filtro) . ' · generado el ' . e($r['generado']) . '</p></div>'
        . '<div class="franja"><span style="background:#73bc25"></span><span style="background:#a3d300"></span><span style="background:#ffcc00"></span><span style="background:#ff6b00"></span></div>';

    $var = static fn(?float $v): string => $v === null ? '' : ' <span class="' . ($v >= 0 ? 'up' : 'down') . '">' . redes_variacion_txt($v) . '</span>';
    $titulos = REDES_REPORTE_SECCIONES;
    $cu = $r['cuenta'];

    if (in_array('resumen', $secciones, true)) {
        $html .= '<h2>Resumen ejecutivo</h2><p class="frase">' . redes_resumen_frase($r) . '</p>';
        if ($cu['total']) {
            $html .= '<p class="frase">En la cuenta, el alcance total fue de <b>' . estadisticas_num($cu['total']['alcance_total']) . ' personas</b>'
                . ($cu['total']['pct_organico'] !== null ? ' (' . number_format($cu['total']['pct_organico'], 0) . '% orgánico)' : '')
                . ' y se sumaron <b>' . estadisticas_num($cu['total']['seguidores_nuevos']) . ' seguidores nuevos</b>'
                . ($cu['total']['inversion_q'] ? ' con una inversión en pauta de <b>' . redes_quetzales($cu['total']['inversion_q']) . '</b>' : '') . '.</p>';
        }
        if ($r['anterior']) {
            $html .= '<p class="sub">Las variaciones comparan con el periodo anterior: ' . e($r['anterior']['etiqueta']) . '.</p>';
        }
        $html .= '<table class="kpis"><tr>';
        $i = 0;
        foreach ($r['kpis'] as $clave => [$nombre, , $valor, $previo, $nota]) {
            if ($i++ === 3) {
                $html .= '</tr><tr>';
            }
            $v = redes_variacion((float) $valor, $previo === null ? null : (float) $previo);
            $sinDatos = $clave === 'web' && !$r['web'];
            $html .= '<td><small>' . e($nombre) . '</small><strong>' . ($sinDatos ? 'Sin datos' : ($clave === 'tasa' ? number_format((float) $valor, 1) . '%' : estadisticas_num($valor))) . '</strong>'
                . ($v !== null ? $var($v) . ' <small style="display:inline">vs. periodo anterior</small>' : '<small>' . e($sinDatos ? 'Conecte Google Analytics' : $nota) . '</small>') . '</td>';
        }
        $html .= '</tr></table>';
    }

    if (in_array('cuenta', $secciones, true)) {
        $html .= '<h2>' . e($titulos['cuenta']) . '</h2>';
        if (!$cu['plataformas']) {
            $html .= '<p class="vacio">No hay métricas mensuales de la cuenta registradas en este periodo (Redes sociales › Resumen › Registrar mes).</p>';
        } else {
            $filas = [];
            foreach ($cu['plataformas'] as $nombre => $pc) {
                $tt = $pc['total'];
                $pv = $pc['previo'];
                $filas[] = [
                    '<b>' . e($nombre) . '</b>',
                    estadisticas_num($tt['alcance_total']) . $var(redes_variacion((float) $tt['alcance_total'], $pv ? (float) $pv['alcance_total'] : null)),
                    $tt['pct_organico'] !== null ? number_format($tt['pct_organico'], 0) . '%' : '—',
                    '+' . estadisticas_num($tt['seguidores_nuevos']) . $var(redes_variacion((float) $tt['seguidores_nuevos'], $pv ? (float) $pv['seguidores_nuevos'] : null)),
                    $tt['seguidores_total'] ? estadisticas_num($tt['seguidores_total']) : '—',
                    $tt['inversion_q'] ? redes_quetzales($tt['inversion_q']) . ($tt['inversion_usd'] ? '<br><span class="sub">US$' . number_format($tt['inversion_usd'], 2) . '</span>' : '') : 'Sin pauta',
                    redes_quetzales($tt['cpm_q']),
                    redes_quetzales($tt['costo_seguidor_q']),
                ];
            }
            $html .= $tabla(['Cuenta', 'Alcance total', '% orgánico', 'Seguidores nuevos', 'Seguidores totales', 'Inversión', 'Costo por 1,000 alcanzados', 'Costo por seguidor'], $filas);
            $html .= '<h3>Mes a mes</h3>' . $tabla(
                ['Mes', 'Cuenta', 'Alcance total', 'Orgánico', 'Pagado', 'Seguidores nuevos', 'Inversión'],
                array_map(static fn($f) => [estadisticas_fecha_corta($f['periodo'] . '-01'), e($f['plataforma']), (int) $f['alcance_total'], (int) $f['alcance_organico'], (int) $f['alcance_pagado'], (int) $f['seguidores_nuevos'], (float) $f['inversion_q'] ? redes_quetzales((float) $f['inversion_q']) : '—'], $cu['filas'])
            );
            $notas = array_filter($cu['filas'], static fn($f) => trim((string) $f['notas']) !== '');
            foreach ($notas as $f) {
                $html .= '<p class="sub"><b>' . e($f['plataforma'] . ' · ' . estadisticas_fecha_corta($f['periodo'] . '-01')) . ':</b> ' . e((string) $f['notas']) . '</p>';
            }
            $html .= '<p class="sub">El alcance orgánico mide lo que logra el contenido por sí solo; el pagado, lo que añade la pauta. Un costo por seguidor que baja indica que la inversión se está aprovechando mejor.</p>';
        }
    }

    if (in_array('tendencia', $secciones, true)) {
        $html .= '<h2>' . e($titulos['tendencia']) . '</h2><h3>Alcance por mes (suma de publicaciones)</h3>' . redes_svg_img(redes_svg_columnas(array_combine(array_keys($r['meses']), array_column($r['meses'], 'alcance'))), 640)
            . '<h3>Interacciones por mes</h3>' . redes_svg_img(redes_svg_columnas(array_combine(array_keys($r['meses']), array_column($r['meses'], 'interacciones')), 'num', 700, 200, '#3d6614'), 640);
    }

    if (in_array('plataformas', $secciones, true)) {
        $html .= '<h2>' . e($titulos['plataformas']) . '</h2>' . $tabla(
            ['Plataforma', 'Publicaciones', 'Alcance', 'Interacciones', 'Tasa %', 'Clics', 'Participación', 'Var. interacciones'],
            array_map(static fn($n, $p) => [e($n), $p['publicaciones'], $p['alcance'], $p['interacciones'], (float) $p['tasa'], $p['clics'], number_format($p['participacion'], 0) . '%', redes_variacion_txt($p['var_interacciones']) ?: '—'], array_keys($r['plataformas']), $r['plataformas'])
        );
    }

    if (in_array('audiencia', $secciones, true)) {
        $html .= '<h2>' . e($titulos['audiencia']) . '</h2>';
        if (!$r['audiencia']) {
            $html .= '<p class="vacio">Aún no hay datos de audiencia. Se obtienen al sincronizar Google Analytics, Instagram, Facebook o LinkedIn, o importando el archivo de audiencia de cada red.</p>';
        }
        $paises = array_slice(redes_audiencia_paises_combinados($r['audiencia']), 0, 12, true);
        if (count($r['audiencia']) > 1 && $paises) {
            $html .= '<h3>Países principales (todas las fuentes)</h3>' . $barras(array_map(static fn($p) => ['etiqueta' => $p['etiqueta'], 'valor' => $p['valor'], 'texto' => number_format($p['pct'], 1) . '%'], $paises), 320);
        }
        foreach ($r['audiencia'] as $plataforma => $au) {
            $html .= '<h3>' . e($plataforma) . ' · ' . estadisticas_num($au['total']) . ' ' . ($au['metrica'] === 'seguidores' ? 'seguidores' : 'visitantes') . ' (' . e($au['cuando']) . ')</h3><table class="cols"><tr>';
            $col = 0;
            foreach ($au['dimensiones'] as $dim => $items) {
                if ($col++ % 2 === 0 && $col > 1) {
                    $html .= '</tr><tr>';
                }
                $html .= '<td><b>' . e(REDES_AUDIENCIA_DIMENSIONES[$dim]) . '</b>' . $barras(array_map(static fn($i) => ['etiqueta' => $i['etiqueta'], 'valor' => $i['valor'], 'texto' => number_format($i['pct'], 1) . '%'], array_slice($items, 0, 8)), 80) . '</td>';
            }
            $html .= '</tr></table>';
        }
    }

    if (in_array('destacadas', $secciones, true)) {
        $html .= '<h2>' . e($titulos['destacadas']) . '</h2>';
        if (!array_filter($r['destacadas_red'])) {
            $html .= '<p class="vacio">Sin publicaciones en el periodo.</p>';
        }
        foreach ($r['destacadas_red'] as $red => $posts) {
            foreach ($posts as $p) {
                $img = redes_imagen_data_uri($p['material'] ?? null);
                $alc = (int) $p['alcance'];
                $texto = redes_texto_publicacion($p) ?: (string) $p['titulo'];
                $pilar = ($p['pilar'] ?? '') ?: redes_pilar_sugerido(redes_texto_completo($p));
                $html .= '<table class="post"><tr>' . ($img ? '<td class="post-img"><img src="' . $img . '" alt=""></td>' : '')
                    . '<td><small class="sub">' . e($red) . ' · ' . ($p['fecha'] ? e(REDES_DIAS[(int) date('N', strtotime((string) $p['fecha']))] . ' ' . date('d/m/Y', strtotime((string) $p['fecha']))) : '') . ($pilar ? ' · ' . e($pilar) : '') . (!empty($p['pautada']) ? ' · <b>Pautada</b>' : '') . '</small>'
                    . '<p>' . nl2br(e(mb_strimwidth($texto, 0, 420, '…'))) . '</p>'
                    . '<p class="post-metricas"><b>' . estadisticas_num($alc) . '</b> alcance · <b>' . estadisticas_num((int) $p['interacciones']) . '</b> interacciones · <b>' . ($alc ? number_format((int) $p['interacciones'] / $alc * 100, 1) . '%' : '—') . '</b> tasa · <b>' . estadisticas_num((int) $p['clics']) . '</b> clics'
                    . (!empty($p['pautada']) && (float) $p['inversion'] ? ' · ' . redes_quetzales((float) $p['inversion']) . ' invertidos' : '') . '</p>'
                    . (!empty($p['url']) ? '<small class="sub">' . e((string) $p['url']) . '</small>' : '') . '</td></tr></table>';
            }
        }
    }

    if (in_array('recomendaciones', $secciones, true)) {
        $html .= '<h2>' . e($titulos['recomendaciones']) . '</h2>';
        if ($a['pauta']['solo_organico']) {
            $html .= '<p class="sub">Para juzgar el contenido se comparan solo las ' . $a['n'] . ' publicaciones orgánicas (de ' . $a['pauta']['n_total'] . '); la pauta se resume aparte.</p>';
        }
        $html .= '<ul class="recos">';
        foreach ($a['recomendaciones'] as $rec) {
            $html .= '<li class="' . e($rec['tono']) . '">' . $rec['html'] . '</li>';
        }
        $html .= '</ul>';
        if ($a['n'] >= 5) {
            $grupo = static fn(array $g, callable $nombre): array => array_map(static fn($k, $x) => ['etiqueta' => $nombre($k), 'valor' => $x['valor'], 'texto' => ($a['usa_tasa'] ? number_format($x['valor'], 1) . '%' : number_format($x['valor'], 1)) . ' · ' . $x['n']], array_keys($g), $g);
            $html .= '<h3>Por tema (pilar de contenido)</h3>' . $barras($grupo($a['pilar'], static fn($k) => (string) $k), 300)
                . '<table class="cols"><tr><td><h3>Por formato</h3>' . $barras($grupo($a['tipo'], static fn($k) => (string) $k), 100)
                . '</td><td><h3>Por día de la semana</h3>' . $barras($grupo($a['dia'], static fn($k) => REDES_DIAS[$k]), 100) . '</td></tr></table>';
            if ($a['temas']) {
                $html .= '<h3>Palabras y hashtags que más conectan</h3>' . $barras($grupo(array_slice($a['temas'], 0, 8, true), static fn($k) => (string) $k), 300);
            }
            $html .= '<p class="sub">' . ($a['usa_tasa'] ? 'Tasa de interacción' : 'Interacciones por publicación') . ' · número de publicaciones.</p>';
        }
        $pa = $a['pauta'];
        if ($pa['pagado']['n']) {
            $html .= '<h3>Orgánico frente a pautado</h3>' . $tabla(
                ['', 'Publicaciones', 'Alcance promedio', 'Tasa %', 'Inversión', 'Costo por 1,000', 'Costo por interacción'],
                [
                    ['Orgánico', $pa['organico']['n'], (int) round($pa['organico']['alcance_promedio']), (float) $pa['organico']['tasa'], '—', '—', '—'],
                    ['Pautado', $pa['pagado']['n'], (int) round($pa['pagado']['alcance_promedio']), (float) $pa['pagado']['tasa'], redes_quetzales($pa['pagado']['inversion'] ?: null), redes_quetzales($pa['pagado']['cpm']), redes_quetzales($pa['pagado']['costo_interaccion'])],
                ]
            );
        }
    }

    if (in_array('top', $secciones, true)) {
        $html .= '<h2>' . e($titulos['top']) . '</h2>' . $tabla(
            ['Publicación', 'Alcance', 'Interacciones', 'Tasa %', 'Clics'],
            array_map(static fn($p) => [
                '<b>' . e(mb_strimwidth(redes_texto_publicacion($p) ?: (string) $p['titulo'], 0, 90, '…')) . '</b><br><span class="sub">' . e((string) $p['plataforma']) . ' · ' . ($p['fecha'] ? date('d/m/Y', strtotime((string) $p['fecha'])) : '') . (!empty($p['pautada']) ? ' · pautada' : '') . '</span>',
                (int) $p['alcance'], (int) $p['interacciones'], (int) $p['alcance'] ? (int) $p['interacciones'] / (int) $p['alcance'] * 100 : 0.0, (int) $p['clics'],
            ], $r['top'])
        );
    }

    if (in_array('web', $secciones, true) && $r['web']) {
        $w = $r['web_totales'];
        $html .= '<h2>' . e($titulos['web']) . '</h2>' . $tabla(['Indicador', 'Valor'], [['Usuarios', $w['usuarios']], ['Sesiones', $w['sesiones']], ['Vistas', $w['vistas']], ['Sesiones desde redes sociales', $w['sesiones_redes']], ['Sesiones desde WhatsApp', $w['sesiones_whatsapp']]])
            . '<h3>Usuarios por mes</h3>' . redes_svg_img(redes_svg_columnas(array_column($r['web'], 'usuarios', 'periodo')), 640);
    }

    if (in_array('conclusiones', $secciones, true)) {
        $html .= '<h2>' . e($titulos['conclusiones']) . '</h2>';
        if (trim($conclusiones) !== '') {
            $html .= '<div class="conclusiones">' . nl2br(e($conclusiones)) . '</div>';
        }
        $html .= '<h3>Acciones sugeridas para el próximo periodo</h3><ul class="recos">';
        foreach (array_slice(array_filter($a['recomendaciones'], static fn($x) => $x['tono'] !== 'info') ?: $a['recomendaciones'], 0, 4) as $rec) {
            $html .= '<li class="' . e($rec['tono']) . '">' . $rec['html'] . '</li>';
        }
        $html .= '</ul>';
    }
    return $html . '<footer>Informe generado desde el módulo Comités, Transferencia y Comunicación · CENGICAÑA · ' . e($r['generado']) . '</footer></body></html>';
}

/** Hojas de Excel del reporte de redes. */
function redes_reporte_hojas(array $r): array
{
    $resumen = [['Indicador', 'Periodo actual', 'Periodo anterior', 'Variación (%)']];
    foreach ($r['kpis'] as $clave => [$nombre, , $valor, $previo]) {
        $var = redes_variacion((float) $valor, $previo === null ? null : (float) $previo);
        $resumen[] = [$nombre, $clave === 'tasa' ? round((float) $valor, 1) : (int) $valor, $previo === null ? '' : ($clave === 'tasa' ? round((float) $previo, 1) : (int) $previo), $var === null ? '' : round($var, 1)];
    }
    $mensual = [['Mes', 'Publicaciones', 'Alcance', 'Interacciones', 'Tasa de interacción (%)', 'Clics']];
    foreach ($r['meses'] as $mes => $m) {
        $mensual[] = [$mes, $m['publicaciones'], $m['alcance'], $m['interacciones'], round($m['tasa'], 1), $m['clics']];
    }
    $plats = [['Plataforma', 'Publicaciones', 'Alcance', 'Interacciones', 'Tasa (%)', 'Clics', 'Variación interacciones (%)']];
    foreach ($r['plataformas'] as $n => $p) {
        $plats[] = [$n, $p['publicaciones'], $p['alcance'], $p['interacciones'], round($p['tasa'], 1), $p['clics'], $p['var_interacciones'] === null ? '' : round($p['var_interacciones'], 1)];
    }
    $recos = [['Recomendación'], ...array_map(static fn($x) => [html_entity_decode(strip_tags($x['html']), ENT_QUOTES, 'UTF-8')], $r['analisis']['recomendaciones'])];
    $aud = [['Plataforma', 'Dimensión', 'Valor', 'Cantidad', '% del total', 'Métrica', 'Mes']];
    foreach ($r['audiencia'] as $plataforma => $au) {
        foreach ($au['dimensiones'] as $dim => $items) {
            foreach ($items as $i) {
                $aud[] = [$plataforma, REDES_AUDIENCIA_DIMENSIONES[$dim], $i['etiqueta'], $i['valor'], round($i['pct'], 1), $au['metrica'], $au['periodo']];
            }
        }
    }
    $top = [['Fecha', 'Plataforma', 'Publicación', 'Alcance', 'Interacciones', 'Clics', 'Enlace']];
    foreach ($r['top'] as $p) {
        $top[] = [(string) $p['fecha'], (string) $p['plataforma'], redes_texto_publicacion($p) ?: (string) $p['titulo'], (int) $p['alcance'], (int) $p['interacciones'], (int) $p['clics'], (string) $p['url']];
    }
    $cuenta = [['Mes', 'Cuenta', 'Alcance total', 'Alcance orgánico', 'Alcance pagado', '% orgánico', 'Seguidores nuevos', 'Seguidores totales', 'Inversión (Q)', 'Inversión (US$)', 'Costo por 1,000 alcanzados (Q)', 'Costo por seguidor (Q)', 'Notas']];
    foreach ($r['cuenta']['filas'] as $f) {
        $cuenta[] = [$f['periodo'], $f['plataforma'], (int) $f['alcance_total'], (int) $f['alcance_organico'], (int) $f['alcance_pagado'],
            $f['pct_organico'] === null ? '' : round($f['pct_organico'], 1), (int) $f['seguidores_nuevos'], (int) $f['seguidores_total'],
            (float) $f['inversion_q'], (float) $f['inversion_usd'], $f['cpm_q'] === null ? '' : round($f['cpm_q'], 2), $f['costo_seguidor_q'] === null ? '' : round($f['costo_seguidor_q'], 2), (string) $f['notas']];
    }
    $a = $r['analisis'];
    $pilares = [['Pilar de contenido', 'Publicaciones', 'Interacciones', $a['usa_tasa'] ? 'Tasa de interacción (%)' : 'Interacciones por publicación', 'Índice vs promedio']];
    foreach ($a['pilar'] as $k => $g) {
        $pilares[] = [(string) $k, $g['n'], $g['interacciones'], round($g['valor'], 1), round($g['indice'], 2)];
    }
    $pa = $a['pauta'];
    $pilares[] = [''];
    $pilares[] = ['Orgánico frente a pautado', 'Publicaciones', 'Alcance promedio', 'Tasa de interacción (%)', 'Inversión (Q)'];
    $pilares[] = ['Orgánico', $pa['organico']['n'], (int) round($pa['organico']['alcance_promedio']), round($pa['organico']['tasa'], 1), 0.0];
    $pilares[] = ['Pautado', $pa['pagado']['n'], (int) round($pa['pagado']['alcance_promedio']), round($pa['pagado']['tasa'], 1), (float) $pa['pagado']['inversion']];

    return [
        ['nombre' => 'Resumen', 'filas' => $resumen],
        ['nombre' => 'Recomendaciones', 'filas' => $recos],
        ['nombre' => 'Cuenta mensual y pauta', 'filas' => $cuenta],
        ['nombre' => 'Temas y pauta', 'filas' => $pilares],
        ['nombre' => 'Tendencia mensual', 'filas' => $mensual],
        ['nombre' => 'Por plataforma', 'filas' => $plats],
        ['nombre' => 'Audiencia', 'filas' => $aud],
        ['nombre' => 'Mejores publicaciones', 'filas' => $top],
    ];
}

/** Responde la descarga del reporte de redes y termina la peticion. */
function redes_reporte_exportar(PDO $pdo, array $get): void
{
    $periodo = estadisticas_periodo($get);
    $validas = array_keys(redes_plataformas());
    $plataformas = array_values(array_intersect((array) ($get['plataformas'] ?? []), $validas));
    $secciones = array_values(array_intersect((array) ($get['secciones'] ?? array_keys(REDES_REPORTE_SECCIONES)), array_keys(REDES_REPORTE_SECCIONES)));
    $secciones = $secciones ?: array_keys(REDES_REPORTE_SECCIONES);
    $r = redes_resumen($pdo, $periodo, $plataformas);
    $conclusiones = mb_substr(trim((string) ($get['conclusiones'] ?? '')), 0, 3000);
    $nombre = 'informe_redes_' . ($periodo['desde'] === null ? 'historial' : $periodo['desde_mes'] . '_' . $periodo['hasta_mes']);
    $formato = (string) ($get['generar'] ?? 'pdf');
    if ($formato === 'xlsx') {
        $contenido = estadisticas_excel(redes_reporte_hojas($r));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
    } elseif ($formato === 'pdf') {
        require_once dirname(__DIR__, 2) . '/cengicursos/vendor/autoload.php';
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml(redes_reporte_html($r, $secciones, false, $conclusiones), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $contenido = (string) $pdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombre . '.pdf"');
    } else {
        $contenido = redes_reporte_html($r, $secciones, true, $conclusiones);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $contenido;
    exit;
}
