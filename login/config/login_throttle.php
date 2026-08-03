<?php

const CENGI_LOGIN_MAX_INTENTOS = 5;
const CENGI_LOGIN_VENTANA_SEGUNDOS = 900; // 15 minutos

function cengi_login_throttle_archivo()
{
    return __DIR__ . '/../storage/login_attempts.json';
}

function cengi_login_throttle_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
}

function cengi_login_throttle_leer()
{
    $archivo = cengi_login_throttle_archivo();

    if (!is_file($archivo)) {
        return [];
    }

    $contenido = file_get_contents($archivo);
    $datos = json_decode((string) $contenido, true);

    return is_array($datos) ? $datos : [];
}

function cengi_login_throttle_escribir(array $datos)
{
    $archivo = cengi_login_throttle_archivo();
    $carpeta = dirname($archivo);

    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }

    file_put_contents($archivo, json_encode($datos), LOCK_EX);
}

/**
 * Devuelve los segundos restantes de bloqueo para la IP actual, o 0 si puede intentar.
 */
function cengi_login_throttle_bloqueado()
{
    $ip = cengi_login_throttle_ip();
    $clave = hash('sha256', $ip);
    $datos = cengi_login_throttle_leer();
    $registro = $datos[$clave] ?? null;

    if (!$registro) {
        return 0;
    }

    $transcurrido = time() - $registro['primer_intento'];

    if ($transcurrido >= CENGI_LOGIN_VENTANA_SEGUNDOS) {
        return 0;
    }

    if ($registro['intentos'] < CENGI_LOGIN_MAX_INTENTOS) {
        return 0;
    }

    return CENGI_LOGIN_VENTANA_SEGUNDOS - $transcurrido;
}

function cengi_login_throttle_registrar_fallo()
{
    $ip = cengi_login_throttle_ip();
    $clave = hash('sha256', $ip);
    $datos = cengi_login_throttle_leer();
    $ahora = time();

    $registro = $datos[$clave] ?? null;

    if (!$registro || ($ahora - $registro['primer_intento']) >= CENGI_LOGIN_VENTANA_SEGUNDOS) {
        $registro = ['intentos' => 0, 'primer_intento' => $ahora];
    }

    $registro['intentos']++;
    $datos[$clave] = $registro;

    // Poda entradas viejas para que el archivo no crezca indefinidamente.
    foreach ($datos as $k => $r) {
        if (($ahora - $r['primer_intento']) >= CENGI_LOGIN_VENTANA_SEGUNDOS) {
            unset($datos[$k]);
        }
    }

    cengi_login_throttle_escribir($datos);
}

function cengi_login_throttle_limpiar()
{
    $ip = cengi_login_throttle_ip();
    $clave = hash('sha256', $ip);
    $datos = cengi_login_throttle_leer();

    if (isset($datos[$clave])) {
        unset($datos[$clave]);
        cengi_login_throttle_escribir($datos);
    }
}
