<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/auth.php';
$_SESSION=['es_superadmin'=>true,'usuario_nombre'=>'Prueba workflow'];
require_once __DIR__.'/../conexion.php';require_once __DIR__.'/../includes/workflow_trabajo_helper.php';require_once __DIR__.'/../models/bandeja_analista_model.php';
function checkW($v,$m){if(!$v)throw new RuntimeException($m);}
function rejectW(callable $fn,$message){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException($message);}
wfSchema($conexion);$before=wfTareas($conexion);$user=['id'=>-200000,'nombre'=>'Prueba workflow'];$users=wfUsuarios();checkW(count($users)>0,'No hay usuarios de laboratorio');
$eligible=array_values(array_filter($before,fn($t)=>wfPendiente($t)&&$t['hoja']==='Suelo|pH'));
checkW(count($eligible)>=2,'Se requieren dos tareas de pH/CE pendientes para la prueba');$first=$eligible[0];$key=$first['hoja'];$h=wfConfig()['hojas'][$key];
$conexion->beginTransaction();try{
 $plan=['id_lote'=>$first['id_lote'],'version'=>$first['plan_version'],'prioridad'=>'alta','usuario_id'=>$users[0]['id'],'id_tipo_analisis'=>$first['id_tipo_analisis']];
 wfPlanGuardar($conexion,$plan,$user);
 rejectW(fn()=>wfPlanGuardar($conexion,$plan,$user),'Se permitió una planificación obsoleta');
 $current=wfTareas($conexion);$same=array_values(array_filter($current,fn($t)=>$t['id_muestra']===$first['id_muestra']&&$t['id_tipo_analisis']===$first['id_tipo_analisis']))[0];
 checkW($same['prioridad']['key']==='alta'&&(int)$same['usuario_id']===(int)$users[0]['id'],'No persiste asignación/prioridad');
 $id=(int)$first['id_muestra'];$cap=['seleccion'=>[$id],'encabezado'=>[],'controles'=>[],'ctrlExtra'=>[],'filas'=>[$id=>[]],'curva'=>[],'fecha'=>date('Y-m-d'),'analista'=>'Prueba','equipo'=>'Equipo de prueba','obs'=>'Prueba reversible'];
 foreach($h['encabezado']['campos']??[] as $c)$cap['encabezado'][$c['k']]=$c['def']??'25';
 foreach($h['cols'] as $c)if(empty($c['auto']))$cap['filas'][$id][$c['k']]='7';
 foreach($h['controles'] as $ctrl)foreach($h['cols'] as $c)if(empty($c['auto'])&&!in_array($c['k'],$ctrl['sin']??[],true))$cap['controles'][$ctrl['id']][$c['k']]='1';
 $data=['hoja'=>$key,'version'=>0,'accion'=>'guardar','datos'=>$cap];
 $saved=wfGuardarHoja($conexion,$data,$user,true);checkW($saved['version']===1,'No se guardó avance');
 rejectW(fn()=>wfGuardarHoja($conexion,$data,$user,true),'Se sobrescribió versión antigua');
 $other=$data;$other['version']=1;$other['datos']['seleccion']=[2147483647];rejectW(fn()=>wfGuardarHoja($conexion,$other,$user,true),'Se aceptó muestra ajena');
 $denied=$data;$denied['version']=1;rejectW(fn()=>wfGuardarHoja($conexion,$denied,$user,false),'Se permitió capturar una asignación ajena');
 $invalid=$data;$invalid['version']=1;$invalid['accion']='enviar';$invalid['datos']['filas']=[];rejectW(fn()=>wfGuardarHoja($conexion,$invalid,$user,true),'Se envió muestra incompleta');
 $progress=array_values(array_filter(wfTareas($conexion),fn($t)=>(int)$t['id_muestra']===$id&&$t['hoja']===$key));checkW($progress[0]['estado']['key']==='proceso','Avance no reflejado en planificación');
 $data['version']=1;$data['accion']='enviar';$sent=wfGuardarHoja($conexion,$data,$user,true);checkW(count($sent['formularios'])>0,'No se crearon formularios');
 $after=wfTareas($conexion);$selected=array_values(array_filter($after,fn($t)=>(int)$t['id_muestra']===$id&&$t['hoja']===$key));checkW(count($selected)===count($progress),'Se duplicaron tareas por rangos superpuestos');foreach($selected as $t)checkW($t['estado']['key']==='validacion','No pasó a validación');
 foreach($eligible as $t)if((int)$t['id_muestra']!==$id){$remain=array_values(array_filter($after,fn($r)=>$r['id_muestra']===$t['id_muestra']&&$r['id_tipo_analisis']===$t['id_tipo_analisis']));checkW(count($remain)===1&&wfPendiente($remain[0]),'El envío parcial ocultó otra muestra');}
 $snapshot=$conexion->prepare('SELECT datos FROM lab_hoja_formulario WHERE id_formulario=?');$snapshot->execute([$sent['formularios'][0]]);$snap=json_decode($snapshot->fetchColumn(),true);checkW($snap['encabezado']===$cap['encabezado']&&$snap['analista']===$user['nombre'],'Snapshot incompleto o identidad alterada');
 $data['version']=2;rejectW(fn()=>wfGuardarHoja($conexion,$data,$user,true),'Se envió dos veces la misma muestra');
 checkW(count(lab_bandeja_analista_pendientes($conexion))>0,'No quedan pendientes después de envío parcial');
 $_GET['id_formulario']=$sent['formularios'][0];$_SERVER['REQUEST_METHOD']='GET';
 ob_start();require __DIR__.'/../view/hoja_revision.php';$html=ob_get_clean();
 checkW(str_contains($html,'wf-review-data')&&!preg_match('/Warning:|Fatal error:|Notice:/',$html),'No se renderizó la hoja enviada para revisión');
}finally{$conexion->rollBack();}
checkW(json_encode(wfTareas($conexion))===json_encode($before),'La prueba dejó cambios');
echo "OK: asignación, prioridad, versiones, avance, envío parcial, snapshot, validación, duplicados e integridad. Transacción revertida.\n";
