<?php
function labParametrosValidacion(string $hoja): array {
    static $config;
    $config??=json_decode(file_get_contents(__DIR__.'/../config/hojas_trabajo_v3.json'),true,512,JSON_THROW_ON_ERROR);
    $campos=[];
    foreach($config['hojas'][$hoja]['encabezado']['campos']??[] as $campo)if(!empty($campo['editableVal']))$campos[$campo['k']]=$campo;
    return $campos;
}
function labParametroValidar($valor,array $campo): string {
    if(!is_scalar($valor)&&$valor!==null)throw new InvalidArgumentException('Valor de parámetro inválido.');
    $v=str_replace(',','.',trim((string)$valor));
    if($v===''&&!empty($campo['opcionalVal']))return '';
    if(strlen($v)>20||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||((float)$v==0&&empty($campo['cero'])))throw new InvalidArgumentException('Ingrese un valor '.(!empty($campo['cero'])?'mayor o igual a cero':'mayor que cero').' en '.$campo['label'].'.');
    return $v;
}
