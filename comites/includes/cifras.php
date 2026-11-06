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

    // Transferencia de tecnología (Comités).
    $reuniones = $valor('SELECT COUNT(*) FROM reuniones WHERE realizada=1 AND fecha BETWEEN ? AND ?', [$ini, $fin]);
    $comites = $valor('SELECT COUNT(*) FROM comites WHERE activo=1');
    $eventosTransf = $filas('SELECT COUNT(*) n, COALESCE(SUM(participantes),0) p FROM eventos_transferencia WHERE realizada=1 AND cancelada=0 AND fecha BETWEEN ? AND ?', [$ini, $fin]);
    $contactos = $valor("SELECT COUNT(*) FROM contactos WHERE estado='Activo'");
    $tecnicos = $valor("SELECT COUNT(*) FROM contactos WHERE estado='Activo' AND nivel='Técnico'");

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

    $k = static fn($v, string $etiqueta, string $detalle = '') => ['valor' => $v, 'etiqueta' => $etiqueta, 'detalle' => $detalle];
    $cuentaTipo = static fn(string $tipo) => count(array_filter($vigentes, static fn($a) => $a['tipo'] === $tipo));
    return [
        'anio' => $anio,
        'generado' => date('d/m/Y H:i'),
        'secciones' => [
            'impacto' => ['titulo' => 'Impacto en la agroindustria', 'icono' => 'ti-building-factory-2', 'color' => '#73BC25', 'kpis' => [
                $k($ingeniosGt, 'Ingenios de Guatemala', 'trabajan con CENGICAÑA'),
                $k(count($atendidos) ?: ($porIngenio === null ? null : 0), 'Ingenios atendidos en el año', 'en cursos, eventos o ensayos'),
                $k($ingeniosRegion, 'Ingenios en Centroamérica', 'Guatemala y convenios en la región'),
                $k(count($ingeniosExtranjeros), 'Convenios con ingenios del extranjero', count($paises) . ' países con alianzas'),
            ], 'barras' => ['titulo' => 'Personas capacitadas por ingenio', 'items' => array_slice($porIngenio ?? [], 0, 8), 'etiqueta' => 'nombre', 'valor' => 'personas']],
            'capacitacion' => ['titulo' => 'Capacitación', 'icono' => 'ti-school', 'color' => '#A3D300', 'kpis' => [
                $k($cursos, 'Cursos impartidos'), $k($capacitados, 'Personas capacitadas', 'participantes únicos en cursos'),
                $k($diplomas, 'Diplomas emitidos'), $k($asistentesEventos, 'Asistentes a eventos', ($eventosCursos ?? 0) . ' eventos técnicos'),
            ], 'barras' => ['titulo' => 'Diplomados del año', 'items' => $diplomados ?? [], 'etiqueta' => 'nombre', 'valor' => 'participantes']],
            'transferencia' => ['titulo' => 'Transferencia de tecnología', 'icono' => 'ti-presentation', 'color' => '#FFCC00', 'kpis' => [
                $k($eventosTransf[0]['n'] ?? null, 'Eventos de transferencia', ((int) ($eventosTransf[0]['p'] ?? 0)) . ' participantes'),
                $k($reuniones, 'Reuniones de comités técnicos', ($comites ?? 0) . ' comités activos'),
                $k($contactos, 'Contactos en el directorio', 'personal de ingenios y aliados'),
                $k($tecnicos, 'Técnicos vinculados', 'reciben información técnica'),
            ]],
            'conocimiento' => ['titulo' => 'Generación de conocimiento', 'icono' => 'ti-books', 'color' => '#FF6B00', 'kpis' => [
                $k(($docIngenios === null && $docInternos === null) ? null : ($docIngenios ?? 0) + ($articulos ?? 0), 'Documentos técnicos publicados', ($docIngenios ?? 0) . ' para ingenios · ' . ($articulos ?? 0) . ' artículos de memoria'),
                $k($informesLab, 'Informes de laboratorio', number_format($muestras ?? 0) . ' muestras analizadas'),
                $k($ensayosCampo, 'Ensayos de investigación en campo', ($proyectos ?? 0) . ' proyectos activos'),
                $k($docInternos, 'Documentos internos generados'),
            ]],
            'comunicacion' => ['titulo' => 'Comunicación y alcance', 'icono' => 'ti-share', 'color' => '#73BC25', 'kpis' => [
                $k($cuenta === null && $posts === null ? null : $alcance, 'Personas alcanzadas en redes'),
                $k($posts[0]['reproducciones'] ?? null, 'Visualizaciones de videos', number_format((int) ($posts[0]['impresiones'] ?? 0)) . ' impresiones'),
                $k($seguidores, 'Seguidores en redes', 'técnicos y público agroindustrial'),
                $k($web[0]['usuarios'] ?? null, 'Visitantes del sitio web', number_format((int) ($web[0]['vistas'] ?? 0)) . ' páginas vistas'),
            ]],
            'alianzas' => ['titulo' => 'Alianzas y convenios', 'icono' => 'ti-heart-handshake', 'color' => '#A3D300', 'kpis' => [
                $k(count($vigentes), 'Alianzas vigentes', count($paises) . ' países'),
                $k($cuentaTipo('empresa_tecnologica'), 'Empresas tecnológicas aliadas'),
                $k($cuentaTipo('universidad') + $cuentaTipo('centro_investigacion'), 'Universidades y centros de investigación'),
                $k($cuentaTipo('organismo'), 'Organismos y gremiales'),
            ], 'lista' => $vigentes],
        ],
    ];
}

