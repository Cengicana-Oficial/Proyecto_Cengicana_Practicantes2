<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/recibos_correo_helpers.php';
$loop=in_array('--loop',$argv,true);
// Pagos que fallaron en este proceso: se reintentan con espera creciente (2, 4, 8... min, máx. 6 h)
// para no repetir cada minuto un error permanente (adjunto > 5 MB, destinatario rechazado).
$fallos=[];
do{
 try{
  $db=conectar();$db->query('SELECT 1');$cfg=cengi_rc_mail_config($db);
  if($cfg['automatico'] && $cfg['destinatarios']) {
   $emails=cengi_rc_correos($cfg['destinatarios']);$marks=implode(',',array_fill(0,count($emails),'?'));
   $q=$db->prepare("SELECT p.* FROM (".cengi_rc_pagos_sql().") p LEFT JOIN recibos_contables r ON r.fuente=p.fuente AND r.fuente_id=p.fuente_id LEFT JOIN recibos_contables_solicitudes_correo sc ON sc.recibo_id=r.id
    WHERE p.fecha>=? AND (COALESCE(r.estado,'pendiente')='pendiente' OR (r.estado='solicitado' AND sc.recibo_id IS NOT NULL))
    AND (r.id IS NULL OR (SELECT COUNT(*) FROM recibos_contables_envios e WHERE e.recibo_id=r.id AND e.enviado IS NOT NULL AND e.destinatario IN ($marks)) < ".count($emails).")
    ORDER BY p.fecha,p.fuente,p.fuente_id LIMIT 100");$q->execute(array_merge([$cfg['desde']],$emails));
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p){
    $clave=$p['fuente'].'-'.$p['fuente_id'];
    if(isset($fallos[$clave]) && $fallos[$clave]['siguiente']>time())continue;
    try{cengi_rc_solicitar_email($db,$p,'Envío automático');unset($fallos[$clave]);}
    catch(PDOException $e){throw $e;}
    catch(Throwable $e){
     $n=($fallos[$clave]['n']??0)+1;$fallos[$clave]=['n'=>$n,'siguiente'=>time()+min(60*(2**$n),6*3600)];
     error_log('Solicitud automática de recibo ('.$clave.', intento '.$n.'): '.$e->getMessage());
    }
   }
  }
 }catch(Throwable $e){
  error_log('Procesador de recibos: '.$e->getMessage());
  // conectar() reutiliza la misma conexión: si MySQL se reinició no se recupera. Al salir con error,
  // Docker (restart: unless-stopped) vuelve a levantar el proceso con una conexión nueva.
  if($loop)exit(1);
 }
 if($loop)sleep(60);
}while($loop);
