<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/config/database.php';
require dirname(__DIR__).'/includes/functions.php';
require dirname(__DIR__).'/includes/domain.php';
require dirname(__DIR__).'/includes/catalogo.php';
require dirname(__DIR__).'/includes/directorio.php';
require dirname(__DIR__).'/includes/directorio-exportar.php';
directorio_preparar($pdo);
$tablas=['comites','contactos','contacto_comite','contacto_segmentos','reuniones','reunion_asistentes','acuerdos','correos_enviados','memorias_resultados','comite_editorial'];
if(app_table_exists($pdo,'evento_asistentes'))array_push($tablas,'evento_asistentes','eventos_transferencia');
foreach ($tablas as $tabla) {
    $pdo->exec("CREATE TEMPORARY TABLE prueba_{$tabla} LIKE {$tabla}");
    $pdo->exec("CREATE TEMPORARY TABLE {$tabla} LIKE prueba_{$tabla}");
}
function verificar(bool $ok,string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); echo "OK: {$mensaje}\n"; }
$user=['id'=>999999,'nombre'=>'Prueba local','es_superadmin'=>1];
$_SESSION['directorio_csrf']='prueba';
$pdo->exec("INSERT INTO comites(id,nombre) VALUES ('prueba','Comité de prueba')");
$base=['nombre'=>'Contacto Prueba','cargo'=>'Jefe de Riego','email'=>'prueba@example.invalid','comites'=>['prueba']];
directorio_mutar($pdo,$user,'guardar',$base,[]);
$c=$pdo->query('SELECT * FROM contactos')->fetch(); $id=(int)$c['id'];
verificar($c['area']==='Agrícola' && $c['nivel']==='Jefatura','Clasificación por cargo');
verificar($c['origen_tipo']==='manual' && $c['origen_por']==='Prueba local','Origen y autor de alta registrados');
try { directorio_mutar($pdo,$user,'guardar',$base+['id'=>0],[]); throw new LogicException('Aceptó duplicado'); } catch (RuntimeException $e) { verificar(!$pdo->inTransaction(),'Duplicados rechazados y transacción revertida'); }
$pdo->prepare("UPDATE contactos SET origen_tipo='curso',origen_detalle='Curso original' WHERE id=?")->execute([$id]);
directorio_mutar($pdo,$user,'guardar',array_merge($base,['id'=>$id,'area'=>'Investigación','nivel'=>'Técnico']),[]);
$c=$pdo->query('SELECT * FROM contactos')->fetch();
verificar($c['origen_tipo']==='curso' && $c['origen_detalle']==='Curso original','Editar conserva el origen');
directorio_mutar($pdo,$user,'clasificar',[],[]);
verificar($pdo->query('SELECT area FROM contactos')->fetchColumn()==='Investigación','Clasificación automática conserva valores asignados');
directorio_mutar($pdo,$user,'masivo',['campo'=>'nivel','valor'=>'Gerencia','ids'=>[$id]],[]);
verificar($pdo->query('SELECT nivel FROM contactos')->fetchColumn()==='Gerencia','Asignación masiva');
directorio_mutar($pdo,$user,'estado',['id'=>$id],[]);
verificar($pdo->query('SELECT estado FROM contactos')->fetchColumn()==='Inactivo','Cambio de estado');
directorio_mutar($pdo,$user,'segmento_guardar',['nombre'=>'Investigación','filtros'=>['area'=>'Investigación','estado'=>'todos']],[]);
$sid=(int)$pdo->lastInsertId();
directorio_mutar($pdo,['id'=>123,'nombre'=>'Otro'],'segmento_eliminar',['id'=>$sid],[]);
verificar((int)$pdo->query('SELECT COUNT(*) FROM contacto_segmentos')->fetchColumn()===1,'Segmentos privados por usuario');
$pdo->exec("INSERT INTO reuniones(comite_id,fecha,realizada,temas) VALUES ('prueba','2026-01-01',1,'Tema')");
$rid=$pdo->lastInsertId(); $pdo->prepare('INSERT INTO reunion_asistentes VALUES (?,?)')->execute([$rid,$id]);
$pdo->exec("INSERT INTO correos_enviados(asunto,destinatarios,enviado_en) VALUES ('Correo de prueba','[\"prueba@example.invalid\"]','2026-02-01')");
$pdo->exec("INSERT INTO acuerdos(comite_id,fecha,acuerdo,responsable) VALUES ('prueba','2026-03-01','Paso','Contacto Prueba')");
$pdo->exec("INSERT INTO memorias_resultados(titulo,autor,autor_email,fecha) VALUES ('Artículo','Contacto Prueba','prueba@example.invalid','2026-04-01')");
$datos=directorio_datos($pdo,$user); $hist=$datos['contactos'][0]['historial'];
verificar(count($hist)===5,'Trazabilidad integra origen, reunión, correo, paso y memoria sin duplicar autor');
verificar($datos['contactos'][0]['ultima']['tipo']==='memoria','Última interacción ordenada por fecha');
$tmp=tempnam(sys_get_temp_dir(),'dir-test-');
try {
    file_put_contents($tmp,"Nombre completo,Cargo,Empresa / ingenio,Área,Nivel,Teléfono,Correo,Comités,Estado\nImportado,Jefe de Campo,Empresa,,,,importado@example.invalid,Comité de prueba,Activo\nRepetido,,,,,,prueba@example.invalid,,Activo\nInválido,,,,,,correo-invalido,,Activo\n");
    $file=['name'=>'prueba.csv','error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp,'size'=>filesize($tmp)];
    $msg=directorio_mutar($pdo,$user,'importar',['origen_tipo'=>'curso','origen_detalle'=>'Curso de prueba'],['lista'=>$file]);
    verificar(str_contains($msg,'1 contactos incorporados')&&str_contains($msg,'1 correos existentes')&&str_contains($msg,'1 filas inválidas'),'Importación informa nuevos, duplicados e inválidos');
    $importado=$pdo->query("SELECT * FROM contactos WHERE email='importado@example.invalid'")->fetch();
    verificar($importado['origen_tipo']==='curso' && $importado['area']==='Agrícola','Importación conserva origen y clasifica');
    $xlsx=directorio_excel([['nombre'=>'Contactos','filas'=>[['Nombre completo','Correo','Área','Nivel'],['=CONTACTO','excel@example.invalid','Laboratorio','Técnico']]]]);
    file_put_contents($tmp,$xlsx); clearstatcache(); $file['name']='prueba.xlsx'; $file['size']=filesize($tmp);
    directorio_mutar($pdo,$user,'importar',['origen_tipo'=>'seminario','origen_detalle'=>'Seminario de prueba'],['lista'=>$file]);
    verificar((int)$pdo->query("SELECT COUNT(*) FROM contactos WHERE email='excel@example.invalid'")->fetchColumn()===1,'Importación XLSX real');
    $reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx'); $book=$reader->load($tmp);
    verificar($book->getActiveSheet()->getCell('A2')->getDataType()==='s','Excel exporta valores como texto, sin fórmulas'); $book->disconnectWorksheets();
    $datos=directorio_datos($pdo,$user); $reporte=directorio_reporte($datos['contactos'],['titulo'=>'<script>alert(1)</script>','agrupar'=>'area'],['prueba'=>'Comité de prueba']);
    verificar(!str_contains($reporte,'<script>')&&str_contains($reporte,'&lt;script&gt;'),'Reporte escapa datos y admite agrupación');
    verificar(str_starts_with(directorio_pdf($reporte),'%PDF-'),'PDF válido generado');
} finally { unlink($tmp); }
verificar(!can_manage_resource(['es_superadmin'=>0,'permissions'=>['comites.contactos.ver']],'contactos'),'Permiso de lectura no concede escritura');
echo "Pruebas completadas en tablas temporales; no se enviaron correos.\n";
