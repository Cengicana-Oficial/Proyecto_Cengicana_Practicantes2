<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/recibos_correo_helpers.php';
$loop=in_array('--loop',$argv,true);
do{
 try{
  $db=conectar();$cfg=cengi_rc_mail_config($db);
  if($cfg['automatico'] && $cfg['destinatarios']) {
   $emails=cengi_rc_correos($cfg['destinatarios']);$marks=implode(',',array_fill(0,count($emails),'?'));
   $q=$db->prepare("SELECT p.* FROM (".cengi_rc_pagos_sql().") p LEFT JOIN recibos_contables r ON r.fuente=p.fuente AND r.fuente_id=p.fuente_id LEFT JOIN recibos_contables_solicitudes_correo sc ON sc.recibo_id=r.id
    WHERE p.fecha>=? AND (COALESCE(r.estado,'pendiente')='pendiente' OR (r.estado='solicitado' AND sc.recibo_id IS NOT NULL))
    AND (r.id IS NULL OR (SELECT COUNT(*) FROM recibos_contables_envios e WHERE e.recibo_id=r.id AND e.enviado IS NOT NULL AND e.destinatario IN ($marks)) < ".count($emails).")
    ORDER BY p.fecha,p.fuente,p.fuente_id LIMIT 100");$q->execute(array_merge([$cfg['desde']],$emails));
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p){try{cengi_rc_solicitar_email($db,$p,'Envío automático');}catch(Throwable $e){error_log('Solicitud automática de recibo: '.$e->getMessage());}}
  }
 }catch(Throwable $e){error_log('Procesador de recibos: '.$e->getMessage());}
 if($loop)sleep(60);
}while($loop);