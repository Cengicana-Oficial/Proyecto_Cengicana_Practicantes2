<?php
declare(strict_types=1);
require_once __DIR__.'/pasos.php';
$_SESSION['pasos_csrf'] ??= bin2hex(random_bytes(32));
if(isset($_GET['pasos_api'])||$_SERVER['REQUEST_METHOD']==='POST'){
    try{if(!can_view_resource($user,'acuerdos')){http_response_code(403);throw new RuntimeException('No tienes acceso a próximos pasos.');}
        if($_SERVER['REQUEST_METHOD']==='POST'){if(!hash_equals($_SESSION['pasos_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('El formulario expiró. Recarga la página.');}$d=json_decode((string)($_POST['datos'] ?? '{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Datos no válidos.');pasos_mutar($pdo,$user,(string)($_POST['paso_accion'] ?? ''),$d);}
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'datos'=>pasos_datos($pdo,$user)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){if(http_response_code()<400)http_response_code(422);error_log('Próximos pasos: '.$e->getMessage());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'No se pudo completar la operación.'],JSON_UNESCAPED_UNICODE);}exit;
}
