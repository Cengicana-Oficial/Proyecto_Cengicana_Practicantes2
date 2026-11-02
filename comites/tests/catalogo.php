<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/catalogo.php';
catalogo_preparar($pdo);
// Tablas temporales por conexión: nunca escribe registros de prueba en las tablas reales.
foreach (['comites','contactos','contacto_comite','reuniones','acuerdos','documentos'] as $tabla) {
    $pdo->exec("CREATE TEMPORARY TABLE prueba_{$tabla} LIKE {$tabla}");
    $pdo->exec("CREATE TEMPORARY TABLE {$tabla} LIKE prueba_{$tabla}");
}
function comprobar(bool $ok, string $mensaje): void {
    if (!$ok) { throw new RuntimeException($mensaje); }
    echo "OK: {$mensaje}\n";
}
function rechazar(PDO $pdo, array $post, string $mensaje): void {
    try { catalogo_accion($pdo,$post,[]); }
    catch (RuntimeException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); comprobar(true,$mensaje); return; }
    throw new RuntimeException('No rechazó: '.$mensaje);
}
$lider = catalogo_contacto($pdo,['nombre'=>'Líder de prueba','email'=>'lider@example.invalid']);
catalogo_accion($pdo,['accion'=>'grupo_guardar','nombre'=>'Comité de prueba','periodicidad'=>'Mensual','estado'=>'Activo','lider_id'=>$lider],[]);
$grupo = $pdo->query('SELECT * FROM comites')->fetch();
$id = $grupo['id'];
comprobar((int)$grupo['lider_id']===$lider,'Crear comité con líder');
comprobar((int)$pdo->query('SELECT COUNT(*) FROM contacto_comite')->fetchColumn()===1,'El líder se agrega como integrante');
rechazar($pdo,['accion'=>'grupo_guardar','nombre'=>'Comité de prueba'],'Rechazar nombre duplicado');
catalogo_accion($pdo,['accion'=>'grupo_integrante_nuevo','id'=>$id,'nombre'=>'Otro nombre','email'=>'lider@example.invalid'],[]);
comprobar((int)$pdo->query('SELECT COUNT(*) FROM contactos')->fetchColumn()===1,'Correo existente sin duplicar contacto');
catalogo_accion($pdo,['accion'=>'grupo_integrante_nuevo','id'=>$id,'nombre'=>'Integrante','email'=>'integrante@example.invalid','area'=>'Campo'],[]);
comprobar((int)$pdo->query('SELECT COUNT(*) FROM contacto_comite')->fetchColumn()===2,'Registrar y vincular nuevo integrante');
catalogo_accion($pdo,['accion'=>'grupo_guardar','id'=>$id,'nombre'=>'Comité actualizado','descripcion'=>'Objetivo','periodicidad'=>'Trimestral','estado'=>'Activo','lider_id'=>$lider],[]);
comprobar(catalogo_fila($pdo,$id)['periodicidad']==='Trimestral','Editar metadatos conservando identificador');
catalogo_accion($pdo,['accion'=>'grupo_estado','id'=>$id],[]);
comprobar((int)catalogo_fila($pdo,$id)['activo']===0,'Inactivar comité');
catalogo_accion($pdo,['accion'=>'grupo_estado','id'=>$id],[]);
comprobar((int)catalogo_fila($pdo,$id)['activo']===1,'Reactivar comité');
$pdo->prepare('INSERT INTO reuniones(comite_id,fecha) VALUES (?,CURRENT_DATE)')->execute([$id]);
rechazar($pdo,['accion'=>'grupo_eliminar','id'=>$id],'Conservar comité con reuniones asociadas');
catalogo_accion($pdo,['accion'=>'grupo_integrante_quitar','id'=>$id,'contacto_id'=>$lider],[]);
comprobar(catalogo_fila($pdo,$id)['lider_id']===null,'Quitar líder limpia la asignación');
$archivo = tempnam(sys_get_temp_dir(),'catalogo-test-');
try {
    file_put_contents($archivo,"Nombre,Correo,Cargo,Empresa,Área,Teléfono\nImportado,importado@example.invalid,Técnico,Empresa,Campo,123\n");
    $file = ['error'=>UPLOAD_ERR_OK,'size'=>filesize($archivo),'name'=>'lista.csv','tmp_name'=>$archivo];
    catalogo_accion($pdo,['accion'=>'grupo_integrante_importar','id'=>$id],['lista'=>$file]);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM contactos WHERE email='importado@example.invalid'")->fetchColumn()===1,'Importación CSV persistente');
    $zip = new ZipArchive();
    $zip->open($archivo,ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Nombre</t></is></c><c r="B1" t="inlineStr"><is><t>Correo</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Desde Excel</t></is></c><c r="B2" t="inlineStr"><is><t>excel@example.invalid</t></is></c></row></sheetData></worksheet>');
    $zip->close();
    clearstatcache();
    $file['name']='lista.xlsx'; $file['size']=filesize($archivo);
    catalogo_accion($pdo,['accion'=>'grupo_integrante_importar','id'=>$id],['lista'=>$file]);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM contactos WHERE email='excel@example.invalid'")->fetchColumn()===1,'Importación XLSX persistente');
} finally { unlink($archivo); }
$pdo->prepare('DELETE FROM reuniones WHERE comite_id=?')->execute([$id]);
catalogo_accion($pdo,['accion'=>'grupo_eliminar','id'=>$id],[]);
comprobar((int)$pdo->query('SELECT COUNT(*) FROM comites')->fetchColumn()===0,'Eliminar comité sin historial');
echo "Pruebas completadas; tablas temporales descartadas al cerrar la conexión.\n";
