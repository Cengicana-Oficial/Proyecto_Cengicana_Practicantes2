<?php
declare(strict_types=1);

// Audiencia por pais, ciudad, edad y genero: seguidores de Instagram, Facebook y
// LinkedIn, y visitantes del sitio (Google Analytics). Se llena al sincronizar
// las conexiones o importando el archivo de audiencia de cada red.

const REDES_AUDIENCIA_DIMENSIONES = ['pais' => 'País', 'ciudad' => 'Ciudad', 'edad' => 'Edad', 'genero' => 'Género'];

/** Codigo ISO-2 => nombre en espanol (sin depender de la extension intl). */
function redes_paises(): array
{
    static $paises = null;
    if ($paises !== null) {
        return $paises;
    }
    $lista = 'AD:Andorra|AE:Emiratos Árabes Unidos|AF:Afganistán|AG:Antigua y Barbuda|AI:Anguila|AL:Albania|AM:Armenia|AO:Angola|AR:Argentina|AT:Austria|AU:Australia|AW:Aruba|AZ:Azerbaiyán'
        . '|BA:Bosnia y Herzegovina|BB:Barbados|BD:Bangladés|BE:Bélgica|BF:Burkina Faso|BG:Bulgaria|BH:Baréin|BI:Burundi|BJ:Benín|BM:Bermudas|BN:Brunéi|BO:Bolivia|BR:Brasil|BS:Bahamas|BT:Bután|BW:Botsuana|BY:Bielorrusia|BZ:Belice'
        . '|CA:Canadá|CD:República Democrática del Congo|CF:República Centroafricana|CG:Congo|CH:Suiza|CI:Costa de Marfil|CL:Chile|CM:Camerún|CN:China|CO:Colombia|CR:Costa Rica|CU:Cuba|CV:Cabo Verde|CW:Curazao|CY:Chipre|CZ:Chequia'
        . '|DE:Alemania|DJ:Yibuti|DK:Dinamarca|DM:Dominica|DO:República Dominicana|DZ:Argelia|EC:Ecuador|EE:Estonia|EG:Egipto|ER:Eritrea|ES:España|ET:Etiopía|FI:Finlandia|FJ:Fiyi|FR:Francia'
        . '|GA:Gabón|GB:Reino Unido|GD:Granada|GE:Georgia|GF:Guayana Francesa|GH:Ghana|GM:Gambia|GN:Guinea|GP:Guadalupe|GQ:Guinea Ecuatorial|GR:Grecia|GT:Guatemala|GU:Guam|GW:Guinea-Bisáu|GY:Guyana'
        . '|HK:Hong Kong|HN:Honduras|HR:Croacia|HT:Haití|HU:Hungría|ID:Indonesia|IE:Irlanda|IL:Israel|IN:India|IQ:Irak|IR:Irán|IS:Islandia|IT:Italia|JM:Jamaica|JO:Jordania|JP:Japón'
        . '|KE:Kenia|KG:Kirguistán|KH:Camboya|KN:San Cristóbal y Nieves|KR:Corea del Sur|KW:Kuwait|KY:Islas Caimán|KZ:Kazajistán|LA:Laos|LB:Líbano|LC:Santa Lucía|LI:Liechtenstein|LK:Sri Lanka|LR:Liberia|LS:Lesoto|LT:Lituania|LU:Luxemburgo|LV:Letonia|LY:Libia'
        . '|MA:Marruecos|MC:Mónaco|MD:Moldavia|ME:Montenegro|MG:Madagascar|MK:Macedonia del Norte|ML:Malí|MM:Myanmar|MN:Mongolia|MO:Macao|MQ:Martinica|MR:Mauritania|MT:Malta|MU:Mauricio|MV:Maldivas|MW:Malaui|MX:México|MY:Malasia|MZ:Mozambique'
        . '|NA:Namibia|NE:Níger|NG:Nigeria|NI:Nicaragua|NL:Países Bajos|NO:Noruega|NP:Nepal|NZ:Nueva Zelanda|OM:Omán|PA:Panamá|PE:Perú|PF:Polinesia Francesa|PG:Papúa Nueva Guinea|PH:Filipinas|PK:Pakistán|PL:Polonia|PR:Puerto Rico|PS:Palestina|PT:Portugal|PY:Paraguay'
        . '|QA:Catar|RE:Reunión|RO:Rumania|RS:Serbia|RU:Rusia|RW:Ruanda|SA:Arabia Saudita|SB:Islas Salomón|SC:Seychelles|SD:Sudán|SE:Suecia|SG:Singapur|SI:Eslovenia|SK:Eslovaquia|SL:Sierra Leona|SN:Senegal|SO:Somalia|SR:Surinam|SS:Sudán del Sur|SV:El Salvador|SX:Sint Maarten|SY:Siria|SZ:Esuatini'
        . '|TC:Islas Turcas y Caicos|TD:Chad|TG:Togo|TH:Tailandia|TJ:Tayikistán|TL:Timor Oriental|TM:Turkmenistán|TN:Túnez|TR:Turquía|TT:Trinidad y Tobago|TW:Taiwán|TZ:Tanzania|UA:Ucrania|UG:Uganda|US:Estados Unidos|UY:Uruguay|UZ:Uzbekistán'
        . '|VC:San Vicente y las Granadinas|VE:Venezuela|VG:Islas Vírgenes Británicas|VI:Islas Vírgenes de EE. UU.|VN:Vietnam|YE:Yemen|ZA:Sudáfrica|ZM:Zambia|ZW:Zimbabue';
    $paises = [];
    foreach (explode('|', $lista) as $par) {
        [$codigo, $nombre] = explode(':', $par, 2);
        $paises[$codigo] = $nombre;
    }
    return $paises;
}

