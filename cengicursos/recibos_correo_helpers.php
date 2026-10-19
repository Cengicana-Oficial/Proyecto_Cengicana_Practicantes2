<?php
require_once __DIR__.'/recibos_contables_helpers.php';
function cengi_rc_correos($texto) {
 $emails=preg_split('/[;,\s]+/',trim((string)$texto),-1,PREG_SPLIT_NO_EMPTY);$out=[];
 foreach($emails as $email){$email=strtolower($email);if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>180)throw new InvalidArgumentException('Indica correos válidos separados por coma o punto y coma.');$out[$email]=$email;}
 if(!$out||count($out)>10)throw new InvalidArgumentException('Indica entre uno y diez destinatarios.');return array_values($out);
}
function cengi_rc_mail_config(PDO $db) {return $db->query('SELECT * FROM recibos_contables_correo_config WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:['destinatarios'=>'','responder_a'=>'','automatico'=>0,'desde'=>cengi_pp_hoy()];}
function cengi_rc_mail_config_guardar(PDO $db,array $d,$usuario){
 $emails=cengi_rc_correos($d['destinatarios']??'');$reply=trim((string)($d['responder_a']??''));$desde=(string)($d['desde']??'');
 if(!filter_var($reply,FILTER_VALIDATE_EMAIL)||strlen($reply)>180||!cengi_pp_es_fecha($desde))throw new InvalidArgumentException('Indica el buzón de respuesta y una fecha inicial válidos.');
 $db->prepare('INSERT INTO recibos_contables_correo_config(id,destinatarios,responder_a,automatico,desde,actualizado_por) VALUES(1,?,?,?,?,?) ON DUPLICATE KEY UPDATE destinatarios=VALUES(destinatarios),responder_a=VALUES(responder_a),automatico=VALUES(automatico),desde=VALUES(desde),actualizado_por=VALUES(actualizado_por),actualizado=NOW()')->execute([implode('; ',$emails),$reply,empty($d['automatico'])?0:1,$desde,$usuario]);
}
function cengi_rc_solicitud_email(array $pago,$referencia,$nota='') {
 $h=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
 $asunto='['.$referencia.'] Solicitud de recibo contable - '.$pago['concepto'];
 $body='<p>Centro Financiero:</p><p>Solicitamos emitir el recibo contable del pago recibido:</p><ul><li>Pagador: '.$h($pago['pagador']).'</li><li>Concepto: '.$h($pago['concepto']).'</li><li>Monto: '.$h(cengi_ins_dinero($pago['monto'],$pago['moneda'])).'</li><li>Fecha de pago: '.$h($pago['fecha']?cengi_pp_fecha($pago['fecha']):'No registrada').'</li><li>Referencia: '.$h($pago['referencia']).'</li></ul><p>'.$h($nota).'</p><p>Por favor responda conservando la referencia <b>'.$h($referencia).'</b> en el asunto y adjunte el recibo contable.</p>';
 if(!empty($pago['cursos'])){$body.='<p>Cursos incluidos en el pago:</p><ul>';foreach($pago['cursos'] as $curso)$body.='<li>'.$h($curso).'</li>';$body.='</ul>';}
 return [$asunto,$body];
}
/** SMTP fuera de transacción; bloqueo por expediente y registro por destinatario para reintentos. */
function cengi_rc_solicitar_email(PDO $db,array $pago,$usuario,$nota='',?callable $transport=null) {
 if($db->inTransaction())throw new LogicException('El correo se envía fuera de la transacción del pago.');
 $config=cengi_rc_mail_config($db);$emails=cengi_rc_correos($config['destinatarios']);
 if(!filter_var($config['responder_a'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Configura el buzón de respuestas.');
 $lock='rc-mail-'.$pago['fuente'].'-'.$pago['fuente_id'];$q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new InvalidArgumentException('Otra solicitud de envío está en curso.');
 try {
  $db->prepare('INSERT IGNORE INTO recibos_contables(fuente,fuente_id) VALUES(?,?)')->execute([$pago['fuente'],$pago['fuente_id']]);
  $q=$db->prepare('SELECT * FROM recibos_contables WHERE fuente=? AND fuente_id=?');$q->execute([$pago['fuente'],$pago['fuente_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
  if(!in_array($r['estado'],['pendiente','solicitado'],true))throw new InvalidArgumentException('El recibo ya fue recibido o enviado.');
  $db->prepare('INSERT IGNORE INTO recibos_contables_solicitudes_correo(recibo_id,token) VALUES(?,?)')->execute([$r['id'],bin2hex(random_bytes(16))]);
  $q=$db->prepare('SELECT token FROM recibos_contables_solicitudes_correo WHERE recibo_id=?');$q->execute([$r['id']]);$token=$q->fetchColumn();$referencia=cengi_rc_referencia($r['id']);
  if($pago['fuente']==='proforma'){require_once __DIR__.'/proformas_helpers.php';$pf=cengi_pf_cargar($db,$pago['fuente_id']);$pago['cursos']=array_map(static function($l) use ($pf){return $l['plan_nombre'].' · '.$l['cantidad'].' cupo(s) · '.cengi_ins_dinero($l['total'],$pf['moneda']);},$pf['lineas']);}
  [$asunto,$body]=cengi_rc_solicitud_email($pago,$referencia,$nota);$adjuntos=[];
  if($pago['fuente']==='proforma'){require_once __DIR__.'/proformas_helpers.php';$pf=cengi_pf_cargar($db,$pago['fuente_id']);$adjuntos[]=['contenido'=>cengi_pf_pdf($pf,cengi_pf_cuentas($db)),'nombre'=>'proforma.pdf','tipo'=>'application/pdf'];}
  if(!empty($pago['comprobante'])&&($file=cengi_rc_archivo($pago['comprobante']))){if(filesize($file)>5*1024*1024)throw new InvalidArgumentException('El comprobante supera los 5 MB.');$adjuntos[]=['contenido'=>file_get_contents($file),'nombre'=>'comprobante.'.pathinfo($file,PATHINFO_EXTENSION),'tipo'=>(new finfo(FILEINFO_MIME_TYPE))->file($file)];}
  if(!$transport){require_once __DIR__.'/correo.php';$transport=static function($to,$sub,$html,$files,$reply){return cengi_enviar_correo($to,'Centro Financiero',$sub,$html,$files,'',$reply);};}
  $errores=[];
  foreach($emails as $email){
   $q=$db->prepare('SELECT enviado FROM recibos_contables_envios WHERE recibo_id=? AND destinatario=?');$q->execute([$r['id'],$email]);if($q->fetchColumn())continue;
   try{if(!$transport($email,$asunto,$body,$adjuntos,$config['responder_a']))throw new RuntimeException('El servidor SMTP no confirmó el envío.');
    $db->prepare("INSERT INTO recibos_contables_envios(recibo_id,destinatario,enviado,error) VALUES(?,?,NOW(),'') ON DUPLICATE KEY UPDATE enviado=NOW(),error=''")->execute([$r['id'],$email]);
    $db->prepare("UPDATE recibos_contables SET estado='solicitado',solicitado_fecha=COALESCE(solicitado_fecha,?),solicitud_referencia=?,responsable=?,actualizado=NOW() WHERE id=?")->execute([cengi_pp_hoy(),$referencia,$usuario,$r['id']]);
    $db->prepare("INSERT INTO recibos_contables_historial(recibo_id,accion,usuario,detalle) VALUES(?,'solicitar_correo',?,?)")->execute([$r['id'],$usuario,'Solicitud enviada a '.$email.' · '.$referencia]);
   }catch(Throwable $e){error_log('Solicitud recibo: '.$e->getMessage());$db->prepare('INSERT INTO recibos_contables_envios(recibo_id,destinatario,error) VALUES(?,?,?) ON DUPLICATE KEY UPDATE error=VALUES(error)')->execute([$r['id'],$email,'No se pudo confirmar el envío SMTP. Revisa Comunicaciones.']);$errores[]=$email;}
  }
  if($errores)throw new InvalidArgumentException('Envío pendiente a: '.implode(', ',$errores).'. Reintenta; los destinatarios ya confirmados no se repetirán.');
  return $r['id'];
 }finally{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}

/** Adjunta el recibo real; solo marca enviado cuando SMTP lo confirma. */
function cengi_rc_enviar_recibo(PDO $db,array $pago,$destinatario,$usuario,?callable $transport=null,$mensaje='') {
 if($db->inTransaction())throw new LogicException('Guardar el recibo antes de enviarlo.');
 $destinatario=trim((string)$destinatario);
 if(!filter_var($destinatario,FILTER_VALIDATE_EMAIL)||strlen($destinatario)>180)throw new InvalidArgumentException('Indica un correo válido del pagador o institución.');
 $lock='rc-final-'.$pago['fuente'].'-'.$pago['fuente_id'];$q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new InvalidArgumentException('El envío del recibo está en curso.');
 try {
  $q=$db->prepare('SELECT * FROM recibos_contables WHERE fuente=? AND fuente_id=?');$q->execute([$pago['fuente'],$pago['fuente_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
  if($r&&$r['estado']==='enviado')return false;
  if(!$r||$r['estado']!=='recibido'||!($f=cengi_rc_archivo($r['archivo_ruta'])))throw new InvalidArgumentException('Primero adjunta el recibo contable.');
  $db->prepare('UPDATE recibos_contables SET destinatario=? WHERE id=?')->execute([$destinatario,$r['id']]);
  $texto=$mensaje!==''?$mensaje:cengi_rc_recibo_texto($pago,$r);
  if(mb_strlen($texto)>10000)throw new InvalidArgumentException('El cuerpo del correo supera los 10000 caracteres.');
  $body=nl2br(htmlspecialchars($texto,ENT_QUOTES,'UTF-8'));
  $adjuntos=[['contenido'=>file_get_contents($f),'nombre'=>$r['archivo_nombre']?:'recibo.pdf','tipo'=>(new finfo(FILEINFO_MIME_TYPE))->file($f)]];
  if(!$transport){require_once __DIR__.'/correo.php';$transport=static function($to,$name,$subject,$html,$files){return cengi_enviar_correo($to,$name,$subject,$html,$files);};}
  try{
   if(!$transport($destinatario,$pago['pagador'],'Recibo contable '.$r['numero'].' · '.$pago['concepto'],$body,$adjuntos))throw new RuntimeException('SMTP no confirmó la entrega.');
  }catch(Throwable $e){
   error_log('Envío recibo al pagador: '.$e->getMessage());
   $db->prepare("INSERT INTO recibos_contables_historial(recibo_id,accion,usuario,detalle) VALUES(?,'error_envio_recibo',?,?)")->execute([$r['id'],$usuario,'No se confirmó el envío a '.$destinatario.'. El recibo permanece guardado para reintentar.']);
   throw new InvalidArgumentException('El recibo quedó guardado, pero no se confirmó el envío al pagador. Reintenta desde esta pantalla.');
  }
  $db->beginTransaction();
  try{
   $db->prepare("UPDATE recibos_contables SET estado='enviado',destinatario=?,enviado_fecha=?,actualizado=NOW() WHERE id=?")->execute([$destinatario,cengi_pp_hoy(),$r['id']]);
   $db->prepare("INSERT INTO recibos_contables_historial(recibo_id,accion,usuario,detalle) VALUES(?,'enviar_recibo',?,?)")->execute([$r['id'],$usuario,'Recibo '.$r['numero'].' enviado desde Cengicursos a '.$destinatario]);
   $db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return true;
 }finally{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}
function cengi_rc_recibo_texto(array $pago,array $r) {
 return "Estimado/a ".$pago['pagador'].":\n\nAdjuntamos el recibo contable ".$r['numero']." correspondiente al pago de ".$pago['concepto'].", por ".cengi_ins_dinero($pago['monto'],$pago['moneda']).".\n\nGracias por su participación.\nCENGICAÑA · Capacitación";
}
/** Folio de lectura humana; el token aleatorio permanece como dato interno. */
function cengi_rc_referencia($reciboId) {
 return (int)$reciboId>0 ? 'RC-'.str_pad((string)(int)$reciboId,6,'0',STR_PAD_LEFT) : 'Se asignará al enviar';
}
function cengi_rc_referencia_visible($texto,$reciboId) {
 return preg_replace('/\bRC-[a-f0-9]{32}\b/i',cengi_rc_referencia($reciboId),(string)$texto);
}