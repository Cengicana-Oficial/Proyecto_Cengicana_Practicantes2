<?php
require_once __DIR__.'/instructor_cv_helpers.php';
require_once __DIR__.'/vendor/autoload.php';
function cengi_cv_masivo_leer(string $ruta): array {
    $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();$reader->setReadDataOnly(true);
    $book=$reader->load($ruta);
    try {
        $sheet=$book->getSheetByName('CV digital');
        if(!$sheet)throw new InvalidArgumentException('Usa la hoja CV digital de la plantilla.');
        if($sheet->getHighestDataRow()>501)throw new InvalidArgumentException('Se permiten hasta 500 filas por archivo.');
        $rows=$sheet->toArray(null,false,false,false);$headers=array_map(static function($v){return strtoupper(trim((string)$v));},array_shift($rows)??[]);
        // Columnas agregadas después de publicar la plantilla: opcionales, para que las plantillas anteriores sigan sirviendo.
        $opcionales=['LINKEDIN'];
        $expected=['CODIGO_INSTRUCTOR'];foreach(cengi_cv_campos() as $grupo)foreach($grupo as $key=>$meta)if(!in_array(strtoupper($key),$opcionales,true))$expected[]=strtoupper($key);
        if(count($headers)!==count(array_unique($headers))||array_diff($expected,$headers))throw new InvalidArgumentException('Faltan encabezados o están repetidos. Descarga nuevamente la plantilla.');
        $out=[];foreach($rows as $i=>$row){if(!array_filter($row,static function($v){return trim((string)$v)!=='';}))continue;$data=[];foreach($headers as $j=>$h){$v=trim((string)($row[$j]??''));if(str_starts_with($v,'='))throw new InvalidArgumentException('Fila '.($i+2).': reemplaza las fórmulas por valores.');$data[strtolower($h)]=$v;}$out[]=['fila'=>$i+2,'datos'=>$data];}
        if(!$out)throw new InvalidArgumentException('El archivo no contiene CV para cargar.');return $out;
    } finally {$book->disconnectWorksheets();}
}
function cengi_cv_masivo_analizar(PDO $db,array $filas,bool $bloquear=false): array {
    $counts=[];foreach($filas as $r){$c=strtoupper(trim($r['datos']['codigo_instructor']??''));$counts[$c]=($counts[$c]??0)+1;}
    $out=[];
    foreach($filas as $r){$r['estado']='error';$r['id']=0;
        try{
            $codigo=strtoupper(trim($r['datos']['codigo_instructor']??''));
            if($codigo===''||$counts[$codigo]>1)throw new InvalidArgumentException('Código vacío o repetido en el archivo.');
            $q=$db->prepare('SELECT id,nombre,cv_path FROM instructores WHERE UPPER(codigo_catedratico)=?'.($bloquear?' FOR UPDATE':''));$q->execute([$codigo]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$matches)throw new InvalidArgumentException('El código '.$codigo.' no existe en el directorio. Esta carga agrega CV a instructores ya registrados. Crea primero al instructor desde «Nuevo instructor» y copia su código en el Excel.');
            if(count($matches)>1)throw new InvalidArgumentException('El código '.$codigo.' está repetido en el directorio. Corrige los códigos de los instructores antes de cargar el CV.');
            $inst=$matches[0];$r['id']=(int)$inst['id'];$r['nombre']=$inst['nombre'];
            if(cengi_cv_leer($db,$r['id'])||trim((string)$inst['cv_path'])!==''){$r['estado']='omitida';$r['detalle']='Ya tiene un CV registrado; se conserva sin cambios.';}
            else{$r['valores']=cengi_cv_normalizar($r['datos'],true);$r['estado']='lista';$r['detalle']='Listo para registrar; pendiente de validación de Capacitación.';}
        }catch(InvalidArgumentException $e){$r['detalle']=$e->getMessage();}
        $out[]=$r;
    }return $out;
}
function cengi_cv_masivo_guardar(PDO $db,array $filas,string $usuario): array {
    $db->beginTransaction();try{
        // Orden estable de bloqueo para cargas concurrentes.
        usort($filas,static function($a,$b){return strcmp($a['datos']['codigo_instructor']??'',$b['datos']['codigo_instructor']??'');});
        $analisis=cengi_cv_masivo_analizar($db,$filas,true);$n=0;
        foreach($analisis as $r){if($r['estado']!=='lista')continue;
            $json=json_encode($r['valores'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $db->prepare("INSERT INTO instructor_cv_digital(instructor_id,datos,estado,enviado_en) VALUES(?,?,'enviado',NOW())")->execute([$r['id'],$json]);
            $db->prepare("INSERT INTO instructor_cv_historial(instructor_id,accion,datos,usuario) VALUES(?,'carga_masiva',?,?)")->execute([$r['id'],$json,$usuario]);
            $db->prepare('UPDATE instructor_cv_enlaces SET cerrado=1 WHERE instructor_id=?')->execute([$r['id']]);$n++;
        }$db->commit();return ['registrados'=>$n,'detalle'=>$analisis];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