/** Nombres en ingles de los paises mas frecuentes (exportaciones de Meta, LinkedIn y GA4 en ingles). */
const REDES_PAISES_INGLES = [
    'united states' => 'US', 'united states of america' => 'US', 'usa' => 'US', 'mexico' => 'MX', 'guatemala' => 'GT', 'el salvador' => 'SV',
    'honduras' => 'HN', 'nicaragua' => 'NI', 'costa rica' => 'CR', 'panama' => 'PA', 'belize' => 'BZ', 'colombia' => 'CO', 'brazil' => 'BR',
    'spain' => 'ES', 'germany' => 'DE', 'france' => 'FR', 'united kingdom' => 'GB', 'canada' => 'CA', 'peru' => 'PE', 'ecuador' => 'EC',
    'argentina' => 'AR', 'chile' => 'CL', 'dominican republic' => 'DO', 'cuba' => 'CU', 'puerto rico' => 'PR', 'venezuela' => 'VE',
    'bolivia' => 'BO', 'paraguay' => 'PY', 'uruguay' => 'UY', 'italy' => 'IT', 'netherlands' => 'NL', 'india' => 'IN', 'china' => 'CN',
    'japan' => 'JP', 'australia' => 'AU', 'south africa' => 'ZA', 'philippines' => 'PH', 'switzerland' => 'CH', 'belgium' => 'BE',
    'sweden' => 'SE', 'portugal' => 'PT', 'thailand' => 'TH', 'south korea' => 'KR', 'jamaica' => 'JM', 'haiti' => 'HT',
];

/** Resuelve un nombre o codigo de pais (espanol, ingles o ISO) a su codigo ISO-2; '' si no se reconoce. */
function redes_pais_codigo(string $valor): string
{
    static $porNombre = null;
    $valor = trim($valor);
    if (preg_match('/^[A-Za-z]{2}$/', $valor) && isset(redes_paises()[strtoupper($valor)])) {
        return strtoupper($valor);
    }
    if ($porNombre === null) {
        $porNombre = REDES_PAISES_INGLES;
        foreach (redes_paises() as $codigo => $nombre) {
            $porNombre[redes_sin_acentos($nombre)] = $codigo;
        }
    }
    // Meta exporta "Guatemala City, Guatemala" o "Guatemala (GT)": se prueba el texto completo y luego cada parte.
    $candidatos = array_merge([$valor], array_reverse(preg_split('/\s*[,(]\s*/', rtrim($valor, ')'))));
    foreach ($candidatos as $c) {
        $c = redes_sin_acentos(trim($c));
        if (isset($porNombre[$c])) {
            return $porNombre[$c];
        }
        if (preg_match('/^[a-z]{2}$/', $c) && isset(redes_paises()[strtoupper($c)])) {
            return strtoupper($c);
        }
    }
    return '';
}

