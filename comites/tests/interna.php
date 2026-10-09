<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/database.php';
require dirname(__DIR__).'/includes/functions.php';
require dirname(__DIR__).'/includes/domain.php';
require dirname(__DIR__).'/includes/catalogo.php';
require dirname(__DIR__).'/includes/directorio.php';
require dirname(__DIR__).'/includes/biblioteca.php';
require dirname(__DIR__).'/includes/biblioteca-interna.php';
interna_preparar($pdo);
foreach(['documentos','documento_historial','documento_carpetas','correos_enviados'] as $t){$pdo->exec("CREATE TEMPORARY TABLE prueba_{$t} LIKE {$t}");$pdo->exec("CREATE TEMPORARY TABLE {$t} LIKE prueba_{$t}");}
function verificar(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "OK: {$s}\n";}
function rechaza(callable $fn,string $s):void{try{$fn();}catch(RuntimeException $e){verificar(true,$s);return;}throw new LogicException($s);}
$_SESSION['interna_csrf']='prueba';
$admin=['id'=>901,'nombre'=>'Administración','nombre_rol'=>'Administrador','permissions'=>['comites.biblioteca_interna.gestionar']];
$reader=['id'=>902,'nombre'=>'Consulta','permissions'=>['comites.biblioteca_interna.ver']];
$manager=['id'=>903,'nombre'=>'Otro','nombre_rol'=>'Comunicación','permissions'=>['comites.biblioteca_interna.gestionar']];
$internal=comites_carpeta_crear($pdo,'interna','Interna',null);$external=comites_carpeta_crear($pdo,'ingenios','Ingenios',null);
$pdo->prepare("INSERT INTO documentos(ambito,carpeta_id,titulo,flujo,estado,habilitado_envio) VALUES ('ingenios',?,'Aprobado externo','aprobado','Aprobado',1)")->execute([$external]);$externalDoc=(int)$pdo->lastInsertId();$before=$pdo->query('SELECT * FROM documentos WHERE id='.$externalDoc)->fetch();
$base=['carpeta_id'=>$internal,'titulo'=>'Memoria interna','tipo'=>'Memoria','fecha'=>'2026-09-01','autor'=>'Prueba','enlace'=>'https://example.invalid/memoria','requiere_aprobacion'=>true,'enviar_ahora'=>true,'habilitado_envio'=>1];
rechaza(fn()=>interna_mutar($pdo,$reader,'guardar',$base),'Consulta no permite cargas');
rechaza(fn()=>interna_mutar($pdo,$manager,'guardar',$base),'Carga reservada al Administrador como en la plantilla');
interna_mutar($pdo,$admin,'guardar',$base);$id=(int)$pdo->query('SELECT MAX(id) FROM documentos')->fetchColumn();$r=interna_fila($pdo,$id);
verificar($r['flujo']==='informativo'&&(int)$r['requiere_aprobacion']===0&&(int)$r['habilitado_envio']===0,'Documento interno sin revisión ni envío aunque se manipule la solicitud');
$revision=biblioteca_revision($pdo,$r);
interna_mutar($pdo,$admin,'guardar',array_merge($base,['id'=>$id,'titulo'=>'Título actualizado']));verificar((int)interna_fila($pdo,$id)['version']===1,'Editar metadatos conserva versión');
interna_mutar($pdo,$admin,'guardar',array_merge($base,['id'=>$id,'enlace'=>'https://example.invalid/v2']));verificar((int)interna_fila($pdo,$id)['version']===2,'Cambiar contenido aumenta versión');
rechaza(fn()=>interna_mutar($pdo,$admin,'guardar',$base+['id'=>$id,'revision'=>$revision]),'Formulario desactualizado rechazado');
foreach(['revisar','correo','enviar_revision'] as $accion)rechaza(fn()=>interna_mutar($pdo,$admin,$accion,['id'=>$id]),'Acción no permitida: '.$accion);
rechaza(fn()=>interna_mutar($pdo,$admin,'guardar',array_merge($base,['carpeta_id'=>$external])),'No permite cargar en carpeta de ingenios');
rechaza(fn()=>interna_mutar($pdo,$admin,'eliminar',['id'=>$externalDoc]),'No permite eliminar documentos de ingenios');
rechaza(fn()=>interna_mutar($pdo,$admin,'guardar',array_merge($base,['enlace'=>'javascript:alert(1)'])),'Enlace no seguro rechazado');
interna_mutar($pdo,$admin,'carpeta',['nombre'=>'Subcarpeta','parent_id'=>$internal]);
rechaza(fn()=>interna_mutar($pdo,$admin,'carpeta',['nombre'=>'Subcarpeta','parent_id'=>$internal]),'Carpeta duplicada rechazada');
$datos=interna_datos($pdo,$admin);verificar(count($datos['documentos'])===1&&count($datos['carpetas'])===2&&$datos['interna'],'Datos limitados a la biblioteca interna');
verificar(!interna_datos($pdo,$reader)['gestionar'],'Vista de consulta sin controles de administración');
verificar($pdo->query('SELECT * FROM documentos WHERE id='.$externalDoc)->fetch()===$before,'Biblioteca para ingenios conservada');
verificar((int)$pdo->query('SELECT COUNT(*) FROM documento_historial WHERE documento_id='.$id)->fetchColumn()===3,'Se conserva registro de cambios y versiones');
interna_mutar($pdo,$admin,'eliminar',['id'=>$id]);verificar((int)$pdo->query("SELECT COUNT(*) FROM documentos WHERE ambito='interna'")->fetchColumn()===0,'Eliminar documento interno');
echo "Pruebas completadas con tablas temporales; no se enviaron correos.\n";
