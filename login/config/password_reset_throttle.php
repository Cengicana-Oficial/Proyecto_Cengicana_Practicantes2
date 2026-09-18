<?php

/**
 * Rate limiting minimalista (archivo JSON, sin dependencias) para
 * login/forgot_password.php, mismo estilo que login/config/login_throttle.php
 * pero con dos limites independientes:
 *  - por combinacion IP+correo (evita reenviar el mismo correo en bucle),
 *  - por IP sola (evita que una sola IP dispare correos hacia muchas
 *    direcciones distintas, aunque cada una individualmente este bajo su
 *    propio limite).
 * No revela si el correo existe o no: el llamador siempre debe mostrar el
 * mismo mensaje de exito, este bloqueado o no.
 */

const CENGI_RESET_MAX_INTENTOS_POR_CORREO = 3;
const CENGI_RESET_MAX_INTENTOS_POR_IP = 10;
const CENGI_RESET_VENTANA_SEGUNDOS = 900; // 15 minutos

function cengi_reset_throttle_archivo()
{
    return __DIR__ . '/../storage/password_reset_attempts.json';
}

function cengi_reset_throttle_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
}

function cengi_reset_throttle_leer()
{
    $archivo = cengi_reset_throttle_archivo();

    if (!is_file($archivo)) {
        return [];
    }

    $contenido = file_get_contents($archivo);
    $datos = json_decode((string) $contenido, true);

    return is_array($datos) ? $datos : [];
}

function cengi_reset_throttle_escribir(array $datos)
{
    $archivo = cengi_reset_throttle_archivo();
    $carpeta = dirname($archivo);

    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }

    file_put_contents($archivo, json_encode($datos), LOCK_EX);
}

function cengi_reset_throttle_clave_correo($correo)
{
    $ip = cengi_reset_throttle_ip();
    return hash('sha256', $ip . '|correo|' . strtolower(trim((string) $correo)));
}

function cengi_reset_throttle_clave_ip()
{
    return hash('sha256', cengi_reset_throttle_ip() . '|ip');
}

function cengi_reset_throttle_registro_activo(?array $registro, $ahora)
{
    if (!$registro) {
        return null;
    }

    if (($ahora - $registro['primer_intento']) >= CENGI_RESET_VENTANA_SEGUNDOS) {
        return null;
    }

    return $registro;
}

/**
 * True si la IP actual (para este correo o en general) debe ser bloqueada
 * antes de intentar enviar un nuevo correo de reseteo.
 */
function cengi_reset_throttle_bloqueado($correo)
{
    $ahora = time();
    $datos = cengi_reset_throttle_leer();

    $registroCorreo = cengi_reset_throttle_registro_activo($datos[cengi_reset_throttle_clave_correo($correo)] ?? null, $ahora);
    if ($registroCorreo && $registroCorreo['intentos'] >= CENGI_RESET_MAX_INTENTOS_POR_CORREO) {
        return true;
    }

    $registroIp = cengi_reset_throttle_registro_activo($datos[cengi_reset_throttle_clave_ip()] ?? null, $ahora);
    if ($registroIp && $registroIp['intentos'] >= CENGI_RESET_MAX_INTENTOS_POR_IP) {
        return true;
    }

    return false;
}

function cengi_reset_throttle_incrementar_clave(array &$datos, $clave, $ahora)
{
    $registro = $datos[$clave] ?? null;

    if (!$registro || ($ahora - $registro['primer_intento']) >= CENGI_RESET_VENTANA_SEGUNDOS) {
        $registro = ['intentos' => 0, 'primer_intento' => $ahora];
    }

    $registro['intentos']++;
    $datos[$clave] = $registro;
}

/**
 * Registra un intento de solicitud de reseteo (exista o no el correo en BD:
 * el throttling debe aplicar igual para no filtrar informacion por timing ni
 * por conteo de intentos restantes).
 */
function cengi_reset_throttle_registrar($correo)
{
    $ahora = time();
    $datos = cengi_reset_throttle_leer();

    cengi_reset_throttle_incrementar_clave($datos, cengi_reset_throttle_clave_correo($correo), $ahora);
    cengi_reset_throttle_incrementar_clave($datos, cengi_reset_throttle_clave_ip(), $ahora);

    // Poda entradas viejas para que el archivo no crezca indefinidamente.
    foreach ($datos as $k => $r) {
        if (($ahora - $r['primer_intento']) >= CENGI_RESET_VENTANA_SEGUNDOS) {
            unset($datos[$k]);
        }
    }

    cengi_reset_throttle_escribir($datos);
}
