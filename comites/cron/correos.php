<?php
declare(strict_types=1);

// Envía la cola de notificaciones por correo y genera los recordatorios del día.
// En producción corre como servicio (comites-correos-worker en docker-compose.prod.yml):
//   php /var/www/html/comites/cron/correos.php --loop   # revisa la cola cada minuto
// Sin --loop hace una sola pasada, para cron:
//   */15 * * * * php /var/www/html/comites/cron/correos.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/domain.php';
require dirname(__DIR__) . '/includes/notificaciones.php';
require dirname(__DIR__) . '/includes/correo-eventos.php';

function comites_correos_pasada(PDO $pdo, bool $silencioso): bool
{
    $hoy = date('Y-m-d');
    if (correo_ajuste($pdo, 'recordatorios_fecha') !== $hoy) {
        correo_guardar_ajuste($pdo, 'recordatorios_fecha', $hoy);
        echo date('c') . ' recordatorios: ' . correo_recordatorios($pdo) . PHP_EOL;
    }
    if (!correo_smtp_listo()) {
        echo date('c') . ' SMTP sin configurar: los correos siguen en la cola.' . PHP_EOL;
        return false;
    }
    [$enviados, $errores] = correo_despachar($pdo, 100);
    if (!$silencioso || $enviados || $errores) {
        echo date('c') . " enviados: $enviados, errores: $errores" . PHP_EOL;
    }
    return true;
}

if (!in_array('--loop', $argv, true)) {
    exit(comites_correos_pasada($pdo, false) ? 0 : 1);
}

// Si algo falla (por ejemplo, se cae MySQL), el proceso termina y Docker lo reinicia
// con una conexión nueva.
try {
    while (true) {
        // Sin SMTP no se reintenta cada minuto: espera 15 antes de volver a revisar.
        sleep(comites_correos_pasada($pdo, true) ? 60 : 900);
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
