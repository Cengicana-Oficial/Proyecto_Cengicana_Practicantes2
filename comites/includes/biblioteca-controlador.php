<?php
declare(strict_types=1);
require_once __DIR__.'/directorio.php';
require_once __DIR__.'/biblioteca.php';
if(can_view_resource($user,'documentos')){biblioteca_preparar($pdo);$_SESSION['biblioteca_csrf'] ??= bin2hex(random_bytes(32));}
if(isset($_GET['biblioteca_api'])||isset($_GET['descarga'])||$_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!can_view_resource($user,'documentos')){http_response_code(403);throw new RuntimeException('No tienes acceso a esta biblioteca.');}
        if(isset($_GET['descarga'])){
            $r=biblioteca_fila($pdo,(int)$_GET['descarga']);$ruta=$r['archivo_ruta'];$nombre=$r['archivo_nombre'];
            if(!empty($_GET['historial'])){$q=$pdo->prepare('SELECT archivo_ruta,archivo_nombre FROM documento_historial WHERE id=? AND documento_id=?');$q->execute([(int)$_GET['historial'],$r['id']]);$h=$q->fetch();if(!$h)throw new RuntimeException('El adjunto no existe.');$ruta=$h['archivo_ruta'];$nombre=$h['archivo_nombre'];}
            $path=biblioteca_ruta($ruta);if(!$path){http_response_code(404);throw new RuntimeException('El archivo no está disponible.');}
            header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)$nombre));header('Content-Length: '.filesize($path));readfile($path);exit;
        }
        $accion=(string)($_POST['bib_accion'] ?? 'datos');$mensaje='';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!hash_equals($_SESSION['biblioteca_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('El formulario expiró. Recarga la biblioteca.');}
            if(!isset($_POST['bib_accion']))throw new RuntimeException('Usa los formularios actualizados de la biblioteca para conservar el flujo de revisión.');
            $d=json_decode((string)($_POST['datos'] ?? '{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Datos no válidos.');
            $mensaje=$accion==='correo'?biblioteca_enviar($pdo,$user,$d):biblioteca_mutar($pdo,$user,$accion,$d,$_FILES);
        }
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'mensaje'=>$mensaje,'datos'=>biblioteca_datos($pdo,$user)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(http_response_code()<400)http_response_code(422);error_log('Biblioteca: '.$e->getMessage());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException?'No se pudo guardar el documento. Revisa los datos.':($e instanceof RuntimeException?$e->getMessage():'No se pudo procesar la solicitud.')],JSON_UNESCAPED_UNICODE);}exit;
}
