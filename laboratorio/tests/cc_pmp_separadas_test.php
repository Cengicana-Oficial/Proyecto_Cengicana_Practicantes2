<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/workflow_trabajo_helper.php';
function cpCheck($v,$m){if(!$v)throw new RuntimeException($m);}
$cc='Suelo|Capacidad de campo (CC)';$pmp='Suelo|Punto de marchitez permanente (PMP)';
cpCheck(wfHoja('Suelo','Capacidad de campo')['key']===$cc,'CC debe tener hoja propia');
cpCheck(wfHoja('Suelo','Punto de marchitez permanente')['key']===$pmp,'PMP debe tener hoja propia');
$fields=['cc_caja'=>'10','cc_h'=>'25','cc_s'=>'20','pmp_caja'=>'12','pmp_h'=>'24','pmp_s'=>'22','olla'=>'G'];
$old=['hoja'=>'Suelo|CC y PMP','seleccion'=>[1,2],'filas'=>[1=>$fields,2=>$fields],'controles'=>['CONTROL'=>$fields],'ctrlExtra'=>[['id'=>'CONTROL_EXTRA_1','despues'=>2]],'fecha'=>'2026-11-06','analista'=>'Prueba'];
$a=wfSepararBorradorFisico($old,$cc,[['id_muestra'=>1]]);$b=wfSepararBorradorFisico($old,$pmp);
cpCheck($a['seleccion']===[1]&&!isset($a['filas'][2]),'Recuperación solo de pendientes autorizados');
cpCheck(isset($a['filas'][1]['cc_h'])&&!isset($a['filas'][1]['pmp_h']),'Separación de datos CC');
cpCheck(!isset($a['filas'][1]['olla']),'La Olla antigua de PMP no debe copiarse a CC');
cpCheck($b['filas'][1]['olla']==='G'&&!isset($b['filas'][1]['cc_h']),'Recuperación PMP');
cpCheck(!isset($a['ctrlExtra'][0]['despues']),'Reubicar controles de muestras excluidas');
$pool=[1=>[['analisis_hoja'=>'Capacidad de campo (CC)','codigo_muestra'=>'S-PRUEBA']]];
$clean=wfNormalizar($a,wfConfig()['hojas'][$cc],$pool,true);
cpCheck(($clean['filas'][1]['olla']??null)==='','Olla opcional');
$a['filas'][1]['olla']='P';$clean=wfNormalizar($a,wfConfig()['hojas'][$cc],$pool,true);cpCheck($clean['filas'][1]['olla']==='P','Guardar Olla de CC');
$a['filas'][1]['olla']='X';try{wfNormalizar($a,wfConfig()['hojas'][$cc],$pool,true);throw new RuntimeException('Aceptó olla inválida');}catch(InvalidArgumentException $e){}
echo "OK: rutas CC/PMP, recuperación de avances y Olla opcional validada.\n";
