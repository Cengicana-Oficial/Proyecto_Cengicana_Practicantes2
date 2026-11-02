<?php
require_once __DIR__.'/menu.php';
require_once __DIR__.'/recibos_contables_helpers.php';
require_once __DIR__.'/administracion_nav.php';
require_once __DIR__.'/recibos_correo_helpers.php';
if(!cengi_ins_puede_validar_pagos()){http_response_code(403);exit('Este seguimiento corresponde a Administración.');}
$db=conectar();
if(empty($_SESSION['rc_csrf']))$_SESSION['rc_csrf']=bin2hex(random_bytes(32));
$fuente=(string)($_GET['fuente']??$_POST['fuente']??'');$id=(int)($_GET['id']??$_POST['id']??0);
$pago=$id?cengi_rc_pago($db,$fuente,$id):null;
$error='';$subido=null;
$volverExpediente='inscripciones.php?'.http_build_query(['vista'=>'recibos','fuente'=>$fuente,'id'=>$id]);
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if(!hash_equals($_SESSION['rc_csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Recarga la página antes de guardar.');
  if (($_POST['accion']??'')==='configurar_correo') {
   cengi_rc_mail_config_guardar($db,$_POST,cengi_pp_usuario_nombre());header('Location: inscripciones.php?vista=recibos&guardado=config');exit;
  }
  if (($_POST['accion']??'')==='solicitar_correo') {
   if (!$pago) throw new InvalidArgumentException('El pago no está disponible.');
   cengi_rc_solicitar_email($db,$pago,cengi_pp_usuario_nombre(),$_POST['nota']??'');header('Location: '.$volverExpediente.'&guardado=solicitud#rc-expediente');exit;
  }
  if(!$pago)throw new InvalidArgumentException('El pago no existe o no está registrado como pagado.');
  $destinatario=trim((string)($_POST['destinatario']??''));
  if(($_POST['accion']??'')==='enviar_recibo') {
   cengi_rc_enviar_recibo($db,$pago,$destinatario,cengi_pp_usuario_nombre(),null,trim((string)($_POST['mensaje']??'')));
   $aviso='enviado';
  } elseif(($_POST['accion']??'')==='recibir') {
   $db->beginTransaction();
   [$ruta,$nombre]=cengi_ins_guardar_comprobante($_FILES['archivo']??['error'=>UPLOAD_ERR_NO_FILE],'recibo_contable');$subido=$ruta;
   cengi_rc_guardar($db,$pago,'recibir',$_POST,cengi_pp_usuario_nombre(),['ruta'=>$ruta,'nombre'=>$nombre]);
   $db->commit();$subido=null;
   $aviso='recibido';
  } else throw new InvalidArgumentException('Acción no válida.');
  header('Location: '.$volverExpediente.'&guardado='.$aviso.'#rc-expediente');exit;
 } catch(Throwable $e) {
  if($db->inTransaction())$db->rollBack();
  if($subido&&($f=cengi_rc_archivo($subido)))unlink($f);
  $error=$e instanceof InvalidArgumentException?$e->getMessage():'No se pudo guardar el seguimiento. Intenta de nuevo.';error_log('Recibos contables: '.$e->getMessage());
 }
}
$configCorreo=cengi_rc_mail_config($db);
$r=null;$historial=[];
if($pago){
 // El expediente se crea al abrirlo: así la vista previa ya muestra su folio RC-000000 real
 // (antes decía "Se asignará al enviar" dentro del asunto y del cuerpo del correo).
 $db->prepare('INSERT IGNORE INTO recibos_contables(fuente,fuente_id) VALUES(?,?)')->execute([$pago['fuente'],$pago['fuente_id']]);
 $q=$db->prepare('SELECT * FROM recibos_contables WHERE fuente=? AND fuente_id=?');$q->execute([$fuente,$id]);$r=$q->fetch(PDO::FETCH_ASSOC)?:['id'=>0,'estado'=>'pendiente'];
 if($r['id']){$q=$db->prepare('SELECT * FROM recibos_contables_historial WHERE recibo_id=? ORDER BY id DESC');$q->execute([$r['id']]);$historial=$q->fetchAll(PDO::FETCH_ASSOC);}
}
if(!empty($_GET['archivo'])) {
 if(!$r||empty($r['archivo_ruta'])||!($f=cengi_rc_archivo($r['archivo_ruta']))){http_response_code(404);exit('Recibo no disponible.');}
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f);header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Disposition: inline; filename="'.preg_replace('/[^a-zA-Z0-9._-]/','_', (string)$r['archivo_nombre']).'"');readfile($f);exit;
}

