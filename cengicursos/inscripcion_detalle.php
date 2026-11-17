<?php
require_once __DIR__ . '/inscripcion_registro.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/administracion_nav.php';
require_once __DIR__ . '/cobranza_helpers.php';
if (!cengi_puede_ver_solicitudes() && !cengi_ins_puede_ver_pagos()) { header('Location: ver_cursos.php'); exit; }
$db = conectar();
$id = (int) ($_GET['id'] ?? 0);
// Sin id llega un filtro de Validaciones y pagos enviado desde esta ruta (enlaces o
// favoritos antiguos): se reenvía a esa pantalla conservando los filtros.
if ($id <= 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $params = $_GET; unset($params['id']); $params['vista'] = 'pagos';
    header('Location: inscripciones.php?' . http_build_query(array_filter($params, static function ($v) { return $v !== ''; }))); exit;
}
$s = cengi_ins_solicitud($db, $id);
$limite = cengi_ins_ingenio_limitado();
if (!$s || ($limite !== null && cengi_texto_normalizado($s['nombre_ingenios'] ?? '') !== cengi_texto_normalizado($limite))) {
    http_response_code(403); exit('Inscripción no disponible.');
}
// Con o sin convocatoria se muestra la ficha. Con convocatoria, la asignación respeta el cupo y la
// lista de espera (cengi_ins_procesar_plan), igual que en Validaciones y pagos.
if (empty($_SESSION['ins_csrf'])) $_SESSION['ins_csrf'] = bin2hex(random_bytes(32));
$mensaje = ''; $tipoMensaje = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['ins_csrf'], (string) ($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Actualiza la página y vuelve a intentar.');
        $db->beginTransaction();
        $s = cengi_ins_solicitud($db, $id, true);
        $antes = json_encode($s, JSON_UNESCAPED_UNICODE);
        $accion = (string) ($_POST['accion'] ?? '');
        $por = cengi_pp_usuario_nombre();
        $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')),0,500);
        if ($motivo === '') throw new InvalidArgumentException('Indica el motivo o la evidencia para conservar el historial.');
        if ($s['estado'] === 'Rechazado') throw new InvalidArgumentException('Reactiva primero la inscripción desde el listado.');
        if ($accion === 'cuota') {
            if (!cengi_ins_puede_validar_pagos()) throw new InvalidArgumentException('No tienes permiso para configurar el cobro.');
            if (cengi_ins_estado_cobro($s) === 'pagado') throw new InvalidArgumentException('No se modifica una inscripción pagada.');
            $paga = (string) ($_POST['tipo_pago'] ?? '');
            // Tarifa (asociado / externo / extranjero) independiente de quién paga: p. ej. un colaborador
            // de ingenio que paga por su cuenta conserva la cuota de asociado.
            $tarifa = array_key_exists($_POST['cuota'] ?? '', CENGI_INS_CUOTAS) ? $_POST['cuota'] : ($s['cuota'] ?: 'asociado');
            $moneda = $s['moneda'] ?: 'GTQ';
            if (trim((string) ($_POST['monto'] ?? '')) === '' && $s['planificacion_id'] && $paga !== 'Exonerado') {
                [$monto, $moneda] = cengi_ins_monto(cengi_pp_plan($db, $s['planificacion_id']), $tarifa);
            } else {
                $monto = filter_var($_POST['monto'] ?? '', FILTER_VALIDATE_FLOAT);
                if ($tarifa !== $s['cuota']) $moneda = $tarifa === 'extranjero' ? 'USD' : 'GTQ';
            }
            if ($monto === false || $monto < 0 || $monto > 99999999 || !in_array($paga,['Ingenio','Propio','Exonerado'],true)) throw new InvalidArgumentException('Revisa el monto y quién paga.');
            if ($paga === 'Exonerado' && $monto != 0) throw new InvalidArgumentException('La exoneración debe tener monto cero.');
            if ($paga !== 'Exonerado' && $monto <= 0) throw new InvalidArgumentException('Indica la cuota; utiliza exoneración si es sin costo.');
            if ($s['grupo_pago_id']) {
                $q = $db->prepare('SELECT proforma_id FROM pagos_grupales WHERE id = ?'); $q->execute([(int) $s['grupo_pago_id']]);
                if ($q->fetchColumn()) throw new InvalidArgumentException('La cuota de esta inscripción viene de su proforma: para cambiarla, anula la proforma y emite otra.');
            }
            if ($paga === $s['tipo_pago']) {
                // Mismo responsable: solo cambia la cuota; la validación ya hecha se conserva.
                $db->prepare("UPDATE solicitudes_inscripcion SET monto=?, moneda=?, cuota=?, pago_estado=IF(?='Exonerado','exonerado',pago_estado) WHERE id_solicitud=?")->execute([$monto,$moneda,$tarifa,$paga,$id]);
            } else {
                // Cambia quién paga: la validación anterior ya no aplica y se ubica en el grupo de pago que corresponda.
                $grupo = $paga === 'Ingenio' && $s['planificacion_id'] && $s['ingenio_id'] ? cengi_ins_grupo_abierto($db, $s['planificacion_id'], $s['ingenio_id']) : null;
                $db->prepare("UPDATE solicitudes_inscripcion SET tipo_pago=?, monto=?, moneda=?, cuota=?, pago_estado=?, validacion_ingenio=?, validacion_ingenio_por='', validacion_ingenio_en=NULL, pago_verificado_por='', pago_verificado_en=NULL, pago_nota=?, grupo_pago_id=? WHERE id_solicitud=?")
                    ->execute([$paga,$monto,$moneda,$tarifa,$paga==='Exonerado'?'exonerado':'pendiente',$paga==='Ingenio'?'pendiente':null,$motivo,$grupo,$id]);
                cengi_ins_recalcular_grupo($db, $grupo);
            }
            cengi_ins_recalcular_grupo($db, $s['grupo_pago_id']);
        } elseif ($accion === 'ingenio') {
            if ($s['tipo_pago'] !== 'Ingenio' || !cengi_ins_puede_validar_ingenio($s['nombre_ingenios'])) throw new InvalidArgumentException('Valida con el responsable del ingenio.');
            $db->prepare("UPDATE solicitudes_inscripcion SET validacion_ingenio='validada', validacion_ingenio_por=?, validacion_ingenio_en=NOW(), validacion_ingenio_nota=? WHERE id_solicitud=?")->execute([$por,$motivo,$id]);
        } elseif ($accion === 'comprobante') {
            if(!cengi_ins_puede_validar_pagos()||$s['tipo_pago']!=='Propio'||in_array($s['pago_estado'],['pagado','reembolsado'],true)) throw new InvalidArgumentException('Este pago no admite un comprobante nuevo.');
            $ref=mb_substr(trim((string)($_POST['referencia']??'')),0,60);$fecha=(string)($_POST['fecha']??'');$monto=filter_var($_POST['monto_comprobante']??'',FILTER_VALIDATE_FLOAT);
            if($ref===''||!cengi_pp_es_fecha($fecha)||$fecha>date('Y-m-d')||$monto===false||$monto<=0)throw new InvalidArgumentException('Indica referencia, fecha y monto del comprobante.');
            [$ruta,$nombre]=cengi_ins_guardar_comprobante($_FILES['archivo']??['error'=>UPLOAD_ERR_NO_FILE],'s'.$id);
            $db->prepare("UPDATE solicitudes_inscripcion SET boleta=?,pago_fecha=?,monto_comprobante=?,comprobante_ruta=?,comprobante_nombre=?,pago_estado='en revisión' WHERE id_solicitud=?")->execute([$ref,$fecha,$monto,$ruta,$nombre,$id]);
        } elseif ($accion === 'validar_pago') {
            if(!cengi_ins_puede_validar_pagos()||$s['tipo_pago']!=='Propio'||$s['pago_estado']!=='en revisión'||!$s['comprobante_ruta']||$s['monto']===null)throw new InvalidArgumentException('Primero define la cuota y registra el comprobante.');
            if(abs((float)$s['monto']-(float)$s['monto_comprobante'])>.009)throw new InvalidArgumentException('El monto del comprobante no coincide con la cuota. Revisa el pago antes de validar.');
            if(cengi_cob_boletas_repetidas($db,$s['boleta'],$id))throw new InvalidArgumentException('La boleta se utiliza en otra inscripción. Revisa la referencia antes de validar.');
            $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado='pagado',pago_referencia=boleta,pago_forma='Comprobante aceptado',pago_registrado_por=?,pago_registrado_en=NOW(),pago_verificado_por=?,pago_verificado_en=NOW(),pago_nota=? WHERE id_solicitud=?")->execute([$por,$por,$motivo,$id]);
        } elseif ($accion === 'pagado') {
            if (!cengi_ins_puede_validar_pagos() || $s['monto'] === null || $s['monto'] <= 0) throw new InvalidArgumentException('Se requiere permiso de pagos y una cuota definida.');
            cengi_ins_exigir_pago_propio_validado($s);
            $ref = mb_substr(trim((string) ($_POST['referencia'] ?? '')),0,120);
            $fecha = (string) ($_POST['fecha'] ?? '');
            $d = DateTime::createFromFormat('!Y-m-d',$fecha);
            if ($ref === '' || !$d || $d->format('Y-m-d') !== $fecha || $fecha > date('Y-m-d')) throw new InvalidArgumentException('Indica una referencia y fecha de pago válida.');
            $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado='pagado', pago_referencia=?, pago_fecha=?, pago_forma='Registro administrativo', pago_verificado_por=?, pago_verificado_en=NOW(), pago_registrado_por=?, pago_registrado_en=NOW(), pago_nota=? WHERE id_solicitud=?")->execute([$ref,$fecha,$por,$por,$motivo,$id]);
        } elseif ($accion === 'asignar') {
            if (!cengi_puede_aprobar_solicitudes()) throw new InvalidArgumentException('No tienes permiso de asignación.');
            if ($s['estado'] === 'Aprobado') throw new InvalidArgumentException('Ya está asignado.');
            if ($s['planificacion_id']) {
                // Asignación manual (p. ej. desde lista de espera): aunque el curso supere el cupo máximo.
                $plan = cengi_pp_plan($db, $s['planificacion_id']);
                if (empty($plan['curso_id'])) throw new InvalidArgumentException('La convocatoria aún no tiene curso abierto; se asigna al abrirse.');
                $s['curso_id'] = $plan['curso_id'];
                cengi_ins_asignar($db,$s,$por);
                $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'asignado', 'plan' => $plan, 'solicitud' => $s];
            } else {
                cengi_ins_asignar($db,$s,$por);
            }
        } else throw new InvalidArgumentException('Acción no válida.');
        $despues = cengi_ins_solicitud($db,$id);
        if(in_array($accion,['ingenio','validar_pago','pagado'],true)&&$despues['estado']==='Pendiente'&&cengi_ins_admitida($despues)) {
            // Con convocatoria: asigna según cupo o pasa a lista de espera; sin convocatoria: asigna directo.
            if ($despues['planificacion_id']) cengi_ins_procesar_plan($db, $despues['planificacion_id'], $por);
            else cengi_ins_asignar($db,$despues,$por);
            $despues=cengi_ins_solicitud($db,$id);
        } elseif ($accion === 'cuota' && $despues['planificacion_id']) {
            cengi_ins_procesar_plan($db, $despues['planificacion_id']);
            $despues=cengi_ins_solicitud($db,$id);
        }
        $db->prepare('INSERT INTO inscripcion_historial (solicitud_id,accion,usuario,motivo,antes,despues) VALUES (?,?,?,?,?,?)')->execute([$id,$accion,$por,$motivo,$antes,json_encode($despues,JSON_UNESCAPED_UNICODE)]);
        $db->commit(); cengi_ins_enviar_cola($db); $s=$despues;
        $mensaje = $despues['estado'] === 'Espera' ? 'Cambio guardado. El curso está lleno: la inscripción quedó en lista de espera.' : 'Cambio guardado. La inscripción y el cobro se conservan por curso.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $GLOBALS['cengi_ins_cola'] = [];
        if (function_exists('cengi_ins_deshacer_cuentas_creadas')) cengi_ins_deshacer_cuentas_creadas($db);
        $mensaje = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No fue posible guardar el cambio.'; $tipoMensaje='error';
        if (!($e instanceof InvalidArgumentException)) error_log('Inscripción: '.$e->getMessage());
    }
}
$q=$db->prepare('SELECT accion,usuario,motivo,creado FROM inscripcion_historial WHERE solicitud_id=? ORDER BY id DESC LIMIT 50');$q->execute([$id]);$historial=$q->fetchAll(PDO::FETCH_ASSOC);
// ---------------------------------------------------------------- datos para la vista
$e = 'cengi_pp_h';
$fh = static function ($iso) { return $iso ? date('d/m/Y H:i', strtotime((string) $iso)) : ''; };
$cobro = cengi_ins_estado_cobro($s);
$req = cengi_ins_requisito($s);
$rechazado = $s['estado'] === 'Rechazado';
$asignado = $s['estado'] === 'Aprobado';
$previa = strpos((string) $s['asignado_por'], 'Asignaci') === 0;
$cobroListo = in_array($cobro, ['pagado', 'exonerado'], true);
$sinCuota = $s['monto'] === null && $s['tipo_pago'] !== 'Exonerado';
$esPropio = $s['tipo_pago'] === 'Propio';
$esIngenio = $s['tipo_pago'] === 'Ingenio';
$puedePagos = cengi_ins_puede_validar_pagos();
$puedeCuota = $puedePagos && $cobro !== 'pagado';
$puedeComprobante = $puedePagos && $esPropio && !in_array($s['pago_estado'], ['pagado', 'reembolsado'], true);
$puedeIngenio = $esIngenio && ($s['validacion_ingenio'] ?? '') !== 'validada' && cengi_ins_puede_validar_ingenio($s['nombre_ingenios']);
$puedeAsignar = !$asignado && cengi_ins_admitida($s) && cengi_puede_aprobar_solicitudes();
$hayAcciones = !$rechazado && ($puedeCuota || $puedeComprobante || $puedeIngenio || $puedeAsignar);
$pagador = ['Ingenio' => 'Su ingenio', 'Propio' => 'Pago propio', 'Exonerado' => 'Sin costo'][$s['tipo_pago']] ?? $s['tipo_pago'];
if ($s['tipo_pago'] === 'Propio' && ($s['cuota'] ?? '') === 'asociado' && $s['ingenio_id']) $pagador = 'Pago propio · cuota de ingenio';
$tarifaTexto = isset(CENGI_INS_CUOTAS[$s['cuota'] ?? '']) ? CENGI_INS_CUOTAS[$s['cuota']][0] . ' (' . CENGI_INS_CUOTAS[$s['cuota']][1] . ')' : '—';
$origen = CENGI_INS_ORIGENES[$s['origen_inscripcion'] ?? 'sin_identificar'] ?? 'Registro anterior';