/** Etiqueta visible de una clave segun la dimension (pais => nombre; ciudad "Ciudad|GT" => "Ciudad, Guatemala"). */
function redes_audiencia_etiqueta(string $dimension, string $clave, ?string $etiqueta = null): string
{
    if ($dimension === 'pais') {
        return redes_paises()[$clave] ?? ($etiqueta ?: $clave);
    }
    if ($dimension === 'ciudad' && str_contains($clave, '|')) {
        [$ciudad, $pais] = explode('|', $clave, 2);
        return $ciudad . ', ' . (redes_paises()[$pais] ?? $pais);
    }
    if ($dimension === 'genero') {
        return ['F' => 'Mujeres', 'M' => 'Hombres', 'U' => 'Sin especificar'][$clave] ?? ($etiqueta ?: $clave);
    }
    return $etiqueta ?: $clave;
}

/** Reemplaza la foto de una dimension para una plataforma y mes. $valores = [clave => valor]. */
function redes_audiencia_guardar(PDO $pdo, string $plataforma, string $dimension, string $metrica, string $periodo, array $valores, string $fuente, array $etiquetas = []): int
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM redes_audiencia WHERE plataforma = ? AND dimension = ? AND periodo = ?')->execute([$plataforma, $dimension, $periodo]);
        $stmt = $pdo->prepare('INSERT INTO redes_audiencia (plataforma, dimension, clave, etiqueta, valor, metrica, periodo, fuente) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $n = 0;
        foreach ($valores as $clave => $valor) {
            if ((int) $valor <= 0 || $clave === '') {
                continue;
            }
            $stmt->execute([$plataforma, $dimension, mb_substr((string) $clave, 0, 120), isset($etiquetas[$clave]) ? mb_substr($etiquetas[$clave], 0, 160) : null, (int) $valor, $metrica, $periodo, $fuente]);
            $n++;
        }
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Audiencia del periodo por plataforma y dimension.
 * Seguidores (fotos mensuales): se usa la foto mas reciente dentro del periodo.
 * Sitio web (totales mensuales): se suman los meses del periodo.
 * Devuelve [plataforma => ['metrica', 'periodo', 'total', 'dimensiones' => [dimension => [clave => ['etiqueta', 'valor', 'pct']]]]].
 */
function redes_audiencia_datos(PDO $pdo, array $periodo): array
{
    try {
        $params = [];
        $where = '';
        if ($periodo['desde'] !== null) {
            $where = ' WHERE periodo BETWEEN ? AND ?';
            $params = [$periodo['desde_mes'], $periodo['hasta_mes']];
        }
        $stmt = $pdo->prepare('SELECT * FROM redes_audiencia' . $where . ' ORDER BY periodo');
        $stmt->execute($params);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // La tabla se crea con deploy/mysql/migrar-esquema.php; sin ella no hay audiencia que mostrar.
        return [];
    }
    return redes_audiencia_agregar($filas, $periodo);
}

/** Agrupa filas de redes_audiencia (de la BD o de ejemplo) en la estructura que usan las vistas. */
function redes_audiencia_agregar(array $filas, array $periodo): array
{
    $ultimo = [];
    foreach ($filas as $f) {
        $ultimo[$f['plataforma']][$f['dimension']] = max($ultimo[$f['plataforma']][$f['dimension']] ?? '', $f['periodo']);
    }
    $res = [];
    foreach ($filas as $f) {
        $p = $f['plataforma'];
        $d = $f['dimension'];
        $acumula = $f['metrica'] !== 'seguidores';
        if (!$acumula && $f['periodo'] !== $ultimo[$p][$d]) {
            continue;
        }
        $res[$p]['metrica'] = $f['metrica'];
        $res[$p]['periodo'] = $ultimo[$p][$d];
        $res[$p]['fuente'] = $f['fuente'];
        $item = &$res[$p]['dimensiones'][$d][$f['clave']];
        $item ??= ['etiqueta' => redes_audiencia_etiqueta($d, $f['clave'], $f['etiqueta']), 'valor' => 0];
        $item['valor'] += (int) $f['valor'];
        unset($item);
    }
    foreach ($res as &$r) {
        foreach ($r['dimensiones'] as $d => &$items) {
            if ($d === 'edad') {
                ksort($items, SORT_NATURAL);
            } else {
                uasort($items, static fn($a, $b) => $b['valor'] <=> $a['valor']);
            }
            $total = array_sum(array_column($items, 'valor')) ?: 1;
            foreach ($items as &$i) {
                $i['pct'] = $i['valor'] / $total * 100;
            }
            unset($i);
            if ($d === 'pais') {
                $r['total'] = $total;
            }
        }
        unset($items);
        $r['dimensiones'] = array_replace(array_intersect_key(array_flip(array_keys(REDES_AUDIENCIA_DIMENSIONES)), $r['dimensiones']), $r['dimensiones']);
        $r['total'] ??= array_sum(array_column(reset($r['dimensiones']) ?: [], 'valor'));
        // Seguidores: foto del ultimo mes leido. Visitantes: suma de todo el periodo.
        $r['cuando'] = $r['metrica'] === 'seguidores' ? 'a ' . estadisticas_fecha_corta($r['periodo'] . '-01') : $periodo['etiqueta'];
    }
    unset($r);
    uksort($res, static fn($a, $b) => array_search($a, ['Sitio web', 'Facebook', 'Instagram', 'LinkedIn'], true) <=> array_search($b, ['Sitio web', 'Facebook', 'Instagram', 'LinkedIn'], true));
    return $res;
}

/**
 * Audiencia de ejemplo (no se guarda): muestra como se vera la pestana cuando las
 * redes esten conectadas. Valores ilustrativos con la forma de lo que entregan las APIs.
 */
function redes_audiencia_ejemplo(array $periodo): array
{
    $mes = $periodo['hasta_mes'] ?: date('Y-m');
    $filas = [];
    $agregar = static function (string $plataforma, string $dimension, string $metrica, array $valores) use (&$filas, $mes): void {
        foreach ($valores as $clave => $valor) {
            $filas[] = ['plataforma' => $plataforma, 'dimension' => $dimension, 'clave' => (string) $clave, 'etiqueta' => null, 'valor' => $valor, 'metrica' => $metrica, 'periodo' => $mes, 'fuente' => 'Ejemplo'];
        }
    };
    $agregar('Sitio web', 'pais', 'usuarios', ['GT' => 4820, 'US' => 610, 'MX' => 402, 'SV' => 388, 'HN' => 295, 'CO' => 160, 'CR' => 96, 'NI' => 84, 'ES' => 40]);
    $agregar('Sitio web', 'ciudad', 'usuarios', ['Guatemala City|GT' => 2310, 'Escuintla|GT' => 640, 'Santa Lucía Cotzumalguapa|GT' => 520, 'Mazatenango|GT' => 290, 'Retalhuleu|GT' => 210, 'Mixco|GT' => 180, 'Miami|US' => 95]);
    $agregar('Facebook', 'pais', 'seguidores', ['GT' => 18240, 'SV' => 1210, 'HN' => 860, 'MX' => 640, 'US' => 520, 'NI' => 310, 'CR' => 150, 'CO' => 120]);
    $agregar('Facebook', 'ciudad', 'seguidores', ['Guatemala City|GT' => 5780, 'Santa Lucía Cotzumalguapa|GT' => 2860, 'Escuintla|GT' => 1490, 'La Democracia|GT' => 540, 'Siquinalá|GT' => 540, 'San Marcos|GT' => 490, 'La Gomera|GT' => 420, 'Patulul|GT' => 345]);
    $agregar('Facebook', 'edad', 'seguidores', ['18-24' => 2750, '25-34' => 7820, '35-44' => 4950, '45-54' => 2300, '55-64' => 890, '65+' => 340]);
    $agregar('Facebook', 'genero', 'seguidores', ['M' => 12250, 'F' => 7070, 'U' => 330]);
    $agregar('Instagram', 'pais', 'seguidores', ['GT' => 2300, 'SV' => 210, 'MX' => 150, 'US' => 90, 'CO' => 40]);
    $agregar('Instagram', 'edad', 'seguidores', ['18-24' => 420, '25-34' => 1100, '35-44' => 760, '45-54' => 330, '55-64' => 110, '65+' => 40]);
    $agregar('Instagram', 'genero', 'seguidores', ['M' => 1640, 'F' => 1050, 'U' => 60]);
    $agregar('LinkedIn', 'pais', 'seguidores', ['GT' => 1180, 'CO' => 95, 'MX' => 60, 'NI' => 45, 'SV' => 40, 'US' => 35, 'BR' => 22]);
    return redes_audiencia_agregar($filas, $periodo);
}

/** Combina los paises de todas las plataformas (seguidores + visitantes) en un solo ranking. */
function redes_audiencia_paises_combinados(array $audiencia): array
{
    $paises = [];
    foreach ($audiencia as $plataforma => $a) {
        foreach ($a['dimensiones']['pais'] ?? [] as $clave => $i) {
            $paises[$clave]['etiqueta'] = $i['etiqueta'];
            $paises[$clave]['valor'] = ($paises[$clave]['valor'] ?? 0) + $i['valor'];
            $paises[$clave]['por'][$plataforma] = $i['valor'];
        }
    }
    uasort($paises, static fn($a, $b) => $b['valor'] <=> $a['valor']);
    $total = array_sum(array_column($paises, 'valor')) ?: 1;
    foreach ($paises as &$p) {
        $p['pct'] = $p['valor'] / $total * 100;
    }
    unset($p);
    return $paises;
}

/** Bandera (flag-icons) o insignia con el codigo si no es un pais ISO. */
function redes_bandera(string $codigo): string
{
    return isset(redes_paises()[$codigo])
        ? '<span class="fi fi-' . strtolower($codigo) . ' aud-flag" aria-hidden="true"></span>'
        : '<span class="aud-flag aud-flag-txt" aria-hidden="true"><i class="ti ti-world"></i></span>';
}

// ---------------------------------------------------------------- Importacion manual

function redes_alias_audiencia(): array
{
    return [
        'pais' => ['País', 'Pais', 'Country', 'País/región', 'Pais/region', 'Country/Region', 'Ubicación', 'Ubicacion', 'Location', 'Países principales', 'Top countries', 'Geography', 'Geografía'],
        'ciudad' => ['Ciudad', 'City', 'Ciudades principales', 'Top cities'],
        'edad' => ['Edad', 'Age', 'Rango de edad', 'Age range'],
        'genero' => ['Género', 'Genero', 'Gender', 'Sexo'],
        'valor' => ['Seguidores', 'Followers', 'Total followers', 'Seguidores totales', 'Usuarios', 'Users', 'Usuarios activos', 'Active users', 'Sesiones', 'Sessions', 'Alcance', 'Reach', 'Valor', 'Value', 'Cantidad', 'Count', 'Total'],
    ];
}

/** Importa un CSV/Excel de audiencia (una dimension por archivo) de cualquier red. */
function redes_importar_audiencia(PDO $pdo, array $post, array $file): string
{
    $plataforma = (string) ($post['plataforma'] ?? '');
    if (!in_array($plataforma, ['Facebook', 'Instagram', 'LinkedIn', 'X', 'YouTube', 'TikTok', 'WhatsApp', 'Sitio web'], true)) {
        throw new RuntimeException('Selecciona la plataforma del archivo.');
    }
    $periodo = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($post['periodo'] ?? '')) ? (string) $post['periodo'] : date('Y-m');
    $alias = redes_alias_audiencia();
    $filas = redes_leer_tabla($file, $alias);
    $dimension = '';
    foreach (['pais', 'ciudad', 'edad', 'genero'] as $d) {
        if ($filas && redes_valor($filas[0], $alias[$d]) !== '') {
            $dimension = $d;
            break;
        }
    }
    if ($dimension === '') {
        throw new RuntimeException('No se encontró una columna de País, Ciudad, Edad o Género en el archivo.');
    }
    $valores = [];
    $etiquetas = [];
    $sinReconocer = 0;
    foreach ($filas as $fila) {
        $texto = trim(redes_valor($fila, $alias[$dimension]));
        $valor = (int) round(redes_numero(redes_valor($fila, $alias['valor'])));
        if ($texto === '' || $valor <= 0) {
            continue;
        }
        $clave = $texto;
        if ($dimension === 'pais') {
            $clave = redes_pais_codigo($texto) ?: $texto;
            $sinReconocer += $clave === $texto ? 1 : 0;
        } elseif ($dimension === 'ciudad') {
            $partes = array_map('trim', explode(',', $texto));
            $pais = count($partes) > 1 ? redes_pais_codigo((string) end($partes)) : '';
            $clave = $pais !== '' ? $partes[0] . '|' . $pais : $texto;
        } elseif ($dimension === 'genero') {
            $g = redes_sin_acentos($texto);
            $clave = in_array($g, ['f', 'female', 'mujer', 'mujeres', 'femenino'], true) ? 'F' : (in_array($g, ['m', 'male', 'hombre', 'hombres', 'masculino'], true) ? 'M' : 'U');
        }
        $valores[$clave] = ($valores[$clave] ?? 0) + $valor;
        $etiquetas[$clave] = $texto;
    }
    if (!$valores) {
        throw new RuntimeException('El archivo no trae valores numéricos (seguidores, usuarios o alcance).');
    }
    $metrica = $plataforma === 'Sitio web' ? 'usuarios' : 'seguidores';
    $n = redes_audiencia_guardar($pdo, $plataforma, $dimension, $metrica, $periodo, $valores, 'Archivo importado', $etiquetas);
    return "Audiencia de $plataforma por " . mb_strtolower(REDES_AUDIENCIA_DIMENSIONES[$dimension]) . " importada: $n fila(s) para " . estadisticas_fecha_corta($periodo . '-01') . '.'
        . ($sinReconocer ? " $sinReconocer país(es) no se reconocieron y se guardaron con su nombre." : '');
}

