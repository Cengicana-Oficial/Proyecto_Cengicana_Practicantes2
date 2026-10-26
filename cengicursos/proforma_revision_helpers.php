<?php
require_once __DIR__.'/proformas_helpers.php';
/** Actualización transaccional: conserva documentos anteriores y cuotas de sus grupos. */
function cengi_pf_actualizar(PDO $db,array $pf,array $datos) {
    if(!in_array($pf['estado'],['emitida','enviada'],true))throw new InvalidArgumentException('Solo se actualizan proformas pendientes de pago.');
    $motivo=mb_substr(trim((string)($datos['motivo']??'')),0,500);
    if($motivo==='')throw new InvalidArgumentException('Escribe el motivo de la actualización.');
    $vence=(string)($datos['vence']??'');
    if(!cengi_pp_es_fecha($vence)||$vence<$pf['fecha'])throw new InvalidArgumentException('Revisa el vencimiento.');
    $dirigido=mb_substr(trim((string)($datos['dirigido']??'')),0,255);
    $correo=trim((string)($datos['correo']??''));
    if($dirigido===''||!filter_var($correo,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Indica destinatario y correo válidos.');
    $q=$db->prepare('SELECT MAX(revision) FROM proforma_versiones WHERE proforma_id=?');$q->execute([$pf['id']]);$revision=(int)$q->fetchColumn();
    $snapshot=function($numero,$documento,$nota)use($db,$pf){$db->prepare('INSERT INTO proforma_versiones(proforma_id,revision,motivo,por,documento) VALUES(?,?,?,?,?)')->execute([$pf['id'],$numero,$nota,cengi_pp_usuario_nombre(),json_encode(['proforma'=>$documento,'cuentas'=>cengi_pf_cuentas($db)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);};
    if(!$revision){$revision=1;$snapshot(1,$pf,'Documento antes de la primera actualización');}
    $total=(float)$pf['cargo_monto'];
    foreach($pf['lineas'] as $l) {
        $edit=$datos['lineas'][$l['id']]??null;
        if(!$edit)throw new InvalidArgumentException('Revisa todas las líneas de la proforma.');
        $cantidad=filter_var($edit['cantidad']??'',FILTER_VALIDATE_INT);
        $precio=filter_var($edit['precio']??'',FILTER_VALIDATE_FLOAT);$descuento=filter_var($edit['descuento']??'',FILTER_VALIDATE_FLOAT);
        if($cantidad===false||$cantidad<max(1,count($l['inscritos']))||$cantidad>500||$precio===false||$precio<0||$precio>99999999||$descuento===false||$descuento<0||$descuento>$precio)throw new InvalidArgumentException('La cantidad debe cubrir a los participantes vinculados; revisa precio y descuento.');
        $precio=round($precio,2);$descuento=round($descuento,2);$monto=round($cantidad*($precio-$descuento),2);$total+=$monto;
        $db->prepare("UPDATE proforma_lineas SET cantidad=?,precio=?,desc_tipo='monto',desc_valor=?,desc_unit=?,total=? WHERE id=? AND proforma_id=?")->execute([$cantidad,$precio,$descuento,$descuento,$monto,$l['id'],$pf['id']]);
        $db->prepare('UPDATE pagos_grupales SET cupos=?,monto=? WHERE id=?')->execute([$cantidad,$monto,$l['grupo_pago_id']]);
        $db->prepare('UPDATE solicitudes_inscripcion SET monto=?,descuento=? WHERE grupo_pago_id=? AND estado<>\'Rechazado\'')->execute([$precio-$descuento,$descuento?:null,$l['grupo_pago_id']]);
    }
    $db->prepare("UPDATE proformas SET dirigido=?,correo=?,vence=?,obs=?,total=?,estado='emitida' WHERE id=?")->execute([$dirigido,$correo,$vence,mb_substr(trim((string)($datos['obs']??'')),0,500),round($total,2),$pf['id']]);
    cengi_pf_log($db,$pf['id'],'Actualizada · revisión '.($revision+1),$motivo);
    $snapshot($revision+1,cengi_pf_cargar($db,$pf['id']),$motivo);
    return $revision+1;
}