// [texto del filtro, etiqueta corta, tono]
$estados=[
 'pendiente'=>['Pendiente de solicitar','Por solicitar','naranja'],
 'solicitado'=>['Solicitud enviada a Centro Financiero','Solicitado','amarillo'],
 'recibido'=>['Recibido · pendiente de enviar','Recibido · por enviar','verde2'],
 'enviado'=>['Recibo enviado al participante / pagador','Enviado','verde'],
];
$pasos=[1=>'Ver solicitud',2=>'Enviar solicitud',3=>'Cargar recibo',4=>'Enviar al pagador'];
$fuentes=['persona'=>'Individual','grupo'=>'Pago grupal','proforma'=>'Proforma'];
// Paso habilitado / completado según el estado del expediente.
$pasoHabilitado=static function($n,$estado){return $n<=2||($n===3&&$estado!=='pendiente')||($n===4&&in_array($estado,['recibido','enviado'],true));};
$pasoHecho=static function($n,$estado){$orden=['pendiente'=>0,'solicitado'=>2,'recibido'=>3,'enviado'=>4];return $n>1&&$n<=($orden[$estado]??0);};
$pasoSiguiente=static function($estado){return ['pendiente'=>2,'solicitado'=>3,'recibido'=>4,'enviado'=>4][$estado]??1;};

$busqueda=trim((string)($_GET['q']??''));$estado=(string)($_GET['estado']??'');if(!isset($estados[$estado]))$estado='';
$from=' FROM ('.cengi_rc_pagos_sql().') p LEFT JOIN recibos_contables r ON r.fuente=p.fuente AND r.fuente_id=p.fuente_id';
$where=' WHERE 1=1';$params=[];
if($busqueda!==''){$where.=' AND (p.pagador LIKE ? OR p.concepto LIKE ? OR p.referencia LIKE ? OR r.numero LIKE ?)';$params=array_fill(0,4,'%'.$busqueda.'%');}
$conteos=array_fill_keys(array_keys($estados),0);
$q=$db->prepare("SELECT COALESCE(r.estado,'pendiente') estado,COUNT(*)".$from.$where.' GROUP BY 1');$q->execute($params);
foreach($q->fetchAll(PDO::FETCH_KEY_PAIR) as $k=>$n)if(isset($conteos[$k]))$conteos[$k]=(int)$n;
if($estado!==''){$where.=" AND COALESCE(r.estado,'pendiente')=?";$params[]=$estado;}
$total=$estado!==''?$conteos[$estado]:array_sum($conteos);$paginas=max(1,(int)ceil($total/25));$pagina=max(1,min($paginas,(int)($_GET['pagina']??1)));
$q=$db->prepare("SELECT p.*,COALESCE(r.estado,'pendiente') estado,r.responsable,r.numero,r.solicitado_fecha,r.recibido_fecha,r.enviado_fecha".$from.$where.' ORDER BY p.fecha DESC,p.fuente,p.fuente_id DESC LIMIT 25 OFFSET '.(($pagina-1)*25));$q->execute($params);$lista=$q->fetchAll(PDO::FETCH_ASSOC);

