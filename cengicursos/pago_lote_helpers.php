<?php
/**
 * Registro de pagos en grupo de inscripciones (compartido por Validaciones y pagos → Cobros
 * pendientes y por la lista de Inscritos). Una fila por persona con monto pagado, forma, fecha y
 * boleta / N.° de proforma / comentario; un monto distinto a la cuota solo se acepta marcando
 * "aceptar diferencia". Sirve también cuando la proforma del ingenio no se generó en el sistema
 * o no hubo validación previa: el pago con proforma registra la validación del ingenio.
 * El modal y su JavaScript están en pago_lote_modal.php.
 */
require_once __DIR__ . '/inscripcion_registro.php';
require_once __DIR__ . '/cobranza_helpers.php';

const CENGI_INS_LOTE_FORMAS = ['Depósito', 'Transferencia', 'Cheque', 'Tarjeta', 'Efectivo', 'Proforma del ingenio'];

/** ¿Tiene un cobro pendiente que se pueda registrar en grupo? */
function cengi_ins_lote_pagable(array $s)
{
    if ($s['estado'] === 'Rechazado' || !in_array($s['tipo_pago'], ['Propio', 'Ingenio'], true)) {
        return false;
    }
    if (in_array(cengi_ins_estado_cobro($s), ['pagado', 'exonerado', 'reembolsado'], true)) {
        return false;
    }
    // Quien ya está en una proforma emitida en el sistema se paga desde esa proforma.
    return !($s['tipo_pago'] === 'Ingenio' && !empty($s['proforma_id']));
}

/** Atributos data-* de la casilla de una persona (los lee pago_lote_modal.php). */
function cengi_ins_lote_datos(array $s)
{
    $h = static function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $tarifa = CENGI_INS_CUOTAS[$s['cuota'] ?? ''][0] ?? '';
    return ' data-pago="1" data-id="' . (int) $s['id_solicitud'] . '" data-nombre="' . $h($s['nombre_participante']) . '" data-pagador="' . $h($s['tipo_pago'])
        . '" data-monto="' . ($s['monto'] === null ? '' : number_format((float) $s['monto'], 2, '.', '')) . '" data-moneda="' . $h($s['moneda'] ?: 'GTQ')
        . '" data-boleta="' . $h($s['boleta'] ?? '') . '" data-comprobante="' . (isset($s['monto_comprobante']) && $s['monto_comprobante'] !== null ? number_format((float) $s['monto_comprobante'], 2, '.', '') : '')
        . '" data-tarifa="' . $h($tarifa) . '"';
}

/**
 * Registra los pagos de las claves "s:<id>" / "m:<id>" de $post. El llamador abre la transacción.
 * Devuelve ['hechos' => n, 'omitidos' => [texto...], 'planes' => [planId => true], 'avisos' => [[solicitud, 'pagado'], ...]].
 * Las inscripciones sin convocatoria que quedan admitidas y aún no asignadas se asignan aquí;
 * las de convocatoria las asigna el llamador con cengi_ins_procesar_plan (cupo y lista de espera).
 */
