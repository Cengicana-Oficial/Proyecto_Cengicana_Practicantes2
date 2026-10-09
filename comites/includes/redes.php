<?php
declare(strict_types=1);

// Redes sociales y sitio web: plataformas, importacion de estadisticas
// exportadas (Meta Business Suite, LinkedIn, X, YouTube, TikTok) y registro
// mensual de Google Analytics 4. Sin conexion directa a APIs: los datos se
// capturan a mano o se importan desde el CSV/Excel que exporta cada servicio.

/** Plataformas disponibles: icono Tabler, color de marca y metricas visibles con su etiqueta. */
function redes_plataformas(): array
{
    $general = [
        'alcance' => 'Alcance', 'impresiones' => 'Impresiones', 'interacciones' => 'Interacciones',
        'reacciones' => 'Reacciones', 'comentarios' => 'Comentarios', 'compartidos' => 'Compartidos',
        'clics' => 'Clics', 'reproducciones' => 'Reproducciones', 'guardados' => 'Guardados',
    ];
    return [
        'Facebook' => ['icon' => 'ti-brand-facebook', 'color' => '#1877f2', 'metricas' => $general],
        'Instagram' => ['icon' => 'ti-brand-instagram', 'color' => '#d62976', 'metricas' => $general],
        'LinkedIn' => ['icon' => 'ti-brand-linkedin', 'color' => '#0a66c2', 'metricas' => array_diff_key($general, ['guardados' => 1])],
        'WhatsApp' => ['icon' => 'ti-brand-whatsapp', 'color' => '#25d366', 'metricas' => [
            'alcance' => 'Personas alcanzadas', 'compartidos' => 'Reenvíos', 'comentarios' => 'Respuestas',
            'clics' => 'Clics en el enlace', 'interacciones' => 'Interacciones totales',
        ]],
        'X' => ['icon' => 'ti-brand-x', 'color' => '#2a2e31', 'metricas' => array_diff_key($general, ['alcance' => 1])],
        'YouTube' => ['icon' => 'ti-brand-youtube', 'color' => '#ff0000', 'metricas' => $general],
        'TikTok' => ['icon' => 'ti-brand-tiktok', 'color' => '#2a2e31', 'metricas' => $general],
        'Sitio web' => ['icon' => 'ti-world', 'color' => '#73bc25', 'metricas' => ['alcance' => 'Usuarios', 'impresiones' => 'Vistas', 'clics' => 'Clics', 'interacciones' => 'Interacciones']],
        'Otro' => ['icon' => 'ti-share', 'color' => '#5f666c', 'metricas' => $general],
    ];
}

/** Plataformas que exportan estadisticas por publicacion en un archivo. */
function redes_plataformas_importables(): array
{
    return ['Facebook', 'Instagram', 'LinkedIn', 'X', 'YouTube', 'TikTok'];
}

/**
 * Lee un CSV/XLSX/XLS y devuelve sus filas como arreglos asociativos con
 * encabezados normalizados. Busca la fila de encabezados entre las primeras
 * 20 (los reportes de LinkedIn y Google Analytics traen lineas de titulo o
 * comentarios "#" antes de la tabla) eligiendo la que mas coincide con $alias.
 */