// Pasos del proceso: [titulo, estado (ok|pend|warn), detalle]
$pasos = [
    ['Registro', 'ok', cengi_pp_fecha($s['creado']) . ' · ' . $origen],
    ['Validación', $req['ok'] ? 'ok' : (($s['validacion_ingenio'] ?? '') === 'rechazada' || $s['pago_estado'] === 'rechazado' ? 'warn' : 'pend'), $req['texto']],
    ['Asignación', $asignado ? 'ok' : 'pend', $asignado ? ($previa ? 'Asignado antes del proceso' : 'Asignado') . ($s['asignado_en'] ? ' · ' . cengi_pp_fecha($s['asignado_en']) : '') : ($s['estado'] === 'Espera' ? 'En lista de espera' : 'Aún sin asignar')],
    ['Cobro', $cobroListo ? 'ok' : ($sinCuota ? 'warn' : 'pend'), $sinCuota ? 'Cuota por definir' : (CENGI_INS_ESTADOS_PAGO[$cobro][0] ?? ucfirst((string) $cobro))],
];

// Siguiente paso sugerido según el estado actual.
if ($rechazado) {
    $siguiente = ['warn', 'Inscripción rechazada o dada de baja', 'Para retomarla, reactívala desde el listado de Interesados.'];
} elseif (!$req['ok']) {
    $siguiente = ['pend', $esIngenio ? 'Falta la validación del ingenio' : 'Falta validar el pago propio',
        $esIngenio ? 'El responsable del ingenio (o Capacitación, indicando quién autoriza) debe validar el patrocinio.' : 'Registra el comprobante del participante y Administración acepta el pago.'];
} elseif ($sinCuota) {
    $siguiente = ['warn', 'Define la cuota del curso', 'Sin cuota no se puede incluir en la proforma ni registrar el pago. Si no tiene costo, marca “Exonerado” con monto 0.'];
} elseif (!$asignado) {
    $siguiente = ['pend', 'Lista para asignar al curso', 'La validación está completa; falta asignarla al curso.'];
} elseif (!$cobroListo) {
    $siguiente = ['pend', 'Falta registrar el pago', $esIngenio ? 'Incluye al participante en la proforma del ingenio y registra el pago del grupo.' : 'Cuando Administración confirme el depósito, registra el pago recibido.'];
} else {
    $siguiente = ['ok', 'Inscripción completa', 'Validada, asignada al curso y con el cobro resuelto.'];
}

