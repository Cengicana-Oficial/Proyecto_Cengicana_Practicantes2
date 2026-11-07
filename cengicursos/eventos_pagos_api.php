<?php
/**
 * Acciones de pagos, confirmacion e ingreso de Control QR de eventos (eventos_qr.php).
 *   GET  ?recibo=<evento_participante_id>   -> comprobante (solo con permiso; no se expone la ruta)
 *   GET  ?exportar=cobros                   -> detalle de cobros en Excel
 *   GET  ?ingresos=<evento_id>              -> ultimos ingresos y conteo (modal de ingreso)
 *   POST accion=pago        id, estado, boleta, banco, fecha, monto, origen, nota, forzar_monto, recibo (archivo)
 *   POST accion=confirmar   ids[] (+ evento_id) o todos=1   -> confirma y envia gafete
 *   POST accion=recordar    ids[] (+ evento_id) o todos=1   -> recordatorio de pago
 *   POST accion=ingreso     evento_id, codigo | ids[]       -> registra ingreso (solo confirmados)
 *   POST accion=soporte     nombre, whatsapp, correo, horario
 * Permisos: pagos y exportacion -> validar_pagos_cengi (Administracion); confirmar e
 * ingreso -> gestion de eventos (Capacitacion); recordar y soporte -> cualquiera de los dos.
 */
require_once __DIR__ . '/eventos_pagos_helpers.php';

