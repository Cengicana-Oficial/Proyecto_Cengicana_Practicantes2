<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$name=$argv[1] ?? '';if(!preg_match('/^Prueba HTTP memorias [a-f0-9]{32}$/',$name))throw new RuntimeException('Identificador de prueba no válido');
require dirname(__DIR__).'/config/database.php';require dirname(__DIR__).'/includes/memorias.php';
$pdo->beginTransaction();$files=[];
try{
    $q=$pdo->prepare('SELECT id,expediente FROM memorias_resultados WHERE titulo=?');$q->execute([$name]);
    foreach($q->fetchAll() as $r){foreach(json_decode((string)$r['expediente'],true) ?: [] as $log)foreach($log['archivos'] ?? [] as $a)$files[]=$a['ruta'];foreach(['memoria_track'=>'memoria_id','memoria_avisos'=>'articulo_id','memorias_resultados'=>'id'] as $t=>$col)$pdo->prepare("DELETE FROM $t WHERE $col=?")->execute([$r['id']]);}
    // El correo exacto y el nombre único limitan la limpieza a este fixture.
    $q=$pdo->prepare('SELECT id,email FROM contactos WHERE nombre=?');$q->execute([$name]);foreach($q->fetchAll() as $c){if(!str_ends_with($c['email'],'@example.invalid'))throw new RuntimeException('Contacto fuera de prueba');$pdo->prepare('DELETE FROM comite_editorial WHERE contacto_id=?')->execute([$c['id']]);$pdo->prepare('DELETE FROM contacto_comite WHERE contacto_id=?')->execute([$c['id']]);$pdo->prepare('DELETE FROM contactos WHERE id=?')->execute([$c['id']]);$pdo->prepare('DELETE FROM memoria_avisos WHERE email=?')->execute([$c['email']]);}
    $pdo->commit();
}catch(Throwable $e){$pdo->rollBack();throw $e;}
foreach(array_unique($files) as $f){$path=mem_ruta($f);if($path)unlink($path);}
echo "Fixtures y archivos HTTP eliminados.\n";