const CENGI_INSD_ACCIONES = [
    'autorizar_ingenio' => ['Validación del ingenio', 'ok'], 'ingenio' => ['Patrocinio validado', 'ok'],
    'cuota' => ['Cuota y responsable actualizados', 'usd'], 'comprobante' => ['Comprobante registrado', 'paperclip'],
    'actualizar_boleta' => ['Boleta actualizada', 'paperclip'], 'validar_pago' => ['Pago aceptado', 'ok'],
    'aceptar_pago_propio' => ['Pago propio aceptado', 'ok'], 'pagado' => ['Pago registrado', 'usd'],
    'asignar' => ['Asignado al curso', 'education'], 'pago_lote' => ['Pago registrado en grupo', 'usd'], 'reenviar_formulario' => ['Enlace de boleta reenviado', 'envelope'],
];
?>
<!doctype html><html lang="es"><head><?php cengi_pp_head('Inscripción #' . $id); ?><link rel="stylesheet" href="css/inscripcion_detalle.css?v=<?= (int) @filemtime(__DIR__ . '/css/inscripcion_detalle.css') ?>"></head>
<body class="cengi-canvas cg-tema"><?php menu_render(); ?>
<main class="container pp-page insd-page">
<header class="ins-process-header"><small>INSCRIPCIÓN #<?= $id ?> · <?= $e(mb_strtoupper((string) $s['plan_nombre'], 'UTF-8')) ?></small><h1><?= $e($s['nombre_participante']) ?></h1><p>Seguimiento de la inscripción: validación, asignación al curso y cobro. Cada cambio queda en el historial con su motivo.</p></header>
<?php cengi_ins_nav('inscripciones'); cengi_pp_feedback($mensaje, $tipoMensaje); ?>
<a class="insd-back" href="inscripciones.php?vista=inscripciones&amp;q=<?= rawurlencode((string) $s['cui_participante']) ?>"><span class="glyphicon glyphicon-arrow-left" aria-hidden="true"></span> Volver a Inscritos</a>

