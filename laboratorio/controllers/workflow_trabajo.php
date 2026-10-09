<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../conexion.php';
require_once __DIR__.'/../includes/workflow_trabajo_helper.php';
lab_require_module_access();
$isPlan=($_GET['modo']??'')==='plan';
if($isPlan)lab_require_permission('laboratorio.kanban.ver');
elseif(!lab_can('laboratorio.formularios_pendientes.ver')&&!lab_is_technician())lab_forbidden();
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try{
    $user=lab_current_user();$manager=lab_can('laboratorio.kanban.gestionar');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if($isPlan&&isset($_GET['seguimiento'])){echo json_encode(['eventos'=>wfSeguimientoEventos($conexion,(int)$_GET['seguimiento'])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
        $key=wfTexto($_GET['hoja']??'',180);$tasks=wfTareas($conexion);
        if($key!==''){
            if(!isset(wfConfig()['hojas'][$key]))throw new InvalidArgumentException('Hoja inválida.');
            $tasks=array_values(array_filter($tasks,fn($t)=>$t['hoja']===$key&&wfPendiente($t)&&wfPuedeCapturar($t,$user,$manager)));
            echo json_encode(['tareas'=>$tasks,'borrador'=>wfBorrador($conexion,$key,$user),'vinculos'=>wfVinculos($conexion,$tasks)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }else echo json_encode(['tareas'=>$tasks,'usuarios'=>wfUsuarios()]+($isPlan?['identificacion'=>(object)wfIdentificacionResumen($conexion),'seguimiento'=>(object)wfSeguimientoLotes($conexion)]:wfResumenBandeja($conexion,$user)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }elseif($_SERVER['REQUEST_METHOD']==='POST'){
        $raw=file_get_contents('php://input',false,null,0,5000001);if(strlen($raw)>5000000)throw new InvalidArgumentException('La hoja es demasiado grande.');
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new InvalidArgumentException('Solicitud inválida.');
        if(!is_string($data['csrf']??null)||empty($_SESSION['workflow_csrf'])||!hash_equals($_SESSION['workflow_csrf'],$data['csrf'])){http_response_code(403);echo json_encode(['error'=>'La sesión venció. Recargue antes de guardar.']);exit;}
        if($isPlan){lab_require_permission('laboratorio.kanban.gestionar');$result=wfPlanGuardar($conexion,$data,$user);}
        else{if(!in_array($data['accion']??'', ['guardar','enviar'],true))throw new InvalidArgumentException('Acción inválida.');$result=wfGuardarHoja($conexion,$data,$user,$manager);}
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }else{http_response_code(405);header('Allow: GET, POST');echo json_encode(['error'=>'Método no permitido.']);}
}catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){$conflict=$e->getCode()===409;http_response_code($conflict?409:500);error_log('[workflow] '.$e->getMessage());echo json_encode(['error'=>$conflict?$e->getMessage():'No se pudo completar la operación. Los cambios no guardados permanecen en pantalla.']);}