$paso=max(1,min(4,(int)($_GET['paso']??($r?$pasoSiguiente($r['estado']):1))));
if($r&&$r['estado']==='pendiente'&&!isset($_GET['paso']))$paso=1;
$pasoUrl=static function($step)use($fuente,$id){return 'inscripciones.php?'.http_build_query(['vista'=>'recibos','fuente'=>$fuente,'id'=>$id,'paso'=>$step]).'#rc-expediente';};
$puedeEnviarSolicitud=$r && $r['estado']==='pendiente';
if($r && $r['estado']==='solicitado'){$q=$db->prepare('SELECT COUNT(*) FROM recibos_contables_envios WHERE recibo_id=? AND enviado IS NULL');$q->execute([$r['id']]);$puedeEnviarSolicitud=(int)$q->fetchColumn()>0;}
$solicitudAsunto='';$solicitudHtml='';
if($pago){
 $referencia=cengi_rc_referencia($r['id']??0);
 $detalle=$pago;
 if($fuente==='proforma'){require_once __DIR__.'/proformas_helpers.php';$pf=cengi_pf_cargar($db,$id);$detalle['cursos']=array_map(static function($l)use($pf){return $l['plan_nombre'].' · '.$l['cantidad'].' cupo(s) · '.cengi_ins_dinero($l['total'],$pf['moneda']);},$pf['lineas']);}
 [$solicitudAsunto,$solicitudHtml]=cengi_rc_solicitud_email($detalle,$referencia);
}
$link=static function(array $cambios)use($busqueda,$estado){return 'inscripciones.php?'.http_build_query(array_filter(array_merge(['vista'=>'recibos','q'=>$busqueda,'estado'=>$estado],$cambios),static function($v){return $v!==''&&$v!==null&&$v!==1;}));};
$fechaHora=static function($v){return $v?date('d/m/Y H:i',strtotime((string)$v)):'—';};
$avisos=['config'=>'Configuración de correo guardada.','solicitud'=>'Solicitud enviada a Centro Financiero.','recibido'=>'Recibo guardado. Ya puedes enviarlo al pagador.','enviado'=>'Recibo enviado al pagador.'];
$aviso=$avisos[$_GET['guardado']??'']??(isset($_GET['guardado'])?'Seguimiento guardado.':'');
$v=static function($archivo){return (int)(@filemtime(__DIR__.'/'.$archivo)?:1);};
$csrf='<input type="hidden" name="csrf" value="'.cengi_pp_h($_SESSION['rc_csrf']).'"><input type="hidden" name="fuente" value="'.cengi_pp_h($fuente).'"><input type="hidden" name="id" value="'.$id.'">';
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recibos contables</title>
<link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
<link rel="stylesheet" href="css/bootstrap.min.css"><link rel="stylesheet" href="css/proyecto.css?v=<?=$v('css/proyecto.css')?>"><link rel="stylesheet" href="css/cengi-tema.css?v=<?=$v('css/cengi-tema.css')?>"><link rel="stylesheet" href="css/ingenios.css?v=<?=$v('css/ingenios.css')?>"><link rel="stylesheet" href="css/planificacion.css?v=<?=$v('css/planificacion.css')?>"><link rel="stylesheet" href="css/recibos_contables.css?v=<?=$v('css/recibos_contables.css')?>"></head>
<body class="cengi-canvas cg-tema"><?php menu_render(); ?><main class="container ing-page rc-page">
<header class="ing-hero"><div><small>Administración · pagos recibidos</small><h1>Recibos contables</h1><p>Gestiona cada pago en cuatro pasos: revisar la solicitud, enviarla a Centro Financiero, cargar el recibo y enviarlo al pagador.</p></div></header>
<?php cengi_ins_nav('recibos'); ?>
<?php if($error):?><div class="alert alert-danger" role="alert"><?=cengi_pp_h($error)?></div><?php endif;?>
<?php if($aviso!==''):?><div class="alert alert-success" role="status"><?=cengi_pp_h($aviso)?></div><?php endif;?>
<?php if($id&&!$pago):?><div class="alert alert-warning">El pago seleccionado ya no está disponible para este trámite.</div><?php endif;?>

<nav class="rc-kpis" aria-label="Filtrar por estado">
 <a href="<?=cengi_pp_h($link(['estado'=>'','pagina'=>'']))?>" class="rc-kpi is-todos<?=$estado===''?' is-active':''?>"<?=$estado===''?' aria-current="true"':''?>><b><?=array_sum($conteos)?></b><span>Todos los pagos</span></a>
 <?php foreach($estados as $k=>[,$corto,$tono]):?>
 <a href="<?=cengi_pp_h($link(['estado'=>$k,'pagina'=>'']))?>" class="rc-kpi is-<?=$tono?><?=$estado===$k?' is-active':''?>"<?=$estado===$k?' aria-current="true"':''?>><b><?=$conteos[$k]?></b><span><?=cengi_pp_h($corto)?></span></a>
 <?php endforeach;?>
</nav>

