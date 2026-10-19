<?php
/**
 * Recibos de pago a instructores y soporte técnico que cobran sin factura: documento del recibo
 * (página imprimible y PDF), envío por correo con un enlace personal para devolverlo firmado
 * y registro del recibo firmado (liquidacion_recibos). Lo usan pago_instructores.php y la
 * página pública recibo_firmado.php. Esquema: migrations/20261023_liquidacion_recibos.sql.
 */

require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/correo.php'; // cengi_enviar_correo() y Dompdf (vendor/autoload)

use Dompdf\Dompdf;
use Dompdf\Options;

const CENGI_PR_DDL = "CREATE TABLE IF NOT EXISTS liquidacion_recibos (
  detalle_id INT UNSIGNED NOT NULL,
  token CHAR(40) NOT NULL,
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  enviado_en DATETIME NULL,
  enviado_a VARCHAR(255) NOT NULL DEFAULT '',
  envios SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  firmado_path VARCHAR(255) NOT NULL DEFAULT '',
  firmado_nombre VARCHAR(255) NOT NULL DEFAULT '',
  firmado_en DATETIME NULL,
  firmado_por VARCHAR(20) NOT NULL DEFAULT '',
  PRIMARY KEY (detalle_id),
  UNIQUE KEY uq_liquidacion_recibos_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Expediente del recibo: cada envío (con el PDF emitido), cada firmado subido (también los
// reemplazados), la validación del Jefe y el pago. Los archivos no se borran nunca.
const CENGI_PR_DDL_HISTORIAL = "CREATE TABLE IF NOT EXISTS liquidacion_recibo_historial (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  detalle_id INT UNSIGNED NOT NULL,
  accion VARCHAR(30) NOT NULL,
  detalle VARCHAR(500) NOT NULL DEFAULT '',
  archivo_path VARCHAR(255) NOT NULL DEFAULT '',
  archivo_nombre VARCHAR(255) NOT NULL DEFAULT '',
  monto DECIMAL(12,2) NULL,
  usuario VARCHAR(160) NOT NULL DEFAULT '',
  creado DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_recibo_historial_detalle (detalle_id, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
// Validación del Jefe de Transferencia en cada recibo (solo los recibos la llevan; las facturas no).
const CENGI_PR_COLUMNAS = [
    'validado_en' => 'DATETIME NULL',
    'validado_por' => "VARCHAR(120) NOT NULL DEFAULT ''",
    'validado_nombre' => "VARCHAR(160) NOT NULL DEFAULT ''",
    'validado_cargo' => "VARCHAR(160) NOT NULL DEFAULT ''",
    'validado_imagen' => 'MEDIUMTEXT NULL',
    'validado_codigo' => "VARCHAR(40) NOT NULL DEFAULT ''",
    'validado_monto' => 'DECIMAL(12,2) NULL',
];
const CENGI_PR_ACCIONES = [
    'enviado' => ['Recibo emitido y enviado', 'envelope'],
    'firmado' => ['Recibo firmado recibido', 'pencil'],
    'reemplazado' => ['Firmado reemplazado (se conserva el anterior)', 'retweet'],
    'validado' => ['Validado por el Jefe de Transferencia', 'check'],
    'validacion_retirada' => ['Validación retirada', 'remove'],
    'pagado' => ['Pago registrado', 'usd'],
    'factura' => ['Factura registrada por Administración', 'list-alt'],
    'requerimiento' => ['Requerimiento de factura enviado', 'envelope'],
];
const CENGI_PR_TIPOS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
const CENGI_PR_MAX = 10 * 1024 * 1024;

/** Crea tablas y columnas que falten (fuera de transacciones: el DDL hace commit implícito). */
function cengi_pr_asegurar(PDO $db)
{
    static $listo = false;
    if ($listo) {
        return;
    }
    $listo = true;
    $tablas = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('liquidacion_recibos', 'liquidacion_recibo_historial')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('liquidacion_recibos', $tablas, true)) {
        $db->exec(CENGI_PR_DDL);
    }
    if (!in_array('liquidacion_recibo_historial', $tablas, true)) {
        $db->exec(CENGI_PR_DDL_HISTORIAL);
    }
    $columnas = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'liquidacion_recibos'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (CENGI_PR_COLUMNAS as $columna => $tipo) {
        if (!in_array($columna, $columnas, true)) {
            $db->exec("ALTER TABLE liquidacion_recibos ADD COLUMN $columna $tipo");
        }
    }
}

/** Anota un paso en el expediente del recibo. */
function cengi_pr_log(PDO $db, $detalleId, $accion, $detalle = '', $archivo = '', $nombre = '', $monto = null, $usuario = '')
{
    $db->prepare('INSERT INTO liquidacion_recibo_historial (detalle_id, accion, detalle, archivo_path, archivo_nombre, monto, usuario, creado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $detalleId, $accion, mb_substr((string) $detalle, 0, 500, 'UTF-8'), (string) $archivo, mb_substr((string) $nombre, 0, 255, 'UTF-8'),
            $monto === null ? null : (float) $monto, mb_substr((string) ($usuario ?: (function_exists('cengi_pp_usuario_nombre') ? cengi_pp_usuario_nombre() : '')), 0, 160, 'UTF-8'), date('Y-m-d H:i:s')]);
}

