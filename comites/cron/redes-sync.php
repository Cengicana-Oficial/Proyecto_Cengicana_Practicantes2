<?php
declare(strict_types=1);

// Sincroniza las conexiones de redes sociales y Google Analytics cuyo
// intervalo vencio. Pensado para cron, por ejemplo cada hora:
//   0 * * * * php /var/www/html/comites/cron/redes-sync.php
// Con --forzar sincroniza todas las conexiones activas sin esperar el intervalo.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/redes.php';
require dirname(__DIR__) . '/includes/redes-api.php';

$forzar = in_array('--forzar', $argv, true);
$hubo = false;
foreach (array_keys(redes_api_proveedores()) as $proveedor) {
    try {
        $mensaje = redes_api_sincronizar($pdo, $proveedor, false);
        if ($forzar && $mensaje === null && (redes_api_conexiones($pdo)[$proveedor]['activo'] ?? 0)) {
            $mensaje = redes_api_sincronizar($pdo, $proveedor, true);
        }
    } catch (Throwable $e) {
        $mensaje = 'ERROR: ' . $e->getMessage();
    }
    if ($mensaje !== null) {
        $hubo = true;
        echo date('Y-m-d H:i:s'), " [$proveedor] $mensaje", PHP_EOL;
    }
}
if (!$hubo) {
    echo date('Y-m-d H:i:s'), ' Sin conexiones pendientes.', PHP_EOL;
}
