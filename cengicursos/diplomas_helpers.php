<?php
/**
 * Certificacion: actividades (cursos y eventos), destinatarios, plantillas de diseño y
 * emision de diplomas con codigo de validacion. Lo usan diplomas.php (diseñador),
 * diplomas_api.php (guardar / emitir / enviar) y validar_diploma.php (publico).
 * El dibujo del diploma se hace en el navegador con js/diplomas.js.
 * Esquema: cengicursos/migrations/20261009_diplomas_plantillas.sql.
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/cengi_texto.php';
require_once __DIR__ . '/eventos_helpers.php';

const CENGI_DIP_MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const CENGI_DIP_MAX_PLANTILLA = 8 * 1024 * 1024; // logos, firmas y fondo van como imagenes data:

function cengi_dip_h($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

/** "9 de noviembre de 2026" */
function cengi_dip_fecha($iso)
{
    if (!$iso) {
        return '';
    }
    $t = strtotime((string) $iso);
    return (int) date('j', $t) . ' de ' . CENGI_DIP_MESES[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

function cengi_dip_url_base()
{
    $env = cengicursos_env();
    if (!empty($env['APP_URL'])) {
        return rtrim((string) $env['APP_URL'], '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function cengi_dip_url_validar($codigo)
{
    return cengi_dip_url_base() . '/cengicursos/validar_diploma.php?c=' . rawurlencode($codigo);
}

/** Cursos con participantes asignados y eventos con registrados, para el selector. */
function cengi_dip_actividades(PDO $db)
{
    $cursos = $db->query("SELECT c.id, c.nombre_cursos, c.inicio, YEAR(COALESCE(c.inicio, c.creado)) AS anio,
            (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id AND a.estado_asignaciones = 1) AS n
        FROM cursos c HAVING n > 0 ORDER BY c.inicio DESC, c.nombre_cursos")->fetchAll(PDO::FETCH_ASSOC);
    $eventos = $db->query("SELECT e.id, e.nombre, e.fecha, (SELECT COUNT(*) FROM evento_participantes p WHERE p.evento_id = e.id) AS n
        FROM eventos e HAVING n > 0 ORDER BY e.fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
    return [
        'Cursos y diplomados' => array_map(static function ($c) {
            return ['clave' => 'cu:' . $c['id'], 'texto' => $c['nombre_cursos'] . ' · ' . $c['anio'] . ' · ' . $c['n'] . ' participante(s)'];
        }, $cursos),
        'Eventos' => array_map(static function ($e) {
            return ['clave' => 'ev:' . $e['id'], 'texto' => $e['nombre'] . ' · ' . date('d/m/Y', strtotime($e['fecha'])) . ' · ' . $e['n'] . ' registrado(s)'];
        }, $eventos),
    ];
}

/** Datos de la actividad que rellenan el diploma. null si la clave no existe. */
function cengi_dip_actividad(PDO $db, $clave)
{
    if (!preg_match('/^(cu|ev):(\d+)$/', (string) $clave, $m)) {
        return null;
    }
    if ($m[1] === 'ev') {
        $stmt = $db->prepare('SELECT * FROM eventos WHERE id = ?');
        $stmt->execute([(int) $m[2]]);
        $e = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$e) {
            return null;
        }
        $horas = '';
        if (preg_match('/(\d{1,2}):(\d{2})\s*(?:–|—|-|a)\s*(\d{1,2}):(\d{2})/u', (string) $e['hora'], $h)) {
            $horas = rtrim(rtrim(number_format(max(0, ((int) $h[3] * 60 + (int) $h[4] - (int) $h[1] * 60 - (int) $h[2]) / 60), 1, '.', ''), '0'), '.');
        }
        return ['clave' => $clave, 'evento' => true, 'id' => (int) $e['id'], 'actividad' => $e['nombre'],
            'tipo_actividad' => mb_strtolower($e['tipo'] ?: 'evento', 'UTF-8'), 'fecha' => cengi_dip_fecha($e['fecha']),
            'lugar' => $e['lugar'] ?: 'CENGICAÑA', 'horas' => $horas, 'color' => $e['color'] ?: '', 'anio' => (int) date('Y', strtotime($e['fecha'])),
            'imagen_evento' => (string) $e['imagen'], 'logo_evento' => (string) $e['logo']];
    }
    $stmt = $db->prepare("SELECT c.*, cc.descripcion_categorias_cursos AS categoria,
            (SELECT COALESCE(SUM(horas), 0) FROM curso_modulos m WHERE m.curso_id = c.id) AS horas_modulos,
            (SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(s.hora_fin, s.hora_inicio))) / 3600, 0) FROM curso_sesiones s
                INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id WHERE p.curso_id = c.id AND s.estado <> 'cancelada') AS horas_sesiones
        FROM cursos c LEFT JOIN categorias_cursos cc ON cc.id = c.categoria_curso_id WHERE c.id = ?");
    $stmt->execute([(int) $m[2]]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        return null;
    }
    $horas = (float) $c['horas_modulos'] ?: (float) $c['horas_sesiones'];
    $fecha = $c['inicio'] && $c['fin'] && $c['inicio'] !== $c['fin']
        ? 'del ' . cengi_dip_fecha($c['inicio']) . ' al ' . cengi_dip_fecha($c['fin'])
        : 'el ' . cengi_dip_fecha($c['inicio'] ?: $c['fin']);
    return ['clave' => $clave, 'evento' => false, 'id' => (int) $c['id'], 'actividad' => $c['nombre_cursos'],
        'tipo_actividad' => mb_strtolower($c['categoria'] ?: 'curso', 'UTF-8'), 'fecha' => $fecha,
        'lugar' => stripos((string) $c['tipo'], 'virtual') !== false ? 'modalidad virtual' : 'CENGICAÑA',
        'horas' => $horas > 0 ? rtrim(rtrim(number_format($horas, 2, '.', ''), '0'), '.') : '', 'color' => '',
        'anio' => (int) date('Y', strtotime($c['inicio'] ?: $c['creado']))];
}

/**
 * Destinatarios de la actividad con su estado y su diploma (si ya se emitio).
 * Curso: asignados activos; aprobado = post-evaluacion >= CENGI_NOTA_APROBACION (mismo criterio que
 * Seguimiento por curso). Evento: registrados; confirmado = tiene QR; asistio = ingreso.
 */
function cengi_dip_participantes(PDO $db, array $actividad)
{
    if ($actividad['evento']) {
        $stmt = $db->prepare("SELECT ep.id, ep.nombre_invitado AS nombre, ep.cui_invitado AS cui, ep.correo_invitado AS correo,
                COALESCE(i.nombre_ingenios, ep.institucion_invitado, '') AS institucion, ep.codigo_qr, ep.ingreso_en,
                d.codigo_unico, d.pdf_path, d.enviado_en, d.emitido_en
            FROM evento_participantes ep LEFT JOIN ingenios i ON i.id = ep.ingenio_id
            LEFT JOIN diplomas d ON d.tipo = 'evento' AND d.evento_participante_id = ep.id
            WHERE ep.evento_id = ? ORDER BY ep.nombre_invitado");
        $stmt->execute([$actividad['id']]);
        return array_map(static function ($p) {
            return ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'cui' => (string) $p['cui'], 'correo' => (string) $p['correo'],
                'institucion' => (string) $p['institucion'], 'rol' => 'participante',
                'estado' => $p['ingreso_en'] ? 'Asistió' : ($p['codigo_qr'] ? 'Confirmado, sin ingreso' : 'Sin confirmar'),
                'filtros' => array_values(array_filter(['todos', $p['codigo_qr'] ? 'confirmados' : null, $p['ingreso_en'] ? 'asistieron' : null])),
                'codigo' => $p['codigo_unico'], 'pdf' => (bool) $p['pdf_path'], 'enviado' => $p['enviado_en'], 'emitido' => $p['emitido_en']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    $stmt = $db->prepare("SELECT a.id, p.nombre_participantes AS nombre, p.cui_participantes AS cui, p.correo_participantes AS correo,
            COALESCE(i.nombre_ingenios, '') AS institucion, cc.posevaluacion, d.codigo_unico, d.pdf_path, d.enviado_en, d.emitido_en
        FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id LEFT JOIN ingenios i ON i.id = p.ingenio_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE a.cursos_id = ? AND a.estado_asignaciones = 1 ORDER BY p.nombre_participantes");
    $stmt->execute([$actividad['id']]);
    return array_map(static function ($p) {
        $nota = is_numeric($p['posevaluacion']) ? (float) $p['posevaluacion'] : null;
        return ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'cui' => (string) $p['cui'], 'correo' => (string) $p['correo'],
            'institucion' => (string) $p['institucion'], 'rol' => 'participante',
            'estado' => $nota === null ? 'Sin nota final' : ($nota >= CENGI_NOTA_APROBACION ? 'Aprobado (' . rtrim(rtrim(number_format($nota, 1), '0'), '.') . ')' : 'No aprobado (' . rtrim(rtrim(number_format($nota, 1), '0'), '.') . ')'),
            'filtros' => array_values(array_filter(['todos', $nota !== null && $nota >= CENGI_NOTA_APROBACION ? 'aprobados' : null])),
            'codigo' => $p['codigo_unico'], 'pdf' => (bool) $p['pdf_path'], 'enviado' => $p['enviado_en'], 'emitido' => $p['emitido_en']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Diseño predeterminado (mismos textos que el prototipo de Certificacion). */
function cengi_dip_plantilla_defecto(array $actividad)
{
    $evento = $actividad['evento'];
    // Evento con imagen o logo ("Editar evento"): el logo va en el espacio derecho y la imagen
    // como fondo suave (embebidos como data URI, igual que las imagenes que se suben aqui).
    $logoEvento = $evento && !empty($actividad['logo_evento']) ? cengi_evento_imagen_data_uri($actividad['logo_evento']) : null;
    $imagenEvento = $evento && !empty($actividad['imagen_evento']) ? cengi_evento_imagen_data_uri($actividad['imagen_evento']) : null;
    return [
        'estilo' => 'institucional', 'papel' => 'carta', 'color' => $actividad['color'] ?: '#2F6B12',
        'documento' => $evento ? 'Constancia de participación' : 'Diploma',
        'intro' => 'El Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar otorga la presente',
        'cuerpo' => $evento
            ? 'por su participación como {rol} en el {tipo_actividad} “{actividad}”, realizado el {fecha} en {lugar}.'
            : 'por haber aprobado satisfactoriamente el {tipo_actividad} “{actividad}”, desarrollado {fecha}, con una duración de {horas} horas académicas.',
        'horas' => $actividad['horas'], 'lugarFecha' => 'Guatemala, {fecha_emision}', 'emision' => date('Y-m-d'),
        'logos' => ['css/images/cengi.png', null, $logoEvento], 'fondo' => $imagenEvento ? 'propia' : 'ninguno', 'fondoImg' => $imagenEvento, 'fondoOp' => 14, 'qr' => true,
        'nFirmas' => 2,
        'firmas' => [
            ['img' => null, 'nombre' => '', 'cargo' => 'Encargada de Capacitación'],
            ['img' => null, 'nombre' => '', 'cargo' => 'Dirección General'],
            ['img' => null, 'nombre' => '', 'cargo' => 'Instructor / Coordinador'],
        ],
        'canva' => null,
    ];
}

function cengi_dip_plantilla(PDO $db, array $actividad)
{
    $stmt = $db->prepare('SELECT datos FROM diploma_plantillas WHERE clave = ?');
    $stmt->execute([$actividad['clave']]);
    $guardada = json_decode((string) $stmt->fetchColumn(), true);
    // Se combina con el predeterminado para que diseños antiguos reciban campos nuevos.
    return is_array($guardada) ? array_replace(cengi_dip_plantilla_defecto($actividad), $guardada) : cengi_dip_plantilla_defecto($actividad);
}

/**
 * Valida el JSON del diseño enviado por el navegador: tamaño maximo y que las imagenes sean
 * data: de imagen o rutas internas conocidas (nunca URLs externas ni javascript:).
 */
function cengi_dip_validar_plantilla($json)
{
    if (!is_string($json) || strlen($json) > CENGI_DIP_MAX_PLANTILLA) {
        throw new InvalidArgumentException('El diseño es demasiado grande (máximo 8 MB con imágenes). Usa imágenes más livianas.');
    }
    $datos = json_decode($json, true);
    if (!is_array($datos)) {
        throw new InvalidArgumentException('El diseño no es válido.');
    }
    $imagenValida = static function ($v) {
        return $v === null || $v === ''
            || (is_string($v) && (preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $v) || preg_match('#^(css/images|img)/[\w.-]+\.(png|jpe?g|webp)$#', $v)));
    };
    $imagenes = array_merge((array) ($datos['logos'] ?? []), [$datos['fondoImg'] ?? null, $datos['canva']['fondo'] ?? null],
        array_column((array) ($datos['firmas'] ?? []), 'img'));
    foreach ($imagenes as $img) {
        if (!$imagenValida($img)) {
            throw new InvalidArgumentException('Una de las imágenes del diseño no es válida (usa PNG, JPG o WebP).');
        }
    }
    if (isset($datos['color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $datos['color'])) {
        $datos['color'] = '#2F6B12';
    }
    return $datos;
}

function cengi_dip_guardar_plantilla(PDO $db, $clave, array $datos, $usuario)
{
    $db->prepare('INSERT INTO diploma_plantillas (clave, datos, actualizado_por) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE datos = VALUES(datos), actualizado_por = VALUES(actualizado_por)')
        ->execute([$clave, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $usuario]);
}

/**
 * Emite (o reutiliza) el diploma de cada destinatario y devuelve [id => codigo].
 * Codigo: CEN-CU|EV-<año>-<correlativo>-<4 caracteres aleatorios>; la parte aleatoria
 * evita que alguien recorra codigos consecutivos en la pagina publica de validacion.
 */
function cengi_dip_emitir(PDO $db, array $actividad, array $ids, array $plantilla, $usuarioId)
{
    $participantes = [];
    foreach (cengi_dip_participantes($db, $actividad) as $p) {
        $participantes[$p['id']] = $p;
    }
    $tipo = $actividad['evento'] ? 'evento' : 'curso';
    $columna = $actividad['evento'] ? 'evento_participante_id' : 'asignacion_id';
    $prefijo = 'CEN-' . ($actividad['evento'] ? 'EV' : 'CU') . '-' . substr((string) ($plantilla['emision'] ?? date('Y')), 0, 4) . '-';
    $codigos = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if (!isset($participantes[$id])) {
            throw new InvalidArgumentException('Uno de los participantes ya no pertenece a esta actividad.');
        }
        $p = $participantes[$id];
        $datos = [$actividad['clave'], mb_substr((string) ($plantilla['documento'] ?? 'Diploma'), 0, 60, 'UTF-8'), mb_substr($actividad['actividad'], 0, 255, 'UTF-8'), $p['nombre']];
        if ($p['codigo']) {
            $db->prepare("UPDATE diplomas SET plantilla_clave = ?, documento = ?, actividad = ?, nombre_participante = ?, pdf_path = NULL WHERE tipo = ? AND $columna = ?")
                ->execute(array_merge($datos, [$tipo, $id]));
            $codigos[$id] = $p['codigo'];
            continue;
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM diplomas WHERE codigo_unico LIKE ?');
        $stmt->execute([$prefijo . '%']);
        $n = (int) $stmt->fetchColumn();
        do {
            $n++;
            $codigo = $prefijo . str_pad((string) $n, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 4));
            $existe = $db->prepare('SELECT COUNT(*) FROM diplomas WHERE codigo_unico = ?');
            $existe->execute([$codigo]);
        } while ((int) $existe->fetchColumn() > 0);
        $db->prepare("INSERT INTO diplomas (tipo, $columna, codigo_unico, emitido_en, creado_por, plantilla_clave, documento, actividad, nombre_participante)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)")->execute(array_merge([$tipo, $id, $codigo, $usuarioId ?: null], $datos));
        $codigos[$id] = $codigo;
    }
    return $codigos;
}

/** Diploma por codigo (para validar), con su actividad. */
function cengi_dip_por_codigo(PDO $db, $codigo)
{
    $stmt = $db->prepare("SELECT d.*,
            COALESCE(NULLIF(d.nombre_participante, ''), p.nombre_participantes, ep.nombre_invitado) AS nombre,
            COALESCE(NULLIF(d.actividad, ''), c.nombre_cursos, e.nombre) AS actividad_nombre,
            a.cursos_id, ep.evento_id
        FROM diplomas d
        LEFT JOIN asignaciones a ON a.id = d.asignacion_id LEFT JOIN participantes p ON p.id = a.participantes_id LEFT JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN evento_participantes ep ON ep.id = d.evento_participante_id LEFT JOIN eventos e ON e.id = ep.evento_id
        WHERE d.codigo_unico = ?");
    $stmt->execute([strtoupper(trim((string) $codigo))]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
