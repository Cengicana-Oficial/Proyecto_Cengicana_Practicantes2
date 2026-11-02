<?php
declare(strict_types=1);
require_once __DIR__.'/directorio.php';
require_once __DIR__.'/biblioteca.php';
require_once __DIR__.'/biblioteca-interna.php';
if(can_view_resource($user,'biblioteca_interna')){interna_preparar($pdo);$_SESSION['interna_csrf'] ??= bin2hex(random_bytes(32));}
if(isset($_GET['biblioteca_api'])||isset($_GET['descarga'])||$_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!can_view_resource($user,'biblioteca_interna')){http_response_code(403);throw new RuntimeException('No tienes acceso a la biblioteca interna.');}
        if(isset($_GET['descarga'])){
            $r=interna_fila($pdo,(int)$_GET['descarga']);$path=biblioteca_ruta($r['archivo_ruta']);if(!$path){http_response_code(404);throw new RuntimeException('El archivo no está disponible.');}
            header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)$r['archivo_nombre']));header('Content-Length: '.filesize($path));readfile($path);exit;
        }
        $mensaje='';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!hash_equals($_SESSION['interna_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('El formulario expiró. Recarga la biblioteca.');}
            $d=json_decode((string)($_POST['datos'] ?? '{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Datos no válidos.');
            $mensaje=interna_mutar($pdo,$user,(string)($_POST['bib_accion'] ?? ''),$d,$_FILES);
        }
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'mensaje'=>$mensaje,'datos'=>interna_datos($pdo,$user)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(http_response_code()<400)http_response_code(422);error_log('Biblioteca interna: '.$e->getMessage());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException?'No se pudo guardar el documento. Revisa los datos.':($e instanceof RuntimeException?$e->getMessage():'No se pudo procesar la solicitud.')],JSON_UNESCAPED_UNICODE);}exit;
}
