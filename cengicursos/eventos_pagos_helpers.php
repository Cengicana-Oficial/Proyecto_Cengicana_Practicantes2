<?php
/**
 * Pagos y confirmacion de participacion de eventos (Control QR de eventos).
 * Esquema: cengicursos/migrations/20261010_eventos_pagos_confirmacion.sql.
 *
 * Evento pagado (como en el prototipo):
 *   1. La persona se inscribe y adjunta su comprobante -> pago_estado 'por_validar'
 *      (o 'pendiente' si el evento permite inscribirse sin comprobante).
 *   2. Administracion (permiso validar_pagos_cengi) valida o rechaza con motivo; se le
 *      avisa por correo. Tambien puede registrar un comprobante recibido por correo o
 *      exonerar (invitados).
 *   3. Capacitacion (permiso de gestion de eventos) confirma la participacion: el QR queda
 *      habilitado para el ingreso y se envia el gafete en PDF.
 * Evento gratuito: la participacion queda confirmada al inscribirse.
 * Solo los participantes confirmados pueden registrar ingreso (camara, enlace o manual).
 */

require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/eventos_helpers.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;

const CENGI_EVP_ESTADOS = [
    'pendiente' => ['Sin pago', 'is-rejected'],
    'por_validar' => ['Comprobante por validar', 'is-waiting'],
    'validado' => ['Pago validado', 'is-active'],
    'rechazado' => ['Comprobante rechazado', 'is-rejected'],
    'exonerado' => ['Invitado / exonerado', 'is-neutral'],
];
const CENGI_EVP_CATEGORIAS = ['Participante', 'Ponente', 'Organizador', 'Expositor', 'Invitado especial', 'Prensa', 'Staff'];

function cengi_evp_puede_validar()
{
    return cengi_es_admin() || cengi_tiene_permiso('validar_pagos_cengi');
}

function cengi_evp_puede_ver_pagos()
{
    return cengi_evp_puede_validar() || cengi_tiene_permiso('ver_pagos_cengi') || cengi_puede_gestionar_eventos();
}

/** Pago resuelto (validado o exonerado) o evento gratuito: se puede confirmar. */
function cengi_evp_listo(array $p)
{
    return ($p['modalidad_pago'] ?? '') !== 'Pagado' || in_array($p['pago_estado'] ?? '', ['validado', 'exonerado'], true);
}

function cengi_evp_es_usd(array $p)
{
    return ($p['pago_origen'] ?? '') === 'extranjero' && (float) ($p['costo_usd'] ?? 0) > 0;
}

function cengi_evp_monto_esperado(array $p)
{
    return cengi_evp_es_usd($p) ? (float) $p['costo_usd'] : (float) ($p['costo'] ?? 0);
}

function cengi_evp_dinero($monto, $usd = false)
{
    $monto = (float) $monto;
    return ($usd ? 'US$' : 'Q') . number_format($monto, fmod($monto, 1.0) ? 2 : 0, '.', ',');
}

