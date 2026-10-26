<?php
/** Valida una fila del guardado conjunto. Conserva curso, estado y contenido interno. */
function cengi_ses_tabla_preparar(PDO $db,array $antes,array $entrada,string $hoy): array
{
    if(!hash_equals(cengi_ses_revision_tabla($antes),(string)($entrada['revision']??'')))throw new InvalidArgumentException('La sesión '.$antes['id'].' cambió. Recarga la página antes de guardar.');
    $plan=cengi_pp_plan($db,$antes['planificacion_id']);
    if(!$plan||$plan['estado']==='cancelado')throw new InvalidArgumentException('El curso de la sesión '.$antes['id'].' no está disponible para edición.');
    $v=[];
    foreach(['modulo','fecha','hora_inicio','hora_fin','modalidad','lugar'] as $k)$v[$k]=trim((string)($entrada[$k]??$antes[$k]));
    foreach(['instructor_id','soporte_id'] as $k)$v[$k]=(int)($entrada[$k]??$antes[$k])?:null;
    if(!cengi_pp_es_fecha($v['fecha']))throw new InvalidArgumentException('Fecha inválida en la sesión '.$antes['id'].'.');
    if($v['fecha']!==$antes['fecha']&&($v['fecha']<$plan['fecha_inicio']||$v['fecha']>$plan['fecha_fin']))throw new InvalidArgumentException('La fecha de la sesión '.$antes['id'].' está fuera del rango del curso.');
    if($antes['estado']==='impartida'&&$v['fecha']>$hoy)throw new InvalidArgumentException('Una sesión impartida no puede tener una fecha futura.');
    foreach(['hora_inicio','hora_fin'] as $k){$v[$k]=substr($v[$k],0,5);if(!cengi_pp_es_hora($v[$k]))throw new InvalidArgumentException('Horario inválido en la sesión '.$antes['id'].'.');$v[$k].=':00';}
    if($v['hora_fin']<=$v['hora_inicio'])throw new InvalidArgumentException('La hora final debe ser mayor que la inicial en la sesión '.$antes['id'].'.');
    if($v['lugar']===''||mb_strlen($v['lugar'])>255||!in_array($v['modalidad'],CENGI_PP_MODALIDADES,true))throw new InvalidArgumentException('Revisa modalidad y lugar de la sesión '.$antes['id'].'.');
    if($antes['liquidacion_id']&&($v['fecha']!==$antes['fecha']||(int)$v['instructor_id']!==(int)$antes['instructor_id']))throw new InvalidArgumentException('La sesión '.$antes['id'].' ya tiene liquidado el pago del instructor.');
    if($antes['liquidacion_soporte_id']&&(int)$v['soporte_id']!==(int)$antes['soporte_id'])throw new InvalidArgumentException('La sesión '.$antes['id'].' ya tiene liquidado el pago del soporte.');
    $estructura=cengi_pp_estructura_modulos($db,$plan);
    if(cengi_pp_modulos_automaticos($estructura))$v['modulo']=$antes['modulo'];
    elseif($v['modulo']!==$antes['modulo']&&!in_array($v['modulo'],cengi_pp_modulos_plan($db,$plan),true))throw new InvalidArgumentException('Módulo no disponible en la sesión '.$antes['id'].'.');
    foreach(['instructor_id'=>'instructores','soporte_id'=>'soporte_tecnico'] as $k=>$tabla)if($v[$k]){$q=$db->prepare("SELECT id FROM $tabla WHERE id=?");$q->execute([$v[$k]]);if(!$q->fetchColumn())throw new InvalidArgumentException('La persona asignada a la sesión '.$antes['id'].' ya no existe.');}
    return $v;
}

/** Actualización atómica de las filas seleccionadas, dentro de la transacción del llamador. */
function cengi_ses_tabla_guardar(PDO $db,array $filas,bool $confirmar=false): array
{
    if(!$db->inTransaction())throw new LogicException('Se requiere una transacción.');
    if(!$filas||count($filas)>200)throw new InvalidArgumentException('Selecciona entre 1 y 200 filas para guardar.');
    $porId=[];
    foreach($filas as $f){$id=(int)($f['id']??0);if(!$id||isset($porId[$id]))throw new InvalidArgumentException('La selección contiene sesiones inválidas o repetidas.');$porId[$id]=$f;}
    ksort($porId,SORT_NUMERIC);$cambios=[];$planes=[];$ics=[];
    foreach($porId as $id=>$f){
        $q=$db->prepare('SELECT id FROM curso_sesiones WHERE id=? FOR UPDATE');$q->execute([$id]);
        $antes=cengi_ses_obtener($db,$id);if(!$antes)throw new InvalidArgumentException('La sesión '.$id.' ya no existe.');
        $v=cengi_ses_tabla_preparar($db,$antes,$f,cengi_pp_hoy());
        $distinta=false;foreach($v as $k=>$valor)if((string)$valor!==(string)($antes[$k]??''))$distinta=true;
        if(!$distinta)continue;
        $cambios[$id]=['antes'=>$antes,'datos'=>$v];$planes[(int)$antes['planificacion_id']]=true;
    }
    foreach(array_keys($planes) as $pid)$ics+=cengi_ics_instantanea($db,[],$pid);
    foreach($cambios as $id=>$c){$v=$c['datos'];$db->prepare('UPDATE curso_sesiones SET '.implode(',',array_map(static function($k){return "$k=?";},array_keys($v))).' WHERE id=?')->execute(array_merge(array_values($v),[$id]));}
    foreach(array_keys($planes) as $pid)cengi_pp_asignar_modulos($db,$pid);
    $avisos=[];
    foreach($cambios as $id=>$c){
        $a=$c['antes'];$v=$c['datos'];$revisar=false;
        foreach(['fecha','hora_inicio','hora_fin','instructor_id','soporte_id','modalidad'] as $k)if((string)($a[$k]??'')!==(string)($v[$k]??''))$revisar=true;
        if($revisar&&$a['estado']!=='cancelada')foreach(cengi_pp_avisos_sesion($db,$v+['planificacion_id'=>$a['planificacion_id']],$id) as $aviso)$avisos[]='Sesión '.$id.': '.strip_tags($aviso);
        if($revisar&&$a['estado']!=='cancelada'){
            if(!$v['soporte_id']&&$v['modalidad']!=='Presencial')$avisos[]='Sesión '.$id.': no tiene soporte técnico asignado.';
            if($v['instructor_id']){
                $doc=cengi_pp_documentacion($db,$v['instructor_id']);
                if($doc&&!$doc['activo'])$avisos[]='Sesión '.$id.': el instructor está inactivo.';
                elseif($doc&&!empty($doc['faltan']))$avisos[]='Sesión '.$id.': documentación pendiente del instructor ('.implode(', ',$doc['faltan']).').';
            }
        }
    }
    if($avisos&&!$confirmar)return ['avisos'=>array_values(array_unique($avisos)),'confirmar'=>true];
    foreach($cambios as $id=>$c){$plan=cengi_pp_plan($db,$c['antes']['planificacion_id']);cengi_pp_log($db,$plan,'Edición de sesiones en tabla','Sesión '.$id.' actualizada en guardado conjunto.',['sesion_id'=>$id,'antes'=>$c['antes'],'despues'=>$c['datos'],'afecta'=>cengi_pp_convocado($plan)&&$c['datos']['fecha']>=cengi_pp_hoy()]);}
    return ['guardadas'=>count($cambios),'ics'=>$ics,'ids'=>array_map('intval',array_keys($cambios))];
}
