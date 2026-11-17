<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/textura_revision_helper.php';
function txCheck($v,$m){if(!$v)throw new RuntimeException($m);}
function txReject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Se aceptó una corrección inválida');}
$cap=['hoja'=>'Suelo|Textura','seleccion'=>[7],'filas'=>[7=>['hr'=>'2.1956087824351185','l1'=>'23','l2'=>'8','t1'=>'77.5','t2'=>'74.4']]];
$cambio=[7=>['clase'=>'Franco arenoso','motivo'=>'Revisión del triángulo textural.']];
$r=labTexturaAplicarRevision($cap,$cambio,'Técnico de prueba','2026-11-06 10:00:00');
txCheck($r['datos']['filas'][7]['claseManual']==='Franco arenoso','No guardó clase');
txCheck($r['datos']['filas'][7]['claseManualUsuario']==='Técnico de prueba','No guardó usuario');
foreach($cap['filas'][7] as $k=>$v)txCheck($r['datos']['filas'][7][$k]===$v,'Cambió un dato medido');
txCheck(str_contains($r['historial'][0],'Automática → Franco arenoso'),'No conserva antes y después');
$restaurada=labTexturaAplicarRevision($r['datos'],[7=>['clase'=>'','motivo'=>'Confirmada la clase automática.']],'Otro técnico','2026-11-06 11:00:00');
txCheck($restaurada['datos']['filas'][7]['claseManual']==='','No vuelve a automática');
txCheck(str_contains($restaurada['historial'][0],'Franco arenoso → Automática'),'Falta historial de restauración');
txReject(fn()=>labTexturaAplicarRevision($cap,[7=>['clase'=>'Inventada','motivo'=>'Prueba']],'T','hoy'));
txReject(fn()=>labTexturaAplicarRevision($cap,[7=>['clase'=>'Franco','motivo'=>' ']],'T','hoy'));
txReject(fn()=>labTexturaAplicarRevision($cap,[8=>['clase'=>'Franco','motivo'=>'Prueba']],'T','hoy'));
txReject(fn()=>labTexturaAplicarRevision(array_replace($cap,['hoja'=>'Suelo|pH']),$cambio,'T','hoy'));
txReject(fn()=>labTexturaAplicarRevision($cap,[7=>['clase'=>['Franco'],'motivo'=>'Prueba']],'T','hoy'));
echo "OK: corrección, restauración, historial, datos intactos y rechazo de cambios inválidos.\n";
