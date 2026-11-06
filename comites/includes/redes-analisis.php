<?php
declare(strict_types=1);

// Analisis de contenido de redes sociales: que formatos, dias, plataformas,
// largos de texto y temas generan mas interaccion, y recomendaciones en
// lenguaje claro. La metrica principal es la tasa de interaccion
// (interacciones / alcance), que compara publicaciones con alcances distintos.

/** Grupos con menos publicaciones que esto se muestran pero no generan recomendaciones. */
const REDES_MIN_MUESTRA = 3;

const REDES_DIAS = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

const REDES_LONGITUDES = [
    'corto' => 'Corto (hasta 100 caracteres)',
    'medio' => 'Medio (100 a 300 caracteres)',
    'largo' => 'Largo (más de 300 caracteres)',
];

const REDES_STOPWORDS = [
    'para', 'como', 'esta', 'este', 'estos', 'estas', 'esto', 'pero', 'porque', 'cuando', 'donde', 'desde', 'hasta', 'sobre',
    'entre', 'tambien', 'nuestro', 'nuestra', 'nuestros', 'nuestras', 'todos', 'todas', 'cada', 'mas', 'muy', 'puede', 'pueden',
    'sera', 'seran', 'fueron', 'tiene', 'tienen', 'hacer', 'hace', 'sean', 'sido', 'estan', 'esta', 'parte', 'gracias', 'hola',
    'publicacion', 'publicaciones', 'aqui', 'alli', 'ahora', 'solo', 'sólo', 'otros', 'otras', 'otro', 'otra', 'mismo', 'misma',
    'durante', 'mediante', 'segun', 'ante', 'bajo', 'tras', 'cual', 'cuales', 'quien', 'quienes', 'that', 'with', 'this', 'from',
    'https', 'http', 'www', 'link', 'enlace', 'conoce', 'mira', 'aqui', 'dias', 'año', 'anos', 'años',
];

/**
 * Pilares de contenido de CENGICANA (los temas de los informes mensuales) con las
 * palabras que permiten sugerirlos cuando la publicacion no tiene pilar asignado.
 * El orden importa: se asigna el primero que coincide.
 */
const REDES_PILARES_CLAVES = [
    'Empleo y convocatorias' => ['estamos buscando', 'vacante', 'empleo', 'oportunidad laboral', 'oportunidaddeempleo', 'plaza', 'trabaja con nosotros', 'trabajar en'],
    'Fechas conmemorativas' => ['dia de', 'dia del', 'dia internacional', 'dia mundial', 'dia nacional', 'feliz', 'aniversario', 'navidad', 'año nuevo'],
    'Capacitación y eventos' => ['diplomado', 'curso', 'taller', 'capacitacion', 'webinar', 'seminario', 'congreso', 'inscrib', 'registrate', 'dia de campo', 'conferencia', 'simposio'],
    'Investigación y resultados' => ['investigacion', 'estudio', 'resultado', 'variedad', 'ensayo', 'innovacion', 'tecnologia', 'inteligencia artificial', 'publicacion cientifica', 'memoria'],
    'Recomendaciones técnicas' => ['recomendacion', 'manejo', 'plaga', 'enfermedad', 'riego', 'fertiliz', 'nutricion', 'maleza', 'madurante', 'cosecha', 'sanidad', 'control de', 'como '],
    'Institucional' => ['visita', 'estudiantes', 'recibimos', 'equipo', 'colaboradores', 'reconocimiento', 'alianza', 'convenio', 'cengicana'],
];
const REDES_PILARES = ['Capacitación y eventos', 'Investigación y resultados', 'Recomendaciones técnicas', 'Fechas conmemorativas', 'Institucional', 'Empleo y convocatorias', 'Otro'];

/** Pilar sugerido por palabras clave del texto ('' si no se reconoce). */
function redes_pilar_sugerido(string $texto): string
{
    $t = redes_sin_acentos($texto);
    if (trim($t) === '') {
        return '';
    }
    foreach (REDES_PILARES_CLAVES as $pilar => $claves) {
        foreach ($claves as $clave) {
            if (str_contains($t, $clave)) {
                return $pilar;
            }
        }
    }
    return '';
}

