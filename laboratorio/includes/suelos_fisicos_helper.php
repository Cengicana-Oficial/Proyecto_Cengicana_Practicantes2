<?php
/** Procedimiento del Excel Físico Suelos 2026: HR (N) y Textura, sin redondeos intermedios. */
function labSueloNumero($v): ?float {
    $v=str_replace(',','.',trim((string)($v??'')));
    return $v!==''&&is_numeric($v)&&is_finite((float)$v)?(float)$v:null;
}
function labSueloHumedadResidual(array $f): ?float {
    $c=labSueloNumero($f['caja']??null);$m=labSueloNumero($f['m']??null);$s=labSueloNumero($f['cajaS']??null);
    return $c!==null&&$m!==null&&$s!==null&&$c>=0&&$m>0&&$s>$c&&$s<=$c+$m+1e-9 ? ($c+$m-$s)*100/$m : null;
}
function labSueloTextura(array $f): ?array {
    $v=[];foreach(['hr','l1','t1','l2','t2'] as $k){$v[$k]=labSueloNumero($f[$k]??null);if($v[$k]===null)return null;}
    if($v['hr']<0||$v['hr']>=100)return null;
    $factor=200/(100-$v['hr']);$lc1=$v['l1']+($v['t1']-60)*0.2;$lc2=$v['l2']+($v['t2']-60)*0.2;
    $la=$lc1*$factor;$arcilla=$lc2*$factor;$limo=$la-$arcilla;$arena=100-$la;
    return compact('factor','lc1','lc2','la','arcilla','limo','arena')+['total'=>$arcilla+$limo+$arena];
}
function labSueloErrores(string $hoja,array $f,array $enc=[]): array {
    if($hoja==='Suelo|Humedad residual')return labSueloHumedadResidual($f)===null?['Revise los pesos: muestra húmeda > 0; caja + seca > caja y <= caja + húmeda.']:[];
    if($hoja!=='Suelo|Textura')return [];
    if(labSueloNumero($enc['peso']??50)!==50.0)return ['El procedimiento de Textura del Excel utiliza 50 g de muestra.'];
    $r=labSueloTextura($f);if($r===null)return ['Complete HR (0 <= HR < 100), lecturas y temperaturas en °F.'];
    foreach(['arcilla','limo','arena'] as $k)if($r[$k]<-1e-9||$r[$k]>100+1e-9)return ['Arena, limo y arcilla deben estar entre 0 y 100 %. Revise lecturas y temperaturas.'];
    return abs($r['total']-100)>1e-8?['La suma de arena, limo y arcilla debe ser 100 %.']:[];
}
function labSueloValidarCaptura(array $cap,array $excluir=[]): void {
    $hoja=$cap['hoja']??'';if(!in_array($hoja,['Suelo|Textura','Suelo|Humedad residual'],true))return;
    foreach($cap['seleccion']??[] as $id){if(in_array((int)$id,$excluir,true))continue;
        $errors=labSueloErrores($hoja,$cap['filas'][$id]??[],$cap['encabezado']??[]);
        if($errors)throw new InvalidArgumentException('Muestra '.$id.': '.implode(' ',$errors));
    }
    foreach(array_merge([['id'=>'CONTROL']],$cap['ctrlExtra']??[]) as $ctrl){
        $errors=labSueloErrores($hoja,$cap['controles'][$ctrl['id']]??[],$cap['encabezado']??[]);
        if($errors)throw new InvalidArgumentException($ctrl['id'].': '.implode(' ',$errors));
    }
}