<section class="pp-card rc-config-card"><details<?=($error&&($_POST['accion']??'')==='configurar_correo')||!$configCorreo['destinatarios']?' open':''?>><summary>
 <span class="rc-config-title"><span class="glyphicon glyphicon-cog" aria-hidden="true"></span> Configuración de correo</span>
 <span class="rc-config-resumen">
  <span><b>Centro Financiero:</b> <?=cengi_pp_h($configCorreo['destinatarios']?:'sin configurar')?></span>
  <span><b>Respuestas:</b> <?=cengi_pp_h($configCorreo['responder_a']?:'sin configurar')?></span>
  <span class="rc-tag <?=$configCorreo['automatico']?'is-verde':'is-gris'?>">Solicitud automática <?=$configCorreo['automatico']?'activa desde '.cengi_pp_fecha($configCorreo['desde']):'desactivada'?></span>
 </span></summary>
<form method="post" class="rc-form rc-config"><input type="hidden" name="csrf" value="<?=cengi_pp_h($_SESSION['rc_csrf'])?>"><input type="hidden" name="accion" value="configurar_correo">
<label class="rc-wide">Destinatarios de Centro Financiero <small>separados por coma o punto y coma</small><textarea class="form-control" name="destinatarios" rows="2" required><?=cengi_pp_h($configCorreo['destinatarios'])?></textarea></label>
<label>Correo que recibe las respuestas<input class="form-control" type="email" name="responder_a" value="<?=cengi_pp_h($configCorreo['responder_a'])?>" required></label>
<label>Incluir pagos recibidos desde<input class="form-control" type="date" name="desde" value="<?=cengi_pp_h($configCorreo['desde'])?>" required></label>
<label class="rc-checkbox rc-wide"><input type="checkbox" name="automatico" value="1" <?=$configCorreo['automatico']?'checked':''?>> Solicitar automáticamente los recibos de pagos nuevos</label>
<p>La fecha inicial evita solicitar en lote recibos de pagos antiguos. Las respuestas llegan a Outlook de Administración; al recibir el recibo, cárgalo en el paso 3 y Cengicursos lo enviará adjunto al participante o encargado del ingenio.</p><button class="ing-btn">Guardar configuración</button></form></details></section>

<?php if($pago):
 [, $estadoCorto, $estadoTono]=$estados[$r['estado']];
 $fechasPaso=[1=>$pago['fecha'],2=>$r['solicitado_fecha']??null,3=>$r['recibido_fecha']??null,4=>$r['enviado_fecha']??null];
?>
<section class="pp-card rc-expediente" id="rc-expediente">
 <header class="rc-exp-head">
  <div>
   <span class="rc-tag is-gris"><?=cengi_pp_h($fuentes[$fuente]??$fuente)?></span> <span class="rc-tag is-<?=$estadoTono?>"><?=cengi_pp_h($estadoCorto)?></span> <?php if($r['id']):?><span class="rc-folio">Folio <?=cengi_pp_h(cengi_rc_referencia($r['id']))?></span><?php endif;?>
   <h2><?=cengi_pp_h($pago['pagador'])?></h2>
   <p><?=cengi_pp_h($pago['concepto'])?></p>
  </div>
  <div class="rc-exp-monto"><b><?=cengi_ins_dinero($pago['monto'],$pago['moneda'])?></b><small>Pagado el <?=cengi_pp_h($pago['fecha']?cengi_pp_fecha($pago['fecha']):'(fecha no registrada)')?></small><small>Referencia: <?=cengi_pp_h($pago['referencia']?:'sin referencia')?></small></div>
 </header>
 <div class="rc-exp-links">
  <?php if($fuente==='proforma'):?><a class="ing-btn is-linea" href="proforma.php?id=<?=$id?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver proforma y comprobante</a>
  <?php else:?><a class="ing-btn is-linea" href="comprobante_inscripcion.php?<?=$fuente==='persona'?'s':'g'?>=<?=$id?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> Ver comprobante de pago</a><?php endif;?>
  <?php if(!empty($r['archivo_ruta'])):?><a class="ing-btn is-linea" href="<?=cengi_pp_h($volverExpediente.'&archivo=1')?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-paperclip" aria-hidden="true"></span> Ver recibo <?=cengi_pp_h($r['numero'])?></a><?php endif;?>
  <a class="rc-cerrar" href="<?=cengi_pp_h($link([]))?>">Cerrar expediente ✕</a>
 </div>

 <ol class="rc-steps" aria-label="Pasos del trámite">
 <?php foreach($pasos as $n=>$label):$hab=$pasoHabilitado($n,$r['estado']);$hecho=$pasoHecho($n,$r['estado']);$clase=trim(($paso===$n?'is-current ':'').($hecho?'is-done ':'').($hab?'':'is-disabled'));
  $nota=$n===1?'':($fechasPaso[$n]?cengi_pp_fecha($fechasPaso[$n]):($hab?'Disponible':'Bloqueado'));?>
  <li class="<?=$clase?>"><?php if($hab):?><a href="<?=cengi_pp_h($pasoUrl($n))?>"<?=$paso===$n?' aria-current="step"':''?>><?php else:?><span><?php endif;?>
   <b><?=$hecho?'✓':$n?></b><strong><?=cengi_pp_h($label)?></strong><?php if($nota!==''):?><small><?=cengi_pp_h($nota)?></small><?php endif;?>
  <?=$hab?'</a>':'</span>'?></li>
 <?php endforeach;?>
 </ol>

