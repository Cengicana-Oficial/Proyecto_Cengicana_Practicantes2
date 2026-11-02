<?php
require_once __DIR__.'/inscripcion_helpers.php';
require_once __DIR__.'/proformas_helpers.php';
/** Normaliza todas las entradas, incluso cargas públicas anteriores. No asigna sin validación. */
function cengi_ins_normalizar_ingreso(PDO $db, $id, $origen) {
    $s=cengi_ins_solicitud($db,$id,true);
    if(!$s) throw new InvalidArgumentException('Inscripción no encontrada.');
    $db->prepare('UPDATE solicitudes_inscripcion SET origen_inscripcion=? WHERE id_solicitud=?')->execute([$origen,$id]);
    $plan=$s['planificacion_id']?cengi_pp_plan($db,$s['planificacion_id']):null;
    if(!$plan && $s['curso_id']) {
        $q=$db->prepare("SELECT id FROM planificacion_cursos WHERE curso_id=? AND estado<>'cancelado' LIMIT 1");$q->execute([$s['curso_id']]);
        if($pid=$q->fetchColumn()) $plan=cengi_pp_plan($db,$pid);
    }
    if(!$plan) {
        $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado=COALESCE(pago_estado,'pendiente'), validacion_ingenio=IF(tipo_pago='Ingenio',COALESCE(validacion_ingenio,'pendiente'),validacion_ingenio) WHERE id_solicitud=?")->execute([$id]);
        return;
    }
    $cuota=$s['cuota']?:'asociado';[$monto,$moneda]=cengi_ins_monto($plan,$cuota);
    $tipo=$s['tipo_pago'];
    // Solo las entradas nuevas con tarifa explícitamente gratuita se exoneran automáticamente.
    if($s['estado']==='Pendiente' && $monto<=0 && !in_array($s['pago_estado'],['pagado','reembolsado','validado'],true) && ($s['monto']===null || (float)$s['monto']<=0)) $tipo='Exonerado';
    $grupo=$s['grupo_pago_id'];
    if($tipo==='Ingenio'&&!$grupo && $s['pago_estado']!=='pagado') $grupo=cengi_ins_grupo_abierto($db,$plan['id'],$s['ingenio_id']);
    $db->prepare("UPDATE solicitudes_inscripcion SET planificacion_id=?,cuota=?,monto=COALESCE(monto,?),moneda=?,tipo_pago=?,pago_estado=IF(?='Exonerado','exonerado',COALESCE(pago_estado,'pendiente')),validacion_ingenio=IF(?='Ingenio',COALESCE(validacion_ingenio,'pendiente'),validacion_ingenio),grupo_pago_id=? WHERE id_solicitud=?")
        ->execute([$plan['id'],$cuota,$tipo==='Exonerado'?0:$monto,$moneda,$tipo,$tipo,$tipo,$grupo,$id]);
    cengi_ins_recalcular_grupo($db,$grupo);
    if($tipo==='Ingenio' && $s['pago_estado']!=='pagado') cengi_pf_auto_amarrar($db,$id,$plan['id'],$s['ingenio_id']);
    if($tipo==='Exonerado') cengi_ins_procesar_plan($db,$plan['id']);
}

function cengi_ins_validacion_vista(array $s) {
    if($s['estado']==='Rechazado')return ['Rechazada','is-rejected'];
    if($s['tipo_pago']==='Exonerado')return ['Sin costo','is-neutral'];
    if(cengi_ins_requisito($s)['ok'])return ['Validada','is-active'];
    return [$s['tipo_pago']==='Ingenio'?'Por validar · ingenio':($s['pago_estado']==='en revisión'?'Comprobante por revisar':'Pago propio por revisar'),'is-pending'];
}
function cengi_ins_pendiente_proforma(array $s) {
    return $s['estado']!=='Rechazado' && $s['tipo_pago']==='Ingenio' && empty($s['proforma_id']) && cengi_ins_estado_cobro($s)!=='pagado';
}
const CENGI_INS_ORIGENES=['formulario'=>'Formulario individual','carga_formulario'=>'Carga masiva · formulario','carga_interna'=>'Carga masiva · coordinación','registro_interno'=>'Registro interno','sin_identificar'=>'Registro anterior'];
function cengi_ins_en_bandeja(array $s,$bandeja) {
    if($bandeja==='')return true;
    if($s['estado']==='Rechazado')return false;
    if($bandeja==='por_validar')return !cengi_ins_admitida($s);
    if($bandeja==='por_asignar')return cengi_ins_admitida($s) && in_array($s['estado'],['Pendiente','Espera'],true);
    if($bandeja==='sin_proforma')return cengi_ins_pendiente_proforma($s);
    if($bandeja==='pendientes')return !in_array(cengi_ins_estado_cobro($s),['pagado','exonerado','reembolsado'],true);
    if($bandeja==='pagados')return cengi_ins_estado_cobro($s)==='pagado';
    return false;
}
