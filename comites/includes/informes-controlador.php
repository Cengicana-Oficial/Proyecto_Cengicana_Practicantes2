<?php
declare(strict_types=1);
require_once __DIR__.'/informes.php';
if(can_view_resource($user,'informes')){inf_preparar($pdo);$_SESSION['informes_csrf'] ??= bin2hex(random_bytes(32));}
if(isset($_GET['informes_api'])||isset($_GET['descarga'])||isset($_GET['plantilla_poa'])||$_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!can_view_resource($user,'informes')){http_response_code(403);throw new RuntimeException('No tienes acceso a informes.');}
        if(isset($_GET['plantilla_poa'])){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="plantilla_POA.csv"');echo "\xEF\xBB\xBFÁrea,Cuatrimestre,Actividad,Meta,Logro,Unidad,Evidencia\r\nRiego,1,Evaluaciones de uniformidad,10,8,número,\r\n";exit;}
        if(isset($_GET['descarga'])){$a=inf_descarga($pdo,$user,$_GET);$path=inf_ruta($a['ruta']);header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($a['nombre']));header('Content-Length: '.filesize($path));readfile($path);exit;}
        $msg='';if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!hash_equals($_SESSION['informes_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('El formulario expiró. Recarga la página.');}
            $d=json_decode((string)($_POST['datos'] ?? '{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Datos no válidos.');$msg=inf_mutar($pdo,$user,(string)($_POST['inf_accion'] ?? ''),$d,$_FILES);
        }
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'mensaje'=>$msg,'datos'=>inf_datos($pdo,$user)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(http_response_code()<400)http_response_code(422);error_log('Informes: '.$e->getMessage());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'No se pudo procesar la solicitud.'],JSON_UNESCAPED_UNICODE);}exit;
}