function redes_leer_tabla(array $file, array $alias): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Selecciona el archivo exportado (CSV, XLSX o XLS).');
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('El archivo supera 10 MB.');
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $rows = [];
    if ($ext === 'csv') {
        $contenido = (string) file_get_contents($file['tmp_name']);
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $lineas = preg_split('/\r\n|\n|\r/', $contenido);
        $muestra = implode("\n", array_slice(array_filter($lineas, static fn($l) => $l !== '' && $l[0] !== '#'), 0, 5));
        $delimitador = substr_count($muestra, ';') > substr_count($muestra, ',') ? ';' : (substr_count($muestra, "\t") > substr_count($muestra, ',') ? "\t" : ',');
        foreach ($lineas as $linea) {
            if (trim($linea) === '' || $linea[0] === '#') {
                continue;
            }
            $rows[] = str_getcsv($linea, $delimitador);
            if (count($rows) > 5001) {
                throw new RuntimeException('El archivo admite hasta 5000 filas.');
            }
        }
    } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
        require_once dirname(__DIR__, 2) . '/cengicursos/vendor/autoload.php';
        if ($ext === 'xlsx') {
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) {
                throw new RuntimeException('El Excel no es válido.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += $zip->statIndex($i)['size'];
            }
            $zip->close();
            if ($total > 30 * 1024 * 1024) {
                throw new RuntimeException('El Excel descomprimido supera 30 MB.');
            }
        }
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
        $reader->setReadDataOnly(true);
        $info = $reader->listWorksheetInfo($file['tmp_name']);
        if (($info[0]['totalRows'] ?? 0) > 5001) {
            throw new RuntimeException('El archivo admite hasta 5000 filas.');
        }
        // LinkedIn exporta varias hojas: se usa la que mas encabezados reconocidos tenga.
        $book = $reader->load($file['tmp_name']);
        $mejor = [];
        $mejorPuntaje = -1;
        foreach ($book->getAllSheets() as $sheet) {
            $hoja = $sheet->toArray(null, true, false, false);
            [$puntaje] = redes_detectar_encabezado($hoja, $alias);
            if ($puntaje > $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejor = $hoja;
            }
        }
        $book->disconnectWorksheets();
        $rows = $mejor;
    } else {
        throw new RuntimeException('Usa un archivo CSV, XLSX o XLS.');
    }

    [$puntaje, $indice] = redes_detectar_encabezado($rows, $alias);
    if ($puntaje < 2) {
        throw new RuntimeException('No se reconocieron las columnas del archivo. Verifica que sea la exportación de estadísticas.');
    }
    $encabezados = array_map(static fn($h) => normalize_text((string) $h), $rows[$indice]);
    $filas = [];
    foreach (array_slice($rows, $indice + 1) as $row) {
        $fila = [];
        foreach ($encabezados as $i => $h) {
            if ($h !== '') {
                $fila[$h] = trim((string) ($row[$i] ?? ''));
            }
        }
        if (implode('', $fila) !== '') {
            $filas[] = $fila;
        }
    }
    if (!$filas) {
        throw new RuntimeException('El archivo no tiene filas de datos.');
    }
    return $filas;
}

/** Devuelve [coincidencias, indice] de la fila que mas parece encabezado. */
function redes_detectar_encabezado(array $rows, array $alias): array
{
    $conocidos = [];
    foreach ($alias as $lista) {
        foreach ($lista as $a) {
            $conocidos[normalize_text($a)] = true;
        }
    }
    $mejor = [0, 0];
    foreach (array_slice($rows, 0, 20) as $i => $row) {
        $n = 0;
        foreach ((array) $row as $celda) {
            if (isset($conocidos[normalize_text((string) $celda)])) {
                $n++;
            }
        }
        if ($n > $mejor[0]) {
            $mejor = [$n, $i];
        }
    }
    return $mejor;
}

function redes_valor(array $fila, array $alias): string
{
    foreach ($alias as $a) {
        $k = normalize_text($a);
        if (isset($fila[$k]) && $fila[$k] !== '') {
            return $fila[$k];
        }
    }
    return '';
}

/** Convierte "1.234", "1,234", "12.5%" o "1 234" en numero. */
function redes_numero(string $valor): float
{
    $v = str_replace(["\u{00A0}", ' ', '%'], '', trim($valor));
    if ($v === '' || $v === '-' || $v === '--') {
        return 0.0;
    }
    if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $v)) {
        $v = str_replace(['.', ','], '', $v);
    } elseif (str_contains($v, ',') && !str_contains($v, '.')) {
        $v = str_replace(',', '.', $v);
    } else {
        $v = str_replace(',', '', $v);
    }
    return is_numeric($v) ? (float) $v : 0.0;
}

