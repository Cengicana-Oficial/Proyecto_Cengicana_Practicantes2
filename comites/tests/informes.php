<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/database.php';foreach(['functions','domain','catalogo','informes'] as $f)require dirname(__DIR__).'/includes/'.$f.'.php';inf_preparar($pdo);
foreach(['informes_cuatrimestrales','informe_soportes','informe_areas','informe_control','informe_poa'] as $t){$pdo->exec("CREATE TEMPORARY TABLE prueba_$t LIKE $t");$pdo->exec("CREATE TEMPORARY TABLE $t LIKE prueba_$t");}
function ok(bool $b,string $s):void{if(!$b)throw new LogicException($s);echo "OK: $s\n";}
function no(callable $f,string $s):void{try{$f();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;ok(true,$s);return;}throw new LogicException($s);}
$_SESSION['informes_csrf']='test';$admin=['id'=>901,'nombre'=>'Administración','es_superadmin'=>1];$head=['id'=>902,'nombre'=>'Responsable','nombre_rol'=>'Jefe de Área','correo'=>'head@example.invalid','permissions'=>['comites.informes.ver']];$reader=['id'=>903,'nombre'=>'Consulta','correo'=>'otro@example.invalid','permissions'=>['comites.informes.ver']];$director=['id'=>904,'nombre'=>'Director','nombre_rol'=>'Director General','permissions'=>['comites.informes.ver']];
$pdo->exec('INSERT INTO informe_control(id) VALUES(1)');$pdo->exec("INSERT INTO informe_areas(nombre,correo) VALUES('Riego','head@example.invalid'),('Variedades','otra@example.invalid')");$aid=(int)$pdo->query("SELECT id FROM informe_areas WHERE nombre='Riego'")->fetchColumn();$slot=['anio'=>2026,'cuatri'=>1,'area_id'=>$aid];
no(fn()=>inf_mutar($pdo,$reader,'guardar',$slot),'Consulta no puede cargar');
no(fn()=>inf_mutar($pdo,$head,'entregar',$slot),'Entrega exige plan e informe');
ok(!(int)$pdo->query('SELECT COUNT(*) FROM informes_cuatrimestrales')->fetchColumn(),'Entrega incompleta no deja registro parcial');
inf_mutar($pdo,$head,'guardar',$slot);$id=(int)$pdo->query('SELECT MAX(id) FROM informes_cuatrimestrales')->fetchColumn();$current=fn()=>inf_fila($pdo,$id);$values=fn()=> $slot+['revision'=>(int)$current()['revision']];
ok($current()['flujo']==='pendiente','Guardar sin entregar conserva borrador');
no(fn()=>inf_mutar($pdo,$head,'guardar',$slot+['revision'=>0]),'Formulario obsoleto rechazado');
no(fn()=>inf_mutar($pdo,$head,'guardar',$values()+['enlace'=>'javascript:alert(1)']),'Enlaces inseguros rechazados');
$pdo->prepare('UPDATE informes_cuatrimestrales SET plan_operativo_ruta=?,informe_ruta=? WHERE id=?')->execute(['../uploads/comites/informes/plan.pdf','../uploads/comites/informes/informe.pdf',$id]);
inf_mutar($pdo,$head,'entregar',$values());ok($current()['flujo']==='entregado','Entrega pasa a Dirección');
no(fn()=>inf_mutar($pdo,$head,'revisar',$values()+['estado'=>'revisado']),'Responsable no se revisa a sí mismo');
no(fn()=>inf_mutar($pdo,$director,'revisar',$values()+['estado'=>'observado']),'Revisión con pendientes exige al menos uno');
inf_mutar($pdo,$director,'revisar',$values()+['estado'=>'observado','nuevos'=>['Adjuntar evidencia','Explicar desviación'],'observaciones'=>'Revisión de resultados']);$pending=json_decode($current()['pendientes'],true);
no(fn()=>inf_mutar($pdo,$head,'guardar',$values()),'Informe observado se atiende por su flujo');
no(fn()=>inf_mutar($pdo,$reader,'atender',$values()),'Tercero no atiende pendientes');
no(fn()=>inf_mutar($pdo,$head,'atender',$values()+['respuestas'=>[$pending[0]['id']=>'Adjunto evidencia']]),'Todos los pendientes requieren respuesta');
inf_mutar($pdo,$head,'atender',$values()+['respuestas'=>[$pending[0]['id']=>'Adjunto evidencia',$pending[1]['id']=>'Se explica la desviación']]);ok($current()['flujo']==='atendido','Respuestas vuelven al Director');
inf_mutar($pdo,$director,'revisar',$values()+['estado'=>'revisado']);ok($current()['flujo']==='revisado','Dirección finaliza revisión');
no(fn()=>inf_mutar($pdo,$head,'entregar',$values()),'Informe revisado queda bloqueado');
ok(count(json_decode($current()['bitacora'],true))===5,'Bitácora conserva borrador, entrega, revisión y respuestas');
ok(inf_limite(2026,3)==='2027-01-15'&&inf_limite(2026,1)==='2026-05-15','Fechas límite coinciden con plantilla');
$rows=inf_poa_filas([['Área'=>'Riego','Cuatrimestre'=>1,'Actividad'=>'Uniformidad','Meta'=>10,'Logro'=>8,'Unidad'=>'número']]);ok($rows[0]['logro']===8.0,'Normalización de columnas POA');
no(fn()=>inf_poa_filas([['Área'=>'Riego','Cuatrimestre'=>4,'Actividad'=>'Prueba','Meta'=>10,'Logro'=>8]]),'POA rechaza cuatrimestre inválido');
no(fn()=>inf_poa_filas([['Área'=>'Riego','Cuatrimestre'=>1,'Actividad'=>'Prueba','Meta'=>10,'Logro'=>'texto']]),'POA rechaza números inválidos');
$tmp=tempnam(sys_get_temp_dir(),'poa');file_put_contents($tmp,"\xEF\xBB\xBFÁrea,Cuatrimestre,Actividad,Meta,Logro,Unidad,Evidencia\nRiego,1,Uniformidad,10,8,número,\n");$file=['tmp_name'=>$tmp,'name'=>'poa.csv','size'=>filesize($tmp),'error'=>0];
try{inf_mutar($pdo,$admin,'poa',['anio'=>2026,'revision'=>0],['poa'=>$file]);ok(count(inf_datos($pdo,$reader)['poa'][0]['filas'])===1,'CSV real con BOM se sincroniza');no(fn()=>inf_mutar($pdo,$head,'poa',['anio'=>2026,'revision'=>1],['poa'=>$file]),'Responsable no cambia fuente POA');no(fn()=>inf_mutar($pdo,$admin,'poa',['anio'=>2026,'revision'=>0],['poa'=>$file]),'Sincronización obsoleta rechazada');}finally{unlink($tmp);}
require_once dirname(__DIR__,2).'/cengicursos/vendor/autoload.php';$book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$book->getActiveSheet()->fromArray([['Área','Cuatrimestre','Actividad','Meta','Logro'],['Riego',2,'Riego eficiente',20,18]]);$tmp=tempnam(sys_get_temp_dir(),'poa');try{(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp);$rows=inf_leer_poa(['tmp_name'=>$tmp,'name'=>'poa.xlsx','size'=>filesize($tmp),'error'=>0]);ok($rows[0]['cuatrimestre']===2,'Excel XLSX real se importa sin navegador ni CDN');}finally{unlink($tmp);$book->disconnectWorksheets();}
inf_mutar($pdo,$admin,'areas',['revision'=>1,'areas'=>[['id'=>$aid,'correo'=>'head@example.invalid','activo'=>false]]]);ok(count(inf_datos($pdo,$reader)['informes'])===1,'Desactivar área conserva informe');
ok(!isset(inf_datos($pdo,$reader)['areas'][0]['correo']),'Correos de asignación solo visibles para coordinación');
no(fn()=>inf_mutar($pdo,$reader,'areas',['revision'=>2]),'Consulta no administra áreas');
$pdo->exec("INSERT INTO informes_cuatrimestrales(periodo,area,evaluacion,plan_operativo_nombre,plan_operativo_ruta,informe_nombre,informe_ruta) VALUES('II cuatrimestre 2025','Riego','En revision','Plan previo','../uploads/comites/informes/previo.pdf','Informe previo','../uploads/comites/informes/informe-previo.pdf'),('Periodo sin formato','Otra','Satisfactorio',NULL,NULL,NULL,NULL)");
$legacy=(int)$pdo->query("SELECT id FROM informes_cuatrimestrales WHERE periodo='II cuatrimestre 2025'")->fetchColumn();inf_preparar($pdo);$migrated=inf_fila($pdo,$legacy);ok((int)$migrated['anio']===2025&&(int)$migrated['cuatri']===2&&$migrated['flujo']==='entregado','Registros previos con período reconocido se integran');
inf_mutar($pdo,$admin,'guardar',['anio'=>2025,'cuatri'=>2,'area_id'=>$migrated['area_id'],'revision'=>$migrated['revision']]);$hist=json_decode(inf_fila($pdo,$legacy)['bitacora'],true);ok(count($hist[1]['archivos'])===2,'Archivos anteriores preservados al actualizar informe migrado');
ok((int)$pdo->query("SELECT COUNT(*) FROM informes_cuatrimestrales WHERE anio IS NULL AND periodo='Periodo sin formato'")->fetchColumn()===1,'Períodos desconocidos conservados sin fechas inventadas');
echo "Pruebas completadas con tablas temporales, sin modificar informes reales.\n";
