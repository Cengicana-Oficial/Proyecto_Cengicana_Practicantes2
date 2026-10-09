<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_start();$_SESSION=['es_superadmin'=>true,'usuario_nombre'=>'Prueba de renderizado'];
$_SERVER['REQUEST_METHOD']='GET';
$page=$argv[1]??'kanban_view.php';
if(!in_array($page,['kanban_view.php','solicitudes_pendientes_tecnico.php'],true))throw new InvalidArgumentException('Página no permitida');
ob_start();require __DIR__.'/../view/'.$page;$html=ob_get_clean();session_abort();
if(preg_match('/Warning:|Fatal error:|Notice:/',$html))throw new RuntimeException('Error PHP en renderizado');
libxml_use_internal_errors(true);$doc=new DOMDocument();$doc->loadHTML($html);$seen=[];
foreach($doc->getElementsByTagName('*') as $node)if($node->hasAttribute('id')){$id=$node->getAttribute('id');if(isset($seen[$id]))throw new RuntimeException('ID repetido: '.$id);$seen[$id]=true;}
$data=json_decode($doc->getElementById('wf-data')->textContent,true,512,JSON_THROW_ON_ERROR);
foreach($data['tareas'] as $task)if(empty($task['hoja']))throw new RuntimeException('Hay un análisis sin hoja configurada');
echo 'OK: '.$page.', controles únicos y hojas asignadas a '.count($data['tareas'])." tareas.\n";
