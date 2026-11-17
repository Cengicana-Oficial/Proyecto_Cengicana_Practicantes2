<?php
declare(strict_types=1);
require_once __DIR__ . '/marca.php';

// Estadisticas consolidadas del modulo: KPIs por periodo (comites, seguimiento,
// documentacion, redes sociales y sitio web) y su reporte descargable en Excel,
// PDF o HTML para imprimir. Solo lectura.

const ESTADISTICAS_PERIODOS = [
    '12m' => 'Últimos 12 meses',
    'anio' => 'Este año',
    'anterior' => 'Año anterior',
    'todo' => 'Todo el historial',
    'rango' => 'Rango personalizado',
];

const ESTADISTICAS_MESES = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** Periodo pedido por GET: clave, limites (Y-m-d, null = sin limite) y etiqueta legible. */
function estadisticas_periodo(array $q): array
{
    $clave = array_key_exists($q['periodo'] ?? '', ESTADISTICAS_PERIODOS) ? (string) $q['periodo'] : '12m';
    $hoy = new DateTimeImmutable('today');
    $mesValido = static fn($v) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $v) ? (string) $v : '';
    $desdeMes = $mesValido($q['desde'] ?? '');
    $hastaMes = $mesValido($q['hasta'] ?? '');
    if ($clave === 'rango' && ($desdeMes === '' || $hastaMes === '')) {
        $clave = '12m';
    }
    switch ($clave) {
        case 'anio':
            $desde = $hoy->format('Y-01-01');
            $hasta = $hoy->format('Y-12-31');
            break;
        case 'anterior':
            $anio = (int) $hoy->format('Y') - 1;
            $desde = $anio . '-01-01';
            $hasta = $anio . '-12-31';
            break;
        case 'todo':
            $desde = $hasta = null;
            break;
        case 'rango':
            if ($desdeMes > $hastaMes) {
                [$desdeMes, $hastaMes] = [$hastaMes, $desdeMes];
            }
            $desde = $desdeMes . '-01';
            $hasta = (new DateTimeImmutable($hastaMes . '-01'))->format('Y-m-t');
            break;
        default:
            $desde = $hoy->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
            $hasta = $hoy->format('Y-m-t');
    }
    $etiqueta = $desde === null
        ? 'Todo el historial'
        : estadisticas_fecha_corta($desde) . ' – ' . estadisticas_fecha_corta($hasta);

    return [
        'clave' => $clave,
        'desde' => $desde,
        'hasta' => $hasta,
        'desde_mes' => $desde ? substr($desde, 0, 7) : '',
        'hasta_mes' => $hasta ? substr($hasta, 0, 7) : '',
        'etiqueta' => $etiqueta,
    ];
}