function redes_fecha(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    if (is_numeric($valor) && (float) $valor > 20000 && (float) $valor < 80000) {
        return gmdate('Y-m-d', (int) round(((float) $valor - 25569) * 86400)); // serial de Excel
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $valor, $m)) {
        // Meta exporta mm/dd/aaaa; si el primer numero no puede ser mes, es dd/mm/aaaa.
        [$a, $b] = (int) $m[1] > 12 ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];
        return checkdate($a, $b, (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $a, $b) : null;
    }
    $ts = strtotime($valor);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Alias de columnas en las exportaciones de Meta, LinkedIn, X, YouTube y TikTok (espanol e ingles). */
function redes_alias_publicaciones(): array
{
    return [
        'url' => ['Enlace permanente', 'Permalink', 'Post link', 'Enlace de la publicación', 'Enlace de la publicacion', 'Link', 'Enlace', 'URL', 'Tweet permalink', 'Video URL'],
        'titulo' => ['Título', 'Titulo', 'Post title', 'Descripción', 'Descripcion', 'Description', 'Texto de la publicación', 'Mensaje', 'Message', 'Tweet text', 'Video title', 'Título del video', 'Contenido', 'Content'],
        'fecha' => ['Hora de publicación', 'Hora de publicacion', 'Fecha de publicación', 'Fecha de publicacion', 'Publish time', 'Created date', 'Date', 'Fecha', 'Time', 'Video publish time', 'Posted'],
        'tipo' => ['Tipo de publicación', 'Tipo de publicacion', 'Post type', 'Content type', 'Tipo'],
        'alcance' => ['Alcance', 'Reach', 'Personas alcanzadas', 'Unique impressions', 'Impresiones únicas'],
        'impresiones' => ['Impresiones', 'Impressions', 'Visualizaciones', 'Views'],
        'interacciones' => ['Interacciones', 'Engagements', 'Interacción', 'Engagement', 'Interacciones con la publicación'],
        'reacciones' => ['Reacciones', 'Reactions', 'Me gusta', 'Likes', 'Me gusta y reacciones'],
        'comentarios' => ['Comentarios', 'Comments', 'Replies', 'Respuestas'],
        'compartidos' => ['Veces que se compartió', 'Veces que se compartio', 'Compartidos', 'Shares', 'Reposts', 'Retweets', 'Veces compartido'],
        'clics' => ['Clics', 'Clicks', 'Clics en el enlace', 'Link clicks', 'Total de clics', 'Clics totales'],
        'reproducciones' => ['Reproducciones', 'Video views', 'Reproducciones de video', 'Reproducciones de 3 segundos', 'Plays'],
        'guardados' => ['Guardados', 'Saves', 'Veces guardado'],
    ];
}

