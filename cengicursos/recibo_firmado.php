<?php
/**
 * Página pública (sin iniciar sesión) del enlace que recibe por correo un instructor o soporte técnico
 * que cobra con recibo: descarga su recibo en PDF y lo devuelve firmado (PDF o foto).
 * El token del enlace identifica una sola fila de liquidación. Ver pago_recibos_helpers.php.
 */
require_once __DIR__ . '/pago_recibos_helpers.php';

$db = conectar();
$h = static function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$fila = null;
if (preg_match('/^[a-f0-9]{40}$/', $token)) {
    $stmt = $db->prepare('SELECT d.*, r.token, r.firmado_en, r.firmado_por, r.enviado_en, r.monto AS monto_enviado
        FROM liquidacion_recibos r INNER JOIN liquidacion_detalle d ON d.id = r.detalle_id WHERE r.token = ?');
    $stmt->execute([$token]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$liq = null;
if ($fila) {
    $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
    $stmt->execute([(int) $fila['liquidacion_id']]);
    $liq = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$disponible = $fila && $liq && $liq['estado'] !== 'anulada' && $fila['comprobante'] === 'recibo';
$cfg = cengi_pp_pago_config($db);

if ($disponible && isset($_GET['pdf'])) {
    $stmt = $db->prepare('SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ?');
    $stmt->execute([(int) $liq['id']]);
    $firmaValida = !empty($liq['firmada_en']) && hash_equals((string) $liq['firma_codigo'], cengi_pago_codigo_firma($db, $liq, $stmt->fetchAll(PDO::FETCH_ASSOC), $liq['firmada_en'], $liq['firmada_por']));
    // El recibo muestra la validación del propio recibo (la del Jefe de Transferencia), no la de la liquidación.
    $val = cengi_pr_estados($db, [(int) $fila['id']])[(int) $fila['id']] ?? null;
    $pdf = cengi_pr_pdf($fila, $liq, $cfg, $val ? cengi_pr_validacion_valida($db, $fila, $val) : false, $liq['pago_fecha'] ?: date('Y-m-d'), $val);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . cengi_pr_nombre_archivo($fila) . '"');
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

$mensaje = '';
$error = '';
if ($disponible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        cengi_pr_guardar_firmado($db, $fila, $_FILES['firmado'] ?? [], 'persona');
        $fila['firmado_en'] = date('Y-m-d H:i:s');
        $fila['firmado_por'] = 'persona';
        $mensaje = '¡Gracias! Recibimos su recibo firmado. Coordinación de Capacitación lo revisará para continuar con el pago.';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('recibo_firmado: ' . $e->getMessage());
        $error = 'No fue posible guardar el archivo. Intente de nuevo o entréguelo en Coordinación de Capacitación.';
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Recibo para firmar · CENGICAÑA</title>
<style>
    :root { --verde:#73BC25; --verde2:#A3D300; --amarillo:#FFCC00; --naranja:#FF6B00; --gris:#CED2D5; --deep:#1f5632; }
    * { box-sizing:border-box; }
    body { margin:0; background:#F5F6F7; color:#294033; font-family:Inter,Arial,Helvetica,sans-serif; }
    .rf { max-width:620px; margin:28px auto; padding:0 16px; }
    .rf-card { overflow:hidden; border:1px solid #E4E7E1; border-radius:14px; background:#fff; box-shadow:0 10px 30px rgba(31,86,50,.08); }
    .rf-head { position:relative; padding:22px 24px 26px; color:#fff; background:radial-gradient(circle at 95% -40%, rgba(163,211,0,.42) 0, rgba(163,211,0,0) 45%), #1f5632; }
    .rf-head::after { content:""; position:absolute; left:0; right:0; bottom:0; height:5px; background:linear-gradient(90deg,var(--verde) 0 25%,var(--verde2) 25% 50%,var(--amarillo) 50% 75%,var(--naranja) 75% 100%); }
    .rf-head small { color:#D9F2A6; font-size:11px; letter-spacing:.12em; text-transform:uppercase; }
    .rf-head h1 { margin:6px 0 0; font-size:22px; }
    .rf-body { padding:22px 24px; }
    .rf-datos { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin:0 0 18px; }
    .rf-datos div { padding:10px 12px; border-radius:10px; background:#F1F3F4; }
    .rf-datos span { display:block; color:#5B6459; font-size:11px; }
    .rf-datos b { font-size:15px; }
    .rf-datos .is-ancho { grid-column:1/-1; }
    .rf-pasos { margin:0 0 18px; padding:0; list-style:none; counter-reset:paso; }
    .rf-pasos li { position:relative; margin:0 0 10px; padding:2px 0 2px 38px; line-height:1.5; counter-increment:paso; }
    .rf-pasos li::before { content:counter(paso); position:absolute; left:0; top:0; display:grid; place-items:center; width:26px; height:26px; border-radius:8px; background:var(--verde); color:#fff; font-weight:700; font-size:13px; }
    .rf-btn { display:inline-block; padding:11px 20px; border:0; border-radius:9px; background:var(--verde); color:#fff; font-weight:700; font-size:14px; text-decoration:none; cursor:pointer; }
    .rf-btn:hover { background:#5e9b1d; }
    .rf-btn.is-sec { border:1px solid var(--verde); background:#fff; color:#3E7A12; }
    .rf-subir { margin-top:16px; padding:16px; border:2px dashed var(--gris); border-radius:12px; background:#FBFCFA; }
    .rf-subir input[type=file] { display:block; width:100%; margin:8px 0 12px; padding:8px; border:1px solid var(--gris); border-radius:8px; background:#fff; }
    .rf-msg { margin:0 0 16px; padding:12px 14px; border-radius:10px; font-size:14px; }
    .rf-msg.is-ok { border-left:5px solid var(--verde); background:#EEF7E3; color:#2d5a0e; }
    .rf-msg.is-error { border-left:5px solid var(--naranja); background:#FFEEE0; color:#8a3c00; }
    .rf-msg.is-info { border-left:5px solid var(--amarillo); background:#FFF7D6; color:#6b5000; }
    .rf-pie { padding:12px 24px; background:#F1F3F4; color:#5B6459; font-size:11px; }
    @media (max-width:520px) { .rf-datos { grid-template-columns:1fr; } }
</style>
</head>
<body>
<main class="rf"><div class="rf-card">
    <header class="rf-head"><small>CENGICAÑA · Capacitación</small><h1>Recibo de honorarios</h1></header>
    <div class="rf-body">
    <?php if (!$disponible): ?>
        <p class="rf-msg is-error">Este enlace no es válido o el recibo ya no está disponible. Si necesita ayuda, comuníquese con Coordinación de Capacitación.</p>
    <?php else: ?>
        <?php if ($mensaje): ?><p class="rf-msg is-ok" role="status"><?php echo $h($mensaje); ?></p><?php endif; ?>
        <?php if ($error): ?><p class="rf-msg is-error" role="alert"><?php echo $h($error); ?></p><?php endif; ?>
        <?php if (!$mensaje && $fila['firmado_en']): ?><p class="rf-msg is-ok">Ya recibimos este recibo firmado el <?php echo date('d/m/Y H:i', strtotime($fila['firmado_en'])); ?>. Si necesita corregirlo, puede subirlo de nuevo.</p><?php endif; ?>
        <div class="rf-datos">
            <div><span>Recibo</span><b><?php echo $h($fila['documento_numero']); ?></b></div>
            <div><span>Monto</span><b>Q<?php echo number_format((float) $fila['total'], 2); ?></b></div>
            <div class="is-ancho"><span>A nombre de</span><b><?php echo $h($fila['persona_nombre']); ?></b><?php echo $fila['persona_dpi'] !== '' ? ' · DPI ' . $h($fila['persona_dpi']) : ''; ?></div>
            <div class="is-ancho"><span>Concepto</span><b style="font-size:13px;font-weight:600;"><?php echo $h(cengi_pago_concepto($fila)); ?></b></div>
        </div>
        <?php if ((float) $fila['monto_enviado'] > 0 && abs((float) $fila['monto_enviado'] - (float) $fila['total']) >= 0.005): ?><p class="rf-msg is-info">El monto del recibo se actualizó. Descargue de nuevo el recibo antes de firmarlo.</p><?php endif; ?>
        <ol class="rf-pasos">
            <li><b>Descargue</b> su recibo e imprímalo.</li>
            <li><b>Fírmelo</b> y verifique que su DPI esté correcto.</li>
            <li><b>Súbalo</b> aquí escaneado (PDF) o como fotografía legible (JPG o PNG).</li>
        </ol>
        <a class="rf-btn is-sec" href="recibo_firmado.php?t=<?php echo $h($token); ?>&amp;pdf=1">Descargar recibo (PDF)</a>
        <form class="rf-subir" method="post" enctype="multipart/form-data">
            <input type="hidden" name="t" value="<?php echo $h($token); ?>">
            <label for="rf-archivo"><b>Recibo firmado</b> <small>(PDF, JPG o PNG · máximo 10 MB)</small></label>
            <input type="file" id="rf-archivo" name="firmado" accept="application/pdf,image/jpeg,image/png" capture="environment" required>
            <button class="rf-btn" type="submit"><?php echo $fila['firmado_en'] ? 'Subir de nuevo' : 'Enviar recibo firmado'; ?></button>
        </form>
    <?php endif; ?>
    </div>
    <footer class="rf-pie"><?php echo $h($cfg['recibi_de']); ?> · <?php echo $h($cfg['lugar']); ?> · También puede entregar el recibo firmado en Coordinación de Capacitación.</footer>
</div></main>
</body>
</html>
