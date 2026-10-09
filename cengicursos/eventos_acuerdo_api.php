<?php
/**
 * Acuerdo de colaboracion de un evento (js/eventos_acuerdo.js, eventos_acuerdo_helpers.php).
 *   GET  ?evento=<id>                                   -> estado completo del acuerdo
 *   GET  ?documento=propuesta|expediente|solicitud&evento=<id>[&recibo=<id>] -> pagina imprimible
 *   GET  ?archivo=firma|pago|recibo&evento=<id>[&id=<id>] -> archivo adjunto
 *   POST accion=...  (ver el switch)
 * Capacitacion (gestion de eventos): modalidad, roles, detalle economico, propuesta,
 * aceptacion, consumo, encuesta y cierre. Administracion (validar pagos): pagos, recibos,
 * cobros, exoneracion y liquidacion. Datos fiscales, cargos y tarifas: ambos.
 */
require_once __DIR__ . '/eventos_acuerdo_helpers.php';

function cengi_acu_json($datos, $codigo = 200)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = conectar();
$cap = cengi_puede_gestionar_eventos();
$adm = cengi_evp_puede_validar();
if (!$cap && !$adm && !cengi_puede_ver_eventos()) {
    cengi_acu_json(['ok' => false, 'error' => 'No tienes acceso a los eventos.'], 403);
}
$eventoId = (int) ($_GET['evento'] ?? $_POST['evento_id'] ?? 0);
$ev = $eventoId ? cengi_acu_evento($db, $eventoId) : null;
if (!$ev || !(int) $ev['en_colaboracion'] || !$ev['aliados']) {
    cengi_acu_json(['ok' => false, 'error' => 'El evento no está en colaboración con una empresa aliada.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = cengi_acu_estado($db, $eventoId);
    if (isset($_GET['documento'])) {
        header('Content-Type: text/html; charset=utf-8');
        if ($_GET['documento'] === 'propuesta') {
            echo cengi_acu_pagina('Propuesta · ' . $ev['nombre'], cengi_acu_doc_propuesta($st));
        } elseif ($_GET['documento'] === 'expediente') {
            echo cengi_acu_pagina('Expediente · ' . $ev['nombre'], cengi_acu_doc_expediente($st));
        } else {
            $r = array_values(array_filter($st['recibos'], static function ($x) { return (int) $x['id'] === (int) ($_GET['recibo'] ?? 0); }))[0] ?? null;
            echo $r ? cengi_acu_pagina('Solicitud de recibo', cengi_acu_doc_solicitud($st, $r)) : 'La solicitud no existe.';
        }
        exit;
    }
    if (isset($_GET['archivo'])) {
        $ruta = null;
        if ($_GET['archivo'] === 'firma') {
            $ruta = $st['acuerdo']['acept_archivo'];
        }
        foreach ($_GET['archivo'] === 'pago' ? $st['pagos'] : ($_GET['archivo'] === 'recibo' ? $st['recibos'] : []) as $x) {
            if ((int) $x['id'] === (int) ($_GET['id'] ?? 0)) {
                $ruta = $x['comprobante'] ?? $x['archivo'];
            }
        }
        $archivo = $ruta ? cengi_acu_archivo_local($ruta) : null;
        if (!$archivo) {
            http_response_code(404);
            exit('El archivo no existe o ya no está disponible.');
        }
        $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($archivo));
        header('Content-Disposition: inline; filename="documento.' . $ext . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($archivo);
        exit;
    }
    cengi_acu_json(['ok' => true, 'estado' => $st, 'permisos' => ['capacitacion' => $cap, 'administracion' => $adm]]);
}

/* ---------- acciones ---------- */
$accion = (string) ($_POST['accion'] ?? '');
$st = cengi_acu_estado($db, $eventoId);
$a = $st['acuerdo'];
$R = $st['resumen'];
$orden = CENGI_ACU_ORDEN[$a['estado']];
$ro = $st['soloLectura'];
$emp = $st['empresas'][0];
$soporte = cengi_evento_soporte_pagos($db);
$requiere = static function ($permiso, $quien) {
    if (!$permiso) {
        cengi_acu_json(['ok' => false, 'error' => 'Esta acción la realiza ' . $quien . '.'], 403);
    }
};
$num = static function ($k, $min = 0) {
    $v = (float) ($_POST[$k] ?? 0);
    return max($min, $v);
};
$fechaValida = static function ($f) {
    return is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f);
};
$json = static function ($k) {
    $v = json_decode((string) ($_POST[$k] ?? ''), true);
    if (!is_array($v)) {
        throw new InvalidArgumentException('Datos no válidos.');
    }
    return $v;
};
$mensaje = 'Cambios guardados.';
$archivosNuevos = [];

try {
    $db->beginTransaction();
    switch ($accion) {
        /* ---- 1 y 2: propuesta (Capacitacion) ---- */
        case 'config':
            $requiere($cap, 'Capacitación (gestión de eventos)');
            $campo = (string) ($_POST['campo'] ?? '');
            $valor = (string) ($_POST['valor'] ?? '');
            if ($ro && $campo !== 'tareas') {
                throw new InvalidArgumentException('El aporte ya tiene pagos: la propuesta no se puede modificar.');
            }
            if ($campo === 'modalidad') {
                $m = (int) $valor;
                if (!isset(CENGI_ACU_MODALIDADES[$m])) {
                    throw new InvalidArgumentException('Modalidad no válida.');
                }
                if ($m !== $ev['colab_modalidad']) {
                    $db->prepare('UPDATE eventos SET colab_modalidad = ? WHERE id = ?')->execute([$m, $eventoId]);
                    cengi_acu_actualizar($db, $eventoId, ['tareas' => cengi_acu_tareas_base($m), 'precios' => []]);
                    cengi_acu_log($db, $eventoId, 'Modalidad cambiada', CENGI_ACU_MODALIDADES[$m]['n']);
                }
            } elseif ($campo === 'financia') {
                if (!isset(CENGI_EVT_COLAB_FINANCIA[$valor])) {
                    throw new InvalidArgumentException('Opción no válida.');
                }
                // Igual que "Editar evento": si la empresa o el Centro cubren el costo, el acceso es gratuito.
                $db->prepare("UPDATE eventos SET colab_financia = ?, modalidad_pago = IF(? = 'compartido', modalidad_pago, 'Gratuito'), costo = IF(? = 'compartido', costo, 0) WHERE id = ?")
                    ->execute([$valor, $valor, $valor, $eventoId]);
                if ($valor !== 'compartido') {
                    $db->prepare('UPDATE evento_participantes SET confirmado_en = ?, confirmado_por = ? WHERE evento_id = ? AND confirmado_en IS NULL')
                        ->execute([date('Y-m-d H:i:s'), cengi_pp_usuario_nombre(), $eventoId]);
                }
            } elseif ($campo === 'modo') {
                cengi_acu_actualizar($db, $eventoId, ['modo' => $valor === 'linea' ? 'linea' : 'presencial']);
            } elseif ($campo === 'dias') {
                cengi_acu_actualizar($db, $eventoId, ['dias' => min(30, max(1, (int) $valor))]);
            } elseif ($campo === 'participantes') {
                $p = min(100000, max(1, (int) $valor));
                cengi_acu_actualizar($db, $eventoId, ['participantes' => $p]);
                if ($ev['cupo'] === null || (int) $ev['cupo'] < $p) {
                    $db->prepare('UPDATE eventos SET cupo = ? WHERE id = ?')->execute([$p, $eventoId]);
                }
            } elseif ($campo === 'descuento') {
                cengi_acu_actualizar($db, $eventoId, ['descuento' => max(0, (float) $valor)]);
            } elseif ($campo === 'alims') {
                $lista = [];
                foreach ($json('valor') as $x) {
                    if (isset(CENGI_ACU_ALIM[$x['tipo'] ?? ''])) {
                        $lista[] = ['tipo' => $x['tipo'], 'tiempos' => min(6, max(1, (int) ($x['tiempos'] ?? 1))), 'precio' => max(0, round((float) ($x['precio'] ?? 0), 2))];
                    }
                }
                cengi_acu_actualizar($db, $eventoId, ['alims' => array_slice($lista, 0, 10)]);
            } elseif ($campo === 'extras') {
                $lista = [];
                foreach ($json('valor') as $x) {
                    $c = mb_substr(trim((string) ($x['c'] ?? '')), 0, 150, 'UTF-8');
                    if ($c !== '') {
                        $lista[] = ['c' => $c, 'cant' => max(0, (float) ($x['cant'] ?? 1)), 'u' => max(0, round((float) ($x['u'] ?? 0), 2))];
                    }
                }
                cengi_acu_actualizar($db, $eventoId, ['extras' => array_slice($lista, 0, 30)]);
            } elseif ($campo === 'precios') {
                $p = [];
                foreach ($json('valor') as $k => $v) {
                    if (in_array($k, ['auditorio', 'gestion', 'coord', 'admin'], true) && $v !== '' && $v !== null) {
                        $p[$k] = max(0, round((float) $v, 2));
                    }
                }
                cengi_acu_actualizar($db, $eventoId, ['precios' => $p]);
            } elseif ($campo === 'tareas') {
                $lista = [];
                foreach ($json('valor') as $t) {
                    $txt = mb_substr(trim((string) ($t['t'] ?? '')), 0, 200, 'UTF-8');
                    if ($txt !== '') {
                        $lista[] = ['t' => $txt, 'resp' => ($t['resp'] ?? '') === 'Empresa' ? 'Empresa' : 'Centro', 'ok' => !empty($t['ok'])];
                    }
                }
                cengi_acu_actualizar($db, $eventoId, ['tareas' => array_slice($lista, 0, 40)]);
            } else {
                throw new InvalidArgumentException('Campo no válido.');
            }
            break;

        case 'fiscal':
            $requiere($cap || $adm, 'Capacitación o Administración');
            $campos = [];
            foreach (['recibo' => 255, 'nit' => 30, 'direccion' => 255, 'descripcion' => 500, 'objetivo' => 500] as $k => $max) {
                $campos['fiscal_' . $k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max, 'UTF-8') ?: null;
            }
            cengi_acu_actualizar($db, $eventoId, $campos);
            $mensaje = 'Datos fiscales guardados.';
            break;

        case 'tarifas':
            $requiere($cap || $adm, 'Capacitación o Administración');
            $T = [];
            foreach (array_keys(CENGI_ACU_TARIFAS) as $k) {
                $T[$k] = $num($k);
            }
            cengi_acu_guardar_config($db, 'tarifas_colab', $T);
            $mensaje = 'Tarifas actualizadas (se aplican a las propuestas que no tienen un precio ajustado).';
            break;

        case 'enviar_propuesta':
            $requiere($cap, 'Capacitación (gestión de eventos)');
            if ($orden > 1) {
                throw new InvalidArgumentException('La propuesta ya fue aceptada.');
            }
            if (!filter_var((string) $emp['correo'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Agrega el correo de la empresa aliada en “Editar evento”.');
            }
            if ($st['calculo']['total'] <= 0 && $ev['colab_financia'] !== 'centro') {
                throw new InvalidArgumentException('El aporte es Q0: completa el detalle económico.');
            }
            $pdf = cengi_acu_pdf(cengi_acu_doc_propuesta($st));
            $ok = cengi_acu_correo($emp['correo'], $emp['contacto'] ?: $emp['nombre'], 'Propuesta para el desarrollo del evento ' . $ev['nombre'] . ' — CENGICAÑA',
                'Estimado(a) ' . ($emp['contacto'] ?: $emp['nombre']) . ":\n\nAdjuntamos la propuesta para " . $ev['nombre'] . ' (' . cengi_evp_fecha($ev['fecha']) . ') bajo la modalidad “'
                . CENGI_ACU_MODALIDADES[$ev['colab_modalidad']]['n'] . '”, con un aporte de ' . cengi_acu_q($st['calculo']['total'])
                . ".\nIncluye la hoja de aceptación para su firma. El aporte se gestiona como donación y se extiende el recibo contable respectivo.",
                [['contenido' => $pdf, 'nombre' => 'Propuesta-' . (cengi_diploma_sanitizar_segmento($ev['nombre']) ?: 'evento') . '.pdf', 'tipo' => 'application/pdf']]);
            if (!$ok) {
                throw new InvalidArgumentException('No se pudo enviar el correo a ' . $emp['correo'] . ' (revisa Comunicaciones).');
            }
            cengi_acu_actualizar($db, $eventoId, ['estado' => 'enviada', 'propuesta_enviada_en' => date('Y-m-d H:i:s'), 'propuesta_enviada_a' => $emp['correo']]);
            cengi_acu_log($db, $eventoId, $orden === 1 ? 'Propuesta reenviada' : 'Propuesta enviada', $emp['correo'] . ' · ' . cengi_acu_q($st['calculo']['total']));
            $mensaje = 'Propuesta enviada a ' . $emp['nombre'] . ' (' . $emp['correo'] . ').';
            break;

        case 'aceptar':
            $requiere($cap, 'Capacitación (gestión de eventos)');
            if ($orden !== 1) {
                throw new InvalidArgumentException('Primero envía la propuesta a la empresa.');
            }
            $rep = mb_substr(trim((string) ($_POST['representante'] ?? '')), 0, 255, 'UTF-8');
            if ($rep === '' || !$fechaValida($_POST['fecha'] ?? '')) {
                throw new InvalidArgumentException('Indica el representante y la fecha de aceptación.');
            }
            $f = cengi_acu_guardar_archivo($_FILES['archivo'] ?? null, 'aceptacion');
            if ($f) {
                $archivosNuevos[] = $f['ruta'];
            }
            $part = max(1, (int) ($_POST['participantes'] ?? $a['participantes']));
            cengi_acu_actualizar($db, $eventoId, ['estado' => 'aceptada', 'acept_representante' => $rep, 'acept_cargo' => mb_substr(trim((string) ($_POST['cargo'] ?? '')), 0, 150, 'UTF-8') ?: null,
                'acept_fecha' => $_POST['fecha'], 'acept_participantes' => $part, 'participantes' => $part,
                'acept_archivo' => $f['ruta'] ?? null, 'acept_archivo_nombre' => $f['nombre'] ?? null, 'acept_archivo_fecha' => $f ? date('Y-m-d H:i:s') : null]);
            cengi_acu_log($db, $eventoId, 'Propuesta aceptada', $part . ' participantes' . ($f ? ' · hoja firmada adjunta' : ' · sin hoja firmada'), $rep);
            $mensaje = 'Aceptación registrada: queda pendiente el pago del aporte.';
            break;

        case 'firma':
            $requiere($cap, 'Capacitación (gestión de eventos)');
            if ($orden < 2) {
                throw new InvalidArgumentException('Primero registra la aceptación.');
            }
            $f = cengi_acu_guardar_archivo($_FILES['archivo'] ?? null, 'aceptacion');
            if (!$f) {
                throw new InvalidArgumentException('Selecciona el archivo firmado (PDF, JPG o PNG).');
            }
            $archivosNuevos[] = $f['ruta'];
            $anterior = $a['acept_archivo'];
            cengi_acu_actualizar($db, $eventoId, ['acept_archivo' => $f['ruta'], 'acept_archivo_nombre' => $f['nombre'], 'acept_archivo_fecha' => date('Y-m-d H:i:s')]);
            cengi_acu_log($db, $eventoId, $anterior ? 'Hoja firmada reemplazada' : 'Hoja de aceptación firmada adjunta', $f['nombre']);
            $mensaje = 'Hoja firmada adjuntada al acuerdo.';
            break;

        /* ---- 3: pagos y recibos (Administracion) ---- */
        case 'pago':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $tipo = ($_POST['tipo'] ?? '') === 'excedente' ? 'excedente' : 'aporte';
            if ($orden < 2 || in_array($a['estado'], ['liquidada', 'cerrada'], true) || ($tipo === 'excedente' && $orden < 3)) {
                throw new InvalidArgumentException('En este momento del acuerdo no se pueden registrar pagos de este tipo.');
            }
            $ref = mb_substr(trim((string) ($_POST['referencia'] ?? '')), 0, 80, 'UTF-8');
            $monto = round($num('monto'), 2);
            if ($ref === '' || !$fechaValida($_POST['fecha'] ?? '') || $monto <= 0) {
                throw new InvalidArgumentException('Indica la referencia, la fecha y el monto.');
            }
            $saldo = $tipo === 'aporte' ? $R['saldoAp'] : $R['saldoEx'];
            if ($monto > $saldo + 0.001 && empty($_POST['forzar'])) {
                $db->rollBack();
                cengi_acu_json(['ok' => false, 'confirmar' => true, 'error' => 'El monto (' . cengi_acu_q($monto) . ') es mayor que el saldo (' . cengi_acu_q($saldo) . '). ¿Registrarlo de todos modos?'], 409);
            }
            $f = cengi_acu_guardar_archivo($_FILES['comprobante'] ?? null, 'pago');
            if ($f) {
                $archivosNuevos[] = $f['ruta'];
            }
            $forma = in_array($_POST['forma'] ?? '', ['Transferencia', 'Depósito', 'Cheque'], true) ? $_POST['forma'] : 'Transferencia';
            $db->prepare('INSERT INTO evento_acuerdo_pagos (evento_id, tipo, forma, banco, referencia, fecha, monto, comprobante, comprobante_nombre, obs, registrado_por, creado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$eventoId, $tipo, $forma, mb_substr(trim((string) ($_POST['banco'] ?? '')), 0, 120, 'UTF-8') ?: null, $ref, $_POST['fecha'], $monto, $f['ruta'] ?? null, $f['nombre'] ?? null,
                    mb_substr(trim((string) ($_POST['obs'] ?? '')), 0, 255, 'UTF-8') ?: null, cengi_pp_usuario_nombre(), date('Y-m-d H:i:s')]);
            cengi_acu_log($db, $eventoId, $tipo === 'aporte' ? 'Pago del aporte registrado' : 'Pago del excedente registrado', $forma . ' ' . $ref . ' · ' . cengi_acu_q($monto));
            cengi_acu_recalcular_estado($db, $eventoId);
            $R2 = cengi_acu_estado($db, $eventoId)['resumen'];
            $mensaje = $tipo === 'aporte'
                ? ($R2['saldoAp'] > 0 ? 'Abono registrado · saldo ' . cengi_acu_q($R2['saldoAp']) . '.' : 'Aporte pagado por completo: solicita el recibo por donación.')
                : ($R2['saldoEx'] > 0 ? 'Abono al excedente · saldo ' . cengi_acu_q($R2['saldoEx']) . '.' : 'Excedente pagado: ya puedes liquidar el acuerdo.');
            break;

        case 'quitar_pago':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $p = array_values(array_filter($st['pagos'], static function ($x) { return (int) $x['id'] === (int) ($_POST['id'] ?? 0); }))[0] ?? null;
            if (!$p || $p['recibo_id'] || in_array($a['estado'], ['liquidada', 'cerrada'], true)) {
                throw new InvalidArgumentException('Solo se puede quitar un pago sin recibo solicitado y antes de liquidar.');
            }
            $db->prepare('DELETE FROM evento_acuerdo_pagos WHERE id = ?')->execute([(int) $p['id']]);
            cengi_acu_log($db, $eventoId, 'Pago anulado', $p['forma'] . ' ' . $p['referencia'] . ' · ' . cengi_acu_q($p['monto']));
            cengi_acu_recalcular_estado($db, $eventoId);
            $borrarDespues = $p['comprobante'];
            $mensaje = 'Pago anulado.';
            break;

        case 'fecha_limite':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            if (!$fechaValida($_POST['fecha'] ?? '')) {
                throw new InvalidArgumentException('Fecha no válida.');
            }
            cengi_acu_actualizar($db, $eventoId, ['fecha_limite' => $_POST['fecha']]);
            $mensaje = 'Fecha límite de pago actualizada.';
            break;

        case 'recordar_pago':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            if ($R['saldoAp'] <= 0 || $orden < 2) {
                throw new InvalidArgumentException('No hay saldo del aporte pendiente.');
            }
            $ok = cengi_acu_correo($emp['correo'], $emp['contacto'] ?: $emp['nombre'], 'Recordatorio de aporte — ' . $ev['nombre'],
                'Estimado(a) ' . ($emp['contacto'] ?: $emp['nombre']) . ":\n\nLe recordamos el aporte pendiente de " . cengi_acu_q($R['saldoAp']) . ' para ' . $ev['nombre'] . ' (' . cengi_evp_fecha($ev['fecha']) . ')'
                . ($a['fecha_limite'] ? ', con fecha límite ' . cengi_evp_fecha($a['fecha_limite']) : '') . ".\n\nPara los datos de la cuenta y el envío del comprobante comuníquese con " . $soporte['nombre']
                . ($soporte['correo'] ? ' (' . $soporte['correo'] . ')' : '') . '.');
            if (!$ok) {
                throw new InvalidArgumentException('No se pudo enviar el recordatorio (revisa Comunicaciones).');
            }
            cengi_acu_log($db, $eventoId, 'Recordatorio de pago enviado', 'Saldo ' . cengi_acu_q($R['saldoAp']));
            $mensaje = 'Recordatorio enviado a la empresa.';
            break;

        case 'solicitar_recibo':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $tipo = ($_POST['tipo'] ?? '') === 'excedente' ? 'excedente' : 'aporte';
            if (!$a['fiscal_recibo'] || !$a['fiscal_nit']) {
                throw new InvalidArgumentException('Completa “Recibo a nombre de” y el NIT en Detalle económico.');
            }
            $pend = array_values(array_filter($st['pagos'], static function ($p) use ($tipo) { return $p['tipo'] === $tipo && !$p['recibo_id']; }));
            if (!$pend) {
                throw new InvalidArgumentException('No hay pagos sin recibo.');
            }
            $monto = array_sum(array_map(static function ($p) { return (float) $p['monto']; }, $pend));
            $concepto = $tipo === 'aporte'
                ? 'Donación para el desarrollo del evento “' . $ev['nombre'] . '” (' . cengi_evp_fecha($ev['fecha']) . '), modalidad: ' . CENGI_ACU_MODALIDADES[$ev['colab_modalidad']]['n'] . '.'
                : 'Donación complementaria por excedentes y cargos adicionales del evento “' . $ev['nombre'] . '” (' . cengi_evp_fecha($ev['fecha']) . ').';
            $db->prepare('INSERT INTO evento_acuerdo_recibos (evento_id, tipo, monto, a_nombre, nit, direccion, concepto, solicitado_en, solicitado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$eventoId, $tipo, $monto, $a['fiscal_recibo'], $a['fiscal_nit'], $a['fiscal_direccion'], $concepto, date('Y-m-d H:i:s'), cengi_pp_usuario_nombre()]);
            $reciboId = (int) $db->lastInsertId();
            $db->prepare('UPDATE evento_acuerdo_pagos SET recibo_id = ? WHERE id IN (' . implode(',', array_map('intval', array_column($pend, 'id'))) . ')')->execute([$reciboId]);
            $r = cengi_acu_recibos($db, $eventoId);
            $r = array_values(array_filter($r, static function ($x) use ($reciboId) { return (int) $x['id'] === $reciboId; }))[0];
            $st2 = cengi_acu_estado($db, $eventoId);
            $ok = cengi_acu_correo(cengi_acu_correo_financiero($db), 'Área financiera', 'Solicitud de recibo por donación — ' . $ev['nombre'],
                "Área financiera:\n\nSe solicita emitir el recibo contable por donación con estos datos:\n• A nombre de: {$r['a_nombre']}\n• NIT: {$r['nit']}\n• Dirección fiscal: " . ($r['direccion'] ?: '—')
                . "\n• Monto: " . cengi_acu_q($monto) . ' (' . cengi_acu_letras($monto) . ")\n• Concepto: {$concepto}\n• Pagos: "
                . implode('; ', array_map(static function ($p) { return $p['forma'] . ' ' . $p['referencia'] . ' del ' . cengi_evp_fecha($p['fecha']) . ' por ' . cengi_acu_q($p['monto']); }, $pend))
                . "\n\nFavor de responder con el recibo emitido para cargarlo al expediente.",
                [['contenido' => cengi_acu_pdf(cengi_acu_doc_solicitud($st2, $r)), 'nombre' => 'Solicitud-recibo.pdf', 'tipo' => 'application/pdf']]);
            cengi_acu_log($db, $eventoId, 'Recibo solicitado al área financiera', cengi_acu_q($monto) . ' a nombre de ' . $r['a_nombre'] . ($ok ? '' : ' · el correo no se pudo enviar'));
            $mensaje = $ok ? 'Solicitud de recibo enviada a ' . cengi_acu_correo_financiero($db) . '. Cuando lo emitan, cárgalo aquí.' : 'Solicitud registrada, pero el correo al área financiera falló (revisa Comunicaciones).';
            break;

        case 'recordar_recibo':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $r = array_values(array_filter($st['recibos'], static function ($x) { return (int) $x['id'] === (int) ($_POST['id'] ?? 0) && $x['estado'] !== 'emitido'; }))[0] ?? null;
            if (!$r) {
                throw new InvalidArgumentException('La solicitud no existe o ya fue emitida.');
            }
            $ok = cengi_acu_correo(cengi_acu_correo_financiero($db), 'Área financiera', 'Recordatorio: solicitud de recibo por donación — ' . $ev['nombre'],
                "Área financiera:\n\nLes recordamos la solicitud de recibo por donación del " . cengi_evp_fecha(substr($r['solicitado_en'], 0, 10)) . ":\n• A nombre de: {$r['a_nombre']} (NIT {$r['nit']})\n• Monto: " . cengi_acu_q($r['monto']) . "\n• Concepto: {$r['concepto']}",
                [['contenido' => cengi_acu_pdf(cengi_acu_doc_solicitud($st, $r)), 'nombre' => 'Solicitud-recibo.pdf', 'tipo' => 'application/pdf']]);
            if (!$ok) {
                throw new InvalidArgumentException('No se pudo enviar el recordatorio (revisa Comunicaciones).');
            }
            cengi_acu_log($db, $eventoId, 'Recordatorio de recibo al área financiera', cengi_acu_q($r['monto']));
            $mensaje = 'Recordatorio enviado al área financiera.';
            break;

        case 'cargar_recibo':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $r = array_values(array_filter($st['recibos'], static function ($x) { return (int) $x['id'] === (int) ($_POST['id'] ?? 0) && $x['estado'] !== 'emitido'; }))[0] ?? null;
            $numero = mb_substr(trim((string) ($_POST['numero'] ?? '')), 0, 60, 'UTF-8');
            if (!$r || $numero === '' || !$fechaValida($_POST['fecha'] ?? '')) {
                throw new InvalidArgumentException('Indica el número y la fecha de emisión del recibo.');
            }
            $dup = $db->prepare('SELECT COUNT(*) FROM evento_acuerdo_recibos WHERE numero = ?');
            $dup->execute([$numero]);
            if ((int) $dup->fetchColumn()) {
                throw new InvalidArgumentException('Ese número de recibo ya está registrado en otro expediente.');
            }
            $f = cengi_acu_guardar_archivo($_FILES['archivo'] ?? null, 'recibo');
            if (!$f) {
                throw new InvalidArgumentException('Adjunta el archivo del recibo emitido.');
            }
            $archivosNuevos[] = $f['ruta'];
            $db->prepare("UPDATE evento_acuerdo_recibos SET estado = 'emitido', numero = ?, fecha = ?, archivo = ?, archivo_nombre = ?, cargado_en = ?, cargado_por = ? WHERE id = ?")
                ->execute([$numero, $_POST['fecha'], $f['ruta'], $f['nombre'], date('Y-m-d H:i:s'), cengi_pp_usuario_nombre(), (int) $r['id']]);
            cengi_acu_log($db, $eventoId, 'Recibo emitido cargado', $numero . ' · ' . cengi_acu_q($r['monto']));
            $mensaje = 'Recibo ' . $numero . ' cargado. Ya puedes enviarlo a la empresa.';
            break;

        case 'enviar_recibo':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $r = array_values(array_filter($st['recibos'], static function ($x) { return (int) $x['id'] === (int) ($_POST['id'] ?? 0) && $x['estado'] === 'emitido'; }))[0] ?? null;
            $archivo = $r ? cengi_acu_archivo_local($r['archivo']) : null;
            if (!$archivo) {
                throw new InvalidArgumentException('El recibo no tiene archivo cargado.');
            }
            $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
            $ok = cengi_acu_correo($emp['correo'], $emp['contacto'] ?: $emp['nombre'], 'Recibo por donación ' . $r['numero'] . ' — ' . $ev['nombre'],
                'Estimado(a) ' . ($emp['contacto'] ?: $emp['nombre']) . ":\n\nAdjuntamos el recibo contable por donación " . $r['numero'] . ' por ' . cengi_acu_q($r['monto']) . ', correspondiente a ' . $ev['nombre'] . ".\n\nGracias por su aporte.",
                [['contenido' => file_get_contents($archivo), 'nombre' => 'Recibo-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $r['numero']) . '.' . $ext, 'tipo' => $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg')]]);
            if (!$ok) {
                throw new InvalidArgumentException('No se pudo enviar el recibo (revisa Comunicaciones).');
            }
            $db->prepare('UPDATE evento_acuerdo_recibos SET enviado_en = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $r['id']]);
            cengi_acu_log($db, $eventoId, 'Recibo enviado a la empresa', $r['numero']);
            $mensaje = 'Recibo ' . $r['numero'] . ' enviado a ' . $emp['correo'] . '.';
            break;

        /* ---- 4: liquidacion ---- */
        case 'consumo':
            $requiere($cap || $adm, 'Capacitación o Administración');
            if ($orden >= 4 || $orden < 3 || !$R['realizado']) {
                throw new InvalidArgumentException($orden >= 4 ? 'El acuerdo ya está liquidado.' : 'La liquidación se hace después del evento y con el aporte pagado.');
            }
            $exc = $a['excedente'];
            $exc['consumidos'][(int) ($_POST['i'] ?? 0)] = max(0, (int) ($_POST['valor'] ?? 0));
            cengi_acu_actualizar($db, $eventoId, ['excedente' => $exc]);
            break;

        case 'cargo_agregar':
        case 'cargo_quitar':
            $requiere($cap || $adm, 'Capacitación o Administración');
            if ($orden >= 4 || $orden < 3 || !$R['realizado']) {
                throw new InvalidArgumentException($orden >= 4 ? 'El acuerdo ya está liquidado.' : 'La liquidación se hace después del evento y con el aporte pagado.');
            }
            $cargos = $a['cargos'];
            if ($accion === 'cargo_agregar') {
                $c = mb_substr(trim((string) ($_POST['concepto'] ?? '')), 0, 150, 'UTF-8');
                $m = round($num('monto'), 2);
                if ($c === '' || $m <= 0) {
                    throw new InvalidArgumentException('Escribe el concepto y el monto del cargo.');
                }
                $cargos[] = ['c' => $c, 'monto' => $m];
                cengi_acu_log($db, $eventoId, 'Cargo adicional agregado', $c . ' · ' . cengi_acu_q($m));
            } else {
                array_splice($cargos, (int) ($_POST['i'] ?? -1), 1);
            }
            cengi_acu_actualizar($db, $eventoId, ['cargos' => $cargos]);
            break;

        case 'cobrar_excedente':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            if ($orden !== 3 || !$R['realizado'] || $R['saldoEx'] <= 0) {
                throw new InvalidArgumentException('No hay saldo de excedentes por cobrar.');
            }
            $vence = date('Y-m-d', strtotime('+7 days'));
            $det = '';
            if ($st['excedente'] && empty($a['excedente']['exonerado'])) {
                foreach ($st['excedente']['items'] as $x) {
                    $det .= '• ' . $x['n'] . ': ' . $x['consumidas'] . ' servidas de ' . $x['contratadas'] . ' contratadas' . ($x['extra'] ? ' → ' . $x['extra'] . ' × ' . cengi_acu_q($x['precio']) . ' = ' . cengi_acu_q($x['monto']) : '') . "\n";
                }
            }
            foreach ($a['cargos'] as $c) {
                $det .= '• ' . $c['c'] . ': ' . cengi_acu_q($c['monto']) . "\n";
            }
            $ok = cengi_acu_correo($emp['correo'], $emp['contacto'] ?: $emp['nombre'], 'Liquidación del evento ' . $ev['nombre'],
                'Estimado(a) ' . ($emp['contacto'] ?: $emp['nombre']) . ":\n\nCompartimos la liquidación del evento:\n" . $det . "\nSaldo por cancelar: " . cengi_acu_q($R['saldoEx']) . ', a más tardar el ' . cengi_evp_fecha($vence)
                . '. Para los datos de la cuenta comuníquese con ' . $soporte['nombre'] . ($soporte['correo'] ? ' (' . $soporte['correo'] . ')' : '') . '.');
            if (!$ok) {
                throw new InvalidArgumentException('No se pudo enviar el cobro (revisa Comunicaciones).');
            }
            $exc = $a['excedente'];
            $exc['cobro'] = ['ts' => date('Y-m-d H:i:s'), 'vence' => $vence, 'monto' => $R['saldoEx']];
            cengi_acu_actualizar($db, $eventoId, ['excedente' => $exc]);
            cengi_acu_log($db, $eventoId, 'Cobro de liquidación enviado', cengi_acu_q($R['saldoEx']) . ' · vence ' . cengi_evp_fecha($vence));
            $mensaje = 'Cobro de ' . cengi_acu_q($R['saldoEx']) . ' enviado; vence el ' . cengi_evp_fecha($vence) . '.';
            break;

        case 'exonerar':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 300, 'UTF-8');
            if ($motivo === '' || !$st['excedente'] || $orden !== 3 || !$R['realizado']) {
                throw new InvalidArgumentException('Escribe el motivo de la exoneración.');
            }
            $exc = $a['excedente'];
            $exc['exonerado'] = ['motivo' => $motivo, 'por' => cengi_pp_usuario_nombre(), 'ts' => date('Y-m-d H:i:s')];
            cengi_acu_actualizar($db, $eventoId, ['excedente' => $exc]);
            cengi_acu_log($db, $eventoId, 'Excedente exonerado', $motivo);
            $mensaje = 'Excedente de alimentación exonerado.';
            break;

        case 'liquidar':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            if ($a['estado'] !== 'pagada' || !$R['realizado']) {
                throw new InvalidArgumentException('Se liquida después del evento y con el aporte pagado.');
            }
            if ($R['saldoEx'] > 0) {
                throw new InvalidArgumentException('Aún hay saldo por cobrar.');
            }
            cengi_acu_actualizar($db, $eventoId, ['estado' => 'liquidada']);
            cengi_acu_log($db, $eventoId, $R['excTotal'] > 0 ? 'Acuerdo liquidado · total final ' . cengi_acu_q($R['totalFinal']) : 'Acuerdo liquidado sin excedentes');
            $mensaje = 'Acuerdo liquidado: envía la encuesta de colaboración y cierra el expediente.';
            break;

        /* ---- 5: cierre ---- */
        case 'cerrar':
            $requiere($cap, 'Capacitación (gestión de eventos)');
            if ($a['estado'] !== 'liquidada') {
                throw new InvalidArgumentException('Primero se liquida el acuerdo.');
            }
            if ($R['recibosPendientes']) {
                throw new InvalidArgumentException('Falta cargar ' . $R['recibosPendientes'] . ' recibo(s) emitido(s) por el área financiera antes de cerrar el expediente.');
            }
            $faltan = array_column(array_filter($st['empresas'], static function ($x) { return !$x['respuesta']; }), 'nombre');
            cengi_acu_actualizar($db, $eventoId, ['estado' => 'cerrada']);
            cengi_acu_log($db, $eventoId, 'Expediente cerrado', $faltan ? 'sin encuesta de ' . implode(', ', $faltan) : 'con encuesta respondida');
            $mensaje = 'Expediente de la colaboración cerrado.';
            break;

        case 'correo_financiero':
            $requiere($adm, 'Administración (permiso “Validar pagos”)');
            $correo = trim((string) ($_POST['correo'] ?? ''));
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo no es válido.');
            }
            cengi_acu_guardar_config($db, 'area_financiera', $correo);
            $mensaje = 'Correo del área financiera actualizado.';
            break;

        default:
            throw new InvalidArgumentException('Acción no válida.');
    }
    $db->commit();
    if (!empty($borrarDespues) && ($local = cengi_acu_archivo_local($borrarDespues))) {
        @unlink($local);
    }
    if ($accion === 'firma' && !empty($anterior) && ($local = cengi_acu_archivo_local($anterior))) {
        @unlink($local);
    }
    cengi_acu_json(['ok' => true, 'mensaje' => $mensaje, 'estado' => cengi_acu_estado($db, $eventoId)]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($archivosNuevos as $ruta) {
        if ($local = cengi_acu_archivo_local($ruta)) {
            @unlink($local);
        }
    }
    if ($e instanceof InvalidArgumentException) {
        cengi_acu_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    error_log('eventos_acuerdo_api: ' . $e->getMessage());
    cengi_acu_json(['ok' => false, 'error' => 'No fue posible completar la acción.'], 500);
}
