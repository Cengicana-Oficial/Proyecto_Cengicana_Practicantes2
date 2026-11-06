<?php
const LAB_TEXTURAS_REVISION = ['Arena','Arena franca','Franco arenoso','Franco','Franco limoso','Limo','Franco arcillo arenoso','Franco arcilloso','Franco arcillo limoso','Arcillo arenoso','Arcillo limoso','Arcilla'];

/** Solo la clase reportada: los datos medidos y las fracciones quedan intactos. */
function labTexturaAplicarRevision(array $cap,array $cambios,string $usuario,string $fecha): array {
    if(($cap['hoja']??'')!=='Suelo|Textura')throw new InvalidArgumentException('Solo se puede corregir la clase en una hoja de Textura.');
    $historial=[];
    foreach($cambios as $id=>$cambio){
        if(!ctype_digit((string)$id)||!in_array((int)$id,array_map('intval',$cap['seleccion']??[]),true)||!isset($cap['filas'][$id])||!is_array($cambio))throw new InvalidArgumentException('La muestra de la corrección no pertenece al formulario.');
        if(!is_string($cambio['clase']??null)||!is_string($cambio['motivo']??null))throw new InvalidArgumentException('Corrección de textura inválida.');
        $clase=trim($cambio['clase']);$motivo=trim($cambio['motivo']);
        if($clase!==''&&!in_array($clase,LAB_TEXTURAS_REVISION,true))throw new InvalidArgumentException('Seleccione una clase textural del catálogo.');
        if($motivo===''||mb_strlen($motivo)>1000)throw new InvalidArgumentException('Indique el motivo de la corrección de textura (máximo 1000 caracteres).');
        $antes=$cap['filas'][$id]['claseManual']??'';
        $cap['filas'][$id]['claseManual']=$clase;
        $cap['filas'][$id]['claseManualMotivo']=$motivo;
        $cap['filas'][$id]['claseManualUsuario']=$usuario;
        $cap['filas'][$id]['claseManualFecha']=$fecha;
        $historial[]='Muestra '.$id.': '.($antes?:'Automática').' → '.($clase?:'Automática').'. Motivo: '.$motivo;
    }
    return ['datos'=>$cap,'historial'=>$historial];
}