function cifras_num($v): string
{
    return $v === null ? '—' : number_format((float) $v, 0, '.', ',');
}

/** Informe en HTML (vista de impresión y PDF con Dompdf), con la marca CENGICAÑA. */
function cifras_reporte_html(array $d, bool $imprimir = false): string
{
    $h = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>CENGICAÑA en cifras ' . $d['anio'] . '</title><style>'
        . '@page{margin:16mm 14mm}body{font:11px "DejaVu Sans",Arial,sans-serif;color:' . MARCA_TEXTO . ';margin:0}@media screen{body{max-width:960px;margin:24px auto;padding:0 16px}}'
        . '.cab{width:100%;border-collapse:collapse}.cab td{vertical-align:middle;padding:0}h1{font-size:24px;color:' . MARCA_OSCURO . ';margin:0}.sub{color:#5F666C;margin:4px 0 0}'
        . 'h2{font-size:14px;color:' . MARCA_OSCURO . ';margin:20px 0 8px;padding-left:8px;border-left:5px solid;page-break-after:avoid}'
        . '.kpis{width:100%;border-collapse:separate;border-spacing:6px 0;margin:0 -6px}.kpis td{width:25%;border:1px solid ' . MARCA_GRIS . ';border-top:4px solid;border-radius:6px;padding:9px 10px;vertical-align:top}'
        . '.kpis b{display:block;font-size:20px;color:' . MARCA_OSCURO . '}.kpis span{display:block;font-weight:bold;font-size:10.5px;margin-top:2px}.kpis small{color:#5F666C;font-size:9.5px}'
        . '.barras{width:100%;border-collapse:collapse;margin-top:8px}.barras td{padding:2px 4px;font-size:10px}.barras .b{height:8px;border-radius:4px}'
        . '.ali{width:100%;border-collapse:collapse;margin-top:8px}.ali th{background:#EEF7E4;color:#2F5A0B;border-bottom:2px solid #73BC25;text-align:left;padding:5px;font-size:10px}.ali td{padding:5px;border-bottom:1px solid ' . MARCA_GRIS . ';vertical-align:middle}.ali img{max-height:26px;max-width:70px}'
        . '.muted{color:#5F666C}.foot{margin-top:18px;border-top:1px solid ' . MARCA_GRIS . ';padding-top:6px;text-align:right;color:#5F666C;font-size:9px}'
        . '.no-print{position:fixed;top:12px;right:12px}.no-print button{background:' . MARCA_OSCURO . ';color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:bold;cursor:pointer}@media print{.no-print{display:none}}'
        . '</style></head><body>' . ($imprimir ? '<div class="no-print"><button onclick="window.print()">Imprimir / guardar PDF</button></div>' : '')
        . '<table class="cab"><tr><td><h1>CENGICAÑA en cifras ' . $d['anio'] . '</h1><p class="sub">Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar · generado el ' . e($d['generado']) . '</p></td><td style="text-align:right">' . marca_logo(48) . '</td></tr></table>'
        . '<div style="margin:10px 0 4px">' . marca_franja() . '</div>';
    foreach ($d['secciones'] as $s) {
        $h .= '<h2 style="border-left-color:' . $s['color'] . '">' . e($s['titulo']) . '</h2><table class="kpis"><tr>';
        foreach ($s['kpis'] as $k) $h .= '<td style="border-top-color:' . $s['color'] . '"><b>' . cifras_num($k['valor']) . '</b><span>' . e($k['etiqueta']) . '</span>' . ($k['detalle'] !== '' ? '<small>' . e($k['detalle']) . '</small>' : '') . '</td>';
        $h .= '</tr></table>';
        if (!empty($s['barras']['items'])) {
            $b = $s['barras'];
            $max = max(1, ...array_map(static fn($i) => (int) $i[$b['valor']], $b['items']));
            $h .= '<p class="muted" style="margin:10px 0 0;font-weight:bold">' . e($b['titulo']) . '</p><table class="barras">';
            foreach ($b['items'] as $i) $h .= '<tr><td style="width:36%">' . e((string) $i[$b['etiqueta']]) . '</td><td><div class="b" style="width:' . max(2, round((int) $i[$b['valor']] / $max * 100)) . '%;background:' . $s['color'] . '"></div></td><td style="width:40px;text-align:right"><b>' . cifras_num($i[$b['valor']]) . '</b></td></tr>';
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
    return $h . '<p class="muted" style="margin-top:14px;font-size:9.5px">Fuentes: Cengicursos (capacitación), Comités (transferencia, directorio, biblioteca, memorias y redes), Laboratorio (informes y muestras) y Experimentos (ensayos). Las alianzas se registran en Comités → Cifras institucionales.</p>'
        . '<div class="foot">Comités, Transferencia y Comunicación · CENGICAÑA</div></body></html>';
}

/** Presentación PowerPoint editable: portada, una diapositiva por sección y cierre. */
function cifras_pptx(array $d): string
{
    $p = new ComitesPptx('CENGICAÑA en cifras ' . $d['anio']);
    $logoRuta = __DIR__ . '/../assets/img/cengicana-logo.png';
    $logo = is_file($logoRuta) ? (string) file_get_contents($logoRuta) : '';
    $franja = static function (int $s, float $y) use ($p): void {
        foreach ([[0, 5.33, '#73BC25'], [5.33, 3.33, '#A3D300'], [8.66, 2.67, '#FFCC00'], [11.33, 2.0, '#FF6B00']] as [$x, $w, $c]) $p->rect($s, $x, $y, $w, 0.09, $c);
    };

    $s = $p->slide();
    $p->rect($s, 0, 0, 13.333, 7.5, MARCA_OSCURO);
    $franja($s, 5.2);
    $p->text($s, 0.8, 1.6, 11.5, 1.3, 'CENGICAÑA en cifras', ['size' => 48, 'bold' => true, 'color' => '#FFFFFF']);
    $p->text($s, 0.8, 2.9, 11.5, 0.8, (string) $d['anio'], ['size' => 32, 'color' => '#A3D300', 'bold' => true]);
    $p->text($s, 0.8, 3.8, 11.5, 1.0, "Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar\nImpacto, capacitación, transferencia de tecnología y alianzas", ['size' => 16, 'color' => '#EEF7E4']);
    $p->rect($s, 0.8, 5.7, 3.0, 1.15, '#FFFFFF', null, true);
    if ($logo) $p->image($s, 0.95, 5.8, 2.7, 0.95, $logo);

    foreach ($d['secciones'] as $sec) {
        $s = $p->slide();
        $p->rect($s, 0, 0, 13.333, 7.5, '#FFFFFF');
        $p->rect($s, 0, 0, 0.18, 7.5, $sec['color']);
        $p->text($s, 0.6, 0.35, 10, 0.8, $sec['titulo'], ['size' => 30, 'bold' => true, 'color' => MARCA_OSCURO]);
        $p->text($s, 0.6, 1.05, 10, 0.4, 'CENGICAÑA · ' . $d['anio'], ['size' => 13, 'color' => '#5F666C']);
        if ($logo) $p->image($s, 10.9, 0.35, 1.9, 0.75, $logo, 'r');
        $franja($s, 1.55);
        foreach ($sec['kpis'] as $i => $k) {
            $x = 0.6 + $i * 3.08;
            $p->rect($s, $x, 1.95, 2.85, 2.05, '#F7F8F6', MARCA_GRIS, true);
            $p->rect($s, $x, 1.95, 2.85, 0.1, $sec['color']);
            $p->text($s, $x + 0.12, 2.15, 2.6, 0.85, cifras_num($k['valor']), ['size' => 34, 'bold' => true, 'color' => MARCA_OSCURO]);
            $p->text($s, $x + 0.12, 2.98, 2.6, 0.95, $k['etiqueta'] . ($k['detalle'] !== '' ? "\n" . $k['detalle'] : ''), ['size' => 12, 'color' => '#3D4247']);
        }
        if (!empty($sec['barras']['items'])) {
            $b = $sec['barras'];
            $items = array_slice($b['items'], 0, 6);
            $max = max(1, ...array_map(static fn($i) => (int) $i[$b['valor']], $items));
            $p->text($s, 0.6, 4.3, 12, 0.4, $b['titulo'], ['size' => 14, 'bold' => true, 'color' => MARCA_OSCURO]);
            foreach ($items as $j => $i) {
                $y = 4.8 + $j * 0.42;
                $p->text($s, 0.6, $y - 0.05, 4.2, 0.4, (string) $i[$b['etiqueta']], ['size' => 11, 'color' => '#3D4247']);
                $p->rect($s, 4.9, $y + 0.06, 6.8, 0.22, '#EEF0EE', null, true);
                $p->rect($s, 4.9, $y + 0.06, max(0.08, 6.8 * (int) $i[$b['valor']] / $max), 0.22, $sec['color'], null, true);
                $p->text($s, 11.85, $y - 0.05, 0.9, 0.4, cifras_num($i[$b['valor']]), ['size' => 12, 'bold' => true, 'color' => MARCA_OSCURO, 'align' => 'r']);
            }
        }
        if (isset($sec['lista'])) {
            $lista = array_slice($sec['lista'], 0, 10);
            if (!$lista) $p->text($s, 0.6, 4.4, 12, 0.5, 'Registre las alianzas en Comités → Cifras institucionales para mostrarlas aquí.', ['size' => 13, 'color' => '#5F666C']);
            foreach ($lista as $j => $a) {
                $x = 0.6 + ($j % 5) * 2.48;
                $y = 4.3 + intdiv($j, 5) * 1.5;
                $p->rect($s, $x, $y, 2.3, 1.35, '#FFFFFF', MARCA_GRIS, true);
                $conLogo = $a['logo'] && $p->image($s, $x + 0.15, $y + 0.1, 2.0, 0.7, (string) $a['logo']);
                $p->text($s, $x + 0.08, $y + ($conLogo ? 0.82 : 0.2), 2.14, $conLogo ? 0.5 : 1.05, $a['nombre'] . "\n" . $a['pais'], ['size' => $conLogo ? 9 : 11, 'color' => '#3D4247', 'align' => 'ctr']);
            }
        }
        $p->text($s, 0.6, 7.0, 12.1, 0.35, 'Fuente: sistemas de CENGICAÑA (Cengicursos, Comités, Laboratorio y Experimentos) · ' . $d['generado'], ['size' => 9, 'color' => '#8B9298']);
    }

    $s = $p->slide();
    $p->rect($s, 0, 0, 13.333, 7.5, MARCA_OSCURO);
    $franja($s, 4.6);
    $p->text($s, 0.8, 2.3, 11.7, 1.0, 'Trabajemos juntos por la agroindustria azucarera', ['size' => 34, 'bold' => true, 'color' => '#FFFFFF', 'align' => 'ctr']);
    $p->text($s, 0.8, 3.3, 11.7, 0.6, 'CENGICAÑA · Comités, Transferencia y Comunicación', ['size' => 16, 'color' => '#A3D300', 'align' => 'ctr']);
    $p->rect($s, 5.17, 5.2, 3.0, 1.15, '#FFFFFF', null, true);
    if ($logo) $p->image($s, 5.32, 5.3, 2.7, 0.95, $logo);
    return $p->generar();
}

/** Descargas: html (imprimir), pdf o pptx. Termina la ejecución. */
function cifras_exportar(PDO $pdo, int $anio, string $formato): void
{
    $d = cifras_datos($pdo, $anio);
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
