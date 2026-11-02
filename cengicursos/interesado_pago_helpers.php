<?php
require_once __DIR__.'/cobranza_helpers.php';
function cengi_interesado_aceptar_pago(PDO $db,array $s,array $datos,$por,$archivo=null,$via='administracion') {
    if($s['tipo_pago']!=='Propio'||$s['estado']==='Rechazado'||$s['pago_estado']==='pagado')throw new InvalidArgumentException('Este interesado no tiene pago propio pendiente.');
    $ref=mb_substr(trim((string)($datos['boleta']??'')),0,60);$fecha=(string)($datos['fecha']??'');$monto=filter_var($datos['monto']??'',FILTER_VALIDATE_FLOAT);
    if($ref===''||!cengi_pp_es_fecha($fecha)||$fecha>cengi_pp_hoy()||$monto===false||$monto<=0)throw new InvalidArgumentException('Indica referencia, fecha y monto del pago.');
    $cuota=$s['monto'];if($cuota===null){$cuota=filter_var($datos['cuota']??'',FILTER_VALIDATE_FLOAT);if($cuota===false||$cuota<=0)throw new InvalidArgumentException('Define la cuota del curso.');}
    if(abs((float)$cuota-$monto)>.009)throw new InvalidArgumentException('El pago no coincide con la cuota. Revisa el importe antes de aceptar.');
    if(cengi_cob_boletas_repetidas($db,$ref,$s['id_solicitud']))throw new InvalidArgumentException('Esta referencia ya aparece en otra inscripción. Revisa el pago.');
    [$ruta,$nombre]=[$s['comprobante_ruta'],$s['comprobante_nombre']];
    if($archivo&&($archivo['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)[$ruta,$nombre]=cengi_ins_guardar_comprobante($archivo,'s'.$s['id_solicitud']);
    if(!$ruta)throw new InvalidArgumentException('Adjunta la constancia de depósito o boleta; es obligatoria para ambos validadores.');
    $nota=mb_substr(trim((string)($datos['nota']??'')),0,500);
    if($nota===''&&$via==='capacitacion')$nota='Boleta cargada por Capacitación.';
    if($nota==='')throw new InvalidArgumentException('Escribe la evidencia u observación de la revisión.');
    if(!in_array($via,['administracion','capacitacion'],true))throw new InvalidArgumentException('Validador no válido.');
    if($via==='capacitacion') {
        $db->prepare("UPDATE solicitudes_inscripcion SET monto=?,boleta=?,pago_fecha=?,monto_comprobante=?,comprobante_ruta=?,comprobante_nombre=?,pago_estado='en revisión',pago_validado_por=?,pago_validado_rol='capacitacion',pago_validado_en=NOW(),pago_validacion_evidencia=? WHERE id_solicitud=?")->execute([$cuota,$ref,$fecha,$monto,$ruta,$nombre,$por,$nota,$s['id_solicitud']]);
    } else {
    $db->prepare("UPDATE solicitudes_inscripcion SET monto=?,boleta=?,pago_fecha=?,monto_comprobante=?,comprobante_ruta=?,comprobante_nombre=?,pago_estado='pagado',pago_forma='Pago aceptado',pago_referencia=?,pago_verificado_por=?,pago_verificado_en=NOW(),pago_registrado_por=?,pago_registrado_en=NOW(),pago_nota=? WHERE id_solicitud=?")->execute([$cuota,$ref,$fecha,$monto,$ruta,$nombre,$ref,$por,$por,$nota,$s['id_solicitud']]);
        if(($s['pago_validado_rol']??'')!=='capacitacion')$db->prepare("UPDATE solicitudes_inscripcion SET pago_validado_por=?,pago_validado_rol='administracion',pago_validado_en=NOW(),pago_validacion_evidencia=? WHERE id_solicitud=?")->execute([$por,$nota,$s['id_solicitud']]);
    }
    $db->prepare('INSERT INTO inscripcion_historial(solicitud_id,accion,usuario,motivo,antes,despues) VALUES(?,?,?,?,?,?)')->execute([$s['id_solicitud'],'aceptar_pago_propio',$por,$nota,json_encode($s,JSON_UNESCAPED_UNICODE),json_encode(cengi_ins_solicitud($db,$s['id_solicitud']),JSON_UNESCAPED_UNICODE)]);
}
