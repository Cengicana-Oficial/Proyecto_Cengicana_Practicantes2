<?php
declare(strict_types=1);
function tableros_url(string $url):string {
    $url=trim($url);if(strlen($url)>500||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)throw new RuntimeException('Introduce una URL HTTP o HTTPS de inserción válida, sin credenciales y de hasta 500 caracteres.');return $url;
}
function tableros_url_segura(?string $url):?string {try{return tableros_url((string)$url);}catch(RuntimeException $e){return null;}}
