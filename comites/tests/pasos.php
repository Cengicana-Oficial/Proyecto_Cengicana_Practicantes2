<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/database.php';foreach(['functions','domain','pasos'] as $f)require dirname(__DIR__).'/includes/'.$f.'.php';
foreach(['acuerdos','comites'] as $t){$pdo->exec("CREATE TEMPORARY TABLE prueba_$t LIKE $t");$pdo->exec("CREATE TEMPORARY TABLE $t LIKE prueba_$t");}
function ok(bool $b,string $s):void{if(!$b)throw new LogicException($s);echo "OK: $s\n";}
function no(callable $f,string $s):void{try{$f();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;ok(true,$s);return;}throw new LogicException($s);}
$_SESSION['pasos_csrf']='test';$admin=['es_superadmin'=>1];$reader=['permissions'=>['comites.acuerdos.ver']];$pdo->exec("INSERT INTO comites(id,nombre,activo) VALUES('riego','Riego',1),('otro','Otro',1)");
$base=['comite_id'=>'riego','acuerdo'=>'Evaluar riego','responsable'=>'Responsable','plazo'=>'2020-01-01','estado'=>'En proceso','avance'=>25,'observaciones'=>'Confirmar fecha'];
no(fn()=>pasos_mutar($pdo,$reader,'guardar',$base),'Consulta no modifica próximos pasos');
no(fn()=>pasos_mutar($pdo,$admin,'guardar',array_replace($base,['plazo'=>'2026-02-30'])),'Fecha inválida rechazada');
pasos_mutar($pdo,$admin,'guardar',$base);$r=pasos_datos($pdo,$admin)['pasos'][0];$id=$r['id'];ok($r['estado_calculado']==='Vencido'&&$r['observaciones']==='Confirmar fecha','Vencimiento calculado y observaciones guardadas');
no(fn()=>pasos_mutar($pdo,$admin,'guardar',$base+['id'=>$id,'revision'=>'obsoleta']),'Edición obsoleta rechazada');
pasos_mutar($pdo,$admin,'guardar',array_replace($base,['id'=>$id,'revision'=>$r['revision'],'avance'=>100]));$r=pasos_datos($pdo,$admin)['pasos'][0];ok($r['estado']==='Completado'&&$r['avance']===100,'100% completa automáticamente');
pasos_mutar($pdo,$admin,'guardar',array_replace($base,['id'=>$id,'revision'=>$r['revision'],'avance'=>100,'estado'=>'Cancelado']));$r=pasos_datos($pdo,$admin)['pasos'][0];ok($r['estado_calculado']==='Cancelado','Cancelación prevalece sobre fecha y avance');
$pdo->prepare('UPDATE acuerdos SET reunion_id=42 WHERE id=?')->execute([$id]);$r=pasos_datos($pdo,$admin)['pasos'][0];
no(fn()=>pasos_mutar($pdo,$admin,'guardar',array_replace($base,['id'=>$id,'revision'=>$r['revision'],'comite_id'=>'otro'])),'Paso ligado conserva comité de la reunión');
comites_sync_reunion_acuerdos($pdo,42,'riego','2026-01-01','Responsable',[['id'=>$id,'acuerdo'=>'Evaluar riego','avance'=>100,'estado'=>'Cancelado','plazo'=>'2020-01-01']]);$r=pasos_datos($pdo,$admin)['pasos'][0];ok($r['estado']==='Cancelado','Editar reunión conserva cancelación');
pasos_mutar($pdo,$admin,'eliminar',['id'=>$id,'revision'=>$r['revision']]);ok(!pasos_datos($pdo,$admin)['pasos'],'Eliminar limpia el próximo paso');
ok(pasos_estado(['estado'=>'Pendiente','avance'=>0,'plazo'=>'2026-10-01'],'2026-10-01')==='En proceso','Plazo de hoy no se considera vencido');
echo "Pruebas completadas en tablas temporales.\n";
