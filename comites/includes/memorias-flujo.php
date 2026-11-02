<?php
declare(strict_types=1);

function mem_flujo_pendientes(array $m): array
{
    return array_values(array_filter($m['observaciones'],static fn($o)=>$o['estado']!=='atendida'));
}

function mem_flujo_inicializar(PDO $pdo,array &$m): void
{
    if(empty($m['fechas_etapa'])||empty($m['version'])||isset($m['fase_revision'])||in_array($m['estado'],['aprobado','publicado'],true))return;
    $m['fase_revision']=mem_mpr_etapa($m) ?? 'comite';
    $m['integrantes_editorial']=array_map(static fn($r)=>['id'=>(int)$r['id'],'nombre'=>$r['nombre'],'email'=>strtolower(trim($r['email'] ?? ''))],mem_revisores($pdo));
    $m['participaciones_editorial']=[];$m['editorial_version_inicio']=$m['version'];
    if($m['fase_revision']!=='comite'&&mem_flujo_pendientes($m))mem_flujo_corregir($m,'comite',$m['fase_revision']);
}

function mem_flujo_mover_calendario(array &$m,string $fase,string $inicio): void
{
    $etapas=['comite','transferencia','direccion'];
    $indice=array_search($fase,$etapas,true);
    if($indice===false||empty($m['fechas_etapa'][$fase]))return;
    $dias=(int)(new DateTimeImmutable($m['fechas_etapa'][$fase]['inicio']))->diff(new DateTimeImmutable($inicio))->format('%r%a');
    foreach(array_slice($etapas,$indice) as $key){
        foreach(['inicio','limite'] as $campo)$m['fechas_etapa'][$key][$campo]=(new DateTimeImmutable($m['fechas_etapa'][$key][$campo]))->modify(sprintf('%+d days',$dias))->format('Y-m-d');
        // El destino puede ser Dirección nuevamente después de sus propias observaciones.
        if(isset($m['revisiones'][$key])){
            $m['revisiones'][$key]['habilitada_desde']=$m['fechas_etapa'][$key]['inicio'];
            $m['revisiones'][$key]['limite']=$m['fechas_etapa'][$key]['limite'];
        }
    }
}

function mem_flujo_programar_correccion(array &$m): void
{
    if(!empty($m['correccion']['limite']))mem_flujo_mover_calendario($m,$m['correccion']['destino'],(new DateTimeImmutable($m['correccion']['limite']))->modify('+1 day')->format('Y-m-d'));
}

function mem_flujo_entrar(array &$m,string $fase,?string $hoy=null): void
{
    $hoy??=date('Y-m-d');$m['fase_revision']=$fase;
    $m['estado']=$fase==='direccion'?'aprobado_jt':'revision_final';
    $p=$m['fechas_etapa'][$fase];
    $limite=$p['limite'];
    // Una corrección tardía no debe consumir toda la revisión del siguiente responsable.
    if($limite<$hoy){$dias=(new DateTimeImmutable($p['inicio']))->diff(new DateTimeImmutable($p['limite']))->days;$limite=(new DateTimeImmutable($hoy))->modify('+'.$dias.' days')->format('Y-m-d');}
    $m['revisiones'][$fase]=['habilitada_desde'=>$hoy,'limite'=>$limite,'version_recibida'=>$m['version']];
}

function mem_flujo_corregir(array &$m,string $origen,string $destino,?string $hoy=null): void
{
    $hoy??=date('Y-m-d');$dias=(int)($m['dias_correccion'][$origen] ?? 0);
    $m['fase_revision']='correccion';
    $m['correccion']=['origen'=>$origen,'destino'=>$destino,'inicio'=>$hoy,'dias'=>$dias?:null,'limite'=>$dias?(new DateTimeImmutable($hoy))->modify('+'.$dias.' days')->format('Y-m-d'):null,'version_origen'=>$m['version'],'observaciones'=>array_column(mem_flujo_pendientes($m),'id')];
    mem_flujo_programar_correccion($m);
}

function mem_flujo_cerrar(array &$m,string $fase,?string $hoy=null): void
{
    $hoy??=date('Y-m-d');$destino=$fase==='comite'?'transferencia':'direccion';
    $m['revisiones'][$fase]['fin']=$hoy;
    if(mem_flujo_pendientes($m))mem_flujo_corregir($m,$fase,$destino,$hoy);
    elseif($fase!=='direccion')mem_flujo_entrar($m,$destino,$hoy);
    // Dirección siempre valida expresamente la versión corregida antes de liberar.
}

function mem_flujo_automatico(array &$m,?string $hoy=null): bool
{
    $hoy??=date('Y-m-d');$fase=$m['fase_revision'];
    if(!in_array($fase,['comite','transferencia'],true))return false;
    $limite=$m['revisiones'][$fase]['limite'] ?? $m['fechas_etapa'][$fase]['limite'];
    $todos=$fase==='comite'&&!empty($m['integrantes_editorial']);
    if($fase==='comite')foreach($m['integrantes_editorial'] ?? [] as $r){
        $items=array_filter($m['observaciones'],static fn($o)=>($o['etapa'] ?? '')==='comite'&&(int)$o['version']>=(int)($m['editorial_version_inicio'] ?? $m['version'])&&!empty($r['email'])&&strcasecmp(trim($o['autor_email'] ?? ''),trim($r['email']))===0);
        if(!$items)$todos=false;
    }
    if(!$todos&&$hoy<=$limite)return false;
    mem_flujo_cerrar($m,$fase,$hoy);
    $m['bitacora'][]=mem_log(['nombre'=>'Sistema','nombre_rol'=>'Automático'],'revision_cerrada',(int)$m['version'],($todos?'Todos los integrantes registraron observaciones. ':'Terminó el plazo de revisión. ').($m['fase_revision']==='correccion'?'Diagramación debe corregir y cargar una nueva versión antes de habilitar al siguiente revisor.':'Se habilita la siguiente revisión sin observaciones pendientes.'));
    return true;
}

function mem_flujo_integrante(array $m,array $u): ?array
{
    foreach($m['integrantes_editorial'] ?? [] as $r)if(!empty($u['correo'])&&!empty($r['email'])&&strcasecmp(trim($u['correo']),trim($r['email']))===0)return $r;
    return null;
}
