<?php
/**
 * Datos y acciones de "Difusión", "Encuestas y evaluación", el informe por evento y la
 * pestaña "Evaluación de eventos" de cengicursos/eventos_qr.php (prototipo SIGEC v29).
 *
 *   GET  ?accion=datos                     JSON con todos los eventos y sus encuestas/difusion
 *   GET  ?accion=exportar_respuestas&evento_id=N   Excel con las respuestas de un evento
 *   GET  ?accion=exportar_consolidado              Excel con la evaluacion de todos los eventos
 *   POST accion=dif_enlaces | dif_flyer | dif_aprobar | dif_quitar_aprob |
 *        dif_bitacora_agregar | dif_bitacora_quitar | enviar_colab | enviar_percep
 *        (solo AJAX y con permiso de gestion; responden JSON)
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/eventos_helpers.php';
require_once __DIR__ . '/classes/export_helpers.php';

cengi_require_ver_eventos();
$db = conectar();
$puedeGestionar = cengi_puede_gestionar_eventos();

function cengi_evg_json(array $datos, $codigo = 200)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cengi_evg_error($mensaje, $codigo = 400)
{
    cengi_evg_json(['ok' => false, 'mensaje' => $mensaje], $codigo);
}

function cengi_evg_fecha_valida($fecha)
{
    $obj = DateTime::createFromFormat('!Y-m-d', (string) $fecha);
    return $obj && $obj->format('Y-m-d') === $fecha;
}

/* cengi_enviar_correo() lanza excepciones si la configuracion SMTP esta incompleta: se
   registran y se tratan como un envio fallido para responder JSON en vez de un error fatal. */
function cengi_evg_enviar_correo($correo, $nombre, $asunto, $html)
{
    try {
        return cengi_enviar_correo((string) $correo, (string) $nombre, (string) $asunto, (string) $html);
    } catch (Throwable $e) {
        error_log('Correo de encuesta de evento a ' . $correo . ': ' . $e->getMessage());
        return false;
    }
}

function cengi_evg_url_valida($url)
{
    return $url === '' || (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url));
}

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

