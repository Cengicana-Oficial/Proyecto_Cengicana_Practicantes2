<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/database.php';
require dirname(__DIR__).'/includes/functions.php';
require dirname(__DIR__).'/includes/domain.php';
require dirname(__DIR__).'/includes/catalogo.php';
require dirname(__DIR__).'/includes/directorio.php';
require dirname(__DIR__).'/includes/directorio-exportar.php';
require dirname(__DIR__).'/includes/agenda.php';
agenda_preparar($pdo);
foreach(['comites','contactos','contacto_comite','contacto_segmentos','reuniones','reunion_asistentes','acuerdos','eventos_transferencia','evento_asistentes','evento_encuestas','correos_enviados','memorias_resultados','comite_editorial'] as $t){
    $pdo->exec("CREATE TEMPORARY TABLE prueba_{$t} LIKE {$t}");$pdo->exec("CREATE TEMPORARY TABLE {$t} LIKE prueba_{$t}");
}
function verificar(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo "OK: {$m}\n";}
function rechaza(callable $fn,string $m):void{try{$fn();}catch(RuntimeException $e){verificar(true,$m);return;}throw new LogicException($m);}
$u=['id'=>999999,'nombre'=>'Prueba local','es_superadmin'=>1];
$_SESSION['agenda_csrf']='prueba';$_SESSION['directorio_csrf']='prueba';
$pdo->exec("INSERT INTO comites(id,nombre) VALUES ('prueba','Comité de prueba'),('otro','Otro comité')");
$plan=['comite_id'=>'prueba','plan_mes'=>'2026-01','plan_fecha'=>'2026-01-31','plan_temas'=>'Plan original','responsable'=>'Responsable','repetir'=>1];
agenda_mutar($pdo,$u,'reuniones','planificar',$plan);
verificar((int)$pdo->query('SELECT COUNT(*) FROM reuniones')->fetchColumn()===12,'Recurrencia hasta diciembre');
verificar($pdo->query("SELECT plan_fecha FROM reuniones WHERE plan_mes='2026-02'")->fetchColumn()==='2026-02-28','Recurrencia ajusta último día del mes');
rechaza(fn()=>agenda_mutar($pdo,$u,'reuniones','planificar',$plan),'Duplicados rechazados');
verificar(!$pdo->inTransaction() && (int)$pdo->query('SELECT COUNT(*) FROM reuniones')->fetchColumn()===12,'Duplicados no dejan cambios parciales');
rechaza(fn()=>agenda_plan(['plan_mes'=>'2026-02','plan_fecha'=>'2026-03-01']),'Fecha tentativa corresponde al mes');
rechaza(fn()=>agenda_fecha('2026-02-30'),'Fecha imposible rechazada');
$cid=directorio_guardar($pdo,['nombre'=>'Líder prueba','email'=>'lider@example.invalid','cargo'=>'Gerente agrícola'],'Prueba');
$pdo->prepare("UPDATE comites SET lider_id=? WHERE id='prueba'")->execute([$cid]);
$leader=['nombre'=>'Líder','correo'=>'lider@example.invalid','permissions'=>['comites.reuniones.ver'],'es_superadmin'=>0];
verificar(agenda_puede($pdo,$leader,'reuniones','prueba')&&!agenda_puede($pdo,$leader,'reuniones','otro')&&!agenda_puede($pdo,$leader,'eventos'),'Líder gestiona únicamente su comité');
rechaza(fn()=>agenda_mutar($pdo,$leader,'reuniones','planificar',array_merge($plan,['comite_id'=>'otro'])),'Permisos comprobados al escribir');
$id=(int)$pdo->query('SELECT MIN(id) FROM reuniones')->fetchColumn();
agenda_mutar($pdo,$leader,'reuniones','reprogramar',['id'=>$id,'plan_mes'=>'2026-02','plan_fecha'=>'2026-02-10','motivo'=>'Cambio de agenda']);
$r=agenda_fila($pdo,'reuniones',$id);$h=json_decode($r['reprogramaciones'],true);
verificar($r['plan_fecha']==='2026-02-10'&&$h[0]['de']==='2026-01-31'&&$h[0]['por']==='Líder','Reprogramación conserva historial y autor');
agenda_mutar($pdo,$leader,'reuniones','guardar_plan',['id'=>$id,'plan_temas'=>'Plan original','responsable'=>'Líder']);
verificar(agenda_fila($pdo,'reuniones',$id)['responsable']==='Líder','Edición de temas y responsable sin cambiar fechas');
agenda_mutar($pdo,$u,'reuniones','guardar_reunion',['id'=>$id,'comite_id'=>'prueba','fecha'=>'2026-02-11','realizada'=>true,'temas'=>'Resultados','asistentes'=>[$cid],'acuerdos'=>[['acuerdo'=>'Acción de prueba','plazo'=>'2026-03-01']]]);
$r=agenda_fila($pdo,'reuniones',$id);
verificar($r['plan_fecha']==='2026-02-10'&&$r['plan_temas']==='Plan original'&&$r['fecha']==='2026-02-11','Ejecución conserva planificación');
verificar((int)$pdo->query('SELECT COUNT(*) FROM acuerdos')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM reunion_asistentes')->fetchColumn()===1,'Asistentes y próximos pasos vinculados');
rechaza(fn()=>agenda_mutar($pdo,$u,'reuniones','cancelar',['id'=>$id,'motivo'=>'Prueba']),'No cancela reuniones realizadas');
agenda_mutar($pdo,$u,'reuniones','cancelar',['id'=>$id+1,'motivo'=>'No se realizará']);
verificar(agenda_estado(agenda_fila($pdo,'reuniones',$id+1))==='cancelada','Cancelación prevalece sobre vencimiento');
agenda_mutar($pdo,$u,'eventos','fijos',['anio'=>2026]);agenda_mutar($pdo,$u,'eventos','fijos',['anio'=>2026]);
verificar((int)$pdo->query('SELECT COUNT(*) FROM eventos_transferencia')->fetchColumn()===4,'Cuatro eventos fijos sin duplicación');
$fixed=(int)$pdo->query('SELECT MIN(id) FROM eventos_transferencia')->fetchColumn();
rechaza(fn()=>agenda_mutar($pdo,$u,'eventos','eliminar',['id'=>$fixed]),'Evento fijo protegido de eliminación');
agenda_mutar($pdo,$u,'eventos','guardar_evento',['nombre'=>'Evento de prueba','plan_mes'=>'2026-06','modalidad'=>'Híbrido']);
$eid=(int)$pdo->query("SELECT id FROM eventos_transferencia WHERE nombre='Evento de prueba'")->fetchColumn();
agenda_mutar($pdo,$u,'eventos','ejecutar_evento',['id'=>$eid,'fecha'=>'2026-06-15','participantes'=>2,'ingenios'=>1]);
$filas=[['Nombre completo'=>'Líder prueba','Correo'=>'lider@example.invalid'],['Nombre completo'=>'Contacto nuevo','Correo'=>'nuevo@example.invalid','Cargo'=>'Jefe de Riego','Empresa / ingenio'=>'Ingenio Prueba']];
agenda_mutar($pdo,$u,'eventos','asistentes_cargar',['id'=>$eid,'filas'=>$filas]);agenda_mutar($pdo,$u,'eventos','asistentes_cargar',['id'=>$eid,'filas'=>$filas]);
verificar((int)$pdo->query('SELECT COUNT(*) FROM evento_asistentes')->fetchColumn()===2,'Asistentes importados sin duplicados');
$aid=(int)$pdo->query("SELECT id FROM evento_asistentes WHERE estado='nuevo'")->fetchColumn();
verificar((int)$pdo->query('SELECT COUNT(*) FROM contactos')->fetchColumn()===1,'Contactos nuevos requieren revisión');
agenda_mutar($pdo,$u,'eventos','integrar',['id'=>$eid,'ids'=>[$aid],'comite_id'=>'prueba']);
$nuevo=$pdo->query("SELECT * FROM contactos WHERE email='nuevo@example.invalid'")->fetch();
verificar($nuevo['origen_tipo']==='evento'&&$nuevo['area']==='Agrícola','Integración registra origen y clasifica contacto');
$dir=directorio_datos($pdo,$u);$c=array_values(array_filter($dir['contactos'],fn($c)=>$c['id']==$nuevo['id']))[0];
verificar(count(array_filter($c['historial'],fn($h)=>$h['tipo']==='evento'))===1,'Trazabilidad incluye asistencia a evento realizado');
$tmp=tempnam(sys_get_temp_dir(),'agenda-test-');
try{
    file_put_contents($tmp,"Calificación (1 a 5),Comentario\n5,Excelente\n4,Bien\n9,Inválida\n");
    $file=['name'=>'encuesta.csv','error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp,'size'=>filesize($tmp)];
    agenda_mutar($pdo,$u,'eventos','encuesta_cargar',['id'=>$eid],['lista'=>$file]);
    verificar((int)$pdo->query('SELECT COUNT(*) FROM evento_encuestas')->fetchColumn()===2,'Plantilla de encuesta funciona y rechaza valores fuera de rango');
    file_put_contents($tmp,"Comité,Mes,Fecha tentativa,Temas a revisar,Responsable\nOtro comité,Octubre,2026-10-15,Tema,Prueba\n");clearstatcache();$file['size']=filesize($tmp);
    agenda_mutar($pdo,$u,'reuniones','importar_plan',['anio'=>2026],['lista'=>$file]);
    verificar($pdo->query("SELECT plan_fecha FROM reuniones WHERE comite_id='otro'")->fetchColumn()==='2026-10-15','Importación de planificación con mes en español');
}finally{unlink($tmp);}
$datos=agenda_datos($pdo,$u,'eventos');$ev=array_values(array_filter($datos['registros'],fn($r)=>$r['id']===$eid))[0];
verificar(count($ev['encuesta'])===2&&$ev['estado']==='realizada','Datos de ejecución y evaluación disponibles');
agenda_mutar($pdo,$u,'eventos','eliminar',['id'=>$eid]);
verificar((int)$pdo->query('SELECT COUNT(*) FROM evento_asistentes')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM evento_encuestas')->fetchColumn()===0,'Eliminar evento limpia sus relaciones');
agenda_mutar($pdo,$u,'reuniones','eliminar',['id'=>$id]);
verificar((int)$pdo->query('SELECT COUNT(*) FROM acuerdos')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM reunion_asistentes')->fetchColumn()===0,'Eliminar reunión limpia próximos pasos y asistentes');
echo "Pruebas de agenda completadas; datos de prueba solo en tablas temporales.\n";
