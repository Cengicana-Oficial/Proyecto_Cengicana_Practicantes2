<?php
require_once __DIR__ . '/inscripcion_helpers.php';
/** Un expediente por pago: las órdenes de proforma no generan duplicados. */
function cengi_rc_pagos_sql() {
 return "SELECT 'persona' fuente,s.id_solicitud fuente_id,s.nombre_participante pagador,s.correo destinatario,s.monto,s.moneda,
 COALESCE(s.pago_fecha,DATE(s.pago_registrado_en)) fecha,s.pago_referencia referencia,
 COALESCE(pc.nombre,c.nombre_cursos,'Inscripción') concepto,s.comprobante_ruta comprobante
 FROM solicitudes_inscripcion s LEFT JOIN planificacion_cursos pc ON pc.id=s.planificacion_id LEFT JOIN cursos c ON c.id=s.curso_id
 WHERE s.pago_estado='pagado' AND s.grupo_pago_id IS NULL AND s.tipo_pago<>'Exonerado' AND s.monto>0
 UNION ALL SELECT 'grupo',g.id,i.nombre_ingenios,'',g.monto,g.moneda,COALESCE(g.pago_fecha,DATE(g.verificado_en)),g.pago_referencia,pc.nombre,g.comprobante_ruta
 FROM pagos_grupales g JOIN ingenios i ON i.id=g.ingenio_id JOIN planificacion_cursos pc ON pc.id=g.planificacion_id
 WHERE g.estado='pagado' AND g.proforma_id IS NULL AND g.monto>0
 UNION ALL SELECT 'proforma',p.id,COALESCE(NULLIF(p.dirigido,''),i.nombre_ingenios),p.correo,p.total,p.moneda,p.pago_fecha,p.pago_referencia,CONCAT('Proforma N.° ',p.numero),''
 FROM proformas p JOIN ingenios i ON i.id=p.ingenio_id WHERE p.estado='pagada' AND p.total>0";
}
function cengi_rc_pago(PDO $db,$fuente,$id) {
 if (!in_array($fuente,['persona','grupo','proforma'],true) || (int)$id<=0) return null;
 $q=$db->prepare('SELECT * FROM ('.cengi_rc_pagos_sql().') pagos WHERE fuente=? AND fuente_id=?');$q->execute([$fuente,(int)$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function cengi_rc_guardar(PDO $db, array $pago, $accion, array $datos, $usuario, array $archivo=[]) {
 if (!$db->inTransaction()) throw new LogicException('Se requiere transacción.');
 $db->prepare('INSERT IGNORE INTO recibos_contables(fuente,fuente_id) VALUES(?,?)')->execute([$pago['fuente'],$pago['fuente_id']]);
 $q=$db->prepare('SELECT * FROM recibos_contables WHERE fuente=? AND fuente_id=? FOR UPDATE');$q->execute([$pago['fuente'],$pago['fuente_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
 $fecha=(string)($datos['fecha']??'');$nota=mb_substr(trim((string)($datos['nota']??'')),0,1000);
 if(!cengi_pp_es_fecha($fecha)||$fecha>cengi_pp_hoy())throw new InvalidArgumentException('Indica una fecha válida que no sea futura.');
 if($accion==='solicitar') {
  if($r['estado']!=='pendiente')throw new InvalidArgumentException('La solicitud ya está registrada.');
  $ref=mb_substr(trim((string)($datos['referencia']??'')),0,180);if($ref==='')throw new InvalidArgumentException('Indica el asunto o referencia del correo enviado a Centro Financiero.');
  $db->prepare("UPDATE recibos_contables SET estado='solicitado',solicitado_fecha=?,solicitud_referencia=?,responsable=?,actualizado=NOW() WHERE id=?")->execute([$fecha,$ref,$usuario,$r['id']]);
  $nota='Solicitud manual: '.$ref.'. '.$nota;
 } elseif($accion==='recibir') {
  if($r['estado']!=='solicitado')throw new InvalidArgumentException('Registra primero la solicitud a Centro Financiero.');
  if($fecha<$r['solicitado_fecha'])throw new InvalidArgumentException('La recepción no puede ser anterior a la solicitud.');
  $num=mb_substr(trim((string)($datos['numero']??'')),0,100);if($num===''||empty($archivo['ruta']))throw new InvalidArgumentException('Indica número de recibo y adjunta el documento.');
  $db->prepare("UPDATE recibos_contables SET estado='recibido',recibido_fecha=?,numero=?,archivo_ruta=?,archivo_nombre=?,actualizado=NOW() WHERE id=?")->execute([$fecha,$num,$archivo['ruta'],$archivo['nombre'],$r['id']]);
  $nota='Recibo '.$num.'. '.$nota;
 } elseif($accion==='enviar') {
  if($r['estado']!=='recibido'||!$r['archivo_ruta'])throw new InvalidArgumentException('Adjunta el recibo antes de registrar su envío.');
  if($fecha<$r['recibido_fecha'])throw new InvalidArgumentException('El envío no puede ser anterior a la recepción.');
  $correo=trim((string)($datos['destinatario']??''));if(!filter_var($correo,FILTER_VALIDATE_EMAIL)||strlen($correo)>180||empty($datos['confirmar']))throw new InvalidArgumentException('Indica el destinatario y confirma que ya enviaste el correo.');
  $db->prepare("UPDATE recibos_contables SET estado='enviado',destinatario=?,enviado_fecha=?,actualizado=NOW() WHERE id=?")->execute([$correo,$fecha,$r['id']]);
  $nota='Envío externo registrado a '.$correo.'. '.$nota;
 } else throw new InvalidArgumentException('Acción no válida.');
 $db->prepare('INSERT INTO recibos_contables_historial(recibo_id,accion,usuario,detalle) VALUES(?,?,?,?)')->execute([$r['id'],$accion,$usuario,$nota]);
 return (int)$r['id'];
}
function cengi_rc_archivo($ruta) {
 $base=realpath(__DIR__.'/../uploads');$url=cengi_normalizar_url_archivo($ruta);$file=$url?realpath(__DIR__.'/..'.$url):false;
 return $base&&$file&&strpos($file,$base.DIRECTORY_SEPARATOR)===0&&is_file($file)?$file:null;
}