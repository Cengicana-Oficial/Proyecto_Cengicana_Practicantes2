<?php

/**
 * Envio diario de comunicaciones (comunicaciones.php):
 *   - recordatorios automaticos segun las reglas activas de comunicacion_reglas
 *     (sesion de curso, inicio de curso, recordatorio al instructor), y
 *   - comunicaciones manuales programadas cuya fecha ya llego.
 *
 * Cada recordatorio se registra con (regla, referencia) UNIQUE, asi que el script puede
 * ejecutarse varias veces al dia sin reenviar. La referencia incluye la fecha y hora de
 * la sesion: si se reprograma, el recordatorio vuelve a programarse para la nueva fecha.
 *
 * Mismos modos de ejecucion que cron_recordatorio_cupo.php:
 *   1) CLI (recomendado): `php cron_comunicaciones.php` (por ejemplo, cada dia a las 7:00).
 *   2) HTTP con la cabecera "Authorization: Bearer <CRON_RECORDATORIO_SECRET>" o
 *      "X-Cron-Token: ..." (ver cengi_cron_token_valido() en conexion.php).
 */

require_once __DIR__ . '/planificacion_helpers.php';

$esCli = (php_sapi_name() === 'cli');

if (!$esCli) {
    header('Content-Type: application/json; charset=utf-8');
    if (!cengi_cron_token_valido()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'mensaje' => 'Acceso no autorizado.']);
        exit();
    }
}

try {
    $resumen = cengi_pp_ejecutar_pendientes(conectar());
    $salida = ['ok' => true] + $resumen;
} catch (Throwable $e) {
    error_log('cron_comunicaciones: ' . $e->getMessage());
    $salida = ['ok' => false, 'mensaje' => 'Error al procesar las comunicaciones.'];
    if (!$esCli) {
        http_response_code(500);
    }
}

echo json_encode($salida, JSON_UNESCAPED_UNICODE) . ($esCli ? PHP_EOL : '');
exit($salida['ok'] ? 0 : 1);
