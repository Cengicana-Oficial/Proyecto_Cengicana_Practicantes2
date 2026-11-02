<?php
/**
 * Evaluación de eventos: datos de encuestas (percepción de participantes y colaboración de
 * empresas aliadas), clasificación de comentarios y meta de respuesta.
 * La usan eventos_gestion.php (JSON, Excel por evento) y eventos_evaluacion.php (página).
 */
require_once __DIR__ . '/eventos_helpers.php';

/** Valor por defecto; el porcentaje vigente se configura en eventos_evaluacion.php. */
const CENGI_META_EVAL_EVENTO = 40;
const CENGI_META_EVAL_EVENTO_CLAVE = 'meta_eval_evento';

/* Todos los eventos con aliados, respuestas y bitacora (los volumenes son pequenos). */
function cengi_evg_datos(PDO $db)
{
    // Cada evento necesita su enlace general de percepcion: se crea si aun no existe.
    foreach ($db->query('SELECT id FROM eventos WHERE token_percepcion IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $idSinToken) {
        cengi_evento_asegurar_token_percepcion($db, (int) $idSinToken);
    }

    $eventos = $db->query("
        SELECT e.id, e.nombre, e.tipo, e.fecha, e.hora, e.lugar, e.cupo, e.color, e.estado, e.modalidad_pago,
               e.en_colaboracion, e.colab_modalidad, e.colab_financia, e.token_percepcion, e.percep_enviada_en,
               e.dif_flyer, e.dif_flyer_nombre, e.dif_landing, e.dif_canva, e.dif_aprob_por, e.dif_aprob_fecha, e.dif_aprob_nota,
               (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id) AS registrados,
               (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id
                  AND (e.modalidad_pago <> 'Pagado' OR ep.pagado = 1)) AS confirmados,
               (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.ingreso_en IS NOT NULL) AS asistentes,
               (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.ingreso_en IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM evento_encuesta_percep r WHERE r.evento_participante_id = ep.id)) AS asistentes_sin_respuesta
        FROM eventos e
        ORDER BY e.fecha DESC, e.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $porId = [];
    foreach ($eventos as $e) {
        foreach (['id', 'cupo', 'en_colaboracion', 'colab_modalidad', 'registrados', 'confirmados', 'asistentes', 'asistentes_sin_respuesta'] as $campo) {
            $e[$campo] = $e[$campo] !== null ? (int) $e[$campo] : null;
        }
        $e['aliados'] = [];
        $e['colab'] = [];
        $e['percep'] = [];
        $e['difusion'] = [];
        $porId[$e['id']] = $e;
    }

    foreach ($db->query('SELECT id, evento_id, nombre, contacto, correo, token, enviado_en FROM evento_aliados ORDER BY evento_id, orden, id')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $eid = (int) $a['evento_id'];
        if (!isset($porId[$eid])) {
            continue;
        }
        $porId[$eid]['aliados'][] = ['id' => (int) $a['id'], 'nombre' => $a['nombre'], 'contacto' => $a['contacto'], 'correo' => $a['correo'], 'token' => $a['token'], 'enviado_en' => $a['enviado_en']];
    }

    $cols = implode(', ', array_map(static function ($i) { return 'c.' . $i['k']; }, CENGI_EVT_COLAB_ITEMS));
    foreach ($db->query("SELECT c.evento_id, c.aliado_id, c.empresa, c.nombre_responde, {$cols}, c.fortalecio, c.participaria, c.mejorar, c.apoyo, c.creado FROM evento_encuesta_colab c ORDER BY c.creado")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $eid = (int) $c['evento_id'];
        if (!isset($porId[$eid])) {
            continue;
        }
        $r = [];
        foreach (CENGI_EVT_COLAB_ITEMS as $i) {
            $r[$i['k']] = $c[$i['k']] !== null ? (int) $c[$i['k']] : null;
        }
        $porId[$eid]['colab'][] = [
            'aliado_id' => (int) $c['aliado_id'], 'empresa' => $c['empresa'], 'nombre' => (string) $c['nombre_responde'], 'ts' => $c['creado'],
            'r' => $r, 'fortalecio' => $c['fortalecio'], 'participaria' => $c['participaria'], 'mejorar' => (string) $c['mejorar'], 'apoyo' => (string) $c['apoyo'],
        ];
    }

    foreach ($db->query("
        SELECT r.evento_id, r.evento_participante_id, r.via, r.estrellas, r.mejora, r.creado,
               ep.nombre_invitado AS participante,
               COALESCE(i.nombre_ingenios, NULLIF(ep.institucion_invitado, '')) AS institucion
        FROM evento_encuesta_percep r
        LEFT JOIN evento_participantes ep ON ep.id = r.evento_participante_id
        LEFT JOIN ingenios i ON i.id = ep.ingenio_id
        ORDER BY r.creado
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eid = (int) $r['evento_id'];
        if (!isset($porId[$eid])) {
            continue;
        }
        $porId[$eid]['percep'][] = [
            'pid' => $r['evento_participante_id'] !== null ? (int) $r['evento_participante_id'] : null, 'via' => $r['via'],
            'estrellas' => (int) $r['estrellas'], 'mejora' => (string) $r['mejora'], 'ts' => $r['creado'],
            'participante' => $r['participante'], 'institucion' => $r['institucion'],
        ];
    }

    foreach ($db->query('SELECT id, evento_id, canal, fecha, detalle, registrado_por FROM evento_difusion ORDER BY fecha, id')->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $eid = (int) $d['evento_id'];
        if (!isset($porId[$eid])) {
            continue;
        }
        $porId[$eid]['difusion'][] = ['id' => (int) $d['id'], 'canal' => $d['canal'], 'fecha' => $d['fecha'], 'detalle' => (string) $d['detalle'], 'por' => (string) $d['registrado_por']];
    }

    return array_values($porId);
}

/* ---------- analisis (mismo criterio que ppEvAnalisis / ppClasificar del prototipo) ---------- */

const CENGI_EVG_TEMAS = [
    ['Organización y horarios', '/organiz|agenda|horari|puntual|retras|tiempo|registro|fila|inicio|duraci|señaliz|parqueo/u'],
    ['Contenido y ponentes', '/ponen|tema|conten|charla|expositor|demostr|práctic|practic|técnic|tecnic|informaci[oó]n t/u'],
    ['Auditorio e instalaciones', '/auditor|sonido|audio|micr|aire|clima|calor|asiento|parqueo|instalac|pantalla|proyector|espacio/u'],
    ['Difusión y comunicación', '/difus|invit|comunic|redes|correo|aviso|anticipaci|convocatoria/u'],
    ['Alimentación', '/comida|almuerzo|refrigerio|caf[eé]|alimenta|agua/u'],
    ['Networking y seguimiento', '/contacto|networking|empresas|conocer|seguimiento|listado|stand|comercial/u'],
];

function cengi_evg_clasificar($texto)
{
    $n = ' ' . mb_strtolower((string) $texto, 'UTF-8') . ' ';
    $elogio = '/excelente|muy buen|muy útil|muy util|me gust[oó]|felicit|gracias|repetir|interesante|valioso/u';
    $mejora = '/falt|mejor|más |mas |lento|tarde|retras|poco|calor|problema|no se|no hab|no fue|dif[ií]cil|incómod|incomod|agregar|publicar|compartir|señaliz|se traslap/u';
    if (preg_match($elogio, $n) && !preg_match($mejora, $n)) {
        return 'Comentarios positivos';
    }
    foreach (CENGI_EVG_TEMAS as $tema) {
        if (preg_match($tema[1], $n)) {
            return $tema[0];
        }
    }
    return 'Otros';
}

function cengi_evg_promedio(array $valores)
{
    $v = array_values(array_filter($valores, static function ($x) { return $x !== null; }));
    return $v ? array_sum($v) / count($v) : null;
}

function cengi_evg_tema_principal(array $percep)
{
    $conteo = [];
    foreach ($percep as $r) {
        if ($r['mejora'] === '') {
            continue;
        }
        $t = cengi_evg_clasificar($r['mejora']);
        if ($t !== 'Comentarios positivos' && $t !== 'Otros') {
            $conteo[$t] = ($conteo[$t] ?? 0) + 1;
        }
    }
    arsort($conteo);
    return $conteo ? (string) array_key_first($conteo) : '';
}

/* ---------- meta de respuesta a la encuesta de percepción ---------- */

/** Configuración guardada en eventos_config: ['porcentaje' => int, 'actualizado_por' => '', 'actualizado' => '']. */
function cengi_evg_meta_config(PDO $db)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['porcentaje' => CENGI_META_EVAL_EVENTO, 'actualizado_por' => '', 'actualizado' => ''];
    try {
        $stmt = $db->prepare('SELECT valor FROM eventos_config WHERE clave = ?');
        $stmt->execute([CENGI_META_EVAL_EVENTO_CLAVE]);
        $guardado = json_decode((string) $stmt->fetchColumn(), true);
        if (is_array($guardado) && isset($guardado['porcentaje'])) {
            $cache = array_merge($cache, $guardado);
            $cache['porcentaje'] = max(1, min(100, (int) $cache['porcentaje']));
        }
    } catch (Throwable $e) {
        error_log('Meta de evaluación de eventos: ' . $e->getMessage());
    }
    return $cache;
}

function cengi_evg_meta_guardar(PDO $db, $porcentaje, $usuario)
{
    $porcentaje = filter_var($porcentaje, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($porcentaje === false) {
        throw new InvalidArgumentException('Indica un porcentaje entero entre 1 y 100.');
    }
    $valor = ['porcentaje' => $porcentaje, 'actualizado_por' => (string) $usuario, 'actualizado' => date('Y-m-d H:i:s')];
    $db->prepare('INSERT INTO eventos_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([CENGI_META_EVAL_EVENTO_CLAVE, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

/** Estados de la meta de respuesta: [texto, clase de semáforo]. */
const CENGI_EVG_ESTADOS_META = [
    'cumple' => ['Cumple la meta', 'is-bien'],
    'abierto' => ['Bajo la meta · evento abierto', 'is-medio'],
    'cerrado' => ['Cerró bajo la meta', 'is-bajo'],
    'por_realizar' => ['Por realizar', 'is-sin'],
    'sin_ingresos' => ['Sin ingresos registrados', 'is-sin'],
    'cancelado' => ['Cancelado', 'is-sin'],
];

/**
 * Indicadores de un evento (de cengi_evg_datos) para la página de evaluación.
 * Tasa de respuesta = respuestas de percepción (personales y por QR) ÷ asistentes con ingreso,
 * con tope de 100 %. Solo «cuenta» para la meta un evento ya realizado con ingresos registrados.
 */
function cengi_evg_resumen(array $e, $meta, $hoy)
{
    $estrellas = array_column($e['percep'], 'estrellas');
    $n = count($estrellas);
    $dist = [];
    foreach ([1, 2, 3, 4, 5] as $s) {
        $dist[$s] = count(array_filter($estrellas, static function ($x) use ($s) { return $x === $s; }));
    }
    $items = [];
    foreach (CENGI_EVT_COLAB_ITEMS as $i) {
        $items[$i['k']] = cengi_evg_promedio(array_map(static function ($c) use ($i) { return $c['r'][$i['k']]; }, $e['colab']));
    }
    $indice = cengi_evg_promedio(array_values($items));
    $asist = (int) $e['asistentes'];
    $tasa = $asist ? (int) round(min(100, $n / $asist * 100)) : null;
    $necesarias = $asist ? (int) ceil($asist * $meta / 100) : 0;
    if ($e['estado'] === 'Cancelado') {
        $estado = 'cancelado';
    } elseif ((string) $e['fecha'] > $hoy) {
        $estado = 'por_realizar';
    } elseif (!$asist) {
        $estado = 'sin_ingresos';
    } elseif ($n >= $necesarias) {
        $estado = 'cumple';
    } else {
        $estado = $e['estado'] === 'Finalizado' ? 'cerrado' : 'abierto';
    }
    return [
        'n' => $n,
        'personales' => count(array_filter($e['percep'], static function ($r) { return $r['pid'] !== null; })),
        'prom' => $n ? array_sum($estrellas) / $n : null,
        'dist' => $dist,
        'satisf' => $n ? (int) round(($dist[4] + $dist[5]) / $n * 100) : null,
        'tasa' => $tasa,
        'necesarias' => $necesarias,
        'faltan' => max(0, $necesarias - $n),
        'estado' => $estado,
        'cuenta' => in_array($estado, ['cumple', 'abierto', 'cerrado'], true),
        'tema' => cengi_evg_tema_principal($e['percep']),
        'items' => $items,
        'indice' => $indice,
        'aliados' => count($e['aliados']),
        'aliados_resp' => count($e['colab']),
    ];
}
