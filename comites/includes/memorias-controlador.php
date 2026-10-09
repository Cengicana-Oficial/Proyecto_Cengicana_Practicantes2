<?php
declare(strict_types=1);
require_once __DIR__.'/directorio.php';
require_once __DIR__.'/memorias.php';
if(can_view_resource($user,'memorias')){mem_preparar($pdo);$_SESSION['memorias_csrf'] ??= bin2hex(random_bytes(32));}
if(isset($_GET['memorias_api'])||isset($_GET['descarga'])||$_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!can_view_resource($user,'memorias')){http_response_code(403);throw new RuntimeException('No tienes acceso a Memoria de resultados.');}
        if(isset($_GET['descarga'])){$a=mem_descarga($pdo,$user,$_GET);$path=mem_ruta($a['ruta']);header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)$a['nombre']));header('Content-Length: '.filesize($path));readfile($path);exit;}
        $msg='';if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!hash_equals($_SESSION['memorias_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('El formulario expiró. Recarga la página.');}
            $d=json_decode((string)($_POST['datos'] ?? '{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Datos no válidos.');$msg=mem_mutar($pdo,$user,(string)($_POST['mem_accion'] ?? ''),$d,$_FILES);
        }
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'mensaje'=>$msg,'datos'=>mem_datos($pdo,$user)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(http_response_code()<400)http_response_code(422);error_log('Memorias: '.$e->getMessage());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException?'No se pudo guardar. Revisa los datos y vuelve a intentarlo.':($e instanceof RuntimeException?$e->getMessage():'No se pudo procesar la solicitud.')],JSON_UNESCAPED_UNICODE);}exit;
}
