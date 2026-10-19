<?php
declare(strict_types=1);

// Envía la cola de notificaciones por correo y genera los recordatorios del día.
// El módulo ya lo hace al terminar cada petición; este script cubre los días sin
// actividad y los reintentos. Pensado para cron, por ejemplo cada 15 minutos:
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

$hoy = date('Y-m-d');
if (correo_ajuste($pdo, 'recordatorios_fecha') !== $hoy) {
    correo_guardar_ajuste($pdo, 'recordatorios_fecha', $hoy);
    echo date('c') . ' recordatorios: ' . correo_recordatorios($pdo) . PHP_EOL;
}
if (!correo_smtp_listo()) {
    echo date('c') . ' SMTP sin configurar: los correos siguen en la cola.' . PHP_EOL;
    exit(1);
}
[$enviados, $errores] = correo_despachar($pdo, 100);
echo date('c') . " enviados: $enviados, errores: $errores" . PHP_EOL;