$accion = (string) ($_GET['accion'] ?? $_POST['accion'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'datos') {
    cengi_evg_json([
        'ok' => true,
        'eventos' => cengi_evg_datos($db),
        'puede_gestionar' => $puedeGestionar,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($accion === 'exportar_respuestas' || $accion === 'exportar_consolidado')) {
    $eventos = cengi_evg_datos($db);
    if ($accion === 'exportar_respuestas') {
        $eventoId = (int) ($_GET['evento_id'] ?? 0);
        $eventos = array_values(array_filter($eventos, static function ($e) use ($eventoId) { return $e['id'] === $eventoId; }));
        if (!$eventos) {
            http_response_code(404);
            exit('El evento no existe.');
        }
        $e = $eventos[0];
        $encabezados = ['Encuesta', 'Fecha', 'Empresa / vía', 'Responde / participante', 'Institución', 'Estrellas'];
        foreach (CENGI_EVT_COLAB_ITEMS as $i) {
            $encabezados[] = $i['t'];
        }
        $encabezados = array_merge($encabezados, ['¿Fortaleció el evento?', '¿Participaría nuevamente?', 'Qué mejorar / oportunidad de mejora', 'Apoyo deseado', 'Tema']);
        $filas = [];
        foreach ($e['colab'] as $c) {
            $fila = ['Colaboración', $c['ts'], $c['empresa'], $c['nombre'], '', ''];
            foreach (CENGI_EVT_COLAB_ITEMS as $i) {
                $fila[] = $c['r'][$i['k']] === null ? 'No aplica' : $c['r'][$i['k']];
            }
            $filas[] = array_merge($fila, [$c['fortalecio'], $c['participaria'], $c['mejorar'], $c['apoyo'], '']);
        }
        foreach ($e['percep'] as $r) {
            $fila = ['Percepción', $r['ts'], $r['via'], $r['participante'] ?: 'Anónimo', (string) $r['institucion'], $r['estrellas']];
            foreach (CENGI_EVT_COLAB_ITEMS as $i) {
                $fila[] = '';
            }
            $filas[] = array_merge($fila, ['', '', $r['mejora'], '', $r['mejora'] !== '' ? cengi_evg_clasificar($r['mejora']) : '']);
        }
        cengi_export_enviar_excel($encabezados, $filas, 'Respuestas', 'Encuestas_' . preg_replace('/[^A-Za-z0-9]+/', '_', $e['nombre']));
    }

    $encabezados = ['Evento', 'Fecha', 'Tipo', 'Registrados', 'Asistentes', 'Respuestas participantes', 'Percepción promedio', '% satisfechos', 'Tema de mejora principal', 'En colaboración', 'Empresas aliadas', 'Respondieron', 'Índice de colaboración'];
    foreach (CENGI_EVT_COLAB_ITEMS as $i) {
        $encabezados[] = $i['t'];
    }
    $filas = [];
    foreach ($eventos as $e) {
        $estrellas = array_column($e['percep'], 'estrellas');
        $prom = cengi_evg_promedio($estrellas);
        $satisf = $estrellas ? count(array_filter($estrellas, static function ($x) { return $x >= 4; })) / count($estrellas) * 100 : null;
        $items = [];
        foreach (CENGI_EVT_COLAB_ITEMS as $i) {
            $items[] = cengi_evg_promedio(array_map(static function ($c) use ($i) { return $c['r'][$i['k']]; }, $e['colab']));
        }
        $indice = cengi_evg_promedio($items);
        $fila = [
            $e['nombre'], (string) $e['fecha'], $e['tipo'], $e['registrados'], $e['asistentes'], count($e['percep']),
            $prom !== null ? round($prom, 2) : '', $satisf !== null ? (int) round($satisf) : '', cengi_evg_tema_principal($e['percep']),
            $e['en_colaboracion'] ? 'Sí' : 'No', count($e['aliados']), count($e['colab']), $indice !== null ? round($indice, 2) : '',
        ];
        foreach ($items as $p) {
            $fila[] = $p !== null ? round($p, 2) : '';
        }
        $filas[] = $fila;
    }
    cengi_export_enviar_excel($encabezados, $filas, 'Consolidado', 'Evaluacion_de_eventos');
}

/* ---------- acciones (POST, AJAX, solo gestion) ---------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cengi_evg_error('Acción no válida.', 404);
}
if (!$puedeGestionar) {
    cengi_evg_error('No tienes permiso para esta acción.', 403);
}
// Solo peticiones AJAX del propio panel (jQuery envia este encabezado; un formulario de
// otro sitio no puede agregarlo).
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    cengi_evg_error('Petición no válida.', 400);
}

$eventoId = (int) ($_POST['evento_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM eventos WHERE id = ?');
$stmt->execute([$eventoId]);
$evento = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$evento) {
    cengi_evg_error('El evento no existe.', 404);
}
$usuarioNombre = (string) ($_SESSION['usuario'] ?? '');

switch ($accion) {
    case 'dif_enlaces':
        $landing = trim((string) ($_POST['landing'] ?? ''));
        $canva = trim((string) ($_POST['canva'] ?? ''));
        if (!cengi_evg_url_valida($landing) || !cengi_evg_url_valida($canva) || strlen($landing) > 500 || strlen($canva) > 500) {
            cengi_evg_error('Escribe un enlace válido que empiece con http:// o https://.');
        }
        $db->prepare('UPDATE eventos SET dif_landing = ?, dif_canva = ? WHERE id = ?')->execute([$landing ?: null, $canva ?: null, $eventoId]);
        cengi_evg_json(['ok' => true, 'mensaje' => 'Enlaces guardados']);
        break;

    case 'dif_flyer':
        $archivo = $_FILES['flyer'] ?? null;
        if (!$archivo || (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $archivo['tmp_name'])) {
            cengi_evg_error('No fue posible subir el flyer. Intenta nuevamente.');
        }
        if ((int) $archivo['size'] > 5 * 1024 * 1024) {
            cengi_evg_error('El archivo supera los 5 MB.');
        }
        $extension = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        $permitidos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (!isset($permitidos[$extension]) || $permitidos[$extension] !== $mime) {
            cengi_evg_error('Formato no permitido. Usa PDF, JPG o PNG.');
        }
        $ruta = cengi_guardar_archivo_subido($archivo['tmp_name'], 'flyers_evento', bin2hex(random_bytes(16)) . '.' . $extension);
        if ($ruta === null) {
            cengi_evg_error('No fue posible guardar el flyer.', 500);
        }
        $nombreOriginal = mb_substr(basename((string) $archivo['name']), 0, 255, 'UTF-8');
        $db->prepare('UPDATE eventos SET dif_flyer = ?, dif_flyer_nombre = ? WHERE id = ?')->execute([$ruta, $nombreOriginal, $eventoId]);
        cengi_evg_json(['ok' => true, 'mensaje' => 'Flyer guardado en el registro del evento']);
        break;

    case 'dif_aprobar':
        $por = mb_substr(trim((string) ($_POST['por'] ?? '')), 0, 255, 'UTF-8');
        $fecha = trim((string) ($_POST['fecha'] ?? ''));
        $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 500, 'UTF-8');
        if ($por === '' || !cengi_evg_fecha_valida($fecha)) {
            cengi_evg_error('Indica quién aprobó y la fecha.');
        }
        $db->prepare('UPDATE eventos SET dif_aprob_por = ?, dif_aprob_fecha = ?, dif_aprob_nota = ? WHERE id = ?')->execute([$por, $fecha, $nota ?: null, $eventoId]);
        cengi_evg_json(['ok' => true, 'mensaje' => 'Aprobación del material registrada']);
        break;

    case 'dif_quitar_aprob':
        $db->prepare('UPDATE eventos SET dif_aprob_por = NULL, dif_aprob_fecha = NULL, dif_aprob_nota = NULL WHERE id = ?')->execute([$eventoId]);
        cengi_evg_json(['ok' => true]);
        break;

    case 'dif_bitacora_agregar':
        $canal = (string) ($_POST['canal'] ?? '');
        $fecha = trim((string) ($_POST['fecha'] ?? ''));
        $detalle = mb_substr(trim((string) ($_POST['detalle'] ?? '')), 0, 500, 'UTF-8');
        if (!isset(CENGI_EVT_DIF_CANALES[$canal])) {
            cengi_evg_error('Selecciona el canal.');
        }
        if (!cengi_evg_fecha_valida($fecha)) {
            cengi_evg_error('Indica la fecha de la divulgación.');
        }
        $db->prepare('INSERT INTO evento_difusion (evento_id, canal, fecha, detalle, registrado_por) VALUES (?, ?, ?, ?, ?)')
            ->execute([$eventoId, $canal, $fecha, $detalle ?: null, $usuarioNombre ?: null]);
        cengi_evg_json(['ok' => true, 'mensaje' => 'Divulgación por ' . mb_strtolower(CENGI_EVT_DIF_CANALES[$canal], 'UTF-8') . ' registrada']);
        break;

    case 'dif_bitacora_quitar':
        $db->prepare('DELETE FROM evento_difusion WHERE id = ? AND evento_id = ?')->execute([(int) ($_POST['id'] ?? 0), $eventoId]);
        cengi_evg_json(['ok' => true]);
        break;

    case 'enviar_colab':
        $stmt = $db->prepare('SELECT * FROM evento_aliados WHERE id = ? AND evento_id = ?');
        $stmt->execute([(int) ($_POST['aliado_id'] ?? 0), $eventoId]);
        $aliado = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$aliado) {
            cengi_evg_error('La empresa aliada no existe.', 404);
        }
        if (!filter_var((string) $aliado['correo'], FILTER_VALIDATE_EMAIL)) {
            cengi_evg_error('La empresa no tiene un correo válido. Edita el evento o copia el enlace.');
        }
        require_once __DIR__ . '/correo.php';
        $url = cengi_evento_url_encuesta($aliado['token']);
        $html = cengi_evento_correo_encuesta_html(
            'Estimado(a) ' . trim((string) $aliado['contacto']) . ':',
            ['Gracias por colaborar con CENGICAÑA en ' . $evento['nombre'] . ($evento['fecha'] ? ' (' . date('d/m/Y', strtotime($evento['fecha'])) . ')' : '') . '. Queremos conocer su percepción para mejorar futuras alianzas; la encuesta toma unos 3 minutos.'],
            $url,
            'Responder la encuesta'
        );
        if (!cengi_evg_enviar_correo($aliado['correo'], (string) ($aliado['contacto'] ?: $aliado['nombre']), 'Su opinión sobre nuestra colaboración en ' . $evento['nombre'], $html)) {
            cengi_evg_error('No fue posible enviar el correo. Intenta nuevamente o copia el enlace.', 500);
        }
        $db->prepare('UPDATE evento_aliados SET enviado_en = NOW() WHERE id = ?')->execute([(int) $aliado['id']]);
        cengi_evg_json(['ok' => true, 'mensaje' => 'Encuesta de colaboración enviada a ' . $aliado['nombre']]);
        break;

    case 'enviar_percep':
        $stmt = $db->prepare("
            SELECT ep.id, ep.nombre_invitado AS nombre, ep.token_encuesta,
                   COALESCE(NULLIF(p.correo_participantes, ''), NULLIF(ep.correo_invitado, '')) AS correo
            FROM evento_participantes ep
            LEFT JOIN participantes p ON p.id = ep.participante_id
            WHERE ep.evento_id = ? AND ep.ingreso_en IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM evento_encuesta_percep r WHERE r.evento_participante_id = ep.id)
        ");
        $stmt->execute([$eventoId]);
        $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$pendientes) {
            cengi_evg_error('No hay asistentes pendientes de responder.');
        }
        require_once __DIR__ . '/correo.php';
        $enviados = 0;
        $sinCorreo = 0;
        $fallidos = 0;
        $guardarToken = $db->prepare('UPDATE evento_participantes SET token_encuesta = ? WHERE id = ? AND token_encuesta IS NULL');
        foreach ($pendientes as $p) {
            if (!filter_var((string) $p['correo'], FILTER_VALIDATE_EMAIL)) {
                $sinCorreo++;
                continue;
            }
            $tokenP = $p['token_encuesta'];
            if (!$tokenP) {
                $tokenP = cengi_evento_token();
                $guardarToken->execute([$tokenP, (int) $p['id']]);
            }
            $html = cengi_evento_correo_encuesta_html(
                'Hola ' . trim((string) $p['nombre']) . ':',
                ['Gracias por asistir a ' . $evento['nombre'] . '. Califica tu experiencia en menos de un minuto.'],
                cengi_evento_url_encuesta($tokenP),
                'Calificar el evento'
            );
            if (cengi_evg_enviar_correo($p['correo'], (string) $p['nombre'], '¿Cómo fue tu experiencia en ' . $evento['nombre'] . '?', $html)) {
                $enviados++;
            } else {
                $fallidos++;
            }
        }
        if ($enviados > 0) {
            $db->prepare('UPDATE eventos SET percep_enviada_en = NOW() WHERE id = ?')->execute([$eventoId]);
        }
        $detalle = [];
        if ($sinCorreo) {
            $detalle[] = $sinCorreo . ' sin correo';
        }
        if ($fallidos) {
            $detalle[] = $fallidos . ' no se pudieron enviar';
        }
        cengi_evg_json([
            'ok' => $enviados > 0,
            'mensaje' => ($enviados > 0 ? 'Encuesta de percepción enviada a ' . $enviados . ' asistente(s)' : 'No se pudo enviar la encuesta')
                . ($detalle ? ' (' . implode(', ', $detalle) . ')' : ''),
        ], $enviados > 0 ? 200 : 500);
        break;

    default:
        cengi_evg_error('Acción no válida.', 404);
}