// ---------------------------------------------------------------- Lectura por API

/** GA4: usuarios activos por pais y ciudad, mes a mes, de los ultimos 12 meses. */
function redes_ga4_audiencia(PDO $pdo, string $token, string $propiedad): string
{
    $rango = [['startDate' => date('Y-m-01', strtotime('first day of -12 months')), 'endDate' => 'today']];
    $paises = redes_ga4_reporte($token, $propiedad, [
        'dateRanges' => $rango, 'dimensions' => [['name' => 'yearMonth'], ['name' => 'countryId']],
        'metrics' => [['name' => 'activeUsers']], 'limit' => 10000,
    ]);
    $ciudades = redes_ga4_reporte($token, $propiedad, [
        'dateRanges' => $rango, 'dimensions' => [['name' => 'yearMonth'], ['name' => 'city'], ['name' => 'countryId']],
        'metrics' => [['name' => 'activeUsers']], 'limit' => 10000,
        'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
    ]);
    $porMes = [];
    foreach ($paises as [$d, $m]) {
        $mes = substr($d[0], 0, 4) . '-' . substr($d[0], 4, 2);
        $porMes['pais'][$mes][$d[1] !== '(not set)' ? $d[1] : 'ZZ'] = (int) $m[0];
    }
    foreach ($ciudades as [$d, $m]) {
        if ($d[1] === '(not set)') {
            continue;
        }
        $mes = substr($d[0], 0, 4) . '-' . substr($d[0], 4, 2);
        if (count($porMes['ciudad'][$mes] ?? []) < 50) {
            $porMes['ciudad'][$mes][$d[1] . '|' . $d[2]] = (int) $m[0];
        }
    }
    foreach ($porMes as $dimension => $meses) {
        foreach ($meses as $mes => $valores) {
            redes_audiencia_guardar($pdo, 'Sitio web', $dimension, 'usuarios', $mes, $valores, 'Google Analytics (API)', ['ZZ' => 'Sin determinar']);
        }
    }
    return ' Audiencia por país y ciudad actualizada.';
}

