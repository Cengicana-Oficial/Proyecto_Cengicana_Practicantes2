<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/database.php';
foreach(['functions','domain','catalogo','directorio','memorias'] as $f)require dirname(__DIR__).'/includes/'.$f.'.php';
mem_preparar($pdo);
foreach(['memoria_ediciones','memoria_config','memoria_avisos','memorias_resultados','memoria_track','comite_editorial','contactos','contacto_comite','comites'] as $t){$pdo->exec("CREATE TEMPORARY TABLE prueba_{$t} LIKE {$t}");$pdo->exec("CREATE TEMPORARY TABLE {$t} LIKE prueba_{$t}");}
function ok(bool $v,string $msg):void{if(!$v)throw new RuntimeException($msg);echo "OK: $msg\n";}
function no(callable $fn,string $msg):void{try{$fn();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;ok(true,$msg);return;}throw new LogicException($msg);}
$_SESSION['memorias_csrf']='prueba';
$admin=['id'=>901,'nombre'=>'Admin','correo'=>'admin@example.invalid','es_superadmin'=>1];
$user=fn($id,$role,$email)=>['id'=>$id,'nombre'=>$role,'nombre_rol'=>$role,'correo'=>$email,'permissions'=>['comites.memorias.ver']];
$author=$user(902,'Autor','autor@example.invalid');$reviewer=$user(903,'Revisor','revisor@example.invalid');$other=$user(904,'Consulta','otro@example.invalid');$diag=$user(905,'Diagramación','diag@example.invalid');$jt=$user(906,'Jefe de Transferencia','jt@example.invalid');$dir=$user(907,'Director General','dir@example.invalid');
$pdo->exec("INSERT INTO memoria_config(id) VALUES(1)");$pdo->exec("INSERT INTO comites(id,nombre) VALUES('editorial','Editorial')");
no(fn()=>mem_mutar($pdo,$other,'edicion',['nombre'=>'No']),'Consulta no configura ediciones');
mem_mutar($pdo,$admin,'edicion',['nombre'=>'Prueba','anio'=>2026,'dias_revision'=>15]);$eid=(int)$pdo->query('SELECT MAX(id) FROM memoria_ediciones')->fetchColumn();
no(fn()=>mem_mutar($pdo,$admin,'edicion',['id'=>$eid,'revision'=>0,'nombre'=>'Vieja']),'Edición desactualizada rechazada');
mem_mutar($pdo,$admin,'revisor_nuevo',['nombre'=>'Revisor','email'=>$reviewer['correo']]);$cid=(int)$pdo->query('SELECT MAX(id) FROM contactos')->fetchColumn();
ok(count(mem_revisores($pdo))===1,'Revisor integrado al directorio y comité');
$pdo->prepare("INSERT INTO memorias_resultados(numero_memoria,titulo,autor,autor_email,edicion_id,flujo,coautores) VALUES('MR-2026-001','Artículo','Autor',?,?,'entregado','')")->execute([$author['correo'],$eid]);$id=(int)$pdo->lastInsertId();
ok(count(mem_datos($pdo,$author)['articulos'])===1&&count(mem_datos($pdo,$other)['articulos'])===0,'Privacidad por identidad del autor');
no(fn()=>mem_descarga($pdo,$other,['id'=>$id]),'Descarga ajena rechazada');
no(fn()=>mem_mutar($pdo,$author,'asignar',['id'=>$id]),'Autor no puede asignar revisor');
no(fn()=>mem_mutar($pdo,$admin,'asignar',['id'=>$id,'revisor_id'=>$cid,'limite_revision'=>'2000-01-01']),'Plazo pasado rechazado');
$assign=['id'=>$id,'revisor_id'=>$cid,'limite_revision'=>date('Y-m-d')];mem_mutar($pdo,$admin,'asignar',$assign);
ok(mem_fila($pdo,$id)['flujo']==='revision'&&count(mem_datos($pdo,$reviewer)['articulos'])===1,'Asignación habilita revisión al destinatario');
ok(count(mem_datos($pdo,$reviewer)['avisos'])===1,'Aviso interno al revisor');
no(fn()=>mem_mutar($pdo,$reviewer,'revisar',['id'=>$id,'recomendacion'=>'Cambios menores']),'Revisión exige archivos');
no(fn()=>mem_mutar($pdo,$other,'declinar',['id'=>$id,'observaciones'=>'Motivo suficiente']),'Tercero no declina revisión');
mem_mutar($pdo,$reviewer,'declinar',['id'=>$id,'observaciones'=>'No puedo revisar']);ok(mem_fila($pdo,$id)['flujo']==='entregado','Declinación vuelve a coordinación');
$pdo->prepare('UPDATE memorias_resultados SET autor_email=? WHERE id=?')->execute([$reviewer['correo'],$id]);
no(fn()=>mem_mutar($pdo,$admin,'asignar',$assign),'Conflicto de interés requiere confirmación');mem_mutar($pdo,$admin,'asignar',$assign+['confirmar_conflicto'=>true]);
no(fn()=>mem_mutar($pdo,$admin,'asignar',$assign+['revision'=>1,'confirmar_conflicto'=>true]),'Transición con versión obsoleta rechazada');
$pdo->prepare("UPDATE memorias_resultados SET flujo='observaciones',autor_email=? WHERE id=?")->execute([$author['correo'],$id]);
no(fn()=>mem_mutar($pdo,$reviewer,'corregir',['id'=>$id,'observaciones'=>'Corrección enviada']),'Solo autor corrige');
no(fn()=>mem_mutar($pdo,$author,'corregir',['id'=>$id,'observaciones'=>'Corrección enviada']),'Corrección exige nueva versión del archivo');
// El callback simula únicamente archivos para probar el flujo del consolidado; los uploads reales se prueban por HTTP.
$upload=fn(...$args)=>['nombre'=>'mpr.pdf','ruta'=>'../uploads/comites/memorias/prueba.pdf'];
$mpr=fn()=>mem_mpr(mem_edicion($pdo,$eid));
$act=function($u,$action,$d=[])use($pdo,$eid,$upload){mem_mpr_mutar($pdo,$u,$action,['id'=>$eid]+$d,$upload);};
no(fn()=>$act($other,'mpr_cargar'),'Consolidado restringido a participantes');
no(fn()=>$act($diag,'mpr_cargar'),'Carga con artículos pendientes requiere confirmación');
$act($diag,'mpr_cargar',['aceptar_pendientes'=>true]);ok($mpr()['estado']==='revision_final','Carga inicia revisión final');
no(fn()=>$act($dir,'mpr_aprobar'),'Dirección no aprueba antes de Transferencia');
$act($reviewer,'mpr_observar',['observaciones'=>'Revisar conclusiones']);$oid=$mpr()['observaciones'][0]['id'];
no(fn()=>$act($jt,'mpr_aprobar'),'Observaciones pendientes bloquean aprobación sin confirmación');
$act($diag,'mpr_cargar',['aceptar_pendientes'=>true,'observaciones'=>'Se revisó el texto']);ok($mpr()['observaciones'][0]['estado']==='pendiente','Observación no seleccionada sigue pendiente en siguiente versión');
$act($diag,'mpr_cargar',['aceptar_pendientes'=>true,'observaciones'=>'Se corrigieron conclusiones','atendidas'=>[$oid]]);ok($mpr()['observaciones'][0]['estado']==='atendida'&&count($mpr()['versiones'])===2,'Atención preserva respuesta y versiones anteriores');
$act($jt,'mpr_aprobar');ok($mpr()['estado']==='aprobado_jt','Transferencia aprueba primero');
$act($reviewer,'mpr_observar',['observaciones'=>'Nueva observación final']);ok($mpr()['estado']==='revision_final','Nueva observación reinicia aprobación');
$act($jt,'mpr_aprobar',['aceptar_pendientes'=>true]);$act($dir,'mpr_aprobar',['aceptar_pendientes'=>true]);
no(fn()=>$act($diag,'mpr_publicar',['fecha'=>date('Y-m-d'),'enlace'=>'javascript:alert(1)']),'Publicación rechaza enlaces inseguros');
$previous=$mpr()['version'];
$act($diag,'mpr_cargar',['aceptar_pendientes'=>true,'observaciones'=>'Correcciones finales del consolidado']);
ok($mpr()['estado']==='revision_final'&&$mpr()['version']===$previous+1,'Corregir documento aprobado crea versión y reinicia aprobaciones');
no(fn()=>$act($diag,'mpr_publicar',['fecha'=>date('Y-m-d')]),'No publica corrección sin nuevas aprobaciones');
$act($jt,'mpr_aprobar',['aceptar_pendientes'=>true]);$act($dir,'mpr_aprobar',['aceptar_pendientes'=>true]);
$act($diag,'mpr_publicar',['fecha'=>date('Y-m-d'),'enlace'=>'https://example.invalid/mpr']);ok($mpr()['estado']==='publicado','Publicación solo después de ambas aprobaciones');
no(fn()=>$act($diag,'mpr_cargar'),'Consolidado publicado queda cerrado');
ok(mem_datos($pdo,$author)['ediciones'][0]['mpr']===null,'Autor no recibe expediente del consolidado');
mem_mutar($pdo,$admin,'eliminar',['id'=>$id]);ok(count(mem_datos($pdo,$admin)['articulos'])===0,'Eliminación limpia artículo y seguimiento');
echo "Pruebas en tablas temporales completadas, sin correos enviados.\n";
