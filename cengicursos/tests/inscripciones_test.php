<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../inscripcion_registro.php';
require_once __DIR__.'/../administracion_nav.php';
require_once __DIR__.'/../proforma_revision_helpers.php';
require_once __DIR__.'/../interesado_pago_helpers.php';
function cengi_require_gestor_solicitudes($url) {}
function cengi_ve_todo_por_rol_o_ingenio() { return true; }
function cengi_es_admin() { return true; }
function cengi_puede_aprobar_solicitudes() { return true; }
function cengi_puede_ver_solicitudes() { return true; }
function menu_render() {}
function check_ins($v,$msg) { if (!$v) throw new RuntimeException($msg); }
$navHtml=cengi_ins_pago_html('<html><a href="pagos_inscripcion.php?plan=7&amp;tab=grupos">Pagos</a><form method="get"></form><script>var endpoint="pagos_inscripcion.php?dato=1";</script></html>');
check_ins(strpos($navHtml,'href="inscripciones.php?vista=pagos&amp;plan=7&amp;tab=grupos"')!==false,'Pestañas conservan destino y contexto.');
check_ins(strpos($navHtml,'name="vista" value="pagos"')!==false,'Filtros GET conservan la vista.');
check_ins(strpos($navHtml,'var endpoint="pagos_inscripcion.php?dato=1"')!==false,'No altera scripts.');
check_ins(cengi_ins_pago_html('PK archivos binarios')==='PK archivos binarios','No altera descargas binarias.');
$env=[];
foreach(file(__DIR__.'/../../deploy/env/mysql.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
 if(strpos(trim($line),'#')===0 || strpos($line,'=')===false) continue;
 [$key,$value]=explode('=',$line,2); $env[trim($key)]=trim($value," \t\n\r\0\x0B\"'");
}
$db = new PDO('mysql:host=127.0.0.1;port=33063;charset=utf8mb4','root',$env['MYSQL_ROOT_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$source = 'cengi_cursos';
$name = 'codex_ins_test_'.bin2hex(random_bytes(6));
try {
    $db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $db->exec("USE `$name`");
    foreach(['participantes','cursos','asignaciones','solicitudes_inscripcion','planificacion_cursos','pagos_grupales','ingenios','categorias_cursos','instructores','proformas','proforma_lineas','proforma_historial','eventos_config'] as $t) $db->exec("CREATE TABLE `$t` LIKE `$source`.`$t`");
    $db->exec(file_get_contents(__DIR__.'/../migrations/20261023_inscripciones_seguimiento.sql'));
    $db->exec(file_get_contents(__DIR__.'/../migrations/20261024_proforma_versiones.sql'));
    $db->exec(file_get_contents(__DIR__.'/../migrations/20261025_pago_validacion.sql'));
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    $db->exec("INSERT INTO ingenios(id,nombre_ingenios) VALUES(1,'Prueba'); INSERT INTO participantes(id,ingenio_id,cui_participantes,nombre_participantes) VALUES(1,1,'123','Estudiante');");
    $db->exec("INSERT INTO cursos(id,categoria_curso_id,ingenio_id,nombre_cursos) VALUES(1,1,1,'Curso A'),(2,1,1,'Curso B'),(3,1,1,'Histórico')");
    $db->beginTransaction();
    $a=cengi_ins_registrar_persona($db,1,1);$again=cengi_ins_registrar_persona($db,1,1);$b=cengi_ins_registrar_persona($db,1,2,'Propio');
    check_ins($a===$again && $a!==$b,'Un registro por curso, sin duplicar al repetir.');
    $s=cengi_ins_solicitud($db,$a);
    check_ins($s['monto']===null && $s['pago_estado']==='pendiente','Tarifa desconocida no se inventa como gratuita.');
    check_ins(!cengi_ins_requisito($s)['ok'],'Patrocinio pendiente bloquea asignación.');
    try { cengi_ins_asignar($db,$s,'Prueba'); throw new RuntimeException('Asignación sin validar aceptada'); } catch(InvalidArgumentException $e) {}
    $db->commit();
    $db->exec("INSERT INTO asignaciones(participantes_id,cursos_id) VALUES(1,3)");
    $sql=file_get_contents(__DIR__.'/../migrations/20261021_inscripciones_por_curso.sql');$db->exec($sql);$db->exec($sql);
    check_ins((int)$db->query('SELECT COUNT(*) FROM solicitudes_inscripcion')->fetchColumn()===3,'Conciliación idempotente.');
    $h=$db->query('SELECT * FROM solicitudes_inscripcion WHERE curso_id=3')->fetch(PDO::FETCH_ASSOC);
    check_ins($h['estado']==='Aprobado' && $h['pago_estado']==='pendiente','Conserva acceso histórico sin inventar pago.');
    $db->exec("UPDATE solicitudes_inscripcion SET pago_estado='pagado' WHERE id_solicitud=$b");
    check_ins(cengi_ins_requisito(cengi_ins_solicitud($db,$b))['ok'],'Pago propio validado habilita asignación.');
    check_ins(!cengi_ins_requisito(cengi_ins_solicitud($db,$a))['ok'],'Pago de un curso no habilita otro.');
    $_SESSION=[]; $_GET=['id'=>$a]; $_SERVER['REQUEST_METHOD']='GET';
    $sourcePage=file_get_contents(__DIR__.'/../inscripcion_detalle.php');
    $sourcePage=str_replace("require_once __DIR__ . '/menu.php';",'', $sourcePage);
    $sourcePage=str_replace('$db = conectar();','', $sourcePage);
    $sourcePage=str_replace('__DIR__',var_export(realpath(__DIR__.'/..'),true),$sourcePage);
    ob_start(); try { eval('?>'.$sourcePage); $html=ob_get_contents(); } finally { ob_end_clean(); }
    check_ins(strpos($html,'Preparar proforma y pago del grupo')!==false && strpos($html,'Validar patrocinio')!==false,'Ficha dirige el pago del ingenio a proforma.');
    check_ins(strpos($html,'value="pagado"')===false,'No admite cobro individual del ingenio.');
    check_ins(strpos($html,'value="asignar"')===false,'No ofrece asignación antes de validar.');
    $db->exec("INSERT INTO cursos(id,categoria_curso_id,ingenio_id,nombre_cursos) VALUES(4,1,1,'Convocatoria');INSERT INTO planificacion_cursos(id,anio,nombre,fecha_inicio,fecha_fin,hora_inicio,hora_fin,costo_asociados,estado,curso_id) VALUES(1,2026,'Convocatoria','2026-12-01','2026-12-03','08:00','17:00',100,'aprobado',4)");
    $db->exec("INSERT INTO solicitudes_inscripcion(nombre_participante,cui_participante,correo,ingenio_id,curso_id,tipo_pago) VALUES('Carga A','222','a@example.test',1,4,'Ingenio'),('Carga B','333','b@example.test',1,4,'Ingenio')");
    $ids=$db->query('SELECT id_solicitud FROM solicitudes_inscripcion WHERE curso_id=4')->fetchAll(PDO::FETCH_COLUMN);
    $db->beginTransaction();foreach($ids as $sid)cengi_ins_normalizar_ingreso($db,$sid,'carga_formulario');$db->commit();
    $s1=cengi_ins_solicitud($db,$ids[0]);$s2=cengi_ins_solicitud($db,$ids[1]);
    check_ins((int)$s1['planificacion_id']===1 && (float)$s1['monto']===100.0 && $s1['origen_inscripcion']==='carga_formulario','La carga vincula convocatoria, cuota y origen.');
    check_ins($s1['grupo_pago_id']===$s2['grupo_pago_id'] && !cengi_ins_requisito($s1)['ok'],'Carga agrupada espera validación.');
    $db->beginTransaction();
    $pfid=cengi_pf_emitir($db,['tipo_cliente'=>'asociado','fecha'=>'2026-10-12','ingenio_id'=>1,'cliente'=>['dirigido'=>'Prueba','contacto'=>'Prueba','correo'=>'test@example.test','nit'=>'CF','pais'=>'Guatemala'],'obs'=>'Prueba','lineas'=>[['plan'=>cengi_pp_plan($db,1),'inscritos'=>$ids,'cupos'=>0,'desc_tipo'=>'monto','desc_valor'=>0]]]);
    $db->commit();$pf=cengi_pf_cargar($db,$pfid);
    check_ins((float)$pf['total']===200.0 && count($pf['lineas'][0]['inscritos'])===2,'Proforma agrupa dos participantes con importe completo.');
    $datos=['motivo'=>'Ajuste de precio acordado','vence'=>'2026-12-12','dirigido'=>'Prueba','correo'=>'test@example.test','obs'=>'Actualización','lineas'=>[$pf['lineas'][0]['id']=>['cantidad'=>2,'precio'=>120,'descuento'=>10]]];
    $db->beginTransaction();cengi_pf_actualizar($db,$pf,$datos);$db->commit();$pf=cengi_pf_cargar($db,$pfid);
    check_ins((float)$pf['total']===220.0&&(int)$db->query('SELECT COUNT(*) FROM proforma_versiones')->fetchColumn()===2,'Revisión conserva antes y después y actualiza grupos.');
    check_ins((float)cengi_ins_solicitud($db,$ids[0])['monto']===110.0,'Cuota vinculada sigue la revisión.');
    $db->prepare("UPDATE solicitudes_inscripcion SET validacion_ingenio='validada' WHERE id_solicitud=?")->execute([$ids[0]]);
    $beforePaid=cengi_ins_solicitud($db,$ids[0]);
    check_ins(cengi_ins_admitida($beforePaid)&&cengi_ins_estado_cobro($beforePaid)!=='pagado','Autorización del ingenio inscribe y conserva cobro pendiente.');
    $db->prepare("UPDATE solicitudes_inscripcion SET validacion_ingenio='rechazada' WHERE id_solicitud=?")->execute([$ids[1]]);
    $db->beginTransaction();cengi_pf_registrar_pago($db,$pf,'Transferencia','TEST-001','2026-10-12');$db->commit();
    $s1=cengi_ins_solicitud($db,$ids[0]);$s2=cengi_ins_solicitud($db,$ids[1]);
    check_ins(cengi_ins_estado_cobro($s1)==='pagado'&&cengi_ins_requisito($s1)['ok'],'Pago de proforma liquida grupo y valida pendiente.');
    check_ins(cengi_ins_admitida($s1),'Autorización y pago habilitan inscripción.');
    check_ins($s2['validacion_ingenio']==='rechazada'&&!cengi_ins_requisito($s2)['ok'],'Pago no reemplaza rechazo explícito del ingenio.');
    try{cengi_pf_actualizar($db,cengi_pf_cargar($db,$pfid),$datos);throw new RuntimeException('Proforma pagada editable');}catch(InvalidArgumentException $e){}
    check_ins(!cengi_ins_en_bandeja($s1,'pendientes'),'Pagados salen de pendientes.');
    try{cengi_ins_exigir_pago_propio_validado(['tipo_pago'=>'Propio','pago_estado'=>'en revisión']);throw new RuntimeException('Pago sin revisión aceptado');}catch(InvalidArgumentException $e){}
    cengi_ins_exigir_pago_propio_validado(['tipo_pago'=>'Propio','pago_estado'=>'validado']);
    $db->exec(file_get_contents(__DIR__.'/../migrations/20261023_inscripciones_seguimiento.sql'));
    check_ins(cengi_ins_estado_cobro(cengi_ins_solicitud($db,$ids[0]))==='pagado','Migración repetida conserva pagos grupales.');
    $db->exec("INSERT INTO solicitudes_inscripcion(nombre_participante,cui_participante,correo,ingenio_id,curso_id,tipo_pago,pago_estado,monto) VALUES('Pago histórico','444','old@example.test',1,4,'Ingenio','pagado',100)");$legacy=(int)$db->lastInsertId();
    $db->beginTransaction();cengi_ins_normalizar_ingreso($db,$legacy,'sin_identificar');$db->commit();
    check_ins(cengi_ins_estado_cobro(cengi_ins_solicitud($db,$legacy))==='pagado','Integración conserva pagos individuales históricos del ingenio.');
    $eligible=cengi_pf_candidatos($db,1,1);
    check_ins(!in_array($legacy,array_column($eligible[1]??[],'id_solicitud')),'No vuelve a cobrar un pago histórico.');
    $db->exec("INSERT INTO solicitudes_inscripcion(nombre_participante,cui_participante,correo,ingenio_id,curso_id,tipo_pago,pago_estado,monto) VALUES('Propio nuevo','555','pay@example.test',1,4,'Propio','pendiente',100)");$own=(int)$db->lastInsertId();$ownRow=cengi_ins_solicitud($db,$own);
    check_ins(!cengi_ins_inscrito($ownRow),'Pago propio pendiente no aparece confirmado.');
    $datosOwn=['boleta'=>'OWN-001','fecha'=>'2026-10-12','monto'=>100,'recibido'=>1,'nota'=>'Se revisó la constancia de depósito'];
    try{cengi_interesado_aceptar_pago($db,$ownRow,$datosOwn,'Coordinador',null,'capacitacion');throw new RuntimeException('Aceptó pago sin constancia');}catch(InvalidArgumentException $e){}
    $db->prepare("UPDATE solicitudes_inscripcion SET comprobante_ruta='/uploads/prueba.pdf',comprobante_nombre='prueba.pdf' WHERE id_solicitud=?")->execute([$own]);$ownRow=cengi_ins_solicitud($db,$own);
    $db->beginTransaction();cengi_interesado_aceptar_pago($db,$ownRow,$datosOwn,'Coordinador (Capacitacion)',null,'capacitacion');$db->commit();
    $ownRow=cengi_ins_solicitud($db,$own);check_ins(!cengi_ins_inscrito($ownRow)&&$ownRow['pago_estado']==='en revisión'&&$ownRow['pago_validado_rol']==='capacitacion','Capacitacion deja pendiente la verificación de Administración.');
    $db->beginTransaction();cengi_interesado_aceptar_pago($db,$ownRow,$datosOwn,'Administradora',null,'administracion');$db->commit();
    $ownRow=cengi_ins_solicitud($db,$own);check_ins(cengi_ins_inscrito($ownRow)&&$ownRow['pago_verificado_por']==='Administradora'&&$ownRow['pago_validado_por']==='Coordinador (Capacitacion)','Administración confirma sin perder al validador inicial.');
    echo "OK: ingresos y cargas, convocatoria, cuotas, duplicados, validación previa, proformas grupales y pendientes.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE IF EXISTS `$name`");
}