/** Instagram: seguidores por pais, ciudad, edad y genero (requiere 100 seguidores o mas). */
function redes_instagram_audiencia(PDO $pdo, string $version, string $ig, string $token): string
{
    $guardadas = [];
    foreach (['country' => 'pais', 'city' => 'ciudad', 'age' => 'edad', 'gender' => 'genero'] as $breakdown => $dimension) {
        try {
            $json = redes_meta_get($version, "$ig/insights", ['metric' => 'follower_demographics', 'period' => 'lifetime', 'metric_type' => 'total_value', 'breakdown' => $breakdown], $token);
        } catch (RuntimeException $e) {
            continue;
        }
        $valores = [];
        foreach ($json['data'][0]['total_value']['breakdowns'][0]['results'] ?? [] as $r) {
            $clave = (string) ($r['dimension_values'][0] ?? '');
            if ($dimension === 'ciudad') {
                // Instagram devuelve "Guatemala City, Guatemala".
                $partes = array_map('trim', explode(',', $clave));
                $pais = count($partes) > 1 ? redes_pais_codigo((string) end($partes)) : '';
                $clave = $pais !== '' ? $partes[0] . '|' . $pais : $clave;
            }
            $valores[$clave] = (int) ($r['value'] ?? 0);
        }
        if ($valores) {
            redes_audiencia_guardar($pdo, 'Instagram', $dimension, 'seguidores', date('Y-m'), $valores, 'Instagram (API)');
            $guardadas[] = REDES_AUDIENCIA_DIMENSIONES[$dimension];
        }
    }
    return $guardadas ? ' Audiencia de Instagram: ' . mb_strtolower(implode(', ', $guardadas)) . '.' : ' Instagram no entregó datos de audiencia (se necesitan 100 seguidores o más).';
}