<?php if($paso<=2):?>
<div class="rc-action"><h3><?=$paso===1?'Vista previa del correo de solicitud':'Enviar solicitud a Centro Financiero'?></h3>
 <dl class="rc-mail-meta">
  <dt>Para</dt><dd><?=cengi_pp_h($configCorreo['destinatarios']?:'Configura el correo de Centro Financiero')?></dd>
  <dt>Responder a</dt><dd><?=cengi_pp_h($configCorreo['responder_a']?:'Configura el correo de Administración')?></dd>
  <dt>Asunto</dt><dd><?=cengi_pp_h($solicitudAsunto)?></dd>
  <dt>Adjuntos</dt><dd><?=$fuente==='proforma'?'Proforma de pago (PDF)':($pago['comprobante']?'Comprobante de pago':'<span class="rc-muted">No hay comprobante disponible</span>')?></dd>
 </dl>
 <div class="rc-mail-preview"><?=$solicitudHtml?></div>
 <?php if($r['estado']!=='pendiente'&&!$puedeEnviarSolicitud):?>
  <p class="rc-ok"><span class="glyphicon glyphicon-ok-circle" aria-hidden="true"></span> Solicitud enviada el <?=cengi_pp_h(cengi_pp_fecha($r['solicitado_fecha']))?>. <?php if($r['estado']==='solicitado'):?>Cuando Administración reciba el recibo en Outlook, <a href="<?=cengi_pp_h($pasoUrl(3))?>">cárgalo en el paso 3</a>.<?php endif;?></p>
 <?php elseif($paso===1):?><a class="ing-btn" href="<?=cengi_pp_h($pasoUrl(2))?>">Continuar a enviar solicitud →</a>
 <?php else:?><form method="post" class="rc-enviar"><?=$csrf?><input type="hidden" name="accion" value="solicitar_correo"><button class="ing-btn" <?=$configCorreo['destinatarios']?'':'disabled'?>><span class="glyphicon glyphicon-send" aria-hidden="true"></span> Enviar correo a Centro Financiero</button><small><?=$r['estado']==='solicitado'?'Se reintentará solo a los destinatarios que no confirmaron el envío.':'Los destinatarios ya confirmados no reciben solicitudes duplicadas.'?></small></form><?php endif;?>
</div>
<?php elseif($paso===3 && $r['estado']==='solicitado'):?>
<div class="rc-action"><h3>Cargar el recibo recibido en Outlook</h3><p class="rc-muted">Guardar el documento todavía no envía nada al pagador; eso se hace en el paso 4.</p>
<form method="post" enctype="multipart/form-data" class="rc-form"><?=$csrf?><input type="hidden" name="accion" value="recibir">
<label>Número de recibo<input class="form-control" name="numero" maxlength="100" value="<?=cengi_pp_h($_POST['numero']??'')?>" required></label>
<label>Fecha de recepción<input class="form-control" type="date" name="fecha" min="<?=cengi_pp_h($r['solicitado_fecha']??'')?>" max="<?=cengi_pp_h(cengi_pp_hoy())?>" value="<?=cengi_pp_h($_POST['fecha']??cengi_pp_hoy())?>" required></label>
<label class="rc-wide">Recibo contable <small>PDF, JPG o PNG · máximo 5 MB</small><input class="form-control" type="file" name="archivo" accept="application/pdf,image/jpeg,image/png" required></label>
<button class="ing-btn"><span class="glyphicon glyphicon-cloud-upload" aria-hidden="true"></span> Guardar recibo y continuar</button></form></div>
<?php elseif($paso===3 && in_array($r['estado'],['recibido','enviado'],true)):?>
<div class="rc-action"><h3>Recibo cargado</h3>
 <dl class="rc-mail-meta"><dt>Número</dt><dd><?=cengi_pp_h($r['numero'])?></dd><dt>Recibido</dt><dd><?=cengi_pp_h(cengi_pp_fecha($r['recibido_fecha']))?></dd><dt>Archivo</dt><dd><a href="<?=cengi_pp_h($volverExpediente.'&archivo=1')?>" target="_blank" rel="noopener"><?=cengi_pp_h($r['archivo_nombre']?:'Ver recibo')?></a></dd></dl>
 <?php if($r['estado']==='recibido'):?><a class="ing-btn" href="<?=cengi_pp_h($pasoUrl(4))?>">Continuar a enviar al pagador →</a><?php endif;?></div>
