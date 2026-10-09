<?php
/**
 * Acuerdo de colaboracion de un evento con empresas aliadas (prototipo: ppColab*).
 * Esquema: cengicursos/migrations/20261012_eventos_acuerdos.sql.
 *
 * Pasos: propuesta (modalidad, roles y detalle economico) -> envio a la empresa ->
 * aceptacion firmada -> pagos del aporte y recibos por donacion (los emite el area
 * financiera; aqui se solicitan y se cargan) -> liquidacion despues del evento
 * (alimentacion servida vs. contratada y cargos) -> encuesta de colaboracion y cierre.
 * Capacitacion (gestion de eventos) arma y envia la propuesta; Administracion (validar
 * pagos) registra pagos, recibos y liquida.
 */

require_once __DIR__ . '/eventos_pagos_helpers.php';

const CENGI_ACU_MODALIDADES = [
    1 => ['n' => 'Invitación abierta a la red de contactos', 'corto' => 'Invitación a la red', 'c' => '#5FA52B',
        'desc' => 'CENGICAÑA lanza la invitación a su red de contactos para ampliar el alcance del evento.',
        'centro' => ['Difusión del evento a través de canales oficiales', 'Registro de asistentes (link de inscripción y QR)', 'Coordinación general el día del evento'],
        'empresa' => ['Contenido, ponentes y conducción del evento', 'Material y equipo propio', 'Confirmar agenda y cantidad de participantes']],
    2 => ['n' => 'Evento organizado con colaboración de CENGICAÑA', 'corto' => 'Organizado en colaboración', 'c' => '#C8961A',
        'desc' => 'CENGICAÑA participa con presentaciones o presencia estratégica, sin respaldar formalmente el contenido o conclusiones.',
        'centro' => ['Invitación a la red de contactos', 'Colaboración en la agenda, presentaciones y dinámicas', 'Sugerir temáticas de interés para el sector', 'Registro de asistentes y control de ingreso con QR'],
        'empresa' => ['Contenido principal y ponentes', 'Aprobar la agenda conjunta', 'Material promocional y de apoyo']],
    3 => ['n' => 'Taller o curso con apoyo integral de CENGICAÑA', 'corto' => 'Taller con apoyo integral', 'c' => '#E06A1A',
        'desc' => 'CENGICAÑA brinda apoyo integral en diseño metodológico, contenidos, evaluaciones y diplomas.',
        'centro' => ['Diseño de la metodología de enseñanza', 'Elaboración de contenidos complementarios', 'Gestión de evaluaciones y retroalimentación', 'Emisión de diplomas de participación (con el logo de la empresa)'],
        'empresa' => ['Instructores o especialistas temáticos', 'Revisar y aprobar contenidos', 'Proveer el logo para diplomas']],
];
const CENGI_ACU_ALIM = [
    'basica' => ['n' => 'Refacción básica', 'r' => [50, 60]],
    'servicio' => ['n' => 'Refacción con servicio', 'r' => [65, 75]],
    'almuerzo' => ['n' => 'Almuerzo completo con servicio', 'r' => [120, 160]],
];
const CENGI_ACU_ESTADOS = [
    'borrador' => ['Propuesta en borrador', 'is-neutral'], 'enviada' => ['Propuesta enviada', 'is-waiting'],
    'aceptada' => ['Aceptada · pendiente de pago', 'is-waiting'], 'parcial' => ['Pago parcial', 'is-waiting'],
    'pagada' => ['Aporte pagado', 'is-active'], 'liquidada' => ['Liquidado', 'is-active'], 'cerrada' => ['Expediente cerrado', 'is-finished'],
];
const CENGI_ACU_ORDEN = ['borrador' => 0, 'enviada' => 1, 'aceptada' => 2, 'parcial' => 2, 'pagada' => 3, 'liquidada' => 4, 'cerrada' => 5];
const CENGI_ACU_TARIFAS = ['auditorioDia' => 6500, 'gestion1' => 1500, 'coord2' => 6200, 'admin3' => 9000, 'linea3' => 9000];

function cengi_acu_q($monto)
{
    return 'Q' . number_format((float) $monto, 2, '.', ',');
}

function cengi_acu_config(PDO $db, $clave, $defecto)
{
    $valor = $db->prepare('SELECT valor FROM eventos_config WHERE clave = ?');
    $valor->execute([$clave]);
    $v = json_decode((string) $valor->fetchColumn(), true);
    return is_array($defecto) ? (is_array($v) ? array_replace($defecto, array_intersect_key($v, $defecto)) : $defecto) : (is_string($v) && $v !== '' ? $v : $defecto);
}