/** Facebook: seguidores por pais y ciudad. Meta retiro estas metricas en algunas versiones: si no responden, se avisa. */
function redes_facebook_audiencia(PDO $pdo, string $version, string $pagina, string $token): string
{
    $guardadas = [];
    foreach (['page_fans_country' => 'pais', 'page_fans_city' => 'ciudad'] as $metrica => $dimension) {
        try {
            $json = redes_meta_get($version, "$pagina/insights", ['metric' => $metrica, 'period' => 'lifetime'], $token);
        } catch (RuntimeException $e) {
            continue;
        }
        $serie = $json['data'][0]['values'] ?? [];
        $datos = $serie ? end($serie) : [];
        $valores = [];
        foreach ((array) ($datos['value'] ?? []) as $clave => $v) {
            if ($dimension === 'ciudad') {
                $partes = array_map('trim', explode(',', (string) $clave));
                $pais = count($partes) > 1 ? redes_pais_codigo((string) end($partes)) : '';
                $clave = $pais !== '' ? $partes[0] . '|' . $pais : $clave;
            }
            $valores[$clave] = (int) $v;
        }
        if ($valores) {
            redes_audiencia_guardar($pdo, 'Facebook', $dimension, 'seguidores', date('Y-m'), $valores, 'Facebook (API)');
            $guardadas[] = REDES_AUDIENCIA_DIMENSIONES[$dimension];
        }
    }
    return $guardadas ? ' Audiencia de Facebook: ' . mb_strtolower(implode(', ', $guardadas)) . '.' : ' Facebook ya no entrega seguidores por país en esta versión de la API: importe el archivo de audiencia de Meta Business Suite.';
}