/** Expediente de varios recibos: [detalle_id => [pasos, del más reciente al más antiguo]]. */
function cengi_pr_historial(PDO $db, array $detalleIds)
{
    $ids = array_values(array_filter(array_map('intval', $detalleIds)));
    if (!$ids) {
        return [];
    }
    $stmt = $db->prepare('SELECT * FROM liquidacion_recibo_historial WHERE detalle_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY creado DESC, id DESC');
    $stmt->execute($ids);
    $porRecibo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $porRecibo[(int) $h['detalle_id']][] = $h;
    }
    return $porRecibo;
}
/** Secreto para el código de la firma (se genera una vez). */
function cengi_pago_secreto(PDO $db)
{
    $valor = $db->query("SELECT valor FROM eventos_config WHERE clave = 'pi_firma_secreto'")->fetchColumn();
    if (!$valor) {
        $valor = bin2hex(random_bytes(32));
        $db->prepare("INSERT IGNORE INTO eventos_config (clave, valor) VALUES ('pi_firma_secreto', ?)")->execute([$valor]);
        $valor = $db->query("SELECT valor FROM eventos_config WHERE clave = 'pi_firma_secreto'")->fetchColumn();
    }
    return (string) $valor;
}

/**
 * Código de verificación de la firma: HMAC del contenido de la liquidación (encabezado y
 * filas). Si algo cambia después de firmar, el código ya no coincide.
 */
function cengi_pago_codigo_firma(PDO $db, array $liq, array $detalle, $firmadaEn, $firmadaPor)
{
    $filas = array_map(static function ($d) {
        return implode('|', [$d['tipo'], $d['persona_id'], $d['planificacion_id'], (int) $d['sesiones'], number_format((float) $d['tarifa'], 2, '.', ''),
            // El n.° de factura se anota al pagar (después de firmar): solo el del recibo forma parte del código.
            number_format((float) $d['total'], 2, '.', ''), $d['comprobante'] ?? '', ($d['comprobante'] ?? '') === 'recibo' ? ($d['documento_numero'] ?? '') : '']);
    }, $detalle);
    sort($filas);
    $base = implode("\n", array_merge([$liq['id'], $liq['numero'], number_format((float) $liq['total'], 2, '.', ''), $firmadaEn, $firmadaPor], $filas));
    $h = strtoupper(substr(hash_hmac('sha256', $base, cengi_pago_secreto($db)), 0, 12));
    return implode('-', str_split($h, 4));
}

/** Concepto del recibo: "Honorarios de instructor <curso> Mes de ...". */
function cengi_pago_concepto(array $d)
{
    $fechas = array_values(array_filter(explode(',', (string) $d['fechas'])));
    sort($fechas);
    $rol = $d['tipo'] === 'soporte' ? 'soporte técnico' : 'instructor';
    $periodo = '';
    if ($fechas) {
        $ini = substr($fechas[0], 0, 7);
        $fin = substr(end($fechas), 0, 7);
        $mes = static function ($ym) {
            return mb_strtolower(CENGI_PP_MESES[(int) substr($ym, 5, 2)], 'UTF-8');
        };
        $periodo = $ini === $fin ? 'Mes de ' . $mes($ini) : 'Meses de ' . $mes($ini) . ' a ' . $mes($fin) . ' ' . substr($fin, 0, 4);
    }
    return trim('Honorarios de ' . $rol . ' ' . $d['curso_nombre'] . ' ' . $periodo);
}