/** Importa estadisticas por publicacion: actualiza por enlace (o fecha + titulo) y crea las que no existan. */
function redes_importar_publicaciones(PDO $pdo, string $plataforma, array $file): string
{
    if (!in_array($plataforma, redes_plataformas_importables(), true)) {
        throw new RuntimeException('Selecciona la plataforma del archivo.');
    }
    $alias = redes_alias_publicaciones();
    $filas = redes_leer_tabla($file, $alias);
    $metricas = ['alcance', 'impresiones', 'interacciones', 'reacciones', 'comentarios', 'compartidos', 'clics', 'reproducciones', 'guardados'];
    $porUrl = $pdo->prepare('SELECT id FROM publicaciones WHERE url = ? LIMIT 1');
    $porTitulo = $pdo->prepare('SELECT id FROM publicaciones WHERE plataforma = ? AND fecha = ? AND titulo = ? LIMIT 1');
    $sets = implode(', ', array_map(static fn($m) => "$m = ?", $metricas));
    $actualizar = $pdo->prepare("UPDATE publicaciones SET $sets, origen = 'importado' WHERE id = ?");
    $insertar = $pdo->prepare(
        'INSERT INTO publicaciones (fecha, plataforma, tipo, titulo, estado, url, origen, ' . implode(', ', $metricas) . ')
         VALUES (?, ?, ?, ?, \'Publicada\', ?, \'importado\', ' . implode(', ', array_fill(0, count($metricas), '?')) . ')'
    );
    $nuevas = $actualizadas = $omitidas = 0;
    $pdo->beginTransaction();
    try {
        foreach ($filas as $fila) {
            $url = redes_valor($fila, $alias['url']);
            $url = preg_match('/^https?:\/\//i', $url) ? mb_substr($url, 0, 500) : '';
            $titulo = mb_substr(trim(preg_replace('/\s+/', ' ', redes_valor($fila, $alias['titulo']))), 0, 220);
            $fecha = redes_fecha(redes_valor($fila, $alias['fecha']));
            $valores = [];
            foreach ($metricas as $m) {
                $valores[] = (int) round(redes_numero(redes_valor($fila, $alias[$m])));
            }
            if ($url === '' && $titulo === '') {
                $omitidas++;
                continue;
            }
            if ($valores[2] === 0) {
                // Sin columna de interacciones: se suma lo que si viene.
                $valores[2] = $valores[3] + $valores[4] + $valores[5] + $valores[6] + $valores[8];
            }
            $id = false;
            if ($url !== '') {
                $porUrl->execute([$url]);
                $id = $porUrl->fetchColumn();
            }
            if (!$id && $fecha && $titulo !== '') {
                $porTitulo->execute([$plataforma, $fecha, $titulo]);
                $id = $porTitulo->fetchColumn();
            }
            if ($id) {
                $actualizar->execute([...$valores, (int) $id]);
                $actualizadas++;
            } else {
                $insertar->execute([$fecha, $plataforma, mb_substr(redes_valor($fila, $alias['tipo']), 0, 60) ?: null, $titulo !== '' ? $titulo : 'Publicación ' . ($fecha ?? ''), $url ?: null, ...$valores]);
                $nuevas++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return "Importación de $plataforma: $actualizadas publicación(es) actualizada(s), $nuevas nueva(s)" . ($omitidas ? ", $omitidas fila(s) sin enlace ni título omitida(s)" : '') . '.';
}

/** Alias de columnas del explorador / informes de Google Analytics 4 (espanol e ingles). */
function redes_alias_web(): array
{
    return [
        'periodo' => ['Año y mes', 'Ano y mes', 'Year month', 'Mes', 'Month', 'Fecha', 'Date', 'Nth month', 'Periodo'],
        'canal' => ['Grupo de canales predeterminado de la sesión', 'Grupo de canales predeterminado de la sesion', 'Session default channel group', 'Grupo de canales predeterminado', 'Default channel group', 'Grupo de canales principal de la sesión', 'Session primary channel group'],
        'fuente' => ['Fuente de la sesión', 'Fuente de la sesion', 'Session source', 'Fuente / medio de la sesión', 'Session source / medium'],
        'usuarios' => ['Usuarios activos', 'Active users', 'Usuarios', 'Users', 'Total de usuarios', 'Total users'],
        'nuevos_usuarios' => ['Usuarios nuevos', 'New users', 'Nuevos usuarios'],
        'sesiones' => ['Sesiones', 'Sessions'],
        'vistas' => ['Vistas', 'Views', 'Número de vistas', 'Numero de vistas', 'Páginas vistas', 'Paginas vistas', 'Pageviews', 'Screen page views'],
        'duracion' => ['Tiempo de interacción medio', 'Tiempo de interaccion medio', 'Duración media de la interacción', 'Duracion media de la interaccion', 'Average engagement time', 'Tiempo de interacción medio por sesión', 'Average engagement time per session', 'Duración media de la sesión', 'Average session duration'],
        'rebote' => ['Porcentaje de rebote', 'Tasa de rebote', 'Bounce rate'],
    ];
}

/** "202605", "2026-05", "20260514", "mayo de 2026" o fecha -> "2026-05". */
function redes_periodo(string $valor): ?string
{
    $valor = trim($valor);
    if (preg_match('/^(\d{4})(\d{2})(\d{2})?$/', $valor, $m) || preg_match('/^(\d{4})-(\d{2})/', $valor, $m)) {
        return ((int) $m[2] >= 1 && (int) $m[2] <= 12) ? $m[1] . '-' . $m[2] : null;
    }
    $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];
    $t = normalize_text($valor);
    foreach ($meses as $nombre => $n) {
        if (str_contains($t, $nombre) && preg_match('/(\d{4})/', $t, $y)) {
            return sprintf('%04d-%02d', $y[1], $n);
        }
    }
    $f = redes_fecha($valor);
    return $f ? substr($f, 0, 7) : null;
}

/** "1m 05s", "00:01:05" o segundos -> segundos. */
function redes_segundos(string $valor): int
{
    $v = trim($valor);
    if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $v, $m)) {
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }
    if (preg_match('/^(\d+):(\d{2})$/', $v, $m)) {
        return (int) $m[1] * 60 + (int) $m[2];
    }
    if (preg_match('/(?:(\d+)\s*m)?\s*(?:(\d+)\s*s)?/i', $v, $m) && ($m[1] ?? '') . ($m[2] ?? '') !== '') {
        return (int) ($m[1] ?? 0) * 60 + (int) ($m[2] ?? 0);
    }
    return (int) round(redes_numero($v));
}

/**
 * Importa el CSV/Excel de Google Analytics. Agrupa por mes; si el informe
 * trae el canal o la fuente de la sesion, separa las visitas que llegan
 * desde redes sociales y desde WhatsApp.
 */
function redes_importar_web(PDO $pdo, array $file): string
{
    $alias = redes_alias_web();
    $filas = redes_leer_tabla($file, $alias);
    $meses = [];
    foreach ($filas as $fila) {
        $periodo = redes_periodo(redes_valor($fila, $alias['periodo']));
        if (!$periodo) {
            continue;
        }
        $m = &$meses[$periodo];
        $m ??= ['usuarios' => 0, 'nuevos_usuarios' => 0, 'sesiones' => 0, 'vistas' => 0, 'duracion_pond' => 0.0, 'rebote_pond' => 0.0, 'peso' => 0, 'sesiones_redes' => 0, 'sesiones_whatsapp' => 0];
        $sesiones = (int) round(redes_numero(redes_valor($fila, $alias['sesiones'])));
        $usuarios = (int) round(redes_numero(redes_valor($fila, $alias['usuarios'])));
        $peso = max(1, $sesiones ?: $usuarios);
        $m['usuarios'] += $usuarios;
        $m['nuevos_usuarios'] += (int) round(redes_numero(redes_valor($fila, $alias['nuevos_usuarios'])));
        $m['sesiones'] += $sesiones;
        $m['vistas'] += (int) round(redes_numero(redes_valor($fila, $alias['vistas'])));
        $rebote = redes_numero(redes_valor($fila, $alias['rebote']));
        $m['duracion_pond'] += redes_segundos(redes_valor($fila, $alias['duracion'])) * $peso;
        $m['rebote_pond'] += ($rebote > 0 && $rebote <= 1 ? $rebote * 100 : $rebote) * $peso;
        $m['peso'] += $peso;
        $canal = normalize_text(redes_valor($fila, $alias['canal']) . ' ' . redes_valor($fila, $alias['fuente']));
        if (str_contains($canal, 'whatsapp') || str_contains($canal, 'wa.me')) {
            $m['sesiones_whatsapp'] += $sesiones;
        }
        if (str_contains($canal, 'social') || preg_match('/facebook|instagram|linkedin|twitter|t\.co|youtube|tiktok|whatsapp/', $canal)) {
            $m['sesiones_redes'] += $sesiones;
        }
        unset($m);
    }
    if (!$meses) {
        throw new RuntimeException('No se encontró la columna de fecha o mes. En Google Analytics agrega la dimensión "Año y mes" o "Fecha" al informe antes de exportarlo.');
    }
    $stmt = $pdo->prepare(
        "INSERT INTO sitio_web_metricas (periodo, usuarios, nuevos_usuarios, sesiones, vistas, duracion_seg, rebote, sesiones_redes, sesiones_whatsapp, fuente)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Importado de Google Analytics')
         ON DUPLICATE KEY UPDATE usuarios = VALUES(usuarios), nuevos_usuarios = VALUES(nuevos_usuarios), sesiones = VALUES(sesiones),
            vistas = VALUES(vistas), duracion_seg = VALUES(duracion_seg), rebote = VALUES(rebote), sesiones_redes = VALUES(sesiones_redes),
            sesiones_whatsapp = VALUES(sesiones_whatsapp), fuente = VALUES(fuente)"
    );
    foreach ($meses as $periodo => $m) {
        $stmt->execute([
            $periodo, $m['usuarios'], $m['nuevos_usuarios'], $m['sesiones'], $m['vistas'],
            $m['peso'] ? (int) round($m['duracion_pond'] / $m['peso']) : 0,
            $m['peso'] ? round($m['rebote_pond'] / $m['peso'], 2) : 0,
            $m['sesiones_redes'], $m['sesiones_whatsapp'],
        ]);
    }
    ksort($meses);
    return 'Google Analytics: ' . count($meses) . ' mes(es) importado(s) (' . array_key_first($meses) . ($meses && count($meses) > 1 ? ' a ' . array_key_last($meses) : '') . ').';
}

function redes_guardar_web(PDO $pdo, array $post): string
{
    $periodo = (string) ($post['periodo'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
        throw new RuntimeException('Selecciona el mes.');
    }
    $n = static fn(string $k): int => max(0, (int) ($post[$k] ?? 0));
    $duracion = max(0, (int) ($post['duracion_min'] ?? 0)) * 60 + max(0, (int) ($post['duracion_s'] ?? 0));
    $rebote = min(100, max(0, (float) str_replace(',', '.', (string) ($post['rebote'] ?? 0))));
    $notas = trim((string) ($post['notas'] ?? '')) ?: null;
    $pdo->prepare(
        "INSERT INTO sitio_web_metricas (periodo, usuarios, nuevos_usuarios, sesiones, vistas, duracion_seg, rebote, sesiones_redes, sesiones_whatsapp, notas, fuente)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Registro manual')
         ON DUPLICATE KEY UPDATE usuarios = VALUES(usuarios), nuevos_usuarios = VALUES(nuevos_usuarios), sesiones = VALUES(sesiones),
            vistas = VALUES(vistas), duracion_seg = VALUES(duracion_seg), rebote = VALUES(rebote), sesiones_redes = VALUES(sesiones_redes),
            sesiones_whatsapp = VALUES(sesiones_whatsapp), notas = VALUES(notas), fuente = VALUES(fuente)"
    )->execute([$periodo, $n('usuarios'), $n('nuevos_usuarios'), $n('sesiones'), $n('vistas'), $duracion, $rebote, $n('sesiones_redes'), $n('sesiones_whatsapp'), $notas]);
    return 'Métricas del sitio web de ' . $periodo . ' guardadas.';
}

function redes_duracion(int $segundos): string
{
    return intdiv($segundos, 60) . 'm ' . str_pad((string) ($segundos % 60), 2, '0', STR_PAD_LEFT) . 's';
}

function redes_formato(int|float $n): string
{
    return number_format((float) $n, 0, '.', ',');
}
