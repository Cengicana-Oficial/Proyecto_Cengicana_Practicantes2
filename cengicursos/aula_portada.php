<?php
require_once __DIR__.'/menu.php';
require_once __DIR__.'/aula_helpers.php';
$db=conectar();$cid=(int)($_GET['curso']??0);
if(cengi_es_estudiante()) {
    if(!aula_assignment($db,$cid)) { http_response_code(403);exit; }
} else aula_teacher_course($db,$cid);
$q=$db->prepare('SELECT mime,imagen,version FROM aula_portadas WHERE curso_id=?');$q->execute([$cid]);$p=$q->fetch(PDO::FETCH_ASSOC);
if(!$p){http_response_code(404);exit;}
header('Content-Type: '.$p['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=3600');
header('Content-Length: '.strlen($p['imagen']));echo $p['imagen'];
