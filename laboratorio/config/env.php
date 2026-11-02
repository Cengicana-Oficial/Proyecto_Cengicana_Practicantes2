<?php
if(!function_exists('loadEnvFile')){
    function loadEnvFile(string $path): void{
        if(!is_file($path) || !is_readable($path)){
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if($lines === false){
            return;
        }

        foreach($lines as $line){
            $line = trim($line);

            if($line === '' || $line[0] === '#'){
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            if($name === ''){
                continue;
            }
            $value = trim(($value));
            $value = trim($value, "'\"");

            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if(!function_exists('lab_env_central')){
    /**
     * Variables de login/.env (base central de usuarios) sin cargarlas en el entorno, para no
     * pisar las del laboratorio. parse_ini_file() no sirve: el .env usa comentarios con "#",
     * que PHP 7+ ya no acepta en archivos INI, y devuelve false.
     */
    function lab_env_central(): array{
        static $env = null;
        if($env !== null){
            return $env;
        }
        $env = [];
        $path = __DIR__ . '/../../login/.env';
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        foreach($lines ?: [] as $line){
            $line = trim($line);
            if($line === '' || $line[0] === '#' || strpos($line, '=') === false){
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $env[trim($name)] = trim(trim($value), "'\"");
        }
        if(empty($env['DB_HOST']) || empty($env['DB_NAME']) || empty($env['DB_USER'])){
            throw new RuntimeException('No se pudo leer la configuración de la base central de usuarios (login/.env).');
        }
        return $env;
    }
}

if(!defined('APP_ENV_LOADED')){
    loadEnvFile(__DIR__ . '/../.env');
    define('APP_ENV_LOADED', true); #entorno cargado de aplicacion
}

?>