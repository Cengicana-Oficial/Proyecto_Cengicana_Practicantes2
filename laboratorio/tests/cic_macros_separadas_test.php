<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/workflow_trabajo_helper.php';
require_once __DIR__.'/../includes/parametros_validacion_helper.php';
function cmCheck($v,$msg){if(!$v)throw new RuntimeException($msg);}
$mk='Suelo|Macronutrientes (Ca, Mg, K, Na)';$ck='Suelo|Capacidad de intercambio catiónico (CIC)';
cmCheck(wfHoja('Suelo','Macronutrientes')['key']===$mk,'Ruta de Macronutrientes');
cmCheck(wfHoja('Suelo','Capacidad de intercambio catiónico (CIC)')['key']===$ck,'Ruta CIC');
$fields=['peso'=>'2','ca'=>'10','mg'=>'8','k'=>'4','na'=>'3','ml'=>'5'];
$old=['hoja'=>'Suelo|Macronutrientes','encabezado'=>['fca'=>'5.1','fmg'=>'8.2264','fk'=>'0.2557','fna'=>'0.4348','n'=>'0.031'],'seleccion'=>[1,2],'filas'=>[1=>$fields,2=>$fields],'controles'=>['BLANCO'=>$fields,'CONTROL'=>$fields],'fecha'=>'2026-11-06','analista'=>'Prueba'];
$macro=wfSepararBorradorFisico($old,$mk,[['id_muestra'=>1]]);$cic=wfSepararBorradorFisico($old,$ck);
cmCheck($macro['seleccion']===[1],'Recuperar solo muestras autorizadas');
cmCheck(!isset($macro['filas'][1]['ml'])&&!isset($macro['encabezado']['n']),'No mezclar CIC en Macronutrientes');
cmCheck($macro['encabezado']['fca']==='5.1','Conservar factor previo');
cmCheck($cic['encabezado']===['n'=>'0.031']&&isset($cic['filas'][1]['ml'])&&!isset($cic['filas'][1]['ca']),'Recuperar CIC por separado');
cmCheck(isset(labParametrosValidacion($mk)['fca'])&&isset(labParametrosValidacion($ck)['n']),'Permitir correcciones del técnico');
$clean=wfNormalizar($macro,wfConfig()['hojas'][$mk],[1=>[['codigo_muestra'=>'S-PRUEBA','analisis_hoja'=>'Macronutrientes']]],true);
cmCheck(!isset($clean['filas'][1]['ml'])&&$clean['encabezado']['fca']==='5.1','Captura independiente normalizada');
echo "OK: rutas independientes, avances, controles y parámetros del técnico.\n";
