<?php
require_once __DIR__ . '/inscripcion_registro.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/administracion_nav.php';
require_once __DIR__ . '/cobranza_helpers.php';
if (!cengi_puede_ver_solicitudes() && !cengi_ins_puede_ver_pagos()) { header('Location: ver_cursos.php'); exit; }
$db = conectar();
$id = (int) ($_GET['id'] ?? 0);
$s = cengi_ins_solicitud($db, $id);
$limite = cengi_ins_ingenio_limitado();
if (!$s || ($limite !== null && cengi_texto_normalizado($s['nombre_ingenios'] ?? '') !== cengi_texto_normalizado($limite))) {
    http_response_code(403); exit('Inscripción no disponible.');
}
if ($s['planificacion_id']) {
    header('Location: pagos_inscripcion.php?plan=' . (int) $s['planificacion_id'] . '&anio=' . (int) $s['anio'] . '&q=' . rawurlencode($s['cui_participante'])); exit;
}
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
            $monto = filter_var($_POST['monto'] ?? '', FILTER_VALIDATE_FLOAT);
            $paga = (string) ($_POST['tipo_pago'] ?? '');
            if ($monto === false || $monto < 0 || $monto > 99999999 || !in_array($paga,['Ingenio','Propio','Exonerado'],true)) throw new InvalidArgumentException('Revisa el monto y quién paga.');
            if ($paga === 'Exonerado' && $monto != 0) throw new InvalidArgumentException('La exoneración debe tener monto cero.');
            if ($paga !== 'Exonerado' && $monto <= 0) throw new InvalidArgumentException('Indica la cuota; utiliza exoneración si es sin costo.');
            $db->prepare("UPDATE solicitudes_inscripcion SET tipo_pago=?, monto=?, moneda='GTQ', pago_estado=?, validacion_ingenio=?, validacion_ingenio_por='', validacion_ingenio_en=NULL, pago_verificado_por='', pago_verificado_en=NULL, pago_nota=? WHERE id_solicitud=?")
                ->execute([$paga,$monto,$paga==='Exonerado'?'exonerado':'pendiente',$paga==='Ingenio'?'pendiente':null,$motivo,$id]);
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
            cengi_ins_asignar($db,$s,$por);
        } else throw new InvalidArgumentException('Acción no válida.');
        $despues = cengi_ins_solicitud($db,$id);
        if(in_array($accion,['ingenio','validar_pago','pagado'],true)&&$despues['estado']==='Pendiente'&&cengi_ins_admitida($despues)) {
            cengi_ins_asignar($db,$despues,$por);$despues=cengi_ins_solicitud($db,$id);
        }
        $db->prepare('INSERT INTO inscripcion_historial (solicitud_id,accion,usuario,motivo,antes,despues) VALUES (?,?,?,?,?,?)')->execute([$id,$accion,$por,$motivo,$antes,json_encode($despues,JSON_UNESCAPED_UNICODE)]);
        $db->commit(); $s=$despues; $mensaje='Cambio guardado. La inscripción y el cobro se conservan por curso.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $mensaje = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No fue posible guardar el cambio.'; $tipoMensaje='error';
        if (!($e instanceof InvalidArgumentException)) error_log('Inscripción: '.$e->getMessage());
    }
}
$q=$db->prepare('SELECT accion,usuario,motivo,creado FROM inscripcion_historial WHERE solicitud_id=? ORDER BY id DESC LIMIT 50');$q->execute([$id]);$historial=$q->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="es"><head><?php cengi_pp_head('Inscripción y cobro'); ?></head><body class="cengi-canvas"><?php menu_render(); ?>
<main class="container pp-page"><header class="pp-page-header"><h1>Inscripción #<?php echo $id; ?></h1><p><?php echo cengi_pp_h($s['nombre_participante'].' · '.$s['plan_nombre']); ?></p><a href="inscripciones.php">Volver a Inscripciones</a></header>
<?php cengi_ins_nav('pagos'); cengi_pp_feedback($mensaje,$tipoMensaje); ?>
<section class="pp-card"><?php if($s['tipo_pago']==='Propio'&&!in_array($s['pago_estado'],['pagado','validado'],true)): ?><p>Espacio seguro para adjuntar boleta: <input class="form-control" readonly value="<?= cengi_pp_h(cengi_cob_url_comprobante($db,$s)) ?>"></p><?php endif; ?><h2>1. Registro · 2. Validación · 3. Asignación · 4. Cobro</h2>
<p>Estado: <b><?php echo cengi_pp_h($s['estado']); ?></b> · Responsable: <b><?php echo cengi_pp_h($s['tipo_pago']); ?></b> · Cobro: <b><?php echo cengi_pp_h(cengi_ins_estado_cobro($s)); ?></b></p>
<p>Cuota: <?php echo $s['monto'] === null ? 'Por definir; no se considera gratuita' : cengi_ins_dinero($s['monto'],$s['moneda']); ?> · <?php echo cengi_pp_h(cengi_ins_requisito($s)['texto']); ?></p>
<p>Validar el patrocinio autoriza la participación. Solo registrar el pago con evidencia marca la inscripción como pagada.</p>
<?php if($s['tipo_pago']==='Ingenio'): ?><p><a class="btn btn-primary" href="inscripcion_preparar_cobro.php?curso=<?= (int)$s['curso_id'] ?>">Preparar proforma y pago del grupo</a></p><?php endif; ?>
<?php if($s['comprobante_ruta']): ?><p><a target="_blank" rel="noopener" href="comprobante_inscripcion.php?s=<?= $id ?>">Revisar comprobante</a> · <?= cengi_ins_dinero($s['monto_comprobante'],$s['moneda']) ?> · Boleta <?= cengi_pp_h($s['boleta']) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo cengi_pp_h($_SESSION['ins_csrf']); ?>">
<?php if (cengi_ins_puede_validar_pagos()): ?><div class="row"><label class="col-sm-4">Quién paga<select class="form-control" name="tipo_pago"><?php foreach(['Ingenio','Propio','Exonerado'] as $p): ?><option <?php echo $s['tipo_pago']===$p?'selected':''; ?>><?php echo $p; ?></option><?php endforeach; ?></select></label><label class="col-sm-4">Cuota por este curso (Q)<input class="form-control" name="monto" type="number" min="0" step="0.01" value="<?php echo cengi_pp_h($s['monto']); ?>"></label></div>
<div class="row"><label class="col-sm-4">Referencia / recibo<input class="form-control" name="referencia" maxlength="120"></label><label class="col-sm-4">Fecha de pago<input class="form-control" name="fecha" type="date" max="<?php echo date('Y-m-d'); ?>"></label></div><?php endif; ?>
<label>Motivo o evidencia; si aceptas por el ingenio, indica nombre y cargo de quien autoriza<textarea class="form-control" name="motivo" required maxlength="500"></textarea></label><div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:15px">
<?php if(cengi_ins_puede_validar_pagos()): ?><button class="btn btn-default" name="accion" value="cuota">Guardar cuota y responsable</button><?php if($s['tipo_pago']==='Propio'): ?><label>Monto comprobante<input class="form-control" name="monto_comprobante" type="number" min="0" step="0.01"></label><label>Comprobante (PDF, JPG o PNG · hasta 5 MB)<input name="archivo" type="file" accept="application/pdf,image/jpeg,image/png"></label><button class="btn btn-default" name="accion" value="comprobante">Registrar comprobante</button><?php if($s['pago_estado']==='en revisión'): ?><button class="btn btn-primary" name="accion" value="validar_pago">Aceptar pago e inscribir</button><?php endif; ?><?php if($s['pago_estado']==='validado'): ?><button class="btn btn-success" name="accion" value="pagado">Registrar pago recibido</button><?php endif; ?><?php endif; ?><?php endif; ?>
<?php if($s['tipo_pago']==='Ingenio' && cengi_ins_puede_validar_ingenio($s['nombre_ingenios'])): ?><button class="btn btn-primary" name="accion" value="ingenio">Validar patrocinio</button><?php endif; ?>
<?php if($s['estado']!=='Aprobado' && cengi_ins_admitida($s) && cengi_puede_aprobar_solicitudes()): ?><button class="btn btn-primary" name="accion" value="asignar">Asignar al curso</button><?php endif; ?></div></form></section>
<section class="pp-card"><h2>Historial de esta inscripción</h2><?php foreach($historial as $h): ?><p><b><?php echo cengi_pp_h($h['creado'].' · '.$h['accion'].' · '.$h['usuario']); ?></b><br><?php echo cengi_pp_h($h['motivo']); ?></p><?php endforeach; ?><?php if(!$historial): ?><p>No hay cambios registrados todavía.</p><?php endif; ?></section></main></body></html>
