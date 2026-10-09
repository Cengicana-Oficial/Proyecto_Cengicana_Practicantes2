<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/tableros.php';
foreach(['javascript:alert(1)','data:text/html,test','https://user:password@example.com/report','<iframe src="https://example.com"></iframe>','/login/login.php'] as $url){if(tableros_url_segura($url)!==null)throw new RuntimeException('Enlace no seguro aceptado');}
$url='https://app.powerbi.com/reportEmbed?reportId=example&autoAuth=true';if(tableros_url($url)!==$url)throw new RuntimeException('URL de inserción alterada');
echo "OK: validación de URL y conservación de parámetros de inserción.\n";