function cengi_evp_json($datos, $codigo = 200)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = conectar();
$validar = cengi_evp_puede_validar();
$gestionar = cengi_puede_gestionar_eventos();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['recibo'])) {
        $p = cengi_evp_puede_ver_pagos() ? cengi_evp_participante($db, (int) $_GET['recibo']) : null;
        $base = realpath(__DIR__ . '/../uploads');
        $url = $p ? cengi_normalizar_url_archivo($p['recibo_pago']) : '';
        $archivo = $url !== '' ? realpath(__DIR__ . '/..' . $url) : false;
        if (!$base || !$archivo || strpos($archivo, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($archivo)) {
            http_response_code(404);
            exit('El comprobante no existe o ya no está disponible.');
        }
        $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($archivo));
        header('Content-Disposition: inline; filename="comprobante-' . (int) $p['id'] . '.' . $extension . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($archivo);
        exit;
    }
    if (($_GET['exportar'] ?? '') === 'cobros') {
        if (!cengi_evp_puede_ver_pagos()) {
            http_response_code(403);
            exit('No tienes permiso para descargar los cobros.');
        }
        require_once __DIR__ . '/classes/export_helpers.php';
        $filas = [];
        $stmt = $db->query(cengi_evp_select() . " WHERE e.modalidad_pago = 'Pagado' ORDER BY e.fecha DESC, e.id, nombre");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $usd = cengi_evp_es_usd($p);
            $filas[] = [$p['evento_nombre'], $p['evento_fecha'], $p['nombre'], (string) $p['correo'], $p['ingenio'], $usd ? 'Extranjero (US$)' : 'Guatemala (Q)',
                CENGI_EVP_ESTADOS[$p['pago_estado']][0] ?? 'Sin pago', $usd ? 'USD' : 'GTQ', cengi_evp_monto_esperado($p),
                $p['pago_monto'] !== null ? (float) $p['pago_monto'] : '', (string) $p['pago_boleta'], (string) $p['pago_banco'], (string) $p['pago_fecha'],
                (string) $p['pago_validado_por'], (string) $p['pago_validado_en'], (string) $p['pago_nota'], $p['confirmado_en'] ? 'Confirmada' : 'Pendiente'];
        }
        cengi_export_enviar_excel(['Evento', 'Fecha del evento', 'Participante', 'Correo', 'Institución', 'Paga desde', 'Estado del pago', 'Moneda', 'Costo', 'Monto del comprobante',
            'Boleta / transferencia', 'Banco', 'Fecha del pago', 'Validado por', 'Fecha de validación', 'Nota / motivo', 'Participación'],
            $filas, 'Cobros de eventos', 'cobros-eventos-' . date('Y-m-d'), [30, 14, 30, 28, 24, 16, 22, 8, 10, 12, 18, 16, 14, 22, 18, 30, 14]);
    }
    if (isset($_GET['participante'])) {
        $p = cengi_evp_puede_ver_pagos() ? cengi_evp_participante($db, (int) $_GET['participante']) : null;
        if (!$p) {
            cengi_evp_json(['ok' => false, 'error' => 'El participante no existe.'], 404);
        }
        $usd = cengi_evp_es_usd($p);
        cengi_evp_json(['ok' => true, 'participante' => [
            'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'correo' => (string) $p['correo'], 'ingenio' => $p['ingenio'], 'evento' => $p['evento_nombre'],
            'evento_fecha' => $p['evento_fecha'], 'costo' => (float) $p['costo'], 'costo_usd' => (float) $p['costo_usd'], 'origen' => $p['pago_origen'],
            'esperado' => cengi_evp_dinero(cengi_evp_monto_esperado($p), $usd), 'estado' => $p['pago_estado'], 'estado_texto' => CENGI_EVP_ESTADOS[$p['pago_estado']][0] ?? '',
            'boleta' => (string) $p['pago_boleta'], 'banco' => (string) $p['pago_banco'], 'fecha' => (string) $p['pago_fecha'],
            'monto' => $p['pago_monto'] !== null ? (float) $p['pago_monto'] : '', 'recibo' => (bool) $p['recibo_pago'], 'nota' => (string) $p['pago_nota'],
            'validado_por' => (string) $p['pago_validado_por'], 'validado_en' => (string) $p['pago_validado_en'], 'confirmado' => (bool) $p['confirmado_en'],
        ], 'puedeValidar' => $validar]);
    }
    if (isset($_GET['ingresos'])) {
        if (!$gestionar) {
            cengi_evp_json(['ok' => false, 'error' => 'No tienes permiso.'], 403);
        }
        $stmt = $db->prepare("SELECT COALESCE(NULLIF(p.nombre_participantes, ''), ep.nombre_invitado) AS nombre, ep.categoria_gafete AS categoria, ep.ingreso_en
            FROM evento_participantes ep LEFT JOIN participantes p ON p.id = ep.participante_id
            WHERE ep.evento_id = ? AND ep.ingreso_en IS NOT NULL ORDER BY ep.ingreso_en DESC LIMIT 8");
        $stmt->execute([(int) $_GET['ingresos']]);
        $conteo = $db->prepare('SELECT SUM(ingreso_en IS NOT NULL), SUM(confirmado_en IS NOT NULL) FROM evento_participantes WHERE evento_id = ?');
        $conteo->execute([(int) $_GET['ingresos']]);
        [$ingresos, $confirmados] = array_map('intval', $conteo->fetch(PDO::FETCH_NUM));
        cengi_evp_json(['ok' => true, 'ultimos' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'ingresos' => $ingresos, 'confirmados' => $confirmados]);
    }
    cengi_evp_json(['ok' => false, 'error' => 'Solicitud no válida.'], 400);
}

$accion = (string) ($_POST['accion'] ?? '');
$eventoId = isset($_POST['evento_id']) && $_POST['evento_id'] !== '' ? (int) $_POST['evento_id'] : null;