/** Publicaciones publicadas del periodo con alguna metrica, listas para analizar. */
function redes_analisis_filas(PDO $pdo, array $periodo, string $plataforma = ''): array
{
    $sql = "SELECT id, fecha, plataforma, tipo, pilar, pautada, inversion, material, titulo, copy_texto, url, alcance, impresiones, interacciones, reacciones,
                   comentarios, compartidos, clics, reproducciones, guardados
            FROM publicaciones
            WHERE estado = 'Publicada' AND (alcance > 0 OR impresiones > 0 OR interacciones > 0)";
    $params = [];
    if ($periodo['desde'] !== null) {
        $sql .= ' AND fecha BETWEEN ? AND ?';
        array_push($params, $periodo['desde'], $periodo['hasta']);
    }
    if ($plataforma !== '') {
        $sql .= ' AND plataforma = ?';
        $params[] = $plataforma;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY fecha');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Tipos que exportan Meta, LinkedIn y otras redes, agrupados con nombre en espanol. */
function redes_tipo_normalizado(?string $tipo): string
{
    $t = strtolower(trim((string) $tipo));
    if ($t === '') {
        return 'Sin tipo';
    }
    $mapa = [
        'Video' => ['video', 'added_video', 'reel', 'reels', 'clip', 'short', 'shorts', 'live', 'en vivo', 'vídeo'],
        'Foto' => ['photo', 'added_photos', 'image', 'imagen', 'foto', 'fotografia', 'fotografía'],
        'Carrusel' => ['carousel', 'carousel_album', 'album', 'carrusel', 'document', 'documento'],
        'Enlace' => ['link', 'shared_story', 'article', 'articulo', 'artículo', 'enlace'],
        'Texto' => ['status', 'mobile_status_update', 'text', 'texto'],
        'Evento' => ['event', 'created_event', 'evento'],
    ];
    foreach ($mapa as $nombre => $claves) {
        foreach ($claves as $clave) {
            if ($t === $clave || str_contains($t, $clave)) {
                return $nombre;
            }
        }
    }
    return mb_convert_case($t, MB_CASE_TITLE);
}

/** Texto que vio la audiencia (copy o, si no hay, el titulo), sin los titulos de relleno de las importaciones. */
function redes_texto_publicacion(array $p): string
{
    $copy = trim((string) ($p['copy_texto'] ?? ''));
    if ($copy !== '') {
        return $copy;
    }
    $titulo = trim((string) ($p['titulo'] ?? ''));
    return preg_match('/^Publicación( de \S+)?( \d{4}-\d{2}-\d{2})?$/u', $titulo) ? '' : $titulo;
}

/** Nombre corto para mostrar: el titulo, o el texto si el titulo es de relleno de una importacion. */
function redes_titulo_corto(array $p): string
{
    $titulo = trim((string) ($p['titulo'] ?? ''));
    if ($titulo !== '' && !preg_match('/^Publicación( de \S+)?( \d{4}-\d{2}-\d{2})?$/u', $titulo)) {
        return $titulo;
    }
    return redes_texto_publicacion($p) ?: $titulo;
}

/** Titulo (si no es de relleno) y texto juntos: para clasificar el pilar se usa todo lo que se publico. */
function redes_texto_completo(array $p): string
{
    $titulo = trim((string) ($p['titulo'] ?? ''));
    $titulo = preg_match('/^Publicación( de \S+)?( \d{4}-\d{2}-\d{2})?$/u', $titulo) ? '' : $titulo;
    return trim($titulo . ' ' . trim((string) ($p['copy_texto'] ?? '')));
}

function redes_sin_acentos(string $s): string
{
    return strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/** Palabras y hashtags distintos de un texto (clave normalizada => forma visible). */
function redes_palabras(string $texto): array
{
    $texto = preg_replace('~https?://\S+~u', ' ', $texto);
    preg_match_all('/#[\p{L}\p{N}_]{3,}|[\p{L}]{4,}/u', (string) $texto, $m);
    $palabras = [];
    foreach ($m[0] as $palabra) {
        $clave = redes_sin_acentos($palabra);
        if (!in_array(ltrim($clave, '#'), REDES_STOPWORDS, true) && !isset($palabras[$clave])) {
            $palabras[$clave] = $palabra[0] === '#' ? $palabra : mb_strtolower($palabra);
        }
    }
    return $palabras;
}

/**
 * Analisis completo a partir de las filas de publicaciones (funcion pura, sin BD).
 * Cada grupo trae: n, interacciones, base, promedio (interacciones por publicacion),
 * tasa (%), valor (la metrica que se compara), indice (valor / valor global) y poca_muestra.
 */
function redes_analisis(array $filas): array
{
    $posts = [];
    foreach ($filas as $p) {
        $partes = (int) $p['reacciones'] + (int) $p['comentarios'] + (int) $p['compartidos'] + (int) $p['guardados'];
        $inter = (int) $p['interacciones'] ?: $partes;
        $base = (int) $p['alcance'] ?: (int) $p['impresiones'];
        $texto = redes_texto_publicacion($p);
        $largo = mb_strlen($texto);
        $posts[] = $p + [
            'inter' => $inter,
            'base' => $base,
            'tasa' => $base ? $inter / $base * 100 : null,
            'tipo_n' => redes_tipo_normalizado($p['tipo'] ?? ''),
            'dia' => $p['fecha'] ? (int) date('N', strtotime((string) $p['fecha'])) : null,
            'semana' => $p['fecha'] ? date('o-W', strtotime((string) $p['fecha'])) : null,
            'mes' => $p['fecha'] ? substr((string) $p['fecha'], 0, 7) : null,
            'longitud' => $largo === 0 ? null : ($largo <= 100 ? 'corto' : ($largo <= 300 ? 'medio' : 'largo')),
            'palabras' => redes_palabras($texto),
            'pilar_n' => ($p['pilar'] ?? '') ?: (redes_pilar_sugerido(redes_texto_completo($p)) ?: 'Sin clasificar'),
            'pilar_sugerido' => empty($p['pilar']),
            'pagada' => !empty($p['pautada']),
            'inversion_q' => (float) ($p['inversion'] ?? 0),
        ];
    }

    // Pauta: la publicidad infla el alcance y baja la tasa. Para juzgar el CONTENIDO se comparan
    // solo publicaciones organicas cuando hay suficientes; la pauta se resume aparte.
    $todos = $posts;
    $organicos = array_values(array_filter($todos, static fn($p) => !$p['pagada']));
    $pagados = array_values(array_filter($todos, static fn($p) => $p['pagada']));
    $soloOrganico = $pagados && count($organicos) >= 5;
    if ($soloOrganico) {
        $posts = $organicos;
    }
    $resumenGrupo = static function (array $grupo): array {
        $inter = array_sum(array_column($grupo, 'inter'));
        $base = array_sum(array_column($grupo, 'base'));
        $inversion = array_sum(array_column($grupo, 'inversion_q'));
        return [
            'n' => count($grupo), 'interacciones' => $inter, 'alcance' => $base, 'inversion' => $inversion,
            'tasa' => $base ? $inter / $base * 100 : 0.0,
            'alcance_promedio' => $grupo ? $base / count($grupo) : 0,
            'cpm' => $base && $inversion ? $inversion / $base * 1000 : null,
            'costo_interaccion' => $inter && $inversion ? $inversion / $inter : null,
        ];
    };
    $pauta = ['organico' => $resumenGrupo($organicos), 'pagado' => $resumenGrupo($pagados), 'solo_organico' => $soloOrganico, 'n_total' => count($todos)];

    $n = count($posts);
    $totalInter = array_sum(array_column($posts, 'inter'));
    $totalBase = array_sum(array_column($posts, 'base'));
    // Si no hay alcance registrado, se compara por interacciones por publicacion.
    $usaTasa = $totalBase > 0;
    $global = $usaTasa ? $totalInter / $totalBase * 100 : ($n ? $totalInter / $n : 0);

    $resumir = static function (array $grupo) use ($usaTasa, $global): array {
        $cnt = count($grupo);
        $inter = array_sum(array_column($grupo, 'inter'));
        $base = array_sum(array_column($grupo, 'base'));
        $tasa = $base ? $inter / $base * 100 : 0.0;
        $valor = $usaTasa ? $tasa : ($cnt ? $inter / $cnt : 0);
        $suma = static fn(string $c): int => array_sum(array_map('intval', array_column($grupo, $c)));
        $conTasaGrupo = array_filter($grupo, static fn($p) => $p['tasa'] !== null);
        usort($conTasaGrupo, static fn($x, $y) => $y['tasa'] <=> $x['tasa']);
        $mejor = $conTasaGrupo[0] ?? null;
        return [
            'n' => $cnt,
            'interacciones' => $inter,
            'base' => $base,
            'promedio' => $cnt ? $inter / $cnt : 0,
            'tasa' => $tasa,
            'valor' => $valor,
            'indice' => $global > 0 ? $valor / $global : 0,
            'poca_muestra' => $cnt < REDES_MIN_MUESTRA,
            'alcance_promedio' => $cnt ? $base / $cnt : 0,
            'ctr' => $base ? $suma('clics') / $base * 100 : 0.0,
            'pct_compartidos' => $inter ? $suma('compartidos') / $inter * 100 : 0.0,
            'pct_comentarios' => $inter ? $suma('comentarios') / $inter * 100 : 0.0,
            'mejor' => $mejor ? ['id' => (int) $mejor['id'], 'titulo' => mb_strimwidth(redes_titulo_corto($mejor), 0, 90, '…'), 'tasa' => $mejor['tasa'], 'plataforma' => $mejor['plataforma']] : null,
        ];
    };
    $agrupar = static function (callable $clave, ?array $orden = null) use ($posts, $resumir): array {
        $grupos = [];
        foreach ($posts as $p) {
            $k = $clave($p);
            if ($k !== null && $k !== '') {
                $grupos[$k][] = $p;
            }
        }
        $res = array_map($resumir, $grupos);
        if ($orden !== null) {
            return array_intersect_key(array_replace(array_flip(array_keys($orden)), $res), $res);
        }
        uasort($res, static fn($a, $b) => $b['valor'] <=> $a['valor'] ?: $b['n'] <=> $a['n']);
        return $res;
    };

    $porTipo = $agrupar(static fn($p) => $p['tipo_n']);
    $porPilar = $agrupar(static fn($p) => $p['pilar_n']);
    if (isset($porPilar['Sin clasificar'])) {
        // Lo no clasificado no es un tema: siempre al final.
        $porPilar += array_splice($porPilar, (int) array_search('Sin clasificar', array_keys($porPilar), true), 1);
    }
    $pilaresSugeridos = count(array_filter($posts, static fn($p) => $p['pilar_sugerido'] && $p['pilar_n'] !== 'Sin clasificar'));
    $porPlataforma = $agrupar(static fn($p) => $p['plataforma'] ?: 'Sin plataforma');
    $porDia = $agrupar(static fn($p) => $p['dia'], REDES_DIAS);
    $porLongitud = $agrupar(static fn($p) => $p['longitud'], REDES_LONGITUDES);
    $porMes = $agrupar(static fn($p) => $p['mes']);
    ksort($porMes);

    // Frecuencia: rendimiento por publicacion segun cuantas se publicaron esa semana.
    $porSemana = array_count_values(array_filter(array_column($posts, 'semana')));
    $frecuencia = $agrupar(static function ($p) use ($porSemana) {
        if (!$p['semana']) {
            return null;
        }
        $c = $porSemana[$p['semana']];
        return $c >= 4 ? '4 o más por semana' : ($c === 1 ? '1 por semana' : $c . ' por semana');
    }, ['1 por semana' => 1, '2 por semana' => 1, '3 por semana' => 1, '4 o más por semana' => 1]);

    // Temas: palabras presentes en al menos 2 publicaciones.
    $porPalabra = [];
    $formas = [];
    foreach ($posts as $p) {
        foreach ($p['palabras'] as $clave => $forma) {
            $porPalabra[$clave][] = $p;
            $formas[$clave] ??= $forma;
        }
    }
    // Palabras que aparecen siempre juntas (mismas publicaciones) son un mismo tema:
    // se agrupan en una sola etiqueta, con los hashtags primero.
    $clusters = [];
    foreach ($porPalabra as $clave => $grupo) {
        if (count($grupo) >= 2) {
            $firma = implode(',', array_column($grupo, 'id'));
            $clusters[$firma]['grupo'] = $grupo;
            $clusters[$firma]['palabras'][] = $formas[$clave];
        }
    }
    $temas = [];
    foreach ($clusters as $c) {
        usort($c['palabras'], static fn($x, $y) => ($y[0] === '#') <=> ($x[0] === '#'));
        $temas[implode(' · ', array_slice($c['palabras'], 0, 3))] = $resumir($c['grupo']);
    }
    uasort($temas, static fn($a, $b) => $b['indice'] <=> $a['indice'] ?: $b['n'] <=> $a['n']);

    $composicion = [];
    foreach (['reacciones' => 'Reacciones', 'comentarios' => 'Comentarios', 'compartidos' => 'Compartidos', 'guardados' => 'Guardados', 'clics' => 'Clics'] as $campo => $etiqueta) {
        $composicion[$etiqueta] = array_sum(array_map('intval', array_column($posts, $campo)));
    }

    $conTasa = array_values(array_filter($posts, static fn($p) => $p['tasa'] !== null));
    usort($conTasa, static fn($a, $b) => $b['tasa'] <=> $a['tasa']);
    $destacadas = array_slice($conTasa, 0, 5);
    $revisar = count($conTasa) >= 6 ? array_reverse(array_slice($conTasa, -5)) : [];

    // Distribucion de la tasa: mediana y cuartiles dicen que es "normal" para la cuenta.
    $tasas = array_column($conTasa, 'tasa');
    sort($tasas);
    $percentil = static function (array $v, float $p): ?float {
        if (!$v) {
            return null;
        }
        $i = ($p / 100) * (count($v) - 1);
        $bajo = (int) floor($i);
        return $v[$bajo] + ($v[min($bajo + 1, count($v) - 1)] - $v[$bajo]) * ($i - $bajo);
    };
    $cortes = [0, 2, 4, 6, 8, 10, 15, 20];
    $histograma = [];
    foreach ($cortes as $i => $desde) {
        $hasta = $cortes[$i + 1] ?? null;
        $histograma[] = [
            'etiqueta' => $hasta === null ? $desde . '% o más' : $desde . '–' . $hasta . '%', 'desde' => $desde, 'hasta' => $hasta,
            'n' => count(array_filter($tasas, static fn($t) => $t >= $desde && ($hasta === null || $t < $hasta))),
        ];
    }
    while ($histograma && end($histograma)['n'] === 0) {
        array_pop($histograma);
    }
    while ($histograma && $histograma[0]['n'] === 0) {
        array_shift($histograma);
    }
    $distribucion = [
        'n' => count($tasas), 'min' => $tasas[0] ?? null, 'max' => $tasas ? end($tasas) : null,
        'p25' => $percentil($tasas, 25), 'mediana' => $percentil($tasas, 50), 'p75' => $percentil($tasas, 75),
        'histograma' => $histograma,
    ];

    // Cruce tema x formato: en que combinacion funciona mejor cada tema.
    $cruce = [];
    $porPilarTipo = [];
    foreach ($posts as $p) {
        $porPilarTipo[$p['pilar_n']][$p['tipo_n']][] = $p;
    }
    foreach ($porPilarTipo as $pil => $tipos) {
        foreach ($tipos as $tip => $grupo) {
            $cruce[$pil][$tip] = $resumir($grupo);
        }
    }

    // Ritmo: publicaciones por semana entre la primera y la ultima del periodo.
    $fechas = array_filter(array_column($posts, 'fecha'));
    $semanasPeriodo = $fechas ? max(1, (strtotime(max($fechas)) - strtotime(min($fechas))) / 604800 + 1) : 1;

    // Candidatas a pautar: organicas que la audiencia ya respondio mejor que el promedio organico.
    $tasaOrganica = $pauta['organico']['tasa'];
    $candidatas = array_values(array_filter($organicos, static fn($p) => $p['tasa'] !== null && $tasaOrganica > 0 && $p['tasa'] >= $tasaOrganica * 1.25 && $p['base'] > 0));
    usort($candidatas, static fn($x, $y) => $y['tasa'] <=> $x['tasa']);

    $a = [
        'distribucion' => $distribucion,
        'cruce' => $cruce,
        'por_semana' => $n / $semanasPeriodo,
        'mejor_post' => $conTasa[0] ?? null,
        'peor_post' => count($conTasa) >= 3 ? end($conTasa) : null,
        'pilar' => $porPilar,
        'pilares_sugeridos' => $pilaresSugeridos,
        'pauta' => $pauta,
        'candidatas' => array_slice($candidatas, 0, 3),
        'n' => $n,
        'usa_tasa' => $usaTasa,
        'global' => $global,
        'interacciones' => $totalInter,
        'alcance' => $totalBase,
        'promedio' => $n ? $totalInter / $n : 0,
        'clics' => $composicion['Clics'],
        'ctr' => $totalBase ? $composicion['Clics'] / $totalBase * 100 : 0,
        'valor_pct' => $totalInter ? ($composicion['Comentarios'] + $composicion['Compartidos'] + $composicion['Guardados']) / $totalInter * 100 : 0,
        'tipo' => $porTipo,
        'plataforma' => $porPlataforma,
        'dia' => $porDia,
        'longitud' => $porLongitud,
        'frecuencia' => $frecuencia,
        'mes' => $porMes,
        'temas' => array_slice($temas, 0, 12, true),
        'composicion' => $composicion,
        'destacadas' => $destacadas,
        'revisar' => $revisar,
    ];
    $a['recomendaciones'] = redes_recomendaciones($a);
    return $a;
}

// ---------------------------------------------------------------- Evaluacion de cada publicacion

/** Niveles de rendimiento frente al promedio de su red: [etiqueta, clase, icono]. */
const REDES_NIVELES = [
    'destacada' => ['Destacada', 'is-top', 'ti-star-filled'],
    'arriba' => ['Sobre el promedio', 'is-up', 'ti-trending-up'],
    'promedio' => ['En el promedio', 'is-mid', 'ti-minus'],
    'abajo' => ['Bajo el promedio', 'is-down', 'ti-trending-down'],
    'sin_dato' => ['Sin alcance para comparar', 'is-none', 'ti-help-circle'],
];

/**
 * Referencia por plataforma con las publicaciones organicas publicadas de los
 * ultimos 12 meses: tasa de interaccion, clics por cada 100 alcanzados y peso
 * de compartidos y comentarios dentro de las interacciones.
 */
function redes_promedios_plataforma(PDO $pdo): array
{
    $filas = $pdo->query(
        "SELECT plataforma, pautada, COUNT(*) AS n, SUM(alcance) AS alcance, SUM(interacciones) AS inter, SUM(clics) AS clics,
                SUM(compartidos) AS compartidos, SUM(comentarios) AS comentarios, SUM(guardados) AS guardados
         FROM publicaciones
         WHERE estado = 'Publicada' AND alcance > 0 AND fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY plataforma, pautada"
    )->fetchAll(PDO::FETCH_ASSOC);
    $porRed = [];
    foreach ($filas as $f) {
        // Se prefiere la referencia organica; si no hay suficientes, se usan todas.
        $porRed[$f['plataforma']][(int) $f['pautada'] ? 'pagada' : 'organica'] = $f;
    }
    $res = [];
    foreach ($porRed as $red => $g) {
        $base = (int) ($g['organica']['n'] ?? 0) >= REDES_MIN_MUESTRA ? $g['organica'] : null;
        if (!$base) {
            $base = ['n' => 0, 'alcance' => 0, 'inter' => 0, 'clics' => 0, 'compartidos' => 0, 'comentarios' => 0, 'guardados' => 0];
            foreach ($g as $f) {
                foreach ($base as $k => $v) {
                    $base[$k] = $v + (float) $f[$k];
                }
            }
        }
        $inter = max(1, (float) $base['inter']);
        $res[$red] = [
            'n' => (int) $base['n'],
            'organica' => isset($g['organica']) && $base === $g['organica'],
            'tasa' => (float) $base['inter'] / max(1, (float) $base['alcance']) * 100,
            'ctr' => (float) $base['clics'] / max(1, (float) $base['alcance']) * 100,
            'alcance' => (float) $base['alcance'] / max(1, (int) $base['n']),
            'pct_compartidos' => (float) $base['compartidos'] / $inter * 100,
            'pct_comentarios' => (float) $base['comentarios'] / $inter * 100,
            'pct_guardados' => (float) $base['guardados'] / $inter * 100,
        ];
        // Referencia propia de las pautadas: la pauta baja la tasa, asi se comparan entre si.
        $pg = $g['pagada'] ?? null;
        if ($pg && (int) $pg['n'] >= REDES_MIN_MUESTRA && (float) $pg['alcance'] > 0) {
            $interPg = max(1, (float) $pg['inter']);
            $res[$red]['pagada'] = [
                'n' => (int) $pg['n'], 'organica' => false,
                'tasa' => (float) $pg['inter'] / (float) $pg['alcance'] * 100,
                'ctr' => (float) $pg['clics'] / (float) $pg['alcance'] * 100,
                'alcance' => (float) $pg['alcance'] / (int) $pg['n'],
                'pct_compartidos' => (float) $pg['compartidos'] / $interPg * 100,
                'pct_comentarios' => (float) $pg['comentarios'] / $interPg * 100,
                'pct_guardados' => (float) $pg['guardados'] / $interPg * 100,
            ];
        }
    }
    return $res;
}

/**
 * Diagnostico de una publicacion frente a su red: tasa, indice, nivel, desglose
 * de interacciones, clics por cada 100 alcanzados, pilar, hallazgos en lenguaje
 * claro y datos que faltan para analizarla bien.
 */
function redes_evaluar_publicacion(array $p, array $promedios): array
{
    $alcance = (int) $p['alcance'] ?: (int) $p['impresiones'];
    $partes = ['reacciones' => (int) $p['reacciones'], 'comentarios' => (int) $p['comentarios'], 'compartidos' => (int) $p['compartidos'], 'guardados' => (int) $p['guardados'], 'clics' => (int) $p['clics']];
    $inter = (int) $p['interacciones'] ?: array_sum($partes) - $partes['clics'];
    $tasa = $alcance ? $inter / $alcance * 100 : null;
    $ref = $promedios[$p['plataforma']] ?? null;
    // Pautadas: contra el promedio de las pautadas si existe; si no, contra las organicas (y se avisa).
    $refTipo = 'organica';
    if (!empty($p['pautada']) && $ref) {
        if (isset($ref['pagada'])) {
            $ref = $ref['pagada'];
            $refTipo = 'pagada';
        } else {
            $refTipo = 'organica_vs_pautada';
        }
    }
    $conRef = $ref && $ref['n'] >= REDES_MIN_MUESTRA && $ref['tasa'] > 0;
    $indice = $tasa !== null && $conRef ? $tasa / $ref['tasa'] : null;
    $nivel = $tasa === null ? 'sin_dato' : ($indice === null ? 'promedio' : ($indice >= 1.5 ? 'destacada' : ($indice >= 1.15 ? 'arriba' : ($indice >= 0.85 ? 'promedio' : 'abajo'))));
    $ctr = $alcance ? $partes['clics'] / $alcance * 100 : null;
    $sumaDesglose = array_sum($partes) ?: 1;
    $desglose = array_map(static fn($v) => $v / $sumaDesglose * 100, $partes);
    $texto = redes_texto_publicacion($p);
    $pilarSugerido = empty($p['pilar']) ? redes_pilar_sugerido(redes_texto_completo($p)) : '';
    $pilar = ($p['pilar'] ?? '') ?: $pilarSugerido;
    $esWa = $p['plataforma'] === 'WhatsApp';

    $grupoRef = ($refTipo === 'pagada' ? 'las pautadas de ' : ($refTipo === 'organica_vs_pautada' ? 'las orgánicas de ' : '')) . e((string) $p['plataforma']);
    $hallazgos = [];
    if ($indice !== null) {
        $hallazgos[] = $indice >= 1.15
            ? ['bien', 'ti-trending-up', 'Su tasa de interacción (' . number_format($tasa, 1) . '%) es <b>' . redes_veces($indice) . '</b> el promedio de ' . $grupoRef . ' (' . number_format($ref['tasa'], 1) . '%).']
            : ($indice <= 0.85
                ? ['mejorar', 'ti-trending-down', 'Su tasa de interacción (' . number_format($tasa, 1) . '%) quedó por debajo del promedio de ' . $grupoRef . ' (' . number_format($ref['tasa'], 1) . '%).']
                : ['info', 'ti-equal', 'Su tasa de interacción (' . number_format($tasa, 1) . '%) está en el promedio de ' . $grupoRef . '.']);
    }
    if ($inter > 0 && $conRef) {
        $pctComp = $partes['compartidos'] / max(1, $inter) * 100;
        $pctCom = $partes['comentarios'] / max(1, $inter) * 100;
        if ($partes['compartidos'] >= 5 && $pctComp >= max(10, $ref['pct_compartidos'] * 1.5)) {
            $hallazgos[] = ['bien', 'ti-share-3', 'Se compartió mucho (' . number_format($pctComp, 0) . '% de sus interacciones): la audiencia lo consideró <b>útil para otros</b>. Repita el formato y el tema.'];
        }
        if ($partes['comentarios'] >= 5 && $pctCom >= max(8, $ref['pct_comentarios'] * 1.5)) {
            $hallazgos[] = ['bien', 'ti-messages', 'Generó <b>conversación</b> (' . number_format($pctCom, 0) . '% comentarios). Responda los comentarios para aumentar su alcance.'];
        }
    }
    if ($ctr !== null && $conRef && !empty($p['url']) && $ref['ctr'] > 0) {
        if ($ctr >= $ref['ctr'] * 1.5 && $partes['clics'] >= 5) {
            $hallazgos[] = ['bien', 'ti-click', number_format($ctr, 1) . ' de cada 100 personas hizo clic (promedio ' . number_format($ref['ctr'], 1) . '): la <b>llamada a la acción</b> funcionó.'];
        } elseif ($ctr < $ref['ctr'] * 0.6 && $alcance >= 300) {
            $hallazgos[] = ['mejorar', 'ti-click', 'Pocos clics (' . number_format($ctr, 1) . ' de cada 100). Ponga el enlace al inicio y una acción clara («Inscríbase», «Descargue»).'];
        }
    }
    if (!empty($p['pautada'])) {
        $inv = (float) ($p['inversion'] ?? 0);
        $hallazgos[] = ['info', 'ti-cash', 'Publicación <b>pautada</b>' . ($inv > 0 ? ' (' . redes_quetzales($inv) . '): ' . ($alcance ? redes_quetzales($inv / $alcance * 1000) . ' por cada 1,000 alcanzados' : '') . ($inter ? ' · ' . redes_quetzales($inv / $inter) . ' por interacción' : '') : '') . '. ' . ($refTipo === 'organica_vs_pautada' ? 'Se comparó con las orgánicas porque aún no hay suficientes pautadas: la pauta suele bajar la tasa, tome la comparación con cautela.' : 'Se comparó con las demás publicaciones pautadas.')];
    } elseif ($nivel === 'destacada' && !$esWa) {
        $hallazgos[] = ['bien', 'ti-rocket', 'Rinde muy por encima de lo normal sin pauta: <b>buena candidata para pautar</b> o para volver a publicar en otro formato.'];
    }

    $faltan = [];
    if (!$alcance) {
        $faltan[] = 'alcance';
    }
    if ($texto === '') {
        $faltan[] = 'texto de la publicación';
    }
    if (empty($p['pilar'])) {
        $faltan[] = 'pilar de contenido';
    }
    if (empty($p['url'])) {
        $faltan[] = 'enlace';
    }

    return [
        'alcance' => $alcance, 'interacciones' => $inter, 'tasa' => $tasa, 'indice' => $indice, 'nivel' => $nivel, 'ctr' => $ctr,
        'desglose' => $desglose, 'partes' => $partes, 'pilar' => $pilar, 'pilar_sugerido' => $pilarSugerido !== '',
        'referencia' => $conRef ? $ref : null, 'ref_tipo' => $refTipo, 'grupo_ref' => strip_tags($grupoRef), 'hallazgos' => $hallazgos, 'faltan' => $faltan, 'texto' => $texto,
        'dia' => $p['fecha'] ? REDES_DIAS[(int) date('N', strtotime((string) $p['fecha']))] : '',
    ];
}

/** Formatea la metrica comparada (tasa % o interacciones por publicacion). */
function redes_valor_txt(array $a, float $valor): string
{
    return $a['usa_tasa'] ? number_format($valor, 1) . '%' : number_format($valor, 1) . ' interacciones';
}

function redes_veces(float $indice): string
{
    return number_format($indice, 1) . '×';
}

/**
 * Recomendaciones accionables. Solo se comparan grupos con muestra suficiente
 * y diferencias de al menos 15 %, para no sacar conclusiones del azar.
 * Cada una: ['tono' => bien|mejorar|info, 'icono' => ti-*, 'html' => texto con <b>].
 */
function redes_recomendaciones(array $a): array
{
    $rec = [];
    $metrica = $a['usa_tasa'] ? 'tasa de interacción' : 'interacciones por publicación';
    if ($a['n'] < 5) {
        return [[
            'tono' => 'info', 'icono' => 'ti-database-search',
            'html' => 'Solo hay <b>' . $a['n'] . ' ' . ($a['n'] === 1 ? 'publicación' : 'publicaciones') . '</b> con métricas en este periodo. Con al menos 10 a 15, las recomendaciones son confiables: conecte las redes o importe sus estadísticas y amplíe el periodo.',
        ]];
    }
    $solidos = static fn(array $grupos): array => array_filter($grupos, static fn($g) => !$g['poca_muestra']);

    $tipos = $solidos($a['tipo']);
    if (count($tipos) >= 2) {
        $mejor = array_key_first($tipos);
        $peor = array_key_last($tipos);
        $ratio = $tipos[$peor]['valor'] > 0 ? $tipos[$mejor]['valor'] / $tipos[$peor]['valor'] : 0;
        if ($tipos[$mejor]['indice'] >= 1.15 || $ratio >= 1.3) {
            $rec[] = ['tono' => 'bien', 'icono' => 'ti-photo-video', 'html' => 'El formato <b>' . e($mejor) . '</b> es el que más conecta: ' . redes_valor_txt($a, $tipos[$mejor]['valor']) . ' de ' . $metrica
                . ($ratio >= 1.3 ? ', <b>' . redes_veces($ratio) . '</b> lo que logra <b>' . e($peor) . '</b>' : '') . ' (' . $tipos[$mejor]['n'] . ' publicaciones). Dé prioridad a este formato.'];
        }
        if ($tipos[$peor]['indice'] <= 0.8) {
            $rec[] = ['tono' => 'mejorar', 'icono' => 'ti-arrow-down-right', 'html' => 'Las publicaciones de tipo <b>' . e($peor) . '</b> quedan por debajo del promedio (' . redes_veces($tipos[$peor]['indice']) . '). Pruebe a acompañarlas con imagen o video, o a replantear el mensaje.'];
        }
    }

    $pilares = array_diff_key($solidos($a['pilar']), ['Sin clasificar' => 1]);
    if (count($pilares) >= 2) {
        $mejor = array_key_first($pilares);
        $peor = array_key_last($pilares);
        if ($pilares[$mejor]['indice'] >= 1.15) {
            $rec[] = ['tono' => 'bien', 'icono' => 'ti-category-2', 'html' => 'El tema que más interesa es <b>' . e($mejor) . '</b>: ' . redes_valor_txt($a, $pilares[$mejor]['valor']) . ' de ' . $metrica
                . ' (' . redes_veces($pilares[$mejor]['indice']) . ' el promedio, ' . $pilares[$mejor]['n'] . ' publicaciones). Planifique más contenido de este tipo en el calendario.'];
        }
        if ($pilares[$peor]['indice'] <= 0.8) {
            $rec[] = ['tono' => 'mejorar', 'icono' => 'ti-category-minus', 'html' => 'Las publicaciones de <b>' . e($peor) . '</b> conectan menos (' . redes_veces($pilares[$peor]['indice']) . '). Pruebe a contarlas con personas, datos del campo o un video corto, en lugar de una pieza solo gráfica.'];
        }
    }

    $pauta = $a['pauta'];
    if ($pauta['pagado']['n'] > 0 && $pauta['organico']['n'] > 0 && $pauta['organico']['tasa'] > 0) {
        $multAlcance = $pauta['organico']['alcance_promedio'] > 0 ? $pauta['pagado']['alcance_promedio'] / $pauta['organico']['alcance_promedio'] : 0;
        $relTasa = $pauta['pagado']['tasa'] / $pauta['organico']['tasa'];
        $html = 'La pauta multiplicó el alcance por publicación <b>' . number_format($multAlcance, 1) . '×</b>';
        if ($relTasa < 0.7) {
            $html .= ', pero la tasa de interacción de lo pautado (' . number_format($pauta['pagado']['tasa'], 1) . '%) es bastante menor que la orgánica (' . number_format($pauta['organico']['tasa'], 1) . '%): se está llegando a personas menos interesadas. Ajuste la segmentación (zonas cañeras, técnicos e ingenios) antes de subir el presupuesto.';
            $rec[] = ['tono' => 'mejorar', 'icono' => 'ti-target-arrow', 'html' => $html];
        } else {
            $html .= ' sin perder interés (tasa pautada ' . number_format($pauta['pagado']['tasa'], 1) . '% frente a ' . number_format($pauta['organico']['tasa'], 1) . '% orgánica). La segmentación está funcionando.';
            $rec[] = ['tono' => 'bien', 'icono' => 'ti-target-arrow', 'html' => $html];
        }
    }
    if ($a['candidatas']) {
        $c = $a['candidatas'][0];
        $titulo = mb_strimwidth(redes_texto_publicacion($c) ?: (string) $c['titulo'], 0, 70, '…');
        $rec[] = ['tono' => 'bien', 'icono' => 'ti-rocket', 'html' => 'Candidata a pautar: <b>«' . e($titulo) . '»</b> logró ' . number_format((float) $c['tasa'], 1) . '% de tasa sin pauta. Invertir en lo que ya funciona orgánicamente rinde más que pautar contenido nuevo sin probar.'];
    }

    $dias = $solidos($a['dia']);
    if (count($dias) >= 2) {
        uasort($dias, static fn($x, $y) => $y['valor'] <=> $x['valor']);
        $mejor = array_key_first($dias);
        $peor = array_key_last($dias);
        if ($dias[$mejor]['indice'] >= 1.15) {
            $rec[] = ['tono' => 'bien', 'icono' => 'ti-calendar-star', 'html' => 'Los <b>' . e(mb_strtolower(REDES_DIAS[$mejor])) . '</b> sus publicaciones rinden <b>' . redes_veces($dias[$mejor]['indice']) . '</b> el promedio. Programe ese día lo más importante'
                . ($dias[$peor]['indice'] <= 0.85 ? ' y evite los ' . e(mb_strtolower(REDES_DIAS[$peor])) . ' (' . redes_veces($dias[$peor]['indice']) . ')' : '') . '.'];
        }
    }

    $largos = $solidos($a['longitud']);
    if (count($largos) >= 2) {
        uasort($largos, static fn($x, $y) => $y['valor'] <=> $x['valor']);
        $mejor = array_key_first($largos);
        if ($largos[$mejor]['indice'] >= 1.15) {
            $rec[] = ['tono' => 'bien', 'icono' => 'ti-text-size', 'html' => 'Los <b>' . ['corto' => 'textos cortos', 'medio' => 'textos de largo medio', 'largo' => 'textos largos'][$mejor] . '</b> ' . e(substr(REDES_LONGITUDES[$mejor], strpos(REDES_LONGITUDES[$mejor], '('))) . ' funcionan mejor (' . redes_veces($largos[$mejor]['indice']) . ' el promedio). Ajuste el largo de sus textos a ese rango.'];
        }
    }

    $plataformas = $solidos($a['plataforma']);
    if (count($plataformas) >= 2 && $a['usa_tasa'] && $plataformas[array_key_first($plataformas)]['indice'] >= 1.15) {
        $mejor = array_key_first($plataformas);
        $rec[] = ['tono' => 'info', 'icono' => 'ti-share', 'html' => 'En <b>' . e($mejor) . '</b> su audiencia participa más: ' . redes_valor_txt($a, $plataformas[$mejor]['valor']) . ' de tasa de interacción, frente a ' . redes_valor_txt($a, $a['global']) . ' en general.'];
    }

    $temas = array_filter($a['temas'], static fn($t) => $t['indice'] >= 1.2);
    if ($temas) {
        $lista = array_map(static fn($t, $g) => '<b>' . e($t) . '</b> (' . redes_veces($g['indice']) . ')', array_keys(array_slice($temas, 0, 4, true)), array_slice($temas, 0, 4, true));
        $rec[] = ['tono' => 'bien', 'icono' => 'ti-flame', 'html' => 'Los temas que más interesan: ' . implode(', ', $lista) . '. Vuelva a publicar sobre ellos y úselos como hashtags.'];
    }

    $frec = $solidos($a['frecuencia']);
    if (count($frec) >= 2) {
        $claves = array_keys($frec);
        $primero = $frec[$claves[0]];
        $ultimo = $frec[end($claves)];
        if ($primero['valor'] > 0 && $ultimo['valor'] / $primero['valor'] <= 0.75) {
            $rec[] = ['tono' => 'mejorar', 'icono' => 'ti-stack-2', 'html' => 'Las semanas con <b>' . e(end($claves)) . '</b> publicaciones rinden menos por publicación (' . redes_valor_txt($a, $ultimo['valor']) . ' frente a ' . redes_valor_txt($a, $primero['valor']) . '). Publicar menos, pero con más calidad, puede dar mejores resultados.'];
        } elseif ($primero['valor'] > 0 && $ultimo['valor'] / $primero['valor'] >= 1.15) {
            $rec[] = ['tono' => 'bien', 'icono' => 'ti-stack-2', 'html' => 'Publicar con más frecuencia no está desgastando a la audiencia: las semanas con <b>' . e(end($claves)) . '</b> publicaciones rinden igual o mejor. Puede mantener o aumentar el ritmo.'];
        }
    }

    $total = $a['interacciones'] ?: 1;
    $comentarios = $a['composicion']['Comentarios'] / $total * 100;
    $compartidos = $a['composicion']['Compartidos'] / $total * 100;
    if ($compartidos >= 12) {
        $rec[] = ['tono' => 'bien', 'icono' => 'ti-share-3', 'html' => 'El <b>' . number_format($compartidos, 0) . '%</b> de las interacciones son compartidos: la audiencia encuentra el contenido útil para otras personas. Mantenga el contenido práctico (datos, recomendaciones técnicas, invitaciones).'];
    }
    if ($comentarios < 5 && $a['interacciones'] >= 50) {
        $rec[] = ['tono' => 'mejorar', 'icono' => 'ti-message-question', 'html' => 'Solo el <b>' . number_format($comentarios, 1) . '%</b> de las interacciones son comentarios. Cierre las publicaciones con una pregunta o pida la opinión de la audiencia para generar conversación.'];
    }
    if ($a['alcance'] && $a['ctr'] < 1 && $a['clics'] > 0) {
        $rec[] = ['tono' => 'mejorar', 'icono' => 'ti-click', 'html' => 'Solo el ' . number_format($a['ctr'], 1) . '% de las personas alcanzadas hace clic. Ponga el enlace al principio y use una llamada a la acción clara («Descargue», «Inscríbase»).'];
    }

    $meses = array_values(array_filter($a['mes'], static fn($m) => $m['n'] > 0));
    if (count($meses) >= 4) {
        $reciente = array_slice($meses, -2);
        $anterior = array_slice($meses, -4, 2);
        $vr = array_sum(array_column($reciente, 'interacciones')) / max(1, $a['usa_tasa'] ? array_sum(array_column($reciente, 'base')) / 100 : array_sum(array_column($reciente, 'n')));
        $va = array_sum(array_column($anterior, 'interacciones')) / max(1, $a['usa_tasa'] ? array_sum(array_column($anterior, 'base')) / 100 : array_sum(array_column($anterior, 'n')));
        if ($va > 0 && abs($vr / $va - 1) >= 0.15) {
            $sube = $vr > $va;
            $rec[] = ['tono' => $sube ? 'bien' : 'mejorar', 'icono' => $sube ? 'ti-trending-up' : 'ti-trending-down', 'html' => 'En los últimos 2 meses con publicaciones, la ' . $metrica . ' <b>' . ($sube ? 'subió' : 'bajó') . ' ' . number_format(abs($vr / $va - 1) * 100, 0) . '%</b> respecto a los 2 meses anteriores' . ($sube ? '. Lo que está haciendo funciona.' : '. Revise qué cambió (formatos, temas o frecuencia).')];
        }
    }

    if (!$rec) {
        $rec[] = ['tono' => 'info', 'icono' => 'ti-scale', 'html' => 'Los resultados son parejos entre formatos, días y temas: ninguna diferencia supera el 15 %. Pruebe formatos nuevos (video corto, carrusel) para descubrir qué destaca.'];
    }
    return $rec;
}
