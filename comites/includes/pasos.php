<?php
declare(strict_types=1);
function pasos_estado(array $r,?string $hoy=null):string {if($r['estado']==='Cancelado')return 'Cancelado';if((int)$r['avance']>=100||$r['estado']==='Completado')return 'Completado';return !empty($r['plazo'])&&$r['plazo']<($hoy ?? date('Y-m-d'))?'Vencido':'En proceso';}
function pasos_revision(array $r):string {return hash('sha256',json_encode(array_intersect_key($r,array_flip(['id','comite_id','reunion_id','fecha','acuerdo','responsable','plazo','fecha_seguimiento','avance','estado','observaciones']))));}
function pasos_datos(PDO $pdo,array $u):array {
    $rows=$pdo->query('SELECT a.*,c.nombre AS comite_nombre FROM acuerdos a LEFT JOIN comites c ON c.id=a.comite_id')->fetchAll();foreach($rows as &$r){$r['id']=(int)$r['id'];$r['revision']=pasos_revision($r);$r['estado_calculado']=pasos_estado($r);$r['avance']=$r['estado_calculado']==='Completado'?100:max(0,min(100,(int)$r['avance']));}unset($r);
    return ['pasos'=>$rows,'comites'=>$pdo->query('SELECT id,nombre,activo FROM comites ORDER BY nombre')->fetchAll(),'gestionar'=>can_manage_resource($u,'acuerdos'),'csrf'=>$_SESSION['pasos_csrf'],'hoy'=>date('Y-m-d')];
}
function pasos_fecha(string $v,bool $required=false):?string {if(!$v&&!$required)return null;if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new RuntimeException('Indica una fecha válida.');return $v;}
function pasos_mutar(PDO $pdo,array $u,string $action,array $d):void {
    if(!can_manage_resource($u,'acuerdos'))throw new RuntimeException('No tienes permiso para gestionar próximos pasos.');$pdo->beginTransaction();
    try{$id=(int)($d['id'] ?? 0);$r=null;if($id){$q=$pdo->prepare('SELECT * FROM acuerdos WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('El próximo paso no existe.');if(!hash_equals(pasos_revision($r),(string)($d['revision'] ?? '')))throw new RuntimeException('El próximo paso cambió. Recarga antes de continuar.');}
        if($action==='eliminar'){if(!$id)throw new RuntimeException('Selecciona el próximo paso.');$pdo->prepare('DELETE FROM acuerdos WHERE id=?')->execute([$id]);}
        elseif($action==='guardar'){
            $cid=(string)($d['comite_id'] ?? '');$q=$pdo->prepare('SELECT * FROM comites WHERE id=?');$q->execute([$cid]);$c=$q->fetch();if(!$c||(!$c['activo']&&($r['comite_id'] ?? '')!==$cid))throw new RuntimeException('Selecciona un comité activo.');
            if(!empty($r['reunion_id'])&&$cid!==$r['comite_id'])throw new RuntimeException('El comité de un próximo paso de reunión se cambia desde la reunión.');
            $text=trim((string)($d['acuerdo'] ?? ''));$responsable=trim((string)($d['responsable'] ?? ''));if(!$text||mb_strlen($responsable)>180)throw new RuntimeException('Describe el próximo paso y revisa el responsable.');$plazo=pasos_fecha((string)($d['plazo'] ?? ''),true);$seguimiento=pasos_fecha((string)($d['fecha_seguimiento'] ?? ''));$avance=max(0,min(100,(int)($d['avance'] ?? 0)));$estado=(string)($d['estado'] ?? 'En proceso');if(!in_array($estado,['En proceso','Completado','Cancelado'],true))throw new RuntimeException('Estado no válido.');if($avance===100&&$estado!=='Cancelado')$estado='Completado';if($estado==='Completado')$avance=100;
            $values=[$cid,$text,$responsable,$plazo,$seguimiento,$avance,$estado,trim((string)($d['observaciones'] ?? ''))];
            if($r)$pdo->prepare('UPDATE acuerdos SET comite_id=?,acuerdo=?,responsable=?,plazo=?,fecha_seguimiento=?,avance=?,estado=?,observaciones=? WHERE id=?')->execute([...$values,$id]);else $pdo->prepare('INSERT INTO acuerdos(comite_id,acuerdo,responsable,plazo,fecha_seguimiento,avance,estado,observaciones,fecha) VALUES(?,?,?,?,?,?,?,?,CURDATE())')->execute($values);
        }else throw new RuntimeException('Acción no válida.');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
