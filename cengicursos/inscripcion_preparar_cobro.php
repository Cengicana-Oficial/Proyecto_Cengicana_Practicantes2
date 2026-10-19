<?php
require_once __DIR__.'/menu.php';
require_once __DIR__.'/inscripcion_ingreso.php';
require_once __DIR__.'/administracion_nav.php';
if(!cengi_ins_puede_validar_pagos()){http_response_code(403);exit('Solo Administración puede definir las cuotas.');}
$db=conectar();$cid=(int)($_GET['curso']??0);
$q=$db->prepare('SELECT * FROM cursos WHERE id=?');$q->execute([$cid]);$course=$q->fetch(PDO::FETCH_ASSOC);
if(!$course){http_response_code(404);exit('Curso no encontrado.');}
$error='';
if(empty($_SESSION['ins_csrf']))$_SESSION['ins_csrf']=bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!hash_equals($_SESSION['ins_csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Recarga la página.');
        $inicio=(string)($_POST['inicio']??'');$fin=(string)($_POST['fin']??'');
        if(!cengi_pp_es_fecha($inicio)||!cengi_pp_es_fecha($fin)||$fin<$inicio)throw new InvalidArgumentException('Revisa las fechas del curso.');
        $fees=[];foreach(['asociados','externos','extranjero'] as $key){$fee=filter_var($_POST[$key]??'',FILTER_VALIDATE_FLOAT);if($fee===false||$fee<0||$fee>99999999)throw new InvalidArgumentException('Revisa las cuotas.');$fees[$key]=round($fee,2);}
        if(max($fees)<=0)throw new InvalidArgumentException('Define al menos una cuota para preparar proformas.');
        $db->beginTransaction();$q=$db->prepare('SELECT id FROM cursos WHERE id=? FOR UPDATE');$q->execute([$cid]);
        $q=$db->prepare('SELECT id FROM planificacion_cursos WHERE curso_id=?');$q->execute([$cid]);$pid=(int)$q->fetchColumn();
        if(!$pid){
            $db->prepare("INSERT INTO planificacion_cursos(anio,nombre,categoria_curso_id,modalidad,fecha_inicio,fecha_fin,hora_inicio,hora_fin,costo_asociados,costo_externos,costo_extranjero,estado,curso_id,creado_por_nombre,observaciones) VALUES(?,?,?,?,?,?,'00:00:00','00:00:00',?,?,?,'aprobado',?,?,?)")
            ->execute([(int)substr($inicio,0,4),$course['nombre_cursos'],$course['categoria_curso_id'],$course['tipo'],$inicio,$fin,$fees['asociados'],$fees['externos'],$fees['extranjero'],$cid,cengi_pp_usuario_nombre(),'Integración del curso existente al seguimiento administrativo. La programación de sesiones se mantiene en su gestión.']);
            $pid=(int)$db->lastInsertId();
        }
        // Vincula las solicitudes al curso existente. Mantiene pagos y cuotas ya definidos.
        $q=$db->prepare('SELECT id_solicitud FROM solicitudes_inscripcion WHERE curso_id=? AND planificacion_id IS NULL');$q->execute([$cid]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $sid){
            $before=cengi_ins_solicitud($db,$sid,true);
            $db->prepare('UPDATE solicitudes_inscripcion SET planificacion_id=? WHERE id_solicitud=?')->execute([$pid,$sid]);
            cengi_ins_normalizar_ingreso($db,$sid,$before['origen_inscripcion']??'sin_identificar');
            $db->prepare('INSERT INTO inscripcion_historial(solicitud_id,accion,usuario,motivo,antes,despues) VALUES(?,?,?,?,?,?)')->execute([$sid,'preparar_cobro',cengi_pp_usuario_nombre(),'Curso vinculado a cuotas y proformas',json_encode($before,JSON_UNESCAPED_UNICODE),json_encode(cengi_ins_solicitud($db,$sid),JSON_UNESCAPED_UNICODE)]);
        }
        $planCobro=cengi_pp_plan($db,$pid);
        $db->commit();header('Location: inscripciones.php?vista=pagos&tab=grupos&plan='.$pid.'&anio='.(int)$planCobro['anio']);exit;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e instanceof InvalidArgumentException?$e->getMessage():'No fue posible preparar el cobro.';if(!($e instanceof InvalidArgumentException))error_log($e->getMessage());}
}
?>
<!doctype html><html lang="es"><head><?php cengi_pp_head('Preparar cobro del curso'); ?></head><body class="cengi-canvas cg-tema"><?php menu_render(); ?><main class="container pp-page"><header class="ins-process-header"><small>INSCRIPCIONES Y PAGOS</small><h1>Preparar cobro del curso</h1><p><?= cengi_pp_h($course['nombre_cursos']) ?></p></header><?php cengi_ins_nav('pagos');cengi_pp_feedback($error,'error'); ?>
<section class="pp-card"><h2>Cuotas para proformas</h2><p>Confirma las cuotas y fechas. Las inscripciones actuales se conservarán en este curso y quedarán disponibles para emitir proformas por ingenio. Revisa el importe final de cada proforma antes de emitirla.</p>
<form method="post" class="pp-toolbar"><input type="hidden" name="csrf" value="<?= cengi_pp_h($_SESSION['ins_csrf']) ?>"><label>Inicio<input type="date" name="inicio" required value="<?= cengi_pp_h($_POST['inicio']??$course['inicio']) ?>"></label><label>Fin<input type="date" name="fin" required value="<?= cengi_pp_h($_POST['fin']??$course['fin']) ?>"></label>
<?php foreach(['asociados'=>'Asociado (Q)','externos'=>'Externo (Q)','extranjero'=>'Extranjero (US$)'] as $key=>$label): ?><label><?= $label ?><input type="number" name="<?= $key ?>" min="0" step="0.01" required value="<?= cengi_pp_h($_POST[$key]??'') ?>"></label><?php endforeach; ?><button class="btn btn-primary">Guardar cuotas y preparar grupos</button></form></section></main></body></html>
