<?php
/**
 * Recordatorio automático de pagos de inscripción (ejecutar una vez al día).
 *
 * Envía el recordatorio de pago (cobranza_helpers.php) a:
 *   - participantes con pago propio sin comprobante o con el comprobante rechazado, y
 *   - usuarios del ingenio por órdenes grupales sin pagar o con el comprobante rechazado,
 * cuando el curso inicia en los próximos CENGI_COB_AUTO_ANTES días o inició hace menos de
 * CENGI_COB_AUTO_DESPUES días. A cada pendiente se le escribe como máximo cada
 * CENGI_COB_AUTO_CADA días y hasta CENGI_COB_AUTO_MAX recordatorios (se cuentan también los
 * enviados a mano desde Pagos de inscripción).
 *
 * Modos (igual que cron_recordatorio_cupo.php):
 *   - CLI: php cron_recordatorio_pago.php [--simular]
 *   - HTTP: cron_recordatorio_pago.php[?simular=1] con el token en una cabecera
 *     ("Authorization: Bearer <token>" o "X-Cron-Token: <token>"), no en la URL, que queda en
 *     los logs (ver cengi_cron_token_valido() en conexion.php). Por HTTP solo se devuelven los
 *     conteos; el detalle con nombres y montos solo se muestra en CLI.
 * Con "simular" solo informa a quién se le escribiría, sin enviar correos.
 */
require_once __DIR__ . '/cobranza_helpers.php';

const CENGI_COB_AUTO_ANTES = 10;
const CENGI_COB_AUTO_DESPUES = 14;
const CENGI_COB_AUTO_CADA = 3;
const CENGI_COB_AUTO_MAX = 4;

$esCli = PHP_SAPI === 'cli';
if (!$esCli) {
    header('Content-Type: application/json; charset=utf-8');
    if (!cengi_cron_token_valido()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'mensaje' => 'Acceso no autorizado.']);
        exit;
    }
}
$simular = $esCli ? in_array('--simular', $argv ?? [], true) : !empty($_GET['simular']);

$db = conectar();
$hoy = cengi_pp_hoy();
$resultado = ['ok' => true, 'simulacion' => $simular, 'enviados' => 0, 'fallidos' => 0, 'sin_usuario' => 0, 'omitidos' => 0, 'historicos' => 0, 'detalle' => []];

try {
    // Pendientes de los años con cursos en la ventana (normalmente el actual; en enero, también el anterior).
    $anios = array_unique([(int) date('Y', strtotime($hoy . ' -' . CENGI_COB_AUTO_DESPUES . ' days')), (int) date('Y', strtotime($hoy . ' +' . CENGI_COB_AUTO_ANTES . ' days'))]);
    foreach ($anios as $anio) {
        foreach (cengi_cob_pendientes($db, $anio) as $p) {
            $aplica = in_array($p['situacion'], ['sin-comprobante', 'comprobante-rechazado', 'orden-pendiente', 'orden-rechazada'], true)
                && $p['venc']['dias'] <= CENGI_COB_AUTO_ANTES && $p['venc']['dias'] >= -CENGI_COB_AUTO_DESPUES;
            if (!$aplica) {
                continue;
            }
            // Órdenes con inscripciones de la conciliación histórica: el monto puede incluir
            // participantes ya cobrados por otra vía; las revisa Administración a mano.
            if (!empty($p['historicos'])) {
                $resultado['historicos']++;
                continue;
            }
            $r = $p['recordatorio'];
            if ($r && ($r['n'] >= CENGI_COB_AUTO_MAX || cengi_pp_dias_entre(substr($r['ultimo'], 0, 10), $hoy) < CENGI_COB_AUTO_CADA)) {
                $resultado['omitidos']++;
                continue;
            }
            $linea = ($p['tipo'] === 's' ? 'Participante: ' : 'Ingenio: ') . $p['quien'] . ' · ' . $p['curso'] . ' · ' . $p['monto_txt'] . ' · ' . $p['venc']['texto'];
            if ($simular) {
                $resultado['detalle'][] = '[simulación] ' . $linea;
                continue;
            }
            try {
                $fila = $p['fila'] + ['fecha_inicio' => $p['fecha_inicio']];
                $ok = $p['tipo'] === 's' ? cengi_cob_recordar_participante($db, $fila) : cengi_cob_recordar_grupo($db, $fila);
            } catch (Throwable $e) {
                error_log('Recordatorio de pago automático: ' . $e->getMessage());
                $ok = false;
            }
            $clave = $ok === null ? 'sin_usuario' : ($ok ? 'enviados' : 'fallidos');
            $resultado[$clave]++;
            $resultado['detalle'][] = ['enviados' => 'Enviado', 'fallidos' => 'Falló', 'sin_usuario' => 'Sin usuario con correo'][$clave] . ': ' . $linea;
        }
    }
} catch (Throwable $e) {
    error_log('Recordatorio de pago automático: ' . $e->getMessage());
    $resultado = ['ok' => false, 'mensaje' => 'Error al procesar los recordatorios.'];
}

if ($esCli) {
    echo ($resultado['ok'] ? '' : 'ERROR: ' . ($resultado['mensaje'] ?? '') . PHP_EOL);
    if ($resultado['ok']) {
        echo ($simular ? 'SIMULACIÓN — ' : '') . 'Enviados: ' . $resultado['enviados'] . ' · fallidos: ' . $resultado['fallidos'] . ' · sin usuario: ' . $resultado['sin_usuario']
            . ' · omitidos (recordados hace poco o al máximo): ' . $resultado['omitidos']
            . ' · órdenes con asignaciones históricas (revisión manual): ' . $resultado['historicos'] . PHP_EOL;
        foreach ($resultado['detalle'] as $d) {
            echo '  - ' . $d . PHP_EOL;
        }
    }
    exit($resultado['ok'] ? 0 : 1);
}
unset($resultado['detalle']);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