/** Estado del recibo (fila de liquidacion_recibos) por detalle_id. */
function cengi_pr_estados(PDO $db, array $detalleIds)
{
    $ids = array_values(array_filter(array_map('intval', $detalleIds)));
    if (!$ids) {
        return [];
    }
    $stmt = $db->prepare('SELECT * FROM liquidacion_recibos WHERE detalle_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'detalle_id');
}

/** Token del enlace personal (se crea la primera vez y se conserva en los reenvíos). */
function cengi_pr_token(PDO $db, array $d)
{
    $actual = $db->prepare('SELECT token FROM liquidacion_recibos WHERE detalle_id = ?');
    $actual->execute([(int) $d['id']]);
    $token = (string) $actual->fetchColumn();
    if ($token === '') {
        $token = bin2hex(random_bytes(20));
        $db->prepare('INSERT INTO liquidacion_recibos (detalle_id, token, monto) VALUES (?, ?, ?)')->execute([(int) $d['id'], $token, (float) $d['total']]);
    }
    return $token;
}

function cengi_pr_url($token)
{
    $env = function_exists('cengicursos_env') ? cengicursos_env() : [];
    if (!empty($env['APP_URL'])) {
        $base = rtrim((string) $env['APP_URL'], '/');
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/cengicursos/recibo_firmado.php?t=' . rawurlencode($token);
}

/** Correo de la persona del recibo (instructor o soporte técnico). */
function cengi_pr_correo(PDO $db, array $d)
{
    $stmt = $db->prepare('SELECT correo FROM ' . ($d['tipo'] === 'soporte' ? 'soporte_tecnico' : 'instructores') . ' WHERE id = ?');
    $stmt->execute([(int) $d['persona_id']]);
    $correo = trim((string) $stmt->fetchColumn());
    return filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '';
}

/** Estados de un recibo, en el orden del trámite. */
const CENGI_PR_ESTADOS = [
    'sin_enviar' => ['Sin enviar', 'is-sin_enviar'],
    'enviado' => ['Enviado · espera firma', 'is-enviado'],
    'monto_cambio' => ['El monto cambió · reenviar', 'is-monto_cambio'],
    'firmado' => ['Firmado · por validar', 'is-firmado'],
    'validado' => ['Validado por el Jefe', 'is-validado'],
];
/**
 * Estado del recibo de una fila: firmado solo cuenta si se firmó por el monto vigente
 * (si la liquidación se corrigió después, hay que reenviarlo).
 */
function cengi_pr_estado(array $d, $er)
{
    $mismoMonto = $er && abs((float) $er['monto'] - (float) $d['total']) < 0.005;
    if ($er && $er['firmado_en'] && $mismoMonto) {
        // Validado solo si el Jefe validó este mismo monto (si se corrige la liquidación, hay que volver a validar).
        return !empty($er['validado_en']) && abs((float) $er['validado_monto'] - (float) $d['total']) < 0.005 ? 'validado' : 'firmado';
    }
    if ($er && ($er['enviado_en'] || $er['firmado_en'])) {
        return $mismoMonto ? 'enviado' : 'monto_cambio';
    }
    return 'sin_enviar';
}

/**
 * Recibos de una liquidación: total, firmados y validados (al monto vigente), quiénes faltan
 * firmar, cuáles esperan la validación del Jefe y la fecha del último paso.
 * 'listos': todos validados (o firmados, si la configuración no exige la validación del Jefe).
 */
function cengi_pr_resumen(PDO $db, array $detalle, $exigeValidacion = true)
{
    $recibos = array_values(array_filter($detalle, static function ($d) {
        return ($d['comprobante'] ?? '') === 'recibo';
    }));
    $estados = $recibos ? cengi_pr_estados($db, array_column($recibos, 'id')) : [];
    $r = ['total' => count($recibos), 'firmados' => 0, 'validados' => 0, 'faltan' => [], 'por_validar' => [], 'ultima' => null, 'estados' => $estados, 'por_enviar' => 0];
    foreach ($recibos as $d) {
        $er = $estados[(int) $d['id']] ?? null;
        $estado = cengi_pr_estado($d, $er);
        if ($estado === 'validado' || $estado === 'firmado') {
            $r['firmados']++;
            $r['ultima'] = max((string) $r['ultima'], (string) ($estado === 'validado' ? $er['validado_en'] : $er['firmado_en'])) ?: null;
            if ($estado === 'validado') {
                $r['validados']++;
            } else {
                $r['por_validar'][] = $d['persona_nombre'];
            }
        } else {
            $r['faltan'][] = $d['persona_nombre'];
            if ($estado !== 'enviado') {
                $r['por_enviar']++;
            }
        }
    }
    $r['faltan'] = array_values(array_unique($r['faltan']));
    $r['listos'] = !$r['faltan'] && (!$exigeValidacion || !$r['por_validar']);
    return $r;
}

/** Código de verificación de la validación de un recibo (HMAC de su contenido y del firmado). */
function cengi_pr_codigo_validacion(PDO $db, array $d, array $er, $validadoEn, $usuario)
{
    $datos = implode('|', [(int) $d['id'], $d['documento_numero'], number_format((float) $d['total'], 2, '.', ''), $d['persona_nombre'], $d['persona_dpi'] ?? '',
        $er['firmado_path'] ?? '', $validadoEn, $usuario]);
    return strtoupper(implode('-', str_split(substr(hash_hmac('sha256', $datos, cengi_pago_secreto($db)), 0, 12), 4)));
}

/** ¿La validación guardada corresponde al recibo y al firmado actuales? */
function cengi_pr_validacion_valida(PDO $db, array $d, $er)
{
    return $er && !empty($er['validado_en']) && hash_equals((string) $er['validado_codigo'], cengi_pr_codigo_validacion($db, $d, $er, $er['validado_en'], $er['validado_por']));
}

/** El Jefe de Transferencia valida un recibo firmado (con la firma registrada en la configuración). */
function cengi_pr_validar(PDO $db, array $d, array $er, array $cfg, $usuario)
{
    $en = date('Y-m-d H:i:s');
    $codigo = cengi_pr_codigo_validacion($db, $d, $er, $en, $usuario);
    $db->prepare('UPDATE liquidacion_recibos SET validado_en = ?, validado_por = ?, validado_nombre = ?, validado_cargo = ?, validado_imagen = ?, validado_codigo = ?, validado_monto = ? WHERE detalle_id = ?')
        ->execute([$en, $usuario, $cfg['jefe_nombre'] ?: $usuario, $cfg['jefe_cargo'], $cfg['firma'], $codigo, (float) $d['total'], (int) $d['id']]);
    cengi_pr_log($db, $d['id'], 'validado', 'Código ' . $codigo . ' · ' . ($cfg['jefe_nombre'] ?: $usuario), '', '', $d['total'], $usuario);
    return $codigo;
}
/** Logo embebido (data URI) para el recibo impreso y el PDF. */
function cengi_pr_logo()
{
    static $logo = null;
    if ($logo === null) {
        $archivo = __DIR__ . '/img/logo-recibo.png'; // versión reducida (160 px) del logo del comité
        $logo = is_file($archivo) && filesize($archivo) < 400000 ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($archivo)) : '';
    }
    return $logo;
}

/**
 * HTML del recibo (clases pg-recibo-* de css/pago_instructores.css; el PDF usa los mismos
 * estilos en línea). Solo tablas, para que se vea igual en el navegador y en Dompdf.
 * Orden del trámite: firma quien recibe → el Jefe de Transferencia valida.
 */
function cengi_pr_html(array $d, array $liq, array $cfg, $firmaValida, $fecha, $val = null)
{
    $h = static function ($v) {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    };
    $q = static function ($n) {
        return 'Q' . number_format((float) $n, 2);
    };
    $pct = rtrim(rtrim(number_format((float) $d['impuesto_pct'], 2, '.', ''), '0'), '.');
    $dpi = $d['persona_dpi'] !== '' ? $h(preg_replace('/^(\d{4})(\d{5})(\d{4})$/', '  ', $d['persona_dpi'])) : '______________________';
    $fechas = implode(', ', array_map(static function ($f) {
        return date('d/m', strtotime($f));
    }, array_filter(explode(',', (string) ($d['fechas'] ?? '')))));
    $logo = cengi_pr_logo();
    // $val: fila de liquidacion_recibos con la validación del Jefe; $firmaValida: si su código es válido.
    $validado = $val && !empty($val['validado_en']) && $firmaValida && abs((float) $val['validado_monto'] - (float) $d['total']) < 0.005;
    // Tarjeta 2: el Jefe de Transferencia valida después de la firma de quien recibe.
    $validacion = '<div class="pg-recibo-firma-espacio">' . ($validado ? '<img src="' . $h($val['validado_imagen']) . '" alt="Firma">' : '') . '</div>'
        . '<div class="pg-recibo-firma-linea"></div>'
        . '<b class="pg-recibo-firma-nombre">' . $h($validado ? $val['validado_nombre'] : ($cfg['jefe_nombre'] ?: '________________________')) . '</b>'
        . '<span class="pg-recibo-firma-dato">' . $h($validado ? $val['validado_cargo'] : $cfg['jefe_cargo']) . '</span>'
        . ($validado
            ? '<div class="pg-recibo-sello is-ok">✓ Validado electrónicamente el ' . date('d/m/Y H:i', strtotime($val['validado_en'])) . '<br>Código ' . $h($val['validado_codigo']) . '</div>'
            : '<div class="pg-recibo-sello' . ($val && !empty($val['validado_en']) ? ' is-mal' : '') . '">' . ($val && !empty($val['validado_en']) ? 'Validación no vigente: el recibo cambió después de validarse' : 'Pendiente · se valida después de la firma de quien recibe') . '</div>');
    return '<section class="pg-recibo">'
        . '<table class="pg-recibo-cab"><tr>'
        . '<td class="pg-recibo-org">' . ($logo !== '' ? '<img src="' . $logo . '" alt="">' : '') . '<div><b>' . $h($cfg['recibi_de']) . '</b><span>Recibo de honorarios · Capacitación</span></div></td>'
        . '<td class="pg-recibo-num"><span>Recibo No.</span><b>' . $h($d['documento_numero']) . '</b></td>'
        . '<td class="pg-recibo-total"><span>Total recibido</span><b>' . $q($d['total']) . '</b></td>'
        . '</tr></table>'
        . '<div class="pg-recibo-franja"></div>'
        . '<table class="pg-recibo-tabla">'
        . '<tr><th>Recibí de:</th><td class="pg-recibo-linea">' . $h($cfg['recibi_de']) . '</td></tr>'
        . '<tr><th>La cantidad de:</th><td class="pg-recibo-linea"><b>' . $h(mb_strtoupper(cengi_acu_letras($d['total']), 'UTF-8')) . '</b></td></tr>'
        . '<tr><th>Por concepto de:</th><td class="pg-recibo-linea">' . $h(cengi_pago_concepto($d)) . '</td></tr>'
        . '<tr><th>Lugar y fecha:</th><td class="pg-recibo-linea">' . $h(mb_strtoupper($cfg['lugar'], 'UTF-8')) . ', ' . date('d/m/Y', strtotime($fecha)) . '</td></tr>'
        . '</table>'
        . ((float) $d['impuesto'] > 0
            ? '<table class="pg-recibo-detalle"><tr><th>Sesiones' . ($fechas !== '' ? ' <small>(' . $h($fechas) . ')</small>' : '') . '</th><th>Tarifa por sesión</th><th>Neto</th><th>Impuesto ' . $pct . '%</th><th>Total</th></tr>'
                . '<tr><td>' . (int) $d['sesiones'] . '</td><td>' . $q($d['tarifa']) . '</td><td>' . $q($d['neto']) . '</td><td>' . $q($d['impuesto']) . '</td><td><b>' . $q($d['total']) . '</b></td></tr></table>'
            : '<table class="pg-recibo-detalle"><tr><th>Sesiones' . ($fechas !== '' ? ' <small>(' . $h($fechas) . ')</small>' : '') . '</th><th>Tarifa por sesión</th><th>Total a pagar (neto)</th></tr>'
                . '<tr><td>' . (int) $d['sesiones'] . '</td><td>' . $q($d['tarifa']) . '</td><td><b>' . $q($d['total']) . '</b></td></tr></table>')
        . '<table class="pg-recibo-firmas"><tr>'
        . '<td class="is-persona"><div class="pg-recibo-paso"><span>1</span> Firma de quien recibe</div><div class="pg-recibo-firma-espacio"></div><div class="pg-recibo-firma-linea"></div>'
            . '<b class="pg-recibo-firma-nombre">' . $h($d['persona_nombre']) . '</b><span class="pg-recibo-firma-dato">DPI: ' . $dpi . '</span>'
            . '<div class="pg-recibo-sello">Firme sobre la línea y devuelva el recibo</div></td>'
        . '<td class="is-validacion' . ($validado ? ' is-validado' : '') . '"><div class="pg-recibo-paso"><span>2</span> Validación del trámite</div>' . $validacion . '</td>'
        . '</tr></table>'
        . '<div class="pg-recibo-pie">Liquidación ' . $h($liq['numero']) . ' · ' . $h(date('d/m/Y', strtotime($liq['desde']))) . ' – ' . $h(date('d/m/Y', strtotime($liq['hasta']))) . '</div>'
        . '</section>';
}

/** PDF del recibo para adjuntar al correo y descargar desde el enlace personal. */
function cengi_pr_pdf(array $d, array $liq, array $cfg, $firmaValida, $fecha, $val = null)
{
    $doc = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page { margin: 28px; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1E2A1A; font-size: 11.5px; }
        .pg-recibo { border: 1px solid #CED2D5; border-radius: 8px; }
        .pg-recibo-cab { width: 100%; border-collapse: collapse; background: #1f5632; color: #fff; }
        .pg-recibo-cab td { padding: 14px 16px; vertical-align: middle; }
        .pg-recibo-org img { float: left; width: 46px; height: 46px; margin-right: 10px; padding: 3px; background: #fff; border-radius: 6px; }
        .pg-recibo-org b { display: block; font-size: 14px; }
        .pg-recibo-org span, .pg-recibo-num span, .pg-recibo-total span { display: block; color: #D9F2A6; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .pg-recibo-num { width: 150px; text-align: center; white-space: nowrap; }
        .pg-recibo-num b { font-size: 13px; }
        .pg-recibo-total { width: 160px; text-align: right; white-space: nowrap; background: #73BC25; }
        .pg-recibo-total span { color: #fff; }
        .pg-recibo-total b { font-size: 18px; }
        .pg-recibo-franja { height: 4px; background: #FFCC00; }
        .pg-recibo-tabla { width: 96%; margin: 10px 0 4px; border-collapse: collapse; }
        .pg-recibo-tabla th { width: 120px; padding: 9px 8px 4px 18px; color: #5B6459; font-weight: normal; font-style: italic; text-align: left; vertical-align: bottom; }
        .pg-recibo-tabla td { padding: 9px 4px 4px; vertical-align: bottom; }
        .pg-recibo-linea { border-bottom: 1px solid #CED2D5; }
        .pg-recibo-detalle { width: 94%; margin: 12px auto; border-collapse: collapse; }
        .pg-recibo-detalle th { padding: 6px 8px; background: #F1F3F4; color: #5B6459; font-size: 9.5px; font-weight: bold; text-align: left; }
        .pg-recibo-detalle th small { font-weight: normal; }
        .pg-recibo-detalle td { padding: 7px 8px; border-bottom: 1px solid #E4E7E1; }
        .pg-recibo-firmas { width: 100%; margin: 8px 0 12px; border-collapse: separate; border-spacing: 14px 0; }
        .pg-recibo-firmas td { width: 50%; padding: 10px 14px 12px; border: 1px solid #E4E7E1; border-radius: 8px; text-align: center; vertical-align: top; font-size: 10px; }
        .pg-recibo-firmas td.is-validado { border-color: #D6EBBE; background: #F6FBF0; }
        .pg-recibo-paso { margin-bottom: 4px; color: #3E7A12; font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .pg-recibo-paso span { padding: 0 5px; border-radius: 8px; background: #73BC25; color: #fff; }
        .pg-recibo-firma-espacio { height: 62px; text-align: center; }
        .pg-recibo-firma-espacio img { max-width: 180px; max-height: 58px; margin-top: 4px; }
        .pg-recibo-firma-linea { margin: 0 14px 5px; border-top: 1px solid #1E2A1A; height: 1px; }
        .pg-recibo-firma-nombre { display: block; font-size: 11px; }
        .pg-recibo-firma-dato { display: block; color: #5B6459; font-size: 9.5px; }
        .pg-recibo-sello { margin-top: 7px; padding: 4px 6px; border-radius: 5px; background: #F1F3F4; color: #5B6459; font-size: 8.5px; }
        .pg-recibo-sello.is-ok { background: #EEF7E3; color: #3E7A12; }
        .pg-recibo-sello.is-mal { background: #FFEEE0; color: #B34E00; }
        .pg-recibo-pie { padding: 7px 18px; border-top: 1px solid #E4E7E1; color: #5B6459; font-size: 8.5px; }
        .pg-nota { margin-top: 12px; color: #5B6459; font-size: 9px; text-align: center; }
    </style></head><body>' . cengi_pr_html($d, $liq, $cfg, $firmaValida, $fecha, $val)
        . '<p class="pg-nota">Firme en el espacio “1 · Firma de quien recibe” y devuélvalo por el enlace que recibió en su correo, o entréguelo a Coordinación de Capacitación. Después lo valida el ' . htmlspecialchars((string) $cfg['jefe_cargo'], ENT_QUOTES, 'UTF-8') . '.</p></body></html>';
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($doc, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

function cengi_pr_nombre_archivo(array $d)
{
    return 'Recibo_' . trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $d['documento_numero'] . '_' . iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['persona_nombre'])), '_') . '.pdf';
}

/**
 * Envía el recibo en PDF con el enlace para devolverlo firmado. Devuelve '' si salió o el motivo si no.
 */
function cengi_pr_enviar(PDO $db, array $d, array $liq, array $cfg, $firmaValida, $fecha)
{
    if (($d['comprobante'] ?? '') !== 'recibo') {
        return 'no cobra con recibo';
    }
    $correo = cengi_pr_correo($db, $d);
    if ($correo === '') {
        return 'sin correo registrado';
    }
    $token = cengi_pr_token($db, $d);
    $url = cengi_pr_url($token);
    $h = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $html = '<!DOCTYPE html><html lang="es"><body style="margin:0;background:#F5F6F7;font-family:Arial,Helvetica,sans-serif;color:#294033;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #E4E7E1;">'
        . '<tr><td style="background:#1f5632;padding:22px 26px;color:#fff;"><div style="font-size:11px;letter-spacing:2px;color:#D9F2A6;">CENGICAÑA · CAPACITACIÓN</div><div style="font-size:20px;font-weight:bold;margin-top:4px;">Recibo de honorarios para firmar</div></td></tr>'
        . '<tr><td style="height:5px;background:linear-gradient(90deg,#73BC25 0 25%,#A3D300 25% 50%,#FFCC00 50% 75%,#FF6B00 75% 100%);background-color:#73BC25;"></td></tr>'
        . '<tr><td style="padding:24px 26px;font-size:14px;line-height:1.6;">'
        . '<p>Estimado(a) <b>' . $h($d['persona_nombre']) . '</b>:</p>'
        . '<p>Adjunto encontrará el recibo <b>' . $h($d['documento_numero']) . '</b> por <b>Q' . number_format((float) $d['total'], 2) . '</b> correspondiente a: ' . $h(cengi_pago_concepto($d)) . ' (liquidación ' . $h($liq['numero']) . ').</p>'
        . '<p>Por favor <b>imprímalo, fírmelo y devuélvalo</b> escaneado o en fotografía legible usando el botón:</p>'
        . '<p style="text-align:center;margin:22px 0;"><a href="' . $h($url) . '" style="display:inline-block;padding:12px 26px;border-radius:9px;background:#73BC25;color:#fff;font-weight:bold;text-decoration:none;">Subir recibo firmado</a></p>'
        . '<p style="font-size:12px;color:#5B6459;">Si el botón no funciona, copie este enlace: <br><span style="word-break:break-all;">' . $h($url) . '</span></p>'
        . '<p style="font-size:12px;color:#5B6459;">También puede entregarlo firmado en Coordinación de Capacitación. Verifique que su número de DPI aparezca correctamente.</p>'
        . '</td></tr><tr><td style="padding:14px 26px;background:#F1F3F4;font-size:11px;color:#5B6459;">' . $h($cfg['recibi_de']) . ' · ' . $h($cfg['lugar']) . '</td></tr></table></td></tr></table></body></html>';
    $val = cengi_pr_estados($db, [(int) $d['id']])[(int) $d['id']] ?? null;
    $pdf = cengi_pr_pdf($d, $liq, $cfg, $val ? cengi_pr_validacion_valida($db, $d, $val) : false, $fecha, $val);
    $GLOBALS['cengi_correo_categoria'] = 'Pago a instructores';
    $ok = cengi_enviar_correo($correo, (string) $d['persona_nombre'], 'Recibo ' . $d['documento_numero'] . ' para firmar · ' . $liq['numero'], $html, [[
        'contenido' => $pdf,
        'nombre' => cengi_pr_nombre_archivo($d),
        'tipo' => 'application/pdf',
    ]]);
    if (!$ok) {
        return 'el servidor de correo rechazó el envío';
    }
    $db->prepare('UPDATE liquidacion_recibos SET enviado_en = ?, enviado_a = ?, envios = envios + 1, monto = ? WHERE detalle_id = ?')
        ->execute([date('Y-m-d H:i:s'), $correo, (float) $d['total'], (int) $d['id']]);
    // Copia del PDF tal como se envió (evidencia del recibo emitido).
    $copia = '';
    $dirEmit = __DIR__ . '/../uploads/recibos_emitidos';
    if ((is_dir($dirEmit) || @mkdir($dirEmit, 0775, true)) && is_writable($dirEmit)) {
        $nombreCopia = bin2hex(random_bytes(16)) . '.pdf';
        if (file_put_contents($dirEmit . '/' . $nombreCopia, $pdf) !== false) {
            $copia = '/uploads/recibos_emitidos/' . $nombreCopia;
        }
    }
    cengi_pr_log($db, $d['id'], 'enviado', 'Enviado a ' . $correo, $copia, cengi_pr_nombre_archivo($d), $d['total']);
    return '';
}


/**
 * Requerimiento de factura: se envía a quien cobra con factura con el monto neto, el % de
 * pequeño contribuyente y el total a facturar. Devuelve '' si salió o el motivo si no.
 */
function cengi_pr_enviar_requerimiento(PDO $db, array $d, array $liq, array $cfg)
{
    if (($d['comprobante'] ?? '') !== 'factura') {
        return 'no cobra con factura';
    }
    $correo = cengi_pr_correo($db, $d);
    if ($correo === '') {
        return 'sin correo registrado';
    }
    $h = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $q = static function ($n) {
        return 'Q' . number_format((float) $n, 2);
    };
    $pct = rtrim(rtrim(number_format((float) $d['impuesto_pct'], 2, '.', ''), '0'), '.');
    $fila = static function ($a, $b, $fuerte = false) use ($h) {
        return '<tr><td style="padding:8px 12px;border-bottom:1px solid #E4E7E1;color:#5B6459;">' . $a . '</td><td style="padding:8px 12px;border-bottom:1px solid #E4E7E1;text-align:right;' . ($fuerte ? 'font-weight:bold;color:#1f5632;font-size:16px;' : '') . '">' . $b . '</td></tr>';
    };
    $html = '<!DOCTYPE html><html lang="es"><body style="margin:0;background:#F5F6F7;font-family:Arial,Helvetica,sans-serif;color:#294033;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #E4E7E1;">'
        . '<tr><td style="background:#1f5632;padding:22px 26px;color:#fff;"><div style="font-size:11px;letter-spacing:2px;color:#D9F2A6;">CENGICAÑA · CAPACITACIÓN</div><div style="font-size:20px;font-weight:bold;margin-top:4px;">Solicitud de factura</div></td></tr>'
        . '<tr><td style="height:5px;background-color:#73BC25;"></td></tr>'
        . '<tr><td style="padding:24px 26px;font-size:14px;line-height:1.6;">'
        . '<p>Estimado(a) <b>' . $h($d['persona_nombre']) . '</b>:</p>'
        . '<p>Para gestionar su pago por <b>' . $h(cengi_pago_concepto($d)) . '</b> (liquidación ' . $h($liq['numero']) . '), le solicitamos emitir su <b>factura de pequeño contribuyente</b> por el siguiente monto:</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0;border:1px solid #E4E7E1;border-radius:8px;">'
        . $fila((int) $d['sesiones'] . ' sesión(es) × ' . $q($d['tarifa']), $q($d['neto']))
        . $fila('Pequeño contribuyente (' . $pct . '%)', $q($d['impuesto']))
        . $fila('Total a facturar', $q($d['total']), true)
        . '</table>'
        . '<p>Emítala a nombre de <b>' . $h($cfg['recibi_de']) . '</b> y envíela respondiendo este correo o entréguela en Coordinación de Capacitación. Administración confirmará su pago al recibirla.</p>'
        . '</td></tr><tr><td style="padding:14px 26px;background:#F1F3F4;font-size:11px;color:#5B6459;">' . $h($cfg['recibi_de']) . ' · ' . $h($cfg['lugar']) . '</td></tr></table></td></tr></table></body></html>';
    $GLOBALS['cengi_correo_categoria'] = 'Pago a instructores';
    if (!cengi_enviar_correo($correo, (string) $d['persona_nombre'], 'Solicitud de factura · ' . $liq['numero'] . ' · ' . $q($d['total']), $html)) {
        return 'el servidor de correo rechazó el envío';
    }
    cengi_pr_log($db, $d['id'], 'requerimiento', 'Enviado a ' . $correo . ' · total a facturar ' . $q($d['total']), '', '', $d['total']);
    return '';
}

/** Guarda el recibo firmado (PDF o imagen) de un $_FILES; devuelve la ruta o lanza InvalidArgumentException. */
function cengi_pr_guardar_firmado(PDO $db, array $d, array $archivo, $origen)
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Selecciona el recibo firmado (PDF, JPG o PNG).');
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK || $archivo['size'] > CENGI_PR_MAX) {
        throw new InvalidArgumentException('El archivo no se pudo cargar (máximo 10 MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if (!isset(CENGI_PR_TIPOS[$mime])) {
        throw new InvalidArgumentException('El recibo firmado debe ser PDF, JPG o PNG.');
    }
    // Nombre no adivinable: el recibo lleva DPI.
    $ruta = cengi_guardar_archivo_subido($archivo['tmp_name'], 'recibos_firmados', bin2hex(random_bytes(16)) . '.' . CENGI_PR_TIPOS[$mime]);
    if (!$ruta) {
        throw new RuntimeException('No se pudo guardar el archivo. Intenta de nuevo.');
    }
    cengi_pr_token($db, $d);
    $previo = $db->prepare('SELECT firmado_en, validado_en FROM liquidacion_recibos WHERE detalle_id = ?');
    $previo->execute([(int) $d['id']]);
    $previo = $previo->fetch(PDO::FETCH_ASSOC) ?: [];
    $nombre = mb_substr(basename((string) $archivo['name']), 0, 255, 'UTF-8');
    // El firmado anterior se conserva en el expediente (no se borra); el vigente es el nuevo.
    $db->prepare('UPDATE liquidacion_recibos SET firmado_path = ?, firmado_nombre = ?, firmado_en = ?, firmado_por = ?, monto = ? WHERE detalle_id = ?')
        ->execute([$ruta, $nombre, date('Y-m-d H:i:s'), $origen, (float) $d['total'], (int) $d['id']]);
    $quien = $origen === 'persona' ? 'Lo devolvió la persona por su enlace' : 'Registrado por Administración';
    cengi_pr_log($db, $d['id'], !empty($previo['firmado_en']) ? 'reemplazado' : 'firmado', $quien, $ruta, $nombre, $d['total'], $origen === 'persona' ? $d['persona_nombre'] : '');
    if (!empty($previo['validado_en'])) {
        $db->prepare("UPDATE liquidacion_recibos SET validado_en = NULL, validado_codigo = '', validado_monto = NULL WHERE detalle_id = ?")->execute([(int) $d['id']]);
        cengi_pr_log($db, $d['id'], 'validacion_retirada', 'Se subió un nuevo firmado: el Jefe debe validarlo de nuevo', '', '', $d['total']);
    }
    return $ruta;
}

/** Ruta en disco de un archivo del expediente (uploads/recibos_firmados o recibos_emitidos), o null. */
function cengi_pr_archivo_firmado($ruta)
{
    $archivo = $ruta !== '' ? realpath(__DIR__ . '/..' . $ruta) : false;
    foreach (['recibos_firmados', 'recibos_emitidos', 'facturas'] as $carpeta) {
        $raiz = realpath(__DIR__ . '/../uploads/' . $carpeta);
        if ($raiz && $archivo && strpos($archivo, $raiz . DIRECTORY_SEPARATOR) === 0 && is_file($archivo)) {
            return $archivo;
        }
    }
    return null;
}