<?php elseif($paso===4 && $r['estado']==='recibido'):?>
<div class="rc-action"><h3>Correo al pagador con el recibo adjunto</h3><p class="rc-muted">Revisa el destinatario y el mensaje antes de enviarlo desde Cengicursos.</p>
<form method="post" class="rc-form"><?=$csrf?><input type="hidden" name="accion" value="enviar_recibo">
<label class="rc-wide">Para · pagador o institución<input class="form-control" type="email" name="destinatario" maxlength="180" value="<?=cengi_pp_h($_POST['destinatario']??($r['destinatario']?:$pago['destinatario']))?>" required></label>
<dl class="rc-mail-meta rc-wide"><dt>Asunto</dt><dd><?=cengi_pp_h('Recibo contable '.$r['numero'].' · '.$pago['concepto'])?></dd><dt>Adjunto</dt><dd><span class="glyphicon glyphicon-paperclip" aria-hidden="true"></span> <?=cengi_pp_h($r['archivo_nombre'])?></dd></dl>
<label class="rc-wide">Mensaje<textarea class="form-control" name="mensaje" rows="8" maxlength="10000" required><?=cengi_pp_h($_POST['mensaje']??cengi_rc_recibo_texto($pago,$r))?></textarea></label><button class="ing-btn"><span class="glyphicon glyphicon-send" aria-hidden="true"></span> Enviar correo con recibo adjunto</button></form></div>
<?php elseif($r['estado']==='enviado'):?>
<div class="rc-complete"><span class="glyphicon glyphicon-ok-circle" aria-hidden="true"></span><div><strong>Trámite completo</strong><p>Recibo <?=cengi_pp_h($r['numero'])?> enviado a <?=cengi_pp_h($r['destinatario'])?> el <?=cengi_pp_h(cengi_pp_fecha($r['enviado_fecha']))?>.</p></div></div>
<?php else:?>
<div class="rc-action rc-bloqueado"><p>Este paso se habilita al completar el anterior. <a href="<?=cengi_pp_h($pasoUrl($pasoSiguiente($r['estado'])))?>">Ir al paso <?=$pasoSiguiente($r['estado'])?>: <?=cengi_pp_h($pasos[$pasoSiguiente($r['estado'])])?></a></p></div>
<?php endif;?>

<details class="rc-history"><summary>Historial del trámite <span class="rc-count"><?=count($historial)?></span></summary>
<?php if($historial):?><ol class="rc-timeline"><?php foreach($historial as $hist):?><li class="<?=strpos((string)$hist['accion'],'error')===0?'is-error':''?>"><time><?=cengi_pp_h($fechaHora($hist['creado']))?></time><span><?=cengi_pp_h(cengi_rc_referencia_visible($hist['detalle'],$r['id']))?></span><small><?=cengi_pp_h($hist['usuario'])?></small></li><?php endforeach;?></ol>
<?php else:?><p class="rc-muted">Aún no se ha solicitado el recibo.</p><?php endif;?></details>
</section>
<?php endif;?>

<section class="pp-card rc-lista">
<form method="get" class="rc-toolbar"><input type="hidden" name="vista" value="recibos">
 <label class="rc-search"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input class="form-control" type="search" name="q" value="<?=cengi_pp_h($busqueda)?>" placeholder="Buscar pagador, curso, proforma, referencia o recibo" aria-label="Buscar"></label>
 <select class="form-control" name="estado" aria-label="Estado"><option value="">Todos los estados</option><?php foreach($estados as $k=>[$texto]):?><option value="<?=$k?>" <?=$estado===$k?'selected':''?>><?=cengi_pp_h($texto)?></option><?php endforeach;?></select>
 <button class="ing-btn">Buscar</button><?php if($busqueda!==''||$estado!==''):?><a class="rc-limpiar" href="inscripciones.php?vista=recibos">Limpiar</a><?php endif;?>