/** LinkedIn: seguidores por pais (urn:li:geo resueltos a nombre con la API de geo). */
function redes_linkedin_audiencia(PDO $pdo, string $org, string $token, string $version): string
{
    $json = redes_linkedin_get('https://api.linkedin.com/rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($org), $token, $version);
    $porGeo = [];
    foreach ($json['elements'][0]['followerCountsByGeoCountry'] ?? [] as $g) {
        $id = (string) preg_replace('/\D/', '', (string) ($g['geo'] ?? ''));
        $porGeo[$id] = (int) ($g['followerCounts']['organicFollowerCount'] ?? 0) + (int) ($g['followerCounts']['paidFollowerCount'] ?? 0);
    }
    if (!$porGeo) {
        return ' LinkedIn no entregó seguidores por país.';
    }
    $nombres = [];
    foreach (array_chunk(array_keys($porGeo), 50) as $lote) {
        try {
            $geo = redes_linkedin_get('https://api.linkedin.com/rest/geo?ids=List(' . implode(',', $lote) . ')', $token, $version);
            foreach ($geo['results'] ?? [] as $id => $g) {
                $nombres[(string) $id] = (string) ($g['defaultLocalizedName']['value'] ?? '');
            }
        } catch (RuntimeException $e) {
            // Sin nombres se guarda el id; la vista lo muestra tal cual.
        }
    }
    $valores = [];
    $etiquetas = [];
    foreach ($porGeo as $id => $v) {
        $nombre = $nombres[$id] ?? '';
        $clave = $nombre !== '' ? (redes_pais_codigo($nombre) ?: $nombre) : 'geo:' . $id;
        $valores[$clave] = ($valores[$clave] ?? 0) + $v;
        $etiquetas[$clave] = $nombre ?: 'Región ' . $id;
    }
    redes_audiencia_guardar($pdo, 'LinkedIn', 'pais', 'seguidores', date('Y-m'), $valores, 'LinkedIn (API)', $etiquetas);
    return ' Audiencia de LinkedIn por país actualizada.';
}