<section class="insd-person">
 <div class="insd-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr(trim((string) $s['nombre_participante']), 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
 <div class="insd-person-info">
  <h2><?= $e($s['nombre_participante']) ?></h2>
  <p><?= $e($s['nombre_ingenios'] ?: ($s['institucion_otra'] ?: 'Sin institución')) ?><?= $s['puesto_participante'] ? ' · ' . $e($s['puesto_participante']) : '' ?></p>
  <ul class="insd-tags">
   <li><?= $e($pagador) ?></li>
   <?php if ($previa): ?><li class="is-amarillo">Asignado antes del proceso de validación</li><?php endif; ?>
   <?php if ($rechazado): ?><li class="is-naranja">Rechazada</li><?php endif; ?>
  </ul>
 </div>
 <div class="insd-fee"><small>Cuota del curso</small><strong><?= $s['monto'] === null ? ($esIngenio || $esPropio ? 'Por definir' : 'Sin costo') : cengi_ins_dinero($s['monto'], $s['moneda']) ?></strong><?= cengi_pp_badge(CENGI_INS_ESTADOS_PAGO, $cobro) ?></div>
</section>

<ol class="insd-steps" aria-label="Avance de la inscripción">
 <?php foreach ($pasos as $n => [$titulo, $estado, $detalle]): ?>
 <li class="is-<?= $estado ?>"><span class="insd-step-dot" aria-hidden="true"><?= $estado === 'ok' ? '<span class="glyphicon glyphicon-ok"></span>' : $n + 1 ?></span><span class="insd-step-text"><b><?= $titulo ?></b><small><?= $e($detalle) ?></small></span></li>
 <?php endforeach; ?>
