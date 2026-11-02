<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/aula_helpers.php';
$db=conectar();
if (!empty($_GET['diploma'])) {
    $r=aula_rows($db,'SELECT a.cursos_id,a.id,cc.diploma FROM asignaciones a JOIN control_cursos cc ON cc.asignacion_id=a.id WHERE a.id=?',[(int)$_GET['diploma']])[0]??null;
    $own=$r?aula_assignment($db,$r['cursos_id']):null;
    if (!$r || !$own || (int)$own['id']!==(int)$r['id']) { http_response_code(403); exit('Acceso denegado.'); }
    $root=realpath(__DIR__.'/../uploads');
    $path=realpath(__DIR__.'/'.ltrim($r['diploma'],'/'));
    if(!$path)$path=realpath(__DIR__.'/../'.ltrim($r['diploma'],'/'));
    if(!$root||!$path||strpos($path,$root.DIRECTORY_SEPARATOR)!==0||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='pdf'){http_response_code(404);exit('Archivo no disponible.');}
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="Diploma.pdf"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');readfile($path);exit;
}
$r=aula_rows($db,'SELECT f.*,t.asignacion_id FROM aula_archivos f LEFT JOIN aula_intentos t ON t.id=f.intento_id WHERE f.id=?',[(int)($_GET['id']??0)])[0]??null;
if(!$r){http_response_code(404);exit('Archivo no disponible.');}
$own=aula_assignment($db,$r['curso_id']);
// Recurso oculto o aun no disponible (Gestion del aula): solo coordinacion/docencia lo abre.
$oculto=!$r['intento_id']&&aula_vista_ready($db)&&aula_rows($db,'SELECT 1 FROM aula_recursos r WHERE r.archivo_id=? AND '.aula_recurso_oculto_sql('r').' LIMIT 1',[(int)$r['id']]);
if (!$own || $oculto || ($r['intento_id'] && (int)$own['id']!==(int)$r['asignacion_id'])) {
    aula_teacher_course($db,$r['curso_id']);
}
header('Content-Type: '.$r['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
if($r['mime']==='video/mp4') {
    header('Content-Disposition: inline');header('Accept-Ranges: bytes');
    $length=strlen($r['contenido']);$start=0;$end=$length-1;
    if(isset($_SERVER['HTTP_RANGE'])) {
        if(!preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$length);exit;}
        if($m[1]===''){$start=max(0,$length-(int)$m[2]);}else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
        if($start>$end||$start>=$length){http_response_code(416);header('Content-Range: bytes */'.$length);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$length);
    }
    header('Content-Length: '.($end-$start+1));echo substr($r['contenido'],$start,$end-$start+1);exit;
}
header("Content-Disposition: attachment; filename=\"Documento.pdf\"; filename*=UTF-8''".rawurlencode($r['nombre']));echo $r['contenido'];