/** Participantes de la accion: ids[] acotados al evento, o todos los pendientes (todos=1). */
function cengi_evp_objetivo(PDO $db, $eventoId, $condicionTodos)
{
    if (!empty($_POST['todos'])) {
        $sql = cengi_evp_select() . ' WHERE ' . $condicionTodos . ($eventoId ? ' AND ep.evento_id = ' . (int) $eventoId : '');
        return $db->query($sql . ' ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    }
    return cengi_evp_participantes($db, (array) ($_POST['ids'] ?? []), $eventoId);
}

try {
    if ($accion === 'pago') {
        if (!$validar) {
            cengi_evp_json(['ok' => false, 'error' => 'Solo Administración (permiso “Validar pagos”) puede validar o registrar pagos.'], 403);
        }
        $datos = array_intersect_key($_POST, array_flip(['boleta', 'banco', 'fecha', 'monto', 'origen', 'nota', 'forzar_monto']));
        $archivo = $_FILES['recibo'] ?? null;
        if ($archivo && (int) $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int) $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
                throw new InvalidArgumentException('No fue posible subir el comprobante.');
            }
            if ((int) $archivo['size'] > 5 * 1024 * 1024) {
                throw new InvalidArgumentException('El comprobante supera 5 MB.');
            }
            $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $extension = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
            if (($tipos[$extension] ?? '') !== (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name'])) {
                throw new InvalidArgumentException('El comprobante debe ser un PDF, JPG o PNG válido.');
            }
            $datos['recibo'] = cengi_guardar_archivo_subido($archivo['tmp_name'], 'recibos_evento', bin2hex(random_bytes(16)) . '.' . $extension);
            if (!$datos['recibo']) {
                throw new RuntimeException('No se pudo guardar el comprobante.');
            }
        }
        try {
            $mensaje = cengi_evp_resolver_pago($db, (int) ($_POST['id'] ?? 0), (string) ($_POST['estado'] ?? ''), $datos);
        } catch (DomainException $e) {
            cengi_evp_json(['ok' => false, 'confirmar' => true, 'error' => $e->getMessage()], 409);
        }
        cengi_evp_json(['ok' => true, 'mensaje' => $mensaje]);
    }

    // Registrar varios pagos a la vez (control de lo pagado): Administración revisa el monto de cada
    // persona contra el costo; una diferencia solo se acepta marcando "aceptar diferencia".
    if ($accion === 'pagos_lote') {
        if (!$validar) {
            cengi_evp_json(['ok' => false, 'error' => 'Solo Administración (permiso “Validar pagos”) puede registrar pagos.'], 403);
        }
        // Forma y fecha van por persona (forma_fila / fecha_fila); las comunes son el respaldo.
        $formaComun = mb_substr(trim((string) ($_POST['forma'] ?? '')), 0, 120, 'UTF-8');
        $fechaComun = trim((string) ($_POST['fecha'] ?? ''));
        $refComun = mb_substr(trim((string) ($_POST['referencia'] ?? '')), 0, 60, 'UTF-8');
        $notaComun = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 300, 'UTF-8');
        $formasFila = (array) ($_POST['forma_fila'] ?? []);
        $fechasFila = (array) ($_POST['fecha_fila'] ?? []);
        $montos = (array) ($_POST['monto'] ?? []);
        $boletas = (array) ($_POST['boleta'] ?? []);
        $aceptar = (array) ($_POST['aceptar'] ?? []);
        $hechos = [];
        $omitidos = [];
        $usuarioPago = cengi_pp_usuario_nombre();
        $upd = $db->prepare("UPDATE evento_participantes SET pago_estado = 'validado', pagado = 1, pago_boleta = ?, pago_banco = ?, pago_fecha = ?, pago_monto = ?,
            pago_nota = ?, pago_validado_por = ?, pago_validado_en = NOW() WHERE id = ?");
        foreach (cengi_evp_participantes($db, (array) ($_POST['ids'] ?? []), $eventoId) as $p) {
            $pid = (int) $p['id'];
            if ($p['modalidad_pago'] !== 'Pagado') { $omitidos[] = $p['nombre'] . ': el evento es gratuito'; continue; }
            if (in_array($p['pago_estado'], ['validado', 'exonerado'], true)) { $omitidos[] = $p['nombre'] . ': ya tenía el pago ' . ($p['pago_estado'] === 'validado' ? 'validado' : 'exonerado'); continue; }
            $forma = mb_substr(trim((string) ($formasFila[$pid] ?? '')), 0, 120, 'UTF-8') ?: $formaComun;
            $fecha = trim((string) ($fechasFila[$pid] ?? '')) ?: $fechaComun;
            if ($forma === '') { $omitidos[] = $p['nombre'] . ': falta la forma de pago'; continue; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) { $omitidos[] = $p['nombre'] . ': fecha de pago no válida (no puede ser futura)'; continue; }
            $txt = str_replace(',', '', trim((string) ($montos[$pid] ?? '')));
            if (!is_numeric($txt) || (float) $txt <= 0) { $omitidos[] = $p['nombre'] . ': falta el monto pagado'; continue; }
            $pagado = round((float) $txt, 2);
            $usd = cengi_evp_es_usd($p);
            $esperado = cengi_evp_monto_esperado($p);
            $dif = round($pagado - $esperado, 2);
            if ($esperado > 0 && abs($dif) > 0.009 && empty($aceptar[$pid])) {
                $omitidos[] = $p['nombre'] . ': pagó ' . cengi_evp_dinero($pagado, $usd) . ' y el costo es ' . cengi_evp_dinero($esperado, $usd) . ' (marca «aceptar diferencia»)';
                continue;
            }
            // "Boleta o comentario": con números es la boleta; solo texto ("pagado", "efectivo") es un comentario.
            $textoFila = trim((string) ($boletas[$pid] ?? ''));
            $comentarioFila = $textoFila !== '' && !preg_match('/\d/', $textoFila) ? mb_substr($textoFila, 0, 150, 'UTF-8') : '';
            $boletaFila = $comentarioFila === '' ? mb_substr($textoFila, 0, 60, 'UTF-8') : '';
            $boleta = $boletaFila ?: ($refComun ?: (string) $p['pago_boleta']);
            $nota = trim(implode(' · ', array_filter([
                'Registrado en lote por Administración',
                $comentarioFila,
                $notaComun,
                $esperado > 0 && abs($dif) > 0.009 ? 'Pagó ' . cengi_evp_dinero($pagado, $usd) . ' (costo ' . cengi_evp_dinero($esperado, $usd) . ', diferencia ' . cengi_evp_dinero($dif, $usd) . ')' : '',
            ])));
            $upd->execute([$boleta ?: null, $forma, $fecha, $pagado, mb_substr($nota, 0, 500, 'UTF-8'), $usuarioPago, $pid]);
            $hechos[] = $pid;
        }
        if (!$hechos) {
            throw new InvalidArgumentException('Ningún pago se registró. ' . implode(' · ', $omitidos));
        }
        $partes = [count($hechos) . ' pago(s) registrado(s) como validados.'];
        // Igual que al validar uno por uno: con el pago validado la persona pasa a participante (QR y gafete).
        if (!empty($_POST['confirmar'])) {
            $r = cengi_evp_confirmar($db, cengi_evp_participantes($db, $hechos, $eventoId));
            $partes[] = $r['confirmados'] . ' participación(es) confirmada(s); gafete enviado a ' . $r['enviados'] . '.';
            if ($r['sin_correo']) $partes[] = 'Sin correo (entrégales el gafete impreso): ' . implode(', ', $r['sin_correo']) . '.';
            if ($r['fallidos']) $partes[] = 'Falló el envío del gafete a: ' . implode(', ', $r['fallidos']) . '.';
        } else {
            $partes[] = 'Quedan «Por confirmar»: Capacitación confirma y envía el gafete.';
        }
        if ($omitidos) $partes[] = 'Se omitieron: ' . implode(' · ', $omitidos) . '.';
        cengi_evp_json(['ok' => true, 'mensaje' => implode(' ', $partes), 'omitidos' => count($omitidos)]);
    }

    if ($accion === 'confirmar') {
        if (!$gestionar) {
            cengi_evp_json(['ok' => false, 'error' => 'Solo Capacitación (gestión de eventos) confirma la participación.'], 403);
        }
        $lista = cengi_evp_objetivo($db, $eventoId, "ep.confirmado_en IS NULL AND (e.modalidad_pago <> 'Pagado' OR ep.pago_estado IN ('validado', 'exonerado'))");
        if (!$lista) {
            throw new InvalidArgumentException('No hay participaciones por confirmar.');
        }
        $r = cengi_evp_confirmar($db, $lista);
        $partes = [$r['confirmados'] . ' participación(es) confirmada(s); gafete enviado a ' . $r['enviados'] . '.'];
        if ($r['sin_pago']) {
            $partes[] = 'Sin pago resuelto (no se confirmaron): ' . implode(', ', $r['sin_pago']) . '.';
        }
        if ($r['sin_correo']) {
            $partes[] = 'Sin correo (entrégales el gafete impreso): ' . implode(', ', $r['sin_correo']) . '.';
        }
        if ($r['fallidos']) {
            $partes[] = 'Falló el envío a: ' . implode(', ', $r['fallidos']) . '.';
        }
        cengi_evp_json(['ok' => true, 'mensaje' => implode(' ', $partes), 'detalle' => $r]);
    }

    if ($accion === 'recordar') {
        if (!$validar && !$gestionar) {
            cengi_evp_json(['ok' => false, 'error' => 'No tienes permiso.'], 403);
        }
        $lista = cengi_evp_objetivo($db, $eventoId, "e.modalidad_pago = 'Pagado' AND ep.pago_estado IN ('pendiente', 'rechazado') AND e.estado NOT IN ('Finalizado', 'Cancelado')");
        if (!$lista) {
            throw new InvalidArgumentException('No hay pagos pendientes para recordar.');
        }
        $r = cengi_evp_recordar($db, $lista);
        cengi_evp_json(['ok' => true, 'mensaje' => 'Recordatorio enviado a ' . $r['enviados'] . ' participante(s).'
            . ($r['sin_correo'] ? ' Sin correo: ' . implode(', ', $r['sin_correo']) . '.' : '')
            . ($r['fallidos'] ? ' Falló el envío a: ' . implode(', ', $r['fallidos']) . ' (revisa Comunicaciones).' : '')
            . ($r['omitidos'] ? ' ' . $r['omitidos'] . ' ya tenían el pago en trámite o resuelto.' : ''), 'detalle' => $r]);
    }

    if ($accion === 'ingreso') {
        if (!$gestionar || !$eventoId) {
            cengi_evp_json(['ok' => false, 'error' => 'No tienes permiso para registrar ingresos.'], 403);
        }
        if (isset($_POST['codigo']) || isset($_POST['id'])) {
            cengi_evp_json(cengi_evp_registrar_ingreso($db, $eventoId, $_POST['codigo'] ?? null, isset($_POST['id']) ? (int) $_POST['id'] : null));
        }
        $resultados = [];
        foreach (array_map('intval', (array) ($_POST['ids'] ?? [])) as $id) {
            $resultados[] = cengi_evp_registrar_ingreso($db, $eventoId, null, $id);
        }
        $registrados = count(array_filter($resultados, static function ($x) { return $x['estado'] === 'registrado'; }));
        $bloqueados = array_column(array_filter($resultados, static function ($x) { return $x['estado'] === 'no_confirmado'; }), 'nombre');
        cengi_evp_json(['ok' => true, 'mensaje' => $registrados . ' ingreso(s) registrado(s).'
            . ($bloqueados ? ' Sin participación confirmada (no se registraron): ' . implode(', ', $bloqueados) . '.' : '')]);
    }

    if ($accion === 'soporte') {
        if (!$validar && !$gestionar) {
            cengi_evp_json(['ok' => false, 'error' => 'No tienes permiso.'], 403);
        }
        $soporte = [];
        foreach (['nombre' => 120, 'whatsapp' => 30, 'correo' => 120, 'horario' => 120] as $campo => $max) {
            $soporte[$campo] = mb_substr(trim((string) ($_POST[$campo] ?? '')), 0, $max, 'UTF-8');
        }
        if ($soporte['nombre'] === '') {
            throw new InvalidArgumentException('Escribe el nombre visible del soporte de pagos.');
        }
        if ($soporte['correo'] !== '' && !filter_var($soporte['correo'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo no es válido.');
        }
        if ($soporte['whatsapp'] !== '' && strlen(preg_replace('/\D/', '', $soporte['whatsapp'])) < 8) {
            throw new InvalidArgumentException('El WhatsApp debe tener al menos 8 dígitos (con código de país si es necesario).');
        }
        $db->prepare("INSERT INTO eventos_config (clave, valor) VALUES ('soporte_pagos', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([json_encode($soporte, JSON_UNESCAPED_UNICODE)]);
        cengi_evp_json(['ok' => true, 'mensaje' => 'Contacto de soporte de pagos guardado.']);
    }

    cengi_evp_json(['ok' => false, 'error' => 'Acción no válida.'], 400);
} catch (InvalidArgumentException $e) {
    cengi_evp_json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('eventos_pagos_api: ' . $e->getMessage());
    cengi_evp_json(['ok' => false, 'error' => 'No fue posible completar la acción.'], 500);
}
