<?php
require_once __DIR__.'/instructor_cv_helpers.php';
require_once __DIR__.'/../login/config/session.php';cengi_session_start();
$db=conectar();$token=(string)($_GET['token']??'');
if($token!==''){$link=cengi_cv_enlace($db,$token);if(!$link){http_response_code(404);exit;}$id=(int)$link['instructor_id'];}
else{require_once __DIR__.'/revisar_permisos.php';cengi_require_gestionar_instructores();$id=(int)($_GET['id']??0);}
$cv=cengi_cv_leer($db,$id);$url=cengi_normalizar_url_archivo($cv['valores']['foto']??'');$base=realpath(__DIR__.'/../uploads/instructores');$path=$url?realpath(__DIR__.'/..'.$url):false;
if(!$base||!$path||!is_file($path)||strpos($path,$base.DIRECTORY_SEPARATOR)!==0){http_response_code(404);exit;}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);if(!in_array($mime,['image/jpeg','image/png'],true)){http_response_code(404);exit;}
header('Content-Type: '.$mime);header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($path));readfile($path);