function cengi_ins_pagos_lote(PDO $db, array $post, $usuario, $limitado, $hoy, $avisar)
{
    $formaComun = mb_substr(trim((string) ($post['pago_forma'] ?? '')), 0, 60, 'UTF-8');
    $fechaComun = (string) ($post['pago_fecha'] ?? '');
    $refComun = mb_substr(trim((string) ($post['pago_referencia'] ?? '')), 0, 120, 'UTF-8');
    $notaComun = mb_substr(trim((string) ($post['pago_nota'] ?? '')), 0, 300, 'UTF-8');
    $formasFila = (array) ($post['forma_fila'] ?? []);
    $fechasFila = (array) ($post['fecha_fila'] ?? []);
    $montosPagados = (array) ($post['monto_pagado'] ?? []);
    $refsFila = (array) ($post['ref_fila'] ?? []);
    $aceptarDif = (array) ($post['aceptar_dif'] ?? []);
    $r = ['hechos' => 0, 'omitidos' => [], 'planes' => [], 'avisos' => []];
    $hist = $db->prepare('INSERT INTO inscripcion_historial (solicitud_id,accion,usuario,motivo,antes,despues) VALUES (?,?,?,?,?,?)');

    foreach (array_unique((array) ($post['claves'] ?? [])) as $clave) {
        [$tipoClave, $idClave] = array_pad(explode(':', (string) $clave, 2), 2, 0);
        if (!in_array($tipoClave, ['s', 'm'], true)) {
            continue;
        }
        $s = cengi_ins_solicitud($db, (int) $idClave, true);
        if (!$s || ($limitado !== null && cengi_texto_normalizado($s['nombre_ingenios'] ?? '') !== cengi_texto_normalizado($limitado))) {
            $r['omitidos'][] = 'Inscripción #' . (int) $idClave . ': no existe o no tienes acceso';
            continue;
        }
        $sid = (int) $s['id_solicitud'];
        $nombre = $s['nombre_participante'];
        if ($s['estado'] === 'Rechazado') { $r['omitidos'][] = $nombre . ': la inscripción está rechazada'; continue; }
        $forma = mb_substr(trim((string) ($formasFila[$sid] ?? '')), 0, 60, 'UTF-8') ?: $formaComun;
        $fecha = (string) ($fechasFila[$sid] ?? '') ?: $fechaComun;
        $esProforma = $forma === 'Proforma del ingenio';
        if ($forma === '') { $r['omitidos'][] = $nombre . ': falta la forma de pago'; continue; }
        if (!cengi_pp_es_fecha($fecha) || $fecha > $hoy) { $r['omitidos'][] = $nombre . ': fecha de pago no válida (no puede ser futura)'; continue; }
        $pagadoTxt = str_replace(',', '', trim((string) ($montosPagados[$sid] ?? '')));
        $pagado = is_numeric($pagadoTxt) ? round((float) $pagadoTxt, 2) : null;
        $esperado = $s['monto'] === null ? null : (float) $s['monto'];
        // "Boleta o comentario": con números es la referencia; solo texto ("pagado", "efectivo") es un comentario.
        $textoFila = trim((string) ($refsFila[$sid] ?? ''));
        $comentarioFila = $textoFila !== '' && !preg_match('/\d/', $textoFila) ? mb_substr($textoFila, 0, 150, 'UTF-8') : '';
        $ref = ($comentarioFila === '' ? mb_substr($textoFila, 0, 120, 'UTF-8') : '') ?: $refComun;
        if ($s['tipo_pago'] === 'Propio') {
            if (in_array($s['pago_estado'], ['pagado', 'reembolsado'], true)) { $r['omitidos'][] = $nombre . ': ya estaba ' . $s['pago_estado']; continue; }
            if ($s['pago_estado'] === 'en revisión' && cengi_cob_boletas_repetidas($db, $s['boleta'], $sid)) { $r['omitidos'][] = $nombre . ': la boleta se repite en otra inscripción'; continue; }
            $ref = $ref ?: ($s['boleta'] ?: '');
        } elseif ($s['tipo_pago'] === 'Ingenio') {
            if (!$esProforma) { $r['omitidos'][] = $nombre . ': lo paga su ingenio; elige la forma «Proforma del ingenio»'; continue; }
            if (($s['validacion_ingenio'] ?? '') === 'rechazada') { $r['omitidos'][] = $nombre . ': el ingenio no validó la inscripción'; continue; }
            if ($s['grupo_pago_id']) {
                $q = $db->prepare('SELECT proforma_id, estado FROM pagos_grupales WHERE id = ?'); $q->execute([(int) $s['grupo_pago_id']]);
                $gr = $q->fetch(PDO::FETCH_ASSOC);
                if ($gr && ($gr['proforma_id'] || $gr['estado'] === 'pagado')) { $r['omitidos'][] = $nombre . ': ya está en una proforma del sistema; registra el pago en esa proforma'; continue; }
            }
            if (cengi_ins_estado_cobro($s) === 'pagado') { $r['omitidos'][] = $nombre . ': ya estaba pagado'; continue; }
            if ($ref === '') { $r['omitidos'][] = $nombre . ': indica el número de proforma'; continue; }
        } else {
            $r['omitidos'][] = $nombre . ': no tiene costo'; continue;
        }
        if ($pagado === null || $pagado <= 0) { $r['omitidos'][] = $nombre . ': indica el monto pagado'; continue; }
        $diferencia = $esperado === null ? null : round($pagado - $esperado, 2);
        if ($esperado === null && empty($aceptarDif[$sid])) { $r['omitidos'][] = $nombre . ': no tiene cuota definida; marca «aceptar» para tomar el monto pagado como cuota'; continue; }
        if ($diferencia !== null && abs($diferencia) > 0.009 && empty($aceptarDif[$sid])) {
            $r['omitidos'][] = $nombre . ': pagó ' . cengi_ins_dinero($pagado, $s['moneda']) . ' y la cuota es ' . cengi_ins_dinero($esperado, $s['moneda']) . ' (marca «aceptar diferencia» si es correcto)'; continue;
        }
        $notaFila = trim(implode(' · ', array_filter([
            $comentarioFila,
            $notaComun,
            $esperado === null ? 'Cuota tomada del monto pagado' : ($diferencia !== null && abs($diferencia) > 0.009 ? 'Pagó ' . cengi_ins_dinero($pagado, $s['moneda']) . ' (cuota ' . cengi_ins_dinero($esperado, $s['moneda']) . ', diferencia ' . cengi_ins_dinero($diferencia, $s['moneda']) . ')' : ''),
        ])));
        $antes = json_encode($s, JSON_UNESCAPED_UNICODE);
        $grupoAnterior = $s['grupo_pago_id'];
        if ($s['tipo_pago'] === 'Ingenio') {
            // Pagado con una proforma fuera del sistema: sale de la orden grupal pendiente, queda pagado
            // por sí mismo y, si faltaba, se registra la validación del ingenio.
            $db->prepare("UPDATE solicitudes_inscripcion SET grupo_pago_id = NULL,
                validacion_ingenio = 'validada',
                validacion_ingenio_por = IF(COALESCE(validacion_ingenio_por, '') = '', ?, validacion_ingenio_por),
                validacion_ingenio_en = COALESCE(validacion_ingenio_en, NOW()),
                validacion_ingenio_nota = IF(COALESCE(validacion_ingenio_nota, '') = '', ?, validacion_ingenio_nota) WHERE id_solicitud = ?")
                ->execute([$usuario . ' (Administración)', 'Pagado por el ingenio con ' . $ref, $sid]);
        }
        $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado = 'pagado', pago_forma = ?, pago_referencia = ?, pago_fecha = ?,
            monto_comprobante = ?, monto = COALESCE(monto, ?), pago_nota = IF(? = '', pago_nota, ?),
            pago_registrado_en = NOW(), pago_registrado_por = ?, pago_verificado_por = ?, pago_verificado_en = NOW() WHERE id_solicitud = ?")
            ->execute([$forma, $ref ?: 'Sin referencia', $fecha, $pagado, $pagado, $notaFila, $notaFila, $usuario, $usuario, $sid]);
        cengi_ins_recalcular_grupo($db, $grupoAnterior);
        $despues = cengi_ins_solicitud($db, $sid);
        if ($despues['planificacion_id']) {
            $r['planes'][$despues['planificacion_id']] = true;
        } elseif (in_array($despues['estado'], ['Pendiente', 'Espera'], true) && cengi_ins_admitida($despues) && $despues['curso_id']) {
            // Sin convocatoria: con el pago registrado se asigna directamente al curso.
            cengi_ins_asignar($db, $despues, $usuario);
            $despues = cengi_ins_solicitud($db, $sid);
        }
        $hist->execute([$sid, 'pago_lote', $usuario, trim($forma . ' ' . ($ref ?: '') . ' · ' . cengi_ins_dinero($pagado, $s['moneda']) . ' · ' . cengi_pp_fecha($fecha) . ($notaFila !== '' ? ' · ' . $notaFila : '')), $antes, json_encode($despues, JSON_UNESCAPED_UNICODE)]);
        if ($avisar && $s['tipo_pago'] === 'Propio' && $s['planificacion_id']) {
            $r['avisos'][] = [array_merge($s, ['pago_referencia' => $ref]), 'pagado'];
        }
        $r['hechos']++;
    }
    return $r;
}