function cengi_evp_fecha($fecha)
{
    $ts = $fecha ? strtotime((string) $fecha) : false;
    if (!$ts) {
        return 'fecha por confirmar';
    }
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return (int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}

/** SELECT comun de participantes de evento con los datos del evento (alias ep / e). */
function cengi_evp_select()
{
    return "SELECT ep.*, e.nombre AS evento_nombre, e.modalidad_pago, e.costo, e.costo_usd, e.fecha AS evento_fecha, e.hora AS evento_hora,
            e.lugar AS evento_lugar, e.tipo AS evento_tipo, e.estado AS evento_estado, e.logo AS evento_logo, e.color AS evento_color,
            COALESCE(NULLIF(p.nombre_participantes, ''), ep.nombre_invitado) AS nombre,
            COALESCE(NULLIF(p.correo_participantes, ''), NULLIF(ep.correo_invitado, '')) AS correo,
            COALESCE(ip.nombre_ingenios, ie.nombre_ingenios, NULLIF(ep.institucion_invitado, ''), 'Invitado externo') AS ingenio
        FROM evento_participantes ep
        INNER JOIN eventos e ON e.id = ep.evento_id
        LEFT JOIN participantes p ON p.id = ep.participante_id
        LEFT JOIN ingenios ip ON ip.id = p.ingenio_id
        LEFT JOIN ingenios ie ON ie.id = ep.ingenio_id";
}

function cengi_evp_participante(PDO $db, $id)
{
    $stmt = $db->prepare(cengi_evp_select() . ' WHERE ep.id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Participantes por ids (opcionalmente acotados a un evento: nunca se confia en el cliente). */
function cengi_evp_participantes(PDO $db, array $ids, $eventoId = null)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $sql = cengi_evp_select() . ' WHERE ep.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params = $ids;
    if ($eventoId !== null) {
        $sql .= ' AND ep.evento_id = ?';
        $params[] = (int) $eventoId;
    }
    $stmt = $db->prepare($sql . ' ORDER BY nombre');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Correo registrado en Comunicaciones (categoria Eventos) a una sola persona.
 * Devuelve true (enviado), false (fallo el envio) o null (no tiene correo valido).
 */
function cengi_evp_avisar(PDO $db, array $p, $asunto, $mensaje)
{
    if (!filter_var((string) $p['correo'], FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    [, $ok] = cengi_pp_enviar_comunicacion($db, [
        'categoria' => 'Eventos', 'origen' => 'sistema', 'referencia' => 'evento:' . $p['evento_id'],
        'asunto' => $asunto, 'mensaje' => $mensaje . "\n\nCoordinación de Capacitación — CENGICAÑA",
    ], [['nombre' => $p['nombre'], 'correo' => $p['correo']]]);
    return $ok > 0;
}

/**
 * Registra o resuelve el pago de un participante (Administracion).
 * $estado: 'por_validar' (solo guarda el comprobante), 'validado', 'rechazado', 'exonerado'
 * o 'pendiente' (deshace). $datos: boleta, banco, fecha, monto, origen, recibo (ruta ya
 * guardada), nota, forzar_monto. Devuelve el texto para el usuario.
 */
function cengi_evp_resolver_pago(PDO $db, $id, $estado, array $datos)
{
    $p = cengi_evp_participante($db, $id);
    if (!$p) {
        throw new InvalidArgumentException('El participante ya no existe.');
    }
    if ($p['modalidad_pago'] !== 'Pagado') {
        throw new InvalidArgumentException('El evento es gratuito: no lleva control de pago.');
    }
    if (!isset(CENGI_EVP_ESTADOS[$estado])) {
        throw new InvalidArgumentException('Estado de pago no válido.');
    }
    if ($p['confirmado_en'] && !in_array($estado, ['validado', 'exonerado'], true)) {
        throw new InvalidArgumentException('La participación ya está confirmada (tiene QR); no se puede dejar el pago sin resolver.');
    }

    $origen = in_array($datos['origen'] ?? '', ['nacional', 'extranjero'], true) ? $datos['origen'] : $p['pago_origen'];
    $boleta = mb_substr(trim((string) ($datos['boleta'] ?? $p['pago_boleta'])), 0, 60, 'UTF-8');
    $banco = mb_substr(trim((string) ($datos['banco'] ?? $p['pago_banco'])), 0, 120, 'UTF-8');
    $fecha = trim((string) ($datos['fecha'] ?? $p['pago_fecha']));
    $montoTexto = trim((string) ($datos['monto'] ?? $p['pago_monto']));
    $recibo = $datos['recibo'] ?? $p['recibo_pago'];
    $nota = mb_substr(trim((string) ($datos['nota'] ?? '')), 0, 500, 'UTF-8');

    if (in_array($estado, ['por_validar', 'validado'], true)) {
        $errores = [];
        if ($boleta === '') {
            $errores[] = 'el número de boleta o transferencia';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) {
            $errores[] = 'la fecha del pago';
        }
        if (!is_numeric($montoTexto) || (float) $montoTexto <= 0) {
            $errores[] = 'el monto del comprobante';
        }
        if (!$recibo) {
            $errores[] = 'el archivo del comprobante';
        }
        if ($errores) {
            throw new InvalidArgumentException('Falta ' . implode(', ', $errores) . '.');
        }
        $p['pago_origen'] = $origen;
        $esperado = cengi_evp_monto_esperado($p);
        if ($estado === 'validado' && $esperado > 0 && abs((float) $montoTexto - $esperado) > 0.009 && empty($datos['forzar_monto'])) {
            throw new DomainException('El comprobante es por ' . cengi_evp_dinero($montoTexto, cengi_evp_es_usd($p)) . ' y el costo es '
                . cengi_evp_dinero($esperado, cengi_evp_es_usd($p)) . '. ¿Validarlo de todos modos?');
        }
    }
    if ($estado === 'rechazado' && $nota === '') {
        throw new InvalidArgumentException('Escribe el motivo del rechazo; se envía al participante.');
    }

    $resuelto = in_array($estado, ['validado', 'rechazado', 'exonerado'], true);
    $db->prepare('UPDATE evento_participantes SET pago_estado = ?, pagado = ?, pago_origen = ?, pago_boleta = ?, pago_banco = ?, pago_fecha = ?, pago_monto = ?,
            recibo_pago = ?, pago_nota = ?, pago_validado_por = ?, pago_validado_en = ? WHERE id = ?')
        ->execute([$estado, in_array($estado, ['validado', 'exonerado'], true) ? 1 : 0, $origen, $boleta ?: null, $banco ?: null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : null, is_numeric($montoTexto) ? round((float) $montoTexto, 2) : null,
            $recibo ?: null, $nota ?: null, $resuelto ? cengi_pp_usuario_nombre() : null, $resuelto ? date('Y-m-d H:i:s') : null, (int) $id]);

    $evento = $p['evento_nombre'];
    // Pago validado (o invitado/exonerado): la persona pasa de inmediato a participante del
    // evento; se confirma su participación y se le envía el gafete con el QR de ingreso.
    if (in_array($estado, ['validado', 'exonerado'], true) && !$p['confirmado_en']) {
        $fila = cengi_evp_participantes($db, [(int) $id]);
        $r = $fila ? cengi_evp_confirmar($db, $fila) : ['confirmados' => 0, 'enviados' => 0, 'sin_correo' => [], 'fallidos' => []];
        $gafete = $r['enviados'] ? ' Se le envió su gafete con el código QR.'
            : ($r['sin_correo'] ? ' No tiene correo: entrégale el gafete impreso.' : ($r['fallidos'] ? ' No se pudo enviar el gafete (revisa Comunicaciones).' : ''));
        return $p['nombre'] . ($estado === 'validado' ? ': pago validado; ya es participante del evento.' : ' quedó como invitado / exonerado y ya es participante del evento.') . $gafete;
    }
    if ($estado === 'validado') {
        return 'Pago de ' . $p['nombre'] . ' actualizado.';
    }
    if ($estado === 'rechazado') {
        $avisado = cengi_evp_avisar($db, $p, 'Comprobante no válido: ' . $evento,
            "Hola {nombre}:\n\nNo pudimos validar tu comprobante de pago para {$evento}:\n{$nota}\n\n" . cengi_evp_instrucciones_pago($db, $p));
        return 'Comprobante de ' . $p['nombre'] . ' rechazado.' . cengi_evp_texto_aviso($avisado, ' con el motivo');
    }
    if ($estado === 'exonerado') {
        return $p['nombre'] . ' quedó como invitado / exonerado.';
    }
    return $estado === 'por_validar' ? 'Comprobante registrado; queda por validar.' : 'El pago quedó pendiente.';
}

function cengi_evp_texto_aviso($avisado, $extra = '')
{
    if ($avisado === null) {
        return ' No tiene correo registrado: avísale por otro medio.';
    }
    return $avisado ? ' Se le avisó por correo' . $extra . '.' : ' No se pudo enviar el correo (revisa Comunicaciones).';
}

/** Instrucciones de pago con el contacto de soporte (recordatorios y rechazos). */
function cengi_evp_instrucciones_pago(PDO $db, array $p)
{
    $s = cengi_evento_soporte_pagos($db);
    $contacto = array_filter([
        $s['correo'] ? 'Correo: ' . $s['correo'] : '',
        $s['whatsapp'] ? 'WhatsApp: ' . $s['whatsapp'] : '',
        $s['horario'] ? 'Horario: ' . $s['horario'] : '',
    ]);
    return 'Costo: ' . cengi_evp_dinero(cengi_evp_monto_esperado($p), cengi_evp_es_usd($p)) . ".\n"
        . "Solicita los datos de la cuenta a {$s['nombre']} y envía tu comprobante (foto o PDF con número de boleta, fecha y monto) para validarlo.\n"
        . ($contacto ? implode("\n", $contacto) . "\n" : '')
        . 'CENGICAÑA nunca te pedirá pagar a cuentas personales.';
}

/** Recordatorio de pago a quienes estan pendientes o con comprobante rechazado. */
function cengi_evp_recordar(PDO $db, array $participantes)
{
    $r = ['enviados' => 0, 'sin_correo' => [], 'fallidos' => [], 'omitidos' => 0];
    foreach ($participantes as $p) {
        if ($p['modalidad_pago'] !== 'Pagado' || !in_array($p['pago_estado'], ['pendiente', 'rechazado'], true)) {
            $r['omitidos']++;
            continue;
        }
        $motivo = $p['pago_estado'] === 'rechazado' && $p['pago_nota'] ? "\nTu comprobante anterior no se pudo validar: {$p['pago_nota']}\n" : '';
        $ok = cengi_evp_avisar($db, $p, 'Recordatorio de pago: ' . $p['evento_nombre'],
            "Hola {nombre}:\n\nTu inscripción a {$p['evento_nombre']} (" . cengi_evp_fecha($p['evento_fecha']) . ") está pendiente de pago.{$motivo}\n" . cengi_evp_instrucciones_pago($db, $p));
        if ($ok) {
            $r['enviados']++;
            $db->prepare('UPDATE evento_participantes SET pago_recordatorio_en = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $p['id']]);
        } elseif ($ok === null) {
            $r['sin_correo'][] = $p['nombre'];
        } else {
            $r['fallidos'][] = $p['nombre'];
        }
    }
    return $r;
}

/**
 * Confirma la participacion (Capacitacion) de los participantes listos y les envia el
 * gafete. Los que aun no tienen el pago resuelto se omiten.
 */
function cengi_evp_confirmar(PDO $db, array $participantes, $enviarGafete = true)
{
    $r = ['confirmados' => 0, 'sin_pago' => [], 'ya' => 0, 'enviados' => 0, 'sin_correo' => [], 'fallidos' => []];
    $porEvento = [];
    $stmt = $db->prepare('UPDATE evento_participantes SET confirmado_en = ?, confirmado_por = ? WHERE id = ? AND confirmado_en IS NULL');
    foreach ($participantes as $p) {
        if ($p['confirmado_en']) {
            $r['ya']++;
            continue;
        }
        if (!cengi_evp_listo($p)) {
            $r['sin_pago'][] = $p['nombre'];
            continue;
        }
        $stmt->execute([date('Y-m-d H:i:s'), cengi_pp_usuario_nombre(), (int) $p['id']]);
        $r['confirmados']++;
        $porEvento[(int) $p['evento_id']][] = (int) $p['id'];
    }
    if ($enviarGafete) {
        foreach ($porEvento as $eventoId => $ids) {
            $g = cengi_evp_enviar_gafetes($db, $eventoId, $ids);
            $r['enviados'] += $g['enviados'];
            $r['sin_correo'] = array_merge($r['sin_correo'], $g['sin_correo']);
            $r['fallidos'] = array_merge($r['fallidos'], $g['fallidos']);
        }
    }
    return $r;
}

function cengi_evp_qr_data_uri($codigo)
{
    $opciones = new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'scale' => 6, 'imageTransparent' => false]);
    return (new QRCode($opciones))->render((string) $codigo);
}

/** PDF del gafete (tarjeta .cengi-badge-card de eventos_qr.php) para adjuntarlo al correo. */
function cengi_evp_gafete_pdf(array $p)
{
    $h = static function ($v) {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    };
    $detalle = trim(cengi_evp_fecha($p['evento_fecha']) . ($p['evento_hora'] ? ' · ' . $p['evento_hora'] : '') . ($p['evento_lugar'] ? ' · ' . $p['evento_lugar'] : ''));
    // Color y logo del evento ("Editar evento"); el logo va embebido porque Dompdf no lee rutas remotas.
    $colorBanda = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['evento_color'] ?? '')) ? $p['evento_color'] : '#1f5632';
    $logo = !empty($p['evento_logo']) ? cengi_evento_imagen_data_uri($p['evento_logo']) : null;
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page { margin: 0; }
        body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; color: #294033; margin: 0; padding: 40px; }
        .gaf-card { width: 320px; margin: 0 auto; border: 1px solid #dbe8dc; border-radius: 14px; overflow: hidden; }
        .gaf-top { background-color: ' . $colorBanda . '; padding: 18px 20px; color: #ffffff; text-align: center; }
        .gaf-logo { background: #ffffff; border-radius: 8px; padding: 5px 10px; display: inline-block; margin-bottom: 8px; }
        .gaf-logo img { max-height: 34px; max-width: 180px; }
        .gaf-eyebrow { font-size: 11px; letter-spacing: 1px; text-transform: uppercase; opacity: .9; }
        .gaf-event { margin-top: 6px; font-size: 16px; font-weight: bold; }
        .gaf-det { margin-top: 4px; font-size: 10px; opacity: .9; }
        .gaf-body { padding: 20px; text-align: center; }
        .gaf-cat { display: inline-block; background: #EAF6DD; color: #2F6B12; border-radius: 20px; padding: 3px 12px; font-size: 10px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .gaf-name { margin-top: 10px; font-size: 19px; font-weight: bold; color: #294033; }
        .gaf-company { margin: 4px 0 16px; color: #55705f; font-size: 13px; }
        .gaf-qr-box { width: 160px; height: 160px; margin: 0 auto; border: 1.5px solid #dbe8dc; border-radius: 10px; text-align: center; }
        .gaf-qr-box img { width: 140px; height: 140px; margin-top: 9px; }
        .gaf-code { margin-top: 12px; color: #55705f; font-size: 11px; letter-spacing: .5px; }
    </style></head><body><div class="gaf-card">
        <div class="gaf-top">' . ($logo ? '<div class="gaf-logo"><img src="' . $logo . '" alt=""></div>' : '') . '<div class="gaf-eyebrow">CENGICAÑA &middot; ' . $h($p['evento_tipo'] ?: 'Evento') . '</div><div class="gaf-event">' . $h($p['evento_nombre']) . '</div><div class="gaf-det">' . $h($detalle) . '</div></div>
        <div class="gaf-body"><span class="gaf-cat">' . $h($p['categoria_gafete'] ?: 'Participante') . '</span><div class="gaf-name">' . $h($p['nombre']) . '</div><div class="gaf-company">' . $h($p['ingenio']) . '</div>
        <div class="gaf-qr-box"><img src="' . cengi_evp_qr_data_uri($p['codigo_qr']) . '" alt="QR"></div><div class="gaf-code">' . $h($p['codigo_qr']) . '</div></div>
    </div></body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, 340, 500], 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** Envia el gafete (PDF adjunto) solo a participantes confirmados del evento. */
function cengi_evp_enviar_gafetes(PDO $db, $eventoId, array $ids)
{
    require_once __DIR__ . '/correo.php';
    $r = ['enviados' => 0, 'sin_correo' => [], 'fallidos' => [], 'sin_confirmar' => []];
    foreach (cengi_evp_participantes($db, $ids, $eventoId) as $p) {
        $nombre = (string) ($p['nombre'] ?: 'Participante');
        if (!$p['confirmado_en']) {
            $r['sin_confirmar'][] = $nombre;
            continue;
        }
        $correo = trim((string) $p['correo']);
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $r['sin_correo'][] = $nombre;
            continue;
        }
        try {
            $pdf = cengi_evp_gafete_pdf($p);
            $participanteNombre = $nombre;
            $eventoNombre = (string) $p['evento_nombre'];
            $codigoQr = (string) $p['codigo_qr'];
            $html = '';
            require __DIR__ . '/correos/plantilla_gafete_evento.php';
            $ok = cengi_enviar_correo($correo, $nombre, 'Su gafete para el evento: ' . $eventoNombre, $html, [[
                'contenido' => $pdf,
                'nombre' => 'gafete-' . (cengi_diploma_sanitizar_segmento($nombre) ?: 'participante') . '.pdf',
                'tipo' => 'application/pdf',
            ]]);
        } catch (Throwable $e) {
            error_log('cengi_evp_enviar_gafetes: ' . $nombre . ' (evento_participante ' . $p['id'] . '): ' . $e->getMessage());
            $ok = false;
        }
        if ($ok) {
            $r['enviados']++;
            $db->prepare('UPDATE evento_participantes SET gafete_enviado_en = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $p['id']]);
        } else {
            $r['fallidos'][] = $nombre;
        }
    }
    return $r;
}

/**
 * Registra el ingreso por codigo QR o id dentro de un evento. Solo participantes
 * confirmados. Devuelve ['ok', 'estado' => registrado|ya|no_confirmado|no_existe, 'nombre', 'hora'].
 */
function cengi_evp_registrar_ingreso(PDO $db, $eventoId, $codigo = null, $id = null)
{
    $sql = cengi_evp_select() . ' WHERE ep.evento_id = ? AND ' . ($id ? 'ep.id = ?' : 'ep.codigo_qr = ?');
    $stmt = $db->prepare($sql);
    $stmt->execute([(int) $eventoId, $id ? (int) $id : strtoupper(trim((string) $codigo))]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return ['ok' => false, 'estado' => 'no_existe', 'mensaje' => 'Este código no pertenece a este evento.'];
    }
    $base = ['nombre' => $p['nombre'], 'ingenio' => $p['ingenio'], 'categoria' => $p['categoria_gafete'], 'id' => (int) $p['id']];
    if (!$p['confirmado_en']) {
        $motivo = $p['modalidad_pago'] === 'Pagado' && !cengi_evp_listo($p) ? 'su pago aún no está validado' : 'su participación aún no está confirmada';
        return $base + ['ok' => false, 'estado' => 'no_confirmado', 'mensaje' => 'No puede ingresar todavía: ' . $motivo . '.'];
    }
    if ($p['ingreso_en']) {
        return $base + ['ok' => true, 'estado' => 'ya', 'hora' => date('H:i', strtotime($p['ingreso_en'])), 'mensaje' => 'Ya había ingresado a las ' . date('H:i', strtotime($p['ingreso_en'])) . '.'];
    }
    $db->prepare('UPDATE evento_participantes SET ingreso_en = ? WHERE id = ? AND ingreso_en IS NULL')->execute([date('Y-m-d H:i:s'), (int) $p['id']]);
    return $base + ['ok' => true, 'estado' => 'registrado', 'hora' => date('H:i'), 'mensaje' => 'Ingreso registrado.'];
}

/** Cobros por evento pagado (pestaña "Cobros por evento"). */
function cengi_evp_cobros(PDO $db)
{
    return $db->query("SELECT e.id, e.nombre, e.fecha, e.costo, e.costo_usd, e.estado,
            COUNT(ep.id) AS registrados,
            COALESCE(SUM(ep.pago_estado = 'validado'), 0) AS validados,
            COALESCE(SUM(ep.pago_estado = 'por_validar'), 0) AS por_validar,
            COALESCE(SUM(ep.pago_estado IN ('pendiente', 'rechazado')), 0) AS pendientes,
            COALESCE(SUM(ep.pago_estado = 'exonerado'), 0) AS exonerados,
            COALESCE(SUM(CASE WHEN ep.pago_estado = 'validado' AND NOT (ep.pago_origen = 'extranjero' AND e.costo_usd > 0) THEN COALESCE(ep.pago_monto, e.costo) END), 0) AS cobrado_q,
            COALESCE(SUM(CASE WHEN ep.pago_estado = 'validado' AND ep.pago_origen = 'extranjero' AND e.costo_usd > 0 THEN COALESCE(ep.pago_monto, e.costo_usd) END), 0) AS cobrado_usd,
            COALESCE(SUM(CASE WHEN ep.pago_estado IN ('pendiente', 'rechazado', 'por_validar') AND NOT (ep.pago_origen = 'extranjero' AND e.costo_usd > 0) THEN e.costo END), 0) AS pendiente_q,
            COALESCE(SUM(CASE WHEN ep.pago_estado IN ('pendiente', 'rechazado', 'por_validar') AND ep.pago_origen = 'extranjero' AND e.costo_usd > 0 THEN e.costo_usd END), 0) AS pendiente_usd
        FROM eventos e LEFT JOIN evento_participantes ep ON ep.evento_id = e.id
        WHERE e.modalidad_pago = 'Pagado'
        GROUP BY e.id ORDER BY e.fecha DESC, e.id DESC")->fetchAll(PDO::FETCH_ASSOC);
}