</form>
<p class="rc-total"><b><?=$total?></b> pago(s)<?=$paginas>1?' · página '.$pagina.' de '.$paginas:''?></p>
<div class="table-responsive"><table class="table rc-table"><thead><tr><th>Pagador / concepto</th><th>Pago recibido</th><th>Seguimiento</th><th>Recibo</th><th><span class="sr-only">Acción</span></th></tr></thead><tbody>
<?php foreach($lista as $p):
 [, $corto, $tono]=$estados[$p['estado']];
 $baseFecha=$p['estado']==='solicitado'?$p['solicitado_fecha']:($p['estado']==='recibido'?$p['recibido_fecha']:$p['fecha']);
 $dias=$p['estado']!=='enviado'&&$baseFecha?max(0,(int)(new DateTimeImmutable($baseFecha))->diff(new DateTimeImmutable(cengi_pp_hoy()))->format('%r%a')):null;
 $sig=$pasoSiguiente($p['estado']);$actual=$pago&&$p['fuente']===$fuente&&(int)$p['fuente_id']===$id;
 $url='inscripciones.php?'.http_build_query(['vista'=>'recibos','fuente'=>$p['fuente'],'id'=>(int)$p['fuente_id'],'paso'=>$sig]).'#rc-expediente';
 $accion=['pendiente'=>'Solicitar recibo','solicitado'=>'Cargar recibo','recibido'=>'Enviar al pagador','enviado'=>'Ver expediente'][$p['estado']];
?>
<tr class="<?=$actual?'is-selected':''?>">
 <td><strong><?=cengi_pp_h($p['pagador'])?></strong><span class="rc-sub"><?=cengi_pp_h($p['concepto'])?></span><span class="rc-tag is-gris rc-tag-sm"><?=cengi_pp_h($fuentes[$p['fuente']]??$p['fuente'])?></span></td>
 <td class="rc-nowrap"><strong><?=cengi_ins_dinero($p['monto'],$p['moneda'])?></strong><span class="rc-sub"><?=cengi_pp_h($p['fecha']?cengi_pp_fecha($p['fecha']):'Sin fecha')?></span></td>
 <td><span class="rc-tag is-<?=$tono?>"><?=cengi_pp_h($corto)?></span>
  <span class="rc-progreso" aria-hidden="true"><?php for($n=1;$n<=4;$n++):?><i class="<?=$pasoHecho($n,$p['estado'])||$n===1?'is-on':''?>"></i><?php endfor;?></span>
  <?php if($dias!==null):?><span class="rc-sub<?=$dias>=5?' is-alerta':''?>"><?=$dias?> día<?=$dias===1?'':'s'?> en espera</span><?php endif;?>
  <?php if($p['responsable']):?><span class="rc-sub">Por <?=cengi_pp_h($p['responsable'])?></span><?php endif;?></td>
 <td><?=$p['numero']?'<strong>'.cengi_pp_h($p['numero']).'</strong>':'<span class="rc-muted">—</span>'?></td>
 <td class="rc-accion"><a class="ing-btn<?=$p['estado']==='enviado'?' is-linea':''?>" href="<?=cengi_pp_h($url)?>"><?=cengi_pp_h($accion)?></a></td>
</tr>
<?php endforeach;?>
<?php if(!$lista):?><tr><td colspan="5" class="rc-vacio">No hay pagos que coincidan con el filtro.</td></tr><?php endif;?>
</tbody></table></div>
<?php if($paginas>1):?><nav class="rc-paginacion" aria-label="Páginas">
 <?php if($pagina>1):?><a href="<?=cengi_pp_h($link(['pagina'=>$pagina-1]))?>">← Anterior</a><?php endif;?>
 <?php for($n=1;$n<=$paginas;$n++):?><a href="<?=cengi_pp_h($link(['pagina'=>$n]))?>" class="<?=$n===$pagina?'is-active':''?>"<?=$n===$pagina?' aria-current="page"':''?>><?=$n?></a><?php endfor;?>
 <?php if($pagina<$paginas):?><a href="<?=cengi_pp_h($link(['pagina'=>$pagina+1]))?>">Siguiente →</a><?php endif;?>
</nav><?php endif;?>
</section></main></body></html>