function cengi_acu_guardar_config(PDO $db, $clave, $valor)
{
    $db->prepare('INSERT INTO eventos_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([$clave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

function cengi_acu_tarifas(PDO $db)
{
    return array_map('floatval', cengi_acu_config($db, 'tarifas_colab', CENGI_ACU_TARIFAS));
}

/** Correo del area financiera que emite los recibos por donacion. */
function cengi_acu_correo_financiero(PDO $db)
{
    return cengi_acu_config($db, 'area_financiera', CENGI_EVENTO_CORREO_PAGO);
}

function cengi_acu_tareas_base($modalidad)
{
    $m = CENGI_ACU_MODALIDADES[$modalidad] ?? CENGI_ACU_MODALIDADES[2];
    return array_merge(
        array_map(static function ($t) { return ['t' => $t, 'resp' => 'Centro', 'ok' => false]; }, $m['centro']),
        array_map(static function ($t) { return ['t' => $t, 'resp' => 'Empresa', 'ok' => false]; }, $m['empresa'])
    );
}

function cengi_acu_alim_medio($tipo)
{
    $r = CENGI_ACU_ALIM[$tipo]['r'] ?? [0, 0];
    return round(($r[0] + $r[1]) / 2);
}

/** Evento en colaboracion con sus empresas aliadas y conteos de participantes. */
function cengi_acu_evento(PDO $db, $eventoId)
{
    $stmt = $db->prepare("SELECT e.*, (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id) AS registrados,
            (SELECT COUNT(*) FROM evento_participantes ep WHERE ep.evento_id = e.id AND ep.ingreso_en IS NOT NULL) AS asistentes
        FROM eventos e WHERE e.id = ?");
    $stmt->execute([(int) $eventoId]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        return null;
    }
    $stmt = $db->prepare('SELECT id, nombre, contacto, correo, token, enviado_en FROM evento_aliados WHERE evento_id = ? ORDER BY orden, id');
    $stmt->execute([(int) $eventoId]);
    $e['aliados'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $e['colab_modalidad'] = isset(CENGI_ACU_MODALIDADES[(int) $e['colab_modalidad']]) ? (int) $e['colab_modalidad'] : 2;
    $e['colab_financia'] = $e['colab_financia'] ?: 'empresa';
    return $e;
}

function cengi_acu_decodificar(array $fila)
{
    foreach (['alims', 'extras', 'tareas', 'cargos'] as $k) {
        $v = json_decode((string) ($fila[$k . '_json'] ?? ''), true);
        $fila[$k] = is_array($v) ? array_values($v) : [];
    }
    foreach (['precios', 'excedente'] as $k) {
        $v = json_decode((string) ($fila[$k . '_json'] ?? ''), true);
        $fila[$k] = is_array($v) ? $v : [];
    }
    foreach (['alims_json', 'extras_json', 'tareas_json', 'cargos_json', 'precios_json', 'excedente_json'] as $k) {
        unset($fila[$k]);
    }
    $fila['dias'] = max(1, (int) $fila['dias']);
    $fila['participantes'] = max(1, (int) $fila['participantes']);
    $fila['descuento'] = (float) $fila['descuento'];
    return $fila;
}

/**
 * Acuerdo del evento; si no existe se crea con los valores iniciales del prototipo.
 * Con $crear = false (listados) no se escribe nada: se devuelven esos valores iniciales.
 */
function cengi_acu_cargar(PDO $db, array $ev, $crear = true)
{
    $stmt = $db->prepare('SELECT * FROM evento_acuerdos WHERE evento_id = ?');
    $stmt->execute([(int) $ev['id']]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila && !$crear) {
        return cengi_acu_decodificar(['evento_id' => (int) $ev['id'], 'estado' => 'borrador', 'modo' => 'presencial', 'dias' => 1, 'participantes' => (int) ($ev['cupo'] ?: 50),
            'descuento' => 0, 'tareas_json' => json_encode(cengi_acu_tareas_base($ev['colab_modalidad'])), 'fiscal_recibo' => $ev['aliados'][0]['nombre'] ?? null, 'fiscal_nit' => null,
            'fiscal_direccion' => null, 'fiscal_descripcion' => $ev['descripcion'], 'fiscal_objetivo' => null, 'propuesta_enviada_en' => null, 'propuesta_enviada_a' => null,
            'acept_representante' => null, 'acept_cargo' => null, 'acept_fecha' => null, 'acept_participantes' => null, 'acept_archivo' => null, 'acept_archivo_nombre' => null,
            'acept_archivo_fecha' => null, 'fecha_limite' => null]);
    }
    if (!$fila) {
        $db->prepare('INSERT INTO evento_acuerdos (evento_id, participantes, tareas_json, fiscal_recibo, fiscal_descripcion, fecha_limite, alims_json, extras_json, precios_json, cargos_json, excedente_json)
            VALUES (?, ?, ?, ?, ?, ?, \'[]\', \'[]\', \'{}\', \'[]\', \'{}\')')
            ->execute([(int) $ev['id'], (int) ($ev['cupo'] ?: 50), json_encode(cengi_acu_tareas_base($ev['colab_modalidad']), JSON_UNESCAPED_UNICODE),
                $ev['aliados'][0]['nombre'] ?? null, $ev['descripcion'] ? mb_substr($ev['descripcion'], 0, 500, 'UTF-8') : null,
                $ev['fecha'] ? date('Y-m-d', strtotime($ev['fecha'] . ' -5 days')) : null]);
        $stmt->execute([(int) $ev['id']]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return cengi_acu_decodificar($fila);
}

/** Actualiza columnas del acuerdo (los arreglos se guardan como JSON). */
function cengi_acu_actualizar(PDO $db, $eventoId, array $campos)
{
    $sets = [];
    $valores = [];
    foreach ($campos as $k => $v) {
        if (in_array($k, ['alims', 'extras', 'tareas', 'cargos', 'precios', 'excedente'], true)) {
            $k .= '_json';
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $sets[] = $k . ' = ?';
        $valores[] = $v;
    }
    if ($sets) {
        $valores[] = (int) $eventoId;
        $db->prepare('UPDATE evento_acuerdos SET ' . implode(', ', $sets) . ' WHERE evento_id = ?')->execute($valores);
    }
}

function cengi_acu_log(PDO $db, $eventoId, $texto, $detalle = '', $por = null)
{
    $db->prepare('INSERT INTO evento_acuerdo_historial (evento_id, texto, detalle, por, creado) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int) $eventoId, mb_substr($texto, 0, 200, 'UTF-8'), $detalle !== '' ? mb_substr($detalle, 0, 500, 'UTF-8') : null, $por ?: cengi_pp_usuario_nombre(), date('Y-m-d H:i:s')]);
}

function cengi_acu_solo_lectura(array $a)
{
    return in_array($a['estado'], ['parcial', 'pagada', 'liquidada', 'cerrada'], true);
}

/** Detalle economico (ppColabCalculo): lineas, subtotal, descuento y total del aporte. */
function cengi_acu_calculo(array $ev, array $a, array $T)
{
    $mod = $ev['colab_modalidad'];
    $presencial = !($mod === 3 && $a['modo'] === 'linea');
    $P = $a['precios'];
    $pu = static function ($k, $def) use ($P) {
        return isset($P[$k]) && $P[$k] !== '' && $P[$k] !== null ? (float) $P[$k] : (float) $def;
    };
    $L = [];
    if ($presencial) {
        $L[] = ['k' => 'auditorio', 'c' => 'Uso de auditorio', 'd' => 'Uso de auditorio y sala de exposición para el evento', 'cant' => $a['dias'], 'u' => $pu('auditorio', $T['auditorioDia']), 'unidad' => 'día(s)'];
    }
    if ($mod === 1) {
        $L[] = ['k' => 'gestion', 'c' => 'Gestión y difusión', 'd' => 'Gestión y difusión del evento a la red de contactos del Centro', 'cant' => 1, 'u' => $pu('gestion', $T['gestion1']), 'unidad' => 'servicio'];
    } elseif ($mod === 2) {
        $L[] = ['k' => 'coord', 'c' => 'Coordinación del evento', 'd' => 'Coordinación, invitación y participación del Centro (agenda, presentaciones y dinámicas)', 'cant' => 1, 'u' => $pu('coord', $T['coord2']), 'unidad' => 'servicio'];
    } else {
        $L[] = ['k' => 'admin', 'c' => $presencial ? 'Gastos administrativos y académicos' : 'Gastos administrativos y académicos (en línea)',
            'd' => 'Metodología, contenidos, evaluaciones y emisión de diplomas', 'cant' => 1, 'u' => $pu('admin', $presencial ? $T['admin3'] : $T['linea3']), 'unidad' => 'servicio'];
    }
    if ($presencial) {
        foreach ($a['alims'] as $i => $x) {
            $t = (int) ($x['tiempos'] ?? 0);
            if ($t <= 0 || !isset(CENGI_ACU_ALIM[$x['tipo'] ?? ''])) {
                continue;
            }
            $L[] = ['k' => 'alim' . $i, 'alim' => $i, 'c' => $x['tipo'] === 'almuerzo' ? 'Almuerzos' : 'Refacciones',
                'd' => CENGI_ACU_ALIM[$x['tipo']]['n'] . ": {$t} por día × {$a['participantes']} participantes × {$a['dias']} día(s)",
                'cant' => $a['participantes'] * $a['dias'] * $t, 'u' => (float) ($x['precio'] ?? 0) ?: cengi_acu_alim_medio($x['tipo']), 'unidad' => 'unidades'];
        }
    }
    foreach ($a['extras'] as $i => $x) {
        $L[] = ['k' => 'x' . $i, 'extra' => $i, 'c' => (string) $x['c'], 'd' => (string) ($x['d'] ?? ''), 'cant' => (float) ($x['cant'] ?? 1), 'u' => (float) ($x['u'] ?? 0), 'unidad' => ''];
    }
    $sub = 0;
    foreach ($L as &$l) {
        $l['sub'] = round($l['cant'] * $l['u'], 2);
        $sub += $l['sub'];
    }
    unset($l);
    $desc = min($sub, max(0, $a['descuento']));
    return ['lineas' => $L, 'subtotal' => $sub, 'descuento' => $desc, 'total' => $sub - $desc, 'presencial' => $presencial];
}

/** Excedente de alimentacion (ppColabExcedente): servidas vs. contratadas despues del evento. */
function cengi_acu_excedente(array $ev, array $a, array $cal)
{
    $lineas = array_values(array_filter($cal['lineas'], static function ($l) { return isset($l['alim']); }));
    if (!$lineas) {
        return null;
    }
    $asist = (int) $ev['asistentes'];
    $reg = (array) ($a['excedente']['consumidos'] ?? []);
    $items = [];
    foreach ($lineas as $l) {
        $x = $a['alims'][$l['alim']];
        $cons = isset($reg[$l['alim']]) ? (int) $reg[$l['alim']] : $asist * $a['dias'] * (int) $x['tiempos'];
        $extra = max(0, $cons - $l['cant']);
        $items[] = ['i' => $l['alim'], 'n' => CENGI_ACU_ALIM[$x['tipo']]['n'], 'contratadas' => $l['cant'], 'consumidas' => $cons, 'extra' => $extra, 'precio' => $l['u'], 'monto' => $extra * $l['u']];
    }
    return ['items' => $items, 'asist' => $asist, 'extra' => array_sum(array_column($items, 'extra')), 'monto' => array_sum(array_column($items, 'monto'))];
}

function cengi_acu_pagos(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT * FROM evento_acuerdo_pagos WHERE evento_id = ? ORDER BY fecha, id');
    $stmt->execute([(int) $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cengi_acu_recibos(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT * FROM evento_acuerdo_recibos WHERE evento_id = ? ORDER BY id');
    $stmt->execute([(int) $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Resumen economico (ppColabResumen): aporte, pagos, excedentes, saldos. */
function cengi_acu_resumen(array $ev, array $a, array $cal, $ex, array $pagos, array $recibos)
{
    $pag = static function ($tipo) use ($pagos) {
        return array_sum(array_map(static function ($p) { return (float) $p['monto']; }, array_filter($pagos, static function ($p) use ($tipo) { return $p['tipo'] === $tipo; })));
    };
    $excAlim = $ex && empty($a['excedente']['exonerado']) ? $ex['monto'] : 0;
    $cargos = array_sum(array_map(static function ($c) { return (float) $c['monto']; }, $a['cargos']));
    $pagAp = $pag('aporte');
    $pagEx = $pag('excedente');
    return ['aporte' => $cal['total'], 'pagAp' => $pagAp, 'saldoAp' => max(0, round($cal['total'] - $pagAp, 2)), 'excAlim' => $excAlim, 'cargos' => $cargos,
        'excTotal' => $excAlim + $cargos, 'pagEx' => $pagEx, 'saldoEx' => max(0, round($excAlim + $cargos - $pagEx, 2)), 'totalFinal' => $cal['total'] + $excAlim + $cargos,
        'pagado' => $pagAp + $pagEx, 'realizado' => $ev['fecha'] && $ev['fecha'] <= date('Y-m-d'),
        'pagosSinRecibo' => count(array_filter($pagos, static function ($p) { return !$p['recibo_id']; })),
        'recibosPendientes' => count(array_filter($recibos, static function ($r) { return $r['estado'] !== 'emitido'; }))];
}

/** Estado completo del acuerdo (lo que dibuja js/eventos_acuerdo.js y los documentos). */
function cengi_acu_estado(PDO $db, $eventoId, $crear = true)
{
    $ev = cengi_acu_evento($db, $eventoId);
    if (!$ev) {
        return null;
    }
    $a = cengi_acu_cargar($db, $ev, $crear);
    $T = cengi_acu_tarifas($db);
    $cal = cengi_acu_calculo($ev, $a, $T);
    $ex = cengi_acu_excedente($ev, $a, $cal);
    $pagos = cengi_acu_pagos($db, $eventoId);
    $recibos = cengi_acu_recibos($db, $eventoId);
    $stmt = $db->prepare('SELECT texto, detalle, por, creado FROM evento_acuerdo_historial WHERE evento_id = ? ORDER BY creado DESC, id DESC');
    $stmt->execute([(int) $eventoId]);
    $hist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Encuesta de colaboracion por empresa (evento_encuesta_colab).
    $claves = array_column(CENGI_EVT_COLAB_ITEMS, 'k');
    $stmt = $db->prepare('SELECT * FROM evento_encuesta_colab WHERE evento_id = ? ORDER BY creado DESC');
    $stmt->execute([(int) $eventoId]);
    $respuestas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($respuestas[(int) $r['aliado_id']])) {
            $notas = array_filter(array_map(static function ($k) use ($r) { return $r[$k]; }, $claves), static function ($v) { return $v !== null; });
            $respuestas[(int) $r['aliado_id']] = ['creado' => $r['creado'], 'promedio' => $notas ? array_sum($notas) / count($notas) : null, 'participaria' => $r['participaria']];
        }
    }
    $empresas = array_map(static function ($al) use ($respuestas) {
        return ['id' => (int) $al['id'], 'nombre' => $al['nombre'], 'contacto' => (string) $al['contacto'], 'correo' => (string) $al['correo'], 'enviado' => $al['enviado_en'],
            'url' => cengi_evento_url_encuesta($al['token']), 'respuesta' => $respuestas[(int) $al['id']] ?? null];
    }, $ev['aliados']);
    $promedios = array_filter(array_column(array_filter(array_column($empresas, 'respuesta')), 'promedio'));

    $evento = ['id' => (int) $ev['id'], 'nombre' => $ev['nombre'], 'fecha' => $ev['fecha'], 'fecha_texto' => cengi_evp_fecha($ev['fecha']), 'lugar' => (string) $ev['lugar'],
        'cupo' => $ev['cupo'] !== null ? (int) $ev['cupo'] : null, 'modalidad' => $ev['colab_modalidad'], 'financia' => $ev['colab_financia'],
        'modalidad_pago' => $ev['modalidad_pago'], 'costo' => (float) $ev['costo'], 'registrados' => (int) $ev['registrados'], 'asistentes' => (int) $ev['asistentes'],
        'en_colaboracion' => (int) $ev['en_colaboracion']];
    return ['evento' => $evento, 'acuerdo' => $a, 'calculo' => $cal, 'excedente' => $ex, 'resumen' => cengi_acu_resumen($ev, $a, $cal, $ex, $pagos, $recibos),
        'pagos' => $pagos, 'recibos' => $recibos, 'historial' => $hist, 'empresas' => $empresas, 'indice' => $promedios ? array_sum($promedios) / count($promedios) : null,
        'tarifas' => $T, 'correoFinanciero' => cengi_acu_correo_financiero($db), 'soloLectura' => cengi_acu_solo_lectura($a),
        'modalidades' => CENGI_ACU_MODALIDADES, 'alim' => CENGI_ACU_ALIM, 'estados' => CENGI_ACU_ESTADOS, 'orden' => CENGI_ACU_ORDEN];
}

/** Recalcula aceptada / parcial / pagada segun los pagos del aporte (ppColabEstado). */
function cengi_acu_recalcular_estado(PDO $db, $eventoId)
{
    $st = cengi_acu_estado($db, $eventoId);
    $a = $st['acuerdo'];
    if (!in_array($a['estado'], ['aceptada', 'parcial', 'pagada'], true)) {
        return $a['estado'];
    }
    $R = $st['resumen'];
    $nuevo = $R['pagAp'] <= 0 ? 'aceptada' : ($R['saldoAp'] > 0 ? 'parcial' : 'pagada');
    if ($nuevo !== $a['estado']) {
        cengi_acu_actualizar($db, $eventoId, ['estado' => $nuevo]);
    }
    return $nuevo;
}

/** Comprobantes, hojas firmadas y recibos: PDF, JPG o PNG de hasta 5 MB en uploads/acuerdos_evento/. */
function cengi_acu_guardar_archivo($archivo, $prefijo)
{
    if (!$archivo || (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        throw new InvalidArgumentException('No fue posible subir el archivo.');
    }
    if ((int) $archivo['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('El archivo supera 5 MB.');
    }
    $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $ext = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
    if (($tipos[$ext] ?? '') !== (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name'])) {
        throw new InvalidArgumentException('El archivo debe ser un PDF, JPG o PNG válido.');
    }
    $ruta = cengi_guardar_archivo_subido($archivo['tmp_name'], 'acuerdos_evento', $prefijo . '-' . bin2hex(random_bytes(12)) . '.' . $ext);
    if (!$ruta) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }
    return ['ruta' => $ruta, 'nombre' => mb_substr(basename((string) $archivo['name']), 0, 200, 'UTF-8')];
}

/** Ruta absoluta de un archivo del acuerdo (solo dentro de uploads/acuerdos_evento/). */
function cengi_acu_archivo_local($ruta)
{
    $url = cengi_normalizar_url_archivo($ruta);
    $base = realpath(__DIR__ . '/../uploads/acuerdos_evento');
    $archivo = $url !== '' ? realpath(__DIR__ . '/..' . $url) : false;
    return $base && $archivo && strpos($archivo, $base . DIRECTORY_SEPARATOR) === 0 && is_file($archivo) ? $archivo : null;
}

/** Al eliminar el evento: archivos del acuerdo (las filas se borran en cascada). */
function cengi_acu_archivos_evento(PDO $db, $eventoId)
{
    $rutas = [];
    foreach (['SELECT acept_archivo FROM evento_acuerdos WHERE evento_id = ?', 'SELECT comprobante FROM evento_acuerdo_pagos WHERE evento_id = ?', 'SELECT archivo FROM evento_acuerdo_recibos WHERE evento_id = ?'] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute([(int) $eventoId]);
        $rutas = array_merge($rutas, array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    return $rutas;
}

/** Correo a la empresa o al area financiera (queda registrado en Comunicaciones por correo.php). */
function cengi_acu_correo($correo, $nombre, $asunto, $mensaje, array $adjuntos = [])
{
    require_once __DIR__ . '/correo.php';
    if (!filter_var((string) $correo, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('No hay un correo válido para el envío.');
    }
    try {
        return cengi_enviar_correo($correo, (string) $nombre, $asunto, cengi_pp_correo_html($asunto, $mensaje . "\n\nCoordinación de Capacitación — CENGICAÑA"), $adjuntos);
    } catch (Throwable $e) {
        error_log('Acuerdo de colaboracion, correo: ' . $e->getMessage());
        return false;
    }
}

/** Numero a letras para los recibos (ppLetras). */
function cengi_acu_letras($n)
{
    $n = round((float) $n, 2);
    $ent = (int) floor($n);
    $cent = (int) round(($n - $ent) * 100);
    $U = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
    $D = ['', '', '', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    $C = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];
    $c3 = static function ($x) use ($U, $D, $C) {
        if ($x === 0) {
            return '';
        }
        if ($x === 100) {
            return 'cien';
        }
        $t = $C[intdiv($x, 100)];
        $r = $x % 100;
        if ($r) {
            $t .= ($t ? ' ' : '') . ($r < 30 ? $U[$r] : $D[intdiv($r, 10)] . ($r % 10 ? ' y ' . $U[$r % 10] : ''));
        }
        return $t;
    };
    $mill = intdiv($ent, 1000000);
    $mil = intdiv($ent, 1000) % 1000;
    $t = '';
    if ($mill) {
        $t .= ($mill === 1 ? 'un millón' : preg_replace('/uno$/', 'un', $c3($mill)) . ' millones') . ' ';
    }
    if ($mil) {
        $t .= ($mil === 1 ? 'mil' : preg_replace('/uno$/', 'un', $c3($mil)) . ' mil') . ' ';
    }
    $t = trim($t . $c3($ent % 1000)) ?: 'cero';
    $t = preg_replace(['/veintiuno$/', '/ uno$/', '/^uno$/'], ['veintiún', ' un', 'un'], $t);
    if (preg_match('/(millón|millones)$/', $t)) {
        $t .= ' de';
    }
    return mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8') . ($ent === 1 ? ' quetzal ' : ' quetzales ') . ($cent ? 'con ' . str_pad((string) $cent, 2, '0', STR_PAD_LEFT) . '/100' : 'exactos');
}

/* ---------- documentos (tablas, para que Dompdf los dibuje igual que el navegador) ---------- */

function cengi_acu_h($v)
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_acu_doc_encabezado($titulo, $subtitulo)
{
    return '<table class="cab"><tr><td><div class="marca">CENGICAÑA</div><div class="lema">Conocimiento que transforma la agroindustria</div></td>'
        . '<td style="text-align:right;"><div class="titulo">' . $titulo . '</div><div>' . $subtitulo . '</div></td></tr></table>';
}

function cengi_acu_doc_css()
{
    return '<style>body{font-family:"DejaVu Sans",Arial,Helvetica,sans-serif;font-size:10.5px;color:#1C2517;margin:0;}
        .doc{max-width:720px;margin:0 auto;padding:24px 28px;background:#fff;}
        table{width:100%;border-collapse:collapse;} td,th{vertical-align:top;}
        .cab{border-bottom:3px solid #2F6B12;margin-bottom:6px;} .cab td{padding-bottom:8px;}
        .marca{font-weight:bold;font-size:16px;color:#2F6B12;} .lema{font-size:9px;color:#5B6459;} .titulo{font-size:15px;font-weight:bold;}
        .h{border-bottom:1.5px solid #2F6B12;color:#2F6B12;font-weight:bold;font-size:10.5px;letter-spacing:.5px;padding:10px 0 3px;margin-bottom:5px;text-transform:uppercase;}
        .kv td{padding:2px 0;} .kv td.k{color:#5B6459;width:32%;} .kv td.v{font-weight:bold;}
        .lin th{border-bottom:1px solid #1C2517;font-size:9.5px;padding:4px;text-align:left;} .lin td{border-bottom:1px solid #D9DED4;padding:4px;} .num{text-align:right;}
        .tot td{padding:5px 4px;font-size:12px;font-weight:bold;border-top:2px solid #2F6B12;}
        .box{display:inline-block;width:10px;height:10px;border:1.2px solid #1C2517;text-align:center;line-height:10px;font-size:9px;margin-right:6px;}
        .pie{margin-top:14px;border-top:1px solid #D9DED4;padding-top:5px;font-size:9px;color:#5B6459;text-align:center;}
        .nota{margin-top:10px;font-size:9.5px;background:#F6F8F4;padding:7px 9px;}
        @media print{.no-print{display:none;}} @page{size:letter portrait;margin:12mm;}</style>';
}

function cengi_acu_kv(array $filas)
{
    $html = '<table class="kv">';
    foreach ($filas as [$k, $v]) {
        $html .= '<tr><td class="k">' . cengi_acu_h($k) . '</td><td class="v">' . $v . '</td></tr>';
    }
    return $html . '</table>';
}

/** Propuesta para el desarrollo del evento con la hoja de aceptacion (ppColabDocHTML). */
function cengi_acu_doc_propuesta(array $st)
{
    $ev = $st['evento'];
    $a = $st['acuerdo'];
    $cal = $st['calculo'];
    $emp = $st['empresas'][0] ?? ['nombre' => ''];
    $M = CENGI_ACU_MODALIDADES[$ev['modalidad']];
    $h = 'cengi_acu_h';
    $vacio = '______________________________';
    $lineas = '';
    foreach ($cal['lineas'] as $l) {
        $lineas .= '<tr><td><b>' . $h($l['c']) . '</b></td><td>' . $h($l['d']) . '</td><td class="num">' . $h(rtrim(rtrim(number_format($l['cant'], 2, '.', ''), '0'), '.')) . '</td><td class="num">'
            . number_format($l['u'], 2) . '</td><td class="num">' . number_format($l['sub'], 2) . '</td></tr>';
    }
    if ($cal['descuento'] > 0) {
        $lineas .= '<tr><td colspan="4" class="num">Descuento</td><td class="num">−' . number_format($cal['descuento'], 2) . '</td></tr>';
    }
    $tareas = static function ($resp) use ($a, $h) {
        return implode('', array_map(static function ($t) use ($h) { return '<div>• ' . $h($t['t']) . '</div>'; }, array_filter($a['tareas'], static function ($t) use ($resp) { return $t['resp'] === $resp; })));
    };
    $box = static function ($ok, $t) { return '<div style="margin:3px 0;"><span class="box">' . ($ok ? '✓' : '&nbsp;') . '</span>' . $t . '</div>'; };
    $mods = '';
    foreach (CENGI_ACU_MODALIDADES as $n => $m) {
        $mods .= $box($ev['modalidad'] === $n, '<b>Propuesta ' . $n . ':</b> ' . $h($m['n']));
    }
    $alims = $box(!$a['alims'], 'No deseo recibir ningún servicio de alimentación');
    foreach (CENGI_ACU_ALIM as $k => $v) {
        $it = null;
        foreach ($a['alims'] as $x) {
            if ($x['tipo'] === $k) {
                $it = $x;
            }
        }
        $alims .= $box((bool) $it, $h($v['n']) . ($it ? ' — ' . (int) $it['tiempos'] . ' por día' : ''));
    }
    $acept = $a['acept_representante'];
    return '<div class="doc">' . cengi_acu_doc_encabezado('PROPUESTA PARA EL DESARROLLO DE EVENTOS', 'Propuesta para: <b>' . $h($emp['nombre']) . '</b> · Emisión ' . $h(cengi_evp_fecha(date('Y-m-d'))))
        . '<div class="h">Evento y modalidad</div>' . cengi_acu_kv([
            ['Evento', $h($ev['nombre'] . ' · ' . $ev['fecha_texto'] . ' · ' . $ev['lugar'])],
            ['Modalidad', 'Propuesta ' . $ev['modalidad'] . ': ' . $h($M['n']) . ($ev['modalidad'] === 3 ? ' (' . ($a['modo'] === 'linea' ? 'en línea' : 'presencial') . ')' : '')],
            ['Participantes', $a['participantes'] . ' estimados · ' . ($ev['financia'] === 'empresa' ? 'sin costo para los participantes' : ($ev['financia'] === 'compartido' ? 'los participantes pagan una cuota' : 'CENGICAÑA asume el costo'))],
        ])
        . '<table style="margin-top:6px;"><tr><td style="width:50%;padding-right:10px;"><b>Rol del Centro</b>' . $tareas('Centro') . '</td><td><b>Responsabilidades de la empresa</b>' . $tareas('Empresa') . '</td></tr></table>'
        . '<div class="h">Detalle económico</div><table class="lin"><thead><tr><th>Concepto</th><th>Descripción</th><th class="num">Cantidad</th><th class="num">Costo unitario (Q)</th><th class="num">Subtotal (Q)</th></tr></thead><tbody>' . $lineas . '</tbody></table>'
        . '<table class="tot"><tr><td style="border:0;width:65%;"></td><td>Total</td><td class="num">' . cengi_acu_q($cal['total']) . '</td></tr></table>'
        . '<div class="h">Datos de la empresa</div>' . cengi_acu_kv([
            ['Recibo a nombre de', $h($a['fiscal_recibo'] ?: $vacio)], ['NIT', $h($a['fiscal_nit'] ?: $vacio)], ['Dirección fiscal', $h($a['fiscal_direccion'] ?: $vacio)],
            ['Descripción de la actividad', $h($a['fiscal_descripcion'] ?: $vacio)], ['Objetivo de la actividad', $h($a['fiscal_objetivo'] ?: $vacio)],
        ])
        . '<div class="h">Notas</div><div>1. El costo de refacciones contempla el total de unidades indicadas. Las refacciones adicionales se consideran excedente y se cobran según la cantidad adicional; deben cancelarse al finalizar el evento o por transferencia en un plazo máximo de una semana.</div>'
        . '<div>2. El evento no requiere uso del auditorio para ensayo.</div><div>3. El aporte se gestiona como donación; se extiende el recibo contable respectivo.</div>'
        . '<div class="h">Aceptación y elección de propuesta</div><div style="line-height:1.8;">Yo, <b>' . $h($acept ?: '________________________________') . '</b>, en representación de <b>' . $h($emp['nombre'] ?: '________________')
        . '</b>, declaro que acepto la propuesta presentada por CENGICAÑA y elijo el siguiente escenario y servicios:</div>'
        . '<table style="margin-top:6px;"><tr><td style="width:50%;padding-right:10px;">' . $mods . '</td><td>' . $alims . '</td></tr></table>'
        . '<table style="margin-top:14px;"><tr><td style="width:50%;">Participantes estimados: <b>' . (int) $a['participantes'] . '</b><br>Fecha del evento: <b>' . $h($ev['fecha_texto']) . '</b><br>Fecha de aceptación: <b>'
        . $h($a['acept_fecha'] ? cengi_evp_fecha($a['acept_fecha']) : '____________') . '</b></td><td style="text-align:center;"><div style="height:44px;"></div><div style="border-top:1px solid #1C2517;padding-top:4px;">Firma del representante</div></td></tr></table>'
        . ($a['acept_archivo'] ? '<div style="margin-top:8px;font-size:9.5px;color:#2F6B12;">✓ Hoja de aceptación firmada adjunta al expediente: ' . $h($a['acept_archivo_nombre']) . '</div>' : '')
        . '<div class="pie">(502) 7828-1000 · info@cengicana.org · www.cengicana.org</div></div>';
}

/** Solicitud de recibo por donacion para el area financiera (ppColabSolicitudHTML). */
function cengi_acu_doc_solicitud(array $st, array $r)
{
    $h = 'cengi_acu_h';
    $pagos = array_filter($st['pagos'], static function ($p) use ($r) { return (int) $p['recibo_id'] === (int) $r['id']; });
    $lista = implode('<br>', array_map(static function ($p) use ($h) {
        return $h($p['forma'] . ($p['banco'] ? ' · ' . $p['banco'] : '') . ' · Ref. ' . $p['referencia'] . ' · ' . cengi_evp_fecha($p['fecha'])) . ' · ' . cengi_acu_q($p['monto']);
    }, $pagos));
    return '<div class="doc">' . cengi_acu_doc_encabezado('SOLICITUD DE RECIBO POR DONACIÓN', 'Solicitada ' . $h(cengi_evp_fecha(substr($r['solicitado_en'], 0, 10))) . ' · ' . $h($r['solicitado_por'])
            . ($r['estado'] === 'emitido' ? '<div style="color:#2F6B12;font-weight:bold;">Emitido: ' . $h($r['numero']) . ' (' . $h(cengi_evp_fecha($r['fecha'])) . ')</div>' : ''))
        . '<div style="margin-top:12px;">' . cengi_acu_kv([
            ['A nombre de', $h($r['a_nombre'])], ['NIT', $h($r['nit'])], ['Dirección fiscal', $h($r['direccion'] ?: '—')],
            ['Monto', '<span style="font-size:14px;">' . cengi_acu_q($r['monto']) . '</span><div style="font-weight:normal;font-style:italic;">' . $h(cengi_acu_letras($r['monto'])) . '</div>'],
            ['Concepto', $h($r['concepto'])], ['Pagos que ampara', $lista ?: '—'],
        ]) . '</div><div class="nota">Documento interno para el área financiera. El recibo contable autorizado lo emite el área financiera y se adjunta al expediente del evento.</div></div>';
}

/** Expediente de la colaboracion (ppColabExpedienteHTML). */
function cengi_acu_doc_expediente(array $st)
{
    $h = 'cengi_acu_h';
    $ev = $st['evento'];
    $a = $st['acuerdo'];
    $R = $st['resumen'];
    $emp = $st['empresas'][0] ?? ['nombre' => ''];
    $tareasOk = count(array_filter($a['tareas'], static function ($t) { return !empty($t['ok']); }));
    $pagos = '';
    foreach ($st['pagos'] as $p) {
        $rc = null;
        foreach ($st['recibos'] as $r) {
            if ((int) $r['id'] === (int) $p['recibo_id']) {
                $rc = $r;
            }
        }
        $pagos .= '<div>• ' . $h(cengi_evp_fecha($p['fecha']) . ' · ' . ($p['tipo'] === 'aporte' ? 'Aporte' : 'Excedente') . ' · ' . $p['forma'] . ' ' . $p['referencia']) . ' · ' . cengi_acu_q($p['monto'])
            . ' · recibo ' . ($rc ? ($rc['estado'] === 'emitido' ? $h($rc['numero']) : 'solicitado al área financiera') : 'sin solicitar') . '</div>';
    }
    $hist = implode('', array_map(static function ($x) use ($h) { return '<div>' . $h(date('d/m/Y H:i', strtotime($x['creado'])) . ' · ' . $x['texto'] . ' · ' . $x['por'] . ($x['detalle'] ? ' — ' . $x['detalle'] : '')) . '</div>'; }, array_reverse($st['historial'])));
    $resp = array_filter(array_column($st['empresas'], 'respuesta'));
    return '<div class="doc">' . cengi_acu_doc_encabezado($h($ev['nombre']), $h($ev['fecha_texto'] . ' · ' . $ev['lugar']) . '<div>Expediente de colaboración</div>')
        . '<div class="h">Acuerdo</div>' . cengi_acu_kv([
            ['Empresa aliada', $h($emp['nombre'])], ['Modalidad', $h(CENGI_ACU_MODALIDADES[$ev['modalidad']]['n'])], ['Estado', $h(CENGI_ACU_ESTADOS[$a['estado']][0])],
            ['Aceptado por', $a['acept_representante'] ? $h($a['acept_representante'] . ' · ' . cengi_evp_fecha($a['acept_fecha']) . ($a['acept_archivo'] ? ' · hoja firmada adjunta' : ' · sin hoja firmada')) : '—'],
            ['Tareas cumplidas', $tareasOk . ' de ' . count($a['tareas'])],
        ])
        . '<div class="h">Resumen económico</div>' . cengi_acu_kv([
            ['Aporte acordado', cengi_acu_q($R['aporte'])], ['Excedente de alimentación', cengi_acu_q($R['excAlim']) . (!empty($a['excedente']['exonerado']) ? ' (exonerado)' : '')],
            ['Cargos adicionales', cengi_acu_q($R['cargos'])], ['Total final', cengi_acu_q($R['totalFinal'])], ['Pagado', cengi_acu_q($R['pagado'])], ['Saldo', cengi_acu_q(max(0, $R['totalFinal'] - $R['pagado']))],
        ])
        . '<div class="h">Pagos y recibos</div>' . ($pagos ?: '<div>Sin pagos.</div>')
        . '<div class="h">Resultados del evento</div>' . cengi_acu_kv([
            ['Participantes estimados / asistentes', $a['participantes'] . ' / ' . $ev['asistentes']],
            ['Índice de colaboración', $st['indice'] !== null ? number_format($st['indice'], 1) . ' de 5' : 'Sin respuesta de la empresa'],
            ['¿Participaría nuevamente?', $resp ? 'Sí ' . count(array_filter($resp, static function ($r) { return $r['participaria'] === 'si'; })) . ' · No ' . count(array_filter($resp, static function ($r) { return $r['participaria'] === 'no'; })) : '—'],
        ])
        . '<div class="h">Historial</div>' . ($hist ?: '<div>Sin movimientos.</div>') . '</div>';
}

/** Pagina imprimible (se abre en otra pestaña con boton de imprimir). */
function cengi_acu_pagina($titulo, $cuerpo)
{
    return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . cengi_acu_h($titulo) . '</title>'
        . cengi_acu_doc_css() . '<style>body{background:#EEF1EA;} .doc{margin:16px auto;border:1px solid #D9DED4;} .barra{text-align:center;padding:12px;} .barra button{padding:8px 16px;border:0;border-radius:8px;background:#2F6B12;color:#fff;font-weight:bold;cursor:pointer;} @media print{body{background:#fff;} .doc{margin:0;border:0;}}</style></head><body>'
        . '<div class="barra no-print"><button type="button" onclick="window.print()">Imprimir / guardar PDF</button></div>' . $cuerpo . '</body></html>';
}

/** PDF (Dompdf) de un documento, para adjuntarlo al correo. */
function cengi_acu_pdf($cuerpo)
{
    require_once __DIR__ . '/correo.php';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml('<!DOCTYPE html><html><head><meta charset="utf-8">' . cengi_acu_doc_css() . '</head><body>' . $cuerpo . '</body></html>', 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** Aportes de empresas aliadas (pestaña Cobros): resumen por evento en colaboracion. */
function cengi_acu_listado(PDO $db)
{
    $lista = [];
    $ids = $db->query("SELECT e.id FROM eventos e WHERE e.en_colaboracion = 1 AND EXISTS (SELECT 1 FROM evento_aliados al WHERE al.evento_id = e.id) ORDER BY e.fecha DESC, e.id DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $st = cengi_acu_estado($db, (int) $id, false);
        $a = $st['acuerdo'];
        $R = $st['resumen'];
        $accion = in_array($a['estado'], ['aceptada', 'parcial'], true) ? 'Registrar pago'
            : ($R['pagosSinRecibo'] ? 'Solicitar recibo' : ($R['recibosPendientes'] ? 'Cargar recibo'
            : ($a['estado'] === 'pagada' && $R['realizado'] ? 'Liquidar' : ($a['estado'] === 'liquidada' ? 'Cerrar' : 'Ver'))));
        $lista[] = ['id' => (int) $id, 'nombre' => $st['evento']['nombre'], 'fecha' => $st['evento']['fecha'], 'empresa' => $st['empresas'][0]['nombre'] ?? '',
            'modalidad' => CENGI_ACU_MODALIDADES[$st['evento']['modalidad']]['corto'], 'estado' => $a['estado'], 'resumen' => $R, 'accion' => $accion,
            'recibos' => array_map(static function ($r) { return $r['estado'] === 'emitido' ? $r['numero'] : 'solicitado'; }, $st['recibos'])];
    }
    return $lista;
}
