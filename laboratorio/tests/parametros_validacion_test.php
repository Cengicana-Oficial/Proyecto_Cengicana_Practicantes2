<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/parametros_validacion_helper.php';
require_once __DIR__.'/../includes/workflow_trabajo_helper.php';
function pvCheck($v,$m){if(!$v)throw new RuntimeException($m);}
function pvReject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Se aceptó un parámetro inválido');}
$n=0;
foreach(wfConfig()['hojas'] as $key=>$h){
    if(($h['encabezado']['titulo']??'')!=='Parámetros de la corrida'||$key==='Suelo|Textura')continue;
    $permitidos=labParametrosValidacion($key);
    foreach($h['encabezado']['campos'] as $c){
        if(in_array($c['k'],['pend','inter'],true)){pvCheck(empty($c['soloVal'])&&empty($c['gestionVal']),'Pendiente/intercepto deben capturarse');continue;}
        pvCheck(!empty($c['soloVal'])&&!empty($c['gestionVal']),'Parámetro visible en captura: '.$key);
        pvCheck(isset($permitidos[$c['k']]),'No se permite en validación: '.$key.' '.$c['k']);
        $default=$c['def']??'';pvCheck(labParametroValidar($default,$c)===$default,'Default inválido');
    }
    $n++;
}
pvCheck(labParametrosValidacion('Suelo|Textura')===[],'Los 50 g de Textura deben seguir fijos');
$factor=labParametrosValidacion('Suelo|Boro')['corr'];
pvCheck(labParametroValidar('1,408',$factor)==='1.408','Coma decimal');
foreach(['','0','-1','abc','1e999'] as $v)pvReject(fn()=>labParametroValidar($v,$factor));
$pend=labParametrosValidacion('Foliar|Fósforo')['pend'];pvCheck(labParametroValidar('',$pend)==='','Restaurar pendiente calculada');
$blanco=labParametrosValidacion('Agua|Titulaciones')['be'];pvCheck(labParametroValidar('0',$blanco)==='0','Conservar blanco cero');
// Enviar captura ya no exige confirmar parámetros trasladados al revisor.
$key='Foliar|Nitrógeno';$h=wfConfig()['hojas'][$key];$f=[];
foreach($h['cols'] as $c)if(empty($c['auto']))$f[$c['k']]='1';
$raw=['fecha'=>'2026-11-06','analista'=>'Prueba','seleccion'=>[1],'filas'=>[1=>$f],'controles'=>['BLANCO'=>$f,'CONTROL'=>$f]];
$cap=wfNormalizar($raw,$h,[1=>[['codigo_muestra'=>'S-PRUEBA','analisis_hoja'=>'Nitrógeno']]],true);
pvCheck($cap['encabezado']['nhcl']==='0.0099779','No conserva normalidad por defecto');
echo "OK: $n hojas, parámetros del revisor, valores por defecto y reglas numéricas.\n";
