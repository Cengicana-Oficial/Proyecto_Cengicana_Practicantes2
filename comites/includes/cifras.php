<?php
declare(strict_types=1);
require_once __DIR__ . '/marca.php';
require_once __DIR__ . '/pptx.php';
/**
 * Cifras institucionales: indicadores por año reunidos de todos los módulos (lectura directa en
 * la misma instancia MySQL, como la sincronización de eventos) y el registro de alianzas y
 * convenios, que no existía en ningún módulo. Se presenta como página, PDF y PowerPoint para las
 * reuniones con empresas e ingenios que podrían ser aliados.
 * Permisos: los de Estadísticas (ver = consultar; gestionar = administrar alianzas).
 */
const CIFRAS_TIPOS = [
    'ingenio' => 'Ingenio azucarero', 'empresa_tecnologica' => 'Empresa tecnológica', 'universidad' => 'Universidad',
    'centro_investigacion' => 'Centro de investigación', 'organismo' => 'Organismo o gremial', 'otro' => 'Otro',
];
const CIFRAS_ESTADOS = ['vigente' => 'Vigente', 'negociacion' => 'En negociación', 'finalizado' => 'Finalizado'];
const CIFRAS_CENTROAMERICA = ['guatemala', 'belice', 'el salvador', 'honduras', 'nicaragua', 'costa rica', 'panama'];
const CIFRAS_PAISES = ['Guatemala', 'El Salvador', 'Honduras', 'Nicaragua', 'Costa Rica', 'Panamá', 'Belice', 'México', 'República Dominicana',
    'Colombia', 'Ecuador', 'Perú', 'Brasil', 'Argentina', 'Estados Unidos', 'España', 'Australia', 'India', 'Sudáfrica'];