function estadisticas_fecha_corta(string $fecha): string
{
    $t = strtotime($fecha);
    return ESTADISTICAS_MESES[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/** Parametros de URL que reproducen el periodo (para enlaces de descarga). */
function estadisticas_query(array $periodo, array $extra = []): string
{
    $q = ['view' => 'estadisticas', 'periodo' => $periodo['clave']];
    if ($periodo['clave'] === 'rango') {
        $q['desde'] = $periodo['desde_mes'];
        $q['hasta'] = $periodo['hasta_mes'];
    }
    return 'index.php?' . http_build_query($q + $extra);
}

/** Todos los indicadores del periodo en un solo arreglo (vista y exportaciones). */
function estadisticas_datos(PDO $pdo, array $periodo): array
{
    // Filtro de fecha reutilizable: devuelve [sql, params] para la columna indicada.
    $rango = static function (string $columna) use ($periodo): array {
        if ($periodo['desde'] === null) {
            return ['', []];
        }
        return [' AND DATE(' . $columna . ') BETWEEN ? AND ?', [$periodo['desde'], $periodo['hasta']]];
    };
    $filas = static function (string $sql, array $params = []) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $fila = static fn(string $sql, array $params = []): array => $filas($sql, $params)[0] ?? [];

    [$fReu, $pReu] = $rango('COALESCE(r.fecha, r.plan_fecha)');
    [$fAcu, $pAcu] = $rango('fecha');
    [$fEve, $pEve] = $rango('COALESCE(fecha, plan_fecha)');
    [$fDoc, $pDoc] = $rango('fecha');
    [$fMem, $pMem] = $rango('fecha');
    [$fCor, $pCor] = $rango('enviado_en');
    [$fPub, $pPub] = $rango('fecha');

    $reuniones = $fila(
        "SELECT COUNT(*) AS total, COALESCE(SUM(r.realizada = 1),0) AS realizadas, COALESCE(SUM(r.cancelada = 1),0) AS canceladas
         FROM reuniones r WHERE 1=1" . $fReu,
        $pReu
    );
    $acuerdos = $fila(
        "SELECT COUNT(*) AS total, COALESCE(SUM(estado = 'Completado'),0) AS completados, COALESCE(AVG(avance),0) AS avance,
                COALESCE(SUM(plazo < CURDATE() AND estado NOT IN ('Completado','Cancelado')),0) AS vencidos
         FROM acuerdos WHERE 1=1" . $fAcu,
        $pAcu
    );
    $eventos = $fila(
        "SELECT COUNT(*) AS total, COALESCE(SUM(realizada = 1),0) AS realizados, COALESCE(SUM(participantes),0) AS participantes
         FROM eventos_transferencia WHERE cancelada = 0" . $fEve,
        $pEve
    );
    $publicaciones = $fila(
        'SELECT COUNT(*) AS total, COALESCE(SUM(alcance),0) AS alcance, COALESCE(SUM(impresiones),0) AS impresiones,
                COALESCE(SUM(interacciones),0) AS interacciones, COALESCE(SUM(clics),0) AS clics, COALESCE(SUM(compartidos),0) AS compartidos
         FROM publicaciones WHERE 1=1' . $fPub,
        $pPub
    );

    $kpis = [
        'comites' => (int) $pdo->query('SELECT COUNT(*) FROM comites WHERE activo = 1')->fetchColumn(),
        'contactos' => (int) $pdo->query("SELECT COUNT(*) FROM contactos WHERE estado = 'Activo'")->fetchColumn(),
        'reuniones' => (int) $reuniones['total'],
        'reuniones_realizadas' => (int) $reuniones['realizadas'],
        'reuniones_canceladas' => (int) $reuniones['canceladas'],
        'acuerdos' => (int) $acuerdos['total'],
        'acuerdos_completados' => (int) $acuerdos['completados'],
        'acuerdos_vencidos' => (int) $acuerdos['vencidos'],
        'avance_acuerdos' => round((float) $acuerdos['avance']),
        'eventos' => (int) $eventos['total'],
        'eventos_realizados' => (int) $eventos['realizados'],
        'participantes' => (int) $eventos['participantes'],
        'documentos' => (int) $fila('SELECT COUNT(*) AS n FROM documentos WHERE 1=1' . $fDoc, $pDoc)['n'],
        'memorias' => (int) $fila('SELECT COUNT(*) AS n FROM memorias_resultados WHERE 1=1' . $fMem, $pMem)['n'],
        'correos' => (int) $fila('SELECT COUNT(*) AS n FROM correos_enviados WHERE 1=1' . $fCor, $pCor)['n'],
        'publicaciones' => (int) $publicaciones['total'],
        'alcance' => (int) $publicaciones['alcance'],
        'impresiones' => (int) $publicaciones['impresiones'],
        'interacciones' => (int) $publicaciones['interacciones'],
        'clics' => (int) $publicaciones['clics'],
        'compartidos' => (int) $publicaciones['compartidos'],
    ];
    $kpis['pct_reuniones'] = $kpis['reuniones'] ? (int) round($kpis['reuniones_realizadas'] / $kpis['reuniones'] * 100) : 0;
    $kpis['pct_acuerdos'] = $kpis['acuerdos'] ? (int) round($kpis['acuerdos_completados'] / $kpis['acuerdos'] * 100) : 0;
    $kpis['tasa_interaccion'] = $kpis['alcance'] ? round($kpis['interacciones'] / $kpis['alcance'] * 100, 1) : 0.0;

    // Serie mensual: publicaciones, alcance, interacciones, reuniones, eventos y correos.
    $porMes = [];
    $acumular = static function (array $rows, array $campos) use (&$porMes): void {
        foreach ($rows as $r) {
            if (!$r['mes']) {
                continue;
            }
            foreach ($campos as $campo) {
                $porMes[$r['mes']][$campo] = (int) $r[$campo];
            }
        }
    };
    $acumular($filas(
        "SELECT DATE_FORMAT(fecha,'%Y-%m') AS mes, COUNT(*) AS publicaciones, COALESCE(SUM(alcance),0) AS alcance, COALESCE(SUM(interacciones),0) AS interacciones
         FROM publicaciones WHERE fecha IS NOT NULL" . $fPub . ' GROUP BY mes',
        $pPub
    ), ['publicaciones', 'alcance', 'interacciones']);
    $acumular($filas(
        "SELECT DATE_FORMAT(COALESCE(r.fecha, r.plan_fecha),'%Y-%m') AS mes, COUNT(*) AS reuniones, COALESCE(SUM(r.realizada = 1),0) AS reuniones_realizadas
         FROM reuniones r WHERE 1=1" . $fReu . ' GROUP BY mes',
        $pReu
    ), ['reuniones', 'reuniones_realizadas']);
    $acumular($filas(
        "SELECT DATE_FORMAT(COALESCE(fecha, plan_fecha),'%Y-%m') AS mes, COUNT(*) AS eventos FROM eventos_transferencia WHERE cancelada = 0" . $fEve . ' GROUP BY mes',
        $pEve
    ), ['eventos']);
    $acumular($filas(
        "SELECT DATE_FORMAT(enviado_en,'%Y-%m') AS mes, COUNT(*) AS correos FROM correos_enviados WHERE 1=1" . $fCor . ' GROUP BY mes',
        $pCor
    ), ['correos']);

    // Meses continuos del periodo (sin huecos) para que la tendencia se lea bien.
    if ($periodo['desde'] !== null) {
        $inicio = $periodo['desde_mes'];
        $fin = $periodo['hasta_mes'];
    } else {
        $claves = array_keys($porMes);
        sort($claves);
        $inicio = $claves[0] ?? date('Y-m');
        $fin = max(end($claves) ?: date('Y-m'), date('Y-m'));
    }
    $meses = [];
    $cursor = new DateTimeImmutable($inicio . '-01');
    $limite = new DateTimeImmutable($fin . '-01');
    while ($cursor <= $limite) {
        $m = $cursor->format('Y-m');
        $meses[$m] = ($porMes[$m] ?? []) + ['publicaciones' => 0, 'alcance' => 0, 'interacciones' => 0, 'reuniones' => 0, 'reuniones_realizadas' => 0, 'eventos' => 0, 'correos' => 0];
        $cursor = $cursor->modify('+1 month');
    }
    $meses = array_slice($meses, -36, null, true);

    $plataformas = $filas(
        'SELECT plataforma, COUNT(*) AS total, COALESCE(SUM(alcance),0) AS alcance, COALESCE(SUM(impresiones),0) AS impresiones,
                COALESCE(SUM(interacciones),0) AS interacciones, COALESCE(SUM(clics),0) AS clics, COALESCE(SUM(compartidos),0) AS compartidos
         FROM publicaciones WHERE 1=1' . $fPub . ' GROUP BY plataforma ORDER BY interacciones DESC, total DESC',
        $pPub
    );
    foreach ($plataformas as &$p) {
        $p['tasa'] = (int) $p['alcance'] ? round((int) $p['interacciones'] / (int) $p['alcance'] * 100, 1) : 0.0;
    }
    unset($p);

    $web = $filas(
        'SELECT * FROM sitio_web_metricas WHERE 1=1' . ($periodo['desde'] !== null ? ' AND periodo BETWEEN ? AND ?' : '') . ' ORDER BY periodo',
        $periodo['desde'] !== null ? [$periodo['desde_mes'], $periodo['hasta_mes']] : []
    );
    $webTotales = ['usuarios' => 0, 'nuevos_usuarios' => 0, 'sesiones' => 0, 'vistas' => 0, 'sesiones_redes' => 0, 'sesiones_whatsapp' => 0];
    foreach ($web as $w) {
        foreach ($webTotales as $k => $v) {
            $webTotales[$k] += (int) $w[$k];
        }
    }
    $webTotales['rebote'] = $web ? round(array_sum(array_column($web, 'rebote')) / count($web), 1) : 0.0;

    return [
        'periodo' => $periodo,
        'generado' => date('d/m/Y H:i'),
        'kpis' => $kpis,
        'meses' => $meses,
        'plataformas' => $plataformas,
        'tipos' => $filas(
            "SELECT COALESCE(NULLIF(tipo,''),'Sin tipo') AS tipo, COUNT(*) AS total, COALESCE(SUM(interacciones),0) AS interacciones
             FROM publicaciones WHERE 1=1" . $fPub . ' GROUP BY 1 ORDER BY total DESC LIMIT 10',
            $pPub
        ),
        'top' => $filas(
            'SELECT fecha, plataforma, tipo, titulo, url, alcance, interacciones, clics FROM publicaciones
             WHERE 1=1' . $fPub . ' ORDER BY interacciones DESC, alcance DESC LIMIT 10',
            $pPub
        ),
        'web' => $web,
        'web_totales' => $webTotales,
        'analisis' => redes_analisis(redes_analisis_filas($pdo, $periodo)),
        'acuerdos_estado' => $filas('SELECT estado, COUNT(*) AS total FROM acuerdos WHERE 1=1' . $fAcu . ' GROUP BY estado ORDER BY total DESC', $pAcu),
        'reuniones_comite' => $filas(
            'SELECT c.nombre AS comite, COUNT(r.id) AS total, COALESCE(SUM(r.realizada = 1),0) AS realizadas
             FROM comites c LEFT JOIN reuniones r ON r.comite_id = c.id' . $fReu . '
             WHERE c.activo = 1 GROUP BY c.id, c.nombre ORDER BY total DESC, c.nombre',
            $pReu
        ),
        'documentos_estado' => $filas(
            "SELECT IF(ambito = 'interna','Interna','Ingenios') AS ambito, estado, COUNT(*) AS total
             FROM documentos WHERE 1=1" . $fDoc . ' GROUP BY ambito, estado ORDER BY ambito, total DESC',
            $pDoc
        ),
        'memorias_estado' => $filas('SELECT estado, COUNT(*) AS total FROM memorias_resultados WHERE 1=1' . $fMem . ' GROUP BY estado ORDER BY total DESC', $pMem),
    ];
}

/**
 * Lista de barras horizontales para las vistas web.
 * $items = [['html' => etiqueta ya escapada, 'valor' => n, 'total' => n opcional, 'nota' => tooltip,
 *            'texto' => valor ya formateado opcional, 'detalle' => texto secundario, 'atenuado' => bool]].
 * $referencia dibuja una linea vertical (p. ej. el promedio) con su etiqueta.
 */
function estadisticas_barras_html(array $items, string $vacio, ?float $referencia = null, string $refEtiqueta = ''): string
{
    if (!$items) {
        return '<div class="empty-state"><i class="ti ti-inbox"></i>' . e($vacio) . '</div>';
    }
    $max = max(1, $referencia ?? 0, ...array_map(static fn($i) => $i['total'] ?? $i['valor'], $items));
    $html = '<ul class="est-barras' . ($referencia !== null ? ' tiene-ref' : '') . '">';
    foreach ($items as $i) {
        $base = $i['total'] ?? null;
        $html .= '<li title="' . e($i['nota'] ?? '') . '" class="' . (!empty($i['atenuado']) ? 'is-atenuado' : '') . '"><span class="est-barras-et">' . $i['html'] . '</span><span class="est-barras-track">'
            . ($base !== null ? '<span class="est-barras-base" style="width:' . round($base / $max * 100, 1) . '%"></span>' : '')
            . '<span class="est-barras-fill" style="width:' . round($i['valor'] / $max * 100, 1) . '%;' . (isset($i['color']) ? '--est-color:' . e($i['color']) : '') . '"></span>'
            . ($referencia !== null ? '<span class="est-barras-ref" style="left:' . round($referencia / $max * 100, 1) . '%"></span>' : '') . '</span>'
            . '<span class="est-barras-num">' . ($i['texto'] ?? estadisticas_num($i['valor'])) . ($base !== null ? '<small>/' . estadisticas_num($base) . '</small>' : '')
            . (isset($i['detalle']) ? '<small class="est-barras-det">' . e($i['detalle']) . '</small>' : '') . '</span></li>';
    }
    $html .= '</ul>';
    if ($referencia !== null && $refEtiqueta !== '') {
        $html .= '<p class="est-leyenda"><span class="est-leyenda-ref"></span> ' . e($refEtiqueta) . '</p>';
    }
    return $html;
}

function estadisticas_mes_corto(string $mes): string
{
    [$a, $m] = explode('-', $mes);
    return ESTADISTICAS_MESES[(int) $m] . ' ' . substr($a, 2);
}

function estadisticas_num(int|float $n, int $decimales = 0): string
{
    return number_format((float) $n, $decimales, '.', ',');
}

/** Hojas del Excel consolidado: cada hoja es [nombre, filas] con la primera fila como encabezado. */
function estadisticas_hojas(array $d): array
{
    $k = $d['kpis'];
    $resumen = [
        ['Indicador', 'Valor'],
        ['Periodo', $d['periodo']['etiqueta']],
        ['Generado', $d['generado']],
        ['Comités activos', $k['comites']],
        ['Contactos activos', $k['contactos']],
        ['Reuniones programadas', $k['reuniones']],
        ['Reuniones realizadas', $k['reuniones_realizadas']],
        ['Reuniones canceladas', $k['reuniones_canceladas']],
        ['% cumplimiento de reuniones', $k['pct_reuniones']],
        ['Acuerdos registrados', $k['acuerdos']],
        ['Acuerdos completados', $k['acuerdos_completados']],
        ['Acuerdos vencidos', $k['acuerdos_vencidos']],
        ['Avance promedio de acuerdos (%)', $k['avance_acuerdos']],
        ['Eventos de transferencia', $k['eventos']],
        ['Eventos realizados', $k['eventos_realizados']],
        ['Participantes en eventos', $k['participantes']],
        ['Documentos cargados', $k['documentos']],
        ['Artículos de memoria', $k['memorias']],
        ['Correos enviados', $k['correos']],
        ['Publicaciones y envíos', $k['publicaciones']],
        ['Alcance total', $k['alcance']],
        ['Impresiones', $k['impresiones']],
        ['Interacciones', $k['interacciones']],
        ['Clics', $k['clics']],
        ['Tasa de interacción (%)', $k['tasa_interaccion']],
    ];
    $mensual = [['Mes', 'Publicaciones', 'Alcance', 'Interacciones', 'Reuniones', 'Reuniones realizadas', 'Eventos', 'Correos']];
    foreach ($d['meses'] as $mes => $m) {
        $mensual[] = [$mes, $m['publicaciones'], $m['alcance'], $m['interacciones'], $m['reuniones'], $m['reuniones_realizadas'], $m['eventos'], $m['correos']];
    }
    $redes = [['Plataforma', 'Publicaciones', 'Alcance', 'Impresiones', 'Interacciones', 'Clics', 'Compartidos', 'Tasa de interacción (%)']];
    foreach ($d['plataformas'] as $p) {
        $redes[] = [$p['plataforma'] ?: 'Sin plataforma', (int) $p['total'], (int) $p['alcance'], (int) $p['impresiones'], (int) $p['interacciones'], (int) $p['clics'], (int) $p['compartidos'], $p['tasa']];
    }
    $top = [['Fecha', 'Plataforma', 'Tipo', 'Título', 'Alcance', 'Interacciones', 'Clics', 'Enlace']];
    foreach ($d['top'] as $p) {
        $top[] = [(string) $p['fecha'], (string) $p['plataforma'], (string) $p['tipo'], (string) $p['titulo'], (int) $p['alcance'], (int) $p['interacciones'], (int) $p['clics'], (string) $p['url']];
    }
    $web = [['Mes', 'Usuarios', 'Nuevos usuarios', 'Sesiones', 'Vistas', 'Duración media (s)', 'Rebote (%)', 'Sesiones desde redes', 'Sesiones desde WhatsApp']];
    foreach ($d['web'] as $w) {
        $web[] = [$w['periodo'], (int) $w['usuarios'], (int) $w['nuevos_usuarios'], (int) $w['sesiones'], (int) $w['vistas'], (int) $w['duracion_seg'], (float) $w['rebote'], (int) $w['sesiones_redes'], (int) $w['sesiones_whatsapp']];
    }
    $comites = [['Comité', 'Reuniones', 'Realizadas']];
    foreach ($d['reuniones_comite'] as $r) {
        $comites[] = [$r['comite'], (int) $r['total'], (int) $r['realizadas']];
    }
    $estados = [['Ámbito', 'Estado', 'Total']];
    foreach ($d['acuerdos_estado'] as $r) {
        $estados[] = ['Acuerdos', $r['estado'], (int) $r['total']];
    }
    foreach ($d['documentos_estado'] as $r) {
        $estados[] = ['Documentos ' . $r['ambito'], $r['estado'], (int) $r['total']];
    }
    foreach ($d['memorias_estado'] as $r) {
        $estados[] = ['Memorias', $r['estado'], (int) $r['total']];
    }

    $an = $d['analisis'];
    $metrica = $an['usa_tasa'] ? 'Tasa de interacción (%)' : 'Interacciones por publicación';
    $recomendaciones = [['Recomendación'], ...array_map(static fn($r) => [html_entity_decode(strip_tags($r['html']), ENT_QUOTES, 'UTF-8')], $an['recomendaciones'])];
    $analisis = [['Dimensión', 'Grupo', 'Publicaciones', 'Interacciones', 'Interacciones por publicación', $metrica, 'Índice vs promedio', 'Pocos datos']];
    $dimensiones = [
        'Formato' => [$an['tipo'], static fn($k) => (string) $k],
        'Día de la semana' => [$an['dia'], static fn($k) => REDES_DIAS[$k]],
        'Plataforma' => [$an['plataforma'], static fn($k) => (string) $k],
        'Largo del texto' => [$an['longitud'], static fn($k) => REDES_LONGITUDES[$k]],
        'Publicaciones por semana' => [$an['frecuencia'], static fn($k) => (string) $k],
        'Tema / palabra' => [$an['temas'], static fn($k) => (string) $k],
    ];
    foreach ($dimensiones as $dim => [$grupos, $nombre]) {
        foreach ($grupos as $k => $g) {
            $analisis[] = [$dim, $nombre($k), $g['n'], $g['interacciones'], round($g['promedio'], 1), round($g['valor'], 1), round($g['indice'], 2), $g['poca_muestra'] ? 'Sí' : 'No'];
        }
    }

    return [
        ['nombre' => 'Resumen', 'filas' => $resumen],
        ['nombre' => 'Recomendaciones', 'filas' => $recomendaciones],
        ['nombre' => 'Qué funciona', 'filas' => $analisis],
        ['nombre' => 'Tendencia mensual', 'filas' => $mensual],
        ['nombre' => 'Redes por plataforma', 'filas' => $redes],
        ['nombre' => 'Top publicaciones', 'filas' => $top],
        ['nombre' => 'Sitio web', 'filas' => $web],
        ['nombre' => 'Reuniones por comité', 'filas' => $comites],
        ['nombre' => 'Estados', 'filas' => $estados],
    ];
}

/** Excel con numeros reales (sumables) y texto explicito para lo que capturan usuarios. */
function estadisticas_excel(array $hojas): string
{
    require_once dirname(__DIR__, 2) . '/cengicursos/vendor/autoload.php';
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    foreach ($hojas as $i => $hoja) {
        $sheet = $i === 0 ? $book->getActiveSheet() : $book->createSheet();
        $sheet->setTitle(mb_substr($hoja['nombre'], 0, 31));
        foreach ($hoja['filas'] as $r => $fila) {
            foreach (array_values($fila) as $c => $valor) {
                $celda = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . ($r + 1);
                if (is_int($valor) || is_float($valor)) {
                    $sheet->setCellValue($celda, $valor);
                    $sheet->getStyle($celda)->getNumberFormat()->setFormatCode(is_float($valor) ? '#,##0.0' : '#,##0');
                } else {
                    $sheet->setCellValueExplicit($celda, (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        $columnas = count($hoja['filas'][0] ?? []);
        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, $columnas));
        $encabezado = $sheet->getStyle('A1:' . $ultima . '1');
        $encabezado->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $encabezado->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF3D6614');
        $sheet->freezePane('A2');
        for ($c = 1; $c <= $columnas; $c++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $largo = max(array_map(static fn($f) => mb_strlen((string) (array_values($f)[$c - 1] ?? '')), $hoja['filas']));
            if ($largo > 80) {
                // Textos largos (recomendaciones, titulos): ancho fijo con ajuste de linea.
                $sheet->getColumnDimension($letra)->setWidth(100);
                $sheet->getStyle($letra . ':' . $letra)->getAlignment()->setWrapText(true);
            } else {
                $sheet->getColumnDimension($letra)->setAutoSize(true);
            }
        }
    }
    $book->setActiveSheetIndex(0);
    $tmp = tempnam(sys_get_temp_dir(), 'estadisticas-');
    try {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp);
        return (string) file_get_contents($tmp);
    } finally {
        $book->disconnectWorksheets();
        unlink($tmp);
    }
}

/**
 * Barras horizontales en tabla para el reporte imprimible. Dompdf no resuelve
 * bien anchos en % dentro de celdas, asi que la barra se mide en px contra $ancho.
 */
function estadisticas_reporte_barras(array $items, int $ancho = 380): string
{
    $max = max(1, ...array_values($items));
    $html = '<table class="barras">';
    foreach ($items as $etiqueta => $valor) {
        $px = (int) round($valor / $max * $ancho);
        $html .= '<tr><td class="et">' . e((string) $etiqueta) . '</td><td class="tr" style="width:' . $ancho . 'px">'
            . ($px > 0 ? '<div style="width:' . $px . 'px"></div>' : '') . '</td><td class="n">' . estadisticas_num($valor) . '</td></tr>';
    }
    return $html . '</table>';
}

/** Reporte consolidado en HTML: se descarga como PDF o se abre para imprimir. */
function estadisticas_reporte_html(array $d, bool $imprimir = false): string
{
    $k = $d['kpis'];
    $verde = MARCA_OSCURO;
    $tarjetas = [
        ['Reuniones realizadas', $k['reuniones_realizadas'] . ' / ' . $k['reuniones'], $k['pct_reuniones'] . '% de cumplimiento'],
        ['Acuerdos completados', $k['acuerdos_completados'] . ' / ' . $k['acuerdos'], $k['avance_acuerdos'] . '% avance promedio · ' . $k['acuerdos_vencidos'] . ' vencidos'],
        ['Eventos realizados', $k['eventos_realizados'] . ' / ' . $k['eventos'], estadisticas_num($k['participantes']) . ' participantes'],
        ['Publicaciones y envíos', estadisticas_num($k['publicaciones']), estadisticas_num($k['alcance']) . ' de alcance'],
        ['Interacciones', estadisticas_num($k['interacciones']), $k['tasa_interaccion'] . '% tasa de interacción'],
        ['Correos enviados', estadisticas_num($k['correos']), $k['documentos'] . ' documentos · ' . $k['memorias'] . ' memorias'],
    ];
    $tabla = static function (array $encabezados, array $filas): string {
        if (!$filas) {
            return '<p class="vacio">Sin datos en el periodo.</p>';
        }
        $h = '<table class="datos"><thead><tr>';
        foreach ($encabezados as $i => $t) {
            $h .= '<th' . ($i ? ' class="n"' : '') . '>' . e($t) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($filas as $fila) {
            $h .= '<tr>';
            foreach (array_values($fila) as $i => $v) {
                $h .= '<td' . ($i ? ' class="n"' : '') . '>' . (is_int($v) || is_float($v) ? estadisticas_num($v, is_float($v) ? 1 : 0) : e((string) $v)) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    };

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Reporte de estadísticas · ' . e($d['periodo']['etiqueta']) . '</title><style>'
        . 'body{font:11px "DejaVu Sans",Arial,sans-serif;color:#334155;margin:24px}'
        . 'header{margin-bottom:16px}.cab{width:100%;border-collapse:collapse;margin-bottom:8px}.cab td{vertical-align:top;padding:0}'
        . 'h1{font-size:20px;color:' . $verde . ';margin:0 0 4px}h2{font-size:14px;color:' . $verde . ';margin:22px 0 8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px;page-break-after:avoid}'
        . 'h3{font-size:12px;margin:12px 0 6px;color:#1e293b}.sub{color:#64748b;margin:0}'
        . '.kpis{width:100%;border-collapse:separate;border-spacing:6px}.kpis td{width:33%;border:1px solid #d5ebbd;background:#f6fbf0;border-radius:6px;padding:8px 10px;vertical-align:top}'
        . '.kpis small{display:block;color:#64748b;font-size:10px}.kpis strong{display:block;font-size:17px;color:#1e293b;margin:2px 0}'
        . 'table.datos{width:100%;border-collapse:collapse;margin:4px 0 10px}table.datos th{background:#EEF7E4;color:#2F5A0B;border-bottom:2px solid #73BC25;text-align:left;padding:5px 6px;font-size:10px}'
        . 'table.datos td{padding:4px 6px;border-bottom:1px solid #e2e8f0}table.datos tr:nth-child(even) td{background:#f8fafc}table.datos .n{text-align:right}'
        . 'table.barras{border-collapse:collapse;margin:4px 0 10px}table.barras td{padding:3px 4px}table.barras .et{width:120px}table.barras .n{width:50px;text-align:right;font-weight:bold}'
        . 'table.barras .tr div{height:10px;background:#73bc25}'
        . '.cols{width:100%;border-collapse:collapse}.cols>tbody>tr>td{width:50%;vertical-align:top;padding-right:10px}'
        . 'ul.recos{list-style:none;margin:4px 0 10px;padding:0}ul.recos li{margin:0 0 5px;padding:6px 9px;border-left:4px solid #ced2d5;background:#f8fafc;line-height:1.45}'
        . 'ul.recos li.bien{border-left-color:#73bc25;background:#f6fbf0}ul.recos li.mejorar{border-left-color:#ff6b00;background:#fff7f0}'
        . '.vacio{color:#94a3b8;font-style:italic}footer{margin-top:20px;font-size:9px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:6px}'
        . '@media print{body{margin:10mm}thead{display:table-header-group}tr{page-break-inside:avoid}.no-print{display:none}}'
        . '.no-print{position:fixed;top:12px;right:12px}.no-print button{background:' . $verde . ';color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:bold;cursor:pointer}'
        . '</style></head><body>';
    if ($imprimir) {
        $html .= '<div class="no-print"><button onclick="window.print()">Imprimir / guardar PDF</button></div>';
    }
    $html .= '<header><table class="cab"><tr><td><h1>Comités, Transferencia y Comunicación</h1><p class="sub">Reporte consolidado de estadísticas · ' . e($d['periodo']['etiqueta']) . ' · generado el ' . e($d['generado']) . '</p></td><td style="text-align:right">' . marca_logo(40) . '</td></tr></table>' . marca_franja() . '</header>';

    $html .= '<h2>Resumen del periodo</h2><table class="kpis"><tr>';
    foreach ($tarjetas as $i => [$titulo, $valor, $nota]) {
        if ($i === 3) {
            $html .= '</tr><tr>';
        }
        $html .= '<td><small>' . e($titulo) . '</small><strong>' . e($valor) . '</strong><small>' . e($nota) . '</small></td>';
    }
    $html .= '</tr></table>';

    $html .= '<h2>Redes sociales y WhatsApp</h2>';
    $html .= '<h3>Alcance por mes</h3>' . ($k['publicaciones'] ? estadisticas_reporte_barras(array_combine(array_map('estadisticas_mes_corto', array_keys($d['meses'])), array_column($d['meses'], 'alcance'))) : '<p class="vacio">Sin publicaciones en el periodo.</p>');
    $html .= '<h3>Resultados por plataforma</h3>' . $tabla(
        ['Plataforma', 'Publicaciones', 'Alcance', 'Interacciones', 'Clics', 'Tasa %'],
        array_map(static fn($p) => [$p['plataforma'] ?: 'Sin plataforma', (int) $p['total'], (int) $p['alcance'], (int) $p['interacciones'], (int) $p['clics'], (float) $p['tasa']], $d['plataformas'])
    );
    $an = $d['analisis'];
    $html .= '<h3>Qué está funcionando</h3><ul class="recos">';
    foreach ($an['recomendaciones'] as $r) {
        $html .= '<li class="' . e($r['tono']) . '">' . $r['html'] . '</li>';
    }
    $html .= '</ul>';
    if ($an['n'] >= 5) {
        $fmt = static fn(float $v): float => round($v, 1);
        $grupoFilas = static fn(array $grupos, callable $nombre): array => array_map(
            static fn($k, $g) => [$nombre($k) . ($g['poca_muestra'] ? ' *' : ''), $g['n'], $fmt($g['valor']), $fmt($g['indice'])],
            array_keys($grupos), $grupos
        );
        $enc = static fn(string $dim): array => [$dim, 'Publ.', $an['usa_tasa'] ? 'Tasa %' : 'Int./publ.', 'Índice'];
        $html .= '<table class="cols"><tr><td>' . '<h3>Por formato</h3>' . $tabla($enc('Formato'), $grupoFilas($an['tipo'], static fn($k) => (string) $k))
            . '</td><td><h3>Por día de la semana</h3>' . $tabla($enc('Día'), $grupoFilas($an['dia'], static fn($k) => REDES_DIAS[$k])) . '</td></tr></table>';
        if ($an['temas']) {
            $html .= '<h3>Temas que más conectan</h3>' . $tabla($enc('Tema'), array_slice($grupoFilas($an['temas'], static fn($k) => (string) $k), 0, 8));
        }
        $html .= '<p class="sub">Índice: rendimiento frente al promedio general (1.0 = igual al promedio). * Menos de ' . REDES_MIN_MUESTRA . ' publicaciones: tómelo como referencia.</p>';
    }
    $html .= '<h3>Publicaciones con más interacción</h3>' . $tabla(
        ['Publicación', 'Alcance', 'Interacciones', 'Clics'],
        array_map(static fn($p) => [($p['fecha'] ? date('d/m/Y', strtotime((string) $p['fecha'])) . ' · ' : '') . $p['plataforma'] . ' · ' . $p['titulo'], (int) $p['alcance'], (int) $p['interacciones'], (int) $p['clics']], $d['top'])
    );
    if ($d['web']) {
        $w = $d['web_totales'];
        $html .= '<h3>Sitio web (Google Analytics)</h3>' . $tabla(
            ['Indicador', 'Valor'],
            [['Usuarios', $w['usuarios']], ['Nuevos usuarios', $w['nuevos_usuarios']], ['Sesiones', $w['sesiones']], ['Vistas', $w['vistas']], ['Sesiones desde redes', $w['sesiones_redes']], ['Sesiones desde WhatsApp', $w['sesiones_whatsapp']], ['Rebote promedio (%)', (float) $w['rebote']]]
        );
    }

    $html .= '<h2>Comités y seguimiento</h2><table class="cols"><tr><td>';
    $html .= '<h3>Reuniones por comité</h3>' . $tabla(['Comité', 'Programadas', 'Realizadas'], array_map(static fn($r) => [$r['comite'], (int) $r['total'], (int) $r['realizadas']], $d['reuniones_comite']));
    $html .= '</td><td>';
    $html .= '<h3>Acuerdos por estado</h3>' . ($d['acuerdos_estado'] ? estadisticas_reporte_barras(array_column($d['acuerdos_estado'], 'total', 'estado'), 150) : '<p class="vacio">Sin acuerdos en el periodo.</p>');
    $html .= '<h3>Actividad mensual</h3>' . $tabla(
        ['Mes', 'Reuniones', 'Eventos', 'Correos'],
        array_map(static fn($mes, $m) => [estadisticas_mes_corto($mes), $m['reuniones'], $m['eventos'], $m['correos']], array_keys($d['meses']), $d['meses'])
    );
    $html .= '</td></tr></table>';

    $html .= '<h2>Documentación</h2><table class="cols"><tr><td>';
    $html .= '<h3>Documentos por estado</h3>' . $tabla(['Biblioteca · estado', 'Total'], array_map(static fn($r) => [$r['ambito'] . ' · ' . $r['estado'], (int) $r['total']], $d['documentos_estado']));
    $html .= '</td><td>';
    $html .= '<h3>Memorias por estado</h3>' . ($d['memorias_estado'] ? estadisticas_reporte_barras(array_column($d['memorias_estado'], 'total', 'estado'), 150) : '<p class="vacio">Sin artículos en el periodo.</p>');
    $html .= '</td></tr></table>';

    return $html . '<footer>CENGICAÑA · Comités, Transferencia y Comunicación · ' . $k['comites'] . ' comités activos · ' . estadisticas_num($k['contactos']) . ' contactos activos</footer></body></html>';
}

/** Responde la descarga pedida (xlsx, pdf o html) y termina la peticion. */
function estadisticas_exportar(PDO $pdo, array $periodo, string $formato): void
{
    $datos = estadisticas_datos($pdo, $periodo);
    $nombre = 'estadisticas_comites_' . ($periodo['desde'] === null ? 'historial' : $periodo['desde_mes'] . '_' . $periodo['hasta_mes']);
    if ($formato === 'xlsx') {
        $contenido = estadisticas_excel(estadisticas_hojas($datos));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
    } elseif ($formato === 'pdf') {
        require_once dirname(__DIR__, 2) . '/cengicursos/vendor/autoload.php';
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml(estadisticas_reporte_html($datos), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $contenido = (string) $pdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombre . '.pdf"');
    } else {
        $contenido = estadisticas_reporte_html($datos, true);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $contenido;
    exit;
}
