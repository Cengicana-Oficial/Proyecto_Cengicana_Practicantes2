<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$name=$argv[1] ?? '';if(!preg_match('/^Prueba HTTP informes [a-f0-9]{32}$/',$name))throw new RuntimeException('Identificador de prueba no válido');
require dirname(__DIR__).'/config/database.php';require dirname(__DIR__).'/includes/domain.php';require dirname(__DIR__).'/includes/informes.php';
$pdo->beginTransaction();$paths=[];
try{$q=$pdo->prepare('SELECT * FROM informes_cuatrimestrales WHERE area=?');$q->execute([$name]);foreach($q->fetchAll() as $r){foreach(inf_archivos($pdo,$r) as $a)$paths[]=$a['ruta'];foreach(json_decode((string)$r['bitacora'],true) ?: [] as $h)foreach($h['archivos'] ?? [] as $a)$paths[]=$a['ruta'];$pdo->prepare('DELETE FROM informe_soportes WHERE informe_id=?')->execute([$r['id']]);$pdo->prepare('DELETE FROM informes_cuatrimestrales WHERE id=?')->execute([$r['id']]);}$pdo->prepare('DELETE FROM informe_areas WHERE nombre=?')->execute([$name]);$pdo->exec('UPDATE informe_control SET revision=revision+1 WHERE id=1');$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
foreach(array_unique($paths) as $f){$p=inf_ruta($f);if($p)unlink($p);}echo "Fixtures HTTP y archivos de prueba eliminados.\n";