function cifras_preparar(PDO $pdo): void
{
    if (!defined('ESQUEMA_MIGRANDO')) return; // DDL solo al desplegar (deploy/mysql/migrar-esquema.php)
    $pdo->exec("CREATE TABLE IF NOT EXISTS alianzas (
      id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(200) NOT NULL, tipo VARCHAR(30) NOT NULL, pais VARCHAR(80) NOT NULL DEFAULT 'Guatemala',
      objeto TEXT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'vigente', fecha_inicio DATE NULL, fecha_fin DATE NULL,
      contacto VARCHAR(200) NULL, sitio_web VARCHAR(255) NULL, logo MEDIUMTEXT NULL,
      creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP, actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function cifras_es_centroamerica(string $pais): bool
{
    return in_array(normalize_text($pais), CIFRAS_CENTROAMERICA, true);
}

function cifras_alianzas(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT * FROM alianzas ORDER BY FIELD(estado,"vigente","negociacion","finalizado"), tipo, nombre')->fetchAll();
    } catch (PDOException $e) {
        return []; // la tabla se crea al desplegar
    }
}

/** Alta, edición y baja de alianzas. Devuelve el mensaje para el usuario. */
function cifras_alianza_accion(PDO $pdo, array $post, array $files): string
{
    $id = (int) ($post['id'] ?? 0);
    if (($post['accion'] ?? '') === 'alianza_eliminar') {
        $pdo->prepare('DELETE FROM alianzas WHERE id=?')->execute([$id]);
        return 'Alianza eliminada.';
    }
    $nombre = trim((string) ($post['nombre'] ?? ''));
    $tipo = (string) ($post['tipo'] ?? '');
    $estado = (string) ($post['estado'] ?? 'vigente');
    $pais = trim((string) ($post['pais'] ?? ''));
    if ($nombre === '' || mb_strlen($nombre) > 200) throw new RuntimeException('Escribe el nombre de la institución (hasta 200 caracteres).');
    if (!isset(CIFRAS_TIPOS[$tipo])) throw new RuntimeException('Elige el tipo de institución.');
    if (!isset(CIFRAS_ESTADOS[$estado])) throw new RuntimeException('Estado no válido.');
    if ($pais === '' || mb_strlen($pais) > 80) throw new RuntimeException('Indica el país.');
    $fechas = [];
    foreach (['fecha_inicio', 'fecha_fin'] as $campo) {
        $f = trim((string) ($post[$campo] ?? ''));
        if ($f !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) throw new RuntimeException('Fecha no válida.');
        $fechas[$campo] = $f ?: null;
    }
    if ($fechas['fecha_inicio'] && $fechas['fecha_fin'] && $fechas['fecha_fin'] < $fechas['fecha_inicio']) throw new RuntimeException('La fecha de fin es anterior a la de inicio.');
    $web = trim((string) ($post['sitio_web'] ?? ''));
    if ($web !== '' && !preg_match('~^https?://~i', $web)) $web = 'https://' . $web;
    if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) throw new RuntimeException('El sitio web no es válido.');
    $logo = null;
    if ($id) {
        $q = $pdo->prepare('SELECT logo FROM alianzas WHERE id=?');
        $q->execute([$id]);
        $logo = $q->fetchColumn() ?: null;
    }
    if (!empty($post['quitar_logo'])) $logo = null;
    if (($files['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $files['logo'];
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1024 * 1024) throw new RuntimeException('El logo debe pesar menos de 1 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        // Solo PNG o JPG: son los formatos que se pueden incrustar en el PDF y en PowerPoint.
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) throw new RuntimeException('Usa un logo PNG o JPG.');
        $logo = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($f['tmp_name']));
    }
    $v = [$nombre, $tipo, $pais, trim((string) ($post['objeto'] ?? '')) ?: null, $estado, $fechas['fecha_inicio'], $fechas['fecha_fin'],
        mb_substr(trim((string) ($post['contacto'] ?? '')), 0, 200) ?: null, $web ?: null, $logo];
    if ($id) {
        $pdo->prepare('UPDATE alianzas SET nombre=?,tipo=?,pais=?,objeto=?,estado=?,fecha_inicio=?,fecha_fin=?,contacto=?,sitio_web=?,logo=? WHERE id=?')->execute([...$v, $id]);
        return 'Alianza actualizada.';
    }
    $pdo->prepare('INSERT INTO alianzas (nombre,tipo,pais,objeto,estado,fecha_inicio,fecha_fin,contacto,sitio_web,logo) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($v);
    return 'Alianza registrada.';
}

/**
 * Configuración editable de la presentación, guardada en uploads/comites/cifras/presentacion.json
 * (volumen persistente, junto con las fotos subidas; no requiere tablas). Por diapositiva: foto y
 * encuadre, título, mensaje, cifras visibles, metas, cifras manuales, posición y si se oculta.
 * También guarda las diapositivas libres que crean los gestores (claves "p…") con su plantilla.
 */
const CIFRAS_DIAPOS = [ // clave => [foto predeterminada, encuadre vertical %]
    'portada' => ['cifras-campus.jpg', 45], 'evolucion' => ['cifras-canaveral.jpg', 40], 'impacto' => ['cifras-canaveral.jpg', 60],
    'capacitacion' => ['cifras-campus.jpg', 75], 'transferencia' => ['cifras-canaveral.jpg', 35], 'comites' => ['cifras-campus.jpg', 55],
    'visitas' => ['cifras-campus.jpg', 85], 'conocimiento' => ['cifras-campus.jpg', 30], 'comunicacion' => ['cifras-canaveral.jpg', 80],
    'alianzas' => ['cifras-campus.jpg', 60], 'cierre' => ['cifras-canaveral.jpg', 50],
];
const CIFRAS_EXTRAS_MAX = 3;
const CIFRAS_EXTRAS_MAX_LIBRE = 6;
const CIFRAS_DESTACADOS_DEF = ['impacto-atendidos', 'capacitacion-personas', 'transferencia-eventos', 'comunicacion-alcance'];
const CIFRAS_EVOLUCION_DEF = ['capacitacion-personas', 'capacitacion-cursos', 'transferencia-eventos', 'comites-reuniones', 'conocimiento-documentos', 'comunicacion-alcance'];
// Cifras que describen el estado actual (no un resultado del año): no se comparan con el año anterior.
const CIFRAS_SIN_COMPARAR = ['impacto-ingenios', 'impacto-region', 'impacto-extranjero', 'comites-activos', 'comites-contactos', 'conocimiento-ensayos',
    'alianzas-vigentes', 'alianzas-tecnologicas', 'alianzas-academia', 'alianzas-organismos'];
const CIFRAS_PLANTILLAS = [ // clave => [nombre, icono, descripción]
    'cifras' => ['Cifras', 'ti-numbers', 'Hasta 6 tarjetas con cifras que escribes tú.'],
    'texto' => ['Texto y foto', 'ti-align-left', 'Un logro, proyecto o mensaje con puntos clave sobre una foto.'],
    'grafico' => ['Gráfico', 'ti-chart-bar', 'Tus datos en barras, columnas o dona (puedes pegarlos desde Excel).'],
];
const CIFRAS_GRAFICOS = ['barras' => 'Barras horizontales', 'columnas' => 'Columnas', 'dona' => 'Dona (partes de un total)'];
const CIFRAS_COLORES = ['#73BC25' => 'Verde', '#A3D300' => 'Verde lima', '#FFCC00' => 'Amarillo', '#FF6B00' => 'Naranja'];

function cifras_fotos_dir(): string
{
    return dirname(__DIR__, 2) . '/uploads/comites/cifras';
}

function cifras_es_libre(string $clave): bool
{
    return (bool) preg_match('/^p[0-9a-z]{6,20}$/', $clave);
}

function cifras_config(): array
{
    $dir = cifras_fotos_dir();
    foreach (['presentacion.json', 'fotos.json'] as $f) { // fotos.json: versión anterior (solo fotos)
        if (is_file("$dir/$f")) {
            $d = json_decode((string) file_get_contents("$dir/$f"), true);
            return is_array($d) ? $d : [];
        }
    }
    return [];
}

/** clave => ['url' => absoluta para la página, 'ruta' => archivo en disco, 'pos' => 0-100, 'propia' => bool] */
function cifras_fotos(?array $conf = null): array
{
    // Rutas absolutas: un url() relativo dentro de una variable CSS se resolvería contra assets/css/.
    $app = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/comites/index.php')), '/');
    $raiz = rtrim(str_replace('\\', '/', dirname($app ?: '/')), '/');
    $conf ??= cifras_config();
    $claves = CIFRAS_DIAPOS;
    foreach ($conf as $clave => $c) if (cifras_es_libre((string) $clave)) $claves[$clave] = ['cifras-campus.jpg', 50];
    $out = [];
    foreach ($claves as $clave => [$archivo, $pos]) {
        $c = $conf[$clave] ?? [];
        $propio = isset($c['archivo']) && preg_match('/^[a-z0-9]+-\d+\.jpg$/', (string) $c['archivo']) && is_file(cifras_fotos_dir() . '/' . $c['archivo']);
        $ruta = $propio ? cifras_fotos_dir() . '/' . $c['archivo'] : dirname(__DIR__) . '/assets/img/' . $archivo;
        $out[$clave] = [
            'url' => $propio ? $raiz . '/uploads/comites/cifras/' . $c['archivo'] : $app . '/assets/img/' . $archivo . '?v=' . @filemtime($ruta),
            'ruta' => $ruta,
            'pos' => isset($c['pos']) ? max(0, min(100, (int) $c['pos'])) : $pos,
            'propia' => (bool) $propio,
        ];
    }
    return $out;
}

/** Valores de las cifras de los dos años anteriores (id => valor por año), para comparar y para la evolución. */
function cifras_historial(PDO $pdo, int $anio): array
{
    $h = [];
    foreach ([$anio - 2, $anio - 1] as $a) {
        if ($a < 2015) continue;
        foreach (cifras_datos($pdo, $a)['secciones'] as $s) foreach ($s['kpis'] as $k) $h[$a][$k['id']] = $k['valor'];
    }
    return $h;
}

/** Datos del año con la comparación de años anteriores y la configuración de la presentación aplicada. */
function cifras_presentacion(PDO $pdo, int $anio, bool $incluirOcultas = false): array
{
    return cifras_aplicar_config(cifras_datos($pdo, $anio) + ['historial' => cifras_historial($pdo, $anio)], $incluirOcultas);
}

/** Valor numérico de una cifra o null si no es comparable (sin datos, textos como "85 %"). */
function cifras_numero($v): ?float
{
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return (float) $v;
    $limpio = str_replace(',', '', (string) $v);
    return is_numeric($limpio) ? (float) $limpio : null;
}

/** Variación contra el año anterior: ['texto' => '▲ 25 % vs 2025', 'signo' => 1|0|-1] o null. */
function cifras_delta($actual, $anterior, int $anioAnterior): ?array
{
    $a = cifras_numero($actual);
    $p = cifras_numero($anterior);
    if ($a === null || $p === null || ($a == 0 && $p == 0)) return null;
    if ($p == 0) return ['texto' => '▲ nuevo frente a ' . $anioAnterior, 'signo' => 1];
    $pct = (int) round(($a - $p) / $p * 100);
    if ($pct === 0) return ['texto' => '= igual que ' . $anioAnterior, 'signo' => 0];
    return ['texto' => ($pct > 0 ? '▲ ' : '▼ ') . abs($pct) . ' % vs ' . $anioAnterior, 'signo' => $pct > 0 ? 1 : -1];
}

/**
 * Aplica la configuración a los datos del año (con 'historial' de cifras_historial si se quiere
 * comparar): diapositiva de evolución, diapositivas libres, títulos, mensajes, cifras visibles,
 * metas, cifras manuales, comparación con el año anterior, fotos, posición y ocultas. Con
 * $incluirOcultas (vista de gestores) las ocultas se marcan en lugar de quitarse.
 * Añade 'portada' y 'cierre' y conserva en cada sección 'kpis_todos' para el editor.
 */
function cifras_aplicar_config(array $d, bool $incluirOcultas = false): array
{
    $conf = cifras_config();
    $fotos = cifras_fotos($conf);
    $anio = (int) $d['anio'];
    $hist = $d['historial'] ?? [];
    $previo = $hist[$anio - 1] ?? null;
    $todos = [];
    foreach ($d['secciones'] as $s) foreach ($s['kpis'] as $k) $todos[$k['id']] = $k + ['seccion' => $s['titulo'], 'color' => $s['color']];

    // Crecimiento en tres años: minigráficos con las cifras elegidas.
    $anios = array_values(array_filter([$anio - 2, $anio - 1, $anio], static fn($a) => $a === $anio || isset($hist[$a])));
    $idsEvo = array_slice(array_values(array_filter((array) ($conf['evolucion']['kpis'] ?? CIFRAS_EVOLUCION_DEF), static fn($id) => isset($todos[$id]))), 0, 6);
    $series = [];
    foreach ($idsEvo as $id) {
        $valores = [];
        foreach ($anios as $a) $valores[$a] = $a === $anio ? cifras_numero($todos[$id]['valor']) : cifras_numero($hist[$a][$id] ?? null);
        $series[] = ['id' => $id, 'etiqueta' => $todos[$id]['etiqueta'], 'color' => $todos[$id]['color'], 'valores' => $valores,
            'delta' => $previo !== null ? cifras_delta($todos[$id]['valor'], $previo[$id] ?? null, $anio - 1) : null];
    }
    $d['secciones'] = ['evolucion' => ['titulo' => 'Crecimiento en ' . count($anios) . ' años', 'icono' => 'ti-trending-up', 'color' => '#A3D300',
        'tipo' => 'evolucion', 'kpis' => [], 'series' => $series, 'anios' => $anios, 'evolucion_ids' => $idsEvo]] + $d['secciones'];

    // Diapositivas libres creadas por los gestores.
    foreach ($conf as $clave => $c) {
        if (!cifras_es_libre((string) $clave) || !isset(CIFRAS_PLANTILLAS[$c['plantilla'] ?? ''])) continue;
        $pl = $c['plantilla'];
        $sec = ['titulo' => 'Nueva diapositiva', 'icono' => CIFRAS_PLANTILLAS[$pl][1], 'color' => isset(CIFRAS_COLORES[$c['color'] ?? '']) ? $c['color'] : '#73BC25',
            'kpis' => [], 'tipo' => 'libre', 'plantilla' => $pl, 'cuerpo' => array_values(array_filter(array_map('trim', explode("\n", (string) ($c['cuerpo'] ?? '')))))];
        if ($pl === 'grafico') {
            $g = (array) ($c['grafico'] ?? []);
            $gt = isset(CIFRAS_GRAFICOS[$g['tipo'] ?? '']) ? $g['tipo'] : 'barras';
            $items = array_map(static fn($f) => ['nombre' => (string) $f[0], 'valor' => $f[1]], array_filter((array) ($g['filas'] ?? []), 'is_array'));
            if ($gt === 'barras') $sec['barras'] = ['titulo' => (string) ($g['titulo'] ?? ''), 'items' => $items, 'etiqueta' => 'nombre', 'valor' => 'valor'];
            else $sec['grafico'] = ['tipo' => $gt, 'titulo' => (string) ($g['titulo'] ?? ''), 'items' => $items];
            $sec['grafico_conf'] = ['tipo' => $gt, 'titulo' => (string) ($g['titulo'] ?? ''), 'filas' => implode("\n", array_map(static fn($i) => $i['nombre'] . '; ' . $i['valor'], $items))];
        }
        $d['secciones'][$clave] = $sec;
    }

    foreach ($d['secciones'] as $clave => &$s) {
        $c = $conf[$clave] ?? [];
        $s['tipo'] ??= 'seccion';
        $s['kpis_todos'] = $s['kpis'];
        $ocultos = (array) ($c['kpis_ocultos'] ?? []);
        $metas = (array) ($c['metas'] ?? []);
        $s['kpis'] = array_values(array_filter($s['kpis'], static fn($k) => !in_array($k['id'], $ocultos, true)));
        foreach ($s['kpis'] as &$k) {
            if ($previo !== null && !in_array($k['id'], CIFRAS_SIN_COMPARAR, true)) $k['delta'] = cifras_delta($k['valor'], $previo[$k['id']] ?? null, $anio - 1);
            $meta = cifras_numero($metas[$k['id']] ?? null);
            $valor = cifras_numero($k['valor']);
            if ($meta && $meta > 0 && $valor !== null) $k += ['meta' => $meta, 'avance' => (int) round($valor / $meta * 100)];
        }
        unset($k);
        $max = $s['tipo'] === 'libre' ? CIFRAS_EXTRAS_MAX_LIBRE : CIFRAS_EXTRAS_MAX;
        $s['extras'] = array_slice(array_values(array_filter((array) ($c['extras'] ?? []), static fn($e) => is_array($e) && trim((string) ($e['valor'] ?? '')) !== '')), 0, $max);
        foreach ($s['extras'] as $i => $e) {
            $s['kpis'][] = ['id' => 'extra-' . $i, 'valor' => (string) $e['valor'], 'etiqueta' => (string) ($e['etiqueta'] ?? ''), 'detalle' => (string) ($e['detalle'] ?? ''), 'manual' => true];
        }
        $s['metas'] = $metas;
        $s['titulo_original'] = $s['titulo'];
        if (trim((string) ($c['titulo'] ?? '')) !== '') $s['titulo'] = (string) $c['titulo'];
        $s['mensaje'] = (string) ($c['mensaje'] ?? '');
        $s['oculta'] = !empty($c['oculta']);
        $s['despues'] = (string) ($c['despues'] ?? '');
        $s['foto'] = $fotos[$clave] ?? $fotos['portada'];
    }
    unset($s);

    // Posición elegida por el gestor: "despues" = clave de la diapositiva anterior ('_inicio' = primera).
    foreach (array_keys($d['secciones']) as $clave) {
        $destino = $d['secciones'][$clave]['despues'];
        if ($destino === '' || $destino === $clave || ($destino !== '_inicio' && !isset($d['secciones'][$destino]))) continue;
        $mover = [$clave => $d['secciones'][$clave]];
        unset($d['secciones'][$clave]);
        if ($destino === '_inicio') { $d['secciones'] = $mover + $d['secciones']; continue; }
        $nuevo = [];
        foreach ($d['secciones'] as $k => $v) { $nuevo[$k] = $v; if ($k === $destino) $nuevo += $mover; }
        $d['secciones'] = $nuevo;
    }
    if (!$incluirOcultas) $d['secciones'] = array_filter($d['secciones'], static fn($s) => !$s['oculta']);

    $cp = $conf['portada'] ?? [];
    $ids = array_values(array_filter((array) ($cp['destacados'] ?? CIFRAS_DESTACADOS_DEF), static fn($id) => isset($todos[$id])));
    $destacados = [];
    foreach (array_slice($ids, 0, 4) as $id) {
        $k = $todos[$id];
        if ($previo !== null && !in_array($id, CIFRAS_SIN_COMPARAR, true)) $k['delta'] = cifras_delta($k['valor'], $previo[$id] ?? null, $anio - 1);
        $destacados[] = $k;
    }
    $d['portada'] = [
        'titulo' => trim((string) ($cp['titulo'] ?? '')) ?: 'CENGICAÑA en cifras',
        'mensaje' => trim((string) ($cp['mensaje'] ?? '')) ?: 'Impacto, capacitación, transferencia de tecnología, comités técnicos, visitas, conocimiento, comunicación y alianzas al servicio de la agroindustria azucarera.',
        'titulo_personalizado' => trim((string) ($cp['titulo'] ?? '')), 'mensaje_personalizado' => trim((string) ($cp['mensaje'] ?? '')),
        'destacados' => $destacados, 'destacados_ids' => $ids, 'candidatos' => $todos, 'foto' => $fotos['portada'],
    ];
    $cc = $conf['cierre'] ?? [];
    $d['cierre'] = [
        'titulo' => trim((string) ($cc['titulo'] ?? '')) ?: 'Trabajemos juntos por la agroindustria azucarera',
        'mensaje' => trim((string) ($cc['mensaje'] ?? '')) ?: 'CENGICAÑA · Comités, Transferencia y Comunicación',
        'titulo_personalizado' => trim((string) ($cc['titulo'] ?? '')), 'mensaje_personalizado' => trim((string) ($cc['mensaje'] ?? '')),
        'oculta' => !empty($cc['oculta']), 'foto' => $fotos['cierre'],
    ];
    return $d;
}

/**
 * Crea, guarda o elimina una diapositiva (textos, cifras, metas, gráfico, foto, posición y
 * visibilidad). Devuelve [mensaje para el usuario, clave de la diapositiva para volver a ella].
 */
function cifras_diapositiva_accion(array $post, array $files): array
{
    $dir = cifras_fotos_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de la presentación en el servidor.');
    if (!is_writable($dir)) throw new RuntimeException('La carpeta de la presentación no tiene permisos de escritura en el servidor.');
    $conf = cifras_config();
    $clave = (string) ($post['clave'] ?? '');
    $id = static fn(string $v) => preg_match('/^[a-z0-9]+(-[a-z0-9]+)?$/', $v) === 1;

    if ($clave === 'nueva') {
        $pl = (string) ($post['plantilla'] ?? '');
        if (!isset(CIFRAS_PLANTILLAS[$pl])) throw new RuntimeException('Elige una plantilla para la nueva diapositiva.');
        $clave = 'p' . base_convert((string) time(), 10, 36) . bin2hex(random_bytes(2));
        $conf[$clave] = ['plantilla' => $pl, 'despues' => (string) ($post['despues'] ?? '') ?: 'alianzas'];
    } elseif (!isset(CIFRAS_DIAPOS[$clave]) && !(cifras_es_libre($clave) && isset($conf[$clave]['plantilla']))) {
        throw new RuntimeException('Diapositiva no válida.');
    }
    $libre = cifras_es_libre($clave);
    $actual = $conf[$clave] ?? [];
    $anterior = $actual['archivo'] ?? null;
    $borrarFoto = static function (?string $archivo) use ($dir): void {
        if ($archivo && preg_match('/^[a-z0-9]+-\d+\.jpg$/', $archivo)) @unlink($dir . '/' . $archivo);
    };
    $texto = static function (string $campo, int $max, bool $lineas = false) use ($post): string {
        $v = (string) ($post[$campo] ?? '');
        $v = $lineas ? trim(str_replace("\r", '', $v)) : trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if (mb_strlen($v) > $max) throw new RuntimeException("El texto es demasiado largo (máximo $max caracteres).");
        return $v;
    };

    if (!empty($post['eliminar']) && $libre) {
        $borrarFoto($anterior);
        foreach ($conf as $k => &$c) if (is_array($c) && ($c['despues'] ?? '') === $clave) $c['despues'] = (string) ($actual['despues'] ?? '');
        unset($c, $conf[$clave]);
        $msg = 'Diapositiva eliminada.';
        $clave = (string) ($actual['despues'] ?? '') ?: 'portada';
    } elseif (!empty($post['restaurar_todo']) && !$libre) {
        $borrarFoto($anterior);
        unset($conf[$clave]);
        $msg = 'La diapositiva volvió a su versión original.';
    } else {
        $nuevo = ['titulo' => $texto('titulo', 120), 'mensaje' => $texto('mensaje', 300), 'oculta' => !empty($post['oculta']) && $clave !== 'portada'];
        $nuevo['pos'] = max(0, min(100, (int) ($post['pos'] ?? (CIFRAS_DIAPOS[$clave][1] ?? 50))));
        $despues = (string) ($post['despues'] ?? '');
        $nuevo['despues'] = $despues === '' ? (string) ($actual['despues'] ?? '') : ($despues === '_original' ? '' : $despues);
        if ($nuevo['despues'] !== '' && $nuevo['despues'] !== '_inicio' && !$id($nuevo['despues'])) $nuevo['despues'] = '';

        $elegir = static function (string $campo, int $max, string $error) use ($post, $id): array {
            $ids = array_values(array_unique(array_map('strval', (array) ($post[$campo] ?? []))));
            if (count($ids) > $max) throw new RuntimeException($error);
            return array_values(array_filter($ids, $id));
        };
        if ($clave === 'portada') {
            $nuevo['destacados'] = $elegir('destacados', 4, 'Elige como máximo 4 cifras destacadas para la portada.');
        } elseif ($clave === 'evolucion') {
            $nuevo['kpis'] = $elegir('evolucion_kpis', 6, 'Elige como máximo 6 cifras para la diapositiva de crecimiento.');
        }
        if ($clave !== 'portada' && $clave !== 'cierre' && $clave !== 'evolucion') {
            $todos = array_map('strval', (array) ($post['kpis_todos'] ?? []));
            $visibles = array_map('strval', (array) ($post['kpis_visibles'] ?? []));
            $nuevo['kpis_ocultos'] = array_values(array_filter(array_diff($todos, $visibles), $id));
            $nuevo['metas'] = [];
            foreach ((array) ($post['meta'] ?? []) as $kid => $m) {
                $m = trim(str_replace(',', '', (string) $m));
                if ($m === '') continue;
                if (!$id((string) $kid) || !is_numeric($m) || (float) $m <= 0) throw new RuntimeException('Las metas deben ser números mayores que cero.');
                $nuevo['metas'][(string) $kid] = (float) $m;
            }
            $nuevo['extras'] = [];
            foreach (array_slice((array) ($post['extra_valor'] ?? []), 0, $libre ? CIFRAS_EXTRAS_MAX_LIBRE : CIFRAS_EXTRAS_MAX, true) as $i => $valor) {
                $e = ['valor' => trim((string) $valor), 'etiqueta' => trim((string) ($post['extra_etiqueta'][$i] ?? '')), 'detalle' => trim((string) ($post['extra_detalle'][$i] ?? ''))];
                if ($e['valor'] === '' && $e['etiqueta'] === '') continue;
                if ($e['valor'] === '' || $e['etiqueta'] === '') throw new RuntimeException('Cada cifra manual necesita valor y descripción.');
                if (mb_strlen($e['valor']) > 20 || mb_strlen($e['etiqueta']) > 80 || mb_strlen($e['detalle']) > 120) throw new RuntimeException('Cifra manual demasiado larga: valor hasta 20, descripción hasta 80 y detalle hasta 120 caracteres.');
                $nuevo['extras'][] = $e;
            }
        }
        if ($libre) {
            $nuevo['plantilla'] = $actual['plantilla'];
            $nuevo['color'] = isset(CIFRAS_COLORES[$post['color'] ?? '']) ? (string) $post['color'] : '#73BC25';
            if ($nuevo['titulo'] === '') $nuevo['titulo'] = $actual['titulo'] ?? CIFRAS_PLANTILLAS[$actual['plantilla']][0];
            $cuerpo = $texto('cuerpo', 1500, true);
            if (substr_count($cuerpo, "\n") >= 8) throw new RuntimeException('Usa como máximo 8 puntos en el texto.');
            $nuevo['cuerpo'] = $cuerpo;
            if ($actual['plantilla'] === 'grafico') {
                $filas = [];
                foreach (preg_split('/\n/', $texto('grafico_filas', 3000, true)) as $n => $linea) {
                    if (trim($linea) === '') continue;
                    // "Etiqueta; valor", "Etiqueta<TAB>valor" (pegado desde Excel) o "Etiqueta: valor".
                    if (!preg_match('/^(.+?)\s*[\t;:]\s*(-?[\d.,]+)\s*%?\s*$/u', trim($linea), $m)) throw new RuntimeException('No se entiende la fila ' . ($n + 1) . ' del gráfico. Escribe «Etiqueta; valor».');
                    $v = str_replace(',', '', $m[2]);
                    if (!is_numeric($v)) throw new RuntimeException('El valor de la fila ' . ($n + 1) . ' del gráfico no es un número.');
                    $filas[] = [mb_substr(trim($m[1]), 0, 60), $v + 0];
                }
                if (count($filas) > 12) throw new RuntimeException('El gráfico admite hasta 12 filas.');
                $tipoG = (string) ($post['grafico_tipo'] ?? 'barras');
                $nuevo['grafico'] = ['tipo' => isset(CIFRAS_GRAFICOS[$tipoG]) ? $tipoG : 'barras', 'titulo' => $texto('grafico_titulo', 80), 'filas' => $filas];
            }
        }
        $archivo = $anterior;
        if (!empty($post['restaurar_foto'])) {
            $borrarFoto($anterior);
            $archivo = null;
            $nuevo['pos'] = CIFRAS_DIAPOS[$clave][1] ?? 50;
        }
        $f = $files['foto'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) throw new RuntimeException('La foto no se pudo subir. Usa una imagen JPG o PNG de menos de 15 MB.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) throw new RuntimeException('Usa una foto JPG o PNG.');
            $dim = @getimagesize($f['tmp_name']);
            if (!$dim || $dim[0] < 800) throw new RuntimeException('La foto es muy pequeña: usa una de al menos 800 px de ancho.');
            if ($dim[0] * $dim[1] > 26_000_000) throw new RuntimeException('La foto es demasiado grande para procesarla. Redúcela a menos de 6000 px de ancho.');
            $src = $mime === 'image/png' ? @imagecreatefrompng($f['tmp_name']) : @imagecreatefromjpeg($f['tmp_name']);
            if (!$src) throw new RuntimeException('No se pudo leer la foto.');
            $w = min(1920, $dim[0]);
            $h = (int) round($dim[1] * $w / $dim[0]);
            $dst = imagecreatetruecolor($w, $h);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $dim[0], $dim[1]);
            $nombre = $clave . '-' . time() . '.jpg';
            if (!imagejpeg($dst, $dir . '/' . $nombre, 80)) throw new RuntimeException('No fue posible guardar la foto en el servidor.');
            imagedestroy($src);
            imagedestroy($dst);
            if ($archivo && $archivo !== $nombre) $borrarFoto($archivo);
            $archivo = $nombre;
        }
        if ($archivo) $nuevo['archivo'] = $archivo;
        $conf[$clave] = array_filter($nuevo, static fn($v) => $v !== '' && $v !== [] && $v !== false && $v !== null);
        $msg = ($post['clave'] ?? '') === 'nueva' ? 'Diapositiva creada.' : 'Diapositiva actualizada.';
    }
    if (file_put_contents($dir . '/presentacion.json', json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar la configuración de la presentación.');
    }
    @unlink($dir . '/fotos.json');
    return [$msg, $clave];
}

/**
 * Indicadores del año desde todos los módulos. Cada consulta es independiente: si un módulo no
 * está disponible su indicador queda en null ("sin datos") y el resto se calcula igual.
 */
function cifras_datos(PDO $pdo, int $anio): array
{
    $bd = static fn(string $env, string $def) => '`' . str_replace('`', '``', app_env_value($env, $def)) . '`';
    $cur = $bd('CENGICURSOS_DB_NAME', 'cengi_cursos');
    $exp = $bd('EXPERIMENTOS_DB_NAME', 'sigec');
    $lab = $bd('LABORATORIO_DB_NAME', 'laboratorios_prueba');
    $ini = "$anio-01-01";
    $fin = "$anio-12-31";
    $filas = static function (string $sql, array $p = []) use ($pdo): ?array {
        try { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchAll(); }
        catch (PDOException $e) { error_log('Cifras institucionales: ' . $e->getMessage()); return null; }
    };
    $valor = static function (string $sql, array $p = []) use ($filas): ?int {
        $r = $filas($sql, $p);
        return $r === null ? null : (int) (array_values($r[0] ?? [0])[0] ?? 0);
    };
    $alianzas = cifras_alianzas($pdo);
    $vigentes = array_values(array_filter($alianzas, static fn($a) => $a['estado'] === 'vigente'));

    // Impacto en la agroindustria.
    $ingeniosGt = $valor("SELECT COUNT(*) FROM {$cur}.ingenios WHERE tipo='Ingenio azucarero' AND nombre_ingenios NOT LIKE 'cengica%'");
    $porIngenio = $filas("SELECT nombre, COUNT(DISTINCT persona) personas FROM (
            SELECT i.nombre_ingenios nombre, CONCAT('p', p.id) persona FROM {$cur}.asignaciones a JOIN {$cur}.cursos c ON c.id=a.cursos_id
              JOIN {$cur}.participantes p ON p.id=a.participantes_id JOIN {$cur}.ingenios i ON i.id=p.ingenio_id WHERE c.inicio BETWEEN ? AND ?
            UNION ALL
            SELECT i.nombre_ingenios, CONCAT('e', ep.id) FROM {$cur}.evento_participantes ep JOIN {$cur}.eventos e ON e.id=ep.evento_id
              JOIN {$cur}.ingenios i ON i.id=ep.ingenio_id WHERE ep.ingreso_en IS NOT NULL AND e.fecha BETWEEN ? AND ?
        ) x WHERE nombre NOT LIKE 'cengica%' GROUP BY nombre ORDER BY personas DESC, nombre", [$ini, $fin, $ini, $fin]);
    $ensayos = $filas("SELECT i.nombre FROM {$exp}.ensayos e JOIN {$exp}.ingenios i ON i.id=e.ingenio_id WHERE e.estado IN ('En campo','Activo') OR e.fecha_inicio BETWEEN ? AND ?", [$ini, $fin]);
    $atendidos = [];
    foreach (array_merge(array_column($porIngenio ?? [], 'nombre'), array_column($ensayos ?? [], 'nombre')) as $n) $atendidos[normalize_text((string) $n)] = true;
    $ingeniosExtranjeros = array_filter($vigentes, static fn($a) => $a['tipo'] === 'ingenio' && normalize_text($a['pais']) !== 'guatemala');
    $ingeniosRegion = ($ingeniosGt ?? 0) + count(array_filter($ingeniosExtranjeros, static fn($a) => cifras_es_centroamerica($a['pais'])));
    $paises = array_unique(array_map(static fn($a) => $a['pais'], $vigentes));

    // Capacitación (Cengicursos).
    $cursos = $valor("SELECT COUNT(*) FROM {$cur}.cursos WHERE inicio BETWEEN ? AND ?", [$ini, $fin]);
    $capacitados = $valor("SELECT COUNT(DISTINCT a.participantes_id) FROM {$cur}.asignaciones a JOIN {$cur}.cursos c ON c.id=a.cursos_id WHERE c.inicio BETWEEN ? AND ?", [$ini, $fin]);
    $diplomas = $valor("SELECT COUNT(*) FROM {$cur}.diplomas WHERE emitido_en BETWEEN ? AND ?", [$ini, "$fin 23:59:59"]);
    $eventosCursos = $valor("SELECT COUNT(*) FROM {$cur}.eventos WHERE fecha BETWEEN ? AND ? AND estado<>'Cancelado'", [$ini, $fin]);
    $asistentesEventos = $valor("SELECT COUNT(*) FROM {$cur}.evento_participantes ep JOIN {$cur}.eventos e ON e.id=ep.evento_id WHERE ep.ingreso_en IS NOT NULL AND e.fecha BETWEEN ? AND ?", [$ini, $fin]);
    $diplomados = $filas("SELECT c.nombre_cursos nombre, COUNT(DISTINCT a.participantes_id) participantes FROM {$cur}.cursos c LEFT JOIN {$cur}.asignaciones a ON a.cursos_id=c.id
        WHERE c.nombre_cursos LIKE 'Diplomado%' AND c.inicio BETWEEN ? AND ? GROUP BY c.id ORDER BY c.inicio", [$ini, $fin]);

    // Eventos de transferencia (Comités → Agenda).
    $eventosTransf = $filas('SELECT COUNT(*) n, COALESCE(SUM(participantes),0) p FROM eventos_transferencia WHERE realizada=1 AND cancelada=0 AND fecha BETWEEN ? AND ?', [$ini, $fin]);
    $planTransf = $filas("SELECT COUNT(*) programados, COALESCE(SUM(realizada=1),0) realizados FROM eventos_transferencia WHERE cancelada=0 AND plan_mes LIKE ?", [$anio . '-%']);
    $encuestas = $filas('SELECT COUNT(*) n, AVG(q.calificacion) promedio FROM evento_encuestas q JOIN eventos_transferencia e ON e.id=q.evento_id WHERE q.oculto=0 AND q.calificacion>0 AND e.fecha BETWEEN ? AND ?', [$ini, $fin]);
    $eventosLista = $filas('SELECT nombre, participantes FROM eventos_transferencia WHERE realizada=1 AND cancelada=0 AND fecha BETWEEN ? AND ? ORDER BY fecha', [$ini, $fin]);
    $nEventos = (int) ($eventosTransf[0]['n'] ?? 0);
    $programados = (int) ($planTransf[0]['programados'] ?? 0);
    $cumplimiento = $planTransf === null ? null : ($programados ? round((int) $planTransf[0]['realizados'] / $programados * 100) . ' %' : '—');
    $satisfaccion = $encuestas === null ? null : ((int) $encuestas[0]['n'] ? number_format((float) $encuestas[0]['promedio'], 1) : '—');

    // Comités técnicos.
    $reuniones = $valor('SELECT COUNT(*) FROM reuniones WHERE realizada=1 AND fecha BETWEEN ? AND ?', [$ini, $fin]);
    $comites = $valor('SELECT COUNT(*) FROM comites WHERE activo=1');
    $acuerdos = $filas("SELECT COUNT(*) n, COALESCE(SUM(estado='Completado'),0) completados FROM acuerdos WHERE estado<>'Cancelado' AND fecha BETWEEN ? AND ?", [$ini, $fin]);
    $asistencias = $valor('SELECT COUNT(*) FROM reunion_asistentes ra JOIN reuniones r ON r.id=ra.reunion_id WHERE r.realizada=1 AND r.fecha BETWEEN ? AND ?', [$ini, $fin]);
    $porComite = $filas('SELECT c.nombre, COUNT(r.id) reuniones FROM comites c JOIN reuniones r ON r.comite_id=c.id AND r.realizada=1 AND r.fecha BETWEEN ? AND ? GROUP BY c.id ORDER BY reuniones DESC, c.nombre LIMIT 8', [$ini, $fin]);
    $contactos = $valor("SELECT COUNT(*) FROM contactos WHERE estado='Activo'");
    $tecnicos = $valor("SELECT COUNT(*) FROM contactos WHERE estado='Activo' AND nivel='Técnico'");
    $nAcuerdos = (int) ($acuerdos[0]['n'] ?? 0);

    // Visitas a CENGICAÑA (módulo de solicitudes de visita): solo solicitudes aprobadas.
    $vis = $bd('VISITAS_DB_NAME', 'cengi_visitas');
    $aprobada = "s.id_estado=(SELECT id_estado FROM {$vis}.estados WHERE nombre_estado='APROBADO')";
    $visitas = $filas("SELECT COUNT(*) n, COALESCE(SUM(s.cantidad_visitantes),0) personas, COUNT(DISTINCT so.nombre_institucion) instituciones
        FROM {$vis}.solicitudes s JOIN {$vis}.solicitantes so ON so.id_solicitante=s.id_solicitante WHERE $aprobada AND s.fecha_visita BETWEEN ? AND ?", [$ini, $fin]);
    $museo = $filas("SELECT COALESCE(SUM(m.cant_extranjeros+m.cant_adultos+m.cant_adultos_mayores+m.cant_estudiantes+m.cant_ninos),0) personas, COALESCE(SUM(m.cant_extranjeros),0) extranjeros
        FROM {$vis}.solicitud_museo m JOIN {$vis}.solicitudes s ON s.id_solicitud=m.id_solicitud WHERE $aprobada AND s.fecha_visita BETWEEN ? AND ?", [$ini, $fin]);
    $porNivel = $filas("SELECT n.nombre_nivel nombre, SUM(s.cantidad_visitantes) personas FROM {$vis}.solicitudes s JOIN {$vis}.niveles_academicos n ON n.id_nivel=s.id_nivel
        WHERE $aprobada AND s.fecha_visita BETWEEN ? AND ? GROUP BY n.id_nivel HAVING personas>0 ORDER BY personas DESC", [$ini, $fin]);

    // Generación de conocimiento.
    $docIngenios = $valor("SELECT COUNT(*) FROM documentos WHERE ambito='ingenios' AND estado IN ('Aprobado','Informativo','Publicado') AND COALESCE(fecha, DATE(creado_en)) BETWEEN ? AND ?", [$ini, $fin]);
    $docInternos = $valor("SELECT COUNT(*) FROM documentos WHERE ambito='interna' AND COALESCE(fecha, DATE(creado_en)) BETWEEN ? AND ?", [$ini, $fin]);
    $articulos = $valor('SELECT COUNT(*) FROM memorias_resultados WHERE COALESCE(fecha, DATE(creado_en)) BETWEEN ? AND ?', [$ini, $fin]);
    $informesLab = $valor("SELECT COUNT(*) FROM {$lab}.lab_informe WHERE estado='FIRMADO' AND fecha_firma BETWEEN ? AND ?", [$ini, "$fin 23:59:59"]);
    $muestras = $valor("SELECT COALESCE(SUM(numero_muestras),0) FROM {$lab}.solicitud WHERE fecha_ingreso BETWEEN ? AND ?", [$ini, "$fin 23:59:59"]);
    $ensayosCampo = $valor("SELECT COUNT(*) FROM {$exp}.ensayos WHERE estado='En campo'");
    $proyectos = $valor("SELECT COUNT(*) FROM {$exp}.proyectos WHERE estado='Activo'");

    // Comunicación y alcance (Redes y sitio web).
    $periodo = [$anio . '-01', $anio . '-12'];
    $cuenta = $filas('SELECT COALESCE(SUM(alcance_total),0) alcance, COALESCE(SUM(impresiones),0) impresiones FROM redes_cuenta_mensual WHERE periodo BETWEEN ? AND ?', $periodo);
    $posts = $filas('SELECT COUNT(*) n, COALESCE(SUM(alcance),0) alcance, COALESCE(SUM(impresiones),0) impresiones, COALESCE(SUM(reproducciones),0) reproducciones, COALESCE(SUM(interacciones),0) interacciones FROM publicaciones WHERE fecha BETWEEN ? AND ?', [$ini, $fin]);
    $seguidores = $valor('SELECT COALESCE(SUM(seguidores_total),0) FROM redes_cuenta_mensual r WHERE periodo=(SELECT MAX(periodo) FROM redes_cuenta_mensual r2 WHERE r2.plataforma=r.plataforma AND r2.periodo<=?)', [$periodo[1]]);
    $web = $filas('SELECT COALESCE(SUM(usuarios),0) usuarios, COALESCE(SUM(vistas),0) vistas FROM sitio_web_metricas WHERE periodo BETWEEN ? AND ?', $periodo);
    $alcance = max((int) ($cuenta[0]['alcance'] ?? 0), (int) ($posts[0]['alcance'] ?? 0));

    $k = static fn(string $id, $v, string $etiqueta, string $detalle = '') => ['id' => $id, 'valor' => $v, 'etiqueta' => $etiqueta, 'detalle' => $detalle];
    $cuentaTipo = static fn(string $tipo) => count(array_filter($vigentes, static fn($a) => $a['tipo'] === $tipo));
    return [
        'anio' => $anio,
        'generado' => date('d/m/Y H:i'),
        'secciones' => [
            'impacto' => ['titulo' => 'Impacto en la agroindustria', 'icono' => 'ti-building-factory-2', 'color' => '#73BC25', 'kpis' => [
                $k('impacto-ingenios', $ingeniosGt, 'Ingenios de Guatemala', 'trabajan con CENGICAÑA'),
                $k('impacto-atendidos', count($atendidos) ?: ($porIngenio === null ? null : 0), 'Ingenios atendidos en el año', 'en cursos, eventos o ensayos'),
                $k('impacto-region', $ingeniosRegion, 'Ingenios en Centroamérica', 'Guatemala y convenios en la región'),
                $k('impacto-extranjero', count($ingeniosExtranjeros), 'Convenios con ingenios del extranjero', count($paises) . ' países con alianzas'),
            ], 'barras' => ['titulo' => 'Personas capacitadas por ingenio', 'items' => array_slice($porIngenio ?? [], 0, 8), 'etiqueta' => 'nombre', 'valor' => 'personas']],
            'capacitacion' => ['titulo' => 'Capacitación', 'icono' => 'ti-school', 'color' => '#A3D300', 'kpis' => [
                $k('capacitacion-cursos', $cursos, 'Cursos impartidos'),
                $k('capacitacion-personas', $capacitados, 'Personas capacitadas', 'participantes únicos en cursos'),
                $k('capacitacion-diplomas', $diplomas, 'Diplomas emitidos'),
                $k('capacitacion-eventos', $asistentesEventos, 'Asistentes a eventos', ($eventosCursos ?? 0) . ' eventos técnicos'),
            ], 'barras' => ['titulo' => 'Diplomados del año', 'items' => $diplomados ?? [], 'etiqueta' => 'nombre', 'valor' => 'participantes']],
            'transferencia' => ['titulo' => 'Eventos de transferencia de tecnología', 'icono' => 'ti-presentation', 'color' => '#FFCC00', 'kpis' => [
                $k('transferencia-eventos', $eventosTransf === null ? null : $nEventos, 'Eventos realizados', 'días de campo, presentaciones y talleres'),
                $k('transferencia-participantes', $eventosTransf[0]['p'] ?? null, 'Participantes', $nEventos ? 'promedio de ' . round((int) $eventosTransf[0]['p'] / $nEventos) . ' por evento' : ''),
                $k('transferencia-plan', $cumplimiento, 'Cumplimiento del plan', $programados ? ((int) $planTransf[0]['realizados']) . ' de ' . $programados . ' eventos programados' : 'sin eventos programados'),
                $k('transferencia-satisfaccion', $satisfaccion, 'Satisfacción de participantes', $encuestas && (int) $encuestas[0]['n'] ? 'promedio sobre 5 · ' . (int) $encuestas[0]['n'] . ' respuestas' : 'sin encuestas aún'),
            ], 'barras' => ['titulo' => 'Participantes por evento', 'items' => array_slice($eventosLista ?? [], -8), 'etiqueta' => 'nombre', 'valor' => 'participantes']],
            'comites' => ['titulo' => 'Comités técnicos', 'icono' => 'ti-users-group', 'color' => '#FF6B00', 'kpis' => [
                $k('comites-activos', $comites, 'Comités técnicos activos', 'con ingenios y especialistas'),
                $k('comites-reuniones', $reuniones, 'Reuniones realizadas', $reuniones && $asistencias ? 'promedio de ' . round($asistencias / $reuniones) . ' asistentes' : ''),
                $k('comites-acuerdos', $acuerdos === null ? null : $nAcuerdos, 'Acuerdos tomados', $nAcuerdos ? round((int) $acuerdos[0]['completados'] / $nAcuerdos * 100) . ' % completados' : ''),
                $k('comites-contactos', $contactos, 'Contactos en el directorio', ($tecnicos ?? 0) . ' técnicos vinculados'),
            ], 'barras' => ['titulo' => 'Reuniones por comité', 'items' => $porComite ?? [], 'etiqueta' => 'nombre', 'valor' => 'reuniones']],
            'visitas' => ['titulo' => 'Visitas a CENGICAÑA', 'icono' => 'ti-map-pin', 'color' => '#73BC25', 'kpis' => [
                $k('visitas-visitas', $visitas[0]['n'] ?? null, 'Visitas atendidas', 'grupos de instituciones y empresas'),
                $k('visitas-personas', $visitas[0]['personas'] ?? null, 'Visitantes recibidos'),
                $k('visitas-instituciones', $visitas[0]['instituciones'] ?? null, 'Instituciones visitantes'),
                $k('visitas-museo', $museo[0]['personas'] ?? null, 'Visitantes al museo', ((int) ($museo[0]['extranjeros'] ?? 0)) . ' extranjeros'),
            ], 'barras' => ['titulo' => 'Visitantes por nivel académico', 'items' => $porNivel ?? [], 'etiqueta' => 'nombre', 'valor' => 'personas']],
            'conocimiento' => ['titulo' => 'Generación de conocimiento', 'icono' => 'ti-books', 'color' => '#A3D300', 'kpis' => [
                $k('conocimiento-documentos', ($docIngenios === null && $docInternos === null) ? null : ($docIngenios ?? 0) + ($articulos ?? 0), 'Documentos técnicos publicados', ($docIngenios ?? 0) . ' para ingenios · ' . ($articulos ?? 0) . ' artículos de memoria'),
                $k('conocimiento-laboratorio', $informesLab, 'Informes de laboratorio', number_format($muestras ?? 0) . ' muestras analizadas'),
                $k('conocimiento-ensayos', $ensayosCampo, 'Ensayos de investigación en campo', ($proyectos ?? 0) . ' proyectos activos'),
                $k('conocimiento-internos', $docInternos, 'Documentos internos generados'),
            ]],
            'comunicacion' => ['titulo' => 'Comunicación y alcance', 'icono' => 'ti-share', 'color' => '#FFCC00', 'kpis' => [
                $k('comunicacion-alcance', $cuenta === null && $posts === null ? null : $alcance, 'Personas alcanzadas en redes'),
                $k('comunicacion-videos', $posts[0]['reproducciones'] ?? null, 'Visualizaciones de videos', number_format((int) ($posts[0]['impresiones'] ?? 0)) . ' impresiones'),
                $k('comunicacion-seguidores', $seguidores, 'Seguidores en redes', 'técnicos y público agroindustrial'),
                $k('comunicacion-web', $web[0]['usuarios'] ?? null, 'Visitantes del sitio web', number_format((int) ($web[0]['vistas'] ?? 0)) . ' páginas vistas'),
            ]],
            'alianzas' => ['titulo' => 'Alianzas y convenios', 'icono' => 'ti-heart-handshake', 'color' => '#FF6B00', 'kpis' => [
                $k('alianzas-vigentes', count($vigentes), 'Alianzas vigentes', count($paises) . ' países'),
                $k('alianzas-tecnologicas', $cuentaTipo('empresa_tecnologica'), 'Empresas tecnológicas aliadas'),
                $k('alianzas-academia', $cuentaTipo('universidad') + $cuentaTipo('centro_investigacion'), 'Universidades y centros de investigación'),
                $k('alianzas-organismos', $cuentaTipo('organismo'), 'Organismos y gremiales'),
            ], 'lista' => $vigentes],
        ],
    ];
}

/** Número con separador de miles; los textos (cifras manuales, "85 %", "4.6") se muestran tal cual. */
function cifras_num($v): string
{
    if ($v === null) return '—';
    if (is_string($v) && (!is_numeric($v) || str_contains($v, '.'))) return $v;
    return number_format((float) $v, 0, '.', ',');
}

/** Informe en HTML (vista de impresión y PDF con Dompdf), con la marca CENGICAÑA. */
function cifras_reporte_html(array $d, bool $imprimir = false): string
{
    $h = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>CENGICAÑA en cifras ' . $d['anio'] . '</title><style>'
        . '@page{margin:16mm 14mm}body{font:11px "DejaVu Sans",Arial,sans-serif;color:' . MARCA_TEXTO . ';margin:0}@media screen{body{max-width:960px;margin:24px auto;padding:0 16px}}'
        . '.cab{width:100%;border-collapse:collapse}.cab td{vertical-align:middle;padding:0}h1{font-size:24px;color:' . MARCA_OSCURO . ';margin:0}.sub{color:#5F666C;margin:4px 0 0}'
        . 'h2{font-size:14px;color:' . MARCA_OSCURO . ';margin:20px 0 8px;padding-left:8px;border-left:5px solid;page-break-after:avoid}'
        . '.kpis{width:100%;border-collapse:separate;border-spacing:6px 0;margin:0 -6px}.kpis td{width:25%;border:1px solid ' . MARCA_GRIS . ';border-top:4px solid;border-radius:6px;padding:9px 10px;vertical-align:top}'
        . '.kpis b{display:block;font-size:20px;color:' . MARCA_OSCURO . '}.kpis span{display:block;font-weight:bold;font-size:10.5px;margin-top:2px}.kpis small{display:block;color:#5F666C;font-size:9.5px;margin-top:2px}'
        . '.barras{width:100%;border-collapse:collapse;margin-top:8px}.barras td{padding:2px 4px;font-size:10px}.barras .b{height:8px;border-radius:4px}'
        . '.ali{width:100%;border-collapse:collapse;margin-top:8px}.ali th{background:#EEF7E4;color:#2F5A0B;border-bottom:2px solid #73BC25;text-align:left;padding:5px;font-size:10px}.ali td{padding:5px;border-bottom:1px solid ' . MARCA_GRIS . ';vertical-align:middle}.ali img{max-height:26px;max-width:70px}'
        . '.d1{color:#2F7A0B!important;font-weight:bold}.d-1{color:#B54800!important;font-weight:bold}.d0{color:#5F666C}.meta{height:5px;background:#EEF0EE;border-radius:3px;margin-top:3px}.meta i{display:block;height:5px;border-radius:3px}'
        . '.muted{color:#5F666C}.foot{margin-top:18px;border-top:1px solid ' . MARCA_GRIS . ';padding-top:6px;text-align:right;color:#5F666C;font-size:9px}'
        . '.no-print{position:fixed;top:12px;right:12px}.no-print button{background:' . MARCA_OSCURO . ';color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:bold;cursor:pointer}@media print{.no-print{display:none}}'
        . '</style></head><body>' . ($imprimir ? '<div class="no-print"><button onclick="window.print()">Imprimir / guardar PDF</button></div>' : '')
        . '<table class="cab"><tr><td><h1>' . e($d['portada']['titulo'] ?? 'CENGICAÑA en cifras') . ' ' . $d['anio'] . '</h1><p class="sub">Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar · generado el ' . e($d['generado']) . '</p></td><td style="text-align:right">' . marca_logo(48) . '</td></tr></table>'
        . '<div style="margin:10px 0 4px">' . marca_franja() . '</div>';
    foreach ($d['secciones'] as $s) {
        $h .= '<h2 style="border-left-color:' . $s['color'] . '">' . e($s['titulo']) . '</h2>' . (($s['mensaje'] ?? '') !== '' ? '<p class="muted" style="margin:-4px 0 8px">' . e($s['mensaje']) . '</p>' : '');
        foreach (array_chunk($s['kpis'], 4) as $fila) {
            $h .= '<table class="kpis" style="margin-bottom:6px"><tr>';
            foreach ($fila as $k) {
                $h .= '<td style="border-top-color:' . $s['color'] . '"><b>' . e(cifras_num($k['valor'])) . '</b><span>' . e($k['etiqueta']) . '</span>' . ($k['detalle'] !== '' ? '<small>' . e($k['detalle']) . '</small>' : '')
                    . (!empty($k['delta']) ? '<small class="d' . $k['delta']['signo'] . '">' . e($k['delta']['texto']) . '</small>' : '')
                    . (isset($k['meta']) ? '<small>' . (int) $k['avance'] . ' % de la meta (' . e(cifras_num((string) $k['meta'])) . ')</small><div class="meta"><i style="width:' . min(100, (int) $k['avance']) . '%;background:' . $s['color'] . '"></i></div>' : '') . '</td>';
            }
            $h .= str_repeat('<td style="border:0"></td>', 4 - count($fila)) . '</tr></table>';
        }
        // Barras horizontales (también para columnas y dona en el informe impreso; la dona muestra el % del total).
        $grafico = !empty($s['barras']['items']) ? ['titulo' => $s['barras']['titulo'], 'items' => array_map(static fn($i) => ['nombre' => $i[$s['barras']['etiqueta']], 'valor' => $i[$s['barras']['valor']]], $s['barras']['items']), 'tipo' => 'barras']
            : (!empty($s['grafico']['items']) ? $s['grafico'] : null);
        if ($grafico) {
            $max = max(1, ...array_map(static fn($i) => (float) $i['valor'], $grafico['items']));
            $suma = array_sum(array_map(static fn($i) => (float) $i['valor'], $grafico['items'])) ?: 1;
            $h .= '<p class="muted" style="margin:10px 0 0;font-weight:bold">' . e($grafico['titulo']) . '</p><table class="barras">';
            foreach ($grafico['items'] as $i) {
                $h .= '<tr><td style="width:36%">' . e((string) $i['nombre']) . '</td><td><div class="b" style="width:' . max(2, round((float) $i['valor'] / $max * 100)) . '%;background:' . $s['color'] . '"></div></td><td style="width:70px;text-align:right"><b>' . e(cifras_num($i['valor']))
                    . '</b>' . ($grafico['tipo'] === 'dona' ? ' <span class="muted">(' . round((float) $i['valor'] / $suma * 100) . ' %)</span>' : '') . '</td></tr>';
            }
            $h .= '</table>';
        }
        if (!empty($s['cuerpo'])) $h .= '<ul style="margin:6px 0 0;padding-left:18px">' . implode('', array_map(static fn($l) => '<li style="margin-bottom:3px">' . e($l) . '</li>', $s['cuerpo'])) . '</ul>';
        if (($s['tipo'] ?? '') === 'evolucion' && $s['series']) {
            $h .= '<table class="ali"><tr><th>Cifra</th>' . implode('', array_map(static fn($a) => '<th style="text-align:right">' . $a . '</th>', $s['anios'])) . '<th>Variación</th></tr>';
            foreach ($s['series'] as $se) {
                $h .= '<tr><td><b>' . e($se['etiqueta']) . '</b></td>' . implode('', array_map(static fn($v) => '<td style="text-align:right">' . ($v === null ? '—' : number_format($v, 0, '.', ',')) . '</td>', $se['valores']))
                    . '<td class="d' . ($se['delta']['signo'] ?? 0) . '">' . e($se['delta']['texto'] ?? '—') . '</td></tr>';
            }
            $h .= '</table>';
        }
        if (isset($s['lista'])) {
            if ($s['lista']) {
                $h .= '<table class="ali"><tr><th style="width:80px"></th><th>Institución</th><th>Tipo</th><th>País</th><th>Colaboración</th></tr>';
                foreach ($s['lista'] as $a) $h .= '<tr><td>' . ($a['logo'] ? '<img src="' . e($a['logo']) . '" alt="">' : '') . '</td><td><b>' . e($a['nombre']) . '</b></td><td>' . e(CIFRAS_TIPOS[$a['tipo']] ?? $a['tipo']) . '</td><td>' . e($a['pais']) . '</td><td>' . e(mb_strimwidth((string) $a['objeto'], 0, 140, '…')) . '</td></tr>';
                $h .= '</table>';
            } else $h .= '<p class="muted">Aún no hay alianzas registradas.</p>';
        }
    }
    return $h . '<p class="muted" style="margin-top:14px;font-size:9.5px">Fuentes: Cengicursos (capacitación), Comités (eventos de transferencia, comités, directorio, biblioteca, memorias y redes), Visitas (solicitudes aprobadas), Laboratorio (informes y muestras) y Experimentos (ensayos). Las alianzas se registran en Comités → Cifras institucionales.</p>'
        . '<div class="foot">Comités, Transferencia y Comunicación · CENGICAÑA</div></body></html>';
}

/** Colores de los segmentos de la dona (paleta de la marca y tonos derivados). */
const CIFRAS_SERIE = ['#73BC25', '#FFCC00', '#FF6B00', '#A3D300', '#CED2D5', '#2F7A0B', '#FFE680', '#FFB27A', '#DCFA51', '#8B9298', '#1F5632', '#F5FAD9'];

/**
 * Presentación PowerPoint editable con el mismo diseño de la página: foto a sangre con velo verde,
 * tarjetas translúcidas con el color de cada sección, comparación con el año anterior, metas,
 * gráficos (barras, columnas, dona, evolución) y franja de la marca. Respeta la configuración de
 * cada diapositiva ($d viene de cifras_presentacion). Todo el texto es editable.
 */
function cifras_pptx(array $d): string
{
    $p = new ComitesPptx('CENGICAÑA en cifras ' . $d['anio']);
    $logoRuta = __DIR__ . '/../assets/img/cengicana-logo.png';
    $logo = is_file($logoRuta) ? (string) file_get_contents($logoRuta) : '';
    $f = 'Segoe UI';
    $t = static fn(int $s, float $x, float $y, float $w, float $h, string $txt, array $o = []) => $p->text($s, $x, $y, $w, $h, $txt, $o + ['font' => $f, 'color' => '#FFFFFF']);
    $total = 1 + count($d['secciones']) + (empty($d['cierre']['oculta']) ? 1 : 0);
    $num = 0;
    $colorDelta = static fn(?array $dl) => match ($dl['signo'] ?? 0) { 1 => '#BCF32E', -1 => '#FFB27A', default => '#DDE3D8' };

    $franja = static function (int $s, float $x, float $y, float $w) use ($p): void {
        $cx = $x;
        foreach ([[40, '#73BC25'], [25, '#A3D300'], [20, '#FFCC00'], [15, '#FF6B00']] as [$parte, $c]) {
            $p->rect($s, $cx, $y, $w * $parte / 100, 0.06, $c);
            $cx += $w * $parte / 100;
        }
    };
    // Fondo, velo, encabezado (logo + paginación) y franja inferior comunes a todas las diapositivas.
    $marco = static function (array $foto, array $velo, string $marca) use ($p, $t, $logo, $franja, &$num, $total): int {
        $s = $p->slide();
        $num++;
        $p->rect($s, 0, 0, 13.333, 7.5, '#04330F');
        $datos = is_file($foto['ruta']) ? (string) file_get_contents($foto['ruta']) : '';
        if ($datos !== '') $p->fondo($s, $datos, (int) $foto['pos']);
        $p->gradiente($s, 0, 0, 13.333, 7.5, $velo, 0);
        if ($logo) $p->image($s, 0.5, 0.3, 1.5, 0.5, $logo, 'l');
        if ($marca !== '') $t($s, 1.72, 0.47, 5, 0.36, $marca, ['size' => 11, 'bold' => true, 'opacidad' => 75]);
        $p->rect($s, 11.73, 0.36, 1.1, 0.36, '#FFFFFF', '#FFFFFF', true, ['opacidad' => 10, 'opacidad_linea' => 18, 'radio' => 50000]);
        $t($s, 11.73, 0.36, 1.1, 0.36, sprintf('%02d / %02d', $num, $total), ['size' => 10, 'bold' => true, 'align' => 'ctr', 'anchor' => 'ctr', 'opacidad' => 80]);
        $franja($s, 0.5, 7.08, 12.33);
        return $s;
    };
    $vidrio = static fn(int $s, float $x, float $y, float $w, float $h) => $p->rect($s, $x, $y, $w, $h, '#022D0E', '#ABEB3F', true, ['opacidad' => 58, 'opacidad_linea' => 30, 'radio' => 9000]);
    $panel = static fn(int $s, float $x, float $y, float $w, float $h) => $p->rect($s, $x, $y, $w, $h, '#FFFFFF', '#FFFFFF', true, ['opacidad' => 9, 'opacidad_linea' => 16, 'radio' => 5000]);
    // Tarjeta de cifra: valor, etiqueta, detalle, variación contra el año anterior y barra de meta.
    $tarjeta = static function (int $s, float $x, float $y, float $w, float $h, array $k, string $color, int $tam) use ($p, $t, $vidrio, $colorDelta): void {
        $vidrio($s, $x, $y, $w, $h);
        $p->rect($s, $x, $y + 0.12, 0.07, $h - 0.24, $color);
        $t($s, $x + 0.2, $y + 0.1, $w - 0.3, $tam / 72 * 1.25, cifras_num($k['valor']), ['size' => $tam, 'bold' => true]);
        $yy = $y + 0.1 + $tam / 72 * 1.25;
        $t($s, $x + 0.2, $yy, $w - 0.3, 0.32, $k['etiqueta'], ['size' => 12, 'bold' => true, 'color' => '#F1FFD9']);
        $yy += 0.3;
        $fondo = $y + $h - (isset($k['meta']) ? 0.42 : 0.08);
        if (($k['detalle'] ?? '') !== '' && $yy + 0.3 <= $fondo) { $t($s, $x + 0.2, $yy, $w - 0.3, 0.3, $k['detalle'], ['size' => 10, 'opacidad' => 68]); $yy += 0.27; }
        if (!empty($k['delta']) && $yy + 0.3 <= $fondo) $t($s, $x + 0.2, $yy, $w - 0.3, 0.3, $k['delta']['texto'], ['size' => 10, 'bold' => true, 'color' => $colorDelta($k['delta'])]);
        if (isset($k['meta'])) {
            $yb = $y + $h - 0.36;
            $p->rect($s, $x + 0.2, $yb + 0.05, $w - 1.55, 0.1, '#FFFFFF', null, true, ['opacidad' => 15, 'radio' => 50000]);
            $p->rect($s, $x + 0.2, $yb + 0.05, max(0.06, ($w - 1.55) * min(100, $k['avance']) / 100), 0.1, $color, null, true, ['radio' => 50000]);
            $t($s, $x + $w - 1.3, $yb - 0.06, 1.2, 0.3, $k['avance'] . ' % de meta', ['size' => 9, 'bold' => true, 'align' => 'r', 'opacidad' => 85]);
        }
    };
    $veloSeccion = [[0, '#042C0F', 93], [45, '#063E16', 80], [100, '#0A541E', 42]];

    // Portada.
    $po = $d['portada'];
    $s = $marco($po['foto'], [[0, '#042C0F', 92], [45, '#063E16', 72], [100, '#0A541E', 18]], '');
    $p->rect($s, 0.6, 1.25, 3.1, 0.38, '#BCF32E', '#BCF32E', true, ['opacidad' => 14, 'opacidad_linea' => 35, 'radio' => 50000]);
    $t($s, 0.6, 1.25, 3.1, 0.38, 'Informe institucional ' . $d['anio'], ['size' => 11, 'bold' => true, 'color' => '#E6FF9E', 'align' => 'ctr', 'anchor' => 'ctr']);
    $t($s, 0.55, 1.75, 9.5, 0.95, $po['titulo'], ['size' => 46, 'bold' => true, 'anchor' => 'b']);
    $t($s, 0.55, 2.62, 9.5, 0.9, (string) $d['anio'], ['size' => 46, 'bold' => true, 'color' => '#BCF32E']);
    $p->gradiente($s, 0.65, 3.6, 1.3, 0.07, [[0, '#A3D300', 100], [100, '#FFCC00', 100]], 0);
    $t($s, 0.6, 3.8, 7.6, 1.0, $po['mensaje'], ['size' => 14, 'opacidad' => 88]);
    $n = count($po['destacados']);
    foreach ($po['destacados'] as $i => $k) {
        $w = (12.33 - 0.2 * ($n - 1)) / max(1, $n);
        $tarjeta($s, 0.5 + $i * ($w + 0.2), 5.25, $w, 1.6, $k, $k['color'], 30);
    }

    foreach ($d['secciones'] as $sec) {
        $s = $marco($sec['foto'], $veloSeccion, 'en cifras ' . $d['anio']);
        $p->rect($s, 0.5, 1.0, 0.78, 0.78, $sec['color'], null, true, ['radio' => 22000]);
        $t($s, 0.5, 1.0, 0.78, 0.78, sprintf('%02d', $num), ['size' => 20, 'bold' => true, 'color' => '#0C3215', 'align' => 'ctr', 'anchor' => 'ctr']);
        $t($s, 1.45, 0.98, 10, 0.3, 'CAPÍTULO ' . sprintf('%02d', $num), ['size' => 10, 'bold' => true, 'color' => $sec['color'], 'espaciado' => 200]);
        $t($s, 1.45, 1.22, 11.3, 0.62, $sec['titulo'], ['size' => 28, 'bold' => true]);
        $y0 = 2.15;
        if ($sec['mensaje'] !== '') {
            $t($s, 0.5, 1.95, 12.3, 0.5, $sec['mensaje'], ['size' => 13, 'opacidad' => 86]);
            $y0 = 2.6;
        }
        $alto = 6.85 - $y0;

        // Crecimiento en tres años: una tarjeta con minicolumnas por cifra.
        if ($sec['tipo'] === 'evolucion') {
            $series = $sec['series'];
            $cols = count($series) <= 4 ? max(1, min(2, count($series))) : 3;
            $cols = count($series) === 3 ? 3 : $cols;
            $filas = max(1, (int) ceil(count($series) / $cols));
            $wc = (12.33 - 0.2 * ($cols - 1)) / $cols;
            $hc = min($filas === 1 ? 4.0 : 2.3, ($alto - 0.2 * ($filas - 1)) / $filas);
            foreach ($series as $i => $se) {
                $x = 0.5 + ($i % $cols) * ($wc + 0.2);
                $y = $y0 + intdiv($i, $cols) * ($hc + 0.2);
                $vidrio($s, $x, $y, $wc, $hc);
                $t($s, $x + 0.2, $y + 0.1, $wc - 0.4, 0.32, $se['etiqueta'], ['size' => 12, 'bold' => true, 'color' => '#F1FFD9']);
                if ($se['delta']) $t($s, $x + 0.2, $y + 0.38, $wc - 0.4, 0.28, $se['delta']['texto'], ['size' => 10, 'bold' => true, 'color' => $colorDelta($se['delta'])]);
                $max = max(1, ...array_map(static fn($v) => (float) $v, $se['valores']));
                $na = count($se['valores']);
                $slot = ($wc - 0.4) / $na;
                $base = $y + $hc - 0.35;
                $altoBarra = $hc - 1.2;
                $j = 0;
                foreach ($se['valores'] as $anioV => $v) {
                    $bx = $x + 0.2 + $j * $slot + $slot * 0.2;
                    $bh = $v === null ? 0 : max(0.04, $altoBarra * $v / $max);
                    $ultimo = $j === $na - 1;
                    if ($v !== null) $p->rect($s, $bx, $base - $bh, $slot * 0.6, $bh, $ultimo ? $se['color'] : '#FFFFFF', null, false, ['opacidad' => $ultimo ? 100 : 30]);
                    $t($s, $bx - 0.2, $base - $bh - 0.3, $slot * 0.6 + 0.4, 0.28, $v === null ? '—' : number_format($v, 0, '.', ','), ['size' => 10, 'bold' => true, 'align' => 'ctr']);
                    $t($s, $bx - 0.2, $base + 0.03, $slot * 0.6 + 0.4, 0.28, (string) $anioV, ['size' => 9, 'align' => 'ctr', 'opacidad' => 70]);
                    $j++;
                }
            }
            if (!$series) $t($s, 0.5, $y0, 12, 0.5, 'Elige las cifras a comparar en «Editar diapositiva».', ['size' => 13, 'opacidad' => 75]);
            continue;
        }

        $kpis = $sec['kpis'];
        $nk = count($kpis);
        $conBarras = !empty($sec['barras']['items']);
        $conGrafico = !empty($sec['grafico']['items']);
        $conTexto = !empty($sec['cuerpo']);
        $conPanel = $conBarras || $conGrafico || $conTexto || isset($sec['lista']);
        $px = $nk ? 6.85 : 0.5;
        $pw = $nk ? 5.98 : 12.33;
        if ($nk) {
            if ($conPanel) { $cols = $nk <= 1 ? 1 : 2; $anchoZona = 6.0; }
            else { $cols = $nk <= 4 ? $nk : 3; $anchoZona = 12.33; }
            $filas = max(1, (int) ceil($nk / $cols));
            $hk = min($conPanel ? 1.9 : 2.3, ($alto - 0.18 * ($filas - 1)) / $filas);
            $wk = ($anchoZona - 0.18 * ($cols - 1)) / $cols;
            $yk = $y0 + max(0, ($alto - ($filas * $hk + 0.18 * ($filas - 1))) / 2);
            $tam = $hk < 1.3 ? 24 : ($conPanel ? 32 : 38);
            foreach ($kpis as $i => $k) {
                $tarjeta($s, 0.5 + ($i % $cols) * ($wk + 0.18), $yk + intdiv($i, $cols) * ($hk + 0.18), $wk, $hk, $k, $sec['color'], $tam);
            }
        }
        if (!$conPanel) continue;
        $panel($s, $px, $y0, $pw, $alto);
        $titulo = static fn(string $txt) => $t($s, $px + 0.25, $y0 + 0.2, $pw - 0.5, 0.32, mb_strtoupper($txt), ['size' => 10, 'bold' => true, 'opacidad' => 72, 'espaciado' => 100]);

        if ($conTexto && !$conBarras && !$conGrafico) {
            // Plantilla de texto: puntos clave grandes.
            $tam = $nk ? 15 : 18;
            $paso = min(0.75, ($alto - 0.5) / count($sec['cuerpo']));
            foreach ($sec['cuerpo'] as $j => $linea) {
                $y = $y0 + 0.3 + $j * $paso;
                $p->rect($s, $px + 0.3, $y + 0.12, 0.12, 0.12, $sec['color'], null, true, ['radio' => 50000]);
                $t($s, $px + 0.55, $y, $pw - 0.8, $paso, $linea, ['size' => $tam]);
            }
        } elseif ($conBarras) {
            $b = $sec['barras'];
            $items = array_slice($b['items'], 0, 10);
            $max = max(1, ...array_map(static fn($i) => (float) $i[$b['valor']], $items));
            if ($b['titulo'] !== '') $titulo($b['titulo']);
            $paso = min(0.5, ($alto - 0.8) / max(1, count($items)));
            $wl = $pw * 0.38;
            $wb = $pw - $wl - 1.2;
            foreach ($items as $j => $i) {
                $y = $y0 + 0.7 + $j * $paso;
                $t($s, $px + 0.25, $y, $wl - 0.1, 0.32, mb_strimwidth((string) $i[$b['etiqueta']], 0, (int) ($wl * 14), '…'), ['size' => 10, 'opacidad' => 90]);
                $p->rect($s, $px + 0.25 + $wl, $y + 0.1, $wb, 0.14, '#FFFFFF', null, true, ['opacidad' => 13, 'radio' => 50000]);
                $p->rect($s, $px + 0.25 + $wl, $y + 0.1, max(0.08, $wb * (float) $i[$b['valor']] / $max), 0.14, $sec['color'], null, true, ['radio' => 50000]);
                $t($s, $px + $pw - 0.95, $y, 0.75, 0.32, cifras_num($i[$b['valor']]), ['size' => 11, 'bold' => true, 'align' => 'r']);
            }
        } elseif ($conGrafico && $sec['grafico']['tipo'] === 'columnas') {
            $g = $sec['grafico'];
            $items = array_slice($g['items'], 0, 12);
            if ($g['titulo'] !== '') $titulo($g['titulo']);
            $max = max(1, ...array_map(static fn($i) => (float) $i['valor'], $items));
            $slot = ($pw - 0.5) / count($items);
            $base = $y0 + $alto - 0.6;
            $altoMax = $alto - 1.5;
            foreach ($items as $j => $i) {
                $bx = $px + 0.25 + $j * $slot;
                $bh = max(0.04, $altoMax * (float) $i['valor'] / $max);
                $ancho = min($slot * 0.64, 1.3);
                $p->rect($s, $bx + ($slot - $ancho) / 2, $base - $bh, $ancho, $bh, $sec['color']);
                $t($s, $bx, $base - $bh - 0.3, $slot, 0.28, cifras_num($i['valor']), ['size' => 10, 'bold' => true, 'align' => 'ctr']);
                $t($s, $bx, $base + 0.04, $slot, 0.5, mb_strimwidth($i['nombre'], 0, max(6, (int) ($slot * 11)), '…'), ['size' => 9, 'align' => 'ctr', 'opacidad' => 85]);
            }
        } elseif ($conGrafico) {
            // Dona: sectores de anillo y leyenda con porcentaje.
            $g = $sec['grafico'];
            $items = array_slice($g['items'], 0, 8);
            if ($g['titulo'] !== '') $titulo($g['titulo']);
            $suma = array_sum(array_map(static fn($i) => max(0, (float) $i['valor']), $items)) ?: 1;
            $dd = min($alto - 0.9, $pw * 0.45);
            $dx = $px + 0.3;
            $dy = $y0 + 0.7 + ($alto - 0.9 - $dd) / 2;
            $ang = -90;
            foreach ($items as $j => $i) {
                $frac = max(0, (float) $i['valor']) / $suma;
                if ($frac <= 0) continue;
                $fin = $ang + min(359.9, $frac * 360);
                $p->arco($s, $dx, $dy, $dd, $ang, $fin, 22, CIFRAS_SERIE[$j % count(CIFRAS_SERIE)]);
                $ang = $fin;
            }
            $lx = $dx + $dd + 0.3;
            $paso = min(0.45, ($alto - 0.9) / max(1, count($items)));
            foreach ($items as $j => $i) {
                $y = $y0 + 0.75 + $j * $paso;
                $p->rect($s, $lx, $y + 0.09, 0.16, 0.16, CIFRAS_SERIE[$j % count(CIFRAS_SERIE)], null, true, ['radio' => 20000]);
                $t($s, $lx + 0.25, $y, $px + $pw - $lx - 1.25, 0.32, mb_strimwidth($i['nombre'], 0, 30, '…'), ['size' => 10, 'opacidad' => 90]);
                $t($s, $px + $pw - 1.05, $y, 0.85, 0.32, round(max(0, (float) $i['valor']) / $suma * 100) . ' %', ['size' => 11, 'bold' => true, 'align' => 'r']);
            }
        } else {
            $lista = array_slice($sec['lista'], 0, 9);
            $titulo('Aliados vigentes');
            if (!$lista) $t($s, $px + 0.25, $y0 + 0.65, $pw - 0.5, 0.8, 'Registre las alianzas en Comités → Cifras institucionales para mostrarlas aquí.', ['size' => 12, 'opacidad' => 75]);
            $wa = ($pw - 0.5 - 0.3) / 3;
            foreach ($lista as $j => $a) {
                $x = $px + 0.25 + ($j % 3) * ($wa + 0.15);
                $y = $y0 + 0.65 + intdiv($j, 3) * 1.2;
                $p->rect($s, $x, $y, $wa, 0.62, '#FFFFFF', null, true, ['radio' => 12000]);
                $conLogo = $a['logo'] && $p->image($s, $x + 0.1, $y + 0.06, $wa - 0.2, 0.5, (string) $a['logo']);
                if (!$conLogo) $t($s, $x, $y, $wa, 0.62, mb_strtoupper(mb_substr($a['nombre'], 0, 2)), ['size' => 16, 'bold' => true, 'color' => '#2F5A0B', 'align' => 'ctr', 'anchor' => 'ctr']);
                $t($s, $x, $y + 0.64, $wa, 0.42, $a['nombre'] . "\n" . $a['pais'], ['size' => 8, 'align' => 'ctr', 'opacidad' => 85]);
            }
        }
    }

    // Cierre.
    if (empty($d['cierre']['oculta'])) {
        $c = $d['cierre'];
        $s = $marco($c['foto'], [[0, '#042C0F', 90], [50, '#063E16', 82], [100, '#0A541E', 70]], '');
        $t($s, 0.8, 2.35, 11.73, 1.3, $c['titulo'], ['size' => 36, 'bold' => true, 'align' => 'ctr', 'anchor' => 'b']);
        $p->gradiente($s, 6.02, 3.8, 1.3, 0.07, [[0, '#A3D300', 100], [100, '#FFCC00', 100]], 0);
        $t($s, 0.8, 4.0, 11.73, 0.6, $c['mensaje'], ['size' => 16, 'color' => '#BCF32E', 'align' => 'ctr']);
        if ($logo) $p->image($s, 5.67, 5.0, 2.0, 1.1, $logo);
    }
    return $p->generar();
}

/** Descargas: html (imprimir), pdf o pptx, con la configuración de diapositivas aplicada. Termina la ejecución. */
function cifras_exportar(PDO $pdo, int $anio, string $formato): void
{
    $d = cifras_presentacion($pdo, $anio);
    $nombre = 'cengicana_en_cifras_' . $anio;
    if ($formato === 'pptx') {
        $contenido = cifras_pptx($d);
        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="' . $nombre . '.pptx"');
    } elseif ($formato === 'pdf') {
        require_once __DIR__ . '/directorio.php';
        require_once __DIR__ . '/directorio-exportar.php';
        $contenido = directorio_pdf(cifras_reporte_html($d), 'portrait');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombre . '.pdf"');
    } else {
        $contenido = cifras_reporte_html($d, true);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $contenido;
    exit;
}