</ol>

<div class="insd-next is-<?= $siguiente[0] ?>"><span class="glyphicon glyphicon-<?= $siguiente[0] === 'ok' ? 'ok-circle' : ($siguiente[0] === 'warn' ? 'alert' : 'time') ?>" aria-hidden="true"></span><div><strong><?= $e($siguiente[1]) ?></strong><p><?= $e($siguiente[2]) ?></p></div></div>

<div class="insd-layout">
 <div class="insd-col">
  <section class="insd-card">
   <h3><span class="glyphicon glyphicon-user" aria-hidden="true"></span> Participante</h3>
   <dl class="insd-dl">
    <dt>CUI</dt><dd><?= $e($s['cui_participante'] ?: '—') ?></dd>
    <dt>Correo</dt><dd><?= $s['correo'] ? '<a href="mailto:' . $e($s['correo']) . '">' . $e($s['correo']) . '</a>' : '—' ?></dd>
    <dt>Teléfono</dt><dd><?= $e($s['telefono'] ?: '—') ?></dd>
    <dt>Puesto · área</dt><dd><?= $e(trim($s['puesto_participante'] . ' · ' . $s['area_participante'], ' ·') ?: '—') ?></dd>
    <dt>Grado académico</dt><dd><?= $e($s['grado_academico'] ?: '—') ?></dd>
    <dt>Origen</dt><dd><?= $e($origen) ?><?= $s['como_se_entero'] ? '<small>' . $e($s['como_se_entero']) . '</small>' : '' ?></dd>
   </dl>
  </section>

  <section class="insd-card">
   <h3><span class="glyphicon glyphicon-check" aria-hidden="true"></span> Validación</h3>
   <dl class="insd-dl">
    <dt>Estado</dt><dd><span class="insd-chip is-<?= $pasos[1][1] ?>"><?= $e($req['texto']) ?></span></dd>
    <?php if ($esIngenio): ?>
     <dt>Validó</dt><dd><?= $e($s['validacion_ingenio_por'] ?: '—') ?><?= $s['validacion_ingenio_en'] ? '<small>' . $fh($s['validacion_ingenio_en']) . '</small>' : '' ?></dd>
     <?php if ($s['validacion_ingenio_nota']): ?><dt>Nota</dt><dd><?= $e($s['validacion_ingenio_nota']) ?></dd><?php endif; ?>
    <?php elseif ($esPropio): ?>
     <dt>Revisó</dt><dd><?= $e($s['pago_validado_por'] ?: ($s['pago_verificado_por'] ?: '—')) ?><?= $s['pago_validado_rol'] ? '<small>' . $e($s['pago_validado_rol']) . '</small>' : '' ?></dd>
    <?php endif; ?>
   </dl>
  </section>

  <section class="insd-card">
   <h3><span class="glyphicon glyphicon-usd" aria-hidden="true"></span> Cobro</h3>
   <dl class="insd-dl">
    <dt>Quién paga</dt><dd><?= $e($pagador) ?></dd>
    <dt>Cuota</dt><dd><?= $s['monto'] === null ? '<span class="insd-chip is-warn">Por definir</span>' : cengi_ins_dinero($s['monto'], $s['moneda']) ?><small>Tarifa: <?= $e($tarifaTexto) ?></small></dd>
    <dt>Estado</dt><dd><?= cengi_pp_badge(CENGI_INS_ESTADOS_PAGO, $cobro) ?></dd>
    <?php if ($esIngenio): ?><dt>Orden / proforma</dt><dd><?= $s['grupo_orden'] ? $e($s['grupo_orden']) : 'Sin orden de pago del grupo' ?></dd><?php endif; ?>
    <?php if ($s['pago_referencia'] || $s['pago_fecha']): ?><dt>Referencia</dt><dd><?= $e($s['pago_referencia'] ?: $s['boleta'] ?: '—') ?><?= $s['pago_fecha'] ? '<small>' . cengi_pp_fecha($s['pago_fecha']) . ($s['pago_forma'] ? ' · ' . $e($s['pago_forma']) : '') . '</small>' : '' ?></dd><?php endif; ?>
    <?php if ($s['pago_verificado_por']): ?><dt>Verificó</dt><dd><?= $e($s['pago_verificado_por']) ?><?= $s['pago_verificado_en'] ? '<small>' . $fh($s['pago_verificado_en']) . '</small>' : '' ?></dd><?php endif; ?>
    <?php if ($s['comprobante_ruta']): ?><dt>Comprobante</dt><dd><a target="_blank" rel="noopener" href="comprobante_inscripcion.php?s=<?= $id ?>"><span class="glyphicon glyphicon-paperclip" aria-hidden="true"></span> Ver comprobante</a><small>Boleta <?= $e($s['boleta'] ?: '—') ?><?= $s['monto_comprobante'] !== null ? ' · ' . cengi_ins_dinero($s['monto_comprobante'], $s['moneda']) : '' ?></small></dd><?php endif; ?>
    <?php if ($s['pago_nota']): ?><dt>Nota</dt><dd><?= $e($s['pago_nota']) ?></dd><?php endif; ?>
   </dl>
   <?php if ($esIngenio && !$cobroListo && !$rechazado): ?><a class="insd-link-btn" href="<?= $s['planificacion_id'] ? 'inscripciones.php?' . $e(http_build_query(['vista' => 'pagos', 'tab' => 'grupos', 'plan' => (int) $s['planificacion_id'], 'anio' => (int) $s['anio']])) : 'inscripcion_preparar_cobro.php?curso=' . (int) $s['curso_id'] ?>"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Preparar proforma y pago del grupo</a><?php endif; ?>
   <?php if ($esPropio && !$rechazado && !in_array($s['pago_estado'], ['pagado', 'validado'], true)): ?>
    <div class="insd-share"><label for="insd-link">Enlace seguro para que el participante adjunte su boleta</label><div class="insd-share-row"><input id="insd-link" readonly value="<?= $e(cengi_cob_url_comprobante($db, $s)) ?>" onclick="this.select()"><button type="button" onclick="var i=document.getElementById('insd-link');i.select();var b=this;if(navigator.clipboard){navigator.clipboard.writeText(i.value).then(function(){b.textContent='Copiado';});}else{b.textContent='Ctrl+C';}">Copiar</button></div></div>
   <?php endif; ?>
  </section>
 </div>

 <div class="insd-col">
  <?php if ($hayAcciones): ?>
  <section class="insd-card insd-actions">
   <h3><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span> Registrar un cambio</h3>
   <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $e($_SESSION['ins_csrf']) ?>">
    <label class="insd-field insd-motivo">Motivo o evidencia <span>(obligatorio, queda en el historial)</span>
     <textarea name="motivo" required maxlength="500" placeholder="<?= $puedeIngenio ? 'Si validas por el ingenio, indica nombre y cargo de quien autoriza.' : 'Ej.: boleta recibida por correo, acuerdo con el ingenio, corrección de cuota…' ?>"></textarea></label>

    <?php if ($puedeIngenio): ?>
    <fieldset class="insd-group"><legend>Validación del ingenio</legend>
     <p>Confirma que el ingenio autoriza y cubre la participación.</p>
     <button class="btn btn-primary" name="accion" value="ingenio"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Validar patrocinio</button>
    </fieldset>
    <?php endif; ?>

    <?php if ($puedeCuota): ?>
    <fieldset class="insd-group"><legend>Cuota y responsable del pago</legend>
     <div class="insd-grid">
      <label class="insd-field">Quién paga<select name="tipo_pago"><?php foreach (['Ingenio' => 'Ingenio', 'Propio' => 'Pago propio', 'Exonerado' => 'Exonerado (sin costo)'] as $v => $t): ?><option value="<?= $v ?>" <?= $s['tipo_pago'] === $v ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <label class="insd-field">Tarifa<select name="cuota"><?php foreach (CENGI_INS_CUOTAS as $v => [$t, $ayuda]): ?><option value="<?= $v ?>" <?= ($s['cuota'] ?: 'asociado') === $v ? 'selected' : '' ?>><?= $e($t . ' · ' . $ayuda) ?></option><?php endforeach; ?></select></label>
      <label class="insd-field">Cuota por este curso (<?= ($s['moneda'] ?? '') === 'USD' ? 'US$' : 'Q' ?>)<input name="monto" type="number" min="0" step="0.01" value="<?= $e($s['monto']) ?>" placeholder="<?= $s['planificacion_id'] ? 'Vacío = según la tarifa' : '0.00' ?>"></label>
     </div>
     <p class="insd-hint">Quien trabaja en un ingenio y paga por su cuenta: «Pago propio» con tarifa «Asociado». <?= $s['planificacion_id'] ? 'Deja la cuota vacía para calcularla según la tarifa de la convocatoria. ' : '' ?>Cambiar quién paga reinicia la validación.</p>
     <button class="btn btn-default" name="accion" value="cuota"><span class="glyphicon glyphicon-floppy-disk" aria-hidden="true"></span> Guardar cuota y responsable</button>
    </fieldset>
    <?php endif; ?>

    <?php if ($puedeComprobante): ?>
    <fieldset class="insd-group"><legend>Pago propio</legend>
     <div class="insd-grid">
      <label class="insd-field">Referencia / boleta<input name="referencia" maxlength="120"></label>
      <label class="insd-field">Fecha de pago<input name="fecha" type="date" max="<?= date('Y-m-d') ?>"></label>
      <?php if ($s['pago_estado'] !== 'validado'): ?>
      <label class="insd-field">Monto del comprobante (Q)<input name="monto_comprobante" type="number" min="0" step="0.01"></label>
      <label class="insd-field">Comprobante <small>PDF, JPG o PNG · hasta 5 MB</small><input name="archivo" type="file" accept="application/pdf,image/jpeg,image/png"></label>
      <?php endif; ?>
     </div>
     <div class="insd-buttons">
      <?php if ($s['pago_estado'] !== 'validado'): ?><button class="btn btn-default" name="accion" value="comprobante"><span class="glyphicon glyphicon-upload" aria-hidden="true"></span> Registrar comprobante</button><?php endif; ?>
      <?php if ($s['pago_estado'] === 'en revisión'): ?><button class="btn btn-primary" name="accion" value="validar_pago"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Aceptar pago e inscribir</button><?php endif; ?>
      <?php if ($s['pago_estado'] === 'validado'): ?><button class="btn btn-success" name="accion" value="pagado"><span class="glyphicon glyphicon-usd" aria-hidden="true"></span> Registrar pago recibido</button><?php endif; ?>
     </div>
    </fieldset>
    <?php endif; ?>

    <?php if ($puedeAsignar): ?>
    <fieldset class="insd-group"><legend>Asignación</legend>
     <p>La validación está completa: asigna al participante al curso.</p>
     <button class="btn btn-primary" name="accion" value="asignar"><span class="glyphicon glyphicon-education" aria-hidden="true"></span> Asignar al curso</button>
    </fieldset>
    <?php endif; ?>
   </form>
  </section>
  <?php else: ?>
  <section class="insd-card insd-empty"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span><p><?= $rechazado ? 'La inscripción está rechazada; reactívala desde el listado para registrar cambios.' : 'No hay acciones disponibles para tu rol en esta inscripción.' ?></p></section>
  <?php endif; ?>

  <section class="insd-card">
   <h3><span class="glyphicon glyphicon-time" aria-hidden="true"></span> Historial</h3>
   <?php if ($historial): ?>
   <ol class="insd-timeline">
    <?php foreach ($historial as $h): [$etq, $ico] = CENGI_INSD_ACCIONES[$h['accion']] ?? [ucfirst(str_replace('_', ' ', $h['accion'])), 'record']; ?>
    <li><span class="insd-tl-dot glyphicon glyphicon-<?= $ico ?>" aria-hidden="true"></span><div><b><?= $e($etq) ?></b><small><?= $fh($h['creado']) ?> · <?= $e($h['usuario']) ?></small><?php if ($h['motivo'] !== ''): ?><p><?= $e($h['motivo']) ?></p><?php endif; ?></div></li>
    <?php endforeach; ?>
    <?php if ($asignado && $previa): ?><li class="is-previa"><span class="insd-tl-dot glyphicon glyphicon-education" aria-hidden="true"></span><div><b>Asignado antes del proceso de validación</b><small><?= $fh($s['asignado_en']) ?></small><p><?= $e($s['asignado_por']) ?></p></div></li><?php endif; ?>
   </ol>
   <?php else: ?><p class="insd-hint">No hay cambios registrados todavía.</p><?php endif; ?>
  </section>
 </div>
</div>
</main></body></html>
