<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/suelos_fisicos_helper.php';
function fisCheck($v,$msg){if(!$v)throw new RuntimeException($msg);}
function fisReject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Se aceptaron datos inválidos');}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/suelos_fisicos_excel_2026.json'),true,512,JSON_THROW_ON_ERROR);
foreach($fixture['casos'] as $c){
    $hr=labSueloHumedadResidual($c['humedad']);fisCheck(abs($hr-$c['entrada']['hr'])<1e-10,'HR no coincide');
    $f=$c['entrada'];$f['hr']=(string)$hr;$r=labSueloTextura($f);
    foreach($c['esperado'] as $k=>$v)fisCheck(abs($r[$k]-$v)<1e-10,$c['nombre'].' '.$k.' no coincide');
    fisCheck(!labSueloErrores('Suelo|Textura',$f,['peso'=>50]),'Textura válida rechazada');
    $cap=['hoja'=>'Suelo|Textura','encabezado'=>['peso'=>50],'seleccion'=>[1,2],'filas'=>[1=>$f,2=>$f],'controles'=>['CONTROL'=>$f]];
    labSueloValidarCaptura($cap);
    $cap['filas'][1]['l2']=40;fisReject(fn()=>labSueloValidarCaptura($cap));
    labSueloValidarCaptura($cap,[1]); // Permite devolver la muestra inválida a reanálisis.
    $cap['controles']['CONTROL']['hr']='';fisReject(fn()=>labSueloValidarCaptura($cap,[1]));
}
foreach([['caja'=>16,'m'=>0,'cajaS'=>20],['caja'=>16,'m'=>5,'cajaS'=>22],['caja'=>16,'m'=>5,'cajaS'=>16]] as $f)fisCheck(labSueloHumedadResidual($f)===null,'Pesos inválidos aceptados');
fisCheck(labSueloHumedadResidual(['caja'=>'16,18','m'=>'5,01','cajaS'=>'21,08'])!==null,'No acepta coma decimal');
fisCheck(labSueloHumedadResidual(['caja'=>16,'m'=>5,'cajaS'=>21])===0.0,'HR cero rechazada');
echo "OK: 3 casos Excel, validación del servidor, controles y reanálisis.\n";
