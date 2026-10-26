<?php
require_once __DIR__.'/inscripcion_ingreso.php';

function cengi_ci_normalizar($valor): string {
    $valor=preg_replace('/^\xEF\xBB\xBF/','',trim((string)$valor));
    return cengi_texto_normalizado(str_replace(['_','/','-'], ' ', $valor));
}
function cengi_ci_columnas(): array {
    return ['id_curso'=>['ID_CURSO','ID curso'], 'curso'=>['CURSO','Curso/Diplomado','Código curso'],
        'ingenio'=>['INGENIO','Institución','Ingenio/institución'], 'cui'=>['CUI','DPI'], 'nombre'=>['NOMBRE','Nombre participante'],
        'puesto'=>['PUESTO','Cargo'], 'area'=>['AREA','Área'], 'correo'=>['CORREO_ELECTRONICO','Correo','Email'],
        'grado'=>['GRADO_ACADEMICO','Grado académico'], 'telefono'=>['TELEFONO','Teléfono'],
        'pagador'=>['QUIEN_PAGA','Quién paga','TIPO_PAGO','Pagador'], 'cuota'=>['TIPO_CUOTA','Tipo de cuota','Cuota']];
}
function cengi_ci_leer(array $archivo): array {
    if(($archivo['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($archivo['tmp_name']??''))throw new InvalidArgumentException('Selecciona un archivo válido.');
    if(($archivo['size']??0)>5*1024*1024)throw new InvalidArgumentException('El archivo supera los 5 MB.');
    $ext=strtolower(pathinfo($archivo['name'],PATHINFO_EXTENSION));$filas=[];
    if($ext==='csv'){
        $f=fopen($archivo['tmp_name'],'r');if(!$f)throw new InvalidArgumentException('No se pudo leer el CSV.');
        $primera=fgets($f);rewind($f);$sep=substr_count((string)$primera,';')>substr_count((string)$primera,',')?';':',';
        while(($fila=fgetcsv($f,0,$sep))!==false){if(count(array_filter($fila,static function($v){return trim((string)$v)!=='';})))$filas[]=$fila;if(count($filas)>501)break;}fclose($f);
    }elseif(in_array($ext,['xlsx','xls'],true)){
        require_once __DIR__.'/vendor/autoload.php';
        try{$libro=\PhpOffice\PhpSpreadsheet\IOFactory::load($archivo['tmp_name']);$hoja=$libro->getSheet(0);
            $max=$hoja->getHighestDataRow();if($max>501)throw new InvalidArgumentException('Máximo 500 registros por archivo.');
            $cols=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($hoja->getHighestDataColumn());
            if($cols>40)throw new InvalidArgumentException('El archivo contiene demasiadas columnas. Usa la plantilla.');
            for($r=1;$r<=$max;$r++){$fila=[];for($c=1;$c<=$cols;$c++){$celda=$hoja->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$r);if($celda->getDataType()==='f')throw new InvalidArgumentException('Utiliza valores, sin fórmulas, en la hoja de carga.');$fila[]=(string)$celda->getFormattedValue();}$filas[]=$fila;}
        }catch(InvalidArgumentException $e){throw $e;}catch(Throwable $e){throw new InvalidArgumentException('No fue posible leer el Excel. Revisa el archivo o guárdalo como CSV.');}
    }else throw new InvalidArgumentException('Utiliza CSV, XLS o XLSX.');
    if(count($filas)<2)throw new InvalidArgumentException('El archivo debe tener encabezados y al menos un participante.');
    if(count($filas)>501)throw new InvalidArgumentException('Máximo 500 registros por archivo.');
    $map=[];$aliases=cengi_ci_columnas();
    foreach($filas[0] as $i=>$titulo)foreach($aliases as $key=>$nombres)if(in_array(cengi_ci_normalizar($titulo),array_map('cengi_ci_normalizar',$nombres),true)){
        if(isset($map[$key]))throw new InvalidArgumentException('Hay dos columnas para '.$nombres[0].'.');$map[$key]=$i;
    }
    foreach(['ingenio','nombre','puesto','area','correo','grado','telefono'] as $key)if(!isset($map[$key]))throw new InvalidArgumentException('Falta la columna '.$aliases[$key][0].'.');
    $salida=[];foreach(array_slice($filas,1) as $i=>$fila){$datos=[];foreach($map as $k=>$pos)$datos[$k]=trim((string)($fila[$pos]??''));if(count(array_filter($datos,static function($v){return $v!=='';})))$salida[]=['linea'=>$i+2,'datos'=>$datos];}
    return $salida;
}

/** Vista previa sin escrituras; se ejecuta de nuevo al confirmar. */
function cengi_ci_analizar(PDO $db,array $filas,array $defectos): array {
    $cursos=$db->query('SELECT id,nombre_cursos,codigo_curso,inicio FROM cursos')->fetchAll(PDO::FETCH_ASSOC);
    $ingenios=$db->query('SELECT id,nombre_ingenios FROM ingenios')->fetchAll(PDO::FETCH_ASSOC);
    $limite=cengi_ins_ingenio_limitado();$vistos=[];$salida=[];$documentosPorCorreo=[];
    foreach($filas as $f){$d=$f['datos'];$r=['linea'=>$f['linea'],'datos'=>$d,'estado'=>'lista','detalle'=>'Lista para registrar','curso_id'=>null,'curso_nombre'=>'','ingenio_id'=>null];
        try{
            foreach(['nombre','puesto','area','correo','grado','telefono','ingenio'] as $campo)if(trim((string)($d[$campo]??''))==='')throw new InvalidArgumentException('Falta '.$campo.'.');
            foreach($d as $campo=>$valor)if(mb_strlen((string)$valor)>255)throw new InvalidArgumentException('El campo '.$campo.' es demasiado largo.');
            if(!filter_var($d['correo'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Correo electrónico inválido.');
            if($limite!==null&&cengi_ci_normalizar($limite)!==cengi_ci_normalizar($d['ingenio']))throw new InvalidArgumentException('El ingenio no corresponde al alcance de tu cuenta.');
            $ig=array_values(array_filter($ingenios,static function($i)use($d){return cengi_ci_normalizar($i['nombre_ingenios'])===cengi_ci_normalizar($d['ingenio']);}));
            if(count($ig)!==1)throw new InvalidArgumentException('Ingenio o institución no identificado.');$r['ingenio_id']=(int)$ig[0]['id'];
            $id=trim((string)($d['id_curso']??''));$nombre=trim((string)($d['curso']??''));
            if($id!==''&&!ctype_digit($id))throw new InvalidArgumentException('ID de curso inválido.');
            if($id!=='')$matches=array_values(array_filter($cursos,static function($c)use($id){return (int)$c['id']===(int)$id;}));
            elseif($nombre!=='')$matches=array_values(array_filter($cursos,static function($c)use($nombre){return cengi_ci_normalizar($nombre)===cengi_ci_normalizar($c['nombre_cursos'])||($c['codigo_curso']&&cengi_ci_normalizar($nombre)===cengi_ci_normalizar($c['codigo_curso']));}));
            else $matches=array_values(array_filter($cursos,static function($c)use($defectos){return (int)$c['id']===(int)($defectos['curso']??0);}));
            if(count($matches)!==1)throw new InvalidArgumentException('Curso no identificado o nombre repetido; indica su ID.');$c=$matches[0];
            if($id!==''&&$nombre!==''&&!in_array(cengi_ci_normalizar($nombre),[cengi_ci_normalizar($c['nombre_cursos']),cengi_ci_normalizar($c['codigo_curso'])],true))throw new InvalidArgumentException('ID y nombre/código del curso no coinciden.');
            $r['curso_id']=(int)$c['id'];$r['curso_nombre']=$c['nombre_cursos'];
            $p=cengi_ci_normalizar(($d['pagador']??'')!==''?$d['pagador']:($defectos['pagador']??''));
            $pagadores=['propio'=>'Propio','pagopropio'=>'Propio','ingenio'=>'Ingenio','institucion'=>'Ingenio'];if(!isset($pagadores[$p]))throw new InvalidArgumentException('Indica quién paga: Propio o Ingenio.');$r['pagador']=$pagadores[$p];
            $cuota=cengi_ci_normalizar(($d['cuota']??'')!==''?$d['cuota']:($defectos['cuota']??''));if(!isset(CENGI_INS_CUOTAS[$cuota]))throw new InvalidArgumentException('Indica una cuota: Asociado, Externo o Extranjero.');$r['cuota']=$cuota;
            $cui=$d['cui']??'';$q=$db->prepare("SELECT id,cui_participantes FROM participantes WHERE (cui_participantes<>'' AND cui_participantes=?) OR (correo_participantes<>'' AND LOWER(correo_participantes)=LOWER(?)) ORDER BY id LIMIT 2");$q->execute([$cui,$d['correo']]);$personas=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($personas)>1)throw new InvalidArgumentException('CUI y correo tienen coincidencias ambiguas.');
            if($persona=$personas[0]??null){if($cui!==''&&$persona['cui_participantes']!==''&&$cui!==$persona['cui_participantes'])throw new InvalidArgumentException('El correo pertenece a otro CUI.');$cui=$persona['cui_participantes']?:$cui;
                $q=$db->prepare('SELECT id FROM asignaciones WHERE participantes_id=? AND cursos_id=? AND estado_asignaciones=1 LIMIT 1');$q->execute([$persona['id'],$r['curso_id']]);if($q->fetchColumn()){$r['estado']='omitida';$r['detalle']='Ya está asignado al curso; se conserva su registro.';}
            }
            if($cui===''&&!$persona){
                $q=$db->prepare("SELECT DISTINCT cui_participante FROM solicitudes_inscripcion WHERE LOWER(correo)=LOWER(?) AND estado<>'Rechazado' AND COALESCE(cui_participante,'')<>'' LIMIT 2");$q->execute([$d['correo']]);$docs=$q->fetchAll(PDO::FETCH_COLUMN);
                if(count($docs)>1)throw new InvalidArgumentException('El correo tiene varios documentos asociados; revisa la identidad.');
                $cui=$docs[0]??'';
            }
            $email=mb_strtolower($d['correo']);
            if($cui!==''&&isset($documentosPorCorreo[$email])&&$documentosPorCorreo[$email]!==$cui)throw new InvalidArgumentException('El mismo correo aparece con CUI diferentes en el archivo.');
            if($cui!=='')$documentosPorCorreo[$email]=$cui;
            $r['datos']['cui']=$cui;$identity=$r['curso_id'].'|'.($cui!==''?'c:'.$cui:'e:'.mb_strtolower($d['correo']));$emailKey=$r['curso_id'].'|e:'.mb_strtolower($d['correo']);
            $q=$db->prepare("SELECT id_solicitud FROM solicitudes_inscripcion WHERE curso_id=? AND estado<>'Rechazado' AND ((cui_participante<>'' AND cui_participante=?) OR (correo<>'' AND LOWER(correo)=LOWER(?))) LIMIT 1");$q->execute([$r['curso_id'],$cui,$d['correo']]);
            if($q->fetchColumn()||isset($vistos[$identity])||isset($vistos[$emailKey])){$r['estado']='omitida';$r['detalle']='Ya existe una solicitud para esta persona y curso, o está repetida en el archivo.';}
            $vistos[$identity]=$vistos[$emailKey]=true;
        }catch(InvalidArgumentException $e){$r['estado']='error';$r['detalle']=$e->getMessage();}
        $salida[]=$r;
    }
    return $salida;
}

function cengi_ci_importar(PDO $db,array $filas,array $defectos): array {
    if(!$filas||count($filas)>500)throw new InvalidArgumentException('El archivo debe contener entre 1 y 500 filas.');
    if((int)$db->query("SELECT GET_LOCK('cengi_importar_interesados',15)")->fetchColumn()!==1)throw new RuntimeException('Hay otra carga en curso; intenta nuevamente.');
    try{
        $db->beginTransaction();$revisadas=cengi_ci_analizar($db,$filas,$defectos);$procesados=0;$idsCursos=[];$identidades=[];
        $insert=$db->prepare('INSERT INTO solicitudes_inscripcion(nombre_participante,cui_participante,puesto_participante,area_participante,grado_academico,correo,telefono,ingenio_id,curso_id,tipo_pago,cuota) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach($revisadas as $r){if($r['estado']!=='lista')continue;$d=$r['datos'];$cui=$d['cui']??'';$email=mb_strtolower($d['correo']);if($cui==='')$cui=$identidades[$email]??cengi_cui_nd_generar($db);$identidades[$email]=$cui;
            $insert->execute([$d['nombre'],$cui,$d['puesto'],$d['area'],$d['grado'],$d['correo'],$d['telefono'],$r['ingenio_id'],$r['curso_id'],$r['pagador'],$r['cuota']]);$sid=(int)$db->lastInsertId();
            cengi_ins_normalizar_ingreso($db,$sid,'carga_interna');
            $db->prepare('INSERT INTO inscripcion_historial(solicitud_id,accion,usuario,motivo) VALUES(?,?,?,?)')->execute([$sid,'carga_interesado',cengi_pp_usuario_nombre(),'Carga flexible · fila '.$r['linea'].' · '.$r['pagador'].' · '.$r['cuota']]);$procesados++;$idsCursos[$r['curso_id']]=true;
        }
        $db->commit();return ['procesados'=>$procesados,'omitidos'=>count($revisadas)-$procesados,'cursos'=>count($idsCursos),'detalle'=>$revisadas];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{$db->query("SELECT RELEASE_LOCK('cengi_importar_interesados')");}
}
