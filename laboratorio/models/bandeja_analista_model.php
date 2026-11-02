<?php
require_once __DIR__.'/kanban_model.php';
/** Pendientes reales por muestra: un envío parcial no oculta el resto del lote. */
function lab_bandeja_analista_pendientes(PDO $pdo):array {
    $groups=[];
    foreach(lab_planificacion_tareas($pdo) as $t){
        if(!in_array($t['estado']['key'],['pendiente','proceso','rechazado','repetido'],true))continue;
        $id=$t['id_solicitud'];
        if(!isset($groups[$id]))$groups[$id]=['id_solicitud'=>$id,'id_lote'=>$t['id_lote'],'codigo_lote'=>$t['codigo_lote'],'tipo_muestra'=>$t['tipo_muestra'],'fecha_ingreso'=>$t['fecha_ingreso'],'fecha_estimada'=>$t['fecha_estimada'],'samples'=>[],'analyses'=>[]];
        $groups[$id]['samples'][$t['id_muestra']]=true;$groups[$id]['analyses'][$t['id_tipo_analisis']]=$t['analisis_nombre'];
    }
    foreach($groups as &$g){$g['numero_muestras']=count($g['samples']);$g['total_pendientes']=count($g['analyses']);$g['analisis_pendientes']=implode('||',$g['analyses']);unset($g['samples'],$g['analyses']);}unset($g);
    return array_values($groups);
}